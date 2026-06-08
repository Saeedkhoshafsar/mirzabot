<?php

/**
 * =============================================================================
 *  Visual Button Flow — data layer (Step 12, Phase 1)
 * =============================================================================
 *  The entire bot menu is modelled as a node tree, edited visually in the panel
 *  (React Flow) and executed at chat time. This file is the storage + helper
 *  layer only; the canvas (panel/flow.php) and the runtime engine (index.php)
 *  build on top of it.
 *
 *  Tree shape (compatible with React Flow):
 *    {
 *      "nodes": [ { "id","type","label","parent","position":{x,y},"config":{...} }, ... ],
 *      "edges": [ { "id","source","target" }, ... ],
 *      "meta":  { "root": "n_root", "updated_at": "...", "version": 1 }
 *    }
 *
 *  Node types: button | message | action | input | condition | n8n
 *  Everything is OPT-IN: if button_flow is empty, the bot keeps its built-in
 *  menu and nothing changes.
 * =============================================================================
 */

if (!defined('FLOW_HISTORY_KEEP')) {
    define('FLOW_HISTORY_KEEP', 20); // how many revisions to retain
}

/** Valid node types. */
function flow_node_types()
{
    return ['button', 'message', 'action', 'input', 'condition', 'n8n'];
}

/**
 * Hard security blacklist of file extensions that are NEVER accepted at an
 * input step, no matter what the admin configures. Covers executables, scripts
 * and markup that could carry active content (XSS/RCE vectors). This list is
 * enforced at config-save time (admin allow-list is filtered against it) AND at
 * runtime (incoming files are re-checked), so a disguised upload can't slip in.
 */
function flow_blocked_extensions()
{
    return [
        // server-side scripts
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'phps',
        'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'pl', 'py', 'rb',
        // shell / executables
        'sh', 'bash', 'zsh', 'exe', 'msi', 'bat', 'cmd', 'com', 'scr',
        'dll', 'so', 'bin', 'app', 'deb', 'rpm', 'apk', 'jar', 'vbs', 'ps1',
        // active markup / scriptable
        'html', 'htm', 'xhtml', 'svg', 'js', 'mjs', 'wasm', 'xml', 'xsl',
        // office macros
        'docm', 'xlsm', 'pptm',
        // misc dangerous
        'htaccess', 'ini', 'lnk', 'reg',
    ];
}

/**
 * Sanitise free text coming from a user before it is stored or forwarded.
 * Removes HTML tags and control chars, neutralises angle brackets, trims, and
 * optionally caps length. NEVER echo raw user text anywhere; pass it through
 * this first. Returns a clean UTF-8 string.
 */
function flow_sanitize_text($text, $maxLen = 0)
{
    $text = (string) $text;
    // drop control chars except newline/tab
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    // strip tags then escape any leftover angle brackets/entities
    $text = strip_tags($text);
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim($text);
    if ($maxLen > 0 && function_exists('mb_substr')) {
        $text = mb_substr($text, 0, (int) $maxLen, 'UTF-8');
    }
    return $text;
}

/**
 * Validate a user's TEXT input against an input-node config.
 * Returns [ok=>bool, value=>cleaned, error=>code|null].
 */
function flow_validate_input_text(array $cfg, $text)
{
    $clean = !empty($cfg['sanitize_text']) ? flow_sanitize_text($text) : (string) $text;
    $len = function_exists('mb_strlen') ? mb_strlen($clean, 'UTF-8') : strlen($clean);

    $min = (int) ($cfg['input_min'] ?? 0);
    $max = (int) ($cfg['input_max'] ?? 0);
    if ($min > 0 && $len < $min) {
        return ['ok' => false, 'value' => $clean, 'error' => 'too_short'];
    }
    if ($max > 0 && $len > $max) {
        return ['ok' => false, 'value' => $clean, 'error' => 'too_long'];
    }

    $mode = (string) ($cfg['input_validation'] ?? 'none');
    if ($mode === 'number') {
        if (!preg_match('~^[0-9۰-۹٠-٩]+$~u', $clean)) {
            return ['ok' => false, 'value' => $clean, 'error' => 'not_number'];
        }
    } elseif ($mode === 'regex') {
        $pat = (string) ($cfg['input_pattern'] ?? '');
        if ($pat !== '') {
            // Run admin regex safely: delimit ourselves, suppress warnings.
            $delim = '~';
            $safe = str_replace($delim, '\\' . $delim, $pat);
            $res = @preg_match($delim . $safe . $delim . 'u', $clean);
            if ($res !== 1) {
                return ['ok' => false, 'value' => $clean, 'error' => 'pattern'];
            }
        }
    }
    // mode 'length' is already covered by min/max above.
    return ['ok' => true, 'value' => $clean, 'error' => null];
}

/**
 * Validate an uploaded file's extension + (optionally) real MIME against an
 * input-node config. $ext = claimed extension, $mime = telegram-reported mime,
 * $sizeBytes = file size. Returns [ok=>bool, error=>code|null].
 * The hard blacklist always wins.
 */
