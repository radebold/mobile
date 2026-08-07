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
- integriertes Self-Update direkt aus diesem GitHub-Repository

Die Anwendung sendet **keine Nachricht automatisch**.

## Voraussetzungen

- Synology Web Station / Apache
- PHP 5.6 oder neuer
- PHP-Erweiterungen: `curl`, `openssl`, `imap`, `json`, `mbstring`
- Gmail mit App-Passwort für IMAP
- Gemini API-Key
- Google Apps Script Bridge für native Gmail-Entwürfe

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
