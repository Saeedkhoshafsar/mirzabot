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
        $branches = [];
        if (!empty($c['branches']) && is_array($c['branches'])) {
            foreach ($c['branches'] as $b) {
                if (!is_array($b) || empty($b['goto'])) {
                    continue;
                }
                $branches[] = [
                    'when' => (string) ($b['when'] ?? ''),
                    'goto' => (string) $b['goto'],
                ];
            }
        }
        $out['branches'] = $branches;
    }

    return $out;
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
        $tree['nodes'][] = [
            'id'       => $id,
            'type'     => $type,
            'label'    => $b['label'],
            'parent'   => 'n_root',
            'system'   => false,
            'position' => ['x' => $x, 'y' => $y],
            'config'   => flow_normalise_config([
                'message'   => $b['message'],
                'url'       => $b['url'],
                'auto_back' => true,
                'auto_home' => true,
            ], $type),
        ];
        $tree['edges'][] = [
            'id'     => 'e_root_' . $id,
            'source' => 'n_root',
            'target' => $id,
        ];
        $y += 90;
    }
    return flow_normalise_tree($tree);
}

/** True when a non-trivial flow (more than just the root node) is stored. */
function flow_is_active($bot_id = null)
{
    $tree = get_button_flow($bot_id);
    return count($tree['nodes']) > 1;
}
