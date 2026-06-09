// ---------------------------------------------------------------------------
// Dynamic product form: render attribute fields based on the selected
// product_type (definitions injected as window.PRODUCT_TYPES).
// ---------------------------------------------------------------------------

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// A sentinel value used by select cells to mean "let me type my own value".
var CUSTOM_OPT = '__custom__';

// Build a select cell that lists predefined options and (optionally) a
// "مقدار دلخواه…" choice that reveals a free-text input. This implements the
// requested behaviour: pick from a ready list OR enter a custom value.
function buildSelectCell(cName, c, cv) {
    var opts = Array.isArray(c.options) ? c.options : [];
    var allowCustom = (c.allow_custom === 1 || c.allow_custom === '1' || c.allow_custom === true);
    // Decide whether the saved value matches a predefined option.
    var inList = opts.indexOf(String(cv)) !== -1;
    var useCustom = (cv !== '' && !inList && allowCustom);

    var sel = '<select class="input rep-select" onchange="repeaterSelectChange(this)">';
    sel += '<option value="">— انتخاب —</option>';
    opts.forEach(function (o) {
        var s = (!useCustom && String(o) === String(cv)) ? ' selected' : '';
        sel += '<option value="' + escapeHtml(o) + '"' + s + '>' + escapeHtml(o) + '</option>';
    });
    if (allowCustom) {
        sel += '<option value="' + CUSTOM_OPT + '"' + (useCustom ? ' selected' : '') +
            '>✏️ مقدار دلخواه…</option>';
    }
    sel += '</select>';

    // Hidden field carries the ACTUAL submitted value (the select itself isn't
    // named, so it never posts). The custom text box (shown only when needed)
    // writes into this same hidden field.
    var hidden = '<input type="hidden" name="' + cName + '" value="' + escapeHtml(cv) + '">';
    var customStyle = useCustom ? '' : ' style="display:none"';
    var custom = '<input type="text" class="input rep-custom" placeholder="مقدار دلخواه"' +
        customStyle + ' value="' + (useCustom ? escapeHtml(cv) : '') + '"' +
        ' oninput="repeaterCustomInput(this)">';

    return hidden + sel + custom;
}

