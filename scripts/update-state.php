<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Support/UpdateManager.php';

$channel = $argv[1] ?? '';
$ref = trim((string) ($argv[2] ?? ''));

if (!in_array($channel, ['release', 'beta'], true) || $ref === '') {
    fwrite(STDERR, "usage: php scripts/update-state.php <release|beta> <ref>\n");
    exit(2);
}

$state = bluebotWriteInstalledBuildState($channel, $ref);
if ($state === []) {
    fwrite(STDERR, "Failed to persist build state.\n");
    exit(3);
}

// Database state is best-effort only. Build/version persistence must never
// depend on the full BlueBot runtime being bootable.
try {
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/function.php';

    if (function_exists('update')) {
        update('setting', 'update_installed_channel', $channel);
        update('setting', 'update_installed_ref', $ref);
        update('setting', 'update_last_notified', $ref);
    }
} catch (Throwable $error) {
    // The build state is already persisted; runtime DB sync can recover later.
}

echo (string) ($state['display_version'] ?? '') . PHP_EOL;