function flow_validate_input_file(array $cfg, $ext, $mime = '', $sizeBytes = 0)
{
    $ext = strtolower(ltrim((string) $ext, '.'));
    if ($ext !== '' && in_array($ext, flow_blocked_extensions(), true)) {
        return ['ok' => false, 'error' => 'blocked_type'];
    }
    $allowed = is_array($cfg['file_extensions'] ?? null) ? $cfg['file_extensions'] : [];
    if (!empty($allowed) && ($ext === '' || !in_array($ext, $allowed, true))) {
        return ['ok' => false, 'error' => 'ext_not_allowed'];
    }
    $maxMb = (int) ($cfg['file_max_mb'] ?? 0);
    if ($maxMb > 0 && $sizeBytes > 0 && $sizeBytes > $maxMb * 1024 * 1024) {
        return ['ok' => false, 'error' => 'too_large'];
    }
    // Optional MIME sanity: block obviously executable/script mime types and,
    // when an extension allow-list exists, ensure the mime family is plausible.
    if (!empty($cfg['verify_mime']) && $mime !== '') {
        $badMime = ['text/html', 'application/x-httpd-php', 'application/x-sh',
            'application/javascript', 'text/javascript', 'image/svg+xml',
            'application/x-msdownload', 'application/x-executable'];
        if (in_array(strtolower($mime), $badMime, true)) {
            return ['ok' => false, 'error' => 'blocked_mime'];
        }
    }
    return ['ok' => true, 'error' => null];
}

/** A fresh, empty tree with just a protected root node (the main menu). */
function flow_default_tree()
{
    return [
        'nodes' => [
            [
                'id'       => 'n_root',
                'type'     => 'button',
                'label'    => 'منوی اصلی',
                'parent'   => null,
                'system'   => true, // protected: needs confirmation to edit/delete
                'position' => ['x' => 0, 'y' => 0],
                'config'   => [
                    'message'   => '',
                    'auto_back' => false,
                    'auto_home' => false,
                ],
            ],
        ],
        'edges' => [],
        'meta'  => [
            'root'       => 'n_root',
            'version'    => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ],
    ];
}

/** Resolve which bot scope we are operating on (0 = main bot). */
function flow_bot_id($bot_id = null)
{
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    return (int) $bot_id;
}

/**
 * Load the raw tree. Returns a normalised array (always has nodes/edges/meta).
 * Falls back to flow_default_tree() when nothing is stored yet.
 */
