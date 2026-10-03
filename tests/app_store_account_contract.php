<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$account = (string) @file_get_contents($root . '/src/Services/AppStoreAccount.php');
$sms = (string) @file_get_contents($root . '/src/Support/SmsService.php');
$client = (string) @file_get_contents($root . '/api/client.php');
$bot = (string) @file_get_contents($root . '/index.php');
$models = (string) @file_get_contents($root . '/android-client/app/src/main/java/com/bluepanel/client/data/ApiModels.kt');
$api = (string) @file_get_contents($root . '/android-client/app/src/main/java/com/bluepanel/client/data/BluePanelApi.kt');
$ui = (string) @file_get_contents($root . '/android-client/app/src/main/java/com/bluepanel/client/MainActivity.kt');
$accountTable = (string) @file_get_contents($root . '/db/tables/app_mobile_accounts.php');
$sessionTable = (string) @file_get_contents($root . '/db/tables/app_mobile_sessions.php');

$checks = [
    [$account, 'final class AppStoreAccount', 'Mobile store account service is missing.'],
    [$account, 'public static function requestOtp', 'Mobile OTP request flow is missing.'],
    [$sms, 'Mapped SMS pattern send failed; attempting provider resync', 'OTP should try its stored mapped pattern before catalog refresh.'],
    [$account, 'public static function verifyOtp', 'Mobile OTP verification flow is missing.'],
    [$account, 'public static function linkTelegramUserByPhone', 'Phone-based Telegram account linking is missing.'],
    [$account, "['invoice', 'id_user']", 'Invoice ownership is not migrated during phone linking.'],
    [$account, "['Payment_report', 'id_user']", 'Payment ownership is not migrated during phone linking.'],
    [$account, "UPDATE app_mobile_accounts\n                 SET user_id=?,linked_telegram_id=?", 'Mobile account is not rebound to Telegram after verification.'],
    [$account, 'public static function catalog', 'Store catalog service is missing.'],
    [$account, 'public static function createCheckout', 'Store checkout service is missing.'],
    [$account, "'getconfigafterpay|' . $username", 'Checkout does not reuse the production DirectPayment delivery state.'],
    [$account, 'markPaymentDeliveryError', 'Paid checkout delivery failures must be marked for review.'],
    [$account, "\$paymentState === '' || \$paymentState === 'unpaid'", 'Checkout cleanup must preserve paid delivery errors.'],
    [$client, "if (\$action === 'mobile-otp-request')", 'Android API does not expose mobile OTP request.'],
    [$client, "if (\$action === 'mobile-otp-verify')", 'Android API does not expose mobile OTP verification.'],
    [$client, "if (\$action === 'store-catalog')", 'Android API does not expose storefront catalog.'],
    [$client, "'requires_account' => true", 'Panel-only sessions must expose an account-registration storefront state.'],
    [$client, 'clientPublicOtpError', 'OTP failures must expose safe actionable diagnostics.'],
    [$client, "if (\$action === 'store-checkout')", 'Android API does not expose storefront checkout.'],
    [$client, "if (\$sessionType === 'mobile')", 'Android API does not return all services for mobile accounts.'],
    [$bot, 'AppStoreAccount::linkTelegramUserByPhone', 'Telegram phone verification does not link app accounts.'],
    [$models, 'data class StorePlan', 'Android store models are missing.'],
    [$api, 'suspend fun requestMobileOtp', 'Android OTP API client is missing.'],
    [$api, 'suspend fun storeCatalog', 'Android store catalog API client is missing.'],
    [$api, 'suspend fun checkout', 'Android store checkout API client is missing.'],
    [$ui, 'private fun PhoneRegistrationScreen', 'Android phone registration UI is missing.'],
    [$ui, 'private fun StoreScreen', 'Android storefront UI is missing.'],
    [$ui, 'ساخت / اتصال حساب با شماره موبایل', 'Panel QR sessions must be able to upgrade into a mobile shopping account.'],
    [$ui, 'ثبت‌نام با شماره موبایل و خرید سرویس', 'Android login screen does not expose storefront registration.'],
    [$ui, 'order.status == "paid" && order.serviceId != null', 'Android must not report purchase success without a delivered service.'],
    [$accountTable, 'UNIQUE KEY uq_app_mobile_phone (phone)', 'Mobile phone uniqueness is not enforced by schema.'],
    [$sessionTable, 'UNIQUE KEY uq_app_mobile_token (token_hash)', 'Mobile session token uniqueness is not enforced by schema.'],
];

foreach ($checks as [$source, $needle, $message]) {
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

if (!str_contains($account, "hash('sha256', \$token)") || !str_contains($account, "hash('sha256', trim(\$deviceId))")) {
    $failures[] = 'Mobile sessions must store token/device fingerprints rather than plaintext secrets.';
}

if (!str_contains($account, 'BluebotSms::normalizePhone') || !str_contains($account, 'BluebotSms::verifyPhoneOtp')) {
    $failures[] = 'Mobile identity must be based on the existing verified SMS phone flow.';
}

if ($failures !== []) {
    fwrite(STDERR, "App store account contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "App store account contract OK.\n";
