<?php

/*
 * =========================================================
 * mobile.de Verkaufszentrale
 * Version 2.6.0
 * PHP 5.6 kompatibel
 *
 * Funktionen:
 * - Gmail-Nachrichten auswerten
 * - mobile.de / Kleinanzeigen Nachrichten gruppieren
 * - Nachrichtentext bereinigen
 * - Gemini Antwortentwurf erstellen
 * - Entwurf lokal speichern / bearbeiten
 * - Gmail-Entwurf im passenden mobile.de-Thread anlegen
 * - Status lokal verwalten
 * - Bei „Beantwortet / gesendet“ Gmail-Nachrichten archivieren und ausblenden
 * - Bei „Kein Interesse“ Gmail-Nachrichten in den Papierkorb verschieben
 *
 * Kein automatisches Senden.
 * =========================================================
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

if (!defined('MOBILE_APP_VERSION')) {
    define('MOBILE_APP_VERSION', '2.6.0');
}

$configFile = __DIR__ . '/_private/config.php';
$functionsFile = __DIR__ . '/functions.php';
$vehicleFile = __DIR__ . '/vehicle.php';

if (!file_exists($configFile)) {
    die('Konfigurationsdatei fehlt: ' . htmlspecialchars($configFile, ENT_QUOTES, 'UTF-8'));
}

if (!file_exists($functionsFile)) {
    die('functions.php fehlt.');
}

if (!file_exists($vehicleFile)) {
    die('vehicle.php fehlt.');
}

require $functionsFile;
require $configFile;
require $vehicleFile;


/*
 * ---------------------------------------------------------
 * CSRF Schutz
 * ---------------------------------------------------------
 */

if (!isset($_SESSION['mobile_csrf']) || $_SESSION['mobile_csrf'] == '') {
    $_SESSION['mobile_csrf'] = sha1(uniqid(mt_rand(), true));
}

$csrf = $_SESSION['mobile_csrf'];


/*
 * ---------------------------------------------------------
 * App State laden
 * ---------------------------------------------------------
 */

$state = loadAppState();

$flashType = '';
$flashMessage = '';
$selectedKey = '';

if (isset($_GET['conversation'])) {
    $selectedKey = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['conversation']));
}


/*
 * ---------------------------------------------------------
 * Gmail lesen
 * ---------------------------------------------------------
 */

$mailResult = loadMobileConversations($config);
$conversations = $mailResult['conversations'];


