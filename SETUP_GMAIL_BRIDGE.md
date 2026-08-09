# Gmail-Bridge einrichten

Die NAS-Anwendung verwendet Google Apps Script, damit Antworten im bestehenden Gmail-Thread entweder als nativer Entwurf angelegt oder nach Bestätigung direkt versendet werden können.

1. Google Apps Script öffnen und ein neues Projekt anlegen.
2. Inhalt von `gmail-bridge.gs` in das Projekt kopieren.
3. In `gmail-bridge.gs` ein langes, zufälliges `BRIDGE_TOKEN` eintragen.
4. **Bereitstellen → Neue Bereitstellung → Web-App**.
5. Ausführen als: **Ich**.
6. Zugriff passend zum eigenen Konto konfigurieren.
7. Deployment-URL kopieren.
8. In `_private/config.php` eintragen:

```php
$config['gmail_bridge_url'] = 'https://script.google.com/macros/s/DEINE_DEPLOYMENT_ID/exec';
$config['gmail_bridge_token'] = 'DAS_GLEICHE_TOKEN_WIE_IM_SCRIPT';
```

## Bridge aktualisieren

Ab Version 2.10.0 unterstützt die Bridge zusätzlich die Aktion `sendReply`, die eine geprüfte Antwort direkt mit `GmailMessage.reply()` versendet und dabei den bestehenden Thread beibehält.

Bei einer bereits eingerichteten Bridge muss die neue `gmail-bridge.gs` einmal in das bestehende Apps-Script-Projekt kopiert und anschließend neu bereitgestellt werden:

**Bereitstellen → Bereitstellungen verwalten → Bearbeiten → Neue Version → Bereitstellen**

Die Deployment-URL bleibt dabei normalerweise unverändert.

Wichtig: `gmail-bridge.gs` enthält im Repository **kein echtes Token**. Zugangsdaten gehören nicht nach GitHub.
