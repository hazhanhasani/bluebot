<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function zarinpalContractContains(string $source, string $needle, string $message, array &$failures): void
{
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

$helper = (string) @file_get_contents($root . '/function.php');
zarinpalContractContains(
    $helper,
    "'https://api.zarinpal.com/pg/v4/payment/request.json'",
    'ZarinPal request endpoint must use the official API host.',
    $failures
);
zarinpalContractContains($helper, "'currency' => 'IRT'", 'ZarinPal checkout must declare IRT currency.', $failures);
zarinpalContractContains(
    $helper,
    <<<'PHP'
'callback_url' => 'https://' . $domain . '/payment/zarinpal.php'
PHP,
    'ZarinPal callback URL is missing.',
    $failures
);
zarinpalContractContains($helper, "CURLOPT_PROTOCOLS => CURLPROTO_HTTPS", 'ZarinPal client must restrict transport to HTTPS.', $failures);
zarinpalContractContains($helper, "CURLOPT_SSL_VERIFYPEER => true", 'ZarinPal TLS peer verification must remain enabled.', $failures);
zarinpalContractContains($helper, "'gateway_message'", 'ZarinPal request failures must retain gateway diagnostics.', $failures);

$callback = (string) @file_get_contents($root . '/payment/zarinpal.php');
zarinpalContractContains(
    $callback,
    "'https://api.zarinpal.com/pg/v4/payment/verify.json'",
    'ZarinPal verify endpoint must use the official API host.',
    $failures
);
zarinpalContractContains($callback, 'in_array($verifyCode, [100, 101], true)', 'ZarinPal verification must accept successful status codes 100 and 101.', $failures);
if (str_contains($callback, '"description" => $Payment_reports')) {
    $failures[] = 'ZarinPal verification payload must not include request-only description data.';
}

$index = (string) @file_get_contents($root . '/index.php');
zarinpalContractContains($index, '$zarinpalAuthority', 'ZarinPal checkout must validate returned authority.', $failures);
zarinpalContractContains($index, 'Unknown ZarinPal error', 'ZarinPal checkout must normalize gateway errors.', $failures);
zarinpalContractContains($index, "'-14' => 'دامنه Callback", 'ZarinPal checkout must explain callback-domain mismatch.', $failures);
zarinpalContractContains($index, "errorLinkPaymentDetails", 'ZarinPal checkout must show a safe gateway error code and reason.', $failures);

if ($failures !== []) {
    fwrite(STDERR, "ZarinPal payment contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "ZarinPal payment contract OK.\n";
