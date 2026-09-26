<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/TrustedProxy.php';

$failures = [];

$cases = [
    [
        'name' => 'direct Telegram IPv4 is allowed',
        'server' => ['REMOTE_ADDR' => '149.154.167.50'],
        'expected' => true,
    ],
    [
        'name' => 'direct non-Telegram IP is denied',
        'server' => ['REMOTE_ADDR' => '203.0.113.10'],
        'expected' => false,
    ],
    [
        'name' => 'spoofed CF header from direct origin is ignored',
        'server' => [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_CF_CONNECTING_IP' => '149.154.167.50',
        ],
        'expected' => false,
    ],
    [
        'name' => 'Telegram through trusted Cloudflare IPv4 is allowed',
        'server' => [
            'REMOTE_ADDR' => '104.16.12.25',
            'HTTP_CF_CONNECTING_IP' => '91.108.6.20',
        ],
        'expected' => true,
    ],
    [
        'name' => 'non-Telegram visitor through Cloudflare is denied',
        'server' => [
            'REMOTE_ADDR' => '172.67.10.10',
            'HTTP_CF_CONNECTING_IP' => '8.8.8.8',
        ],
        'expected' => false,
    ],
    [
        'name' => 'Telegram IPv6 through Cloudflare IPv6 is allowed',
        'server' => [
            'REMOTE_ADDR' => '2606:4700::1234',
            'HTTP_CF_CONNECTING_IP' => '2001:67c:4e8:12::1',
        ],
        'expected' => true,
    ],
    [
        'name' => 'invalid forwarded address is denied',
        'server' => [
            'REMOTE_ADDR' => '104.16.1.1',
            'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
        ],
        'expected' => false,
    ],
];

foreach ($cases as $case) {
    $actual = bluebotTelegramWebhookIpAllowed($case['server']);
    if ($actual !== $case['expected']) {
        $failures[] = $case['name'] . ': expected '
            . var_export($case['expected'], true)
            . ', got ' . var_export($actual, true);
    }
}

if (!bluebotIpInCidr('198.41.200.1', '198.41.128.0/17')) {
    $failures[] = 'Cloudflare IPv4 CIDR matcher failed.';
}

if (!bluebotIpInCidr('2a06:98c0::1', '2a06:98c0::/29')) {
    $failures[] = 'Cloudflare IPv6 CIDR matcher failed.';
}

if ($failures !== []) {
    fwrite(STDERR, "Trusted proxy tests failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Trusted proxy webhook tests OK.\n";
