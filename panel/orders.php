<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Shop orders manager (Step 8b).
 * Lists shop orders (Payment_report rows that have product_id + order_status),
 * lets the admin change order status, attach a shipping carrier + tracking
 * code, and add an admin note. On save the customer is notified in the bot
 * (notify_order_update) including a clickable tracking link when supported.
 *
 * VPN service rows are NOT shown here (they have no product_id/order_status),
 * so the VPN flow is completely unaffected.
 */

$cur       = function_exists('store_currency') ? store_currency() : 'تومان';
$statuses  = function_exists('order_statuses') ? order_statuses() : [];
$carriers  = function_exists('shipping_carriers') ? shipping_carriers() : [];

// ----------------------------------------------------- UPDATE STATUS --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_order') {
    csrf_check_post();
    $orderId  = (string) ($_POST['order_id'] ?? '');
    $status   = (string) ($_POST['order_status'] ?? '');
    $carrier  = (string) ($_POST['shipping_carrier'] ?? '');
    $tracking = trim((string) ($_POST['tracking_code'] ?? ''));
    $note     = trim((string) ($_POST['admin_note'] ?? ''));

    if ($orderId === '') {
        flash('error', 'کد سفارش نامعتبر است.');
        header('Location: orders.php');
        exit;
    }

    try {
        // Persist tracking/carrier + admin note (status set below to honour bump rules).
        db_query(
            $pdo,
            "UPDATE Payment_report
             SET shipping_carrier = ?, carrier_name = ?, tracking_code = ?, admin_note = ?, at_updated = ?
             WHERE id_order = ?",
            [
                $carrier !== '' ? $carrier : null,
                $carrier !== '' ? ($carriers[$carrier]['name'] ?? $carrier) : null,
                $tracking !== '' ? $tracking : null,
                $note !== '' ? $note : null,
                date('Y-m-d H:i:s'),
                $orderId,
            ]
        );
        // Set status (always, using the chosen value).
        if ($status !== '') {
            set_order_status($orderId, $status);
        }
        // Notify the customer of the change.
        if (function_exists('notify_order_update')) {
            notify_order_update($orderId, $note);
        }
        flash('success', 'سفارش بروزرسانی شد و به مشتری اطلاع داده شد.');
    } catch (Exception $e) {
        flash('error', 'خطا در بروزرسانی: ' . $e->getMessage());
    }
    $qs = [];
    if (($_GET['filter'] ?? '') !== '') { $qs[] = 'filter=' . urlencode((string) $_GET['filter']); }
    if (($_GET['q'] ?? '') !== '')      { $qs[] = 'q=' . urlencode((string) $_GET['q']); }
    header('Location: orders.php' . ($qs ? '?' . implode('&', $qs) : ''));
    exit;
}

// --------------------------------------------------------------- LOAD -------
$filter = (string) ($_GET['filter'] ?? '');
$q      = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where  = "WHERE product_id IS NOT NULL AND order_status IS NOT NULL";
if ($filter !== '' && isset($statuses[$filter])) {
    $where .= " AND order_status = ?";
    $params[] = $filter;
}
if ($q !== '') {
    // Simple search across order id / user id / tracking code.
    $where .= " AND (id_order LIKE ? OR id_user LIKE ? OR tracking_code LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$orders = db_fetchAll(
    $pdo,
    "SELECT * FROM Payment_report $where ORDER BY id DESC LIMIT 300",
    $params
);

// Status counts for the filter chips.
$counts = ['all' => 0];
foreach (array_keys($statuses) as $s) {
    $counts[$s] = 0;
}
try {
    $rows = db_fetchAll(
        $pdo,
        "SELECT order_status, COUNT(*) AS c FROM Payment_report
         WHERE product_id IS NOT NULL AND order_status IS NOT NULL
         GROUP BY order_status"
    );
    foreach ($rows as $r) {
        $counts['all'] += (int) $r['c'];
        $counts[$r['order_status']] = (int) $r['c'];
    }
} catch (Exception $e) {
    // ignore
}

$flashOk  = get_flash('success');
$flashErr = get_flash('error');

$pageTitle    = 'سفارش‌ها';
$activeNav    = 'orders';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px" class="fade-up">
    <div>
        <div style="font-size:1rem;font-weight:800;color:var(--text)">سفارش‌های فروشگاه</div>
        <div style="font-size:.8rem;color:var(--mute)">تغییر وضعیت، ثبت کد رهگیری پستی/تیپاکس و اطلاع‌رسانی خودکار به مشتری</div>
    </div>
</div>

<?php if ($flashOk): ?>
    <div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<form method="GET" action="orders.php" style="margin-bottom:12px" class="fade-up d1">
    <?php if ($filter !== ''): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
    <div style="display:flex;gap:8px;max-width:520px">
        <input type="text" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="جستجو: کد سفارش، شناسهٔ کاربر یا کد رهگیری">
        <button type="submit" class="btn btn-primary btn-sm"><?= icon('search', 14) ?> جستجو</button>
        <?php if ($q !== ''): ?><a href="orders.php<?= $filter !== '' ? '?filter=' . urlencode($filter) : '' ?>" class="btn btn-ghost btn-sm">پاک کردن</a><?php endif; ?>
    </div>
