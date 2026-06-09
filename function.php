<?php
require_once 'vendor/autoload.php';
require 'config.php';
require 'vendor/autoload.php';
ini_set('error_log', 'error_log');

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

#-----------shell helper utilities------------#
function isShellExecAvailable()
{
    static $isAvailable;

    if ($isAvailable !== null) {
        return $isAvailable;
    }

    if (!function_exists('shell_exec')) {
        $isAvailable = false;
        return $isAvailable;
    }

    $disabledFunctions = ini_get('disable_functions');
    if (!empty($disabledFunctions) && stripos($disabledFunctions, 'shell_exec') !== false) {
        $isAvailable = false;
        return $isAvailable;
    }

    $isAvailable = true;
    return $isAvailable;
}

function getCrontabBinary()
{
    static $resolvedPath;

    if ($resolvedPath !== null) {
        return $resolvedPath ?: null;
    }

    $candidateDirectories = [
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/sbin',
        '/sbin',
    ];

    $environmentPath = getenv('PATH');
    if ($environmentPath !== false && $environmentPath !== '') {
        foreach (explode(PATH_SEPARATOR, $environmentPath) as $pathDirectory) {
            $pathDirectory = trim($pathDirectory);
            if ($pathDirectory !== '' && !in_array($pathDirectory, $candidateDirectories, true)) {
                $candidateDirectories[] = $pathDirectory;
            }
        }
    }

    foreach ($candidateDirectories as $directory) {
        $executablePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'crontab';
        if (@is_file($executablePath) && @is_executable($executablePath)) {
            $resolvedPath = $executablePath;
            return $resolvedPath;
        }
    }

    if (isShellExecAvailable()) {
        $whichOutput = @shell_exec('command -v crontab 2>/dev/null');
        if (is_string($whichOutput)) {
            $whichOutput = trim($whichOutput);
            if ($whichOutput !== '' && @is_executable($whichOutput)) {
                $resolvedPath = $whichOutput;
                return $resolvedPath;
            }
        }
    }

    $resolvedPath = '';
    error_log('Unable to locate the crontab executable on this system.');

    return null;
}

function runShellCommand($command)
{
    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to run command: ' . $command);
        return null;
    }

    if (getenv('PATH') === false || trim((string) getenv('PATH')) === '') {
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
    }

    return shell_exec($command);
}

function deleteDirectory($directory)
{
    if (!file_exists($directory)) {
        return true;
    }

    if (!is_dir($directory)) {
        return @unlink($directory);
    }

    $items = scandir($directory);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($directory);
}

function ensureTableUtf8mb4($table)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $currentCollation = $stmt->fetchColumn();

        if ($currentCollation === false) {
            error_log("Failed to detect current collation for table {$table}");
            return false;
        }

        if (stripos((string) $currentCollation, 'utf8mb4') === 0) {
            return true;
        }

        $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return true;
    } catch (PDOException $e) {
        error_log('Failed to convert table to utf8mb4: ' . $e->getMessage());
        return false;
    }
}

function ensureCardNumberTableSupportsUnicode()
{
    global $connect;

    if (!isset($connect) || !($connect instanceof mysqli)) {
        return;
    }

    try {
        if (method_exists($connect, 'character_set_name') && $connect->character_set_name() !== 'utf8mb4') {
            if (!$connect->set_charset('utf8mb4')) {
                error_log('Failed to enforce utf8mb4 charset on mysqli connection: ' . $connect->error);
            }
        }

        if (!$connect->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'")) {
            error_log('Failed to execute SET NAMES utf8mb4 for card_number table: ' . $connect->error);
        }

        $createQuery = "CREATE TABLE IF NOT EXISTS card_number (" .
            "cardnumber varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY," .
            "namecard varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$connect->query($createQuery)) {
            error_log('Failed to create card_number table with utf8mb4 charset: ' . $connect->error);
        }

        ensureTableUtf8mb4('card_number');

        $columnInfo = $connect->query("SHOW FULL COLUMNS FROM card_number WHERE Field IN ('cardnumber', 'namecard')");
        if ($columnInfo instanceof mysqli_result) {
            while ($column = $columnInfo->fetch_assoc()) {
                $collation = $column['Collation'] ?? '';
                if (!is_string($collation) || stripos($collation, 'utf8mb4') === false) {
                    $field = $column['Field'];
                    $type = $field === 'cardnumber' ? 'varchar(500)' : 'varchar(1000)';
                    $alter = sprintf(
                        "ALTER TABLE card_number MODIFY %s %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci%s",
                        $field,
                        $type,
                        $field === 'cardnumber' ? ' PRIMARY KEY' : ' NOT NULL'
                    );
                    if (!$connect->query($alter)) {
                        error_log('Failed to update card_number column collation: ' . $connect->error);
                    }
                }
            }
            $columnInfo->free();
        } else {
            error_log('Unable to inspect card_number column collations: ' . $connect->error);
        }
    } catch (\Throwable $e) {
        error_log('Unexpected error while ensuring card_number utf8mb4 compatibility: ' . $e->getMessage());
    }
}

function normaliseUpdateValue($value)
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $value;
}

function copyDirectoryContents($source, $destination)
{
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            if (!copyDirectoryContents($sourcePath, $destinationPath)) {
                return false;
            }
        } else {
            if (!@copy($sourcePath, $destinationPath)) {
                return false;
            }
        }
    }

    return true;
}

#-----------function------------#
function step($step, $from_id)
{
    global $pdo;
    $stmt = $pdo->prepare('UPDATE user SET step = ? WHERE id = ?');
    $stmt->execute([$step, $from_id]);
    clearSelectCache('user');
}
function determineColumnTypeFromValue($value)
{
    if (is_bool($value)) {
        return 'TINYINT(1)';
    }

    if (is_int($value)) {
        return 'INT(11)';
    }

    if (is_float($value)) {
        return 'DOUBLE';
    }

    if ($value === null) {
        return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    if (is_string($value)) {
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($value, 'UTF-8');
        } else {
            $length = strlen($value);
        }

        if ($length <= 191) {
            return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        if ($length <= 500) {
            return 'VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
}
function ensureColumnExistsForUpdate($tableName, $fieldName, $valueSample = null)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$tableName, $fieldName]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $datatype = determineColumnTypeFromValue($valueSample);

        $defaultValue = null;
        if (is_bool($valueSample)) {
            $defaultValue = $valueSample ? '1' : '0';
        } elseif (is_scalar($valueSample) && $valueSample !== null) {
            $defaultValue = (string) $valueSample;
        }

        addFieldToTable($tableName, $fieldName, $defaultValue, $datatype);
    } catch (PDOException $e) {
        error_log('Failed to ensure column exists: ' . $e->getMessage());
    }
}
function update($table, $field, $newValue, $whereField = null, $whereValue = null)
{
    global $pdo, $user;

    $valueToStore = normaliseUpdateValue($newValue);

    ensureColumnExistsForUpdate($table, $field, $valueToStore);

    $executeUpdate = function ($value) use ($pdo, $table, $field, $whereField, $whereValue) {
        if ($whereField !== null) {
            $stmt = $pdo->prepare("SELECT $field FROM $table WHERE $whereField = ? FOR UPDATE");
            $stmt->execute([$whereValue]);
            $stmt = $pdo->prepare("UPDATE $table SET $field = ? WHERE $whereField = ?");
            $stmt->execute([$value, $whereValue]);
        } else {
            $stmt = $pdo->prepare("UPDATE $table SET $field = ?");
            $stmt->execute([$value]);
        }
    };

    try {
        $executeUpdate($valueToStore);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Incorrect string value') !== false) {
            $tableConverted = ensureTableUtf8mb4($table);
            if ($tableConverted) {
                try {
                    $executeUpdate($valueToStore);
                } catch (PDOException $retryException) {
                    error_log('Retry after charset conversion failed: ' . $retryException->getMessage());
                    throw $retryException;
                }
            } else {
                $fallbackValue = is_string($valueToStore) ? @iconv('UTF-8', 'UTF-8//IGNORE', $valueToStore) : $valueToStore;
                if ($fallbackValue === false) {
                    $fallbackValue = '';
                }
                $executeUpdate($fallbackValue);
            }
        } else {
            throw $e;
        }
    }

    $date = date("Y-m-d H:i:s");
    if (!isset($user['step'])) {
        $user['step'] = '';
    }
    $logValue = is_scalar($valueToStore) ? $valueToStore : json_encode($valueToStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $logss = "{$table}_{$field}_{$logValue}_{$whereField}_{$whereValue}_{$user['step']}_$date";
    if ($field != "message_count" || $field != "last_message_time") {
        file_put_contents('log.txt', "\n" . $logss, FILE_APPEND);
    }

    clearSelectCache($table);
}
function &getSelectCacheStore()
{
    static $store = [
    'results' => [],
    'tableIndex' => [],
    ];

    return $store;
}

function clearSelectCache($table = null)
{
    $store = &getSelectCacheStore();

    if ($table === null) {
        $store['results'] = [];
        $store['tableIndex'] = [];
        return;
    }

    if (!isset($store['tableIndex'][$table])) {
        return;
    }

    foreach (array_keys($store['tableIndex'][$table]) as $cacheKey) {
        unset($store['results'][$cacheKey]);
    }

    unset($store['tableIndex'][$table]);
}

function select($table, $field, $whereField = null, $whereValue = null, $type = "select", $options = [])
{
    global $pdo;

    $useCache = true;
    if (is_array($options) && array_key_exists('cache', $options)) {
        $useCache = (bool) $options['cache'];
    }

    $cacheKey = null;
    if ($useCache) {
        $cacheKey = hash('sha256', json_encode([
            $table,
            $field,
            $whereField,
            $whereValue,
            $type,
        ], JSON_UNESCAPED_UNICODE));

        $store = &getSelectCacheStore();
        if (isset($store['results'][$cacheKey])) {
            return $store['results'][$cacheKey];
        }
    }

    $query = "SELECT $field FROM $table";

    if ($whereField !== null) {
        $query .= " WHERE $whereField = :whereValue";
    }

    try {
        $stmt = $pdo->prepare($query);
        if ($whereField !== null) {
            $stmt->bindParam(':whereValue', $whereValue, PDO::PARAM_STR);
        }

        $stmt->execute();
        if ($type == "count") {
            $result = $stmt->rowCount();
        } elseif ($type == "FETCH_COLUMN") {
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($table === 'admin' && $field === 'id_admin') {
                global $adminnumber;
                if (!is_array($results)) {
                    $results = [];
                }

                $results = array_values(array_unique(array_filter($results, function ($value) {
                    return $value !== null && $value !== '';
                })));

                if (empty($results) && isset($adminnumber) && $adminnumber !== '') {
                    $results[] = (string) $adminnumber;
                }
            }
            $result = $results;
        } elseif ($type == "fetchAll") {
            $result = $stmt->fetchAll();
        } else {
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            $result = $fetched === false ? null : $fetched;
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        die("Query failed: " . $e->getMessage());
    }

    if ($useCache && $cacheKey !== null) {
        $store = &getSelectCacheStore();
        $store['results'][$cacheKey] = $result;
        if (!isset($store['tableIndex'][$table])) {
            $store['tableIndex'][$table] = [];
        }
        $store['tableIndex'][$table][$cacheKey] = true;
    }

    return $result;
}

function getPaySettingValue($name, $default = null)
{
    $result = select("PaySetting", "ValuePay", "NamePay", $name, "select");
    if (!is_array($result) || !array_key_exists('ValuePay', $result)) {
        return $default;
    }

    return $result['ValuePay'];
}
function generateUUID()
{
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    return $uuid;
}
function rate_arze()
{
    $arze_rate = [];
    $requests_tron = json_decode(file_get_contents('https://api.diadata.org/v1/assetQuotation/Tron/0x0000000000000000000000000000000000000000'), true);
    $html_read = file_get_contents("https://www.bon-bast.com/");
    preg_match('/<span>\s*([\d,]+)\s*<\/span>/', $html_read, $matches);
    if (!empty($matches[1])) {
        $requestsusd = str_replace(',', '', $matches[1]);
    }
    $arze_rate['USD'] = intval($requestsusd);
    $arze_rate['TRX'] = intval($requests_tron['Price'] * $arze_rate['USD']);

    return $arze_rate;
}
function updatePaymentMessageId($response, $orderId)
{
    if (!is_array($response)) {
        error_log("Failed to send payment message for order {$orderId}: unexpected response");
        return false;
    }

    if (empty($response['ok'])) {
        error_log("Failed to send payment message for order {$orderId}: " . json_encode($response));
        return false;
    }

    if (!isset($response['result']['message_id'])) {
        error_log("Missing message_id for order {$orderId}: " . json_encode($response));
        return false;
    }

    update("Payment_report", "message_id", intval($response['result']['message_id']), "id_order", $orderId);
    return true;
}
function nowPayments($payment, $price_amount, $order_id, $order_description)
{
    global $domainhosts;
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/' . $payment,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 7000,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => 1,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments,
            'Content-Type: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'price_amount' => $price_amount,
        'price_currency' => 'usd',
        'order_id' => $order_id,
        'order_description' => $order_description,
        'ipn_callback_url' => "https://" . $domainhosts . "/payment/nowpayment.php"
    ]));

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function StatusPayment($paymentid)
{
    $apinowpayments = select("PaySetting", "*", "NamePay", "marchent_tronseller", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.nowpayments.io/v1/payment/' . $paymentid,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apinowpayments
        ),
    ));
    $response = curl_exec($curl);
    $response = json_decode($response, true);
    curl_close($curl);
    return $response;
}
function channel(array $id_channel)
{
    global $from_id;
    $channel_link = array();
    foreach ($id_channel as $channel) {
        $response = telegram('getChatMember', [
            'chat_id' => $channel,
            'user_id' => $from_id
        ]);
        if ($response['ok']) {
            if (!in_array($response['result']['status'], ['member', 'creator', 'administrator'])) {
                $channel_link[] = $channel;
            }
        }
    }
    if (count($channel_link) == 0) {
        return [];
    } else {
        return $channel_link;
    }
}
function isValidDate($date)
{
    return (strtotime($date) != false);
}
function trnado($order_id, $price)
{
    global $domainhosts;
    $apitronseller = select("PaySetting", "*", "NamePay", "apiternado", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $urlpay = select("PaySetting", "*", "NamePay", "urlpaymenttron", "select")['ValuePay'];
    $curl = curl_init();
    $data = array(
        "PaymentID" => $order_id,
        "WalletAddress" => $walletaddress,
        "TronAmount" => $price,
        "CallbackUrl" => "https://" . $domainhosts . "/payment/tronado.php"
    );
    $datasend = json_encode($data);
    curl_setopt_array($curl, array(
        CURLOPT_URL => "$urlpay",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'x-api-key:' . $apitronseller,
            'Content-Type: application/json',
            'Cookie: ASP.NET_SessionId=spou2s5lo4nnxkjtavscrrlo'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, $datasend);

    $response = curl_exec($curl);

    curl_close($curl);
    return json_decode($response, true);
}
function formatBytes($bytes, $precision = 2): string
{
    global $textbotlang;
    $base = log($bytes, 1024);
    $power = $bytes > 0 ? floor($base) : 0;
    $suffixes = [
        $textbotlang['hardcoded']['unitByte'],
        $textbotlang['hardcoded']['unitKilobyte'],
        $textbotlang['hardcoded']['unitMegabyte'],
        $textbotlang['hardcoded']['unitGigabyteFn'],
        $textbotlang['hardcoded']['unitTerabyte'],
    ];
    return round(pow(1024, $base - $power), $precision) . ' ' . $suffixes[$power];
}
function generateUsername($from_id, $Metode, $username, $randomString, $text, $namecustome, $usernamecustom)
{
    global $textbotlang;
    $setting = select("setting", "*", null, null, "select");
    $user = select("user", "*", "id", $from_id, "select");
    if ($user == false) {
        $user = array();
        $user = array(
            'number_username' => '',
        );
    }
    if ($Metode == $textbotlang['keyboard']['numericIdRandom']) {
        return $from_id . "_" . $randomString;
    } elseif ($Metode == $textbotlang['keyboard']['usernameSequential']) {
        if ($username == "NOT_USERNAME") {
            if (preg_match('/^\w{3,32}$/', $namecustome)) {
                $username = $namecustome;
            }
        }
        return $username . "_" . $user['number_username'];
    } elseif ($Metode == $textbotlang['keyboard']['customUsername'])
        return $text;
    elseif ($Metode == $textbotlang['keyboard']['customUsernameRandom']) {
        $random_number = rand(1000000, 9999999);
        return $text . "_" . $random_number;
    } elseif ($Metode == $textbotlang['keyboard']['customTextRandom']) {
        return $namecustome . "_" . $randomString;
    } elseif ($Metode == $textbotlang['keyboard']['customTextSequential']) {
        return $namecustome . "_" . $setting['numbercount'];
    } elseif ($Metode == $textbotlang['keyboard']['numericIdSequential']) {
        return $from_id . "_" . $user['number_username'];
    } elseif ($Metode == $textbotlang['keyboard']['agentCustomTextSequential']) {
        if ($usernamecustom == "none") {
            return $namecustome . "_" . $setting['numbercount'];
        }
        return $usernamecustom . "_" . $user['number_username'];
    }
}
function outputlink($text)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $text);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 10000);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        return null;
    } else {
        return $response;
    }
}
function DirectPayment($order_id, $image = 'images.jpg')
{
    global $pdo, $ManagePanel, $textbotlang, $keyboardextendfnished, $keyboard, $Confirm_pay, $from_id, $message_id;
    $buyreport = select("topicid", "idreport", "report", "buyreport", "select")['idreport'];
    $admin_ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    $otherservice = select("topicid", "idreport", "report", "otherservice", "select")['idreport'];
    $otherreport = select("topicid", "idreport", "report", "otherreport", "select")['idreport'];
    $errorreport = select("topicid", "idreport", "report", "errorreport", "select")['idreport'];
    $porsantreport = select("topicid", "idreport", "report", "porsantreport", "select")['idreport'];
    $setting = select("setting", "*");
    $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
    $format_price_cart = number_format($Payment_report['price']);
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    $steppay = explode("|", $Payment_report['id_invoice']);
    update("user", "Processing_value", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_one", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_tow", "0", "id", $Balance_id['id']);
    update("user", "Processing_value_four", "0", "id", $Balance_id['id']);
    if ($steppay[0] == "getconfigafterpay") {
        $get_invoice = select("invoice", "*", "username", $steppay[1], "select");
        $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
        $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
        $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
        $stmt->execute();
        $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($get_invoice['name_product'] == $textbotlang['extracted']['index_php']['customVolumeButton'] || $get_invoice['name_product'] == $textbotlang['extracted']['index_php']['customServiceButton']) {
            $info_product['data_limit_reset'] = "no_reset";
            $info_product['Volume_constraint'] = $get_invoice['Volume'];
            $info_product['name_product'] = $textbotlang['users']['customSellVolume']['title'];
            $info_product['code_product'] = "customvolume";
            $info_product['Service_time'] = $get_invoice['Service_time'];
            $info_product['price_product'] = $get_invoice['price_product'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE name_product = :name_product AND (Location = :Service_location  or Location = '/all')");
            $stmt->bindParam(':name_product', $get_invoice['name_product'], PDO::PARAM_STR);
            $stmt->bindParam(':Service_location', $get_invoice['Service_location'], PDO::PARAM_STR);
            $stmt->execute();
            $info_product = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $username_ac = $get_invoice['username'];
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $get_invoice['Service_location'], "select");
        $date = strtotime("+" . $get_invoice['Service_time'] . "days");
        if (intval($get_invoice['Service_time']) == 0) {
            $timestamp = 0;
        } else {
            $timestamp = strtotime(date("Y-m-d H:i:s", $date));
        }
        $datac = array(
            'expire' => $timestamp,
            'data_limit' => $get_invoice['Volume'] * pow(1024, 3),
            'from_id' => $Balance_id['id'],
            'username' => $Balance_id['username'],
            'type' => 'buy'
        );
        $dataoutput = $ManagePanel->createUser($marzban_list_get['name_panel'], $info_product['code_product'], $username_ac, $datac);
        if ($dataoutput['username'] == null) {
            $dataoutput['msg'] = json_encode($dataoutput['msg']);
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['errorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['serviceCreateFailedRefund'], $balance), $keyboard, 'HTML');
            $texterros = sprintf($textbotlang['hardcoded']['configCreateError'], $dataoutput['msg'], $Balance_id['id'], $Balance_id['username'], $marzban_list_get['name_panel']);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $texterros,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $Shoppinginfo = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['keyboard']['viewTutorial'], 'callback_data' => "helpbtn"],
                ]
            ]
        ]);
        $output_config_link = "";
        $config = "";
        if ($marzban_list_get['config'] == "onconfig" && is_array($dataoutput['configs'])) {
            foreach ($dataoutput['configs'] as $link) {
                $config .= "\n" . $link;
            }
        }
        $output_config_link = $marzban_list_get['sublink'] == "onsublink" ? $dataoutput['subscription_url'] : "";
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "Manualsale" ? $textbotlang['textbot']['manual'] : $textbotlang['textbot']['afterPay'];
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "WGDashboard" ? $textbotlang['textbot']['wgDashboard'] : $textbotlang['textbot']['afterPay'];
        $textbotlang['textbot']['afterPay'] = $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik" ? $textbotlang['textbot']['afterPayIbsng'] : $textbotlang['textbot']['afterPay'];
        if (intval($get_invoice['Service_time']) == 0)
            $get_invoice['Service_time'] = $textbotlang['users']['status']['unlimited'];
        $textcreatuser = str_replace('{username}', $dataoutput['username'], $textbotlang['textbot']['afterPay']);
        $textcreatuser = str_replace('{name_service}', $get_invoice['name_product'], $textcreatuser);
        $textcreatuser = str_replace('{location}', $marzban_list_get['name_panel'], $textcreatuser);
        $textcreatuser = str_replace('{day}', $get_invoice['Service_time'], $textcreatuser);
        $textcreatuser = str_replace('{volume}', $get_invoice['Volume'], $textcreatuser);
        $textcreatuser = str_replace('{config}', "<code>{$output_config_link}</code>", $textcreatuser);
        $textcreatuser = str_replace('{links}', $config, $textcreatuser);
        $textcreatuser = str_replace('{links2}', "{$output_config_link}", $textcreatuser);
        if ($marzban_list_get['type'] == "Manualsale" || $marzban_list_get['type'] == "ibsng" || $marzban_list_get['type'] == "mikrotik") {
            $textcreatuser = str_replace('{password}', $dataoutput['subscription_url'], $textcreatuser);
            update("invoice", "user_info", $dataoutput['subscription_url'], "id_invoice", $get_invoice['id_invoice']);
        }
        sendMessageService($marzban_list_get, $dataoutput['configs'], $output_config_link, $dataoutput['username'], $Shoppinginfo, $textcreatuser, $get_invoice['id_invoice'], $get_invoice['id_user'], $image);
        $partsdic = explode("_", $Balance_id['Processing_value_four'], $get_invoice['id_user']);
        if ($partsdic[0] == "dis") {
            $SellDiscountlimit = select("DiscountSell", "*", "codeDiscount", $partsdic[1], "select");
            $value = intval($SellDiscountlimit['usedDiscount']) + 1;
            update("DiscountSell", "usedDiscount", $value, "codeDiscount", $partsdic[1]);
            $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user,code) VALUES (:id_user,:code)");
            $stmt->bindParam(':id_user', $Balance_id['id']);
            $stmt->bindParam(':code', $partsdic[1]);
            $stmt->execute();
            $text_report = sprintf($textbotlang['hardcoded']['discountCodeUsedAdmin'], $Balance_id['username'], $Balance_id['id'], $partsdic[1]);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $affiliatescommission = select("affiliates", "*", null, null, "select");
        $marzbanporsant_one_buy = select("affiliates", "*", null, null, "select");
        $stmt = $pdo->prepare("SELECT * FROM invoice WHERE name_product != '{$textbotlang['Admin']['adminphp']['db_test_service_name']}'  AND id_user = :id_user AND Status != 'Unpaid'");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $countinvoice = $stmt->rowCount();
        if ($affiliatescommission['status_commission'] == "oncommission" && ($Balance_id['affiliates'] != null && intval($Balance_id['affiliates']) != 0)) {
            if ($marzbanporsant_one_buy['porsant_one_buy'] == "on_buy_porsant") {
                if ($countinvoice <= 1) {
                    $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                    $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                    if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                        sendmessage($Balance_id['affiliates'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
                        $scorenew = $user_Balance['score'] + 2;
                        update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                    }
                    $Balance_prim = $user_Balance['Balance'] + $result;
                    $dateacc = date('Y/m/d H:i:s');
                    update("user", "Balance", $Balance_prim, "id", $Balance_id['affiliates']);
                    $result = number_format($result);
                    $textadd = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidUserFn'], $result);
                    $textreportport = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidLogFn'], $result, $Balance_id['affiliates'], $Balance_id['id'], $dateacc);
                    if (strlen($setting['Channel_Report']) > 0) {
                        telegram('sendmessage', [
                            'chat_id' => $setting['Channel_Report'],
                            'message_thread_id' => $porsantreport,
                            'text' => $textreportport,
                            'parse_mode' => "HTML"
                        ]);
                    }
                    sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
                }
            } else {

                $result = ($Payment_report['price'] * $setting['affiliatespercentage']) / 100;
                $user_Balance = select("user", "*", "id", $Balance_id['affiliates'], "select");
                if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['affiliates'], $admin_ids)) {
                    sendmessage($Balance_id['affiliates'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
                    $scorenew = $user_Balance['score'] + 2;
                    update("user", "score", $scorenew, "id", $Balance_id['affiliates']);
                }
                $Balance_prim = $user_Balance['Balance'] + $result;
                $dateacc = date('Y/m/d H:i:s');
                update("user", "Balance", $Balance_prim, "id", $Balance_id['affiliates']);
                $result = number_format($result);
                $textadd = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidUserFn2'], $result);
                $textreportport = sprintf($textbotlang['hardcoded']['affiliateCommissionPaidLogFn2'], $result, $Balance_id['affiliates'], $Balance_id['id'], $dateacc);
                if (strlen($setting['Channel_Report']) > 0) {
                    telegram('sendmessage', [
                        'chat_id' => $setting['Channel_Report'],
                        'message_thread_id' => $porsantreport,
                        'text' => $textreportport,
                        'parse_mode' => "HTML"
                    ]);
                }
                sendmessage($Balance_id['affiliates'], $textadd, null, 'HTML');
            }
        }
        if ($marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['customTextSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['usernameSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['numericIdSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['agentCustomTextSequential']) {
            $value = intval($Balance_id['number_username']) + 1;
            update("user", "number_username", $value, "id", $Balance_id['id']);
            if ($marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['customTextSequential'] || $marzban_list_get['MethodUsername'] == $textbotlang['keyboard']['agentCustomTextSequential']) {
                $value = intval($setting['numbercount']) + 1;
                update("setting", "numbercount", $value);
            }
        }
        $Balance_prims = $Balance_id['Balance'] - $get_invoice['price_product'];
        if ($Balance_prims <= 0)
            $Balance_prims = 0;
        update("user", "Balance", $Balance_prims, "id", $Balance_id['id']);
        $balanceformatsell = select("user", "Balance", "id", $get_invoice['id_user'], "select")['Balance'];
        $balanceformatsell = number_format($balanceformatsell, 0);
        $balancebefore = number_format($Balance_id['Balance'], 0);
        $timejalali = jdate('Y/m/d H:i:s');
        $textonebuy = "";
        if ($countinvoice == 1) {
            $textonebuy = $textbotlang['extracted']['index_php']['firstPurchaseLabel'];
        }
        $Response = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['Admin']['manageUser']['manageUserBtn'], 'callback_data' => 'manageuser_' . $Balance_id['id']],
                ],
            ]
        ]);
        $text_report = sprintf($textbotlang['hardcoded']['accountCreateReportAfterPay'], $textonebuy, $Balance_id['id'], $Balance_id['username'], $username_ac, $get_invoice['Service_location'], $get_invoice['Service_time'], $get_invoice['name_product'], $get_invoice['Volume'], $balancebefore, $balanceformatsell, $get_invoice['id_invoice'], $Balance_id['agent'], $Balance_id['number'], $get_invoice['price_product'], $Payment_report['price'], $timejalali);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $buyreport,
                'text' => $text_report,
                'parse_mode' => "HTML",
                'reply_markup' => $Response
            ]);
        }
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        update("invoice", "Status", "active", "username", $get_invoice['username']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            update("invoice", "Status", "active", "id_invoice", $get_invoice['id_invoice']);
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedNewService'], $username_ac, $get_invoice['Service_location'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart, $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
    } elseif ($steppay[0] == "getextenduser") {
        $balanceformatsell = number_format(select("user", "Balance", "id", $Balance_id['id'], "select")['Balance'], 0);
        $partsdic = explode("%", $steppay[1]);
        $usernamepanel = $partsdic[0];
        $sql = "SELECT * FROM service_other WHERE username = :username  AND value  LIKE CONCAT('%', :value, '%') AND id_user = :id_user ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':username', $usernamepanel, PDO::PARAM_STR);
        $stmt->bindParam(':value', $partsdic[1], PDO::PARAM_STR);
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->execute();
        $data_order = $stmt->fetch(PDO::FETCH_ASSOC);
        $service_other = $data_order;
        if ($service_other == false) {
            sendmessage($Balance_id['id'], $textbotlang['hardcoded']['renewGenericError'], $keyboard, 'HTML');
            return;
        }
        $service_other = json_decode($service_other['value'], true);
        $codeproduct = $service_other['code_product'];
        $nameloc = select("invoice", "*", "username", $usernamepanel, "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        if ($codeproduct == "custom_volume") {
            $prodcut['code_product'] = "custom_volume";
            $prodcut['name_product'] = $nameloc['name_product'];
            $prodcut['price_product'] = $data_order['price'];
            $prodcut['Service_time'] = $service_other['Service_time'];
            $prodcut['Volume_constraint'] = $service_other['volumebuy'];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM product WHERE (Location = '{$nameloc['Service_location']}' OR Location = '/all') AND agent= '{$Balance_id['agent']}' AND code_product = '$codeproduct'");
            $stmt->execute();
            $prodcut = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($nameloc['name_product'] == $textbotlang['hardcoded']['testServiceNameFn']) {
            update("invoice", "name_product", $prodcut['name_product'], "id_invoice", $nameloc['id_invoice']);
            update("invoice", "price_product", $prodcut['price_product'], "id_invoice", $nameloc['id_invoice']);
        }
        $dateacc = date('Y/m/d H:i:s');
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $nameloc['username']);
        $Balance_Low_user = 0;
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $extend = $ManagePanel->extend($marzban_list_get['Methodextend'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $nameloc['username'], $prodcut['code_product'], $marzban_list_get['code_panel']);
        if ($extend['status'] == false) {
            $balance = $Balance_id['Balance'] + $Payment_report['price'];
            update("user", "Balance", $balance, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], $textbotlang['users']['sell']['errorConfig'], $keyboard, 'HTML');
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['serviceRenewFailedRefund'], $balance), $keyboard, 'HTML');
            $extend['msg'] = json_encode($extend['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['renewServiceErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extend['msg']);
            sendmessage($nameloc['id_user'], $textbotlang['extracted']['index_php']['renewServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }

        update("service_other", "output", json_encode($extend), "id", $data_order['id']);
        update("service_other", "status", "paid", "id", $data_order['id']);
        $partsdic = explode("_", $Balance_id['Processing_value_four']);
        if ($partsdic[0] == "dis") {
            $SellDiscountlimit = select("DiscountSell", "*", "codeDiscount", $partsdic[1], "select");
            $value = intval($SellDiscountlimit['usedDiscount']) + 1;
            update("DiscountSell", "usedDiscount", $value, "codeDiscount", $partsdic[1]);
            $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user,code) VALUES (:id_user,:code)");
            $stmt->bindParam(':id_user', $Balance_id['id']);
            $stmt->bindParam(':code', $partsdic[1]);
            $stmt->execute();
            $text_report = sprintf($textbotlang['hardcoded']['discountCodeUsedAdminFn'], $Balance_id['username'], $Balance_id['id'], $partsdic[1]);
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $otherreport,
                    'text' => $text_report,
                ]);
            }
        }
        $keyboardextendfnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backlist'], 'callback_data' => "backorder"],
                ],
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        if ($Balance_id['agent'] == "f") {
            $valurcashbackextend = select("shopSetting", "*", "Namevalue", "chashbackextend", "select")['value'];
        } else {
            $valurcashbackextend = json_decode(select("shopSetting", "*", "Namevalue", "chashbackextend_agent", "select")['value'], true)[$Balance_id['agenr']];
        }
        if (intval($valurcashbackextend) != 0) {
            $result = ($prodcut['price_product'] * $valurcashbackextend) / 100;
            $pricelastextend = $result;
            update("user", "Balance", $pricelastextend, "id", $Balance_id['id']);
            sendmessage($Balance_id['id'], sprintf($textbotlang['hardcoded']['renewGiftChargedFn'], $result), null, 'HTML');
        }
        $priceproductformat = number_format($prodcut['price_product']);
        $textextend = sprintf($textbotlang['hardcoded']['renewServiceSuccessFn'], $usernamepanel, $prodcut['name_product'], $priceproductformat);
        sendmessage($Balance_id['id'], $textextend, $keyboardextendfnished, 'HTML');
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned2Points'], null, 'html');
            $scorenew = $Balance_id['score'] + 2;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $timejalali = jdate('Y/m/d H:i:s');
        $text_report = sprintf($textbotlang['hardcoded']['renewReportAdminFn'], $Balance_id['id'], $Balance_id['username'], $usernamepanel, $nameloc['Service_location'], $prodcut['name_product'], $prodcut['Volume_constraint'], $prodcut['Service_time'], $priceproductformat, $balanceformatsell, $timejalali);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {

            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedRenew'], $usernamepanel, $prodcut['name_product'], $nameloc['Service_location'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart, $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
    } elseif ($steppay[0] == "getextravolumeuser") {
        $steppay = explode("%", $steppay[1]);
        $volume = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != null) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $Balance_id['id']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'volume_value' => $volume,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_user";
        $extra_volume = $ManagePanel->extra_volume($nameloc['username'], $marzban_list_get['code_panel'], $volume);
        if ($extra_volume['status'] == false) {
            $extra_volume['msg'] = json_encode($extra_volume['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['extraVolumeErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extra_volume['msg']);
            sendmessage($nameloc['id_user'], $textbotlang['extracted']['index_php']['extraVolumeServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindParam(':output', json_encode($extra_volume));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price'], 0);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textvolume = sprintf($textbotlang['hardcoded']['extraVolumeSuccessFn'], $steppay[0], $volume, $volumesformat);
        sendmessage($Balance_id['id'], $textvolume, $keyboardextrafnished, 'HTML');
        $volumes = $volume;
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedExtraVolume'], $volumes, $steppay[0], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = sprintf($textbotlang['hardcoded']['extraVolumeReportAdminFn'], $Balance_id['id'], $volumes, $Payment_report['price'], $steppay[0], $Balance_id['Balance']);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
                'parse_mode' => "HTML"
            ]);
        }
    } elseif ($steppay[0] == "getextratimeuser") {
        $steppay = explode("%", $steppay[1]);
        $tmieextra = $steppay[1];
        $nameloc = select("invoice", "*", "username", $steppay[0], "select");
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        $Balance_Low_user = 0;
        $inboundid = $marzban_list_get['inboundid'];
        if ($nameloc['inboundid'] != false) {
            $inboundid = $nameloc['inboundid'];
        }
        update("user", "Balance", $Balance_Low_user, "id", $nameloc['id_user']);
        $DataUserOut = $ManagePanel->DataUser($nameloc['Service_location'], $steppay[0]);
        $data_for_database = json_encode(array(
            'day' => $tmieextra,
            'old_volume' => $DataUserOut['data_limit'],
            'expire_old' => $DataUserOut['expire']
        ));
        $dateacc = date('Y/m/d H:i:s');
        $type = "extra_time_user";
        $timeservice = $DataUserOut['expire'] - time();
        $day = floor($timeservice / 86400);
        $extra_time = $ManagePanel->extra_time($nameloc['username'], $marzban_list_get['code_panel'], $tmieextra);
        if ($extra_time['status'] == false) {
            $extra_time['msg'] = json_encode($extra_time['msg']);
            $textreports = sprintf($textbotlang['hardcoded']['extraTimeErrorFn'], $marzban_list_get['name_panel'], $nameloc['username'], $extra_time['msg']);
            sendmessage($from_id, $textbotlang['extracted']['index_php']['extraVolumeServiceError'], null, 'HTML');
            if (strlen($setting['Channel_Report']) > 0) {
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $errorreport,
                    'text' => $textreports,
                    'parse_mode' => "HTML"
                ]);
            }
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO service_other (id_user, username,value,type,time,price,output) VALUES (:id_user,:username,:value,:type,:time,:price,:output)");
        $stmt->bindParam(':id_user', $Balance_id['id']);
        $stmt->bindParam(':username', $steppay[0]);
        $stmt->bindParam(':value', $data_for_database);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':time', $dateacc);
        $stmt->bindParam(':price', $Payment_report['price']);
        $stmt->bindParam(':output', json_encode($extra_time));
        $stmt->execute();
        $keyboardextrafnished = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $textbotlang['users']['status']['backservice'], 'callback_data' => "product_" . $nameloc['id_invoice']],
                ]
            ]
        ]);
        $volumesformat = number_format($Payment_report['price']);
        if (intval($setting['scorestatus']) == 1 and !in_array($Balance_id['id'], $admin_ids)) {
            sendmessage($Balance_id['id'], $textbotlang['extracted']['index_php']['earned1Point'], null, 'html');
            $scorenew = $Balance_id['score'] + 1;
            update("user", "score", $scorenew, "id", $Balance_id['id']);
        }
        $textextratime = sprintf($textbotlang['hardcoded']['extraTimeSuccessFn'], $steppay[0], $tmieextra, $volumesformat);
        sendmessage($Balance_id['id'], $textextratime, $keyboardextrafnished, 'HTML');
        if ($Payment_report['Payment_Method'] == "cart to cart") {
            $volumes = $tmieextra;
            $textconfrom = sprintf($textbotlang['hardcoded']['paymentConfirmedExtraTime'], $volumes, $steppay[0], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $Balance_id['Balance'], $format_price_cart);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        update("invoice", "Status", "active", "id_invoice", $nameloc['id_invoice']);
        $text_report = sprintf($textbotlang['hardcoded']['extraTimeReportAdminFn'], $Balance_id['id'], $volumes, $Payment_report['price'], $steppay[0]);
        if (strlen($setting['Channel_Report']) > 0) {
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $otherservice,
                'text' => $text_report,
            ]);
        }
    } else {
        $Balance_confrim = intval($Balance_id['Balance']) + intval($Payment_report['price']);
        update("user", "Balance", $Balance_confrim, "id", $Payment_report['id_user']);
        update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
        $Payment_report['price'] = number_format($Payment_report['price'], 0);
        $format_price_cart = $Payment_report['price'];
        if ($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline") {
            $textconfrom = sprintf($textbotlang['hardcoded']['newPaymentBalanceChargeFn'], $Balance_id['id'], $Payment_report['id_order'], $Balance_id['username'], $format_price_cart, $Balance_id['Balance'], $Payment_report['dec_not_confirmed']);
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        sendmessage($Payment_report['id_user'], sprintf($textbotlang['hardcoded']['balanceChargedThanks'], $Payment_report['price'], $Payment_report['id_order']), null, 'HTML');
    }
}
function plisio($order_id, $price)
{
    $apinowpayments = select("PaySetting", "ValuePay", "NamePay", "apinowpayment", "select")['ValuePay'];
    $api_key = $apinowpayments;

    $url = 'https://api.plisio.net/api/v1/invoices/new';
    $url .= '?source_currency=USD';
    $url .= '&source_amount=' . urlencode($price);
    $url .= '&order_number=' . urlencode($order_id);
    $url .= '&email=customer@plisio.net';
    $url .= '&order_name=plisio';
    $url .= '&language=fa';
    $url .= '&api_key=' . urlencode($api_key);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = json_decode(curl_exec($ch), true);
    return $response['data'];
    curl_close($ch);
}
function checkConnection($address, $port)
{
    $socket = @stream_socket_client("tcp://$address:$port", $errno, $errstr, 5);
    if ($socket) {
        fclose($socket);
        return true;
    } else {
        return false;
    }
}
function savedata($type, $namefiled, $valuefiled)
{
    global $from_id;
    if ($type == "clear") {
        $datauser = [];
        $datauser[$namefiled] = $valuefiled;
        $data = json_encode($datauser);
        update("user", "Processing_value", $data, "id", $from_id);
    } elseif ($type == "save") {
        $userdata = select("user", "*", "id", $from_id, "select");
        $dataperevieos = json_decode($userdata['Processing_value'], true);
        $dataperevieos[$namefiled] = $valuefiled;
        update("user", "Processing_value", json_encode($dataperevieos), "id", $from_id);
    }
}
function addFieldToTable($tableName, $fieldName, $defaultValue = null, $datatype = "VARCHAR(500)")
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_name = :tableName");
    $stmt->bindParam(':tableName', $tableName);
    $stmt->execute();
    $tableExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tableExists['count'] == 0)
        return;
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$pdo->query("SELECT DATABASE()")->fetchColumn(), $tableName, $fieldName]);
    $filedExists = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($filedExists['count'] != 0)
        return;
    $query = "ALTER TABLE $tableName ADD $fieldName $datatype";
    $statement = $pdo->prepare($query);
    $statement->execute();
    if ($defaultValue != null) {
        $stmt = $pdo->prepare("UPDATE $tableName SET $fieldName= ?");
        $stmt->bindParam(1, $defaultValue);
        $stmt->execute();
    }
    echo "The $fieldName field was added ✅";
}
function outtypepanel($typepanel, $message)
{
    global $from_id, $optionMarzban, $optionX_ui_single, $optionhiddfy, $optionalireza, $optionalireza_single, $optionmarzneshin, $option_mikrotik, $optionwg, $options_ui, $optioneylanpanel, $optionibsng;
    if ($typepanel == "marzban") {
        sendmessage($from_id, $message, $optionMarzban, 'HTML');
    } elseif ($typepanel == "x-ui_single") {
        sendmessage($from_id, $message, $optionX_ui_single, 'HTML');
    } elseif ($typepanel == "hiddify") {
        sendmessage($from_id, $message, $optionhiddfy, 'HTML');
    } elseif ($typepanel == "alireza_single") {
        sendmessage($from_id, $message, $optionalireza_single, 'HTML');
    } elseif ($typepanel == "marzneshin") {
        sendmessage($from_id, $message, $optionmarzneshin, 'HTML');
    } elseif ($typepanel == "WGDashboard") {
        sendmessage($from_id, $message, $optionwg, 'HTML');
    } elseif ($typepanel == "s_ui") {
        sendmessage($from_id, $message, $options_ui, 'HTML');
    } elseif ($typepanel == "ibsng") {
        sendmessage($from_id, $message, $optionibsng, 'HTML');
    } elseif ($typepanel == "mikrotik") {
        sendmessage($from_id, $message, $option_mikrotik, 'HTML');
    }
}

