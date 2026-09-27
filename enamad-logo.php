<?php

declare(strict_types=1);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$source = 'https://trustseal.enamad.ir/logo.aspx?id=748781&Code=HuvEauyphrDRR17dhwoisDFNoMFMkDC0';
$cacheDir = __DIR__ . '/storage/cache';
$cacheFile = $cacheDir . '/enamad-748781.bin';
$metaFile = $cacheDir . '/enamad-748781.json';
$ttl = 21600;

$serve = static function (string $body, string $contentType, int $maxAge = 3600) use ($method): never {
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=' . $maxAge . ', stale-while-revalidate=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($body));
    if ($method !== 'HEAD') {
        echo $body;
    }
    exit;
};

$readCached = static function () use ($cacheFile, $metaFile, $ttl): ?array {
    if (!is_file($cacheFile) || !is_file($metaFile)) {
        return null;
    }

    $meta = json_decode((string) @file_get_contents($metaFile), true);
    if (!is_array($meta)) {
        return null;
    }

    $savedAt = (int) ($meta['saved_at'] ?? 0);
    $type = (string) ($meta['content_type'] ?? '');
    $body = @file_get_contents($cacheFile);
    if (!is_string($body) || $body === '' || !in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'], true)) {
        return null;
    }

    return [
        'body' => $body,
        'type' => $type,
        'fresh' => $savedAt > 0 && (time() - $savedAt) <= $ttl,
    ];
};

$cached = $readCached();
if (is_array($cached) && !empty($cached['fresh'])) {
    $serve($cached['body'], $cached['type'], $ttl);
}

$remoteBody = null;
$remoteType = '';

if (function_exists('curl_init')) {
    $ch = curl_init($source);
    if ($ch !== false) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT_MS => 3500,
            CURLOPT_TIMEOUT_MS => 7000,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Referer: https://' . (preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'bot.blluepanel.ir')) ?: 'bot.blluepanel.ir') . '/',
                'User-Agent: Mozilla/5.0 BluePanel-TrustSeal/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
        $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $finalHost = strtolower((string) (parse_url($effective, PHP_URL_HOST) ?? ''));
        $allowedHost = $finalHost === 'enamad.ir' || str_ends_with($finalHost, '.enamad.ir');
        $type = trim(explode(';', $type, 2)[0]);

        if (
            is_string($response)
            && strlen($response) > 32
            && $status >= 200
            && $status < 300
            && $allowedHost
            && in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'], true)
        ) {
            $remoteBody = $response;
            $remoteType = $type;
        }
    }
}

if (is_string($remoteBody) && $remoteBody !== '') {
    if ((is_dir($cacheDir) || @mkdir($cacheDir, 0775, true)) && is_writable($cacheDir)) {
        @file_put_contents($cacheFile, $remoteBody, LOCK_EX);
        @file_put_contents(
            $metaFile,
            json_encode(['saved_at' => time(), 'content_type' => $remoteType], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
    $serve($remoteBody, $remoteType, $ttl);
}

if (is_array($cached)) {
    $serve($cached['body'], $cached['type'], 900);
}

$fallback = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="180" height="150" viewBox="0 0 180 150">
  <rect width="180" height="150" rx="18" fill="#ffffff"/>
  <path d="M90 23 57 36v25c0 28 14 48 33 59 19-11 33-31 33-59V36L90 23Z" fill="#e9f2fb" stroke="#3578b8" stroke-width="3"/>
  <path d="m75 67 10 10 22-25" fill="none" stroke="#27865e" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
  <text x="90" y="129" text-anchor="middle" font-family="Tahoma,Arial,sans-serif" font-size="13" font-weight="700" fill="#1b3550">استعلام نماد اعتماد</text>
</svg>
SVG;

$serve($fallback, 'image/svg+xml', 300);
