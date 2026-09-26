<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$expectedAdapters = [
    'Marzban.php',
    'Marzneshin.php',
    'SolidLayer.php',
    'ThreeXUI.php',
    'AlirezaXUI.php',
    'Hiddify.php',
    'SUI.php',
    'WGDashboard.php',
    'MikroTik.php',
    'IBSng.php',
    'MirzaAgent.php',
    'Rebecca.php',
];

$legacyRootAdapters = [
    'Marzban.php',
    'marzneshin.php',
    'solidlayer.php',
    'x-ui_single.php',
    'alireza_single.php',
    'hiddify.php',
    's_ui.php',
    'WGDashboard.php',
    'mikrotik.php',
    'ibsng.php',
    'mirza_agent.php',
    'Rebecca.php',
];

$failures = [];
$adapterDir = $root . '/src/Panel/Adapters';

foreach ($expectedAdapters as $file) {
    if (!is_file($adapterDir . '/' . $file)) {
        $failures[] = "Missing organized panel adapter: {$file}";
    }
}

foreach ($legacyRootAdapters as $file) {
    if (is_file($root . '/' . $file)) {
        $failures[] = "Legacy root adapter still exists: {$file}";
    }
}

if (!is_file($root . '/src/Support/JalaliDate.php')) {
    $failures[] = 'Missing src/Support/JalaliDate.php';
}
if (is_file($root . '/jdf.php')) {
    $failures[] = 'Legacy root jdf.php still exists';
}

if (!is_file($root . '/assets/images/qr-background.jpg')) {
    $failures[] = 'Missing default QR background asset.';
}
if (is_file($root . '/images.jpg')) {
    $failures[] = 'Legacy root images.jpg still exists.';
}

if (!is_file($root . '/cronbot/NotificationsService.php')) {
    $failures[] = 'Missing corrected cronbot/NotificationsService.php';
}
if (is_file($root . '/cronbot/NoticationsService.php')) {
    $failures[] = 'Misspelled cronbot/NoticationsService.php still exists';
}

$jobs = @file_get_contents($root . '/cronbot/jobs.php');
if ($jobs === false || !str_contains($jobs, "'NotificationsService'")) {
    $failures[] = 'Cron registry does not use NotificationsService.';
}

$gitignore = @file($root . '/.gitignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($gitignore)) {
    $failures[] = 'Unable to read .gitignore.';
} elseif (in_array('docs', array_map('trim', $gitignore), true) || in_array('/docs/', array_map('trim', $gitignore), true)) {
    $failures[] = 'Source documentation directory must not be ignored.';
}

$installerChecks = @file_get_contents($root . '/install/checks.php');
foreach ($expectedAdapters as $file) {
    $needle = "src/Panel/Adapters/{$file}";
    if ($installerChecks === false || !str_contains($installerChecks, $needle)) {
        $failures[] = "Installer checks do not validate {$file}";
    }
}

$index = @file_get_contents($root . '/index.php');
if ($index === false || !str_contains($index, "/src/Support/JalaliDate.php")) {
    $failures[] = 'index.php does not load the organized JalaliDate helper.';
}
if ($index !== false && str_contains($index, "'images.jpg'")) {
    $failures[] = 'index.php still references legacy root images.jpg.';
}

$functions = @file_get_contents($root . '/function.php');
if ($functions === false || !str_contains($functions, "storage/qr/background.jpg")) {
    $failures[] = 'QR runtime background is not stored under storage/qr.';
}
if ($functions === false || !str_contains($functions, "assets/images/qr-background.jpg")) {
    $failures[] = 'QR default background asset is not resolved from assets/images.';
}

$admin = @file_get_contents($root . '/admin.php');
if ($admin !== false && (
    str_contains($admin, 'file_put_contents("images.jpg"')
    || str_contains($admin, 'file_put_contents("custom.jpg"')
)) {
    $failures[] = 'Admin still writes mutable QR files into the repository root.';
}

$panels = @file_get_contents($root . '/panels.php');
foreach ($expectedAdapters as $file) {
    $needle = "/src/Panel/Adapters/{$file}";
    if ($panels === false || !str_contains($panels, $needle)) {
        $failures[] = "panels.php does not load {$file}";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Project structure contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Project structure contract OK.\n";
