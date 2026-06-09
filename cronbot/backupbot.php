<?php
require_once '../config.php';
require_once '../function.php';
$textbotlang = languagechange();
require_once '../botapi.php';

$reportbackup = select("topicid", "idreport", "report", "backupfile", "select")['idreport'];
$destination = getcwd();
$setting = select("setting", "*");
$sourcefir = dirname($destination);
$botlist = select("botsaz", "*", null, null, "fetchAll");
if ($botlist) {
    foreach ($botlist as $bot) {
        $folderName = $bot['id_user'] . $bot['username'];
        @unlink('file.zip'); // start clean so a previous run can't leak into this one
        shell_exec("zip -r $destination/file.zip $sourcefir/vpnbot/$folderName/data $sourcefir/vpnbot/$folderName/product.json $sourcefir/vpnbot/$folderName/product_name.json 2>/dev/null");
        // Only send if the archive was actually created and is non-empty;
        // otherwise skip this bot instead of sending a broken/empty file.
        if (@file_exists('file.zip') && @filesize('file.zip') > 0) {
            telegram('sendDocument', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $reportbackup,
                'document' => new CURLFile('file.zip'),
                'caption' => "@{$bot['username']} | {$bot['id_user']}",
            ]);
        }
        @unlink('file.zip');
    }
}

$backup_file_name = 'backup_' . date("Y-m-d") . '.sql';
$zip_file_name = 'backup_' . date("Y-m-d") . '.zip';
$dbhost = empty($dbhost) ? "localhost" : $dbhost;

// ---------------------------------------------------------------------------
// Database dump with self-diagnosing fallbacks.
//
// The old code ran a single mysqldump and, on any non-zero exit, sent a bare
// "❌ خطا در بکاپ گیری" with NO detail — impossible to debug. We now:
//   1) Try mysqldump with --ssl-mode=DISABLED (works on Oracle MySQL clients).
//   2) If that fails because the flag is unknown (common on MariaDB's
//      mysqldump, which uses --skip-ssl instead), retry without it.
//   3) As a last resort retry with no SSL flag at all.
//   4) Capture stderr (into a .err file) and report the real reason in
//      Telegram, plus a short human hint for the most common causes.
// A dump is only considered successful if mysqldump exited 0 AND produced a
// non-empty file (a 0-byte file means it failed silently / disk full).
// ---------------------------------------------------------------------------

// Build the dump command for a given SSL strategy. We escape every dynamic
// value so a password with special characters can't break the shell line.
function backup_build_dump_command($dbhost, $usernamedb, $passworddb, $dbname, $backup_file_name, $sslFlag)
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
        . ' > ' . escapeshellarg($backup_file_name)
        . ' 2> ' . escapeshellarg($backup_file_name . '.err');
    return $cmd;
}

// Try the SSL strategies in order until one works.
$sslStrategies = ['--ssl-mode=DISABLED', '--skip-ssl', ''];
$return_var = 1;
$dumpStderr = '';
$mysqldumpMissing = false;

// Detect a missing mysqldump binary up front for a clearer message.
$whichDump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
if ($whichDump === '') {
    $mysqldumpMissing = true;
}

if (!$mysqldumpMissing) {
    foreach ($sslStrategies as $sslFlag) {
        @unlink($backup_file_name);
        @unlink($backup_file_name . '.err');
        $command = backup_build_dump_command($dbhost, $usernamedb, $passworddb, $dbname, $backup_file_name, $sslFlag);
        $out = [];
        $rv = 0;
        exec($command, $out, $rv);
        $dumpStderr = @file_exists($backup_file_name . '.err')
            ? trim((string) @file_get_contents($backup_file_name . '.err'))
            : '';
        $fileOk = @file_exists($backup_file_name) && @filesize($backup_file_name) > 0;
        if ($rv === 0 && $fileOk) {
            $return_var = 0; // success
            break;
        }
        // If this failure is NOT about an unknown SSL option, retrying with a
        // different SSL flag won't help — stop and report the real error.
        if (stripos($dumpStderr, 'ssl') === false || stripos($dumpStderr, 'unknown') === false) {
            $return_var = ($rv === 0 ? 1 : $rv);
            break;
        }
        $return_var = ($rv === 0 ? 1 : $rv);
    }
}

@unlink($backup_file_name . '.err');

if ($return_var !== 0) {
    // Turn the raw error into a short, human-readable hint.
    $hint = '';
    if ($mysqldumpMissing) {
        $hint = 'برنامهٔ mysqldump روی سرور نصب نیست (بستهٔ mysql-client / mariadb-client را نصب کنید).';
    } elseif (stripos($dumpStderr, 'access denied') !== false) {
        $hint = 'نام کاربری یا رمز دیتابیس اشتباه است یا دسترسی کافی ندارد.';
    } elseif (stripos($dumpStderr, "can't connect") !== false || stripos($dumpStderr, 'connection refused') !== false) {
        $hint = 'اتصال به دیتابیس برقرار نشد (سرویس MySQL/MariaDB در حال اجرا نیست؟).';
    } elseif (stripos($dumpStderr, 'unknown database') !== false) {
        $hint = 'نام دیتابیس پیدا نشد.';
    } elseif (stripos($dumpStderr, 'no space') !== false || stripos($dumpStderr, 'disk full') !== false) {
        $hint = 'فضای دیسک سرور پر شده است.';
    } elseif (stripos($dumpStderr, 'unknown option') !== false || stripos($dumpStderr, 'unknown variable') !== false) {
        $hint = 'نسخهٔ mysqldump این گزینه را پشتیبانی نمی‌کند.';
    }

    // Keep the Telegram message short: Telegram captions/text have limits and
    // the raw dump error can be long, so trim it.
    $detail = $dumpStderr !== ''
        ? (function_exists('mb_substr') ? mb_substr($dumpStderr, 0, 600) : substr($dumpStderr, 0, 600))
        : '';

    // mysqldump sometimes fails WITHOUT writing anything to stderr (e.g. it
    // was killed, the shell couldn't spawn it, or the output redirect failed).
    // In that case fall back to whatever diagnostic info we do have, so the
    // Telegram message is NEVER just a bare "❌" with no clue.
    if ($detail === '') {
        if ($mysqldumpMissing) {
            $detail = 'دستور mysqldump روی سرور پیدا نشد (در PATH نیست).';
        } else {
            $detail = 'mysqldump بدون پیام خطا و با کد خروج ' . (int) $return_var . ' متوقف شد '
                . '(فایل خروجی ' . ((@file_exists($backup_file_name) && @filesize($backup_file_name) > 0) ? 'ساخته شد ولی ناقص بود' : 'اصلاً ساخته نشد') . ').';
        }
    }
    if ($hint === '' && $detail !== '') {
        $hint = 'برای دیدن علت دقیق، آدرس cronbot/backuptest.php را در مرورگر باز کنید.';
    }

    $msg = $textbotlang['keyboard']['backupError'];
    if ($hint !== '') {
        $msg .= "\n\n🔎 علت احتمالی: " . $hint;
    }
    if ($detail !== '') {
        $msg .= "\n\n🧾 پیام خطا:\n" . $detail;
    }

    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $reportbackup,
        'text' => $msg,
    ]);
    @unlink($backup_file_name);
} else {
    telegram('sendDocument', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $reportbackup,
        'document' => new CURLFile($backup_file_name),
        'caption' => $textbotlang['hardcoded']['backupDatabaseCaption'],
    ]);
    unlink($backup_file_name);
}