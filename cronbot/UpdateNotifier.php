<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/function.php';
require_once dirname(__DIR__) . '/botapi.php';
require_once dirname(__DIR__) . '/src/Support/UpdateManager.php';

$settings = bluebotUpdateSettings();
$channel = bluebotUpdateChannel($settings);
$target = bluebotUpdateLatest($channel);

if ($target === null || !bluebotUpdateAvailable($target, $settings)) {
    return;
}

$ref = trim((string) ($target['ref'] ?? ''));
if ($ref === '' || hash_equals((string) ($settings['update_last_notified'] ?? ''), $ref)) {
    return;
}

$channelLabels = [
    'release' => 'Stable',
    'beta' => 'Beta',
    'auto' => 'Auto',
];

$label = htmlspecialchars((string) ($target['label'] ?? $ref), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$current = htmlspecialchars(bluebotUpdateCurrentVersion(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$summaryRaw = trim((string) ($target['summary'] ?? ''));
$summary = $summaryRaw !== ''
    ? "\n\n📝 " . htmlspecialchars($summaryRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    : '';

$text = "🔔 <b>نسخه جدید BlueBot منتشر شد</b>\n\n"
    . "📦 کانال: <b>" . ($channelLabels[$channel] ?? $channel) . "</b>\n"
    . "🔹 نسخه نصب‌شده: <code>{$current}</code>\n"
    . "🆕 نسخه جدید: <code>{$label}</code>"
    . $summary
    . "\n\nبرای بروزرسانی امن، دکمه زیر را بزنید.";

$keyboard = json_encode([
    'inline_keyboard' => [
        [
            ['text' => '🚀 بروزرسانی', 'callback_data' => 'bluebot_update_run'],
            ['text' => '🔍 بررسی', 'callback_data' => 'bluebot_update_status'],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$adminIds = select('admin', 'id_admin', null, null, 'FETCH_COLUMN', ['cache' => false]);
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
