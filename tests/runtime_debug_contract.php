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
$notifications = $read('cronbot/NotificationsService.php');
$bulkMessages = $read('cronbot/sendmessage.php');
$uptimeNode = $read('cronbot/uptime_node.php');
$uptimePanel = $read('cronbot/uptime_panel.php');
$dailyStatus = $read('cronbot/statusday.php');
$cronCard = $read('cronbot/croncard.php');
$cronPlisio = $read('cronbot/plisio.php');
$cronIranpay = $read('cronbot/iranpay1.php');
$admin = $read('admin.php');
$iranpay1 = $read('payment/iranpay1.php');
$zarinpal = $read('payment/zarinpal.php');

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
    [$admin, "bluebotAudit('admin.user_transfer'", 'Admin account transfers must be audited.'],
    [$admin, "['sms_otp_challenges', 'user_id']", 'Admin account transfer must move OTP ownership.'],
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

if ($failures !== []) {
    fwrite(STDERR, "Runtime debug contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Runtime debug contract OK.\n";
