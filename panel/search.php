<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Unified advanced search (Step 8d).
 * Searches across products, orders and users in one place, with optional
 * filters (product type, order status, price range). Read-only — links out
 * to the relevant management pages. The VPN flow is untouched (orders here are
 * only shop rows: product_id IS NOT NULL).
 */

$cur      = function_exists('store_currency') ? store_currency() : 'تومان';
$statuses = function_exists('order_statuses') ? order_statuses() : [];
$types    = function_exists('product_types') ? product_types() : [];

$q        = trim((string) ($_GET['q'] ?? ''));
$ptype    = (string) ($_GET['ptype'] ?? '');
$ostatus  = (string) ($_GET['ostatus'] ?? '');
$priceMin = ($_GET['price_min'] ?? '') !== '' ? money_int($_GET['price_min']) : null;
$priceMax = ($_GET['price_max'] ?? '') !== '' ? money_int($_GET['price_max']) : null;
$like     = '%' . $q . '%';

$products = [];
$orders   = [];
$users    = [];
$ran      = ($q !== '' || $ptype !== '' || $ostatus !== '' || $priceMin !== null || $priceMax !== null);

if ($ran) {
    // ----- Products -----
    $pWhere = ["product_type <> 'vpn'"];
    $pParams = [];
    if ($q !== '') {
        $pWhere[] = "(name_product LIKE ? OR note LIKE ? OR category LIKE ? OR attributes LIKE ?)";
        array_push($pParams, $like, $like, $like, $like);
    }
    if ($ptype !== '' && isset($types[$ptype])) {
        $pWhere[] = "product_type = ?";
        $pParams[] = $ptype;
    }
    if ($priceMin !== null) {
        $pWhere[] = "CAST(price_product AS UNSIGNED) >= ?";
        $pParams[] = $priceMin;
    }
    if ($priceMax !== null) {
        $pWhere[] = "CAST(price_product AS UNSIGNED) <= ?";
        $pParams[] = $priceMax;
    }
    $products = db_fetchAll(
        $pdo,
        "SELECT * FROM product WHERE " . implode(' AND ', $pWhere) . " ORDER BY id DESC LIMIT 50",
        $pParams
    );

    // ----- Orders (shop only) -----
    $oWhere = ["product_id IS NOT NULL", "order_status IS NOT NULL"];
    $oParams = [];
    if ($q !== '') {
        $oWhere[] = "(id_order LIKE ? OR tracking_code LIKE ? OR id_user LIKE ?)";
        array_push($oParams, $like, $like, $like);
    }
    if ($ostatus !== '' && isset($statuses[$ostatus])) {
        $oWhere[] = "order_status = ?";
        $oParams[] = $ostatus;
    }
    if ($priceMin !== null) {
        $oWhere[] = "CAST(price AS UNSIGNED) >= ?";
        $oParams[] = $priceMin;
    }
    if ($priceMax !== null) {
        $oWhere[] = "CAST(price AS UNSIGNED) <= ?";
        $oParams[] = $priceMax;
    }
    $orders = db_fetchAll(
        $pdo,
        "SELECT * FROM Payment_report WHERE " . implode(' AND ', $oWhere) . " ORDER BY id DESC LIMIT 50",
        $oParams
    );

    // ----- Users -----
    if ($q !== '') {
        $users = db_fetchAll(
            $pdo,
            "SELECT * FROM user
             WHERE id LIKE ? OR COALESCE(username,'') LIKE ? OR COALESCE(namecustom,'') LIKE ? OR COALESCE(number,'') LIKE ?
             ORDER BY register DESC LIMIT 50",
            [$like, $like, $like, $like]
        );
    }
}

$pageTitle    = 'جستجوی پیشرفته';
$activeNav    = 'search';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="margin-bottom:16px" class="fade-up">
    <div style="font-size:1rem;font-weight:800;color:var(--text)">جستجوی پیشرفته</div>
    <div style="font-size:.8rem;color:var(--mute)">جستجو در محصول‌ها، سفارش‌ها و کاربران با فیلتر نوع، وضعیت و بازهٔ قیمت</div>
</div>