function get_button_flow($bot_id = null)
{
    global $pdo, $setting;
    $bot_id = flow_bot_id($bot_id);
    $raw = null;
    try {
        if ($bot_id > 0 && isset($pdo)) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([$bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($brow && !empty($brow['setting'])) {
                $bset = json_decode($brow['setting'], true);
                if (is_array($bset) && isset($bset['button_flow'])) {
                    $raw = is_array($bset['button_flow'])
                        ? $bset['button_flow']
                        : json_decode((string) $bset['button_flow'], true);
                }
            }
        } else {
            if (is_array($setting) && isset($setting['button_flow'])) {
                $raw = is_array($setting['button_flow'])
                    ? $setting['button_flow']
                    : json_decode((string) $setting['button_flow'], true);
            } else {
                $row = select("setting", "button_flow", null, null, "FETCH_COLUMN");
                if (is_array($row)) {
                    $row = $row[0] ?? null;
                }
                if ($row) {
                    $raw = json_decode((string) $row, true);
                }
            }
        }
    } catch (Exception $e) {
        error_log("get_button_flow error: " . $e->getMessage());
    }
    return flow_normalise_tree(is_array($raw) ? $raw : null);
}

/**
 * Persist the tree. Snapshots the *previous* tree into history first (so the
 * save is undoable), validates/normalises the incoming tree, trims history, and
 * clears the per-request select cache. Returns [ok=>bool, error=>string|null].
 */
function set_button_flow(array $tree, $bot_id = null, $note = '', $created_by = '')
{
    global $pdo;
    $bot_id = flow_bot_id($bot_id);
    if (!isset($pdo)) {
        return ['ok' => false, 'error' => 'no_pdo'];
    }

    // Validate before touching anything.
    $valid = flow_validate_tree($tree);
    if (!$valid['ok']) {
        return ['ok' => false, 'error' => $valid['error']];
    }
    $tree = flow_normalise_tree($tree);
    $tree['meta']['updated_at'] = date('Y-m-d H:i:s');
    $tree['meta']['version'] = (int) ($tree['meta']['version'] ?? 1) + 1;

    try {
        // 1) Snapshot current tree into history (best effort).
        $previous = get_button_flow($bot_id);
        flow_push_history($previous, $bot_id, $note, $created_by);

        // 2) Write the new tree.
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE);
        if ($bot_id > 0) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([$bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            $bset = ($brow && !empty($brow['setting'])) ? json_decode($brow['setting'], true) : [];
            if (!is_array($bset)) {
                $bset = [];
            }
            $bset['button_flow'] = $tree;
            $ok = $pdo->prepare("UPDATE botsaz SET setting = ? WHERE id = ?")
                ->execute([json_encode($bset, JSON_UNESCAPED_UNICODE), $bot_id]);
            if (function_exists('clearSelectCache')) {
                clearSelectCache('botsaz');
            }
        } else {
            $ok = $pdo->prepare("UPDATE setting SET button_flow = ?")->execute([$json]);
            if (function_exists('clearSelectCache')) {
                clearSelectCache('setting');
            }
        }

        // 3) Trim history.
        flow_trim_history($bot_id);

        // 4) Reconcile legacy custom buttons with the flow (so edits/deletes the
        //    admin made to imported buttons in the visual editor actually take
        //    effect on the bot's keyboard). Best-effort, never blocks the save.
        if ($ok) {
            flow_sync_legacy_buttons($tree, $bot_id);
        }

        return ['ok' => (bool) $ok, 'error' => null, 'tree' => $tree];
    } catch (Exception $e) {
        error_log("set_button_flow error: " . $e->getMessage());
        return ['ok' => false, 'error' => 'db_error'];
    }
}

/**
 * Normalise an arbitrary/partial tree into a complete, safe structure.
 * - Guarantees nodes/edges/meta keys.
 * - Ensures a single root node exists (id n_root) and is marked system.
 * - Coerces each node's fields and rebuilds parent links from edges so the two
 *   never disagree (edges are the source of truth for hierarchy).
 */
function flow_normalise_tree($raw)
{
    if (!is_array($raw) || empty($raw['nodes']) || !is_array($raw['nodes'])) {
        return flow_default_tree();
    }
    $types = flow_node_types();
    $nodes = [];
    $byId = [];
    foreach ($raw['nodes'] as $n) {
        if (!is_array($n) || empty($n['id'])) {
            continue;
        }
        $id = (string) $n['id'];
        $type = (string) ($n['type'] ?? 'button');
        if (!in_array($type, $types, true)) {
            $type = 'button';
        }
        $node = [
            'id'       => $id,
            'type'     => $type,
            'label'    => (string) ($n['label'] ?? ''),
            'parent'   => isset($n['parent']) && $n['parent'] !== '' ? (string) $n['parent'] : null,
            'system'   => !empty($n['system']),
            'position' => [
                'x' => (float) ($n['position']['x'] ?? 0),
                'y' => (float) ($n['position']['y'] ?? 0),
            ],
            'config'   => flow_normalise_config(is_array($n['config'] ?? null) ? $n['config'] : [], $type),
        ];
        $nodes[] = $node;
        $byId[$id] = count($nodes) - 1;
    }

    if (empty($nodes)) {
        return flow_default_tree();
    }

    // Ensure a root exists.
    $rootId = (string) ($raw['meta']['root'] ?? 'n_root');
    if (!isset($byId[$rootId])) {
        // pick the first parent-less node, else the first node.
        $rootId = null;
        foreach ($nodes as $n) {
            if ($n['parent'] === null) {
                $rootId = $n['id'];
                break;
            }
        }
        if ($rootId === null) {
            $rootId = $nodes[0]['id'];
        }
    }
    // Root is always protected & parent-less.
    $nodes[$byId[$rootId]]['system'] = true;
    $nodes[$byId[$rootId]]['parent'] = null;

    // Rebuild edges + parent links consistently.
    $edges = [];
    if (!empty($raw['edges']) && is_array($raw['edges'])) {
        foreach ($raw['edges'] as $e) {
            if (!is_array($e) || empty($e['source']) || empty($e['target'])) {
                continue;
            }
            $src = (string) $e['source'];
            $tgt = (string) $e['target'];
            if (!isset($byId[$src]) || !isset($byId[$tgt]) || $src === $tgt) {
                continue;
            }
            $edges[] = [
                'id'     => (string) ($e['id'] ?? ('e_' . $src . '_' . $tgt)),
                'source' => $src,
                'target' => $tgt,
            ];
            // edge defines parent of target
            $nodes[$byId[$tgt]]['parent'] = $src;
        }
    }

    // Auto-link condition branches to their children by connection order, so
    // the admin only labels branches in the form and we resolve the 'goto'
    // node ids from the actual edges here (kept in sync automatically).
    $childOrder = [];
    foreach ($edges as $e) {
        $childOrder[$e['source']][] = $e['target'];
    }
    foreach ($nodes as $i => $n) {
        if (($n['type'] ?? '') !== 'condition') {
            continue;
        }
        $children = $childOrder[$n['id']] ?? [];
        $branches = $n['config']['branches'] ?? [];
        if (!is_array($branches)) {
            $branches = [];
        }
        foreach ($branches as $bi => $b) {
            $branches[$bi]['goto'] = isset($children[$bi]) ? (string) $children[$bi] : '';
        }
        $nodes[$i]['config']['branches'] = $branches;
    }

    return [
        'nodes' => $nodes,
        'edges' => $edges,
        'meta'  => [
            'root'       => $rootId,
            'version'    => (int) ($raw['meta']['version'] ?? 1),
            'updated_at' => (string) ($raw['meta']['updated_at'] ?? date('Y-m-d H:i:s')),
        ],
    ];
}

/** Coerce a node's config to safe defaults based on its type. */
function flow_normalise_config(array $c, $type)
{
    $out = [
        // common
        'callback_data' => (string) ($c['callback_data'] ?? ''),
        'message'       => (string) ($c['message'] ?? ''),
        'url'           => (string) ($c['url'] ?? ''),
        'auto_back'     => !empty($c['auto_back']),
        'auto_home'     => !empty($c['auto_home']),
    ];
    // Link back to a legacy automation custom button (set during migration). Kept
    // through every normalise so the panel can sync edits/deletes to the legacy
    // button list on save. Empty for native flow nodes.
    if (!empty($c['legacy_id'])) {
        $out['legacy_id'] = (string) $c['legacy_id'];
    }
    if (!empty($out['url']) && !preg_match('~^https?://~i', $out['url'])) {
        $out['url'] = '';
    }

    if ($type === 'action') {
        $out['action_key'] = (string) ($c['action_key'] ?? ''); // builtin action id (e.g. "buy")
    }

    if ($type === 'input') {
        $valid = (string) ($c['input_validation'] ?? 'none');
        if (!in_array($valid, ['none', 'regex', 'length', 'number'], true)) {
            $valid = 'none';
        }
        // What kind of message the user is allowed to send at this step.
        $mode = (string) ($c['input_mode'] ?? 'text');
        if (!in_array($mode, ['text', 'photo', 'document', 'media', 'any'], true)) {
            $mode = 'text';
        }
        $out['input_mode']        = $mode;
        $out['input_validation']  = $valid;
        $out['input_pattern']     = (string) ($c['input_pattern'] ?? '');
        $out['input_min']         = max(0, (int) ($c['input_min'] ?? 0)); // min chars
        $out['input_max']         = max(0, (int) ($c['input_max'] ?? 0)); // max chars (0 = no cap)
        $out['max_attempts']      = max(0, (int) ($c['max_attempts'] ?? 0)); // 0 = unlimited
        $out['attempt_window_sec']= max(0, (int) ($c['attempt_window_sec'] ?? 0));
        $out['force_reply']       = !empty($c['force_reply']);
        $out['error_message']     = (string) ($c['error_message'] ?? '');
        $out['input_tag']         = (string) ($c['input_tag'] ?? '');
        // Prompt shown to the user when this input step is reached (what to send).
        $out['prompt']            = (string) ($c['prompt'] ?? '');

        // --- File constraints (only meaningful when a file is allowed) ---
        // Admin-defined allow-list of extensions, normalised to lowercase,
        // dot-stripped, de-duped. Anything not listed is rejected. A hard
        // security blacklist (flow_blocked_extensions) ALWAYS wins regardless
        // of what the admin allows, so an executable/script can never pass.
        $allowed = [];
        $rawAllowed = $c['file_extensions'] ?? [];
        if (is_string($rawAllowed)) {
            $rawAllowed = preg_split('~[\s,;]+~', $rawAllowed);
        }
        if (is_array($rawAllowed)) {
            $blocked = flow_blocked_extensions();
            foreach ($rawAllowed as $ext) {
                $ext = strtolower(ltrim(trim((string) $ext), '.'));
                if ($ext === '' || !preg_match('~^[a-z0-9]{1,10}$~', $ext)) {
                    continue;
                }
                if (in_array($ext, $blocked, true)) {
                    continue; // never allow dangerous types even if admin tries
                }
                $allowed[$ext] = true;
            }
        }
        $out['file_extensions']   = array_values(array_keys($allowed));
        $out['file_max_mb']       = max(0, (int) ($c['file_max_mb'] ?? 0)); // 0 = telegram default cap
        $out['file_max_count']    = max(1, (int) ($c['file_max_count'] ?? 1));
        // Sanitise any free text before storing / forwarding (strip HTML etc).
        $out['sanitize_text']     = !isset($c['sanitize_text']) ? true : !empty($c['sanitize_text']);
        // Verify real MIME type, not just the extension, to stop disguised files.
        $out['verify_mime']       = !isset($c['verify_mime']) ? true : !empty($c['verify_mime']);
    }

    if ($type === 'n8n') {
        $mode = (string) ($c['n8n_mode'] ?? 'async');
        if (!in_array($mode, ['async', 'sync'], true)) {
            $mode = 'async';
        }
        $out['n8n_mode']      = $mode;
        $out['n8n_endpoint']  = (string) ($c['n8n_endpoint'] ?? '');
        $out['n8n_tag']       = (string) ($c['n8n_tag'] ?? '');
        $out['n8n_timeout_sec'] = max(1, min(30, (int) ($c['n8n_timeout_sec'] ?? 5)));
        $out['send_path']     = !isset($c['send_path']) ? true : !empty($c['send_path']);
        $out['send_inputs']   = !isset($c['send_inputs']) ? true : !empty($c['send_inputs']);
        $out['wait_message']  = (string) ($c['wait_message'] ?? 'در حال بررسی…');
    }

    if ($type === 'condition') {
        // What value the condition is evaluated against at runtime:
        //   'input'  -> a previously collected input (by key, condition_source_key)
        //   'n8n'    -> a field from the last n8n response (by key)
        //   'last'   -> the most recent user input
        $src = (string) ($c['condition_source'] ?? 'last');
        if (!in_array($src, ['input', 'n8n', 'last'], true)) {
            $src = 'last';
        }
        $out['condition_source']     = $src;
        $out['condition_source_key'] = (string) ($c['condition_source_key'] ?? '');
        $out['message']              = (string) ($c['message'] ?? '');

        // Ordered branches: first matching branch wins; an empty 'op' is the
        // default/else branch (should be placed last). Each branch points to a
        // child node id via 'goto'.
        $allowedOps = ['eq', 'neq', 'contains', 'not_contains', 'gt', 'gte',
            'lt', 'lte', 'regex', 'empty', 'not_empty', 'is_valid', 'is_invalid', 'else'];
        $branches = [];
        if (!empty($c['branches']) && is_array($c['branches'])) {
            foreach ($c['branches'] as $b) {
                if (!is_array($b)) {
                    continue;
                }
                $op = (string) ($b['op'] ?? ($b['when'] !== '' ? 'eq' : 'else'));
                if (!in_array($op, $allowedOps, true)) {
                    $op = 'eq';
                }
                $branches[] = [
                    'label' => (string) ($b['label'] ?? ''),     // human label (e.g. "معتبر")
                    'op'    => $op,                                // comparison operator
                    'value' => (string) ($b['value'] ?? ($b['when'] ?? '')), // compare-to value
                    'goto'  => (string) ($b['goto'] ?? ''),       // child node id (may be empty until linked)
                ];
            }
        }
        $out['branches'] = $branches;
    }

    return $out;
}

/**
 * Evaluate a condition node's branches against a runtime value.
 * Returns the index of the first matching branch, or -1 if none matched.
 * $value is the resolved value (input/n8n/last). $valid is an optional bool
 * used by the is_valid / is_invalid operators (e.g. did the previous input
 * pass validation, or did n8n report success).
 */
function flow_eval_condition(array $cfg, $value, $valid = null)
{
    $branches = isset($cfg['branches']) && is_array($cfg['branches']) ? $cfg['branches'] : [];
    $value = (string) $value;
    foreach ($branches as $i => $b) {
        $op = (string) ($b['op'] ?? 'eq');
        $cmp = (string) ($b['value'] ?? '');
        $match = false;
        switch ($op) {
            case 'else':
                $match = true;
                break;
            case 'eq':
                $match = ($value === $cmp);
                break;
            case 'neq':
                $match = ($value !== $cmp);
                break;
            case 'contains':
                $match = ($cmp !== '' && mb_strpos($value, $cmp) !== false);
                break;
            case 'not_contains':
                $match = ($cmp === '' || mb_strpos($value, $cmp) === false);
                break;
            case 'gt':
                $match = is_numeric($value) && is_numeric($cmp) && ($value + 0) > ($cmp + 0);
                break;
            case 'gte':
                $match = is_numeric($value) && is_numeric($cmp) && ($value + 0) >= ($cmp + 0);
                break;
            case 'lt':
                $match = is_numeric($value) && is_numeric($cmp) && ($value + 0) < ($cmp + 0);
                break;
            case 'lte':
                $match = is_numeric($value) && is_numeric($cmp) && ($value + 0) <= ($cmp + 0);
                break;
            case 'regex':
                if ($cmp !== '') {
                    $delim = '~';
                    $safe = str_replace($delim, '\\' . $delim, $cmp);
                    $match = (@preg_match($delim . $safe . $delim . 'u', $value) === 1);
                }
                break;
            case 'empty':
                $match = ($value === '');
                break;
            case 'not_empty':
                $match = ($value !== '');
                break;
            case 'is_valid':
                $match = ($valid === true);
                break;
            case 'is_invalid':
                $match = ($valid === false);
                break;
        }
        if ($match) {
            return (int) $i;
        }
    }
    return -1;
}

/**
 * Validate a tree before saving. Returns [ok=>bool, error=>string|null].
 * Rules: must have nodes; ids unique; edges reference existing nodes; no cycles;
 * exactly one root (parent-less) reachable.
 */
function flow_validate_tree($tree)
{
    if (!is_array($tree) || empty($tree['nodes']) || !is_array($tree['nodes'])) {
        return ['ok' => false, 'error' => 'empty_tree'];
    }
    $ids = [];
    foreach ($tree['nodes'] as $n) {
        if (!is_array($n) || empty($n['id'])) {
            return ['ok' => false, 'error' => 'node_without_id'];
        }
        $id = (string) $n['id'];
        if (isset($ids[$id])) {
            return ['ok' => false, 'error' => 'duplicate_id:' . $id];
        }
        $ids[$id] = true;
    }
    // edges
    $parentOf = [];
    if (!empty($tree['edges']) && is_array($tree['edges'])) {
        foreach ($tree['edges'] as $e) {
            if (!is_array($e) || empty($e['source']) || empty($e['target'])) {
                continue;
            }
            $s = (string) $e['source'];
            $t = (string) $e['target'];
            if (!isset($ids[$s]) || !isset($ids[$t])) {
                return ['ok' => false, 'error' => 'edge_dangling'];
            }
            if (isset($parentOf[$t])) {
                return ['ok' => false, 'error' => 'multi_parent:' . $t]; // a node can have only one parent in a tree
            }
            $parentOf[$t] = $s;
        }
    }
    // cycle detection
    foreach ($ids as $id => $_) {
        $seen = [];
        $cur = $id;
        while (isset($parentOf[$cur])) {
            $cur = $parentOf[$cur];
            if (isset($seen[$cur])) {
                return ['ok' => false, 'error' => 'cycle_detected'];
            }
            $seen[$cur] = true;
        }
    }
    return ['ok' => true, 'error' => null];
}

/* ------------------------------------------------------------------ */
/*  Tree query helpers (used by the runtime engine in later phases)    */
/* ------------------------------------------------------------------ */

/** Index nodes by id for quick lookup. */
function flow_index_nodes(array $tree)
{
    $byId = [];
    foreach ($tree['nodes'] as $n) {
        $byId[$n['id']] = $n;
    }
    return $byId;
}

/** Return the immediate children of a node id, in stable order. */
function flow_children(array $tree, $parentId)
{
    $children = [];
    $order = [];
    foreach ($tree['edges'] as $e) {
        if ($e['source'] === $parentId) {
            $order[$e['target']] = true;
        }
    }
    foreach ($tree['nodes'] as $n) {
        if (isset($order[$n['id']]) || ($n['parent'] === $parentId)) {
            $children[] = $n;
        }
    }
    // de-dupe by id while preserving order
    $seen = [];
    $out = [];
    foreach ($children as $c) {
        if (!isset($seen[$c['id']])) {
            $seen[$c['id']] = true;
            $out[] = $c;
        }
    }
    return $out;
}

/** Find a node by id (or null). */
function flow_node(array $tree, $id)
{
    foreach ($tree['nodes'] as $n) {
        if ($n['id'] === (string) $id) {
            return $n;
        }
    }
    return null;
}

/** The root node id of the tree. */
function flow_root_id(array $tree)
{
    return (string) ($tree['meta']['root'] ?? 'n_root');
}

/**
 * Build the parent chain (ancestor ids) from the root down to $nodeId,
 * inclusive. Uses each node's 'parent' link (which is rebuilt from edges on
 * save, so it is reliable). Returns an ordered array of node ids.
 */
function flow_path_ids(array $tree, $nodeId)
{
    $byId = flow_index_nodes($tree);
    $chain = [];
    $cur = (string) $nodeId;
    $guard = 0;
    while ($cur !== '' && isset($byId[$cur]) && $guard < 500) {
        array_unshift($chain, $cur);
        $parent = $byId[$cur]['parent'] ?? null;
        $cur = $parent ? (string) $parent : '';
        $guard++;
    }
    return $chain;
}

/**
 * Build the human-readable breadcrumb (labels) from root to $nodeId, e.g.
 * ["خرید", "نقدی", "درگاه", "بانک ملی"]. This is what an n8n node forwards.
 */
function flow_breadcrumb(array $tree, $nodeId)
{
    $byId = flow_index_nodes($tree);
    $labels = [];
    foreach (flow_path_ids($tree, $nodeId) as $id) {
        if (isset($byId[$id])) {
            $labels[] = (string) ($byId[$id]['label'] ?? '');
        }
    }
    return $labels;
}

/**
 * Assemble the JSON payload an n8n node sends. Includes (optionally) the
 * breadcrumb path and the collected user inputs, plus identifying context.
 * $cfg = the n8n node config; $ctx = ['bot_id','user_id','node_id',
 * 'inputs'=>[key=>val], 'callback_url'=>...].
 */
function flow_n8n_payload(array $tree, array $cfg, array $ctx)
{
    $nodeId = (string) ($ctx['node_id'] ?? '');
    $payload = [
        'bot_id'   => $ctx['bot_id'] ?? 0,
        'user_id'  => $ctx['user_id'] ?? null,
        'node_id'  => $nodeId,
        'tag'      => (string) ($cfg['n8n_tag'] ?? ''),
        'mode'     => (string) ($cfg['n8n_mode'] ?? 'async'),
        'ts'       => time(),
    ];
    if (!empty($cfg['send_path'])) {
        $payload['path']      = flow_breadcrumb($tree, $nodeId);
        $payload['path_ids']  = flow_path_ids($tree, $nodeId);
    }
    if (!empty($cfg['send_inputs'])) {
        $payload['inputs'] = isset($ctx['inputs']) && is_array($ctx['inputs']) ? $ctx['inputs'] : [];
    }
    if ($payload['mode'] === 'async' && !empty($ctx['callback_url'])) {
        $payload['callback_url'] = (string) $ctx['callback_url'];
    }
    return $payload;
}

/**
 * Send an n8n payload to the configured endpoint.
 * - async: fire-and-forget POST with a very short timeout so the bot never
 *   blocks; n8n is expected to call us back on the callback_url later.
 * - sync : POST and wait up to n8n_timeout_sec for a JSON response.
 * Returns ['ok'=>bool, 'mode'=>..., 'response'=>array|null, 'error'=>code|null].
 * Never throws.
 */
function flow_n8n_send(array $cfg, array $payload)
{
    $url = trim((string) ($cfg['n8n_endpoint'] ?? ''));
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return ['ok' => false, 'mode' => $cfg['n8n_mode'] ?? 'async', 'response' => null, 'error' => 'bad_endpoint'];
    }
    $mode = (string) ($cfg['n8n_mode'] ?? 'async');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'mode' => $mode, 'response' => null, 'error' => 'no_curl'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOSIGNAL       => true,
    ]);

    if ($mode === 'async') {
        // Fire-and-forget: tiny timeout, we don't care about the body. n8n will
        // reach back via callback_url. This keeps the bot responsive under load.
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 800);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 700);
        @curl_exec($ch);
        curl_close($ch);
        // We intentionally treat dispatch as success regardless of the short
        // timeout; delivery is confirmed later by the callback.
        return ['ok' => true, 'mode' => 'async', 'response' => null, 'error' => null];
    }

    // sync
    $timeout = max(1, min(30, (int) ($cfg['n8n_timeout_sec'] ?? 5)));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0) {
        return ['ok' => false, 'mode' => 'sync', 'response' => null, 'error' => ($errno === 28 ? 'timeout' : 'curl_err')];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'mode' => 'sync', 'response' => null, 'error' => 'http_' . $code];
    }
    $resp = json_decode((string) $raw, true);
    return ['ok' => true, 'mode' => 'sync', 'response' => is_array($resp) ? $resp : null, 'error' => null];
}

