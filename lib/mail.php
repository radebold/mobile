<?php

/* Modul: mail.php */

/*
 * =========================================================
 * mobile.de Verkaufszentrale - Funktionen
 * PHP 5.6 kompatibel
 * =========================================================
 */

function h($text)
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}


function safeArrayGet($array, $key, $default)
{
    if (is_array($array) && isset($array[$key])) {
        return $array[$key];
    }

    return $default;
}


function decodeMimeText($text)
{
    if ($text === null || $text === '') {
        return '';
    }

    $elements = @imap_mime_header_decode($text);
    $result = '';

    if (is_array($elements)) {
        foreach ($elements as $element) {
            $charset = isset($element->charset) ? strtoupper($element->charset) : 'DEFAULT';
            $part = isset($element->text) ? $element->text : '';

            if (
                $charset != 'DEFAULT' &&
                $charset != 'UTF-8' &&
                function_exists('mb_convert_encoding')
            ) {
                $converted = @mb_convert_encoding($part, 'UTF-8', $charset);
                if ($converted !== false) {
                    $part = $converted;
                }
            }

            $result .= $part;
        }
    } else {
        $result = $text;
    }

    return $result;
}


function decodeBodyPart($data, $encoding)
{
    if ($encoding == 3) {
        return base64_decode($data);
    }

    if ($encoding == 4) {
        return quoted_printable_decode($data);
    }

    return $data;
}


function getPartCharset($part)
{
    $charset = '';

    if (isset($part->parameters) && is_array($part->parameters)) {
        foreach ($part->parameters as $parameter) {
            if (
                isset($parameter->attribute) &&
                strtoupper($parameter->attribute) == 'CHARSET'
            ) {
                $charset = isset($parameter->value) ? $parameter->value : '';
            }
        }
    }

    return $charset;
}


