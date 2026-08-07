<?php

/* Modul: ai.php */

function buildVehicleKnowledgeText($vehicle)
{
    $lines = array();

    $lines[] = 'FAHRZEUGDATEN:';

    $simple = array(
        'title' => 'Fahrzeug',
        'price' => 'Angebotspreis',
        'mileage' => 'Kilometerstand',
        'first_registration' => 'Erstzulassung',
        'power' => 'Leistung',
        'engine' => 'Motor',
        'transmission' => 'Getriebe'
    );

    foreach ($simple as $key => $label) {
        if (isset($vehicle[$key]) && trim($vehicle[$key]) != '') {
            $lines[] = '- ' . $label . ': ' . trim($vehicle[$key]);
        }
    }

    $sections = array(
        'ownership' => 'Besitz/Historie',
        'condition' => 'Zustand',
        'service' => 'Service/Wartung',
        'equipment' => 'Ausstattung',
        'tires' => 'Reifen/Raeder',
        'sales_rules' => 'Verkaufsregeln'
    );

    foreach ($sections as $key => $label) {
        if (isset($vehicle[$key]) && is_array($vehicle[$key])) {
            $lines[] = '';
            $lines[] = strtoupper($label) . ':';
            foreach ($vehicle[$key] as $item) {
                $lines[] = '- ' . $item;
            }
        }
    }

    return implode("\n", $lines);
}


function redactKnownNameForAI($text, $name)
{
    $name = trim($name);

    if ($name == '') {
        return $text;
    }

    $text = str_ireplace($name, '[NAME ENTFERNT]', $text);

    $parts = preg_split('/\\s+/u', $name);

    if (is_array($parts) && count($parts) > 1) {
        $last = trim($parts[count($parts) - 1]);

        if (function_exists('mb_strlen')) {
            $length = mb_strlen($last, 'UTF-8');
        } else {
            $length = strlen($last);
        }

        if ($length >= 4) {
            $text = preg_replace(
                '/\\bFamilie\\s+' . preg_quote($last, '/') . '\\b/iu',
                '[NAME ENTFERNT]',
                $text
            );
        }
    }

    return $text;
}


function buildBuyerHistoryForAI($conversation, $maxMessages)
{
    $messages = isset($conversation['messages']) ? $conversation['messages'] : array();

    if (count($messages) > $maxMessages) {
        $messages = array_slice($messages, count($messages) - $maxMessages);
    }

    $lines = array();

    foreach ($messages as $message) {
        $safeText = sanitizeForAI($message['text']);
        $safeText = redactKnownNameForAI($safeText, isset($conversation['name']) ? $conversation['name'] : '');
        $lines[] = '[' . $message['date'] . '] ' . $safeText;
    }

    return implode("\n\n", $lines);
}


function getGeminiInteractionText($data)
{
    $result = '';

    if (!isset($data['steps']) || !is_array($data['steps'])) {
        return '';
    }

    foreach ($data['steps'] as $step) {
        if (!isset($step['type']) || $step['type'] != 'model_output') {
            continue;
        }

        if (!isset($step['content']) || !is_array($step['content'])) {
            continue;
        }

        foreach ($step['content'] as $content) {
            if (
                isset($content['type']) &&
                $content['type'] == 'text' &&
                isset($content['text'])
            ) {
                if ($result != '') {
                    $result .= "\n";
                }

                $result .= $content['text'];
            }
        }
    }

    return trim($result);
}


function getBuyerFirstName($name)
{
    $name = trim($name);

    if ($name == '') {
        return '';
    }

    $name = preg_replace('/^(Herr\\/Frau|Herr|Frau)\\s+/iu', '', $name);
    $name = trim($name);

    if ($name == '') {
        return '';
    }

    $parts = preg_split('/\\s+/u', $name);

    if (!is_array($parts) || count($parts) == 0) {
        return '';
    }

    $firstName = trim($parts[0]);

    if ($firstName == '' || strpos($firstName, '@') !== false) {
        return '';
    }

    return $firstName;
}


