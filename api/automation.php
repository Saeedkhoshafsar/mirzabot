<?php
/**
 * Inbound Automation API (Step 11)
 * =================================
 * Lets n8n / Make / Zapier / any client DRIVE the bot. Auth is a bearer token
 * configured in panel -> Automation, completely independent from the internal
 * panel token. Optionally also verifies an HMAC signature of the raw body.
 *
 * Usage from n8n (HTTP Request node):
 *   POST https://YOUR-DOMAIN/api/automation.php
 *   Header: Authorization: Bearer <inbound_token>
 *   Body (JSON): { "action": "send_message", "chat_id": 123, "text": "hi" }
 *
 * Optional signature: Header X-Signature: sha256=<hmac of raw body with secret>
 *
 * Response: { "ok": true|false, "result"|"error": ... }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../automation.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Tehran');
ini_set('error_log', 'error_log');

$setting = select("setting", "*");

function aout($ok, $payload = null, $code = 200)
{
    http_response_code($code);
    if ($ok) {
        echo json_encode(['ok' => true, 'result' => $payload], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'error' => $payload], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// --- read body + headers -----------------------------------------------------
$rawBody = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
$hget = function ($name) use ($headers) {
    foreach ($headers as $k => $v) {
        if (strcasecmp($k, $name) === 0) {
            return $v;
        }
    }
    return '';
};

$bot_id = (int) ($_GET['bot_id'] ?? 0);
$cfg = get_automation_config($bot_id);

// --- auth: bearer token ------------------------------------------------------
$expected = (string) ($cfg['inbound_token'] ?? '');
if ($expected === '') {
    aout(false, 'inbound automation API is not enabled', 403);
}
$auth = (string) $hget('Authorization');
$provided = '';
if (stripos($auth, 'Bearer ') === 0) {
    $provided = trim(substr($auth, 7));
} else {
    $provided = (string) $hget('X-Auth-Token');
}
if ($provided === '' || !hash_equals($expected, $provided)) {
    aout(false, 'invalid or missing token', 401);
}

// --- optional HMAC signature of the raw body --------------------------------
$sig = (string) $hget('X-Signature');
if ($sig !== '') {
    if (!automation_verify_signature($rawBody, (string) ($cfg['secret'] ?? ''), $sig)) {
        aout(false, 'invalid signature', 401);
    }
}

// --- parse payload -----------------------------------------------------------
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    // allow form/query fallback
    $data = $_POST ?: $_GET;
}
$action = (string) ($data['action'] ?? ($_GET['action'] ?? ''));
if ($action === '') {
    aout(false, 'missing action', 400);
}
$allowed = automation_actions();
if (!isset($allowed[$action])) {
    aout(false, 'unknown action: ' . $action, 400);
}

// --- dispatch ----------------------------------------------------------------
try {
    switch ($action) {

        case 'send_message': {
            $chat = (string) ($data['chat_id'] ?? '');
            $text = (string) ($data['text'] ?? '');
            if ($chat === '' || $text === '') {
                aout(false, 'chat_id and text are required', 400);
            }
            $kb = isset($data['reply_markup'])
                ? (is_string($data['reply_markup']) ? $data['reply_markup'] : json_encode($data['reply_markup']))
                : null;
            $res = sendmessage($chat, $text, $kb, (string) ($data['parse_mode'] ?? 'HTML'));
            aout(!empty($res['ok']), $res);
            break;
        }

        case 'broadcast': {
            $ids  = $data['chat_ids'] ?? [];
            $text = (string) ($data['text'] ?? '');
            if (!is_array($ids) || !$ids || $text === '') {
                aout(false, 'chat_ids[] and text are required', 400);
            }
            $sent = 0; $failed = 0;
            foreach ($ids as $cid) {
                $r = sendmessage((string) $cid, $text, null, (string) ($data['parse_mode'] ?? 'HTML'));
                if (!empty($r['ok'])) { $sent++; } else { $failed++; }
            }
            aout(true, ['sent' => $sent, 'failed' => $failed]);
            break;
        }

        case 'set_order_status': {
            $oid = (string) ($data['order_id'] ?? '');
            $st  = (string) ($data['status'] ?? '');
            if ($oid === '' || $st === '') {
                aout(false, 'order_id and status are required', 400);
            }
            $ok = function_exists('set_order_status') ? set_order_status($oid, $st) : false;
            if ($ok) {
                fire_event('order.status_changed', ['order_id' => $oid, 'status' => $st, 'via' => 'api'], $bot_id);
            }
            aout($ok, ['order_id' => $oid, 'status' => $st]);
            break;
        }

        case 'set_order_tracking': {
            $oid     = (string) ($data['order_id'] ?? '');
            $carrier = (string) ($data['carrier'] ?? '');
            $code    = (string) ($data['tracking_code'] ?? '');
            if ($oid === '' || $carrier === '' || $code === '') {
                aout(false, 'order_id, carrier and tracking_code are required', 400);
            }
            $bump = array_key_exists('bump_shipped', $data) ? (bool) $data['bump_shipped'] : true;
            $ok = function_exists('set_order_tracking') ? set_order_tracking($oid, $carrier, $code, $bump) : false;
            if ($ok) {
                fire_event('order.shipped', [
                    'order_id' => $oid, 'carrier' => $carrier, 'tracking_code' => $code,
                    'tracking_url' => function_exists('carrier_tracking_url') ? carrier_tracking_url($carrier, $code) : '',
                    'via' => 'api',
                ], $bot_id);
            }
            aout($ok, ['order_id' => $oid, 'carrier' => $carrier, 'tracking_code' => $code]);
            break;
        }

        case 'adjust_balance': {
            global $pdo;
            $uid    = (string) ($data['user_id'] ?? '');
            $amount = (int) ($data['amount'] ?? 0); // +/-
            if ($uid === '' || $amount === 0) {
                aout(false, 'user_id and non-zero amount are required', 400);
            }
            $u = select("user", "*", "id", $uid, "select");
            if (!$u) {
                aout(false, 'user not found', 404);
            }
            $old = (int) ($u['Balance'] ?? 0);
            $new = $old + $amount;
            $pdo->prepare("UPDATE user SET Balance = ? WHERE id = ?")->execute([$new, $uid]);
            fire_event('user.balance_changed', [
                'user_id' => $uid, 'old' => $old, 'new' => $new, 'delta' => $amount, 'via' => 'api',
            ], $bot_id);
            aout(true, ['user_id' => $uid, 'old' => $old, 'new' => $new]);
            break;
        }

        case 'get_order': {
            $oid = (string) ($data['order_id'] ?? '');
            if ($oid === '') {
                aout(false, 'order_id is required', 400);
            }
            $row = select("Payment_report", "*", "id_order", $oid, "select");
            aout((bool) $row, $row ?: null);
            break;
        }

        case 'get_user': {
            $uid = (string) ($data['user_id'] ?? '');
            if ($uid === '') {
                aout(false, 'user_id is required', 400);
            }
            $row = select("user", "*", "id", $uid, "select");
            if ($row) {
                // never leak sensitive auth columns
                unset($row['token']);
            }
            aout((bool) $row, $row ?: null);
            break;
        }

        case 'fire_event': {
            $ev = (string) ($data['event'] ?? '');
            if ($ev === '') {
                aout(false, 'event is required', 400);
            }
            $payload = is_array($data['data'] ?? null) ? $data['data'] : [];
            fire_event($ev, $payload, $bot_id);
            aout(true, ['fired' => $ev]);
            break;
        }

        default:
            aout(false, 'action not implemented', 400);
    }
} catch (Exception $e) {
    error_log('automation api error: ' . $e->getMessage());
    aout(false, 'internal error', 500);
}