// Build the markup for a single repeater row.
// `fieldKey` is the attribute key, `idx` the row index, `cols` the column defs,
// `row` an optional map of saved cell values.
function buildRepeaterRow(fieldKey, idx, cols, row) {
    row = row || {};
    var cells = '';
    cols.forEach(function (c) {
        var cv = row[c.key];
        if (cv === undefined || cv === null) cv = '';
        var cName = 'attr[' + fieldKey + '][' + idx + '][' + c.key + ']';

        if (c.type === 'image' || c.type === 'file') {
            // Per-variant media (image OR generic file: pdf/doc/zip/…). A column
            // may allow several uploads (images_count / files_count). Each slot
            // gets its own file input named
            //   media_variant[fieldKey][idx][colKey][slot]
            // and a hidden field attr[...][slot] that keeps any existing url.
            var isImg = (c.type === 'image');
            var n = parseInt(isImg ? c.images_count : c.files_count, 10) || 1;
            if (n < 1) n = 1;
            var accept = variantFormatsToAccept(c.formats, isImg);
            var existingVals = Array.isArray(cv) ? cv : (cv ? [cv] : []);
            var slots = '';
            for (var s = 0; s < n; s++) {
                var sv = existingVals[s] || '';
                var hName = 'attr[' + fieldKey + '][' + idx + '][' + c.key + '][' + s + ']';
                var fName = 'media_variant[' + fieldKey + '][' + idx + '][' + c.key + '][' + s + ']';
                var srcUrl = sv ? (/^https?:|^\//.test(sv) ? sv : '../' + sv) : '';
                var preview;
                if (isImg) {
                    preview = sv
                        ? '<img src="' + escapeHtml(srcUrl) + '" class="rep-img-thumb" alt="">'
                        : '<span class="rep-img-empty">بدون تصویر</span>';
                } else {
                    preview = sv
                        ? '<span class="rep-file-name" title="' + escapeHtml(sv) + '">📎 ' + escapeHtml(variantFileBase(sv)) + '</span>'
                        : '<span class="rep-img-empty">بدون فایل</span>';
                }
                var btnLbl = isImg
                    ? (n > 1 ? 'تصویر ' + (s + 1) : 'انتخاب تصویر')
                    : (n > 1 ? 'فایل ' + (s + 1) : 'انتخاب فایل');
                slots += '<label class="rep-img-pick' + (isImg ? '' : ' rep-file-pick') + '">' +
                    preview +
                    '<input type="hidden" name="' + hName + '" value="' + escapeHtml(sv) + '">' +
                    '<input type="file" name="' + fName + '"' + (accept ? ' accept="' + accept + '"' : '') + ' ' +
                    'onchange="repeaterImagePreview(this)" style="display:none">' +
                    '<span class="rep-img-btn">' + btnLbl + '</span>' +
                    '</label>';
            }
            cells += '<td data-col="' + escapeHtml(c.label) + '" class="rep-img-cell">' +
                slots + '</td>';
            return;
        }

        // Dropdown column (predefined list + optional custom value) — from the
        // variant-schema feature: pick from a ready list OR enter a custom value.
        if (c.type === 'select') {
            cells += '<td data-col="' + escapeHtml(c.label) + '">' +
                buildSelectCell(cName, c, cv) + '</td>';
            return;
        }

        var ph = c.placeholder ? ' placeholder="' + escapeHtml(c.placeholder) + '"' : '';

        // Long text.
        if (c.type === 'textarea') {
            cells += '<td data-col="' + escapeHtml(c.label) + '">' +
                '<textarea name="' + cName + '" class="input rep-textarea" rows="2"' + ph + '>' +
                escapeHtml(cv) + '</textarea></td>';
            return;
        }

        // Yes/No toggle stored as 1/0.
        if (c.type === 'bool') {
            var checked = (cv === 1 || cv === '1' || cv === true || cv === 'on') ? ' checked' : '';
            cells += '<td data-col="' + escapeHtml(c.label) + '" class="rep-bool-cell">' +
                '<input type="hidden" name="' + cName + '" value="' + (checked ? '1' : '0') + '">' +
                '<input type="checkbox" class="rep-bool"' + checked +
                ' onchange="repeaterBoolToggle(this)"></td>';
            return;
        }

        // Colour picker (keeps a text mirror so any hex/value is preserved).
        if (c.type === 'color') {
            var colVal = cv && /^#?[0-9a-fA-F]{3,8}$/.test(cv) ? (cv[0] === '#' ? cv : '#' + cv) : '#000000';
            cells += '<td data-col="' + escapeHtml(c.label) + '" class="rep-color-cell">' +
                '<input type="hidden" name="' + cName + '" value="' + escapeHtml(cv) + '">' +
                '<input type="color" class="rep-color" value="' + escapeHtml(colVal) + '"' +
                ' oninput="repeaterColorInput(this)">' +
                '<input type="text" class="input rep-color-text"' + ph + ' value="' + escapeHtml(cv) + '"' +
                ' oninput="repeaterColorText(this)"></td>';
            return;
        }

        // number / url / date / text — a plain typed input with the matching type.
        var cType = 'text';
        var stepAttr = '';
        if (c.type === 'number') { cType = 'number'; stepAttr = ' step="any"'; }
        else if (c.type === 'url') { cType = 'url'; }
        else if (c.type === 'date') { cType = 'date'; }
        cells += '<td data-col="' + escapeHtml(c.label) + '">' +
            '<input type="' + cType + '"' + stepAttr + ph +
            ' name="' + cName + '" class="input" value="' + escapeHtml(cv) + '"></td>';
    });
    cells += '<td class="rep-actions">' +
        '<button type="button" class="btn btn-ghost rep-del" title="حذف ردیف" ' +
        'onclick="repeaterDelRow(this)">×</button></td>';
    return '<tr class="rep-row">' + cells + '</tr>';
}

// Show a thumbnail preview when a per-variant image file is chosen.
window.repeaterImagePreview = function (input) {
    var cell = input.closest('.rep-img-cell');
    if (!cell || !input.files || !input.files.length) return;
    var file = input.files[0];
    var url = URL.createObjectURL(file);
    var img = cell.querySelector('.rep-img-thumb');
    var empty = cell.querySelector('.rep-img-empty');
    if (!img) {
        img = document.createElement('img');
        img.className = 'rep-img-thumb';
        var pick = cell.querySelector('.rep-img-pick');
        pick.insertBefore(img, pick.firstChild);
    }
    if (empty) empty.remove();
    img.src = url;
};

// When a select cell changes: either copy the chosen option into the hidden
// value field, or (if "مقدار دلخواه…") reveal the free-text box.
window.repeaterSelectChange = function (sel) {
    var td = sel.closest('td');
    var hidden = td.querySelector('input[type=hidden]');
    var custom = td.querySelector('.rep-custom');
    if (sel.value === CUSTOM_OPT) {
        if (custom) { custom.style.display = ''; custom.focus(); hidden.value = custom.value || ''; }
    } else {
        if (custom) custom.style.display = 'none';
        hidden.value = sel.value;
    }
};

// While typing a custom value, keep the hidden submitted value in sync.
window.repeaterCustomInput = function (inp) {
    var td = inp.closest('td');
    var hidden = td.querySelector('input[type=hidden]');
    if (hidden) hidden.value = inp.value;
};

// Yes/No cell: mirror the checkbox state into the submitted hidden value.
window.repeaterBoolToggle = function (cb) {
    var td = cb.closest('td');
    var hidden = td.querySelector('input[type=hidden]');
    if (hidden) hidden.value = cb.checked ? '1' : '0';
};

// Colour cell: native picker → hidden + text mirror.
window.repeaterColorInput = function (inp) {
    var td = inp.closest('td');
    var hidden = td.querySelector('input[type=hidden]');
    var text = td.querySelector('.rep-color-text');
    if (hidden) hidden.value = inp.value;
    if (text) text.value = inp.value;
};

// Colour cell: free-text → hidden + (when a valid hex) the native picker.
window.repeaterColorText = function (inp) {
    var td = inp.closest('td');
    var hidden = td.querySelector('input[type=hidden]');
    var picker = td.querySelector('.rep-color');
    if (hidden) hidden.value = inp.value;
    if (picker && /^#?[0-9a-fA-F]{3,8}$/.test(inp.value)) {
        picker.value = inp.value[0] === '#' ? inp.value : '#' + inp.value;
    }
};

// The file-format catalogue, mirrored from PHP variant_file_formats() and
// injected as window.VARIANT_FILE_FORMATS. Falls back to a small built-in list
// so the builder still works even if the server didn't inject it.
function variantFileFormats() {
    if (window.VARIANT_FILE_FORMATS && typeof window.VARIANT_FILE_FORMATS === 'object') {
        return window.VARIANT_FILE_FORMATS;
    }
    return {
        jpg: { exts: ['jpg', 'jpeg'], label: 'JPG', kind: 'image' },
        png: { exts: ['png'], label: 'PNG', kind: 'image' },
        webp: { exts: ['webp'], label: 'WEBP', kind: 'image' },
        gif: { exts: ['gif'], label: 'GIF', kind: 'image' },
        pdf: { exts: ['pdf'], label: 'PDF', kind: 'file' },
        doc: { exts: ['doc', 'docx'], label: 'Word', kind: 'file' },
        xls: { exts: ['xls', 'xlsx'], label: 'Excel', kind: 'file' },
        ppt: { exts: ['ppt', 'pptx'], label: 'PowerPoint', kind: 'file' },
        txt: { exts: ['txt'], label: 'Text', kind: 'file' },
        zip: { exts: ['zip'], label: 'ZIP', kind: 'file' },
        rar: { exts: ['rar'], label: 'RAR', kind: 'file' },
        mp4: { exts: ['mp4'], label: 'MP4 ویدیو', kind: 'file' },
        mp3: { exts: ['mp3'], label: 'MP3 صوت', kind: 'file' }
    };
}

// Build an HTML accept="" string from a list of format keys. For image columns
// with no narrowing we accept any image; otherwise we map each format → its
// extensions (".pdf,.doc,…").
function variantFormatsToAccept(formats, isImage) {
    var cat = variantFileFormats();
    if (!Array.isArray(formats) || !formats.length) {
        return isImage ? 'image/*' : '';
    }
    var parts = [];
    formats.forEach(function (f) {
        var def = cat[f];
        if (def && Array.isArray(def.exts)) {
            def.exts.forEach(function (e) { parts.push('.' + e); });
        }
    });
    return parts.join(',');
}

// A short, friendly basename for a stored file url (drops the random prefix).
function variantFileBase(url) {
    var s = String(url || '');
    var i = s.lastIndexOf('/');
    return i >= 0 ? s.slice(i + 1) : s;
}

// Find a variant schema definition by id (from window.VARIANT_SCHEMAS).
function findVariantSchema(id) {
    id = parseInt(id, 10);
    var all = window.VARIANT_SCHEMAS || [];
    for (var i = 0; i < all.length; i++) {
        if (parseInt(all[i].id, 10) === id) return all[i];
    }
    return null;
}

// Compute the variants table columns for a chosen schema id. Mirrors the PHP
// variant_schema_columns(): custom fields first, then the universal
// stock / price-diff / image columns. Falls back to legacy columns when no
// (valid) schema is selected.
function variantColumnsForSchema(id) {
    var legacy = [
        { key: 'color', label: 'رنگ', type: 'text' },
        { key: 'size', label: 'سایز', type: 'text' },
        { key: 'sku', label: 'کد (SKU)', type: 'text' },
        { key: 'stock', label: 'موجودی', type: 'number' },
        { key: 'price_diff', label: 'اختلاف قیمت (+/−)', type: 'number' },
        { key: 'image', label: 'تصویر این تنوع', type: 'image' }
    ];
    var schema = findVariantSchema(id);
    if (!schema || !schema.fields || !schema.fields.length) return legacy;
    var reserved = { stock: 1, price_diff: 1, image: 1 };
    var cols = [];
    schema.fields.forEach(function (f) {
        if (reserved[f.key]) return;
        cols.push(f);
    });
    cols.push({ key: 'stock', label: 'موجودی', type: 'number' });
    cols.push({ key: 'price_diff', label: 'اختلاف قیمت (+/−)', type: 'number' });
    cols.push({ key: 'image', label: 'تصویر این تنوع', type: 'image' });
    return cols;
}

// Append the universal stock / price-diff columns to a list of seller-built
// custom columns (mirrors PHP variant_columns_with_builtins()).
function variantColumnsWithBuiltins(customCols) {
    var reserved = { stock: 1, price_diff: 1 };
    var cols = [];
    (customCols || []).forEach(function (c) {
        if (c && c.key && reserved[c.key]) return;
        cols.push(c);
    });
    cols.push({ key: 'stock', label: 'موجودی', type: 'number' });
    cols.push({ key: 'price_diff', label: 'اختلاف قیمت (+/−)', type: 'number' });
    return cols;
}

// Render a full repeater field (a labelled table + "add row" button).
// For the variants table (def.builder), the seller builds the columns INLINE
// via "+ افزودن ویژگی" (each attribute = a column with a type & name); those
// custom columns are kept in `customCols` and persisted as attr[_variant_cols].
function buildRepeaterField(fieldKey, def, rows, schemaId, customCols) {
    var cols;
    if (def.builder) {
        // Seller-defined columns + the universal stock/price-diff.
        customCols = Array.isArray(customCols) ? customCols : [];
        cols = variantColumnsWithBuiltins(customCols);
    } else if (def.dynamic_columns) {
        cols = variantColumnsForSchema(schemaId || 0);
    } else {
        cols = def.columns || [];
    }
    rows = Array.isArray(rows) ? rows : [];

    var head = '';
    cols.forEach(function (c) { head += '<th>' + escapeHtml(c.label) + '</th>'; });
    head += '<th></th>';

    var body = '';
    rows.forEach(function (r, i) {
        body += buildRepeaterRow(fieldKey, i, cols, r);
    });
    // Always show at least one empty row to invite input.
    if (!rows.length) {
        body += buildRepeaterRow(fieldKey, 0, cols, {});
    }

    var colsJson = encodeURIComponent(JSON.stringify(cols));
    var dynAttr = (def.dynamic_columns || def.builder) ? ' data-dynamic="1"' : '';
    var builderAttr = def.builder ? ' data-builder="1"' : '';
    var customJson = encodeURIComponent(JSON.stringify(customCols || []));
    var showWhen = def.show_when ? ' data-show-when="' + escapeHtml(def.show_when) + '"' : '';
    var html = '<div class="field rep-field" data-fieldkey="' + escapeHtml(fieldKey) +
        '" data-cols="' + colsJson + '"' + dynAttr + builderAttr +
        ' data-custom-cols="' + customJson + '"' + showWhen + '>' +
        '<label>' + escapeHtml(def.label) +
        // پس‌کد badge: when a (variants) repeater holds more than one row, the
        // product effectively has variants/post-codes and needs a photo per row.
        (def.dynamic_columns || def.builder ? ' <span class="rep-pscode-badge" style="display:none"></span>' : '') +
        '</label>';
    if (def.hint) {
        html += '<div class="field-hint">' + escapeHtml(def.hint) + '</div>';
    }

    // Inline attribute builder bar: lists the seller's columns as removable
    // chips + an "افزودن ویژگی" button that opens the type/name picker.
    if (def.builder) {
        // Hidden field that submits the column definitions with the product.
        html += '<input type="hidden" class="rep-cols-input" name="attr[_variant_cols]" value="' +
            escapeHtml(JSON.stringify(customCols || [])) + '">';
        html += '<div class="vb-bar">';
        (customCols || []).forEach(function (c, i) {
            html += '<span class="vb-chip" data-idx="' + i + '">' +
                '<span class="vb-chip-type">' + escapeHtml(variantTypeLabel(c.type)) + '</span>' +
                escapeHtml(c.label) +
                '<button type="button" class="vb-chip-x" title="حذف ویژگی" onclick="variantRemoveAttr(this)">×</button>' +
                '</span>';
        });
        html += '<button type="button" class="btn btn-ghost vb-add" onclick="variantOpenAddAttr(this)">+ افزودن ویژگی</button>';
        html += '</div>';
        // Inline picker panel (hidden until "افزودن ویژگی" is clicked).
        html += variantAttrPickerMarkup();
    }

    html += '<div class="rep-wrap"><table class="rep-table"><thead><tr>' + head +
        '</tr></thead><tbody class="rep-body">' + body + '</tbody></table></div>' +
        '<button type="button" class="btn btn-ghost rep-add" onclick="repeaterAddRow(this)">+ افزودن ردیف (تنوع)</button>' +
        '</div>';
    return html;
}

// Human label for a variant attribute type (used on chips).
function variantTypeLabel(t) {
    switch (t) {
        case 'number': return 'عدد';
        case 'textarea': return 'متن بلند';
        case 'url': return 'لینک';
        case 'date': return 'تاریخ';
        case 'color': return 'رنگ';
        case 'bool': return 'بله/خیر';
        case 'select': return 'لیست';
        case 'image': return 'تصویر';
        case 'file': return 'فایل';
        default: return 'متن';
    }
}

// Build the checkbox grid for the file-format picker (شناخته‌شده‌ها). When
// `imageOnly` is true only the image formats are offered (for an image column).
function variantFormatCheckboxes(imageOnly, defaults) {
    var cat = variantFileFormats();
    defaults = defaults || {};
    var html = '<div class="vb-formats">';
    Object.keys(cat).forEach(function (k) {
        var def = cat[k];
        var isImg = (def.kind === 'image');
        if (imageOnly && !isImg) return;
        var checked = defaults[k] ? ' checked' : '';
        html += '<label class="vb-fmt"><input type="checkbox" class="vb-fmt-cb" value="' +
            escapeHtml(k) + '"' + checked + '> ' + escapeHtml(def.label) + '</label>';
    });
    html += '</div>';
    return html;
}

// Markup for the inline "add attribute" picker panel:
// type + name + placeholder + (per-type) options / image-count / file-formats.
function variantAttrPickerMarkup() {
    return '' +
    '<div class="vb-picker" style="display:none">' +
        '<div class="vb-picker-row">' +
            '<label class="vb-lbl">نوع ویژگی</label>' +
            '<select class="input vb-type" onchange="variantPickerTypeChange(this)">' +
                '<optgroup label="نوشتاری">' +
                    '<option value="text">متن کوتاه — مثل رنگ، جنس</option>' +
                    '<option value="textarea">متن بلند — توضیح چند خطی</option>' +
                    '<option value="number">عدد — مثل وزن، تعداد</option>' +
                    '<option value="url">لینک (URL)</option>' +
                    '<option value="date">تاریخ</option>' +
                    '<option value="color">رنگ (انتخابگر رنگ)</option>' +
                    '<option value="bool">بله/خیر (تیک)</option>' +
                '</optgroup>' +
                '<optgroup label="انتخابی">' +
                    '<option value="select">لیست انتخابی + مقدار دلخواه</option>' +
                '</optgroup>' +
                '<optgroup label="فایل / رسانه">' +
                    '<option value="image">تصویر — یک یا چند عکس</option>' +
                    '<option value="file">فایل — PDF، Word، ZIP و…</option>' +
                '</optgroup>' +
            '</select>' +
        '</div>' +
        '<div class="vb-picker-row">' +
            '<label class="vb-lbl">نام ویژگی</label>' +
            '<input type="text" class="input vb-name" placeholder="مثلاً: رنگ، سایز، جنس، حجم…">' +
        '</div>' +
        '<div class="vb-picker-row vb-ph-row">' +
            '<label class="vb-lbl">راهنمای داخل فیلد (اختیاری)</label>' +
            '<input type="text" class="input vb-ph" placeholder="متن راهنما برای کاربر؛ مثلاً: کد رنگ را وارد کنید">' +
        '</div>' +
        '<div class="vb-picker-row vb-req-row">' +
            '<label class="vb-chk"><input type="checkbox" class="vb-req"> پر کردن این فیلد الزامی است</label>' +
        '</div>' +
        '<div class="vb-picker-row vb-opts-row" style="display:none">' +
            '<label class="vb-lbl">گزینه‌های لیست</label>' +
            '<input type="text" class="input vb-opts" placeholder="گزینه‌ها را با ویرگول جدا کنید: آبی، قرمز، زرد">' +
        '</div>' +
        '<div class="vb-picker-row vb-allowcustom-row" style="display:none">' +
            '<label class="vb-chk"><input type="checkbox" class="vb-allowcustom" checked> اجازهٔ وارد کردن مقدار دلخواه خارج از لیست</label>' +
        '</div>' +
        '<div class="vb-picker-row vb-imgcount-row" style="display:none">' +
            '<label class="vb-lbl">تعداد تصویر برای هر تنوع</label>' +
            '<input type="number" class="input vb-imgcount" min="1" max="10" value="1">' +
        '</div>' +
        '<div class="vb-picker-row vb-imgformats-row" style="display:none">' +
            '<label class="vb-lbl">فرمت‌های مجاز تصویر</label>' +
            variantFormatCheckboxes(true, { jpg: 1, png: 1, webp: 1, gif: 1 }) +
        '</div>' +
        '<div class="vb-picker-row vb-fileformats-row" style="display:none">' +
            '<label class="vb-lbl">فرمت‌های مجاز فایل (یک یا چند مورد)</label>' +
            variantFormatCheckboxes(false, { pdf: 1 }) +
        '</div>' +
        '<div class="vb-picker-row vb-filecount-row" style="display:none">' +
            '<label class="vb-lbl">تعداد فایل برای هر تنوع</label>' +
            '<input type="number" class="input vb-filecount" min="1" max="10" value="1">' +
        '</div>' +
        '<div class="vb-picker-actions">' +
            '<button type="button" class="btn btn-primary vb-confirm" onclick="variantConfirmAddAttr(this)">افزودن</button>' +
            '<button type="button" class="btn btn-ghost vb-cancel" onclick="variantCancelAddAttr(this)">انصراف</button>' +
        '</div>' +
    '</div>';
}

// Update the "پس‌کد" badge for a dynamic (variants) repeater: when there is
// more than one variant row, show that the product has post-codes and that a
// separate image is needed for each variant (blue/red/yellow, etc.).
function updatePsCodeBadge(field) {
    if (!field) return;
    var badge = field.querySelector('.rep-pscode-badge');
    if (!badge) return;
    var count = field.querySelectorAll('.rep-body .rep-row').length;
    // Count rows that actually carry some value (ignore empty starter row).
    var filled = 0;
    field.querySelectorAll('.rep-body .rep-row').forEach(function (tr) {
        var has = false;
        tr.querySelectorAll('input[type=text],input[type=number],input[type=hidden],select').forEach(function (inp) {
            if (inp.value && String(inp.value).trim() !== '') has = true;
        });
        if (has) filled++;
    });
    var n = Math.max(count, filled);
    if (n > 1) {
        badge.textContent = '🏷️ این محصول پس‌کد دارد (' + n + ' تنوع) — برای هر تنوع یک تصویر مجزا بارگذاری کنید';
        badge.style.display = 'inline-block';
    } else {
        badge.style.display = 'none';
    }
}

// Collect the current rows of a repeater field as an array of value maps, so
// we can preserve typed data when rebuilding columns after a schema change.
function repeaterCollectRows(field) {
    var fieldKey = field.getAttribute('data-fieldkey');
    var out = [];
    field.querySelectorAll('.rep-body .rep-row').forEach(function (tr) {
        var row = {};
        tr.querySelectorAll('input,select,textarea').forEach(function (inp) {
            if (!inp.name) return;
            // Skip the file inputs (they carry no preservable value).
            if (inp.type === 'file') return;
            // Multi-image hidden: attr[fieldKey][idx][col][slot]
            var mi = inp.name.match(/^attr\[[^\]]+\]\[\d+\]\[([^\]]+)\]\[(\d+)\]$/);
            if (mi) {
                var ckey = mi[1], slot = parseInt(mi[2], 10);
                if (!Array.isArray(row[ckey])) row[ckey] = [];
                row[ckey][slot] = inp.value;
                return;
            }
            // Scalar cell: attr[fieldKey][idx][col]
            var m = inp.name.match(/^attr\[[^\]]+\]\[\d+\]\[([^\]]+)\]$/);
            if (m) row[m[1]] = inp.value;
        });
        out.push(row);
    });
    return out;
}

