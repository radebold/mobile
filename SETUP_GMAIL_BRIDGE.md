# Gmail-Bridge einrichten

Die NAS-Anwendung verwendet Google Apps Script, damit Antwortentwürfe als **echte Gmail-Entwürfe im bestehenden Thread** angelegt werden.

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

Wichtig: `gmail-bridge.gs` enthält im Repository **kein echtes Token**. Zugangsdaten gehören nicht nach GitHub.
