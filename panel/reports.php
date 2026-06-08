<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Sales & Discount report (Step 14).
 *
 * A read-only analytics dashboard for the generic shop:
 *   - Sales: total revenue, order count, average order value, breakdown by
 *     order_status, top products, daily trend (last 14 days).
 *   - Discounts: coupon uses, total discount amount given, top codes & users.
 *
 * Scope: only shop rows (Payment_report.product_id IS NOT NULL) are counted, so
 * the VPN flow (which never sets product_id) is completely unaffected. All
 * queries are read-only and parameterized (PDO bind).
 */

$cur      = function_exists('store_currency') ? store_currency() : 'تومان';
$statuses = function_exists('order_statuses') ? order_statuses() : [];

// ---------------------------------------------------- DATE RANGE ------------
// Supported ranges. We compare against the shop's string time column
// (Y-m-d H:i:s, written by shop_record_order), so a string lower-bound works.
$range = (string) ($_GET['range'] ?? 'month');
$rangeLabels = [
    'today' => 'امروز',
    'week'  => '۷ روز اخیر',
    'month' => '۳۰ روز اخیر',
    'all'   => 'کل زمان‌ها',
];
if (!isset($rangeLabels[$range])) {
    $range = 'month';
}
$since = null; // string lower bound 'Y-m-d H:i:s' or null for "all"
switch ($range) {
    case 'today':
        $since = date('Y-m-d 00:00:00');
        break;
    case 'week':
        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        break;
    case 'month':
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        break;
    case 'all':
    default:
        $since = null;
        break;
}

// Shared WHERE for shop rows. The time column on shop rows is a Y-m-d H:i:s
// string, so the comparison is lexicographic-safe for the same format.
$baseWhere = "product_id IS NOT NULL AND order_status IS NOT NULL";
$timeWhere = $baseWhere;
$timeParams = [];
if ($since !== null) {
    $timeWhere .= " AND `time` >= ?";
    $timeParams[] = $since;
}

// Only count "real" sales (paid-ish) toward revenue. Canceled/refunded excluded.
$revenueStatuses = ['paid', 'processing', 'shipped', 'delivered'];
$inPlaceholders  = implode(',', array_fill(0, count($revenueStatuses), '?'));

// ---------------------------------------------------- SALES KPIs ------------
$totalRevenue = 0;
$orderCount   = 0;
$avgOrder     = 0;
$itemsSold    = 0;
try {
    $row = db_fetch(
        $pdo,
        "SELECT COUNT(*) AS c,
                COALESCE(SUM(CAST(price AS DECIMAL(20,0))), 0) AS revenue,
                COALESCE(SUM(CAST(quantity AS UNSIGNED)), 0) AS items
         FROM Payment_report
         WHERE $timeWhere AND order_status IN ($inPlaceholders)",
        array_merge($timeParams, $revenueStatuses)
    );
    $orderCount   = (int) ($row['c'] ?? 0);
    $totalRevenue = (int) ($row['revenue'] ?? 0);
    $itemsSold    = (int) ($row['items'] ?? 0);
    $avgOrder     = $orderCount > 0 ? (int) round($totalRevenue / $orderCount) : 0;
} catch (Exception $e) {
    // ignore — KPIs stay 0
}

// ---------------------------------------------------- BY STATUS -------------
$byStatus = [];
try {
    $rows = db_fetchAll(
        $pdo,
        "SELECT order_status, COUNT(*) AS c,
                COALESCE(SUM(CAST(price AS DECIMAL(20,0))), 0) AS revenue
         FROM Payment_report
         WHERE $timeWhere
         GROUP BY order_status",
        $timeParams
    );
    foreach ($rows as $r) {
        $byStatus[(string) $r['order_status']] = [
            'count'   => (int) $r['c'],
            'revenue' => (int) $r['revenue'],
        ];
    }
} catch (Exception $e) {
    // ignore
}

// ---------------------------------------------------- TOP PRODUCTS ----------
$topProducts = [];
try {
    $rows = db_fetchAll(
        $pdo,
        "SELECT pr.product_id,
                COUNT(*) AS orders,
                COALESCE(SUM(CAST(pr.quantity AS UNSIGNED)), 0) AS qty,
                COALESCE(SUM(CAST(pr.price AS DECIMAL(20,0))), 0) AS revenue
         FROM Payment_report pr
         WHERE $timeWhere AND order_status IN ($inPlaceholders)
         GROUP BY pr.product_id
         ORDER BY revenue DESC
         LIMIT 10",
        array_merge($timeParams, $revenueStatuses)
    );
    foreach ($rows as $r) {
        $name = '#' . (int) $r['product_id'];
        $p = db_fetch($pdo, "SELECT name_product FROM product WHERE id = ?", [(int) $r['product_id']]);
        if ($p && !empty($p['name_product'])) {
            $name = (string) $p['name_product'];
        }
        $topProducts[] = [
            'name'    => $name,
            'orders'  => (int) $r['orders'],
            'qty'     => (int) $r['qty'],
            'revenue' => (int) $r['revenue'],
        ];
    }
} catch (Exception $e) {
    // ignore
}

