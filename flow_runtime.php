<?php
/**
 * flow_runtime.php — Phase 7 of the Visual Button Flow.
 *
 * Chat-time engine that walks the node tree the admin built in the visual
 * editor and renders it to the Telegram user. Everything here is OPT-IN and
 * regression-safe: when no flow is active (only the root node exists), the
 * single entry point flow_runtime_handle() returns false and the legacy bot
 * dispatch in index.php runs unchanged.
 *
 * Navigation uses INLINE keyboards: every child node becomes a callback button
 * "flowgo_<nodeId>". Non-root nodes optionally get auto «بازگشت» (flowback) and
 * «منوی اصلی» (flowhome) buttons. User position + collected inputs live in the
 * button_flow_state table (one row per bot+user).
 */

require_once __DIR__ . '/flow.php';

/* -------------------------------------------------------------------------
 * State storage (button_flow_state)
 * ---------------------------------------------------------------------- */

/** Load the user's flow state row, or a fresh default if none exists. */
function flow_state_get($user_id, $bot_id = null)
{
    global $pdo;
    $bid = (int) flow_bot_id($bot_id);
    $uid = (string) $user_id;
    $default = [
        'bot_id'            => $bid,
        'user_id'           => $uid,
        'current_node'      => '',
        'await_node'        => '',
        'path'              => [],
        'inputs'            => [],
        'attempts'          => 0,
        'attempts_reset_at' => '',
    ];
    try {
        $stmt = $pdo->prepare("SELECT * FROM button_flow_state WHERE bot_id = :b AND user_id = :u LIMIT 1");
        $stmt->execute([':b' => $bid, ':u' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $default;
        }
        $default['current_node']      = (string) ($row['current_node'] ?? '');
        $default['await_node']        = (string) ($row['await_node'] ?? '');
        $default['attempts']          = (int) ($row['attempts'] ?? 0);
        $default['attempts_reset_at'] = (string) ($row['attempts_reset_at'] ?? '');
        $path = json_decode((string) ($row['path_json'] ?? ''), true);
        $default['path'] = is_array($path) ? $path : [];
        $inputs = json_decode((string) ($row['inputs_json'] ?? ''), true);
        $default['inputs'] = is_array($inputs) ? $inputs : [];
    } catch (Throwable $e) {
        error_log('flow_state_get: ' . $e->getMessage());
    }
    return $default;
}

/** Persist the user's flow state (upsert). */
function flow_state_save(array $state, $bot_id = null)
{
    global $pdo;
    $bid = (int) flow_bot_id($bot_id);
    $uid = (string) ($state['user_id'] ?? '');
    if ($uid === '') {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO button_flow_state
                (bot_id, user_id, current_node, await_node, path_json, inputs_json, attempts, attempts_reset_at, updated_at)
             VALUES (:b, :u, :cur, :aw, :pj, :ij, :at, :ar, :up)
             ON DUPLICATE KEY UPDATE
                current_node = VALUES(current_node),
                await_node   = VALUES(await_node),
                path_json    = VALUES(path_json),
                inputs_json  = VALUES(inputs_json),
                attempts     = VALUES(attempts),
                attempts_reset_at = VALUES(attempts_reset_at),
                updated_at   = VALUES(updated_at)"
        );
        $stmt->execute([
            ':b'  => $bid,
            ':u'  => $uid,
            ':cur'=> (string) ($state['current_node'] ?? ''),
            ':aw' => (string) ($state['await_node'] ?? ''),
            ':pj' => json_encode(array_values($state['path'] ?? []), JSON_UNESCAPED_UNICODE),
            ':ij' => json_encode((object) ($state['inputs'] ?? []), JSON_UNESCAPED_UNICODE),
            ':at' => (int) ($state['attempts'] ?? 0),
            ':ar' => (string) ($state['attempts_reset_at'] ?? ''),
            ':up' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        error_log('flow_state_save: ' . $e->getMessage());
    }
}

/**
 * Cheap check: is this user currently parked on an input node awaiting a reply?
 * Used by index.php to decide whether plain text should be routed to the flow
 * before the legacy text handlers see it. Returns false on any error.
 */
function flow_state_has_await($user_id, $bot_id = null)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT await_node FROM button_flow_state WHERE bot_id = :b AND user_id = :u LIMIT 1");
        $stmt->execute([':b' => (int) flow_bot_id($bot_id), ':u' => (string) $user_id]);
        $aw = (string) ($stmt->fetchColumn() ?: '');
        return $aw !== '';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The id of the node the user is currently parked on (or '' if none). Used by
 * index.php to decide whether a plain-text message is a reply-keyboard tap on a
 * flow node that must be routed to the runtime BEFORE legacy text handlers.
 */
function flow_state_current_node($user_id, $bot_id = null)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT current_node FROM button_flow_state WHERE bot_id = :b AND user_id = :u LIMIT 1");
        $stmt->execute([':b' => (int) flow_bot_id($bot_id), ':u' => (string) $user_id]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Does this plain-text message correspond to a reply-keyboard tap on the node
 * the user is currently parked on? Returns true when the text exactly matches
 * one of the current node's enabled user-children OR the back/home nav labels.
 * Used as the index.php gate so we never hijack unrelated text — only an EXACT
 * match against the user's own current-node buttons routes into the flow.
 */
function flow_reply_tap_is_for_flow($user_id, $text, $bot_id = null)
{
    $text = trim((string) $text);
    if ($text === '' || !flow_is_active($bot_id)) {
        return false;
    }
    $curId = flow_state_current_node($user_id, $bot_id);
    if ($curId === '') {
        return false;
    }
    $tree = get_button_flow($bot_id);
    $cur  = flow_node($tree, $curId);
    if (!is_array($cur)) {
        return false;
    }
    return flow_match_child_by_text($tree, $cur, $text) !== null;
}

/** Wipe a user's flow state (e.g. on exit / home). */
function flow_state_clear($user_id, $bot_id = null)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("DELETE FROM button_flow_state WHERE bot_id = :b AND user_id = :u");
        $stmt->execute([':b' => (int) flow_bot_id($bot_id), ':u' => (string) $user_id]);
    } catch (Throwable $e) {
        error_log('flow_state_clear: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * Rendering helpers
 * ---------------------------------------------------------------------- */

/**
 * Build the inline keyboard for a node: one button per child (callback
 * flowgo_<childId>), then optional auto «بازگشت» / «منوی اصلی» rows.
 * Buttons with a config.url are rendered as link buttons instead.
 */
function flow_runtime_keyboard(array $tree, array $node)
{
    $rows = [];
    $children = flow_children($tree, $node['id']);
    foreach ($children as $child) {
        // Skip the bot's real-menu / demo mirror nodes: those buttons are already
        // rendered by the bot's native main menu (keyboard.php). Re-rendering them
        // as inline flow buttons caused a duplicate menu under /start. Disabled
        // nodes are skipped too. Only the admin's own (user) child nodes render.
        $kind = (string) ($child['node_kind'] ?? 'user');
        if ($kind === 'system_menu' || $kind === 'system_demo' || $kind === 'root') {
            continue;
        }
        if (!empty($child['disabled'])) {
            continue;
        }
        $label = (string) ($child['label'] ?? '');
        if ($label === '') {
            $label = '⬜';
        }
        $cfg = $child['config'] ?? [];
        if (!empty($cfg['url'])) {
            $rows[] = [['text' => $label, 'url' => $cfg['url']]];
        } else {
            $rows[] = [['text' => $label, 'callback_data' => 'flowgo_' . $child['id']]];
        }
    }

    // Auto navigation buttons (only on non-root nodes, honouring per-node flags).
    $cfg = $node['config'] ?? [];
    $isRoot = ($node['id'] === flow_root_id($tree));
    $navRow = [];
    if (!$isRoot && !empty($node['parent']) && !empty($cfg['auto_back'])) {
        $navRow[] = ['text' => '🔙 بازگشت', 'callback_data' => 'flowback'];
    }
    if (!$isRoot && !empty($cfg['auto_home'])) {
        $navRow[] = ['text' => '🏠 منوی اصلی', 'callback_data' => 'flowhome'];
    }
    if ($navRow) {
        $rows[] = $navRow;
    }

    return ['inline_keyboard' => $rows];
}

/** Display mode for a node's children: 'inline' | 'reply' | 'both'. */
function flow_node_display_mode(array $node)
{
    $m = strtolower(trim((string) ($node['config']['display_mode'] ?? 'inline')));
    return in_array($m, ['inline', 'reply', 'both'], true) ? $m : 'inline';
}

/**
 * Build a REPLY keyboard for a node's children (plain-text buttons, bottom of
 * screen). Reply buttons can't carry callback_data/url, so each is a plain text
 * row and the runtime maps the tapped text back to the child node (see
 * flow_runtime_handle → text matching). URL children are skipped here (a reply
 * button can't open a link) — they still appear in the inline keyboard.
 * Returns null when there's nothing to render.
 */
function flow_runtime_reply_keyboard(array $tree, array $node)
{
    $rows = [];
    foreach (flow_children($tree, $node['id']) as $child) {
        $kind = (string) ($child['node_kind'] ?? 'user');
        if ($kind === 'system_menu' || $kind === 'system_demo' || $kind === 'root') {
            continue;
        }
        if (!empty($child['disabled'])) {
            continue;
        }
        if (!empty($child['config']['url'])) {
            continue; // link buttons only make sense as inline
        }
        $label = (string) ($child['label'] ?? '');
        if ($label === '') {
            $label = '⬜';
        }
        $rows[] = [['text' => $label]];
    }
    // Navigation as plain-text rows too (matched back in the handler).
    $cfg = $node['config'] ?? [];
    $isRoot = ($node['id'] === flow_root_id($tree));
    $navRow = [];
    if (!$isRoot && !empty($node['parent']) && !empty($cfg['auto_back'])) {
        $navRow[] = ['text' => '🔙 بازگشت'];
    }
    if (!$isRoot && !empty($cfg['auto_home'])) {
        $navRow[] = ['text' => '🏠 منوی اصلی'];
    }
    if ($navRow) {
        $rows[] = $navRow;
    }
    if (empty($rows)) {
        return null;
    }
    return ['keyboard' => $rows, 'resize_keyboard' => true];
}

/**
 * Find a direct child of $parentNode whose visible label matches $text (used to
 * resolve reply-keyboard taps back to a node). Returns the child node or null.
 * Also recognises the auto nav labels and returns the sentinel ids
 * '__flowback__' / '__flowhome__'.
 */
function flow_match_child_by_text(array $tree, array $parentNode, $text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return null;
    }
    if ($text === '🔙 بازگشت') {
        return ['id' => '__flowback__'];
    }
    if ($text === '🏠 منوی اصلی') {
        return ['id' => '__flowhome__'];
    }
    foreach (flow_children($tree, $parentNode['id']) as $child) {
        $kind = (string) ($child['node_kind'] ?? 'user');
        if ($kind === 'system_menu' || $kind === 'system_demo' || $kind === 'root') {
            continue;
        }
        if (!empty($child['disabled'])) {
            continue;
        }
        if (trim((string) ($child['label'] ?? '')) === $text) {
            return $child;
        }
    }
    return null;
}

/** The text shown for a node (message, or a fallback to the label). */
function flow_runtime_text(array $node)
{
    $cfg = $node['config'] ?? [];
    $msg = trim((string) ($cfg['message'] ?? ''));
    if ($msg !== '') {
        return $msg;
    }
    $label = trim((string) ($node['label'] ?? ''));
    return $label !== '' ? $label : '—';
}

/* -------------------------------------------------------------------------
 * Engine: enter a node + execute its type
 * ---------------------------------------------------------------------- */

/**
 * Move the user to $nodeId and render/execute it. Returns true when handled.
 * $ctx carries the live request context from index.php (from_id, user row,
 * keyboard, last_value for conditions, etc.).
 */
function flow_runtime_enter(array $tree, $nodeId, array &$state, array $ctx)
{
    $from_id = $ctx['from_id'];
    $node = flow_node($tree, $nodeId);
    if (!$node) {
        // Unknown node — bail back to root so the user is never stuck.
        $nodeId = flow_root_id($tree);
        $node = flow_node($tree, $nodeId);
        if (!$node) {
            return false;
        }
    }

    $state['current_node'] = (string) $node['id'];
    $state['await_node']   = '';
    $state['path']         = flow_path_ids($tree, $node['id']);

    $type = (string) ($node['type'] ?? 'button');

    switch ($type) {
        case 'action':
            return flow_runtime_exec_action($tree, $node, $state, $ctx);

        case 'input':
            return flow_runtime_exec_input($tree, $node, $state, $ctx);

        case 'condition':
            return flow_runtime_exec_condition($tree, $node, $state, $ctx);

        case 'n8n':
            return flow_runtime_exec_n8n($tree, $node, $state, $ctx);

        case 'button':
        case 'message':
        default:
            $mode = flow_node_display_mode($node);
            $text = flow_runtime_text($node);
            if ($mode === 'reply') {
                // Reply-keyboard only. If there's nothing to show as reply
                // (e.g. all children are URL buttons), fall back to inline so
                // the user is never left without buttons.
                $rkb = flow_runtime_reply_keyboard($tree, $node);
                if ($rkb !== null) {
                    sendmessage($from_id, $text, json_encode($rkb, JSON_UNESCAPED_UNICODE), 'HTML');
                } else {
                    $kb = flow_runtime_keyboard($tree, $node);
                    sendmessage($from_id, $text, json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
                }
            } elseif ($mode === 'both') {
                // Inline carries the message; a follow-up line installs the
                // reply keyboard (Telegram can't attach both to one message).
                $kb = flow_runtime_keyboard($tree, $node);
                sendmessage($from_id, $text, json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
                $rkb = flow_runtime_reply_keyboard($tree, $node);
                if ($rkb !== null) {
                    sendmessage($from_id, '⌨️', json_encode($rkb, JSON_UNESCAPED_UNICODE), 'HTML');
                }
            } else { // 'inline' (default)
                $kb = flow_runtime_keyboard($tree, $node);
                sendmessage($from_id, $text, json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
            }
            flow_state_save($state);
            return true;
    }
}

/**
 * Action node: hand control back to a built-in bot action (e.g. "buy",
 * "account"). To stay safe inside the single-pass if/elseif dispatch in
 * index.php, we DON'T try to re-enter legacy code mid-chain. Instead we render
 * an inline button carrying the builtin callback_data, so when the user taps it
 * Telegram re-dispatches cleanly and the legacy handler runs normally. We also
 * keep the flow's own back/home navigation alongside it.
 */
function flow_runtime_exec_action(array $tree, array $node, array &$state, array $ctx)
{
    $from_id = $ctx['from_id'];
    $cfg = $node['config'] ?? [];
    $key = trim((string) ($cfg['action_key'] ?? ''));

    $kb = flow_runtime_keyboard($tree, $node);

    if ($key !== '') {
        // Prepend a button that triggers the builtin action via its callback.
        $btnLabel = trim((string) ($node['label'] ?? '')) ?: 'ادامه';
        array_unshift($kb['inline_keyboard'], [
            ['text' => '▶️ ' . $btnLabel, 'callback_data' => $key],
        ]);
    }

    sendmessage($from_id, flow_runtime_text($node), json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
    flow_state_save($state);
    return true;
}

/**
 * Input node: prompt the user and mark await_node so the next message is
 * captured + validated. Resets the attempt counter when the window expires.
 */
function flow_runtime_exec_input(array $tree, array $node, array &$state, array $ctx)
{
    $from_id = $ctx['from_id'];
    $cfg = $node['config'] ?? [];

    $prompt = trim((string) ($cfg['prompt'] ?? ''));
    if ($prompt === '') {
        $prompt = flow_runtime_text($node);
    }

    // Reset attempt window if elapsed.
    $window = (int) ($cfg['attempt_window_sec'] ?? 0);
    if ($window > 0) {
        $resetAt = (int) ($state['attempts_reset_at'] ?? 0);
        if ($resetAt === 0 || time() > $resetAt) {
            $state['attempts'] = 0;
            $state['attempts_reset_at'] = (string) (time() + $window);
        }
    } else {
        $state['attempts'] = 0;
    }

    $state['await_node'] = (string) $node['id'];

    // force_reply asks Telegram to pop the reply UI.
    $rm = null;
    if (!empty($cfg['force_reply'])) {
        $rm = json_encode(['force_reply' => true, 'input_field_placeholder' => mb_substr($prompt, 0, 64)], JSON_UNESCAPED_UNICODE);
    }
    sendmessage($from_id, $prompt, $rm, 'HTML');
    flow_state_save($state);
    return true;
}

/**
 * Condition node: resolve the value to test (last input / a named input / n8n
 * field), evaluate branches, and jump to the matching branch's goto child.
 */
function flow_runtime_exec_condition(array $tree, array $node, array &$state, array $ctx)
{
    $from_id = $ctx['from_id'];
    $cfg = $node['config'] ?? [];

    $msg = trim((string) ($cfg['message'] ?? ''));
    if ($msg !== '') {
        sendmessage($from_id, $msg, null, 'HTML');
    }

    $src = (string) ($cfg['condition_source'] ?? 'last');
    $key = (string) ($cfg['condition_source_key'] ?? '');
    $inputs = $state['inputs'] ?? [];
    $value = '';
    $valid = $state['inputs']['__last_valid'] ?? null;

    if ($src === 'input' && $key !== '') {
        $value = (string) ($inputs[$key] ?? '');
    } elseif ($src === 'n8n') {
        $resp = $inputs['__n8n_last'] ?? [];
        $value = $key !== '' ? (string) ($resp[$key] ?? '') : (string) ($inputs['__last'] ?? '');
        if (isset($resp['ok'])) {
            $valid = (bool) $resp['ok'];
        }
    } else { // last
        $value = (string) ($inputs['__last'] ?? '');
    }

    $idx = flow_eval_condition($cfg, $value, $valid);
    $branches = $cfg['branches'] ?? [];
    $goto = '';
    if ($idx >= 0 && isset($branches[$idx])) {
        $goto = (string) ($branches[$idx]['goto'] ?? '');
    }
    if ($goto !== '' && flow_node($tree, $goto)) {
        return flow_runtime_enter($tree, $goto, $state, $ctx);
    }

    // No branch matched / no target: fall back to showing children.
    $kb = flow_runtime_keyboard($tree, $node);
    sendmessage($from_id, flow_runtime_text($node), json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
    flow_state_save($state);
    return true;
}

/**
 * n8n node: build + send the payload. async = fire-and-forget (instant);
 * sync = wait for a response, store it, then auto-advance to the first child
 * (or a condition reading __n8n_last).
 */
function flow_runtime_exec_n8n(array $tree, array $node, array &$state, array $ctx)
{
    $from_id = $ctx['from_id'];
    $cfg = $node['config'] ?? [];

    $wait = trim((string) ($cfg['wait_message'] ?? ''));
    if ($wait !== '') {
        sendmessage($from_id, $wait, null, 'HTML');
    }

    $payloadCtx = [
        'bot_id'  => (int) flow_bot_id(),
        'user_id' => (string) $from_id,
        'node_id' => (string) $node['id'],
        'tag'     => (string) ($cfg['n8n_tag'] ?? ''),
        'mode'    => (string) ($cfg['n8n_mode'] ?? 'async'),
        'inputs'  => $state['inputs'] ?? [],
    ];
    $payload = flow_n8n_payload($tree, $cfg, $payloadCtx);
    $res = flow_n8n_send($cfg, $payload);

    // Store the response so a downstream condition / message can read it.
    $state['inputs']['__n8n_last'] = is_array($res) ? $res : ['ok' => (bool) $res];

    // Advance to the first child (commonly a condition reading __n8n_last).
    $children = flow_children($tree, $node['id']);
    if (!empty($children)) {
        return flow_runtime_enter($tree, $children[0]['id'], $state, $ctx);
    }

    flow_state_save($state);
    return true;
}

/* -------------------------------------------------------------------------
 * Awaited-input capture
 * ---------------------------------------------------------------------- */

/**
 * The user is sitting on an input node (await_node set) and just sent a
 * message. Validate it, store it, and advance to the input node's child.
 * On failure, re-prompt (respecting max_attempts). Returns true when handled.
 */
function flow_runtime_capture_input(array $tree, array &$state, array $ctx)
{
    $from_id = $ctx['from_id'];
    $awaitId = (string) ($state['await_node'] ?? '');
    $node = flow_node($tree, $awaitId);
    if (!$node || ($node['type'] ?? '') !== 'input') {
        $state['await_node'] = '';
        return false;
    }
    $cfg = $node['config'] ?? [];
    $mode = (string) ($cfg['input_mode'] ?? 'text');

    $hasPhoto = !empty($ctx['photo']);
    $hasDoc   = !empty($ctx['document']);
    $text     = (string) ($ctx['text'] ?? '');
    $caption  = (string) ($ctx['caption'] ?? '');

    $ok = true;
    $err = '';
    $storeValue = '';
    $valid = true;

    // --- File modes ---
    if (in_array($mode, ['photo', 'document', 'media'], true)) {
        $isFileMsg = $hasPhoto || $hasDoc;
        if (!$isFileMsg) {
            $ok = false;
            $err = 'لطفاً یک فایل/عکس ارسال کنید.';
        } else {
            if ($mode === 'photo' && !$hasPhoto) {
                $ok = false; $err = 'فقط عکس قابل قبول است.';
            } elseif ($mode === 'document' && !$hasDoc) {
                $ok = false; $err = 'فقط فایل (سند) قابل قبول است.';
            } else {
                // Validate document by extension + size when present.
                if ($hasDoc) {
                    $fname = (string) ($ctx['doc_name'] ?? '');
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    $mime = (string) ($ctx['doc_mime'] ?? '');
                    $size = (int) ($ctx['doc_size'] ?? 0);
                    $chk = flow_validate_input_file($cfg, $ext, $mime, $size);
                    if (!$chk['ok']) {
                        $ok = false;
                        $err = (string) $chk['error'];
                    } else {
                        $storeValue = (string) ($ctx['fileid'] ?? '');
                    }
                } else {
                    $storeValue = (string) ($ctx['photoid'] ?? '');
                }
            }
        }
        // text/caption tag along when valid
        if ($ok && $caption !== '' && !empty($cfg['sanitize_text'])) {
            $caption = flow_sanitize_text($caption);
        }
    } elseif ($mode === 'any') {
        // accept anything; prefer text else file id
        if ($text !== '') {
            $vt = flow_validate_input_text($cfg, $text);
            $ok = $vt['ok'];
            $err = (string) ($vt['error'] ?? '');
            $storeValue = (string) ($vt['value'] ?? $text);
        } elseif ($hasPhoto) {
            $storeValue = (string) ($ctx['photoid'] ?? '');
        } elseif ($hasDoc) {
            $fname = (string) ($ctx['doc_name'] ?? '');
            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
            $chk = flow_validate_input_file($cfg, $ext, (string) ($ctx['doc_mime'] ?? ''), (int) ($ctx['doc_size'] ?? 0));
            $ok = $chk['ok'];
            $err = (string) ($chk['error'] ?? '');
            $storeValue = (string) ($ctx['fileid'] ?? '');
        } else {
            $ok = false;
            $err = 'ورودی نامعتبر است.';
        }
    } else { // text
        if ($text === '') {
            $ok = false;
            $err = 'لطفاً یک متن ارسال کنید.';
        } else {
            $vt = flow_validate_input_text($cfg, $text);
            $ok = $vt['ok'];
            $err = (string) ($vt['error'] ?? '');
            $storeValue = (string) ($vt['value'] ?? $text);
        }
    }

    $valid = $ok;

    if (!$ok) {
        // Attempt accounting.
        $max = (int) ($cfg['max_attempts'] ?? 0);
        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        if ($max > 0 && $state['attempts'] >= $max) {
            // Too many failures — drop them to home to avoid a trap.
            $errMsg = trim((string) ($cfg['error_message'] ?? ''));
            sendmessage($from_id, ($errMsg !== '' ? $errMsg . "\n" : '') . '⛔️ تعداد تلاش‌ها به پایان رسید.', null, 'HTML');
            $root = flow_root_id($tree);
            $state['attempts'] = 0;
            $state['await_node'] = '';
            return flow_runtime_enter($tree, $root, $state, $ctx);
        }
        $errMsg = trim((string) ($cfg['error_message'] ?? ''));
        sendmessage($from_id, ($errMsg !== '' ? $errMsg : $err), null, 'HTML');
        flow_state_save($state); // keep awaiting
        return true;
    }

    // Success: store input under its tag/key + __last, reset attempts.
    $tag = trim((string) ($cfg['input_tag'] ?? ''));
    if ($tag !== '') {
        $state['inputs'][$tag] = $storeValue;
    }
    $state['inputs']['__last'] = $storeValue;
    $state['inputs']['__last_valid'] = $valid;
    $state['attempts'] = 0;
    $state['await_node'] = '';

    // Advance to the input node's first child (often a condition).
    $children = flow_children($tree, $node['id']);
    if (!empty($children)) {
        return flow_runtime_enter($tree, $children[0]['id'], $state, $ctx);
    }
    flow_state_save($state);
    return true;
}

/* -------------------------------------------------------------------------
 * Entry point — called from index.php
 * ---------------------------------------------------------------------- */

/**
 * Single integration point. Returns:
 *   false -> not handled, let legacy dispatch run
 *   true  -> fully handled, index.php should return
 *
 * Action nodes that map to a builtin render an inline button carrying the
 * builtin callback_data; when tapped, that callback is NOT a flow* prefix so it
 * falls through to the matching legacy handler cleanly (no mid-chain re-entry).
 *
 * $ctx must contain at least: from_id, text, datain, photo, document, fileid,
 * photoid, caption, doc_name, doc_mime, doc_size, user.
 */
function flow_runtime_handle(array $ctx)
{
    $from_id = $ctx['from_id'] ?? 0;
    if (!$from_id) {
        return false;
    }

    // Regression guard: do nothing unless a real flow is configured.
    if (!flow_is_active()) {
        return false;
    }

    $tree = get_button_flow();
    $datain = (string) ($ctx['datain'] ?? '');
    $text   = (string) ($ctx['text'] ?? '');

    $state = flow_state_get($from_id);

    // ---- Navigation callbacks -------------------------------------------
    if ($datain === 'flowhome') {
        flow_state_clear($from_id);
        $fresh = flow_state_get($from_id);
        flow_runtime_enter($tree, flow_root_id($tree), $fresh, $ctx);
        return true;
    }

    if ($datain === 'flowback') {
        $node = flow_node($tree, $state['current_node'] ?? '');
        $parent = (is_array($node) && !empty($node['parent'])) ? (string) $node['parent'] : '';
        $target = ($parent !== '' && flow_node($tree, $parent)) ? $parent : flow_root_id($tree);
        flow_runtime_enter($tree, $target, $state, $ctx);
        return true;
    }

    if (preg_match('/^flowgo_(.+)$/', $datain, $m)) {
        flow_runtime_enter($tree, $m[1], $state, $ctx);
        return true;
    }

    // ---- Reply-keyboard tap on a flow node ------------------------------
    // When the user is parked on a flow node whose children are shown as a
    // REPLY keyboard, taps arrive as plain text (no callback_data). Resolve the
    // text back to the matching child (or the nav labels) and navigate. We only
    // act on an EXACT match against the current node's own children, so normal
    // bot text never gets hijacked.
    if ($datain === '' && $text !== '' && !empty($state['current_node'])) {
        $cur = flow_node($tree, (string) $state['current_node']);
        if (is_array($cur)) {
            $hit = flow_match_child_by_text($tree, $cur, $text);
            if (is_array($hit) && !empty($hit['id'])) {
                if ($hit['id'] === '__flowhome__') {
                    flow_state_clear($from_id);
                    $fresh = flow_state_get($from_id);
                    flow_runtime_enter($tree, flow_root_id($tree), $fresh, $ctx);
                    return true;
                }
                if ($hit['id'] === '__flowback__') {
                    $parent = !empty($cur['parent']) ? (string) $cur['parent'] : '';
                    $target = ($parent !== '' && flow_node($tree, $parent)) ? $parent : flow_root_id($tree);
                    flow_runtime_enter($tree, $target, $state, $ctx);
                    return true;
                }
                flow_runtime_enter($tree, (string) $hit['id'], $state, $ctx);
                return true;
            }
        }
    }

    // ---- Awaited input (user typed/sent something on an input node) ------
    if (!empty($state['await_node']) && $datain === '') {
        // Only treat real incoming content as input.
        if ($text !== '' || !empty($ctx['photo']) || !empty($ctx['document'])) {
            flow_runtime_capture_input($tree, $state, $ctx);
            return true;
        }
    }

    // Not a flow interaction — let legacy dispatch handle it.
    return false;
}

/* -------------------------------------------------------------------------
 * Main-menu follow-up: show an admin's custom child nodes that hang off a
 * real menu button.
 * ---------------------------------------------------------------------- */

/**
 * Resolve a real-menu node by the callback the bot uses for it (e.g. "buy",
 * "supportbtns") OR by the visible button label/menu_key. Returns the node or
 * null. Only system_menu nodes are considered.
 */
function flow_find_menu_node(array $tree, $callback = '', $label = '')
{
    $callback = (string) $callback;
    $label    = trim((string) $label);
    foreach ($tree['nodes'] as $n) {
        if (($n['node_kind'] ?? '') !== 'system_menu') {
            continue;
        }
        $cb = (string) ($n['config']['callback'] ?? '');
        if ($callback !== '' && $cb !== '' && $cb === $callback) {
            return $n;
        }
        if ($label !== '') {
            if (trim((string) ($n['label'] ?? '')) === $label) {
                return $n;
            }
            if (trim((string) ($n['menu_key'] ?? '')) === $label) {
                return $n;
            }
        }
    }
    return null;
}

/**
 * When a user taps a REAL main-menu button (e.g. «سرویس‌های من»), the bot runs
 * its built-in logic as usual. If the admin has attached their own (user) child
 * nodes to that menu button in the flow, we ALSO send a small follow-up message
 * with those children as inline buttons — without touching/blocking the bot's
 * native handling. This is what makes "add a child to a real menu button" work
 * for BOTH inline and reply keyboards.
 *
 * Returns true if a follow-up was sent (caller may ignore the result; it never
 * blocks legacy dispatch). Safe no-op when no flow / no matching node / no
 * user children.
 *
 * @param mixed $callback  the bot callback_data (inline taps), '' for reply text
 * @param mixed $label     the visible button text (reply-keyboard taps)
 */
function flow_runtime_menu_followup($from_id, $callback = '', $label = '')
{
    if (!$from_id || !flow_is_active()) {
        return false;
    }
    $tree = get_button_flow();
    $menu = flow_find_menu_node($tree, $callback, $label);
    if (!$menu) {
        return false;
    }
    // Don't surface children of a menu button the admin disabled.
    if (!empty($menu['disabled'])) {
        return false;
    }

    // Does this menu button have any of the admin's own (enabled) children?
    $children = flow_children($tree, $menu['id']);
    $hasUserChild = false;
    foreach ($children as $child) {
        if ((string) ($child['node_kind'] ?? 'user') === 'user' && empty($child['disabled'])) {
            $hasUserChild = true;
            break;
        }
    }
    if (!$hasUserChild) {
        return false;
    }

    // Whether to also SUPPRESS the bot's native behaviour for this button
    // (e.g. when the admin only wants their own children to show and finds the
    // built-in message noisy). The caller checks the return value to decide
    // whether to `return` before legacy dispatch.
    $suppress = !empty($menu['config']['suppress_native']);

    // Park the user on the menu node so subsequent reply-keyboard taps resolve
    // against its children (flow_match_child_by_text).
    $state = flow_state_get($from_id);
    $state['current_node'] = (string) $menu['id'];
    $state['await_node']   = '';
    $state['path']         = flow_path_ids($tree, $menu['id']);
    flow_state_save($state);

    // Render the children honouring the menu node's display_mode, reusing the
    // same engine the rest of the flow uses (inline / reply / both).
    $ctx = ['from_id' => $from_id];
    $mode = flow_node_display_mode($menu);
    $header = trim((string) ($menu['config']['message'] ?? ''));
    if ($header === '') {
        $header = '➕ گزینه‌های بیشتر:';
    }

    if ($mode === 'reply') {
        $rkb = flow_runtime_reply_keyboard($tree, $menu);
        if ($rkb !== null) {
            sendmessage($from_id, $header, json_encode($rkb, JSON_UNESCAPED_UNICODE), 'HTML');
        } else {
            $kb = flow_runtime_keyboard($tree, $menu);
            sendmessage($from_id, $header, json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
        }
    } elseif ($mode === 'both') {
        $kb = flow_runtime_keyboard($tree, $menu);
        sendmessage($from_id, $header, json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
        $rkb = flow_runtime_reply_keyboard($tree, $menu);
        if ($rkb !== null) {
            sendmessage($from_id, '⌨️', json_encode($rkb, JSON_UNESCAPED_UNICODE), 'HTML');
        }
    } else { // 'inline' (default)
        $kb = flow_runtime_keyboard($tree, $menu);
        sendmessage($from_id, $header, json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
    }

    // Return 'suppress' so the caller stops native dispatch; true otherwise.
    return $suppress ? 'suppress' : true;
}
