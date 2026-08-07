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
$config['mobile_web_url'] = 'http://DEINE-NAS-IP/mobile/';

/*
 * WhatsApp-Benachrichtigung bei neuen mobile.de-Nachrichten.
 */
$config['whatsapp_notify_enabled'] = true;
$config['whatsapp_to'] = '+49XXXXXXXXXXX';
$config['openwa_adapter'] = 'open-wa.0';

/*
 * Vorhandener ioBroker simple-api Adapter, typischerweise Port 8087.
 * Die Verkaufszentrale schreibt JSON in den unten definierten Datenpunkt.
 * Ein ioBroker-JavaScript leitet die Nachricht an open-wa.0 weiter.
 */
$config['iobroker_api_mode'] = 'simple-api';
$config['iobroker_rest_url'] = 'http://DEINE-IOBROKER-IP:8087';
$config['iobroker_simple_api_state'] = '0_userdata.0.mobile.whatsapp.outgoing';

/* Nur ausfüllen, wenn Authentifizierung im API-Adapter aktiviert ist. */
$config['iobroker_rest_user'] = '';
$config['iobroker_rest_password'] = '';
// $config['iobroker_rest_bearer_token'] = '';

/*
 * Alternativ mit installiertem ioBroker rest-api Adapter:
 * $config['iobroker_api_mode'] = 'rest-api';
 * $config['iobroker_rest_url'] = 'http://DEINE-IOBROKER-IP:8093';
 */

/* Schutz für den periodischen Aufruf durch den Synology Aufgabenplaner. */
$config['notify_cron_token'] = 'BITTE-EIN-LANGES-ZUFAELLIGES-GEHEIMNIS-EINTRAGEN';

/* Optional: maximale Länge des Käufertexts in der WhatsApp */
$config['whatsapp_notify_message_max_chars'] = 900;

/*
 * Optional: eigene Updatequelle. Normalerweise NICHT notwendig.
 */
// $config['update_manifest_url'] = 'https://raw.githubusercontent.com/radebold/mobile/main/update-manifest.json';
