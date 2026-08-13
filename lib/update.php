<?php

/* Modul: update.php */

function getGitHubUpdateManifestUrl($config)
{
    if (
        is_array($config) &&
        isset($config['update_manifest_url']) &&
        trim($config['update_manifest_url']) != ''
    ) {
        return trim($config['update_manifest_url']);
    }

    return 'https://raw.githubusercontent.com/radebold/mobile/main/update-manifest.json';
}


function githubUpdateHttpGet($url, $timeout)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'body' => '',
        'http_code' => 0
    );

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, intval($timeout)));
    curl_setopt($ch, CURLOPT_TIMEOUT, intval($timeout));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
            'Accept: application/json,text/plain,*/*',
            'User-Agent: Sharan-Verkaufszentrale-Updater'
        )
    );

    $body = curl_exec($ch);

    if ($body === false) {
        $result['error'] = curl_error($ch);
        curl_close($ch);
        return $result;
    }

    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    $result['http_code'] = $httpCode;
    $result['body'] = $body;

    if ($httpCode < 200 || $httpCode >= 300) {
        $result['error'] = 'HTTP ' . $httpCode;
        return $result;
    }

    $result['ok'] = true;
    return $result;
}


function getGitHubUpdateCachePath($appDir)
{
    return rtrim($appDir, '/\\') . '/data/update-cache.json';
}


function getGitHubUpdateInfo($currentVersion, $appDir, $config, $force)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'available' => false,
        'current_version' => $currentVersion,
        'latest_version' => $currentVersion,
        'notes' => '',
        'manifest' => array()
    );

    $cacheFile = getGitHubUpdateCachePath($appDir);
    $cacheMaxAge = 1800;

    if (!$force && file_exists($cacheFile)) {
        $rawCache = @file_get_contents($cacheFile);
        $cache = json_decode($rawCache, true);

        if (
            is_array($cache) &&
            isset($cache['checked_at']) &&
            intval($cache['checked_at']) >= (time() - $cacheMaxAge) &&
            isset($cache['manifest']) &&
            is_array($cache['manifest'])
        ) {
            $manifest = $cache['manifest'];

            if (isset($manifest['version']) && trim($manifest['version']) != '') {
                $result['ok'] = true;
                $result['manifest'] = $manifest;
                $result['latest_version'] = trim($manifest['version']);
                $result['notes'] = isset($manifest['notes']) ? trim($manifest['notes']) : '';
                $result['available'] = version_compare($result['latest_version'], $currentVersion, '>');
                return $result;
            }
        }
    }

    $manifestUrl = getGitHubUpdateManifestUrl($config);
    $separator = strpos($manifestUrl, '?') === false ? '?' : '&';
    $requestUrl = $manifestUrl . $separator . 'ts=' . time();

    $http = githubUpdateHttpGet($requestUrl, 8);

    if (!$http['ok']) {
        $result['error'] = 'GitHub nicht erreichbar: ' . $http['error'];
        return $result;
    }

    $manifest = json_decode($http['body'], true);

    if (!is_array($manifest)) {
        $result['error'] = 'Das Update-Manifest ist kein gültiges JSON.';
        return $result;
    }

    if (
        !isset($manifest['version']) ||
        trim($manifest['version']) == '' ||
        !isset($manifest['files']) ||
        !is_array($manifest['files'])
    ) {
        $result['error'] = 'Das Update-Manifest ist unvollständig.';
        return $result;
    }

    $result['ok'] = true;
    $result['manifest'] = $manifest;
    $result['latest_version'] = trim($manifest['version']);
    $result['notes'] = isset($manifest['notes']) ? trim($manifest['notes']) : '';
    $result['available'] = version_compare($result['latest_version'], $currentVersion, '>');

    $cache = array(
        'checked_at' => time(),
        'manifest' => $manifest
    );

    @file_put_contents(
        $cacheFile,
        json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return $result;
}