// Rebuild the variants table when the chosen schema changes, keeping any
// values the user already typed (matched by column key).
window.onVariantSchemaChange = function (sel) {
    var which = sel.getAttribute('data-which') || 'add';
    var box = document.getElementById(which + '_attr');
    if (!box) return;
    var field = box.querySelector('.rep-field[data-dynamic="1"]');
    if (!field) return;
    var fieldKey = field.getAttribute('data-fieldkey');
    var existing = repeaterCollectRows(field);
    var def = { label: field.querySelector('label').textContent, dynamic_columns: true,
        hint: (field.querySelector('.field-hint') || {}).textContent || '' };
    var html = buildRepeaterField(fieldKey, def, existing, sel.value);
    field.outerHTML = html;
    // Re-bind the badge updater to the freshly-rebuilt field.
    var box = document.getElementById(which + '_attr');
    var fresh = box ? box.querySelector('.rep-field[data-dynamic="1"]') : null;
    if (fresh) {
        updatePsCodeBadge(fresh);
        fresh.addEventListener('input', function () { updatePsCodeBadge(fresh); });
        fresh.addEventListener('change', function () { updatePsCodeBadge(fresh); });
    }
};

// ---------------------------------------------------------------------------
// Inline variant ATTRIBUTE BUILDER
// The seller defines the variant columns themselves (نوع + نام) right on the
// product form, so every product can have exactly the fields it needs.
// ---------------------------------------------------------------------------

