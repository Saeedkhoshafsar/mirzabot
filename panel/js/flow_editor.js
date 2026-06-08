/* =============================================================================
 * Visual Button Flow editor (Phase 2: canvas)
 * Renders the stored tree with React Flow (UMD/CDN, no build), lets the admin
 * drag nodes around, tracks unsaved changes and saves back to flow.php?api=save.
 * Node creation / editing / deletion / linking arrive in Phase 3.
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

  // ---- Main editor component ----------------------------------------------
  function Editor() {
    var nodesPair = RF.useNodesState([]);
    var edgesPair = RF.useEdgesState([]);
    var nodes = nodesPair[0], setNodes = nodesPair[1], onNodesChange = nodesPair[2];
    var edges = edgesPair[0], setEdges = edgesPair[1], onEdgesChange = edgesPair[2];

    var metaRef = useRef({});
    var dirtyState = useState(false);
    var dirty = dirtyState[0], setDirty = dirtyState[1];
    var loadingState = useState(true);
    var loading = loadingState[0], setLoading = loadingState[1];

    function setStatus(kind, text) {
      var el = document.getElementById('status');
      if (!el) return;
      el.className = 'status ' + kind;
      el.textContent = text;
    }

    var load = useCallback(function () {
      setLoading(true);
      apiGet('tree').then(function (res) {
        if (res && res.ok) {
          var rf = treeToRF(res.tree);
          metaRef.current = rf.meta;
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

    // mark dirty on any node/edge change after initial load
    var markDirty = useCallback(function () {
      setDirty(true);
      setStatus('dirty', 'ذخیره‌نشده');
    }, []);

    var handleNodesChange = useCallback(function (changes) {
      onNodesChange(changes);
      // position/dimension changes => dirty (ignore pure select)
      var meaningful = changes.some(function (c) {
        return c.type === 'position' || c.type === 'remove' || c.type === 'add';
      });
      if (meaningful) markDirty();
    }, [onNodesChange, markDirty]);

    var handleEdgesChange = useCallback(function (changes) {
      onEdgesChange(changes);
      var meaningful = changes.some(function (c) { return c.type === 'remove' || c.type === 'add'; });
      if (meaningful) markDirty();
    }, [onEdgesChange, markDirty]);

    var save = useCallback(function () {
      var tree = rfToTree(nodes, edges, metaRef.current);
      setStatus('dirty', 'در حال ذخیره…');
      apiPost('save', { tree: tree, note: 'canvas save' }).then(function (res) {
        if (res && res.ok) {
          if (res.tree) { metaRef.current = res.tree.meta || metaRef.current; }
          setDirty(false);
          setStatus('saved', 'ذخیره شد ✓');
        } else {
          setStatus('error', 'خطا: ' + ((res && res.error) || 'نامشخص'));
        }
      }).catch(function () { setStatus('error', 'خطای شبکه هنگام ذخیره'); });
    }, [nodes, edges]);

    var migrate = useCallback(function () {
      if (!confirm('دکمه‌های قدیمی به‌صورت نودهای فرزند منوی اصلی وارد می‌شوند. ادامه؟')) return;
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
    }, [setNodes, setEdges, markDirty]);

    // Wire the topbar buttons (rendered in PHP) to these callbacks.
    useEffect(function () {
      var sb = document.getElementById('btn-save');
      var rb = document.getElementById('btn-reload');
      var mb = document.getElementById('btn-migrate');
      function onSave() { save(); }
      function onReload() {
        if (dirty && !confirm('تغییرات ذخیره‌نشده از بین می‌رود. ادامه؟')) return;
        load();
      }
      function onMigrate() { migrate(); }
      if (sb) sb.addEventListener('click', onSave);
      if (rb) rb.addEventListener('click', onReload);
      if (mb) mb.addEventListener('click', onMigrate);
      return function () {
        if (sb) sb.removeEventListener('click', onSave);
        if (rb) rb.removeEventListener('click', onReload);
        if (mb) mb.removeEventListener('click', onMigrate);
      };
    }, [save, load, migrate, dirty]);

    // reflect dirty -> enable/disable save button
    useEffect(function () {
      var sb = document.getElementById('btn-save');
      if (sb) sb.disabled = !dirty;
    }, [dirty]);

    // warn on unload if dirty
    useEffect(function () {
      function beforeUnload(e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
      }
      window.addEventListener('beforeunload', beforeUnload);
      return function () { window.removeEventListener('beforeunload', beforeUnload); };
    }, [dirty]);

    return h(RF.ReactFlow, {
      nodes: nodes,
      edges: edges,
      onNodesChange: handleNodesChange,
      onEdgesChange: handleEdgesChange,
      nodeTypes: nodeTypes,
      fitView: true,
      minZoom: 0.2,
      maxZoom: 2,
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
    );
  }

  // ---- Mount ---------------------------------------------------------------
  var container = document.getElementById('root');
  var root = ReactDOM.createRoot(container);
  root.render(
    h(RF.ReactFlowProvider, null, h(Editor, null))
  );
})();
