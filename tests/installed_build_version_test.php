<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$stateDir = $root . '/storage/update';
$stateFile = $stateDir . '/build.json';
$versionFile = $root . '/version';

$stateBackup = is_file($stateFile) ? file_get_contents($stateFile) : null;
$versionBackup = is_file($versionFile) ? file_get_contents($versionFile) : null;

if (!is_dir($stateDir)) {
    mkdir($stateDir, 0775, true);
}

require_once $root . '/src/Support/UpdateManager.php';

$base = trim((string) $versionBackup);
$base = preg_replace('/-beta\+[0-9a-f]{7,40}$/i', '', $base) ?? $base;
$betaRef = '1234567890abcdef1234567890abcdef12345678';

try {
    $beta = bluebotWriteInstalledBuildState('beta', $betaRef);
    $expectedBeta = $base . '-beta+1234567';

    if (($beta['display_version'] ?? '') !== $expectedBeta) {
        throw new RuntimeException('Beta state display version was not persisted.');
    }

    if (bluebotUpdateCurrentVersion() !== $expectedBeta) {
        throw new RuntimeException('Build-aware version resolver did not return the Beta build.');
    }

    if (trim((string) file_get_contents($versionFile)) !== $expectedBeta) {
        throw new RuntimeException('Runtime version file did not sync to the Beta build.');
    }

    $release = bluebotWriteInstalledBuildState('release', 'v0.6.0');
    if (($release['display_version'] ?? '') !== '0.6.0') {
        throw new RuntimeException('Stable release tag was not normalized.');
    }

    if (bluebotUpdateCurrentVersion() !== '0.6.0') {
        throw new RuntimeException('Build-aware version resolver did not return the Stable tag.');
    }

    if (trim((string) file_get_contents($versionFile)) !== '0.6.0') {
        throw new RuntimeException('Runtime version file did not sync to the Stable tag.');
    }
} finally {
    if ($versionBackup !== null) {
        file_put_contents($versionFile, $versionBackup);
    }

    if ($stateBackup !== null) {
        file_put_contents($stateFile, $stateBackup);
    } else {
        @unlink($stateFile);
        @rmdir($stateDir);
    }
}

echo "Installed build version tests OK.\n";
