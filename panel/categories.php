<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Product categories manager.
 * Central list of categories that the product form offers as multi-select.
 * Backed by the `category` table (id, remark). Renaming/deleting a category
 * keeps every product's category assignment in sync (see function.php).
 */

// Make sure the table exists even on older installs (non-destructive).
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS category (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        remark VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin");
} catch (Exception $e) { /* ignore */ }

// --- Add ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_check_post();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        flash('error', 'نام دسته‌بندی را وارد کنید.');
    } elseif (categories_add($name)) {
        flash('success', 'دسته‌بندی «' . $name . '» افزوده شد.');
    } else {
        flash('error', 'افزودن ناموفق بود (احتمالاً تکراری است).');
    }
    header('Location: categories.php');
    exit;
}

// --- Rename ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename') {
    csrf_check_post();
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id && categories_rename($id, $name)) {
        flash('success', 'دسته‌بندی ویرایش شد (در محصولات هم به‌روزرسانی شد).');
    } else {
        flash('error', 'ویرایش ناموفق بود.');
    }
    header('Location: categories.php');
    exit;
}

// --- Delete ---
if (isset($_GET['delete'])) {
    csrf_check_get();
    if (categories_delete((int) $_GET['delete'])) {
        flash('success', 'دسته‌بندی حذف شد (از محصولات مرتبط هم برداشته شد).');
    } else {
        flash('error', 'حذف ناموفق بود.');
    }
    header('Location: categories.php');
    exit;
}

$cats = categories_list();

// count products per category (best-effort) so the admin sees usage.
$counts = [];
try {
    $rows = $pdo->query("SELECT category FROM product WHERE category IS NOT NULL AND category <> ''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $csv) {
        foreach (product_category_parse($csv) as $n) {
            $key = mb_strtolower($n);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    }
} catch (Exception $e) { /* ignore */ }

$flashOk = get_flash('success');
$flashErr = get_flash('error');

$pageTitle = 'دسته‌بندی محصولات';
$activeNav = 'categories';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px"
  class="fade-up">
  <div>
    <div style="font-size:1rem;font-weight:800;color:var(--text)">دسته‌بندی محصولات</div>
    <div style="font-size:.8rem;color:var(--mute)">دسته‌ها را اینجا تعریف کنید؛ هنگام افزودن/ویرایش محصول از همین فهرست
      انتخاب می‌شوند (می‌توانید چند دسته انتخاب کنید).</div>
  </div>
  <a href="product.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> بازگشت به محصولات</a>
</div>

<?php if ($flashOk): ?>
  <div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if ($flashErr): ?>
  <div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>

<div class="card fade-up d1" style="margin-bottom:16px">
  <div class="card-head">
    <div>
      <div class="card-title">افزودن دسته‌بندی</div>
      <div class="card-subtitle">مثلاً: لوازم جانبی، پوشاک، اشتراک، لایسنس…</div>
    </div>
  </div>
  <div class="card-body">
    <form method="POST" action="categories.php">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="text" name="name" class="input" style="flex:1;min-width:220px"
          placeholder="نام دستهٔ جدید…" maxlength="200" required>
        <button type="submit" class="btn btn-primary"><?= icon('plus', 14) ?> افزودن</button>
      </div>
    </form>
  </div>
</div>

<div class="card fade-up d2">
  <div class="card-head">
    <div class="card-title">دسته‌های موجود <small style="color:var(--dim);font-weight:400">(<?= count($cats) ?>)</small>
    </div>
  </div>
  <div class="card-body">
    <?php if (empty($cats)): ?>
      <div style="color:var(--mute);text-align:center;padding:24px">هنوز دسته‌بندی‌ای تعریف نشده است.</div>
    <?php else: ?>
      <div class="tbl-wrap">
        <table class="tbl-xl">
          <thead>
            <tr>
              <th>#</th>
              <th>نام دسته</th>
              <th>تعداد محصول</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 1;
            foreach ($cats as $c):
              $name = $c['remark'] ?? '';
              $used = $counts[mb_strtolower($name)] ?? 0; ?>
              <tr>
                <td class="cf"><?= $i++ ?></td>
                <td class="cs"><?= htmlspecialchars($name) ?></td>
                <td class="cn"><?= $used ?></td>
                <td>
                  <div style="display:flex;gap:5px">
                    <button class="btn btn-ghost btn-sm btn-icon" title="ویرایش نام"
                      onclick="renameCat(<?= (int) $c['id'] ?>, <?= htmlspecialchars(json_encode($name), ENT_QUOTES) ?>)">
                      <?= icon('edit', 13) ?>
                    </button>
                    <a href="categories.php?delete=<?= (int) $c['id'] ?>&_csrf=<?= csrf_token() ?>"
                      class="btn btn-no btn-sm btn-icon" title="حذف"
                      data-confirm="دستهٔ «<?= htmlspecialchars($name) ?>» حذف شود؟ (از محصولات مرتبط هم برداشته می‌شود)">
                      <?= icon('trash', 13) ?>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- hidden rename form -->
<form method="POST" action="categories.php" id="renameForm" style="display:none">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="rename">
  <input type="hidden" name="id" id="renameId">
  <input type="hidden" name="name" id="renameName">
</form>
<script>
  function renameCat(id, current) {
    var name = prompt('نام جدید دسته‌بندی:', current);
    if (name === null) return;
    name = name.trim();
    if (!name) return;
    document.getElementById('renameId').value = id;
    document.getElementById('renameName').value = name;
    document.getElementById('renameForm').submit();
  }
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
