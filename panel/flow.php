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
    </style>
</head>

<body>
    <div id="app">
        <div class="topbar">
            <a href="index.php" class="tb-btn ghost">⟵ پنل</a>
            <h1>🌳 ویرایشگر بصری دکمه‌های ربات</h1>
            <span id="status" class="status saved">ذخیره‌شده</span>
            <span class="spacer"></span>
            <span class="hint">نودها را بکشید تا جابه‌جا شوند</span>
            <button id="btn-migrate" class="tb-btn" title="ساخت درخت از دکمه‌های قدیمی">وارد کردن دکمه‌های قبلی</button>
            <button id="btn-reload" class="tb-btn">بارگذاری مجدد</button>
            <button id="btn-save" class="tb-btn primary" disabled>💾 ذخیره</button>
        </div>
        <div class="canvas-wrap">
            <div id="root" style="position:absolute;inset:0"></div>
            <div class="legend">
                <div><i style="background:#3b82f6"></i>دکمه &nbsp; <i style="background:#22c55e"></i>پیام
                    &nbsp; <i style="background:#a855f7"></i>اکشن</div>
                <div><i style="background:#f59e0b"></i>ورودی &nbsp; <i style="background:#ec4899"></i>شرط
                    &nbsp; <i style="background:#06b6d4"></i>n8n</div>
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
