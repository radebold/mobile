<?php

/* Modul: gmail.php */


function getImapServerPrefix($imapServer)
{
    if (preg_match('/^(\{[^\}]+\})/', $imapServer, $matches)) {
        return $matches[1];
    }

    return '';
}


function cleanEmailAddressForHeader($email)
{
    $email = trim($email);
    $email = str_replace(array("\r", "\n"), '', $email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    return $email;
}


function findGmailTrashMailbox($imap, $serverPrefix)
{
    $mailboxes = @imap_getmailboxes($imap, $serverPrefix, '*');

    if (is_array($mailboxes)) {
        foreach ($mailboxes as $mailbox) {
            if (!isset($mailbox->name)) {
                continue;
            }

            $fullName = $mailbox->name;
            $shortName = $fullName;

            if ($serverPrefix != '' && strpos($shortName, $serverPrefix) === 0) {
                $shortName = substr($shortName, strlen($serverPrefix));
            }

            $decodedName = $shortName;

            if (function_exists('imap_utf7_decode')) {
                $decoded = @imap_utf7_decode($shortName);
                if ($decoded !== false && $decoded != '') {
                    $decodedName = $decoded;
                }
            }

            $lower = function_exists('mb_strtolower')
                ? mb_strtolower($decodedName, 'UTF-8')
                : strtolower($decodedName);

            if (
                preg_match('/(^|\/)(trash)$/iu', $decodedName) ||
                preg_match('/(^|\/)(papierkorb)$/iu', $decodedName) ||
                strpos($lower, '/trash') !== false ||
                strpos($lower, '/papierkorb') !== false
            ) {
                return $shortName;
            }
        }
    }

    return '[Gmail]/Trash';
}


function findGmailAllMailMailbox($imap, $serverPrefix)
{
    $mailboxes = @imap_getmailboxes($imap, $serverPrefix, '*');

    if (is_array($mailboxes)) {
        foreach ($mailboxes as $mailbox) {
            if (!isset($mailbox->name)) {
                continue;
            }

            $fullName = $mailbox->name;
            $shortName = $fullName;

            if ($serverPrefix != '' && strpos($shortName, $serverPrefix) === 0) {
                $shortName = substr($shortName, strlen($serverPrefix));
            }

            $decodedName = $shortName;

            if (function_exists('imap_utf7_decode')) {
                $decoded = @imap_utf7_decode($shortName);
                if ($decoded !== false && $decoded != '') {
                    $decodedName = $decoded;
                }
            }

            $lower = function_exists('mb_strtolower')
                ? mb_strtolower($decodedName, 'UTF-8')
                : strtolower($decodedName);

            if (
                preg_match('/(^|\/)(all mail)$/iu', $decodedName) ||
                preg_match('/(^|\/)(alle nachrichten)$/iu', $decodedName) ||
                strpos($lower, '/all mail') !== false ||
                strpos($lower, '/alle nachrichten') !== false
            ) {
                return $shortName;
            }
        }
    }

    return '[Gmail]/All Mail';
}


function moveConversationToGmailArchive($config, $conversation)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'moved' => 0,
        'mailbox' => ''
    );

    if (
        !isset($config['imap_server']) ||
        !isset($config['gmail_user']) ||
        !isset($config['gmail_password'])
    ) {
        $result['error'] = 'Gmail-Konfiguration unvollständig.';
        return $result;
    }

    if (
        !isset($conversation['messages']) ||
        !is_array($conversation['messages']) ||
        count($conversation['messages']) == 0
    ) {
        $result['ok'] = true;
        return $result;
    }

    $uids = array();
    foreach ($conversation['messages'] as $message) {
        if (isset($message['uid']) && intval($message['uid']) > 0) {
            $uids[] = intval($message['uid']);
        }
    }

    $uids = array_values(array_unique($uids));
    if (count($uids) == 0) {
        $result['ok'] = true;
        return $result;
    }

    $password = str_replace(' ', '', $config['gmail_password']);
    $imap = @imap_open(
        $config['imap_server'],
        $config['gmail_user'],
        $password
    );

    if (!$imap) {
        $err = imap_last_error();
        $result['error'] = $err ? $err : 'Gmail IMAP Schreibverbindung fehlgeschlagen.';
        return $result;
    }

    $serverPrefix = getImapServerPrefix($config['imap_server']);
    $archiveMailbox = findGmailAllMailMailbox($imap, $serverPrefix);
    $result['mailbox'] = $archiveMailbox;

    $uidList = implode(',', $uids);
    $flags = defined('CP_UID') ? CP_UID : 1;

    $ok = @imap_mail_move(
        $imap,
        $uidList,
        $archiveMailbox,
        $flags
    );

    if (!$ok) {
        $err = imap_last_error();
        $result['error'] = $err ? $err : 'Die Gmail-Nachrichten konnten nicht archiviert werden.';
        @imap_close($imap);
        return $result;
    }

    @imap_expunge($imap);
    @imap_close($imap);

    $result['ok'] = true;
    $result['moved'] = count($uids);
    return $result;
}


