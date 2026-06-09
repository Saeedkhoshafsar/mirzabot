<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_auth();

/*
 * Variant schemas manager.
 *
 * A "variant schema" is a reusable, category-like template that defines the
 * CUSTOM columns a product's variants table should show. For example:
 *   - "لباس"   → رنگ (select), سایز (select), جنس (select), پس‌کد (text)
 *   - "آرایشی" → حجم (select), شِید/رنگ (select)
 *   - "لوازم خانگی" → ولتاژ (select), گارانتی (text)
 * Each column can be a free text/number field OR a dropdown backed by a list
 * of predefined values, optionally allowing the seller to type a custom one.
 *
 * The universal موجودی / اختلاف قیمت / تصویر columns are added automatically
 * to every product's variants table, so they are NOT defined here.
 */

ensure_variant_schema_table();

// --- delete --------------------------------------------------------------
if (isset($_GET['delete'])) {
    csrf_check_get();
    if (variant_schema_delete((int) $_GET['delete'])) {
        flash('success', 'قالب تنوع حذف شد.');
    } else {
        flash('error', 'حذف قالب ناموفق بود.');
    }
    header('Location: variant_schemas.php');
    exit;
}

// --- add / edit ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['add', 'edit'], true)) {
    csrf_check_post();
    $name = trim($_POST['name'] ?? '');

    // Build the fields[] array from the submitted parallel arrays.
    // labels[], types[], options[] (newline/comma separated), allow_custom[].
    $labels  = $_POST['field_label']  ?? [];
    $types   = $_POST['field_type']   ?? [];
    $options = $_POST['field_options'] ?? [];
    $allowC  = $_POST['field_allow_custom'] ?? []; // map idx => 'on'

    $fields = [];
    if (is_array($labels)) {
        foreach ($labels as $i => $lbl) {
            $lbl = trim((string) $lbl);
            if ($lbl === '') {
                continue;
            }
            $fields[] = [
                'label'        => $lbl,
                'type'         => $types[$i] ?? 'text',
                'options'      => $options[$i] ?? '',
                'allow_custom' => isset($allowC[$i]) ? 1 : 0,
            ];
        }
    }

    if ($name === '') {
        flash('error', 'نام قالب الزامی است.');
    } elseif (($_POST['action'] ?? '') === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && variant_schema_update($id, $name, $fields)) {
            flash('success', 'قالب «' . $name . '» به‌روزرسانی شد.');
        } else {
            flash('error', 'به‌روزرسانی قالب ناموفق بود.');
        }
    } else {
        if (variant_schema_add($name, $fields)) {
            flash('success', 'قالب «' . $name . '» ساخته شد.');
        } else {
            flash('error', 'ساخت قالب ناموفق بود.');
        }
    }
    header('Location: variant_schemas.php');
    exit;
}

$schemas  = variant_schemas_list();
$flashOk  = get_flash('success');
$flashErr = get_flash('error');

$pageTitle    = 'قالب‌های تنوع محصول';
$activeNav     = 'product';
$showPageHead = false;
include __DIR__ . '/inc/layout_head.php';

// Field type labels for display.
$typeLabels = ['text' => 'متن', 'number' => 'عدد', 'select' => 'لیست انتخابی', 'image' => 'تصویر'];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px" class="fade-up">
    <div>
        <div style="font-size:1rem;font-weight:800;color:var(--text)">قالب‌های تنوع محصول</div>
        <div style="font-size:.8rem;color:var(--mute)">برای هر دستهٔ کالا (لباس، آرایشی، لوازم خانگی…) ستون‌های دلخواه بسازید؛ هنگام افزودن محصول، قالب را انتخاب می‌کنید.</div>
    </div>
    <div style="display:flex;gap:8px">
        <button class="btn btn-primary btn-sm" onclick="openSchemaModal()"><?= icon('plus', 14) ?> قالب جدید</button>
        <a href="product.php" class="btn btn-ghost btn-sm"><?= icon('arrow-left', 14) ?> بازگشت به محصولات</a>
    </div>
</div>

