<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$appIndex = @file_get_contents($root . '/app/index.php');
if ($appIndex === false || !str_contains($appIndex, 'https://telegram.org/js/telegram-web-app.js?63')) {
    $failures[] = 'Mini App does not load the current official Telegram WebApp SDK.';
}
if ($appIndex === false || !str_contains($appIndex, './js/telegram-bootstrap.js?v=0.1.2')) {
    $failures[] = 'Mini App compatibility bootstrap is not loaded.';
}
if ($appIndex === false || !str_contains($appIndex, './js/telegram-web-app.js')) {
    $failures[] = 'Local Telegram SDK fallback is missing.';
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
if ($version !== '0.1.2') {
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