/*
 * ---------------------------------------------------------
 * POST Aktionen
 * ---------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $postedCsrf = isset($_POST['csrf']) ? $_POST['csrf'] : '';

    if ($postedCsrf !== $csrf) {
        $flashType = 'error';
        $flashMessage = 'Sicherheitspruefung fehlgeschlagen. Seite bitte neu laden.';
    } else {

        $action = isset($_POST['action']) ? $_POST['action'] : '';

        /*
         * Globale Aktion: Anwendung direkt aus dem GitHub-Repository aktualisieren.
         * _private/config.php und data/status.json werden dabei niemals angefasst.
         */
        if ($action == 'install_update') {

            $updateResult = installGitHubUpdate(
                __DIR__,
                MOBILE_APP_VERSION,
                $config
            );

            if ($updateResult['ok']) {
                header('Location: index.php?updated=' . rawurlencode($updateResult['version']));
                exit;
            }

            $flashType = 'error';
            $flashMessage = 'Update fehlgeschlagen: ' . $updateResult['error'];

        } else {

            $key = isset($_POST['conversation_key'])
                ? preg_replace('/[^a-f0-9]/', '', strtolower($_POST['conversation_key']))
                : '';

            $selectedKey = $key;

            if ($key == '' || !isset($conversations[$key])) {
                $flashType = 'error';
                $flashMessage = 'Das Gespräch wurde nicht gefunden. Bitte aktualisieren.';
            } else {

                if ($action == 'generate') {

                $replyHint = isset($_POST['reply_hint']) ? trim($_POST['reply_hint']) : '';

                /*
                 * Den persoenlichen Hinweis pro Gespraech merken. So bleibt er
                 * bei einer erneuten Antwortgenerierung erhalten.
                 */
                setConversationState(
                    $state,
                    $key,
                    array(
                        'reply_hint' => $replyHint
                    )
                );

                $gemini = geminiGenerateReply(
                    $config,
                    $vehicle,
                    $conversations[$key],
                    $replyHint
                );

                if ($gemini['ok']) {
                    setConversationState(
                        $state,
                        $key,
                        array(
                            'status' => 'entwurf',
                            'draft' => $gemini['text'],
                            'draft_updated' => date('Y-m-d H:i:s'),
                            'reply_hint' => $replyHint
                        )
                    );

                    if (saveAppState($state)) {
                        $flashType = 'success';
                        $flashMessage = 'Gemini-Antwort wurde erstellt (' . h($gemini['elapsed']) . ' s).';
                    } else {
                        $flashType = 'error';
                        $flashMessage = 'Antwort wurde erstellt, konnte aber nicht in data/status.json gespeichert werden.';
                    }
                } else {
                    $flashType = 'error';
                    $flashMessage = 'Gemini-Fehler: ' . $gemini['error'];
                }

            } elseif ($action == 'save_draft') {

                $draft = isset($_POST['draft']) ? trim($_POST['draft']) : '';

                setConversationState(
                    $state,
                    $key,
                    array(
                        'status' => $draft != '' ? 'entwurf' : 'offen',
                        'draft' => $draft,
                        'draft_updated' => date('Y-m-d H:i:s')
                    )
                );

                if (saveAppState($state)) {
                    $flashType = 'success';
                    $flashMessage = 'Entwurf gespeichert.';
                } else {
                    $flashType = 'error';
                    $flashMessage = 'Entwurf konnte nicht gespeichert werden.';
                }

            } elseif ($action == 'create_gmail_draft') {

                $draft = isset($_POST['draft']) ? trim($_POST['draft']) : '';

                /*
                 * Den aktuell sichtbaren Text zuerst lokal sichern, damit
                 * auch manuelle Änderungen vor dem Gmail-Entwurf erhalten bleiben.
                 */
                setConversationState(
                    $state,
                    $key,
                    array(
                        'status' => $draft != '' ? 'entwurf' : 'offen',
                        'draft' => $draft,
                        'draft_updated' => date('Y-m-d H:i:s')
                    )
                );

                if ($draft == '') {
                    $flashType = 'error';
                    $flashMessage = 'Der Antwortentwurf ist leer.';
                } else {
                    $gmailDraft = createGmailDraft(
                        $config,
                        $vehicle,
                        $conversations[$key],
                        $draft
                    );

                    if ($gmailDraft['ok']) {
                        setConversationState(
                            $state,
                            $key,
                            array(
                                'status' => 'entwurf',
                                'draft' => $draft,
                                'draft_updated' => date('Y-m-d H:i:s'),
                                'gmail_draft_created' => date('Y-m-d H:i:s'),
                                'gmail_draft_verified' => !empty($gmailDraft['verified']) ? '1' : '0',
                                'gmail_draft_id' => isset($gmailDraft['draft_id']) ? $gmailDraft['draft_id'] : '',
                                'gmail_draft_thread_id' => isset($gmailDraft['thread_id']) ? $gmailDraft['thread_id'] : '',
                                'gmail_draft_recipient' => isset($gmailDraft['recipient']) ? $gmailDraft['recipient'] : ''
                            )
                        );

                        if (saveAppState($state)) {
                            $flashType = 'success';
                            $flashMessage = 'Gmail-Entwurf wurde als echter Gmail-Entwurf im Antwort-Thread angelegt. Die Nachricht wurde NICHT gesendet.';
                        } else {
                            $flashType = 'error';
                            $flashMessage = 'Gmail-Entwurf wurde angelegt, aber der lokale Status konnte nicht gespeichert werden.';
                        }
                    } else {
                        saveAppState($state);
                        $flashType = 'error';
                        $flashMessage = 'Gmail-Entwurf konnte nicht angelegt werden: ' . $gmailDraft['error'];
                    }
                }

            } elseif ($action == 'set_status') {

                $status = isset($_POST['status'])
                    ? validStatus($_POST['status'])
                    : 'offen';

                if ($status == 'beantwortet') {

                    /*
                     * "Beantwortet / gesendet":
                     * - aktuelle mobile.de-Mails dieses Gesprächs in Gmail archivieren
                     * - Gespräch lokal ausblenden
                     * - lokalen Entwurf behalten
                     *
                     * Kommt später eine neue Käufernachricht, wird das Gespräch
                     * automatisch wieder als "Neu" aktiviert.
                     */
                    $archiveResult = moveConversationToGmailArchive(
                        $config,
                        $conversations[$key]
                    );

                    setConversationState(
                        $state,
                        $key,
                        array(
                            'status' => 'beantwortet',
                            'answered_at' => date('Y-m-d H:i:s'),
                            'answered_at_ts' => time()
                        )
                    );

                    $saved = saveAppState($state);

                    if ($archiveResult['ok'] && $saved) {
                        $flashType = 'success';
                        $flashMessage = 'Als beantwortet markiert. Die zugehörigen Gmail-Nachrichten wurden archiviert und das Gespräch wird bis zu einer neuen Käufernachricht ausgeblendet.';
                    } elseif (!$archiveResult['ok'] && $saved) {
                        $flashType = 'error';
                        $flashMessage = 'Als beantwortet gespeichert und ausgeblendet, aber die Gmail-Nachrichten konnten nicht archiviert werden: ' . $archiveResult['error'];
                    } elseif ($archiveResult['ok'] && !$saved) {
                        $flashType = 'error';
                        $flashMessage = 'Die Gmail-Nachrichten wurden archiviert, aber der lokale Status konnte nicht gespeichert werden.';
                    } else {
                        $flashType = 'error';
                        $flashMessage = 'Status konnte nicht vollständig gesetzt werden. Gmail: ' . $archiveResult['error'];
                    }

                } elseif ($status == 'erledigt') {

                    /*
                     * "Kein Interesse":
                     * - aktuelle mobile.de-Mails dieses Gesprächs in Gmail in den Papierkorb verschieben
                     * - Gespräch lokal ausblenden
                     * - lokalen Entwurf verwerfen
                     *
                     * Die mobile.de-Plattform selbst wird dabei NICHT verändert.
                     */
                    $trashResult = moveConversationToGmailTrash(
                        $config,
                        $conversations[$key]
                    );

                    setConversationState(
                        $state,
                        $key,
                        array(
                            'status' => 'erledigt',
                            'draft' => '',
                            'draft_updated' => '',
                            'no_interest_at' => date('Y-m-d H:i:s'),
                            'no_interest_at_ts' => time()
                        )
                    );

                    $saved = saveAppState($state);

                    if ($trashResult['ok'] && $saved) {
                        $flashType = 'success';
                        $flashMessage = 'Kein Interesse gesetzt. Die zugehörigen Gmail-Nachrichten wurden in den Papierkorb verschoben und das Gespräch wird nicht mehr angezeigt.';
                    } elseif (!$trashResult['ok'] && $saved) {
                        $flashType = 'error';
                        $flashMessage = 'Kein Interesse wurde gespeichert und das Gespräch ausgeblendet, aber die Gmail-Nachrichten konnten nicht in den Papierkorb verschoben werden: ' . $trashResult['error'];
                    } elseif ($trashResult['ok'] && !$saved) {
                        $flashType = 'error';
                        $flashMessage = 'Die Gmail-Nachrichten wurden in den Papierkorb verschoben, aber der lokale Status konnte nicht gespeichert werden.';
                    } else {
                        $flashType = 'error';
                        $flashMessage = 'Status konnte nicht vollständig gesetzt werden. Gmail: ' . $trashResult['error'];
                    }

                } else {

                    setConversationState(
                        $state,
                        $key,
                        array('status' => $status)
                    );

                    if (saveAppState($state)) {
                        $flashType = 'success';
                        $flashMessage = 'Status aktualisiert.';
                    } else {
                        $flashType = 'error';
                        $flashMessage = 'Status konnte nicht gespeichert werden.';
                    }
                }
            }
        }
    }
    }
}


