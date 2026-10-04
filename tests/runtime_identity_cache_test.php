<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/bluebot-identity-' . bin2hex(random_bytes(8));
mkdir($fixture . '/src/Support', 0700, true);
copy($root . '/src/Support/RuntimeIdentity.php', $fixture . '/src/Support/RuntimeIdentity.php');
require $fixture . '/src/Support/RuntimeIdentity.php';

$APIKEY = '123456789:identity_cache_test';
$domainhosts = 'old.example.com';
$usernamebot = 'OldExampleBot';
$_SERVER = ['HTTPS' => 'on', 'HTTP_HOST' => 'active.example.com'];
$telegramCalls = 0;
$telegramResponse = ['ok' => true, 'result' => ['username' => 'NewExampleBot']];

function telegram(string $method): array
{
    global $telegramCalls, $telegramResponse;
    if ($method !== 'getMe') {
        throw new RuntimeException('Unexpected Telegram method.');
    }
    $telegramCalls++;
    return $telegramResponse;
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$seed = static function (array $data): void {
    $path = bluebotRuntimeIdentityCachePath();
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
};

try {
    // A legacy cache's updated_at was refreshed by traffic and cannot establish
    // when its username was actually verified with Telegram.
    $seed([
        'bot_key' => bluebotRuntimeBotKey(),
        'domain' => 'old.example.com',
        'username' => 'OldExampleBot',
        'updated_at' => time(),
    ]);
    $identity = bluebotAdoptRuntimeIdentity(true);
    $assert($telegramCalls === 1, 'Legacy caches must refresh the bot username once.');
    $assert($identity['username'] === 'NewExampleBot', 'Telegram rename was not adopted.');
    $checkedAt = (int) (bluebotReadRuntimeIdentity()['telegram_checked_at'] ?? 0);
    $assert($checkedAt > 0, 'Successful getMe must store its verification timestamp.');

    for ($request = 0; $request < 3; $request++) {
        bluebotAdoptRuntimeIdentity(true);
    }
    $assert($telegramCalls === 1, 'Fresh usernames must not call getMe for every webhook.');
    $assert(bluebotReadRuntimeIdentity()['telegram_checked_at'] === $checkedAt, 'Traffic must not extend username freshness.');

    $expiredAt = time() - 21601;
    $cached = bluebotReadRuntimeIdentity();
    $cached['telegram_checked_at'] = $expiredAt;
    $cached['telegram_attempted_at'] = $expiredAt;
    $seed($cached);
    bluebotAdoptRuntimeIdentity(false);
    $assert(bluebotReadRuntimeIdentity()['telegram_checked_at'] === $expiredAt, 'A host-only cache write must preserve the expired verification time.');
    $telegramResponse['result']['username'] = 'RenamedExampleBot';
    $identity = bluebotAdoptRuntimeIdentity(true);
    $assert($telegramCalls === 2, 'An expired username must refresh despite intervening traffic.');
    $assert($identity['username'] === 'RenamedExampleBot', 'Expired username refresh failed.');

    $cached = bluebotReadRuntimeIdentity();
    $cached['telegram_checked_at'] = $expiredAt;
    $cached['telegram_attempted_at'] = $expiredAt;
    $seed($cached);
    $telegramResponse = ['ok' => false, 'description' => 'Temporary failure'];
    bluebotAdoptRuntimeIdentity(true);
    $assert(bluebotReadRuntimeIdentity()['telegram_checked_at'] === $expiredAt, 'A failed getMe must not make stale identity fresh.');
    bluebotAdoptRuntimeIdentity(true);
    $assert($telegramCalls === 3, 'A failed getMe must not add a timeout to every incoming webhook.');
    $cached = bluebotReadRuntimeIdentity();
    $cached['telegram_attempted_at'] = time() - 61;
    $seed($cached);
    $telegramResponse = ['ok' => true, 'result' => ['username' => 'RecoveredExampleBot']];
    $identity = bluebotAdoptRuntimeIdentity(true);
    $assert($telegramCalls === 4 && $identity['username'] === 'RecoveredExampleBot', 'Identity refresh must recover after a failed getMe.');

    $APIKEY = '987654321:changed_identity_cache_token';
    bluebotAdoptRuntimeIdentity(true);
    $assert($telegramCalls === 5, 'A changed token must not reuse another bot\'s fresh identity.');
    echo "Runtime identity cache tests OK.\n";
} finally {
    @unlink(bluebotRuntimeIdentityCachePath());
    @rmdir($fixture . '/storage/cache');
    @rmdir($fixture . '/storage');
    @unlink($fixture . '/src/Support/RuntimeIdentity.php');
    @rmdir($fixture . '/src/Support');
    @rmdir($fixture . '/src');
    @rmdir($fixture);
}
