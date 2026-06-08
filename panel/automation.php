<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/../automation.php';
require_auth();

/*
 * Open Automation / n8n integration (Step 11).
 * Configure outbound event webhooks (to n8n / Make / Zapier / any URL) and the
 * inbound API token so external tools can drive the bot. Designed to give an
 * n8n specialist full freedom: any event out, a broad action surface in,
 * HMAC-signed both ways, multiple endpoints with per-endpoint event filters.
 */

$allEvents  = automation_events();
$allActions = automation_actions();
$cfg        = get_automation_config(0);

// --------------------------------------------------------------- SAVE -------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_post();
    $act = (string) ($_POST['action'] ?? '');

    if ($act === 'regen_secret') {
        $cfg['secret'] = automation_random_token(24);
        set_automation_config($cfg, 0);
        flash('success', 'کلید امضا (Secret) بازتولید شد.');
        header('Location: automation.php'); exit;
    }
    if ($act === 'regen_token') {
        $cfg['inbound_token'] = automation_random_token(24);
        set_automation_config($cfg, 0);
        flash('success', 'توکن API ورودی بازتولید شد.');
        header('Location: automation.php'); exit;
    }

    if ($act === 'save_automation') {
        $new = $cfg;
        $new['enabled']     = !empty($_POST['enabled']);
        $new['log_enabled'] = !empty($_POST['log_enabled']);
        if (empty($new['secret'])) {
            $new['secret'] = automation_random_token(24);
        }
        if (!empty($_POST['enable_inbound']) && empty($new['inbound_token'])) {
            $new['inbound_token'] = automation_random_token(24);
        }
        if (empty($_POST['enable_inbound'])) {
            $new['inbound_token'] = '';
        }

        // Rebuild endpoints from posted rows.
        $endpoints = [];
        $urls    = $_POST['ep_url']    ?? [];
        $names   = $_POST['ep_name']   ?? [];
        $actives = $_POST['ep_active'] ?? [];
        $allEv   = $_POST['ep_all']    ?? [];
        $evsSel  = $_POST['ep_events'] ?? [];
        foreach ($urls as $i => $u) {
            $u = trim((string) $u);
            if ($u === '') { continue; }
            if (!preg_match('~^https?://~i', $u)) { continue; }
            $events = !empty($allEv[$i]) ? '*' : array_values(array_intersect(
                array_keys($allEvents),
                is_array($evsSel[$i] ?? null) ? $evsSel[$i] : []
            ));
            $endpoints[] = [
                'name'   => trim((string) ($names[$i] ?? '')),
                'url'    => $u,
                'active' => !empty($actives[$i]),
                'events' => $events === [] ? '*' : $events,
            ];
        }
        $new['endpoints'] = $endpoints;

        // Custom buttons are managed on keyboard.php now — preserve whatever
        // is already stored so saving the webhook settings here never wipes
        // the buttons the admin created on the keyboard page.
        $new['buttons'] = is_array($cfg['buttons'] ?? null) ? $cfg['buttons'] : [];

        if (set_automation_config($new, 0)) {
            flash('success', 'تنظیمات اتوماسیون ذخیره شد.');
        } else {
            flash('error', 'خطا در ذخیرهٔ تنظیمات اتوماسیون.');
        }
        header('Location: automation.php'); exit;
    }
}

$cfg   = get_automation_config(0);
$logs  = automation_recent_logs(40, null);

// Best-effort public base URL for the inbound endpoint hint.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'YOUR-DOMAIN';
$apiUrl = $scheme . '://' . $host . '/api/automation.php';

$flashOk  = get_flash('success');
$flashErr = get_flash('error');

$pageTitle    = 'اتوماسیون و n8n';
$activeNav    = 'automation';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="margin-bottom:16px" class="fade-up">
    <div style="font-size:1rem;font-weight:800;color:var(--text)">اتوماسیون باز و اتصال n8n</div>
    <div style="font-size:.8rem;color:var(--mute)">رویدادها را به n8n/Make/Zapier بفرستید و اجازه دهید ابزار بیرونی، ربات را کنترل کند — بدون قفل‌شدن به یک ابزار خاص</div>
</div>

