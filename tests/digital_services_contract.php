<?php

$root = dirname(__DIR__);

$manager = file_get_contents($root . '/src/Services/DigitalServiceManager.php');
$index = file_get_contents($root . '/index.php');
$admin = file_get_contents($root . '/admin.php');
$keyboard = file_get_contents($root . '/keyboard.php');
$setting = file_get_contents($root . '/db/tables/setting.php');
$panel = file_get_contents($root . '/panel/digital_services.php');
$tgTools = file_get_contents($root . '/src/Services/TgToolsClient.php');
$cronJobs = file_get_contents($root . '/cronbot/jobs.php');
$digitalServicesCron = file_get_contents($root . '/cronbot/digital_services.php');
$settings = file_get_contents($root . '/db/tables/digital_service_settings.php');

$checks = [
    [str_contains($manager, "pending_approval"), 'orders must enter pending approval'],
    [str_contains($manager, "approveAndDeliver"), 'manual approval delivery action missing'],
    [str_contains($manager, "rejectAndRefund"), 'reject/refund action missing'],
    [str_contains($manager, "UPDATE user SET Balance = Balance + ?"), 'refund must credit wallet'],
    [str_contains($manager, "giftPremiumSubscription"), 'Telegram Premium provider missing'],
    [str_contains($manager, "provider === 'tgtools'"), 'TGTools provider dispatch missing'],
    [str_contains($manager, "generatedProviderProductCode"), 'automatic provider product code generation missing'],
    [str_contains($manager, "ensureTgToolsCatalog"), 'automatic Stars/Premium catalog generation missing'],
    [str_contains($manager, "maybeSyncTgToolsCatalog"), 'periodic TGTools catalog refresh missing'],
    [str_contains($manager, "provider_service_code = NULL"), 'TGTools products must not require provider service codes'],
    [str_contains($manager, "reconcileTgToolsProcessing"), 'TGTools async reconciliation missing'],
    [str_contains($manager, "failAndRefundProviderOrder"), 'TGTools provider failure refund missing'],
    [str_contains($tgTools, "https://api.tg-tools.shop"), 'TGTools host allowlist missing'],
    [str_contains($tgTools, "/api/purchase/stars"), 'TGTools Stars endpoint missing'],
    [str_contains($tgTools, "/api/purchase/premium"), 'TGTools Premium endpoint missing'],
    [str_contains($tgTools, "/api/purchase/prices"), 'TGTools live prices endpoint missing'],
    [str_contains($tgTools, "X-Api-Key"), 'TGTools API key authentication missing'],
    [str_contains($tgTools, "trackingCode"), 'TGTools idempotency tracking code missing'],
    [str_contains($cronJobs, "'job' => 'digital_services'"), 'TGTools reconciliation cron is not scheduled'],
    [str_contains($digitalServicesCron, "maybeSyncTgToolsCatalog"), 'TGTools live catalog cron refresh missing'],
    [str_contains($settings, "tgtools_api_key"), 'TGTools API key setting missing'],
    [str_contains($settings, "tgtools_catalog_last_sync"), 'TGTools catalog sync timestamp setting missing'],
    [str_contains($panel, "save_tgtools"), 'TGTools admin settings UI missing'],
    [str_contains($panel, "sync_tgtools_catalog"), 'TGTools catalog sync action missing'],
    [str_contains($panel, "set_product_price"), 'generated product retail price action missing'],
    [str_contains($panel, 'value="tgtools"'), 'TGTools product provider option missing'],
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
