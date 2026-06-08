/* =============================================================================
 * Visual Button Flow editor (Phase 2 canvas + Phase 3 node ops + Phase 4 input)
 * - View / drag / save the stored tree (React Flow, UMD/CDN, no build).
 * - Phase 3: add child (drag from a node's bottom port to empty canvas -> form),
 *   add root node, edit (double-click -> side panel), delete (cascade + confirm),
 *   connect / disconnect edges, orphan handling, system-node protection.
 * - Phase 4: input-node config form (mode, file extensions, size, attempts,
 *   validation, security flags) wired into the side panel.
 * =========================================================================== */
(function () {
  'use strict';

  var React = window.React;
  var ReactDOM = window.ReactDOM;
  var RF = window.ReactFlow;
  if (!React || !ReactDOM || !RF) {
    document.getElementById('root').innerHTML =
      '<div style="padding:24px;color:#fca5a5;font-size:14px">خطا: کتابخانه React Flow از CDN بارگذاری نشد. اتصال اینترنت سرور/مرورگر را بررسی کنید.</div>';
    return;
  }

  var h = React.createElement;
  var useState = React.useState;
  var useCallback = React.useCallback;
  var useEffect = React.useEffect;
  var useRef = React.useRef;

  var TYPE_LABELS = {
    button: 'دکمه', message: 'پیام', action: 'اکشن',
    input: 'ورودی', condition: 'شرط', n8n: 'n8n'
  };
  // Types the admin may create via the UI (root/system "button" still allowed).
  var CREATABLE_TYPES = ['button', 'message', 'action', 'input', 'condition', 'n8n'];

  var uid = (function () {
    var c = 0;
    return function () {
      c += 1;
      return 'n_' + Date.now().toString(36) + '_' + c.toString(36);
    };
  })();

  // ---- API helpers ---------------------------------------------------------
  function apiGet(name) {
    return fetch('flow.php?api=' + name, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  function apiPost(name, payload) {
    payload = payload || {};
    payload._csrf = window.FLOW_CSRF;
    return fetch('flow.php?api=' + name, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': window.FLOW_CSRF },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
  }

  // ---- Convert stored tree <-> React Flow nodes/edges ----------------------
  function treeToRF(tree) {
    var nodes = (tree.nodes || []).map(function (n) {
      return {
        id: n.id,
        position: { x: (n.position && n.position.x) || 0, y: (n.position && n.position.y) || 0 },
        data: { label: n.label, type: n.type, system: !!n.system, config: n.config || {} },
        type: 'flowNode'
      };
    });
    var edges = (tree.edges || []).map(function (e) {
      return {
        id: e.id || ('e_' + e.source + '_' + e.target),
        source: e.source,
        target: e.target,
        animated: false,
        style: { stroke: '#64748b', strokeWidth: 2 }
      };
    });
    return { nodes: nodes, edges: edges, meta: tree.meta || {} };
  }

  function rfToTree(rfNodes, rfEdges, meta) {
    var parentOf = {};
    rfEdges.forEach(function (e) { parentOf[e.target] = e.source; });
    var nodes = rfNodes.map(function (n) {
      return {
        id: n.id,
        type: (n.data && n.data.type) || 'button',
        label: (n.data && n.data.label) || '',
        parent: parentOf[n.id] || null,
        system: !!(n.data && n.data.system),
        position: { x: Math.round(n.position.x), y: Math.round(n.position.y) },
        config: (n.data && n.data.config) || {}
      };
    });
    var edges = rfEdges.map(function (e) {
      return { id: e.id, source: e.source, target: e.target };
    });
    return { nodes: nodes, edges: edges, meta: meta || {} };
  }

  function newEdge(source, target) {
    return {
      id: 'e_' + source + '_' + target,
      source: source, target: target,
      animated: false, style: { stroke: '#64748b', strokeWidth: 2 }
    };
  }

  // Collect a node and all its descendants (for cascade delete).
  function descendantsOf(rootId, edges) {
    var childrenOf = {};
    edges.forEach(function (e) {
      (childrenOf[e.source] = childrenOf[e.source] || []).push(e.target);
    });
    var out = [];
    var stack = [rootId];
    var seen = {};
    while (stack.length) {
      var id = stack.pop();
      if (seen[id]) continue;
      seen[id] = true;
      out.push(id);
      (childrenOf[id] || []).forEach(function (c) { stack.push(c); });
    }
    return out; // includes rootId
  }

  // ---- Custom node renderer ------------------------------------------------
  function FlowNode(props) {
    var d = props.data || {};
    var cls = 'rf-node t-' + (d.type || 'button') + (d.system ? ' system' : '') + (props.selected ? ' selected' : '');
    return h('div', { className: cls },
      h(RF.Handle, { type: 'target', position: RF.Position.Top, style: { background: '#94a3b8' } }),
      h('div', { className: 'nt' }, TYPE_LABELS[d.type] || d.type),
      h('div', { className: 'nl' }, d.label || '(بدون نام)'),
      d.system ? h('span', { className: 'badge' }, 'سیستمی') : null,
      h(RF.Handle, { type: 'source', position: RF.Position.Bottom, style: { background: '#94a3b8' } })
    );
  }

  var nodeTypes = { flowNode: FlowNode };

  // ===========================================================================
  // Side panel form (create or edit a node) — Phase 3 + Phase 4
  // ===========================================================================
  function defaultConfigFor(type) {
    if (type === 'input') {
      return {
        message: '', input_mode: 'text', input_validation: 'none', input_pattern: '',
        input_min: 0, input_max: 0, max_attempts: 5, attempt_window_sec: 0,
        force_reply: true, error_message: '', input_tag: '',
        file_extensions: [], file_max_mb: 0, file_max_count: 1,
        sanitize_text: true, verify_mime: true
      };
    }
    if (type === 'n8n') {
      return {
        n8n_mode: 'async', n8n_endpoint: '', n8n_tag: '', n8n_timeout_sec: 5,
        send_path: true, send_inputs: true, wait_message: 'در حال بررسی…'
      };
    }
    if (type === 'action') return { message: '', action_key: '' };
    if (type === 'condition') return { message: '', branches: [] };
    return { message: '', auto_back: false, auto_home: false };
  }

  function field(labelTxt, control, help) {
    return h('div', { className: 'fld' },
      h('label', null, labelTxt),
      control,
      help ? h('div', { className: 'hlp' }, help) : null
    );
  }

  function NodeForm(props) {
    // props: draft {id?, type, label, system, config}, isNew, onSave, onDelete, onClose
    var d = props.draft;
    var setDraft = props.setDraft;
    var isNew = props.isNew;
    var isSystem = !!d.system;

    function setField(k, v) {
      var nd = Object.assign({}, d);
      nd[k] = v;
      setDraft(nd);
    }
    function setCfg(k, v) {
      var nc = Object.assign({}, d.config || {});
      nc[k] = v;
      setField('config', nc);
    }
    var cfg = d.config || {};

    var rows = [];

    if (isSystem) {
      rows.push(h('div', { className: 'sys-warn', key: 'sw' },
        '⚠️ این یک نود سیستمی محافظت‌شده است. تغییر یا حذف آن می‌تواند رفتار ربات را خراب کند. فقط در صورت اطمینان ادامه دهید.'));
    }

    // --- common: type (locked for system root) + label ---
    rows.push(field('نوع نود',
      h('select', {
        key: 'type', value: d.type,
        disabled: isSystem,
        onChange: function (e) {
          var t = e.target.value;
          var nd = Object.assign({}, d, { type: t });
          // reset config to sensible defaults for the new type, keep message if any
          var base = defaultConfigFor(t);
          if (cfg.message) base.message = cfg.message;
          nd.config = base;
          setDraft(nd);
        }
      }, CREATABLE_TYPES.map(function (t) {
        return h('option', { key: t, value: t }, TYPE_LABELS[t] + ' (' + t + ')');
      })),
      isSystem ? 'نوع نود سیستمی قابل تغییر نیست.' : 'دکمه=منوی فرزند، پیام=نمایش متن، ورودی=دریافت از کاربر، شرط=انشعاب، اکشن=عملیات داخلی، n8n=ارسال به n8n.'));

    rows.push(field('عنوان دکمه/نود',
      h('input', {
        key: 'label', type: 'text', value: d.label || '',
        placeholder: 'مثلاً: خرید نقدی',
        onChange: function (e) { setField('label', e.target.value); }
      }),
      'متنی که روی دکمه به کاربر نشان داده می‌شود.'));

    // message text shown for most node types
    if (d.type !== 'condition') {
      rows.push(field('پیام/توضیح (اختیاری)',
        h('textarea', {
          key: 'msg', value: cfg.message || '',
          placeholder: 'پیامی که هنگام رسیدن به این نود نمایش داده می‌شود…',
          onChange: function (e) { setCfg('message', e.target.value); }
        })));
    }

    // ---- type-specific blocks ----
    if (d.type === 'button') {
      rows.push(h('div', { className: 'grp', key: 'gbtn' },
        h('div', { className: 'grp-t' }, 'دکمه‌های راهبری خودکار'),
        h('label', { className: 'chk' },
          h('input', { type: 'checkbox', checked: !!cfg.auto_back, onChange: function (e) { setCfg('auto_back', e.target.checked); } }),
          'دکمهٔ «بازگشت» خودکار اضافه شود'),
        h('label', { className: 'chk' },
          h('input', { type: 'checkbox', checked: !!cfg.auto_home, onChange: function (e) { setCfg('auto_home', e.target.checked); } }),
          'دکمهٔ «منوی اصلی» خودکار اضافه شود')
      ));
    }

    if (d.type === 'action') {
      rows.push(field('کلید اکشن داخلی',
        h('input', {
          key: 'ak', type: 'text', value: cfg.action_key || '',
          placeholder: 'مثلاً: buy, account, support',
          onChange: function (e) { setCfg('action_key', e.target.value); }
        }),
        'شناسهٔ یک عملیات داخلی ربات که هنگام رسیدن به این نود اجرا می‌شود.'));
    }

    if (d.type === 'condition') {
      var branches = Array.isArray(cfg.branches) ? cfg.branches : [];
      function setBranches(arr) { setCfg('branches', arr); }
      function updBranch(i, k, v) {
        var copy = branches.map(function (b, j) { return j === i ? Object.assign({}, b, (function () { var o = {}; o[k] = v; return o; })()) : b; });
        setBranches(copy);
      }
      function addBranch() { setBranches(branches.concat([{ label: '', op: 'eq', value: '', goto: '' }])); }
      function delBranch(i) { setBranches(branches.filter(function (_, j) { return j !== i; })); }

      var OPS = [
        ['eq', 'برابر است با'], ['neq', 'برابر نیست با'],
        ['contains', 'شامل'], ['not_contains', 'شامل نیست'],
        ['gt', 'بزرگ‌تر از'], ['gte', 'بزرگ‌تر/مساوی'],
        ['lt', 'کوچک‌تر از'], ['lte', 'کوچک‌تر/مساوی'],
        ['regex', 'الگوی Regex'], ['empty', 'خالی است'], ['not_empty', 'خالی نیست'],
        ['is_valid', 'معتبر بود (ورودی/n8n)'], ['is_invalid', 'نامعتبر بود'],
        ['else', 'در غیر این صورت (پیش‌فرض)']
      ];
      var noValue = { empty: 1, not_empty: 1, is_valid: 1, is_invalid: 1, else: 1 };

      var branchRows = branches.map(function (b, i) {
        return h('div', { key: 'br' + i, style: { border: '1px solid #334155', borderRadius: '8px', padding: '8px', marginBottom: '8px' } },
          h('div', { className: 'fld row', style: { marginBottom: '6px' } },
            h('div', null, h('input', { type: 'text', placeholder: 'برچسب شاخه (مثلاً: معتبر)', value: b.label || '', onChange: function (e) { updBranch(i, 'label', e.target.value); } })),
            h('button', { className: 'btn danger', style: { padding: '4px 8px', flex: 'none' }, onClick: function () { delBranch(i); } }, '✕')),
          h('div', { className: 'fld row', style: { marginBottom: noValue[b.op] ? '0' : '6px' } },
            h('div', null, h('select', { value: b.op || 'eq', onChange: function (e) { updBranch(i, 'op', e.target.value); } },
              OPS.map(function (o) { return h('option', { key: o[0], value: o[0] }, o[1]); }))),
            (noValue[b.op] ? null : h('div', null, h('input', { type: 'text', placeholder: 'مقدار مقایسه', value: b.value || '', onChange: function (e) { updBranch(i, 'value', e.target.value); } })))),
          h('div', { className: 'hlp' }, 'مقصد: فرزند شمارهٔ ' + (i + 1) + ' این نود (به ترتیب اتصال). برای ساخت مقصد، از پورت پایین این نود یک فرزند بکشید.')
        );
      });

      rows.push(h('div', { className: 'grp', key: 'gcond' },
        h('div', { className: 'grp-t' }, 'تنظیمات شرط (انشعاب)'),
        field('منبع مقدار',
          h('select', { value: cfg.condition_source || 'last', onChange: function (e) { setCfg('condition_source', e.target.value); } },
            h('option', { value: 'last' }, 'آخرین ورودی کاربر'),
            h('option', { value: 'input' }, 'یک ورودی ذخیره‌شده (با کلید)'),
            h('option', { value: 'n8n' }, 'پاسخ n8n (با کلید)')),
          'مقداری که شرط روی آن ارزیابی می‌شود.'),
        (cfg.condition_source === 'input' || cfg.condition_source === 'n8n') ? field('کلید منبع',
          h('input', { type: 'text', value: cfg.condition_source_key || '', placeholder: cfg.condition_source === 'n8n' ? 'مثلاً: status' : 'مثلاً: discount_code', onChange: function (e) { setCfg('condition_source_key', e.target.value); } })) : null,
        field('پیام (اختیاری)',
          h('input', { type: 'text', value: cfg.message || '', onChange: function (e) { setCfg('message', e.target.value); } })),
        h('div', { style: { fontSize: '11.5px', color: '#93c5fd', margin: '4px 0 8px' } }, 'شاخه‌ها (اولین تطبیق برنده است):'),
        branchRows,
        h('button', { className: 'btn', style: { width: '100%' }, onClick: addBranch }, '➕ افزودن شاخه')
      ));
    }

    if (d.type === 'n8n') {
      rows.push(h('div', { className: 'grp', key: 'gn8n' },
        h('div', { className: 'grp-t' }, 'تنظیمات n8n'),
        field('حالت ارسال',
          h('select', { value: cfg.n8n_mode || 'async', onChange: function (e) { setCfg('n8n_mode', e.target.value); } },
            h('option', { value: 'async' }, 'ناهمگام (Webhook بازگشتی) — پیشنهادی'),
            h('option', { value: 'sync' }, 'همگام (انتظار با تایم‌اوت)')),
          'حالت ناهمگام برای جلوگیری از کندی/کرش سرور هنگام بار زیاد توصیه می‌شود.'),
        field('آدرس Webhook در n8n',
          h('input', { type: 'text', value: cfg.n8n_endpoint || '', placeholder: 'https://n8n.example.com/webhook/...', onChange: function (e) { setCfg('n8n_endpoint', e.target.value); } })),
        h('div', { className: 'fld row' },
          h('div', null, h('label', null, 'تایم‌اوت (ثانیه)'),
            h('input', { type: 'number', min: 1, max: 30, value: cfg.n8n_timeout_sec || 5, onChange: function (e) { setCfg('n8n_timeout_sec', parseInt(e.target.value || '5', 10)); } })),
          h('div', null, h('label', null, 'برچسب'),
            h('input', { type: 'text', value: cfg.n8n_tag || '', onChange: function (e) { setCfg('n8n_tag', e.target.value); } }))),
        h('label', { className: 'chk' },
          h('input', { type: 'checkbox', checked: cfg.send_path !== false, onChange: function (e) { setCfg('send_path', e.target.checked); } }),
          'ارسال مسیر کاربر (Breadcrumb) به n8n'),
        h('label', { className: 'chk' },
          h('input', { type: 'checkbox', checked: cfg.send_inputs !== false, onChange: function (e) { setCfg('send_inputs', e.target.checked); } }),
          'ارسال ورودی‌های جمع‌آوری‌شدهٔ کاربر'),
        field('پیام انتظار',
          h('input', { type: 'text', value: cfg.wait_message || '', onChange: function (e) { setCfg('wait_message', e.target.value); } }))
      ));
    }

    // ---- Phase 4: INPUT node full config (text + files + security) ----
    if (d.type === 'input') {
      var mode = cfg.input_mode || 'text';
      rows.push(h('div', { className: 'grp', key: 'gin' },
        h('div', { className: 'grp-t' }, 'تنظیمات ورودی کاربر'),
        field('نوع ورودی مجاز',
          h('select', { value: mode, onChange: function (e) { setCfg('input_mode', e.target.value); } },
            h('option', { value: 'text' }, 'فقط متن'),
            h('option', { value: 'photo' }, 'فقط عکس'),
            h('option', { value: 'document' }, 'فقط فایل/سند'),
            h('option', { value: 'media' }, 'عکس یا فایل'),
            h('option', { value: 'any' }, 'هر نوع (متن/عکس/فایل)')),
          'تعیین می‌کند کاربر در این مرحله چه چیزی می‌تواند بفرستد.'),
        field('برچسب ورودی (کلید ذخیره)',
          h('input', { type: 'text', value: cfg.input_tag || '', placeholder: 'مثلاً: discount_code یا receipt', onChange: function (e) { setCfg('input_tag', e.target.value); } }),
          'با این کلید، مقدار وارد‌شده ذخیره و در نودهای بعدی/n8n استفاده می‌شود.'),
        field('پیام درخواست از کاربر',
          h('input', { type: 'text', value: cfg.prompt || '', placeholder: 'لطفاً کد تخفیف خود را بفرستید…', onChange: function (e) { setCfg('prompt', e.target.value); } })),
        h('label', { className: 'chk' },
          h('input', { type: 'checkbox', checked: cfg.force_reply !== false, onChange: function (e) { setCfg('force_reply', e.target.checked); } }),
          'اجبار به پاسخ (Force Reply)')
      ));

      // text validation (only relevant if text is allowed)
      if (mode === 'text' || mode === 'any' || mode === 'media') {
        rows.push(h('div', { className: 'grp', key: 'gtv' },
          h('div', { className: 'grp-t' }, 'اعتبارسنجی متن'),
          field('نوع اعتبارسنجی',
            h('select', { value: cfg.input_validation || 'none', onChange: function (e) { setCfg('input_validation', e.target.value); } },
              h('option', { value: 'none' }, 'بدون بررسی'),
              h('option', { value: 'number' }, 'فقط عدد'),
              h('option', { value: 'length' }, 'فقط طول'),
              h('option', { value: 'regex' }, 'الگوی دلخواه (Regex)'))),
          (cfg.input_validation === 'regex') ? field('الگوی Regex',
            h('input', { type: 'text', value: cfg.input_pattern || '', placeholder: '^[A-Za-z0-9]+$', onChange: function (e) { setCfg('input_pattern', e.target.value); } }),
            'بدون اسلش/دلیمیتر بنویسید. مثال بالا: فقط حروف و اعداد انگلیسی.') : null,
          h('div', { className: 'fld row' },
            h('div', null, h('label', null, 'حداقل کاراکتر'),
              h('input', { type: 'number', min: 0, value: cfg.input_min || 0, onChange: function (e) { setCfg('input_min', parseInt(e.target.value || '0', 10)); } })),
            h('div', null, h('label', null, 'حداکثر کاراکتر (۰=بی‌حد)'),
              h('input', { type: 'number', min: 0, value: cfg.input_max || 0, onChange: function (e) { setCfg('input_max', parseInt(e.target.value || '0', 10)); } }))),
          h('label', { className: 'chk' },
            h('input', { type: 'checkbox', checked: cfg.sanitize_text !== false, onChange: function (e) { setCfg('sanitize_text', e.target.checked); } }),
            'پاکسازی متن (حذف تگ/کد مخرب) — امنیتی، روشن بماند')
        ));
      }

      // file constraints (only relevant if a file/photo is allowed)
      if (mode === 'photo' || mode === 'document' || mode === 'media' || mode === 'any') {
        rows.push(h('div', { className: 'grp', key: 'gfile' },
          h('div', { className: 'grp-t' }, '🛡️ محدودیت و امنیت فایل'),
          field('فرمت‌های مجاز (با کاما)',
            h('input', {
              type: 'text',
              value: Array.isArray(cfg.file_extensions) ? cfg.file_extensions.join(', ') : (cfg.file_extensions || ''),
              placeholder: 'jpg, png, pdf',
              onChange: function (e) { setCfg('file_extensions', e.target.value); }
            }),
            'خالی = همهٔ فرمت‌های بی‌خطر. فرمت‌های خطرناک (php, exe, sh, js, html, svg…) همیشه و خودکار مسدودند، حتی اگر اینجا بنویسید.'),
          h('div', { className: 'fld row' },
            h('div', null, h('label', null, 'حداکثر حجم (MB، ۰=پیش‌فرض)'),
              h('input', { type: 'number', min: 0, value: cfg.file_max_mb || 0, onChange: function (e) { setCfg('file_max_mb', parseInt(e.target.value || '0', 10)); } })),
            h('div', null, h('label', null, 'حداکثر تعداد فایل'),
              h('input', { type: 'number', min: 1, value: cfg.file_max_count || 1, onChange: function (e) { setCfg('file_max_count', parseInt(e.target.value || '1', 10)); } }))),
          h('label', { className: 'chk' },
            h('input', { type: 'checkbox', checked: cfg.verify_mime !== false, onChange: function (e) { setCfg('verify_mime', e.target.checked); } }),
            'بررسی نوع واقعی فایل (MIME) برای جلوگیری از فایل جعلی — امنیتی')
        ));
      }

      // rate limit
      rows.push(h('div', { className: 'grp', key: 'grl' },
        h('div', { className: 'grp-t' }, 'محدودیت تلاش (ضد سوءاستفاده)'),
        h('div', { className: 'fld row' },
          h('div', null, h('label', null, 'حداکثر تلاش (۰=بی‌حد)'),
            h('input', { type: 'number', min: 0, value: cfg.max_attempts || 0, onChange: function (e) { setCfg('max_attempts', parseInt(e.target.value || '0', 10)); } })),
          h('div', null, h('label', null, 'بازهٔ زمانی (ثانیه)'),
            h('input', { type: 'number', min: 0, value: cfg.attempt_window_sec || 0, onChange: function (e) { setCfg('attempt_window_sec', parseInt(e.target.value || '0', 10)); } }))),
        field('پیام خطا (در صورت نامعتبر بودن)',
          h('input', { type: 'text', value: cfg.error_message || '', placeholder: 'ورودی نامعتبر است، دوباره تلاش کنید.', onChange: function (e) { setCfg('error_message', e.target.value); } }))
      ));
    }

    return h('div', { className: 'side-body' }, rows);
  }

  // ===========================================================================
  // Main editor
  // ===========================================================================
  function Editor() {
    var nodesPair = RF.useNodesState([]);
    var edgesPair = RF.useEdgesState([]);
    var nodes = nodesPair[0], setNodes = nodesPair[1], onNodesChange = nodesPair[2];
    var edges = edgesPair[0], setEdges = edgesPair[1], onEdgesChange = edgesPair[2];

    var rfInstance = useRef(null);
    var metaRef = useRef({});
    var wrapRef = useRef(null);
    var connectStart = useRef(null); // {nodeId} when a drag-from-port starts

    var dirtyState = useState(false);
    var dirty = dirtyState[0], setDirty = dirtyState[1];
    var loadingState = useState(true);
    var loading = loadingState[0], setLoading = loadingState[1];

    // ---- Undo/Redo (client-side) ----
    // undoStack holds previous {nodes, edges} snapshots; redoStack holds ones
    // we've undone past. We never push while applying an undo/redo (guarded by
    // isApplyingHistory) so the stacks stay clean.
    var undoStack = useRef([]);
    var redoStack = useRef([]);
    var isApplyingHistory = useRef(false);
    var canUndoState = useState(false);
    var canUndo = canUndoState[0], setCanUndo = canUndoState[1];
    var canRedoState = useState(false);
    var canRedo = canRedoState[0], setCanRedo = canRedoState[1];

    // History (saved versions) modal state.
    var historyState = useState({ open: false, items: null });
    var historyView = historyState[0], setHistoryView = historyState[1];

    // side panel state: { open, isNew, draft, pendingParent }
    var panelState = useState({ open: false, isNew: false, draft: null, pendingParent: null, dropPos: null });
    var panel = panelState[0], setPanel = panelState[1];

    function setStatus(kind, text) {
      var el = document.getElementById('status');
      if (!el) return;
      el.className = 'status ' + kind;
      el.textContent = text;
    }

    var UNDO_LIMIT = 50;

    function cloneRF(ns, es) {
      // Structured deep clone of the current canvas (data only).
      return {
        nodes: (ns || []).map(function (n) {
          return {
            id: n.id, type: n.type, position: { x: n.position.x, y: n.position.y },
            data: JSON.parse(JSON.stringify(n.data || {})),
            selected: false
          };
        }),
        edges: (es || []).map(function (e) {
          return { id: e.id, source: e.source, target: e.target, type: e.type };
        })
      };
    }

    function refreshUndoButtons() {
      setCanUndo(undoStack.current.length > 0);
      setCanRedo(redoStack.current.length > 0);
    }

    // Push the CURRENT canvas onto the undo stack BEFORE a change is applied.
    var pushUndo = useCallback(function (curNodes, curEdges) {
      if (isApplyingHistory.current) return;
      undoStack.current.push(cloneRF(curNodes, curEdges));
      if (undoStack.current.length > UNDO_LIMIT) undoStack.current.shift();
      redoStack.current = []; // a fresh edit invalidates the redo chain
      refreshUndoButtons();
    }, []);

    var markDirty = useCallback(function () {
      setDirty(true);
      setStatus('dirty', 'ذخیره‌نشده');
    }, []);

    var undo = useCallback(function () {
      if (undoStack.current.length === 0) return;
      isApplyingHistory.current = true;
      var snap = undoStack.current.pop();
      // current state goes to redo so we can re-apply it
      redoStack.current.push(cloneRF(nodes, edges));
      setNodes(snap.nodes);
      setEdges(snap.edges);
      refreshUndoButtons();
      setDirty(true);
      setStatus('dirty', 'واگرد شد');
      setTimeout(function () { isApplyingHistory.current = false; }, 0);
    }, [nodes, edges, setNodes, setEdges]);

    var redo = useCallback(function () {
      if (redoStack.current.length === 0) return;
      isApplyingHistory.current = true;
      var snap = redoStack.current.pop();
      undoStack.current.push(cloneRF(nodes, edges));
      setNodes(snap.nodes);
      setEdges(snap.edges);
      refreshUndoButtons();
      setDirty(true);
      setStatus('dirty', 'ازنو شد');
      setTimeout(function () { isApplyingHistory.current = false; }, 0);
    }, [nodes, edges, setNodes, setEdges]);

    var load = useCallback(function () {
      setLoading(true);
      apiGet('tree').then(function (res) {
        if (res && res.ok) {
          var rf = treeToRF(res.tree);
          metaRef.current = rf.meta;
          // A fresh load resets the undo/redo timeline.
          undoStack.current = [];
          redoStack.current = [];
          isApplyingHistory.current = false;
          setCanUndo(false);
          setCanRedo(false);
          setNodes(rf.nodes);
          setEdges(rf.edges);
          setDirty(false);
          setStatus('saved', 'ذخیره‌شده');
        } else {
          setStatus('error', 'خطا در بارگذاری');
        }
        setLoading(false);
      }).catch(function () {
        setStatus('error', 'خطای شبکه');
        setLoading(false);
      });
    }, [setNodes, setEdges]);

    useEffect(function () { load(); }, [load]);

    // ---- node/edge change tracking ----
    var handleNodesChange = useCallback(function (changes) {
      // Block removal of system nodes via the built-in change pipeline.
      var filtered = changes.filter(function (c) {
        if (c.type === 'remove') {
          var nd = nodes.find(function (x) { return x.id === c.id; });
          if (nd && nd.data && nd.data.system) {
            setStatus('error', 'نود سیستمی را نمی‌توان مستقیم حذف کرد');
            return false;
          }
        }
        return true;
      });
      // Only snapshot for structural/positional changes (not select/dimensions),
      // and only at the END of a drag (dragging===false) so we don't flood the
      // undo stack with one entry per mouse-move during a node drag.
      var meaningful = filtered.some(function (c) {
        if (c.type === 'remove' || c.type === 'add') return true;
        if (c.type === 'position') return c.dragging === false;
        return false;
      });
      if (meaningful) pushUndo(nodes, edges);
      onNodesChange(filtered);
      if (meaningful) markDirty();
    }, [onNodesChange, markDirty, nodes, edges, pushUndo]);

    var handleEdgesChange = useCallback(function (changes) {
      var meaningful = changes.some(function (c) { return c.type === 'remove' || c.type === 'add'; });
      if (meaningful) pushUndo(nodes, edges);
      onEdgesChange(changes);
      if (meaningful) markDirty();
    }, [onEdgesChange, markDirty, nodes, edges, pushUndo]);

    // ---- connect two existing nodes (enforce single-parent + no-cycle) ----
    var onConnect = useCallback(function (params) {
      var source = params.source, target = params.target;
      if (!source || !target || source === target) return;
      // target must not already have a parent (single-parent tree)
      var hasParent = edges.some(function (e) { return e.target === target; });
      if (hasParent) {
        setStatus('error', 'این نود از قبل والد دارد. ابتدا اتصال قبلی را حذف کنید.');
        return;
      }
      // no cycle: target must not be an ancestor of source
      var subtree = descendantsOf(target, edges);
      if (subtree.indexOf(source) !== -1) {
        setStatus('error', 'اتصال حلقه‌ای مجاز نیست.');
        return;
      }
      pushUndo(nodes, edges);
      setEdges(function (es) { return es.concat([newEdge(source, target)]); });
      markDirty();
    }, [nodes, edges, setEdges, markDirty, pushUndo]);

    // ---- drag from a port and drop on empty canvas -> create child ----
    var onConnectStart = useCallback(function (_evt, params) {
      connectStart.current = (params && params.handleType === 'source') ? params.nodeId : null;
    }, []);

    var onConnectEnd = useCallback(function (evt) {
      var sourceId = connectStart.current;
      connectStart.current = null;
      if (!sourceId) return;
      var targetIsPane = evt.target && evt.target.classList &&
        evt.target.classList.contains('react-flow__pane');
      if (!targetIsPane) return; // dropped on a node/handle -> onConnect handles it
      // compute drop position in flow coords
      var pos = { x: 0, y: 0 };
      try {
        var bounds = wrapRef.current.getBoundingClientRect();
        var cx = (evt.clientX !== undefined) ? evt.clientX : (evt.changedTouches && evt.changedTouches[0].clientX);
        var cy = (evt.clientY !== undefined) ? evt.clientY : (evt.changedTouches && evt.changedTouches[0].clientY);
        if (rfInstance.current && rfInstance.current.screenToFlowPosition) {
          pos = rfInstance.current.screenToFlowPosition({ x: cx, y: cy });
        } else if (rfInstance.current && rfInstance.current.project) {
          pos = rfInstance.current.project({ x: cx - bounds.left, y: cy - bounds.top });
        }
      } catch (e) { /* fall back to 0,0 */ }
      // open the create form; remember the parent + drop position
      setPanel({
        open: true, isNew: true, pendingParent: sourceId, dropPos: pos,
        draft: { type: 'button', label: '', system: false, config: defaultConfigFor('button') }
      });
    }, []);

    // ---- double-click a node -> edit ----
    var onNodeDoubleClick = useCallback(function (_evt, node) {
      setPanel({
        open: true, isNew: false, pendingParent: null, dropPos: null,
        draft: {
          id: node.id,
          type: (node.data && node.data.type) || 'button',
          label: (node.data && node.data.label) || '',
          system: !!(node.data && node.data.system),
          config: Object.assign({}, (node.data && node.data.config) || {})
        }
      });
    }, []);

    // ---- save the side-panel form ----
    var savePanel = useCallback(function () {
      var d = panel.draft;
      if (!d) return;
      if (!d.label || !d.label.trim()) {
        alert('عنوان نود را وارد کنید.');
        return;
      }
      // normalise file_extensions text -> array (server re-validates + strips dangerous)
      var cfg = Object.assign({}, d.config || {});
      if (typeof cfg.file_extensions === 'string') {
        cfg.file_extensions = cfg.file_extensions.split(/[\s,;]+/).filter(Boolean);
      }
      pushUndo(nodes, edges);
      if (panel.isNew) {
        var id = uid();
        var newNode = {
          id: id, type: 'flowNode',
          position: panel.dropPos || { x: 120, y: 120 },
          data: { label: d.label.trim(), type: d.type, system: false, config: cfg }
        };
        setNodes(function (ns) { return ns.concat([newNode]); });
        if (panel.pendingParent) {
          setEdges(function (es) { return es.concat([newEdge(panel.pendingParent, id)]); });
        }
      } else {
        setNodes(function (ns) {
          return ns.map(function (n) {
            if (n.id !== d.id) return n;
            return Object.assign({}, n, {
              data: Object.assign({}, n.data, { label: d.label.trim(), type: d.type, config: cfg })
            });
          });
        });
      }
      markDirty();
      setPanel({ open: false, isNew: false, draft: null, pendingParent: null, dropPos: null });
    }, [panel, nodes, edges, setNodes, setEdges, markDirty, pushUndo]);

    // ---- delete a node (cascade descendants, confirm, protect system) ----
    var deleteNode = useCallback(function (nodeId) {
      var nd = nodes.find(function (n) { return n.id === nodeId; });
      if (!nd) return;
      if (nd.data && nd.data.system) {
        if (!confirm('این نود «سیستمی» محافظت‌شده است. حذف آن می‌تواند ربات را خراب کند. مطمئنید؟')) return;
        if (!confirm('تأیید نهایی: واقعاً نود سیستمی «' + (nd.data.label || '') + '» و همهٔ زیرشاخه‌هایش حذف شوند؟')) return;
      }
      var subtree = descendantsOf(nodeId, edges);
      var sysInSub = nodes.filter(function (n) {
        return subtree.indexOf(n.id) !== -1 && n.data && n.data.system && n.id !== nodeId;
      });
      var msg = 'حذف نود «' + ((nd.data && nd.data.label) || '') + '»' +
        (subtree.length > 1 ? ' و ' + (subtree.length - 1) + ' زیرشاخه' : '') + '؟';
      if (sysInSub.length) {
        msg += '\n\n⚠️ ' + sysInSub.length + ' نود سیستمی نیز در این زیردرخت وجود دارد و حذف خواهد شد!';
      }
      if (!confirm(msg)) return;
      pushUndo(nodes, edges);
      var rm = {};
      subtree.forEach(function (id) { rm[id] = true; });
      setNodes(function (ns) { return ns.filter(function (n) { return !rm[n.id]; }); });
      setEdges(function (es) { return es.filter(function (e) { return !rm[e.source] && !rm[e.target]; }); });
      markDirty();
      setPanel({ open: false, isNew: false, draft: null, pendingParent: null, dropPos: null });
    }, [nodes, edges, setNodes, setEdges, markDirty, pushUndo]);

    // ---- add a free (root-level, no parent) node via topbar button ----
    var addRootNode = useCallback(function () {
      var pos = { x: 80, y: 80 };
      try {
        if (rfInstance.current && rfInstance.current.screenToFlowPosition) {
          var b = wrapRef.current.getBoundingClientRect();
          pos = rfInstance.current.screenToFlowPosition({ x: b.left + b.width / 2, y: b.top + 120 });
        }
      } catch (e) {}
      setPanel({
        open: true, isNew: true, pendingParent: null, dropPos: pos,
        draft: { type: 'button', label: '', system: false, config: defaultConfigFor('button') }
      });
    }, []);

    var save = useCallback(function () {
      var tree = rfToTree(nodes, edges, metaRef.current);
      setStatus('dirty', 'در حال ذخیره…');
      apiPost('save', { tree: tree, note: 'canvas save' }).then(function (res) {
        if (res && res.ok) {
          if (res.tree) {
            // reload from server-normalised tree so config defaults/strips reflect
            var rf = treeToRF(res.tree);
            metaRef.current = rf.meta;
            setNodes(rf.nodes);
            setEdges(rf.edges);
          }
          setDirty(false);
          setStatus('saved', 'ذخیره شد ✓');
        } else {
          setStatus('error', 'خطا: ' + ((res && res.error) || 'نامشخص'));
        }
      }).catch(function () { setStatus('error', 'خطای شبکه هنگام ذخیره'); });
    }, [nodes, edges, setNodes, setEdges]);

    var migrate = useCallback(function () {
      if (!confirm('دکمه‌های قدیمی به‌صورت نودهای فرزند منوی اصلی وارد می‌شوند. ادامه؟')) return;
      pushUndo(nodes, edges);
      apiPost('migrate', {}).then(function (res) {
        if (res && res.ok) {
          var rf = treeToRF(res.tree);
          metaRef.current = rf.meta;
          setNodes(rf.nodes);
          setEdges(rf.edges);
          markDirty();
          alert('وارد شد. برای ذخیره، دکمه «ذخیره» را بزنید.');
        } else {
          alert('خطا در وارد کردن.');
        }
      });
    }, [nodes, edges, setNodes, setEdges, markDirty, pushUndo]);

    // ---- History (saved versions) modal ----
    var openHistory = useCallback(function () {
      setHistoryView({ open: true, items: null });
      apiGet('history').then(function (res) {
        setHistoryView({ open: true, items: (res && res.ok && res.items) ? res.items : [] });
      }).catch(function () {
        setHistoryView({ open: true, items: [] });
      });
    }, []);

    var closeHistory = useCallback(function () {
      setHistoryView({ open: false, items: null });
    }, []);

    var restoreVersion = useCallback(function (id) {
      if (!confirm('این نسخه به‌عنوان درخت فعال بازگردانده شود؟ (نسخهٔ فعلی هم به‌عنوان یک نسخهٔ تازه ذخیره می‌شود)')) return;
      apiPost('restore', { id: id }).then(function (res) {
        if (res && res.ok && res.tree) {
          var rf = treeToRF(res.tree);
          metaRef.current = rf.meta;
          undoStack.current = [];
          redoStack.current = [];
          setCanUndo(false);
          setCanRedo(false);
          setNodes(rf.nodes);
          setEdges(rf.edges);
          setDirty(false);
          setStatus('saved', 'نسخه بازگردانی شد ✓');
          closeHistory();
        } else {
          alert('بازگردانی ناموفق بود: ' + ((res && res.error) || 'نامشخص'));
        }
      }).catch(function () { alert('خطای شبکه هنگام بازگردانی.'); });
    }, [setNodes, setEdges, closeHistory]);

    // Wire the topbar buttons.
    useEffect(function () {
      var sb = document.getElementById('btn-save');
      var rb = document.getElementById('btn-reload');
      var mb = document.getElementById('btn-migrate');
      var ab = document.getElementById('btn-add');
      var ub = document.getElementById('btn-undo');
      var rdb = document.getElementById('btn-redo');
      var hb = document.getElementById('btn-history');
      var hc = document.getElementById('history-close');
      function onSave() { save(); }
      function onReload() {
        if (dirty && !confirm('تغییرات ذخیره‌نشده از بین می‌رود. ادامه؟')) return;
        load();
      }
      function onMigrate() { migrate(); }
      function onAdd() { addRootNode(); }
      function onUndo() { undo(); }
      function onRedo() { redo(); }
      function onHistory() { openHistory(); }
      function onHistClose() { closeHistory(); }
      if (sb) sb.addEventListener('click', onSave);
      if (rb) rb.addEventListener('click', onReload);
      if (mb) mb.addEventListener('click', onMigrate);
      if (ab) ab.addEventListener('click', onAdd);
      if (ub) ub.addEventListener('click', onUndo);
      if (rdb) rdb.addEventListener('click', onRedo);
      if (hb) hb.addEventListener('click', onHistory);
      if (hc) hc.addEventListener('click', onHistClose);
      return function () {
        if (sb) sb.removeEventListener('click', onSave);
        if (rb) rb.removeEventListener('click', onReload);
        if (mb) mb.removeEventListener('click', onMigrate);
        if (ab) ab.removeEventListener('click', onAdd);
        if (ub) ub.removeEventListener('click', onUndo);
        if (rdb) rdb.removeEventListener('click', onRedo);
        if (hb) hb.removeEventListener('click', onHistory);
        if (hc) hc.removeEventListener('click', onHistClose);
      };
    }, [save, load, migrate, addRootNode, dirty, undo, redo, openHistory, closeHistory]);

    useEffect(function () {
      var sb = document.getElementById('btn-save');
      if (sb) sb.disabled = !dirty;
    }, [dirty]);

    // Enable/disable undo & redo buttons to match the stacks.
    useEffect(function () {
      var ub = document.getElementById('btn-undo');
      var rdb = document.getElementById('btn-redo');
      if (ub) ub.disabled = !canUndo;
      if (rdb) rdb.disabled = !canRedo;
    }, [canUndo, canRedo]);

    // keyboard: Delete removes selected; Ctrl+Z undo; Ctrl+Y / Ctrl+Shift+Z redo
    useEffect(function () {
      function onKey(e) {
        if (panel.open) return; // don't hijack while editing form
        var meta = e.ctrlKey || e.metaKey;
        if (meta && (e.key === 'z' || e.key === 'Z')) {
          e.preventDefault();
          if (e.shiftKey) { redo(); } else { undo(); }
          return;
        }
        if (meta && (e.key === 'y' || e.key === 'Y')) {
          e.preventDefault();
          redo();
          return;
        }
        if (e.key === 'Delete' || e.key === 'Backspace') {
          var sel = nodes.find(function (n) { return n.selected; });
          if (sel) { e.preventDefault(); deleteNode(sel.id); }
        }
      }
      window.addEventListener('keydown', onKey);
      return function () { window.removeEventListener('keydown', onKey); };
    }, [nodes, deleteNode, panel.open, undo, redo]);

    useEffect(function () {
      function beforeUnload(e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } }
      window.addEventListener('beforeunload', beforeUnload);
      return function () { window.removeEventListener('beforeunload', beforeUnload); };
    }, [dirty]);

    // Render the History modal (it lives in the page markup, outside #root, so
    // we build its rows imperatively and toggle the overlay's visibility).
    useEffect(function () {
      var overlay = document.getElementById('history-modal');
      var listEl = document.getElementById('history-list');
      if (!overlay || !listEl) return;
      overlay.style.display = historyView.open ? 'flex' : 'none';
      if (!historyView.open) return;

      listEl.innerHTML = '';
      if (historyView.items === null) {
        var loadingDiv = document.createElement('div');
        loadingDiv.className = 'modal-empty';
        loadingDiv.textContent = 'در حال بارگذاری…';
        listEl.appendChild(loadingDiv);
        return;
      }
      if (historyView.items.length === 0) {
        var emptyDiv = document.createElement('div');
        emptyDiv.className = 'modal-empty';
        emptyDiv.textContent = 'هنوز نسخه‌ای ذخیره نشده است.';
        listEl.appendChild(emptyDiv);
        return;
      }
      historyView.items.forEach(function (it) {
        var row = document.createElement('div');
        row.className = 'hist-row';

        var meta = document.createElement('div');
        meta.className = 'meta';
        var when = document.createElement('div');
        when.className = 'when';
        when.textContent = it.created_at || '—';
        var sub = document.createElement('div');
        sub.className = 'sub';
        var by = it.created_by ? ('توسط ' + it.created_by) : '';
        var note = it.note ? (' • ' + it.note) : '';
        sub.textContent = '#' + it.id + (by ? (' • ' + by) : '') + note;
        meta.appendChild(when);
        meta.appendChild(sub);

        var badge = document.createElement('span');
        badge.className = 'badge';
        badge.textContent = (it.node_count != null ? it.node_count : '?') + ' نود';

        var btn = document.createElement('button');
        btn.className = 'tb-btn primary';
        btn.style.padding = '6px 12px';
        btn.textContent = 'بازگردانی';
        btn.addEventListener('click', function () { restoreVersion(it.id); });

        row.appendChild(meta);
        row.appendChild(badge);
        row.appendChild(btn);
        listEl.appendChild(row);
      });
    }, [historyView, restoreVersion]);

    // ---- render ----
    var canvas = h('div', { ref: wrapRef, style: { position: 'absolute', inset: 0 } },
      h(RF.ReactFlow, {
        nodes: nodes,
        edges: edges,
        onInit: function (inst) { rfInstance.current = inst; },
        onNodesChange: handleNodesChange,
        onEdgesChange: handleEdgesChange,
        onConnect: onConnect,
        onConnectStart: onConnectStart,
        onConnectEnd: onConnectEnd,
        onNodeDoubleClick: onNodeDoubleClick,
        nodeTypes: nodeTypes,
        fitView: true,
        minZoom: 0.2,
        maxZoom: 2,
        deleteKeyCode: null, // we handle delete ourselves (cascade + confirm)
        proOptions: { hideAttribution: true }
      },
        h(RF.Background, { color: '#334155', gap: 18 }),
        h(RF.Controls, null),
        h(RF.MiniMap, {
          nodeColor: function (n) {
            var t = (n.data && n.data.type) || 'button';
            return ({ button: '#3b82f6', message: '#22c55e', action: '#a855f7', input: '#f59e0b', condition: '#ec4899', n8n: '#06b6d4' })[t] || '#64748b';
          },
          maskColor: 'rgba(15,23,42,0.7)'
        })
      ));

    // side panel
    var side = null;
    if (panel.open && panel.draft) {
      side = h('div', { className: 'side open' },
        h('div', { className: 'side-head' },
          h('h2', null, panel.isNew ? 'نود جدید' : 'ویرایش نود'),
          h('button', {
            className: 'btn', style: { padding: '4px 10px' },
            onClick: function () { setPanel({ open: false, isNew: false, draft: null, pendingParent: null, dropPos: null }); }
          }, '✕')
        ),
        h(NodeForm, {
          draft: panel.draft,
          setDraft: function (nd) { setPanel(Object.assign({}, panel, { draft: nd })); },
          isNew: panel.isNew
        }),
        h('div', { className: 'side-foot' },
          h('button', { className: 'btn primary', onClick: savePanel }, panel.isNew ? 'افزودن نود' : 'ذخیرهٔ تغییرات'),
          (!panel.isNew && panel.draft.id && panel.draft.id !== 'n_root')
            ? h('button', { className: 'btn danger', onClick: function () { deleteNode(panel.draft.id); } }, '🗑 حذف')
            : null
        )
      );
    }

    return h('div', { style: { position: 'absolute', inset: 0 } }, canvas, side);
  }

  // ---- Mount ---------------------------------------------------------------
  var container = document.getElementById('root');
  var root = ReactDOM.createRoot(container);
  root.render(
    h(RF.ReactFlowProvider, null, h(Editor, null))
  );
})();