function ensureReplyParagraphs($text)
{
    $text = str_replace(array("\r\n", "\r"), "\n", trim($text));
    $text = preg_replace('/^```(?:text)?\\s*/iu', '', $text);
    $text = preg_replace('/\\s*```$/u', '', $text);
    $text = preg_replace('/[ \\t]+\\n/u', "\n", $text);
    $text = preg_replace('/\\n{3,}/u', "\n\n", $text);
    $text = trim($text);

    if ($text == '') {
        return '';
    }

    if (strpos($text, "\n\n") !== false) {
        return $text;
    }

    $flat = preg_replace('/\\s*\\n\\s*/u', ' ', $text);
    $flat = preg_replace('/[ \\t]{2,}/u', ' ', $flat);
    $flat = trim($flat);

    $length = function_exists('mb_strlen')
        ? mb_strlen($flat, 'UTF-8')
        : strlen($flat);

    if ($length < 300) {
        return $flat;
    }

    $sentences = preg_split('/(?<=[.!?])\\s+(?=[A-ZÄÖÜ0-9])/u', $flat);

    if (!is_array($sentences) || count($sentences) < 4) {
        return $flat;
    }

    $paragraphs = array();
    $current = array();
    $currentLength = 0;

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);

        if ($sentence == '') {
            continue;
        }

        $sentenceLength = function_exists('mb_strlen')
            ? mb_strlen($sentence, 'UTF-8')
            : strlen($sentence);

        if (
            count($current) >= 2 ||
            ($currentLength > 0 && ($currentLength + $sentenceLength) > 300)
        ) {
            $paragraphs[] = implode(' ', $current);
            $current = array();
            $currentLength = 0;
        }

        $current[] = $sentence;
        $currentLength += $sentenceLength + 1;
    }

    if (count($current) > 0) {
        $paragraphs[] = implode(' ', $current);
    }

    if (count($paragraphs) <= 1) {
        return $flat;
    }

    return implode("\n\n", $paragraphs);
}


