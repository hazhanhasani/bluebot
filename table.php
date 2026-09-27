<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db/bootstrap.php';

$webhookState = ensureWebhookSecret();
$webhookSecret = trim((string) ($webhookState['secret'] ?? ''));

if ($webhookSecret === '' || !bluebotSetMainWebhook($webhookSecret)) {
    error_log('BlueBot webhook setup failed after database bootstrap.');

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        exit('webhook setup failed');
    }

    fwrite(STDERR, "Webhook setup failed.\n");
    exit(1);
}
