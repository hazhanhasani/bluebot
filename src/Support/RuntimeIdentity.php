<?php

declare(strict_types=1);

function bluebotRuntimeIdentityCachePath(): string
{
    return dirname(__DIR__, 2) . '/storage/cache/runtime_identity.json';
}

function bluebotRuntimeBotKey(): string
{
    global $APIKEY;

    $token = trim((string) ($APIKEY ?? ''));
    return $token === '' ? '' : substr(hash('sha256', $token), 0, 24);
}

function bluebotNormalizePublicHost(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $value)) {
        $parsed = parse_url($value, PHP_URL_HOST);
        $value = is_string($parsed) ? $parsed : '';
    }

    $value = trim($value, " \t\n\r\0\x0B/");
    if ($value === '') {
        return '';
    }

    // Strip an optional port from ordinary Host headers.
    if ($value[0] !== '[' && substr_count($value, ':') === 1) {
        [$value] = explode(':', $value, 2);
    }

    $value = strtolower(rtrim($value, '.'));

    // Telegram Web Apps require a public HTTPS hostname.
    if ($value === 'localhost' || filter_var($value, FILTER_VALIDATE_IP)) {
        return '';
    }

    if (strlen($value) > 253
        || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $value)) {
        return '';
    }

    return $value;
}

