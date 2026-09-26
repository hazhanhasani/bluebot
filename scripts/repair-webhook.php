<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/botapi.php';
require_once $root . '/function.php';

try {
    $state = ensureWebhookSecret();
    $secret = trim((string) ($state['secret'] ?? ''));

    if ($secret === '') {
        fwrite(STDERR, "Webhook secret is empty.\n");
        exit(2);
    }

    if (!bluebotSetMainWebhook($secret)) {
        fwrite(STDERR, "Telegram rejected webhook refresh.\n");
        exit(3);
    }

    echo "Main Telegram webhook refreshed successfully.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Webhook repair failed: " . $error->getMessage() . "\n");
    exit(4);
}
