<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require_once __DIR__ . '/support/source_function.php';

loadTestSourceFunction($root . '/function.php', 'sanitize_recursive');
loadTestSourceFunction($root . '/api/miniapp.php', 'mini_request_data');
require_once $root . '/src/Services/DigitalServiceManager.php';

$failures = [];
$product = ['type' => 'instagram_followers', 'provider' => 'manual'];
$boundaryPrefix = 'https://example.com/post?ref=a&caption=';
$targets = [
    'URL query parameters' => 'https://example.com/post?id=123&ref=abc',
    'HTML-sensitive characters' => 'https://example.com/post?caption="a<b>"&ref=O\'Brien',
    'Existing literal entity' => 'https://example.com/post?ref=a&amp;next=b',
    '255-character target' => $boundaryPrefix . str_repeat('a', 255 - strlen($boundaryPrefix)),
];

foreach ($targets as $name => $target) {
    $quoteInput = mini_request_data(['actions' => 'digital_quote', 'target' => $target]);
    [$quoteOk, $quotedTarget] = BluebotDigitalServices::validateTarget($product, $quoteInput['target']);
    if (!$quoteOk || $quotedTarget !== $target) {
        $failures[] = $name . ': quote must preserve the validated destination.';
        continue;
    }

    // The storefront sends the API's quoted target back to digital_purchase.
    $purchaseInput = mini_request_data(['actions' => 'digital_purchase', 'target' => $quotedTarget]);
    [$purchaseOk, $purchaseTarget] = BluebotDigitalServices::validateTarget($product, $purchaseInput['target']);
    if (!$purchaseOk || $purchaseTarget !== $quotedTarget) {
        $failures[] = $name . ': purchase destination must match the quoted order state.';
    }
}

$legacy = mini_request_data(['actions' => 'purchase', 'custom_note' => '<b>note</b>']);
if (($legacy['custom_note'] ?? '') !== '&lt;b&gt;note&lt;/b&gt;') {
    $failures[] = 'Legacy VPN request HTML sanitization must remain unchanged.';
}

[$validTooLong] = BluebotDigitalServices::validateTarget($product, str_repeat('a', 256));
[$validEmpty] = BluebotDigitalServices::validateTarget($product, '');
[$validTelegram] = BluebotDigitalServices::validateTarget(
    ['type' => 'telegram_stars', 'provider' => 'tgtools'],
    '<invalid>'
);
if ($validTooLong || $validEmpty || $validTelegram) {
    $failures[] = 'Digital destination validation must continue to reject invalid input.';
}

if ($failures !== []) {
    fwrite(STDERR, "Mini App target tests failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Mini App target round-trip tests OK.\n";
