<?php

declare(strict_types=1);

require_once __DIR__ . '/support/source_function.php';
loadTestSourceFunction(dirname(__DIR__) . '/api/utils.php', 'validateTelegramInitDataForMiniApp');
loadTestSourceFunction(dirname(__DIR__) . '/api/miniapp.php', 'mini_authenticated_user');

$users = [
    '101' => ['id' => '101', 'token' => 'previous-account-token', 'User_Status' => 'Active'],
    '202' => ['id' => '202', 'token' => 'current-account-token', 'User_Status' => 'Active'],
    '404' => ['id' => '404', 'token' => 'blocked-account-token', 'User_Status' => 'block'],
];

function select(string $table, string $columns, string $field, string $value, string $mode): array|false
{
    foreach ($GLOBALS['users'] as $user) {
        if (($user[$field] ?? null) === $value) return $user;
    }
    return false;
}
function bluebotLog(string $level, string $message, array $context): void {}

function signedLaunch(string $userId, string $botToken, ?int $authDate = null): string
{
    $data = [
        'auth_date' => (string) ($authDate ?? time()),
        'query_id' => 'test-launch',
        'user' => json_encode(['id' => (int) $userId, 'first_name' => 'Test'], JSON_THROW_ON_ERROR),
    ];
    ksort($data, SORT_STRING);
    $lines = [];
    foreach ($data as $key => $value) $lines[] = $key . '=' . $value;
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $data['hash'] = hash_hmac('sha256', implode("\n", $lines), $secret);
    return http_build_query($data);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$botToken = '123456789:test-miniapp-auth-token';
$currentLaunch = signedLaunch('202', $botToken);
$user = mini_authenticated_user('previous-account-token', $currentLaunch, $botToken);
$assert(($user['id'] ?? '') === '202', 'A valid Telegram launch must not use a previous account\'s cached bearer.');
$assert((mini_authenticated_user('', $currentLaunch, $botToken)['id'] ?? '') === '202', 'Signed initData-only authentication failed.');
$assert(mini_authenticated_user('previous-account-token', signedLaunch('303', $botToken), $botToken) === null,
    'An unregistered signed launch must not fall back to another account\'s bearer.');
$assert((mini_authenticated_user('previous-account-token', signedLaunch('404', $botToken), $botToken)['User_Status'] ?? '') === 'block',
    'A blocked signed account must remain the authoritative identity for the existing block check.');

$expiredLaunch = signedLaunch('202', $botToken, time() - 86401);
$assert((mini_authenticated_user('current-account-token', $expiredLaunch, $botToken)['id'] ?? '') === '202',
    'Existing bearer sessions must work after Telegram launch data expires.');
$assert((mini_authenticated_user('current-account-token', '', $botToken)['id'] ?? '') === '202', 'Legacy bearer authentication failed.');
$assert(mini_authenticated_user('', $expiredLaunch, $botToken) === null, 'Expired launch data must not authenticate without a bearer.');
$assert(mini_authenticated_user('', $currentLaunch, 'wrong-bot-token') === null, 'Invalid Telegram signatures must not authenticate.');
$assert((mini_authenticated_user('current-account-token', $currentLaunch, 'wrong-bot-token')['id'] ?? '') === '202',
    'Invalid launch data must not invalidate an independently authenticated bearer session.');
$assert(mini_authenticated_user('invalid-token', '', $botToken) === null, 'Invalid bearer must not authenticate.');

echo "Mini App launch identity tests OK.\n";
