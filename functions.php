<?php

/*
 * Sharan Verkaufszentrale - Funktions-Bootstrap
 * PHP 5.6 kompatibel
 */

require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/ai.php';
require_once __DIR__ . '/lib/gmail.php';
require_once __DIR__ . '/lib/state.php';
require_once __DIR__ . '/lib/update.php';


/*
 * =========================================================
 * WhatsApp-Benachrichtigungen über ioBroker REST-API
 * -> sendTo('open-wa.0', 'send', {to: '...', text: '...'})
 * =========================================================
 */

function getMobileNotifyStatePath()
{
    return __DIR__ . '/data/notify-state.json';
}


function loadMobileNotifyState()
{
    $default = array(
        'initialized' => false,
        'seen' => array(),
        'last_run' => '',
        'last_success' => '',
        'last_error' => ''
    );

    $file = getMobileNotifyStatePath();

    if (!file_exists($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) == '') {
        return $default;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $default;
    }

    foreach ($default as $key => $value) {
        if (!isset($data[$key])) {
            $data[$key] = $value;
        }
    }

    if (!is_array($data['seen'])) {
        $data['seen'] = array();
    }

    return $data;
}


function saveMobileNotifyState($state)
{
    $file = getMobileNotifyStatePath();
    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    /* Nur die letzten 500 bekannten Nachrichten merken. */
    if (isset($state['seen']) && is_array($state['seen']) && count($state['seen']) > 500) {
        arsort($state['seen'], SORT_NUMERIC);
        $state['seen'] = array_slice($state['seen'], 0, 500, true);
    }

    $json = json_encode(
        $state,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return false;
    }

    return @file_put_contents($file, $json, LOCK_EX) !== false;
}


function getMobileNotifyMessageKey($conversation, $message)
{
    if (isset($message['message_id']) && trim($message['message_id']) != '') {
        return sha1('mid|' . trim($message['message_id']));
    }

    $email = isset($conversation['email']) ? $conversation['email'] : '';
    $timestamp = isset($message['timestamp']) ? intval($message['timestamp']) : 0;
    $text = isset($message['text']) ? $message['text'] : '';

    return sha1('fallback|' . $email . '|' . $timestamp . '|' . $text);
}


function isWhatsAppNotifyEnabled($config)
{
    if (!is_array($config)) {
        return false;
    }

    if (!isset($config['whatsapp_notify_enabled'])) {
        return false;
    }

    $value = $config['whatsapp_notify_enabled'];

    return $value === true || $value === 1 || $value === '1' || strtolower(trim(strval($value))) === 'true';
}


function ioBrokerRestSendOpenWa($config, $to, $text)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'http_code' => 0,
        'response' => ''
    );

    if (!isset($config['iobroker_rest_url']) || trim($config['iobroker_rest_url']) == '') {
        $result['error'] = 'iobroker_rest_url fehlt.';
        return $result;
    }

    if (trim($to) == '') {
        $result['error'] = 'whatsapp_to fehlt.';
        return $result;
    }

    $url = rtrim(trim($config['iobroker_rest_url']), '/') . '/v1/command/sendTo';

    $payload = array(
        'adapterInstance' => isset($config['openwa_adapter']) && trim($config['openwa_adapter']) != ''
            ? trim($config['openwa_adapter'])
            : 'open-wa.0',
        'command' => 'send',
        'message' => array(
            'to' => trim($to),
            'text' => $text
        )
    );

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        $result['error'] = 'WhatsApp-Payload konnte nicht als JSON erzeugt werden.';
        return $result;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

    if (
        isset($config['iobroker_rest_bearer_token']) &&
        trim($config['iobroker_rest_bearer_token']) != ''
    ) {
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . trim($config['iobroker_rest_bearer_token'])
            )
        );
    } elseif (
        isset($config['iobroker_rest_user']) &&
        trim($config['iobroker_rest_user']) != ''
    ) {
        $user = trim($config['iobroker_rest_user']);
        $pass = isset($config['iobroker_rest_password']) ? $config['iobroker_rest_password'] : '';
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    }

    $body = curl_exec($ch);

    if ($body === false) {
        $result['error'] = 'ioBroker REST nicht erreichbar: ' . curl_error($ch);
        curl_close($ch);
        return $result;
    }

    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    $result['http_code'] = $httpCode;
    $result['response'] = $body;

    if ($httpCode < 200 || $httpCode >= 300) {
        $result['error'] = 'ioBroker REST HTTP ' . $httpCode . ': ' . trim($body);
        return $result;
    }

    $decoded = json_decode($body, true);
    if (is_array($decoded) && isset($decoded['error']) && trim(strval($decoded['error'])) != '') {
        $result['error'] = 'ioBroker: ' . trim(strval($decoded['error']));
        return $result;
    }

    $result['ok'] = true;
    return $result;
}