<?php if ($flashOk): ?><div class="notice notice-ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="notice notice-no"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<div class="card fade-up d1">
    <div class="card-head">
        <div class="card-title">قالب‌های ساخته‌شده <small style="color:var(--dim);font-weight:400">(<?= count($schemas) ?>)</small></div>
    </div>
    <div class="tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>نام قالب</th>
                    <th>ستون‌های دلخواه</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schemas)): ?>
                    <tr><td colspan="4" style="text-align:center;color:var(--mute);padding:24px">
                        هنوز قالبی نساخته‌اید. با «قالب جدید» اولین قالب (مثلاً «لباس») را بسازید.
                    </td></tr>
                <?php else: foreach ($schemas as $s): ?>
                    <tr>
                        <td class="cn cf"><?= (int) $s['id'] ?></td>
                        <td class="cs"><?= htmlspecialchars($s['name']) ?></td>
                        <td>
                            <?php foreach ($s['fields'] as $f):
                                $isSelect = ($f['type'] ?? '') === 'select'; ?>
                                <span class="tag <?= $isSelect ? 'tag-info' : '' ?>" style="margin:2px 2px;display:inline-block">
                                    <?= htmlspecialchars($f['label']) ?>
                                    <small style="color:var(--dim)">(<?= htmlspecialchars($typeLabels[$f['type']] ?? $f['type']) ?><?php
                                        if ($isSelect && !empty($f['options'])) {
                                            echo ': ' . htmlspecialchars(implode('، ', array_slice($f['options'], 0, 4)));
                                            if (count($f['options']) > 4) echo '…';
                                        }
                                    ?>)</small>
                                </span>
                            <?php endforeach; ?>
                            <?php if (empty($s['fields'])): ?><span class="cf">— بدون ستون —</span><?php endif; ?>
                        </td>
                        <td style="text-align:left;white-space:nowrap">
                            <button type="button" class="btn btn-ghost btn-sm btn-icon" title="ویرایش"
                                onclick='editSchema(<?= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><?= icon('edit', 13) ?></button>
                            <a href="variant_schemas.php?delete=<?= (int) $s['id'] ?>&_csrf=<?= csrf_token() ?>"
                                class="btn btn-no btn-sm btn-icon" title="حذف"
                                data-confirm="این قالب حذف شود؟ محصولاتی که از آن استفاده می‌کنند به ستون‌های پیش‌فرض برمی‌گردند."><?= icon('trash', 13) ?></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit modal -->
<div class="modal-veil" id="schemaModal">
  <div class="modal">
    <div class="modal-head">
      <h3 id="schemaModalTitle">قالب تنوع جدید</h3>
      <button class="modal-x" onclick="closeModal('schemaModal')"><?= icon('close', 14) ?></button>
    </div>
    <form method="POST" action="variant_schemas.php">
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" id="schema_action" value="add">
        <input type="hidden" name="id" id="schema_id" value="">
        <div class="field full">
          <label>نام قالب (مثلاً: لباس، آرایشی، لوازم خانگی)</label>
          <input type="text" name="name" id="schema_name" class="input" placeholder="لباس" required>
        </div>

        <div class="field full" style="margin-top:10px">
          <label>ستون‌های دلخواه این قالب</label>
          <div class="field-hint">برای هر ویژگی یک ردیف اضافه کنید. اگر نوع «لیست انتخابی» باشد، مقادیر آماده را هر کدام در یک خط بنویسید و در صورت تمایل «اجازهٔ مقدار دلخواه» را تیک بزنید تا فروشنده بتواند مقدار جدید هم تایپ کند.</div>
          <div id="schema_fields" style="margin-top:10px;display:flex;flex-direction:column;gap:10px"></div>
          <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" onclick="addSchemaField()">+ افزودن ستون</button>
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary"><?= icon('check', 13) ?> ذخیره قالب</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal('schemaModal')">انصراف</button>
      </div>
    </form>
  </div>
</div>

