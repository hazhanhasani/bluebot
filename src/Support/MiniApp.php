<?php

declare(strict_types=1);

require_once __DIR__ . '/RuntimeIdentity.php';

function bluebotMiniAppUrl(): string
{
    // The active bot/domain is authoritative. This prevents an old Worker/CDN
    // override from being inherited when BlueBot is moved to a new bot/domain.
    $host = bluebotPublicDomain();
    if ($host !== '') {
        return 'https://' . $host . '/app/';
    }

    // Legacy edge/CDN override is fallback-only when BlueBot cannot determine
    // any active public domain at runtime or from the current bot cache/config.
    $override = trim((string) getenv('BLUEBOT_MINIAPP_URL'));
    if ($override !== '' && filter_var($override, FILTER_VALIDATE_URL) !== false) {
        $parts = parse_url($override);
        if (is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https') {
            return rtrim($override, '/') . '/';
        }
    }

    return '';
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
    $botKey = bluebotRuntimeBotKey();

    if (!$force && is_array($cached)
        && ($cached['url'] ?? '') === $url
        && ($cached['bot_key'] ?? '') === $botKey
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
            'bot_key' => $botKey,
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

    $headers = [];
    curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$headers): int {
        $length = strlen($line);
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $length;
    });

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

    $cdnChallenge = strtolower((string) ($headers['cf-mitigated'] ?? '')) === 'challenge'
        || stripos($body, 'Just a moment') !== false
        || stripos($body, 'cf-chl-') !== false
        || stripos($body, 'Attention Required! | Cloudflare') !== false;

    if ($cdnChallenge) {
        return ['ok' => false, 'state' => 'cdn_challenge', 'http_status' => $status];
    }

    $bluebot = stripos($body, '<title>BlueBot Web App</title>') !== false;
    return [
        'ok' => $status >= 200 && $status < 400 && $bluebot,
        'state' => $bluebot ? 'healthy' : 'unexpected_response',
        'http_status' => $status,
    ];
}