function bluebotRequestIsHttps(array $server): bool
{
    $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }

    if ((string) ($server['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
    $forwardedProto = strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto === 'https'
        && function_exists('bluebotIsCloudflareProxyIp')
        && bluebotIsCloudflareProxyIp($remote)) {
        return true;
    }

    return false;
}

function bluebotRuntimeHostFromRequest(?array $server = null): string
{
    $server ??= $_SERVER;

    if ($server === [] || !bluebotRequestIsHttps($server)) {
        return '';
    }

    $host = bluebotNormalizePublicHost((string) ($server['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        return $host;
    }

    return bluebotNormalizePublicHost((string) ($server['SERVER_NAME'] ?? ''));
}

function bluebotReadRuntimeIdentity(): array
{
    $path = bluebotRuntimeIdentityCachePath();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    $botKey = bluebotRuntimeBotKey();
    if ($botKey === '' || !hash_equals($botKey, (string) ($decoded['bot_key'] ?? ''))) {
        return [];
    }

    return $decoded;
}

function bluebotWriteRuntimeIdentity(string $domain, string $username, int $telegramCheckedAt = 0, int $telegramAttemptedAt = 0): bool
{
    $domain = bluebotNormalizePublicHost($domain);
    $username = ltrim(trim($username), '@');
    $botKey = bluebotRuntimeBotKey();

    if ($botKey === '' || $domain === '') {
        return false;
    }

    if ($username !== '' && !preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
        $username = '';
    }

    $path = bluebotRuntimeIdentityCachePath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    return @file_put_contents($path, json_encode([
        'bot_key' => $botKey,
        'domain' => $domain,
        'username' => $username,
        'updated_at' => time(),
        'telegram_checked_at' => $telegramCheckedAt,
        'telegram_attempted_at' => $telegramAttemptedAt,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function bluebotPublicDomain(): string
{
    global $domainhosts;

    $override = bluebotNormalizePublicHost((string) getenv('BLUEBOT_PUBLIC_DOMAIN'));
    if ($override !== '') {
        return $override;
    }

    $requestHost = bluebotRuntimeHostFromRequest();
    if ($requestHost !== '') {
        return $requestHost;
    }

    $cached = bluebotReadRuntimeIdentity();
    $cachedHost = bluebotNormalizePublicHost((string) ($cached['domain'] ?? ''));
    if ($cachedHost !== '') {
        return $cachedHost;
    }

    return bluebotNormalizePublicHost((string) ($domainhosts ?? ''));
}

function bluebotPersistRuntimeIdentityToConfig(string $domain, string $username): bool
{
    $domain = bluebotNormalizePublicHost($domain);
    $username = ltrim(trim($username), '@');

    if ($domain === '' || ($username !== '' && !preg_match('/^[A-Za-z0-9_]{5,32}$/', $username))) {
        return false;
    }

    $path = dirname(__DIR__, 2) . '/config.php';
    if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
        return false;
    }

    $source = @file_get_contents($path);
    if (!is_string($source) || $source === '') {
        return false;
    }

    $escapedDomain = str_replace(['\\', "'"], ['\\\\', "\\'"], $domain);
    $updated = preg_replace(
        '/^\$domainhosts\s*=\s*\'[^\']*\';/m',
        "\$domainhosts = '{$escapedDomain}';",
        $source,
        1,
        $domainCount
    );

    if (!is_string($updated) || $domainCount !== 1) {
        return false;
    }

    if ($username !== '') {
        $escapedUsername = str_replace(['\\', "'"], ['\\\\', "\\'"], $username);
        $updated = preg_replace(
            '/^\$usernamebot\s*=\s*\'[^\']*\';/m',
            "\$usernamebot = '{$escapedUsername}';",
            $updated,
            1,
            $usernameCount
        );

        if (!is_string($updated) || $usernameCount !== 1) {
            return false;
        }
    }

    if ($updated === $source) {
        return true;
    }

    $tmp = $path . '.identity.' . bin2hex(random_bytes(5));
    if (@file_put_contents($tmp, $updated, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    @chmod($path, 0640);
    return true;
}

function bluebotAdoptRuntimeIdentity(bool $refreshTelegram = false, bool $persistConfig = false): array
{
    global $domainhosts, $usernamebot;

    $cached = bluebotReadRuntimeIdentity();

    $domain = bluebotRuntimeHostFromRequest();
    if ($domain === '') {
        $domain = bluebotNormalizePublicHost((string) ($cached['domain'] ?? ''));
    }
    if ($domain === '') {
        $domain = bluebotNormalizePublicHost((string) ($domainhosts ?? ''));
    }

    $username = ltrim(trim((string) ($cached['username'] ?? $usernamebot ?? '')), '@');

    // Host/cache writes happen on every webhook; only a successful getMe may
    // extend the bot username's freshness window.
    $telegramCheckedAt = (int) ($cached['telegram_checked_at'] ?? 0);
    $telegramAttemptedAt = (int) ($cached['telegram_attempted_at'] ?? 0);
    $identityFresh = $telegramCheckedAt > 0
        && (time() - $telegramCheckedAt) < 21600
        && $username !== ''
        && preg_match('/^[A-Za-z0-9_]{5,32}$/', $username);

    // A temporary Telegram outage must not add a network timeout to every
    // incoming update. Failed attempts remain stale and retry after one minute.
    $telegramRetryReady = $telegramAttemptedAt <= 0 || (time() - $telegramAttemptedAt) >= 60;
    if ($refreshTelegram && !$identityFresh && $telegramRetryReady && function_exists('telegram')) {
        $telegramAttemptedAt = time();
        try {
            $me = telegram('getMe');
            $remoteUsername = ltrim(trim((string) ($me['result']['username'] ?? '')), '@');
            if (!empty($me['ok']) && preg_match('/^[A-Za-z0-9_]{5,32}$/', $remoteUsername)) {
                $username = $remoteUsername;
                $telegramCheckedAt = time();
            }
        } catch (Throwable $error) {
            if (function_exists('bluebotLog')) {
                bluebotLog('warning', 'Unable to refresh runtime bot identity', [
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }

    if ($domain !== '') {
        $domainhosts = $domain;
    }

    if ($username !== '' && preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
        $usernamebot = $username;
    }

    if ($domain !== '') {
        bluebotWriteRuntimeIdentity($domain, $username, $telegramCheckedAt, $telegramAttemptedAt);
        if ($persistConfig) {
            bluebotPersistRuntimeIdentityToConfig($domain, $username);
        }
    }

    return [
        'domain' => $domain,
        'username' => $username,
        'bot_key' => bluebotRuntimeBotKey(),
    ];
}
