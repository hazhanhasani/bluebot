<?php

declare(strict_types=1);

function bluebotUpdateRepository(): string
{
    return 'hazhanhasani/bluebot';
}

function bluebotUpdateNormalizeDisplayVersion(string $version): string
{
    $version = ltrim(trim($version), 'vV');
    if ($version === '') {
        return '';
    }

    // Compatibility with older beta builds that wrote beta+<sha>-<version>.
    if (preg_match('/^beta\+([0-9a-f]{7,40})-(\d+(?:\.\d+){1,3})$/i', $version, $match)) {
        return $match[2] . '-beta+' . strtolower($match[1]);
    }

    return $version;
}

function bluebotUpdateBaseVersion(string $version): string
{
    $version = bluebotUpdateNormalizeDisplayVersion($version);
    $base = preg_replace('/-beta\+[0-9a-f]{7,40}$/i', '', $version) ?? $version;

    if (preg_match('/\d+(?:\.\d+){1,3}/', $base, $match)) {
        return $match[0];
    }

    return $base;
}


function bluebotWriteInstalledBuildState(string $channel, string $ref): array
{
    $root = dirname(__DIR__, 2);
    $channel = bluebotUpdateNormalizeChannel($channel);
    $ref = trim($ref);

    if ($channel === 'auto') {
        $channel = 'release';
    }

    $versionPath = $root . '/version';
    $base = is_file($versionPath) ? bluebotUpdateBaseVersion((string) file_get_contents($versionPath)) : '';

    if ($channel === 'beta' && $ref !== '') {
        $display = ($base !== '' ? $base . '-' : '') . 'beta+' . substr($ref, 0, 7);
    } elseif ($channel === 'release' && $ref !== '') {
        $display = ltrim($ref, 'vV');
    } else {
        $display = $base;
    }

    $directory = $root . '/storage/update';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return [];
    }

    $payload = [
        'channel' => $channel,
        'ref' => $ref,
        'label' => $channel === 'beta' ? 'main@' . substr($ref, 0, 7) : $ref,
        'base_version' => $base,
        'display_version' => $display,
        'installed_at' => gmdate(DATE_ATOM),
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false || @file_put_contents($directory . '/build.json', $encoded . PHP_EOL, LOCK_EX) === false) {
        return [];
    }

    if ($display !== '') {
        @file_put_contents($versionPath, $display . PHP_EOL, LOCK_EX);
    }

    return $payload;
}

function bluebotInstalledBuildState(): array
{
    $root = dirname(__DIR__, 2);
    $path = $root . '/storage/update/build.json';

    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function bluebotUpdateCurrentVersion(): string
{
    $build = bluebotInstalledBuildState();
    $display = bluebotUpdateNormalizeDisplayVersion((string) ($build['display_version'] ?? ''));
    if ($display !== '') {
        return $display;
    }

    $root = dirname(__DIR__, 2);
    $basePath = $root . '/version';
    return is_file($basePath)
        ? bluebotUpdateNormalizeDisplayVersion((string) file_get_contents($basePath))
        : '';
}

function bluebotUpdateNormalizeChannel($channel): string
{
    $channel = strtolower(trim((string) $channel));
    return in_array($channel, ['release', 'beta', 'auto'], true) ? $channel : 'release';
}

function bluebotUpdateSettings(): array
{
    $row = select('setting', '*', null, null, 'select', ['cache' => false]);
    return is_array($row) ? $row : [];
}

function bluebotUpdateChannel(?array $settings = null): string
{
    $settings ??= bluebotUpdateSettings();
    return bluebotUpdateNormalizeChannel($settings['update_channel'] ?? 'release');
}

function bluebotUpdateSetChannel(string $channel): string
{
    $channel = bluebotUpdateNormalizeChannel($channel);
    update('setting', 'update_channel', $channel);
    update('setting', 'update_last_notified', '');
    return $channel;
}

function bluebotUpdateSourceCachePath(string $channel): string
{
    $channel = bluebotUpdateNormalizeChannel($channel);
    return dirname(__DIR__, 2) . '/storage/update/source-' . $channel . '.json';
}

function bluebotUpdateSourceState(string $channel): array
{
    $path = bluebotUpdateSourceCachePath($channel);
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function bluebotUpdateSourceCachedTarget(string $channel, int $maxAge): ?array
{
    $state = bluebotUpdateSourceState($channel);
    $checkedAt = (int) ($state['checked_ts'] ?? 0);
    if ($checkedAt <= 0 || time() - $checkedAt > max(1, $maxAge)) {
        return null;
    }

    $target = $state['target'] ?? null;
    return !empty($state['ok']) && is_array($target) ? $target : null;
}

function bluebotUpdateSourceCacheIsFreshFailure(string $channel, int $maxAge = 60): bool
{
    $state = bluebotUpdateSourceState($channel);
    $checkedAt = (int) ($state['checked_ts'] ?? 0);

    return $checkedAt > 0
        && time() - $checkedAt <= max(1, $maxAge)
        && empty($state['ok']);
}

function bluebotUpdateStoreSourceState(string $channel, ?array $target, string $error = ''): void
{
    $channel = bluebotUpdateNormalizeChannel($channel);
    $path = bluebotUpdateSourceCachePath($channel);
    $previous = bluebotUpdateSourceState($channel);
    $directory = dirname($path);

    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }

    $failures = $target !== null ? 0 : ((int) ($previous['failures'] ?? 0) + 1);
    $payload = [
        'channel' => $channel,
        'ok' => $target !== null,
        'checked_at' => gmdate(DATE_ATOM),
        'checked_ts' => time(),
        'failures' => $failures,
        'last_alert_ts' => (int) ($previous['last_alert_ts'] ?? 0),
        'error' => $target !== null ? '' : mb_substr(trim($error), 0, 300),
        'target' => $target,
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        return;
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $encoded . PHP_EOL, LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }

    @chmod($tmp, 0660);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
    }
}

