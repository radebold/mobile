<?php

/* Modul: state.php */


function getStateFilePath()
{
    return dirname(__DIR__) . '/data/status.json';
}


function loadAppState()
{
    $default = array('conversations' => array());
    $file = getStateFilePath();

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

    if (!isset($data['conversations']) || !is_array($data['conversations'])) {
        $data['conversations'] = array();
    }

    return $data;
}


function saveAppState($state)
{
    $file = getStateFilePath();
    $dir = dirname($file);

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    return @file_put_contents($file, $json, LOCK_EX) !== false;
}


function getConversationState($state, $key)
{
    if (
        isset($state['conversations']) &&
        isset($state['conversations'][$key]) &&
        is_array($state['conversations'][$key])
    ) {
        return $state['conversations'][$key];
    }

    return array(
        'status' => '',
        'draft' => '',
        'draft_updated' => '',
        'reply_hint' => ''
    );
}


function setConversationState(&$state, $key, $values)
{
    if (!isset($state['conversations']) || !is_array($state['conversations'])) {
        $state['conversations'] = array();
    }

    if (!isset($state['conversations'][$key]) || !is_array($state['conversations'][$key])) {
        $state['conversations'][$key] = array();
    }

    foreach ($values as $name => $value) {
        $state['conversations'][$key][$name] = $value;
    }
}


function validStatus($status)
{
    $allowed = array('neu', 'offen', 'entwurf', 'beantwortet', 'besichtigung', 'erledigt');
    return in_array($status, $allowed) ? $status : 'offen';
}


function getStatusLabel($status)
{
    $labels = array(
        'neu' => 'Neu',
        'offen' => 'Offen',
        'entwurf' => 'Antwort vorbereitet',
        'beantwortet' => 'Beantwortet / gesendet',
        'besichtigung' => 'Besichtigung',
        'erledigt' => 'Kein Interesse'
    );

    return isset($labels[$status]) ? $labels[$status] : 'Offen';
}


function getStatusClass($status)
{
    $classes = array(
        'neu' => 'status-neu',
        'offen' => 'status-offen',
        'entwurf' => 'status-entwurf',
        'beantwortet' => 'status-beantwortet',
        'besichtigung' => 'status-besichtigung',
        'erledigt' => 'status-erledigt'
    );

    return isset($classes[$status]) ? $classes[$status] : 'status-offen';
}

