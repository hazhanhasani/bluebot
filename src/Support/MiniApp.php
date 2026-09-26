<?php

declare(strict_types=1);

function bluebotMiniAppUrl(): string
{
    global $domainhosts;

    $host = trim((string) $domainhosts);
    $host = preg_replace('~^https?://~i', '', $host);
    $host = trim((string) $host, '/');

    return $host === '' ? '' : 'https://' . $host . '/app/';
}

function bluebotEnsureMiniAppMenuButton(bool $force = false): bool
{
    $url = bluebotMiniAppUrl();
    if ($url === '' || !function_exists('telegram')) {
        return false;
    }

    $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
    $cacheFile = $cacheDir . '/miniapp_menu.json';
    $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;

    if (!$force && is_array($cached)
        && ($cached['url'] ?? '') === $url
        && isset($cached['time'])
        && (time() - (int) $cached['time']) < 21600) {
        return true;
    }

    $response = telegram('setChatMenuButton', [
        'menu_button' => json_encode([
            'type' => 'web_app',
            'text' => 'پنل کاربری',
            'web_app' => ['url' => $url],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $ok = is_array($response) && !empty($response['ok']);
    if ($ok) {
        @mkdir($cacheDir, 0775, true);
        @file_put_contents($cacheFile, json_encode([
            'url' => $url,
            'time' => time(),
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $ok;
}

function bluebotMiniAppHealth(): array
{
    $url = bluebotMiniAppUrl();
    if ($url === '') {
        return ['ok' => false, 'state' => 'missing_url', 'http_status' => 0];
    }

    $curl = curl_init($url);
    if ($curl === false) {
        return ['ok' => false, 'state' => 'curl_init_failed', 'http_status' => 0];
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT_MS => 2500,
        CURLOPT_TIMEOUT_MS => 6000,
        CURLOPT_HTTPHEADER => [
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'User-Agent: BlueBot-MiniApp-Health',
        ],
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = $body === false ? curl_error($curl) : '';
    curl_close($curl);

    if (!is_string($body)) {
        return ['ok' => false, 'state' => 'network_error', 'http_status' => $status, 'error' => $error];
    }

    $cloudflareEmpty = stripos($body, 'There is nothing here yet') !== false
        && stripos($body, 'cloudflare') !== false;

    if ($cloudflareEmpty) {
        return ['ok' => false, 'state' => 'cloudflare_empty_worker', 'http_status' => $status];
    }

    $bluebot = stripos($body, '<title>BlueBot Web App</title>') !== false;
    return [
        'ok' => $status >= 200 && $status < 400 && $bluebot,
        'state' => $bluebot ? 'healthy' : 'unexpected_response',
        'http_status' => $status,
    ];
}
