<?php

function mirza_cron_jobs(): array
{
    return [
        ['job' => 'croncard', 'schedule' => '*/1 * * * *', 'title' => 'تأیید خودکار رسید کارت به کارت'],
        ['job' => 'NotificationsService', 'schedule' => '*/1 * * * *', 'title' => 'ارسال اعلان‌های ربات'],
        ['job' => 'sms', 'schedule' => '*/1 * * * *', 'title' => 'صف پیامک و اعلان‌های سرویس'],
        ['job' => 'sendmessage', 'schedule' => '*/1 * * * *', 'title' => 'صف ارسال پیام همگانی'],
        ['job' => 'activeconfig', 'schedule' => '*/1 * * * *', 'title' => 'فعال‌سازی سرویس‌های خریداری‌شده'],
        ['job' => 'disableconfig', 'schedule' => '*/1 * * * *', 'title' => 'غیرفعال‌سازی سرویس‌های منقضی'],
        ['job' => 'iranpay1', 'schedule' => '*/1 * * * *', 'title' => 'پیگیری پرداخت‌های ایران‌پی'],
        ['job' => 'gift', 'schedule' => '*/2 * * * *', 'title' => 'پردازش کدهای هدیه'],
        ['job' => 'configtest', 'schedule' => '*/2 * * * *', 'title' => 'مدیریت سرویس‌های تست'],
        ['job' => 'plisio', 'schedule' => '*/3 * * * *', 'title' => 'پیگیری پرداخت‌های ارز دیجیتال'],
        ['job' => 'payment_expire', 'schedule' => '*/5 * * * *', 'title' => 'انقضای فاکتورهای پرداخت‌نشده'],
        ['job' => 'statusday', 'schedule' => '*/15 * * * *', 'title' => 'گزارش وضعیت روزانه'],
        ['job' => 'on_hold', 'schedule' => '*/15 * * * *', 'title' => 'سرویس‌های در حالت انتظار'],
        ['job' => 'uptime_node', 'schedule' => '*/15 * * * *', 'title' => 'پایش وضعیت نودها'],
        ['job' => 'uptime_panel', 'schedule' => '*/15 * * * *', 'title' => 'پایش وضعیت پنل‌ها'],
        ['job' => 'expireagent', 'schedule' => '*/30 * * * *', 'title' => 'انقضای اشتراک نمایندگان'],
        ['job' => 'UpdateNotifier', 'schedule' => '* * * * *', 'title' => 'بررسی نسخه جدید بلو پنل'],
        ['job' => 'backupbot', 'schedule' => '0 */5 * * *', 'title' => 'پشتیبان‌گیری ربات‌ساز'],
        ['job' => 'lottery', 'schedule' => '*/1 * * * *', 'title' => 'قرعه‌کشی و امتیازات'],
    ];
}

function mirza_cron_dispatcher_path(): string
{
    return __DIR__ . '/run.php';
}

function mirza_cron_stagger_seconds(string $seed = ''): int
{
    if ($seed === '') {
        $seed = dirname(__DIR__);
    }

    return (int) (sprintf('%u', crc32($seed)) % 20);
}

function mirza_cron_php_binary(): string
{
    $php = PHP_BINDIR . '/php';
    if (is_executable($php)) {
        return $php;
    }
    if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
        return PHP_BINARY;
    }
    if (is_executable('/usr/bin/php')) {
        return '/usr/bin/php';
    }

    return 'php';
}

function mirza_cron_dispatcher_command(string $seed = ''): string
{
    $sleep = mirza_cron_stagger_seconds($seed);
    $php = mirza_cron_php_binary();
    $path = mirza_cron_dispatcher_path();

    return '* * * * * sleep ' . $sleep . '; ' . $php . ' ' . $path . ' >/dev/null 2>&1';
}

function mirza_cron_http_secret_path(): string
{
    return dirname(__DIR__) . '/storage/cron-http.secret';
}

function mirza_cron_http_secret(bool $create = true): string
{
    $path = mirza_cron_http_secret_path();

    if (is_file($path) && is_readable($path)) {
        $secret = trim((string) @file_get_contents($path));
        if (preg_match('/^[a-f0-9]{64}$/', $secret)) {
            return $secret;
        }
    }

    if (!$create) {
        return '';
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return '';
    }

    try {
        $secret = bin2hex(random_bytes(32));
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        return '';
    }

    if (@file_put_contents($tmp, $secret . PHP_EOL, LOCK_EX) === false) {
        @unlink($tmp);
        return '';
    }

    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return '';
    }
    @chmod($path, 0640);

    return $secret;
}

function mirza_cron_http_authorized(array $server): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $secret = mirza_cron_http_secret(false);
    $provided = trim((string) ($server['HTTP_X_BLUEBOT_CRON_TOKEN'] ?? ''));

    return $secret !== ''
        && $provided !== ''
        && hash_equals($secret, $provided);
}

function mirza_cron_dispatcher_curl_command(string $baseUrl): string
{
    $secret = mirza_cron_http_secret(true);
    if ($secret === '') {
        return '';
    }

    $secretPath = escapeshellarg(mirza_cron_http_secret_path());
    $url = escapeshellarg(rtrim($baseUrl, '/') . '/cronbot/run.php');

    return '* * * * * token=$(cat ' . $secretPath
        . ' 2>/dev/null); [ -n "$token" ] && curl -fsS --max-time 55 '
        . '-H "X-BlueBot-Cron-Token: $token" ' . $url
        . ' >/dev/null 2>&1';
}
