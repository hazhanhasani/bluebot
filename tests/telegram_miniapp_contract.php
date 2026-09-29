<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$appIndex = @file_get_contents($root . '/app/index.php');
if ($appIndex !== false && str_contains($appIndex, 'https://telegram.org/js/telegram-web-app.js')) {
    $failures[] = 'Mini App startup must not block on the external Telegram SDK.';
}
if ($appIndex === false || !str_contains($appIndex, './js/telegram-bootstrap.js?v=0.1.7')) {
    $failures[] = 'Mini App compatibility bootstrap is not loaded with the current cache key.';
}
if ($appIndex === false || !str_contains($appIndex, './js/telegram-web-app.js?v=0.1.7')) {
    $failures[] = 'Local Telegram SDK is not loaded first.';
}

if ($appIndex === false || !str_contains($appIndex, 'script defer src="./js/telegram-web-app.js?v=0.1.7"')) {
    $failures[] = 'Mini App Telegram SDK must load with defer for non-blocking first paint.';
}
if ($appIndex === false || !str_contains($appIndex, 'bluebot-boot')) {
    $failures[] = 'Mini App must provide an immediate boot/loading surface.';
}

if ($appIndex === false || !str_contains($appIndex, './js/app-loader.js?v=0.1.7')) {
    $failures[] = 'Mini App ordered application loader is missing.';
}

$appLoader = @file_get_contents($root . '/app/js/app-loader.js');
if ($appLoader === false || !str_contains($appLoader, "import('../assets/index-C-2a0Dur.js')")) {
    $failures[] = 'Mini App loader does not start the canonical production bundle URL.';
}
if ($appLoader !== false && str_contains($appLoader, "index-C-2a0Dur.js?v=")) {
    $failures[] = 'Mini App entry module must not use a query string; route chunks import the canonical URL and a second module identity can mount Router twice.';
}

if ($appLoader === false || !str_contains($appLoader, '__BLUEBOT_MINIAPP_BOOT__')) {
    $failures[] = 'Mini App loader must reject duplicate bootstraps before React Router mounts twice.';
}
if ($appIndex !== false && preg_match('/<script[^>]+src="\.\/assets\/index-C-2a0Dur\.js[^"]*"/i', $appIndex) === 1) {
    $failures[] = 'Mini App bundle must be started only through the guarded application loader.';
}

if ($appLoader === false || !str_contains($appLoader, "Startup timeout")) {
    $failures[] = 'Mini App loader must surface startup failures instead of leaving a black screen.';
}
if ($appLoader === false || !str_contains($appLoader, "پنل کاربری کامل بارگذاری نشد")) {
    $failures[] = 'Mini App startup error UI is missing.';
}

$mainBundle = @file_get_contents($root . '/app/assets/index-C-2a0Dur.js');
if ($mainBundle === false || !str_contains($mainBundle, 'BBs=(()=>')) {
    $failures[] = 'Mini App safe WebView storage wrapper is missing.';
}
if ($mainBundle === false || !str_contains($mainBundle, 'window.localStorage')) {
    $failures[] = 'Mini App safe storage wrapper does not probe native localStorage.';
}

$appHtaccess = @file_get_contents($root . '/app/.htaccess');
if ($appHtaccess === false || !str_contains($appHtaccess, 'Cloudflare-CDN-Cache-Control')) {
    $failures[] = 'Mini App static/CDN cache policy is missing.';
}

if ($appHtaccess === false || !str_contains($appHtaccess, 'app-loader|telegram-bootstrap|telegram-web-app')) {
    $failures[] = 'Mini App runtime bootstrap scripts need an explicit non-immutable cache policy.';
}
if ($appHtaccess === false || !str_contains($appHtaccess, 'JS/CSS filenames in this legacy Mini App build')) {
    $failures[] = 'Mini App JS/CSS must be revalidated to prevent mixed cached bundle generations.';
}

$apiHtaccess = @file_get_contents($root . '/api/.htaccess');
if ($apiHtaccess === false || !str_contains($apiHtaccess, 'CDN-Cache-Control "no-store"')) {
    $failures[] = 'Authenticated API responses are not explicitly protected from CDN caching.';
}

$miniAppSupport = @file_get_contents($root . '/src/Support/MiniApp.php');
$keyboard = @file_get_contents($root . '/keyboard.php');
$indexSource = @file_get_contents($root . '/index.php');

if ($keyboard !== false && (
    str_contains($keyboard, '$miniAppButton')
    || str_contains($keyboard, "'web_app' => ['url' =>")
)) {
    $failures[] = 'Main reply/inline keyboard must not inject the Mini App user-panel button.';
}
if ($keyboard !== false && str_contains($keyboard, '$miniAppHost = trim((string) ($domainhosts')) {
    $failures[] = 'Main keyboard must not rebuild Mini App URL from stale config domain.';
}
if ($miniAppSupport === false || !str_contains($miniAppSupport, '$host = bluebotPublicDomain();')) {
    $failures[] = 'Mini App URL must resolve through the current runtime domain.';
}
if ($miniAppSupport === false || !str_contains($miniAppSupport, "bluebotMiniAppBuildVersion")) {
    $failures[] = 'Mini App URL must include the build version so Telegram opens a fresh WebView after updates.';
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
if ($version !== '0.1.7') {
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
