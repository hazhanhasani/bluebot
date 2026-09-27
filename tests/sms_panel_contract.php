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
$langFa = $read('lang/fa.php');
$privacy = $read('privacy.html');
$terms = $read('terms.html');

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
    [$sms, "'/patterns?' . \$query", 'Pattern synchronization must walk provider pages.'],
    [$sms, "'complete' => true", 'Pattern synchronization must mark complete caches.'],
    [$sms, "function refreshLines", 'Automatic sender-line discovery is missing.'],
    [$sms, "'/lines/accessible'", 'Official accessible-lines endpoint is missing.'],
    [$sms, "function smartAssignPatterns", 'Smart pattern assignment is missing.'],
    [$sms, "\$type = 'unknown';", 'Provider variables without explicit type metadata must remain unknown.'],
    [$sms, "['number', 'unknown']", 'Numeric pattern compatibility must tolerate missing provider type metadata.'],
    [$sms, "function queueAndDispatchForUser", 'Foreground durable SMS dispatch is missing.'],
    [$sms, "function processQueue", 'SMS retry queue processor is missing.'],
    [$sms, "function syncServiceStatus", 'Live service reminder integration is missing.'],
    [$sms, "function requestPhoneOtp", 'FarazSMS phone OTP request flow is missing.'],
    [$sms, "function verifyPhoneOtp", 'FarazSMS phone OTP verification flow is missing.'],
    [$sms, "function sampleParams", 'SMS test sample parameter generator is missing.'],
    [$panel, "مدیریت کامل فراز اس‌ام‌اس / ایران‌پیامک", 'Web SMS management page is missing.'],
    [$panel, "save_templates", 'SMS template management is missing from the panel.'],
    [$panel, "BluebotSms::sampleParams", 'SMS test form must auto-populate event variables.'],
    [$panel, "sms-test-params", 'SMS test parameter editor is missing.'],
    [$panel, "بررسی عمومی سامانه", 'Public system verification card is missing from the SMS panel.'],
    [$panel, "\$publicSystemUrl", 'SMS panel must derive the public verification URL from the current deployment host.'],
    [$bot, "سامانه فعال و قابل بررسی است", 'Public provider verification landing page is missing from root index.'],
    [$bot, "trustseal.enamad.ir", 'Enamad trust seal is missing from the public verification page.'],
    [$bot, "HuvEauyphrDRR17dhwoisDFNoMFMkDC0", 'Configured Enamad trust-seal code is missing.'],
    [$bot, "https://trustseal.enamad.ir", 'CSP must allow the Enamad trust-seal image.'],
    [$bot, "REQUEST_METHOD", 'Root index must distinguish public GET/HEAD requests from Telegram webhook POSTs.'],
    [$bot, "bot.blluepanel.ir", 'Provider verification fallback host must match the registered system.'],
    [$bot, "زرین‌پال", 'Public landing must expose Zarinpal integration information.'],
    [$bot, "آقای‌پرداخت", 'Public landing must expose AqayePardakht integration information.'],
    [$bot, 'href="/privacy.html"', 'Public landing must link to the privacy policy.'],
    [$bot, 'href="/terms.html"', 'Public landing must link to the terms page.'],
    [$privacy, "سیاست حریم خصوصی", 'Public privacy policy page is missing.'],
    [$privacy, "اطلاعات حساس کارت بانکی", 'Privacy policy must describe payment-card handling.'],
    [$terms, "شرایط استفاده و پرداخت", 'Public terms and payment policy page is missing.'],
    [$terms, "پرداخت آنلاین", 'Terms page must document online payment behavior.'],
    [$panel, "refresh_patterns", 'Pattern refresh action is missing from the panel.'],
    [$panel, "refresh_lines", 'Sender-line refresh action is missing from the panel.'],
    [$panel, 'name="otp_active"', 'OTP enable control is missing from the SMS panel.'],
    [$panel, "متن دقیق برای ثبت در فراز SMS", 'FarazSMS pattern registration guidance is missing from the panel.'],
    [$panel, "bot.blluepanel.ir", 'Registered IranPayamak system guidance is missing from the SMS panel.'],
    [$farazDocs, "bot.blluepanel.ir", 'IranPayamak system selection must be documented.'],
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
    [$bot, 'update("user", "verify", "1", "id", $from_id);', 'Successful phone verification must satisfy account verification.'],
    [$farazDocs, "phone_verification", 'FarazSMS pattern documentation is missing.'],
    [$farazDocs, "code`: number, max 6", 'OTP variable type/length is not documented.'],
    [$panel, "sms-var-type", 'SMS panel variable type badges are missing.'],
    [$langFa, "'confirming' => '🔐 <b>تأیید شماره موبایل</b>", 'Persian phone verification prompt is missing.'],
];

