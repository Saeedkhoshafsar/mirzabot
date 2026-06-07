<?php
// ============================================================================
//  Mirza Bot - diagnostic endpoint  (TEMPORARY - safe, read-only-ish)
//  Visit: https://<your-domain>/diag.php?key=mirzadiag
//  Checks DB connection, key tables, setting row, admin id, and sends a
//  test Telegram message to the configured admin. Helps find why /start
//  produces a 200 but no reply.
// ============================================================================
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== 'mirzadiag') {
    http_response_code(403);
    echo "forbidden";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

function out($label, $val) { echo str_pad($label, 28) . ": " . $val . "\n"; }

require_once __DIR__ . '/config.php';

echo "==== MIRZA DIAG ====\n\n";

// 1) Config values (masked)
out("dbhost", $dbhost ?? '(unset)');
out("dbname", $dbname ?? '(unset)');
out("db user", $usernamedb ?? '(unset)');
out("APIKEY length", isset($APIKEY) ? strlen($APIKEY) . " chars" : '(unset)');
out("APIKEY tail", isset($APIKEY) ? '...' . substr($APIKEY, -6) : '(unset)');
out("adminnumber", $adminnumber ?? '(unset)');
out("domainhosts", $domainhosts ?? '(unset)');
out("usernamebot", $usernamebot ?? '(unset)');
echo "\n";

// 2) mysqli connection
if (isset($connect) && !$connect->connect_error) {
    out("mysqli", "OK connected");
} else {
    out("mysqli", "FAIL: " . ($connect->connect_error ?? 'no $connect'));
}

// 3) PDO connection
if (isset($pdo)) {
    out("PDO", "OK object exists");
} else {
    out("PDO", "FAIL: \$pdo not set (connection failed)");
}
echo "\n";

// 4) Key tables present?
$need = ['user', 'setting', 'admin', 'help', 'invoice', 'product', 'topicid', 'channels'];
if (isset($pdo)) {
    foreach ($need as $t) {
        try {
            $r = $pdo->query("SELECT COUNT(*) AS c FROM `$t`")->fetch(PDO::FETCH_ASSOC);
            out("table $t", "OK rows=" . $r['c']);
        } catch (Throwable $e) {
            out("table $t", "MISSING/ERROR: " . $e->getMessage());
        }
    }
}
echo "\n";

// 5) setting row present?
try {
    $s = $pdo->query("SELECT * FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($s) {
        out("setting row", "OK");
        out("  verifystart", $s['verifystart'] ?? '(null)');
        out("  statusnewuser", $s['statusnewuser'] ?? '(null)');
        out("  Channel_Report", isset($s['Channel_Report']) ? "len=" . strlen((string)$s['Channel_Report']) : '(null)');
        out("  keyboardmain", isset($s['keyboardmain']) ? "len=" . strlen((string)$s['keyboardmain']) : '(null)');
    } else {
        out("setting row", "EMPTY - no row! (this breaks the bot)");
    }
} catch (Throwable $e) {
    out("setting row", "ERROR: " . $e->getMessage());
}
echo "\n";

// 6) admin resolution
try {
    $adm = $pdo->query("SELECT id_admin FROM admin")->fetchAll(PDO::FETCH_COLUMN);
    out("admin table ids", $adm ? implode(',', $adm) : '(empty -> falls back to adminnumber)');
} catch (Throwable $e) {
    out("admin table ids", "ERROR: " . $e->getMessage());
}
echo "\n";

// 7) lang file load
try {
    $lang = require __DIR__ . '/lang/fa.php';
    out("lang/fa.php", is_array($lang) ? "OK keys=" . count($lang) : "NOT an array");
    out("  text_start exists", isset($lang['users']['text_start']) ? "YES" : "NO (missing key!)");
} catch (Throwable $e) {
    out("lang/fa.php", "ERROR: " . $e->getMessage());
}
echo "\n";

// 8) Live Telegram test: getMe + send a message to admin
function tg_raw($token, $method, $params = []) {
    $ch = curl_init("https://api.telegram.org/bot$token/$method");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return $err ? "CURL ERROR: $err" : $res;
}

out("getMe", tg_raw($APIKEY, 'getMe'));
echo "\n";
out("sendMessage->admin",
    tg_raw($APIKEY, 'sendMessage', [
        'chat_id' => $adminnumber,
        'text' => "✅ DIAG: Mirza bot can reach Telegram and send to admin ($adminnumber).",
    ])
);

echo "\n\n==== END DIAG ====\n";
