<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string) file_get_contents($root . '/version'));
$manifest = json_decode(
    (string) file_get_contents($root . '/android-client/update.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$gradle = (string) file_get_contents($root . '/android-client/app/build.gradle.kts');
$client = (string) file_get_contents($root . '/api/client.php');

if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    throw new RuntimeException('Stable version is not valid semver.');
}

if (($manifest['latest_version_name'] ?? null) !== $version) {
    throw new RuntimeException('Android update manifest version name does not match version file.');
}

$versionCode = $manifest['latest_version_code'] ?? null;
if (!is_int($versionCode) || $versionCode < 1) {
    throw new RuntimeException('Android latest_version_code must be a positive integer.');
}

$expectedUrl = sprintf(
    'https://github.com/hazhanhasani/bluebot/releases/download/v%s/blue-vpn-android-v%s.apk',
    $version,
    $version
);
if (($manifest['download_url'] ?? null) !== $expectedUrl) {
    throw new RuntimeException('Android update download URL does not match the stable release version.');
}

foreach ([
    'versionCode = androidVersionCode',
    'versionName = stableVersion',
    '.resolve("version")',
    'rootProject.file("update.json")',
] as $needle) {
    if (!str_contains($gradle, $needle)) {
        throw new RuntimeException('Android Gradle version source contract is missing: ' . $needle);
    }
}

if (!str_contains($client, "'latest_version_name' => \$releaseVersion")) {
    throw new RuntimeException('Android API must publish the stable version file as latest_version_name.');
}

if (str_contains($client, "\$manifest['latest_version_name']")) {
    throw new RuntimeException('Android API must not trust a stale manifest version name.');
}

if (!str_contains(
    $client,
    'releases/download/{$tag}/blue-vpn-android-{$tag}.apk'
)) {
    throw new RuntimeException('Android API must derive the APK URL from the stable release tag.');
}

echo "Android release version contract OK.\n";
