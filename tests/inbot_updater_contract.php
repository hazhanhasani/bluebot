<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$requiredFiles = [
    'src/Support/UpdateManager.php',
    'src/Support/MiniApp.php',
    'cronbot/UpdateNotifier.php',
    'scripts/bluebot-update-worker.sh',
    'scripts/update-state.php',
];

foreach ($requiredFiles as $file) {
    if (!is_file($root . '/' . $file)) {
        $failures[] = "Missing updater component: {$file}";
    }
}

$installerVersioning = @file_get_contents($root . '/install.sh');
foreach ([
    'installed_build_state_path',
    'get_main_commit_sha',
    'record_installed_build',
    '-beta+',
] as $needle) {
    if ($installerVersioning === false || !str_contains($installerVersioning, $needle)) {
        $failures[] = "Installer build-version contract missing: {$needle}";
    }
}

$manager = @file_get_contents($root . '/src/Support/UpdateManager.php');
foreach ([
    'bluebotUpdateLatestRelease',
    'bluebotUpdateLatestReleaseFromRedirect',
    'bluebotUpdateLatestReleaseFromRaw',
    'bluebotUpdateFetchText',
    'bluebotUpdateNormalizeDisplayVersion',
    'bluebotUpdateSourceCachePath',
    'bluebotUpdateSourceCachedTarget',
    'bluebotUpdateSourceFailureShouldNotify',
    'bluebotUpdateMarkSourceFailureNotified',
    'bluebotUpdateLatestBeta',
    'bluebotQueueUpdate',
    '/var/lib/bluebot',
    'bluebot_update_run',
    "strtolower((string) (\$parts['host'] ?? '')) !== 'api.github.com'",
    'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS',
    'CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS',
    'CURLOPT_SSL_VERIFYPEER => true',
    'CURLOPT_SSL_VERIFYHOST => 2',
] as $needle) {
    if ($manager === false || !str_contains($manager, $needle)) {
        $failures[] = "UpdateManager missing contract: {$needle}";
    }
}

$jobs = @file_get_contents($root . '/cronbot/jobs.php');
if ($jobs === false || !str_contains($jobs, "'UpdateNotifier'")) {
    $failures[] = 'UpdateNotifier is not scheduled.';
}
if ($jobs === false || !str_contains($jobs, "['job' => 'UpdateNotifier', 'schedule' => '* * * * *'")) {
    $failures[] = 'UpdateNotifier must check for updates every minute.';
}
if ($jobs === false || !str_contains($jobs, 'بررسی نسخه جدید بلو پنل')) {
    $failures[] = 'UpdateNotifier cron title must use Blue Panel branding.';
}

$installer = @file_get_contents($root . '/install.sh');
if ($installer !== false && preg_match('/curl[^\n]*https?:\/\/[^\n]*\/table\.php/', $installer)) {
    $failures[] = 'Installer/updater still exposes database migration through public HTTP.';
}
$legacyDirectWebhook = 'curl -F "url=https://${DOMAIN_NAME}/index.php"';
if ($installer !== false && str_contains($installer, $legacyDirectWebhook)) {
    $failures[] = 'Migration still creates an unprotected Telegram webhook directly.';
}
if ($installer === false || !str_contains($installer, "php scripts/repair-webhook.php")) {
    $failures[] = 'Updater does not refresh the main Telegram webhook after update.';
}

foreach ([
    'bluebot_validate_source_tree',
    'bluebot_validate_live_tree',
    'bluebot_resolve_extracted_root',
    'bluebot_verify_panel_route',
    'STAGED_DIR="${BOT_DIR}.staging"',
    'ROLLBACK_DIR="${BOT_DIR}.rollback"',
    'panel/service.php',
    'panel/invoice.php',
    'panel/category.php',
    'panel/digital_services.php',
] as $needle) {
    if ($installer === false || !str_contains($installer, $needle)) {
        $failures[] = "Atomic updater safety contract missing: {$needle}";
    }
}

if ($installer !== false) {
    $updatePos = strpos($installer, 'function update_bot()');
    $stageValidatePos = strpos($installer, 'bluebot_validate_live_tree "$STAGED_DIR"', $updatePos ?: 0);
    $swapPos = strpos($installer, 'mv "$BOT_DIR" "$ROLLBACK_DIR"', $updatePos ?: 0);
    if ($updatePos === false || $stageValidatePos === false || $swapPos === false || $stageValidatePos > $swapPos) {
        $failures[] = 'Updater must validate the complete staged tree before swapping the live installation.';
    }

    $panelHealthPos = strpos($installer, 'bluebot_verify_panel_route "$DOMAIN_NAME"', $updatePos ?: 0);
    $successPos = strpos($installer, 'BlueBot updated successfully', $updatePos ?: 0);
    if ($panelHealthPos === false || $successPos === false || $panelHealthPos > $successPos) {
        $failures[] = 'Updater must verify the /panel route before reporting update success.';
    }

    $configAbortPos = strpos($installer, 'config.php is missing. Update aborted before touching the live install.', $updatePos ?: 0);
    if ($configAbortPos === false) {
        $failures[] = 'Updater must abort before live swap when config.php is missing.';
    }
}

foreach ([
    'install_update_worker',
    '/usr/local/sbin/bluebot-update-worker',
    '--background',
    'bluebot_storage_backup',
] as $needle) {
    if ($installer === false || !str_contains($installer, $needle)) {
        $failures[] = "Installer missing updater contract: {$needle}";
    }
}

