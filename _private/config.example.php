<?php

/*
 * Beispielkonfiguration für die Sharan Verkaufszentrale.
 * Kopieren nach: _private/config.php
 * Diese echte config.php ist per .gitignore vom Repository ausgeschlossen.
 */

$config = array();

/* Gmail / IMAP - nur für das Lesen und Archivieren der mobile.de-Mails */
$config['imap_server'] = '{imap.gmail.com:993/imap/ssl}INBOX';
$config['gmail_user'] = 'dein.name@gmail.com';
$config['gmail_password'] = 'DEIN_GOOGLE_APP_PASSWORT';

/* Gemini */
$config['gemini_api_key'] = 'DEIN_GEMINI_API_KEY';
$config['gemini_model'] = 'gemini-3.5-flash-lite';

/* Gmail-Bridge für echte Gmail-Entwürfe */
$config['gmail_bridge_url'] = 'https://script.google.com/macros/s/DEINE_DEPLOYMENT_ID/exec';
$config['gmail_bridge_token'] = 'EIN_LANGES_ZUFAELLIGES_GEHEIMNIS';

/* Optional */
$config['mobile_days'] = 30;
$config['mobile_max_messages'] = 100;

/*
 * Optional: eigene Updatequelle. Normalerweise NICHT notwendig.
 * Standard ist:
 * https://raw.githubusercontent.com/radebold/mobile/main/update-manifest.json
 */
// $config['update_manifest_url'] = 'https://raw.githubusercontent.com/radebold/mobile/main/update-manifest.json';