function moveConversationToGmailTrash($config, $conversation)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'moved' => 0,
        'mailbox' => ''
    );

    if (
        !isset($config['imap_server']) ||
        !isset($config['gmail_user']) ||
        !isset($config['gmail_password'])
    ) {
        $result['error'] = 'Gmail-Konfiguration unvollständig.';
        return $result;
    }

    if (
        !isset($conversation['messages']) ||
        !is_array($conversation['messages']) ||
        count($conversation['messages']) == 0
    ) {
        $result['ok'] = true;
        return $result;
    }

    $uids = array();

    foreach ($conversation['messages'] as $message) {
        if (isset($message['uid']) && intval($message['uid']) > 0) {
            $uids[] = intval($message['uid']);
        }
    }

    $uids = array_values(array_unique($uids));

    if (count($uids) == 0) {
        $result['ok'] = true;
        return $result;
    }

    $password = str_replace(' ', '', $config['gmail_password']);

    $imap = @imap_open(
        $config['imap_server'],
        $config['gmail_user'],
        $password
    );

    if (!$imap) {
        $err = imap_last_error();
        $result['error'] = $err ? $err : 'Gmail IMAP Schreibverbindung fehlgeschlagen.';
        return $result;
    }

    $serverPrefix = getImapServerPrefix($config['imap_server']);
    $trashMailbox = findGmailTrashMailbox($imap, $serverPrefix);
    $result['mailbox'] = $trashMailbox;

    $uidList = implode(',', $uids);
    $flags = defined('CP_UID') ? CP_UID : 1;

    $ok = @imap_mail_move(
        $imap,
        $uidList,
        $trashMailbox,
        $flags
    );

    if (!$ok) {
        $err = imap_last_error();
        $result['error'] = $err ? $err : 'Die Gmail-Nachrichten konnten nicht in den Papierkorb verschoben werden.';
        @imap_close($imap);
        return $result;
    }

    @imap_expunge($imap);
    @imap_close($imap);

    $result['ok'] = true;
    $result['moved'] = count($uids);

    return $result;
}


function createGmailDraft($config, $vehicle, $conversation, $draftText)
{
    $result = array(
        'ok' => false,
        'verified' => false,
        'error' => '',
        'draft_id' => '',
        'thread_id' => '',
        'message_id' => '',
        'subject' => '',
        'recipient' => ''
    );

    $draftText = trim($draftText);

    if ($draftText == '') {
        $result['error'] = 'Der Antwortentwurf ist leer.';
        return $result;
    }

    if (
        !isset($config['gmail_bridge_url']) ||
        trim($config['gmail_bridge_url']) == '' ||
        !isset($config['gmail_bridge_token']) ||
        trim($config['gmail_bridge_token']) == ''
    ) {
        $result['error'] =
            'Die Gmail-Bridge ist noch nicht eingerichtet. ' .
            'Bitte gmail_bridge_url und gmail_bridge_token in _private/config.php eintragen.';
        return $result;
    }

    $latest = getLatestMessage($conversation);

    $replyMessageId = '';
    if (is_array($latest) && isset($latest['message_id'])) {
        $replyMessageId = trim(str_replace(array("\r", "\n"), '', $latest['message_id']));
    }

    $subject = isset($conversation['subject']) ? trim($conversation['subject']) : '';
    if (is_array($latest) && isset($latest['subject']) && trim($latest['subject']) != '') {
        $subject = trim($latest['subject']);
    }

    $senderEmail = isset($conversation['email'])
        ? cleanEmailAddressForHeader($conversation['email'])
        : '';

    $recipientName = isset($conversation['name'])
        ? trim($conversation['name'])
        : '';

    $sellerName = isset($vehicle['seller_name']) && trim($vehicle['seller_name']) != ''
        ? trim($vehicle['seller_name'])
        : 'Thomas Radebold';

    $payload = array(
        'action' => 'createDraftReply',
        'token' => trim($config['gmail_bridge_token']),
        'body' => $draftText,
        'message_id' => $replyMessageId,
        'sender_email' => $senderEmail,
        'recipient_name' => $recipientName,
        'subject' => $subject,
        'seller_name' => $sellerName
    );

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        $result['error'] = 'Die Gmail-Bridge-Anfrage konnte nicht als JSON erzeugt werden.';
        return $result;
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, trim($config['gmail_bridge_url']));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
            'Content-Type: application/json',
            'Accept: application/json'
        )
    );

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        $result['error'] = 'Gmail-Bridge nicht erreichbar: ' . $err;
        return $result;
    }

    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        $preview = trim(strip_tags($raw));
        if (strlen($preview) > 300) {
            $preview = substr($preview, 0, 300) . '...';
        }

        $result['error'] =
            'Die Gmail-Bridge hat keine gültige JSON-Antwort geliefert' .
            ($httpCode > 0 ? ' (HTTP ' . $httpCode . ')' : '') .
            ($preview != '' ? ': ' . $preview : '.');

        return $result;
    }

    if (empty($data['ok'])) {
        $result['error'] = isset($data['error']) && trim($data['error']) != ''
            ? trim($data['error'])
            : 'Die Gmail-Bridge konnte keinen Entwurf anlegen.';
        return $result;
    }

    $result['ok'] = true;
    $result['verified'] = true;
    $result['draft_id'] = isset($data['draft_id']) ? strval($data['draft_id']) : '';
    $result['thread_id'] = isset($data['thread_id']) ? strval($data['thread_id']) : '';
    $result['message_id'] = isset($data['message_id']) ? strval($data['message_id']) : '';
    $result['subject'] = isset($data['subject']) ? strval($data['subject']) : $subject;
    $result['recipient'] = isset($data['recipient']) && trim($data['recipient']) != ''
        ? strval($data['recipient'])
        : ($recipientName != '' ? $recipientName . ' <' . $senderEmail . '>' : $senderEmail);

    return $result;
}


