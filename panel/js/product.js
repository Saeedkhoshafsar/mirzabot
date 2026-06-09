// ---------------------------------------------------------------------------
// Dynamic product form: render attribute fields based on the selected
// product_type (definitions injected as window.PRODUCT_TYPES).
// ---------------------------------------------------------------------------

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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

// Render a full repeater field (a labelled table + "add row" button).
function buildRepeaterField(fieldKey, def, rows) {
    var cols = def.columns || [];
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
    var html = '<div class="field rep-field" data-fieldkey="' + escapeHtml(fieldKey) +
        '" data-cols="' + colsJson + '">' +
        '<label>' + escapeHtml(def.label) + '</label>';
    if (def.hint) {
        html += '<div class="field-hint">' + escapeHtml(def.hint) + '</div>';
    }
    html += '<div class="rep-wrap"><table class="rep-table"><thead><tr>' + head +
        '</tr></thead><tbody class="rep-body">' + body + '</tbody></table></div>' +
        '<button type="button" class="btn btn-ghost rep-add" onclick="repeaterAddRow(this)">+ افزودن ردیف</button>' +
        '</div>';
    return html;
}

// Re-index all row inputs of a repeater after add/remove so names stay sequential.
function repeaterReindex(field) {
    var fieldKey = field.getAttribute('data-fieldkey');
    var rows = field.querySelectorAll('.rep-body .rep-row');
    rows.forEach(function (tr, i) {
        tr.querySelectorAll('input').forEach(function (inp) {
            // name = attr[fieldKey][OLD][col]  → replace OLD index with i
            inp.name = inp.name.replace(
                /^(attr\[[^\]]+\])\[\d+\]/,
                '$1[' + i + ']'
            );
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
};

window.repeaterDelRow = function (btn) {
    var field = btn.closest('.rep-field');
    var tr = btn.closest('.rep-row');
    if (!tr || !field) return;
    var body = field.querySelector('.rep-body');
    // Keep at least one row present.
    if (body.querySelectorAll('.rep-row').length <= 1) {
        tr.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        return;
    }
    tr.parentNode.removeChild(tr);
    repeaterReindex(field);
};

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

    def.fields.forEach(function (f) {
        var val = values[f.key];
        var name = 'attr[' + f.key + ']';

        // Repeater is self-contained (own table markup) — render & return.
        if (f.type === 'repeater') {
            box.insertAdjacentHTML('beforeend', buildRepeaterField(f.key, f, val));
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
    document.getElementById('edit_price').value = p.price_product || '';
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
        if (!files || !files.length) { picked.textContent = ''; return; }
        var names = [];
        for (var i = 0; i < files.length && i < 5; i++) names.push(files[i].name);
        var label = files.length + ' فایل انتخاب شد: ' + names.join('، ');
        if (files.length > 5) label += ' …';
        picked.textContent = label;
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