function addBackgroundImage($urlimage, $qrCodeResult, $backgroundPath)
{
    if (!file_exists($backgroundPath)) {
        error_log("addBackgroundImage: File not found at $backgroundPath");
        file_put_contents($urlimage, $qrCodeResult->getString());
        return;
    }

    $qrString = $qrCodeResult->getString();
    $qrCodeImage = imagecreatefromstring($qrString);
    if (!$qrCodeImage) {
        error_log("addBackgroundImage: Failed to create QR Code resource");
        return;
    }

    $backgroundImage = null;

    try {
        $backgroundImage = imagecreatefromjpeg($backgroundPath);
    } catch (Throwable $t) {
        error_log("addBackgroundImage::EXCEPTION loading image: " . $t->getMessage());
    }

    if (!$backgroundImage) {
        $lastError = error_get_last();
        error_log("addBackgroundImage::System Error: " . $lastError['message']);

        imagepng($qrCodeImage, $urlimage);
        imagedestroy($qrCodeImage);
        return;
    }

    $qrCodeWidth = imagesx($qrCodeImage);
    $qrCodeHeight = imagesy($qrCodeImage);
    $backgroundWidth = imagesx($backgroundImage);
    $backgroundHeight = imagesy($backgroundImage);

    $x = ($backgroundWidth - $qrCodeWidth) / 2;
    $y = ($backgroundHeight - $qrCodeHeight) / 2;

    imagecopy($backgroundImage, $qrCodeImage, $x, $y, 0, 0, $qrCodeWidth, $qrCodeHeight);

    imagepng($backgroundImage, $urlimage);

    imagedestroy($qrCodeImage);
    imagedestroy($backgroundImage);
}

function checktelegramip()
{
    // When the bot runs behind a reverse proxy / load balancer (Coolify,
    // Docker, Nginx, Cloudflare, Traefik ...), $_SERVER['REMOTE_ADDR'] is the
    // proxy's INTERNAL address (e.g. 10.0.1.4), never Telegram's real IP.
    // We therefore collect every candidate IP from the forwarding headers as
    // well, and accept the request if ANY of them falls inside an official
    // Telegram range. This keeps the security check meaningful while making it
    // work behind a proxy.
    $candidates = [];

    // Real client IP forwarded by the proxy. X-Forwarded-For may contain a
    // comma-separated chain "client, proxy1, proxy2"; the left-most entry is
    // the original client (Telegram).
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidates[] = trim($part);
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_X_REAL_IP']);
    }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) { // Cloudflare
        $candidates[] = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = trim($_SERVER['REMOTE_ADDR']);
    }

    $telegramIpRanges = [
        ['lower' => '149.154.160.0', 'upper' => '149.154.175.255'],
        ['lower' => '91.108.4.0', 'upper' => '91.108.7.255'],
        ['lower' => '2001:67c:4e8::', 'upper' => '2001:67c:4e8:ffff:ffff:ffff:ffff:ffff'],
    ];

    foreach ($candidates as $clientIp) {
        if ($clientIp === '' || !filter_var($clientIp, FILTER_VALIDATE_IP)) {
            continue;
        }
        foreach ($telegramIpRanges as $range) {
            if (isClientIpInRange($clientIp, $range['lower'], $range['upper'])) {
                return true;
            }
        }
    }

    // Optional escape hatch: if TELEGRAM_IP_CHECK=off is set in the environment
    // (e.g. when fronted by a proxy that strips forwarding headers), skip the
    // IP allow-list entirely. The webhook secret/token in the URL still
    // protects the endpoint.
    $bypass = getenv('TELEGRAM_IP_CHECK');
    if ($bypass !== false && strtolower(trim($bypass)) === 'off') {
        return true;
    }

    return false;
}

function isClientIpInRange($clientIp, $lowerBound, $upperBound)
{
    $clientPacked = inet_pton($clientIp);
    $lowerPacked = inet_pton($lowerBound);
    $upperPacked = inet_pton($upperBound);

    if ($clientPacked === false || $lowerPacked === false || $upperPacked === false) {
        return false;
    }

    $length = strlen($clientPacked);
    if ($length !== strlen($lowerPacked) || $length !== strlen($upperPacked)) {
        return false;
    }

    return strcmp($clientPacked, $lowerPacked) >= 0 && strcmp($clientPacked, $upperPacked) <= 0;
}
function addCronIfNotExists($cronCommand)
{
    $commands = is_array($cronCommand) ? $cronCommand : [$cronCommand];
    $commands = array_values(array_filter(array_map('trim', $commands), static function ($command) {
        return $command !== '';
    }));

    if (empty($commands)) {
        return true;
    }

    $logContext = implode('; ', $commands);

    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $crontabBinary = getCrontabBinary();
    if ($crontabBinary === null) {
        error_log('crontab executable not found; unable to register cron job(s): ' . $logContext);
        return false;
    }

    $existingCronJobs = runShellCommand(sprintf('%s -l 2>/dev/null', escapeshellarg($crontabBinary)));
    $existingCronJobs = trim((string) $existingCronJobs);
    $cronLines = $existingCronJobs === '' ? [] : preg_split('/\r?\n/', $existingCronJobs);
    $cronLines = array_values(array_filter(array_map('trim', $cronLines), static function ($line) {
        return $line !== '' && strpos($line, '#') !== 0;
    }));

    $newLineAdded = false;
    foreach ($commands as $command) {
        if (!in_array($command, $cronLines, true)) {
            $cronLines[] = $command;
            $newLineAdded = true;
        }
    }

    if (!$newLineAdded) {
        return true;
    }

    $cronLines = array_values(array_unique($cronLines));
    $cronContent = implode(PHP_EOL, $cronLines) . PHP_EOL;

    $temporaryFile = tempnam(sys_get_temp_dir(), 'cron');
    if ($temporaryFile === false) {
        error_log('Unable to create temporary file for cron job registration.');
        return false;
    }

    if (file_put_contents($temporaryFile, $cronContent) === false) {
        error_log('Unable to write cron configuration to temporary file: ' . $temporaryFile);
        unlink($temporaryFile);
        return false;
    }

    runShellCommand(sprintf('%s %s', escapeshellarg($crontabBinary), escapeshellarg($temporaryFile)));
    unlink($temporaryFile);

    return true;
}

function activecron()
{
    global $domainhosts;

    $cronCommands = [
        "*/15 * * * * curl https://$domainhosts/cronbot/statusday.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/croncard.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/NoticationsService.php",
        "*/5 * * * * curl https://$domainhosts/cronbot/payment_expire.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/sendmessage.php",
        "*/3 * * * * curl https://$domainhosts/cronbot/plisio.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/activeconfig.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/disableconfig.php",
        "*/1 * * * * curl https://$domainhosts/cronbot/iranpay1.php",
        "0 */5 * * * curl https://$domainhosts/cronbot/backupbot.php",
        "*/2 * * * * curl https://$domainhosts/cronbot/gift.php",
        "*/30 * * * * curl https://$domainhosts/cronbot/expireagent.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/on_hold.php",
        "*/2 * * * * curl https://$domainhosts/cronbot/configtest.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/uptime_node.php",
        "*/15 * * * * curl https://$domainhosts/cronbot/uptime_panel.php",
    ];

    addCronIfNotExists($cronCommands);
}
function createInvoice($amount)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];

    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.com/api/factor/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array('amount' => $amount, 'address' => $walletaddress, 'base' => 'trx'),
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return json_decode($response, true);
}
function verifpay($id)
{
    global $from_id, $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "apiiranpay", "select")['ValuePay'];
    $walletaddress = select("PaySetting", "*", "NamePay", "walletaddress", "select")['ValuePay'];
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://pay.melorinabeauty.ir/api/factor/status?id=' . $id,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: Token ' . $PaySetting
        ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);

    return $response;
}
function createInvoiceiranpay1($amount, $id_invoice)
{
    global $domainhosts;
    $PaySetting = select("PaySetting", "*", "NamePay", "marchent_floypay", "select")['ValuePay'];
    $curl = curl_init();
    $amount = intval($amount);
    $data = [
        "ApiKey" => $PaySetting,
        "Hash_id" => $id_invoice,
        "Amount" => $amount . "0",
        "CallbackURL" => "https://$domainhosts/payment/iranpay1.php"
    ];
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://tetra98.com/api/create_order",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'accept: application/json',
            'Content-Type: application/json'
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function sanitizeUserName($userName)
{
    $forbiddenCharacters = [
        "'",
        "\"",
        "<",
        ">",
        "--",
        "#",
        ";",
        "\\",
        "%",
        "(",
        ")"
    ];

    foreach ($forbiddenCharacters as $char) {
        $userName = str_replace($char, "", $userName);
    }

    return $userName;
}
function publickey()
{
    $privateKey = sodium_crypto_box_keypair();
    $privateKeyEncoded = base64_encode(sodium_crypto_box_secretkey($privateKey));
    $publicKey = sodium_crypto_box_publickey($privateKey);
    $publicKeyEncoded = base64_encode($publicKey);
    $presharedKey = base64_encode(random_bytes(32));
    return [
        'private_key' => $privateKeyEncoded,
        'public_key' => $publicKeyEncoded,
        'preshared_key' => $presharedKey
    ];
}

function languagechange($path_dir = null, string $lang = 'fa')
{
    global $from_id;
    $user_lang = select("user", "*", "id", $from_id);
    $lang = $user_lang ? $user_lang['lang'] : $lang;
    $allowed = ['fa', 'en', 'ar', 'ru', 'zh'];
    if (!in_array($lang, $allowed, true))
        $lang = 'fa';
    return require __DIR__ . '/lang/' . $lang . '.php';
}

/**
 * Resolve a bot label by key with DB-backed override support.
 *
 * Lookup order:
 *   1) botlabels table (per-language override edited from the web panel)
 *   2) the matching key inside the language file's `textbot` section
 *   3) the provided $default (or the key itself)
 *
 * This is the core of the "customizable from panel" feature: button names and
 * terminology can be overridden without touching lang/*.php files.
 *
 * @param string      $key     symbolic label key (e.g. "sell", "text_sell")
 * @param string|null $lang    language code; null = current user language
 * @param string|null $default fallback value if nothing is found
 */
function bot_label($key, $lang = null, $default = null, $bot_id = null)
{
    global $pdo, $from_id;
    static $cache = [];

    if ($lang === null) {
        $allowed = ['fa', 'en', 'ar', 'ru', 'zh'];
        $lang = 'fa';
        if (!empty($from_id)) {
            $u = select("user", "*", "id", $from_id);
            if ($u && !empty($u['lang']) && in_array($u['lang'], $allowed, true)) {
                $lang = $u['lang'];
            }
        }
    }

    // bot_id: which bot scope to resolve. Defaults to the current bot context
    // (BOT_ID constant set by the child-bot router) or 0 = main bot.
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }

    $ck = $bot_id . '|' . $lang . '|' . $key;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck] !== null ? $cache[$ck] : ($default !== null ? $default : $key);
    }

    // 1) DB override — first per-bot (bot_id), then fall back to main bot (0)
    $value = null;
    try {
        if (isset($pdo)) {
            $stmt = $pdo->prepare("SELECT label_value FROM botlabels WHERE label_key = ? AND lang = ? AND bot_id IN (?, 0) ORDER BY (bot_id = ?) DESC LIMIT 1");
            $stmt->execute([$key, $lang, $bot_id, $bot_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['label_value']) && $row['label_value'] !== '') {
                $value = $row['label_value'];
            }
        }
    } catch (Exception $e) {
        // table may not exist yet on first run; ignore and fall back
    }

    // 2) language file textbot section
    if ($value === null) {
        $langData = languagechange(null, $lang);
        if (isset($langData['textbot'][$key]) && $langData['textbot'][$key] !== '') {
            $value = $langData['textbot'][$key];
        }
    }

    $cache[$ck] = $value;
    if ($value !== null) {
        return $value;
    }
    return $default !== null ? $default : $key;
}

/**
 * Get the default (language-file) value for a label key, ignoring DB overrides.
 * Used by the panel to show "original" text next to the editable field.
 */
function bot_label_default($key, $lang = 'fa')
{
    $langData = languagechange(null, $lang);
    if (isset($langData['textbot'][$key]) && is_string($langData['textbot'][$key])) {
        return $langData['textbot'][$key];
    }
    return '';
}

/**
 * Create/update/delete a per-bot label override in the `botlabels` table.
 * - $value === null or '' deletes the override (falls back to language file).
 * - $bot_id = 0 means the main bot; >0 a child bot (botsaz.id).
 */
function set_bot_label($key, $lang, $value, $bot_id = 0)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    $bot_id = (int) $bot_id;
    try {
        if ($value === null || trim($value) === '') {
            $stmt = $pdo->prepare("DELETE FROM botlabels WHERE bot_id = ? AND label_key = ? AND lang = ?");
            return $stmt->execute([$bot_id, $key, $lang]);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO botlabels (bot_id, label_key, lang, label_value, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE label_value = VALUES(label_value), updated_at = NOW()"
        );
        return $stmt->execute([$bot_id, $key, $lang, $value]);
    } catch (Exception $e) {
        error_log("set_bot_label error: " . $e->getMessage());
        return false;
    }
}

/**
 * Save the store terminology map (JSON) for the main bot or a child bot.
 * Main bot -> setting.store_terminology ; child bot -> botsaz.setting JSON key.
 */
function set_store_terminology(array $terms, $bot_id = 0)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    $bot_id = (int) $bot_id;
    try {
        if ($bot_id > 0) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([$bot_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $set = ($row && !empty($row['setting'])) ? json_decode($row['setting'], true) : [];
            if (!is_array($set)) {
                $set = [];
            }
            $set['store_terminology'] = $terms;
            $up = $pdo->prepare("UPDATE botsaz SET setting = ? WHERE id = ?");
            return $up->execute([json_encode($set, JSON_UNESCAPED_UNICODE), $bot_id]);
        }
        $up = $pdo->prepare("UPDATE setting SET store_terminology = ?");
        return $up->execute([json_encode($terms, JSON_UNESCAPED_UNICODE)]);
    } catch (Exception $e) {
        error_log("set_store_terminology error: " . $e->getMessage());
        return false;
    }
}

