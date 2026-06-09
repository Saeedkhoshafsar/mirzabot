<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Shipping settings (Step 12).
 * Lets the admin enable/disable carriers (post / tipax / chapar / mahex / snapp
 * / other), enter the PRIVATE per-carrier API credentials each merchant gets
 * from that service, set a per-carrier shipping cost, and configure a
 * free-shipping rule (always, or above a minimum order total).
 *
 * Config is stored as JSON in setting.store_shipping (global) or
 * botsaz.setting.store_shipping (per child bot). VPN behaviour is unaffected:
 * an empty config means no carrier is active.
 */

$carriers = function_exists('shipping_carriers') ? shipping_carriers() : [];
$cfg      = function_exists('get_shipping_config') ? get_shipping_config(0) : [
    'carriers' => [], 'free_shipping' => ['enabled' => false, 'min_order' => 0], 'default_cost' => 0,
];

// --------------------------------------------------------------- SAVE -------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_shipping') {
    csrf_check_post();

    $newCfg = [
        'carriers'      => [],
        'free_shipping' => [
            'enabled'   => !empty($_POST['free_enabled']),
            'min_order' => max(0, (int) ($_POST['free_min_order'] ?? 0)),
        ],
        'default_cost'  => max(0, (int) ($_POST['default_cost'] ?? 0)),
        // Global weight tariff: base + per-kg (Audit-4).
        'weight_tariff' => [
            'base'   => max(0, (int) ($_POST['wt_base'] ?? 0)),
            'per_kg' => max(0, (int) ($_POST['wt_per_kg'] ?? 0)),
        ],
    ];

    $postedCarriers = $_POST['carrier'] ?? [];
    foreach ($carriers as $code => $meta) {
        $row     = is_array($postedCarriers[$code] ?? null) ? $postedCarriers[$code] : [];
        $enabled = !empty($row['enabled']);
        $cost    = max(0, (int) ($row['cost'] ?? 0));
        $wtBase  = max(0, (int) ($row['wt_base'] ?? 0));
        $wtPerKg = max(0, (int) ($row['wt_per_kg'] ?? 0));
        $creds   = [];
        foreach (($meta['fields'] ?? []) as $fkey => $fmeta) {
            $val = trim((string) ($row['creds'][$fkey] ?? ''));
            if ($val !== '') {
                $creds[$fkey] = $val;
            }
        }
        // Only persist a carrier entry if it is enabled OR carries data.
        if ($enabled || $cost > 0 || $wtBase > 0 || $wtPerKg > 0 || !empty($creds)) {
            $entry = [
                'enabled' => $enabled,
                'cost'    => $cost,
                'creds'   => $creds,
            ];
            if ($wtBase > 0 || $wtPerKg > 0) {
                $entry['weight_tariff'] = ['base' => $wtBase, 'per_kg' => $wtPerKg];
            }
            $newCfg['carriers'][$code] = $entry;
        }
    }

    // Validate required credentials for enabled carriers.
    $missing = [];
    foreach ($newCfg['carriers'] as $code => $c) {
        if (empty($c['enabled'])) {
            continue;
        }
        foreach (($carriers[$code]['fields'] ?? []) as $fkey => $fmeta) {
            if (!empty($fmeta['required']) && empty($c['creds'][$fkey])) {
                $missing[] = ($carriers[$code]['name'] ?? $code) . ' → ' . $fmeta['label'];
            }
        }
    }

    if ($missing) {
        flash('error', 'این فیلدهای ضروری خالی‌اند: ' . implode('، ', $missing));
        header('Location: shipping.php');
        exit;
    }

    if (function_exists('set_shipping_config') && set_shipping_config($newCfg, 0)) {
        flash('success', 'تنظیمات حمل‌ونقل ذخیره شد.');
    } else {
        flash('error', 'خطا در ذخیرهٔ تنظیمات حمل‌ونقل.');
    }
    header('Location: shipping.php');
    exit;
}

$flashOk  = get_flash('success');
$flashErr = get_flash('error');

$pageTitle    = 'حمل‌ونقل و ارسال';
$activeNav    = 'shipping';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="margin-bottom:16px" class="fade-up">
    <div style="font-size:1rem;font-weight:800;color:var(--text)">حمل‌ونقل و ارسال</div>
    <div style="font-size:.8rem;color:var(--mute)">شرکت‌های ارسال را فعال کنید، اطلاعات اختصاصی API هر سرویس را وارد کنید و ارسال رایگان را تنظیم کنید</div>
</div>