// Read the seller's current custom columns from a builder repeater field.
function variantReadCustomCols(field) {
    try {
        return JSON.parse(decodeURIComponent(field.getAttribute('data-custom-cols') || '[]')) || [];
    } catch (e) { return []; }
}

// Turn a label into a safe key (mirrors PHP variant_schema_slug, simplified).
function variantSlug(s) {
    s = String(s || '').trim().toLowerCase();
    // Keep unicode letters/digits; everything else → underscore.
    s = s.replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_+|_+$/g, '');
    return s;
}

// Rebuild a builder repeater field after its columns changed, preserving rows.
function variantRebuildField(field, newCustomCols) {
    var which = field.closest('[id$="_attr"]');
    which = which ? which.id.replace('_attr', '') : 'add';
    var fieldKey = field.getAttribute('data-fieldkey');
    var existing = repeaterCollectRows(field);
    var label = (field.querySelector('label') || {}).childNodes
        ? field.querySelector('label').childNodes[0].nodeValue.trim()
        : 'تنوع محصول';
    var hint = (field.querySelector('.field-hint') || {}).textContent || '';
    var def = { label: label, dynamic_columns: true, builder: true, hint: hint,
        show_when: field.getAttribute('data-show-when') || '' };
    var html = buildRepeaterField(fieldKey, def, existing, 0, newCustomCols);
    field.outerHTML = html;

    var box = document.getElementById(which + '_attr');
    var fresh = box ? box.querySelector('.rep-field[data-builder="1"]') : null;
    if (fresh) {
        updatePsCodeBadge(fresh);
        fresh.addEventListener('input', function () { updatePsCodeBadge(fresh); });
        fresh.addEventListener('change', function () { updatePsCodeBadge(fresh); });
    }
    return fresh;
}

