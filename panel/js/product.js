// ---------------------------------------------------------------------------
// Dynamic product form: render attribute fields based on the selected
// product_type (definitions injected as window.PRODUCT_TYPES).
// ---------------------------------------------------------------------------

function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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

    def.fields.forEach(function (f) {
        var val = values[f.key];
        if (val === undefined || val === null) val = '';
        var html = '<div class="field"><label>' + escapeHtml(f.label) + '</label>';
        var name = 'attr[' + f.key + ']';

        if (f.type === 'textarea') {
            html += '<textarea name="' + name + '" class="textarea">' + escapeHtml(val) + '</textarea>';
        } else if (f.type === 'bool') {
            var checked = (val === '1' || val === 1 || val === true || val === 'on') ? 'checked' : '';
            // hidden 0 so unchecked still submits a value
            html += '<input type="hidden" name="' + name + '" value="0">';
            html += '<label style="display:flex;align-items:center;gap:8px;font-weight:400">' +
                '<input type="checkbox" value="1" ' + checked +
                ' onchange="this.previousElementSibling.value=this.checked?1:0"> بله</label>';
        } else {
            var inputType = (f.type === 'number') ? 'number' : 'text';
            html += '<input type="' + inputType + '" name="' + name + '" class="input" value="' + escapeHtml(val) + '">';
        }
        if (f.hint) {
            html += '<div class="field-hint">' + escapeHtml(f.hint) + '</div>';
        }
        html += '</div>';
        box.insertAdjacentHTML('beforeend', html);
    });
};

window.openEditModal = function (p) {
    document.getElementById('edit_id').value = p.id || '';
    document.getElementById('edit_name').value = p.name_product || '';
    document.getElementById('edit_price').value = p.price_product || '';
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
