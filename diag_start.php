<?php
// ============================================================================
//  diag_start.php  - simulate a real /start update through index.php and show
//  every PHP error/warning/notice on screen, so we can see exactly where the
//  bot stops. Visit:  https://<domain>/diag_start.php?key=mirzadiag
//  REMOVE after debugging.
// ============================================================================

if (($_GET['key'] ?? '') !== 'mirzadiag') {
    http_response_code(403);
    echo "forbidden";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "==== SIMULATING /start FOR ADMIN 5361225137 ====\n\n";

// Build a fake Telegram update for /start from the admin user.
$fakeUpdate = [
    'update_id' => random_int(100000000, 999999999), // unique so dedup won't skip
    'message' => [
        'message_id' => random_int(1, 100000),
        'from' => [
            'id' => 5361225137,
            'is_bot' => false,
            'first_name' => 'SAEED',
            'username' => 'Saeed_2000k',
            'language_code' => 'en',
        ],
        'chat' => [
            'id' => 5361225137,
            'first_name' => 'SAEED',
            'username' => 'Saeed_2000k',
            'type' => 'private',
        ],
        'date' => time(),
        'text' => '/start',
    ],
];

// botapi.php reads the update from php://input via file_get_contents.
// We can't rewrite php://input from here, so instead we pre-define $update
// is not enough because index.php pulls fresh from input. Easiest reliable
// approach: write the JSON to a temp stream wrapper by using a custom
// stream is complex; instead we POST it to index.php over loopback with curl.

$json = json_encode($fakeUpdate, JSON_UNESCAPED_UNICODE);

$ch = curl_init('http://127.0.0.1/index.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "HTTP code from index.php : $code\n";
echo "curl error              : " . ($err ?: '(none)') . "\n";
echo "raw body returned        :\n---\n$resp\n---\n\n";

// Now check if the user row was created and what the error_log says.
require_once __DIR__ . '/config.php';

echo "user row after /start    :\n";
try {
    $r = $pdo->query("SELECT id,step,User_Status,verify,roll_Status FROM user WHERE id=5361225137")
             ->fetch(PDO::FETCH_ASSOC);
    var_export($r);
    echo "\n";
} catch (Throwable $e) {
    echo "query error: " . $e->getMessage() . "\n";
}

echo "\n---- tail of error_log (if any) ----\n";
foreach (['error_log', 'error_log user'] as $f) {
    $p = __DIR__ . '/' . $f;
    if (is_file($p)) {
        echo ">> $f:\n" . substr(file_get_contents($p), -3000) . "\n";
    } else {
        echo ">> $f: (not present)\n";
    }
}

echo "\n==== END ====\n";