/* State nach POST ggf. neu laden */
$state = loadAppState();


/*
 * ---------------------------------------------------------
 * Zaehler
 * ---------------------------------------------------------
 */

$countNew = 0;
$countOpen = 0;
$countDraft = 0;
$countAnswered = 0;
$countVisit = 0;
$countDone = 0;
$countActiveConversations = 0;
$countActiveMessages = 0;

foreach ($conversations as $key => $conversation) {
    $local = getConversationState($state, $key);
    $status = isset($local['status']) && $local['status'] != ''
        ? validStatus($local['status'])
        : ($conversation['unread'] ? 'neu' : 'offen');

    /*
     * Kommt nach einem abgeschlossenen Gespräch eine neue Nachricht,
     * wird es automatisch wieder als "Neu" aktiviert.
     */
    $reactivateAfterTs = 0;

    if (
        $status == 'beantwortet' &&
        isset($local['answered_at_ts']) &&
        intval($local['answered_at_ts']) > 0
    ) {
        $reactivateAfterTs = intval($local['answered_at_ts']);
    } elseif (
        $status == 'erledigt' &&
        isset($local['no_interest_at_ts']) &&
        intval($local['no_interest_at_ts']) > 0
    ) {
        $reactivateAfterTs = intval($local['no_interest_at_ts']);
    }

    if (
        $reactivateAfterTs > 0 &&
        isset($conversation['latest_ts']) &&
        intval($conversation['latest_ts']) > $reactivateAfterTs
    ) {
        $status = 'neu';

        setConversationState(
            $state,
            $key,
            array(
                'status' => 'neu',
                'answered_at' => '',
                'answered_at_ts' => 0,
                'no_interest_at' => '',
                'no_interest_at_ts' => 0
            )
        );

        saveAppState($state);
    }

    if ($status == 'beantwortet' || $status == 'erledigt') {
        continue;
    }

    $countActiveConversations++;
    $countActiveMessages += count($conversation['messages']);

    if ($status == 'neu') $countNew++;
    if ($status == 'offen') $countOpen++;
    if ($status == 'entwurf') $countDraft++;
    if ($status == 'besichtigung') $countVisit++;
}

