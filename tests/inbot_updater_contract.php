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

$manager = @file_get_contents($root . '/src/Support/UpdateManager.php');
foreach ([
    'bluebotUpdateLatestRelease',
    'bluebotUpdateLatestBeta',
    'bluebotQueueUpdate',
    '/var/lib/bluebot',
    'bluebot_update_run',
] as $needle) {
    if ($manager === false || !str_contains($manager, $needle)) {
        $failures[] = "UpdateManager missing contract: {$needle}";
    }
}

$jobs = @file_get_contents($root . '/cronbot/jobs.php');
if ($jobs === false || !str_contains($jobs, "'UpdateNotifier'")) {
    $failures[] = 'UpdateNotifier is not scheduled.';
}

$installer = @file_get_contents($root . '/install.sh');
if ($installer !== false && str_contains($installer, "curl -s 'https://\$URL_PATH/table.php'")) {
    $failures[] = 'Updater still depends on public HTTP for database migration.';
}
if ($installer === false || !str_contains($installer, "php scripts/repair-webhook.php")) {
    $failures[] = 'Updater does not refresh the main Telegram webhook after update.';
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
] as $needle) {
    if ($admin === false || !str_contains($admin, $needle)) {
        $failures[] = "Admin updater flow missing: {$needle}";
    }
}

$keyboard = @file_get_contents($root . '/keyboard.php');
if ($keyboard === false || !str_contains($keyboard, "'web_app' => ['url' => \$miniAppUrl]")) {
    $failures[] = 'Direct Mini App web_app button is missing.';
}

$appHtaccess = @file_get_contents($root . '/app/.htaccess');
if ($appHtaccess === false || !str_contains($appHtaccess, 'max-age=31536000, immutable')) {
    $failures[] = 'Mini App immutable asset caching is missing.';
}
if ($appHtaccess === false || !str_contains($appHtaccess, 'no-cache, no-store, must-revalidate')) {
    $failures[] = 'Mini App shell no-cache policy is missing.';
}

if ($failures !== []) {
    fwrite(STDERR, "In-bot updater contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "In-bot updater contracts OK.\n";
