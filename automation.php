<?php
/**
 * Open Automation Layer (Step 11)
 * ================================
 * A no-lock-in bridge between this bot and ANY external automation tool —
 * n8n, Make, Zapier, or a plain script. It has two directions:
 *
 *   OUTBOUND (events):  the bot fires fire_event('order.created', $data) and
 *                       every configured webhook URL receives a signed JSON
 *                       POST. An n8n "Webhook" node can catch it and do
 *                       literally anything.
 *
 *   INBOUND (actions):  n8n calls api/automation.php?action=... with a bearer
 *                       token; this performs an allow-listed action inside the
 *                       bot (send message, set order status, adjust balance...).
 *
 * Security:
 *   - Outbound: HMAC-SHA256 signature in the X-Signature header (secret shared
 *     with n8n). The receiver can verify it; nobody can forge events.
 *   - Inbound:  static bearer token + optional HMAC of the body.
 *
 * Everything is OPT-IN and disabled by default. If automation_config is empty,
 * fire_event() is a cheap no-op, so existing VPN/shop installs are unaffected.
 *
 * Design goals for n8n power-users (per project owner's request):
 *   - Send the FULL context of each event so workflows never feel limited.
 *   - Expose a broad, documented action surface (not a fixed handful).
 *   - Let multiple endpoints subscribe, each to its own subset of events.
 *   - Never force n8n: the same signed webhook works with Make/Zapier/custom.
 */

if (!function_exists('select')) {
    require_once __DIR__ . '/function.php';
}

/**
 * The canonical catalogue of events the bot can emit. Used by the panel UI to
 * render checkboxes and by docs. Extend freely — fire_event() accepts any name.
 */
function automation_events()
{
    return [
        'order.created'        => 'سفارش جدید ثبت شد',
        'order.paid'           => 'سفارش پرداخت شد',
        'order.status_changed' => 'وضعیت سفارش تغییر کرد',
        'order.shipped'        => 'سفارش ارسال شد (کد رهگیری ثبت شد)',
        'order.delivered'      => 'سفارش تحویل شد',
        'order.canceled'       => 'سفارش لغو شد',
        'payment.received'     => 'پرداخت دریافت شد',
        'user.registered'      => 'کاربر جدید ثبت‌نام کرد',
        'user.balance_changed' => 'موجودی کاربر تغییر کرد',
        'service.created'       => 'سرویس/اشتراک جدید ساخته شد',
        'service.expiring'      => 'سرویس رو به انقضا',
        'ticket.created'        => 'تیکت پشتیبانی جدید',
        'custom.trigger'        => 'تریگر دلخواه (از دکمه‌های سفارشی)',
    ];
}

/** The allow-listed inbound actions n8n may invoke. Used by panel + docs. */
function automation_actions()
{
    return [
        'send_message'      => 'ارسال پیام تلگرام به کاربر',
        'broadcast'         => 'ارسال پیام به چند کاربر',
        'set_order_status'  => 'تغییر وضعیت سفارش',
        'set_order_tracking'=> 'ثبت شرکت ارسال و کد رهگیری',
        'adjust_balance'    => 'افزایش/کاهش موجودی کاربر',
        'get_order'         => 'دریافت اطلاعات یک سفارش',
        'get_user'          => 'دریافت اطلاعات یک کاربر',
        'fire_event'        => 'شلیک یک رویداد دلخواه (زنجیره‌سازی)',
    ];
}

/**
 * Read automation config (global setting or per-bot botsaz.setting JSON).
 * Returns a normalised array:
 *   enabled       => bool                (master switch for outbound)
 *   secret        => string              (HMAC secret shared with n8n)
 *   inbound_token => string              (bearer token for inbound API)
 *   endpoints     => [ ['url'=>..,'events'=>[..]|'*','active'=>bool,'name'=>..], ... ]
 *   log_enabled   => bool
 */
