<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Shop discount / coupon manager (Step 8).
 * Reuses the existing DiscountSell table (shared with the VPN discount flow)
 * but exposes the e-commerce extensions added in Step 8:
 *   discount_kind  percent | fixed
 *   price          discount value (percent number OR fixed amount)
 *   max_amount     cap for percent discounts (0 = no cap)
 *   min_order      minimum cart amount required (0 = none)
 *   limitDiscount  global usage cap (0 = unlimited)
 *   useuser        per-user cap (0 = unlimited)
 *   usedDiscount   how many times already used
 *   time           expiry unix ts (0 = never)
 *   start_at       optional start unix ts
 *   enabled        '1' active / '0' disabled
 *
 * Single-code create, batch generate, enable/disable toggle, delete and a
 * usage summary from discount_usage. The panel works on the local bot DB, so
 * codes are created with bot_id = 0 (valid for every bot of this instance),
 * matching validate_discount_code()'s `bot_id = ? OR bot_id = 0` rule.
 */

$cur = function_exists('store_currency') ? store_currency() : 'تومان';

/* Convert a Jalali/Gregorian-ish "YYYY-MM-DD HH:MM" or empty to a unix ts. */
function disc_parse_ts($v)
{
    $v = trim((string) $v);
    if ($v === '') {
        return 0;
    }
    $ts = strtotime($v);
    return $ts ? $ts : 0;
}

// ---------------------------------------------------------------- DELETE ----
if (isset($_GET['delete'])) {
    csrf_check_get();
    $id = (int) $_GET['delete'];
    try {
        db_query($pdo, "DELETE FROM DiscountSell WHERE id = ?", [$id]);
        flash('success', 'کد تخفیف حذف شد.');
    } catch (Exception $e) {
        flash('error', 'خطا در حذف: ' . $e->getMessage());
    }
    header('Location: discounts.php');
    exit;
}

// ------------------------------------------------------- TOGGLE ENABLED -----
if (isset($_GET['toggle'])) {
    csrf_check_get();
    $id = (int) $_GET['toggle'];
    try {
        $row = db_fetch($pdo, "SELECT enabled FROM DiscountSell WHERE id = ?", [$id]);
        if ($row) {
            $new = ((string) ($row['enabled'] ?? '1') === '0') ? '1' : '0';
            db_query($pdo, "UPDATE DiscountSell SET enabled = ? WHERE id = ?", [$new, $id]);
            flash('success', $new === '1' ? 'کد فعال شد.' : 'کد غیرفعال شد.');
        }
    } catch (Exception $e) {
        flash('error', 'خطا: ' . $e->getMessage());
    }
    header('Location: discounts.php');
    exit;
}