<?php if ($flashOk): ?>
    <div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<div class="notice">
    <b>ترتیب محاسبهٔ هزینهٔ ارسال</b> (اولین موردی که اعمال شود):
    <ol style="margin:8px 0 0;padding-inline-start:20px;font-size:.82rem;line-height:1.9">
        <li>اگر <b>ارسال رایگان</b> فعال باشد و شرطش برقرار شود → رایگان</li>
        <li>اگر برای شرکت یک <b>آدرس API نرخ‌دهی</b> وارد شده باشد → قیمت <b>زنده</b> بر اساس وزن گرفته می‌شود</li>
        <li>اگر <b>نرخ پلکانی وزنی</b> تنظیم شده باشد → <code>پایه + (هر کیلو × وزن سفارش)</code></li>
        <li>در غیر این صورت → هزینهٔ ثابت شرکت، و اگر نبود هزینهٔ پیش‌فرض فروشگاه</li>
    </ol>
    <div style="margin-top:8px;font-size:.8rem;color:var(--mute)">
        وزن از فیلد «وزن (گرم)» محصول خوانده می‌شود. اطلاعات API هر کسب‌وکار <b>خصوصی</b> است و دستی وارد می‌شود.
        <br>توجه: فیلد «روش‌های ارسال» داخل فرم محصول فقط جنبهٔ <b>نمایشی/اطلاع‌رسانی</b> دارد؛ هزینهٔ واقعی از همین صفحه (سطح فروشگاه) محاسبه می‌شود.
    </div>
</div>