foreach ($checks as [$source, $needle, $message]) {
    if ($source === '' || !str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

if (str_contains($langFa, "'confirming' => '🔐 <b>تأیید شماره موبایل</b>\\n")) {
    $failures[] = 'Phone verification prompt must not render literal \\n sequences.';
}

if (str_contains($sms, 'REQUIRED_PATTERN_SIGNATURE')) {
    $failures[] = 'Approved provider patterns must not require the registered system domain inside pattern text.';
}
if (!str_contains($sms, "\$row['system']") || !str_contains($sms, "\$row['domain']")) {
    $failures[] = 'Provider system/domain metadata must be preserved when present.';
}

$publicGatePos = strpos($bot, "in_array(\$bluePanelRequestMethod, ['GET', 'HEAD'], true)");
$configRequirePos = strpos($bot, "require_once __DIR__ . '/config.php'");
if ($publicGatePos === false || $configRequirePos === false || $publicGatePos > $configRequirePos) {
    $failures[] = 'Public provider verification GET/HEAD gate must run before loading bot configuration.';
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
    $samples = BluebotSms::sampleParams($eventKey);
    foreach ((array) ($eventSpec['vars'] ?? []) as $sampleVar) {
        $sampleName = (string) ($sampleVar['name'] ?? '');
        if ($sampleName !== '' && !array_key_exists($sampleName, $samples)) {
            $failures[] = "SMS test sample is missing variable {$sampleName} for {$eventKey}.";
        }
        if (($sampleVar['type'] ?? '') === 'number' && isset($samples[$sampleName]) && !preg_match('/^\d+$/', (string) $samples[$sampleName])) {
            $failures[] = "SMS numeric test sample must contain digits only for {$eventKey}.{$sampleName}.";
        }
    }
    $body = (string) ($eventSpec['body'] ?? '');
    if (!str_starts_with($body, "BlueVPN | بلو پنل\n")) {
        $failures[] = "SMS body must start with the approved BlueVPN / Blue Panel branding and a real newline: {$eventKey}";
    }
    if (str_contains($body, '\\n')) {
        $failures[] = "SMS body contains a literal \\n instead of a real newline: {$eventKey}";
    }
    if (stripos($body, 'BlueBot') !== false) {
        $failures[] = "Customer-facing SMS body still contains BlueBot branding: {$eventKey}";
    }

    $declaredVars = [];
    foreach ((array) ($eventSpec['vars'] ?? []) as $variable) {
        $type = (string) ($variable['type'] ?? '');
        if (!in_array($type, ['string', 'number'], true)) {
            $failures[] = "Unsupported SMS variable type in {$eventKey}: {$type}";
        }
        $name = (string) ($variable['name'] ?? '');
        if ($name !== '') {
            $declaredVars[] = $name;
        }
    }

    preg_match_all('/%([A-Za-z0-9_-]+)%/', $body, $matches);
    $bodyVars = array_values(array_unique($matches[1] ?? []));
    sort($bodyVars, SORT_STRING);
    sort($declaredVars, SORT_STRING);
    if ($bodyVars !== $declaredVars) {
        $failures[] = "SMS placeholders do not match declared variables in {$eventKey}.";
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
