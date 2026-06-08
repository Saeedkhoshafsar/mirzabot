<?php
// -----------------------------------------------------------------------------
// Temporary diagnostics page. Open it as an admin to see exactly what is stored
// in the DB for store mode, custom buttons and the bot's main keyboard.
// Safe to delete once debugging is finished.
// -----------------------------------------------------------------------------
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/../automation.php';
require_once __DIR__ . '/../flow.php';
require_auth();

header('Content-Type: text/plain; charset=utf-8');

echo "=== PANEL DIAGNOSTICS ===\n\n";

// 1) Panel mode resolution -----------------------------------------------------
$env = getenv('PANEL_MODE');
echo "ENV PANEL_MODE       : " . ($env === false ? '(not set)' : $env) . "\n";
echo "panel_mode()         : " . (function_exists('panel_mode') ? panel_mode() : 'n/a') . "\n";
echo "panel_is_shop()      : " . (function_exists('panel_is_shop') ? var_export(panel_is_shop(), true) : 'n/a') . "\n";

try {
    $row = $pdo->query("SELECT store_mode, store_currency FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo "setting.store_mode   : " . var_export($row['store_mode'] ?? null, true) . "\n";
    echo "setting.store_currency: " . var_export($row['store_currency'] ?? null, true) . "\n";
} catch (Throwable $e) {
    echo "setting read error   : " . $e->getMessage() . "\n";
}

// 2) automation_config / custom buttons ---------------------------------------
echo "\n--- automation_config (raw column) ---\n";
try {
    $raw = $pdo->query("SELECT automation_config FROM setting LIMIT 1")->fetchColumn();
    echo var_export($raw, true) . "\n";
} catch (Throwable $e) {
    echo "read error: " . $e->getMessage() . "\n";
}

echo "\n--- get_automation_config(0) ---\n";
$cfg = get_automation_config(0);
echo "buttons count: " . count($cfg['buttons'] ?? []) . "\n";
print_r($cfg['buttons'] ?? 'no buttons key');

echo "\n--- automation_buttons(0) (normalised) ---\n";
print_r(automation_buttons(0));

// 2b) BUTTON FLOW (Step 12, Phase 1) ------------------------------------------
echo "\n\n=== BUTTON FLOW (Phase 1: data layer) ===\n";
echo "flow.php loaded         : " . (function_exists('get_button_flow') ? 'yes' : 'NO') . "\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM setting LIKE 'button_flow%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "setting columns         : " . (empty($cols) ? '(missing — open panel/index.php once to run table.php)' : implode(', ', $cols)) . "\n";
} catch (Throwable $e) {
    echo "columns read error      : " . $e->getMessage() . "\n";
}
foreach (['button_flow_history', 'button_flow_state'] as $t) {
    try {
        $exists = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = '$t'")->fetchColumn();
        echo "table $t : " . ($exists ? 'exists' : 'MISSING') . "\n";
    } catch (Throwable $e) {
        echo "table $t : err " . $e->getMessage() . "\n";
    }
}

$tree = get_button_flow(0);
echo "\ncurrent tree nodes      : " . count($tree['nodes']) . "\n";
echo "current tree edges      : " . count($tree['edges']) . "\n";
echo "root id                 : " . flow_root_id($tree) . "\n";
echo "flow_is_active(0)       : " . var_export(flow_is_active(0), true) . "\n";

echo "\n--- legacy → tree preview (NOT saved) ---\n";
$preview = flow_build_from_legacy(0);
echo "preview nodes           : " . count($preview['nodes']) . " (root + legacy buttons)\n";
foreach ($preview['nodes'] as $n) {
    echo "   [" . $n['type'] . "] " . $n['label'] . ($n['system'] ? '  (system)' : '') . "\n";
}

