<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . $path);
    return is_string($content) ? $content : '';
};

$functions = $read('function.php');
$usersPage = $read('panel/users.php');
$usersJs = $read('panel/js/users.js');
$apiUsers = $read('api/users.php');
$panels = $read('panels.php');
$apiPanels = $read('api/panels.php');
$apiProduct = $read('api/product.php');
$miniApp = $read('api/miniapp.php');
$wgDashboard = $read('src/Panel/Adapters/WGDashboard.php');
$notifications = $read('cronbot/NotificationsService.php');
$bulkMessages = $read('cronbot/sendmessage.php');
$uptimeNode = $read('cronbot/uptime_node.php');
$uptimePanel = $read('cronbot/uptime_panel.php');
$dailyStatus = $read('cronbot/statusday.php');
$cronCard = $read('cronbot/croncard.php');
$cronPlisio = $read('cronbot/plisio.php');
$cronIranpay = $read('cronbot/iranpay1.php');
$cronJobs = $read('cronbot/jobs.php');
$cronRun = $read('cronbot/run.php');
$storageHtaccess = $read('storage/.htaccess');
$nowPaymentCallback = $read('payment/nowpayment.php');
$iranpay1Callback = $read('payment/iranpay1.php');
$cubepayCallback = $read('payment/iranpay2.php');
$iranpay4Callback = $read('payment/iranpay4.php');
$zarinpalCallback = $read('payment/zarinpal.php');
$varizaWebhook = $read('payment/variza_webhook.php');
$admin = $read('admin.php');
$botIndex = $read('index.php');
$vpnDefaultIndex = $read('vpnbot/Default/index.php');
$vpnUpdateIndex = $read('vpnbot/update/index.php');
$vpnDefaultKeyboard = $read('vpnbot/Default/keyboard.php');
$vpnUpdateKeyboard = $read('vpnbot/update/keyboard.php');
$vpnDefaultFunc = $read('vpnbot/Default/func.php');
$vpnUpdateFunc = $read('vpnbot/update/func.php');
$iranpay1 = $read('payment/iranpay1.php');
$zarinpal = $read('payment/zarinpal.php');
$diagnostics = $read('src/Support/Diagnostics.php');