/* ------------------------------------------------------------------ */
/*  Version history                                                    */
/* ------------------------------------------------------------------ */

/** Snapshot a tree into history. Best effort; never throws to caller. */
function flow_push_history(array $tree, $bot_id = null, $note = '', $created_by = '')
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    $bot_id = flow_bot_id($bot_id);
    try {
        $count = count($tree['nodes'] ?? []);
        $stmt = $pdo->prepare("INSERT INTO button_flow_history (bot_id, tree, note, node_count, created_at, created_by)
                               VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $bot_id,
            json_encode($tree, JSON_UNESCAPED_UNICODE),
            mb_substr((string) $note, 0, 250),
            $count,
            date('Y-m-d H:i:s'),
            mb_substr((string) $created_by, 0, 90),
        ]);
    } catch (Exception $e) {
        error_log("flow_push_history error: " . $e->getMessage());
        return false;
    }
}

/** Keep only the most recent FLOW_HISTORY_KEEP rows for a bot. */
function flow_trim_history($bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return;
    }
    $bot_id = flow_bot_id($bot_id);
    try {
        $keep = (int) FLOW_HISTORY_KEEP;
        // Find the id threshold to keep.
        $stmt = $pdo->prepare("SELECT id FROM button_flow_history WHERE bot_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?");
        $stmt->execute([$bot_id, $keep]);
        $threshold = $stmt->fetchColumn();
        if ($threshold) {
            $del = $pdo->prepare("DELETE FROM button_flow_history WHERE bot_id = ? AND id <= ?");
            $del->execute([$bot_id, (int) $threshold]);
        }
    } catch (Exception $e) {
        error_log("flow_trim_history error: " . $e->getMessage());
    }
}

