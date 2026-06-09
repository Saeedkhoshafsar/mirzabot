<?php
/**
 * Product preview — shows a faithful demo of how a product appears inside the
 * Telegram bot (media + caption + inline buttons), exactly as a customer sees
 * it. Reuses shop_product_view_data() so the demo always matches the real bot.
 *
 * Opened from the product list "نمایش" (eye) button: product_preview.php?pid=ID
 * Supports ?embed=1 to render inside an iframe/modal (no full page chrome).
 */
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

$pid = (int) ($_GET['pid'] ?? 0);
$embed = isset($_GET['embed']);

$product = null;
if ($pid) {
    $product = db_fetch($pdo, "SELECT * FROM product WHERE id = ?", [$pid]);
}

if (!$product) {
    if ($embed) {
        echo '<div style="padding:20px;font-family:sans-serif;color:#ef4444">محصول یافت نشد.</div>';
        exit;
    }
    flash('error', 'محصول یافت نشد.');
    header('Location: product.php');
    exit;
}

$ptype = $product['product_type'] ?? 'vpn';
$ptypes = function_exists('product_types') ? product_types() : [];
$ptypeLabel = $ptypes[$ptype]['label'] ?? $ptype;

// Build the same view a customer would receive in the bot.
$view = function_exists('shop_product_view_data')
    ? shop_product_view_data($product)
    : ['media' => [], 'caption' => htmlspecialchars($product['name_product'] ?? ''), 'keyboard' => [], 'available' => true];

/**
 * Convert the limited Telegram HTML used in captions (<b>, <i>, <code>, <a>,
 * \n) into safe display HTML. Everything else is escaped first, then the
 * whitelisted tags are restored. Newlines become <br>.
 */
function tg_caption_to_html(string $caption): string
{
    // Caption already contains intentional <b>…</b> from the bot builder; those
    // are produced by our own code (not user free-text beyond htmlspecialchars),
    // so we can keep them. We escape nothing here because the builder already
    // escaped the dynamic parts via htmlspecialchars(); we only turn \n → <br>.
    return nl2br($caption);
}

$captionHtml = tg_caption_to_html($view['caption']);

// Variants (for physical products with تنوع) — show a small selector demo.
$attrs = function_exists('product_attributes') ? product_attributes($product) : [];
$variants = [];
if ($ptype === 'physical' && !empty($attrs['variants']) && is_array($attrs['variants'])) {
    $cols = [];
    if (!empty($attrs['_variant_cols']) && function_exists('variant_columns_with_builtins')
        && function_exists('variant_normalize_columns')) {
        $cols = variant_columns_with_builtins(variant_normalize_columns($attrs['_variant_cols']));
    }
    foreach ($attrs['variants'] as $v) {
        if (!is_array($v)) {
            continue;
        }
        $label = function_exists('product_variant_label') ? trim(product_variant_label($v)) : '';
        if ($label === '') {
            // Build a label from non-reserved columns.
            $parts = [];
            foreach ($v as $ck => $cv) {
                if (in_array($ck, ['stock', 'price_diff', 'image'], true)) {
                    continue;
                }
                if (is_array($cv)) {
                    continue;
                }
                $cv = trim((string) $cv);
                if ($cv !== '') {
                    $parts[] = $cv;
                }
            }
            $label = implode(' / ', $parts);
        }
        $stock = isset($v['stock']) && $v['stock'] !== '' ? (int) $v['stock'] : null;
        $diff  = isset($v['price_diff']) && $v['price_diff'] !== '' ? (int) $v['price_diff'] : 0;
        $variants[] = ['label' => $label ?: 'تنوع', 'stock' => $stock, 'diff' => $diff];
    }
}

// Type-specific extra demo (what the buyer receives AFTER purchase).
$deliveryDemo = '';
switch ($ptype) {
    case 'digital_file':
        $ft = product_attr($product, 'file_type', 'document');
        $cap = (string) product_attr($product, 'caption', '');
        $deliveryDemo = 'پس از خرید، یک فایل از نوع «' . htmlspecialchars($ft) . '» برای مشتری ارسال می‌شود'
            . ($cap !== '' ? ' همراه با توضیح: «' . htmlspecialchars($cap) . '»' : '') . '.';
        break;
    case 'serial_code':
        $fmt = (string) product_attr($product, 'code_format', '');
        $sample = $fmt !== '' ? str_replace('{code}', 'XXXX-XXXX-XXXX', $fmt) : "✅ کد شما:\nXXXX-XXXX-XXXX";
        $deliveryDemo = 'پس از خرید، یک کد از مخزن برای مشتری ارسال می‌شود؛ نمونه:<br><span class="tg-code">'
            . nl2br(htmlspecialchars($sample)) . '</span>';
        break;
    case 'service':
        $note = (string) product_attr($product, 'delivery_note', '');
        $deliveryDemo = $note !== ''
            ? 'پس از خرید این متن نمایش داده می‌شود:<br>«' . nl2br(htmlspecialchars($note)) . '»'
            : 'پس از خرید پیام «خرید شما ثبت شد» نمایش داده می‌شود.';
        break;
    case 'physical':
        $deliveryDemo = 'پس از خرید سفارش ثبت و برای هماهنگی ارسال با مشتری تماس گرفته می‌شود؛ موجودی به‌صورت خودکار کم می‌شود.';
        break;
    case 'vpn':
        $deliveryDemo = 'این محصول از نوع سرویس VPN است و در جریان خرید سرویس ربات نمایش داده می‌شود (نه فروشگاه عمومی).';
        break;
}

