<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Product serial/license codes manager (Step 6).
 * Batch-add (one code per line), list with status, and delete unused codes.
 * Only meaningful for products of type 'serial_code', but accessible for any
 * product (the bot delivers a free code on purchase via deliver_serial_code()).
 */

$pid = (int) ($_GET['pid'] ?? ($_POST['pid'] ?? 0));
$product = $pid ? db_fetch($pdo, "SELECT * FROM product WHERE id = ?", [$pid]) : null;
if (!$product) {
    flash('error', 'محصول یافت نشد.');
    header('Location: product.php');
    exit;
}

// --- Handle delete (only available codes) ---
if (isset($_GET['delete'])) {
    csrf_check_get();
    if (product_codes_delete((int) $_GET['delete'])) {
        flash('success', 'کد حذف شد.');
    } else {
        flash('error', 'این کد قابل حذف نیست (احتمالاً فروخته شده است).');
    }
    header('Location: product_codes.php?pid=' . $pid);
    exit;
}

// --- Handle bulk add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_codes') {
    csrf_check_post();
    $raw = $_POST['codes'] ?? '';
    $res = product_codes_add_bulk($pid, $raw);
    if ($res['added'] > 0) {
        $msg = $res['added'] . ' کد افزوده شد.';
        if ($res['skipped'] > 0) {
            $msg .= ' (' . $res['skipped'] . ' کد تکراری نادیده گرفته شد)';
        }
        flash('success', $msg);
    } elseif ($res['skipped'] > 0) {
        flash('error', 'همهٔ کدها تکراری بودند؛ چیزی افزوده نشد.');
    } else {
        flash('error', 'کدی وارد نشد.');
    }
    header('Location: product_codes.php?pid=' . $pid);
    exit;
}

$counts = product_codes_count($pid);
$codes  = product_codes_list($pid, null, 500);

$flashOk  = get_flash('success');
$flashErr = get_flash('error');

$pageTitle    = 'کدها / سریال محصول';
$activeNav     = 'product';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px" class="fade-up">
    <div>
        <div style="font-size:1rem;font-weight:800;color:var(--text)"><?= htmlspecialchars($product['name_product'] ?? '') ?></div>
        <div style="font-size:.8rem;color:var(--mute)">مدیریت کدها/سریال‌های لایسنس — هر کد پس از خرید به یک خریدار تحویل می‌شود</div>
    </div>
    <a href="product.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> بازگشت به محصولات</a>
</div>

<?php if ($flashOk): ?>
    <div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<div class="stats" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat ok">
        <div class="stat-label">موجود (آزاد)</div>
        <div class="stat-num"><?= number_format($counts['available']) ?></div>
    </div>
    <div class="stat no">
        <div class="stat-label">فروخته‌شده</div>
        <div class="stat-num"><?= number_format($counts['sold']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">کل</div>
        <div class="stat-num"><?= number_format($counts['total']) ?></div>
    </div>
</div>

<?php if ($counts['available'] === 0): ?>
    <div class="notice notice-warn">هیچ کد آزادی موجود نیست؛ تا زمانی که کد اضافه نکنید، خرید این محصول ممکن نیست.</div>
<?php endif; ?>

<div class="card fade-up d1" style="margin-bottom:16px">
    <div class="card-head">
        <div>
            <div class="card-title">افزودن کد به‌صورت دسته‌ای</div>
            <div class="card-subtitle">هر کد را در یک خط جداگانه بنویسید. کدهای تکراری نادیده گرفته می‌شوند.</div>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="product_codes.php?pid=<?= $pid ?>">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="add_codes">
            <input type="hidden" name="pid" value="<?= $pid ?>">
            <div class="field">
                <textarea name="codes" class="textarea" style="min-height:140px;font-family:var(--mono)"
                    placeholder="ABCD-1234-EFGH&#10;XXXX-YYYY-ZZZZ&#10;..."></textarea>
                <div class="field-hint">مثال: کلید لایسنس، کد فعال‌سازی، اشتراک یکبارمصرف و...</div>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="btn btn-primary"><?= icon('plus', 14) ?> افزودن کدها</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-up d2">
    <div class="card-head">
        <div class="card-title">فهرست کدها <small style="color:var(--dim);font-weight:400">(<?= count($codes) ?>)</small></div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>کد</th>
                    <th>وضعیت</th>
                    <th>خریدار</th>
                    <th>زمان فروش</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($codes)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--mute);padding:24px">هنوز کدی اضافه نشده است.</td></tr>
                <?php else: foreach ($codes as $c):
                    $isSold = ($c['status'] === 'sold'); ?>
                    <tr>
                        <td class="cn cf"><?= (int) $c['id'] ?></td>
                        <td class="cm cs"><?= htmlspecialchars($c['code']) ?></td>
                        <td>
                            <?php if ($isSold): ?>
                                <span class="tag tag-no">فروخته‌شده</span>
                            <?php else: ?>
                                <span class="tag tag-ok">آزاد</span>
                            <?php endif; ?>
                        </td>
                        <td class="cf"><?= $c['buyer_id'] ? htmlspecialchars($c['buyer_id']) : '—' ?></td>
                        <td class="cf"><?= $c['sold_at'] ? safe_date($c['sold_at']) : '—' ?></td>
                        <td style="text-align:left">
                            <?php if (!$isSold): ?>
                                <a href="product_codes.php?pid=<?= $pid ?>&delete=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>"
                                    class="btn btn-no btn-sm btn-icon" title="حذف"
                                    data-confirm="این کد حذف شود؟"><?= icon('trash', 13) ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