function shortenWhatsAppText($text, $maxLength)
{
    $text = trim($text);
    $maxLength = intval($maxLength);

    if ($maxLength < 100) {
        $maxLength = 100;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 1, 'UTF-8')) . '…';
    }

    if (strlen($text) <= $maxLength) {
        return $text;
    }

    return rtrim(substr($text, 0, $maxLength - 3)) . '...';
}


function buildMobileWhatsAppNotification($config, $conversation, $message)
{
    $name = isset($conversation['name']) && trim($conversation['name']) != ''
        ? trim($conversation['name'])
        : 'Interessent';

    $date = isset($message['date']) ? $message['date'] : date('d.m.Y H:i');
    $text = isset($message['text']) ? trim($message['text']) : '';

    $maxChars = 900;
    if (isset($config['whatsapp_notify_message_max_chars']) && intval($config['whatsapp_notify_message_max_chars']) > 100) {
        $maxChars = intval($config['whatsapp_notify_message_max_chars']);
    }

    $text = shortenWhatsAppText($text, $maxChars);

    $notification = "🚗 *Neue mobile.de-Nachricht*\n\n";
    $notification .= "Von: " . $name . "\n";
    $notification .= "Zeit: " . $date . "\n\n";
    $notification .= $text;

    if (isset($config['mobile_web_url']) && trim($config['mobile_web_url']) != '') {
        $notification .= "\n\nVerkaufszentrale:\n" . rtrim(trim($config['mobile_web_url']), '/');
    }

    return $notification;
}


function collectMobileMessagesForNotify($conversations)
{
    $items = array();

    if (!is_array($conversations)) {
        return $items;
    }

    foreach ($conversations as $conversation) {
        if (!isset($conversation['messages']) || !is_array($conversation['messages'])) {
            continue;
        }

        foreach ($conversation['messages'] as $message) {
            $items[] = array(
                'conversation' => $conversation,
                'message' => $message,
                'timestamp' => isset($message['timestamp']) ? intval($message['timestamp']) : 0
            );
        }
    }

    usort($items, 'sortMobileNotifyItemsAscending');
    return $items;
}


function sortMobileNotifyItemsAscending($a, $b)
{
    if ($a['timestamp'] == $b['timestamp']) {
        return 0;
    }

    return ($a['timestamp'] < $b['timestamp']) ? -1 : 1;
}