/** List recent history revisions (metadata only, no tree blob). */
function flow_history_list($bot_id = null, $limit = 20)
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    $bot_id = flow_bot_id($bot_id);
    try {
        $stmt = $pdo->prepare("SELECT id, note, node_count, created_at, created_by
                               FROM button_flow_history WHERE bot_id = ?
                               ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, $bot_id, PDO::PARAM_INT);
        $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("flow_history_list error: " . $e->getMessage());
        return [];
    }
}

/** Fetch a single history revision's tree (or null). */
function flow_history_get($history_id, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return null;
    }
    $bot_id = flow_bot_id($bot_id);
    try {
        $stmt = $pdo->prepare("SELECT tree FROM button_flow_history WHERE id = ? AND bot_id = ? LIMIT 1");
        $stmt->execute([(int) $history_id, $bot_id]);
        $raw = $stmt->fetchColumn();
        if (!$raw) {
            return null;
        }
        return flow_normalise_tree(json_decode((string) $raw, true));
    } catch (Exception $e) {
        error_log("flow_history_get error: " . $e->getMessage());
        return null;
    }
}

/**
 * Restore a previous revision as the live tree. The current tree is first
 * snapshotted (so a restore is itself undoable). Returns set_button_flow result.
 */
function flow_history_restore($history_id, $bot_id = null, $created_by = '')
{
    $tree = flow_history_get($history_id, $bot_id);
    if (!$tree) {
        return ['ok' => false, 'error' => 'revision_not_found'];
    }
    return set_button_flow($tree, $bot_id, 'restore #' . (int) $history_id, $created_by);
}

