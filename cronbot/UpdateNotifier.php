<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/function.php';
require_once dirname(__DIR__) . '/botapi.php';
require_once dirname(__DIR__) . '/src/Support/UpdateManager.php';

$settings = bluebotUpdateSettings();
$channel = bluebotUpdateChannel($settings);
$target = bluebotUpdateLatest($channel);
$adminIds = select('admin', 'id_admin', null, null, 'FETCH_COLUMN', ['cache' => false]);

if ($target === null) {
    if (bluebotUpdateSourceFailureShouldNotify($channel)) {
        $warning = "⚠️ <b>بررسی بروزرسانی بلو پنل ناموفق است</b>\n\n"
            . "منابع انتشار چند بار پیاپی در دسترس نبودند. "
            . "Cron همچنان هر دقیقه بررسی می‌کند و پس از برقراری ارتباط، اعلان نسخه جدید خودکار ارسال می‌شود.";

        $warningSent = false;
        foreach ((array) $adminIds as $adminId) {
            if (!is_numeric($adminId)) {
                continue;
            }

            $response = sendmessage((string) $adminId, $warning, null, 'HTML');
            if (is_array($response) && !empty($response['ok'])) {
                $warningSent = true;
            }
        }

        if ($warningSent) {
            bluebotUpdateMarkSourceFailureNotified($channel);
        }
    }
    return;
}

if (!bluebotUpdateAvailable($target, $settings)) {
    return;
}

$ref = trim((string) ($target['ref'] ?? ''));
if ($ref === '' || hash_equals((string) ($settings['update_last_notified'] ?? ''), $ref)) {
    return;
}

$channelLabels = [
    'release' => 'پایدار',
    'beta' => 'آزمایشی',
    'auto' => 'خودکار',
];

$label = htmlspecialchars((string) ($target['label'] ?? $ref), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$current = htmlspecialchars(bluebotUpdateCurrentVersion(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$summaryRaw = trim((string) ($target['summary'] ?? ''));
$summary = $summaryRaw !== ''
    ? "\n\n📝 " . htmlspecialchars($summaryRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    : '';

$text = "✨ <b>آپدیت جدید بلو پنل آماده است</b>\n\n"
    . "📦 کانال انتشار: <b>" . ($channelLabels[$channel] ?? $channel) . "</b>\n"
    . "📍 نسخه فعلی: <code>{$current}</code>\n"
    . "🚀 نسخه جدید: <code>{$label}</code>"
    . $summary
    . "\n\nنسخه تازه آماده نصب است. برای بروزرسانی امن و خودکار، دکمه «بروزرسانی» را بزنید.";

$keyboard = json_encode([
    'inline_keyboard' => [
        [
            ['text' => '🚀 بروزرسانی بلو پنل', 'callback_data' => 'bluebot_update_run'],
            ['text' => '🔍 جزئیات نسخه', 'callback_data' => 'bluebot_update_status'],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$sent = false;

foreach ((array) $adminIds as $adminId) {
    if (!is_numeric($adminId)) {
        continue;
    }

    $response = sendmessage((string) $adminId, $text, $keyboard, 'HTML');
    if (is_array($response) && !empty($response['ok'])) {
        $sent = true;
    }
}

if ($sent) {
    bluebotUpdateMarkNotified($ref);
}