<div class="card fade-up d1" style="margin-bottom:16px">
    <div class="card-body">
        <form method="GET" action="search.php">
            <div class="field full">
                <label>عبارت جستجو</label>
                <input type="text" name="q" class="input" value="<?= htmlspecialchars($q) ?>"
                    placeholder="نام محصول، کد سفارش، کد رهگیری، آیدی/شماره/نام کاربر…">
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <div class="field" style="flex:1;min-width:150px">
                    <label>نوع محصول</label>
                    <select name="ptype" class="select">
                        <option value="">همه</option>
                        <?php foreach ($types as $tv => $tinfo): if ($tv === 'vpn') continue; ?>
                            <option value="<?= htmlspecialchars($tv) ?>" <?= $ptype === $tv ? 'selected' : '' ?>><?= htmlspecialchars($tinfo['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="flex:1;min-width:150px">
                    <label>وضعیت سفارش</label>
                    <select name="ostatus" class="select">
                        <option value="">همه</option>
                        <?php foreach ($statuses as $sv => $sl): ?>
                            <option value="<?= htmlspecialchars($sv) ?>" <?= $ostatus === $sv ? 'selected' : '' ?>><?= htmlspecialchars($sl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="flex:1;min-width:120px">
                    <label>حداقل قیمت</label>
                    <input type="text" name="price_min" class="input" data-money inputmode="numeric" value="<?= $priceMin !== null ? number_format((int) $priceMin) : '' ?>">
                </div>
                <div class="field" style="flex:1;min-width:120px">
                    <label>حداکثر قیمت</label>
                    <input type="text" name="price_max" class="input" data-money inputmode="numeric" value="<?= $priceMax !== null ? number_format((int) $priceMax) : '' ?>">
                </div>
            </div>
            <div style="margin-top:12px;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary"><?= icon('search', 14) ?> جستجو</button>
                <a href="search.php" class="btn btn-ghost">پاک‌کردن</a>
            </div>
        </form>
    </div>
</div>

<?php if ($ran): ?>

<!-- Products -->
<div class="card fade-up d2" style="margin-bottom:16px">
    <div class="card-head">
        <div class="card-title"><?= icon('package', 14) ?> محصول‌ها <small style="color:var(--dim);font-weight:400">(<?= count($products) ?>)</small></div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>#</th><th>نام</th><th>نوع</th><th>دسته</th><th>قیمت</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--mute);padding:18px">موردی یافت نشد.</td></tr>
                <?php else: foreach ($products as $p): ?>
                    <tr>
                        <td class="cn cf"><?= (int) $p['id'] ?></td>
                        <td><?= htmlspecialchars((string) $p['name_product']) ?></td>
                        <td><span class="tag tag-info"><?= htmlspecialchars($types[$p['product_type']]['label'] ?? (string) $p['product_type']) ?></span></td>
                        <td class="cf"><?= htmlspecialchars((string) ($p['category'] ?? '')) ?: '—' ?></td>
                        <td class="cf"><?= number_format((int) preg_replace('/[^\d]/', '', (string) $p['price_product'])) ?> <?= htmlspecialchars($cur) ?></td>
                        <td style="text-align:left"><a href="product.php" class="btn btn-ghost btn-sm">مدیریت</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Orders -->
<div class="card fade-up d3" style="margin-bottom:16px">
    <div class="card-head">
        <div class="card-title"><?= icon('invoice', 14) ?> سفارش‌ها <small style="color:var(--dim);font-weight:400">(<?= count($orders) ?>)</small></div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>کد سفارش</th><th>کاربر</th><th>مبلغ</th><th>وضعیت</th><th>کد رهگیری</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="6" style="text-align:center;color:var(--mute);padding:18px">موردی یافت نشد.</td></tr>
                <?php else: foreach ($orders as $o): ?>
                    <tr>
                        <td class="cm cs"><?= htmlspecialchars((string) $o['id_order']) ?></td>
                        <td class="cf"><code><?= htmlspecialchars((string) $o['id_user']) ?></code></td>
                        <td class="cf"><?= number_format((int) $o['price']) ?> <?= htmlspecialchars($cur) ?></td>
                        <td><span class="tag tag-info"><?= htmlspecialchars(order_status_label($o['order_status'] ?? '')) ?></span></td>
                        <td class="cf"><?= !empty($o['tracking_code']) ? htmlspecialchars((string) $o['tracking_code']) : '—' ?></td>
                        <td style="text-align:left"><a href="orders.php" class="btn btn-ghost btn-sm">مدیریت</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Users -->
<div class="card fade-up d4">
    <div class="card-head">
        <div class="card-title"><?= icon('users', 14) ?> کاربران <small style="color:var(--dim);font-weight:400">(<?= count($users) ?>)</small></div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>آیدی</th><th>نام</th><th>شماره</th><th>موجودی</th><th></th></tr></thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" style="text-align:center;color:var(--mute);padding:18px">موردی یافت نشد.</td></tr>
                <?php else: foreach ($users as $u): ?>
                    <tr>
                        <td class="cm cf"><code><?= htmlspecialchars((string) $u['id']) ?></code></td>
                        <td><?= htmlspecialchars((string) ($u['namecustom'] ?? '')) ?: '—' ?></td>
                        <td class="cf"><?= (!empty($u['number']) && $u['number'] !== 'none') ? htmlspecialchars((string) $u['number']) : '—' ?></td>
                        <td class="cf"><?= number_format((int) ($u['Balance'] ?? 0)) ?></td>
                        <td style="text-align:left"><a href="user.php?id=<?= urlencode((string) $u['id']) ?>" class="btn btn-ghost btn-sm">مشاهده</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
