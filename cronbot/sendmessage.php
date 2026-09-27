<?php
chdir(__DIR__);
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

$textbotlang = languagechange();

$infoPath = __DIR__ . '/info';
$usersPath = __DIR__ . '/users.json';

if (!is_file($infoPath) || !is_file($usersPath)) {
    return;
}

$info = json_decode((string) @file_get_contents($infoPath), true);
if (!is_array($info)
    || empty($info['id_admin'])
    || empty($info['id_message'])
    || empty($info['type'])) {
    bluebotLog('warning', 'Bulk-message state file is invalid');
    return;
}

$queueHandle = @fopen($usersPath, 'c+');
if ($queueHandle === false) {
    bluebotLog('warning', 'Unable to open bulk-message queue');
    return;
}

if (!flock($queueHandle, LOCK_EX)) {
    fclose($queueHandle);
    bluebotLog('warning', 'Unable to lock bulk-message queue');
    return;
}

rewind($queueHandle);
$queueRaw = stream_get_contents($queueHandle);
$userid = json_decode(is_string($queueRaw) ? $queueRaw : '', false);
if (!is_array($userid)) {
    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    bluebotLog('warning', 'Bulk-message queue JSON is invalid');
    return;
}

$finishQueue = static function () use ($queueHandle, $usersPath, $infoPath, $info, $textbotlang): void {
    deletemessage($info['id_admin'], $info['id_message']);
    sendmessage(
        $info['id_admin'],
        $textbotlang['Admin']['messageBulk']['done'],
        null,
        'HTML'
    );

    flock($queueHandle, LOCK_UN);
    fclose($queueHandle);
    @unlink($infoPath);
    @unlink($usersPath);
};

if ($userid === []) {
    $finishQueue();
    return;
}

$countRemaining = count($userid);
$textProcess = sprintf(
    $textbotlang['Admin']['messageBulk']['progress'],
    $countRemaining
);

$cancelMessage = json_encode([
    'inline_keyboard' => [
        [
            [
                'text' => $textbotlang['keyboard']['cancelOperation'],
                'callback_data' => 'cancel_sendmessage',
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

Editmessagetext(
    $info['id_admin'],
    $info['id_message'],
    $textProcess,
    $cancelMessage
);

$keyboards = [
    'buy' => json_encode([
        'inline_keyboard' => [[
            ['text' => $textbotlang['textbot']['sell'], 'callback_data' => 'buy'],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'start' => json_encode([
        'inline_keyboard' => [[
            ['text' => $textbotlang['keyboard']['start'], 'callback_data' => 'start'],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'usertestbtn' => json_encode([
        'inline_keyboard' => [[
            ['text' => $textbotlang['textbot']['userTest'], 'callback_data' => 'usertestbtn'],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'helpbtn' => json_encode([
        'inline_keyboard' => [[
            ['text' => $textbotlang['textbot']['help'], 'callback_data' => 'helpbtn'],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'affiliatesbtn' => json_encode([
        'inline_keyboard' => [[
            ['text' => $textbotlang['textbot']['affiliates'], 'callback_data' => 'affiliatesbtn'],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'addbalance' => json_encode([
        'inline_keyboard' => [[
            ['text' => $textbotlang['textbot']['addBalance'], 'callback_data' => 'Add_Balance'],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
];

$getUserId = static function ($entry): ?string {
    if (is_object($entry) && isset($entry->id)) {
        $id = trim((string) $entry->id);
    } elseif (is_array($entry) && isset($entry['id'])) {
        $id = trim((string) $entry['id']);
    } else {
        return null;
    }

    return $id !== '' ? $id : null;
};

for ($i = 0; $i < 20 && $userid !== []; $i++) {
    $entry = array_shift($userid);
    $userId = $getUserId($entry);
    if ($userId === null) {
        continue;
    }

    $type = (string) ($info['type'] ?? '');
    $pinRequested = (string) ($info['pingmessage'] ?? '') === 'yes';

    if ($type === 'unpinmessage') {
        unpinmessage($userId);
        continue;
    }

    $response = null;

    if ($type === 'sendmessage' || $type === 'xdaynotmessage') {
        $buttonType = (string) ($info['btnmessage'] ?? 'none');
        $keyboard = $buttonType === 'none'
            ? null
            : ($keyboards[$buttonType] ?? null);

        $response = sendmessage(
            $userId,
            (string) ($info['message'] ?? ''),
            $keyboard,
            'HTML'
        );

        $blocked = is_array($response)
            && empty($response['ok'])
            && (string) ($response['description'] ?? '') === 'Forbidden: bot was blocked by the user';

        if ($blocked) {
            $invoiceCount = select('invoice', '*', 'id_user', $userId, 'count');
            $userInfo = select('user', 'Balance', 'id', $userId, 'select');

            if ((int) $invoiceCount === 0
                && is_array($userInfo)
                && (int) ($userInfo['Balance'] ?? 0) === 0) {
                $stmt = $pdo->prepare('DELETE FROM user WHERE id = :id');
                $stmt->execute([':id' => $userId]);
                clearSelectCache('user');
            }
        }
    } elseif ($type === 'forwardmessage') {
        $response = forwardMessage(
            $info['id_admin'],
            $info['message'] ?? '',
            $userId
        );
    }

    if ($pinRequested
        && is_array($response)
        && !empty($response['ok'])
        && !empty($response['result']['message_id'])) {
        pinmessage($userId, $response['result']['message_id']);
    }
}

rewind($queueHandle);
ftruncate($queueHandle, 0);
$encodedQueue = json_encode(array_values($userid), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encodedQueue === false || fwrite($queueHandle, $encodedQueue) === false) {
    bluebotLog('error', 'Unable to persist bulk-message queue');
}
fflush($queueHandle);

$queueFinished = $userid === [];
flock($queueHandle, LOCK_UN);
fclose($queueHandle);

if ($queueFinished) {
    deletemessage($info['id_admin'], $info['id_message']);
    sendmessage(
        $info['id_admin'],
        $textbotlang['Admin']['messageBulk']['done'],
        null,
        'HTML'
    );
    @unlink($infoPath);
    @unlink($usersPath);
}