// Open the inline "افزودن ویژگی" picker for this builder field.
window.variantOpenAddAttr = function (btn) {
    var field = btn.closest('.rep-field');
    if (!field) return;
    var picker = field.querySelector('.vb-picker');
    if (!picker) return;
    // reset inputs
    picker.querySelector('.vb-type').value = 'text';
    picker.querySelector('.vb-name').value = '';
    var ph = picker.querySelector('.vb-ph'); if (ph) ph.value = '';
    var req = picker.querySelector('.vb-req'); if (req) req.checked = false;
    var opts = picker.querySelector('.vb-opts'); if (opts) opts.value = '';
    var ac = picker.querySelector('.vb-allowcustom'); if (ac) ac.checked = true;
    var ic = picker.querySelector('.vb-imgcount'); if (ic) ic.value = '1';
    var fc = picker.querySelector('.vb-filecount'); if (fc) fc.value = '1';
    // reset format checkboxes to defaults (image: jpg/png/webp/gif, file: pdf)
    picker.querySelectorAll('.vb-imgformats-row .vb-fmt-cb').forEach(function (cb) {
        cb.checked = (['jpg', 'png', 'webp', 'gif'].indexOf(cb.value) !== -1);
    });
    picker.querySelectorAll('.vb-fileformats-row .vb-fmt-cb').forEach(function (cb) {
        cb.checked = (cb.value === 'pdf');
    });
    variantPickerTypeChange(picker.querySelector('.vb-type'));
    picker.style.display = '';
    picker.querySelector('.vb-name').focus();
};

// Toggle the conditional rows for the chosen type. Placeholder + required are
// always available; options(+allow custom) for select; image count/formats for
// image; file formats/count for file.
window.variantPickerTypeChange = function (sel) {
    var picker = sel.closest('.vb-picker');
    if (!picker) return;
    var t = sel.value;
    var show = function (cls, on) {
        var el = picker.querySelector(cls);
        if (el) el.style.display = on ? '' : 'none';
    };
    show('.vb-opts-row', t === 'select');
    show('.vb-allowcustom-row', t === 'select');
    show('.vb-imgcount-row', t === 'image');
    show('.vb-imgformats-row', t === 'image');
    show('.vb-fileformats-row', t === 'file');
    show('.vb-filecount-row', t === 'file');
    // Placeholder makes sense for typed fields, not for bool/image/file.
    show('.vb-ph-row', (t !== 'bool' && t !== 'image' && t !== 'file'));
};

// Cancel the picker without adding.
window.variantCancelAddAttr = function (btn) {
    var picker = btn.closest('.vb-picker');
    if (picker) picker.style.display = 'none';
};

// Confirm: build a column def from the picker, append it, rebuild the table.
window.variantConfirmAddAttr = function (btn) {
    var field = btn.closest('.rep-field');
    var picker = btn.closest('.vb-picker');
    if (!field || !picker) return;
    var type = picker.querySelector('.vb-type').value || 'text';
    var label = (picker.querySelector('.vb-name').value || '').trim();
    if (!label) { picker.querySelector('.vb-name').focus(); return; }

    var cols = variantReadCustomCols(field);
    // Build a unique key.
    var base = variantSlug(label) || 'col';
    var key = base, i = 2, used = {};
    cols.forEach(function (c) { used[c.key] = 1; });
    used['stock'] = 1; used['price_diff'] = 1;
    while (used[key]) { key = base + i; i++; }

    var col = { key: key, label: label, type: type };

    // Placeholder (راهنما) — for typed fields only.
    if (type !== 'bool' && type !== 'image' && type !== 'file') {
        var phEl = picker.querySelector('.vb-ph');
        var phVal = phEl ? (phEl.value || '').trim() : '';
        if (phVal) col.placeholder = phVal.slice(0, 120);
    }
    // Required flag.
    var reqEl = picker.querySelector('.vb-req');
    if (reqEl && reqEl.checked) col.required = 1;

    if (type === 'select') {
        var raw = (picker.querySelector('.vb-opts').value || '').split(/[\n,]+/);
        var opts = [];
        raw.forEach(function (o) { o = o.trim(); if (o && opts.indexOf(o) === -1) opts.push(o); });
        col.options = opts;
        var acEl = picker.querySelector('.vb-allowcustom');
        col.allow_custom = (acEl && acEl.checked) ? 1 : 0;
    }
    if (type === 'image') {
        var n = parseInt(picker.querySelector('.vb-imgcount').value, 10) || 1;
        if (n < 1) n = 1; if (n > 10) n = 10;
        col.images_count = n;
        var imgFmts = [];
        picker.querySelectorAll('.vb-imgformats-row .vb-fmt-cb').forEach(function (cb) {
            if (cb.checked) imgFmts.push(cb.value);
        });
        col.formats = imgFmts.length ? imgFmts : ['jpg', 'png', 'webp', 'gif'];
    }
    if (type === 'file') {
        var fileFmts = [];
        picker.querySelectorAll('.vb-fileformats-row .vb-fmt-cb').forEach(function (cb) {
            if (cb.checked) fileFmts.push(cb.value);
        });
        if (!fileFmts.length) { alert('حداقل یک فرمت فایل را انتخاب کنید.'); return; }
        col.formats = fileFmts;
        var fn = parseInt(picker.querySelector('.vb-filecount').value, 10) || 1;
        if (fn < 1) fn = 1; if (fn > 10) fn = 10;
        col.files_count = fn;
    }
    cols.push(col);
    variantRebuildField(field, cols);
};

