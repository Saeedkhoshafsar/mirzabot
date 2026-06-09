<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Build a "keepRows" map from $_FILES['media_variant'] so set_product_type()
 * does not drop variant rows that only carry an uploaded image (no text yet).
 * Shape returned: [ fieldKey => [ "idx", "idx", ... ] ]  (idx as strings).
 */
function product_variant_keep_rows()
{
    $keep = [];
    $mv = $_FILES['media_variant'] ?? null;
    if (!is_array($mv) || !isset($mv['name']) || !is_array($mv['name'])) {
        return $keep;
    }
    foreach ($mv['name'] as $fieldKey => $rows) {
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $idx => $nm) {
            $err = $mv['error'][$fieldKey][$idx] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_OK) {
                $keep[$fieldKey][] = (string) $idx;
            }
        }
    }
    return $keep;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
  csrf_check_post();
  $name = trim($_POST['name_product'] ?? '');
  if ($name === '') {
    flash('error', $textbotlang['panel']['productNameRequired']);
    header('Location: product.php');
    exit;
  }
  if (db_count($pdo, "SELECT COUNT(*) FROM product WHERE name_product = ?", [$name])) {
    flash('error', $textbotlang['panel']['productNameExists']);
    header('Location: product.php');
    exit;
  }
  $code = bin2hex(random_bytes(2));
  // Categories: accept multi-select array (category[]) and store as CSV; fall back to legacy free-text.
  $catCsv = '';
  if (function_exists('product_category_join') && is_array($_POST['category'] ?? null)) {
    $catCsv = product_category_join($_POST['category']);
    // any brand-new typed category persists to the category table too
    if (function_exists('categories_add')) {
      foreach ($_POST['category'] as $cn) {
        $cn = trim((string) $cn);
        if ($cn !== '') {
          categories_add($cn);
        }
      }
    }
  } else {
    $catCsv = $_POST['cetegory_product'] ?? '';
  }
  try {
    db_query(
      $pdo,
      "INSERT INTO product (name_product,code_product,price_product,Volume_constraint,Service_time,Location,agent,data_limit_reset,note,category,hide_panel,one_buy_status) VALUES (?,?,?,?,?,?,?,'no_reset',?,?,'{}','0')",
      [$name, $code, money_int($_POST['price_product'] ?? 0), (int) ($_POST['volume_product'] ?? 0), (int) ($_POST['time_product'] ?? 0), $_POST['namepanel'] ?? '', $_POST['agent_product'] ?? '', $_POST['note_product'] ?? '', $catCsv]
    );
    // Generic product type + attributes (defaults to 'vpn' if unset).
    $newId = (int) $pdo->lastInsertId();
    if ($newId) {
      $ptype = $_POST['product_type'] ?? 'vpn';
      $attrs = is_array($_POST['attr'] ?? null) ? $_POST['attr'] : [];
      set_product_type($newId, $ptype, $attrs, product_variant_keep_rows());
      // Attach per-variant (per-color/پس‌کد) images to their rows.
      if (!empty($_FILES['media_variant']) && function_exists('product_apply_variant_images')) {
        product_apply_variant_images($newId, $_FILES['media_variant']);
      }
    }

    // Images uploaded directly in the add-product form (no separate step needed).
    $mediaMsg = '';
    if ($newId && !empty($_FILES['media']) && is_array($_FILES['media']['name'] ?? null)
        && function_exists('product_media_handle_upload')) {
      $counts = product_media_handle_upload($newId, $_FILES['media']);
      if ($counts['ok'] > 0) {
        $mediaMsg = ' (' . $counts['ok'] . ' رسانه آپلود شد'
          . ($counts['err'] ? '، ' . $counts['err'] . ' ناموفق' : '') . ')';
      } elseif ($counts['err'] > 0) {
        $mediaMsg = ' (آپلود رسانه ناموفق بود؛ فقط تصویر/ویدیو/صوت تا ۲۵MB مجاز است)';
      }
    }

    flash('success', $textbotlang['panel']['productAddedPrefix'] . $name
      . $textbotlang['panel']['productAddedSuffix'] . $mediaMsg);
  } catch (Exception $e) {
    flash('error', $textbotlang['panel']['productDbError'] . $e->getMessage());
    header('Location: product.php');
    exit;
  }
  header('Location: product.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  csrf_check_post();
  $pid = (int) ($_POST['edit_id'] ?? 0);
  $name = trim($_POST['name_product'] ?? '');
  if ($pid && $name !== '') {
    // Categories: accept multi-select array (category[]); fall back to legacy free-text.
    $catCsv = '';
    if (function_exists('product_category_join') && is_array($_POST['category'] ?? null)) {
      $catCsv = product_category_join($_POST['category']);
      if (function_exists('categories_add')) {
        foreach ($_POST['category'] as $cn) {
          $cn = trim((string) $cn);
          if ($cn !== '') {
            categories_add($cn);
          }
        }
      }
    } else {
      $catCsv = $_POST['cetegory_product'] ?? '';
    }
    try {
      db_query(
        $pdo,
        "UPDATE product SET name_product=?,price_product=?,Volume_constraint=?,Service_time=?,Location=?,agent=?,note=?,category=? WHERE id=?",
        [$name, money_int($_POST['price_product'] ?? 0), (int) ($_POST['volume_product'] ?? 0), (int) ($_POST['time_product'] ?? 0), $_POST['namepanel'] ?? '', $_POST['agent_product'] ?? '', $_POST['note_product'] ?? '', $catCsv, $pid]
      );
      // Generic product type + attributes
      $ptype = $_POST['product_type'] ?? 'vpn';
      $attrs = is_array($_POST['attr'] ?? null) ? $_POST['attr'] : [];
      set_product_type($pid, $ptype, $attrs, product_variant_keep_rows());
      // Attach per-variant (per-color/پس‌کد) images to their rows.
      if (!empty($_FILES['media_variant']) && function_exists('product_apply_variant_images')) {
        product_apply_variant_images($pid, $_FILES['media_variant']);
      }
      flash('success', $textbotlang['panel']['productEdited']);
    } catch (Exception $e) {
      flash('error', $textbotlang['panel']['productErrorPrefix'] . $e->getMessage());
    }
  }
  header('Location: product.php');
  exit;
}

