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
 * WhatsApp-Benachrichtigungen über ioBroker
 *
 * Unterstützte Wege:
 * 1) simple-api (z. B. Port 8087):
 *    PHP schreibt JSON direkt in mqtt.0.whatsapp.outgoing.
 *    Die vorhandene MQTT-/WhatsApp-Bridge übernimmt den Versand.
 *
 * 2) rest-api:
 *    PHP ruft /v1/command/sendTo direkt auf.
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
        'last_error' => '',
        'last_test' => '',
        'last_test_error' => '',
        'last_update_check' => '',
        'latest_version' => '',
        'update_available' => false,
        'last_update_notified_version' => '',
        'last_update_notification' => '',
        'update_error' => ''
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
    if (!is_array($config) || !isset($config['whatsapp_notify_enabled'])) {
        return false;
    }

    $value = $config['whatsapp_notify_enabled'];

    return $value === true ||
        $value === 1 ||
        $value === '1' ||
        strtolower(trim(strval($value))) === 'true';
}


function isWhatsAppUpdateNotifyEnabled($config)
{
    if (!isset($config['whatsapp_notify_updates'])) {
        return true;
    }

    $value = $config['whatsapp_notify_updates'];

    if ($value === false || $value === 0 || $value === '0') {
        return false;
    }

    $text = strtolower(trim(strval($value)));
    return $text !== 'false' && $text !== 'no' && $text !== 'off';
}


function normalizeWhatsAppRecipient($to)
{
    $to = trim($to);

    if ($to == '') {
        return '';
    }

    /* Bereits vollständige WhatsApp-/Gruppen-ID unverändert lassen. */
    if (strpos($to, '@') !== false) {
        return $to;
    }

    /* Telefonnummern in das von der Bridge erwartete Format 49...@c.us bringen. */
    $number = preg_replace('/[^0-9]/', '', $to);

    if ($number == '') {
        return $to;
    }

    if (substr($number, 0, 2) == '00') {
        $number = substr($number, 2);
    }

    if (substr($number, 0, 1) == '0') {
        $number = '49' . substr($number, 1);
    }

    return $number . '@c.us';
}


function getIoBrokerApiMode($config)
{
    if (isset($config['iobroker_api_mode']) && trim($config['iobroker_api_mode']) != '') {
        $mode = strtolower(trim($config['iobroker_api_mode']));

        if ($mode == 'simple-api' || $mode == 'simple_api' || $mode == 'simple') {
            return 'simple-api';
        }

        if ($mode == 'rest-api' || $mode == 'rest_api' || $mode == 'rest') {
            return 'rest-api';
        }
    }

    if (isset($config['iobroker_rest_url'])) {
        $url = trim($config['iobroker_rest_url']);
        $parts = @parse_url($url);

        if (is_array($parts) && isset($parts['port']) && intval($parts['port']) == 8087) {
            return 'simple-api';
        }
    }

    return 'rest-api';
}