function sendGmailReply($config, $vehicle, $conversation, $replyText)
{
    $result = array(
        'ok' => false,
        'sent' => false,
        'error' => '',
        'thread_id' => '',
        'message_id' => '',
        'subject' => '',
        'recipient' => ''
    );

    $replyText = trim($replyText);

    if ($replyText == '') {
        $result['error'] = 'Der Antworttext ist leer.';
        return $result;
    }

    if (
        !isset($config['gmail_bridge_url']) ||
        trim($config['gmail_bridge_url']) == '' ||
        !isset($config['gmail_bridge_token']) ||
        trim($config['gmail_bridge_token']) == ''
    ) {
        $result['error'] = 'Die Gmail-Bridge ist noch nicht eingerichtet.';
        return $result;
    }

    $latest = getLatestMessage($conversation);

    $replyMessageId = '';
    if (is_array($latest) && isset($latest['message_id'])) {
        $replyMessageId = trim(str_replace(array("\r", "\n"), '', $latest['message_id']));
    }

    $subject = isset($conversation['subject']) ? trim($conversation['subject']) : '';
    if (is_array($latest) && isset($latest['subject']) && trim($latest['subject']) != '') {
        $subject = trim($latest['subject']);
    }

    $senderEmail = isset($conversation['email'])
        ? cleanEmailAddressForHeader($conversation['email'])
        : '';

    $recipientName = isset($conversation['name'])
        ? trim($conversation['name'])
        : '';

    $sellerName = isset($vehicle['seller_name']) && trim($vehicle['seller_name']) != ''
        ? trim($vehicle['seller_name'])
        : 'Thomas Radebold';

    $payload = array(
        'action' => 'sendReply',
        'token' => trim($config['gmail_bridge_token']),
        'body' => $replyText,
        'message_id' => $replyMessageId,
        'sender_email' => $senderEmail,
        'recipient_name' => $recipientName,
        'subject' => $subject,
        'seller_name' => $sellerName
    );

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        $result['error'] = 'Die Gmail-Bridge-Anfrage konnte nicht als JSON erzeugt werden.';
        return $result;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, trim($config['gmail_bridge_url']));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
            'Content-Type: application/json',
            'Accept: application/json'
        )
    );

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        $result['error'] = 'Gmail-Bridge nicht erreichbar: ' . $err;
        return $result;
    }

    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        $preview = trim(strip_tags($raw));
        if (strlen($preview) > 300) {
            $preview = substr($preview, 0, 300) . '...';
        }

        $result['error'] =
            'Die Gmail-Bridge hat keine gültige JSON-Antwort geliefert' .
            ($httpCode > 0 ? ' (HTTP ' . $httpCode . ')' : '') .
            ($preview != '' ? ': ' . $preview : '.');
        return $result;
    }

    if (empty($data['ok']) || empty($data['sent'])) {
        $result['error'] = isset($data['error']) && trim($data['error']) != ''
            ? trim($data['error'])
            : 'Die Gmail-Bridge konnte die Antwort nicht versenden.';
        return $result;
    }

    $result['ok'] = true;
    $result['sent'] = true;
    $result['thread_id'] = isset($data['thread_id']) ? strval($data['thread_id']) : '';
    $result['message_id'] = isset($data['message_id']) ? strval($data['message_id']) : '';
    $result['subject'] = isset($data['subject']) ? strval($data['subject']) : $subject;
    $result['recipient'] = isset($data['recipient']) && trim($data['recipient']) != ''
        ? strval($data['recipient'])
        : ($recipientName != '' ? $recipientName . ' <' . $senderEmail . '>' : $senderEmail);

    return $result;
}