$worker = @file_get_contents($root . '/scripts/bluebot-update-worker.sh');
if ($worker === false || !str_contains($worker, 'bluebotUpdateCurrentVersion')) {
    $failures[] = 'Update worker does not read the build-aware installed version.';
}
if ($worker === false || !str_contains($worker, 'بروزرسانی بلو پنل')) {
    $failures[] = 'Update worker user-facing notifications must use Blue Panel branding.';
}
if ($worker === false || !str_contains($worker, 'INSTALLED_CHANNEL')) {
    $failures[] = 'Update worker does not preserve the resolved installed source channel.';
}
if ($worker === false || !str_contains($worker, 'UPDATE_ARGS+=(--version "$REF")')) {
    $failures[] = 'Release updates must be pinned to the queued release tag.';
}
if ($worker === false || !str_contains($worker, 'UPDATE_ARGS+=(--ref "$REF")')) {
    $failures[] = 'Beta updates must be pinned to the queued commit SHA.';
}
if ($worker !== false && str_contains($worker, 'update --channel "$CHANNEL" --background')) {
    $failures[] = 'Update worker must not re-resolve a moving channel after the update was queued.';
}
if ($worker !== false) {
    $managerPos = strpos($worker, 'bluebotUpdateCurrentVersion');
    $fallbackPos = strpos($worker, 'tr -d');
    if ($managerPos === false || $fallbackPos === false || $managerPos > $fallbackPos) {
        $failures[] = 'Update worker must prefer build-aware version resolution before file fallback.';
    }
}

if ($manager === false || !str_contains($manager, "'installed_channel' => \$installedChannel")) {
    $failures[] = 'Queued update payload does not carry the resolved installed channel.';
}

$stateScript = @file_get_contents($root . '/scripts/update-state.php');
if ($stateScript === false || !str_contains($stateScript, 'bluebotWriteInstalledBuildState')) {
    $failures[] = 'Update-state CLI does not persist build metadata directly.';
}
if ($stateScript !== false) {
    $managerRequirePos = strpos($stateScript, "/src/Support/UpdateManager.php");
    $runtimeRequirePos = strpos($stateScript, "/function.php");
    if ($managerRequirePos === false || ($runtimeRequirePos !== false && $managerRequirePos > $runtimeRequirePos)) {
        $failures[] = 'Build state must be persisted before full application bootstrap.';
    }
}

if ($manager === false || !str_contains($manager, 'bluebotWriteInstalledBuildState')) {
    $failures[] = 'UpdateManager is missing the independent build-state writer.';
}
if ($manager === false || !str_contains($manager, "'display_version' => \$display")) {
    $failures[] = 'Build state does not persist a display version.';
}

if ($installerVersioning === false || !str_contains($installerVersioning, '$BOT_DIR_DEFAULT/version')) {
    $failures[] = 'Installer does not synchronize the runtime version file.';
}
if ($installerVersioning === false || !str_contains($installerVersioning, '--ref <sha>')) {
    $failures[] = 'Installer CLI is missing exact commit-ref support.';
}
if ($installerVersioning === false || !str_contains($installerVersioning, 'archive/${ARG_REF}.zip')) {
    $failures[] = 'Installer does not download the exact queued beta commit.';
}

$setting = @file_get_contents($root . '/db/tables/setting.php');
foreach ([
    'update_channel',
    'update_last_notified',
    'update_installed_channel',
    'update_installed_ref',
] as $needle) {
    if ($setting === false || !str_contains($setting, $needle)) {
        $failures[] = "Setting schema missing: {$needle}";
    }
}

$admin = @file_get_contents($root . '/admin.php');
foreach ([
    'bluebot_update_channel_',
    'bluebot_update_status',
    'bluebot_update_run',
    'bluebotEnsureMiniAppMenuButton',
    'bluebotUpdateLatest(bluebotUpdateChannel($updateSettings), true)',
    'بروزرسانی بلو پنل',
] as $needle) {
    if ($admin === false || !str_contains($admin, $needle)) {
        $failures[] = "Admin updater flow missing: {$needle}";
    }
}

$keyboard = @file_get_contents($root . '/keyboard.php');
if ($keyboard !== false && str_contains($keyboard, "'web_app' => ['url' =>")) {
    $failures[] = 'Main customer keyboard must not inject a direct Mini App web_app button.';
}

$appHtaccess = @file_get_contents($root . '/app/.htaccess');
if ($appHtaccess === false || !str_contains($appHtaccess, 'max-age=31536000, immutable')) {
    $failures[] = 'Mini App immutable asset caching is missing.';
}
if ($appHtaccess === false || !str_contains($appHtaccess, 'no-cache, no-store, must-revalidate')) {
    $failures[] = 'Mini App shell no-cache policy is missing.';
}

$notifier = @file_get_contents($root . '/cronbot/UpdateNotifier.php');
foreach ([
    'bluebotUpdateSourceFailureShouldNotify',
    'bluebotUpdateMarkSourceFailureNotified',
    'بررسی بروزرسانی بلو پنل ناموفق است',
] as $needle) {
    if ($notifier === false || !str_contains($notifier, $needle)) {
        $failures[] = "UpdateNotifier source-health contract missing: {$needle}";
    }
}

if ($manager !== false) {
    $rawPos = strpos($manager, '$rawRelease = bluebotUpdateLatestReleaseFromRaw()');
    $apiPos = strpos($manager, 'bluebotUpdateFetchJson("https://api.github.com/repos/{$repo}/releases/latest")');
    if ($rawPos === false || $apiPos === false || $rawPos > $apiPos) {
        $failures[] = 'Stable update checks must prefer the raw release source before GitHub API.';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "In-bot updater contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "In-bot updater contracts OK.\n";
