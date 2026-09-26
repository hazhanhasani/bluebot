<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function contractContains(string $source, string $needle, string $message, array &$failures): void
{
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

$helper = (string) @file_get_contents($root . '/src/Payment/Blupal.php');
contractContains($helper, "'https://blupal.top/api'", 'Blupal production API base URL is missing.', $failures);
contractContains($helper, "'X-API-Key: ' . $apiKey", 'Blupal authentication header is missing.', $failures);
contractContains($helper, "'/v1/invoices/create'", 'Blupal invoice creation endpoint is missing.', $failures);
contractContains($helper, "'/v1/invoices/' . rawurlencode($invoiceId)", 'Blupal invoice verification endpoint is missing.', $failures);
contractContains($helper, "$amountToman * 10", 'Blupal Toman-to-Rial conversion is missing.', $failures);
contractContains($helper, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS', 'Blupal client must restrict transport to HTTPS.', $failures);
contractContains($helper, 'CURLOPT_SSL_VERIFYPEER => true', 'Blupal TLS peer verification must remain enabled.', $failures);
contractContains($helper, "if ($status !== 'PAID')", 'Blupal settlement must require remote PAID status.', $failures);
contractContains($helper, 'expectedRial', 'Blupal settlement must compare the remote amount.', $failures);
contractContains($helper, 'claimPaymentPaid($orderId)', 'Blupal settlement must use idempotent payment claiming.', $failures);
contractContains($helper, 'markPaymentDeliveryError($orderId', 'Blupal delivery failures must be recorded.', $failures);

$webhook = (string) @file_get_contents($root . '/payment/blupal_webhook.php');
contractContains($webhook, "$_SERVER['REQUEST_METHOD'] !== 'POST'", 'Blupal webhook must be POST-only.', $failures);
contractContains($webhook, "payment.completed", 'Blupal webhook payment.completed event support is missing.', $failures);
contractContains($webhook, "bluebotBlupalSettle($invoiceId)", 'Blupal webhook must re-verify payment through the authenticated API.', $failures);
contractContains($webhook, 'payload_too_large', 'Blupal webhook payload size guard is missing.', $failures);

$index = (string) @file_get_contents($root . '/index.php');
contractContains($index, '$datain == "blupal"', 'Blupal buyer checkout branch is missing.', $failures);
contractContains($index, "'blupal', $invoice", 'Blupal payment report method is missing.', $failures);
contractContains($index, '"dec_not_confirmed", $remoteInvoiceId', 'Blupal remote invoice ID is not persisted.', $failures);
contractContains($index, 'blupalcheck_', 'Blupal manual payment verification action is missing.', $failures);
contractContains($index, 'bluebotBlupalSettle($remoteInvoiceId)', 'Blupal manual verification must use authenticated remote verification.', $failures);

$keyboard = (string) @file_get_contents($root . '/keyboard.php');
contractContains($keyboard, "'blupal' => ['label' => $textbotlang['keyboard']['blupalGateway']", 'Blupal is missing from payment gateway administration.', $failures);
contractContains($keyboard, 'bluebotBlupalConfigured()', 'Blupal buyer button must remain hidden until credentials are configured.', $failures);

$settings = (string) @file_get_contents($root . '/db/tables/PaySetting.php');
foreach ([
    "'blupal_status' => 'offblupal'",
    "'blupal_api_key' => '0'",
    "'minbalanceblupal' => '10000'",
    "'maxbalanceblupal' => '1000000'",
    "'chashbackblupal' => '0'",
] as $needle) {
    contractContains($settings, $needle, "Missing Blupal setting default: {$needle}", $failures);
}

foreach (['fa', 'en', 'ru', 'zh'] as $language) {
    $source = (string) @file_get_contents($root . "/lang/{$language}.php");
    contractContains($source, "'blupalGateway' =>", "Blupal gateway label is missing from {$language}.", $failures);
    contractContains($source, "'checkBlupalPayment' =>", "Blupal payment-check label is missing from {$language}.", $failures);
}

if ($failures !== []) {
    fwrite(STDERR, "Blupal payment contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Blupal payment contract OK.\n";
