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

        // Per-variant image: file input (uploaded) + hidden keeps existing url.
        if (c.type === 'image') {
            var fName = 'media_variant[' + fieldKey + '][' + idx + ']';
            var thumbSrc = cv ? (/^https?:|^\//.test(cv) ? cv : '../' + cv) : '';
            var thumb = cv
                ? '<img src="' + escapeHtml(thumbSrc) + '" class="rep-img-thumb" alt="">'
                : '<span class="rep-img-empty">بدون تصویر</span>';
            cells += '<td data-col="' + escapeHtml(c.label) + '" class="rep-img-cell">' +
                '<input type="hidden" name="' + cName + '" value="' + escapeHtml(cv) + '">' +
                '<label class="rep-img-pick">' + thumb +
                '<input type="file" name="' + fName + '" accept="image/*" ' +
                'onchange="repeaterImagePreview(this)" style="display:none">' +
                '<span class="rep-img-btn">انتخاب تصویر</span></label></td>';
            return;
        }

        // Dropdown column (predefined list + optional custom value).
        if (c.type === 'select') {
            cells += '<td data-col="' + escapeHtml(c.label) + '">' +
                buildSelectCell(cName, c, cv) + '</td>';
            return;
        }

        var cType = (c.type === 'number') ? 'number' : 'text';
        var stepAttr = (c.type === 'number') ? ' step="any"' : '';
        cells += '<td data-col="' + escapeHtml(c.label) + '">' +
            '<input type="' + cType + '"' + stepAttr +
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
    var url = URL.createObjectURL(input.files[0]);
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

// Render a full repeater field (a labelled table + "add row" button).
// For the variants table (def.dynamic_columns), columns are derived from the
// currently-selected variant schema so they always match the product category.
function buildRepeaterField(fieldKey, def, rows, schemaId) {
    var cols = def.columns || [];
    if (def.dynamic_columns) {
        cols = variantColumnsForSchema(schemaId || 0);
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
    var dynAttr = def.dynamic_columns ? ' data-dynamic="1"' : '';
    var html = '<div class="field rep-field" data-fieldkey="' + escapeHtml(fieldKey) +
        '" data-cols="' + colsJson + '"' + dynAttr + '>' +
        '<label>' + escapeHtml(def.label) +
        // پس‌کد badge: when a (variants) repeater holds more than one row, the
        // product effectively has variants/post-codes and needs a photo per row.
        (def.dynamic_columns ? ' <span class="rep-pscode-badge" style="display:none"></span>' : '') +
        '</label>';
    if (def.hint) {
        html += '<div class="field-hint">' + escapeHtml(def.hint) + '</div>';
    }
    html += '<div class="rep-wrap"><table class="rep-table"><thead><tr>' + head +
        '</tr></thead><tbody class="rep-body">' + body + '</tbody></table></div>' +
        '<button type="button" class="btn btn-ghost rep-add" onclick="repeaterAddRow(this)">+ افزودن ردیف</button>' +
        '</div>';
    return html;
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
            // attr[fieldKey][idx][col]
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
    var html = '<div class="field"><label>' + escapeHtml(f.label) +
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
window.renderAttrFields = function (which, values) {
    values = values || {};
    var typeSel = document.getElementById(which + '_ptype');
    var box = document.getElementById(which + '_attr');
    if (!typeSel || !box) return;

    var types = window.PRODUCT_TYPES || {};
    var def = types[typeSel.value];
    box.innerHTML = '';
    if (!def || !def.fields || !def.fields.length) return;

    // The currently-chosen variant schema id (drives the variants columns).
    var schemaId = parseInt(values['_variant_schema'] || 0, 10) || 0;

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
            box.insertAdjacentHTML('beforeend', buildRepeaterField(f.key, f, val, schemaId));
            return;
        }

        if (val === undefined || val === null) val = '';
        var html = '<div class="field"><label>' + escapeHtml(f.label) + '</label>';

        if (f.type === 'textarea') {
            html += '<textarea name="' + name + '" class="textarea">' + escapeHtml(val) + '</textarea>';
        } else if (f.type === 'bool') {
            var checked = (val === '1' || val === 1 || val === true || val === 'on') ? 'checked' : '';
            // hidden 0 so unchecked still submits a value
            html += '<input type="hidden" name="' + name + '" value="0">';
            html += '<label style="display:flex;align-items:center;gap:8px;font-weight:400">' +
                '<input type="checkbox" value="1" ' + checked +
                ' onchange="this.previousElementSibling.value=this.checked?1:0"> بله</label>';
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
};

window.openEditModal = function (p) {
    document.getElementById('edit_id').value = p.id || '';
    document.getElementById('edit_name').value = p.name_product || '';
    var priceEl = document.getElementById('edit_price');
    priceEl.value = (window.formatMoney ? window.formatMoney(p.price_product || '') : (p.price_product || ''));
    document.getElementById('edit_volume').value = p.Volume_constraint || '';
    document.getElementById('edit_time').value = p.Service_time || '';
    document.getElementById('edit_cat').value = p.category || '';
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

    openModal('editModal');
};

// Initialise the add-modal attribute fields on load.
document.addEventListener('DOMContentLoaded', function () {
    renderAttrFields('add');
});
