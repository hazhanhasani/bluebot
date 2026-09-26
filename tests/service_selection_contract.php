<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) @file_get_contents($root . '/index.php');
$failures = [];

$checks = [
    "preg_match('/^product_([A-Za-z0-9-]{1,200})$/D'" => 'Product callback must use strict complete matching.',
    "'callback_query_id' => \$callback_query_id" => 'Service selection must acknowledge Telegram callback queries immediately.',
    "serviceLoadingShort" => 'Service selection must expose an immediate loading state.',
    "serviceBackKeyboard" => 'Service selection must keep a back action available during slow panel requests.',
    "panelNotConnectedCached" => 'Service selection must show cached order details when the panel is temporarily unavailable.',
    "bluebotEnsureMiniAppMenuButton(false);" => 'Bot /start must automatically synchronize the Mini App menu button.',
];

foreach ($checks as $needle => $message) {
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

$loadingPos = strpos($source, "serviceLoading']");
$panelNeedle = "\$DataUserOut = \$ManagePanel->DataUser(\$nameloc['Service_location'], \$nameloc['username']);";
$panelPos = strpos($source, $panelNeedle);
if ($loadingPos === false || $panelPos === false || $loadingPos > $panelPos) {
    $failures[] = 'Loading feedback must be sent before the potentially slow panel lookup.';
}

if ($failures !== []) {
    fwrite(STDERR, "Service selection contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Service selection contract OK.\n";