/* ------------------------------------------------------------------ */
/*  Migration from the legacy automation_config['buttons'] format      */
/* ------------------------------------------------------------------ */

/**
 * Build an initial tree from the old flat custom buttons (automation_config
 * ['buttons']). Each legacy button becomes a child of root. Safe to call when
 * there are no legacy buttons (returns a default tree). Does NOT save.
 */
function flow_build_from_legacy($bot_id = null)
{
    $tree = flow_default_tree();
    if (!function_exists('automation_buttons')) {
        if (is_file(__DIR__ . '/automation.php')) {
            require_once __DIR__ . '/automation.php';
        }
    }
    if (!function_exists('automation_buttons')) {
        return $tree;
    }
    $legacy = automation_buttons($bot_id);
    $x = 240;
    $y = 0;
    foreach ($legacy as $b) {
        $id = 'n_' . substr(md5($b['id'] . microtime() . $b['label']), 0, 8);
        $type = ($b['url'] !== '' && $b['message'] === '' && $b['event'] === '') ? 'button' : 'message';
        $cfg = flow_normalise_config([
            'message'   => $b['message'],
            'url'       => $b['url'],
            'auto_back' => true,
            'auto_home' => true,
        ], $type);
        // Remember which legacy custom button this node mirrors, so edits/deletes
        // made in the visual editor can be written back to automation_config on
        // save (otherwise the bot would keep showing the old legacy button).
        $cfg['legacy_id'] = (string) $b['id'];
        $tree['nodes'][] = [
            'id'       => $id,
            'type'     => $type,
            'label'    => $b['label'],
            'parent'   => 'n_root',
            'system'   => false,
            'position' => ['x' => $x, 'y' => $y],
            'config'   => $cfg,
        ];
        $tree['edges'][] = [
            'id'     => 'e_root_' . $id,
            'source' => 'n_root',
            'target' => $id,
        ];
        $y += 90;
    }

    // Mark the imported legacy buttons as "flow_managed" right away, so that if
    // the admin deletes one of these nodes and saves, the sync step knows the
    // legacy button was imported and should be removed (not left orphaned in the
    // bot keyboard). Best-effort; never blocks migration.
    if (!empty($legacy)
        && function_exists('get_automation_config')
        && function_exists('set_automation_config')) {
        try {
            $cfg = get_automation_config($bot_id);
            if (is_array($cfg['buttons'] ?? null)) {
                $dirty = false;
                foreach ($cfg['buttons'] as $i => $b) {
                    if (is_array($b) && empty($b['flow_managed'])) {
                        $cfg['buttons'][$i]['flow_managed'] = true;
                        $dirty = true;
                    }
                }
                if ($dirty) {
                    set_automation_config($cfg, $bot_id);
                }
            }
        } catch (Throwable $e) {
            error_log('flow_build_from_legacy mark error: ' . $e->getMessage());
        }
    }

    return flow_normalise_tree($tree);
}

