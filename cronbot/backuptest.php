<?php
// ---------------------------------------------------------------------------
// backuptest.php — one-click backup diagnostic.
//
// Open this file in a browser (https://YOUR-DOMAIN/cronbot/backuptest.php) to
// see, in plain language, WHY the nightly backup fails. It runs the exact same
// mysqldump logic as backupbot.php but, instead of sending a Telegram message,
// it prints a readable Persian report directly in the page. It does NOT send
// anything to Telegram and deletes any temporary dump file it creates.
//
// Safety:
//   * It never prints the database password.
//   * It only reads/creates a temporary dump file and removes it afterwards.
// ---------------------------------------------------------------------------

date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';

header('Content-Type: text/html; charset=utf-8');

function line($emoji, $text)
{
    echo '<div style="margin:6px 0">' . $emoji . ' ' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
}

echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>تست بکاپ</title></head><body style="font-family:Tahoma,Arial,sans-serif;background:#0f1115;color:#e6e6e6;padding:18px;line-height:1.9">';
echo '<h2 style="margin:0 0 12px">🩺 تست بکاپ دیتابیس</h2>';

$dbhost = empty($dbhost) ? 'localhost' : $dbhost;

// 1) Is mysqldump installed?
$whichDump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
if ($whichDump === '') {
    line('❌', 'برنامهٔ mysqldump روی این سرور نصب نیست.');
    line('🛠️', 'راه‌حل: بستهٔ mysql-client یا mariadb-client را روی سرور نصب کنید (مثلاً: apt install mariadb-client).');
    echo '</body></html>';
    exit;
}
line('✅', 'mysqldump نصب است: ' . $whichDump);

// 2) Try the dump with the same SSL fallbacks as backupbot.php.
$tmp = sys_get_temp_dir() . '/backuptest_' . date('Ymd_His') . '.sql';
$err = $tmp . '.err';
$sslStrategies = ['--ssl-mode=DISABLED', '--skip-ssl', ''];

function build_cmd($dbhost, $usernamedb, $passworddb, $dbname, $tmp, $err, $sslFlag)
{
    $cmd = 'mysqldump'
        . ' -h ' . escapeshellarg($dbhost)
        . ' -u ' . escapeshellarg($usernamedb)
        . ' -p' . escapeshellarg($passworddb)
        . ' --no-tablespaces';
    if ($sslFlag !== '') {
        $cmd .= ' ' . $sslFlag;
    }
    $cmd .= ' ' . escapeshellarg($dbname)
        . ' > ' . escapeshellarg($tmp)
        . ' 2> ' . escapeshellarg($err);
    return $cmd;
}

$success = false;
$lastErr = '';
$lastRv = 1;
$usedFlag = null;

foreach ($sslStrategies as $sslFlag) {
    @unlink($tmp);
    @unlink($err);
    $cmd = build_cmd($dbhost, $usernamedb, $passworddb, $dbname, $tmp, $err, $sslFlag);
    $out = [];
    $rv = 0;
    exec($cmd, $out, $rv);
    $stderr = @file_exists($err) ? trim((string) @file_get_contents($err)) : '';
    $fileOk = @file_exists($tmp) && @filesize($tmp) > 0;
    if ($rv === 0 && $fileOk) {
        $success = true;
        $usedFlag = $sslFlag === '' ? '(بدون فلگ SSL)' : $sslFlag;
        break;
    }
    $lastErr = $stderr;
    $lastRv = ($rv === 0 ? 1 : $rv);
    // Only try the next SSL flag if this failure was about an unknown SSL option.
    if (stripos($stderr, 'ssl') === false || stripos($stderr, 'unknown') === false) {
        break;
    }
}

if ($success) {
    line('✅', 'بکاپ با موفقیت گرفته شد. مشکلی وجود ندارد.');
    line('ℹ️', 'حالت اتصال موفق: ' . $usedFlag);
    line('📦', 'حجم فایل تستی: ' . number_format((float) @filesize($tmp)) . ' بایت (این فایل تستی پاک شد).');
} else {
    line('❌', 'بکاپ شکست خورد. کد خروج: ' . (int) $lastRv);

    $hint = '';
    if (stripos($lastErr, 'access denied') !== false) {
        $hint = 'نام کاربری یا رمز دیتابیس اشتباه است یا دسترسی کافی ندارد. مقادیر config.php را بررسی کنید.';
    } elseif (stripos($lastErr, "can't connect") !== false || stripos($lastErr, 'connection refused') !== false) {
        $hint = 'اتصال به دیتابیس برقرار نشد. مطمئن شوید سرویس MySQL/MariaDB روشن است و آدرس میزبان درست است.';
    } elseif (stripos($lastErr, 'unknown database') !== false) {
        $hint = 'نام دیتابیس پیدا نشد. مقدار نام دیتابیس در config.php را بررسی کنید.';
    } elseif (stripos($lastErr, 'no space') !== false || stripos($lastErr, 'disk full') !== false) {
        $hint = 'فضای دیسک سرور پر شده است. مقداری فضا آزاد کنید.';
    } elseif (stripos($lastErr, 'unknown option') !== false || stripos($lastErr, 'unknown variable') !== false) {
        $hint = 'نسخهٔ mysqldump این گزینه را پشتیبانی نمی‌کند (ربات خودش حالت‌های دیگر را امتحان کرد).';
    }

    if ($hint !== '') {
        line('🔎', 'علت احتمالی: ' . $hint);
    }
    if ($lastErr !== '') {
        echo '<div style="margin:10px 0 4px">🧾 پیام دقیق خطا:</div>';
        $shown = function_exists('mb_substr') ? mb_substr($lastErr, 0, 1500) : substr($lastErr, 0, 1500);
        echo '<pre style="white-space:pre-wrap;background:#1b1f27;border:1px solid #2a2f3a;border-radius:8px;padding:12px;color:#ff9b9b">'
            . htmlspecialchars($shown, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        line('🧾', 'mysqldump هیچ پیام خطایی چاپ نکرد؛ احتمالاً توسط سرور متوقف شده یا اجازهٔ نوشتن فایل را نداشته است.');
    }
}

@unlink($tmp);
@unlink($err);

echo '<hr style="border-color:#2a2f3a;margin:16px 0">';
echo '<div style="color:#9aa">این صفحه فقط برای تشخیص است و چیزی به تلگرام نمی‌فرستد. پس از رفع مشکل می‌توانید این فایل را حذف کنید.</div>';
echo '</body></html>';
