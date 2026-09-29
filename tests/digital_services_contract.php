<?php

$root = dirname(__DIR__);

$manager = file_get_contents($root . '/src/Services/DigitalServiceManager.php');
$index = file_get_contents($root . '/index.php');
$admin = file_get_contents($root . '/admin.php');
$keyboard = file_get_contents($root . '/keyboard.php');
$setting = file_get_contents($root . '/db/tables/setting.php');
$panel = file_get_contents($root . '/panel/digital_services.php');
$tgTools = file_get_contents($root . '/src/Services/TgToolsClient.php');
$ozvinooClient = file_get_contents($root . '/src/Services/OZVinooClient.php');
$providerCatalog = file_get_contents($root . '/src/Services/DigitalServiceProviderCatalog.php');
$providerTable = file_get_contents($root . '/db/tables/digital_service_providers.php');
$cronJobs = file_get_contents($root . '/cronbot/jobs.php');
$digitalServicesCron = file_get_contents($root . '/cronbot/digital_services.php');
$settings = file_get_contents($root . '/db/tables/digital_service_settings.php');
$langFa = file_get_contents($root . '/lang/fa.php');

$buyStart = strpos($index, "} elseif (preg_match('/^ds_buy:");
$buyEnd = $buyStart === false
    ? false
    : strpos($index, "} elseif (\$user['step'] === 'digital_service_target')", $buyStart);
$buyRoute = ($buyStart !== false && $buyEnd !== false)
    ? substr($index, $buyStart, $buyEnd - $buyStart)
    : '';

