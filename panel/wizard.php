<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Setup wizard (Step 9).
 * A one-click way to configure a fresh install: pick a ready-made preset
 * (VPN / online shop / digital files / channel / custom). Applying a preset
 * seeds the panel mode + store terminology + currency in one shot via
 * apply_panel_preset(). The user can then fine-tune everything in store.php /
 * labels.php / product.php.
 *
 * This NEVER touches the VPN flow logic — it only seeds display settings and
 * the panel profile. Re-running is safe (idempotent).
 */

$presets  = function_exists('panel_presets') ? panel_presets() : [];
$envMode  = getenv('PANEL_MODE');
$envLocked = ($envMode !== false && function_exists('is_valid_panel_mode') && is_valid_panel_mode($envMode));

// --------------------------------------------------------------- APPLY ------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_preset') {
    csrf_check_post();
    $key = (string) ($_POST['preset'] ?? '');
    if (!isset($presets[$key])) {
        flash('error', 'قالب انتخاب‌شده نامعتبر است.');
        header('Location: wizard.php');
        exit;
    }
    if ($envLocked) {
        flash('error', 'حالت پنل با متغیر محیطی PANEL_MODE قفل شده است؛ ابتدا آن را بردارید.');
        header('Location: wizard.php');
        exit;
    }
    $overrides = [];
    $cur = trim((string) ($_POST['store_currency'] ?? ''));
    if ($cur !== '') {
        $overrides['currency'] = $cur;
    }
    if (function_exists('apply_panel_preset') && apply_panel_preset($key, 0, $overrides)) {
        flash('success', 'قالب «' . ($presets[$key]['label'] ?? $key) . '» اعمال شد. می‌توانید جزئیات را در «تنظیمات فروشگاه» و «برندینگ» تنظیم کنید.');
    } else {
        flash('error', 'اعمال قالب ناموفق بود.');
    }
    header('Location: wizard.php');
    exit;
}

// --------------------------------------------------------------- LOAD -------
$row = db_fetch($pdo, "SELECT store_mode, store_currency FROM setting LIMIT 1");
$currentMode = $envLocked ? $envMode : (string) ($row['store_mode'] ?? 'vpn');
$currency    = (string) ($row['store_currency'] ?? '') ?: 'تومان';

$flashOk  = get_flash('success');
$flashErr = get_flash('error');

$pageTitle    = 'راه‌اندازی سریع';
$activeNav    = 'wizard';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="margin-bottom:16px" class="fade-up">
    <div style="font-size:1rem;font-weight:800;color:var(--text)">ویزارد راه‌اندازی سریع</div>
    <div style="font-size:.8rem;color:var(--mute)">یک قالب آماده را انتخاب کنید تا حالت پنل، واژگان و واحد پول یک‌جا تنظیم شود</div>
</div>

<?php if ($flashOk): ?>
    <div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<?php if ($envLocked): ?>
    <div class="notice notice-warn">
        حالت پنل از طریق متغیر محیطی <code>PANEL_MODE=<?= htmlspecialchars((string) $envMode) ?></code> قفل شده است
        (استقرار چندکانتینری Coolify). اعمال قالب در این حالت غیرفعال است؛ برای تغییر، متغیر محیطی کانتینر را ویرایش کنید.
    </div>
<?php endif; ?>

<div class="notice" style="margin-bottom:16px">
    حالت فعلی: <strong><?= htmlspecialchars($presets[$currentMode]['label'] ?? $currentMode) ?></strong>
    · واحد پول: <strong><?= htmlspecialchars($currency) ?></strong>
</div>

<form method="POST" action="wizard.php">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="apply_preset">

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:16px">
        <?php foreach ($presets as $key => $p):
            $checked = ($key === $currentMode); ?>
            <label class="card" style="cursor:<?= $envLocked ? 'not-allowed' : 'pointer' ?>;margin:0;padding:16px;border:2px solid <?= $checked ? 'var(--ac)' : 'var(--bd)' ?>;opacity:<?= ($envLocked && !$checked) ? '.5' : '1' ?>">
                <div style="display:flex;align-items:flex-start;gap:10px">
                    <input type="radio" name="preset" value="<?= htmlspecialchars($key) ?>"
                        <?= $checked ? 'checked' : '' ?> <?= $envLocked ? 'disabled' : '' ?> style="margin-top:4px">
                    <div style="flex:1">
                        <div style="font-weight:800;color:var(--text)"><?= htmlspecialchars($p['label']) ?></div>
                        <div style="font-size:.76rem;color:var(--mute);margin-top:6px;line-height:1.7">
                            مشتری: <b><?= htmlspecialchars($p['terms']['customer'] ?? '') ?></b> ·
                            محصول: <b><?= htmlspecialchars($p['terms']['product'] ?? '') ?></b> ·
                            سفارش: <b><?= htmlspecialchars($p['terms']['order'] ?? '') ?></b>
                        </div>
                        <div style="font-size:.72rem;color:var(--dim);margin-top:5px">واحد پول پیشنهادی: <?= htmlspecialchars($p['currency'] ?? '') ?></div>
                    </div>
                </div>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="card fade-up" style="margin-bottom:16px">
        <div class="card-body">
            <div class="field" style="max-width:260px">
                <label>واحد پول (اختیاری — جایگزین پیش‌فرض قالب)</label>
                <input type="text" name="store_currency" class="input" placeholder="مثلاً تومان">
                <div class="field-hint">خالی بگذارید تا واحد پولِ پیش‌فرض قالب استفاده شود.</div>
            </div>
        </div>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary" <?= $envLocked ? 'disabled' : '' ?>><?= icon('check', 14) ?> اعمال قالب</button>
        <a href="store.php" class="btn btn-ghost">تنظیمات پیشرفتهٔ فروشگاه</a>
        <a href="labels.php" class="btn btn-ghost">ویرایش واژگان</a>
        <a href="product.php" class="btn btn-ghost">افزودن محصول</a>
    </div>
</form>

<div class="notice" style="margin-top:18px;line-height:1.9">
    <strong>قدم‌های بعد از اعمال قالب:</strong><br>
    ۱) در «<a href="product.php">محصولات</a>» محصول‌هایت را اضافه کن (نوع پیش‌فرض بر اساس قالب انتخاب می‌شود).<br>
    ۲) برای کالای فیزیکی، در «<a href="orders.php">سفارش‌ها</a>» کد رهگیری ثبت کن.<br>
    ۳) در «<a href="discounts.php">کدهای تخفیف</a>» کمپین تخفیف بساز.<br>
    ۴) واژگان و برندینگ را در «<a href="labels.php">برندینگ</a>» شخصی‌سازی کن.
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