function bluebotUpdateSourceFailureShouldNotify(string $channel): bool
{
    $state = bluebotUpdateSourceState($channel);
    if ((int) ($state['failures'] ?? 0) < 3) {
        return false;
    }

    return time() - (int) ($state['last_alert_ts'] ?? 0) >= 3600;
}

function bluebotUpdateMarkSourceFailureNotified(string $channel): void
{
    $state = bluebotUpdateSourceState($channel);
    if ($state === []) {
        return;
    }

    $state['last_alert_ts'] = time();
    $path = bluebotUpdateSourceCachePath($channel);
    $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded !== false) {
        @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX);
    }
}

function bluebotUpdateFetchJson(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'api.github.com') {
        return null;
    }

    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_TIMEOUT_MS => 8000,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'User-Agent: BlueBot-Updater',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function bluebotUpdateFetchText(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'raw.githubusercontent.com'
        || !function_exists('curl_init')) {
        return null;
    }

    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_TIMEOUT_MS => 6000,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: text/plain',
            'User-Agent: BlueBot-Updater',
        ],
    ]);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return null;
    }

    return trim($raw);
}

function bluebotUpdateLatestReleaseFromRedirect(): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $repo = bluebotUpdateRepository();
    $url = "https://github.com/{$repo}/releases/latest";
    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_TIMEOUT_MS => 6000,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'User-Agent: BlueBot-Updater',
        ],
    ]);

    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    curl_close($curl);

    if ($status < 200 || $status >= 400) {
        return null;
    }

    $path = (string) (parse_url($effectiveUrl, PHP_URL_PATH) ?? '');
    if (!preg_match('~/releases/tag/([^/]+)$~', rtrim($path, '/'), $match)) {
        return null;
    }

    $tag = rawurldecode($match[1]);
    $version = ltrim($tag, 'vV');
    if (!preg_match('/^\d+(?:\.\d+){2,3}$/', $version)) {
        return null;
    }

    return [
        'channel' => 'release',
        'ref' => $tag,
        'version' => $version,
        'label' => $version,
        'summary' => '',
        'url' => $effectiveUrl,
        'published_at' => '',
        'source' => 'github_release_redirect',
    ];
}

