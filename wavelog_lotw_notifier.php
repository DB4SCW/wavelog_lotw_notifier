<?php

declare(strict_types=1);

//declare config paths
$configFile = __DIR__ . '/config.json';
$sampleConfigFile = __DIR__ . '/config.sample.json';

//check for existance of config
if (!file_exists($configFile)) {
    die(
        "Config file not found: {$configFile}" . PHP_EOL .
        "Please create a config file in the same folder as the wavelog_lotw_notifier.php script." . PHP_EOL .
        "You can use config.sample.json and fill out your information." . PHP_EOL .
        "You can find the sample file here: {$sampleConfigFile}" . PHP_EOL
    );
}

//load config
$configJson = file_get_contents($configFile);
$config = json_decode((string)$configJson, true);

//abort if config file is invalid
if (!is_array($config)) {
    die("Config-Datei ist kein gültiges JSON: {$configFile}" . PHP_EOL);
}

//helper to load config values
function cfg(array $config, string $path, mixed $default = null): mixed
{
    $parts = explode('.', $path);
    $value = $config;

    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }

        $value = $value[$part];
    }

    return $value;
}

//function to send notification to a discord webhook
function sendToDiscord(string $webhookUrl, string $message): bool
{
    
    //check existance of webhook URL
    if ($webhookUrl === '') {
        echo "Discord-Webhook-URL missing." . PHP_EOL;
        return false;
    }

    //construct the payload
    $payload = json_encode([
        'content' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    //abort if json encoding fails for whatever reason
    if ($payload === false) {
        echo "Discord-Payload konnte nicht als JSON kodiert werden." . PHP_EOL;
        return false;
    }

    //prepare curl
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    //send curl
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    //react to curl errors
    if (curl_errno($ch)) {
        echo "Discord cURL-error: " . curl_error($ch) . PHP_EOL;
        curl_close($ch);
        return false;
    }

    //handle http errors
    if ($httpCode < 200 || $httpCode >= 300) {
        echo "Discord-error ({$httpCode}): {$response}" . PHP_EOL;
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return true;
}

//function to send notification to a Telegram bot
function sendToTelegram(string $botToken, string $chatId, string $message): bool
{
    //check if bot token and chatid are present
    if ($botToken === '' || $chatId === '') {
        echo "Telegram bot_token or chat_id missing." . PHP_EOL;
        return false;
    }

    //construct URL
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

    //construct payload
    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $message,
        'disable_web_page_preview' => true
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    //abort if payload construction fails
    if ($payload === false) {
        echo "Telegram-Payload could not be constructed." . PHP_EOL;
        return false;
    }

    //prepare curl
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    //execute curl
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    //handle curl error
    if (curl_errno($ch)) {
        echo "Telegram cURL-error: " . curl_error($ch) . PHP_EOL;
        curl_close($ch);
        return false;
    }

    //handle http errors
    if ($httpCode < 200 || $httpCode >= 300) {
        echo "Telegram-error ({$httpCode}): {$response}" . PHP_EOL;
        curl_close($ch);
        return false;
    }

    //decode answer
    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        echo "Telegram-Antwort meldet Fehler: {$response}" . PHP_EOL;
        curl_close($ch);
        return false;
    }

    //return
    curl_close($ch);
    return true;
}

//function to send notification to a Gotify server
function sendToGotify(string $serverUrl, string $appToken, string $title, int $priority, string $message): bool
{
    //check if server URL and application token are present
    if ($serverUrl === '' || $appToken === '') {
        echo "Gotify server_url or app_token missing." . PHP_EOL;
        return false;
    }

    //construct URL and payload
    $url = rtrim($serverUrl, '/') . '/message';
    $payload = json_encode([
        'title' => $title,
        'message' => $message,
        'priority' => $priority
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    //abort if payload construction fails
    if ($payload === false) {
        echo "Gotify-Payload could not be constructed." . PHP_EOL;
        return false;
    }

    //prepare curl
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
            'X-Gotify-Key: ' . $appToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    //execute curl
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    //handle curl error
    if (curl_errno($ch)) {
        echo "Gotify cURL-error: " . curl_error($ch) . PHP_EOL;
        curl_close($ch);
        return false;
    }

    //handle http errors
    if ($httpCode < 200 || $httpCode >= 300) {
        echo "Gotify-error ({$httpCode}): {$response}" . PHP_EOL;
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return true;
}

//unified notifier-function
function sendNotification(array $config, string $message, bool $withDiscordMention = false): bool
{
    
    //check feature status
    $discordEnabled = (bool) cfg($config, 'notifications.discord.enabled', false);
    $telegramEnabled = (bool) cfg($config, 'notifications.telegram.enabled', false);
    $gotifyEnabled = (bool) cfg($config, 'notifications.gotify.enabled', false);

    //abort if no channel is enabled
    if (!$discordEnabled && !$telegramEnabled && !$gotifyEnabled) {
        echo "No notification channel activated. We will not send any message." . PHP_EOL;
        return false;
    }

    //assume success
    $success = true;

    //load relevant data and trigger discord message if enabled
    if ($discordEnabled) {
        $discordWebhookUrl = (string) cfg($config, 'notifications.discord.webhook_url', '');

        $discordMention = $withDiscordMention
            ? (string) cfg($config, 'notifications.discord.mention', '')
            : '';

        $discordMessage = trim($discordMention . ' ' . $message);

        $discordSuccess = sendToDiscord($discordWebhookUrl, $discordMessage);
        $success = $success && $discordSuccess;
    }

    //load relevant data and trigger telegram message if enabled
    if ($telegramEnabled) {
        $telegramBotToken = (string) cfg($config, 'notifications.telegram.bot_token', '');
        $telegramChatId = (string) cfg($config, 'notifications.telegram.chat_id', '');

        $telegramSuccess = sendToTelegram($telegramBotToken, $telegramChatId, $message);
        $success = $success && $telegramSuccess;
    }

    //load relevant data and trigger gotify message if enabled
    if ($gotifyEnabled) {
        
        $gotifyServerUrl = (string) cfg($config, 'notifications.gotify.server_url', '');
        $gotifyAppToken = (string) cfg($config, 'notifications.gotify.app_token', '');
        $gotifyTitle = (string) cfg($config, 'notifications.gotify.title', 'Wavelog LotW Notifier');
        $gotifyPriority = (int) cfg($config, 'notifications.gotify.priority', 5);

        $gotifySuccess = sendToGotify(
            $gotifyServerUrl,
            $gotifyAppToken,
            $gotifyTitle,
            $gotifyPriority,
            $message
        );
        
        $success = $success && $gotifySuccess;
    }

    //return combined success
    return $success;
}

//helper to ensure database modification for individual notification feature
function ensureNotifyLotwColumnExists(PDO $pdo, string $dbName): bool
{
    //define check SQL
    $checkSql = "
        SELECT COUNT(*) AS column_count
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :table_schema
          AND TABLE_NAME = 'TABLE_HRD_CONTACTS_V01'
          AND COLUMN_NAME = 'db4scw_notifylotw'
    ";

    //prepare statement and execute
    $stmt = $pdo->prepare($checkSql);
    $stmt->execute([
        'table_schema' => $dbName
    ]);

    //check if column exists
    $exists = ((int)$stmt->fetchColumn()) > 0;

    //just stop here if it does
    if ($exists) {
        return true;
    }

    //if we are here, inform the user we need to alter the table...
    echo "Column db4scw_notifylotw does not exist. Creating column..." . PHP_EOL;

    //define SQL to alter table
    $alterSql = "
        ALTER TABLE TABLE_HRD_CONTACTS_V01
        ADD COLUMN db4scw_notifylotw TINYINT NULL DEFAULT NULL
    ";

    //execute. 
    try {
        $pdo->exec($alterSql);
    } catch (\Throwable $th) {
        return false;
    }
    
    //inform the user about the success
    echo "Column db4scw_notifylotw was created." . PHP_EOL;

    //return success
    return true;
}

//load DB config
$dbHost = (string) cfg($config, 'database.host', '');
$dbName = (string) cfg($config, 'database.name', '');
$dbUser = (string) cfg($config, 'database.user', '');
$dbPass = (string) cfg($config, 'database.pass', '');
$dbCharset = (string) cfg($config, 'database.charset', 'utf8mb4');

//load logbook
$stationLogbookId = (int) cfg($config, 'logbook.station_logbook_id', 13);

//load statefile name or use default
$stateFileName = (string) cfg($config, 'runtime.state_file', 'lotw_resultset_count.state');
$stateFile = __DIR__ . '/' . basename($stateFileName);

//load senddelay
$sendDelayMicroseconds = (int) cfg($config, 'runtime.send_delay_microseconds', 300000);

//check feature for individual notification per QSO
$notifyLotwMarkedQsosEnabled = (bool) cfg($config, 'features.notify_lotw_marked_qsos', true);

//define DB connection
$dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";

//try DB connection, abort if unable
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("DB-connection failed: " . $e->getMessage() . PHP_EOL);
}

//if individual notification is enabled, check if the needed column exists
if ($notifyLotwMarkedQsosEnabled) {
    
    //run ensurance
    try {
        $ensurance_result = ensureNotifyLotwColumnExists($pdo, $dbName);
    } catch (PDOException $e) {
        die("Error while creating column for individual notification: " . $e->getMessage() . PHP_EOL);
    }

    //abort if ensurance is not successful
    if (!$ensurance_result) {
        die("Error while creating column for individual notification." . PHP_EOL);
    }

    //define SQL to get new LotW confirmations for individual QSOs
    $sqlNotifyLotw = "
        SELECT
            COL_PRIMARY_KEY,
            COL_TIME_ON,
            COL_LOTW_QSLRDATE,
            COL_CALL,
            COL_MODE,
            COL_SUBMODE,
            COL_BAND,
            COL_SAT_NAME,
            COL_COMMENT,
            col_lotw_qsl_rcvd
        FROM TABLE_HRD_CONTACTS_V01
        WHERE db4scw_notifylotw = 1
          AND station_id IN (
                SELECT station_logbooks_relationship.station_location_id
                FROM station_logbooks_relationship
                WHERE station_logbooks_relationship.station_logbook_id = :station_logbook_id
            )
          AND col_lotw_qsl_rcvd = 'Y'
    ";

    //try running the query
    try {
        $stmtNotify = $pdo->prepare($sqlNotifyLotw);
        $stmtNotify->execute([
            'station_logbook_id' => $stationLogbookId
        ]);
        $notifyRows = $stmtNotify->fetchAll();
    } catch (PDOException $e) {
        die("SQL-error while getting Notification list for individual QSOs: " . $e->getMessage() . PHP_EOL);
    }

    //if todo list is not empty. run notifications
    if (!empty($notifyRows)) {
        
        //inform the user
        echo "Found newly confirmed QSOs. Running notifications for " . count($notifyRows) . " QSO(s)." . PHP_EOL;

        //define update SQL to remove notify flag
        $updateNotifySql = "
            UPDATE TABLE_HRD_CONTACTS_V01
            SET db4scw_notifylotw = NULL
            WHERE COL_PRIMARY_KEY = :primary_key
        ";

        //prepare statement
        $updateNotifyStmt = $pdo->prepare($updateNotifySql);

        //run through each row
        foreach ($notifyRows as $row) {
            
            //load data in defined format
            $lotwDate = !empty($row['COL_LOTW_QSLRDATE'])
                ? (new DateTime($row['COL_LOTW_QSLRDATE']))->format('Y-m-d H:i')
                : '';

            $qsoDate = !empty($row['COL_TIME_ON'])
                ? (new DateTime($row['COL_TIME_ON']))->format('Y-m-d H:i')
                : '';

            $modus = empty($row['COL_SUBMODE'])
                ? ($row['COL_MODE'] ?? '')
                : (($row['COL_MODE'] ?? '') . ' -> ' . ($row['COL_SUBMODE'] ?? ''));

            $comment = empty($row['COL_COMMENT'])
                ? ''
                : ("\nComment: " . $row['COL_COMMENT']);

            //build message using this data
            $message = sprintf(
                "LotW-Notification for marked QSO:\nCall: %s, Mode: %s, Band: %s, Time: %s\nConfirmed at: %s.%s",
                $row['COL_CALL'] ?? '',
                $modus,
                $row['COL_BAND'] ?? '',
                $qsoDate,
                $lotwDate,
                $comment
            );

            //try to send message
            $notification_result = sendNotification($config, $message);
            
            //react to notification result
            if ($notification_result) {
                
                //inform user for success
                echo "NotifyLotW sent für QSO ID {$row['COL_PRIMARY_KEY']}" . PHP_EOL;

                //try removing the notification flag
                try {
                    $updateNotifyStmt->execute([
                        'primary_key' => $row['COL_PRIMARY_KEY']
                    ]);

                    echo "Removed notification flag for QSO ID {$row['COL_PRIMARY_KEY']}" . PHP_EOL;
                } catch (PDOException $e) {
                    echo "Error while removing notification flag for QSO ID {$row['COL_PRIMARY_KEY']}: " . $e->getMessage() . PHP_EOL;
                }
            } else {
                echo "Error while sending notification for QSO ID {$row['COL_PRIMARY_KEY']}, Flag bleibt gesetzt." . PHP_EOL;
            }

            //delay as configured
            usleep($sendDelayMicroseconds);
        }
    }
}

//base logic for new DXCC confirmations
$sql = "
    SELECT * FROM (
        SELECT 
            COL_TIME_ON AS date,
            COL_LOTW_QSLRDATE AS lotwdate,
            IF(COL_SUBMODE IS NULL, COL_MODE, CONCAT(COL_MODE, ' -> ', COL_SUBMODE)) AS modus,
            COL_BAND,
            COL_CALL,
            prefix,
            dxcc_entities.name AS dxcc_name,
            end,
            adif,
            COL_SAT_NAME AS sat_name,
            ROW_NUMBER() OVER (PARTITION BY adif ORDER BY COL_LOTW_QSLRDATE ASC) AS rn
        FROM TABLE_HRD_CONTACTS_V01 thcv
        JOIN dxcc_entities ON thcv.col_dxcc = dxcc_entities.adif
        WHERE station_id IN (
            SELECT station_logbooks_relationship.station_location_id
            FROM station_logbooks_relationship
            WHERE station_logbooks_relationship.station_logbook_id = :station_logbook_id
        )
        AND col_dxcc > 0
        AND (col_lotw_qsl_rcvd = 'Y' OR 1=0)
    ) ranked
    WHERE rn = 1
    ORDER BY lotwdate DESC;
";

//try getting new notifications
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'station_logbook_id' => $stationLogbookId
    ]);

    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    die("SQL-error: " . $e->getMessage() . PHP_EOL);
}

//abort if no results
if (empty($rows)) {
    exit("No DXCCs found. Go and press the PTT!" . PHP_EOL);
}

//check if the resultset got bigger since last time by counting rows
$currentCount = count($rows);
$lastCount = 0;

//if statefile exist, load content
if (file_exists($stateFile)) {
    $content = trim((string) file_get_contents($stateFile));

    if (is_numeric($content)) {
        $lastCount = (int) $content;
    }
}

//on first run, just save current state and don't run any notifications
if ($lastCount === 0 && !file_exists($stateFile)) {
    file_put_contents($stateFile, (string)$currentCount, LOCK_EX);
    exit("First run detected. {$currentCount} DXCCs detected, saving state. Nothing sent!" . PHP_EOL);
}

//if it didn't get bigger, just abort here
if ($currentCount <= $lastCount) {
    file_put_contents($stateFile, (string)$currentCount, LOCK_EX);
    exit("DXCC count didn't get bigger ({$lastCount} -> {$currentCount}). Stopping." . PHP_EOL);
}

//if we are here, it got bigger. Congratulations. Let's send notifications!
//get only new rows
$newCount = $currentCount - $lastCount;
$newRows = array_slice($rows, 0, $newCount);

//send in the right order
$newRows = array_reverse($newRows);

//inform user
echo "New DXCC confirmations detected: {$newCount}" . PHP_EOL;

//send notification for each new row
foreach ($newRows as $row) {
    
    //load data in defined format
    $lotwDate = !empty($row['lotwdate'])
        ? (new DateTime($row['lotwdate']))->format('Y-m-d H:i')
        : '';

    $qsoDate = !empty($row['date'])
        ? (new DateTime($row['date']))->format('Y-m-d H:i')
        : '';

    //costruct message
    $message = sprintf(
        "New LotW confirmation for DXCC: %s\nCall: %s, Mode: %s, Band: %s, Time: %s\nConfirmed at: %s.",
        $row['dxcc_name'] ?? '',
        $row['COL_CALL'] ?? '',
        $row['modus'] ?? '',
        $row['COL_BAND'] ?? '',
        $qsoDate,
        $lotwDate
    );

    //try sending notification
    $notification_result = sendNotification($config, $message);
    
    //react to result of this notification
    if ($notification_result) {
        echo "Sent: {$message}" . PHP_EOL;
    } else {
        echo "Notification failed: {$message}" . PHP_EOL;
    }

    //delay
    usleep($sendDelayMicroseconds);
}

//if DXCC count got bigger, send final count
if ($newCount > 0) {
    
    //construct message
    $message = sprintf(
        "New amount of DXCCs with LotW confirmation: %d",
        $currentCount
    );

    //try sending notification
    $notification_result = sendNotification($config, $message, true);

    //react to result of this notification
    if ($notification_result) {
        echo "Sent: {$message}" . PHP_EOL;
    } else {
        echo "Notification failed: {$message}" . PHP_EOL;
    }
}

//save new DXCC count
file_put_contents($stateFile, (string)$currentCount, LOCK_EX);

?>