/*
 * Abgeschlossene Gespräche aus dem lokalen Status zählen, weil deren
 * Gmail-Nachrichten nicht mehr im Posteingang liegen.
 */
if (
    isset($state['conversations']) &&
    is_array($state['conversations'])
) {
    foreach ($state['conversations'] as $storedConversation) {
        if (!isset($storedConversation['status'])) {
            continue;
        }

        $storedStatus = validStatus($storedConversation['status']);

        if ($storedStatus == 'beantwortet') {
            $countAnswered++;
        }

        if ($storedStatus == 'erledigt') {
            $countDone++;
        }
    }
}


/*
 * ---------------------------------------------------------
 * Datenschutz / Konfiguration
 * ---------------------------------------------------------
 */

$geminiConfigured = isset($config['gemini_api_key']) && trim($config['gemini_api_key']) != '';
$gmailBridgeConfigured = isset($config['gmail_bridge_url']) && trim($config['gmail_bridge_url']) != '' && isset($config['gmail_bridge_token']) && trim($config['gmail_bridge_token']) != '';
$dataWritable = is_writable(__DIR__ . '/data') || is_writable(getStateFilePath());

/*
 * GitHub-Updatecheck. Standardmäßig wird das Ergebnis 30 Minuten gecacht,
 * damit der Seitenaufruf nicht jedes Mal von GitHub abhängt.
 */
$forceUpdateCheck = isset($_GET['check_update']) && $_GET['check_update'] == '1';
$updateInfo = getGitHubUpdateInfo(
    MOBILE_APP_VERSION,
    __DIR__,
    $config,
    $forceUpdateCheck
);