function bluebotUpdateLatestReleaseFromRaw(): ?array
{
    $repo = bluebotUpdateRepository();
    $version = bluebotUpdateFetchText("https://raw.githubusercontent.com/{$repo}/main/version");
    if (!is_string($version) || !preg_match('/^\d+(?:\.\d+){2,3}$/', $version)) {
        return null;
    }

    $tag = 'v' . $version;
    $tagVersion = bluebotUpdateFetchText(
        "https://raw.githubusercontent.com/{$repo}/" . rawurlencode($tag) . "/version"
    );
    if (!is_string($tagVersion) || !hash_equals($version, trim($tagVersion))) {
        return null;
    }

    return [
        'channel' => 'release',
        'ref' => $tag,
        'version' => $version,
        'label' => $version,
        'summary' => '',
        'url' => "https://github.com/{$repo}/releases/tag/" . rawurlencode($tag),
        'published_at' => '',
        'source' => 'raw_tag_version',
    ];
}

function bluebotUpdateLatestRelease(): ?array
{
    $repo = bluebotUpdateRepository();

    // Stable checks run every minute. Prefer raw GitHub files so the notifier
    // does not consume the unauthenticated api.github.com hourly quota.
    $rawRelease = bluebotUpdateLatestReleaseFromRaw();
    if ($rawRelease !== null) {
        return $rawRelease;
    }

    $redirectRelease = bluebotUpdateLatestReleaseFromRedirect();
    if ($redirectRelease !== null) {
        return $redirectRelease;
    }

    $release = bluebotUpdateFetchJson("https://api.github.com/repos/{$repo}/releases/latest");

    if (is_array($release) && !empty($release['tag_name'])) {
        $tag = (string) $release['tag_name'];
        $name = trim((string) ($release['name'] ?? ''));
        $body = trim((string) ($release['body'] ?? ''));
        $version = ltrim($tag, 'vV');
        if (!preg_match('/^\d+(?:\.\d+){2,3}$/', $version)) {
            return null;
        }

        return [
            'channel' => 'release',
            'ref' => $tag,
            'version' => $version,
            'label' => $version,
            'summary' => $body !== '' ? mb_substr(preg_replace('/\s+/', ' ', $body), 0, 700) : '',
            'url' => (string) ($release['html_url'] ?? "https://github.com/{$repo}/releases"),
            'published_at' => (string) ($release['published_at'] ?? ''),
            'source' => 'github_releases_api',
        ];
    }

    $tags = bluebotUpdateFetchJson("https://api.github.com/repos/{$repo}/tags?per_page=1");
    if (is_array($tags) && isset($tags[0]['name'])) {
        $tag = (string) $tags[0]['name'];
        $version = ltrim($tag, 'vV');
        if (preg_match('/^\d+(?:\.\d+){2,3}$/', $version)) {
            return [
                'channel' => 'release',
                'ref' => $tag,
                'version' => $version,
                'label' => $version,
                'summary' => '',
                'url' => "https://github.com/{$repo}/releases/tag/" . rawurlencode($tag),
                'published_at' => '',
                'source' => 'github_tags_api',
            ];
        }
    }

    return null;
}

function bluebotUpdateLatestBeta(): ?array
{
    $repo = bluebotUpdateRepository();
    $commit = bluebotUpdateFetchJson("https://api.github.com/repos/{$repo}/commits/main");
    if (!is_array($commit) || empty($commit['sha'])) {
        return null;
    }

    $sha = (string) $commit['sha'];
    $message = trim((string) ($commit['commit']['message'] ?? ''));
    $firstLine = $message !== '' ? strtok($message, "\n") : '';

    return [
        'channel' => 'beta',
        'ref' => $sha,
        'version' => '',
        'label' => 'main@' . substr($sha, 0, 7),
        'summary' => mb_substr((string) $firstLine, 0, 300),
        'url' => (string) ($commit['html_url'] ?? "https://github.com/{$repo}/commit/{$sha}"),
        'published_at' => (string) ($commit['commit']['committer']['date'] ?? ''),
    ];
}