$checks = [
    [str_contains($providerCatalog, "syncDueProviders"), 'provider catalog scheduler missing'],
    [str_contains($providerCatalog, "syncProvider"), 'provider catalog synchronizer missing'],
    [str_contains($providerCatalog, "normaliseCategory"), 'provider automatic categorisation missing'],
    [str_contains($providerCatalog, "'youtube' =>"), 'YouTube category mapping missing'],
    [str_contains($providerCatalog, "'twitter' =>"), 'Twitter/X category mapping missing'],
    [str_contains($providerCatalog, "'tiktok' =>"), 'TikTok category mapping missing'],
    [str_contains($providerCatalog, "'spotify' =>"), 'Spotify category mapping missing'],
    [str_contains($providerCatalog, "'linkedin' =>"), 'LinkedIn category mapping missing'],
    [str_contains($providerCatalog, "'facebook' =>"), 'Facebook category mapping missing'],
    [str_contains($providerCatalog, "calculateSellingPrice"), 'provider automatic profit pricing missing'],
    [str_contains($providerCatalog, "FILTER_FLAG_NO_PRIV_RANGE"), 'provider catalog SSRF private-range guard missing'],
    [str_contains($providerCatalog, "CURLOPT_PROTOCOLS => CURLPROTO_HTTPS"), 'provider catalog must be HTTPS-only'],
    [str_contains($providerCatalog, "disableMissingProducts"), 'provider removed products must be auto-disabled'],
    [str_contains($providerCatalog, "discoverCatalogUrl"), 'provider catalog endpoint discovery missing'],
    [str_contains($providerCatalog, "autoDiscoverCatalogMapping"), 'provider catalog field auto-detection missing'],
    [str_contains($providerCatalog, "normaliseItemCollection"), 'provider associative catalog normalization missing'],
    [str_contains($providerCatalog, "requestSmmServices"), 'SMM POST catalog support missing'],
    [str_contains($providerCatalog, "DISCOVERY_MAX_ATTEMPTS"), 'provider discovery request budget missing'],
    [str_contains($providerCatalog, "DISCOVERY_BUDGET_SECONDS"), 'provider discovery time budget missing'],
    [str_contains($providerCatalog, "DISCOVERY_REQUEST_TIMEOUT_SECONDS"), 'provider discovery request timeout missing'],
    [str_contains($providerCatalog, "CURLINFO_REDIRECT_URL"), 'provider safe redirect detection missing'],
    [str_contains($providerCatalog, "$sourceHost === $redirectHost"), 'provider redirects must stay on the same host'],
    [str_contains($providerCatalog, "['/api/', '/api/v2/'"), 'canonical trailing-slash API candidates missing'],
    [str_contains($providerCatalog, "hostSafetyCache"), 'provider host safety DNS cache missing'],
    [str_contains($providerCatalog, "discoverLinkedApiEndpoints"), 'API-root linked endpoint discovery missing'],
    [str_contains($providerCatalog, "diagnosticTopLevelKeys"), 'API-root diagnostic key reporting missing'],
    [str_contains($providerCatalog, "GET/discovered"), 'linked catalog probe flow missing'],
    [str_contains($providerCatalog, "authProfiles"), 'multi-auth provider discovery missing'],
    [str_contains($providerCatalog, "$authHeader !== '' && !preg_match"), 'body-only provider auth header must be allowed'],
    [str_contains($providerCatalog, "X-API-Key"), 'X-API-Key discovery profile missing'],
    [str_contains($providerCatalog, "collectArrayPaths"), 'recursive JSON catalog discovery missing'],
    [str_contains($providerCatalog, "'action' => 'services'"), 'SMM services action missing'],
    [str_contains($providerCatalog, "'smm:'"), 'SMM catalog style marker missing'],
    [str_contains($providerCatalog, "wholesale_rate_per_1000"), 'SMM per-1000 rate handling missing'],
    [str_contains($providerTable, "profit_percent"), 'provider registry profit column missing'],
    [str_contains($providerTable, "exchange_rate_toman"), 'provider registry exchange-rate column missing'],
    [str_contains($manager, "pending_approval"), 'orders must enter pending approval'],
    [str_contains($manager, "approveAndDeliver"), 'manual approval delivery action missing'],
    [str_contains($manager, "rejectAndRefund"), 'reject/refund action missing'],
    [str_contains($manager, "UPDATE user SET Balance = Balance + ?"), 'refund must credit wallet'],
    [str_contains($manager, "giftPremiumSubscription"), 'Telegram Premium provider missing'],
    [str_contains($manager, "provider === 'tgtools'"), 'TGTools provider dispatch missing'],
    [str_contains($manager, "generatedProviderProductCode"), 'automatic provider product code generation missing'],
    [str_contains($manager, "categoryKeyboard"), 'digital service category keyboard missing'],
    [str_contains($manager, "categoryForProduct"), 'digital service category mapping missing'],
    [str_contains($manager, "if (\$type === 'telegram_premium')"), 'Telegram Premium category mapping missing'],
    [str_contains($manager, "if (\$type === 'telegram_stars')"), 'Telegram Stars category mapping missing'],
    [str_contains($manager, "ensureTgToolsCatalog"), 'automatic Stars/Premium catalog generation missing'],
    [str_contains($manager, "maybeSyncTgToolsCatalog"), 'periodic TGTools catalog refresh missing'],
    [str_contains($manager, "tgToolsWalletStatus"), 'TGTools API wallet diagnostics missing'],
    [str_contains($manager, "TGTOOLS_WALLET_INSUFFICIENT"), 'TGTools insufficient-wallet classification missing'],
    [str_contains($manager, "markRetryableProviderFailure"), 'retryable provider failure state missing'],
    [str_contains($manager, "maybeBootstrapOZVinooCatalog"), 'existing OZVinoo auto-bootstrap missing'],
    [str_contains($manager, "syncOZVinooCatalog"), 'official OZVinoo catalog synchronization missing'],
    [str_contains($manager, "ozvinooWalletStatus"), 'OZVinoo wallet diagnostics missing'],
    [str_contains($manager, "reconcileOZVinooProcessing"), 'OZVinoo async reconciliation missing'],
    [str_contains($manager, "deliverOZVinooOfficial"), 'OZVinoo official delivery adapter missing'],
    [str_contains($manager, "presetTarget"), 'OZVinoo virtual-number preset target missing'],
    [str_contains($manager, "ozvinoo_api_style"), 'OZVinoo API style persistence missing'],
    [str_contains($manager, "'youtube' => '▶️ خدمات یوتیوب'"), 'customer YouTube category label missing'],
    [str_contains($manager, "'tiktok' => '🎵 خدمات تیک‌تاک'"), 'customer TikTok category label missing'],
    [str_contains($manager, "deliverOZVinooSmm"), 'OZVinoo SMM order delivery missing'],
    [str_contains($manager, "'action' => 'add'"), 'OZVinoo SMM add-order action missing'],
    [str_contains($manager, "tgtools_stars_profit_percent"), 'TGTools Stars margin setting missing'],
    [str_contains($manager, "tgtools_premium_profit_percent"), 'TGTools Premium margin setting missing'],
    [str_contains($manager, "tgtools_ton_toman_rate"), 'TGTools TON-to-Toman rate setting missing'],
    [str_contains($manager, "calculateSellingPrice"), 'TGTools automatic margin pricing missing'],
    [str_contains($manager, "category_label"), 'dynamic provider category labels missing'],
    [str_contains($manager, "targetKeyboard"), 'dedicated inline target keyboard missing'],
    [str_contains($manager, "ORDER_STATE_INVALID"), 'order confirmation idempotency guard missing'],
    [str_contains($manager, "digital_service_processing"), 'atomic order-intent claim missing'],
    [str_contains($manager, "SELECT * FROM user WHERE id = ? FOR UPDATE"), 'user order-intent row lock missing'],
    [str_contains($manager, "provider_service_code = NULL"), 'TGTools products must not require provider service codes'],
    [str_contains($manager, "reconcileTgToolsProcessing"), 'TGTools async reconciliation missing'],
    [str_contains($manager, "failAndRefundProviderOrder"), 'TGTools provider failure refund missing'],
    [substr_count($manager, "failAndRefundProviderOrder(") >= 4, 'all provider delivery failure paths must refund automatically'],
    [str_contains($tgTools, "https://api.tg-tools.shop"), 'TGTools host allowlist missing'],
    [str_contains($tgTools, "/api/purchase/stars"), 'TGTools Stars endpoint missing'],
    [str_contains($tgTools, "/api/purchase/premium"), 'TGTools Premium endpoint missing'],
    [str_contains($tgTools, "/api/purchase/prices"), 'TGTools live prices endpoint missing'],
    [str_contains($tgTools, "/api/wallet"), 'TGTools API wallet endpoint missing'],
    [str_contains($tgTools, "/api/transaction/"), 'TGTools documented transaction status endpoint missing'],
    [str_contains($tgTools, "X-Api-Key"), 'TGTools API key authentication missing'],
    [str_contains($tgTools, "trackingCode"), 'TGTools idempotency tracking code missing'],
    [str_contains($cronJobs, "'job' => 'digital_services'"), 'TGTools reconciliation cron is not scheduled'],
    [str_contains($digitalServicesCron, "maybeSyncTgToolsCatalog"), 'TGTools live catalog cron refresh missing'],
    [str_contains($digitalServicesCron, "syncDueProviders"), 'generic provider catalog cron sync missing'],
    [str_contains($digitalServicesCron, "maybeBootstrapOZVinooCatalog"), 'OZVinoo cron catalog sync missing'],
    [str_contains($digitalServicesCron, "reconcileOZVinooProcessing"), 'OZVinoo order reconciliation cron missing'],
    [str_contains($settings, "tgtools_api_key"), 'TGTools API key setting missing'],
    [str_contains($settings, "tgtools_catalog_last_sync"), 'TGTools catalog sync timestamp setting missing'],
    [str_contains($settings, "tgtools_stars_profit_percent"), 'TGTools Stars profit seed missing'],
    [str_contains($settings, "tgtools_premium_profit_percent"), 'TGTools Premium profit seed missing'],
    [str_contains($settings, "tgtools_ton_toman_rate"), 'TGTools TON rate seed missing'],
    [str_contains($settings, "ozvinoo_profit_percent"), 'OZVinoo profit seed missing'],
    [str_contains($settings, "ozvinoo_catalog_path"), 'OZVinoo catalog path seed missing'],
    [str_contains($settings, "ozvinoo_exchange_rate_toman"), 'OZVinoo exchange rate seed missing'],
    [str_contains($settings, "ozvinoo_api_style"), 'OZVinoo API style seed missing'],
    [str_contains($settings, "ozvinoo_catalog_last_sync"), 'OZVinoo catalog sync timestamp setting missing'],
    [str_contains($panel, "save_tgtools"), 'TGTools admin settings UI missing'],
    [str_contains($panel, "sync_tgtools_catalog"), 'TGTools catalog sync action missing'],
    [str_contains($panel, "save_provider_catalog"), 'generic provider catalog save action missing'],
    [str_contains($panel, "sync_provider_catalog"), 'generic provider manual sync action missing'],
    [str_contains($panel, "profit_percent"), 'provider profit percentage UI missing'],
    [str_contains($panel, "tgtools_stars_profit_percent"), 'TGTools Stars separate margin UI missing'],
    [str_contains($panel, "tgtools_premium_profit_percent"), 'TGTools Premium separate margin UI missing'],
    [str_contains($panel, "ozvinoo_profit_percent"), 'OZVinoo provider-wide profit UI missing'],
    [str_contains($panel, "کیف پول API TGTools"), 'TGTools API wallet balance UI missing'],
    [str_contains($panel, "محصولات فعال عضوینو در ربات"), 'OZVinoo product-sync diagnostics missing'],
    [str_contains($panel, "sync_ozvinoo_catalog"), 'OZVinoo catalog sync action missing'],
    [str_contains($panel, "syncOZVinooCatalog"), 'OZVinoo panel must use official catalog sync'],
    [str_contains($panel, "کیف پول API عضوینو"), 'OZVinoo wallet balance UI missing'],
    [str_contains($panel, "API رسمی"), 'OZVinoo official API UI copy missing'],
    [str_contains($panel, "data-ozvinoo-sync-form"), 'OZVinoo official sync progress guard missing'],
    [str_contains($panel, "products_path' => \$_POST['products_path'] ?? 'auto'"), 'generic provider mapping must default to auto'],
    [str_contains($panel, "set_product_price"), 'generated product retail price action missing'],
    [str_contains($panel, 'value="tgtools"'), 'TGTools product provider option missing'],
    [str_contains($ozvinooClient, "https://api.ozvinoo.xyz"), 'OZVinoo host allowlist missing'],
    [str_contains($ozvinooClient, "CURLOPT_PROTOCOLS => CURLPROTO_HTTPS"), 'OZVinoo must be HTTPS-only'],
    [str_contains($ozvinooClient, "/web/") && str_contains($ozvinooClient, "/get-balance"), 'OZVinoo balance endpoint missing'],
    [str_contains($ozvinooClient, "/telegram-services/stars/"), 'OZVinoo Stars endpoint missing'],
    [str_contains($ozvinooClient, "/telegram-services/premium/"), 'OZVinoo Premium endpoint missing'],
    [str_contains($ozvinooClient, "/telegram-services/status/"), 'OZVinoo Telegram order status endpoint missing'],
    [str_contains($ozvinooClient, "/telegram-numbers/numbers/"), 'OZVinoo numbers endpoint missing'],
    [str_contains($ozvinooClient, "/telegram-numbers/number-services/"), 'OZVinoo number status endpoint missing'],
    [str_contains($ozvinooClient, "/numbers/getAllOrders/"), 'OZVinoo all-orders endpoint missing'],
    [str_contains($ozvinooClient, "/numbers/getOpenOrders/"), 'OZVinoo open-orders endpoint missing'],
    [str_contains($ozvinooClient, "/numbers/getOrder/"), 'OZVinoo get-order endpoint missing'],
    [str_contains($ozvinooClient, "/numbers/cancelOrder/"), 'OZVinoo cancel-order endpoint missing'],
    [str_contains($ozvinooClient, "Authorization: Bearer"), 'OZVinoo Bearer authentication missing'],
    [str_contains($index, "ds_confirm:"), 'user order confirmation route missing'],
    [str_contains($buyRoute, "BluebotDigitalServices::targetKeyboard(\$product)"), 'order target screen must use an inline keyboard'],
    [str_contains($buyRoute, "BluebotDigitalServices::presetTarget(\$product)"), 'virtual-number checkout must skip manual target input'],
    [!str_contains($buyRoute, '\$backuser'), 'order target edit must not pass ReplyKeyboardMarkup to editMessageText'],
    [str_contains($index, "ORDER_STATE_INVALID"), 'duplicate/stale confirmation handling missing'],
    [str_contains($index, "ds_category:"), 'customer category navigation route missing'],
    [str_contains($index, "لینک، یوزرنیم یا مقصد موردنیاز این سرویس"), 'OZVinoo customer target prompt missing'],
    [str_contains($index, "مقدار این بسته"), 'OZVinoo fixed-package quantity display missing'],
    [str_contains($index, "categoryKeyboard"), 'customer category landing screen missing'],
    [str_contains($index, "notifyAdmins"), 'admin notification missing'],
    [str_contains($admin, "ds_approve:"), 'bot approval callback missing'],
    [str_contains($admin, "ds_reject:"), 'bot reject callback missing'],
    [str_contains($admin, "قابل تلاش مجدد است"), 'admin retryable provider error UX missing'],
    [str_contains($admin, "مبلغ برگشت خورد"), 'admin refunded provider error UX missing'],
    [str_contains($keyboard, "text_digital_services"), 'main keyboard mapping missing'],
    [str_contains($setting, "text_digital_services"), 'default keyboard token missing'],
    [str_contains($panel, "approve_order"), 'web approval action missing'],
    [str_contains($panel, "reject_order"), 'web reject action missing'],
    [!str_contains($index, "ارسال فقط بعد از تأیید دستی ادمین انجام می‌شود."), 'customer product page leaks internal approval workflow'],
    [!str_contains($index, "سفارش تا تأیید دستی ادمین ارسال نخواهد شد."), 'customer confirmation leaks internal approval workflow'],
    [!str_contains($langFa, "در صف تأیید و ارسال ادمین"), 'customer queued message leaks internal approval workflow'],
    [!str_contains($manager, "برای TGTools باید یوزرنیم"), 'customer validation leaks provider name'],
    [str_contains($langFa, "سرویس موردنظر را انتخاب کنید 👇"), 'customer catalog copy was not simplified'],
    [str_contains($langFa, "دسته‌بندی موردنظر را انتخاب کنید 👇"), 'customer category prompt missing'],
];

foreach ($checks as [$ok, $message]) {
    if (!$ok) {
        fwrite(STDERR, "Digital services contract failed: {$message}\n");
        exit(1);
    }
}

echo "Digital services contract OK.\n";