// Phase-1 self test: build the خرید tree, validate, save, read back, history.
if (isset($_GET['flowtest'])) {
    echo "\n--- FLOW SELF-TEST (?flowtest=1) ---\n";
    $t = flow_default_tree();
    $mk = function ($id, $label, $parent, $type = 'button') {
        return [
            'id' => $id, 'type' => $type, 'label' => $label, 'parent' => $parent,
            'system' => false, 'position' => ['x' => 0, 'y' => 0],
            'config' => flow_normalise_config([], $type),
        ];
    };
    $t['nodes'][] = $mk('buy', 'خرید', 'n_root');
    $t['nodes'][] = $mk('cash', 'نقدی', 'buy');
    $t['nodes'][] = $mk('inst', 'قسطی', 'buy');
    $t['nodes'][] = $mk('m3', '۳ ماهه', 'inst');
    $t['nodes'][] = $mk('m6', '۶ ماهه', 'inst');
    foreach ([['n_root','buy'],['buy','cash'],['buy','inst'],['inst','m3'],['inst','m6']] as $e) {
        $t['edges'][] = ['id' => 'e_' . $e[0] . '_' . $e[1], 'source' => $e[0], 'target' => $e[1]];
    }
    $v = flow_validate_tree($t);
    echo "validate              : " . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
    $res = set_button_flow($t, 0, 'diag flowtest', 'diag');
    echo "save ok               : " . var_export($res['ok'], true) . " err=" . var_export($res['error'], true) . "\n";
    $back = get_button_flow(0);
    echo "read back nodes       : " . count($back['nodes']) . "\n";
    echo "children of خرید      : " . implode(', ', array_map(fn($c) => $c['label'], flow_children($back, 'buy'))) . "\n";
    echo "children of قسطی      : " . implode(', ', array_map(fn($c) => $c['label'], flow_children($back, 'inst'))) . "\n";
    echo "history revisions     : " . count(flow_history_list(0)) . "\n";
    echo "\n>>> If save ok=true and children match (نقدی/قسطی and ۳ ماهه/۶ ماهه),\n";
    echo ">>> Phase 1 (data layer) works end-to-end on production.\n";
}

// 3) Write test ----------------------------------------------------------------
if (isset($_GET['writetest'])) {
    echo "\n--- WRITE TEST ---\n";
    $cfg = get_automation_config(0);
    $cfg['buttons'][] = [
        'id' => 'diag' . substr(md5(microtime()), 0, 6),
        'label' => 'تست تشخیص ' . date('H:i:s'),
        'event' => 'custom.trigger',
        'message' => 'این یک دکمه تستی است',
        'url' => '',
        'active' => true,
    ];
    $ok = set_automation_config($cfg, 0);
    echo "set_automation_config returned: " . var_export($ok, true) . "\n";
    // re-read straight from DB
    $raw = $pdo->query("SELECT automation_config FROM setting LIMIT 1")->fetchColumn();
    echo "DB now has: " . $raw . "\n";
    echo "\n>>> If buttons count went up here but NOT on keyboard.php, it's a read/cache issue.\n";
    echo ">>> If set_... returned true but DB did NOT change, it's a WRITE issue (no setting row?).\n";
}