function bluebotUpdateLatest(?string $channel = null, bool $force = false): ?array
{
    $channel = bluebotUpdateNormalizeChannel($channel ?? bluebotUpdateChannel());
    $successTtl = $channel === 'beta' ? 300 : 55;

    if (!$force) {
        $cached = bluebotUpdateSourceCachedTarget($channel, $successTtl);
        if ($cached !== null) {
            return $cached;
        }
        if (bluebotUpdateSourceCacheIsFreshFailure($channel, 55)) {
            return null;
        }
    }

    $target = null;
    if ($channel === 'beta') {
        $target = bluebotUpdateLatestBeta();
    } else {
        $target = bluebotUpdateLatestRelease();
        if ($target !== null && $channel === 'auto') {
            $target['channel'] = 'auto';
        }

        if ($target === null && $channel === 'auto') {
            $target = bluebotUpdateLatestBeta();
            if ($target !== null) {
                $target['channel'] = 'auto';
            }
        }
    }

    bluebotUpdateStoreSourceState(
        $channel,
        $target,
        $target === null ? 'All configured update sources were unavailable.' : ''
    );

    return $target;
}

function bluebotUpdateAvailable(array $target, ?array $settings = null): bool
{
    $settings ??= bluebotUpdateSettings();
    $sourceChannel = (string) ($target['channel'] ?? '');

    if (!empty($target['version']) && $sourceChannel !== 'beta') {
        $current = bluebotUpdateNormalizeDisplayVersion(bluebotUpdateCurrentVersion());
        $latest = bluebotUpdateNormalizeDisplayVersion((string) $target['version']);

        if ($current === '') {
            return true;
        }

        if (preg_match('/^\d+(?:\.\d+){1,3}(?:[-+].*)?$/', $current)
            && preg_match('/^\d+(?:\.\d+){1,3}(?:[-+].*)?$/', $latest)) {
            return version_compare($latest, $current, '>');
        }

        return $latest !== $current;
    }

    $build = bluebotInstalledBuildState();
    $installedRef = trim((string) ($settings['update_installed_ref'] ?? ''));
    if ($installedRef === '') {
        $installedRef = trim((string) ($build['ref'] ?? ''));
    }
    $targetRef = trim((string) ($target['ref'] ?? ''));

    return $targetRef !== '' && ($installedRef === '' || !hash_equals($installedRef, $targetRef));
}

function bluebotUpdateMarkNotified(string $ref): void
{
    update('setting', 'update_last_notified', $ref);
}

function bluebotUpdateMarkInstalled(string $channel, string $ref): void
{
    $channel = bluebotUpdateNormalizeChannel($channel);
    $ref = trim($ref);

    bluebotWriteInstalledBuildState($channel, $ref);

    if (function_exists('update')) {
        update('setting', 'update_installed_channel', $channel);
        update('setting', 'update_installed_ref', $ref);
        update('setting', 'update_last_notified', $ref);
    }
}

function bluebotUpdateQueueDirectory(): string
{
    return '/var/lib/bluebot';
}

function bluebotUpdateQueuePath(): string
{
    return bluebotUpdateQueueDirectory() . '/update-request.json';
}

function bluebotUpdateStatusPath(): string
{
    return bluebotUpdateQueueDirectory() . '/update-status.json';
}

function bluebotUpdateQueueStatus(): array
{
    $path = bluebotUpdateStatusPath();
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function bluebotQueueUpdate($adminId): array
{
    if (!is_numeric($adminId)) {
        return ['ok' => false, 'message' => 'invalid admin id'];
    }

    $admin = select('admin', '*', 'id_admin', (string) $adminId, 'select', ['cache' => false]);
    if (!is_array($admin) || ($admin['rule'] ?? '') !== 'administrator') {
        return ['ok' => false, 'message' => 'administrator access required'];
    }

    $settings = bluebotUpdateSettings();
    $channel = bluebotUpdateChannel($settings);
    $target = bluebotUpdateLatest($channel, true);
    if ($target === null) {
        // A short network outage must not invalidate a previously verified
        // target. A cached target is safe because release refs and beta SHAs
        // are immutable identifiers.
        $state = bluebotUpdateSourceState($channel);
        $cachedTarget = $state['target'] ?? null;
        $target = is_array($cachedTarget) ? $cachedTarget : null;
    }
    if ($target === null) {
        return ['ok' => false, 'message' => 'unable to resolve update source'];
    }

    if (!bluebotUpdateAvailable($target, $settings)) {
        return ['ok' => false, 'message' => 'already up to date', 'target' => $target];
    }

    $directory = bluebotUpdateQueueDirectory();
    if (!is_dir($directory) || !is_writable($directory)) {
        return [
            'ok' => false,
            'message' => 'update worker is not installed or queue directory is not writable',
            'target' => $target,
        ];
    }

    $installedChannel = !empty($target['version']) ? 'release' : 'beta';

    $payload = [
        'status' => 'queued',
        'requested_at' => gmdate(DATE_ATOM),
        'requested_by' => (string) $adminId,
        'chat_id' => (string) $adminId,
        'channel' => $channel,
        'installed_channel' => $installedChannel,
        'ref' => (string) ($target['ref'] ?? ''),
        'label' => (string) ($target['label'] ?? ''),
    ];

    $tmp = $directory . '/.update-request-' . bin2hex(random_bytes(6)) . '.tmp';
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false || file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'failed to write update queue', 'target' => $target];
    }

    if (!@rename($tmp, bluebotUpdateQueuePath())) {
        @unlink($tmp);
        return ['ok' => false, 'message' => 'failed to activate update queue', 'target' => $target];
    }

    return ['ok' => true, 'message' => 'queued', 'target' => $target];
}