// ---------------------------------------------------- DAILY TREND -----------
// Last 14 calendar days (independent of the range filter so the trend chart is
// always meaningful). Buckets keyed by Y-m-d.
$trend = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trend[$d] = 0;
}
try {
    $rows = db_fetchAll(
        $pdo,
        "SELECT SUBSTRING(`time`, 1, 10) AS d,
                COALESCE(SUM(CAST(price AS DECIMAL(20,0))), 0) AS revenue
         FROM Payment_report
         WHERE $baseWhere AND order_status IN ($inPlaceholders)
               AND `time` >= ?
         GROUP BY SUBSTRING(`time`, 1, 10)",
        array_merge($revenueStatuses, [date('Y-m-d 00:00:00', strtotime('-13 days'))])
    );
    foreach ($rows as $r) {
        $d = (string) $r['d'];
        if (isset($trend[$d])) {
            $trend[$d] = (int) $r['revenue'];
        }
    }
} catch (Exception $e) {
    // ignore
}
$trendMax = max(1, max($trend));

// ---------------------------------------------------- DISCOUNT REPORT -------
$discountUses   = 0;
$discountAmount = 0;
$topCodes       = [];
$topDiscUsers   = [];
$duWhere  = "1=1";
$duParams = [];
if ($since !== null) {
    $duWhere   .= " AND used_at >= ?";
    $duParams[] = $since;
}
try {
    $row = db_fetch(
        $pdo,
        "SELECT COUNT(*) AS c, COALESCE(SUM(CAST(amount AS DECIMAL(20,0))), 0) AS amt
         FROM discount_usage WHERE $duWhere",
        $duParams
    );
    $discountUses   = (int) ($row['c'] ?? 0);
    $discountAmount = (int) ($row['amt'] ?? 0);

    $topCodes = db_fetchAll(
        $pdo,
        "SELECT code, COUNT(*) AS uses, COALESCE(SUM(CAST(amount AS DECIMAL(20,0))), 0) AS amt
         FROM discount_usage WHERE $duWhere
         GROUP BY code ORDER BY amt DESC LIMIT 10",
        $duParams
    );

    $topDiscUsers = db_fetchAll(
        $pdo,
        "SELECT user_id, COUNT(*) AS uses, COALESCE(SUM(CAST(amount AS DECIMAL(20,0))), 0) AS amt
         FROM discount_usage WHERE $duWhere AND user_id IS NOT NULL AND user_id <> ''
         GROUP BY user_id ORDER BY amt DESC LIMIT 10",
        $duParams
    );
} catch (Exception $e) {
    // discount_usage may not exist on legacy installs — section stays empty.
}

function reports_fmt_money($n, $cur)
{
    return number_format((int) $n) . ' ' . $cur;
}

$pageTitle    = 'گزارش فروش';
$activeNav    = 'reports';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px" class="fade-up">
    <div>
        <div style="font-size:1rem;font-weight:800;color:var(--text)">گزارش فروش و تخفیف</div>
        <div style="font-size:.8rem;color:var(--mute)">آمار فروش فروشگاه (سفارش‌های VPN در این گزارش لحاظ نمی‌شوند)</div>
    </div>
</div>

<!-- Range filter -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px" class="fade-up d1">
    <?php foreach ($rangeLabels as $rv => $rl): ?>
        <a href="reports.php?range=<?= urlencode($rv) ?>"
           class="btn btn-sm <?= $range === $rv ? 'btn-primary' : 'btn-ghost' ?>">
            <?= htmlspecialchars($rl) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Sales KPIs -->
<div class="stats fade-up d1">
    <div class="stat ok">
        <div class="stat-label">مجموع فروش (<?= htmlspecialchars($rangeLabels[$range]) ?>)</div>
        <div class="stat-num">
            <?= $totalRevenue >= 1000000
                ? number_format($totalRevenue / 1000000, 1) . 'M'
                : number_format($totalRevenue) ?>
        </div>
        <div class="stat-meta"><?= htmlspecialchars($cur) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">تعداد سفارش</div>
        <div class="stat-num"><?= number_format($orderCount) ?></div>
        <div class="stat-meta"><?= number_format($itemsSold) ?> قلم کالا</div>
    </div>
    <div class="stat warn">
        <div class="stat-label">میانگین ارزش سفارش</div>
        <div class="stat-num"><?= number_format($avgOrder) ?></div>
        <div class="stat-meta"><?= htmlspecialchars($cur) ?></div>
    </div>
    <div class="stat no">
        <div class="stat-label">تخفیف داده‌شده</div>
        <div class="stat-num"><?= number_format($discountAmount) ?></div>
        <div class="stat-meta"><?= number_format($discountUses) ?> بار استفاده</div>
    </div>
</div>

