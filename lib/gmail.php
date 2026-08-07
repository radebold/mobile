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
