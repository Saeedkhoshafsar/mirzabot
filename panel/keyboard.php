<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/../automation.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// 1) Custom inline-button manager (normal HTML form POST, NOT the React JSON).
//    Buttons are stored in automation_config['buttons'] and rendered on the
//    bot's main inline keyboard (see keyboard.php in the project root).
// ---------------------------------------------------------------------------
$cbFlash = '';
if ($method === 'POST' && isset($_POST['cb_save'])) {
    $cfg = get_automation_config(0);
    if (!is_array($cfg)) {
        $cfg = [];
    }
    $buttons = [];
    $bLabels = $_POST['btn_label']   ?? [];
    $bMsgs   = $_POST['btn_message'] ?? [];
    $bUrls   = $_POST['btn_url']     ?? [];
    $bActive = $_POST['btn_active']  ?? [];
    foreach ($bLabels as $i => $lbl) {
        $lbl = trim((string) $lbl);
        if ($lbl === '') {
            continue;
        }
        $url = trim((string) ($bUrls[$i] ?? ''));
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = '';
        }
        $buttons[] = [
            'id'      => 'b' . substr(md5($lbl . $i . microtime()), 0, 8),
            'label'   => $lbl,
            'event'   => 'custom.trigger',
            'message' => trim((string) ($bMsgs[$i] ?? '')),
            'url'     => $url,
            'active'  => isset($bActive[$i]),
        ];
    }
    $cfg['buttons'] = $buttons;
    set_automation_config($cfg, 0);
    header('Location: keyboard.php?saved=1#custom-buttons');
    exit;
}

// ---------------------------------------------------------------------------
// 2) React drag-sort save (JSON body) + reset action (unchanged behaviour).
// ---------------------------------------------------------------------------
$keyboard = json_decode(file_get_contents("php://input"), true);
if ($method == "POST" && is_array($keyboard)) {
    $keyboardmain = ['keyboard' => []];
    $keyboardmain['keyboard'] = $keyboard;
    update("setting", "keyboardmain", json_encode($keyboardmain), null, null);
} else {
    $keyboardmain = '{"keyboard":[[{"text":"text_sell"},{"text":"text_extend"}],[{"text":"text_usertest"},{"text":"text_wheel_luck"}],[{"text":"text_Purchased_services"},{"text":"accountwallet"}],[{"text":"text_affiliates"},{"text":"text_Tariff_list"}],[{"text":"text_support"},{"text":"text_help"}]]}';
    $action = filter_input(INPUT_GET, 'action');
    if ($action === "reaset") {
        update("setting", "keyboardmain", $keyboardmain, null, null);
        header('Location: keyboard.php');
        exit;
    }
}

$customButtons = function_exists('automation_buttons') ? automation_buttons(0) : [];
?>

<!doctype html>
<html lang="FA">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $textbotlang['panel']['keyboardManageTitle'] ?></title>

    <script type="module" crossorigin src="js/sort_keyboard.js"></script>
    <link rel="stylesheet" crossorigin href="css/sort_keyboard.css">
    <style>
        @import url(https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap);

        * {
            font-family: 'Vazirmatn' !important;
        }

        button {
            font-family: yekan;
        }

        .btnback {
            position: fixed;
            top: 10px;
            left: 10px;
            padding: 7px;
            background-color: #3d3d3d;
            color: #fff;
            border-radius: 6px;
            font-family: yekan;
            font-size: 13px;
            font-weight: bold;
        }

        .btndefult {
            position: fixed;
            top: 10px;
            left: 150px;
            padding: 7px;
            background-color: #fff;
            border: 2px solid #3d3d3d;
            color: #3d3d3d;
            border-radius: 6px;
            font-family: yekan;
            font-size: 13px;
            font-weight: bold;
        }
    </style>
</head>

