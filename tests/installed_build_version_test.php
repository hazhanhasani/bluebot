<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$stateDir = $root . '/storage/update';
$stateFile = $stateDir . '/build.json';
$backup = null;

if (is_file($stateFile)) {
    $backup = file_get_contents($stateFile);
}

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}

require_once $root . '/src/Support/UpdateManager.php';

$base = trim((string) file_get_contents($root . '/version'));
$betaRef = '1234567890abcdef1234567890abcdef12345678';

file_put_contents($stateFile, json_encode([
    'channel' => 'beta',
    'ref' => $betaRef,
    'label' => 'main@1234567',
    'base_version' => $base,
    'installed_at' => gmdate(DATE_ATOM),
], JSON_PRETTY_PRINT));

$betaVersion = bluebotUpdateCurrentVersion();
$expectedBeta = $base . '-beta+1234567';
if ($betaVersion !== $expectedBeta) {
    fwrite(STDERR, "Expected {$expectedBeta}, got {$betaVersion}\n");
    exit(1);
}

file_put_contents($stateFile, json_encode([
    'channel' => 'release',
    'ref' => 'v0.6.0',
    'label' => 'v0.6.0',
    'base_version' => $base,
    'installed_at' => gmdate(DATE_ATOM),
], JSON_PRETTY_PRINT));

$releaseVersion = bluebotUpdateCurrentVersion();
if ($releaseVersion !== '0.6.0') {
    fwrite(STDERR, "Expected stable tag 0.6.0, got {$releaseVersion}\n");
    exit(1);
}

if ($backup !== null) {
    file_put_contents($stateFile, $backup);
} else {
    @unlink($stateFile);
    @rmdir($stateDir);
}

echo "Installed build version tests OK.\n";
