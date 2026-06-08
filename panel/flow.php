<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/../automation.php';
require_once __DIR__ . '/../flow.php';
require_auth();

/*
 * =============================================================================
 *  Visual Button Flow — panel canvas (Step 12, Phase 2)
 * =============================================================================
 *  This page is BOTH:
 *    - a tiny JSON API (?api=...) the React Flow canvas talks to, and
 *    - the HTML page that hosts the canvas (React Flow loaded from CDN, no build).
 *
 *  API (all admin-authenticated via require_auth, CSRF-checked on writes):
 *    GET  flow.php?api=tree            -> { ok, tree }
 *    POST flow.php?api=save   {tree}   -> { ok, tree?, error? }
 *    GET  flow.php?api=history         -> { ok, items:[...] }
 *    POST flow.php?api=restore {id}    -> { ok, tree?, error? }
 *    POST flow.php?api=migrate         -> { ok, tree }   (build from legacy buttons)
 * =============================================================================
 */

$BOT_ID = 0;

// --------------------------------------------------------------------- API ---
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_GET['api'];
    $isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

    // Read JSON body for POST APIs.
    $body = [];
    if ($isPost) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }
        // CSRF: token sent in body or header.
        $token = $body['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!hash_equals($_SESSION['csrf'] ?? '', (string) $token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'csrf']);
            exit;
        }
    }

    try {
        if ($api === 'tree' && !$isPost) {
            echo json_encode(['ok' => true, 'tree' => get_button_flow($BOT_ID)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'save' && $isPost) {
            $tree = $body['tree'] ?? null;
            if (!is_array($tree)) {
                echo json_encode(['ok' => false, 'error' => 'no_tree']);
                exit;
            }
            $note = (string) ($body['note'] ?? '');
            $by = $_SESSION['admin_user'] ?? '';
            $res = set_button_flow($tree, $BOT_ID, $note, $by);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'history' && !$isPost) {
            echo json_encode(['ok' => true, 'items' => flow_history_list($BOT_ID, 20)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'restore' && $isPost) {
            $id = (int) ($body['id'] ?? 0);
            $res = flow_history_restore($id, $BOT_ID, $_SESSION['admin_user'] ?? '');
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'migrate' && $isPost) {
            $tree = flow_build_from_legacy($BOT_ID);
            echo json_encode(['ok' => true, 'tree' => $tree], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown_api']);
        exit;
    } catch (Throwable $e) {
        error_log('flow api error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'server_error']);
        exit;
    }
}

// --------------------------------------------------------------- PAGE --------
$csrf = csrf_token();
$nodeTypes = flow_node_types();
?>
<!doctype html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ویرایشگر بصری دکمه‌ها</title>

    <!-- React + React Flow from CDN (no build step) -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/reactflow@11.11.4/dist/umd/index.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/reactflow@11.11.4/dist/style.css">

    <style>
        @import url(https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap);

        * {
            box-sizing: border-box;
            font-family: 'Vazirmatn', sans-serif;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: #0f172a;
            color: #e2e8f0;
        }

        #app {
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            flex-wrap: wrap;
        }

        .topbar h1 {
            font-size: 15px;
            margin: 0;
            font-weight: 700;
            color: #f8fafc;
        }

        .spacer {
            flex: 1;
        }

        .tb-btn {
            background: #334155;
            color: #e2e8f0;
            border: 1px solid #475569;
            border-radius: 9px;
            padding: 8px 14px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .tb-btn:hover {
            background: #3e4c63;
        }

        .tb-btn.primary {
            background: #2563eb;
            border-color: #3b82f6;
            color: #fff;
            font-weight: 700;
        }

        .tb-btn.primary:hover {
            background: #1d4ed8;
        }

        .tb-btn.ghost {
            background: transparent;
        }

        .tb-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .canvas-wrap {
            flex: 1;
            position: relative;
        }

        .hint {
            font-size: 12px;
            color: #94a3b8;
        }

        .status {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .status.saved {
            background: #14532d;
            color: #bbf7d0;
        }

        .status.dirty {
            background: #78350f;
            color: #fde68a;
        }

        .status.error {
            background: #7f1d1d;
            color: #fecaca;
        }

        /* Node visual */
        .rf-node {
            min-width: 150px;
            border-radius: 12px;
            padding: 10px 12px;
            border: 2px solid #475569;
            background: #1e293b;
            color: #e2e8f0;
            font-size: 13px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .35);
        }

        .rf-node .nt {
            font-size: 10px;
            opacity: .7;
            margin-bottom: 2px;
        }

        .rf-node .nl {
            font-weight: 700;
            font-size: 13px;
            word-break: break-word;
        }

        .rf-node.t-button {
            border-color: #3b82f6;
        }

        .rf-node.t-message {
            border-color: #22c55e;
        }

        .rf-node.t-action {
            border-color: #a855f7;
        }

        .rf-node.t-input {
            border-color: #f59e0b;
        }

        .rf-node.t-condition {
            border-color: #ec4899;
        }

        .rf-node.t-n8n {
            border-color: #06b6d4;
        }

        .rf-node.system {
            background: #312e2a;
            border-style: dashed;
        }

        .rf-node.selected {
            outline: 2px solid #fff;
        }

        .badge {
            display: inline-block;
            font-size: 9px;
            background: #b45309;
            color: #fff;
            border-radius: 6px;
            padding: 1px 5px;
            margin-top: 4px;
        }

        .legend {
            position: absolute;
            bottom: 14px;
            inset-inline-start: 14px;
            background: rgba(30, 41, 59, .9);
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 11px;
            z-index: 5;
            line-height: 1.9;
        }

        .legend i {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 3px;
            margin-inline-start: 6px;
            vertical-align: middle;
        }

        .react-flow__attribution {
            display: none;
        }

        /* ---- Side panel (create / edit node) ---- */
        .side {
            position: absolute;
            top: 0;
            inset-inline-end: 0;
            bottom: 0;
            width: 360px;
            max-width: 90vw;
            background: #111827;
            border-inline-start: 1px solid #334155;
            box-shadow: -8px 0 24px rgba(0, 0, 0, .4);
            z-index: 20;
            display: flex;
            flex-direction: column;
            transform: translateX(-100%);
            transition: transform .18s ease;
        }

        html[dir=rtl] .side {
            transform: translateX(100%);
        }

        .side.open {
            transform: translateX(0);
        }

        .side-head {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 14px;
            border-bottom: 1px solid #334155;
        }

        .side-head h2 {
            font-size: 14px;
            margin: 0;
            font-weight: 700;
            flex: 1;
        }

        .side-body {
            padding: 14px;
            overflow-y: auto;
            flex: 1;
        }

        .side-foot {
            padding: 12px 14px;
            border-top: 1px solid #334155;
            display: flex;
            gap: 8px;
        }

        .fld {
            margin-bottom: 12px;
        }

        .fld label {
            display: block;
            font-size: 12px;
            color: #cbd5e1;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .fld .hlp {
            font-size: 10.5px;
            color: #7c8aa0;
            margin-top: 3px;
            line-height: 1.6;
        }

        .fld input[type=text],
        .fld input[type=number],
        .fld textarea,
        .fld select {
            width: 100%;
            background: #1e293b;
            border: 1px solid #475569;
            border-radius: 8px;
            color: #e2e8f0;
            padding: 8px 10px;
            font-size: 13px;
        }

        .fld textarea {
            resize: vertical;
            min-height: 60px;
        }

        .fld.row {
            display: flex;
            gap: 8px;
        }

        .fld.row>div {
            flex: 1;
        }

        .chk {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12.5px;
            color: #cbd5e1;
            cursor: pointer;
            margin-bottom: 8px;
        }

        .chk input {
            width: 16px;
            height: 16px;
            accent-color: #2563eb;
        }

        .grp {
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 10px 12px 4px;
            margin-bottom: 14px;
        }

        .grp .grp-t {
            font-size: 11.5px;
            font-weight: 700;
            color: #93c5fd;
            margin-bottom: 9px;
        }

        .btn {
            border-radius: 9px;
            padding: 9px 14px;
            font-size: 13px;
            cursor: pointer;
            border: 1px solid #475569;
            background: #334155;
            color: #e2e8f0;
        }

        .btn.primary {
            background: #2563eb;
            border-color: #3b82f6;
            color: #fff;
            font-weight: 700;
            flex: 1;
        }

        .btn.danger {
            background: #7f1d1d;
            border-color: #b91c1c;
            color: #fecaca;
        }

        .btn:hover {
            filter: brightness(1.12);
        }

        .sys-warn {
            background: #422006;
            border: 1px solid #b45309;
            color: #fde68a;
            font-size: 11.5px;
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 12px;
            line-height: 1.7;
        }

        .toolbar-tip {
            position: absolute;
            top: 14px;
            inset-inline-start: 14px;
            background: rgba(30, 41, 59, .9);
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 11px;
            z-index: 5;
            color: #94a3b8;
            line-height: 1.8;
            max-width: 230px;
        }

        /* ---- History modal ---- */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, .72);
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-box {
            width: min(560px, 92vw);
            max-height: 80vh;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 14px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .5);
        }
        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid #1e293b;
        }
        .modal-head h2 { margin: 0; font-size: 16px; }
        .modal-body { padding: 10px 14px; overflow: auto; }
        .modal-foot {
            padding: 10px 18px;
            border-top: 1px solid #1e293b;
            font-size: 11px;
            color: #94a3b8;
        }
        .modal-empty { color: #94a3b8; text-align: center; padding: 22px; }
        .hist-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #1e293b;
            border-radius: 10px;
            margin-bottom: 8px;
            background: #111c33;
        }
        .hist-row .meta { flex: 1; min-width: 0; }
        .hist-row .meta .when { font-size: 13px; color: #e2e8f0; }
        .hist-row .meta .sub { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .hist-row .badge {
            background: #1e293b;
            border-radius: 8px;
            padding: 2px 8px;
            font-size: 11px;
            color: #cbd5e1;
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <div id="app">
        <div class="topbar">
            <a href="index.php" class="tb-btn ghost">⟵ پنل</a>
            <h1>🌳 ویرایشگر بصری دکمه‌های ربات</h1>
            <span id="status" class="status saved">ذخیره‌شده</span>
            <span class="spacer"></span>
            <span class="hint">دابل‌کلیک=ویرایش • از پورت پایین بکشید=فرزند</span>
            <button id="btn-undo" class="tb-btn" title="واگرد (Ctrl+Z)" disabled>↶</button>
            <button id="btn-redo" class="tb-btn" title="ازنو (Ctrl+Y)" disabled>↷</button>
            <button id="btn-history" class="tb-btn" title="نسخه‌های ذخیره‌شده">🕓 تاریخچه</button>
            <button id="btn-add" class="tb-btn" title="افزودن نود ریشه‌ای جدید">➕ نود جدید</button>
            <button id="btn-migrate" class="tb-btn" title="ساخت درخت از دکمه‌های قدیمی">وارد کردن دکمه‌های قبلی</button>
            <button id="btn-reload" class="tb-btn">بارگذاری مجدد</button>
            <button id="btn-save" class="tb-btn primary" disabled>💾 ذخیره</button>
        </div>
        <div class="canvas-wrap">
            <div id="root" style="position:absolute;inset:0"></div>
            <div class="toolbar-tip">
                💡 برای ساخت زیرشاخه، از نقطهٔ پایین یک نود بکشید و در فضای خالی رها کنید.
                دابل‌کلیک روی نود = ویرایش. انتخاب + کلید Delete = حذف.
            </div>
            <div class="legend">
                <div><i style="background:#3b82f6"></i>دکمه &nbsp; <i style="background:#22c55e"></i>پیام
                    &nbsp; <i style="background:#a855f7"></i>اکشن</div>
                <div><i style="background:#f59e0b"></i>ورودی &nbsp; <i style="background:#ec4899"></i>شرط
                    &nbsp; <i style="background:#06b6d4"></i>n8n</div>
            </div>
        </div>
    </div>

    <!-- History (version rollback) modal — populated by flow_editor.js -->
    <div id="history-modal" class="modal-overlay" style="display:none">
        <div class="modal-box">
            <div class="modal-head">
                <h2>🕓 نسخه‌های ذخیره‌شده</h2>
                <button id="history-close" class="tb-btn ghost" style="padding:4px 10px">✕</button>
            </div>
            <div id="history-list" class="modal-body">
                <div class="modal-empty">در حال بارگذاری…</div>
            </div>
            <div class="modal-foot">
                <span class="hint">بازگردانی، نسخهٔ فعلی را هم به‌عنوان یک نسخهٔ تازه ذخیره می‌کند (پس قابل واگرد است).</span>
            </div>
        </div>
    </div>

    <script>
        window.FLOW_CSRF = <?= json_encode($csrf) ?>;
        window.FLOW_NODE_TYPES = <?= json_encode($nodeTypes) ?>;
    </script>
    <script src="js/flow_editor.js"></script>
</body>

</html>
