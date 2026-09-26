<?php

declare(strict_types=1);

function bluebotUpdateRepository(): string
{
    return 'hazhanhasani/bluebot';
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
    $root = dirname(__DIR__, 2);
    $basePath = $root . '/version';
    $base = is_file($basePath) ? trim((string) file_get_contents($basePath)) : '';
    $build = bluebotInstalledBuildState();
    $channel = bluebotUpdateNormalizeChannel($build['channel'] ?? 'release');
    $ref = trim((string) ($build['ref'] ?? ''));

    if ($channel === 'beta' && $ref !== '') {
        return ($base !== '' ? $base . '-' : '') . 'beta+' . substr($ref, 0, 7);
    }

    if ($channel === 'release' && $ref !== '') {
        return ltrim($ref, 'vV');
    }

    return $base;
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

function bluebotUpdateFetchJson(string $url): ?array
{
    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT_MS => 3000,
        CURLOPT_TIMEOUT_MS => 8000,
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

function bluebotUpdateLatestRelease(): ?array
{
    $repo = bluebotUpdateRepository();
    $release = bluebotUpdateFetchJson("https://api.github.com/repos/{$repo}/releases/latest");

    if (is_array($release) && !empty($release['tag_name'])) {
        $tag = (string) $release['tag_name'];
        $name = trim((string) ($release['name'] ?? ''));
        $body = trim((string) ($release['body'] ?? ''));
        return [
            'channel' => 'release',
            'ref' => $tag,
            'version' => ltrim($tag, 'vV'),
            'label' => $name !== '' ? $name : $tag,
            'summary' => $body !== '' ? mb_substr(preg_replace('/\s+/', ' ', $body), 0, 700) : '',
            'url' => (string) ($release['html_url'] ?? "https://github.com/{$repo}/releases"),
            'published_at' => (string) ($release['published_at'] ?? ''),
        ];
    }

    $tags = bluebotUpdateFetchJson("https://api.github.com/repos/{$repo}/tags?per_page=1");
    if (is_array($tags) && isset($tags[0]['name'])) {
        $tag = (string) $tags[0]['name'];
        return [
            'channel' => 'release',
            'ref' => $tag,
            'version' => ltrim($tag, 'vV'),
            'label' => $tag,
            'summary' => '',
            'url' => "https://github.com/{$repo}/releases/tag/" . rawurlencode($tag),
            'published_at' => '',
        ];
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

function bluebotUpdateLatest(?string $channel = null): ?array
{
    $channel = bluebotUpdateNormalizeChannel($channel ?? bluebotUpdateChannel());

    if ($channel === 'beta') {
        return bluebotUpdateLatestBeta();
    }

    $release = bluebotUpdateLatestRelease();
    if ($release !== null) {
        if ($channel === 'auto') {
            $release['channel'] = 'auto';
        }
        return $release;
    }

    if ($channel === 'auto') {
        $beta = bluebotUpdateLatestBeta();
        if ($beta !== null) {
            $beta['channel'] = 'auto';
        }
        return $beta;
    }

    return null;
}

function bluebotUpdateAvailable(array $target, ?array $settings = null): bool
{
    $settings ??= bluebotUpdateSettings();
    $sourceChannel = (string) ($target['channel'] ?? '');

    if (!empty($target['version']) && $sourceChannel !== 'beta') {
        $current = ltrim(bluebotUpdateCurrentVersion(), 'vV');
        $latest = ltrim((string) $target['version'], 'vV');

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
    update('setting', 'update_installed_channel', bluebotUpdateNormalizeChannel($channel));
    update('setting', 'update_installed_ref', trim($ref));
    update('setting', 'update_last_notified', trim($ref));
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
    $target = bluebotUpdateLatest($channel);
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

    $payload = [
        'status' => 'queued',
        'requested_at' => gmdate(DATE_ATOM),
        'requested_by' => (string) $adminId,
        'chat_id' => (string) $adminId,
        'channel' => $channel,
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

    $text = "🔄 <b>مرکز بروزرسانی BlueBot</b>\n\n";
    $text .= "📦 کانال انتخابی: <b>" . bluebotUpdateChannelLabel($channel) . "</b>\n";
    $text .= "🔹 نسخه فعلی: <code>" . htmlspecialchars($current, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</code>\n";

    if ($target === null) {
        $text .= "⚠️ دریافت اطلاعات نسخه جدید از GitHub ممکن نشد.\n";
    } else {
        $label = htmlspecialchars((string) ($target['label'] ?? $target['ref'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $available = bluebotUpdateAvailable($target, $settings);
        $text .= "🆕 آخرین نسخه: <code>{$label}</code>\n";
        $text .= $available ? "🟢 بروزرسانی جدید آماده است.\n" : "✅ BlueBot بروز است.\n";
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
