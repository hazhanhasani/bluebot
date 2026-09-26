<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$appIndex = @file_get_contents($root . '/app/index.php');
if ($appIndex !== false && str_contains($appIndex, 'https://telegram.org/js/telegram-web-app.js')) {
    $failures[] = 'Mini App startup must not block on the external Telegram SDK.';
}
if ($appIndex === false || !str_contains($appIndex, './js/telegram-bootstrap.js?v=0.1.4')) {
    $failures[] = 'Mini App compatibility bootstrap is not loaded with the current cache key.';
}
if ($appIndex === false || !str_contains($appIndex, './js/telegram-web-app.js?v=0.1.4')) {
    $failures[] = 'Local Telegram SDK is not loaded first.';
}

if ($appIndex === false || !str_contains($appIndex, 'script defer src="./js/telegram-web-app.js?v=0.1.4"')) {
    $failures[] = 'Mini App Telegram SDK must load with defer for non-blocking first paint.';
}
if ($appIndex === false || !str_contains($appIndex, 'bluebot-boot')) {
    $failures[] = 'Mini App must provide an immediate boot/loading surface.';
}

if ($appIndex === false || !str_contains($appIndex, './js/app-loader.js?v=0.1.4')) {
    $failures[] = 'Mini App ordered application loader is missing.';
}

$appLoader = @file_get_contents($root . '/app/js/app-loader.js');
if ($appLoader === false || !str_contains($appLoader, "import('../assets/index-C-2a0Dur.js?v=0.1.4')")) {
    $failures[] = 'Mini App loader does not start the production bundle.';
}

$appHtaccess = @file_get_contents($root . '/app/.htaccess');
if ($appHtaccess === false || !str_contains($appHtaccess, 'Cloudflare-CDN-Cache-Control')) {
    $failures[] = 'Mini App static/CDN cache policy is missing.';
}

$apiHtaccess = @file_get_contents($root . '/api/.htaccess');
if ($apiHtaccess === false || !str_contains($apiHtaccess, 'CDN-Cache-Control "no-store"')) {
    $failures[] = 'Authenticated API responses are not explicitly protected from CDN caching.';
}

$miniAppSupport = @file_get_contents($root . '/src/Support/MiniApp.php');
$keyboard = @file_get_contents($root . '/keyboard.php');
$indexSource = @file_get_contents($root . '/index.php');

if ($keyboard === false || !str_contains($keyboard, '$miniAppUrl = bluebotMiniAppUrl();')) {
    $failures[] = 'Main keyboard Mini App button must use the active runtime Mini App URL.';
}
if ($keyboard !== false && str_contains($keyboard, '$miniAppHost = trim((string) ($domainhosts')) {
    $failures[] = 'Main keyboard must not rebuild Mini App URL from stale config domain.';
}
if ($miniAppSupport === false || !str_contains($miniAppSupport, '$host = bluebotPublicDomain();')) {
    $failures[] = 'Mini App URL must resolve through the current runtime domain.';
}
if ($miniAppSupport !== false) {
    $runtimePos = strpos($miniAppSupport, '$host = bluebotPublicDomain();');
    $legacyOverridePos = strpos($miniAppSupport, "getenv('BLUEBOT_MINIAPP_URL')");
    if ($runtimePos === false || $legacyOverridePos === false || $runtimePos > $legacyOverridePos) {
        $failures[] = 'Current bot domain must take priority over a legacy Worker/CDN Mini App override.';
    }
}
if ($indexSource === false || !str_contains($indexSource, 'bluebotAdoptRuntimeIdentity(true, true);')) {
    $failures[] = 'Authenticated webhook flow must persist the current bot/domain identity.';
}

$miniApi = @file_get_contents($root . '/api/miniapp.php');
if ($miniApi === false || !str_contains($miniApi, "/src/Support/JalaliDate.php")) {
    $failures[] = 'Mini App API does not load JalaliDate from the organized path.';
}
if ($miniApi !== false && str_contains($miniApi, "/../jdf.php")) {
    $failures[] = 'Mini App API still references removed root jdf.php.';
}

$verify = @file_get_contents($root . '/api/verify.php');
if ($verify === false || !str_contains($verify, "array_key_exists('signature', \$initData)")) {
    $failures[] = 'Telegram verifier does not handle signature-era initData.';
}
if ($verify === false || !str_contains($verify, "unset(\$withoutSignature['signature'])")) {
    $failures[] = 'Telegram verifier compatibility path does not exclude signature.';
}
if ($verify === false || !str_contains($verify, "hash_equals")) {
    $failures[] = 'Telegram verifier must use timing-safe hash comparison.';
}

$version = trim((string) @file_get_contents($root . '/app/version'));
if ($version !== '0.1.4') {
    $failures[] = 'Unexpected Mini App version: ' . $version;
}

if ($failures !== []) {
    fwrite(STDERR, "Telegram Mini App contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Telegram Mini App contract OK.\n";
