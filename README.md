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
- WhatsApp-Benachrichtigung bei neuen Käufernachrichten über ioBroker REST-API und `open-wa.0`
- integriertes Self-Update direkt aus diesem GitHub-Repository

Die Anwendung sendet **keine Antwort an Käufer automatisch**. Optional können ausschließlich interne WhatsApp-Benachrichtigungen über neue Anfragen automatisch versendet werden.

## Voraussetzungen

- Synology Web Station / Apache
- PHP 5.6 oder neuer
- PHP-Erweiterungen: `curl`, `openssl`, `imap`, `json`, `mbstring`
- Gmail mit App-Passwort für IMAP
- Gemini API-Key
- Google Apps Script Bridge für native Gmail-Entwürfe
- optional: ioBroker REST-API und `open-wa.0` für WhatsApp-Benachrichtigungen

## Installation auf der Synology

Zielordner:

```text
/volume1/web/mobile/
```

Diese Dateien/Ordner aus dem Repository dorthin kopieren:

```text
index.php
functions.php
vehicle.php
VERSION
assets/
lib/
_private/
data/
```

Dann:

1. Bei einer Neuinstallation `_private/config.example.php` nach `_private/config.php` kopieren. Bei einer bestehenden Installation die vorhandene `_private/config.php` **behalten**.
2. Sicherstellen, dass der Webserver in `data/` schreiben darf.
3. `gmail-bridge.gs` einmal in Google Apps Script einrichten und als Web-App veröffentlichen, sofern dies noch nicht erfolgt ist.
4. Anwendung öffnen: `http://<NAS-IP>/mobile/`

## Gmail-Bridge

`gmail-bridge.gs` wird **nicht** automatisch von der NAS ausgeführt. Die Datei muss einmal in Google Apps Script bereitgestellt werden. Bei späteren Änderungen an dieser Datei ist ein erneutes Deployment in Apps Script erforderlich.

In `_private/config.php` müssen anschließend stehen:

```php
$config['gmail_bridge_url'] = 'https://script.google.com/macros/s/DEINE_DEPLOYMENT_ID/exec';
$config['gmail_bridge_token'] = 'DEIN_GEHEIMES_TOKEN';
```

Dasselbe Token muss in `gmail-bridge.gs` gesetzt sein.

## WhatsApp-Benachrichtigungen über ioBroker + open-wa.0

Die Verkaufszentrale kann neue mobile.de-Nachrichten über die ioBroker REST-API direkt an den Adapter `open-wa.0` weitergeben. Verwendet wird ioBrokers `sendTo`-Command mit dem bereits von `open-wa.0` erwarteten Payload `{to, text}`.

In `_private/config.php` ergänzen:

```php
$config['whatsapp_notify_enabled'] = true;
$config['whatsapp_to'] = '+49XXXXXXXXXXX';
$config['openwa_adapter'] = 'open-wa.0';

$config['iobroker_rest_url'] = 'http://DEINE-IOBROKER-IP:8093';
$config['iobroker_rest_user'] = '';
$config['iobroker_rest_password'] = '';
// $config['iobroker_rest_bearer_token'] = '';

$config['notify_cron_token'] = 'EIN-LANGES-ZUFAELLIGES-GEHEIMNIS';
$config['mobile_web_url'] = 'http://DEINE-NAS-IP/mobile/';
```

### Test

Nach der Konfiguration kann eine Test-WhatsApp ausgelöst werden:

```text
http://<NAS-IP>/mobile/functions.php?token=<notify_cron_token>&test=1
```

Bei Erfolg wird JSON mit `"ok": true` und `"sent_count": 1` ausgegeben.

### Automatische Prüfung

Für automatische Benachrichtigungen muss `functions.php` regelmäßig aufgerufen werden. Beispiel für den Synology Aufgabenplaner, jede Minute:

```bash
/usr/bin/curl -fsS "http://<NAS-IP>/mobile/functions.php?token=<notify_cron_token>" >/dev/null 2>&1
```

Der erste Lauf verschickt **keine alten Nachrichten**, sondern merkt sich den aktuellen Stand als Basis. Erst danach eintreffende mobile.de-Nachrichten lösen eine WhatsApp aus. Erfolgreich gemeldete Nachrichten werden in `data/notify-state.json` gespeichert und deshalb nicht doppelt versendet.

Der Versandweg ist:

```text
Gmail / mobile.de
      ↓
Synology Verkaufszentrale
      ↓
ioBroker REST-API /v1/command/sendTo
      ↓
open-wa.0 / command "send"
      ↓
WhatsApp
```

## Integrierte Updates

Die Weboberfläche prüft dieses Repository auf eine neuere Version. Das Ergebnis wird 30 Minuten gecacht; über **prüfen** im Systembereich kann sofort neu geprüft werden.

Wenn eine neue Version vorhanden ist, erscheint oben ein Update-Button. Beim Update:

1. wird `update-manifest.json` von GitHub geladen,
2. werden nur explizit freigegebene Programmdateien heruntergeladen,
3. wird jede Datei gegen die im Manifest hinterlegte GitHub-Blob-Prüfsumme verifiziert,
4. werden die bisherigen Dateien unter `data/update-backups/` gesichert,
5. werden die neuen Dateien erst danach eingespielt,
6. bei einem Fehler werden bereits ersetzte Dateien aus dem Backup zurückgespielt.

**Nie automatisch überschrieben werden:**

- `_private/config.php`
- `data/status.json`
- `data/notify-state.json`
- persönliche Laufzeitdaten

Automatisch aktualisiert werden ausschließlich die freigegebenen Programmdateien aus `update-manifest.json`, derzeit:

- `index.php`
- `functions.php`
- `vehicle.php`
- `VERSION`
- `assets/app.css`
- `assets/app.js`
- `lib/*.php`

## Entwicklung

Repository: `radebold/mobile`

Die produktive Version steht auf `main`. Für eine neue Version werden die geänderten Dateien committed; danach müssen `VERSION` und `update-manifest.json` auf die neue Version bzw. die neuen Blob-Prüfsummen gesetzt werden. Die NAS erkennt die neue Version anschließend automatisch.