// Remove a custom attribute (chip ×) and rebuild the table.
window.variantRemoveAttr = function (btn) {
    var chip = btn.closest('.vb-chip');
    var field = btn.closest('.rep-field');
    if (!chip || !field) return;
    var idx = parseInt(chip.getAttribute('data-idx'), 10);
    var cols = variantReadCustomCols(field);
    if (idx >= 0 && idx < cols.length) cols.splice(idx, 1);
    variantRebuildField(field, cols);
};

// Re-index all row inputs of a repeater after add/remove so names stay sequential.
function repeaterReindex(field) {
    var rows = field.querySelectorAll('.rep-body .rep-row');
    rows.forEach(function (tr, i) {
        tr.querySelectorAll('input,select,textarea').forEach(function (inp) {
            if (!inp.name) return;
            // attr[fieldKey][OLD][col] → attr[fieldKey][i][col]
            inp.name = inp.name.replace(/^(attr\[[^\]]+\])\[\d+\]/, '$1[' + i + ']');
            // media_variant[fieldKey][OLD] → media_variant[fieldKey][i]
            inp.name = inp.name.replace(/^(media_variant\[[^\]]+\])\[\d+\]/, '$1[' + i + ']');
        });
    });
}

window.repeaterAddRow = function (btn) {
    var field = btn.closest('.rep-field');
    if (!field) return;
    var fieldKey = field.getAttribute('data-fieldkey');
    var cols = [];
    try { cols = JSON.parse(decodeURIComponent(field.getAttribute('data-cols') || '[]')); }
    catch (e) { cols = []; }
    var body = field.querySelector('.rep-body');
    var idx = body.querySelectorAll('.rep-row').length;
    body.insertAdjacentHTML('beforeend', buildRepeaterRow(fieldKey, idx, cols, {}));
    repeaterReindex(field);
    updatePsCodeBadge(field);
};

window.repeaterDelRow = function (btn) {
    var field = btn.closest('.rep-field');
    var tr = btn.closest('.rep-row');
    if (!tr || !field) return;
    var body = field.querySelector('.rep-body');
    // Keep at least one row present.
    if (body.querySelectorAll('.rep-row').length <= 1) {
        tr.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        updatePsCodeBadge(field);
        return;
    }
    tr.parentNode.removeChild(tr);
    repeaterReindex(field);
    updatePsCodeBadge(field);
};

// Build the variant-schema picker: a dropdown listing the merchant's saved
// variant templates (clothing, cosmetics, …) plus a link to manage them. The
// chosen id is submitted as attr[_variant_schema] and rebuilds the variants
// table columns on change.
function buildVariantSchemaField(which, f, schemaId) {
    var all = window.VARIANT_SCHEMAS || [];
    var name = 'attr[_variant_schema]';
    var showWhen = f.show_when ? ' data-show-when="' + escapeHtml(f.show_when) + '"' : '';
    var html = '<div class="field"' + showWhen + '><label>' + escapeHtml(f.label) +
        ' <a href="variant_schemas.php" target="_blank" style="font-size:12px;font-weight:normal;margin-right:6px;color:#3b82f6;">مدیریت قالب‌های تنوع</a></label>';
    html += '<select name="' + name + '" class="select" data-which="' + escapeHtml(which) +
        '" onchange="onVariantSchemaChange(this)">';
    var sel0 = (parseInt(schemaId, 10) || 0) === 0 ? ' selected' : '';
    html += '<option value="0"' + sel0 + '>پیش‌فرض (رنگ/سایز/SKU)</option>';
    all.forEach(function (s) {
        var sid = parseInt(s.id, 10);
        var sel = (sid === parseInt(schemaId, 10)) ? ' selected' : '';
        html += '<option value="' + sid + '"' + sel + '>' + escapeHtml(s.name) + '</option>';
    });
    html += '</select>';
    if (!all.length) {
        html += '<div class="field-hint">هنوز قالب تنوعی نساخته‌اید. از «مدیریت قالب‌های تنوع» یک قالب دلخواه (مثلاً «لباس» با ستون‌های رنگ، سایز، جنس) بسازید.</div>';
    } else if (f.hint) {
        html += '<div class="field-hint">' + escapeHtml(f.hint) + '</div>';
    }
    html += '</div>';
    return html;
}

// Render the attribute inputs for a modal ('add' | 'edit').
// `values` is an optional map of saved attribute values (used on edit).
// Show/hide fields that are only meaningful for specific product type(s).
// A field marked data-ptype-only="vpn,service" is visible only when the chosen
// type is in that list; otherwise it's hidden AND its inputs are disabled so
// they don't submit stale/irrelevant values.
function applyTypeVisibility(which, ptype) {
    var modal = document.getElementById(which + 'Modal');
    var scope = modal || document;
    var nodes = scope.querySelectorAll('[data-ptype-only]');
    nodes.forEach(function (el) {
        var allowed = (el.getAttribute('data-ptype-only') || '')
            .split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        var show = allowed.indexOf(ptype) !== -1;
        el.style.display = show ? '' : 'none';
        // toggle the disabled state of contained inputs so hidden fields don't post
        el.querySelectorAll('input,select,textarea').forEach(function (inp) {
            inp.disabled = !show;
        });
    });
}

// Update the placeholder text of shared fields (name/category/note) to match
// the example sentences declared for the chosen product type in product_types().
function applyTypeExamples(which, ptype) {
    var modal = document.getElementById(which + 'Modal');
    var scope = modal || document;
    var types = window.PRODUCT_TYPES || {};
    var ex = (types[ptype] && types[ptype].examples) || {};
    scope.querySelectorAll('[data-example]').forEach(function (el) {
        var key = el.getAttribute('data-example');
        if (ex[key]) el.setAttribute('placeholder', ex[key]);
    });
}