// 3b) FULL PANEL-FORM SIMULATION ----------------------------------------------
// Mimics the exact cb_save handler in panel/keyboard.php to prove the form
// pathway persists. Run with ?formtest=1 — it ADDS a button named
// "شبیه‌سازی فرم HH:MM:SS", then re-reads via the SAME function the panel uses.
if (isset($_GET['formtest'])) {
    echo "\n--- FORM SIMULATION (exact panel cb_save logic) ---\n";
    // Build POST-like arrays: keep the existing buttons + one brand-new one.
    $existing = automation_buttons(0);
    $bLabels = [];
    $bMsgs = [];
    $bUrls = [];
    $bActive = [];
    foreach ($existing as $i => $b) {
        $bLabels[$i] = $b['label'];
        $bMsgs[$i] = $b['message'];
        $bUrls[$i] = $b['url'];
        $bActive[$i] = $b['active'] ? '1' : null;
    }
    $newIdx = count($existing);
    $bLabels[$newIdx] = 'شبیه‌سازی فرم ' . date('H:i:s');
    $bMsgs[$newIdx] = 'این دکمه از طریق شبیه‌سازی فرم اضافه شد';
    $bUrls[$newIdx] = '';
    $bActive[$newIdx] = '1';

    echo "labels submitted: " . count($bLabels) . " (expected existing+1 = " . ($newIdx + 1) . ")\n";

    // --- verbatim cb_save logic ---
    $cfg = get_automation_config(0);
    if (!is_array($cfg)) {
        $cfg = [];
    }
    $buttons = [];
    foreach ($bLabels as $i => $lbl) {
        $lbl = trim((string) $lbl);
        if ($lbl === '') {
            continue;
        }
        $url = trim((string) ($bUrls[$i] ?? ''));
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = '';
        }
        $buttons[] = [
            'id'      => 'b' . substr(md5($lbl . $i . microtime()), 0, 8),
            'label'   => $lbl,
            'event'   => 'custom.trigger',
            'message' => trim((string) ($bMsgs[$i] ?? '')),
            'url'     => $url,
            'active'  => isset($bActive[$i]),
        ];
    }
    $cfg['buttons'] = $buttons;
    $ok = set_automation_config($cfg, 0);
    echo "set_automation_config returned: " . var_export($ok, true) . "\n";

    // Re-read the SAME way the panel does after redirect:
    $after = automation_buttons(0);
    echo "automation_buttons(0) now returns: " . count($after) . " button(s)\n";
    foreach ($after as $b) {
        echo "   - " . $b['label'] . "\n";
    }
    // And straight from DB to be sure:
    $raw = $pdo->query("SELECT automation_config FROM setting LIMIT 1")->fetchColumn();
    $dbCfg = json_decode((string) $raw, true);
    echo "DB automation_config.buttons count: " . count($dbCfg['buttons'] ?? []) . "\n";
    echo "\n>>> If both counts == " . ($newIdx + 1) . ", the SAVE PATH WORKS and the\n";
    echo ">>> panel form should work too. If automation_buttons(0) is LOWER than the\n";
    echo ">>> DB count, it's a stale-cache read bug (now fixed via clearSelectCache).\n";
}

// 4) Main keyboard -------------------------------------------------------------
echo "\n--- setting.keyboardmain (bot main menu) ---\n";
$km = null;
try {
    $km = $pdo->query("SELECT keyboardmain FROM setting LIMIT 1")->fetchColumn();
    echo $km . "\n";
} catch (Throwable $e) {
    echo "read error: " . $e->getMessage() . "\n";
}

// 4b) Mode-aware keyboard swap diagnostics ------------------------------------
echo "\n--- keyboard mode swap (issue 5) ---\n";
$pmode = function_exists('panel_mode') ? panel_mode() : 'vpn';
echo "active mode               : $pmode\n";
if (function_exists('keyboard_is_factory_default')) {
    $isFactory = keyboard_is_factory_default($km ?? '');
    echo "stored layout is factory  : " . var_export($isFactory, true) . "\n";
    $willSwap = ($pmode !== 'vpn' && $isFactory);
    echo "will auto-swap on next msg : " . var_export($willSwap, true) . "\n";
    if (function_exists('default_main_keyboard_json')) {
        echo "target layout for '$pmode' :\n" . default_main_keyboard_json($pmode) . "\n";
    }
    echo ">>> After you message the bot once, the stored layout above should\n";
    echo ">>> change to the target layout and the VPN-only buttons disappear.\n";
} else {
    echo "keyboard_is_factory_default() not available (deploy not updated yet)\n";
}

// 5) Does a setting row even exist? -------------------------------------------
echo "\n--- setting table rows ---\n";
try {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM setting")->fetchColumn();
    echo "setting row count: $n\n";
} catch (Throwable $e) {
    echo "count error: " . $e->getMessage() . "\n";
}

echo "\n=== END. Add ?writetest=1 to the URL to run a write test. ===\n";