</form>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px" class="fade-up d1">
    <?php $qp = $q !== '' ? '&q=' . urlencode($q) : ''; ?>
    <a href="orders.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>" class="btn btn-sm <?= $filter === '' ? 'btn-primary' : 'btn-ghost' ?>">همه (<?= (int) $counts['all'] ?>)</a>
    <?php foreach ($statuses as $sv => $sl): ?>
        <a href="orders.php?filter=<?= urlencode($sv) . $qp ?>" class="btn btn-sm <?= $filter === $sv ? 'btn-primary' : 'btn-ghost' ?>">
            <?= htmlspecialchars($sl) ?> (<?= (int) ($counts[$sv] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($orders)): ?>
    <div class="card fade-up d2"><div class="card-body" style="text-align:center;color:var(--mute);padding:32px">سفارشی برای نمایش وجود ندارد.</div></div>
<?php else: foreach ($orders as $o):
    $pname = '';
    if (!empty($o['product_id'])) {
        $p = db_fetch($pdo, "SELECT name_product FROM product WHERE id = ?", [(int) $o['product_id']]);
        $pname = $p['name_product'] ?? ('#' . $o['product_id']);
    }
    $st = $o['order_status'] ?? '';
    // Linked shipping address (physical orders).
    $addr = null;
    if (!empty($o['address_id'])) {
        $addr = db_fetch($pdo, "SELECT * FROM customer_address WHERE id = ?", [(int) $o['address_id']]);
    }
?>
    <div class="card fade-up" style="margin-bottom:14px">
        <div class="card-head">
            <div>
                <div class="card-title" style="font-family:var(--mono);font-size:.9rem"><?= htmlspecialchars((string) $o['id_order']) ?></div>
                <div class="card-subtitle">
                    <?= htmlspecialchars((string) $pname) ?> ·
                    <?= number_format((int) $o['price']) ?> <?= htmlspecialchars($cur) ?> ·
                    کاربر <code><?= htmlspecialchars((string) $o['id_user']) ?></code> ·
                    <?= htmlspecialchars((string) ($o['time'] ?? '')) ?>
                </div>
            </div>
            <div>
                <span class="tag tag-info"><?= htmlspecialchars(order_status_label($st)) ?></span>
            </div>
        </div>
        <div class="card-body">
            <?php if ($addr): ?>
                <div class="notice" style="margin-bottom:12px;line-height:1.9">
                    <strong>📦 آدرس گیرنده</strong><br>
                    <?php if (!empty($addr['full_name'])): ?>👤 <?= htmlspecialchars((string) $addr['full_name']) ?><br><?php endif; ?>
                    <?php if (!empty($addr['phone'])): ?>📱 <?= htmlspecialchars((string) $addr['phone']) ?><br><?php endif; ?>
                    📍 <?= htmlspecialchars(trim(((string) ($addr['province'] ?? '')) . ' ' . ((string) ($addr['city'] ?? '')))) ?><br>
                    <?= htmlspecialchars((string) ($addr['address'] ?? '')) ?><br>
                    <?php if (!empty($addr['postal_code'])): ?>🏷 کدپستی: <span style="font-family:var(--mono)"><?= htmlspecialchars((string) $addr['postal_code']) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php
                $formQs = [];
                if ($filter !== '') { $formQs[] = 'filter=' . urlencode($filter); }
                if ($q !== '')      { $formQs[] = 'q=' . urlencode($q); }
                $formAction = 'orders.php' . ($formQs ? '?' . implode('&', $formQs) : '');
            ?>
            <form method="POST" action="<?= htmlspecialchars($formAction) ?>">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_order">
                <input type="hidden" name="order_id" value="<?= htmlspecialchars((string) $o['id_order']) ?>">
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <div class="field" style="flex:1;min-width:160px">
                        <label>وضعیت سفارش</label>
                        <select name="order_status" class="select">
                            <?php foreach ($statuses as $sv => $sl): ?>
                                <option value="<?= htmlspecialchars($sv) ?>" <?= $st === $sv ? 'selected' : '' ?>><?= htmlspecialchars($sl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="flex:1;min-width:160px">
                        <label>شرکت حمل</label>
                        <select name="shipping_carrier" class="select">
                            <option value="">— انتخاب نشده —</option>
                            <?php foreach ($carriers as $cv => $cinfo): ?>
                                <option value="<?= htmlspecialchars($cv) ?>" <?= ($o['shipping_carrier'] ?? '') === $cv ? 'selected' : '' ?>><?= htmlspecialchars($cinfo['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="flex:1;min-width:160px">
                        <label>کد رهگیری</label>
                        <input type="text" name="tracking_code" class="input" style="font-family:var(--mono)"
                            value="<?= htmlspecialchars((string) ($o['tracking_code'] ?? '')) ?>" placeholder="مثلا 123456789012345">
                    </div>
                </div>
                <div class="field full">
                    <label>یادداشت برای مشتری (اختیاری)</label>
                    <input type="text" name="admin_note" class="input"
                        value="<?= htmlspecialchars((string) ($o['admin_note'] ?? '')) ?>" placeholder="مثلا: امروز ارسال شد، تا ۳ روز کاری به دستتان می‌رسد">
                </div>
                <?php
                $url = function_exists('carrier_tracking_url')
                    ? carrier_tracking_url($o['shipping_carrier'] ?? '', $o['tracking_code'] ?? '')
                    : '';
                if ($url !== ''): ?>
                    <div class="field-hint" style="margin-bottom:8px">🔗 لینک پیگیری: <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($url) ?></a></div>
                <?php endif; ?>
                <div style="margin-top:8px">
                    <button type="submit" class="btn btn-primary btn-sm"><?= icon('check', 14) ?> ذخیره و اطلاع به مشتری</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; endif; ?>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
