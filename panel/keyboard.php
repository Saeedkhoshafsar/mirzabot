<?php
// ---------------------------------------------------------------------------
// DEPRECATED: the standalone "keyboard layout" page has been removed.
//
// Button layout / arrangement is now configured per-node inside the visual
// flow editor (flow.php → open a node that has more than one child → the
// «چیدمان دکمه‌ها» section). This stub keeps old bookmarks/links working by
// redirecting to the flow editor instead of 404-ing.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/inc/config.php';
require_auth();

header('Location: flow.php', true, 302);
echo '<!doctype html><meta charset="utf-8">'
   . '<meta http-equiv="refresh" content="0;url=flow.php">'
   . '<p style="font-family:sans-serif;direction:rtl">صفحهٔ «چیدمان کیبورد» حذف شده است. '
   . 'چیدمان دکمه‌ها اکنون داخل تنظیمات هر نود در '
   . '<a href="flow.php">ویرایشگر دکمه‌ها (درختی)</a> انجام می‌شود…</p>';
