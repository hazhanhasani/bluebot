<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Services/AppClientPanelQrResolver.php';

$ref = new ReflectionClass(AppClientPanelQrResolver::class);

$call = static function (string $method, array $args) use ($ref) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};

$token = 'djMsMjg3MTkzLDE3OTEwMDQzMzg.tiVUdcOLnR8NPfkbm-Vw1P_7EJr93h7WMQ8V46v9_ZQ';
$fullUrl = 'https://zerofee.ok-exor.ir/sub/' . $token;
$relativeUrl = '/sub/' . $token;

$identities = $call('identities', [$fullUrl]);

foreach ([$token, 'v3', '287193', '1791004338'] as $expected) {
    if (!in_array(strtolower($expected), $identities, true)) {
        fwrite(STDERR, "Missing QR identity: {$expected}\n");
        exit(1);
    }
}

$rowMatches = $call('rowMatchesIdentity', [[
    'username' => 'manual-panel-user',
    'subscription_url' => $relativeUrl,
    'id' => 287193,
], $identities]);

if ($rowMatches !== true) {
    fwrite(STDERR, "Panel catalog row did not match signed subscription token.\n");
    exit(2);
}

$signatures = $call('signatures', [$fullUrl]);
$runtimeMatches = $call('runtimeMatches', [[
    'subscription_url' => $relativeUrl,
    'links' => [],
], $signatures, $identities]);

if ($runtimeMatches !== true) {
    fwrite(STDERR, "Panel runtime did not match signed subscription token.\n");
    exit(3);
}

echo "Panel QR resolver logic OK.\n";