// ---------------------------------------------------- CREATE SINGLE CODE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_single') {
    csrf_check_post();
    $code = trim($_POST['code'] ?? '');
    $code = preg_replace('/\s+/', '', $code);
    if ($code === '') {
        flash('error', 'کد تخفیف را وارد کنید.');
        header('Location: discounts.php');
        exit;
    }
    if (db_count($pdo, "SELECT COUNT(*) FROM DiscountSell WHERE codeDiscount = ?", [$code])) {
        flash('error', 'این کد قبلاً ثبت شده است.');
        header('Location: discounts.php');
        exit;
    }
    $kind     = ($_POST['discount_kind'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    $price    = (int) ($_POST['price'] ?? 0);
    $maxAmt   = (int) ($_POST['max_amount'] ?? 0);
    $minOrder = (int) ($_POST['min_order'] ?? 0);
    $limit    = (int) ($_POST['limitDiscount'] ?? 0);
    $perUser  = (int) ($_POST['useuser'] ?? 0);
    $expire   = disc_parse_ts($_POST['expire_at'] ?? '');
    $start    = disc_parse_ts($_POST['start_at'] ?? '');
    if ($kind === 'percent' && ($price < 1 || $price > 100)) {
        flash('error', 'درصد تخفیف باید بین ۱ تا ۱۰۰ باشد.');
        header('Location: discounts.php');
        exit;
    }
    if ($kind === 'fixed' && $price < 1) {
        flash('error', 'مبلغ تخفیف باید بزرگتر از صفر باشد.');
        header('Location: discounts.php');
        exit;
    }
    try {
        db_query(
            $pdo,
            "INSERT INTO DiscountSell
             (codeDiscount, price, limitDiscount, agent, usefirst, useuser, code_product,
              code_panel, time, type, usedDiscount, discount_kind, max_amount, min_order,
              start_at, enabled, bot_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $code,
                (string) $price,
                (string) $limit,
                'allusers',
                '0',
                (string) $perUser,
                'all',
                '/all',
                (string) $expire,
                'all',
                '0',
                $kind,
                (string) $maxAmt,
                (string) $minOrder,
                $start ? (string) $start : null,
                '1',
                0,
            ]
        );
        flash('success', 'کد تخفیف «' . $code . '» ساخته شد.');
    } catch (Exception $e) {
        flash('error', 'خطا در ساخت کد: ' . $e->getMessage());
    }
    header('Location: discounts.php');
    exit;
}

// ----------------------------------------------------- BATCH GENERATE -------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    csrf_check_post();
    $count    = (int) ($_POST['count'] ?? 0);
    $kind     = ($_POST['discount_kind'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    $price    = (int) ($_POST['price'] ?? 0);
    if ($count < 1) {
        flash('error', 'تعداد کدها باید حداقل ۱ باشد.');
        header('Location: discounts.php');
        exit;
    }
    if ($kind === 'percent' && ($price < 1 || $price > 100)) {
        flash('error', 'درصد تخفیف باید بین ۱ تا ۱۰۰ باشد.');
        header('Location: discounts.php');
        exit;
    }
    if ($kind === 'fixed' && $price < 1) {
        flash('error', 'مبلغ تخفیف باید بزرگتر از صفر باشد.');
        header('Location: discounts.php');
        exit;
    }
    $res = generate_discount_codes($count, [
        'price'         => $price,
        'discount_kind' => $kind,
        'limitDiscount' => (int) ($_POST['limitDiscount'] ?? 1),
        'useuser'       => (int) ($_POST['useuser'] ?? 1),
        'max_amount'    => (int) ($_POST['max_amount'] ?? 0),
        'min_order'     => (int) ($_POST['min_order'] ?? 0),
        'time'          => disc_parse_ts($_POST['expire_at'] ?? ''),
        'start_at'      => disc_parse_ts($_POST['start_at'] ?? '') ?: '',
        'prefix'        => $_POST['prefix'] ?? '',
        'length'        => (int) ($_POST['length'] ?? 6),
        'bot_id'        => 0,
    ]);
    if ($res['created'] > 0) {
        flash('success', $res['created'] . ' کد تخفیف ساخته شد.');
    } else {
        flash('error', 'هیچ کدی ساخته نشد.');
    }
    header('Location: discounts.php');
    exit;
}

// --------------------------------------------------------------- LOAD -------
$codes = db_fetchAll(
    $pdo,
    "SELECT * FROM DiscountSell ORDER BY id DESC LIMIT 500"
);

// Usage summary from discount_usage (count + total amount).
$usage = ['count' => 0, 'sum' => 0];
try {
    $u = db_fetch($pdo, "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM discount_usage");
    if ($u) {
        $usage['count'] = (int) $u['c'];
        $usage['sum']   = (int) $u['s'];
    }
} catch (Exception $e) {
    // discount_usage may be empty/new; ignore.
}

$activeCount = 0;
foreach ($codes as $c) {
    if ((string) ($c['enabled'] ?? '1') !== '0') {
        $activeCount++;
    }
}

$flashOk  = get_flash('success');
$flashErr = get_flash('error');

$pageTitle    = 'کدهای تخفیف';
$activeNav    = 'discounts';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px" class="fade-up">
    <div>
        <div style="font-size:1rem;font-weight:800;color:var(--text)">کدهای تخفیف فروشگاه</div>
        <div style="font-size:.8rem;color:var(--mute)">ساخت تک‌کد یا تولید دسته‌ای، درصدی یا مبلغ ثابت، با سقف و محدودیت استفاده</div>
    </div>
</div>

<?php if ($flashOk): ?>
    <div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
    <div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<div class="stats" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat">
        <div class="stat-label">کل کدها</div>
        <div class="stat-num"><?= number_format(count($codes)) ?></div>
    </div>
    <div class="stat ok">
        <div class="stat-label">فعال</div>
        <div class="stat-num"><?= number_format($activeCount) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">دفعات استفاده</div>
        <div class="stat-num"><?= number_format($usage['count']) ?></div>
    </div>
    <div class="stat warn">
        <div class="stat-label">مجموع تخفیف داده‌شده</div>
        <div class="stat-num" style="font-size:1.1rem"><?= number_format($usage['sum']) ?> <?= htmlspecialchars($cur) ?></div>
    </div>
</div>

<div class="form-grid" style="grid-template-columns:1fr 1fr;gap:16px;align-items:start">
    <!-- Single code -->
    <div class="card fade-up d1">
        <div class="card-head">
            <div>
                <div class="card-title">ساخت کد تکی</div>
                <div class="card-subtitle">یک کد دلخواه با تنظیمات اختصاصی</div>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" action="discounts.php">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="create_single">
                <div class="field">
                    <label>کد تخفیف</label>
                    <input type="text" name="code" class="input" placeholder="مثال: OFF20" required style="font-family:var(--mono)">
                </div>
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>نوع</label>
                        <select name="discount_kind" class="select">
                            <option value="percent">درصدی (٪)</option>
                            <option value="fixed">مبلغ ثابت</option>
                        </select>
                    </div>
                    <div class="field" style="flex:1">
                        <label>مقدار</label>
                        <input type="number" name="price" class="input" min="1" placeholder="مثلا 20" required>
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>سقف تخفیف (فقط درصدی)</label>
                        <input type="number" name="max_amount" class="input" min="0" value="0">
                        <div class="field-hint">۰ = بدون سقف</div>
                    </div>
                    <div class="field" style="flex:1">
                        <label>حداقل مبلغ سفارش</label>
                        <input type="number" name="min_order" class="input" min="0" value="0">
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>سقف کل استفاده</label>
                        <input type="number" name="limitDiscount" class="input" min="0" value="0">
                        <div class="field-hint">۰ = نامحدود</div>
                    </div>
                    <div class="field" style="flex:1">
                        <label>سقف هر کاربر</label>
                        <input type="number" name="useuser" class="input" min="0" value="1">
                        <div class="field-hint">۰ = نامحدود</div>
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>شروع (اختیاری)</label>
                        <input type="text" name="start_at" class="input" placeholder="2026-06-10 00:00">
                    </div>
                    <div class="field" style="flex:1">
                        <label>انقضا (اختیاری)</label>
                        <input type="text" name="expire_at" class="input" placeholder="2026-07-01 23:59">
                    </div>
                </div>
                <div style="margin-top:12px">
                    <button type="submit" class="btn btn-primary"><?= icon('plus', 14) ?> ساخت کد</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Batch generate -->
    <div class="card fade-up d2">
        <div class="card-head">
            <div>
                <div class="card-title">تولید دسته‌ای</div>
                <div class="card-subtitle">چند کد یکتا با تنظیمات یکسان (حداکثر ۱۰۰۰)</div>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" action="discounts.php">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="generate">
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>تعداد</label>
                        <input type="number" name="count" class="input" min="1" max="1000" value="10" required>
                    </div>
                    <div class="field" style="flex:1">
                        <label>پیشوند (اختیاری)</label>
                        <input type="text" name="prefix" class="input" placeholder="EID" style="font-family:var(--mono)">
                    </div>
                    <div class="field" style="flex:1">
                        <label>طول کد</label>
                        <input type="number" name="length" class="input" min="4" max="16" value="6">
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>نوع</label>
                        <select name="discount_kind" class="select">
                            <option value="percent">درصدی (٪)</option>
                            <option value="fixed">مبلغ ثابت</option>
                        </select>
                    </div>
                    <div class="field" style="flex:1">
                        <label>مقدار</label>
                        <input type="number" name="price" class="input" min="1" placeholder="مثلا 15" required>
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>سقف تخفیف</label>
                        <input type="number" name="max_amount" class="input" min="0" value="0">
                    </div>
                    <div class="field" style="flex:1">
                        <label>حداقل سفارش</label>
                        <input type="number" name="min_order" class="input" min="0" value="0">
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>سقف کل هر کد</label>
                        <input type="number" name="limitDiscount" class="input" min="0" value="1">
                    </div>
                    <div class="field" style="flex:1">
                        <label>سقف هر کاربر</label>
                        <input type="number" name="useuser" class="input" min="0" value="1">
                    </div>
                </div>
                <div style="display:flex;gap:10px">
                    <div class="field" style="flex:1">
                        <label>شروع (اختیاری)</label>
                        <input type="text" name="start_at" class="input" placeholder="2026-06-10 00:00">
                    </div>
                    <div class="field" style="flex:1">
                        <label>انقضا (اختیاری)</label>
                        <input type="text" name="expire_at" class="input" placeholder="2026-07-01 23:59">
                    </div>
                </div>
                <div style="margin-top:12px">
                    <button type="submit" class="btn btn-primary"><?= icon('plus', 14) ?> تولید کدها</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card fade-up d3" style="margin-top:16px">
    <div class="card-head">
        <div class="card-title">فهرست کدها <small style="color:var(--dim);font-weight:400">(<?= count($codes) ?>)</small></div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>کد</th>
                    <th>نوع</th>
                    <th>مقدار</th>
                    <th>استفاده</th>
                    <th>حداقل سفارش</th>
                    <th>انقضا</th>
                    <th>وضعیت</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($codes)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--mute);padding:24px">هنوز کد تخفیفی ساخته نشده است.</td></tr>
                <?php else: foreach ($codes as $c):
                    $kind   = $c['discount_kind'] ?? 'percent';
                    $isOn   = (string) ($c['enabled'] ?? '1') !== '0';
                    $limit  = (int) ($c['limitDiscount'] ?? 0);
                    $used   = (int) ($c['usedDiscount'] ?? 0);
                    $expire = (int) ($c['time'] ?? 0);
                    $expired = ($expire !== 0 && time() >= $expire);
                ?>
                    <tr>
                        <td class="cm cs"><?= htmlspecialchars($c['codeDiscount']) ?></td>
                        <td>
                            <?php if ($kind === 'fixed'): ?>
                                <span class="tag tag-info">مبلغ ثابت</span>
                            <?php else: ?>
                                <span class="tag tag-info">درصدی</span>
                            <?php endif; ?>
                        </td>
                        <td class="cf">
                            <?php if ($kind === 'fixed'): ?>
                                <?= number_format((int) $c['price']) ?> <?= htmlspecialchars($cur) ?>
                            <?php else: ?>
                                <?= (int) $c['price'] ?>٪
                                <?php if ((int) ($c['max_amount'] ?? 0) > 0): ?>
                                    <small style="color:var(--dim)">(تا <?= number_format((int) $c['max_amount']) ?>)</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="cf"><?= $used ?><?= $limit > 0 ? ' / ' . $limit : '' ?></td>
                        <td class="cf"><?= (int) ($c['min_order'] ?? 0) > 0 ? number_format((int) $c['min_order']) : '—' ?></td>
                        <td class="cf"><?= $expire ? safe_date($expire) : 'همیشه' ?></td>
                        <td>
                            <?php if ($expired): ?>
                                <span class="tag tag-no">منقضی</span>
                            <?php elseif ($isOn): ?>
                                <span class="tag tag-ok">فعال</span>
                            <?php else: ?>
                                <span class="tag tag-warn">غیرفعال</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:left;white-space:nowrap">
                            <a href="discounts.php?toggle=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>"
                                class="btn btn-ghost btn-sm btn-icon" title="<?= $isOn ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>"><?= icon($isOn ? 'block' : 'check', 13) ?></a>
                            <a href="discounts.php?delete=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>"
                                class="btn btn-no btn-sm btn-icon" title="حذف"
                                data-confirm="این کد تخفیف حذف شود؟"><?= icon('trash', 13) ?></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