function get_automation_config($bot_id = null)
{
    global $pdo, $setting;
    $defaults = [
        'enabled'       => false,
        'secret'        => '',
        'inbound_token' => '',
        'endpoints'     => [],
        'log_enabled'   => true,
    ];
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    $raw = null;
    try {
        if ((int) $bot_id > 0 && isset($pdo)) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($brow && !empty($brow['setting'])) {
                $bset = json_decode($brow['setting'], true);
                if (is_array($bset) && isset($bset['automation_config'])) {
                    $raw = is_array($bset['automation_config'])
                        ? $bset['automation_config']
                        : json_decode((string) $bset['automation_config'], true);
                }
            }
        } else {
            if (is_array($setting) && isset($setting['automation_config'])) {
                $raw = is_array($setting['automation_config'])
                    ? $setting['automation_config']
                    : json_decode((string) $setting['automation_config'], true);
            } else {
                $row = select("setting", "automation_config", null, null, "FETCH_COLUMN");
                if ($row) {
                    $raw = json_decode((string) $row, true);
                }
            }
        }
    } catch (Exception $e) {
        error_log("get_automation_config error: " . $e->getMessage());
    }
    if (!is_array($raw)) {
        return $defaults;
    }
    $cfg = array_merge($defaults, $raw);
    $cfg['enabled']     = !empty($cfg['enabled']);
    $cfg['log_enabled'] = !isset($raw['log_enabled']) ? true : !empty($cfg['log_enabled']);
    $cfg['endpoints']   = is_array($cfg['endpoints'] ?? null) ? $cfg['endpoints'] : [];
    return $cfg;
}

/** Persist automation config. Returns true on success. */
function set_automation_config(array $cfg, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    try {
        if ((int) $bot_id > 0) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            $bset = ($brow && !empty($brow['setting'])) ? json_decode($brow['setting'], true) : [];
            if (!is_array($bset)) {
                $bset = [];
            }
            $bset['automation_config'] = $cfg;
            return $pdo->prepare("UPDATE botsaz SET setting = ? WHERE id = ?")
                ->execute([json_encode($bset, JSON_UNESCAPED_UNICODE), (int) $bot_id]);
        }
        return $pdo->prepare("UPDATE setting SET automation_config = ?")
            ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
    } catch (Exception $e) {
        error_log("set_automation_config error: " . $e->getMessage());
        return false;
    }
}