// ===========================================================================
// Pure-PHP database dump (mysqldump-free fallback).
//
// Why this exists: the server here is MySQL 8.4 while the installed dump
// client is MariaDB's mysqldump. That mix frequently aborts mid-stream with
// exit code 7 (MariaDB EX_CONSCHECK / "Couldn't read data" while it streams a
// table that uses an 8.0-only collation such as utf8mb4_0900_ai_ci). When that
// happens mysqldump writes a partial/empty file and the nightly backup fails.
//
// This function reproduces a mysqldump-compatible .sql file using nothing but
// the PDO connection the bot already has, so it works no matter which client
// binary (or none) is installed and never trips the version mismatch. It is
// used automatically as a fallback when mysqldump fails.
//
// Returns true on success (file written, non-empty), false otherwise. On
// failure it sets $errOut (by reference) to a human-readable reason.
// ===========================================================================
function php_database_dump($outFile, &$errOut = null)
{
    global $pdo, $dbname;
    $errOut = '';
    if (!isset($pdo)) {
        $errOut = 'اتصال PDO به دیتابیس در دسترس نیست (config.php را بررسی کنید).';
        return false;
    }

    $fh = @fopen($outFile, 'wb');
    if ($fh === false) {
        $errOut = 'امکان ساخت فایل خروجی نبود (مجوز نوشتن یا فضای دیسک را بررسی کنید).';
        return false;
    }

    try {
        // Header mirrors mysqldump so the file restores the same way.
        fwrite($fh, "-- PHP fallback dump (mysqldump unavailable / failed)\n");
        fwrite($fh, "-- Database: " . (string) $dbname . "\n");
        fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($fh, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
        fwrite($fh, "/*!40101 SET NAMES utf8mb4 */;\n");
        fwrite($fh, "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n");
        fwrite($fh, "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n");

        // Enumerate base tables (skip views; we add them after).
        $tables = [];
        $views = [];
        $rows = $pdo->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $r) {
            $name = $r[0];
            $type = isset($r[1]) ? strtoupper((string) $r[1]) : 'BASE TABLE';
            if (strpos($type, 'VIEW') !== false) {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }

        foreach ($tables as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';

            // Structure.
            fwrite($fh, "\n--\n-- Table structure for table $q\n--\n\n");
            fwrite($fh, "DROP TABLE IF EXISTS $q;\n");
            $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM);
            if (isset($create[1])) {
                fwrite($fh, $create[1] . ";\n\n");
            }

            // Data — stream row by row to keep memory flat on huge tables.
            fwrite($fh, "--\n-- Dumping data for table $q\n--\n\n");
            $stmt = $pdo->query("SELECT * FROM $q");
            $colCount = $stmt->columnCount();
            $batch = [];
            $batchLen = 0;
            $flush = function () use (&$batch, &$batchLen, $fh, $q) {
                if (empty($batch)) {
                    return;
                }
                fwrite($fh, "INSERT INTO $q VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
                $batchLen = 0;
            };
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $vals = [];
                for ($i = 0; $i < $colCount; $i++) {
                    $v = $row[$i];
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = (string) $v;
                    } else {
                        $vals[] = $pdo->quote((string) $v);
                    }
                }
                $tuple = '(' . implode(',', $vals) . ')';
                $batch[] = $tuple;
                $batchLen += strlen($tuple);
                // Cap each multi-row INSERT near ~512KB to stay restore-friendly.
                if ($batchLen >= 512000) {
                    $flush();
                }
            }
            $flush();
            fwrite($fh, "\n");
        }

        // Views last (their tables must already exist). Use DROP VIEW (not
        // DROP TABLE) and strip the DEFINER clause so the dump restores on any
        // host even if that MySQL user doesn't exist there.
        foreach ($views as $view) {
            $q = '`' . str_replace('`', '``', $view) . '`';
            fwrite($fh, "\n--\n-- View structure for view $q\n--\n\n");
            fwrite($fh, "DROP VIEW IF EXISTS $q;\n");
            $create = $pdo->query("SHOW CREATE VIEW $q")->fetch(PDO::FETCH_NUM);
            // SHOW CREATE VIEW returns the statement in column index 1.
            if (isset($create[1])) {
                $stmt = (string) $create[1];
                // Remove "DEFINER=`user`@`host` " so the view isn't tied to a
                // specific account on restore (mysqldump does the same with
                // --skip-definer-style portability).
                $stmt = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $stmt);
                // Likewise drop "SQL SECURITY DEFINER" → leave engine default.
                $stmt = preg_replace('/SQL SECURITY DEFINER\s*/', '', $stmt);
                fwrite($fh, $stmt . ";\n\n");
            }
        }

        fwrite($fh, "\n/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n");
        fwrite($fh, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n");
        fclose($fh);
    } catch (Exception $e) {
        @fclose($fh);
        $errOut = 'خطای PDO هنگام دامپ: ' . $e->getMessage();
        return false;
    }

    if (!@file_exists($outFile) || @filesize($outFile) <= 0) {
        $errOut = 'فایل دامپ ساخته شد ولی خالی بود.';
        return false;
    }
    return true;
}

/**
 * Registry of supported product types (generic e-commerce model).
 * Each type declares a label and the attribute fields it uses.
 * - 'vpn' is the legacy/default type; its data lives in the dedicated columns
 *   (Volume_constraint, Location, inbounds, ...), so it has no extra attributes.
 * - Other types store their extra fields in product.attributes (JSON).
 *
 * Simple field: ['key','label','type'=>text|number|textarea|bool|select,'hint','options'?]
 * Repeater field (table of rows):
 *   ['key','label','type'=>'repeater','hint','columns'=>[ ['key','label','type'], ... ]]
 *   The value is stored as an array of associative rows in attributes JSON.
 */
function product_types()
{
    return [
        'vpn' => [
            'label'  => 'سرویس VPN (پیش‌فرض)',
            'examples' => [
                'name'     => 'مثلاً: ۵۰ گیگ یک ماهه',
                'category' => 'مثلاً: اشتراک، نمایندگی',
                'note'     => 'توضیح کوتاه دربارهٔ سرویس (اختیاری)',
            ],
            'fields' => [], // handled by dedicated legacy columns
        ],
        'physical' => [
            'label'  => 'کالای فیزیکی',
            'examples' => [
                'name'     => 'مثلاً: هدفون بلوتوثی مدل X',
                'category' => 'مثلاً: لوازم جانبی، پوشاک',
                'note'     => 'توضیح کالا، مشخصات فنی… (اختیاری)',
            ],
            'fields' => [
                ['key' => 'brand',         'label' => 'برند', 'type' => 'text', 'hint' => 'اختیاری'],
                ['key' => 'warranty',      'label' => 'گارانتی', 'type' => 'text', 'hint' => 'مثلاً: ۱۸ ماه'],
                ['key' => 'weight',        'label' => 'وزن (گرم)', 'type' => 'number', 'hint' => 'برای محاسبهٔ هزینهٔ ارسال'],
                ['key' => 'needs_address', 'label' => 'نیاز به آدرس پستی', 'type' => 'bool', 'hint' => 'دریافت آدرس هنگام خرید'],
                // Single-stock fields (used when the product has NO variants/پس‌کد).
                // hide_when=has_variants → these are auto-hidden & disabled once the
                // seller ticks "پس‌کد/تنوع دارد", because in that mode the real
                // stock/code live per-variant (inside the variants table). This
                // prevents the confusing "two stock fields" the seller noticed.
                ['key' => 'sku',           'label' => 'کد انبار (SKU)', 'type' => 'text', 'hide_when' => 'has_variants', 'hint' => 'اختیاری — فقط وقتی پس‌کد/تنوع ندارید'],
                ['key' => 'stock',         'label' => 'موجودی کل', 'type' => 'number', 'hide_when' => 'has_variants', 'hint' => 'فقط وقتی پس‌کد/تنوع ندارید. وقتی تنوع فعال است، موجودی از مجموع موجودیِ تنوع‌ها محاسبه می‌شود.'],

                // Gate: enabling "پس‌کد/تنوع" reveals the variants table. When this
                // is checked the product is treated as multi-variant (e.g. several
                // colors), each variant carries its own code, stock, price & image,
                // and the single SKU/stock above are ignored.
                ['key' => 'has_variants', 'label' => 'این محصول پس‌کد/تنوع دارد (چند رنگ/سایز)', 'type' => 'bool',
                 'hint' => 'با تیک‌زدن، فیلدهای «کد انبار» و «موجودی کل» بالا غیرفعال می‌شوند و در عوض می‌توانید ویژگی‌های دلخواه (رنگ، سایز، جنس، …) را خودتان بسازید و برای هر تنوع پس‌کد، موجودی، قیمت و تصویر جدا ثبت کنید. موجودی کل محصول از مجموع موجودیِ همان تنوع‌ها محاسبه می‌شود.'],

                // Variants: an INLINE attribute builder. The seller defines the
                // columns themselves with "افزودن ویژگی" (pick type text/number/
                // image/select + a name), so each product gets exactly the fields
                // it needs (clothing → رنگ/سایز/جنس, cosmetics → حجم/شِید, …).
                // The built columns are stored per-product in attributes._variant_cols;
                // موجودی/اختلاف قیمت are appended automatically. Shown only when
                // has_variants is ticked (data-show-when).
                ['key' => 'variants', 'label' => 'تنوع محصول (ویژگی‌های دلخواه)', 'type' => 'repeater',
                 'show_when' => 'has_variants',
                 'dynamic_columns' => true,  // columns built inline by the seller (JS)
                 'builder' => true,          // show the "+ افزودن ویژگی" column builder
                 'hint' => 'ابتدا با «+ افزودن ویژگی» ستون‌های دلخواه را بسازید (نوع: متن، عدد، تصویر یا لیست انتخابی). سپس برای هر تنوع یک ردیف اضافه کنید. ستون‌های موجودی و اختلاف قیمت خودکار اضافه می‌شوند. وقتی بیش از یک تنوع داشته باشید، نشان «پس‌کد دارد» ظاهر می‌شود و می‌توانید برای هر تنوع تصویر مجزا بگذارید.',
                 // Fallback columns used only if the seller adds none.
                 'columns' => [
                    ['key' => 'stock',      'label' => 'موجودی',         'type' => 'number'],
                    ['key' => 'price_diff', 'label' => 'اختلاف قیمت (+/−)', 'type' => 'number'],
                 ],
                ],

                // Carriers: pick from the merchant's enabled (API-backed) carriers
                // instead of typing a company name. Customer chooses one at checkout.
                ['key' => 'carriers', 'label' => 'شرکت‌های پستی مجاز برای این محصول', 'type' => 'carriers',
                 'hint' => 'از شرکت‌های پستی فعال (تنظیم‌شده در بخش ارسال) یک یا چند مورد را انتخاب کنید؛ مشتری هنگام خرید یکی را برمی‌گزیند.'],
            ],
        ],
        'digital_file' => [
            'label'  => 'فایل دیجیتال',
            'examples' => [
                'name'     => 'مثلاً: کتاب صوتی، قالب آماده',
                'category' => 'مثلاً: کتاب، قالب، نرم‌افزار',
                'note'     => 'توضیح فایل و کاربرد آن (اختیاری)',
            ],
            'fields' => [
                ['key' => 'file_id',   'label' => 'شناسهٔ فایل تلگرام (file_id)', 'type' => 'text',     'hint' => 'فایل پس از خرید ارسال می‌شود'],
                ['key' => 'file_type', 'label' => 'نوع فایل', 'type' => 'select', 'hint' => '',
                 'options' => [
                    'document' => 'سند/فایل (document)',
                    'photo'    => 'تصویر (photo)',
                    'video'    => 'ویدیو (video)',
                    'audio'    => 'صوت (audio)',
                 ]],
                ['key' => 'caption',   'label' => 'توضیح همراه فایل', 'type' => 'textarea', 'hint' => ''],
            ],
        ],
        'serial_code' => [
            'label'  => 'کد/سریال (لایسنس)',
            'examples' => [
                'name'     => 'مثلاً: لایسنس آنتی‌ویروس ۱ ساله',
                'category' => 'مثلاً: لایسنس، گیفت‌کارت',
                'note'     => 'توضیح نحوهٔ فعال‌سازی کد (اختیاری)',
            ],
            'fields' => [
                ['key' => 'code_format', 'label' => 'قالب نمایش کد', 'type' => 'text', 'hint' => 'مثلاً: کد شما: {code}'],
            ],
        ],
        'service' => [
            'label'  => 'خدمت/سرویس عمومی',
            'examples' => [
                'name'     => 'مثلاً: طراحی لوگو، مشاوره',
                'category' => 'مثلاً: خدمات، مشاوره',
                'note'     => 'توضیح خدمت و نحوهٔ ارائه (اختیاری)',
            ],
            'fields' => [
                ['key' => 'delivery_note', 'label' => 'توضیح تحویل', 'type' => 'textarea', 'hint' => 'متنی که پس از خرید نمایش داده می‌شود'],
            ],
        ],
    ];
}

/** Return true if a given product type code is known. */
function is_valid_product_type($type)
{
    return array_key_exists((string) $type, product_types());
}

/**
 * Decode the attributes JSON of a product row into an array (safe).
 * Accepts either a full product row (array) or a raw JSON string.
 */
/**
 * Parse a money value that may contain thousands separators or other
 * formatting (e.g. "1,200,000" or "۱٬۲۰۰٬۰۰۰") into a plain integer.
 * Keeps a leading minus sign so price-difference values can be negative.
 */
function money_int($value)
{
    if (is_int($value)) {
        return $value;
    }
    $s = (string) $value;
    // Normalise Persian/Arabic digits to ASCII.
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $s = str_replace($fa, $en, $s);
    $s = str_replace($ar, $en, $s);
    $neg = (strpos($s, '-') !== false);
    $s = preg_replace('/[^\d]/', '', $s);
    if ($s === '') {
        return 0;
    }
    $n = (int) $s;
    return $neg ? -$n : $n;
}

function product_attributes($productOrJson)
{
    $raw = is_array($productOrJson) ? ($productOrJson['attributes'] ?? null) : $productOrJson;
    if (empty($raw)) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** Read a single attribute value from a product row/JSON, with default. */
function product_attr($productOrJson, $key, $default = null)
{
    $attrs = product_attributes($productOrJson);
    return array_key_exists($key, $attrs) ? $attrs[$key] : $default;
}

/**
 * Persist the product_type + attributes JSON for a product row.
 * Validates the type and keeps only fields declared for that type.
 */
function set_product_type($product_id, $type, array $attributes = [], array $keepRows = [])
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    if (!is_valid_product_type($type)) {
        $type = 'vpn';
    }
    $types = product_types();
    // Index declared fields by key so we can sanitize per field type.
    $fieldDefs = [];
    foreach ($types[$type]['fields'] as $f) {
        $fieldDefs[$f['key']] = $f;
    }

    // Resolve the chosen variant schema (if any) up front, so the variants
    // repeater can accept the schema's custom columns instead of only the
    // fixed fallback columns declared in product_types().
    $variantSchemaId = 0;
    if (isset($attributes['_variant_schema'])) {
        $variantSchemaId = (int) $attributes['_variant_schema'];
    }
    // Persist the picked schema id (0/empty = default columns).
    if ($variantSchemaId > 0 && function_exists('variant_schema_get') && variant_schema_get($variantSchemaId)) {
        // keep it
    } else {
        $variantSchemaId = 0;
    }

    // Resolve per-product custom variant columns (_variant_cols). These are the
    // attributes the seller built INLINE on the product form ("افزودن ویژگی"):
    // each one is { key, label, type:[text|number|image|select], options?, allow_custom? }.
    // They take precedence over a picked schema and let every product define its
    // own variant columns (clothing → رنگ/سایز/جنس, cosmetics → حجم/شِید, …).
    $variantCols = [];
    if (isset($attributes['_variant_cols'])) {
        $variantCols = variant_normalize_columns($attributes['_variant_cols']);
    }

    // When the product has variants, the single موجودی کل / SKU inputs are
    // disabled in the UI and their values must NOT be persisted — stock & code
    // live per-variant instead. Compute the gate once so we can drop any
    // hide_when=has_variants field whose gate is currently ON (covers both a
    // fresh submit and a stale value left over from before تنوع was enabled).
    $hasVariantsOn = (function ($a) {
        $hv = $a['has_variants'] ?? null;
        return ($hv === '1' || $hv === 1 || $hv === true || $hv === 'on');
    })($attributes);

    $clean = [];
    if ($variantSchemaId > 0) {
        $clean['_variant_schema'] = $variantSchemaId;
    }
    if (!empty($variantCols)) {
        $clean['_variant_cols'] = $variantCols;
    }
    foreach ($attributes as $k => $v) {
        if ($k === '_variant_schema' || $k === '_variant_cols') {
            continue; // handled above
        }
        if (!isset($fieldDefs[$k])) {
            continue; // unknown field for this type → drop
        }
        $def = $fieldDefs[$k];
        $ftype = $def['type'] ?? 'text';

        // Drop fields gated by hide_when when their gate (e.g. has_variants) is
        // ON, so موجودی کل / SKU never compete with the per-variant values.
        if (!empty($def['hide_when'])) {
            $gate = $def['hide_when'];
            $gateOn = ($gate === 'has_variants') ? $hasVariantsOn : false;
            if ($gateOn) {
                continue;
            }
        }

        if ($ftype === 'repeater') {
            // Expect an array of rows; keep only declared columns, drop empty rows.
            if (!is_array($v)) {
                continue;
            }
            // For the variants repeater, the allowed columns come from the
            // seller's INLINE custom columns (_variant_cols) first; otherwise the
            // selected variant schema; otherwise the static fallback declared above.
            $cols = $def['columns'] ?? [];
            if (!empty($def['dynamic_columns'])) {
                if (!empty($variantCols)) {
                    $cols = variant_columns_with_builtins($variantCols);
                } elseif (function_exists('variant_schema_columns')) {
                    $cols = variant_schema_columns($variantSchemaId);
                }
            }
            $colKeys = [];
            foreach ($cols as $c) {
                $colKeys[$c['key']] = true;
            }
            // Rows that have a pending image upload for this field/index must be
            // kept even if their text cells are empty (image-only variants).
            $keepIdx = isset($keepRows[$k]) && is_array($keepRows[$k]) ? $keepRows[$k] : [];
            $rows = [];
            foreach ($v as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cleanRow = [];
                $hasValue = false;
                foreach ($row as $ck => $cv) {
                    if (!isset($colKeys[$ck])) {
                        continue;
                    }
                    if (is_array($cv)) {
                        // Multi-image cell: an array of slot urls. Keep non-empty
                        // slots, re-indexed; counts as a value if any slot is set.
                        $slots = [];
                        foreach ($cv as $slot) {
                            $slot = is_string($slot) ? trim($slot) : $slot;
                            if ($slot !== '' && $slot !== null) {
                                $slots[] = $slot;
                            }
                        }
                        $cleanRow[$ck] = $slots;
                        if (!empty($slots)) {
                            $hasValue = true;
                        }
                        continue;
                    }
                    $cv = is_string($cv) ? trim($cv) : $cv;
                    $cleanRow[$ck] = $cv;
                    if ($cv !== '' && $cv !== null) {
                        $hasValue = true;
                    }
                }
                if ($hasValue || in_array((string) $idx, $keepIdx, true)) {
                    // Preserve the original submitted index so per-row uploaded
                    // images (media_variant[k][idx]) line up with this row.
                    $rows[$idx] = $cleanRow;
                }
            }
            if (!empty($rows)) {
                $clean[$k] = $rows; // keep original keys (do NOT re-index)
            }
            continue;
        }

        if ($ftype === 'bool') {
            // Normalize checkbox-style values to 1/0.
            $clean[$k] = ($v === '1' || $v === 1 || $v === true || $v === 'on') ? 1 : 0;
            continue;
        }

        if ($ftype === 'carriers') {
            // Multi-select of carrier codes; keep only known, non-empty codes.
            $valid = function_exists('shipping_carriers') ? shipping_carriers() : [];
            $codes = [];
            foreach ((array) $v as $code) {
                $code = is_string($code) ? trim($code) : '';
                if ($code !== '' && (empty($valid) || isset($valid[$code])) && !in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
            if (!empty($codes)) {
                $clean[$k] = array_values($codes);
            }
            continue;
        }

        if ($ftype === 'select') {
            // Keep only values present in declared options.
            $opts = $def['options'] ?? [];
            if (array_key_exists((string) $v, $opts)) {
                $clean[$k] = $v;
            }
            continue;
        }

        // text / number / textarea → store as trimmed scalar (skip empty).
        if (is_array($v)) {
            continue;
        }
        $v = is_string($v) ? trim($v) : $v;
        if ($v !== '' && $v !== null) {
            $clean[$k] = $v;
        }
    }
    try {
        $json = empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("UPDATE product SET product_type = ?, attributes = ? WHERE id = ?");
        return $stmt->execute([$type, $json, (int) $product_id]);
    } catch (Exception $e) {
        error_log("set_product_type error: " . $e->getMessage());
        return false;
    }
}

// ===========================================================================
// Product categories (managed centrally, reused on the product form).
// Backed by the existing `category` table: id + remark (the category name).
// A product can belong to several categories; we store them as a comma-joined
// string in product.category (backward-compatible with the old free-text field
// and the existing search which does `category LIKE ?`).
// ===========================================================================

/** List all categories (name strings), sorted, de-duplicated. */
function categories_list()
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    try {
        $rows = $pdo->query("SELECT id, remark FROM category ORDER BY remark ASC")->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (Exception $e) {
        error_log("categories_list error: " . $e->getMessage());
        return [];
    }
}

/** Just the category names as a flat array. */
function categories_names()
{
    $names = [];
    foreach (categories_list() as $c) {
        $n = trim((string) ($c['remark'] ?? ''));
        if ($n !== '') {
            $names[] = $n;
        }
    }
    return $names;
}

/** Add a category by name (no duplicates, case-insensitive). Returns true on add. */
function categories_add($name)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    $name = trim((string) $name);
    if ($name === '' || mb_strlen($name) > 200) {
        return false;
    }
    try {
        // reject case-insensitive duplicate
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM category WHERE LOWER(remark) = LOWER(?)");
        $stmt->execute([$name]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }
        $stmt = $pdo->prepare("INSERT INTO category (remark) VALUES (?)");
        return $stmt->execute([$name]);
    } catch (Exception $e) {
        error_log("categories_add error: " . $e->getMessage());
        return false;
    }
}

/** Rename a category by id; also updates references inside product.category CSV. */
function categories_rename($id, $newName)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    $newName = trim((string) $newName);
    if ($newName === '' || mb_strlen($newName) > 200) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT remark FROM category WHERE id = ?");
        $stmt->execute([(int) $id]);
        $old = $stmt->fetchColumn();
        if ($old === false) {
            return false;
        }
        $stmt = $pdo->prepare("UPDATE category SET remark = ? WHERE id = ?");
        $stmt->execute([$newName, (int) $id]);
        // propagate rename into products that referenced the old name
        product_category_replace_name((string) $old, $newName);
        return true;
    } catch (Exception $e) {
        error_log("categories_rename error: " . $e->getMessage());
        return false;
    }
}

/** Delete a category by id; also strips it from product.category CSVs. */
function categories_delete($id)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT remark FROM category WHERE id = ?");
        $stmt->execute([(int) $id]);
        $name = $stmt->fetchColumn();
        $stmt = $pdo->prepare("DELETE FROM category WHERE id = ?");
        $stmt->execute([(int) $id]);
        if ($name !== false) {
            product_category_replace_name((string) $name, null); // remove from products
        }
        return true;
    } catch (Exception $e) {
        error_log("categories_delete error: " . $e->getMessage());
        return false;
    }
}

/** Parse a product.category CSV string into a clean array of names. */
function product_category_parse($csv)
{
    $parts = preg_split('/\s*,\s*/', (string) $csv, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_map('trim', $parts)));
}

/** Join an array of category names into the stored CSV form. */
function product_category_join(array $names)
{
    $clean = [];
    foreach ($names as $n) {
        $n = trim((string) $n);
        if ($n !== '' && !in_array($n, $clean, true)) {
            $clean[] = $n;
        }
    }
    return implode(', ', $clean);
}

/**
 * Replace (or remove, when $new===null) a category name inside every product's
 * category CSV. Keeps product assignments consistent after rename/delete.
 */
