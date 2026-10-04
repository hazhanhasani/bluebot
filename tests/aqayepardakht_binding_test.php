<?php

declare(strict_types=1);

// Execute the production callback guard and checkout insert with stand-ins for
// database/network services. Each callback runs separately so exit() is tested.
function aqayeTestRegion(string $source, string $start, string $end, int $offset = 0): string
{
    $first = strpos($source, $start, $offset);
    $last = $first === false ? false : strpos($source, $end, $first);
    if ($first === false || $last === false) {
        throw new RuntimeException('AqayePardakht production region not found.');
    }
    return substr($source, $first, $last - $first);
}

if (in_array($argv[1] ?? '', ['callback', 'create'], true)) {
    $fixture = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $result = ['ready' => false, 'logs' => [], 'inserts' => []];
    http_response_code(200);
    register_shutdown_function(static function () use (&$result): void {
        $result['http_status'] = http_response_code();
        echo "\nAQAYE_RESULT:" . json_encode($result, JSON_UNESCAPED_UNICODE);
    });

    function bluebotLog($level, $message, $context): void
    {
        $GLOBALS['result']['logs'][] = $message;
    }
    function select($table, ...$args): array
    {
        return $table === 'Payment_report' ? $GLOBALS['fixture']['payment'] : [];
    }
    function getPaySettingValue($key, $default = ''): string
    {
        return 'test-merchant';
    }
    function createPayaqayepardakht($price, $order): array
    {
        return $GLOBALS['fixture']['response'];
    }
    function sendmessage(...$args): void {}
    function step(...$args): void {}

    if ($argv[1] === 'callback') {
        $_POST = $fixture['post'];
        $textbotlang = ['paymentGateway' => ['statusFailed' => 'failed']];
        $source = file_get_contents(dirname(__DIR__) . '/payment/aqayepardakht.php');
        eval(aqayeTestRegion($source, '$invoice_id =', '$verified = false;'));
        $result['ready'] = true;
        $result['payload'] = json_decode($payload, true);
    } else {
        $pdo = new class {
            public function prepare(string $sql): object
            {
                return new class($sql) {
                    public function __construct(private string $sql) {}
                    public function execute(array $parameters): void
                    {
                        $GLOBALS['result']['inserts'][] = ['sql' => $this->sql, 'parameters' => $parameters];
                    }
                };
            }
        };
        $from_id = 'test-buyer';
        $user = ['Processing_value' => 5000, 'Processing_value_tow' => 'getbalance', 'Processing_value_one' => 'test'];
        $textbotlang = ['users' => ['Balance' => ['errorLinkPayment' => 'failed']],
            'Admin' => ['reportgroup' => ['errorAqayePardakhtLink' => '%s %s %s']]];
        $keyboard = null;
        $username = 'buyer';
        $setting = ['Channel_Report' => ''];
        $source = file_get_contents(dirname(__DIR__) . '/index.php');
        $route = strpos($source, 'elseif ($datain == "aqayepardakht")');
        if ($route === false) {
            throw new RuntimeException('AqayePardakht checkout route not found.');
        }
        eval(aqayeTestRegion($source, '$invoice =', '$paymentkeyboard =', $route));
        $result['ready'] = true;
    }
    exit;
}

function aqayeTestRun(string $mode, array $fixture): array
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, $mode, base64_encode(json_encode($fixture))],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start payment regression harness.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $marker = strrpos($output, 'AQAYE_RESULT:');
    if ($code !== 0 || $errors !== '' || $marker === false) {
        throw new RuntimeException('Payment harness failed: ' . $errors . $output);
    }
    return json_decode(substr($output, $marker + strlen('AQAYE_RESULT:')), true, 512, JSON_THROW_ON_ERROR);
}

function aqayeTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$payment = ['Payment_Method' => 'aqayepardakht', 'payment_Status' => 'Unpaid',
    'price' => 5000, 'dec_not_confirmed' => 'transaction-for-order-a'];
$post = ['invoice_id' => 'order-a', 'transid' => 'transaction-for-order-a'];
$matching = aqayeTestRun('callback', ['payment' => $payment, 'post' => $post]);
aqayeTestAssert($matching['ready'] && $matching['http_status'] === 200,
    'A bound transaction must reach verification.');
aqayeTestAssert($matching['payload']['transid'] === $payment['dec_not_confirmed'],
    'Verification must use the transaction belonging to the selected order.');

$post['transid'] = 'genuine-paid-transaction-for-order-b';
$mismatch = aqayeTestRun('callback', ['payment' => $payment, 'post' => $post]);
aqayeTestAssert(!$mismatch['ready'] && $mismatch['http_status'] === 400 && count($mismatch['logs']) === 1,
    'A paid transaction for another same-price order must be rejected and logged before verification.');

foreach ([null, '', '   '] as $unbound) {
    $payment['dec_not_confirmed'] = $unbound;
    $legacy = aqayeTestRun('callback', ['payment' => $payment, 'post' => $post]);
    aqayeTestAssert(!$legacy['ready'] && $legacy['http_status'] === 409 && count($legacy['logs']) === 1,
        'Legacy invoices without a transaction binding must fail closed and be logged.');
}

$creation = aqayeTestRun('create', ['response' => ['status' => 'success', 'transid' => 'created-transaction']]);
aqayeTestAssert(count($creation['inserts']) === 1, 'Successful checkout must persist its payment report.');
$insert = $creation['inserts'][0];
preg_match('/Payment_report\s*\(([^)]+)\)/', $insert['sql'], $columns);
$row = array_combine(array_map('trim', explode(',', $columns[1])), $insert['parameters']);
aqayeTestAssert($row['dec_not_confirmed'] === 'created-transaction' && $row['Payment_Method'] === 'aqayepardakht',
    'Checkout must bind the exact created gateway transaction to its local order.');

foreach ([null, '', [], str_repeat('x', 256)] as $invalid) {
    $creation = aqayeTestRun('create', ['response' => ['status' => 'success', 'transid' => $invalid]]);
    aqayeTestAssert($creation['inserts'] === [], 'Checkout must reject a missing or invalid gateway transaction ID.');
}

echo "AqayePardakht transaction binding tests OK.\n";