function applyIoBrokerCurlAuthentication($ch, $config, $headers)
{
    if (
        isset($config['iobroker_rest_bearer_token']) &&
        trim($config['iobroker_rest_bearer_token']) != ''
    ) {
        $headers[] = 'Authorization: Bearer ' . trim($config['iobroker_rest_bearer_token']);
    } elseif (
        isset($config['iobroker_rest_user']) &&
        trim($config['iobroker_rest_user']) != ''
    ) {
        $user = trim($config['iobroker_rest_user']);
        $pass = isset($config['iobroker_rest_password']) ? $config['iobroker_rest_password'] : '';

        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
}


function ioBrokerSimpleApiQueueOpenWa($config, $to, $text)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'http_code' => 0,
        'response' => '',
        'mode' => 'simple-api'
    );

    if (!isset($config['iobroker_rest_url']) || trim($config['iobroker_rest_url']) == '') {
        $result['error'] = 'iobroker_rest_url fehlt.';
        return $result;
    }

    /* Bestehender MQTT-Ausgang der WhatsApp-Bridge. */
    $stateId = 'mqtt.0.whatsapp.outgoing';
    if (
        isset($config['iobroker_simple_api_state']) &&
        trim($config['iobroker_simple_api_state']) != ''
    ) {
        $stateId = trim($config['iobroker_simple_api_state']);
    }

    $baseUrl = rtrim(trim($config['iobroker_rest_url']), '/');
    $url = $baseUrl . '/setValueFromBody/' . rawurlencode($stateId);

    /* Genau das minimale JSON, das die vorhandene MQTT-WhatsApp-Bridge benötigt. */
    $payload = array(
        'to' => normalizeWhatsAppRecipient($to),
        'text' => $text
    );

    $bodyData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($bodyData === false) {
        $result['error'] = 'WhatsApp-Payload konnte nicht als JSON erzeugt werden.';
        return $result;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyData);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    applyIoBrokerCurlAuthentication(
        $ch,
        $config,
        array('Content-Type: text/plain; charset=utf-8')
    );

    $body = curl_exec($ch);

    if ($body === false) {
        $result['error'] = 'ioBroker simple-api nicht erreichbar: ' . curl_error($ch);
        curl_close($ch);
        return $result;
    }

    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    $result['http_code'] = $httpCode;
    $result['response'] = $body;

    if ($httpCode < 200 || $httpCode >= 300) {
        $result['error'] = 'ioBroker simple-api HTTP ' . $httpCode . ': ' . trim($body);
        return $result;
    }

    $result['ok'] = true;
    return $result;
}


