<?php

$root = dirname(__DIR__);

$manager = file_get_contents($root . '/src/Services/DigitalServiceManager.php');
$index = file_get_contents($root . '/index.php');
$admin = file_get_contents($root . '/admin.php');
$keyboard = file_get_contents($root . '/keyboard.php');
$setting = file_get_contents($root . '/db/tables/setting.php');
$panel = file_get_contents($root . '/panel/digital_services.php');

$checks = [
    [str_contains($manager, "pending_approval"), 'orders must enter pending approval'],
    [str_contains($manager, "approveAndDeliver"), 'manual approval delivery action missing'],
    [str_contains($manager, "rejectAndRefund"), 'reject/refund action missing'],
    [str_contains($manager, "UPDATE user SET Balance = Balance + ?"), 'refund must credit wallet'],
    [str_contains($manager, "giftPremiumSubscription"), 'Telegram Premium provider missing'],
    [str_contains($manager, "https://api.ozvinoo.xyz"), 'OZVinoo host allowlist missing'],
    [str_contains($manager, "CURLOPT_PROTOCOLS => CURLPROTO_HTTPS"), 'OZVinoo must be HTTPS-only'],
    [str_contains($index, "ds_confirm:"), 'user order confirmation route missing'],
    [str_contains($index, "notifyAdmins"), 'admin notification missing'],
    [str_contains($admin, "ds_approve:"), 'bot approval callback missing'],
    [str_contains($admin, "ds_reject:"), 'bot reject callback missing'],
    [str_contains($keyboard, "text_digital_services"), 'main keyboard mapping missing'],
    [str_contains($setting, "text_digital_services"), 'default keyboard token missing'],
    [str_contains($panel, "approve_order"), 'web approval action missing'],
    [str_contains($panel, "reject_order"), 'web reject action missing'],
];

foreach ($checks as [$ok, $message]) {
    if (!$ok) {
        fwrite(STDERR, "Digital services contract failed: {$message}\n");
        exit(1);
    }
}

echo "Digital services contract OK.\n";
