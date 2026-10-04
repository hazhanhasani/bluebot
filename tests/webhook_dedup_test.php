<?php

declare(strict_types=1);

// Execute the real botapi include and index authentication section in a fresh
// PHP process per request. Only database and Telegram network effects are stubbed.
$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/bluebot-webhook-test-' . bin2hex(random_bytes(6));
mkdir($sandbox, 0700);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$section = static function (string $source, string $start, string $end): string {
    $offset = strpos($source, $start);
    $limit = $offset === false ? false : strpos($source, $end, $offset + strlen($start));
    if ($offset === false || $limit === false) {
        throw new RuntimeException('Could not locate webhook test source section.');
    }
    return substr($source, $offset, $limit - $offset);
};

try {
    copy($root . '/botapi.php', $sandbox . '/botapi.php');
    copy($root . '/src/Support/TrustedProxy.php', $sandbox . '/TrustedProxy.php');
    file_put_contents($sandbox . '/config.php', "<?php\n");
    file_put_contents($sandbox . '/auth.php', "<?php\n" . $section(
        (string) file_get_contents($root . '/index.php'),
        '#-----------telegram_webhook_auth------------#',
        'if ($is_bot)'
    ));
    file_put_contents($sandbox . '/secret_helpers.php', "<?php\n" . $section(
        (string) file_get_contents($root . '/function.php'),
        'function checktelegramip()',
        'function bluebotSetMainWebhook('
    ));
    file_put_contents($sandbox . '/request.php', <<<'PHP'
<?php
$scenario = json_decode(base64_decode($argv[1]), true);
$_SERVER = $scenario['server'];
$_GET = $scenario['query'];
$setting = ['webhook_secret' => $scenario['secret']];
$requestBody = json_encode(['update_id' => $scenario['id']]);
$events = [];

// php://input is empty under CLI. Supply an HTTP request body without changing
// production code; stdout/stderr remain the already-open CLI descriptors.
class WebhookTestInput
{
    public $context;
    private string $body = '';
    private int $offset = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        if ($path !== 'php://input') {
            throw new RuntimeException('Unexpected PHP stream: ' . $path);
        }
        $this->body = $GLOBALS['requestBody'];
        return true;
    }

    public function stream_read($count): string
    {
        $chunk = substr($this->body, $this->offset, $count);
        $this->offset += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen($this->body);
    }

    public function stream_stat(): array
    {
        return [];
    }
}

function selectValue(...$args) { return ''; }
function bluebotAdoptRuntimeIdentity(...$args) { $GLOBALS['events'][] = 'identity'; }
function ensureWebhookSecret()
{
    $GLOBALS['events'][] = 'bootstrap';
    return ['secret' => 'test-secret', 'created' => true];
}
function bluebotSetMainWebhook($secret) { $GLOBALS['events'][] = 'migrate'; return true; }

require __DIR__ . '/TrustedProxy.php';
require __DIR__ . '/secret_helpers.php';
stream_wrapper_unregister('php');
stream_wrapper_register('php', WebhookTestInput::class);
require __DIR__ . '/botapi.php';
if ($scenario['mode'] === 'include') {
    echo 'included';
    exit;
}
require __DIR__ . '/auth.php';
echo json_encode(['processed' => true, 'events' => $events]);
PHP
    );

    $request = static function (
        int $id,
        array $server = [],
        string $secret = 'test-secret',
        array $query = [],
        string $mode = 'webhook'
    ) use ($sandbox, $assert): string {
        $scenario = base64_encode((string) json_encode([
            'id' => $id,
            'server' => $server + ['REMOTE_ADDR' => '203.0.113.10'],
            'secret' => $secret,
            'query' => $query,
            'mode' => $mode,
        ]));
        $pipes = [];
        $command = [PHP_BINARY];
        $iniFile = php_ini_loaded_file();
        if ($iniFile !== false) {
            array_push($command, '-c', $iniFile);
        }
        array_push($command, '-d', 'display_errors=stderr', $sandbox . '/request.php', $scenario);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch webhook regression request.');
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $assert($exitCode === 0 && $errors === '', "Request $id failed: $errors (exit $exitCode).");
        return $output;
    };
    $cache = static function () use ($sandbox): array {
        $file = $sandbox . '/storage/cache/recent_updates.json';
        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    };
    $processed = static function (string $output, array $events): bool {
        return json_decode($output, true) === ['processed' => true, 'events' => $events];
    };
    $header = ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'test-secret'];
    $telegram = ['REMOTE_ADDR' => '149.154.167.50'];

    $assert($request(81001) === 'Unauthorized access', 'An unauthenticated update must be rejected.');
    $assert($cache() === [], 'Rejected requests must not create or populate the update cache.');
    $assert($processed($request(81001, $header), ['identity']), 'A valid update must survive a guessed-ID poisoning attempt.');
    $assert(isset($cache()[81001]), 'An authenticated update must be recorded.');
    $before = $cache();
    $assert($request(81001, $header) === '', 'An authenticated duplicate must be acknowledged without processing.');
    $assert($request(81001) === 'Unauthorized access', 'Even a cached update ID must pass authentication.');
    $assert($cache() === $before, 'Retries and rejected requests must leave existing cache entries unchanged.');

    $assert($request(81002, ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'wrong']) === 'Unauthorized access', 'An incorrect secret must be rejected.');
    $assert(!isset($cache()[81002]), 'An incorrect secret must not reserve an update ID.');
    $assert($processed($request(81002, $header), ['identity']), 'A valid secret must permit the previously rejected update ID.');

    $assert($processed($request(81003, $telegram), ['identity', 'migrate']), 'A verified Telegram IP must retain legacy webhook migration.');
    $assert($request(81003, $telegram) === '', 'Telegram IP retries must still be deduplicated.');
    $assert($processed($request(81004, [], 'test-secret', ['secret' => 'test-secret']), ['identity', 'migrate']), 'A valid legacy query secret must still migrate and process.');

    $assert($request(81005, [], '') === 'Unauthorized access', 'Secret bootstrap must reject non-Telegram IPs.');
    $assert(!isset($cache()[81005]), 'Rejected bootstrap requests must not poison the update cache.');
    $assert($processed($request(81005, $telegram, ''), ['identity', 'bootstrap']), 'A first-time Telegram webhook must bootstrap and process its update.');
    $assert($request(81005, $telegram, '') === '', 'No-secret Telegram retries must be deduplicated after authentication.');

    $before = $cache();
    $assert($request(81001, [], 'test-secret', [], 'include') === 'included', 'API/payment includes must not exit for a previously handled update ID.');
    $assert($request(81006, [], 'test-secret', [], 'include') === 'included', 'API/payment includes must continue for a new update ID.');
    $assert($cache() === $before, 'API/payment includes must not consume Telegram update IDs.');
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($sandbox);
}

if ($failures !== []) {
    fwrite(STDERR, "Webhook authentication/deduplication tests failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Webhook authentication and deduplication tests OK.\n";
