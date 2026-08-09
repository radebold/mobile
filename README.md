# Sharan Verkaufszentrale

Private Verkaufszentrale für mobile.de-/Kleinanzeigen-Anfragen zum VW Sharan.

## Funktionen

- Gmail per IMAP lesen und mobile.de-/Kleinanzeigen-Anfragen gruppieren
- Antwortvorschläge mit Gemini erstellen
- persönliche Hinweise pro Antwort berücksichtigen
- geprüfte Antworten direkt über Gmail im bestehenden Thread versenden
- optional weiterhin native Gmail-Entwürfe über die Bridge erzeugen
- Gesprächsstatus verwalten
- beantwortete Gespräche archivieren
- Anfragen ohne weiteres Interesse in Gmail in den Papierkorb verschieben
- WhatsApp-Benachrichtigung bei neuen Käufernachrichten inklusive Direktlink zur Unterhaltung
- WhatsApp-Systemstatus und Test direkt in der Weboberfläche
- automatische GitHub-Updateprüfung im Scheduler mit einmaligem WhatsApp-Hinweis je neuer Version
- integriertes Self-Update direkt aus diesem GitHub-Repository

Eine Käuferantwort wird **nie ungeprüft automatisch versendet**. Der Nutzer prüft bzw. bearbeitet den von Gemini erzeugten Text und bestätigt anschließend ausdrücklich den Direktversand. Automatisch versendet werden optional nur interne WhatsApp-Benachrichtigungen über neue Anfragen und verfügbare Programmupdates.

## Voraussetzungen

- Synology Web Station / Apache
- PHP 5.6 oder neuer
- PHP-Erweiterungen: `curl`, `openssl`, `imap`, `json`, `mbstring`
- Gmail mit App-Passwort für IMAP
- Gemini API-Key
- Google Apps Script Bridge für Gmail-Antworten
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

Ab Version 2.10.0 muss die aktuelle `gmail-bridge.gs` im vorhandenen Apps-Script-Projekt bereitgestellt sein. Sie unterstützt neben `createDraftReply` auch `sendReply`. Der Direktversand nutzt offiziell `GmailMessage.reply()`, damit die Antwort an die Reply-To-Adresse der konkreten Nachricht geht und im selben Thread bleibt.

Nach Änderungen an `gmail-bridge.gs`: **Bereitstellen → Bereitstellungen verwalten → Bearbeiten → Neue Version → Bereitstellen**.

## WhatsApp-Benachrichtigung

Für die vorhandene ioBroker-Installation wird `simple-api.0` genutzt. Die Verkaufszentrale schreibt das minimale JSON `{to,text}` direkt nach `mqtt.0.whatsapp.outgoing`.

```php
$config['whatsapp_notify_enabled'] = true;
$config['whatsapp_to'] = '491234567890@c.us';
$config['whatsapp_notify_updates'] = true;
$config['iobroker_api_mode'] = 'simple-api';
$config['iobroker_rest_url'] = 'http://DEINE-IOBROKER-IP:8087';
$config['iobroker_simple_api_state'] = 'mqtt.0.whatsapp.outgoing';
$config['notify_cron_token'] = 'EIN-LANGES-ZUFAELLIGES-GEHEIMNIS';
$config['mobile_web_url'] = 'http://DEINE-NAS-IP/mobile/';
```

Einzelchat-Empfänger werden automatisch auf das Format `49...@c.us` normalisiert.

Eine neue Käufernachricht enthält in WhatsApp den Namen des Interessenten, Zeitpunkt, Betreff, Nachrichtentext und einen Direktlink auf genau diese Unterhaltung in der Verkaufszentrale.

### Weboberfläche

Ab Version 2.8.0 erscheint im Systembereich ein eigener WhatsApp-Status mit Test-Button. Die Anzeige wird grün, wenn der automatische Check innerhalb der letzten drei Minuten gelaufen ist. Gelb bedeutet, dass kein aktueller Scheduler-Lauf erkannt wurde; rot zeigt Konfigurations- oder Laufzeitfehler.

### Automatische Prüfung auf der Synology

Im Synology Aufgabenplaner eine benutzerdefinierte Aufgabe anlegen und jede Minute ausführen:

```bash
/usr/bin/curl -fsS "http://DEINE-NAS-IP/mobile/functions.php?token=DEIN_NOTIFY_CRON_TOKEN" >/dev/null 2>&1
```

Beim ersten normalen Lauf werden alle bereits vorhandenen mobile.de-Mails nur als Ausgangsbestand gespeichert. Erst danach neu eintreffende Nachrichten lösen eine WhatsApp aus. Erfolgreich verarbeitete Nachrichten werden in `data/notify-state.json` gemerkt und nicht doppelt versendet.

Der gleiche Scheduler-Lauf prüft außerdem die GitHub-Updatequelle. Die vorhandene 30-Minuten-Cachelogik verhindert einen GitHub-Aufruf jede Minute. Sobald eine neue Version erkannt wird, kommt einmalig eine WhatsApp mit installierter und verfügbarer Version sowie einem Link zur Updateprüfung. Für dieselbe Version wird nicht mehrfach benachrichtigt.

Wer keine Update-Hinweise per WhatsApp möchte, kann setzen:

```php
$config['whatsapp_notify_updates'] = false;
```

## Integrierte Updates

Die Weboberfläche prüft `update-manifest.json` auf GitHub. Beim Update werden nur freigegebene Programmdateien geladen und vor dem Austausch gesichert. Nicht überschrieben werden insbesondere:

- `_private/config.php`
- `data/status.json`
- `data/notify-state.json`
- persönliche Laufzeitdaten

## Entwicklung

Repository: `radebold/mobile`

Die produktive Version steht auf `main`. Für Releases werden `VERSION` und `update-manifest.json` aktualisiert; die NAS erkennt die neue Version anschließend über die integrierte Updatefunktion.
