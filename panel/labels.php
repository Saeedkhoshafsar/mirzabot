<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Branding & Terminology editor (Step 2).
 * Self-contained page: renders the form (GET) and saves overrides (POST),
 * mirroring the convention used by settings.php / keyboard.php.
 *
 * Scope-aware (multi-bot): bot_id = 0 -> main bot, >0 -> a child bot (botsaz.id).
 */

$lang = 'fa'; // panel edits the Persian labels (default language)

// --- Editable button labels (textbot keys). Source: docs/labels-map.md ---
$editableLabels = [
    'sell'              => 'دکمهٔ خرید/فروش محصول',
    'extend'            => 'دکمهٔ تمدید سرویس',
    'purchasedServices' => 'دکمهٔ سرویس‌ها/سفارش‌های من',
    'accountWallet'     => 'دکمهٔ کیف پول',
    'addBalance'        => 'دکمهٔ افزایش موجودی',
    'affiliates'        => 'دکمهٔ زیرمجموعه‌گیری',
    'tariffList'        => 'دکمهٔ تعرفه/لیست قیمت',
    'support'           => 'دکمهٔ پشتیبانی',
    'help'              => 'دکمهٔ آموزش/راهنما',
    'userTest'          => 'دکمهٔ تست',
    'wheelLuck'         => 'دکمهٔ گردونهٔ شانس',
    'discount'          => 'دکمهٔ کد تخفیف',
    'requestAgent'      => 'دکمهٔ درخواست نمایندگی',
];

// --- Store terminology words ---
$termLabels = [
    'customer' => 'واژهٔ «مشتری/کاربر»',
    'product'  => 'واژهٔ «محصول»',
    'service'  => 'واژهٔ «سرویس»',
    'order'    => 'واژهٔ «سفارش»',
    'wallet'   => 'واژهٔ «کیف پول»',
    'store'    => 'واژهٔ «فروشگاه»',
];

// --- Bot scope list: main bot + child bots from botsaz ---
$botScopes = [0 => 'ربات اصلی'];
try {
    $children = db_fetchAll($pdo, "SELECT id, username FROM botsaz ORDER BY id ASC");
    foreach ($children as $c) {
        $uname = trim((string) ($c['username'] ?? ''));
        $botScopes[(int) $c['id']] = 'ربات: ' . ($uname !== '' ? '@' . ltrim($uname, '@') : ('#' . $c['id']));
    }
} catch (Exception $e) {
    // botsaz may not exist; only main bot then
}

$botId = (int) ($_GET['bot'] ?? ($_POST['bot_id'] ?? 0));
if (!array_key_exists($botId, $botScopes)) {
    $botId = 0;
}

// --- Handle save (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_check_post();

    // 1) Button label overrides
    $labels = $_POST['labels'] ?? [];
    if (is_array($labels)) {
        foreach ($editableLabels as $key => $_desc) {
            if (!array_key_exists($key, $labels)) {
                continue;
            }
            $val = trim((string) $labels[$key]);
            $default = bot_label_default($key, $lang);
            // If unchanged from default (or empty) -> remove override to stay clean.
            if ($val === '' || $val === $default) {
                set_bot_label($key, $lang, null, $botId);
            } else {
                set_bot_label($key, $lang, $val, $botId);
            }
        }
    }

    // 2) Store terminology
    $terms = $_POST['terms'] ?? [];
    if (is_array($terms)) {
        $clean = [];
        foreach ($termLabels as $tk => $_d) {
            $tv = trim((string) ($terms[$tk] ?? ''));
            if ($tv !== '') {
                $clean[$tk] = $tv;
            }
        }
        set_store_terminology($clean, $botId);
    }

    // 3) Branding (only for main bot via setting table)
    if ($botId === 0) {
        $storeName = trim((string) ($_POST['store_name'] ?? ''));
        $storeCurrency = trim((string) ($_POST['store_currency'] ?? ''));
        try {
            $pdo->prepare("UPDATE setting SET store_name = ?, store_currency = ?")
                ->execute([$storeName, $storeCurrency]);
        } catch (Exception $e) {
            // columns may not exist yet on very old DBs; ignore
        }
    }

    flash('success', 'تغییرات با موفقیت ذخیره شد.');
    header('Location: labels.php?bot=' . $botId);
    exit;
}

// --- Load current values for the selected scope ---
$currentTerms = get_store_terminology($botId);

$branding = ['store_name' => '', 'store_currency' => ''];
if ($botId === 0) {
    try {
        $b = db_fetch($pdo, "SELECT store_name, store_currency FROM setting LIMIT 1");
        if ($b) {
            $branding['store_name'] = (string) ($b['store_name'] ?? '');
            $branding['store_currency'] = (string) ($b['store_currency'] ?? '');
        }
    } catch (Exception $e) {
    }
}

$flashOk = get_flash('success');
$flashErr = get_flash('error');

$pageTitle = 'برندینگ و اصطلاحات';
$activeNav = 'labels';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<?php if ($flashOk): ?>
    <div class="card fade-up" style="border-color:var(--ok,#22C55E);margin-bottom:14px">
        <div class="card-body" style="color:var(--ok,#22C55E);font-weight:600"><?= htmlspecialchars($flashOk) ?></div>
    </div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="card fade-up" style="border-color:#F43F5E;margin-bottom:14px">
        <div class="card-body" style="color:#F43F5E;font-weight:600"><?= htmlspecialchars($flashErr) ?></div>
    </div>