<!-- Daily trend (last 14 days) -->
<div class="card fade-up d2" style="margin-bottom:16px">
    <div class="card-head">
        <div class="card-title"><?= icon('chart', 16) ?> روند فروش (۱۴ روز اخیر)</div>
    </div>
    <div class="card-body">
        <div style="display:flex;align-items:flex-end;gap:6px;height:140px">
            <?php foreach ($trend as $d => $v):
                $h = (int) round(($v / $trendMax) * 120);
                if ($v > 0 && $h < 4) { $h = 4; }
            ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0"
                     title="<?= htmlspecialchars($d) ?>: <?= reports_fmt_money($v, $cur) ?>">
                    <div style="width:100%;background:var(--brand,#4f8cff);border-radius:4px 4px 0 0;height:<?= $h ?>px"></div>
                    <div style="font-size:.6rem;color:var(--mute);white-space:nowrap"><?= htmlspecialchars(substr($d, 5)) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Breakdown by status -->
<div class="card fade-up d2" style="margin-bottom:16px">
    <div class="card-head"><div class="card-title">📊 فروش بر اساس وضعیت سفارش</div></div>
    <div class="card-body" style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead>
                <tr style="text-align:right;color:var(--mute);border-bottom:1px solid var(--border)">
                    <th style="padding:8px">وضعیت</th>
                    <th style="padding:8px">تعداد</th>
                    <th style="padding:8px">مبلغ</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($byStatus)): ?>
                <tr><td colspan="3" style="padding:16px;text-align:center;color:var(--mute)">داده‌ای برای این بازه نیست.</td></tr>
            <?php else: foreach ($statuses as $sv => $sl):
                if (!isset($byStatus[$sv])) { continue; }
                $d = $byStatus[$sv];
            ?>
                <tr style="border-bottom:1px solid var(--border)">
                    <td style="padding:8px"><?= htmlspecialchars($sl) ?></td>
                    <td style="padding:8px"><?= number_format($d['count']) ?></td>
                    <td style="padding:8px"><?= reports_fmt_money($d['revenue'], $cur) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Top products -->
<div class="card fade-up d2" style="margin-bottom:16px">
    <div class="card-head"><div class="card-title">🏆 پرفروش‌ترین محصولات</div></div>
    <div class="card-body" style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead>
                <tr style="text-align:right;color:var(--mute);border-bottom:1px solid var(--border)">
                    <th style="padding:8px">#</th>
                    <th style="padding:8px">محصول</th>
                    <th style="padding:8px">سفارش</th>
                    <th style="padding:8px">تعداد</th>
                    <th style="padding:8px">درآمد</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($topProducts)): ?>
                <tr><td colspan="5" style="padding:16px;text-align:center;color:var(--mute)">فروشی برای این بازه ثبت نشده.</td></tr>
            <?php else: foreach ($topProducts as $i => $tp): ?>
                <tr style="border-bottom:1px solid var(--border)">
                    <td style="padding:8px"><?= $i + 1 ?></td>
                    <td style="padding:8px"><?= htmlspecialchars($tp['name']) ?></td>
                    <td style="padding:8px"><?= number_format($tp['orders']) ?></td>
                    <td style="padding:8px"><?= number_format($tp['qty']) ?></td>
                    <td style="padding:8px"><?= reports_fmt_money($tp['revenue'], $cur) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Discount report -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px" class="fade-up d2">
    <div class="card">
        <div class="card-head"><div class="card-title">🏷 پرکاربردترین کدهای تخفیف</div></div>
        <div class="card-body" style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead>
                    <tr style="text-align:right;color:var(--mute);border-bottom:1px solid var(--border)">
                        <th style="padding:8px">کد</th>
                        <th style="padding:8px">دفعات</th>
                        <th style="padding:8px">مبلغ تخفیف</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($topCodes)): ?>
                    <tr><td colspan="3" style="padding:16px;text-align:center;color:var(--mute)">کد تخفیفی استفاده نشده.</td></tr>
                <?php else: foreach ($topCodes as $c): ?>
                    <tr style="border-bottom:1px solid var(--border)">
                        <td style="padding:8px;font-family:var(--mono)"><?= htmlspecialchars((string) $c['code']) ?></td>
                        <td style="padding:8px"><?= number_format((int) $c['uses']) ?></td>
                        <td style="padding:8px"><?= reports_fmt_money($c['amt'], $cur) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="card-title">👤 کاربران با بیشترین تخفیف</div></div>
        <div class="card-body" style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead>
                    <tr style="text-align:right;color:var(--mute);border-bottom:1px solid var(--border)">
                        <th style="padding:8px">کاربر</th>
                        <th style="padding:8px">دفعات</th>
                        <th style="padding:8px">مبلغ تخفیف</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($topDiscUsers)): ?>
                    <tr><td colspan="3" style="padding:16px;text-align:center;color:var(--mute)">داده‌ای نیست.</td></tr>
                <?php else: foreach ($topDiscUsers as $u): ?>
                    <tr style="border-bottom:1px solid var(--border)">
                        <td style="padding:8px;font-family:var(--mono)"><code><?= htmlspecialchars((string) $u['user_id']) ?></code></td>
                        <td style="padding:8px"><?= number_format((int) $u['uses']) ?></td>
                        <td style="padding:8px"><?= reports_fmt_money($u['amt'], $cur) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