function bluebotUpdateChannelLabel(string $channel): string
{
    return [
        'release' => 'Stable',
        'beta' => 'Beta',
        'auto' => 'Auto',
    ][bluebotUpdateNormalizeChannel($channel)] ?? 'Stable';
}

function bluebotUpdateCenterKeyboard(string $selectedChannel, bool $updateAvailable = false): string
{
    $selectedChannel = bluebotUpdateNormalizeChannel($selectedChannel);
    $button = static function (string $channel, string $label) use ($selectedChannel): array {
        return [
            'text' => ($channel === $selectedChannel ? '✅ ' : '') . $label,
            'callback_data' => 'bluebot_update_channel_' . $channel,
        ];
    };

    $rows = [
        [
            $button('release', 'Stable'),
            $button('beta', 'Beta'),
            $button('auto', 'Auto'),
        ],
        [
            ['text' => '🔍 بررسی نسخه', 'callback_data' => 'bluebot_update_status'],
        ],
    ];

    if ($updateAvailable) {
        array_unshift($rows, [
            ['text' => '🚀 بروزرسانی', 'callback_data' => 'bluebot_update_run'],
        ]);
    }

    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bluebotUpdateCenterText(?array $settings = null, ?array $target = null): string
{
    $settings ??= bluebotUpdateSettings();
    $channel = bluebotUpdateChannel($settings);
    $target ??= bluebotUpdateLatest($channel);
    $current = bluebotUpdateCurrentVersion();

    $text = "🔄 <b>مرکز بروزرسانی بلو پنل</b>\n\n";
    $text .= "📦 کانال انتخابی: <b>" . bluebotUpdateChannelLabel($channel) . "</b>\n";
    $text .= "🔹 نسخه فعلی: <code>" . htmlspecialchars($current, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</code>\n";

    if ($target === null) {
        $text .= "⚠️ ارتباط با منبع انتشار برقرار نشد. چند لحظه دیگر دوباره بررسی کنید.\n";
    } else {
        $label = htmlspecialchars((string) ($target['version'] ?? $target['label'] ?? $target['ref'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $available = bluebotUpdateAvailable($target, $settings);
        $text .= "🆕 آخرین نسخه: <code>{$label}</code>\n";
        $text .= $available ? "🟢 بروزرسانی جدید آماده است.\n" : "✅ بلو پنل بروز است.\n";
    }

    $queue = bluebotUpdateQueueStatus();
    if (($queue['state'] ?? '') === 'running') {
        $text .= "\n⏳ بروزرسانی در حال اجراست...";
    } elseif (($queue['state'] ?? '') === 'failed') {
        $text .= "\n❌ آخرین بروزرسانی ناموفق بوده است.";
    } elseif (($queue['state'] ?? '') === 'success') {
        $text .= "\n✅ آخرین بروزرسانی با موفقیت انجام شده است.";
    }

    $text .= "\n\nStable: آخرین نسخه پایدار\nBeta: آخرین تغییرات main\nAuto: نسخه پایدار و در نبود آن Beta";
    return $text;
}