<form method="POST" action="shipping.php">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="save_shipping">

    <!-- Free shipping --------------------------------------------------- -->
    <div class="card fade-up d1" style="margin-bottom:16px">
        <div class="card-head">
            <div>
                <div class="card-title">ارسال رایگان</div>
                <div class="card-subtitle">می‌توانید ارسال را همیشه رایگان کنید یا فقط بالای یک مبلغ مشخص</div>
            </div>
        </div>
        <div class="card-body">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:12px">
                <input type="checkbox" name="free_enabled" value="1" <?= !empty($cfg['free_shipping']['enabled']) ? 'checked' : '' ?>>
                <span style="font-weight:700;color:var(--text)">ارسال رایگان فعال باشد</span>
            </label>
            <div class="field" style="max-width:320px">
                <label>حداقل مبلغ سفارش برای رایگان شدن (<?= htmlspecialchars(function_exists('store_currency') ? store_currency() : 'تومان') ?>)</label>
                <input type="number" min="0" name="free_min_order" class="input"
                       value="<?= (int) ($cfg['free_shipping']['min_order'] ?? 0) ?>" placeholder="0">
                <div class="field-hint"><code>0</code> یعنی همیشه رایگان. عدد بزرگ‌تر یعنی فقط سفارش‌های بالای آن مبلغ رایگان می‌شوند.</div>
            </div>
            <div class="field" style="max-width:320px;margin-top:12px">
                <label>هزینهٔ ارسال پیش‌فرض (<?= htmlspecialchars(function_exists('store_currency') ? store_currency() : 'تومان') ?>)</label>
                <input type="number" min="0" name="default_cost" class="input"
                       value="<?= (int) ($cfg['default_cost'] ?? 0) ?>" placeholder="0">
                <div class="field-hint">وقتی شرکت ارسالی هزینهٔ اختصاصی ندارد، این مبلغ اعمال می‌شود (در صورت رایگان نبودن).</div>
            </div>
        </div>
    </div>

    <!-- Global weight tariff -------------------------------------------- -->
    <?php $gwt = is_array($cfg['weight_tariff'] ?? null) ? $cfg['weight_tariff'] : ['base' => 0, 'per_kg' => 0]; ?>
    <div class="card fade-up d1" style="margin-bottom:16px">
        <div class="card-head">
            <div>
                <div class="card-title">نرخ‌گذاری بر اساس وزن (پلکانی)</div>
                <div class="card-subtitle">قیمت = هزینهٔ پایه + (هزینهٔ هر کیلوگرم × وزن سفارش به کیلوگرم، رو به بالا). اگر هر دو صفر باشد، غیرفعال است.</div>
            </div>
        </div>
        <div class="card-body" style="display:flex;gap:14px;flex-wrap:wrap">
            <div class="field" style="max-width:240px">
                <label>هزینهٔ پایه (<?= htmlspecialchars(function_exists('store_currency') ? store_currency() : 'تومان') ?>)</label>
                <input type="number" min="0" name="wt_base" class="input" value="<?= (int) ($gwt['base'] ?? 0) ?>" placeholder="0">
                <div class="field-hint">هزینهٔ ثابت اولیهٔ هر مرسوله.</div>
            </div>
            <div class="field" style="max-width:240px">
                <label>هزینهٔ هر کیلوگرم (<?= htmlspecialchars(function_exists('store_currency') ? store_currency() : 'تومان') ?>)</label>
                <input type="number" min="0" name="wt_per_kg" class="input" value="<?= (int) ($gwt['per_kg'] ?? 0) ?>" placeholder="0">
                <div class="field-hint">به‌ازای هر کیلوگرم وزن. مثال: پایه ۳۰٬۰۰۰ + هر کیلو ۱۵٬۰۰۰ → بستهٔ ۲٫۳ کیلویی = ۳۰٬۰۰۰ + ۳×۱۵٬۰۰۰.</div>
            </div>
        </div>
    </div>

    <!-- Carriers -------------------------------------------------------- -->
    <div class="card fade-up d2" style="margin-bottom:16px">
        <div class="card-head">
            <div>
                <div class="card-title">شرکت‌های ارسال</div>
                <div class="card-subtitle">هر شرکت را فعال کنید تا فیلدهای اختصاصی آن باز شود</div>
            </div>
        </div>
        <div class="card-body">
            <?php foreach ($carriers as $code => $meta):
                $cc       = $cfg['carriers'][$code] ?? [];
                $enabled  = !empty($cc['enabled']);
                $cost     = (int) ($cc['cost'] ?? 0);
                $creds    = is_array($cc['creds'] ?? null) ? $cc['creds'] : [];
                $hasFields = !empty($meta['fields']);
            ?>
            <div class="card" style="margin:0 0 14px;padding:14px;border:1px solid var(--bd)">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                    <input type="checkbox" class="carrier-toggle" data-target="creds-<?= htmlspecialchars($code) ?>"
                           name="carrier[<?= htmlspecialchars($code) ?>][enabled]" value="1" <?= $enabled ? 'checked' : '' ?>>
                    <span style="font-weight:800;color:var(--text)"><?= htmlspecialchars($meta['name']) ?></span>
                    <code style="margin-inline-start:auto;font-size:.72rem;color:var(--dim)"><?= htmlspecialchars($code) ?></code>
                </label>

                <?php $cwt = is_array($cc['weight_tariff'] ?? null) ? $cc['weight_tariff'] : ['base' => 0, 'per_kg' => 0]; ?>
                <div id="creds-<?= htmlspecialchars($code) ?>" class="carrier-fields" style="<?= $enabled ? '' : 'display:none;' ?>margin-top:12px;padding-top:12px;border-top:1px dashed var(--bd)">
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                        <div class="field" style="max-width:220px">
                            <label>هزینهٔ ثابت این شرکت</label>
                            <input type="number" min="0" name="carrier[<?= htmlspecialchars($code) ?>][cost]" class="input"
                                   value="<?= $cost ?>" placeholder="0">
                            <div class="field-hint">خالی/۰ = از پیش‌فرض استفاده شود.</div>
                        </div>
                        <div class="field" style="max-width:160px">
                            <label>نرخ وزنی: پایه</label>
                            <input type="number" min="0" name="carrier[<?= htmlspecialchars($code) ?>][wt_base]" class="input"
                                   value="<?= (int) ($cwt['base'] ?? 0) ?>" placeholder="0">
                        </div>
                        <div class="field" style="max-width:160px">
                            <label>نرخ وزنی: هر کیلو</label>
                            <input type="number" min="0" name="carrier[<?= htmlspecialchars($code) ?>][wt_per_kg]" class="input"
                                   value="<?= (int) ($cwt['per_kg'] ?? 0) ?>" placeholder="0">
                            <div class="field-hint">اگر تنظیم شود، بر نرخ وزنی سراسری اولویت دارد.</div>
                        </div>
                    </div>

                    <?php if ($hasFields): ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">
                            <?php foreach ($meta['fields'] as $fkey => $fmeta):
                                $ftype = ($fmeta['type'] ?? 'text');
                                $inputType = $ftype === 'password' ? 'password' : ($ftype === 'number' ? 'number' : 'text');
                                $val = (string) ($creds[$fkey] ?? '');
                            ?>
                                <div class="field">
                                    <label>
                                        <?= htmlspecialchars($fmeta['label'] ?? $fkey) ?>
                                        <?php if (!empty($fmeta['required'])): ?><span style="color:var(--danger,#e55)">*</span><?php endif; ?>
                                    </label>
                                    <input type="<?= $inputType ?>" autocomplete="off"
                                           name="carrier[<?= htmlspecialchars($code) ?>][creds][<?= htmlspecialchars($fkey) ?>]"
                                           class="input" value="<?= htmlspecialchars($val) ?>">
                                    <?php if (!empty($fmeta['hint'])): ?>
                                        <div class="field-hint"><?= htmlspecialchars($fmeta['hint']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:.78rem;color:var(--mute)">این گزینه نیازی به اطلاعات API ندارد (تحویل دستی/حضوری).</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <button type="submit" class="btn btn-primary"><?= icon('check', 14) ?> ذخیره تنظیمات حمل‌ونقل</button>
        <a href="orders.php" class="btn btn-ghost">مدیریت سفارش‌ها</a>
    </div>
</form>

<script>
// Toggle the credential block when a carrier checkbox changes.
document.querySelectorAll('.carrier-toggle').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var box = document.getElementById(this.getAttribute('data-target'));
        if (box) { box.style.display = this.checked ? '' : 'none'; }
    });
});
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