$mustContain = [
    [$functions, "\$allowed = ['fa', 'en', 'ru', 'zh'];", 'languagechange() must only select bundled locales.'],
    [$functions, 'if (!is_file($file))', 'languagechange() must fall back when a locale file is missing.'],
    [$functions, 'CURLOPT_TIMEOUT => 15', 'Plisio requests must have a finite timeout.'],
    [$functions, '\'callback\' => "https://" . $domainhosts . "/payment/aqayepardakht.php"', 'AqayePardakht callback must be HTTPS.'],
    [$usersPage, 'id="searchInput"', 'Users search input must be wired to users.js.'],
    [$usersPage, 'id="filterStatus"', 'Users status filter must be wired to users.js.'],
    [$usersPage, 'id="filterRole"', 'Users role filter must be wired to users.js.'],
    [$usersPage, 'id="usersBody"', 'Users table body must expose an AJAX replacement target.'],
    [$usersPage, 'id="tblFoot"', 'Users pagination footer must expose an AJAX replacement target.'],
    [$usersJs, "new DOMParser().parseFromString(html, 'text/html')", 'Users AJAX must parse the returned page before table injection.'],
    [$apiUsers, '$pdo->beginTransaction();', 'Account transfer must be transactional.'],
    [$apiUsers, "['sms_deliveries', 'user_id']", 'Account transfer must move SMS delivery ownership.'],
    [$apiUsers, "['sms_otp_challenges', 'user_id']", 'Account transfer must move SMS OTP ownership.'],
    [$apiUsers, "SHOW TABLES LIKE 'digital_service_orders'", 'Account transfer must safely detect and move digital-service orders.'],
    [$apiUsers, "INSERT IGNORE INTO digital_service_favorites", 'Account transfer must merge digital-service favorites without unique-key collisions.'],
    [$apiUsers, "['reagent_report', 'reagent']", 'Account transfer must move referral ownership references.'],
    [$notifications, "'volume' => false", 'Notification cron state must have safe defaults.'],
    [$notifications, 'private function shouldRemoveServiceVolume', 'Notification volume cleanup must use a stable ASCII method name.'],
    [$notifications, "Invalid inactive inbound configuration", 'Notification cron must validate inactive inbound configuration.'],
    [$notifications, '$inbounds = [$protocol => [$inboundTag]];', 'Notification cron must initialize inbound payloads.'],
    [$bulkMessages, 'flock($queueHandle, LOCK_EX)', 'Bulk-message queue must be locked while processing.'],
    [$bulkMessages, 'if (!is_array($userid))', 'Bulk-message queue must reject invalid JSON.'],
    [$uptimeNode, 'if (!is_array($statusCron)', 'Node uptime cron must validate cron settings.'],
    [$uptimeNode, 'if (!is_array($decoded))', 'Node uptime cron must validate panel responses.'],
    [$uptimePanel, "\$scheme === 'http' ? 80 : 443", 'Panel uptime cron must use the correct default port.'],
    [$dailyStatus, '$setting = is_array($setting) ? $setting : [];', 'Daily report cron must tolerate missing settings.'],
    [$cronCard, 'markPaymentDeliveryError', 'Auto-confirmed payments must preserve delivery failures.'],
    [$cronPlisio, 'markPaymentDeliveryError', 'Plisio cron must preserve delivery failures.'],
    [$cronIranpay, 'markPaymentDeliveryError', 'IranPay cron must preserve delivery failures.'],
    [$cronJobs, 'function mirza_cron_http_authorized', 'Web cron dispatch must require authentication.'],
    [$cronJobs, 'X-BlueBot-Cron-Token: $token', 'Generated curl cron command must send the cron token header.'],
    [$cronRun, "PHP_SAPI !== 'cli' && !mirza_cron_http_authorized", 'Cron dispatcher must reject unauthenticated web calls.'],
    [$storageHtaccess, '<Files "cron-http.secret">', 'Cron dispatcher secret must be denied from web access.'],
    [$cronRun, 'if ($slotFh === null)', 'Cron dispatcher must stop when host concurrency slots are exhausted.'],
    [$functions, 'if ($bytes <= 0)', 'Byte formatter must handle zero and invalid byte values safely.'],
    [$functions, 'random_int(1000000, 9999999)', 'Username generation must use a cryptographically secure random suffix.'],
    [$functions, "getPaySettingValue('marchent_tronseller'", 'NOWPayments helpers must tolerate missing payment settings.'],
    [$nowPaymentCallback, "getPaySettingValue('cashbacknowpayment'", 'NOWPayments callback must tolerate missing cashback settings.'],
    [$iranpay1Callback, "Payment_Method'] ?? '') !== 'Currency Rial 1'", 'IranPay1 callback must be bound to IranPay1 orders.'],
    [$iranpay1Callback, 'languagechange(dirname(__DIR__))', 'IranPay1 callback must initialize language text before rendering.'],
    [$zarinpalCallback, "Payment_Method'] ?? '') !== 'zarinpal'", 'ZarinPal callback must be bound to ZarinPal orders.'],
    [$zarinpalCallback, 'languagechange(dirname(__DIR__))', 'ZarinPal callback must initialize language text before rendering.'],
    [$cubepayCallback, "'delivery_failed'", 'CubePay callback must surface delivery failures separately from payment failures.'],
    [$cubepayCallback, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS', 'CubePay verification must be HTTPS-only.'],
    [$iranpay4Callback, "Payment_Method'] ?? '') !== 'iranpay4'", 'IranPay4 callback must be bound to IranPay4 orders.'],
    [$varizaWebhook, "Payment_Method'] ?? '') !== 'variza'", 'Variza webhook must be bound to Variza orders.'],
    [$admin, "bluebotAudit('admin.user_transfer'", 'Admin account transfers must be audited.'],
    [$admin, "['sms_otp_challenges', 'user_id']", 'Admin account transfer must move OTP ownership.'],
    [$admin, "SHOW TABLES LIKE 'digital_service_orders'", 'Admin account transfer must safely move digital-service orders.'],
    [$admin, "INSERT IGNORE INTO digital_service_favorites", 'Admin account transfer must merge digital-service favorites.'],
    [$functions, 'function selectValue(', 'Runtime must expose a safe scalar select helper.'],
    [$functions, "Invalid renewal delivery state for order", 'DirectPayment must validate renewal state before delivery.'],
    [$functions, "Invalid extra-volume delivery state for order", 'DirectPayment must validate extra-volume state before delivery.'],
    [$functions, "Invalid extra-time delivery state for order", 'DirectPayment must validate extra-time state before delivery.'],
    [$panels, "'msg' => 'Product Not Found'", 'Panel creation must reject missing products before adapter calls.'],
    [$panels, "'msg' => 'No available manual configuration'", 'Manual-sale delivery must reject an empty inventory.'],
    [$panels, '$name_group = $Get_Data_Product[\'inbounds\'];', 'IBSng must honor product-specific group/inbound selection.'],
    [$panels, "'msg' => 'Invalid S-UI subscription settings'", 'S-UI subscription URL construction must validate panel settings.'],
    [$apiPanels, '$decodedUserData', 'Panel API must validate decoded adapter responses.'],
    [$apiProduct, '$decodedUserData', 'Product API must validate decoded adapter responses.'],
    [$miniApp, 'selectValue("user", "Balance"', 'Mini App must read balance through the safe scalar helper.'],
    [$wgDashboard, 'function wgDashboardInvoicePublicKey', 'WGDashboard must validate stored peer public keys.'],
    [$functions, 'function bluebotDownloadTelegramFile', 'Telegram media downloads must use the bounded downloader.'],
    [$functions, 'getimagesizefromstring($content)', 'QR backgrounds must be validated as images before storage.'],
    [$functions, "if (!is_array(\$response) || empty(\$response['ok']))", 'Channel membership checks must guard Telegram failures.'],
    [$admin, 'bluebotDownloadTelegramFile($filePath', 'Admin QR uploads must use the safe Telegram downloader.'],
    [$botIndex, 'AND refral = :referral', 'Affiliate statistics must use a bound referral parameter.'],
    [$vpnDefaultKeyboard, '$stmt->execute(is_array($queryParams) ? $queryParams : []);', 'Default VPNBot product keyboard must bind query parameters.'],
    [$vpnUpdateKeyboard, '$stmt->execute(is_array($queryParams) ? $queryParams : []);', 'Update VPNBot product keyboard must bind query parameters.'],
    [$vpnDefaultFunc, 'function vpnbotSendTempDocument', 'Default VPNBot must use safe temporary document handling.'],
    [$vpnUpdateFunc, 'function vpnbotSendQrPhoto', 'Update VPNBot must use safe temporary QR handling.'],
    [$vpnDefaultIndex, "getStructuredSettingValue(", 'Default VPNBot must guard per-agent structured settings.'],
    [$vpnUpdateIndex, "getStructuredSettingValue(", 'Update VPNBot must guard per-agent structured settings.'],
    [$diagnostics, "SHOW TABLES LIKE 'digital_service_orders'", 'Runtime /debug must detect the Digital Services order table safely.'],
    [$diagnostics, "orders_failed_review", 'Runtime /debug must report digital orders that need manual review.'],
    [$diagnostics, "<b>🛍 Digital Services</b>", 'Runtime /debug must include a Digital Services health section.'],
    [$diagnostics, "provider_approval_", 'Runtime /debug must expose provider delivery modes without exposing credentials.'],
    [$diagnostics, "Active products:", 'Runtime /debug must report active digital-service products.'],
    [$diagnostics, "Needs review:", 'Runtime /debug must report unresolved digital-service failures.'],
    [$diagnostics, "orders_partial_review", 'Runtime /debug must report partial digital orders needing review.'],
    [$diagnostics, "orders_stale_processing", 'Runtime /debug must report stale processing digital orders.'],
    [$diagnostics, "Partial review:", 'Runtime /debug partial-order line missing.'],
    [$diagnostics, "Stale processing (>30m):", 'Runtime /debug stale-processing line missing.'],


];

foreach ($mustContain as [$source, $needle, $message]) {
    if ($source === '' || !str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

foreach ([
    'payment/iranpay1.php' => $iranpay1,
    'payment/zarinpal.php' => $zarinpal,
] as $path => $source) {
    if (preg_match('/CURLOPT_TIMEOUT\s*=>\s*0\b/', $source)) {
        $failures[] = "{$path} must not use an infinite gateway timeout.";
    }
    if (!str_contains($source, 'CURLOPT_CONNECTTIMEOUT => 5')) {
        $failures[] = "{$path} must bound connection time.";
    }
}

if (str_contains($usersPage, "onchange=\"document.getElementById('usersForm').submit()\"")) {
    $failures[] = 'Users filters must not force a full-page reload while AJAX filtering is enabled.';
}

if (str_contains($notifications, 'shouldRemoveServiceـvolume')) {
    $failures[] = 'Notification cron still contains the legacy non-ASCII method identifier.';
}

foreach ([
    'vpnbot/Default/index.php' => $vpnDefaultIndex,
    'vpnbot/update/index.php' => $vpnUpdateIndex,
] as $path => $source) {
    if (preg_match('/SELECT \* FROM product WHERE \(Location = \'\{\$locationproduct/', $source)) {
        $failures[] = "{$path} still interpolates product location into SQL.";
    }
    if (str_contains($source, 'file_put_contents($urlimage')) {
        $failures[] = "{$path} still writes generated media to request-controlled local paths.";
    }
    if (preg_match('/json_decode\(\$marzban_list_get\[[^\]]+\], true\);\s*\$[A-Za-z_]+ = \$[A-Za-z_]+\[\$userbot\[\'agent\'\]\]/s', $source)) {
        $failures[] = "{$path} still dereferences unvalidated per-agent JSON.";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Runtime debug contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Runtime debug contract OK.\n";