<?php if ($flashOk): ?><div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<form method="POST" action="automation.php">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_automation">

    <!-- Master + signature ---------------------------------------------- -->
    <div class="card fade-up d1" style="margin-bottom:16px">
        <div class="card-head"><div><div class="card-title">وضعیت کلی</div>
            <div class="card-subtitle">کلید امضا برای جلوگیری از جعل رویدادها استفاده می‌شود</div></div></div>
        <div class="card-body">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:12px">
                <input type="checkbox" name="enabled" value="1" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>>
                <span style="font-weight:700;color:var(--text)">ارسال رویدادها (Outbound Webhooks) فعال باشد</span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:12px">
                <input type="checkbox" name="log_enabled" value="1" <?= !empty($cfg['log_enabled']) ? 'checked' : '' ?>>
                <span style="color:var(--text)">ثبت لاگ تحویل‌ها (برای عیب‌یابی)</span>
            </label>
            <div class="field" style="max-width:560px">
                <label>کلید امضا (HMAC Secret) — در n8n برای اعتبارسنجی استفاده کنید</label>
                <input type="text" class="input" readonly value="<?= htmlspecialchars((string) ($cfg['secret'] ?? '')) ?>" placeholder="هنوز ساخته نشده — با ذخیره ساخته می‌شود">
                <div class="field-hint">هدر دریافتی: <code>X-Signature: sha256=HMAC_SHA256(body, secret)</code></div>
            </div>
            <button type="submit" form="regen-secret" class="btn btn-ghost" style="margin-top:8px"><?= icon('refresh') ?> بازتولید Secret</button>
        </div>
    </div>

    <!-- Endpoints ------------------------------------------------------- -->
    <div class="card fade-up d2" style="margin-bottom:16px">
        <div class="card-head"><div><div class="card-title">مقصدهای رویداد (Webhook Endpoints)</div>
            <div class="card-subtitle">هر مقصد می‌تواند به همهٔ رویدادها یا فقط بخشی از آن‌ها گوش دهد</div></div></div>
        <div class="card-body">
            <div id="ep-list">
                <?php
                $eps = $cfg['endpoints'] ?: [['name' => '', 'url' => '', 'active' => true, 'events' => '*']];
                foreach ($eps as $i => $ep):
                    $isAll = (($ep['events'] ?? '*') === '*');
                    $selEvents = is_array($ep['events'] ?? null) ? $ep['events'] : [];
                ?>
                <div class="card ep-row" style="margin:0 0 12px;padding:12px;border:1px solid var(--bd)">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                        <div class="field"><label>نام (دلخواه)</label>
                            <input type="text" name="ep_name[<?= $i ?>]" class="input" value="<?= htmlspecialchars((string) ($ep['name'] ?? '')) ?>" placeholder="مثلاً: ورک‌فلوی سفارش‌ها"></div>
                        <div class="field"><label>آدرس Webhook (n8n / Make / ...)</label>
                            <input type="url" name="ep_url[<?= $i ?>]" class="input" value="<?= htmlspecialchars((string) ($ep['url'] ?? '')) ?>" placeholder="https://n8n.example.com/webhook/..."></div>
                    </div>
                    <div style="display:flex;gap:18px;align-items:center;margin:6px 0 10px;flex-wrap:wrap">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="ep_active[<?= $i ?>]" value="1" <?= !empty($ep['active']) ? 'checked' : '' ?>> فعال</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" class="ep-all-toggle" data-idx="<?= $i ?>" name="ep_all[<?= $i ?>]" value="1" <?= $isAll ? 'checked' : '' ?>> همهٔ رویدادها</label>
                    </div>
                    <div class="ep-events" data-idx="<?= $i ?>" style="<?= $isAll ? 'display:none;' : '' ?>display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;border-top:1px dashed var(--bd);padding-top:10px">
                        <?php foreach ($allEvents as $ev => $label): ?>
                            <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.82rem">
                                <input type="checkbox" name="ep_events[<?= $i ?>][]" value="<?= htmlspecialchars($ev) ?>" <?= in_array($ev, $selEvents, true) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($label) ?> <code style="font-size:.7rem;color:var(--dim)"><?= htmlspecialchars($ev) ?></code></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-ep" class="btn btn-ghost"><?= icon('plus') ?> افزودن مقصد</button>
        </div>
    </div>

    <!-- Custom buttons are managed in the visual flow editor now --------- -->
    <div class="card fade-up d3" style="margin-bottom:16px">
        <div class="card-head"><div><div class="card-title">دکمه‌های سفارشی ربات</div>
            <div class="card-subtitle">ساخت و ویرایش دکمه‌های دلخواه ربات به «ویرایشگر دکمه‌ها (درختی)» منتقل شد</div></div></div>
        <div class="card-body">
            <div class="notice" style="margin-bottom:0">
                برای افزودن یا ویرایش دکمه‌های دلخواه ربات (پیام، لینک یا رویداد n8n) به
                <a href="flow.php" style="font-weight:700">ویرایشگر دکمه‌ها (درختی)</a> بروید و یک نود جدید بسازید.
                نود از نوع n8n هنگام کلیک، رویداد دلخواه شما را برای n8n ارسال می‌کند.
            </div>
        </div>
    </div>

    <!-- Inbound API ----------------------------------------------------- -->
    <div class="card fade-up d3" style="margin-bottom:16px">
        <div class="card-head"><div><div class="card-title">API ورودی (کنترل ربات از n8n)</div>
            <div class="card-subtitle">به n8n اجازه می‌دهد پیام بفرستد، وضعیت سفارش را تغییر دهد، موجودی را تنظیم کند و...</div></div></div>
        <div class="card-body">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:12px">
                <input type="checkbox" name="enable_inbound" value="1" <?= !empty($cfg['inbound_token']) ? 'checked' : '' ?>>
                <span style="font-weight:700;color:var(--text)">API ورودی فعال باشد</span>
            </label>
            <div class="field" style="max-width:560px">
                <label>توکن دسترسی (Bearer Token)</label>
                <input type="text" class="input" readonly value="<?= htmlspecialchars((string) ($cfg['inbound_token'] ?? '')) ?>" placeholder="با فعال‌سازی و ذخیره ساخته می‌شود">
            </div>
            <button type="submit" form="regen-token" class="btn btn-ghost" style="margin-top:8px"><?= icon('refresh') ?> بازتولید توکن</button>

            <div class="notice" style="margin-top:14px">
                <div style="font-weight:700;margin-bottom:6px">نمونهٔ فراخوانی از n8n (HTTP Request):</div>
                <pre style="white-space:pre-wrap;font-size:.78rem;direction:ltr;text-align:left;margin:0"><code>POST <?= htmlspecialchars($apiUrl) ?>