<?php endif; ?>

<!-- Bot scope selector -->
<div class="card fade-up" style="margin-bottom:14px">
    <div class="card-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <div style="font-weight:700"><?= icon('settings', 16) ?> انتخاب ربات:</div>
        <select onchange="location.href='labels.php?bot='+this.value"
            style="padding:9px 12px;border-radius:8px;background:var(--sf2,var(--sf));color:var(--fg);border:1px solid var(--bd)">
            <?php foreach ($botScopes as $bid => $bname): ?>
                <option value="<?= $bid ?>" <?= $bid === $botId ? 'selected' : '' ?>><?= htmlspecialchars($bname) ?></option>
            <?php endforeach; ?>
        </select>
        <div style="font-size:.78rem;color:var(--mute)">
            هر ربات می‌تواند اصطلاحات خودش را داشته باشد. خالی‌گذاشتن یک فیلد یعنی استفاده از مقدار پیش‌فرض.
        </div>
    </div>
</div>

<form method="POST" action="labels.php">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="bot_id" value="<?= $botId ?>">

    <?php if ($botId === 0): ?>
        <!-- Branding (main bot only) -->
        <div class="card fade-up" style="margin-bottom:14px">
            <div class="card-head">
                <div>
                    <div class="card-title">برندینگ فروشگاه</div>
                    <div class="card-subtitle">نام فروشگاه و واحد پول نمایشی</div>
                </div>
            </div>
            <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
                <label style="display:flex;flex-direction:column;gap:6px;font-size:.82rem;font-weight:600">
                    نام فروشگاه
                    <input type="text" name="store_name" value="<?= htmlspecialchars($branding['store_name']) ?>"
                        placeholder="مثلاً: فروشگاه من"
                        style="padding:10px;border-radius:8px;background:var(--sf2,var(--sf));color:var(--fg);border:1px solid var(--bd)">
                </label>
                <label style="display:flex;flex-direction:column;gap:6px;font-size:.82rem;font-weight:600">
                    واحد پول
                    <input type="text" name="store_currency" value="<?= htmlspecialchars($branding['store_currency']) ?>"
                        placeholder="مثلاً: تومان"
                        style="padding:10px;border-radius:8px;background:var(--sf2,var(--sf));color:var(--fg);border:1px solid var(--bd)">
                </label>
            </div>
        </div>
    <?php endif; ?>

    <!-- Store terminology -->
    <div class="card fade-up" style="margin-bottom:14px">
        <div class="card-head">
            <div>
                <div class="card-title">اصطلاحات فروشگاه</div>
                <div class="card-subtitle">واژگانی که در سراسر ربات استفاده می‌شوند</div>
            </div>
        </div>
        <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
            <?php foreach ($termLabels as $tk => $desc): ?>
                <label style="display:flex;flex-direction:column;gap:6px;font-size:.82rem;font-weight:600">
                    <?= htmlspecialchars($desc) ?>
                    <input type="text" name="terms[<?= $tk ?>]"
                        value="<?= htmlspecialchars((string) ($currentTerms[$tk] ?? '')) ?>"
                        style="padding:10px;border-radius:8px;background:var(--sf2,var(--sf));color:var(--fg);border:1px solid var(--bd)">
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Button labels -->
    <div class="card fade-up" style="margin-bottom:14px;border-color:var(--ac,#3b82f6)">
        <div class="card-head">
            <div>
                <div class="card-title">🌳 تغییر نام دکمه‌های ربات منتقل شد</div>
                <div class="card-subtitle">برای جلوگیری از سردرگمی، ویرایش نام دکمه‌ها حالا فقط در یک‌جا انجام می‌شود</div>
            </div>
        </div>
        <div class="card-body" style="line-height:1.9">
            <div>تغییر متن دکمه‌های ربات (خرید، کیف پول، پشتیبانی و…) حالا مستقیماً داخل
                <a href="flow.php" style="font-weight:700"><?= icon('settings', 14) ?> ویرایشگر دکمه‌ها (درختی)</a>
                انجام می‌شود — کافی است روی همان دکمه دابل‌کلیک کنید و نامش را عوض کنید.</div>
            <div style="margin-top:6px;color:var(--mute);font-size:.85rem">چرا؟ قبلاً نام دکمه‌ها هم اینجا و هم در ویرایشگر درختی قابل تغییر بود و این گیج‌کننده بود. حالا این صفحه فقط برای «واژگان فروشگاه» و «برندینگ» است؛ نام و چیدمان دکمه‌ها در درخت مدیریت می‌شود.</div>
            <div style="margin-top:10px">
                <a href="flow.php" class="btn btn-primary btn-sm"><?= icon('settings', 14) ?> رفتن به ویرایشگر دکمه‌ها</a>
            </div>
        </div>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;margin-bottom:24px">
        <button type="submit" class="btn btn-primary"><?= icon('check', 16) ?> ذخیرهٔ تغییرات</button>
    </div>
</form>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