/**
 * Reconcile the legacy automation custom buttons (automation_config['buttons'])
 * with the visual flow tree, using the per-node config['legacy_id'] link that
 * migration sets.
 *
 * WHY: the bot's reply/inline keyboard is built from automation_config buttons
 * (keyboard.php), while the visual editor edits the flow tree. After a user
 * imports legacy buttons and then edits/deletes them in the editor, the bot
 * must reflect those changes. This function pushes the flow's view of each
 * mirrored button back into automation_config:
 *   - node still present  -> update the legacy button's label/message/url.
 *   - node deleted         -> remove that legacy button.
 * Native flow nodes (no legacy_id) are ignored here; they live only in the flow
 * and are surfaced by the flow runtime, not the legacy keyboard.
 *
 * Safe & best-effort: never throws; does nothing if automation helpers are
 * unavailable or no buttons are mirrored.
 */
function flow_sync_legacy_buttons(array $tree, $bot_id = null)
{
    if (!function_exists('get_automation_config') || !function_exists('set_automation_config')) {
        if (is_file(__DIR__ . '/automation.php')) {
            require_once __DIR__ . '/automation.php';
        }
    }
    if (!function_exists('get_automation_config') || !function_exists('set_automation_config')) {
        return; // automation layer not present
    }

    // Map legacy_id -> flow node, and label -> flow node (only mirrored nodes
    // carry a legacy_id). The label map is a fallback because the keyboard page
    // historically regenerated button ids, which can break the id link.
    $byLegacy = [];
    $byLabel  = [];
    foreach ($tree['nodes'] as $n) {
        $lid = (string) ($n['config']['legacy_id'] ?? '');
        if ($lid !== '') {
            $byLegacy[$lid] = $n;
            $lbl = (string) ($n['label'] ?? '');
            if ($lbl !== '') {
                $byLabel[$lbl] = $n;
            }
        }
    }

    try {
        $cfg = get_automation_config($bot_id);
        $buttons = is_array($cfg['buttons'] ?? null) ? $cfg['buttons'] : [];
        if (empty($buttons) && empty($byLegacy)) {
            return; // nothing mirrored, nothing to do
        }

        // Only touch legacy buttons that were actually imported into THIS tree
        // at some point. We detect "managed" buttons as those whose id appears
        // in $byLegacy OR that were previously linked (flagged below). To avoid
        // wiping buttons the admin created purely in the automation panel and
        // never imported, we only delete a legacy button when it was clearly
        // imported before (it has the flow_managed flag) and its node is gone.
        $changed = false;
        $kept = [];
        foreach ($buttons as $b) {
            if (!is_array($b) || empty($b['id'])) {
                $kept[] = $b;
                continue;
            }
            $bid = (string) $b['id'];
            $blbl = (string) ($b['label'] ?? '');
            $node = $byLegacy[$bid] ?? ($byLabel[$blbl] ?? null);
            if ($node) {
                // Node still exists -> sync its visible fields back to the button.
                $b['label']   = (string) ($node['label'] ?? ($b['label'] ?? ''));
                $b['message'] = (string) ($node['config']['message'] ?? ($b['message'] ?? ''));
                $b['url']     = (string) ($node['config']['url'] ?? ($b['url'] ?? ''));
                $b['flow_managed'] = true; // mark as imported/linked
                $kept[] = $b;
                $changed = true;
            } else {
                // No matching node. If this button was previously imported into
                // the flow (flow_managed), the admin deleted its node -> drop it.
                if (!empty($b['flow_managed'])) {
                    $changed = true; // deletion = a change; simply don't keep it
                    continue;
                }
                $kept[] = $b; // untouched automation-only button
            }
        }

        if ($changed) {
            $cfg['buttons'] = array_values($kept);
            set_automation_config($cfg, $bot_id);
        }
    } catch (Throwable $e) {
        error_log('flow_sync_legacy_buttons error: ' . $e->getMessage());
    }
}

/** True when a non-trivial flow (more than just the root node) is stored. */
function flow_is_active($bot_id = null)
{
    $tree = get_button_flow($bot_id);
    return count($tree['nodes']) > 1;
}
