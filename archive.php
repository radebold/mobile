<?php

/*
 * =========================================================
 * Archivansicht fuer die mobile.de Verkaufszentrale
 * PHP 5.6 kompatibel
 *
 * Zweck:
 * - lokal als beantwortet / kein Interesse markierte Gespraeche anzeigen
 * - Gespraeche, deren Eingangsmail noch im Gmail-Posteingang liegt,
 *   wieder auf "Offen" setzen
 * =========================================================
 */

session_start();
header('Content-Type: text/html; charset=utf-8');

$versionFile = __DIR__ . '/VERSION';
$appVersion = '2.6.0';
if (file_exists($versionFile)) {
    $versionFromFile = trim(@file_get_contents($versionFile));
    if ($versionFromFile != '') {
        $appVersion = $versionFromFile;
    }
}

if (!defined('MOBILE_APP_VERSION')) {
    define('MOBILE_APP_VERSION', $appVersion);
}

$configFile = __DIR__ . '/_private/config.php';
$functionsFile = __DIR__ . '/functions.php';

if (!file_exists($configFile) || !file_exists($functionsFile)) {
    die('Konfiguration oder functions.php fehlt.');
}

require $functionsFile;
require $configFile;

if (!isset($_SESSION['mobile_csrf']) || $_SESSION['mobile_csrf'] == '') {
    $_SESSION['mobile_csrf'] = sha1(uniqid(mt_rand(), true));
}
$csrf = $_SESSION['mobile_csrf'];

$view = isset($_GET['view']) ? trim($_GET['view']) : 'beantwortet';
if ($view != 'beantwortet' && $view != 'erledigt') {
    $view = 'beantwortet';
}

$state = loadAppState();
$mailResult = loadMobileConversations($config);
$conversations = $mailResult['conversations'];

$flashType = '';
$flashMessage = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $postedCsrf = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    $key = isset($_POST['conversation_key'])
        ? preg_replace('/[^a-f0-9]/', '', strtolower($_POST['conversation_key']))
        : '';

    if ($postedCsrf !== $csrf) {
        $flashType = 'error';
        $flashMessage = 'Sicherheitspruefung fehlgeschlagen. Seite bitte neu laden.';
    } elseif ($key == '') {
        $flashType = 'error';
        $flashMessage = 'Das Gespraech konnte nicht zugeordnet werden.';
    } elseif (!isset($conversations[$key])) {
        $flashType = 'error';
        $flashMessage = 'Die Eingangsmail liegt nicht im Gmail-Posteingang. Bitte die betreffende Mail in Gmail zuerst wieder in den Posteingang verschieben und diese Seite danach aktualisieren.';
    } else {
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

        if (saveAppState($state)) {
            header('Location: index.php?conversation=' . rawurlencode($key) . '#c-' . rawurlencode($key));
            exit;
        }

        $flashType = 'error';
        $flashMessage = 'Das Gespraech konnte lokal nicht wieder geoeffnet werden.';
    }
}

$countAnswered = 0;
$countDone = 0;
$countInboxAnswered = 0;
$countInboxDone = 0;

if (isset($state['conversations']) && is_array($state['conversations'])) {
    foreach ($state['conversations'] as $storedKey => $storedConversation) {
        if (!is_array($storedConversation) || !isset($storedConversation['status'])) {
            continue;
        }

        $storedStatus = validStatus($storedConversation['status']);
        if ($storedStatus == 'beantwortet') {
            $countAnswered++;
            if (isset($conversations[$storedKey])) $countInboxAnswered++;
        }
        if ($storedStatus == 'erledigt') {
            $countDone++;
            if (isset($conversations[$storedKey])) $countInboxDone++;
        }
    }
}

$visibleItems = array();
foreach ($conversations as $key => $conversation) {
    $local = getConversationState($state, $key);
    $status = isset($local['status']) && $local['status'] != ''
        ? validStatus($local['status'])
        : ($conversation['unread'] ? 'neu' : 'offen');

    if ($status == $view) {
        $visibleItems[$key] = $conversation;
    }
}