if (
    isset($_GET['updated']) &&
    trim($_GET['updated']) != '' &&
    $flashMessage == ''
) {
    $flashType = 'success';
    $flashMessage = 'Update auf Version ' . trim($_GET['updated']) . ' erfolgreich installiert.';
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>mobile.de Verkaufszentrale</title>

<link rel="stylesheet" href="assets/app.css?v=<?php echo rawurlencode(MOBILE_APP_VERSION); ?>">
</head>
<body>

<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">S</div>
            <div>
                <div class="brand-title">Sharan</div>
                <div class="brand-sub">Verkaufszentrale</div>
            </div>
        </div>

        <div class="side-section-label">Arbeitsliste</div>
        <div class="side-nav">
            <div class="side-item active"><span>Inbox</span><span class="count"><?php echo intval($countActiveConversations); ?></span></div>
            <div class="side-item"><span>Neu</span><span class="count"><?php echo intval($countNew); ?></span></div>
            <div class="side-item"><span>Offen</span><span class="count"><?php echo intval($countOpen); ?></span></div>
            <div class="side-item"><span>Entwürfe</span><span class="count"><?php echo intval($countDraft); ?></span></div>
            <div class="side-item"><span>Besichtigung</span><span class="count"><?php echo intval($countVisit); ?></span></div>
        </div>

        <div class="side-section-label">Archiv</div>
        <div class="side-nav">
            <div class="side-item"><span>Beantwortet</span><span class="count"><?php echo intval($countAnswered); ?></span></div>
            <div class="side-item"><span>Kein Interesse</span><span class="count"><?php echo intval($countDone); ?></span></div>
        </div>

        <div class="system-status">
            <div class="system-title">System</div>
            <div class="system-row"><span class="system-dot <?php echo $mailResult['ok'] ? 'ok' : 'bad'; ?>"></span> Gmail</div>
            <div class="system-row"><span class="system-dot <?php echo $gmailBridgeConfigured ? 'ok' : 'bad'; ?>"></span> Entwürfe</div>
            <div class="system-row"><span class="system-dot <?php echo $geminiConfigured ? 'ok' : 'bad'; ?>"></span> Gemini</div>
            <div class="system-row"><span class="system-dot <?php echo $dataWritable ? 'ok' : 'bad'; ?>"></span> Speicher</div>
            <div class="system-row">
                <div class="system-version">
                    <span class="system-dot <?php echo !empty($updateInfo['available']) ? 'warn' : (!empty($updateInfo['error']) ? 'bad' : 'ok'); ?>"></span>
                    <span>v<strong><?php echo h(MOBILE_APP_VERSION); ?></strong></span>
                    <a class="update-link" href="index.php?check_update=1" title="GitHub erneut prüfen">prüfen</a>
                </div>
            </div>
        </div>
    </aside>

    <main class="main">
        <header class="main-head">
            <div>
                <h1 class="page-title">Inbox</h1>
                <div class="page-sub"><?php echo intval($countActiveConversations); ?> offene Gespräche · <?php echo intval($countActiveMessages); ?> Nachrichten</div>
            </div>
            <div class="head-actions">
                <?php if (!empty($updateInfo['available'])) { ?>
                    <form class="update-form" method="post" action="index.php" onsubmit="return confirmAppUpdate();">
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="action" value="install_update">
                        <button class="update-btn" type="submit">Update <?php echo h($updateInfo['latest_version']); ?></button>
                    </form>
                <?php } ?>
                <a class="refresh-btn" href="index.php" title="Nachrichten aktualisieren">↻ <span>Aktualisieren</span></a>
            </div>
        </header>

        <?php if (!$mailResult['ok']) { ?>
            <div class="flash flash-error"><strong>Gmail:</strong> <?php echo h($mailResult['error']); ?></div>
        <?php } ?>

        <?php if ($flashMessage != '') { ?>
            <div class="flash <?php echo $flashType == 'success' ? 'flash-success' : 'flash-error'; ?>"><?php echo h($flashMessage); ?></div>
        <?php } ?>

        <?php if ($countActiveConversations == 0 && $mailResult['ok']) { ?>
            <div class="empty-state">
                <div class="empty-inner">
                    <div class="empty-icon">✓</div>
                    <div class="empty-title">Alles erledigt</div>
                    <div class="empty-text">Aktuell gibt es keine offenen Käuferanfragen. Neue mobile.de- oder Kleinanzeigen-Nachrichten erscheinen hier automatisch.</div>
                </div>
            </div>
        <?php } ?>

        <div class="feed">
        <?php foreach ($conversations as $key => $conversation) { ?>
            <?php
                $latest = getLatestMessage($conversation);
                $local = getConversationState($state, $key);
                $status = isset($local['status']) && $local['status'] != ''
                    ? validStatus($local['status'])
                    : ($conversation['unread'] ? 'neu' : 'offen');
                $draft = isset($local['draft']) ? $local['draft'] : '';
                $replyHint = isset($local['reply_hint']) ? $local['reply_hint'] : '';
                $isSelected = ($selectedKey == $key);
                if ($status == 'beantwortet' || $status == 'erledigt') { continue; }
                $displayName = $conversation['name'] != '' ? $conversation['name'] : 'Interessent';
                $initial = strtoupper(substr($displayName, 0, 1));
            ?>

            <article class="thread-card<?php echo $isSelected ? ' selected' : ''; ?>" id="c-<?php echo h($key); ?>">
                <div class="thread-head">
                    <div class="person-block">
                        <div class="avatar"><?php echo h($initial); ?></div>
                        <div>
                            <div class="person-name"><?php echo h($displayName); ?></div>
                            <div class="person-meta"><?php echo $latest ? h($latest['date']) : '-'; ?> · <?php echo intval(count($conversation['messages'])); ?> Nachricht(en)</div>
                        </div>
                    </div>
                    <span class="status-badge <?php echo h(getStatusClass($status)); ?>"><?php echo h(getStatusLabel($status)); ?></span>
                </div>

                <div class="subject" title="<?php echo h($conversation['subject']); ?>"><?php echo h($conversation['subject']); ?></div>

                <div class="thread-body">
                    <?php if ($latest) { ?>
                        <div class="message-panel">
                            <div class="message-date">Neueste Nachricht · <?php echo h($latest['date']); ?></div>
                            <?php echo h($latest['text']); ?>
                        </div>
                    <?php } ?>

                    <div class="compose-row">
                        <form method="post" action="index.php?conversation=<?php echo h($key); ?>#c-<?php echo h($key); ?>">
                            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                            <input type="hidden" name="action" value="generate">
                            <input type="hidden" name="conversation_key" value="<?php echo h($key); ?>">

                            <div class="hint-card">
                                <div class="hint-label">
                                    <span class="hint-title">Persönlicher Hinweis</span>
                                    <span class="hint-sub">optional · nur für diese Antwort</span>
                                </div>
                                <textarea class="reply-hint" name="reply_hint" placeholder="z. B. Besichtigung nur ab 17 Uhr · Winterräder erwähnen · beim Preis noch nicht entgegenkommen"><?php echo h($replyHint); ?></textarea>
                            </div>
                        </form>

                        <div class="quick-actions">
                            <button class="btn btn-primary" type="button" onclick="this.closest('.compose-row').querySelector('form').submit();"<?php echo !$geminiConfigured ? ' disabled' : ''; ?>><?php echo $draft != '' ? 'Neu formulieren' : 'Antwort erstellen'; ?></button>
                        </div>
                    </div>

                    <div class="thread-tools">
                        <div></div>
                        <form method="post" class="inline-form" action="index.php?conversation=<?php echo h($key); ?>#c-<?php echo h($key); ?>">
                            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                            <input type="hidden" name="action" value="set_status">
                            <input type="hidden" name="conversation_key" value="<?php echo h($key); ?>">
                            <select name="status" class="status-select" onfocus="rememberStatusIndex(this);" onchange="return statusChanged(this);">
                                <option value="neu"<?php echo $status == 'neu' ? ' selected' : ''; ?>>Neu</option>
                                <option value="offen"<?php echo $status == 'offen' ? ' selected' : ''; ?>>Offen</option>
                                <option value="entwurf"<?php echo $status == 'entwurf' ? ' selected' : ''; ?>>Antwort vorbereitet</option>
                                <option value="beantwortet"<?php echo $status == 'beantwortet' ? ' selected' : ''; ?>>Beantwortet / gesendet</option>
                                <option value="besichtigung"<?php echo $status == 'besichtigung' ? ' selected' : ''; ?>>Besichtigung</option>
                                <option value="erledigt"<?php echo $status == 'erledigt' ? ' selected' : ''; ?>>Kein Interesse</option>
                            </select>
                        </form>
                    </div>

                    <?php if (count($conversation['messages']) > 1) { ?>
                        <details class="history-details"<?php echo $isSelected ? ' open' : ''; ?>>
                            <summary>Verlauf · <?php echo intval(count($conversation['messages'])); ?> Nachrichten</summary>
                            <?php foreach ($conversation['messages'] as $historyMessage) { ?>
                                <div class="history-item">
                                    <div class="message-panel">
                                        <div class="message-date"><?php echo h($historyMessage['date']); ?></div>
                                        <?php echo h($historyMessage['text']); ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </details>
                    <?php } ?>

                    <?php if ($draft != '') { ?>
                        <div class="draft-card">
                            <div class="draft-head">
                                <div class="draft-title">Antwortentwurf</div>
                                <div class="draft-state">prüfen · bearbeiten · in Gmail speichern</div>
                            </div>
                            <div class="draft-body">
                                <form method="post" action="index.php?conversation=<?php echo h($key); ?>#c-<?php echo h($key); ?>">
                                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                                    <input type="hidden" name="conversation_key" value="<?php echo h($key); ?>">
                                    <textarea class="draft-text" id="draft-<?php echo h($key); ?>" name="draft"><?php echo h($draft); ?></textarea>
                                    <div class="draft-actions">
                                        <button type="submit" name="action" value="create_gmail_draft" class="btn btn-primary" onclick="return confirmGmailDraft();"<?php echo !$gmailBridgeConfigured ? ' disabled title="Gmail-Bridge noch nicht eingerichtet"' : ''; ?>>In Gmail speichern</button>
                                        <button type="submit" name="action" value="save_draft" class="btn btn-success">Lokal speichern</button>
                                        <button type="button" class="btn btn-dark" onclick="copyDraft('draft-<?php echo h($key); ?>', this);">Kopieren</button>
                                    </div>
                                </form>

                                <?php if (isset($local['draft_updated']) && $local['draft_updated'] != '') { ?>
                                    <div class="small-note">Lokal gespeichert: <?php echo h($local['draft_updated']); ?></div>
                                <?php } ?>

                                <?php if (isset($local['gmail_draft_created']) && $local['gmail_draft_created'] != '') { ?>
                                    <div class="small-note">
                                        Gmail-Entwurf: <?php echo h($local['gmail_draft_created']); ?>
                                        <?php if (isset($local['gmail_draft_verified']) && $local['gmail_draft_verified'] == '1') { ?> · bestätigt<?php } ?>
                                        <?php if (isset($local['gmail_draft_recipient']) && $local['gmail_draft_recipient'] != '') { ?><br>Empfänger: <?php echo h($local['gmail_draft_recipient']); ?><?php } ?>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </article>
        <?php } ?>
        </div>

        <div class="footer">Version <?php echo h(MOBILE_APP_VERSION); ?> · PHP <?php echo h(PHP_VERSION); ?> · GitHub-Updates aktiv · kein automatischer Versand</div>
    </main>
</div>

<script src="assets/app.js?v=<?php echo rawurlencode(MOBILE_APP_VERSION); ?>"></script>

</body>
</html>