function product_category_replace_name($old, $new)
{
    global $pdo;
    if (!isset($pdo)) {
        return;
    }
    $old = trim((string) $old);
    if ($old === '') {
        return;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, category FROM product WHERE category LIKE ?");
        $stmt->execute(['%' . $old . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $upd = $pdo->prepare("UPDATE product SET category = ? WHERE id = ?");
        foreach ($rows as $r) {
            $names = product_category_parse($r['category'] ?? '');
            $changed = false;
            $out = [];
            foreach ($names as $n) {
                if (strcasecmp($n, $old) === 0) {
                    $changed = true;
                    if ($new !== null && trim((string) $new) !== '') {
                        $out[] = trim((string) $new);
                    }
                    // when $new is null -> drop it
                } else {
                    $out[] = $n;
                }
            }
            if ($changed) {
                $upd->execute([product_category_join($out), (int) $r['id']]);
            }
        }
    } catch (Exception $e) {
        error_log("product_category_replace_name error: " . $e->getMessage());
    }
}

/**
 * List media rows for a product, ordered by sort then id.
 */
function product_media_list($product_id)
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM product_media WHERE product_id = ? ORDER BY sort ASC, id ASC");
        $stmt->execute([(int) $product_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Count media rows for a product (used by the panel to show a badge on the
 * "images" button so the admin can see at a glance that a product has media).
 */
function product_media_count($product_id)
{
    global $pdo;
    if (!isset($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_media WHERE product_id = ?");
        $stmt->execute([(int) $product_id]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Add a media record for a product.
 * $media_type: image|video|audio|document
 */
function product_media_add($product_id, $file_path, $media_type = 'image', $telegram_file_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    try {
        $sort = (int) ($pdo->query("SELECT COALESCE(MAX(sort),0)+1 FROM product_media WHERE product_id = " . (int) $product_id)->fetchColumn());
        $stmt = $pdo->prepare("INSERT INTO product_media (product_id, media_type, file_path, telegram_file_id, sort, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $ok = $stmt->execute([(int) $product_id, $media_type, $file_path, $telegram_file_id, $sort]);
        // Return the new row id (truthy) so callers can cache a telegram file_id.
        return $ok ? (int) $pdo->lastInsertId() : false;
    } catch (Exception $e) {
        error_log("product_media_add error: " . $e->getMessage());
        return false;
    }
}

/**
 * Cache a permanent Telegram file_id for a freshly-uploaded media row.
 *
 * Why: when a product is shown in the bot, media is sent by URL
 * ($domainhosts/uploads/...). That URL must be PUBLIC + HTTPS or Telegram
 * silently fails to fetch it (Audit-2). To make delivery robust and fast we
 * upload the local file ONCE here (CURLFile) to the main admin's chat and store
 * the returned file_id; afterwards the bot reuses that id (no public URL needed).
 *
 * Best-effort: if there is no admin chat or the upload fails, we keep the URL
 * fallback and simply return false — nothing breaks.
 *
 * @param int    $mediaId   product_media.id
 * @param string $absPath   absolute path of the stored file on disk
 * @param string $mediaType image|video|audio
 * @return string|false the cached file_id, or false if not cached
 */
function product_media_cache_telegram_id($mediaId, $absPath, $mediaType)
{
    global $pdo, $adminnumber;
    if (!isset($pdo) || !is_file($absPath) || !function_exists('telegram')) {
        return false;
    }

    // Resolve a chat to upload to: first registered admin, else $adminnumber.
    $chatId = null;
    try {
        $admins = select('admin', 'id_admin', null, null, 'FETCH_COLUMN');
        if (is_array($admins) && !empty($admins)) {
            $chatId = (string) $admins[0];
        }
    } catch (Exception $e) {
        // ignore
    }
    if ($chatId === null && isset($adminnumber) && $adminnumber !== '') {
        $chatId = (string) $adminnumber;
    }
    if ($chatId === null || (int) $chatId === 0) {
        return false;
    }

    // Pick the right Telegram method + the field that carries the file_id back.
    $map = [
        'image' => ['sendPhoto',    'photo',    'photo'],     // photo => array of sizes
        'video' => ['sendVideo',    'video',    'video'],
        'audio' => ['sendAudio',    'audio',    'audio'],
    ];
    if (!isset($map[$mediaType])) {
        return false;
    }
    [$method, $field, $resultKey] = $map[$mediaType];

    $res = telegram($method, [
        'chat_id'              => $chatId,
        $field                 => new CURLFile($absPath),
        'caption'              => '🗂 کش رسانهٔ محصول (می‌توانید این پیام را حذف کنید)',
        'disable_notification' => true,
    ]);

    if (!is_array($res) || empty($res['ok']) || empty($res['result'])) {
        return false;
    }
    $result = $res['result'];

    // Extract file_id depending on type.
    $fileId = null;
    if ($resultKey === 'photo') {
        // photo is an array of PhotoSize; take the largest (last) entry.
        if (!empty($result['photo']) && is_array($result['photo'])) {
            $last = end($result['photo']);
            $fileId = $last['file_id'] ?? null;
        }
    } elseif (isset($result[$resultKey]['file_id'])) {
        $fileId = $result[$resultKey]['file_id'];
    }

    if (!$fileId) {
        return false;
    }

    try {
        $pdo->prepare("UPDATE product_media SET telegram_file_id = ? WHERE id = ?")
            ->execute([$fileId, (int) $mediaId]);
    } catch (Exception $e) {
        error_log("product_media_cache_telegram_id error: " . $e->getMessage());
        return false;
    }
    return $fileId;
}

/**
 * Delete a media record (and its file on disk) by id.
 */
function product_media_delete($id)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    try {
        $row = null;
        $stmt = $pdo->prepare("SELECT file_path FROM product_media WHERE id = ?");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $del = $pdo->prepare("DELETE FROM product_media WHERE id = ?");
        $ok = $del->execute([(int) $id]);
        if ($ok && $row && !empty($row['file_path'])) {
            // file_path is stored relative to project root (e.g. uploads/products/xx.jpg)
            $abs = __DIR__ . '/' . ltrim($row['file_path'], '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        return $ok;
    } catch (Exception $e) {
        error_log("product_media_delete error: " . $e->getMessage());
        return false;
    }
}

/**
 * Detect a safe media type + extension for an uploaded file (by MIME).
 * Returns [media_type, ext] or null if not allowed.
 */
function product_media_detect($tmpPath, $originalName = '')
{
    $allowed = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png'  => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'image/gif'  => ['image', 'gif'],
        'video/mp4'  => ['video', 'mp4'],
        'video/webm' => ['video', 'webm'],
        'audio/mpeg' => ['audio', 'mp3'],
        'audio/ogg'  => ['audio', 'ogg'],
        'audio/wav'  => ['audio', 'wav'],
    ];
    $mime = null;
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($f, $tmpPath);
        finfo_close($f);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath);
    }
    if ($mime !== null && isset($allowed[$mime])) {
        return $allowed[$mime];
    }
    return null;
}

/**
 * Handle a multi-file upload ($_FILES['media'] shape) for a product, store the
 * accepted files under /uploads/products and register them in product_media.
 * Shared by product_media.php and the in-form uploader on product.php so the
 * logic lives in one place.
 *
 * @param int   $pid    product id
 * @param array $files  the $_FILES['media'] array (name/tmp_name/size/error as arrays)
 * @return array{ok:int, err:int}  counts of stored / rejected files
 */
function product_media_handle_upload($pid, $files)
{
    $result = ['ok' => 0, 'err' => 0];
    $pid = (int) $pid;
    if ($pid <= 0 || empty($files) || !isset($files['name']) || !is_array($files['name'])) {
        return $result;
    }

    // function.php lives at the project root, so uploads/ is a sibling of __DIR__.
    $uploadDirAbs = __DIR__ . '/uploads/products';
    $relPrefix    = 'uploads/products';
    if (!is_dir($uploadDirAbs)) {
        @mkdir($uploadDirAbs, 0755, true);
    }

    $maxBytes = 25 * 1024 * 1024; // 25 MB per file
    $names = $files['name'];
    for ($i = 0; $i < count($names); $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue; // skip empty slots silently
        }
        $tmp  = $files['tmp_name'][$i];
        $size = (int) ($files['size'][$i] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            $result['err']++;
            continue;
        }
        $detect = product_media_detect($tmp, $names[$i]);
        if ($detect === null) {
            $result['err']++;
            continue; // disallowed type
        }
        [$mediaType, $ext] = $detect;
        $fname = $pid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest  = $uploadDirAbs . '/' . $fname;
        if (@move_uploaded_file($tmp, $dest)) {
            @chmod($dest, 0644);
            $mediaId = product_media_add($pid, $relPrefix . '/' . $fname, $mediaType);
            // Cache a permanent Telegram file_id so the bot can deliver media
            // reliably even without a public HTTPS URL. Best-effort.
            if ($mediaId && function_exists('product_media_cache_telegram_id')) {
                @product_media_cache_telegram_id($mediaId, $dest, $mediaType);
            }
            $result['ok']++;
        } else {
            $result['err']++;
        }
    }
    return $result;
}

/**
 * Save a single uploaded variant image (one $_FILES slot) and return its
 * relative URL (e.g. "uploads/products/variants/12_ab.jpg"), or null on failure.
 * Only images are accepted. Used for per-color/per-پس‌کد variant pictures.
 *
 * NOTE: kept for backward-compatibility. New code should use
 * product_variant_file_save() which accepts a per-column allowed-format list
 * (so the seller can upload PDF/Word/ZIP/… too, not just images).
 */
function product_variant_image_save($pid, $tmp, $origName, $size, $error)
{
    return product_variant_file_save($pid, $tmp, $origName, $size, $error, ['jpg', 'png', 'webp', 'gif']);
}

/**
 * Detect a variant upload against the format catalogue (variant_file_formats()).
 * Returns [formatKey, ext] (e.g. ['pdf','pdf'] or ['jpg','jpg']) or null when the
 * file's MIME / extension matches none of the catalogue entries.
 *
 * We match by MIME first (robust), then fall back to extension (covers cases
 * where finfo is unavailable or returns a generic octet-stream for Office docs).
 */
function product_variant_file_detect($tmpPath, $origName = '')
{
    $cat = variant_file_formats();
    $mime = null;
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($f, $tmpPath);
        finfo_close($f);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath);
    }
    $ext = strtolower(pathinfo((string) $origName, PATHINFO_EXTENSION));

    // 1) MIME match.
    if ($mime !== null) {
        foreach ($cat as $key => $def) {
            if (in_array($mime, $def['mimes'], true)) {
                $useExt = ($ext !== '' && in_array($ext, $def['exts'], true)) ? $ext : $def['exts'][0];
                return [$key, $useExt];
            }
        }
    }
    // 2) Extension fallback.
    if ($ext !== '') {
        foreach ($cat as $key => $def) {
            if (in_array($ext, $def['exts'], true)) {
                return [$key, $ext];
            }
        }
    }
    return null;
}

/**
 * Save a single uploaded variant file (one $_FILES slot) and return its
 * relative URL, or null on failure. $allowedFormats is a list of format keys
 * from variant_file_formats() (e.g. ['pdf','doc'] or ['jpg','png']); the upload
 * is rejected when its detected format is not in that list. An empty/omitted
 * list means "any format in the catalogue is fine".
 */
function product_variant_file_save($pid, $tmp, $origName, $size, $error, $allowedFormats = [])
{
    $pid = (int) $pid;
    if ($pid <= 0 || ($error ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $size = (int) $size;
    if ($size <= 0 || $size > 25 * 1024 * 1024) {
        return null;
    }
    $detect = product_variant_file_detect($tmp, $origName);
    if ($detect === null) {
        return null; // unknown / disallowed type
    }
    [$fmtKey, $ext] = $detect;
    $allowedFormats = variant_clean_formats($allowedFormats);
    if (!empty($allowedFormats) && !in_array($fmtKey, $allowedFormats, true)) {
        return null; // not one of the formats this column accepts
    }
    $dirAbs = __DIR__ . '/uploads/products/variants';
    $relPrefix = 'uploads/products/variants';
    if (!is_dir($dirAbs)) {
        @mkdir($dirAbs, 0755, true);
    }
    $fname = $pid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dirAbs . '/' . $fname;
    if (@move_uploaded_file($tmp, $dest)) {
        @chmod($dest, 0644);
        return $relPrefix . '/' . $fname;
    }
    return null;
}

/**
 * After set_product_type(), merge any uploaded per-variant images into the
 * product's stored attributes. $variantFiles is the normalised
 * $_FILES['media_variant'] array.
 *
 * Supports BOTH layouts:
 *   - legacy 2-level: media_variant[fieldKey][idx]              → attrs[field][idx]['image']
 *   - new 4-level:    media_variant[fieldKey][idx][colKey][slot] → attrs[field][idx][colKey][slot]
 *
 * The 4-level form lets the seller build any number of image columns and
 * several images per column (e.g. multiple photos for one color).
 * Returns the number of images saved.
 */
function product_apply_variant_images($pid, $variantFiles)
{
    global $pdo;
    $pid = (int) $pid;
    if ($pid <= 0 || !is_array($variantFiles) || !isset($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("SELECT attributes FROM product WHERE id = ?");
        $stmt->execute([$pid]);
        $raw = $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
    $attrs = $raw ? json_decode((string) $raw, true) : [];
    if (!is_array($attrs)) {
        $attrs = [];
    }

    // Build a colKey → allowed-formats map from the product's own _variant_cols,
    // so each upload is validated against exactly the formats the seller chose
    // for that column (image columns → jpg/png/…, a "فایل" column → pdf/doc/…).
    $colFormats = [];
    if (!empty($attrs['_variant_cols'])) {
        foreach (variant_normalize_columns($attrs['_variant_cols']) as $c) {
            if (!empty($c['formats']) && is_array($c['formats'])) {
                $colFormats[$c['key']] = $c['formats'];
            }
        }
    }

    // Flatten the (possibly deeply) nested $_FILES sub-array into a list of
    // [pathKeys[], name, tmp, size, error] entries. PHP normalises a nested file
    // input into parallel trees under name/tmp_name/size/error.
    $entries = [];
    foreach ($variantFiles as $fieldKey => $node) {
        if (!is_array($node) || !isset($node['name'])) {
            continue;
        }
        $walk = function ($nameNode, $tmpNode, $sizeNode, $errNode, $path) use (&$walk, &$entries) {
            if (is_array($nameNode)) {
                foreach ($nameNode as $k => $sub) {
                    $walk(
                        $sub,
                        is_array($tmpNode) ? ($tmpNode[$k] ?? null) : null,
                        is_array($sizeNode) ? ($sizeNode[$k] ?? null) : null,
                        is_array($errNode) ? ($errNode[$k] ?? null) : null,
                        array_merge($path, [$k])
                    );
                }
                return;
            }
            $entries[] = [
                'path' => $path,
                'name' => $nameNode,
                'tmp'  => $tmpNode,
                'size' => $sizeNode,
                'err'  => $errNode,
            ];
        };
        $walk($node['name'], $node['tmp_name'] ?? null, $node['size'] ?? null, $node['error'] ?? null, [$fieldKey]);
    }

    $saved = 0;
    foreach ($entries as $e) {
        if (($e['err'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $path = $e['path']; // e.g. [fieldKey, idx] or [fieldKey, idx, colKey, slot]
        // Pick the allowed-format whitelist for this column (if any). 4-level
        // paths carry the colKey at index 2; legacy 2-level uploads are images.
        $allowed = [];
        if (count($path) === 4 && isset($colFormats[$path[2]])) {
            $allowed = $colFormats[$path[2]];
        } elseif (count($path) === 2) {
            $allowed = ['jpg', 'png', 'webp', 'gif'];
        }
        $url = product_variant_file_save($pid, $e['tmp'] ?? '', $e['name'] ?? '', $e['size'] ?? 0, $e['err'] ?? 0, $allowed);
        if (!$url) {
            continue;
        }
        if (count($path) === 2) {
            // Legacy: store on the row's 'image' key.
            [$fk, $idx] = $path;
            if (isset($attrs[$fk][$idx]) && is_array($attrs[$fk][$idx])) {
                $attrs[$fk][$idx]['image'] = $url;
                $saved++;
            }
        } elseif (count($path) === 4) {
            // New: attrs[field][idx][colKey][slot] = url
            [$fk, $idx, $colKey, $slot] = $path;
            if (!isset($attrs[$fk]) || !is_array($attrs[$fk])) {
                $attrs[$fk] = [];
            }
            if (!isset($attrs[$fk][$idx]) || !is_array($attrs[$fk][$idx])) {
                $attrs[$fk][$idx] = [];
            }
            if (!isset($attrs[$fk][$idx][$colKey]) || !is_array($attrs[$fk][$idx][$colKey])) {
                $attrs[$fk][$idx][$colKey] = [];
            }
            $attrs[$fk][$idx][$colKey][$slot] = $url;
            $saved++;
        }
    }

    if ($saved > 0) {
        try {
            $json = json_encode($attrs, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("UPDATE product SET attributes = ? WHERE id = ?");
            $stmt->execute([$json, $pid]);
        } catch (Exception $e) {
            error_log("product_apply_variant_images error: " . $e->getMessage());
        }
    }
    return $saved;
}

// ===========================================================================
// Variant schemas (custom, category-like variant templates).
// (The per-variant image helpers product_variant_image_save() /
//  product_apply_variant_images() are defined above, merged from main.)
//
// Why: a clothing product needs size/color/material/پس‌کد, a cosmetics product
// needs volume/shade, a home appliance needs voltage/warranty — there is no
// single fixed variant shape that fits everything. So the merchant defines
// reusable "variant schemas" (like categories) and, on each product, picks one.
// Each schema is a named set of custom columns; every column can be:
//   * a free text/number cell, OR
//   * a dropdown (select) backed by a predefined value list, optionally with
//     "allow_custom" so the seller can still type a one-off value.
//
// Storage: table `variant_schema` (id, name, fields JSON, created_at).
// A `fields` entry looks like:
//   { "key":"color", "label":"رنگ", "type":"select",
//     "options":["قرمز","آبی"], "allow_custom":1 }
// type ∈ text | number | select | image
//
// On a product, the chosen schema id is stored in attributes._variant_schema
// and the rows themselves stay in the existing `variants` repeater, so nothing
// about checkout/stock/price-diff handling changes.
// ===========================================================================

/** Create the variant_schema table on demand (safe to call repeatedly). */
function ensure_variant_schema_table()
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    static $done = false;
    if ($done) {
        return true;
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS variant_schema (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(200) NOT NULL,
                fields MEDIUMTEXT NULL,
                created_at DATETIME NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
        return true;
    } catch (Exception $e) {
        error_log("ensure_variant_schema_table error: " . $e->getMessage());
        return false;
    }
}

/**
 * Normalise a raw fields definition (array of column defs) into a clean,
 * safe structure. Drops malformed entries, dedups keys, and validates type.
 */
function variant_schema_normalize_fields($fields)
{
    if (is_string($fields)) {
        $fields = json_decode($fields, true);
    }
    if (!is_array($fields)) {
        return [];
    }
    $allowedTypes = ['text', 'number', 'select', 'image'];
    $out = [];
    $seen = [];
    foreach ($fields as $f) {
        if (!is_array($f)) {
            continue;
        }
        $label = isset($f['label']) ? trim((string) $f['label']) : '';
        if ($label === '') {
            continue;
        }
        // Derive a stable key from the explicit key or the label.
        $key = isset($f['key']) ? trim((string) $f['key']) : '';
        if ($key === '') {
            $key = variant_schema_slug($label);
        } else {
            $key = variant_schema_slug($key);
        }
        if ($key === '' || isset($seen[$key])) {
            // Ensure uniqueness even when labels collide.
            $key = $key === '' ? 'col' : $key;
            $i = 2;
            $base = $key;
            while (isset($seen[$key])) {
                $key = $base . $i;
                $i++;
            }
        }
        $seen[$key] = true;

        $type = isset($f['type']) ? (string) $f['type'] : 'text';
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }

        $col = ['key' => $key, 'label' => $label, 'type' => $type];

        if ($type === 'select') {
            $opts = [];
            $raw = $f['options'] ?? [];
            if (is_string($raw)) {
                // Accept newline- or comma-separated option text.
                $raw = preg_split('/[\r\n,]+/', $raw);
            }
            if (is_array($raw)) {
                foreach ($raw as $o) {
                    $o = trim((string) $o);
                    if ($o !== '' && !in_array($o, $opts, true)) {
                        $opts[] = $o;
                    }
                }
            }
            $col['options'] = $opts;
            // allow_custom lets the seller type a value not in the list.
            $ac = $f['allow_custom'] ?? 1;
            $col['allow_custom'] = ($ac === 1 || $ac === '1' || $ac === true || $ac === 'on') ? 1 : 0;
        }

        $out[] = $col;
    }
    return $out;
}

/** Turn a label into a safe ascii/utf8 attribute key (letters, digits, _). */
function variant_schema_slug($s)
{
    $s = trim((string) $s);
    // Keep unicode letters/digits, turn everything else into underscores.
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);
    $s = trim($s, '_');
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    return $s;
}

/** List all variant schemas (id, name, fields[]), newest first. */
function variant_schemas_list()
{
    global $pdo;
    if (!isset($pdo) || !ensure_variant_schema_table()) {
        return [];
    }
    try {
        $rows = $pdo->query("SELECT id, name, fields, created_at FROM variant_schema ORDER BY id DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['fields'] = variant_schema_normalize_fields($r['fields'] ?? '[]');
        }
        unset($r);
        return $rows ?: [];
    } catch (Exception $e) {
        error_log("variant_schemas_list error: " . $e->getMessage());
        return [];
    }
}

/** Fetch one schema by id (with normalised fields[]), or null. */
function variant_schema_get($id)
{
    global $pdo;
    $id = (int) $id;
    if ($id <= 0 || !isset($pdo) || !ensure_variant_schema_table()) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT id, name, fields, created_at FROM variant_schema WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['fields'] = variant_schema_normalize_fields($row['fields'] ?? '[]');
        return $row;
    } catch (Exception $e) {
        error_log("variant_schema_get error: " . $e->getMessage());
        return null;
    }
}

/** Create a new variant schema. Returns new id or false. */
function variant_schema_add($name, $fields)
{
    global $pdo;
    if (!isset($pdo) || !ensure_variant_schema_table()) {
        return false;
    }
    $name = trim((string) $name);
    if ($name === '') {
        return false;
    }
    $clean = variant_schema_normalize_fields($fields);
    try {
        $stmt = $pdo->prepare("INSERT INTO variant_schema (name, fields, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$name, json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        return (int) $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("variant_schema_add error: " . $e->getMessage());
        return false;
    }
}

/** Update an existing variant schema. */
function variant_schema_update($id, $name, $fields)
{
    global $pdo;
    $id = (int) $id;
    if ($id <= 0 || !isset($pdo) || !ensure_variant_schema_table()) {
        return false;
    }
    $name = trim((string) $name);
    if ($name === '') {
        return false;
    }
    $clean = variant_schema_normalize_fields($fields);
    try {
        $stmt = $pdo->prepare("UPDATE variant_schema SET name = ?, fields = ? WHERE id = ?");
        return $stmt->execute([$name, json_encode($clean, JSON_UNESCAPED_UNICODE), $id]);
    } catch (Exception $e) {
        error_log("variant_schema_update error: " . $e->getMessage());
        return false;
    }
}

/** Delete a variant schema by id. */
function variant_schema_delete($id)
{
    global $pdo;
    $id = (int) $id;
    if ($id <= 0 || !isset($pdo) || !ensure_variant_schema_table()) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("DELETE FROM variant_schema WHERE id = ?");
        return $stmt->execute([$id]);
    } catch (Exception $e) {
        error_log("variant_schema_delete error: " . $e->getMessage());
        return false;
    }
}

/**
 * Build the repeater "columns" definition for a given variant schema id,
 * ready to be merged into the product form's variants table. Always appends
 * the universal stock / price-diff / image columns so checkout still works.
 * Falls back to the legacy fixed columns when no/invalid schema is given.
 */
function variant_schema_columns($schemaId)
{
    $legacy = [
        ['key' => 'variant_code', 'label' => 'پس‌کد',           'type' => 'text'],
        ['key' => 'color',        'label' => 'رنگ',             'type' => 'text'],
        ['key' => 'size',         'label' => 'سایز',            'type' => 'text'],
        ['key' => 'stock',        'label' => 'موجودی',          'type' => 'number'],
        ['key' => 'price_diff',   'label' => 'اختلاف قیمت (+/−)', 'type' => 'number'],
        ['key' => 'image',        'label' => 'تصویر این تنوع',   'type' => 'image'],
    ];
    $schema = variant_schema_get($schemaId);
    if (!$schema || empty($schema['fields'])) {
        return $legacy;
    }
    $cols = [];
    $reserved = ['stock', 'price_diff', 'image'];
    foreach ($schema['fields'] as $f) {
        // Don't let a custom field collide with the universal ones below.
        if (in_array($f['key'], $reserved, true)) {
            continue;
        }
        $cols[] = $f;
    }
    // Universal columns appended to every schema so stock/pricing/image stay.
    $cols[] = ['key' => 'stock',      'label' => 'موجودی',          'type' => 'number'];
    $cols[] = ['key' => 'price_diff', 'label' => 'اختلاف قیمت (+/−)', 'type' => 'number'];
    $cols[] = ['key' => 'image',      'label' => 'تصویر این تنوع',   'type' => 'image'];
    return $cols;
}

/**
 * Catalogue of upload formats the seller may allow for a `file`/`image`
 * variant column. Maps a short format key → [mime patterns, extensions, label].
 * Used both to render the format picker and to validate uploads server-side.
 */
function variant_file_formats()
{
    return [
        // images
        'jpg'  => ['mimes' => ['image/jpeg'],                 'exts' => ['jpg', 'jpeg'], 'label' => 'JPG'],
        'png'  => ['mimes' => ['image/png'],                  'exts' => ['png'],         'label' => 'PNG'],
        'webp' => ['mimes' => ['image/webp'],                 'exts' => ['webp'],        'label' => 'WEBP'],
        'gif'  => ['mimes' => ['image/gif'],                  'exts' => ['gif'],         'label' => 'GIF'],
        // documents
        'pdf'  => ['mimes' => ['application/pdf'],            'exts' => ['pdf'],         'label' => 'PDF'],
        'doc'  => ['mimes' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'exts' => ['doc', 'docx'], 'label' => 'Word'],
        'xls'  => ['mimes' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'exts' => ['xls', 'xlsx'], 'label' => 'Excel'],
        'ppt'  => ['mimes' => ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'], 'exts' => ['ppt', 'pptx'], 'label' => 'PowerPoint'],
        'txt'  => ['mimes' => ['text/plain'],                 'exts' => ['txt'],         'label' => 'Text'],
        // archives
        'zip'  => ['mimes' => ['application/zip', 'application/x-zip-compressed'], 'exts' => ['zip'], 'label' => 'ZIP'],
        'rar'  => ['mimes' => ['application/x-rar-compressed', 'application/vnd.rar'], 'exts' => ['rar'], 'label' => 'RAR'],
        // media
        'mp4'  => ['mimes' => ['video/mp4'],                  'exts' => ['mp4'],         'label' => 'MP4 ویدیو'],
        'mp3'  => ['mimes' => ['audio/mpeg'],                 'exts' => ['mp3'],         'label' => 'MP3 صوت'],
    ];
}

/**
 * Format catalogue trimmed for the browser (no MIME internals): exts + label +
 * a "kind" hint (image|file) so the JS builder can offer image-only formats for
 * image columns and the full list for generic file columns.
 */
function variant_file_formats_js()
{
    $imageKeys = ['jpg', 'png', 'webp', 'gif'];
    $out = [];
    foreach (variant_file_formats() as $key => $def) {
        $out[$key] = [
            'exts'  => $def['exts'],
            'label' => $def['label'],
            'kind'  => in_array($key, $imageKeys, true) ? 'image' : 'file',
        ];
    }
    return $out;
}

/**
 * Normalise the INLINE per-product custom variant columns (_variant_cols).
 *
 * The seller builds these directly on the product form via "افزودن ویژگی":
 * for each attribute they pick a TYPE and a NAME (and optional placeholder,
 * options, file formats, count…), so every product gets exactly the fields it
 * needs — clothing → رنگ/سایز/جنس, a digital product → یک ستون «فایل PDF», …
 *
 * Accepts a JSON string or array. Each entry may contain:
 *   { key?, label, type, placeholder?, required?,
 *     options?, allow_custom?,           // select
 *     images_count?,                      // image
 *     formats?, files_count? }            // file
 * type ∈ text | number | textarea | url | date | color | bool | select | image | file
 *
 * The universal stock/price-diff are added later by variant_columns_with_builtins().
 *
 * @return array Clean list of column defs (may be empty).
 */
function variant_normalize_columns($cols)
{
    if (is_string($cols)) {
        $decoded = json_decode($cols, true);
        $cols = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($cols)) {
        return [];
    }
    $allowed = ['text', 'number', 'textarea', 'url', 'date', 'color', 'bool', 'select', 'image', 'file'];
    // price_diff is fully automatic and never user-defined. stock is allowed as a
    // *seeded* default column (موجودی) so it can be a removable chip whose per-row
    // value still feeds product_stock(); it is de-duplicated like any other key.
    $reserved = ['price_diff'];
    $formatsCat = variant_file_formats();
    $out = [];
    $seen = [];
    foreach ($cols as $c) {
        if (!is_array($c)) {
            continue;
        }
        $label = isset($c['label']) ? trim((string) $c['label']) : '';
        if ($label === '') {
            continue;
        }
        $type = isset($c['type']) ? (string) $c['type'] : 'text';
        if (!in_array($type, $allowed, true)) {
            $type = 'text';
        }
        // Derive a unique, safe key from key/label.
        $key = isset($c['key']) ? trim((string) $c['key']) : '';
        $key = variant_schema_slug($key !== '' ? $key : $label);
        if ($key === '' || in_array($key, $reserved, true) || isset($seen[$key])) {
            $base = ($key === '' || in_array($key, $reserved, true)) ? 'col' : $key;
            $key = $base;
            $i = 2;
            while ($key === '' || isset($seen[$key]) || in_array($key, $reserved, true)) {
                $key = $base . $i;
                $i++;
            }
        }
        $seen[$key] = true;

        $col = ['key' => $key, 'label' => $label, 'type' => $type];

        // Optional placeholder / hint shown inside the input to guide the seller.
        $ph = isset($c['placeholder']) ? trim((string) $c['placeholder']) : '';
        if ($ph !== '') {
            $col['placeholder'] = mb_substr($ph, 0, 120);
        }
        // Optional "required" flag (front-end hint; storage keeps empty rows out anyway).
        if (!empty($c['required'])) {
            $col['required'] = 1;
        }

        if ($type === 'select') {
            $opts = [];
            $raw = $c['options'] ?? [];
            if (is_string($raw)) {
                $raw = preg_split('/[\r\n,]+/', $raw);
            }
            if (is_array($raw)) {
                foreach ($raw as $o) {
                    $o = trim((string) $o);
                    if ($o !== '' && !in_array($o, $opts, true)) {
                        $opts[] = $o;
                    }
                }
            }
            $col['options'] = $opts;
            $ac = $c['allow_custom'] ?? 1;
            $col['allow_custom'] = ($ac === 1 || $ac === '1' || $ac === true || $ac === 'on') ? 1 : 0;
        }

        if ($type === 'image') {
            // How many separate images the seller may upload for this column
            // (e.g. several photos per color). 1..10, default 1.
            $n = isset($c['images_count']) ? (int) $c['images_count'] : 1;
            $col['images_count'] = max(1, min(10, $n));
            // images are implicitly the image formats; allow narrowing too.
            $col['formats'] = variant_clean_formats($c['formats'] ?? ['jpg', 'png', 'webp', 'gif'], $formatsCat);
            if (empty($col['formats'])) {
                $col['formats'] = ['jpg', 'png', 'webp', 'gif'];
            }
        }

        if ($type === 'file') {
            // Generic file column. The admin picks one OR several allowed formats
            // (pdf, doc, zip, …) and how many files may be uploaded per variant.
            $col['formats'] = variant_clean_formats($c['formats'] ?? ['pdf'], $formatsCat);
            if (empty($col['formats'])) {
                $col['formats'] = ['pdf'];
            }
            $n = isset($c['files_count']) ? (int) $c['files_count'] : 1;
            $col['files_count'] = max(1, min(10, $n));
        }

        $out[] = $col;
    }
    return $out;
}

/** Keep only known format keys (from variant_file_formats()), de-duplicated. */
function variant_clean_formats($formats, $catalogue = null)
{
    if ($catalogue === null) {
        $catalogue = variant_file_formats();
    }
    if (is_string($formats)) {
        $formats = preg_split('/[\s,]+/', $formats);
    }
    if (!is_array($formats)) {
        return [];
    }
    $out = [];
    foreach ($formats as $f) {
        $f = strtolower(trim((string) $f));
        if ($f !== '' && isset($catalogue[$f]) && !in_array($f, $out, true)) {
            $out[] = $f;
        }
    }
    return $out;
}

/**
 * Take the seller's inline custom columns and append the universal
 * stock / price-diff columns (image columns are user-defined now, so they are
 * NOT auto-added). Mirrors the JS variantBuilderColumns() so PHP validation and
 * the rendered table agree on the column set.
 */
function variant_columns_with_builtins(array $customCols)
{
    // stock & price_diff may be seeded as removable default columns (so the
    // seller sees «موجودی»/«کد انبار» chips the instant تنوع is enabled). When a
    // builtin key is already present we keep it in place and skip re-appending,
    // so columns never duplicate. (Mirrors variantColumnsWithBuiltins() in JS.)
    $cols = [];
    $seen = [];
    foreach ($customCols as $c) {
        if (!isset($c['key']) || isset($seen[$c['key']])) {
            continue;
        }
        $seen[$c['key']] = true;
        $cols[] = $c;
    }
    if (!isset($seen['stock'])) {
        $cols[] = ['key' => 'stock',      'label' => 'موجودی',          'type' => 'number'];
    }
    if (!isset($seen['price_diff'])) {
        $cols[] = ['key' => 'price_diff', 'label' => 'اختلاف قیمت (+/−)', 'type' => 'number'];
    }
    return $cols;
}

// ===========================================================================
// Serial / license codes (product_type = 'serial_code')
// A pool of codes per product; each is delivered once on purchase.
// ===========================================================================

/**
 * List codes for a product, newest first. Optional status filter.
 */
function product_codes_list($product_id, $status = null, $limit = 0)
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    try {
        $sql = "SELECT * FROM product_codes WHERE product_id = ?";
        $params = [(int) $product_id];
        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY id DESC";
        if ($limit > 0) {
            $sql .= " LIMIT " . (int) $limit;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("product_codes_list error: " . $e->getMessage());
        return [];
    }
}

/**
 * Count codes for a product grouped by status.
 * Returns ['available' => int, 'sold' => int, 'total' => int].
 */
function product_codes_count($product_id)
{
    global $pdo;
    $out = ['available' => 0, 'sold' => 0, 'total' => 0];
    if (!isset($pdo)) {
        return $out;
    }
    try {
        $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM product_codes WHERE product_id = ? GROUP BY status");
        $stmt->execute([(int) $product_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = $r['status'];
            $c = (int) $r['c'];
            if (isset($out[$st])) {
                $out[$st] = $c;
            }
            $out['total'] += $c;
        }
    } catch (Exception $e) {
        error_log("product_codes_count error: " . $e->getMessage());
    }
    return $out;
}

/**
 * Bulk-add codes to a product (one code per line). De-duplicates against
 * existing codes of the same product. Returns ['added' => int, 'skipped' => int].
 */
function product_codes_add_bulk($product_id, $rawText)
{
    global $pdo;
    $res = ['added' => 0, 'skipped' => 0];
    if (!isset($pdo)) {
        return $res;
    }
    // Split on new lines, trim, drop empties, unique within the batch.
    $lines = preg_split('/\r\n|\r|\n/', (string) $rawText);
    $codes = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln !== '') {
            $codes[$ln] = true; // unique within batch
        }
    }
    if (empty($codes)) {
        return $res;
    }
    try {
        // Fetch existing codes for this product to skip duplicates.
        $stmt = $pdo->prepare("SELECT code FROM product_codes WHERE product_id = ?");
        $stmt->execute([(int) $product_id]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $existing[$c] = true;
        }

        $ins = $pdo->prepare(
            "INSERT INTO product_codes (product_id, code, status, created_at)
             VALUES (?, ?, 'available', NOW())"
        );
        foreach (array_keys($codes) as $code) {
            if (isset($existing[$code])) {
                $res['skipped']++;
                continue;
            }
            $ins->execute([(int) $product_id, $code]);
            $res['added']++;
        }
    } catch (Exception $e) {
        error_log("product_codes_add_bulk error: " . $e->getMessage());
    }
    return $res;
}

/**
 * Delete a single code row (only if still available, to keep sold history).
 */
function product_codes_delete($id, $force = false)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    try {
        if ($force) {
            $stmt = $pdo->prepare("DELETE FROM product_codes WHERE id = ?");
            return $stmt->execute([(int) $id]);
        }
        $stmt = $pdo->prepare("DELETE FROM product_codes WHERE id = ? AND status = 'available'");
        $stmt->execute([(int) $id]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("product_codes_delete error: " . $e->getMessage());
        return false;
    }
}

/**
 * Atomically claim & deliver one available code to a buyer.
 * Uses a transaction with row locking to avoid handing the same code twice.
 * Returns the delivered code string, or null if none available.
 */
function deliver_serial_code($product_id, $buyer_id, $order_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return null;
    }
    try {
        $pdo->beginTransaction();
        // Lock one available row so concurrent buyers can't grab the same code.
        $sel = $pdo->prepare(
            "SELECT id, code FROM product_codes
             WHERE product_id = ? AND status = 'available'
             ORDER BY id ASC LIMIT 1 FOR UPDATE"
        );
        $sel->execute([(int) $product_id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->rollBack();
            return null;
        }
        $upd = $pdo->prepare(
            "UPDATE product_codes
             SET status = 'sold', buyer_id = ?, order_id = ?, sold_at = NOW()
             WHERE id = ?"
        );
        $upd->execute([(string) $buyer_id, $order_id, (int) $row['id']]);
        $pdo->commit();
        return $row['code'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("deliver_serial_code error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get the editable store terminology map (customer/product/service words, etc.).
 * Stored as JSON in setting.store_terminology and editable from the web panel.
 * Returns an associative array of term => label.
 */
function get_store_terminology($bot_id = null)
{
    global $pdo;
    $defaults = [
        'customer'  => 'کاربر',
        'product'   => 'محصول',
        'service'   => 'سرویس',
        'order'     => 'سفارش',
        'wallet'    => 'کیف پول',
        'store'     => 'فروشگاه',
    ];

    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }

    try {
        if (isset($pdo)) {
            // Per-bot (child) store terminology lives in botsaz.setting JSON.
            if ($bot_id > 0) {
                $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
                $stmt->execute([$bot_id]);
                $brow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($brow && !empty($brow['setting'])) {
                    $bset = json_decode($brow['setting'], true);
                    if (is_array($bset) && !empty($bset['store_terminology']) && is_array($bset['store_terminology'])) {
                        return array_merge($defaults, $bset['store_terminology']);
                    }
                }
            }
            // Main bot / fallback: global setting table.
            $stmt = $pdo->query("SELECT store_terminology FROM setting LIMIT 1");
            $row  = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($row && !empty($row['store_terminology'])) {
                $decoded = json_decode($row['store_terminology'], true);
                if (is_array($decoded)) {
                    return array_merge($defaults, $decoded);
                }
            }
        }
    } catch (Exception $e) {
        // ignore, return defaults
    }
    return $defaults;
}

/**
 * Convenience: resolve a single terminology term.
 */
function store_term($term, $default = null)
{
    $map = get_store_terminology();
    if (isset($map[$term]) && $map[$term] !== '') {
        return $map[$term];
    }
    return $default !== null ? $default : $term;
}

/**
 * Registry of panel profiles (step 8e). A single install can run as several
 * independent containers (Coolify) or as several child bots on one install;
 * each carries its own profile that shapes the UX/terminology/default product
 * type. 'vpn' keeps the original, untouched VPN behaviour.
 *
 * Each profile: label, default product_type for new products, and a short
 * description shown in the panel.
 */
function panel_profiles()
{
    return [
        'vpn' => [
            'label'        => 'فروش VPN (پیش‌فرض)',
            'product_type' => 'vpn',
            'desc'         => 'رفتار اصلی ربات VPN — اکانت/کانفیگ، حجم، لوکیشن، اشتراک.',
        ],
        'shop' => [
            'label'        => 'آنلاین‌شاپ (کالای فیزیکی)',
            'product_type' => 'physical',
            'desc'         => 'فروش کالای فیزیکی با آدرس پستی، کد رهگیری و موجودی.',
        ],
        'digital' => [
            'label'        => 'فروش فایل/دیجیتال',
            'product_type' => 'digital_file',
            'desc'         => 'فروش فایل، کد/سریال لایسنس و محصولات دانلودی.',
        ],
        'channel' => [
            'label'        => 'کانال/اشتراک (زیرنویس، محتوا)',
            'product_type' => 'service',
            'desc'         => 'فروش اشتراک کانال یا دسترسی به محتوا/خدمت.',
        ],
        'custom' => [
            'label'        => 'سفارشی (محیط خالی)',
            'product_type' => 'service',
            'desc'         => 'محیط بدون پیش‌فرض؛ خودتان با ویرایش پنل و دکمه‌ها می‌سازید.',
        ],
    ];
}

/** True if a profile code is known. */
function is_valid_panel_mode($mode)
{
    return array_key_exists((string) $mode, panel_profiles());
}

/**
 * Resolve the active panel profile for this install/bot, in priority order:
 *   1. ENV  PANEL_MODE        (single-container / Coolify scenario)
 *   2. botsaz.setting.panel_mode  (per-child-bot, multi-bot scenario)
 *   3. setting.store_mode     (global, legacy)
 *   4. 'vpn'                  (default — keeps VPN behaviour untouched)
 */
function panel_mode()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    global $pdo, $setting;

    // 1) Environment variable (best for Coolify per-container deploys).
    $env = getenv('PANEL_MODE');
    if ($env !== false && is_valid_panel_mode($env)) {
        return $cached = $env;
    }

    // 2) Per-bot profile in botsaz.setting JSON (child bots).
    $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    if ($bot_id > 0 && isset($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([$bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($brow && !empty($brow['setting'])) {
                $bset = json_decode($brow['setting'], true);
                if (is_array($bset) && !empty($bset['panel_mode']) && is_valid_panel_mode($bset['panel_mode'])) {
                    return $cached = $bset['panel_mode'];
                }
            }
        } catch (Exception $e) {
            // ignore, fall through
        }
    }

    // 3) Global setting (legacy store_mode), then 4) default.
    if (is_array($setting) && !empty($setting['store_mode']) && is_valid_panel_mode($setting['store_mode'])) {
        return $cached = $setting['store_mode'];
    }
    $row = select("setting", "store_mode", null, null, "FETCH_COLUMN");
    if (is_array($row)) {
        $row = $row[0] ?? null;
    }
    return $cached = (is_valid_panel_mode($row) ? $row : 'vpn');
}

/**
 * Persist the panel profile. For a child bot (bot_id > 0) it is written into
 * botsaz.setting JSON; otherwise into the global setting.store_mode column.
 * Returns true on success.
 */
function set_panel_mode($mode, $bot_id = null)
{
    global $pdo;
    if (!is_valid_panel_mode($mode) || !isset($pdo)) {
        return false;
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    try {
        if ((int) $bot_id > 0) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            $bset = ($brow && !empty($brow['setting'])) ? json_decode($brow['setting'], true) : [];
            if (!is_array($bset)) {
                $bset = [];
            }
            $bset['panel_mode'] = $mode;
            $pdo->prepare("UPDATE botsaz SET setting = ? WHERE id = ?")
                ->execute([json_encode($bset, JSON_UNESCAPED_UNICODE), (int) $bot_id]);
            return true;
        }
        // Global: keep store_mode (legacy column) as the source of truth.
        $pdo->prepare("UPDATE setting SET store_mode = ?")->execute([$mode]);
        return true;
    } catch (Exception $e) {
        error_log("set_panel_mode error: " . $e->getMessage());
        return false;
    }
}

/**
 * Current store mode (back-compat alias). Now delegates to panel_mode() so the
 * whole codebase resolves the profile consistently (ENV > per-bot > global).
 */
function store_mode()
{
    return panel_mode();
}

/** Default product_type for the active profile (used by new-product UI). */
function panel_default_product_type()
{
    $profiles = panel_profiles();
    $mode = panel_mode();
    return $profiles[$mode]['product_type'] ?? 'vpn';
}

/** True when the active profile is anything other than plain VPN. */
function panel_is_shop()
{
    return panel_mode() !== 'vpn';
}

/**
 * The plain-VPN default main keyboard layout (template keys, not resolved text).
 * This is the canonical "factory" layout shipped historically.
 */
function default_vpn_keyboard_json()
{
    return '{"keyboard":[[{"text":"text_sell"},{"text":"text_extend"}],[{"text":"text_usertest"},{"text":"text_wheel_luck"}],[{"text":"text_Purchased_services"},{"text":"accountwallet"}],[{"text":"text_affiliates"},{"text":"text_Tariff_list"}],[{"text":"text_support"},{"text":"text_help"}]]}';
}

/**
 * Default main keyboard JSON for the active panel mode.
 *
 * For shop / digital / channel / custom profiles the VPN-only buttons
 * (free test account, luck wheel, "extend service") make no sense, so they are
 * dropped and a leaner, store-friendly layout is returned. The remaining keys
 * reuse the SAME callback handlers (buy, account, backorder, support, help,
 * affiliates, tariff) so nothing in the bot breaks — only VPN-specific buttons
 * disappear. Plain VPN mode is returned untouched.
 *
 * @param string|null $mode  Force a mode; defaults to panel_mode().
 */
function default_main_keyboard_json($mode = null)
{
    $mode = $mode ?: (function_exists('panel_mode') ? panel_mode() : 'vpn');

    if ($mode === 'vpn') {
        return default_vpn_keyboard_json();
    }

    // Store-style layout: keep buy / my-orders / wallet / tariff / affiliates /
    // support / help. Drop usertest, wheel_luck and extend.
    $layout = [
        'keyboard' => [
            [['text' => 'text_sell'], ['text' => 'text_Purchased_services']],
            [['text' => 'accountwallet'], ['text' => 'text_Tariff_list']],
            [['text' => 'text_affiliates'], ['text' => 'text_support']],
            [['text' => 'text_help']],
        ],
    ];
    return json_encode($layout, JSON_UNESCAPED_UNICODE);
}

/**
 * Template keys that only make sense for a VPN panel. In any non-VPN profile
 * (shop / digital / …) these buttons are hidden from the bot's main menu.
 *   text_usertest   → free trial VPN account
 *   text_wheel_luck → luck wheel (VPN giveaways)
 *   text_extend     → extend an existing VPN service
 */
function vpn_only_keyboard_keys()
{
    return ['text_usertest', 'text_wheel_luck', 'text_extend'];
}

/**
 * Remove VPN-only buttons from a keyboard "rows" array (the value of the
 * "keyboard" key). Used at menu-build time so a shop/digital bot never shows
 * VPN buttons even if the admin dragged them into a custom layout. Empty rows
 * left behind are dropped. The stored layout in the DB is NOT modified.
 *
 * @param array $rows  e.g. [[['text'=>'text_sell'],['text'=>'text_usertest']], ...]
 * @return array       filtered rows
 */
function strip_vpn_only_buttons(array $rows)
{
    $drop = array_flip(vpn_only_keyboard_keys());
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $kept = [];
        foreach ($row as $btn) {
            $txt = is_array($btn) ? ($btn['text'] ?? '') : '';
            if (isset($drop[$txt])) {
                continue;
            }
            $kept[] = $btn;
        }
        if (!empty($kept)) {
            $out[] = array_values($kept);
        }
    }
    return $out;
}

/**
 * True when the stored keyboardmain still equals one of the factory defaults
 * (VPN or any store layout). Used to decide whether it's safe to auto-swap the
 * layout when the panel mode changes — we never overwrite a layout the admin
 * has customised themselves.
 */
function keyboard_is_factory_default($stored)
{
    $stored = trim((string) $stored);
    if ($stored === '') {
        return true;
    }
    $norm = function ($j) {
        $d = json_decode((string) $j, true);
        return is_array($d) ? json_encode($d) : null;
    };
    $cur = $norm($stored);
    if ($cur === null) {
        return true; // unparseable → treat as resettable
    }
    foreach (['vpn', 'shop'] as $m) {
        if ($cur === $norm(default_main_keyboard_json($m))) {
            return true;
        }
    }
    return false;
}

/**
 * Ready-made setup presets (step 9). Each preset bundles a profile with sane
 * default terminology and currency so a fresh install can be configured in one
 * click via the setup wizard (panel/wizard.php). Presets never overwrite the
 * VPN flow logic — they only seed display terms, currency and the panel mode.
 */
function panel_presets()
{
    return [
        'vpn' => [
            'label'    => 'فروش VPN',
            'mode'     => 'vpn',
            'currency' => 'تومان',
            'terms'    => [
                'customer' => 'کاربر', 'product' => 'سرویس', 'service' => 'سرویس',
                'order'    => 'سفارش', 'wallet'  => 'کیف پول', 'store' => 'فروشگاه',
            ],
        ],
        'shop' => [
            'label'    => 'آنلاین‌شاپ (کالای فیزیکی)',
            'mode'     => 'shop',
            'currency' => 'تومان',
            'terms'    => [
                'customer' => 'مشتری', 'product' => 'محصول', 'service' => 'کالا',
                'order'    => 'سفارش', 'wallet'  => 'کیف پول', 'store' => 'فروشگاه',
            ],
        ],
        'digital' => [
            'label'    => 'فروش فایل/دیجیتال',
            'mode'     => 'digital',
            'currency' => 'تومان',
            'terms'    => [
                'customer' => 'کاربر', 'product' => 'فایل', 'service' => 'محصول',
                'order'    => 'خرید', 'wallet'  => 'کیف پول', 'store' => 'فروشگاه',
            ],
        ],
        'channel' => [
            'label'    => 'کانال/اشتراک',
            'mode'     => 'channel',
            'currency' => 'تومان',
            'terms'    => [
                'customer' => 'عضو', 'product' => 'اشتراک', 'service' => 'اشتراک',
                'order'    => 'اشتراک', 'wallet'  => 'کیف پول', 'store' => 'کانال',
            ],
        ],
        'custom' => [
            'label'    => 'سفارشی (محیط خالی)',
            'mode'     => 'custom',
            'currency' => 'تومان',
            'terms'    => [
                'customer' => 'کاربر', 'product' => 'آیتم', 'service' => 'آیتم',
                'order'    => 'سفارش', 'wallet'  => 'کیف پول', 'store' => 'فروشگاه',
            ],
        ],
    ];
}

/**
 * Apply a setup preset in one shot: sets the panel mode, store terminology and
 * currency. Scope: global (bot_id 0) by default, or a child bot. Returns true
 * on success. Safe & idempotent — re-running just re-seeds the same values.
 */
function apply_panel_preset($preset_key, $bot_id = 0, $overrides = [])
{
    global $pdo;
    $presets = panel_presets();
    if (!isset($presets[$preset_key]) || !isset($pdo)) {
        return false;
    }
    $preset   = $presets[$preset_key];
    $mode     = $overrides['mode']     ?? $preset['mode'];
    $currency = $overrides['currency'] ?? $preset['currency'];
    $terms    = is_array($overrides['terms'] ?? null) ? $overrides['terms'] : $preset['terms'];

    set_panel_mode($mode, $bot_id);
    set_store_terminology($terms, $bot_id);

    // Currency: global column, or per-bot setting JSON.
    try {
        if ((int) $bot_id > 0) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            $bset = ($brow && !empty($brow['setting'])) ? json_decode($brow['setting'], true) : [];
            if (!is_array($bset)) {
                $bset = [];
            }
            $bset['store_currency'] = $currency;
            $pdo->prepare("UPDATE botsaz SET setting = ? WHERE id = ?")
                ->execute([json_encode($bset, JSON_UNESCAPED_UNICODE), (int) $bot_id]);
        } else {
            $pdo->prepare("UPDATE setting SET store_currency = ?")->execute([$currency]);
        }
    } catch (Exception $e) {
        error_log("apply_panel_preset currency error: " . $e->getMessage());
    }
    return true;
}

/**
 * Store currency label (e.g. "تومان"). Read from setting.store_currency.
 */
function store_currency()
{
    global $setting;
    if (is_array($setting) && !empty($setting['store_currency'])) {
        return $setting['store_currency'];
    }
    $row = select("setting", "store_currency", null, null, "FETCH_COLUMN");
    if (is_array($row)) {
        $row = $row[0] ?? null;
    }
    return $row ?: 'تومان';
}

/**
 * Fetch a single non-VPN shop product by id (scoped to current bot when set).
 * Returns the product row or null.
 */
function shop_product($id, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return null;
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM product
             WHERE id = ? AND product_type <> 'vpn' AND (bot_id = ? OR bot_id = 0)
             LIMIT 1"
        );
        $stmt->execute([(int) $id, (int) $bot_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        error_log("shop_product error: " . $e->getMessage());
        return null;
    }
}

/**
 * List non-VPN shop products for the current bot (for the in-bot store list).
 */
function shop_product_list($bot_id = null, $limit = 100)
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM product
             WHERE product_type <> 'vpn' AND (bot_id = ? OR bot_id = 0)
             ORDER BY id DESC LIMIT " . (int) $limit
        );
        $stmt->execute([(int) $bot_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("shop_product_list error: " . $e->getMessage());
        return [];
    }
}

/**
 * Whether a shop product can currently be purchased (basic availability).
 * For serial_code: needs at least one available code. Others: always true
 * here (full inventory comes in step 8c).
 */
function shop_product_available($product)
{
    if (!is_array($product)) {
        return false;
    }
    if (($product['product_type'] ?? '') === 'serial_code') {
        $c = product_codes_count((int) $product['id']);
        return $c['available'] > 0;
    }
    // Physical products with tracked stock are unavailable when stock hits 0.
    if (function_exists('product_tracks_stock') && product_tracks_stock($product)) {
        $stock = product_stock($product);
        if ($stock !== null && $stock <= 0) {
            return false;
        }
    }
    return true;
}

/**
 * Render the shop product list (non-VPN products) as an inline keyboard of
 * product buttons that open shopview_{id}.
 */
function shop_render_list($from_id)
{
    $cur      = store_currency();
    $products = shop_product_list();
    if (empty($products)) {
        sendmessage($from_id, "در حال حاضر محصولی برای فروش موجود نیست.", null, 'html');
        return;
    }
    $kb = ['inline_keyboard' => []];
    foreach ($products as $p) {
        $price = (int) preg_replace('/[^\d]/', '', (string) ($p['price_product'] ?? '0'));
        $label = $p['name_product'] . ' — ' . number_format($price) . ' ' . $cur;
        if (!shop_product_available($p)) {
            $label = '⛔️ ' . $label;
        }
        $kb['inline_keyboard'][] = [[
            'text' => $label,
            'callback_data' => 'shopview_' . (int) $p['id'],
        ]];
    }
    $kb['inline_keyboard'][] = [['text' => '🔍 جستجوی محصول', 'callback_data' => 'shopsearch']];
    $cartN = shop_cart_count($from_id);
    $cartLabel = $cartN > 0 ? "🛒 سبد خرید ({$cartN})" : "🛒 سبد خرید";
    $kb['inline_keyboard'][] = [['text' => $cartLabel, 'callback_data' => 'shopcart']];
    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'backuser']];
    sendmessage($from_id, "🛍 <b>" . store_term('store', 'فروشگاه') . "</b>\nیک محصول را انتخاب کنید:", json_encode($kb), 'html');
}

/**
 * Search shop products (non-VPN) by name / note / category / SKU (attributes).
 * Returns matching product rows scoped to the current bot.
 */
function shop_search_products($query, $bot_id = null, $limit = 30)
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    $query = trim((string) $query);
    if ($query === '') {
        return [];
    }
    $like = '%' . $query . '%';
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM product
             WHERE product_type <> 'vpn' AND (bot_id = ? OR bot_id = 0)
               AND (name_product LIKE ? OR note LIKE ? OR category LIKE ? OR attributes LIKE ?)
             ORDER BY id DESC LIMIT " . (int) $limit
        );
        $stmt->execute([(int) $bot_id, $like, $like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("shop_search_products error: " . $e->getMessage());
        return [];
    }
}

/**
 * Render search results as a product keyboard (same shape as the list).
 */
function shop_render_search_results($from_id, $query)
{
    $cur     = store_currency();
    $results = shop_search_products($query);
    if (empty($results)) {
        sendmessage(
            $from_id,
            "نتیجه‌ای برای «" . htmlspecialchars((string) $query) . "» پیدا نشد.",
            json_encode(['inline_keyboard' => [
                [['text' => '🔍 جستجوی دوباره', 'callback_data' => 'shopsearch']],
                [['text' => '🛍 همهٔ محصولات', 'callback_data' => 'shoplist']],
                [['text' => '🔙 بازگشت', 'callback_data' => 'backuser']],
            ]]),
            'html'
        );
        return;
    }
    $kb = ['inline_keyboard' => []];
    foreach ($results as $p) {
        $price = (int) preg_replace('/[^\d]/', '', (string) ($p['price_product'] ?? '0'));
        $label = $p['name_product'] . ' — ' . number_format($price) . ' ' . $cur;
        if (!shop_product_available($p)) {
            $label = '⛔️ ' . $label;
        }
        $kb['inline_keyboard'][] = [['text' => $label, 'callback_data' => 'shopview_' . (int) $p['id']]];
    }
    $kb['inline_keyboard'][] = [['text' => '🔍 جستجوی دوباره', 'callback_data' => 'shopsearch']];
    $kb['inline_keyboard'][] = [['text' => '🔙 بازگشت', 'callback_data' => 'backuser']];
    sendmessage(
        $from_id,
        "🔍 نتایج جستجو برای «<b>" . htmlspecialchars((string) $query) . "</b>» (" . count($results) . "):",
        json_encode($kb),
        'html'
    );
}

/**
 * Handle the "type your search query" text step in the shop flow.
 * Returns true if handled.
 */
function shop_handle_search_step($step, $text, $from_id, $user)
{
    if ((string) $step !== 'shop_search') {
        return false;
    }
    step('home', $from_id);
    shop_render_search_results($from_id, trim((string) $text));
    return true;
}

/**
 * Build the exact message a customer would see for a shop product:
 *   - media  : array of [type, ref, url] for the first few attachments
 *   - caption: the HTML caption (name, note, price, stock badge)
 *   - keyboard: inline_keyboard rows (Buy / cart / coupon / back)
 *
 * This is the single source of truth shared by shop_render_product() (which
 * actually sends it to Telegram) and the admin "نمایش/Preview" panel
 * (product_preview.php) so the demo always matches the real bot output.
 */
function shop_product_view_data($product)
{
    global $domainhosts;
    $cur   = store_currency();
    $price = (int) preg_replace('/[^\d]/', '', (string) ($product['price_product'] ?? '0'));
    $avail = shop_product_available($product);

    // Resolve the first few media (image/video/audio) with a usable reference.
    $media = [];
    foreach (array_slice(product_media_list((int) $product['id']), 0, 5) as $m) {
        $url = (!empty($domainhosts))
            ? rtrim((strpos($domainhosts, 'http') === 0 ? $domainhosts : 'https://' . $domainhosts), '/') . '/' . ltrim($m['file_path'], '/')
            : ltrim((string) $m['file_path'], '/');
        $ref = !empty($m['telegram_file_id']) ? $m['telegram_file_id'] : $url;
        if (!$ref) {
            continue;
        }
        $media[] = [
            'type'      => $m['media_type'] ?? 'image',
            'ref'       => $ref,
            'url'       => $url, // public URL (bot uses file_id; this is the fallback)
            'file_path' => ltrim((string) $m['file_path'], '/'), // raw stored path (panel preview serves it relatively)
        ];
    }

    // Also include per-variant images stored in the product attributes (these
    // are uploaded from the «ویژگی‌ها/تنوع» builder, not the product_media table).
    // This is why a variant-only product still shows its photo in the bot/preview.
    if (count($media) < 5 && function_exists('product_variant_images')) {
        foreach (product_variant_images($product, 5 - count($media)) as $vm) {
            $media[] = $vm;
        }
    }

    $lines = [];
    $lines[] = "🛍 <b>" . htmlspecialchars($product['name_product'] ?? '') . "</b>";
    if (!empty($product['note'])) {
        $lines[] = "\n" . htmlspecialchars($product['note']);
    }
    $lines[] = "\n💰 " . number_format($price) . " " . $cur;
    // Show remaining stock when tracked (helps create urgency / clarity).
    if (function_exists('product_tracks_stock') && product_tracks_stock($product)) {
        $stock = product_stock($product);
        if ($stock !== null) {
            if ($stock <= 0) {
                $lines[] = "⛔️ ناموجود";
            } elseif ($stock <= 5) {
                $lines[] = "⚠️ تنها <b>" . number_format($stock) . "</b> عدد باقی مانده";
            } else {
                $lines[] = "✅ موجود (" . number_format($stock) . " عدد)";
            }
        }
    }
    if (!$avail) {
        $lines[] = "\n⛔️ ناموجود";
    }
    $caption = implode("\n", $lines);

    $kb = ['inline_keyboard' => []];
    if ($avail) {
        $kb['inline_keyboard'][] = [[
            'text' => "🛒 خرید فوری",
            'callback_data' => "shopbuy_" . (int) $product['id'],
        ]];
        $kb['inline_keyboard'][] = [[
            'text' => "➕ افزودن به سبد",
            'callback_data' => "cartadd_" . (int) $product['id'],
        ]];
        $kb['inline_keyboard'][] = [[
            'text' => "🏷 کد تخفیف",
            'callback_data' => "shopcoupon_" . (int) $product['id'],
        ]];
    }
    $kb['inline_keyboard'][] = [
        ['text' => "🛒 سبد خرید", 'callback_data' => "shopcart"],
        ['text' => "🔙 بازگشت", 'callback_data' => "backuser"],
    ];

    return [
        'media'    => $media,
        'caption'  => $caption,
        'keyboard' => $kb['inline_keyboard'],
        'available' => $avail,
        'price'    => $price,
        'currency' => $cur,
    ];
}

/**
 * Render a shop product to the user: media (image/video/audio) + caption with
 * name, description and price, plus a Buy / Back inline keyboard.
 */
function shop_render_product($from_id, $product)
{
    $view = shop_product_view_data($product);

    // Send any attached media first (best-effort, non-blocking).
    foreach ($view['media'] as $m) {
        if ($m['type'] === 'image') {
            sendphoto($from_id, $m['ref'], '');
        } elseif ($m['type'] === 'video') {
            sendvideo($from_id, $m['ref'], '');
        } elseif ($m['type'] === 'audio') {
            telegram('sendAudio', ['chat_id' => $from_id, 'audio' => $m['ref'], 'caption' => '']);
        }
    }

    sendmessage($from_id, $view['caption'], json_encode(['inline_keyboard' => $view['keyboard']]), 'html');
}

/**
 * Deliver a non-VPN product to the buyer after a successful purchase, based on
 * product_type. Returns a short human-readable status string for logging.
 *   - digital_file : sends the configured Telegram file
 *   - serial_code  : claims & delivers one available code (atomic)
 *   - service      : sends the delivery note
 *   - physical     : placeholder until address/shipping flow (step 8c)
 */
function shop_deliver_product($from_id, $product, $order_id = null)
{
    $type = $product['product_type'] ?? '';
    switch ($type) {
        case 'digital_file':
            $fileId  = product_attr($product, 'file_id');
            $ftype   = product_attr($product, 'file_type', 'document');
            $caption = (string) product_attr($product, 'caption', '');
            if (empty($fileId)) {
                sendmessage($from_id, "فایل این محصول هنوز تنظیم نشده است. لطفاً با پشتیبانی تماس بگیرید.", null, 'html');
                return 'digital_file:missing';
            }
            if ($ftype === 'photo') {
                sendphoto($from_id, $fileId, $caption);
            } elseif ($ftype === 'video') {
                sendvideo($from_id, $fileId, $caption);
            } elseif ($ftype === 'audio') {
                telegram('sendAudio', ['chat_id' => $from_id, 'audio' => $fileId, 'caption' => $caption]);
            } else {
                telegram('sendDocument', ['chat_id' => $from_id, 'document' => $fileId, 'caption' => $caption]);
            }
            return 'digital_file:sent';

        case 'serial_code':
            $code = deliver_serial_code((int) $product['id'], (string) $from_id, $order_id);
            if ($code === null) {
                sendmessage($from_id, "متأسفانه کد آزادی موجود نیست. لطفاً با پشتیبانی تماس بگیرید.", null, 'html');
                return 'serial_code:none';
            }
            $fmt = (string) product_attr($product, 'code_format', '');
            $msg = $fmt !== ''
                ? str_replace('{code}', $code, $fmt)
                : "✅ کد شما:\n<code>" . htmlspecialchars($code) . "</code>";
            sendmessage($from_id, $msg, null, 'html');
            return 'serial_code:delivered';

        case 'service':
            $note = (string) product_attr($product, 'delivery_note', '');
            $msg  = $note !== '' ? $note : "✅ خرید شما ثبت شد. به‌زودی پیگیری می‌شود.";
            sendmessage($from_id, $msg, null, 'html');
            return 'service:noted';

        case 'physical':
            // Decrement tracked stock (no-op when stock is unlimited).
            if (function_exists('product_tracks_stock') && product_tracks_stock($product)) {
                product_decrement_stock((int) $product['id'], 1);
            }
            // Confirm the order; address is collected in the checkout flow and
            // tracking/shipping is handled later by the admin (step 8b panel).
            sendmessage($from_id, "✅ سفارش شما ثبت شد. برای هماهنگی ارسال با شما تماس گرفته می‌شود.", null, 'html');
            return 'physical:recorded';
    }
    return 'unknown';
}

/**
 * Record a shop order in Payment_report (reusing the existing order table).
 * Returns the generated order id.
 */
// ===========================================================================
// Multi-item shopping cart (step: سبد چندقلمی + تعداد). The cart lives in the
// dedicated user.shop_cart JSON column — completely isolated from the VPN flow
// (which uses Processing_value). Shape:
//   [ { "id": <product_id>, "qty": <int>, "variant": <string|null> }, ... ]
// ===========================================================================

/**
 * Read the current user's cart as a normalised array of line items.
 */
function shop_cart_get($from_id)
{
    $row = select("user", "shop_cart", "id", $from_id, "select");
    $raw = '';
    if (is_array($row)) {
        $raw = (string) ($row['shop_cart'] ?? '');
    } elseif (is_string($row)) {
        $raw = $row;
    }
    if ($raw === '' || $raw === 'none' || $raw === '0') {
        return [];
    }
    $items = json_decode($raw, true);
    if (!is_array($items)) {
        return [];
    }
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it) || empty($it['id'])) {
            continue;
        }
        $qty = (int) ($it['qty'] ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }
        $out[] = [
            'id'      => (int) $it['id'],
            'qty'     => $qty,
            'variant' => isset($it['variant']) && $it['variant'] !== '' ? (string) $it['variant'] : null,
        ];
    }
    return $out;
}

/**
 * Persist a cart array back to the user row.
 */
function shop_cart_save($from_id, array $items)
{
    update("user", "shop_cart", json_encode(array_values($items), JSON_UNESCAPED_UNICODE), "id", $from_id);
}

/**
 * Empty the cart.
 */
function shop_cart_clear($from_id)
{
    update("user", "shop_cart", "", "id", $from_id);
}

/**
 * Add a product (optionally a variant) to the cart, or bump its quantity if it
 * is already present with the same variant. Returns the new quantity for that
 * line.
 */
function shop_cart_add($from_id, $product_id, $variant = null, $qty = 1)
{
    $product_id = (int) $product_id;
    $variant    = ($variant !== null && $variant !== '') ? (string) $variant : null;
    $qty        = max(1, (int) $qty);
    $items      = shop_cart_get($from_id);
    $found      = false;
    $newQty     = $qty;
    foreach ($items as &$it) {
        if ($it['id'] === $product_id && $it['variant'] === $variant) {
            $it['qty'] += $qty;
            $newQty     = $it['qty'];
            $found      = true;
            break;
        }
    }
    unset($it);
    if (!$found) {
        $items[] = ['id' => $product_id, 'qty' => $qty, 'variant' => $variant];
    }
    shop_cart_save($from_id, $items);
    return $newQty;
}

/**
 * Set the quantity of the Nth cart line (0-based). A qty of 0 (or less) removes
 * the line. Returns the resulting cart array.
 */
function shop_cart_set_qty($from_id, $index, $qty)
{
    $items = shop_cart_get($from_id);
    $index = (int) $index;
    if (!isset($items[$index])) {
        return $items;
    }
    $qty = (int) $qty;
    if ($qty < 1) {
        array_splice($items, $index, 1);
    } else {
        $items[$index]['qty'] = $qty;
    }
    shop_cart_save($from_id, $items);
    return $items;
}

/**
 * Remove the Nth cart line (0-based). Returns the resulting cart array.
 */
function shop_cart_remove($from_id, $index)
{
    return shop_cart_set_qty($from_id, $index, 0);
}

/**
 * Total item count (sum of quantities) in the cart.
 */
function shop_cart_count($from_id)
{
    $n = 0;
    foreach (shop_cart_get($from_id) as $it) {
        $n += (int) $it['qty'];
    }
    return $n;
}

/**
 * Unit price for a product, including a variant's price_diff when applicable.
 */
/**
 * Stable identifier for one product variant row. Prefers the "پس‌کد"
 * (variant_code), then legacy SKU, then a color/size combination. Used as the
 * key when matching a customer's chosen variant against the stored rows.
 */
function product_variant_key($v)
{
    if (!is_array($v)) {
        return '';
    }
    $code = trim((string) ($v['variant_code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $sku = trim((string) ($v['sku'] ?? ''));
    if ($sku !== '') {
        return $sku;
    }
    return trim((string) ($v['color'] ?? '') . ' ' . (string) ($v['size'] ?? ''));
}

/** Human-readable label for a variant row (for buttons / order summaries). */
function product_variant_label($v)
{
    if (!is_array($v)) {
        return '';
    }
    $parts = [];
    foreach (['color', 'size'] as $k) {
        $val = trim((string) ($v[$k] ?? ''));
        if ($val !== '') {
            $parts[] = $val;
        }
    }
    $label = implode(' / ', $parts);
    $code = trim((string) ($v['variant_code'] ?? ''));
    if ($label === '') {
        $label = $code !== '' ? $code : product_variant_key($v);
    } elseif ($code !== '') {
        $label .= ' (' . $code . ')';
    }
    return $label;
}

function shop_line_unit_price($product, $variant = null)
{
    $base = (int) preg_replace('/[^\d]/', '', (string) ($product['price_product'] ?? '0'));
    if ($variant !== null && $variant !== '') {
        $variants = product_attr($product, 'variants', null);
        if (is_array($variants)) {
            foreach ($variants as $v) {
                if (product_variant_key($v) === (string) $variant) {
                    $base += (int) preg_replace('/[^\-\d]/', '', (string) ($v['price_diff'] ?? '0'));
                    break;
                }
            }
        }
    }
    return max(0, $base);
}

/**
 * Resolve the cart into detailed line items + grand total. Returns:
 *   ['lines' => [ ['product'=>..,'qty'=>..,'variant'=>..,'unit'=>..,'subtotal'=>..], .. ],
 *    'total' => <int>, 'count' => <int>, 'missing' => <int removed unavailable lines>]
 * Lines whose product no longer exists are silently dropped (cart self-heals).
 */
function shop_cart_resolve($from_id)
{
    $items   = shop_cart_get($from_id);
    $lines   = [];
    $total   = 0;
    $count   = 0;
    $changed = false;
    foreach ($items as $it) {
        $product = shop_product((int) $it['id']);
        if (!$product) {
            $changed = true; // drop dangling line
            continue;
        }
        $unit     = shop_line_unit_price($product, $it['variant']);
        $subtotal = $unit * (int) $it['qty'];
        $total   += $subtotal;
        $count   += (int) $it['qty'];
        $lines[]  = [
            'product'  => $product,
            'qty'      => (int) $it['qty'],
            'variant'  => $it['variant'],
            'unit'     => $unit,
            'subtotal' => $subtotal,
        ];
    }
    if ($changed) {
        // Re-save the healed cart (only surviving lines).
        $clean = [];
        foreach ($lines as $l) {
            $clean[] = ['id' => (int) $l['product']['id'], 'qty' => $l['qty'], 'variant' => $l['variant']];
        }
        shop_cart_save($from_id, $clean);
    }
    return ['lines' => $lines, 'total' => $total, 'count' => $count];
}

function shop_record_order($from_id, $product, $price, $status = 'paid', $address_id = null)
{
    global $connect;
    $orderId = 'SHOP-' . time() . '-' . (int) $product['id'];
    try {
        $stmt = $connect->prepare(
            "INSERT INTO Payment_report
             (id_user, id_order, time, price, payment_Status, Payment_Method, product_id, order_status, address_id)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            (string) $from_id,
            $orderId,
            $now,
            (string) $price,
            'success',
            'wallet',
            (int) $product['id'],
            $status,
            $address_id !== null ? (int) $address_id : null,
        ]);
    } catch (Exception $e) {
        error_log("shop_record_order error: " . $e->getMessage());
    }
    return $orderId;
}

// ===========================================================================
// Discounts / coupons (step 8) — built on the existing DiscountSell table.
// ===========================================================================

/**
 * Count how many times a user has already used a coupon code (shop path).
 */
function discount_user_uses($code, $user_id)
{
    global $pdo;
    if (!isset($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM discount_usage WHERE code = ? AND user_id = ?");
        $stmt->execute([(string) $code, (string) $user_id]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("discount_user_uses error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Validate a shop coupon code against a cart amount and user, and compute the
 * discount. Returns:
 *   ['ok' => true,  'amount' => int, 'final' => int, 'row' => array]
 *   ['ok' => false, 'error'  => string]   (human-readable Persian reason)
 *
 * Reuses DiscountSell semantics:
 *   price          = discount value (percent OR fixed, per discount_kind)
 *   discount_kind  = 'percent' (default/legacy) | 'fixed'
 *   max_amount     = cap for percent discounts (0 = no cap)
 *   min_order      = minimum cart amount required
 *   limitDiscount / usedDiscount = global usage cap
 *   useuser        = per-user cap (counted in discount_usage)
 *   time           = expiry unix ts (0 = never); start_at = optional start ts
 *   enabled        = '1' to be usable
 */
function validate_discount_code($code, $user_id, $cart_amount, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return ['ok' => false, 'error' => 'خطای داخلی.'];
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    $code = trim((string) $code);
    if ($code === '') {
        return ['ok' => false, 'error' => 'کد تخفیف خالی است.'];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM DiscountSell
             WHERE codeDiscount = ? AND (bot_id = ? OR bot_id = 0)
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$code, (int) $bot_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("validate_discount_code error: " . $e->getMessage());
        return ['ok' => false, 'error' => 'خطای داخلی.'];
    }

    if (!$row) {
        return ['ok' => false, 'error' => 'کد تخفیف نامعتبر است.'];
    }
    if (isset($row['enabled']) && $row['enabled'] !== null && (string) $row['enabled'] === '0') {
        return ['ok' => false, 'error' => 'این کد تخفیف غیرفعال است.'];
    }
    $now = time();
    if (!empty($row['start_at']) && $now < (int) $row['start_at']) {
        return ['ok' => false, 'error' => 'این کد هنوز فعال نشده است.'];
    }
    if (!empty($row['time']) && (int) $row['time'] !== 0 && $now >= (int) $row['time']) {
        return ['ok' => false, 'error' => 'این کد تخفیف منقضی شده است.'];
    }
    if ((int) ($row['limitDiscount'] ?? 0) !== 0
        && (int) ($row['usedDiscount'] ?? 0) >= (int) $row['limitDiscount']) {
        return ['ok' => false, 'error' => 'ظرفیت استفاده از این کد به پایان رسیده است.'];
    }
    $perUser = (int) ($row['useuser'] ?? 0);
    if ($perUser > 0 && discount_user_uses($code, $user_id) >= $perUser) {
        return ['ok' => false, 'error' => 'سقف استفادهٔ شما از این کد پر شده است.'];
    }
    $minOrder = (int) ($row['min_order'] ?? 0);
    if ($minOrder > 0 && (int) $cart_amount < $minOrder) {
        return ['ok' => false, 'error' => 'حداقل مبلغ سفارش برای این کد ' . number_format($minOrder) . ' است.'];
    }

    // Compute discount.
    $kind  = $row['discount_kind'] ?? 'percent';
    $value = (float) ($row['price'] ?? 0);
    if ($kind === 'fixed') {
        $amount = (int) round($value);
    } else { // percent (default / legacy)
        $amount = (int) round(($value / 100) * (int) $cart_amount);
        $cap = (int) ($row['max_amount'] ?? 0);
        if ($cap > 0 && $amount > $cap) {
            $amount = $cap;
        }
    }
    if ($amount < 0) {
        $amount = 0;
    }
    if ($amount > (int) $cart_amount) {
        $amount = (int) $cart_amount; // never below zero final
    }
    $final = (int) $cart_amount - $amount;

    return ['ok' => true, 'amount' => $amount, 'final' => $final, 'row' => $row];
}

/**
 * Record a coupon use: increments DiscountSell.usedDiscount and logs to
 * discount_usage (for per-user limits and reporting).
 */
function record_discount_use($code, $user_id, $order_id, $amount, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return;
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    try {
        $pdo->prepare("UPDATE DiscountSell SET usedDiscount = usedDiscount + 1 WHERE codeDiscount = ?")
            ->execute([(string) $code]);
        $pdo->prepare(
            "INSERT INTO discount_usage (code, user_id, order_id, amount, bot_id, used_at)
             VALUES (?,?,?,?,?,NOW())"
        )->execute([(string) $code, (string) $user_id, (string) $order_id, (string) $amount, (int) $bot_id]);
    } catch (Exception $e) {
        error_log("record_discount_use error: " . $e->getMessage());
    }
}

/**
 * Batch-generate N unique coupon codes sharing the same settings.
 * $opts: price, discount_kind, limitDiscount, useuser, min_order, max_amount,
 *        time (expiry ts), prefix, length, bot_id.
 * Returns ['created' => int, 'codes' => [...]].
 */
function generate_discount_codes($count, array $opts)
{
    global $pdo;
    $res = ['created' => 0, 'codes' => []];
    if (!isset($pdo) || $count < 1) {
        return $res;
    }
    $count   = min((int) $count, 1000); // safety cap
    $prefix  = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($opts['prefix'] ?? ''));
    $length  = max(4, min(16, (int) ($opts['length'] ?? 6)));
    $bot_id  = (int) ($opts['bot_id'] ?? (defined('BOT_ID') ? BOT_ID : 0));

    try {
        $ins = $pdo->prepare(
            "INSERT INTO DiscountSell
             (codeDiscount, price, limitDiscount, agent, usefirst, useuser, code_product,
              code_panel, time, type, usedDiscount, discount_kind, max_amount, min_order,
              start_at, enabled, bot_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $made = 0;
        $attempts = 0;
        while ($made < $count && $attempts < $count * 20) {
            $attempts++;
            $rand = strtoupper(bin2hex(random_bytes(8)));
            $code = ($prefix !== '' ? $prefix . '-' : '') . substr($rand, 0, $length);
            // Skip if exists.
            $chk = $pdo->prepare("SELECT 1 FROM DiscountSell WHERE codeDiscount = ? LIMIT 1");
            $chk->execute([$code]);
            if ($chk->fetchColumn()) {
                continue;
            }
            $ins->execute([
                $code,
                (string) ($opts['price'] ?? '0'),
                (string) ($opts['limitDiscount'] ?? '1'),
                (string) ($opts['agent'] ?? 'allusers'),
                (string) ($opts['usefirst'] ?? '0'),
                (string) ($opts['useuser'] ?? '1'),
                (string) ($opts['code_product'] ?? 'all'),
                (string) ($opts['code_panel'] ?? '/all'),
                (string) ($opts['time'] ?? '0'),
                (string) ($opts['type'] ?? 'all'),
                '0',
                (string) ($opts['discount_kind'] ?? 'percent'),
                (string) ($opts['max_amount'] ?? '0'),
                (string) ($opts['min_order'] ?? '0'),
                isset($opts['start_at']) && $opts['start_at'] !== '' ? (string) $opts['start_at'] : null,
                '1',
                $bot_id,
            ]);
            $res['codes'][] = $code;
            $made++;
        }
        $res['created'] = $made;
    } catch (Exception $e) {
        error_log("generate_discount_codes error: " . $e->getMessage());
    }
    return $res;
}

// ===========================================================================
// Cart rendering + multi-item checkout (سبد چندقلمی).
// ===========================================================================

/**
 * Render the user's cart: each line with qty +/- and remove buttons, the grand
 * total, plus "checkout" / "continue shopping" / "clear" actions.
 */
function shop_render_cart($from_id)
{
    $cur  = store_currency();
    $data = shop_cart_resolve($from_id);
    if (empty($data['lines'])) {
        $kb = json_encode(['inline_keyboard' => [
            [['text' => "🛍 مشاهدهٔ محصولات", 'callback_data' => "shoplist"]],
            [['text' => "🔙 بازگشت", 'callback_data' => "backuser"]],
        ]]);
        sendmessage($from_id, "🛒 سبد خرید شما خالی است.", $kb, 'html');
        return;
    }

    $lines = ["🛒 <b>سبد خرید شما</b>\n"];
    $rows  = [];
    foreach ($data['lines'] as $i => $l) {
        $name = htmlspecialchars($l['product']['name_product'] ?? '');
        $var  = $l['variant'] !== null ? " (" . htmlspecialchars($l['variant']) . ")" : '';
        $lines[] = ($i + 1) . ". {$name}{$var}\n   "
            . $l['qty'] . " × " . number_format($l['unit']) . " = <b>"
            . number_format($l['subtotal']) . "</b> {$cur}";
        // qty controls for this line (index-based, stable within this render).
        $rows[] = [
            ['text' => "➖", 'callback_data' => "cartdec_{$i}"],
            ['text' => "{$l['qty']} عدد", 'callback_data' => "cartnoop"],
            ['text' => "➕", 'callback_data' => "cartinc_{$i}"],
            ['text' => "🗑", 'callback_data' => "cartdel_{$i}"],
        ];
    }
    $lines[] = "\n💰 جمع کل: <b>" . number_format($data['total']) . "</b> {$cur}";

    $rows[] = [['text' => "✅ تسویه و پرداخت", 'callback_data' => "cartcheckout"]];
    $rows[] = [['text' => "🏷 کد تخفیف", 'callback_data' => "cartcoupon"]];
    $rows[] = [
        ['text' => "🛍 ادامهٔ خرید", 'callback_data' => "shoplist"],
        ['text' => "🗑 خالی‌کردن", 'callback_data' => "cartclear"],
    ];
    $rows[] = [['text' => "🔙 بازگشت", 'callback_data' => "backuser"]];

    sendmessage($from_id, implode("\n", $lines), json_encode(['inline_keyboard' => $rows]), 'html');
}

/**
 * Check out the entire cart in one go: validate availability, apply an optional
 * coupon (stored as shop_dc:{code} in Processing_value), deduct balance, record
 * one order per line, deliver each line, decrement stock and notify admins.
 * Returns true if handled.
 */
function shop_checkout_cart($from_id, $user)
{
    $cur  = store_currency();
    $data = shop_cart_resolve($from_id);
    if (empty($data['lines'])) {
        sendmessage($from_id, "🛒 سبد خرید شما خالی است.", null, 'html');
        return true;
    }

    // Availability re-check for every line before charging anything.
    foreach ($data['lines'] as $l) {
        if (!shop_product_available($l['product'])) {
            sendmessage($from_id, "⛔️ «" . htmlspecialchars($l['product']['name_product'] ?? '') . "» ناموجود شد. لطفاً سبد را به‌روزرسانی کنید.", null, 'html');
            return true;
        }
    }

    $total = (int) $data['total'];

    // Re-validate any applied coupon against the cart total.
    $couponCode = null;
    $discount   = 0;
    $pv = $user['Processing_value'] ?? '';
    if (is_string($pv) && strpos($pv, 'shop_dc:') === 0) {
        $maybe = substr($pv, strlen('shop_dc:'));
        $chk = validate_discount_code($maybe, $from_id, $total);
        if ($chk['ok']) {
            $couponCode = $maybe;
            $discount   = (int) $chk['amount'];
        }
    }
    $afterDiscount = max(0, $total - $discount);

    // Shipping cost (Audit-3,4,5): only for carts that contain a physical item.
    // Weight is summed from each product's 'weight' attribute; the configured
    // weight tariff / API quote / flat cost is then applied. Non-physical carts
    // (VPN, files, codes) get 0 and the whole block is a no-op for them.
    $shipping = 0;
    $hasPhysical = false;
    foreach ($data['lines'] as $l) {
        if (($l['product']['product_type'] ?? '') === 'physical') {
            $hasPhysical = true;
            break;
        }
    }
    if ($hasPhysical && function_exists('calc_shipping_cost')) {
        $weight   = function_exists('cart_total_weight') ? cart_total_weight($data['lines']) : 0;
        $shipping = (int) calc_shipping_cost($afterDiscount, null, null, $weight);
    }

    $payable = max(0, $afterDiscount + $shipping);
    $balance = (int) ($user['Balance'] ?? 0);

    if ($balance < $payable) {
        $need = number_format($payable - $balance);
        sendmessage($from_id, "موجودی کیف پول شما کافی نیست. کسری: <b>{$need}</b> {$cur}", null, 'html');
        return true;
    }

    // Charge once for the whole cart, then deliver each line.
    update("user", "Balance", $balance - $payable, "id", $from_id);

    $orderIds  = [];
    $delivered = [];
    foreach ($data['lines'] as $l) {
        $product = $l['product'];
        $qty     = (int) $l['qty'];
        // Record one order row per line (price = that line's subtotal).
        $orderId = shop_record_order($from_id, $product, $l['subtotal'], 'paid', null);
        $orderIds[] = $orderId;
        // Decrement stock by the line quantity (variant-aware when set).
        if (function_exists('product_decrement_stock')) {
            product_decrement_stock((int) $product['id'], $qty, $l['variant']);
        }
        // Deliver this line qty times (serial codes/files are per-unit).
        for ($k = 0; $k < $qty; $k++) {
            shop_deliver_product($from_id, $product, $orderId);
        }
        $delivered[] = ($product['name_product'] ?? '') . " ×{$qty}";
    }

    // Persist the shipping cost on the first order row of this cart (Audit-3).
    if ($shipping > 0 && !empty($orderIds[0])) {
        update("Payment_report", "shipping_cost", $shipping, "id_order", $orderIds[0]);
    }

    // Record coupon usage once for the whole cart.
    if ($couponCode !== null && $discount > 0) {
        record_discount_use($couponCode, $from_id, $orderIds[0] ?? '', $discount);
    }
    // Clear coupon + empty the cart.
    if ($couponCode !== null || (is_string($pv) && strpos($pv, 'shop_dc:') === 0)) {
        update("user", "Processing_value", "0", "id", $from_id);
    }
    shop_cart_clear($from_id);

    $disLine  = $discount > 0 ? "\nتخفیف: " . number_format($discount) . " {$cur}" : '';
    $shipLine = $shipping > 0 ? "\nهزینهٔ ارسال: " . number_format($shipping) . " {$cur}" : '';
    sendmessage(
        $from_id,
        "✅ پرداخت موفق بود.\nمبلغ کالا: " . number_format($afterDiscount) . " {$cur}{$disLine}{$shipLine}\nمبلغ پرداختی: <b>" . number_format($payable) . "</b> {$cur}\nسفارش شما ثبت شد.",
        null,
        'html'
    );

    // Notify admins.
    $admins = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    if (is_array($admins)) {
        $itemsTxt = htmlspecialchars(implode("، ", $delivered));
        $dl = $discount > 0 ? "\nتخفیف: " . number_format($discount) . " (" . htmlspecialchars((string) $couponCode) . ")" : '';
        foreach ($admins as $aid) {
            sendmessage(
                $aid,
                "🛒 سفارش جدید (سبد چندقلمی)\nاقلام: {$itemsTxt}\nمبلغ پرداختی: " . number_format($payable) . " {$cur}{$dl}"
                    . "\nکاربر: <code>" . htmlspecialchars((string) $from_id) . "</code>",
                null,
                'html'
            );
        }
    }
    return true;
}

/**
 * Central handler for the non-VPN shop callbacks. Returns true if it handled
 * the incoming callback (caller should then stop processing), false otherwise.
 * This keeps the entire generic-shop purchase path isolated from the VPN flow.
 */
function shop_handle_callback($datain, $from_id, $user)
{
    // Show the shop product list.
    if ($datain === 'shoplist') {
        shop_render_list($from_id);
        return true;
    }

    // --- Cart callbacks (سبد چندقلمی) -----------------------------------
    if ($datain === 'cartnoop') {
        return true; // qty badge tap — no action
    }
    // Add a product (optionally a variant) to the cart: cartadd_{pid} or
    // cartadd_{pid}_{variant}. The variant segment is URL-safe (no underscores
    // are used inside it because variant keys are stored without them here).
    if (preg_match('/^cartadd_(\d+)(?:_(.+))?$/', $datain, $m)) {
        $product = shop_product((int) $m[1]);
        if (!$product) {
            sendmessage($from_id, "محصول یافت نشد.", null, 'html');
            return true;
        }
        if (!shop_product_available($product)) {
            sendmessage($from_id, "⛔️ این محصول در حال حاضر ناموجود است.", null, 'html');
            return true;
        }
        $variant = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
        shop_cart_add($from_id, (int) $m[1], $variant, 1);
        sendmessage($from_id, "✅ به سبد خرید اضافه شد.\nتعداد اقلام سبد: <b>" . shop_cart_count($from_id) . "</b>", json_encode(['inline_keyboard' => [
            [['text' => "🛒 مشاهدهٔ سبد", 'callback_data' => "shopcart"]],
            [['text' => "🛍 ادامهٔ خرید", 'callback_data' => "shoplist"]],
        ]]), 'html');
        return true;
    }
    if ($datain === 'shopcart') {
        shop_render_cart($from_id);
        return true;
    }
    if (preg_match('/^cartinc_(\d+)$/', $datain, $m)) {
        $items = shop_cart_get($from_id);
        $idx   = (int) $m[1];
        if (isset($items[$idx])) {
            shop_cart_set_qty($from_id, $idx, $items[$idx]['qty'] + 1);
        }
        shop_render_cart($from_id);
        return true;
    }
    if (preg_match('/^cartdec_(\d+)$/', $datain, $m)) {
        $items = shop_cart_get($from_id);
        $idx   = (int) $m[1];
        if (isset($items[$idx])) {
            shop_cart_set_qty($from_id, $idx, $items[$idx]['qty'] - 1);
        }
        shop_render_cart($from_id);
        return true;
    }
    if (preg_match('/^cartdel_(\d+)$/', $datain, $m)) {
        shop_cart_remove($from_id, (int) $m[1]);
        shop_render_cart($from_id);
        return true;
    }
    if ($datain === 'cartclear') {
        shop_cart_clear($from_id);
        sendmessage($from_id, "🗑 سبد خرید خالی شد.", null, 'html');
        return true;
    }
    if ($datain === 'cartcoupon') {
        if (shop_cart_count($from_id) < 1) {
            sendmessage($from_id, "🛒 سبد خرید شما خالی است.", null, 'html');
            return true;
        }
        step('shop_cart_coupon', $from_id);
        sendmessage($from_id, "🏷 کد تخفیف خود را ارسال کنید:", null, 'html');
        return true;
    }
    if ($datain === 'cartcheckout') {
        shop_checkout_cart($from_id, $user);
        return true;
    }

    // Start a product search: ask the user to type a query.
    if ($datain === 'shopsearch') {
        step('shop_search', $from_id);
        sendmessage($from_id, "🔍 عبارت جستجو را ارسال کنید (نام محصول، دسته یا کد):", null, 'html');
        return true;
    }

    // View a product.
    if (preg_match('/^shopview_(\d+)$/', $datain, $m)) {
        $product = shop_product((int) $m[1]);
        if (!$product) {
            sendmessage($from_id, "محصول یافت نشد.", null, 'html');
            return true;
        }
        shop_render_product($from_id, $product);
        return true;
    }

    // Ask for a coupon code for a product.
    if (preg_match('/^shopcoupon_(\d+)$/', $datain, $m)) {
        $product = shop_product((int) $m[1]);
        if (!$product) {
            sendmessage($from_id, "محصول یافت نشد.", null, 'html');
            return true;
        }
        step('shop_coupon_' . (int) $m[1], $from_id);
        sendmessage($from_id, "🏷 کد تخفیف خود را ارسال کنید:", null, 'html');
        return true;
    }

    // Buy a product (optionally with an applied coupon stored in Processing_value).
    if (preg_match('/^shopbuy_(\d+)$/', $datain, $m)) {
        $product = shop_product((int) $m[1]);
        if (!$product) {
            sendmessage($from_id, "محصول یافت نشد.", null, 'html');
            return true;
        }
        if (!shop_product_available($product)) {
            sendmessage($from_id, "⛔️ این محصول در حال حاضر ناموجود است.", null, 'html');
            return true;
        }

        // Physical products that need a postal address: ensure one exists first.
        $needsAddress = ($product['product_type'] ?? '') === 'physical'
            && (string) product_attr($product, 'needs_address', '') === '1';
        $addressId = null;
        if ($needsAddress) {
            $addr = address_default($from_id);
            if (!$addr) {
                // No saved address — start the address-collection flow, keeping
                // the product id (and any applied coupon) so we resume the buy.
                $pvKeep = (is_string($user['Processing_value'] ?? '') && strpos($user['Processing_value'], 'shop_dc:') === 0)
                    ? '|' . $user['Processing_value'] : '';
                update("user", "Processing_value", 'shop_addr:' . (int) $product['id'] . $pvKeep, "id", $from_id);
                step('shop_address_name', $from_id);
                sendmessage($from_id, "📦 برای ارسال این محصول به آدرس پستی نیاز داریم.\n\nلطفاً <b>نام و نام خانوادگی گیرنده</b> را ارسال کنید:", null, 'html');
                return true;
            }
            $addressId = (int) $addr['id'];
        }

        $price   = (int) preg_replace('/[^\d]/', '', (string) ($product['price_product'] ?? '0'));
        $balance = (int) ($user['Balance'] ?? 0);

        // Re-validate any applied coupon at purchase time (stored as shop_dc:{code}).
        $couponCode = null;
        $discount   = 0;
        $pv = $user['Processing_value'] ?? '';
        if (is_string($pv) && strpos($pv, 'shop_dc:') === 0) {
            $maybe = substr($pv, strlen('shop_dc:'));
            $chk = validate_discount_code($maybe, $from_id, $price);
            if ($chk['ok']) {
                $couponCode = $maybe;
                $discount   = (int) $chk['amount'];
            }
        }
        $payable = max(0, $price - $discount);

        if ($balance < $payable) {
            $need = number_format($payable - $balance);
            sendmessage(
                $from_id,
                "موجودی کیف پول شما کافی نیست. کسری: <b>{$need}</b> " . store_currency(),
                null,
                'html'
            );
            return true;
        }
        // Deduct balance, record order, deliver.
        $newBalance = $balance - $payable;
        update("user", "Balance", $newBalance, "id", $from_id);
        $orderId = shop_record_order($from_id, $product, $payable, 'paid', $addressId);
        $status  = shop_deliver_product($from_id, $product, $orderId);

        // If a serial code/file couldn't be delivered, refund to keep things fair.
        if ($status === 'serial_code:none' || $status === 'digital_file:missing') {
            update("user", "Balance", $balance, "id", $from_id);
            return true;
        }
        // Record coupon usage (after successful delivery).
        if ($couponCode !== null && $discount > 0) {
            record_discount_use($couponCode, $from_id, $orderId, $discount);
        }
        // Clear any applied coupon.
        if ($couponCode !== null || (is_string($pv) && strpos($pv, 'shop_dc:') === 0)) {
            update("user", "Processing_value", "0", "id", $from_id);
        }
        // Notify admins of the new shop order.
        $admins = select("admin", "id_admin", null, null, "FETCH_COLUMN");
        if (is_array($admins)) {
            $name = htmlspecialchars($product['name_product'] ?? '');
            $disLine = $discount > 0 ? "\nتخفیف: " . number_format($discount) . " (" . htmlspecialchars((string) $couponCode) . ")" : '';
            foreach ($admins as $aid) {
                sendmessage(
                    $aid,
                    "🛒 سفارش جدید\nمحصول: {$name}\nمبلغ پرداختی: " . number_format($payable) . " " . store_currency()
                        . $disLine
                        . "\nکاربر: <code>" . htmlspecialchars((string) $from_id) . "</code>\nسفارش: <code>{$orderId}</code>",
                    null,
                    'html'
                );
            }
        }
        return true;
    }

    return false;
}

/**
 * Handle the "enter coupon code" text step in the shop flow.
 * Called from index.php when user.step matches shop_coupon_{id}.
 * Returns true if handled.
 */
function shop_handle_coupon_step($step, $text, $from_id, $user)
{
    // Cart-level coupon (applies to the whole cart total).
    if ((string) $step === 'shop_cart_coupon') {
        $data  = shop_cart_resolve($from_id);
        $total = (int) $data['total'];
        $res   = validate_discount_code(trim((string) $text), $from_id, $total);
        if (!$res['ok']) {
            sendmessage($from_id, "❌ " . $res['error'], null, 'html');
            step('home', $from_id);
            return true;
        }
        update("user", "Processing_value", "shop_dc:" . trim((string) $text), "id", $from_id);
        step('home', $from_id);
        $cur = store_currency();
        $msg = "✅ کد تخفیف اعمال شد.\n"
            . "جمع سبد: " . number_format($total) . " {$cur}\n"
            . "تخفیف: " . number_format((int) $res['amount']) . " {$cur}\n"
            . "💰 مبلغ نهایی: <b>" . number_format((int) $res['final']) . " {$cur}</b>";
        $kb = json_encode(['inline_keyboard' => [
            [['text' => "✅ تسویه و پرداخت", 'callback_data' => "cartcheckout"]],
            [['text' => "🛒 مشاهدهٔ سبد", 'callback_data' => "shopcart"]],
        ]]);
        sendmessage($from_id, $msg, $kb, 'html');
        return true;
    }

    if (!preg_match('/^shop_coupon_(\d+)$/', (string) $step, $m)) {
        return false;
    }
    $product = shop_product((int) $m[1]);
    if (!$product) {
        sendmessage($from_id, "محصول یافت نشد.", null, 'html');
        step('home', $from_id);
        return true;
    }
    $price = (int) preg_replace('/[^\d]/', '', (string) ($product['price_product'] ?? '0'));
    $res = validate_discount_code(trim((string) $text), $from_id, $price);
    if (!$res['ok']) {
        sendmessage($from_id, "❌ " . $res['error'], null, 'html');
        step('home', $from_id);
        return true;
    }
    // Store the applied coupon and show a confirm-buy with the new total.
    update("user", "Processing_value", "shop_dc:" . trim((string) $text), "id", $from_id);
    step('home', $from_id);
    $cur = store_currency();
    $msg = "✅ کد تخفیف اعمال شد.\n"
        . "قیمت: " . number_format($price) . " {$cur}\n"
        . "تخفیف: " . number_format((int) $res['amount']) . " {$cur}\n"
        . "💰 مبلغ نهایی: <b>" . number_format((int) $res['final']) . " {$cur}</b>";
    $kb = json_encode(['inline_keyboard' => [
        [['text' => "🛒 تأیید و خرید", 'callback_data' => "shopbuy_" . (int) $product['id']]],
        [['text' => "🔙 بازگشت", 'callback_data' => "backuser"]],
    ]]);
    sendmessage($from_id, $msg, $kb, 'html');
    return true;
}

/**
 * Handle the multi-step address-collection flow for physical products.
 * Steps: shop_address_name → _phone → _province → _city → _addr → _postal.
 * Temp values accumulate in Processing_value as "shop_addr:{pid}|...|k=v".
 * On completion the address is saved and the buy is resumed automatically.
 * Returns true if the step was handled.
 */
function shop_handle_address_step($step, $text, $from_id, $user)
{
    $steps = [
        'shop_address_name'     => ['key' => 'full_name',   'next' => 'shop_address_phone',    'prompt' => "📱 شمارهٔ تماس گیرنده را ارسال کنید:"],
        'shop_address_phone'    => ['key' => 'phone',       'next' => 'shop_address_province', 'prompt' => "📍 استان را ارسال کنید:"],
        'shop_address_province' => ['key' => 'province',    'next' => 'shop_address_city',     'prompt' => "🏙 شهر را ارسال کنید:"],
        'shop_address_city'     => ['key' => 'city',        'next' => 'shop_address_addr',     'prompt' => "🏠 نشانی کامل پستی را ارسال کنید:"],
        'shop_address_addr'     => ['key' => 'address',     'next' => 'shop_address_postal',   'prompt' => "🏷 کدپستی ۱۰ رقمی را ارسال کنید:"],
        'shop_address_postal'   => ['key' => 'postal_code', 'next' => null,                    'prompt' => null],
    ];
    if (!isset($steps[(string) $step])) {
        return false;
    }
    $text = trim((string) $text);
    if ($text === '') {
        sendmessage($from_id, "مقدار خالی است؛ لطفاً دوباره ارسال کنید.", null, 'html');
        return true;
    }

    // Parse the carrier value: "shop_addr:{pid}[|shop_dc:{code}][|k=v|k=v...]".
    $pv = (string) ($user['Processing_value'] ?? '');
    $parts = explode('|', $pv);
    $head  = array_shift($parts); // shop_addr:{pid}
    if (strpos($head, 'shop_addr:') !== 0) {
        step('home', $from_id);
        return true;
    }
    $pid = (int) substr($head, strlen('shop_addr:'));

    // Collected fields and any preserved coupon.
    $coupon = null;
    $fields = [];
    foreach ($parts as $p) {
        if (strpos($p, 'shop_dc:') === 0) {
            $coupon = $p; // keep verbatim to re-apply on resume
        } elseif (strpos($p, '=') !== false) {
            [$k, $v] = explode('=', $p, 2);
            $fields[$k] = $v;
        }
    }

    // Light validation for phone & postal code (Iran-style, lenient).
    $cur = $steps[(string) $step];
    if ($cur['key'] === 'phone' && !preg_match('/^[0-9+][0-9]{6,14}$/', preg_replace('/\s/', '', $text))) {
        sendmessage($from_id, "شمارهٔ تماس نامعتبر است؛ لطفاً دوباره ارسال کنید.", null, 'html');
        return true;
    }
    if ($cur['key'] === 'postal_code' && !preg_match('/^[0-9]{10}$/', preg_replace('/\D/', '', $text))) {
        sendmessage($from_id, "کدپستی باید ۱۰ رقم باشد؛ لطفاً دوباره ارسال کنید.", null, 'html');
        return true;
    }

    // Store this field (encode = and | to keep the carrier parseable).
    $clean = str_replace(['=', '|'], [' ', ' '], $text);
    $fields[$cur['key']] = $clean;

    if ($cur['next'] !== null) {
        // Rebuild the carrier and advance to the next step.
        $carrier = 'shop_addr:' . $pid;
        if ($coupon !== null) {
            $carrier .= '|' . $coupon;
        }
        foreach ($fields as $k => $v) {
            $carrier .= '|' . $k . '=' . $v;
        }
        update("user", "Processing_value", $carrier, "id", $from_id);
        step($cur['next'], $from_id);
        sendmessage($from_id, $cur['prompt'], null, 'html');
        return true;
    }

    // Final step: save the address, restore any coupon, and resume the buy.
    $newId = address_save($from_id, $fields, true);
    if ($coupon !== null) {
        update("user", "Processing_value", $coupon, "id", $from_id);
    } else {
        update("user", "Processing_value", "0", "id", $from_id);
    }
    step('home', $from_id);

    $product = shop_product($pid);
    if (!$product) {
        sendmessage($from_id, "✅ آدرس ذخیره شد، اما محصول دیگر در دسترس نیست.", null, 'html');
        return true;
    }
    sendmessage(
        $from_id,
        "✅ آدرس شما ذخیره شد:\n\n" . address_format(address_get($newId, $from_id))
            . "\n\nبرای تکمیل خرید روی دکمهٔ زیر بزنید.",
        json_encode(['inline_keyboard' => [
            [['text' => "🛒 تکمیل خرید", 'callback_data' => "shopbuy_" . $pid]],
            [['text' => "🔙 بازگشت", 'callback_data' => "backuser"]],
        ]]),
        'html'
    );
    return true;
}

// ===========================================================================
// Orders & shipping tracking (step 8b) — built on the extended Payment_report.
// ===========================================================================

/**
 * Registry of shipping carriers with a tracking-link template.
 * {code} in `track` is replaced with the tracking code. Admins can pick a
 * carrier when shipping a physical order; the customer gets a clickable link.
 */
function shipping_carriers()
{
    return [
        'post' => [
            'name'  => 'پست جمهوری اسلامی ایران',
            'track' => 'https://tracking.post.ir/?id={code}',
            // Private credentials each merchant obtains from the carrier. Each
            // field: key => [label, type(text|password|number), required, hint].
            'fields' => [
                'api_key'      => ['label' => 'کلید API (در صورت داشتن قرارداد)', 'type' => 'password', 'required' => false, 'hint' => 'برای ثبت خودکار سفارش؛ خالی بماند یعنی فقط رهگیری دستی'],
                'sender_name'  => ['label' => 'نام فرستنده', 'type' => 'text', 'required' => false, 'hint' => ''],
                'sender_phone' => ['label' => 'تلفن فرستنده', 'type' => 'text', 'required' => false, 'hint' => ''],
                'origin_city'  => ['label' => 'شهر مبدأ', 'type' => 'text', 'required' => false, 'hint' => ''],
                'quote_url'    => ['label' => 'آدرس API نرخ‌دهی (اختیاری)', 'type' => 'text', 'required' => false, 'hint' => 'سرویس/Webhook نرخ‌دهی بر اساس وزن؛ خالی = نرخ پلکانی/دستی'],
            ],
        ],
        'tipax' => [
            'name'  => 'تیپاکس',
            'track' => 'https://tipaxco.com/tracking?code={code}',
            'fields' => [
                'api_key'      => ['label' => 'API Key تیپاکس', 'type' => 'password', 'required' => true,  'hint' => 'از پنل کاربری تیپاکس'],
                'username'     => ['label' => 'نام کاربری', 'type' => 'text', 'required' => false, 'hint' => ''],
                'customer_id'  => ['label' => 'کد مشتری / Customer ID', 'type' => 'text', 'required' => false, 'hint' => ''],
                'origin_city'  => ['label' => 'شهر مبدأ', 'type' => 'text', 'required' => false, 'hint' => ''],
                'quote_url'    => ['label' => 'آدرس API نرخ‌دهی (اختیاری)', 'type' => 'text', 'required' => false, 'hint' => 'اگر یک Webhook/سرویس نرخ‌دهی دارید (مثلاً در n8n) که با وزن و مقصد قیمت برمی‌گرداند، آدرسش را بگذارید تا قیمت زنده گرفته شود؛ خالی = نرخ پلکانی/دستی'],
            ],
        ],
        'chapar' => [
            'name'  => 'چاپار',
            'track' => 'https://chaparexp.com/tracking?code={code}',
            'fields' => [
                'api_key'     => ['label' => 'API Key چاپار', 'type' => 'password', 'required' => false, 'hint' => ''],
                'contract_no' => ['label' => 'شماره قرارداد', 'type' => 'text', 'required' => false, 'hint' => ''],
            ],
        ],
        'mahex' => [
            'name'  => 'ماهکس',
            'track' => 'https://mahex.com/tracking?code={code}',
            'fields' => [
                'api_key'  => ['label' => 'API Key ماهکس', 'type' => 'password', 'required' => false, 'hint' => ''],
                'username' => ['label' => 'نام کاربری', 'type' => 'text', 'required' => false, 'hint' => ''],
            ],
        ],
        'snapp' => [
            'name'  => 'اسنپ‌باکس',
            'track' => '',
            'fields' => [
                'api_key'   => ['label' => 'API Key اسنپ‌باکس', 'type' => 'password', 'required' => false, 'hint' => ''],
                'client_id' => ['label' => 'Client ID', 'type' => 'text', 'required' => false, 'hint' => ''],
            ],
        ],
        'other' => [
            'name'  => 'سایر / حضوری',
            'track' => '',
            'fields' => [],
        ],
    ];
}

/**
 * Carriers the merchant has actually enabled in their shipping config, as a
 * flat code=>name map. Used by the product form so the admin picks from the
 * configured (API-backed) carriers instead of typing a company name by hand.
 * Falls back to the full master list when nothing is configured yet.
 */
function enabled_shipping_carriers($bot_id = null)
{
    $all = shipping_carriers();
    $out = [];
    try {
        $cfg = function_exists('get_shipping_config') ? get_shipping_config($bot_id) : [];
        $carriers = is_array($cfg['carriers'] ?? null) ? $cfg['carriers'] : [];
        foreach ($carriers as $code => $c) {
            if (!empty($c['enabled']) && isset($all[$code])) {
                $out[$code] = $all[$code]['name'];
            }
        }
    } catch (Exception $e) {
        error_log("enabled_shipping_carriers error: " . $e->getMessage());
    }
    // Nothing configured yet -> offer the whole master list so the form is still usable.
    if (empty($out)) {
        foreach ($all as $code => $c) {
            $out[$code] = $c['name'];
        }
    }
    return $out;
}

/**
 * Read the shipping config JSON from setting (main bot) or botsaz.setting
 * (child bot). Always returns an array with normalised keys:
 *   carriers => [ carrier_code => ['enabled'=>bool, 'cost'=>int, 'creds'=>[...]] ]
 *   free_shipping => ['enabled'=>bool, 'min_order'=>int]   (min_order 0 = always free)
 *   default_cost  => int    (fallback flat shipping cost when no per-carrier cost)
 */
function get_shipping_config($bot_id = null)
{
    global $pdo, $setting;
    $defaults = [
        'carriers'      => [],
        'free_shipping' => ['enabled' => false, 'min_order' => 0],
        'default_cost'  => 0,
    ];
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    $raw = null;
    try {
        if ((int) $bot_id > 0 && isset($pdo)) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($brow && !empty($brow['setting'])) {
                $bset = json_decode($brow['setting'], true);
                if (is_array($bset) && isset($bset['store_shipping'])) {
                    $raw = is_array($bset['store_shipping'])
                        ? $bset['store_shipping']
                        : json_decode((string) $bset['store_shipping'], true);
                }
            }
        } else {
            if (is_array($setting) && isset($setting['store_shipping'])) {
                $raw = is_array($setting['store_shipping'])
                    ? $setting['store_shipping']
                    : json_decode((string) $setting['store_shipping'], true);
            } else {
                $row = select("setting", "store_shipping", null, null, "FETCH_COLUMN");
                if (is_array($row)) {
                    $row = $row[0] ?? null;
                }
                if ($row) {
                    $raw = json_decode((string) $row, true);
                }
            }
        }
    } catch (Exception $e) {
        error_log("get_shipping_config error: " . $e->getMessage());
    }
    if (!is_array($raw)) {
        return $defaults;
    }
    $cfg = array_merge($defaults, $raw);
    if (!is_array($cfg['carriers'])) {
        $cfg['carriers'] = [];
    }
    if (!is_array($cfg['free_shipping'])) {
        $cfg['free_shipping'] = $defaults['free_shipping'];
    }
    $cfg['free_shipping']['enabled']   = !empty($cfg['free_shipping']['enabled']);
    $cfg['free_shipping']['min_order'] = (int) ($cfg['free_shipping']['min_order'] ?? 0);
    $cfg['default_cost']               = (int) ($cfg['default_cost'] ?? 0);

    // Global weight-based tariff (base + per-kg). Optional (Audit-4).
    $gt = is_array($cfg['weight_tariff'] ?? null) ? $cfg['weight_tariff'] : [];
    $cfg['weight_tariff'] = [
        'base'   => (int) ($gt['base'] ?? 0),
        'per_kg' => (int) ($gt['per_kg'] ?? 0),
    ];

    // Normalise each carrier's per-carrier weight tariff too (if present).
    foreach ($cfg['carriers'] as $code => &$c) {
        if (isset($c['weight_tariff']) && is_array($c['weight_tariff'])) {
            $c['weight_tariff'] = [
                'base'   => (int) ($c['weight_tariff']['base'] ?? 0),
                'per_kg' => (int) ($c['weight_tariff']['per_kg'] ?? 0),
            ];
        }
    }
    unset($c);

    return $cfg;
}

/**
 * Persist the shipping config (validated/normalised array). Scope: global
 * (bot_id 0) -> setting.store_shipping ; child bot -> botsaz.setting JSON.
 * Returns true on success.
 */
function set_shipping_config(array $cfg, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
    try {
        if ((int) $bot_id > 0) {
            $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $bot_id]);
            $brow = $stmt->fetch(PDO::FETCH_ASSOC);
            $bset = ($brow && !empty($brow['setting'])) ? json_decode($brow['setting'], true) : [];
            if (!is_array($bset)) {
                $bset = [];
            }
            $bset['store_shipping'] = $cfg;
            return $pdo->prepare("UPDATE botsaz SET setting = ? WHERE id = ?")
                ->execute([json_encode($bset, JSON_UNESCAPED_UNICODE), (int) $bot_id]);
        }
        return $pdo->prepare("UPDATE setting SET store_shipping = ?")->execute([$json]);
    } catch (Exception $e) {
        error_log("set_shipping_config error: " . $e->getMessage());
        return false;
    }
}

/** True if a given carrier is enabled in the shipping config. */
function carrier_enabled($carrier, $bot_id = null)
{
    $cfg = get_shipping_config($bot_id);
    return !empty($cfg['carriers'][$carrier]['enabled']);
}

/** List of enabled carriers as [code => name] (for customer-facing pickers). */
function enabled_carriers($bot_id = null)
{
    $cfg = get_shipping_config($bot_id);
    $all = shipping_carriers();
    $out = [];
    foreach ($cfg['carriers'] as $code => $c) {
        if (!empty($c['enabled']) && isset($all[$code])) {
            $out[$code] = $all[$code]['name'];
        }
    }
    return $out;
}

/** A carrier's private credential value (e.g. api_key), or '' if unset. */
function carrier_credential($carrier, $field, $bot_id = null)
{
    $cfg = get_shipping_config($bot_id);
    return (string) ($cfg['carriers'][$carrier]['creds'][$field] ?? '');
}

/**
 * Compute the shipping cost for an order (Audit-3,4,5).
 *
 * Resolution order (first match wins):
 *   1. Free-shipping rule  -> 0
 *   2. Live carrier API quote (if credentials present)  -> carrier_quote()
 *   3. Weight-based tariff  (base_cost + per_kg * ceil(weight_kg))   ← real model
 *   4. Per-carrier flat cost
 *   5. Store default_cost
 *
 * @param int      $order_total   cart subtotal (for the free-shipping threshold)
 * @param string   $carrier       carrier code, or null
 * @param int|null $bot_id        scope
 * @param int      $weight_grams  total parcel weight in grams (0 = unknown)
 * @return int cost in the store currency unit
 */
function calc_shipping_cost($order_total, $carrier = null, $bot_id = null, $weight_grams = 0)
{
    $cfg = get_shipping_config($bot_id);
    $order_total = (int) $order_total;
    $weight_grams = max(0, (int) $weight_grams);

    // 1) Free shipping rule.
    $free = $cfg['free_shipping'];
    if (!empty($free['enabled'])) {
        $min = (int) ($free['min_order'] ?? 0);
        if ($min <= 0 || $order_total >= $min) {
            return 0;
        }
    }

    $cc = ($carrier !== null && isset($cfg['carriers'][$carrier]))
        ? $cfg['carriers'][$carrier]
        : [];

    // 2) Live API quote when the merchant entered credentials for this carrier.
    if ($carrier !== null && !empty($cc['creds']) && function_exists('carrier_quote')) {
        $quote = carrier_quote($carrier, $weight_grams, $order_total, $cc['creds'], $bot_id);
        if (is_int($quote) && $quote >= 0) {
            return $quote;
        }
    }

    // 3) Weight-based tariff (base + per-kg). Used when configured for the carrier
    //    or globally. This is how Iran Post / Tipax actually price parcels.
    $tariff = is_array($cc['weight_tariff'] ?? null)
        ? $cc['weight_tariff']
        : (is_array($cfg['weight_tariff'] ?? null) ? $cfg['weight_tariff'] : null);
    if ($tariff && $weight_grams > 0 && (!empty($tariff['base']) || !empty($tariff['per_kg']))) {
        $base   = (int) ($tariff['base'] ?? 0);
        $perKg  = (int) ($tariff['per_kg'] ?? 0);
        $kg     = (int) ceil($weight_grams / 1000);
        $kg     = max(1, $kg); // at least 1kg billed
        return $base + ($perKg * $kg);
    }

    // 4) Per-carrier flat cost.
    if ($carrier !== null && isset($cc['cost']) && (int) $cc['cost'] > 0) {
        return (int) $cc['cost'];
    }

    // 5) Store default flat cost.
    return (int) ($cfg['default_cost'] ?? 0);
}

/**
 * Live shipping-rate adapter (Audit-3). Given a carrier code + the merchant's
 * private credentials, ask that carrier's API for a price based on weight and
 * destination. Returns an int cost, or null to let the caller fall back to the
 * weight tariff / flat cost.
 *
 * NOTE: Each carrier exposes a different REST contract and only issues working
 * credentials to contracted merchants, so the concrete request shapes below are
 * intentionally conservative: if the merchant supplies a custom "quote_url"
 * credential we POST a generic JSON payload to it and read back {cost|price}.
 * This lets a merchant (or an n8n flow) wire ANY carrier without us hard-coding
 * each undocumented private API — while keeping a safe no-network fallback.
 *
 * @return int|null
 */
function carrier_quote($carrier, $weight_grams, $order_total, array $creds, $bot_id = null)
{
    // Only attempt a network call when the merchant gave us an explicit quote
    // endpoint. Otherwise we don't know the carrier's private contract → null.
    $quoteUrl = trim((string) ($creds['quote_url'] ?? ''));
    if ($quoteUrl === '' || !preg_match('~^https?://~i', $quoteUrl)) {
        return null;
    }

    $payload = [
        'carrier'      => $carrier,
        'weight_grams' => (int) $weight_grams,
        'order_total'  => (int) $order_total,
        'api_key'      => (string) ($creds['api_key'] ?? ''),
        'origin_city'  => (string) ($creds['origin_city'] ?? ''),
        'customer_id'  => (string) ($creds['customer_id'] ?? ''),
    ];

    $ch = curl_init($quoteUrl);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode((string) $resp, true);
    if (!is_array($data)) {
        return null;
    }
    // Accept either {cost: N} or {price: N} (toman/rial as the merchant defines).
    $cost = $data['cost'] ?? ($data['price'] ?? null);
    if ($cost === null || !is_numeric($cost)) {
        return null;
    }
    return max(0, (int) $cost);
}

/**
 * Total parcel weight (grams) for a set of cart lines. Reads the 'weight'
 * attribute of each physical product × quantity. Non-physical lines weigh 0.
 *
 * @param array $lines  shop_cart_resolve()['lines']
 * @return int grams
 */
function cart_total_weight(array $lines)
{
    $g = 0;
    foreach ($lines as $l) {
        $product = $l['product'] ?? [];
        $qty     = max(1, (int) ($l['qty'] ?? 1));
        $attrs   = [];
        if (!empty($product['attributes'])) {
            $attrs = is_array($product['attributes'])
                ? $product['attributes']
                : (json_decode((string) $product['attributes'], true) ?: []);
        }
        $w = (int) ($attrs['weight'] ?? 0);
        if ($w > 0) {
            $g += $w * $qty;
        }
    }
    return $g;
}

/** True if free shipping currently applies to a given order total. */
function is_free_shipping($order_total, $bot_id = null)
{
    $cfg = get_shipping_config($bot_id);
    if (empty($cfg['free_shipping']['enabled'])) {
        return false;
    }
    $min = (int) ($cfg['free_shipping']['min_order'] ?? 0);
    return $min <= 0 || (int) $order_total >= $min;
}

/**
 * Build a tracking URL for a carrier code, or '' if not supported.
 */
function carrier_tracking_url($carrier, $code)
{
    $code = trim((string) $code);
    if ($code === '') {
        return '';
    }
    $carriers = shipping_carriers();
    $tpl = $carriers[$carrier]['track'] ?? '';
    if ($tpl === '') {
        return '';
    }
    return str_replace('{code}', rawurlencode($code), $tpl);
}

/**
 * Order status registry: machine value => Persian label + emoji.
 * Used by both the panel dropdown and customer notifications.
 */
function order_statuses()
{
    return [
        'pending'    => '⏳ در انتظار بررسی',
        'paid'       => '💳 پرداخت شد',
        'processing' => '📦 در حال آماده‌سازی',
        'shipped'    => '🚚 ارسال شد',
        'delivered'  => '✅ تحویل شد',
        'canceled'   => '❌ لغو شد',
        'refunded'   => '↩️ مسترد شد',
    ];
}

/**
 * Human-readable label for an order status (falls back to the raw value).
 */
function order_status_label($status)
{
    $map = order_statuses();
    $status = (string) $status;
    return $map[$status] ?? ($status !== '' ? $status : '—');
}

/**
 * Update an order's status (Payment_report.order_status) by order id.
 * Returns true on success.
 */
/**
 * Safe wrapper around the open automation layer. Lazily loads automation.php
 * and fires an event only if the layer is present. Never throws, so it can be
 * sprinkled anywhere without risk to the core flow (no-op when disabled).
 */
function emit_event($event, array $data = [], $bot_id = null)
{
    try {
        if (!function_exists('fire_event')) {
            $auto = __DIR__ . '/automation.php';
            if (is_file($auto)) {
                require_once $auto;
            }
        }
        if (function_exists('fire_event')) {
            fire_event($event, $data, $bot_id);
        }
    } catch (Throwable $e) {
        error_log("emit_event error: " . $e->getMessage());
    }
}

function set_order_status($order_id, $status)
{
    global $connect;
    try {
        $stmt = $connect->prepare(
            "UPDATE Payment_report SET order_status = ?, at_updated = ? WHERE id_order = ?"
        );
        $ok = $stmt->execute([(string) $status, date('Y-m-d H:i:s'), (string) $order_id]);
        if ($ok) {
            emit_event('order.status_changed', ['order_id' => (string) $order_id, 'status' => (string) $status]);
            // Convenience aliases so n8n can subscribe to specific milestones.
            $alias = ['paid' => 'order.paid', 'shipped' => 'order.shipped',
                      'delivered' => 'order.delivered', 'canceled' => 'order.canceled'];
            if (isset($alias[(string) $status])) {
                emit_event($alias[(string) $status], ['order_id' => (string) $order_id, 'status' => (string) $status]);
            }
        }
        return $ok;
    } catch (Exception $e) {
        error_log("set_order_status error: " . $e->getMessage());
        return false;
    }
}

/**
 * Attach shipping/tracking info to an order. Optionally bumps status to shipped.
 * Returns true on success.
 */
function set_order_tracking($order_id, $carrier, $tracking_code, $bump_shipped = true)
{
    global $connect;
    $carriers = shipping_carriers();
    $carrierName = $carriers[$carrier]['name'] ?? (string) $carrier;
    try {
        if ($bump_shipped) {
            $stmt = $connect->prepare(
                "UPDATE Payment_report
                 SET shipping_carrier = ?, carrier_name = ?, tracking_code = ?,
                     order_status = 'shipped', at_updated = ?
                 WHERE id_order = ?"
            );
            $ok = $stmt->execute([
                (string) $carrier, $carrierName, (string) $tracking_code,
                date('Y-m-d H:i:s'), (string) $order_id,
            ]);
        } else {
            $stmt = $connect->prepare(
                "UPDATE Payment_report
                 SET shipping_carrier = ?, carrier_name = ?, tracking_code = ?, at_updated = ?
                 WHERE id_order = ?"
            );
            $ok = $stmt->execute([
                (string) $carrier, $carrierName, (string) $tracking_code,
                date('Y-m-d H:i:s'), (string) $order_id,
            ]);
        }
        if ($ok) {
            emit_event('order.shipped', [
                'order_id'      => (string) $order_id,
                'carrier'       => (string) $carrier,
                'carrier_name'  => $carrierName,
                'tracking_code' => (string) $tracking_code,
                'tracking_url'  => carrier_tracking_url($carrier, $tracking_code),
            ]);
        }
        return $ok;
    } catch (Exception $e) {
        error_log("set_order_tracking error: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetch a single order row by its order id.
 */
function get_order($order_id)
{
    global $pdo;
    if (!isset($pdo)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM Payment_report WHERE id_order = ? LIMIT 1");
        $stmt->execute([(string) $order_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        error_log("get_order error: " . $e->getMessage());
        return null;
    }
}

/**
 * Notify the customer when their order status/tracking changes.
 * Sends a clear Persian message including a tracking link when available.
 */
function notify_order_update($order_id, $extra_note = '')
{
    $order = get_order($order_id);
    if (!$order) {
        return false;
    }
    $userId = $order['id_user'] ?? null;
    if (!$userId) {
        return false;
    }
    $statusLabel = order_status_label($order['order_status'] ?? '');
    $msg = "🔔 بروزرسانی سفارش\n"
        . "کد سفارش: <code>" . htmlspecialchars((string) $order_id) . "</code>\n"
        . "وضعیت: <b>{$statusLabel}</b>";

    $carrier = $order['shipping_carrier'] ?? '';
    $code    = $order['tracking_code'] ?? '';
    if ($code !== null && trim((string) $code) !== '') {
        $carrierName = $order['carrier_name'] ?? '';
        $msg .= "\n\n📦 اطلاعات ارسال";
        if ($carrierName !== '') {
            $msg .= "\nشرکت: " . htmlspecialchars((string) $carrierName);
        }
        $msg .= "\nکد رهگیری: <code>" . htmlspecialchars((string) $code) . "</code>";
        $url = carrier_tracking_url($carrier, $code);
        if ($url !== '') {
            $msg .= "\n🔗 پیگیری مرسوله: " . $url;
        }
    }
    if (trim((string) $extra_note) !== '') {
        $msg .= "\n\n📝 " . htmlspecialchars((string) $extra_note);
    }
    sendmessage($userId, $msg, null, 'html');
    return true;
}

/**
 * List a user's shop orders (newest first) for the "my orders" bot view.
 */
function user_orders($user_id, $limit = 20)
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM Payment_report
             WHERE id_user = ? AND product_id IS NOT NULL AND order_status IS NOT NULL
             ORDER BY id DESC LIMIT ?"
        );
        $stmt->bindValue(1, (string) $user_id);
        $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("user_orders error: " . $e->getMessage());
        return [];
    }
}

/**
 * Render the "my orders" list to a Telegram user.
 * Returns true (handled).
 */
function shop_render_my_orders($from_id)
{
    $orders = user_orders($from_id, 20);
    if (empty($orders)) {
        sendmessage($from_id, "شما هنوز سفارشی ثبت نکرده‌اید.", null, 'html');
        return true;
    }
    $cur = store_currency();
    $msg = "🧾 <b>سفارش‌های شما</b>\n";
    foreach ($orders as $o) {
        $pname = '';
        if (!empty($o['product_id'])) {
            $p = shop_product((int) $o['product_id']);
            $pname = $p['name_product'] ?? ('#' . $o['product_id']);
        }
        $msg .= "\n────────────\n"
            . "کد: <code>" . htmlspecialchars((string) $o['id_order']) . "</code>\n"
            . ($pname !== '' ? "محصول: " . htmlspecialchars((string) $pname) . "\n" : '')
            . "مبلغ: " . number_format((int) $o['price']) . " {$cur}\n"
            . "وضعیت: " . order_status_label($o['order_status'] ?? '') . "\n";
        $code = $o['tracking_code'] ?? '';
        if ($code !== null && trim((string) $code) !== '') {
            $msg .= "کد رهگیری: <code>" . htmlspecialchars((string) $code) . "</code>\n";
            $url = carrier_tracking_url($o['shipping_carrier'] ?? '', $code);
            if ($url !== '') {
                $msg .= "🔗 پیگیری: " . $url . "\n";
            }
        }
    }
    sendmessage($from_id, $msg, null, 'html');
    return true;
}

// ===========================================================================
// Inventory / stock (step 8c) — built on the existing product.attributes JSON.
// Product-level stock lives in attributes.stock; per-variant stock lives in
// attributes.variants[].stock. A stock of '' / null means "unlimited" (the
// VPN flow and digital/serial/service products are never gated by this).
// ===========================================================================

/**
 * Resolve the "code" to show for a product in the panel list.
 * Prefers the seller's کد انبار (SKU) whenever it carries any text — whether the
 * SKU field is enabled or disabled — over the auto-generated, meaningless
 * code_product. The SKU may be a single attribute (no variants) OR supplied
 * per-variant; the first non-empty variant SKU is used when there is no
 * product-level SKU. Returns an array: ['code' => string, 'is_sku' => bool].
 */
function product_display_code($product)
{
    $fallback = (string) ($product['code_product'] ?? '');
    $attrs = function_exists('product_attributes') ? product_attributes($product) : [];

    // 1) product-level SKU (single-stock products).
    if (isset($attrs['sku']) && trim((string) $attrs['sku']) !== '') {
        return ['code' => trim((string) $attrs['sku']), 'is_sku' => true];
    }

    // 2) per-variant SKU/کد انبار (when تنوع is enabled). The "code" column the
    //    seller fills is normally keyed `sku`, but a seller could build their own
    //    column whose label means "کد"/"کد انبار"; treat those as the code too.
    if (!empty($attrs['variants']) && is_array($attrs['variants'])) {
        // Map column keys → labels so we can recognise a "code" column by label.
        $codeKeys = ['sku' => true];
        if (!empty($attrs['_variant_cols']) && function_exists('variant_normalize_columns')) {
            foreach (variant_normalize_columns($attrs['_variant_cols']) as $c) {
                $lbl = isset($c['label']) ? (string) $c['label'] : '';
                $isText = !isset($c['type']) || in_array($c['type'], ['text', 'number', 'url'], true);
                // A column whose label mentions کد/انبار/SKU acts as the code.
                if ($isText && (stripos($lbl, 'کد') !== false || stripos($lbl, 'انبار') !== false
                    || stripos($lbl, 'sku') !== false)) {
                    $codeKeys[$c['key']] = true;
                }
            }
        }
        foreach ($attrs['variants'] as $v) {
            if (!is_array($v)) {
                continue;
            }
            foreach ($codeKeys as $ck => $_) {
                if (isset($v[$ck]) && !is_array($v[$ck]) && trim((string) $v[$ck]) !== '') {
                    return ['code' => trim((string) $v[$ck]), 'is_sku' => true];
                }
            }
        }
    }

    return ['code' => $fallback, 'is_sku' => false];
}

/**
 * The set of variant column keys that act as the product "code" (کد انبار).
 * Used by the preview/variant table so the code column is never duplicated as a
 * "تنوع" (variation) label. Always includes `sku`; adds any seller-built column
 * whose label means کد/انبار/SKU.
 */
function product_variant_code_keys($attrs)
{
    $keys = ['sku' => true];
    if (!empty($attrs['_variant_cols']) && function_exists('variant_normalize_columns')) {
        foreach (variant_normalize_columns($attrs['_variant_cols']) as $c) {
            $lbl = isset($c['label']) ? (string) $c['label'] : '';
            $isText = !isset($c['type']) || in_array($c['type'], ['text', 'number', 'url'], true);
            if ($isText && (stripos($lbl, 'کد') !== false || stripos($lbl, 'انبار') !== false
                || stripos($lbl, 'sku') !== false)) {
                $keys[$c['key']] = true;
            }
        }
    }
    return array_keys($keys);
}

/**
 * Collect per-variant images stored in the product attributes (variants[]).
 * Variant images live in attrs['variants'][i]['image'] (legacy) or in any
 * image-type column attrs['variants'][i][colKey][slot] (new builder). These are
 * stored as relative upload paths (uploads/products/...). Returns a list of
 * ['file_path' => relative, 'url' => public-or-relative] de-duplicated, capped.
 */
function product_variant_images($product, $limit = 5)
{
    global $domainhosts;
    $attrs = function_exists('product_attributes') ? product_attributes($product) : [];
    if (empty($attrs['variants']) || !is_array($attrs['variants'])) {
        return [];
    }

    // Which custom columns are image-type? (new 4-level layout stores under them)
    $imageCols = [];
    if (!empty($attrs['_variant_cols']) && function_exists('variant_normalize_columns')) {
        foreach (variant_normalize_columns($attrs['_variant_cols']) as $c) {
            if (($c['type'] ?? '') === 'image') {
                $imageCols[$c['key']] = true;
            }
        }
    }

    $collect = [];
    $pushPath = function ($p) use (&$collect) {
        $p = ltrim((string) $p, '/');
        if ($p !== '' && !in_array($p, $collect, true)) {
            $collect[] = $p;
        }
    };

    foreach ($attrs['variants'] as $v) {
        if (!is_array($v)) {
            continue;
        }
        // legacy single 'image'
        if (!empty($v['image']) && !is_array($v['image'])) {
            $pushPath($v['image']);
        }
        // new image columns: value is an array of slot => url (or a single url)
        foreach ($v as $ck => $cv) {
            if (!isset($imageCols[$ck])) {
                continue;
            }
            if (is_array($cv)) {
                foreach ($cv as $slot) {
                    if (!is_array($slot) && trim((string) $slot) !== '') {
                        $pushPath($slot);
                    }
                }
            } elseif (trim((string) $cv) !== '') {
                $pushPath($cv);
            }
        }
    }

    $out = [];
    foreach (array_slice($collect, 0, $limit) as $fp) {
        $url = (!empty($domainhosts))
            ? rtrim((strpos($domainhosts, 'http') === 0 ? $domainhosts : 'https://' . $domainhosts), '/') . '/' . $fp
            : $fp;
        $out[] = ['type' => 'image', 'ref' => $url, 'url' => $url, 'file_path' => $fp];
    }
    return $out;
}

/**
 * Count of "پس‌کد"s (post-codes) a product carries.
 *
 * Concept: one product row has a single کد (code), but when تنوع/ویژگی is
 * enabled each variant row behaves like a separate sellable item — i.e. one
 * code with several post-codes. This returns the number of variant rows; a
 * product without variants effectively has a single post-code (returns 1).
 */
function product_variant_count($product)
{
    $attrs = function_exists('product_attributes') ? product_attributes($product) : [];

    $hv = $attrs['has_variants'] ?? null;
    $hasVariantsOn = ($hv === '1' || $hv === 1 || $hv === true || $hv === 'on');

    if (!empty($attrs['variants']) && is_array($attrs['variants'])) {
        $count = 0;
        foreach ($attrs['variants'] as $v) {
            // Skip blank rows (no key carries a non-empty value).
            if (is_array($v)) {
                $hasValue = false;
                foreach ($v as $val) {
                    if (trim((string) $val) !== '') { $hasValue = true; break; }
                }
                if ($hasValue) { $count++; }
            }
        }
        if ($count > 0) {
            return $count;
        }
    }

    // No usable variant rows: a single product = a single post-code.
    return $hasVariantsOn ? 0 : 1;
}

/**
 * Whether stock tracking is meaningful for a product (physical with a numeric
 * stock attribute or variants that declare stock).
 */
function product_tracks_stock($product)
{
    if (!is_array($product)) {
        return false;
    }
    if (($product['product_type'] ?? '') !== 'physical') {
        return false;
    }
    $stock = product_attr($product, 'stock', null);
    if ($stock !== null && $stock !== '') {
        return true;
    }
    $variants = product_attr($product, 'variants', null);
    if (is_array($variants)) {
        foreach ($variants as $v) {
            if (isset($v['stock']) && $v['stock'] !== '') {
                return true;
            }
        }
    }
    return false;
}

/**
 * Current total stock for a physical product. If variants declare stock, the
 * total is the sum of variant stocks; otherwise the product-level stock.
 * Returns null when stock is not tracked (treated as unlimited).
 */
function product_stock($product)
{
    if (!product_tracks_stock($product)) {
        return null;
    }
    $variants = product_attr($product, 'variants', null);
    if (is_array($variants)) {
        $sum = 0;
        $any = false;
        foreach ($variants as $v) {
            if (isset($v['stock']) && $v['stock'] !== '') {
                $sum += max(0, (int) $v['stock']);
                $any = true;
            }
        }
        if ($any) {
            return $sum;
        }
    }
    $stock = product_attr($product, 'stock', null);
    return ($stock === null || $stock === '') ? null : max(0, (int) $stock);
}

/**
 * Decrement a product's stock by $qty after a successful sale.
 * If $variantKey is given (matches a variant SKU/color-size), that variant's
 * stock is reduced; otherwise the product-level stock is reduced.
 * No-op when stock is not tracked. Returns true if a write happened.
 */
function product_decrement_stock($product_id, $qty = 1, $variantKey = null)
{
    global $pdo;
    if (!isset($pdo) || $qty < 1) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM product WHERE id = ? LIMIT 1");
        $stmt->execute([(int) $product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("product_decrement_stock fetch error: " . $e->getMessage());
        return false;
    }
    if (!$product || !product_tracks_stock($product)) {
        return false;
    }
    $attrs    = product_attributes($product);
    $changed  = false;

    if ($variantKey !== null && !empty($attrs['variants']) && is_array($attrs['variants'])) {
        foreach ($attrs['variants'] as &$v) {
            $vk = product_variant_key($v);
            if ($vk === (string) $variantKey && isset($v['stock']) && $v['stock'] !== '') {
                $v['stock'] = max(0, (int) $v['stock'] - (int) $qty);
                $changed = true;
                break;
            }
        }
        unset($v);
    }

    if (!$changed && isset($attrs['stock']) && $attrs['stock'] !== '') {
        $attrs['stock'] = max(0, (int) $attrs['stock'] - (int) $qty);
        $changed = true;
    }

    if (!$changed) {
        return false;
    }
    try {
        $pdo->prepare("UPDATE product SET attributes = ? WHERE id = ?")
            ->execute([json_encode($attrs, JSON_UNESCAPED_UNICODE), (int) $product_id]);
        return true;
    } catch (Exception $e) {
        error_log("product_decrement_stock write error: " . $e->getMessage());
        return false;
    }
}

// ===========================================================================
// Customer addresses (step 8c) — structured postal addresses for physical
// product checkout. Separate table; never touches the VPN flow.
// ===========================================================================

/** List a user's saved addresses (default first, then newest). */
function address_list($user_id, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return [];
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM customer_address
             WHERE user_id = ? AND (bot_id = ? OR bot_id = 0)
             ORDER BY is_default DESC, id DESC"
        );
        $stmt->execute([(string) $user_id, (int) $bot_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("address_list error: " . $e->getMessage());
        return [];
    }
}

/** Fetch a single address row by id (optionally scoped to a user). */
function address_get($id, $user_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return null;
    }
    try {
        if ($user_id !== null) {
            $stmt = $pdo->prepare("SELECT * FROM customer_address WHERE id = ? AND user_id = ? LIMIT 1");
            $stmt->execute([(int) $id, (string) $user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM customer_address WHERE id = ? LIMIT 1");
            $stmt->execute([(int) $id]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        error_log("address_get error: " . $e->getMessage());
        return null;
    }
}

/** The user's default address (or first available), or null. */
function address_default($user_id, $bot_id = null)
{
    $list = address_list($user_id, $bot_id);
    return $list[0] ?? null;
}

/**
 * Save a new address. $data keys: full_name, phone, province, city,
 * postal_code, address. If $makeDefault, all other addresses are unset.
 * Returns the new address id or null.
 */
function address_save($user_id, array $data, $makeDefault = true, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return null;
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    try {
        if ($makeDefault) {
            $pdo->prepare("UPDATE customer_address SET is_default = 0 WHERE user_id = ? AND bot_id = ?")
                ->execute([(string) $user_id, (int) $bot_id]);
        }
        $stmt = $pdo->prepare(
            "INSERT INTO customer_address
             (user_id, bot_id, full_name, phone, province, city, postal_code, address, is_default, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())"
        );
        $stmt->execute([
            (string) $user_id,
            (int) $bot_id,
            (string) ($data['full_name'] ?? ''),
            (string) ($data['phone'] ?? ''),
            (string) ($data['province'] ?? ''),
            (string) ($data['city'] ?? ''),
            (string) ($data['postal_code'] ?? ''),
            (string) ($data['address'] ?? ''),
            $makeDefault ? 1 : 0,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("address_save error: " . $e->getMessage());
        return null;
    }
}

/** Set an address as the user's default. */
function address_set_default($id, $user_id, $bot_id = null)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    if ($bot_id === null) {
        $bot_id = defined('BOT_ID') ? (int) BOT_ID : 0;
    }
    try {
        $pdo->prepare("UPDATE customer_address SET is_default = 0 WHERE user_id = ? AND bot_id = ?")
            ->execute([(string) $user_id, (int) $bot_id]);
        $pdo->prepare("UPDATE customer_address SET is_default = 1 WHERE id = ? AND user_id = ?")
            ->execute([(int) $id, (string) $user_id]);
        return true;
    } catch (Exception $e) {
        error_log("address_set_default error: " . $e->getMessage());
        return false;
    }
}

/** Delete an address (scoped to the owner). */
function address_delete($id, $user_id)
{
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    try {
        $pdo->prepare("DELETE FROM customer_address WHERE id = ? AND user_id = ?")
            ->execute([(int) $id, (string) $user_id]);
        return true;
    } catch (Exception $e) {
        error_log("address_delete error: " . $e->getMessage());
        return false;
    }
}

/** Format an address row into a readable Persian block. */
function address_format($addr)
{
    if (!is_array($addr)) {
        return '';
    }
    $lines = [];
    if (!empty($addr['full_name'])) {
        $lines[] = "👤 " . htmlspecialchars((string) $addr['full_name']);
    }
    if (!empty($addr['phone'])) {
        $lines[] = "📱 " . htmlspecialchars((string) $addr['phone']);
    }
    $loc = trim(((string) ($addr['province'] ?? '')) . ' ' . ((string) ($addr['city'] ?? '')));
    if ($loc !== '') {
        $lines[] = "📍 " . htmlspecialchars($loc);
    }
    if (!empty($addr['address'])) {
        $lines[] = htmlspecialchars((string) $addr['address']);
    }
    if (!empty($addr['postal_code'])) {
        $lines[] = "🏷 کدپستی: <code>" . htmlspecialchars((string) $addr['postal_code']) . "</code>";
    }
    return implode("\n", $lines);
}

function generateAuthStr($length = 10)
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    return substr(str_shuffle(str_repeat($characters, ceil($length / strlen($characters)))), 0, $length);
}
function createqrcode($contents)
{
    $builder = new Builder(
        writer: new PngWriter(),
        writerOptions: [],
        data: $contents,
        encoding: new Encoding('UTF-8'),
        errorCorrectionLevel: ErrorCorrectionLevel::High,
        size: 500,
        margin: 10,
    );

    $result = $builder->build();
    return $result;
}
function sanitize_recursive(array $data): array
{
    $sanitized_data = [];
    foreach ($data as $key => $value) {
        $sanitized_key = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        if (is_array($value)) {
            $sanitized_data[$sanitized_key] = sanitize_recursive($value);
        } elseif (is_string($value)) {
            $sanitized_data[$sanitized_key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        } elseif (is_int($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        } elseif (is_float($value)) {
            $sanitized_data[$sanitized_key] = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        } elseif (is_bool($value) || is_null($value)) {
            $sanitized_data[$sanitized_key] = $value;
        } else {
            $sanitized_data[$sanitized_key] = $value;
        }
    }
    return $sanitized_data;
}

function check_active_btn($keyboard, $text_var)
{
    $trace_keyboard = json_decode($keyboard, true)['keyboard'];
    $status = false;
    foreach ($trace_keyboard as $key => $callback_set) {
        foreach ($callback_set as $keyboard_key => $keyboard) {
            if ($keyboard['text'] == $text_var) {
                $status = true;
                break;
            }
        }
    }
    return $status;
}
function deleteFolder($folderPath)
{
    if (!is_dir($folderPath))
        return false;

    $files = array_diff(scandir($folderPath), ['.', '..']);

    foreach ($files as $file) {
        $filePath = $folderPath . DIRECTORY_SEPARATOR . $file;
        if (is_dir($filePath)) {
            deleteFolder($filePath);
        } else {
            unlink($filePath);
        }
    }

    return rmdir($folderPath);
}
function isBase64($string)
{
    if (base64_encode(base64_decode($string, true)) === $string) {
        return true;
    }
    return false;
}
function sendMessageService($panel_info, $config, $sub_link, $username_service, $reply_markup, $caption, $invoice_id, $user_id = null, $image = 'images.jpg')
{
    global $setting, $from_id, $textbotlang;
    if (!check_active_btn($setting['keyboardmain'], "text_help"))
        $reply_markup = null;
    $user_id = $user_id == null ? $from_id : $user_id;
    $STATUS_SEND_MESSAGE_PHOTO = $panel_info['config'] == "onconfig" && count($config) != 1 ? false : true;
    $out_put_qrcode = "";
    if ($panel_info['type'] == "Manualsale" || $panel_info['type'] == "ibsng" || $panel_info['type'] == "mikrotik") {
    }
    if ($panel_info['sublink'] == "onsublink" && $panel_info['config']) {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['sublink'] == "onsublink") {
        $out_put_qrcode = $sub_link;
    } elseif ($panel_info['config'] == "onconfig") {
        $out_put_qrcode = $config[0];
    }
    if ($STATUS_SEND_MESSAGE_PHOTO) {
        if ($panel_info['type'] == "WGDashboard") {
            $urlimage = "{$panel_info['inboundid']}_{$invoice_id}.conf";
            file_put_contents($urlimage, $sub_link);
            telegram('senddocument', [
                'chat_id' => $user_id,
                'document' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => $caption,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        } else {
            $urlimage = "$user_id$invoice_id.png";
            $qrCode = createqrcode($out_put_qrcode);
            file_put_contents($urlimage, $qrCode->getString());
            addBackgroundImage($urlimage, $qrCode, $image);
            telegram('sendphoto', [
                'chat_id' => $user_id,
                'photo' => new CURLFile($urlimage),
                'reply_markup' => $reply_markup,
                'caption' => $caption,
                'parse_mode' => "HTML",
            ]);
            unlink($urlimage);
        }
    } else {
        sendmessage($user_id, $caption, $reply_markup, 'HTML');
    }
    if ($panel_info['config'] == "onconfig" && $setting['status_keyboard_config'] == "1") {
        if (is_array($config)) {
            sendmessage($user_id, $textbotlang['hardcoded']['getConfigHint'], keyboard_config($config, $invoice_id, false), 'HTML');
        }
    }
}
function isValidInvitationCode($setting, $fromId, $verfy_status)
{
    global $textbotlang;

    if ($setting['verifybucodeuser'] == "onverify" && $verfy_status != 1) {
        sendmessage($fromId, $textbotlang['hardcoded']['accountVerifiedSuccess'], null, 'html');
        update("user", "verify", "1", "id", $fromId);
        update("user", "cardpayment", "1", "id", $fromId);
    }
}
function createPayZarinpal($price, $order_id)
{
    global $domainhosts;
    $marchent_zarinpal = select("PaySetting", "ValuePay", "NamePay", "merchant_zarinpal", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.zarinpal.com/pg/v4/payment/request.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        "merchant_id" => $marchent_zarinpal,
        "currency" => "IRT",
        "amount" => $price,
        "callback_url" => "https://$domainhosts/payment/zarinpal.php",
        "description" => $order_id,
        "metadata" => array(
            "order_id" => $order_id
        )
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function createPayaqayepardakht($price, $order_id)
{
    global $domainhosts;
    $merchant_aqayepardakht = select("PaySetting", "ValuePay", "NamePay", "merchant_id_aqayepardakht", "select")['ValuePay'];
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://panel.aqayepardakht.ir/api/v2/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept: application/json'
        ),
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        'pin' => $merchant_aqayepardakht,
        'amount' => $price,
        'callback' => $domainhosts . "/payment/aqayepardakht.php",
        'invoice_id' => $order_id,
    ]));
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
function parseConfigs($input)
{
    $lines = explode("\n", $input);
    $configs = [];

    $currentName = null;
    $currentData = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, '#') === 0) {
            if ($currentName && $currentData) {
                $configs[] = [
                    'name' => $currentName,
                    'config' => implode("\n", $currentData)
                ];
            }
            $currentName = trim(substr($line, 1));
            $currentData = [];
        } else {
            if ($line !== '') {
                $currentData[] = $line;
            }
        }
    }
    if ($currentName && $currentData) {
        $configs[] = [
            'name' => $currentName,
            'config' => implode("\n", $currentData)
        ];
    }

    return $configs;
}