function convertToUtf8($data, $charset)
{
    if ($data === '') {
        return '';
    }

    if (!$charset) {
        return $data;
    }

    $charset = strtoupper($charset);

    if ($charset == 'UTF-8' || $charset == 'US-ASCII') {
        return $data;
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $data;
}


function getMessageBodyRecursive($imap, $msgNumber, $structure, $partNumber)
{
    $plain = '';
    $html = '';

    if (!isset($structure->parts)) {
        $body = @imap_body($imap, $msgNumber, FT_PEEK);
        $body = decodeBodyPart(
            $body,
            isset($structure->encoding) ? $structure->encoding : 0
        );
        $body = convertToUtf8($body, getPartCharset($structure));

        $type = isset($structure->subtype) ? strtoupper($structure->subtype) : '';

        if ($type == 'HTML') {
            $html = $body;
        } else {
            $plain = $body;
        }

        return array('plain' => $plain, 'html' => $html);
    }

    $count = count($structure->parts);

    for ($i = 0; $i < $count; $i++) {
        $part = $structure->parts[$i];

        $currentPartNumber = $partNumber == ''
            ? strval($i + 1)
            : $partNumber . '.' . ($i + 1);

        if (isset($part->parts)) {
            $child = getMessageBodyRecursive(
                $imap,
                $msgNumber,
                $part,
                $currentPartNumber
            );

            if ($plain == '' && $child['plain'] != '') {
                $plain = $child['plain'];
            }

            if ($html == '' && $child['html'] != '') {
                $html = $child['html'];
            }

            continue;
        }

        $type = isset($part->type) ? $part->type : -1;
        $subtype = isset($part->subtype) ? strtoupper($part->subtype) : '';

        /* type 0 = text */
        if ($type != 0) {
            continue;
        }

        $body = @imap_fetchbody(
            $imap,
            $msgNumber,
            $currentPartNumber,
            FT_PEEK
        );

        $body = decodeBodyPart(
            $body,
            isset($part->encoding) ? $part->encoding : 0
        );

        $body = convertToUtf8($body, getPartCharset($part));

        if ($subtype == 'PLAIN') {
            if ($plain == '') {
                $plain = $body;
            }
        } elseif ($subtype == 'HTML') {
            if ($html == '') {
                $html = $body;
            }
        }
    }

    return array('plain' => $plain, 'html' => $html);
}


function getMessageText($imap, $msgNumber)
{
    $structure = @imap_fetchstructure($imap, $msgNumber);

    if (!$structure) {
        return '';
    }

    $result = getMessageBodyRecursive(
        $imap,
        $msgNumber,
        $structure,
        ''
    );

    if ($result['plain'] != '') {
        return trim($result['plain']);
    }

    if ($result['html'] != '') {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $result['html']);
        $text = preg_replace('/<\/p>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim($text);
    }

    return '';
}


function normalizeText($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", $text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n[ \t]+/u', "\n", $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);

    return trim($text);
}


function substringBeforeFirstMarker($text, $markers)
{
    $lowest = false;

    foreach ($markers as $marker) {
        $pos = stripos($text, $marker);
        if ($pos !== false) {
            if ($lowest === false || $pos < $lowest) {
                $lowest = $pos;
            }
        }
    }

    if ($lowest !== false) {
        return substr($text, 0, $lowest);
    }

    return $text;
}


function cleanMobileMessage($rawText)
{
    $text = normalizeText($rawText);

    /* Markdown-Links auf sichtbaren Text reduzieren. */
    $text = preg_replace('/\[([^\]]+)\]\(https?:\/\/[^\)]+\)/u', '$1', $text);

    /* Initiale mobile.de Anfrage: nur der eigentliche Nachrichtenteil. */
    $messagePos = stripos($text, 'Nachricht:');

    if ($messagePos !== false) {
        $text = substr($text, $messagePos + strlen('Nachricht:'));

        $text = substringBeforeFirstMarker(
            $text,
            array(
                'Nutze bitte die Antwort-Funktion',
                'Bitte beachte:',
                'Sicherheit bei mobile.de',
                'Diese E-Mail ist ein Service der mobile.de',
                'Mit freundlichen Grüßen\n\nDiese E-Mail',
                'Mit freundlichen Grüßen\n\nDiese E-Mail'
            )
        );
    } else {
        /* Folgeantworten enthalten haeufig nur Text + Hinweis/Quoted Mail. */
        $text = substringBeforeFirstMarker(
            $text,
            array(
                'Hinweis: Diese Nachricht',
                'Hinweis: Diese Nachricht enthält',
                'Hinweis: Diese Nachricht enth',
                'Nutze bitte die Antwort-Funktion',
                'Bitte beachte:',
                'Diese E-Mail ist ein Service der mobile.de'
            )
        );

        /* Typische zitierte Antwortketten abschneiden. */
        $quotePatterns = array(
            '/\nAm .+ schrieb .+:/isu',
            '/\n.+ schrieb am .+:/isu',
            '/\nOn .+ wrote:/isu'
        );

        foreach ($quotePatterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
    }

    /* Reine URLs entfernen. */
    $text = preg_replace('/https?:\/\/\S+/u', '', $text);

    /* Lange Trenner entfernen. */
    $text = preg_replace('/[-_]{8,}/u', '', $text);

    /* Typische mobile.de Reste entfernen. */
    $text = preg_replace('/^mobile\.de\s*$/imu', '', $text);
    $text = preg_replace('/^Inseratsnummer:.*$/imu', '', $text);

    return normalizeText($text);
}


function sanitizeForAI($text)
{
    $text = $text;

    /* E-Mail-Adressen */
    $text = preg_replace(
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
        '[E-MAIL ENTFERNT]',
        $text
    );

    /* URLs */
    $text = preg_replace(
        '/https?:\/\/\S+/iu',
        '[LINK ENTFERNT]',
        $text
    );

    /* Telefonnummern, bewusst konservativ. */
    $text = preg_replace_callback(
        '/(?<!\d)(?:\+?49|0)[\d\s\/\-\(\)]{7,}\d(?!\d)/u',
        'replacePhoneForAI',
        $text
    );

    return normalizeText($text);
}


function replacePhoneForAI($matches)
{
    return '[TELEFONNUMMER ENTFERNT]';
}


function getSenderInfoFromHeader($header)
{
    $name = '';
    $email = '';

    if (isset($header->from) && isset($header->from[0])) {
        $from = $header->from[0];

        if (isset($from->personal)) {
            $name = trim(decodeMimeText($from->personal));
        }

        if (isset($from->mailbox) && isset($from->host)) {
            $email = strtolower($from->mailbox . '@' . $from->host);
        }
    }

    return array('name' => $name, 'email' => $email);
}


function makeConversationKey($email)
{
    return sha1(strtolower(trim($email)));
}


function loadMobileConversations($config)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'conversations' => array(),
        'message_count' => 0
    );

    if (!isset($config['imap_server']) || !isset($config['gmail_user']) || !isset($config['gmail_password'])) {
        $result['error'] = 'Gmail-Konfiguration unvollstaendig.';
        return $result;
    }

    $password = str_replace(' ', '', $config['gmail_password']);

    $imap = @imap_open(
        $config['imap_server'],
        $config['gmail_user'],
        $password,
        OP_READONLY
    );

    if (!$imap) {
        $err = imap_last_error();
        $result['error'] = $err ? $err : 'Gmail IMAP Verbindung fehlgeschlagen.';
        return $result;
    }

    $days = 30;
    if (isset($config['mobile_days']) && intval($config['mobile_days']) > 0) {
        $days = intval($config['mobile_days']);
    }

    $maxMessages = 80;
    if (isset($config['mobile_max_messages']) && intval($config['mobile_max_messages']) > 0) {
        $maxMessages = intval($config['mobile_max_messages']);
    }

    $sinceTs = time() - ($days * 86400);
    $since = date('d-M-Y', $sinceTs);

    $criteria = 'FROM "kontakt.mobile.de" SINCE "' . $since . '"';

    $uids = @imap_search($imap, $criteria, SE_UID);

    if (!is_array($uids)) {
        @imap_close($imap);
        $result['ok'] = true;
        return $result;
    }

    rsort($uids, SORT_NUMERIC);
    $uids = array_slice($uids, 0, $maxMessages);

    $conversations = array();

    foreach ($uids as $uid) {
        $msgNumber = @imap_msgno($imap, $uid);
        if (!$msgNumber) {
            continue;
        }

        $header = @imap_headerinfo($imap, $msgNumber);
        if (!$header) {
            continue;
        }

        $sender = getSenderInfoFromHeader($header);
        if ($sender['email'] == '') {
            continue;
        }

        $rawBody = getMessageText($imap, $msgNumber);
        $cleanBody = cleanMobileMessage($rawBody);

        if ($cleanBody == '') {
            $cleanBody = '(Kein verwertbarer Nachrichtentext erkannt.)';
        }

        $subject = isset($header->subject) ? decodeMimeText($header->subject) : '';
        $timestamp = isset($header->udate) ? intval($header->udate) : time();
        $messageId = isset($header->message_id) ? trim($header->message_id) : '';
        $references = isset($header->references) ? trim($header->references) : '';

        $seen = 0;
        $overview = @imap_fetch_overview($imap, strval($msgNumber), 0);
        if (is_array($overview) && isset($overview[0]) && isset($overview[0]->seen)) {
            $seen = intval($overview[0]->seen);
        }

        $key = makeConversationKey($sender['email']);

        if (!isset($conversations[$key])) {
            $conversations[$key] = array(
                'key' => $key,
                'name' => $sender['name'],
                'email' => $sender['email'],
                'subject' => $subject,
                'latest_ts' => $timestamp,
                'unread' => 0,
                'messages' => array()
            );
        }

        if ($sender['name'] != '') {
            $conversations[$key]['name'] = $sender['name'];
        }

        $conversations[$key]['subject'] = $subject;

        if ($timestamp > $conversations[$key]['latest_ts']) {
            $conversations[$key]['latest_ts'] = $timestamp;
        }

        if (!$seen) {
            $conversations[$key]['unread'] = 1;
        }

        $conversations[$key]['messages'][] = array(
            'uid' => $uid,
            'timestamp' => $timestamp,
            'date' => date('d.m.Y H:i', $timestamp),
            'subject' => $subject,
            'text' => $cleanBody,
            'seen' => $seen,
            'message_id' => $messageId,
            'references' => $references
        );

        $result['message_count']++;
    }

    @imap_close($imap);

    foreach ($conversations as $key => $conversation) {
        usort($conversation['messages'], 'sortMessagesAscending');
        $conversations[$key] = $conversation;
    }

    uasort($conversations, 'sortConversationsDescending');

    $result['ok'] = true;
    $result['conversations'] = $conversations;

    return $result;
}


function sortMessagesAscending($a, $b)
{
    if ($a['timestamp'] == $b['timestamp']) {
        return 0;
    }

    return ($a['timestamp'] < $b['timestamp']) ? -1 : 1;
}


function sortConversationsDescending($a, $b)
{
    if ($a['latest_ts'] == $b['latest_ts']) {
        return 0;
    }

    return ($a['latest_ts'] > $b['latest_ts']) ? -1 : 1;
}


function getLatestMessage($conversation)
{
    if (!isset($conversation['messages']) || count($conversation['messages']) == 0) {
        return null;
    }

    return $conversation['messages'][count($conversation['messages']) - 1];
}