window.renderAttrFields = function (which, values) {
    values = values || {};
    var typeSel = document.getElementById(which + '_ptype');
    var box = document.getElementById(which + '_attr');
    if (!typeSel || !box) return;

    // First, toggle the static VPN-only fields for the selected type.
    applyTypeVisibility(which, typeSel.value);
    // Update placeholders of the shared name/category/note fields so the
    // examples always match the chosen product type (no stale "50GB" hints).
    applyTypeExamples(which, typeSel.value);

    var types = window.PRODUCT_TYPES || {};
    var def = types[typeSel.value];
    box.innerHTML = '';
    if (!def || !def.fields || !def.fields.length) return;

    // The currently-chosen variant schema id (drives the variants columns).
    var schemaId = parseInt(values['_variant_schema'] || 0, 10) || 0;
    // Per-product custom variant columns the seller built inline (_variant_cols).
    var customCols = [];
    if (values['_variant_cols']) {
        try {
            customCols = (typeof values['_variant_cols'] === 'string')
                ? JSON.parse(values['_variant_cols'])
                : values['_variant_cols'];
        } catch (e) { customCols = []; }
    }
    if (!Array.isArray(customCols)) customCols = [];

    def.fields.forEach(function (f) {
        var val = values[f.key];
        var name = 'attr[' + f.key + ']';

        // Variant schema picker: a dropdown of saved templates + manage link.
        if (f.type === 'variant_schema') {
            box.insertAdjacentHTML('beforeend', buildVariantSchemaField(which, f, schemaId));
            return;
        }

        // Repeater is self-contained (own table markup) — render & return.
        if (f.type === 'repeater') {
            box.insertAdjacentHTML('beforeend', buildRepeaterField(f.key, f, val, schemaId, customCols));
            return;
        }

        // Carriers multi-select: pick from the enabled (API-backed) carriers.
        if (f.type === 'carriers') {
            box.insertAdjacentHTML('beforeend', buildCarriersField(f, val));
            return;
        }

        if (val === undefined || val === null) val = '';
        var html = '<div class="field"';
        if (f.show_when) html += ' data-show-when="' + escapeHtml(f.show_when) + '"';
        html += '><label>' + escapeHtml(f.label) + '</label>';

        if (f.type === 'textarea') {
            html += '<textarea name="' + name + '" class="textarea">' + escapeHtml(val) + '</textarea>';
        } else if (f.type === 'bool') {
            var checked = (val === '1' || val === 1 || val === true || val === 'on') ? 'checked' : '';
            // hidden 0 so unchecked still submits a value. The checkbox lives
            // INSIDE the <label>, so it has no previousElementSibling — use the
            // shared onBoolToggle() helper to find the hidden mirror by name
            // and (crucially) re-run applyShowWhen() so gated fields appear.
            html += '<input type="hidden" name="' + name + '" value="' + (checked ? '1' : '0') + '">';
            html += '<label style="display:flex;align-items:center;gap:8px;font-weight:400">' +
                '<input type="checkbox" value="1" ' + checked +
                ' data-bool-key="' + escapeHtml(f.key) + '"' +
                ' onchange="onBoolToggle(this)"> بله</label>';
        } else if (f.type === 'select') {
            html += '<select name="' + name + '" class="select">';
            var opts = f.options || {};
            Object.keys(opts).forEach(function (k) {
                var selected = (String(k) === String(val)) ? ' selected' : '';
                html += '<option value="' + escapeHtml(k) + '"' + selected + '>' +
                    escapeHtml(opts[k]) + '</option>';
            });
            html += '</select>';
        } else {
            var inputType = (f.type === 'number') ? 'number' : 'text';
            var stepAttr = (f.type === 'number') ? ' step="any"' : '';
            html += '<input type="' + inputType + '"' + stepAttr +
                ' name="' + name + '" class="input" value="' + escapeHtml(val) + '">';
        }
        if (f.hint) {
            html += '<div class="field-hint">' + escapeHtml(f.hint) + '</div>';
        }
        html += '</div>';
        box.insertAdjacentHTML('beforeend', html);
    });

    // Initialise the پس‌کد badge for any dynamic variants table, and keep it in
    // sync as the merchant types into / leaves variant cells.
    box.querySelectorAll('.rep-field[data-dynamic="1"]').forEach(function (field) {
        updatePsCodeBadge(field);
        field.addEventListener('input', function () { updatePsCodeBadge(field); });
        field.addEventListener('change', function () { updatePsCodeBadge(field); });
    });

    // Apply conditional visibility (e.g. variants table only when "has_variants").
    applyShowWhen(box);
};

// Build a checkbox multi-select of the merchant's enabled shipping carriers.
function buildCarriersField(f, val) {
    var carriers = window.SHIPPING_CARRIERS || {};
    var selected = {};
    // val may be an array, an object map, or a CSV string.
    if (Array.isArray(val)) {
        val.forEach(function (v) { selected[String(v)] = true; });
    } else if (val && typeof val === 'object') {
        Object.keys(val).forEach(function (k) { if (val[k]) selected[String(k)] = true; });
    } else if (typeof val === 'string' && val) {
        val.split(',').forEach(function (v) { selected[v.trim()] = true; });
    }

    var html = '<div class="field"><label>' + escapeHtml(f.label) + '</label>';
    if (f.hint) html += '<div class="field-hint">' + escapeHtml(f.hint) + '</div>';
    var keys = Object.keys(carriers);
    if (!keys.length) {
        html += '<div class="cat-empty" style="color:var(--mute);font-size:13px">' +
            'هنوز شرکت پستی فعالی ندارید. ابتدا در بخش «ارسال» شرکت‌های پستی را فعال کنید.</div>';
    } else {
        html += '<div class="cat-picker">';
        keys.forEach(function (code) {
            var ck = selected[code] ? ' checked' : '';
            html += '<label class="cat-chip"><input type="checkbox" name="attr[' + escapeHtml(f.key) +
                '][]" value="' + escapeHtml(code) + '"' + ck + '> <span>' +
                escapeHtml(carriers[code]) + '</span></label>';
        });
        html += '</div>';
    }
    html += '</div>';
    return html;
}

// Handle a bool checkbox toggle: mirror its state into the hidden input that
// carries the value on submit (the checkbox sits INSIDE the <label>, so it has
// no previousElementSibling), then re-run applyShowWhen() so any gated fields
// (variant schema picker + variants table) show/hide immediately.
window.onBoolToggle = function (cb) {
    var key = cb.getAttribute('data-bool-key');
    // Scope to the enclosing field group / modal so we update the right inputs.
    var scope = cb.closest('.modal-body') || cb.closest('.field') || document;
    // The hidden mirror is a sibling of the <label> within the same field block,
    // matched by its name attr[<key>] (robust regardless of DOM nesting).
    var field = cb.closest('.field') || scope;
    var hidden = field.querySelector('input[type=hidden][name="attr[' + key + ']"]');
    if (!hidden) {
        hidden = scope.querySelector('input[type=hidden][name="attr[' + key + ']"]');
    }
    if (hidden) hidden.value = cb.checked ? 1 : 0;
    applyShowWhen(cb.closest('.modal-body') || document);
};