<body style="background:#eef2f7">
    <a class="btnback" href="index.php"><?= $textbotlang['panel']['keyboardSortHint'] ?></a>
    <a class="btndefult" href="keyboard.php?action=reaset"><?= $textbotlang['panel']['keyboardSaveBtn'] ?></a>

    <!-- ================================================================ -->
    <!-- 1) Custom buttons manager  (FIRST so it's always visible)         -->
    <!-- ================================================================ -->
    <div id="custom-buttons" style="max-width:760px;margin:60px auto 18px;padding:0 14px">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 18px 22px;box-shadow:0 1px 3px rgba(0,0,0,.05)">
            <h2 style="margin:0 0 4px;font-size:18px;color:#0f172a">➕ دکمه‌های سفارشی ربات</h2>
            <p style="margin:0 0 16px;font-size:13px;color:#64748b;line-height:1.9">
                این دکمه‌ها زیر منوی اصلی ربات (به‌صورت شیشه‌ای) به کاربر نمایش داده می‌شوند.
                می‌توانید یک <b>پیام</b> برای ارسال هنگام کلیک تعیین کنید، یا یک <b>لینک</b> بدهید.
                هر دکمه هم‌زمان یک رویداد <code>custom.trigger</code> برای n8n ارسال می‌کند.
            </p>

            <?php if (isset($_GET['saved'])): ?>
                <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:10px;padding:9px 14px;margin-bottom:14px;font-size:13px">
                    ✅ دکمه‌ها ذخیره شدند. (<?= count($customButtons) ?> دکمه فعال/ذخیره‌شده)
                </div>
            <?php endif; ?>

            <form method="post" action="keyboard.php">
                <input type="hidden" name="cb_save" value="1">
                <div id="cb-rows">
                    <?php
                    $rows = $customButtons ?: [];
                    if (empty($rows)) {
                        $rows = [['label' => '', 'message' => '', 'url' => '', 'active' => true]];
                    }
                    foreach ($rows as $i => $b):
                        ?>
                        <div class="cb-row" style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:12px;background:#f8fafc">
                            <div style="display:grid;grid-template-columns:1fr;gap:10px">
                                <div>
                                    <label style="font-size:12px;color:#475569;display:block;margin-bottom:4px">متن دکمه</label>
                                    <input type="text" name="btn_label[<?= $i ?>]" value="<?= htmlspecialchars((string) ($b['label'] ?? '')) ?>"
                                        placeholder="مثلاً: درخواست مشاوره"
                                        style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;box-sizing:border-box">
                                </div>
                                <div>
                                    <label style="font-size:12px;color:#475569;display:block;margin-bottom:4px">پیام هنگام کلیک (اختیاری)</label>
                                    <input type="text" name="btn_message[<?= $i ?>]" value="<?= htmlspecialchars((string) ($b['message'] ?? '')) ?>"
                                        placeholder="مثلاً: لطفاً سوال خود را بنویسید"
                                        style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;box-sizing:border-box">
                                </div>
                                <div>
                                    <label style="font-size:12px;color:#475569;display:block;margin-bottom:4px">لینک (اختیاری، با https://)</label>
                                    <input type="url" name="btn_url[<?= $i ?>]" value="<?= htmlspecialchars((string) ($b['url'] ?? '')) ?>"
                                        placeholder="https://example.com" dir="ltr"
                                        style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;box-sizing:border-box">
                                </div>
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#0f172a;cursor:pointer">
                                        <input type="checkbox" name="btn_active[<?= $i ?>]" value="1" <?= !empty($b['active']) ? 'checked' : '' ?>>
                                        فعال باشد
                                    </label>
                                    <button type="button" class="cb-del"
                                        style="background:#fee2e2;color:#b91c1c;border:none;border-radius:8px;padding:7px 14px;font-size:13px;cursor:pointer">حذف</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="cb-add"
                    style="background:#e0f2fe;color:#0369a1;border:1px dashed #38bdf8;border-radius:10px;padding:10px 16px;font-size:14px;cursor:pointer;width:100%;margin-bottom:14px">
                    + افزودن دکمه جدید
                </button>

                <button type="submit"
                    style="background:#0f172a;color:#fff;border:none;border-radius:10px;padding:11px 22px;font-size:15px;font-weight:bold;cursor:pointer;width:100%">
                    ذخیره دکمه‌ها
                </button>
            </form>
        </div>
    </div>

    <!-- ================================================================ -->
    <!-- 2) React drag-sort app  (orders the MAIN reply keyboard)          -->
    <!-- ================================================================ -->
    <div style="max-width:760px;margin:0 auto 12px;padding:0 14px">
        <div style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:12px;padding:10px 14px;color:#475569;font-size:13px;line-height:1.9">
            <b>ترتیب دکمه‌های اصلی منو:</b> برای جابه‌جا کردن دکمه‌های پیش‌فرض ربات،
            آن‌ها را در کادر زیر بکشید و رها کنید. (این بخش دکمه جدید نمی‌سازد.)
        </div>
    </div>
    <div id="root"></div>

    <script>
        (function () {
            var rows = document.getElementById('cb-rows');
            var addBtn = document.getElementById('cb-add');
            function nextIndex() {
                return rows.querySelectorAll('.cb-row').length;
            }
            function rowHtml(i) {
                return '<div class="cb-row" style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:12px;background:#f8fafc">' +
                    '<div style="display:grid;grid-template-columns:1fr;gap:10px">' +
                    '<div><label style="font-size:12px;color:#475569;display:block;margin-bottom:4px">متن دکمه</label>' +
                    '<input type="text" name="btn_label[' + i + ']" placeholder="مثلاً: درخواست مشاوره" style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;box-sizing:border-box"></div>' +
                    '<div><label style="font-size:12px;color:#475569;display:block;margin-bottom:4px">پیام هنگام کلیک (اختیاری)</label>' +
                    '<input type="text" name="btn_message[' + i + ']" placeholder="مثلاً: لطفاً سوال خود را بنویسید" style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;box-sizing:border-box"></div>' +
                    '<div><label style="font-size:12px;color:#475569;display:block;margin-bottom:4px">لینک (اختیاری، با https://)</label>' +
                    '<input type="url" name="btn_url[' + i + ']" placeholder="https://example.com" dir="ltr" style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;box-sizing:border-box"></div>' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px">' +
                    '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#0f172a;cursor:pointer"><input type="checkbox" name="btn_active[' + i + ']" value="1" checked> فعال باشد</label>' +
                    '<button type="button" class="cb-del" style="background:#fee2e2;color:#b91c1c;border:none;border-radius:8px;padding:7px 14px;font-size:13px;cursor:pointer">حذف</button>' +
                    '</div></div></div>';
            }
            addBtn.addEventListener('click', function () {
                var wrap = document.createElement('div');
                wrap.innerHTML = rowHtml(nextIndex());
                rows.appendChild(wrap.firstChild);
            });
            rows.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('cb-del')) {
                    var row = e.target.closest('.cb-row');
                    if (row) { row.remove(); }
                }
            });
        })();
    </script>
</body>

</html>