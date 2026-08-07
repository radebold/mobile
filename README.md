# Sharan Verkaufszentrale

Private Verkaufszentrale für mobile.de-/Kleinanzeigen-Anfragen zum VW Sharan.

## Funktionen

- Gmail per IMAP lesen und mobile.de-/Kleinanzeigen-Anfragen gruppieren
- Antwortvorschläge mit Gemini erstellen
- persönliche Hinweise pro Antwort berücksichtigen
- echte Gmail-Antwortentwürfe über Google Apps Script erzeugen
- Gesprächsstatus verwalten
- beantwortete Gespräche archivieren
- Anfragen ohne weiteres Interesse in Gmail in den Papierkorb verschieben
- WhatsApp-Benachrichtigung bei neuen Käufernachrichten
- WhatsApp-Systemstatus und Test direkt in der Weboberfläche
- integriertes Self-Update direkt aus diesem GitHub-Repository

Die Anwendung sendet keine Käuferantwort automatisch. Automatisch versendet werden optional nur interne WhatsApp-Benachrichtigungen über neue Anfragen.

## Voraussetzungen

- Synology Web Station / Apache
- PHP 5.6 oder neuer
- PHP-Erweiterungen: `curl`, `openssl`, `imap`, `json`, `mbstring`
- Gmail mit App-Passwort für IMAP
- Gemini API-Key
- Google Apps Script Bridge für native Gmail-Entwürfe
- für WhatsApp: ioBroker `simple-api.0` und der bestehende Ausgang `mqtt.0.whatsapp.outgoing`

## Installation auf der Synology

Zielordner:

```text
/volume1/web/mobile/
```

Bei einer bestehenden Installation `_private/config.php` und `data/` behalten. Die Weboberfläche aktualisiert die freigegebenen Programmdateien über `update-manifest.json`.

## Gmail-Bridge

In `_private/config.php`:

```php
$config['gmail_bridge_url'] = 'https://script.google.com/macros/s/DEINE_DEPLOYMENT_ID/exec';
$config['gmail_bridge_token'] = 'DEIN_GEHEIMES_TOKEN';
```

## WhatsApp-Benachrichtigung

Für die vorhandene ioBroker-Installation wird `simple-api.0` genutzt. Die Verkaufszentrale schreibt das minimale JSON `{to,text}` direkt nach `mqtt.0.whatsapp.outgoing`.

```php
$config['whatsapp_notify_enabled'] = true;
$config['whatsapp_to'] = '491234567890@c.us';
$config['iobroker_api_mode'] = 'simple-api';
$config['iobroker_rest_url'] = 'http://DEINE-IOBROKER-IP:8087';
$config['iobroker_simple_api_state'] = 'mqtt.0.whatsapp.outgoing';
$config['notify_cron_token'] = 'EIN-LANGES-ZUFAELLIGES-GEHEIMNIS';
$config['mobile_web_url'] = 'http://DEINE-NAS-IP/mobile/';
```

Einzelchat-Empfänger werden zusätzlich automatisch auf das Format `49...@c.us` normalisiert.

### Weboberfläche

Ab Version 2.8.0 erscheint im Systembereich ein eigener WhatsApp-Status mit Test-Button. Die Anzeige wird grün, wenn der automatische Check innerhalb der letzten drei Minuten gelaufen ist. Gelb bedeutet, dass kein aktueller Scheduler-Lauf erkannt wurde; rot zeigt Konfigurations- oder Laufzeitfehler.

### Automatische Prüfung auf der Synology

Im Synology Aufgabenplaner eine benutzerdefinierte Aufgabe anlegen und jede Minute ausführen:

```bash
/usr/bin/curl -fsS "http://DEINE-NAS-IP/mobile/functions.php?token=DEIN_NOTIFY_CRON_TOKEN" >/dev/null 2>&1
```

Beim ersten normalen Lauf werden alle bereits vorhandenen mobile.de-Mails nur als Ausgangsbestand gespeichert. Erst danach neu eintreffende Nachrichten lösen eine WhatsApp aus. Erfolgreich verarbeitete Nachrichten werden in `data/notify-state.json` gemerkt und nicht doppelt versendet.

## Integrierte Updates

Die Weboberfläche prüft `update-manifest.json` auf GitHub. Beim Update werden nur freigegebene Programmdateien geladen und vor dem Austausch gesichert. Nicht überschrieben werden insbesondere:

- `_private/config.php`
- `data/status.json`
- `data/notify-state.json`
- persönliche Laufzeitdaten

## Entwicklung

Repository: `radebold/mobile`

Die produktive Version steht auf `main`. Für Releases werden `VERSION` und `update-manifest.json` aktualisiert; die NAS erkennt die neue Version anschließend über die integrierte Updatefunktion.
