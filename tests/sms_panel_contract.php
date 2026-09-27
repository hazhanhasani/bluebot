<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $path) use ($root): string {
    $value = @file_get_contents($root . '/' . $path);
    return is_string($value) ? $value : '';
};

$sms = $read('src/Support/SmsService.php');
$tables = $read('db/tables.php');
$indexes = $read('db/indexes.php');
$userTable = $read('db/tables/user.php');
$panel = $read('panel/sms.php');
$layout = $read('panel/inc/layout_head.php');
$jobs = $read('cronbot/jobs.php');
$worker = $read('cronbot/sms.php');
$monitor = $read('cronbot/NotificationsService.php');
$paymentState = $read('src/Payment/PaymentState.php');
$functions = $read('function.php');
$userPanel = $read('panel/user.php');
$bot = $read('index.php');
$farazDocs = $read('docs/FARAZSMS_PATTERNS.md');

$checks = [
    [$tables, "'sms_settings'", 'sms_settings is not registered in the schema.'],
    [$tables, "'sms_templates'", 'sms_templates is not registered in the schema.'],
    [$tables, "'sms_deliveries'", 'sms_deliveries is not registered in the schema.'],
    [$tables, "'sms_otp_challenges'", 'sms_otp_challenges is not registered in the schema.'],
    [$indexes, 'uniq_sms_dedupe', 'SMS outbox dedupe index is missing.'],
    [$userTable, 'sms_enabled TINYINT(1)', 'Per-user SMS preference is missing.'],
    [$sms, "https://api.iranpayamak.com/ws/v1", 'Official FarazSMS/IranPayamak API base is missing.'],
    [$sms, "aes-256-gcm", 'SMS API key must be encrypted at rest with AES-256-GCM.'],
    [$sms, "function refreshPatterns", 'Provider pattern synchronization is missing.'],
    [$sms, "'/patterns?' . $query", 'Pattern synchronization must walk provider pages.'],
    [$sms, "'complete' => true", 'Pattern synchronization must mark complete caches.'],
    [$sms, "function refreshLines", 'Automatic sender-line discovery is missing.'],
    [$sms, "'/lines/accessible'", 'Official accessible-lines endpoint is missing.'],
    [$sms, "function smartAssignPatterns", 'Smart pattern assignment is missing.'],
    [$sms, "function queueAndDispatchForUser", 'Foreground durable SMS dispatch is missing.'],
    [$sms, "function processQueue", 'SMS retry queue processor is missing.'],
    [$sms, "function syncServiceStatus", 'Live service reminder integration is missing.'],
    [$sms, "function requestPhoneOtp", 'FarazSMS phone OTP request flow is missing.'],
    [$sms, "function verifyPhoneOtp", 'FarazSMS phone OTP verification flow is missing.'],
    [$panel, "مدیریت کامل فراز اس‌ام‌اس / ایران‌پیامک", 'Web SMS management page is missing.'],
    [$panel, "save_templates", 'SMS template management is missing from the panel.'],
    [$panel, "refresh_patterns", 'Pattern refresh action is missing from the panel.'],
    [$panel, "refresh_lines", 'Sender-line refresh action is missing from the panel.'],
    [$panel, 'name="otp_active"', 'OTP enable control is missing from the SMS panel.'],
    [$panel, "متن دقیق برای ثبت در فراز SMS", 'FarazSMS pattern registration guidance is missing from the panel.'],
    [$panel, "broadcast", 'Broadcast SMS action is missing from the panel.'],
    [$layout, 'href="sms.php"', 'SMS center is not linked from the web panel sidebar.'],
    [$jobs, "['job' => 'sms'", 'SMS cron worker is not scheduled.'],
    [$worker, 'BluebotSms::processQueue(60)', 'SMS cron worker does not process the durable outbox.'],
    [$monitor, 'BluebotSms::syncServiceStatus', 'Live service monitor is not connected to SMS reminders.'],
    [$paymentState, "'payment_success'", 'Atomic payment success SMS hook is missing.'],
    [$functions, "'service_activated'", 'Service activation SMS hook is missing.'],
    [$functions, "'service_renewed'", 'Service renewal SMS hook is missing.'],
    [$userPanel, "'wallet_charged'", 'Admin wallet charge SMS hook is missing.'],
    [$userPanel, "toggle_sms", 'Per-user SMS control is missing from the panel.'],
    [$bot, "BluebotSms::requestPhoneOtp", 'Telegram contact flow does not request FarazSMS OTP.'],
    [$bot, "verify_phone_otp", 'Telegram phone OTP step is missing.'],
    [$bot, "BluebotSms::verifyPhoneOtp", 'Telegram phone OTP verification is missing.'],
    [$bot, "\$manualVerificationRequired", 'Manual verification guard compatibility is missing.'],
    [$bot, "\$phoneVerificationStep", 'Phone verification must bypass the manual verification gate.'],
    [$bot, 'update("user", "verify", "1", "id", \$from_id);', 'Successful phone verification must satisfy account verification.'],
    [$farazDocs, "phone_verification", 'FarazSMS pattern documentation is missing.'],
    [$farazDocs, "code`: number, max 6", 'OTP variable type/length is not documented.'],
    [$panel, "sms-var-type", 'SMS panel variable type badges are missing.'],
];

foreach ($checks as [$source, $needle, $message]) {
    if ($source === '' || !str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

require_once $root . '/src/Support/SmsService.php';

$phones = [
    ['09121234567', '+989121234567'],
    ['+989121234567', '+989121234567'],
    ['989121234567', '+989121234567'],
    ['00989121234567', '+989121234567'],
    ['۰۹۱۲۱۲۳۴۵۶۷', '+989121234567'],
];
foreach ($phones as [$input, $expected]) {
    $actual = BluebotSms::normalizePhone($input);
    if ($actual !== $expected) {
        $failures[] = "Phone normalization failed for {$input}: {$actual}";
    }
}
if (BluebotSms::normalizePhone('not-a-phone') !== '') {
    $failures[] = 'Invalid phone values must be rejected.';
}

$catalog = BluebotSms::catalog();
foreach ($catalog as $eventKey => $eventSpec) {
    foreach ((array) ($eventSpec['vars'] ?? []) as $variable) {
        $type = (string) ($variable['type'] ?? '');
        if (!in_array($type, ['string', 'number'], true)) {
            $failures[] = "Unsupported SMS variable type in {$eventKey}: {$type}";
        }
    }
}

foreach ([
    'phone_verification',
    'service_activated',
    'service_renewed',
    'subscription_reminder',
    'subscription_expired',
    'low_remaining_volume',
    'volume_expired',
    'payment_success',
    'wallet_charged',
    'admin_announcement',
] as $event) {
    if (!isset($catalog[$event])) {
        $failures[] = "Missing SMS catalog event: {$event}";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "SMS panel contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "SMS panel contract OK.\n";
