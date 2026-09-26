<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Support/TrustedProxy.php';

$APIKEY = '123456789:test_token_for_runtime_identity_contract';
$domainhosts = 'old.example.com';
$usernamebot = 'OldExampleBot';

require_once dirname(__DIR__) . '/src/Support/RuntimeIdentity.php';

$failures = [];

$assertSame = static function ($expected, $actual, string $message) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true);
    }
};

$assertSame('new.example.com', bluebotNormalizePublicHost('https://New.Example.com/app/'), 'URL host normalization failed.');
$assertSame('new.example.com', bluebotNormalizePublicHost('new.example.com:443'), 'Host:port normalization failed.');
$assertSame('', bluebotNormalizePublicHost('localhost'), 'localhost must not be accepted as a public domain.');
$assertSame('', bluebotNormalizePublicHost('127.0.0.1'), 'IP addresses must not be accepted as Mini App domains.');

$assertSame(
    'new.example.com',
    bluebotRuntimeHostFromRequest([
        'HTTPS' => 'on',
        'HTTP_HOST' => 'new.example.com',
        'SERVER_PORT' => '443',
    ]),
    'HTTPS request host was not detected.'
);

$assertSame(
    '',
    bluebotRuntimeHostFromRequest([
        'HTTPS' => 'off',
        'HTTP_HOST' => 'spoofed.example.com',
        'SERVER_PORT' => '80',
    ]),
    'Plain HTTP host must not become the public bot domain.'
);

$firstBotKey = bluebotRuntimeBotKey();
$APIKEY = '987654321:different_runtime_identity_token';
$secondBotKey = bluebotRuntimeBotKey();
if ($firstBotKey === '' || $secondBotKey === '' || hash_equals($firstBotKey, $secondBotKey)) {
    $failures[] = 'Runtime identity cache key must change with the bot token.';
}

$APIKEY = '123456789:test_token_for_runtime_identity_contract';
$_SERVER = [
    'HTTPS' => 'on',
    'HTTP_HOST' => 'active.example.com',
    'SERVER_PORT' => '443',
];
$assertSame('active.example.com', bluebotPublicDomain(), 'Current authenticated HTTPS host must win over stale configured domain.');

if ($failures !== []) {
    fwrite(STDERR, "Runtime identity tests failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Runtime identity tests OK.\n";