function geminiGenerateReply($config, $vehicle, $conversation, $replyHint)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'text' => '',
        'http_code' => 0,
        'elapsed' => 0
    );

    if (!isset($config['gemini_api_key']) || trim($config['gemini_api_key']) == '') {
        $result['error'] = 'Gemini API-Key fehlt.';
        return $result;
    }

    $model = 'gemini-3.5-flash-lite';
    if (isset($config['gemini_model']) && trim($config['gemini_model']) != '') {
        $model = trim($config['gemini_model']);
    }

    $history = buildBuyerHistoryForAI($conversation, 5);
    $vehicleText = buildVehicleKnowledgeText($vehicle);

    $replyHint = trim($replyHint);
    $safeReplyHint = '';

    if ($replyHint != '') {
        $safeReplyHint = sanitizeForAI($replyHint);
        $safeReplyHint = redactKnownNameForAI(
            $safeReplyHint,
            isset($conversation['name']) ? $conversation['name'] : ''
        );
    }

    $hintBlock = '';

    if ($safeReplyHint != '') {
        $hintBlock =
            "\n\nPERSOENLICHER HINWEIS DES VERKAEUFERS FUER DIESE ANTWORT:\n" .
            $safeReplyHint . "\n" .
            "Dieser Hinweis stammt direkt vom Verkaeufer und soll sinnvoll in die Antwort einfliessen. " .
            "Ergaenze daraus aber keine weitergehenden Fakten, die dort nicht stehen.";
    }

    $prompt =
        "Du formulierst Antwortentwuerfe fuer einen privaten Autoverkäufer in Deutschland.\n\n" .
        "WICHTIGE REGELN:\n" .
        "- Verwende ausschliesslich die unten angegebenen Fahrzeugdaten.\n" .
        "- Erfinde keine Fakten, Preise, Wartungen, Termine oder Zusagen.\n" .
        "- Halte Inspektion, HU/AU, Reparaturen und andere Wartungsereignisse strikt auseinander. Eine HU/AU ist keine Inspektion.\n" .
        "- Ordne jedes Ereignis exakt dem in den Fahrzeugdaten genannten Datum zu. Verknuepfe getrennte Ereignisse nicht so, dass ein falscher zeitlicher Zusammenhang entsteht.\n" .
        "- Fuer dieses Fahrzeug gilt ausdruecklich: letzte Inspektion Mai 2024; HU/AU separat im Mai 2026; im Jahr 2026 fand keine Inspektion statt.\n" .
        "- Du antwortest IMMER aus Sicht des Fahrzeugbesitzers und privaten Verkäufers in der Ich- oder Wir-Form.\n" .
        "- Schreibe niemals 'der Verkäufer', 'der Besitzer', 'der Anbieter' oder ähnliche Formulierungen über die eigene Person.\n" .
        "- Wenn eine Frage mit den Fahrzeugdaten nicht sicher beantwortbar ist, sage das in genau EINEM kurzen natürlichen Satz in der Ich-Form. Verwende nicht zwei gleichbedeutende Formulierungen hintereinander.\n" .
        "- Beantworte vor allem die tatsächlich gestellten Fragen und wiederhole nicht unnötig sämtliche Fahrzeugdaten.\n" .
        "- Wenn der Interessent eine Begründung für einen Wunsch nennt, gehe kurz und konkret auf diese Begründung ein, statt sie zu ignorieren.\n" .
        "- Wenn der Interessent zu WhatsApp, SMS oder einer anderen externen schriftlichen Kommunikation wechseln möchte, zeige Verständnis, erkläre aber freundlich, dass ich selbst mehrere Anfragen bekomme und den Schriftverkehr zunächst zur besseren Zuordnung und aus Sicherheitsgründen hier über mobile.de/Kleinanzeigen halten möchte. Erwähne bei Bedarf knapp, dass mobile.de dazu rät, bei einem Wechsel auf externe Messenger vorsichtig zu sein. Klinge dabei niemals misstrauisch gegenüber dem konkreten Interessenten. Für einen späteren konkreten Besichtigungstermin oder ein Telefonat darf eine offenere Formulierung verwendet werden, aber keine Kontaktdaten erfinden.\n" .
        "- Schreibe wie ein privater Verkäufer, nicht wie ein Autohaus, Makler oder Verkaufsprospekt.\n" .
        "- Freundlich, serioes, natuerlich, kompakt und nicht übertrieben werblich.\n" .
        "- Gliedere die Antwort gut lesbar in kurze Absätze. Zwischen zwei inhaltlich unterschiedlichen Themen MUSS eine Leerzeile stehen.\n" .
        "- Ein Absatz soll in der Regel nur 1 bis 3 Sätze enthalten. Schreibe keine lange Textwand.\n" .
        "- Beispiele für neue Absätze: Kommunikation/WhatsApp, Zustand/Rost, Wartung/Zahnriemen, Reifen, Termin/Preis. Verschiedene Themen nicht einfach Satz für Satz in einen einzigen Absatz schreiben.\n" .
        "- Wenn der Interessent konkretes Interesse zeigt oder mehrere sachliche Fragen zum Fahrzeug stellt, biete am Ende freundlich eine Besichtigung und auf Wunsch eine Probefahrt nach Terminabsprache an. Formuliere aktiv, z. B. dass wir gerne einen passenden Termin vereinbaren können. Lasse diesen Vorschlag nur weg, wenn bereits ein konkreter Termin vereinbart ist oder er offensichtlich unpassend wäre.\n" .
        "- Keine Telefonnummer oder E-Mail-Adresse erfinden.\n" .
        "- Kein Name des Interessenten notwendig; die Anrede wird lokal ergänzt.\n" .
        "- Gib NUR den eigentlichen Antwortinhalt aus: keine Anrede und keine Grußformel/Signatur.\n" .
        "- Bei Preisfragen keinen grossen Rabatt anbieten. Besichtigung zuerst, Preis nur in vernünftigem Rahmen verhandelbar.\n" .
        "- Gib ausschliesslich den fertigen Antworttext aus, keine Erklaerung.\n\n" .
        $vehicleText . "\n\n" .
        "BISHERIGE NACHRICHTEN DES INTERESSENTEN (personenbezogene Kontaktdaten wurden entfernt):\n" .
        $history .
        $hintBlock . "\n\n" .
        "Formuliere jetzt die passende Antwort auf die letzte Nachricht.";

    $requestData = array(
        'model' => $model,
        'input' => $prompt
    );

    $jsonData = json_encode($requestData, JSON_UNESCAPED_UNICODE);

    if ($jsonData === false) {
        $result['error'] = 'Gemini-Request konnte nicht als JSON erzeugt werden.';
        return $result;
    }

    $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/interactions';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
            'Content-Type: application/json',
            'x-goog-api-key: ' . trim($config['gemini_api_key'])
        )
    );

    $start = microtime(true);
    $raw = curl_exec($ch);
    $result['elapsed'] = round(microtime(true) - $start, 2);

    if ($raw === false) {
        $result['error'] = curl_error($ch);
        curl_close($ch);
        return $result;
    }

    $result['http_code'] = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        $result['error'] = 'Gemini hat keine gültige JSON-Antwort geliefert.';
        return $result;
    }

    if (isset($data['error']) && isset($data['error']['message'])) {
        $result['error'] = $data['error']['message'];
        return $result;
    }

    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        $result['error'] = 'Gemini HTTP-Fehler ' . $result['http_code'];
        return $result;
    }

    $text = getGeminiInteractionText($data);
    $text = ensureReplyParagraphs($text);

    if ($text == '') {
        $result['error'] = 'Gemini hat keinen Antworttext geliefert.';
        return $result;
    }

    $sellerName = isset($vehicle['seller_name']) && trim($vehicle['seller_name']) != ''
        ? trim($vehicle['seller_name'])
        : 'Thomas Radebold';

    $buyerName = isset($conversation['name']) ? trim($conversation['name']) : '';
    $buyerFirstName = getBuyerFirstName($buyerName);

    if ($buyerFirstName != '') {
        $greeting = 'Hallo ' . $buyerFirstName . ',';
    } else {
        $greeting = 'Hallo,';
    }

    $result['ok'] = true;
    $result['text'] = $greeting . "\n\n" . trim($text) . "\n\nViele Grüße\n" . $sellerName;

    return $result;
}

