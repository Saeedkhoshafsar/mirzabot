<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Store / panel profile configuration (Step 8e).
 * Lets the admin choose the panel profile (vpn / shop / digital / channel /
 * custom) and the store currency. The profile shapes the whole experience and
 * the default product type for new products.
 *
 * Resolution priority at runtime (see function.php panel_mode()):
 *   ENV PANEL_MODE  >  botsaz.setting.panel_mode (per child bot)  >
 *   setting.store_mode (global)  >  'vpn'
 *
 * This page writes the GLOBAL setting (bot_id = 0). For per-container Coolify
 * deploys, set the PANEL_MODE environment variable instead (it overrides this).
 */

$profiles = function_exists('panel_profiles') ? panel_profiles() : [];
$envMode  = getenv('PANEL_MODE');
$envLocked = ($envMode !== false && function_exists('is_valid_panel_mode') && is_valid_panel_mode($envMode));

// --------------------------------------------------------------- SAVE -------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_store') {
    csrf_check_post();
    $mode     = (string) ($_POST['panel_mode'] ?? 'vpn');
    $currency = trim((string) ($_POST['store_currency'] ?? ''));

    if (!isset($profiles[$mode])) {
        flash('error', 'حالت انتخاب‌شده نامعتبر است.');
        header('Location: store.php');
        exit;
    }
    try {
        // Global profile (bot_id 0). Per-bot/ENV overrides are handled elsewhere.
        if (function_exists('set_panel_mode')) {
            set_panel_mode($mode, 0);
        } else {
            db_query($pdo, "UPDATE setting SET store_mode = ?", [$mode]);
        }
        db_query($pdo, "UPDATE setting SET store_currency = ?", [$currency]);
        flash('success', 'تنظیمات فروشگاه ذخیره شد.');
    } catch (Exception $e) {
        flash('error', 'خطا در ذخیره: ' . $e->getMessage());
    }
    header('Location: store.php');
    exit;
}

// --------------------------------------------------------------- LOAD -------
$row = db_fetch($pdo, "SELECT store_mode, store_currency FROM setting LIMIT 1");
$currentMode = $envLocked ? $envMode : (string) ($row['store_mode'] ?? 'vpn');
if (!isset($profiles[$currentMode])) {
    $currentMode = 'vpn';
}
$currency = (string) ($row['store_currency'] ?? '');
if ($currency === '') {
    $currency = 'تومان';
}

$flashOk  = get_flash('success');
$flashErr = get_flash('error');

$pageTitle    = 'تنظیمات فروشگاه';
$activeNav    = 'store';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="margin-bottom:16px" class="fade-up">
    <div style="font-size:1rem;font-weight:800;color:var(--text)">تنظیمات فروشگاه و حالت پنل</div>
    <div style="font-size:.8rem;color:var(--mute)">حالت پنل را انتخاب کنید — هر کانتینر/ربات می‌تواند حالت متفاوتی داشته باشد</div>
</div>

<?php if ($flashOk): ?>
    <div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<?php if ($envLocked): ?>
    <div class="notice notice-warn">
        حالت پنل از طریق متغیر محیطی <code>PANEL_MODE=<?= htmlspecialchars((string) $envMode) ?></code> تنظیم شده است
        (مناسب استقرار چندکانتینری Coolify). این مقدار بر تنظیمات این صفحه اولویت دارد؛
        برای تغییر، متغیر محیطی کانتینر را ویرایش کنید.
    </div>
<?php endif; ?>

<form method="POST" action="store.php">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_store">

    <div class="card fade-up d1" style="margin-bottom:16px">
        <div class="card-head">
            <div>
                <div class="card-title">حالت پنل (Profile)</div>
                <div class="card-subtitle">رفتار ربات، واژگان و نوع پیش‌فرض محصول را تعیین می‌کند</div>
            </div>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px">
                <?php foreach ($profiles as $code => $p):
                    $checked = ($code === $currentMode); ?>
                    <label class="card" style="cursor:<?= $envLocked ? 'not-allowed' : 'pointer' ?>;margin:0;padding:14px;border:2px solid <?= $checked ? 'var(--ac)' : 'var(--bd)' ?>;opacity:<?= ($envLocked && !$checked) ? '.5' : '1' ?>">
                        <div style="display:flex;align-items:center;gap:10px">
                            <input type="radio" name="panel_mode" value="<?= htmlspecialchars($code) ?>"
                                <?= $checked ? 'checked' : '' ?> <?= $envLocked ? 'disabled' : '' ?>>
                            <div>
                                <div style="font-weight:800;color:var(--text)"><?= htmlspecialchars($p['label']) ?></div>
                                <div style="font-size:.78rem;color:var(--mute);margin-top:3px"><?= htmlspecialchars($p['desc']) ?></div>
                                <div style="font-size:.72rem;color:var(--dim);margin-top:5px">نوع پیش‌فرض محصول: <code><?= htmlspecialchars($p['product_type']) ?></code></div>
                            </div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card fade-up d2" style="margin-bottom:16px">
        <div class="card-head">
            <div class="card-title">واحد پول</div>
        </div>
        <div class="card-body">
            <div class="field" style="max-width:260px">
                <label>واحد پول فروشگاه</label>
                <input type="text" name="store_currency" class="input" value="<?= htmlspecialchars($currency) ?>" placeholder="تومان">
                <div class="field-hint">در قیمت‌ها و فاکتورها نمایش داده می‌شود (مثلاً: تومان، ریال، USDT).</div>
            </div>
        </div>
    </div>

    <div>
        <button type="submit" class="btn btn-primary" <?= $envLocked ? '' : '' ?>><?= icon('check', 14) ?> ذخیره تنظیمات</button>
        <a href="labels.php" class="btn btn-ghost">ویرایش واژگان و برندینگ</a>
    </div>
</form>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
