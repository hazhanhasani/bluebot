<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $path) use ($root): string {
    $value = @file_get_contents($root . '/' . $path);
    return is_string($value) ? $value : '';
};

$botapi = $read('botapi.php');
$functions = $read('function.php');
$index = $read('index.php');
$table = $read('table.php');
$rootHtaccess = $read('.htaccess');
$panelConfig = $read('panel/inc/config.php');
$panelLogin = $read('panel/login.php');
$vpnDefaultBotapi = $read('vpnbot/Default/botapi.php');
$vpnUpdateBotapi = $read('vpnbot/update/botapi.php');
$vpnDefaultAdmin = $read('vpnbot/Default/admin.php');
$vpnUpdateAdmin = $read('vpnbot/update/admin.php');

$checks = [
    [$botapi, 'curl_close($ch);', 'Main Telegram client must close cURL handles.'],
    [$functions, "function_exists('bluebotVerifyPanelTls')", 'Subscription fetcher must follow the panel TLS policy.'],
    [$functions, 'CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0', 'Subscription fetcher TLS hostname policy is missing.'],
    [$functions, 'CURLOPT_SSL_VERIFYPEER, $verifyTls', 'Subscription fetcher TLS peer policy is missing.'],
    [$functions, "'url' => \"https://\$host/index.php\"", 'Main webhook URL must not expose the secret in the query string.'],
    [$functions, 'function webhookHeaderSecretMatches', 'Header-only Telegram webhook verification helper is missing.'],
    [$index, '$telegramHeaderSecretAllowed', 'Main webhook migration must distinguish header authentication.'],
    [$table, "PHP_SAPI !== 'cli'", 'Database/schema bootstrap must not be web-accessible.'],
    [$table, 'bluebotSetMainWebhook($webhookSecret)', 'Database bootstrap must configure the protected webhook helper.'],
    [$rootHtaccess, 'RewriteRule ^(?:db|src|tests|scripts|vendor)(?:/|$) - [F,L,NC]', 'Internal runtime directories must be blocked from direct HTTP access.'],
    [$rootHtaccess, 'RewriteRule ^cronbot/(?!run\\.php$).*\\.php$ - [F,L,NC]', 'Individual cron jobs must not be directly web-triggerable.'],
    [$functions, 'function resolveInvitationOwnerId', 'Referral payload resolver is missing.'],
    [$functions, 'function isValidInvitationCode($setting, $fromId, $verifyStatus, ?string $inviterId = null): bool', 'Referral verification must require a resolved inviter.'],
    [$index, '$affiliatesid = resolveInvitationOwnerId($affiliatesPayload);', 'Referral payload must be resolved before verification.'],
    [$index, 'isValidInvitationCode($setting, $from_id, $user[\'verify\'], $affiliatesid);', 'Referral verification must receive the resolved inviter.'],
    [$panelConfig, 'function bluebotPanelIsHttps', 'Panel HTTPS proxy detection is missing.'],
    [$panelConfig, 'function bluebotPanelClientIp', 'Panel trusted client IP resolution is missing.'],
    [$panelConfig, "hash('sha256', \$ip)", 'Panel login rate-limit filenames must not use weak hashes.'],
    [$panelConfig, 'flock($handle, LOCK_EX)', 'Panel login rate limiter must serialize concurrent attempts.'],
    [$panelConfig, 'ftruncate($handle, 0)', 'Panel login rate limiter must update its locked state atomically.'],
    [$panelLogin, '$ip = bluebotPanelClientIp();', 'Panel login rate limit must use the resolved client IP.'],
    [$vpnDefaultBotapi, 'curl_close($ch);', 'Default VPNBot Telegram client must close cURL handles.'],
    [$vpnUpdateBotapi, 'curl_close($ch);', 'Update VPNBot Telegram client must close cURL handles.'],
    [$vpnDefaultAdmin, '__BLUEBOT_UPDATE_COPY_OK__', 'Default VPNBot updater must verify file copy success.'],
    [$vpnUpdateAdmin, '__BLUEBOT_UPDATE_COPY_OK__', 'Update VPNBot updater must verify file copy success.'],
    [$vpnDefaultAdmin, 'escapeshellarg(rtrim($source', 'Default VPNBot updater must shell-escape source paths.'],
    [$vpnUpdateAdmin, 'escapeshellarg(rtrim($source', 'Update VPNBot updater must shell-escape source paths.'],
];

foreach ($checks as [$source, $needle, $message]) {
    if ($source === '' || !str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

if (str_contains($functions, '"https://$host/index.php?secret=$secret"')) {
    $failures[] = 'Webhook secret must never be embedded in the webhook URL.';
}
if (str_contains($table, '?secret=') || str_contains($table, "'secret' =>")) {
    $failures[] = 'table.php must not embed the webhook secret in callback URLs or query parameters.';
}

$legacyReferralBypassNeedle = 'isValidInvitationCode($setting, $from_id, $user[\'verify\']);';
if (str_contains($index, $legacyReferralBypassNeedle)) {
    $failures[] = 'Unresolved /start payloads must never grant account verification.';
}

if (preg_match('/function\s+outputlink\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s', $functions, $match)) {
    $body = (string) ($match['body'] ?? '');
    if (!str_contains($body, "getenv('BLUEBOT_VERIFY_PANEL_TLS') === '1'")
        || !str_contains($body, 'CURLOPT_SSL_VERIFYPEER, $verifyTls')) {
        $failures[] = 'outputlink() must use the explicit panel TLS compatibility policy.';
    }
} else {
    $failures[] = 'outputlink() could not be inspected.';
}

foreach ([
    'vpnbot/Default/botapi.php' => $vpnDefaultBotapi,
    'vpnbot/update/botapi.php' => $vpnUpdateBotapi,
] as $path => $source) {
    if (str_contains($source, 'var_dump(curl_error(')) {
        $failures[] = "{$path} must not dump transport errors to users.";
    }
}

foreach ([
    'vpnbot/Default/admin.php' => $vpnDefaultAdmin,
    'vpnbot/update/admin.php' => $vpnUpdateAdmin,
] as $path => $source) {
    if (str_contains($source, '$command = "cp -r $source/* $destination 2>&1";')) {
        $failures[] = "{$path} contains the unsafe legacy updater copy command.";
    }
}

$dummyNeedle = '$dummyHash = \'$2y$12$Wq0LwnFpxb6NMZ4lEZYxmeiGT9QJBFzEZZJtgUg2.JHpVTrLeJ5Na\';';
if (!str_contains($panelLogin, $dummyNeedle)) {
    $failures[] = 'Panel login dummy bcrypt hash must be a valid cost-12 bcrypt hash.';
}

if ($failures !== []) {
    fwrite(STDERR, "Security hardening contract failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "Security hardening contract OK.\n";