if (isset($_GET['delete'])) {
  csrf_check_get();
  db_query($pdo, "DELETE FROM product WHERE id = ?", [(int) $_GET['delete']]);
  flash('success', $textbotlang['panel']['productDeleted']);
  header('Location: product.php');
  exit;
}

$panels = [];
try {
  $panels = db_fetchAll($pdo, "SELECT * FROM marzban_panel");
} catch (Exception $e) {
}
$allCats = function_exists('categories_names') ? categories_names() : [];
$products = db_fetchAll($pdo, "SELECT * FROM product ORDER BY id");

$pageTitle = $textbotlang['panel']['productsTitle'];
$pageLede = $textbotlang['panel']['productsSubtitle'];
$activeNav = 'product';
include __DIR__ . '/inc/layout_head.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px" class="fade-up">
  <div style="font-size:.85rem;color:var(--mute)"><?= count($products) ?> <?= $textbotlang['panel']['productsHeading'] ?></div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 14) ?> <?= $textbotlang['panel']['productAddProductBtn'] ?></button>
</div>

<div class="card fade-up d1">
  <?php if (empty($products)): ?>
    <div class="empty" style="padding:60px 20px">
      <svg class="ill" viewBox="0 0 200 160" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="40" y="30" width="120" height="100" rx="12" fill="var(--surface-3)" />
        <rect x="56" y="50" width="88" height="12" rx="6" fill="var(--border-strong)" />
        <rect x="56" y="72" width="60" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="90" width="72" height="8" rx="4" fill="var(--border)" />
        <rect x="56" y="108" width="44" height="8" rx="4" fill="var(--border)" />
        <circle cx="155" cy="125" r="22" fill="var(--accent-s)" stroke="var(--accent)" stroke-width="2" />
        <path d="M147 125h16M155 117v16" stroke="var(--accent)" stroke-width="2.5" stroke-linecap="round" />
      </svg>
      <p><?= $textbotlang['panel']['productEmptyState'] ?></p>
      <button class="btn btn-primary" style="margin-top:14px" onclick="openModal('addModal')"><?= icon('plus', 14) ?>
        <?= $textbotlang['panel']['productEmptyAddBtn'] ?></button>
    </div>
  <?php else: ?>
    <div class="toolbar">
      <div class="toolbar-title"><?= $textbotlang['panel']['productListTitle'] ?> <small>(<?= count($products) ?>)</small></div>
      <div class="search-box" style="min-width:220px">
        <?= icon('search', 14) ?>
        <input type="text" placeholder="<?= htmlspecialchars($textbotlang['panel']['productSearchPlaceholder']) ?>" data-filter="prodTbl">
        <button type="button" class="search-clear">✕</button>
      </div>
    </div>
    <div class="tbl-wrap">
      <table id="prodTbl" class="tbl-xl">
        <thead>
          <tr>
            <th>#</th>
            <th><?= $textbotlang['panel']['productColName'] ?></th>
            <th><?= $textbotlang['panel']['productColPrice'] ?></th>
            <th><?= $textbotlang['panel']['productColVolume'] ?></th>
            <th><?= $textbotlang['panel']['productColDuration'] ?></th>
            <th><?= $textbotlang['panel']['productColPanel'] ?></th>
            <th><?= $textbotlang['panel']['productColCategory'] ?></th>
            <th><?= $textbotlang['panel']['productColCode'] ?></th>
            <th><?= $textbotlang['panel']['productColActions'] ?></th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 1;
          foreach ($products as $p): ?>
            <tr>
              <td class="cf"><?= $i++ ?></td>
              <td class="cs"><?= htmlspecialchars($p['name_product'] ?? '') ?></td>
              <td class="cn cs"><?= number_format((int) ($p['price_product'] ?? 0)) ?> <span class="cf"><?= $textbotlang['panel']['productPriceUnit'] ?></span></td>
              <td class="cn"><?= htmlspecialchars($p['Volume_constraint'] ?? '—') ?> <span class="cf">GB</span></td>
              <td class="cn"><?= htmlspecialchars($p['Service_time'] ?? '—') ?> <span class="cf"><?= $textbotlang['panel']['productDurationUnit'] ?></span></td>
              <td class="cf"><?= htmlspecialchars(trunc($p['Location'] ?? '—', 16)) ?></td>
              <td><?php
              $pcats = function_exists('product_category_parse') ? product_category_parse($p['category'] ?? '') : (empty($p['category']) ? [] : [$p['category']]);
              if (!empty($pcats)):
                foreach ($pcats as $pc): ?><span class="tag tag-info"
                    style="margin:1px 2px;display:inline-block"><?= htmlspecialchars($pc) ?></span><?php endforeach;
              else: ?><span class="cf">—</span><?php endif; ?></td>
              <td class="cm" style="font-size:.72rem"><?= htmlspecialchars($p['code_product'] ?? '') ?></td>
              <td>
                <div style="display:flex;gap:5px">
                  <button class="btn btn-ghost btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productEditBtn']) ?>"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)">
                    <?= icon('edit', 13) ?>
                  </button>
                  <?php $mediaCount = function_exists('product_media_count') ? product_media_count((int) $p['id']) : 0; ?>
                  <a href="product_media.php?pid=<?= (int) $p['id'] ?>"
                    class="btn <?= $mediaCount > 0 ? 'btn-ghost' : 'btn-primary' ?> btn-sm"
                    title="آپلود/مدیریت تصویر، ویدیو و صوت این محصول"
                    style="position:relative;gap:4px">
                    <?= icon('image', 13) ?>
                    <span><?= $mediaCount > 0 ? 'تصاویر (' . $mediaCount . ')' : 'افزودن تصویر' ?></span>
                  </a>
                  <?php if (($p['product_type'] ?? 'vpn') === 'serial_code'):
                      $cc = product_codes_count((int) $p['id']); ?>
                    <a href="product_codes.php?pid=<?= (int) $p['id'] ?>"
                      class="btn <?= $cc['available'] > 0 ? 'btn-ghost' : 'btn-no' ?> btn-sm btn-icon"
                      title="کدها / سریال (آزاد: <?= (int) $cc['available'] ?>)">
                      <?= icon('card', 13) ?>
                    </a>
                  <?php endif; ?>
                  <a href="product.php?delete=<?= (int) $p['id'] ?>&_csrf=<?= csrf_token() ?>"
                    class="btn btn-no btn-sm btn-icon" title="<?= htmlspecialchars($textbotlang['panel']['productDeleteBtn']) ?>"
                    data-confirm=sprintf($textbotlang['panel']['productConfirmDeleteProduct'], $p['name_product'])>
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