function processMobileWhatsAppNotifications($config, $conversations, $testMode)
{
    $result = array(
        'ok' => true,
        'enabled' => isWhatsAppNotifyEnabled($config),
        'initialized' => false,
        'baseline_count' => 0,
        'new_count' => 0,
        'sent_count' => 0,
        'error' => ''
    );

    if (!$result['enabled']) {
        return $result;
    }

    if (!isset($config['whatsapp_to']) || trim($config['whatsapp_to']) == '') {
        $result['ok'] = false;
        $result['error'] = 'whatsapp_to fehlt.';
        return $result;
    }

    if ($testMode) {
        $testText = "🚗 *mobile.de Verkaufszentrale*\n\nWhatsApp-Test erfolgreich ausgelöst.\nZeit: " . date('d.m.Y H:i:s');
        $send = ioBrokerRestSendOpenWa($config, $config['whatsapp_to'], $testText);

        if (!$send['ok']) {
            $result['ok'] = false;
            $result['error'] = $send['error'];
        } else {
            $result['sent_count'] = 1;
        }

        return $result;
    }

    $state = loadMobileNotifyState();
    $items = collectMobileMessagesForNotify($conversations);
    $state['last_run'] = date('Y-m-d H:i:s');

    /*
     * Beim allerersten Lauf werden vorhandene alte Mails nur als Basis gespeichert.
     * Dadurch kommen nach der Installation nicht plötzlich 20 alte WhatsApps.
     */
    if (empty($state['initialized'])) {
        foreach ($items as $item) {
            $key = getMobileNotifyMessageKey($item['conversation'], $item['message']);
            $state['seen'][$key] = $item['timestamp'];
            $result['baseline_count']++;
        }

        $state['initialized'] = true;
        $state['last_error'] = '';
        saveMobileNotifyState($state);

        $result['initialized'] = true;
        return $result;
    }

    $result['initialized'] = true;

    foreach ($items as $item) {
        $key = getMobileNotifyMessageKey($item['conversation'], $item['message']);

        if (isset($state['seen'][$key])) {
            continue;
        }

        $result['new_count']++;

        $text = buildMobileWhatsAppNotification(
            $config,
            $item['conversation'],
            $item['message']
        );

        $send = ioBrokerRestSendOpenWa(
            $config,
            $config['whatsapp_to'],
            $text
        );

        if (!$send['ok']) {
            $result['ok'] = false;
            $result['error'] = $send['error'];
            $state['last_error'] = $send['error'];
            saveMobileNotifyState($state);
            break;
        }

        /* Erst NACH erfolgreichem Versand als erledigt merken. */
        $state['seen'][$key] = $item['timestamp'];
        $state['last_success'] = date('Y-m-d H:i:s');
        $state['last_error'] = '';
        $result['sent_count']++;
        saveMobileNotifyState($state);
    }

    saveMobileNotifyState($state);
    return $result;
}


/*
 * =========================================================
 * Standalone-Aufruf für Synology Aufgabenplaner
 *
 * Beispiel:
 *   /mobile/functions.php?token=GEHEIMNIS
 *   /mobile/functions.php?token=GEHEIMNIS&test=1
 * =========================================================
 */

if (
    isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    header('Content-Type: application/json; charset=utf-8');

    $configFile = __DIR__ . '/_private/config.php';

    if (!file_exists($configFile)) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => 'config.php fehlt.'));
        exit;
    }

    require $configFile;

    $isCli = php_sapi_name() === 'cli';
    $expectedToken = isset($config['notify_cron_token']) ? trim($config['notify_cron_token']) : '';
    $providedToken = isset($_GET['token']) ? trim($_GET['token']) : '';

    if (isset($_SERVER['HTTP_X_MOBILE_TOKEN']) && trim($_SERVER['HTTP_X_MOBILE_TOKEN']) != '') {
        $providedToken = trim($_SERVER['HTTP_X_MOBILE_TOKEN']);
    }

    if (!$isCli) {
        if ($expectedToken == '') {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'notify_cron_token ist nicht konfiguriert.'));
            exit;
        }

        if (!function_exists('hash_equals')) {
            $tokenOk = $providedToken === $expectedToken;
        } else {
            $tokenOk = hash_equals($expectedToken, $providedToken);
        }

        if (!$tokenOk) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'Ungültiger Token.'));
            exit;
        }
    }

    $mail = loadMobileConversations($config);

    if (!$mail['ok']) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => 'Gmail: ' . $mail['error']));
        exit;
    }

    $testMode = isset($_GET['test']) && $_GET['test'] == '1';

    $notify = processMobileWhatsAppNotifications(
        $config,
        $mail['conversations'],
        $testMode
    );

    if (!$notify['ok']) {
        http_response_code(500);
    }

    echo json_encode(
        $notify,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