<style>
  .vs-field-row { border:1px solid var(--bd); border-radius:10px; padding:10px; background:var(--sf2); }
  .vs-field-row .vs-top { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
  .vs-field-row .vs-col { flex:1; min-width:140px; }
  .vs-field-row label { font-size:12px; color:var(--mute); display:block; margin-bottom:4px; }
  .vs-field-row .vs-opts { margin-top:8px; }
  .vs-field-row .vs-del { padding:6px 10px; }
</style>

<script>
var VS_FIELD_TYPES = { text:'متن', number:'عدد', select:'لیست انتخابی', image:'تصویر' };

function escapeHtmlVS(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}

// Build one editable column-definition row.
function vsFieldRow(idx, f) {
    f = f || {};
    var type = f.type || 'text';
    var optsText = '';
    if (Array.isArray(f.options)) optsText = f.options.join('\n');
    else if (typeof f.options === 'string') optsText = f.options;
    var allowChecked = (f.allow_custom === 1 || f.allow_custom === '1' || f.allow_custom === true) ? 'checked' : '';

    var typeSel = '<select name="field_type[' + idx + ']" class="select" onchange="vsToggleOpts(this)">';
    Object.keys(VS_FIELD_TYPES).forEach(function (t) {
        typeSel += '<option value="' + t + '"' + (t === type ? ' selected' : '') + '>' + VS_FIELD_TYPES[t] + '</option>';
    });
    typeSel += '</select>';

    var optsHidden = (type === 'select') ? '' : ' style="display:none"';
    var html = '<div class="vs-field-row">' +
        '<div class="vs-top">' +
            '<div class="vs-col"><label>نام ستون</label>' +
                '<input type="text" name="field_label[' + idx + ']" class="input" value="' + escapeHtmlVS(f.label || '') + '" placeholder="مثلاً: رنگ"></div>' +
            '<div class="vs-col" style="max-width:170px"><label>نوع</label>' + typeSel + '</div>' +
            '<button type="button" class="btn btn-no btn-sm vs-del" onclick="this.closest(\'.vs-field-row\').remove()">حذف</button>' +
        '</div>' +
        '<div class="vs-opts"' + optsHidden + '>' +
            '<label style="font-size:12px;color:var(--mute);display:block;margin-bottom:4px">مقادیر آماده (هر کدام در یک خط)</label>' +
            '<textarea name="field_options[' + idx + ']" class="textarea" style="min-height:80px" placeholder="قرمز&#10;آبی&#10;سبز">' + escapeHtmlVS(optsText) + '</textarea>' +
            '<label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-weight:400;font-size:13px">' +
                '<input type="checkbox" name="field_allow_custom[' + idx + ']" ' + allowChecked + '> اجازهٔ مقدار دلخواه (فروشنده بتواند مقدار جدید تایپ کند)</label>' +
        '</div>' +
    '</div>';
    return html;
}

window.vsToggleOpts = function (sel) {
    var row = sel.closest('.vs-field-row');
    var opts = row.querySelector('.vs-opts');
    if (opts) opts.style.display = (sel.value === 'select') ? '' : 'none';
};

var vsIdx = 0;
window.addSchemaField = function (f) {
    var box = document.getElementById('schema_fields');
    box.insertAdjacentHTML('beforeend', vsFieldRow(vsIdx, f || {}));
    vsIdx++;
};

window.openSchemaModal = function () {
    document.getElementById('schemaModalTitle').textContent = 'قالب تنوع جدید';
    document.getElementById('schema_action').value = 'add';
    document.getElementById('schema_id').value = '';
    document.getElementById('schema_name').value = '';
    document.getElementById('schema_fields').innerHTML = '';
    vsIdx = 0;
    // start with two helpful example rows
    addSchemaField({ label: 'رنگ', type: 'select', options: ['قرمز', 'آبی', 'مشکی'], allow_custom: 1 });
    addSchemaField({ label: 'سایز', type: 'select', options: ['S', 'M', 'L', 'XL'], allow_custom: 1 });
    openModal('schemaModal');
};

window.editSchema = function (s) {
    document.getElementById('schemaModalTitle').textContent = 'ویرایش قالب: ' + (s.name || '');
    document.getElementById('schema_action').value = 'edit';
    document.getElementById('schema_id').value = s.id || '';
    document.getElementById('schema_name').value = s.name || '';
    document.getElementById('schema_fields').innerHTML = '';
    vsIdx = 0;
    (s.fields || []).forEach(function (f) { addSchemaField(f); });
    if (!(s.fields || []).length) addSchemaField();
    openModal('schemaModal');
};
</script>

<?php include __DIR__ . '/inc/layout_foot.php'; ?>