Authorization: Bearer &lt;TOKEN&gt;
Content-Type: application/json

{ "action": "send_message", "chat_id": 123456, "text": "سلام 👋" }</code></pre>
                <div style="margin-top:8px;font-size:.8rem;color:var(--mute)">اکشن‌های مجاز:
                    <?php foreach ($allActions as $a => $lbl): ?>
                        <code style="margin:2px"><?= htmlspecialchars($a) ?></code>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-bottom:20px">
        <button type="submit" class="btn btn-primary"><?= icon('check', 14) ?> ذخیرهٔ تنظیمات</button>
        <a href="orders.php" class="btn btn-ghost">سفارش‌ها</a>
    </div>
</form>

<!-- side forms for regen buttons -->
<form id="regen-secret" method="POST" action="automation.php" style="display:none">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="regen_secret"></form>
<form id="regen-token" method="POST" action="automation.php" style="display:none">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="regen_token"></form>

<!-- Delivery log ------------------------------------------------------- -->
<div class="card fade-up d4" style="margin-bottom:24px">
    <div class="card-head"><div class="card-title">آخرین تحویل‌ها</div></div>
    <div class="card-body" style="overflow:auto">
        <?php if (!$logs): ?>
            <div style="color:var(--mute);font-size:.85rem">هنوز رویدادی ارسال نشده است.</div>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                <thead><tr style="text-align:right;color:var(--mute)">
                    <th style="padding:6px">زمان</th><th style="padding:6px">جهت</th><th style="padding:6px">رویداد</th>
                    <th style="padding:6px">کد</th><th style="padding:6px">وضعیت</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $lg): ?>
                    <tr style="border-top:1px solid var(--bd)">
                        <td style="padding:6px;white-space:nowrap"><?= htmlspecialchars((string) $lg['created_at']) ?></td>
                        <td style="padding:6px"><?= htmlspecialchars((string) $lg['direction']) ?></td>
                        <td style="padding:6px"><code><?= htmlspecialchars((string) $lg['event']) ?></code></td>
                        <td style="padding:6px"><?= htmlspecialchars((string) ($lg['http_code'] ?? '-')) ?></td>
                        <td style="padding:6px"><?= !empty($lg['ok']) ? '✅' : '❌' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
// Toggle per-endpoint event list when "all events" is checked.
function bindAllToggles(scope) {
    (scope || document).querySelectorAll('.ep-all-toggle').forEach(function (cb) {
        cb.onchange = function () {
            var box = document.querySelector('.ep-events[data-idx="' + this.getAttribute('data-idx') + '"]');
            if (box) box.style.display = this.checked ? 'none' : '';
        };
    });
}
bindAllToggles();

// Add a new endpoint row (clone template).
document.getElementById('add-ep').addEventListener('click', function () {
    var list = document.getElementById('ep-list');
    var idx = list.querySelectorAll('.ep-row').length;
    var evs = <?= json_encode($allEvents, JSON_UNESCAPED_UNICODE) ?>;
    var evHtml = '';
    Object.keys(evs).forEach(function (k) {
        evHtml += '<label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.82rem">'
            + '<input type="checkbox" name="ep_events[' + idx + '][]" value="' + k + '">'
            + '<span>' + evs[k] + ' <code style="font-size:.7rem;color:var(--dim)">' + k + '</code></span></label>';
    });
    var div = document.createElement('div');
    div.className = 'card ep-row';
    div.style = 'margin:0 0 12px;padding:12px;border:1px solid var(--bd)';
    div.innerHTML =
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
        + '<div class="field"><label>نام (دلخواه)</label><input type="text" name="ep_name[' + idx + ']" class="input"></div>'
        + '<div class="field"><label>آدرس Webhook</label><input type="url" name="ep_url[' + idx + ']" class="input" placeholder="https://..."></div></div>'
        + '<div style="display:flex;gap:18px;align-items:center;margin:6px 0 10px;flex-wrap:wrap">'
        + '<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="ep_active[' + idx + ']" value="1" checked> فعال</label>'
        + '<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" class="ep-all-toggle" data-idx="' + idx + '" name="ep_all[' + idx + ']" value="1" checked> همهٔ رویدادها</label></div>'
        + '<div class="ep-events" data-idx="' + idx + '" style="display:none;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;border-top:1px dashed var(--bd);padding-top:10px">' + evHtml + '</div>';
    list.appendChild(div);
    bindAllToggles(div);
});
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