// Show/hide any [data-show-when="boolKey"] block based on its gating checkbox,
// and disable inputs inside hidden blocks so they don't submit stale values.
window.applyShowWhen = function (scope) {
    scope = scope || document;
    scope.querySelectorAll('[data-show-when]').forEach(function (el) {
        var key = el.getAttribute('data-show-when');
        var gate = scope.querySelector('input[type=checkbox][data-bool-key="' + key + '"]');
        var on = gate ? gate.checked : false;
        el.style.display = on ? '' : 'none';
        el.querySelectorAll('input,select,textarea').forEach(function (inp) {
            inp.disabled = !on;
        });
    });
};

// --- Category multi-select helpers (Point 4) ---

// Parse a stored CSV ("a, b, c") into a clean array of names.
function parseCategoryCsv(csv) {
    return String(csv || '')
        .split(',')
        .map(function (s) { return s.trim(); })
        .filter(function (s) { return s.length > 0; });
}

// Tick the checkboxes in the given form's picker that match the stored CSV.
// Any stored category that has no checkbox yet (legacy free-text) gets one added.
window.preselectCategories = function (which, csv) {
    var picker = document.getElementById(which + '_cat_picker');
    if (!picker) return;
    var wanted = parseCategoryCsv(csv);
    // uncheck everything first
    picker.querySelectorAll('input[type=checkbox]').forEach(function (cb) { cb.checked = false; });
    wanted.forEach(function (name) {
        var existing = null;
        picker.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
            if (cb.value.toLowerCase() === name.toLowerCase()) existing = cb;
        });
        if (existing) {
            existing.checked = true;
        } else {
            appendCategoryChip(picker, name, true); // legacy/unknown category -> add as checked chip
        }
    });
};

// Build a new checked chip inside a picker.
function appendCategoryChip(picker, name, checked) {
    var empty = picker.querySelector('.cat-empty');
    if (empty) empty.remove();
    var label = document.createElement('label');
    label.className = 'cat-chip';
    var cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.name = 'category[]';
    cb.value = name;
    cb.checked = !!checked;
    var span = document.createElement('span');
    span.textContent = name;
    label.appendChild(cb);
    label.appendChild(document.createTextNode(' '));
    label.appendChild(span);
    picker.appendChild(label);
    return cb;
}

// "Add new category" button handler for add/edit forms.
window.addNewCategoryChip = function (which) {
    var inp = document.getElementById(which + '_cat_new');
    var picker = document.getElementById(which + '_cat_picker');
    if (!inp || !picker) return;
    var name = (inp.value || '').trim();
    if (!name) return;
    // if it already exists, just check it
    var found = null;
    picker.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
        if (cb.value.toLowerCase() === name.toLowerCase()) found = cb;
    });
    if (found) {
        found.checked = true;
    } else {
        appendCategoryChip(picker, name, true);
    }
    inp.value = '';
    inp.focus();
};

window.openEditModal = function (p) {
    document.getElementById('edit_id').value = p.id || '';
    document.getElementById('edit_name').value = p.name_product || '';
    var priceEl = document.getElementById('edit_price');
    priceEl.value = (window.formatMoney ? window.formatMoney(p.price_product || '') : (p.price_product || ''));
    document.getElementById('edit_volume').value = p.Volume_constraint || '';
    document.getElementById('edit_time').value = p.Service_time || '';
    preselectCategories('edit', p.category || '');
    document.getElementById('edit_agent').value = p.agent || '';
    document.getElementById('edit_note').value = p.note || '';

    var sel = document.getElementById('edit_panel');
    if (sel) {
        for (var i = 0; i < sel.options.length; i++) {
            sel.options[i].selected = sel.options[i].value === (p.Location || '');
        }
    }

    // Product type + attributes
    var ptypeSel = document.getElementById('edit_ptype');
    if (ptypeSel) {
        ptypeSel.value = p.product_type || 'vpn';
    }
    var attrs = {};
    if (p.attributes) {
        try { attrs = (typeof p.attributes === 'string') ? JSON.parse(p.attributes) : p.attributes; }
        catch (e) { attrs = {}; }
    }
    renderAttrFields('edit', attrs || {});

    // Point the "manage media" button at this product's media page (Audit-1).
    var mediaLink = document.getElementById('edit_media_link');
    if (mediaLink && p.id) {
        mediaLink.setAttribute('href', 'product_media.php?pid=' + encodeURIComponent(p.id));
    }

    openModal('editModal');
};

// Wire a click/drag-drop file picker zone (used by the in-form image uploader).
function wireDropZone(zoneId, inputId, pickedId) {
    var input = document.getElementById(inputId);
    var zone = document.getElementById(zoneId);
    var picked = document.getElementById(pickedId);
    if (!input || !zone) return;

    function showFiles(files) {
        if (!picked) return;
        picked.innerHTML = '';
        if (!files || !files.length) return;
        var head = document.createElement('div');
        head.className = 'media-pick-head';
        head.textContent = '✓ ' + files.length + ' فایل آمادهٔ آپلود است (پس از ذخیرهٔ محصول بارگذاری می‌شود)';
        picked.appendChild(head);
        var grid = document.createElement('div');
        grid.className = 'media-pick-grid';
        for (var i = 0; i < files.length && i < 12; i++) {
            var f = files[i];
            var cell = document.createElement('div');
            cell.className = 'media-pick-cell';
            if (/^image\//.test(f.type)) {
                var img = document.createElement('img');
                img.src = URL.createObjectURL(f);
                cell.appendChild(img);
            } else {
                var ic = document.createElement('div');
                ic.className = 'media-pick-ic';
                ic.textContent = /^video\//.test(f.type) ? '🎬' : (/^audio\//.test(f.type) ? '🎵' : '📄');
                cell.appendChild(ic);
            }
            var nm = document.createElement('div');
            nm.className = 'media-pick-name';
            nm.textContent = f.name;
            cell.appendChild(nm);
            grid.appendChild(cell);
        }
        picked.appendChild(grid);
    }
    input.addEventListener('change', function () { showFiles(input.files); });

    ['dragenter', 'dragover'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) {
            e.preventDefault(); e.stopPropagation();
            zone.style.borderColor = 'var(--accent)';
            zone.style.background = 'var(--accent-s, rgba(99,102,241,.08))';
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) {
            e.preventDefault(); e.stopPropagation();
            zone.style.borderColor = 'var(--bd)';
            zone.style.background = 'var(--sf2)';
        });
    });
    zone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            showFiles(input.files);
        }
    });
}

// Initialise the add-modal attribute fields + in-form image uploader on load.
document.addEventListener('DOMContentLoaded', function () {
    renderAttrFields('add');
    wireDropZone('addDropZone', 'addMediaInput', 'addMediaPicked');
});