function ioBrokerRestApiSendOpenWa($config, $to, $text)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'http_code' => 0,
        'response' => '',
        'mode' => 'rest-api'
    );

    if (!isset($config['iobroker_rest_url']) || trim($config['iobroker_rest_url']) == '') {
        $result['error'] = 'iobroker_rest_url fehlt.';
        return $result;
    }

    $url = rtrim(trim($config['iobroker_rest_url']), '/') . '/v1/command/sendTo';

    $payload = array(
        'adapterInstance' => isset($config['openwa_adapter']) && trim($config['openwa_adapter']) != ''
            ? trim($config['openwa_adapter'])
            : 'open-wa.0',
        'command' => 'send',
        'message' => array(
            'to' => normalizeWhatsAppRecipient($to),
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

    applyIoBrokerCurlAuthentication(
        $ch,
        $config,
        array('Content-Type: application/json')
    );

    $body = curl_exec($ch);

    if ($body === false) {
        $result['error'] = 'ioBroker REST-API nicht erreichbar: ' . curl_error($ch);
        curl_close($ch);
        return $result;
    }

    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    $result['http_code'] = $httpCode;
    $result['response'] = $body;

    if ($httpCode < 200 || $httpCode >= 300) {
        $result['error'] = 'ioBroker REST-API HTTP ' . $httpCode . ': ' . trim($body);
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


function ioBrokerSendOpenWa($config, $to, $text)
{
    if (trim($to) == '') {
        return array(
            'ok' => false,
            'error' => 'whatsapp_to fehlt.',
            'http_code' => 0,
            'response' => '',
            'mode' => getIoBrokerApiMode($config)
        );
    }

    $mode = getIoBrokerApiMode($config);

    if ($mode == 'simple-api') {
        return ioBrokerSimpleApiQueueOpenWa($config, $to, $text);
    }

    return ioBrokerRestApiSendOpenWa($config, $to, $text);
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


function buildMobileConversationUrl($config, $conversation)
{
    if (!isset($config['mobile_web_url']) || trim($config['mobile_web_url']) == '') {
        return '';
    }

    $base = rtrim(trim($config['mobile_web_url']), '/');
    $key = isset($conversation['key']) ? trim($conversation['key']) : '';

    if ($key == '') {
        return $base . '/';
    }

    return $base . '/index.php?conversation=' . rawurlencode($key) . '#c-' . rawurlencode($key);
}


function buildMobileWhatsAppNotification($config, $conversation, $message)
{
    $name = isset($conversation['name']) && trim($conversation['name']) != ''
        ? trim($conversation['name'])
        : 'Interessent';

    $date = isset($message['date']) ? $message['date'] : date('d.m.Y H:i');
    $text = isset($message['text']) ? trim($message['text']) : '';
    $subject = isset($message['subject']) ? trim($message['subject']) : '';

    $maxChars = 900;
    if (
        isset($config['whatsapp_notify_message_max_chars']) &&
        intval($config['whatsapp_notify_message_max_chars']) > 100
    ) {
        $maxChars = intval($config['whatsapp_notify_message_max_chars']);
    }

    $text = shortenWhatsAppText($text, $maxChars);

    $notification = "🚗 *Neue mobile.de-Nachricht*\n\n";
    $notification .= "Von: *" . $name . "*\n";
    $notification .= "Zeit: " . $date . "\n";

    if ($subject != '') {
        $notification .= "Betreff: " . $subject . "\n";
    }

    $notification .= "\n" . $text;

    $conversationUrl = buildMobileConversationUrl($config, $conversation);
    if ($conversationUrl != '') {
        $notification .= "\n\n👉 *Unterhaltung öffnen:*\n" . $conversationUrl;
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
        'api_mode' => getIoBrokerApiMode($config),
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
        $send = ioBrokerSendOpenWa($config, $config['whatsapp_to'], $testText);
        $notifyState = loadMobileNotifyState();
        $notifyState['last_test'] = date('Y-m-d H:i:s');

        if (!$send['ok']) {
            $result['ok'] = false;
            $result['error'] = $send['error'];
            $notifyState['last_test_error'] = $send['error'];
        } else {
            $result['sent_count'] = 1;
            $notifyState['last_test_error'] = '';
        }

        saveMobileNotifyState($notifyState);
        return $result;
    }

    $state = loadMobileNotifyState();
    $items = collectMobileMessagesForNotify($conversations);
    $state['last_run'] = date('Y-m-d H:i:s');

    /* Beim ersten Lauf nur Baseline setzen, keine alten WhatsApps senden. */
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

        $send = ioBrokerSendOpenWa(
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

        $state['seen'][$key] = $item['timestamp'];
        $state['last_success'] = date('Y-m-d H:i:s');
        $state['last_error'] = '';
        $result['sent_count']++;
        saveMobileNotifyState($state);
    }

    saveMobileNotifyState($state);
    return $result;
}


function getLocalMobileAppVersion()
{
    $file = __DIR__ . '/VERSION';

    if (!file_exists($file)) {
        return '0.0.0';
    }

    $version = trim(@file_get_contents($file));
    return $version != '' ? $version : '0.0.0';
}


function processMobileGitHubUpdateWatch($config)
{
    $localVersion = getLocalMobileAppVersion();
    $result = array(
        'ok' => false,
        'current_version' => $localVersion,
        'latest_version' => '',
        'available' => false,
        'notified' => false,
        'error' => ''
    );

    $updateInfo = getGitHubUpdateInfo(
        $localVersion,
        __DIR__,
        $config,
        false
    );

    $state = loadMobileNotifyState();
    $state['last_update_check'] = date('Y-m-d H:i:s');

    if (empty($updateInfo['ok'])) {
        $error = isset($updateInfo['error']) ? $updateInfo['error'] : 'GitHub-Updateprüfung fehlgeschlagen.';
        $result['error'] = $error;
        $state['update_error'] = $error;
        saveMobileNotifyState($state);
        return $result;
    }

    $latestVersion = isset($updateInfo['latest_version'])
        ? trim($updateInfo['latest_version'])
        : $localVersion;

    $available = !empty($updateInfo['available']);

    $result['ok'] = true;
    $result['latest_version'] = $latestVersion;
    $result['available'] = $available;

    $state['latest_version'] = $latestVersion;
    $state['update_available'] = $available;
    $state['update_error'] = '';

    if (
        $available &&
        isWhatsAppNotifyEnabled($config) &&
        isWhatsAppUpdateNotifyEnabled($config) &&
        isset($config['whatsapp_to']) &&
        trim($config['whatsapp_to']) != '' &&
        (!isset($state['last_update_notified_version']) || $state['last_update_notified_version'] != $latestVersion)
    ) {
        $text = "🔄 *Neue Version der Verkaufszentrale*\n\n";
        $text .= "Installiert: " . $localVersion . "\n";
        $text .= "Verfügbar: *" . $latestVersion . "*\n";

        if (isset($updateInfo['notes']) && trim($updateInfo['notes']) != '') {
            $text .= "\n" . trim($updateInfo['notes']) . "\n";
        }

        if (isset($config['mobile_web_url']) && trim($config['mobile_web_url']) != '') {
            $text .= "\n👉 *Update öffnen:*\n" . rtrim(trim($config['mobile_web_url']), '/') . '/index.php?check_update=1';
        }

        $send = ioBrokerSendOpenWa($config, $config['whatsapp_to'], $text);

        if ($send['ok']) {
            $state['last_update_notified_version'] = $latestVersion;
            $state['last_update_notification'] = date('Y-m-d H:i:s');
            $result['notified'] = true;
        } else {
            $state['update_error'] = 'Update-Hinweis konnte nicht per WhatsApp gesendet werden: ' . $send['error'];
            $result['error'] = $state['update_error'];
        }
    }

    saveMobileNotifyState($state);
    return $result;
}


function getMobileWhatsAppStatus($config)
{
    $state = loadMobileNotifyState();
    $enabled = isWhatsAppNotifyEnabled($config);
    $to = isset($config['whatsapp_to']) ? normalizeWhatsAppRecipient($config['whatsapp_to']) : '';
    $apiUrl = isset($config['iobroker_rest_url']) ? trim($config['iobroker_rest_url']) : '';
    $mode = getIoBrokerApiMode($config);
    $stateId = isset($config['iobroker_simple_api_state']) && trim($config['iobroker_simple_api_state']) != ''
        ? trim($config['iobroker_simple_api_state'])
        : 'mqtt.0.whatsapp.outgoing';

    $lastRunAge = null;
    if (isset($state['last_run']) && trim($state['last_run']) != '') {
        $lastRunTs = @strtotime($state['last_run']);
        if ($lastRunTs !== false) {
            $lastRunAge = max(0, time() - $lastRunTs);
        }
    }

    $configured = $enabled && $to != '' && $apiUrl != '';
    $cronHealthy = $configured && $lastRunAge !== null && $lastRunAge <= 180;

    return array(
        'ok' => true,
        'enabled' => $enabled,
        'configured' => $configured,
        'initialized' => !empty($state['initialized']),
        'api_mode' => $mode,
        'state_id' => $stateId,
        'last_run' => isset($state['last_run']) ? $state['last_run'] : '',
        'last_run_age_seconds' => $lastRunAge,
        'cron_healthy' => $cronHealthy,
        'last_success' => isset($state['last_success']) ? $state['last_success'] : '',
        'last_error' => isset($state['last_error']) ? $state['last_error'] : '',
        'last_test' => isset($state['last_test']) ? $state['last_test'] : '',
        'last_test_error' => isset($state['last_test_error']) ? $state['last_test_error'] : '',
        'last_update_check' => isset($state['last_update_check']) ? $state['last_update_check'] : '',
        'latest_version' => isset($state['latest_version']) ? $state['latest_version'] : '',
        'update_available' => !empty($state['update_available']),
        'last_update_notification' => isset($state['last_update_notification']) ? $state['last_update_notification'] : '',
        'update_error' => isset($state['update_error']) ? $state['update_error'] : ''
    );
}


function isPrivateNetworkRequest()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';

    if ($ip == '127.0.0.1' || $ip == '::1') {
        return true;
    }

    if (preg_match('/^10\./', $ip)) {
        return true;
    }

    if (preg_match('/^192\.168\./', $ip)) {
        return true;
    }

    if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) {
        return true;
    }

    return false;
}


function getMobileArchiveInboxItems($config, $wantedStatus)
{
    $wantedStatus = $wantedStatus == 'erledigt' ? 'erledigt' : 'beantwortet';
    $state = loadAppState();
    $mail = loadMobileConversations($config);

    $result = array(
        'ok' => false,
        'status' => $wantedStatus,
        'total_count' => 0,
        'inbox_count' => 0,
        'items' => array(),
        'error' => ''
    );

    if (!$mail['ok']) {
        $result['error'] = 'Gmail: ' . $mail['error'];
        return $result;
    }

    if (isset($state['conversations']) && is_array($state['conversations'])) {
        foreach ($state['conversations'] as $storedConversation) {
            if (!is_array($storedConversation) || !isset($storedConversation['status'])) {
                continue;
            }

            if (validStatus($storedConversation['status']) == $wantedStatus) {
                $result['total_count']++;
            }
        }
    }

    foreach ($mail['conversations'] as $key => $conversation) {
        $local = getConversationState($state, $key);
        $status = isset($local['status']) && $local['status'] != ''
            ? validStatus($local['status'])
            : ($conversation['unread'] ? 'neu' : 'offen');

        if ($status != $wantedStatus) {
            continue;
        }

        $latest = getLatestMessage($conversation);
        $result['items'][] = array(
            'key' => $key,
            'name' => isset($conversation['name']) && trim($conversation['name']) != '' ? trim($conversation['name']) : 'Interessent',
            'subject' => isset($conversation['subject']) ? trim($conversation['subject']) : '',
            'date' => is_array($latest) && isset($latest['date']) ? $latest['date'] : '',
            'text' => is_array($latest) && isset($latest['text']) ? $latest['text'] : '',
            'message_count' => isset($conversation['messages']) && is_array($conversation['messages']) ? count($conversation['messages']) : 0
        );
    }

    $result['inbox_count'] = count($result['items']);
    $result['ok'] = true;
    return $result;
}


function reopenMobileArchiveConversation($config, $key)
{
    $result = array('ok' => false, 'error' => '', 'url' => '');
    $key = preg_replace('/[^a-f0-9]/', '', strtolower(trim($key)));

    if ($key == '') {
        $result['error'] = 'Das Gespräch konnte nicht zugeordnet werden.';
        return $result;
    }

    $mail = loadMobileConversations($config);
    if (!$mail['ok']) {
        $result['error'] = 'Gmail: ' . $mail['error'];
        return $result;
    }

    if (!isset($mail['conversations'][$key])) {
        $result['error'] = 'Die Eingangsmail liegt nicht im Gmail-Posteingang. Bitte die betreffende Mail in Gmail zuerst wieder in den Posteingang verschieben.';
        return $result;
    }

    $state = loadAppState();
    setConversationState(
        $state,
        $key,
        array(
            'status' => 'offen',
            'draft' => '',
            'draft_updated' => '',
            'draft_for_message_key' => '',
            'draft_for_message_date' => '',
            'reply_hint' => '',
            'answered_at' => '',
            'answered_at_ts' => 0,
            'no_interest_at' => '',
            'no_interest_at_ts' => 0
        )
    );

    if (!saveAppState($state)) {
        $result['error'] = 'Der lokale Gesprächsstatus konnte nicht gespeichert werden.';
        return $result;
    }

    $result['ok'] = true;
    $result['url'] = 'index.php?conversation=' . rawurlencode($key) . '#c-' . rawurlencode($key);
    return $result;
}


/*
 * =========================================================
 * Standalone-Aufruf für Synology Aufgabenplaner und UI
 *
 * UI Status (nur aus privatem Netz):
 *   /mobile/functions.php?status=1
 *
 * UI Archivliste:
 *   /mobile/functions.php?archive_list=beantwortet
 *
 * UI Archiv wieder öffnen (POST + Session-CSRF):
 *   /mobile/functions.php?archive_reopen=1
 *
 * UI Test (nur aus privatem Netz, feste konfigurierte Zielnummer):
 *   /mobile/functions.php?ui_test=1
 *
 * Browser-Test mit Token:
 *   /mobile/functions.php?token=GEHEIMNIS&test=1
 *
 * Regulärer Check:
 *   /mobile/functions.php?token=GEHEIMNIS
 * =========================================================
 */

if (
    isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $configFile = __DIR__ . '/_private/config.php';

    if (!file_exists($configFile)) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => 'config.php fehlt.'));
        exit;
    }

    require $configFile;

    if (isset($_GET['archive_list'])) {
        if (!isPrivateNetworkRequest()) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'Archiv nur im privaten Netz verfügbar.'));
            exit;
        }

        if (session_id() == '') {
            @session_start();
        }

        if (!isset($_SESSION['mobile_csrf']) || $_SESSION['mobile_csrf'] == '') {
            $_SESSION['mobile_csrf'] = sha1(uniqid(mt_rand(), true));
        }

        $wantedStatus = $_GET['archive_list'] == 'erledigt' ? 'erledigt' : 'beantwortet';
        $archiveData = getMobileArchiveInboxItems($config, $wantedStatus);
        $archiveData['csrf'] = $_SESSION['mobile_csrf'];

        if (!$archiveData['ok']) {
            http_response_code(500);
        }

        echo json_encode(
            $archiveData,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if (isset($_GET['archive_reopen']) && $_GET['archive_reopen'] == '1') {
        if (!isPrivateNetworkRequest()) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'Archiv nur im privaten Netz verfügbar.'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] != 'POST') {
            http_response_code(405);
            echo json_encode(array('ok' => false, 'error' => 'Nur POST ist erlaubt.'));
            exit;
        }

        if (session_id() == '') {
            @session_start();
        }

        $expectedCsrf = isset($_SESSION['mobile_csrf']) ? strval($_SESSION['mobile_csrf']) : '';
        $providedCsrf = isset($_POST['csrf']) ? strval($_POST['csrf']) : '';
        $csrfOk = $expectedCsrf != '' && $providedCsrf != '' && (
            function_exists('hash_equals')
                ? hash_equals($expectedCsrf, $providedCsrf)
                : ($expectedCsrf === $providedCsrf)
        );

        if (!$csrfOk) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen. Seite bitte neu laden.'));
            exit;
        }

        $key = isset($_POST['conversation_key']) ? $_POST['conversation_key'] : '';
        $reopen = reopenMobileArchiveConversation($config, $key);

        if (!$reopen['ok']) {
            http_response_code(400);
        }

        echo json_encode(
            $reopen,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    /* Read-only Status für die Weboberfläche. */
    if (isset($_GET['status']) && $_GET['status'] == '1') {
        if (!isPrivateNetworkRequest()) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'Status nur im privaten Netz verfügbar.'));
            exit;
        }

        echo json_encode(
            getMobileWhatsAppStatus($config),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    /* Fester Testversand aus der Weboberfläche, kein frei wählbarer Empfänger/Text. */
    if (isset($_GET['ui_test']) && $_GET['ui_test'] == '1') {
        if (!isPrivateNetworkRequest()) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'Test nur im privaten Netz verfügbar.'));
            exit;
        }

        $notify = processMobileWhatsAppNotifications($config, array(), true);

        if (!$notify['ok']) {
            http_response_code(500);
        }

        echo json_encode(
            $notify,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

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

    if (!$testMode) {
        $notify['update_check'] = processMobileGitHubUpdateWatch($config);
    }

    if (!$notify['ok']) {
        http_response_code(500);
    }

    echo json_encode(
        $notify,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