/** Generate a cryptographically-strong random token/secret (hex). */
function automation_random_token($bytes = 24)
{
    try {
        return bin2hex(random_bytes($bytes));
    } catch (Exception $e) {
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
}

/**
 * Compute the HMAC signature for an outbound (or inbound) body.
 * Format sent in header: "sha256=<hex>".
 */
function automation_sign($body, $secret)
{
    return 'sha256=' . hash_hmac('sha256', (string) $body, (string) $secret);
}

/** Constant-time verify of an inbound signature against the raw body. */
function automation_verify_signature($body, $secret, $provided)
{
    if ($secret === '' || $provided === '') {
        return false;
    }
    $expected = automation_sign($body, $secret);
    return hash_equals($expected, (string) $provided);
}

/** Write one row to automation_log (best-effort, never throws). */
function automation_log($direction, $event, $url, $httpCode, $ok, $payload, $response, $bot_id = 0)
{
    global $pdo;
    if (!isset($pdo)) {
        return;
    }
    try {
        $pdo->prepare(
            "INSERT INTO automation_log
                (bot_id, direction, event, target_url, http_code, ok, payload, response, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            (int) $bot_id,
            substr((string) $direction, 0, 10),
            substr((string) $event, 0, 100),
            $url !== null ? substr((string) $url, 0, 1000) : null,
            $httpCode !== null ? (int) $httpCode : null,
            $ok ? 1 : 0,
            $payload !== null ? substr(is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 8000) : null,
            $response !== null ? substr((string) $response, 0, 4000) : null,
            date('Y-m-d H:i:s'),
        ]);
        // Keep the log bounded: trim to the latest 1000 rows occasionally.
        if (mt_rand(1, 25) === 1) {
            $pdo->query("DELETE FROM automation_log WHERE id < (SELECT mx FROM (SELECT MAX(id)-1000 AS mx FROM automation_log) t)");
        }
    } catch (Exception $e) {
        error_log("automation_log error: " . $e->getMessage());
    }
}

/**
 * THE OUTBOUND ENTRY POINT.
 * Fire an event to every subscribed endpoint. Cheap no-op when automation is
 * disabled. Non-blocking-ish: short timeouts so the bot UX is never delayed.
 *
 * @param string $event  e.g. 'order.created'
 * @param array  $data   event-specific payload (sent verbatim under "data")
 */
function fire_event($event, array $data = [], $bot_id = null)
{
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    $cfg = get_automation_config($bot_id);
    if (empty($cfg['enabled']) || empty($cfg['endpoints'])) {
        return; // disabled -> no-op
    }

    $envelope = [
        'event'     => $event,
        'data'      => $data,
        'bot_id'    => (int) $bot_id,
        'fired_at'  => date('c'),
        'source'    => 'mirzabot',
    ];
    $body   = json_encode($envelope, JSON_UNESCAPED_UNICODE);
    $secret = (string) ($cfg['secret'] ?? '');

    foreach ($cfg['endpoints'] as $ep) {
        if (empty($ep['active']) || empty($ep['url'])) {
            continue;
        }
        // Per-endpoint event filter: '*' (or empty) = all events.
        $subs = $ep['events'] ?? '*';
        if ($subs !== '*' && is_array($subs) && !in_array($event, $subs, true)) {
            continue;
        }
        automation_dispatch((string) $ep['url'], $body, $secret, $event, $bot_id, !empty($cfg['log_enabled']));
    }
}

/** Low-level signed POST to a single endpoint. */
function automation_dispatch($url, $body, $secret, $event, $bot_id, $doLog = true)
{
    $headers = [
        'Content-Type: application/json',
        'X-Mirzabot-Event: ' . $event,
        'X-Mirzabot-Bot: ' . (int) $bot_id,
    ];
    if ($secret !== '') {
        $headers[] = 'X-Signature: ' . automation_sign($body, $secret);
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ok = ($resp !== false && $code >= 200 && $code < 300);
    if ($doLog) {
        automation_log('out', $event, $url, $code, $ok, $body, $ok ? (string) $resp : ($err ?: (string) $resp), $bot_id);
    }
}

/**
 * Custom buttons (Step 11) — admin-defined buttons shown on the bot's main
 * menu. Each button can: fire an n8n event (custom.trigger), send a canned
 * message to the user, and/or open a URL. Stored under automation_config.
 * Returns a normalised list: [['id','label','event','message','url','active'], ...]
 */
function automation_buttons($bot_id = null)
{
    $cfg = get_automation_config($bot_id);
    $btns = is_array($cfg['buttons'] ?? null) ? $cfg['buttons'] : [];
    $out = [];
    foreach ($btns as $b) {
        if (!is_array($b) || empty($b['label'])) {
            continue;
        }
        $out[] = [
            'id'      => (string) ($b['id'] ?? ''),
            'label'   => (string) $b['label'],
            'event'   => (string) ($b['event'] ?? ''),
            'message' => (string) ($b['message'] ?? ''),
            'url'     => (string) ($b['url'] ?? ''),
            'active'  => !isset($b['active']) ? true : !empty($b['active']),
        ];
    }
    return $out;
}

/** Find a single active custom button by id. */
function automation_button($id, $bot_id = null)
{
    foreach (automation_buttons($bot_id) as $b) {
        if ($b['active'] && $b['id'] === (string) $id) {
            return $b;
        }
    }
    return null;
}

/** Recent delivery log rows for the panel. */
function automation_recent_logs($limit = 50, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    try {
        if ($bot_id !== null) {
            $stmt = $pdo->prepare("SELECT * FROM automation_log WHERE bot_id = ? ORDER BY id DESC LIMIT ?");
            $stmt->bindValue(1, (int) $bot_id, PDO::PARAM_INT);
            $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT * FROM automation_log ORDER BY id DESC LIMIT ?");
            $stmt->bindValue(1, (int) $limit, PDO::PARAM_INT);
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("automation_recent_logs error: " . $e->getMessage());
        return [];
    }
}