$title = $view == 'beantwortet' ? 'Beantwortet' : 'Kein Interesse';
$totalCount = $view == 'beantwortet' ? $countAnswered : $countDone;
$inboxCount = $view == 'beantwortet' ? $countInboxAnswered : $countInboxDone;

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo h($title); ?> · mobile.de Verkaufszentrale</title>
<link rel="stylesheet" href="assets/app.css?v=<?php echo rawurlencode(MOBILE_APP_VERSION); ?>">
<style>
a.side-item { text-decoration:none; }
.archive-note { margin:0 6px 12px 18px; padding:12px 14px; border:1px solid #e3e5ea; border-radius:12px; background:#fff; color:#59606d; font-size:12px; line-height:1.55; }
.archive-list { display:grid; gap:12px; padding-left:18px; }
.archive-card { border:1px solid var(--line); border-radius:17px; background:var(--panel); box-shadow:0 5px 20px rgba(20,23,31,.035); padding:17px 18px; }
.archive-card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; }
.archive-card-name { font-size:15px; font-weight:790; }
.archive-card-meta { margin-top:3px; color:var(--muted); font-size:10px; }
.archive-card-subject { margin-top:12px; color:#4b5260; font-size:12px; line-height:1.45; }
.archive-card-message { margin-top:12px; padding:13px 14px; border:1px solid #eceef2; border-radius:12px; background:#fafbfc; color:#282d37; font-size:12px; line-height:1.55; white-space:pre-wrap; }
.archive-card-actions { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
.archive-empty { margin-left:18px; min-height:220px; border:1px solid var(--line); border-radius:18px; background:#fff; display:grid; place-items:center; text-align:center; color:var(--muted); font-size:12px; padding:25px; }
@media (max-width:920px) { .archive-list { padding-left:0; } .archive-note, .archive-empty { margin-left:0; } }
</style>
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
            <a class="side-item" href="index.php"><span>Inbox</span><span class="count">←</span></a>
        </div>

        <div class="side-section-label">Archiv</div>
        <div class="side-nav">
            <a class="side-item<?php echo $view == 'beantwortet' ? ' active' : ''; ?>" href="archive.php?view=beantwortet"><span>Beantwortet</span><span class="count"><?php echo intval($countAnswered); ?></span></a>
            <a class="side-item<?php echo $view == 'erledigt' ? ' active' : ''; ?>" href="archive.php?view=erledigt"><span>Kein Interesse</span><span class="count"><?php echo intval($countDone); ?></span></a>
        </div>

        <div class="system-status">
            <div class="system-title">System</div>
            <div class="system-row"><span class="system-dot <?php echo $mailResult['ok'] ? 'ok' : 'bad'; ?>"></span> Gmail</div>
            <div class="system-row"><span class="system-dot ok"></span> v<?php echo h(MOBILE_APP_VERSION); ?></div>
        </div>
    </aside>

    <main class="main">
        <header class="main-head">
            <div>
                <h1 class="page-title"><?php echo h($title); ?></h1>
                <div class="page-sub"><?php echo intval($totalCount); ?> lokal gespeichert · <?php echo intval($inboxCount); ?> davon noch im Gmail-Posteingang</div>
            </div>
            <div class="head-actions">
                <a class="refresh-btn" href="archive.php?view=<?php echo h($view); ?>">↻ <span>Aktualisieren</span></a>
            </div>
        </header>

        <?php if (!$mailResult['ok']) { ?>
            <div class="flash flash-error"><strong>Gmail:</strong> <?php echo h($mailResult['error']); ?></div>
        <?php } ?>

        <?php if ($flashMessage != '') { ?>
            <div class="flash <?php echo $flashType == 'success' ? 'flash-success' : 'flash-error'; ?>"><?php echo h($flashMessage); ?></div>
        <?php } ?>

        <div class="archive-note">
            Hier kannst du versehentlich oder bewusst ausgeblendete Gespraeche wieder oeffnen, solange die zugehoerige mobile.de-Mail noch im Gmail-Posteingang liegt. Liegt sie bereits im Gmail-Archiv oder Papierkorb, verschiebe sie dort zuerst wieder in den Posteingang und aktualisiere anschließend diese Seite.
        </div>

        <?php if (count($visibleItems) == 0) { ?>
            <div class="archive-empty">
                Aktuell ist kein als „<?php echo h($title); ?>“ markiertes Gespraech im Gmail-Posteingang vorhanden.
            </div>
        <?php } else { ?>
            <div class="archive-list">
            <?php foreach ($visibleItems as $key => $conversation) { ?>
                <?php
                    $latest = getLatestMessage($conversation);
                    $displayName = isset($conversation['name']) && trim($conversation['name']) != '' ? trim($conversation['name']) : 'Interessent';
                ?>
                <article class="archive-card">
                    <div class="archive-card-head">
                        <div>
                            <div class="archive-card-name"><?php echo h($displayName); ?></div>
                            <div class="archive-card-meta"><?php echo $latest ? h($latest['date']) : '-'; ?> · <?php echo intval(count($conversation['messages'])); ?> Nachricht(en)</div>
                        </div>
                        <span class="status-badge <?php echo h(getStatusClass($view)); ?>"><?php echo h(getStatusLabel($view)); ?></span>
                    </div>

                    <div class="archive-card-subject"><?php echo h(isset($conversation['subject']) ? $conversation['subject'] : ''); ?></div>

                    <?php if ($latest) { ?>
                        <div class="archive-card-message"><?php echo h($latest['text']); ?></div>
                    <?php } ?>

                    <div class="archive-card-actions">
                        <form method="post" action="archive.php?view=<?php echo h($view); ?>">
                            <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
                            <input type="hidden" name="conversation_key" value="<?php echo h($key); ?>">
                            <button class="btn btn-primary" type="submit">Wieder öffnen und beantworten</button>
                        </form>
                    </div>
                </article>
            <?php } ?>
            </div>
        <?php } ?>

        <div class="footer">Version <?php echo h(MOBILE_APP_VERSION); ?> · Archivverwaltung</div>
    </main>
</div>
<script src="assets/app.js?v=<?php echo rawurlencode(MOBILE_APP_VERSION); ?>"></script>
</body>
</html>