function isPrivateGmailSendRequest()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';

    if ($ip == '127.0.0.1' || $ip == '::1') return true;
    if (preg_match('/^10\./', $ip)) return true;
    if (preg_match('/^192\.168\./', $ip)) return true;
    if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) return true;

    return false;
}


/*
 * Direkter Versand aus der Weboberfläche.
 * Nur POST, nur privates Netz und nur mit gültigem Session-CSRF-Token.
 */
if (
    isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__) &&
    isset($_GET['send_reply']) &&
    $_GET['send_reply'] == '1'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        http_response_code(405);
        echo json_encode(array('ok' => false, 'error' => 'Nur POST ist erlaubt.'));
        exit;
    }

    if (!isPrivateGmailSendRequest()) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'Direktversand ist nur im privaten Netz erlaubt.'));
        exit;
    }

    if (session_id() == '') {
        @session_start();
    }

    $expectedCsrf = isset($_SESSION['mobile_csrf']) ? strval($_SESSION['mobile_csrf']) : '';
    $providedCsrf = isset($_POST['csrf']) ? strval($_POST['csrf']) : '';

    if ($expectedCsrf == '' || $providedCsrf == '') {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen. Seite bitte neu laden.'));
        exit;
    }

    $csrfOk = function_exists('hash_equals')
        ? hash_equals($expectedCsrf, $providedCsrf)
        : ($expectedCsrf === $providedCsrf);

    if (!$csrfOk) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen. Seite bitte neu laden.'));
        exit;
    }

    require_once __DIR__ . '/mail.php';
    require_once __DIR__ . '/state.php';

    $appDir = dirname(__DIR__);
    $configFile = $appDir . '/_private/config.php';
    $vehicleFile = $appDir . '/vehicle.php';

    if (!file_exists($configFile) || !file_exists($vehicleFile)) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => 'Konfiguration oder Fahrzeugdaten fehlen.'));
        exit;
    }

    require $configFile;
    require $vehicleFile;

    $key = isset($_POST['conversation_key'])
        ? preg_replace('/[^a-f0-9]/', '', strtolower($_POST['conversation_key']))
        : '';
    $replyText = isset($_POST['draft']) ? trim($_POST['draft']) : '';

    if ($key == '' || $replyText == '') {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => 'Gespräch oder Antworttext fehlt.'));
        exit;
    }

    $mailResult = loadMobileConversations($config);

    if (!$mailResult['ok']) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => 'Gmail: ' . $mailResult['error']));
        exit;
    }

    if (!isset($mailResult['conversations'][$key])) {
        http_response_code(404);
        echo json_encode(array('ok' => false, 'error' => 'Das Gespräch wurde nicht gefunden. Bitte Seite aktualisieren.'));
        exit;
    }

    $conversation = $mailResult['conversations'][$key];
    $send = sendGmailReply($config, $vehicle, $conversation, $replyText);

    if (!$send['ok']) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'sent' => false, 'error' => $send['error']));
        exit;
    }

    /*
     * Ab hier ist die Nachricht bereits versendet. Folgefehler dürfen daher
     * NICHT als Versandfehler behandelt werden, sonst droht ein Doppelversand.
     */
    $warnings = array();
    $archive = moveConversationToGmailArchive($config, $conversation);

    if (!$archive['ok']) {
        $warnings[] = 'Antwort wurde gesendet, aber die Eingangsmails konnten nicht archiviert werden: ' . $archive['error'];
    }

    $state = loadAppState();
    setConversationState(
        $state,
        $key,
        array(
            'status' => 'beantwortet',
            'draft' => $replyText,
            'draft_updated' => date('Y-m-d H:i:s'),
            'answered_at' => date('Y-m-d H:i:s'),
            'answered_at_ts' => time(),
            'gmail_sent_at' => date('Y-m-d H:i:s'),
            'gmail_sent_thread_id' => isset($send['thread_id']) ? $send['thread_id'] : '',
            'gmail_sent_message_id' => isset($send['message_id']) ? $send['message_id'] : '',
            'gmail_sent_recipient' => isset($send['recipient']) ? $send['recipient'] : ''
        )
    );

    if (!saveAppState($state)) {
        $warnings[] = 'Antwort wurde gesendet, aber der lokale Status konnte nicht gespeichert werden.';
    }

    echo json_encode(
        array(
            'ok' => true,
            'sent' => true,
            'archived' => !empty($archive['ok']),
            'recipient' => isset($send['recipient']) ? $send['recipient'] : '',
            'warning' => count($warnings) ? implode(' ', $warnings) : ''
        ),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