if (!$embed) {
    $pageTitle = 'نمایش محصول در ربات';
    $pageLede  = 'پیش‌نمایش زندهٔ آنچه مشتری در ربات تلگرام می‌بیند.';
    $activeNav = 'product';
    include __DIR__ . '/inc/layout_head.php';
}
?>
<?php if ($embed): ?>
  <!DOCTYPE html>
  <html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نمایش محصول</title>
  </head>
  <body style="margin:0">
<?php endif; ?>

<style>
  .pv-wrap { display:flex; flex-wrap:wrap; gap:18px; align-items:flex-start; }
  .pv-phone {
    width: 340px; max-width: 100%; flex: 0 0 auto;
    background: #0e1621; border-radius: 22px; padding: 14px 10px 18px;
    border: 8px solid #1b2733; box-shadow: 0 18px 50px rgba(0,0,0,.45);
    direction: rtl;
  }
  .pv-tg-head {
    display:flex; align-items:center; gap:10px; padding:6px 8px 12px;
    border-bottom:1px solid rgba(255,255,255,.06); margin-bottom:10px;
  }
  .pv-tg-ava { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,#2aabee,#229ed9); display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px; }
  .pv-tg-title { color:#fff; font-size:.9rem; font-weight:700; line-height:1.2; }
  .pv-tg-sub { color:#7d8da0; font-size:.7rem; }
  .pv-chat { display:flex; flex-direction:column; gap:8px; padding:4px 4px 0; max-height:560px; overflow:auto; }
  .pv-bubble {
    background:#182533; color:#e7eef5; border-radius:14px 14px 14px 4px;
    padding:0; max-width:92%; align-self:flex-start; overflow:hidden;
    box-shadow:0 2px 6px rgba(0,0,0,.25);
  }
  .pv-media { width:100%; display:block; }
  .pv-media img, .pv-media video { width:100%; display:block; max-height:230px; object-fit:cover; background:#0b1118; }
  .pv-media-audio { padding:10px 12px; display:flex; align-items:center; gap:8px; color:#bcd; font-size:.8rem; background:#0f1a26; }
  .pv-media-more { padding:4px 12px; font-size:.66rem; color:#7d8da0; }
  .pv-caption { padding:10px 12px; font-size:.84rem; line-height:1.7; word-break:break-word; }
  .pv-caption b { color:#fff; }
  .tg-code { font-family:monospace; background:#0b1118; padding:2px 6px; border-radius:6px; display:inline-block; }
  .pv-kb { display:flex; flex-direction:column; gap:6px; padding:0 6px; margin-top:8px; }
  .pv-kb-row { display:flex; gap:6px; }
  .pv-btn {
    flex:1; text-align:center; background:#212d3b; color:#5eb5f7;
    border:1px solid rgba(94,181,247,.18); border-radius:10px;
    padding:9px 8px; font-size:.78rem; font-weight:600; cursor:default;
    user-select:none;
  }
  .pv-empty-media { padding:14px 12px; color:#7d8da0; font-size:.76rem; background:#0f1a26; text-align:center; }

  .pv-side { flex:1 1 280px; min-width:260px; display:flex; flex-direction:column; gap:14px; }
  .pv-info-card { background:var(--sf2,#0f172a); border:1px solid var(--bd,#1e293b); border-radius:14px; padding:14px 16px; }
  .pv-info-card h4 { margin:0 0 10px; font-size:.9rem; color:var(--text,#e2e8f0); display:flex; align-items:center; gap:8px; }
  .pv-row { display:flex; justify-content:space-between; gap:10px; padding:5px 0; font-size:.82rem; border-bottom:1px dashed var(--bd,#1e293b); }
  .pv-row:last-child { border-bottom:none; }
  .pv-row .k { color:var(--mute,#94a3b8); }
  .pv-row .v { color:var(--text,#e2e8f0); font-weight:600; text-align:left; }
  .pv-var-tbl { width:100%; border-collapse:collapse; font-size:.78rem; }
  .pv-var-tbl th, .pv-var-tbl td { padding:6px 8px; border-bottom:1px solid var(--bd,#1e293b); text-align:right; }
  .pv-var-tbl th { color:var(--mute,#94a3b8); font-weight:600; }
  .pv-note { font-size:.8rem; color:var(--mute,#94a3b8); line-height:1.7; }
  .pv-badge { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.7rem; font-weight:700; }
  .pv-badge.ok { background:rgba(16,185,129,.15); color:#34d399; }
  .pv-badge.no { background:rgba(239,68,68,.15); color:#f87171; }
</style>

<?php if (!$embed): ?>
<div style="margin-bottom:16px" class="fade-up">
  <a href="product.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 13) ?> بازگشت به محصولات</a>
</div>
<?php endif; ?>

<div class="pv-wrap fade-up">
  <!-- Telegram phone mockup -->
  <div class="pv-phone">
    <div class="pv-tg-head">
      <div class="pv-tg-ava">🤖</div>
      <div>
        <div class="pv-tg-title">فروشگاه ربات</div>
        <div class="pv-tg-sub">آنلاین · پیش‌نمایش</div>
      </div>
    </div>
    <div class="pv-chat">
      <?php if (!empty($view['media'])): ?>
        <?php foreach ($view['media'] as $i => $m): ?>
          <div class="pv-bubble">
            <div class="pv-media">
              <?php if ($m['type'] === 'image'): ?>
                <img src="<?= htmlspecialchars($m['url']) ?>" alt="media" onerror="this.parentNode.innerHTML='<div class=\'pv-empty-media\'>تصویر در دسترس نیست (در ربات از file_id ارسال می‌شود)</div>'">
              <?php elseif ($m['type'] === 'video'): ?>
                <video src="<?= htmlspecialchars($m['url']) ?>" controls muted></video>
              <?php else: ?>
                <div class="pv-media-audio">🎵 <span>فایل صوتی محصول</span></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Caption + inline keyboard bubble -->
      <div class="pv-bubble" style="max-width:96%">
        <div class="pv-caption"><?= $captionHtml ?></div>
        <?php if (!empty($view['keyboard'])): ?>
          <div class="pv-kb">
            <?php foreach ($view['keyboard'] as $row): ?>
              <div class="pv-kb-row">
                <?php foreach ($row as $btn): ?>
                  <span class="pv-btn"><?= htmlspecialchars($btn['text'] ?? '') ?></span>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Side info -->
  <div class="pv-side">
    <div class="pv-info-card">
      <h4><?= icon('package', 15) ?> اطلاعات محصول</h4>
      <div class="pv-row"><span class="k">نام</span><span class="v"><?= htmlspecialchars($product['name_product'] ?? '') ?></span></div>
      <div class="pv-row"><span class="k">نوع محصول</span><span class="v"><?= htmlspecialchars($ptypeLabel) ?></span></div>
      <div class="pv-row"><span class="k">قیمت</span><span class="v"><?= number_format((int) ($view['price'] ?? 0)) ?> <?= htmlspecialchars($view['currency'] ?? '') ?></span></div>
      <div class="pv-row"><span class="k">کد محصول</span><span class="v" style="font-family:monospace"><?= htmlspecialchars($product['code_product'] ?? '') ?></span></div>
      <?php if (function_exists('product_tracks_stock') && product_tracks_stock($product)): $st = product_stock($product); ?>
        <div class="pv-row"><span class="k">موجودی کل</span><span class="v"><?= $st === null ? 'نامحدود' : number_format($st) . ' عدد' ?></span></div>
      <?php endif; ?>
      <div class="pv-row"><span class="k">وضعیت</span><span class="v">
        <?php if (!empty($view['available'])): ?><span class="pv-badge ok">موجود</span><?php else: ?><span class="pv-badge no">ناموجود</span><?php endif; ?>
      </span></div>
    </div>

    <?php if (!empty($variants)): ?>
      <div class="pv-info-card">
        <h4><?= icon('card', 15) ?> تنوع‌ها (پس‌کد) — انتخابی مشتری</h4>
        <p class="pv-note" style="margin-top:0">مشتری هنگام خرید یکی از این تنوع‌ها را انتخاب می‌کند. موجودی کل بالا برابر مجموع موجودی همین تنوع‌هاست.</p>
        <table class="pv-var-tbl">
          <thead><tr><th>تنوع</th><th>موجودی</th><th>اختلاف قیمت</th></tr></thead>
          <tbody>
            <?php foreach ($variants as $v): ?>
              <tr>
                <td><?= htmlspecialchars($v['label']) ?></td>
                <td><?= $v['stock'] === null ? '—' : number_format($v['stock']) ?></td>
                <td><?= $v['diff'] === 0 ? '—' : (($v['diff'] > 0 ? '+' : '') . number_format($v['diff'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($deliveryDemo !== ''): ?>
      <div class="pv-info-card">
        <h4><?= icon('zap', 15) ?> پس از خرید</h4>
        <p class="pv-note" style="margin:0"><?= $deliveryDemo ?></p>
      </div>
    <?php endif; ?>

    <div class="pv-info-card">
      <p class="pv-note" style="margin:0">💡 این فقط یک <b>دمو</b> است؛ دکمه‌ها در پیش‌نمایش غیرفعال‌اند. تصاویر از روی آدرس عمومی سرور نمایش داده می‌شوند و در ربات واقعی از طریق file_id تلگرام ارسال می‌گردند.</p>
    </div>
  </div>
</div>

<?php if ($embed): ?>
  </body></html>
<?php else: ?>
  <?php include __DIR__ . '/inc/layout_foot.php'; ?>
<?php endif; ?>