<div class="modal-veil" id="addModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['productAddModalTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('addModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
          <!-- STEP 1: product type FIRST — everything below adapts to this choice -->
          <div class="field full">
            <label>۱) نوع محصول را انتخاب کنید</label>
            <?php $defaultPType = function_exists('panel_default_product_type') ? panel_default_product_type() : 'vpn'; ?>
            <select name="product_type" id="add_ptype" class="select" onchange="renderAttrFields('add')">
              <?php foreach (product_types() as $tk => $td): ?>
                <option value="<?= htmlspecialchars($tk) ?>" <?= $tk === $defaultPType ? 'selected' : '' ?>><?= htmlspecialchars($td['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="field-hint">با تغییر نوع محصول، فیلدها و مثال‌های پایین خودکار متناسب می‌شوند.</div>
          </div>

          <div class="field full">
            <label><?= $textbotlang['panel']['productNameLabel'] ?></label>
            <input type="text" name="name_product" id="add_name" data-example="name" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productNameExample']) ?>" required>
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productPriceLabel'] ?></label>
            <input type="text" name="price_product" class="input" data-money inputmode="numeric" placeholder="<?= htmlspecialchars($textbotlang['panel']['productZeroValue']) ?>">
          </div>

          <!-- VPN-only fields: hidden for physical/digital/etc. -->
          <div class="field vpn-only" data-ptype-only="vpn">
            <label><?= $textbotlang['panel']['productVolumeLabel'] ?></label>
            <input type="number" name="volume_product" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productFiftyValue']) ?>" min="0">
          </div>
          <div class="field vpn-only" data-ptype-only="vpn">
            <label><?= $textbotlang['panel']['productDurationLabel'] ?></label>
            <input type="number" name="time_product" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productThirtyValue']) ?>" min="0">
          </div>
          <div class="field vpn-only" data-ptype-only="vpn">
            <label><?= $textbotlang['panel']['productPanelLabel'] ?></label>
            <select name="namepanel" class="select">
              <option value=""><?= $textbotlang['panel']['productNotSelected'] ?></option>
              <?php foreach ($panels as $pl): ?>
                <option value="<?= htmlspecialchars($pl['name_panel'] ?? $pl['id']) ?>">
                  <?= htmlspecialchars($pl['name_panel'] ?? $pl['id']) ?>
                </option><?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label><?= $textbotlang['panel']['productCategoryLabel'] ?>
              <a href="categories.php" style="font-size:12px;font-weight:normal;margin-right:6px;color:#3b82f6;">مدیریت دسته‌بندی‌ها</a>
            </label>
            <div class="cat-picker" id="add_cat_picker">
              <?php if (empty($allCats)): ?>
                <div class="cat-empty" style="color:#888;font-size:13px;">هنوز دسته‌بندی ندارید. از طریق
                  «مدیریت دسته‌بندی‌ها» یا کادر زیر اضافه کنید.</div>
              <?php else: ?>
                <?php foreach ($allCats as $cn): ?>
                  <label class="cat-chip"><input type="checkbox" name="category[]"
                      value="<?= htmlspecialchars($cn) ?>"> <span><?= htmlspecialchars($cn) ?></span></label>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <div class="cat-add-row" style="display:flex;gap:6px;margin-top:8px;">
              <input type="text" id="add_cat_new" class="input" placeholder="افزودن دسته‌بندی جدید…"
                style="flex:1;">
              <button type="button" class="btn btn-sm" onclick="addNewCategoryChip('add')">افزودن</button>
            </div>
          </div>
          <div class="field">
            <label><?= $textbotlang['panel']['productTypeLabel'] ?></label>
            <select name="agent_product" class="select">
              <option value="f"><?= $textbotlang['panel']['productTypeRegular'] ?></option>
              <option value="n"><?= $textbotlang['panel']['productTypeAgent'] ?></option>
              <option value="n2"><?= $textbotlang['panel']['productTypeAgentPro'] ?></option>
            </select>
          </div>
          <div class="field full">
            <label><?= $textbotlang['panel']['productNoteLabel'] ?></label>
            <input type="text" name="note_product" data-example="note" class="input" placeholder="<?= htmlspecialchars($textbotlang['panel']['productDescriptionOptional']) ?>">
          </div>

          <!-- type-specific attribute fields rendered by renderAttrFields() -->
          <div class="field full" id="add_attr" style="display:flex;flex-direction:column;gap:12px"></div>

          <!-- STEP 2: product images RIGHT HERE — no need to edit again later -->
          <div class="field full">
            <label><?= icon('image', 14) ?> تصویر/ویدیو/صوت محصول (اختیاری)</label>
            <label for="addMediaInput" id="addDropZone"
              style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;
                     border:2px dashed var(--bd);border-radius:12px;padding:18px 14px;cursor:pointer;
                     text-align:center;background:var(--sf2);transition:border-color .15s,background .15s">
              <?= icon('image', 26) ?>
              <div style="font-weight:700;color:var(--text);font-size:.85rem">برای انتخاب تصویر کلیک کنید</div>
              <div style="font-size:.72rem;color:var(--mute)">یا فایل را همین‌جا رها کنید — چند فایل هم‌زمان مجاز است (عکس/ویدیو/صوت، تا ۲۵MB)</div>
              <div id="addMediaPicked" style="font-size:.76rem;color:var(--accent);font-weight:700;min-height:1em"></div>
              <input type="file" name="media[]" id="addMediaInput" multiple
                accept="image/*,video/mp4,video/webm,audio/mpeg,audio/ogg,audio/wav" style="display:none">
            </label>
            <div class="field-hint">می‌توانید همین‌جا تصویر اضافه کنید؛ تا ۵ رسانه در ربات به مشتری نشان داده می‌شود.</div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('plus', 13) ?> <?= $textbotlang['panel']['productSaveSubmitBtn'] ?></button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')"><?= $textbotlang['panel']['productCancelModalBtn'] ?></button>
      </div>
    </form>
  </div>
</div>

<div class="modal-veil" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <h3><?= $textbotlang['panel']['productDetailTitle'] ?></h3>
      <button class="modal-x" onclick="closeModal('editModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="edit_id" id="edit_id">
        <div class="form-grid">
          <!-- product type FIRST, same as the add form -->
          <div class="field full">
            <label>نوع محصول</label>
            <select name="product_type" id="edit_ptype" class="select" onchange="renderAttrFields('edit')">
              <?php foreach (product_types() as $tk => $td): ?>
                <option value="<?= htmlspecialchars($tk) ?>"><?= htmlspecialchars($td['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field full">
            <label>نام محصول *</label>
            <input type="text" name="name_product" id="edit_name" data-example="name" class="input" required>
          </div>
          <div class="field">
            <label>قیمت (تومان)</label>
            <input type="text" name="price_product" id="edit_price" class="input" data-money inputmode="numeric">
          </div>

          <!-- VPN-only fields: hidden for physical/digital/etc. -->
          <div class="field vpn-only" data-ptype-only="vpn">
            <label>حجم (GB)</label>
            <input type="number" name="volume_product" id="edit_volume" class="input" min="0">
          </div>
          <div class="field vpn-only" data-ptype-only="vpn">
            <label>مدت (روز)</label>
            <input type="number" name="time_product" id="edit_time" class="input" min="0">
          </div>
          <div class="field vpn-only" data-ptype-only="vpn">
            <label>پنل</label>
            <select name="namepanel" id="edit_panel" class="select">
              <option value="">— انتخاب نشده —</option>
              <?php foreach ($panels as $pl): ?>
                <option value="<?= htmlspecialchars($pl['name_panel'] ?? $pl['id']) ?>">
                  <?= htmlspecialchars($pl['name_panel'] ?? $pl['id']) ?>
                </option><?php endforeach; ?>
            </select>
          </div>

          <div class="field full">
            <label>دسته‌بندی
              <a href="categories.php" style="font-size:12px;font-weight:normal;margin-right:6px;color:#3b82f6;">مدیریت دسته‌بندی‌ها</a>
            </label>
            <div class="cat-picker" id="edit_cat_picker">
              <?php if (empty($allCats)): ?>
                <div class="cat-empty" style="color:#888;font-size:13px;">هنوز دسته‌بندی ندارید.</div>
              <?php else: ?>
                <?php foreach ($allCats as $cn): ?>
                  <label class="cat-chip"><input type="checkbox" name="category[]"
                      value="<?= htmlspecialchars($cn) ?>"> <span><?= htmlspecialchars($cn) ?></span></label>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <div class="cat-add-row" style="display:flex;gap:6px;margin-top:8px;">
              <input type="text" id="edit_cat_new" class="input" placeholder="افزودن دسته‌بندی جدید…"
                style="flex:1;">
              <button type="button" class="btn btn-sm" onclick="addNewCategoryChip('edit')">افزودن</button>
            </div>
          </div>
          <div class="field">
            <label>نوع کاربر</label>
            <select name="agent_product" id="edit_agent" class="select">
              <option value="f">کاربر عادی</option>
              <option value="n">نماینده</option>
              <option value="n2">نماینده پیشرفته</option>
            </select>
          </div>
          <div class="field full">
            <label>پیام/توضیح (اختیاری)</label>
            <input type="text" name="note_product" id="edit_note" data-example="note" class="input">
          </div>

          <div class="field full" id="edit_attr" style="display:flex;flex-direction:column;gap:12px"></div>
          <div class="field full">
            <a href="#" id="edit_media_link" class="btn btn-ghost" style="width:100%;justify-content:center">
              <?= icon('image', 14) ?> مدیریت/افزودن تصویر، ویدیو و صوت این محصول
            </a>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> ذخیره تغییرات</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<style>
  .field-hint { color: var(--mute); font-size: 12px; margin-top: 4px; }
  .rep-field .rep-wrap { overflow-x: auto; border: 1px solid var(--bd); border-radius: 10px; }
  .rep-field .rep-table { width: 100%; border-collapse: collapse; min-width: 640px; }
  .rep-field .rep-table th,
  .rep-field .rep-table td { padding: 6px; border-bottom: 1px solid var(--bd); text-align: right; font-size: 13px; vertical-align: middle; }
  .rep-field .rep-table th { color: var(--mute); font-weight: 600; white-space: nowrap; }
  .rep-field .rep-table tr:last-child td { border-bottom: none; }
  /* Inputs fill their cell so typed text (رنگ/سایز) is never clipped (Audit). */
  .rep-field .rep-table td .input { width: 100%; box-sizing: border-box; padding: 7px 9px; min-width: 90px; }
  .rep-field .rep-table th:nth-child(1), .rep-field .rep-table td:nth-child(1) { min-width: 110px; }
  .rep-field .rep-actions { width: 38px; text-align: center; }
  .rep-field .rep-del { padding: 2px 10px; line-height: 1; font-size: 16px; }
  .rep-field .rep-add { margin-top: 8px; }
  /* Per-variant image cell (upload a separate photo for each variant). */
  .rep-img-cell { text-align: center; min-width: 96px; }
  .rep-img-pick {
    display: inline-flex; flex-direction: column; align-items: center; gap: 4px;
    cursor: pointer; padding: 4px; border: 1px dashed var(--bd, #cbd5e1);
    border-radius: 8px; transition: border-color .15s, background .15s;
  }
  .rep-img-pick:hover { border-color: var(--accent, #3b82f6); background: var(--accent-s, rgba(59,130,246,.07)); }
  .rep-img-thumb {
    width: 54px; height: 54px; object-fit: cover; border-radius: 8px;
    display: block; border: 1px solid var(--bd); background: var(--sf2, #f1f5f9);
  }
  .rep-img-empty {
    width: 54px; height: 54px; display: inline-flex; align-items: center; justify-content: center;
    font-size: 10px; color: var(--mute, #94a3b8); border-radius: 8px;
    border: 1px dashed var(--bd); text-align: center; line-height: 1.2;
  }
  .rep-img-btn { font-size: 11px; color: var(--accent, #3b82f6); font-weight: 700; }

  /* "این محصول پس‌کد دارد" badge shown when >1 variant. */
  .rep-pscode-badge {
    display: inline-block; margin-right: 8px; padding: 2px 10px;
    font-size: 11px; font-weight: 600; border-radius: 999px;
    color: #b45309; background: #fef3c7; border: 1px solid #fde68a;
    vertical-align: middle;
  }

  /* Main media uploader preview */
  #addMediaPicked { width: 100%; }
  .media-pick-head { color: #16a34a; font-weight: 700; font-size: .8rem; margin-top: 4px; }
  .media-pick-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; justify-content: center; }
  .media-pick-cell { width: 84px; display: flex; flex-direction: column; align-items: center; gap: 3px; }
  .media-pick-cell img { width: 84px; height: 84px; object-fit: cover; border-radius: 10px; border: 1px solid var(--bd); }
  .media-pick-ic { width: 84px; height: 84px; display: flex; align-items: center; justify-content: center; font-size: 30px; border-radius: 10px; border: 1px solid var(--bd); background: var(--sf2); }
  .media-pick-name { font-size: 10px; color: var(--mute); max-width: 84px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cat-picker { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px; border: 1px solid var(--bd); border-radius: 10px; min-height: 42px; align-content: flex-start; }
  .cat-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border: 1px solid var(--bd); border-radius: 999px; cursor: pointer; font-size: 13px; user-select: none; transition: background .15s, border-color .15s; }
  .cat-chip:hover { border-color: #3b82f6; }
  .cat-chip input { margin: 0; cursor: pointer; }
  .cat-chip:has(input:checked) { background: rgba(59,130,246,.12); border-color: #3b82f6; }
</style>
<script>
  // Product types + their attribute field definitions (from product_types()).
  window.PRODUCT_TYPES = <?= json_encode(product_types(), JSON_UNESCAPED_UNICODE) ?>;
  // Custom variant schemas (category-like variant templates). Each entry:
  // { id, name, fields:[ {key,label,type,options?,allow_custom?}, ... ] }.
  // The variants table builds its columns from the chosen schema's fields.
  window.VARIANT_SCHEMAS = <?= json_encode(function_exists('variant_schemas_list') ? variant_schemas_list() : [], JSON_UNESCAPED_UNICODE) ?>;
  // Enabled (API-backed) shipping carriers the admin can attach to a product.
  window.SHIPPING_CARRIERS = <?= json_encode(function_exists('enabled_shipping_carriers') ? enabled_shipping_carriers() : [], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="js/product.js"></script>
<script>
  // Render attribute fields for the profile-preselected type in the add form
  // so the right fields appear immediately (step 8e: profile shapes the UI).
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof renderAttrFields === 'function' && document.getElementById('add_ptype')) {
      renderAttrFields('add');
    }
  });
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>