function isAllowedAutoUpdatePath($path)
{
    $allowed = array(
        'index.php',
        'archive.php',
        'functions.php',
        'vehicle.php',
        'VERSION',
        'assets/app.css',
        'assets/app.js',
        'lib/mail.php',
        'lib/ai.php',
        'lib/gmail.php',
        'lib/state.php',
        'lib/update.php'
    );

    return in_array($path, $allowed, true);
}


function ensureDirectoryExists($path)
{
    if (is_dir($path)) {
        return true;
    }

    return @mkdir($path, 0770, true);
}


function installGitHubUpdate($appDir, $currentVersion, $config)
{
    $result = array(
        'ok' => false,
        'error' => '',
        'version' => $currentVersion,
        'backup_dir' => ''
    );

    $appDir = rtrim($appDir, '/\\');

    $info = getGitHubUpdateInfo(
        $currentVersion,
        $appDir,
        $config,
        true
    );

    if (!$info['ok']) {
        $result['error'] = $info['error'];
        return $result;
    }

    if (!$info['available']) {
        $result['ok'] = true;
        $result['version'] = $currentVersion;
        return $result;
    }

    $manifest = $info['manifest'];
    $latestVersion = trim($manifest['version']);

    $baseUrl = isset($manifest['base_url']) && trim($manifest['base_url']) != ''
        ? rtrim(trim($manifest['base_url']), '/') . '/'
        : 'https://raw.githubusercontent.com/radebold/mobile/main/';

    $files = $manifest['files'];

    if (count($files) == 0) {
        $result['error'] = 'Das Update enthält keine Dateien.';
        return $result;
    }

    $token = date('Ymd-His') . '-' . mt_rand(1000, 9999);
    $stagingDir = $appDir . '/data/update-staging/' . $token;
    $backupDir = $appDir . '/data/update-backups/' . $token;

    if (!ensureDirectoryExists($stagingDir)) {
        $result['error'] = 'Der Update-Zwischenspeicher konnte nicht angelegt werden.';
        return $result;
    }

    if (!ensureDirectoryExists($backupDir)) {
        $result['error'] = 'Das Update-Backupverzeichnis konnte nicht angelegt werden.';
        return $result;
    }

    $result['backup_dir'] = $backupDir;
    $prepared = array();

    /*
     * 1. Alle Dateien vollständig herunterladen und per Prüfsumme verifizieren.
     * Erst danach wird eine bestehende Programmdatei verändert.
     */
    foreach ($files as $fileInfo) {
        if (!is_array($fileInfo) || !isset($fileInfo['path'])) {
            $result['error'] = 'Ungültiger Dateieintrag im Update-Manifest.';
            return $result;
        }

        $relativePath = trim($fileInfo['path']);

        if (!isAllowedAutoUpdatePath($relativePath)) {
            $result['error'] = 'Nicht erlaubter Update-Pfad: ' . $relativePath;
            return $result;
        }

        $expectedHash = isset($fileInfo['sha256']) ? strtolower(trim($fileInfo['sha256'])) : '';
        $expectedBlobSha = isset($fileInfo['blob_sha']) ? strtolower(trim($fileInfo['blob_sha'])) : '';

        $hasSha256 = preg_match('/^[a-f0-9]{64}$/', $expectedHash);
        $hasBlobSha = preg_match('/^[a-f0-9]{40}$/', $expectedBlobSha);

        if (!$hasSha256 && !$hasBlobSha) {
            $result['error'] = 'Fehlende oder ungültige Prüfsumme für ' . $relativePath . '.';
            return $result;
        }

        $downloadUrl = $baseUrl . str_replace('%2F', '/', rawurlencode($relativePath));
        $separator = strpos($downloadUrl, '?') === false ? '?' : '&';
        $downloadUrl .= $separator . 'v=' . rawurlencode($latestVersion) . '&ts=' . time();

        $http = githubUpdateHttpGet($downloadUrl, 20);

        if (!$http['ok']) {
            $result['error'] = 'Download von ' . $relativePath . ' fehlgeschlagen: ' . $http['error'];
            return $result;
        }

        if ($hasSha256) {
            $actualHash = hash('sha256', $http['body']);

            if (!hash_equals($expectedHash, $actualHash)) {
                $result['error'] = 'SHA-256-Prüfsumme von ' . $relativePath . ' stimmt nicht.';
                return $result;
            }
        } else {
            /* GitHub Contents API liefert die Git-Blob-ID (SHA-1 über blob <len>\0<content>). */
            $gitBlobPayload = 'blob ' . strlen($http['body']) . chr(0) . $http['body'];
            $actualBlobSha = sha1($gitBlobPayload);

            if (!hash_equals($expectedBlobSha, $actualBlobSha)) {
                $result['error'] = 'GitHub-Blob-Prüfsumme von ' . $relativePath . ' stimmt nicht.';
                return $result;
            }
        }

        $stagedPath = $stagingDir . '/' . $relativePath;
        $stagedParent = dirname($stagedPath);

        if (!is_dir($stagedParent) && !ensureDirectoryExists($stagedParent)) {
            $result['error'] = 'Zwischenverzeichnis für ' . $relativePath . ' konnte nicht angelegt werden.';
            return $result;
        }

        if (@file_put_contents($stagedPath, $http['body'], LOCK_EX) === false) {
            $result['error'] = 'Update-Datei ' . $relativePath . ' konnte nicht zwischengespeichert werden.';
            return $result;
        }

        $prepared[] = array(
            'relative' => $relativePath,
            'staged' => $stagedPath,
            'target' => $appDir . '/' . $relativePath,
            'backup' => $backupDir . '/' . $relativePath,
            'existed' => file_exists($appDir . '/' . $relativePath)
        );
    }

    /*
     * 2. Aktuelle Dateien sichern.
     */
    foreach ($prepared as $item) {
        if ($item['existed']) {
            $backupParent = dirname($item['backup']);
            if (!is_dir($backupParent) && !ensureDirectoryExists($backupParent)) {
                $result['error'] = 'Backupverzeichnis für ' . $item['relative'] . ' konnte nicht angelegt werden.';
                return $result;
            }

            if (!@copy($item['target'], $item['backup'])) {
                $result['error'] = 'Backup von ' . $item['relative'] . ' fehlgeschlagen.';
                return $result;
            }
        }
    }

    /*
     * 3. Update atomar pro Datei einspielen. Bei einem Fehler werden bereits
     * ersetzte Dateien aus dem Backup zurückgespielt.
     */
    $replaced = array();

    foreach ($prepared as $item) {
        $targetDir = dirname($item['target']);

        if (!is_dir($targetDir) && !ensureDirectoryExists($targetDir)) {
            $result['error'] = 'Zielverzeichnis für ' . $item['relative'] . ' konnte nicht angelegt werden.';
            break;
        }

        $tempTarget = $item['target'] . '.update-' . mt_rand(10000, 99999) . '.tmp';

        if (!@copy($item['staged'], $tempTarget)) {
            $result['error'] = 'Temporäre Zieldatei für ' . $item['relative'] . ' konnte nicht erstellt werden.';
            break;
        }

        @chmod($tempTarget, 0644);

        if (!@rename($tempTarget, $item['target'])) {
            @unlink($tempTarget);
            $result['error'] = 'Datei ' . $item['relative'] . ' konnte nicht ersetzt werden.';
            break;
        }

        $replaced[] = $item;
    }

    if ($result['error'] != '') {
        foreach (array_reverse($replaced) as $item) {
            if ($item['existed'] && file_exists($item['backup'])) {
                @copy($item['backup'], $item['target']);
            } elseif (!$item['existed'] && file_exists($item['target'])) {
                @unlink($item['target']);
            }
        }

        return $result;
    }

    /* Cache nach erfolgreichem Update entfernen, damit die neue Version frisch prüft. */
    $cacheFile = getGitHubUpdateCachePath($appDir);
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }

    $result['ok'] = true;
    $result['version'] = $latestVersion;

    return $result;
}
