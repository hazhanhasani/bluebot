<?php

declare(strict_types=1);

final class DiagnosticsTestStatement extends PDOStatement
{
    public function __construct(private mixed $value)
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}

final class DiagnosticsTestPdo extends PDO
{
    public function __construct(public bool $failPaymentCheck = false)
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query === 'SELECT 1') {
            return new DiagnosticsTestStatement(1);
        }
        if (str_contains($query, 'FROM Payment_report')) {
            if ($this->failPaymentCheck) {
                throw new PDOException('Payment_report is unavailable.');
            }
            return new DiagnosticsTestStatement(str_contains($query, "'delivery_reviewed'") ? 3 : 0);
        }
        if (str_starts_with($query, 'SHOW TABLES LIKE')) {
            return new DiagnosticsTestStatement(false);
        }
        throw new RuntimeException('Unexpected diagnostic query: ' . $query);
    }
}

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
};

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/bluebot-diagnostics-' . bin2hex(random_bytes(6));
$previousApiToken = getenv('BLUEBOT_API_TOKEN');
$previousErrorLog = ini_get('error_log');
$previousServer = $_SERVER;

try {
    // Use an isolated project tree so history tests never overwrite live logs.
    foreach (['src/Support', 'storage/cache', 'vendor', 'app', 'panel/inc'] as $directory) {
        mkdir($fixture . '/' . $directory, 0700, true);
    }
    foreach (['Diagnostics.php', 'Logger.php', 'UpdateManager.php', 'ApiToken.php'] as $file) {
        copy($root . '/src/Support/' . $file, $fixture . '/src/Support/' . $file);
    }
    file_put_contents($fixture . '/version', '1.0.0');
    file_put_contents($fixture . '/app/version', '1.0.0');
    file_put_contents($fixture . '/vendor/autoload.php', '<?php');
    putenv('BLUEBOT_API_TOKEN=diagnostics_test_token');
    ini_set('error_log', $fixture . '/error.log');
    require $fixture . '/src/Support/Diagnostics.php';

    $setting = ['webhook_secret' => 'diagnostics_test_secret', 'Bot_Status' => 'active'];
    $pdo = new DiagnosticsTestPdo();
    $healthy = bluebotCollectDiagnostics($pdo, $setting);
    $assertSame(0, $healthy['delivery_errors'], 'A known zero count must be preserved.');
    $assertSame(3, $healthy['delivery_reviewed'], 'A known reviewed count must be preserved.');
    $assertSame(true, bluebotHealthSnapshot($healthy)['overall_healthy'], 'Successful checks must remain healthy.');

    $pdo->failPaymentCheck = true;
    $failed = bluebotCollectDiagnostics($pdo, $setting);
    $assertSame(true, $failed['database_ok'], 'The database connection remains available during a payment query failure.');
    $assertSame(-1, $failed['delivery_errors'], 'A failed payment query must retain the unknown sentinel.');
    $assertSame(-1, $failed['delivery_reviewed'], 'A failed payment query must mark reviewed counts unknown.');
    $snapshot = bluebotHealthSnapshot($failed);
    $assertSame(-1, $snapshot['delivery_errors'], 'Snapshots must retain unknown error counts.');
    $assertSame(-1, $snapshot['delivery_reviewed'], 'Snapshots must retain unknown reviewed counts.');
    $assertSame(false, $snapshot['overall_healthy'], 'Unknown payment counts must not report overall health.');

    $missingCounts = $healthy;
    unset($missingCounts['delivery_errors'], $missingCounts['delivery_reviewed']);
    $assertSame(false, bluebotHealthSnapshot($missingCounts)['overall_healthy'], 'Missing payment counts must not imply a successful check.');
    $reviewedUnknown = $healthy;
    $reviewedUnknown['delivery_reviewed'] = -1;
    $assertSame(false, bluebotHealthSnapshot($reviewedUnknown)['overall_healthy'], 'An unknown reviewed count must not imply a successful check.');

    $report = bluebotBuildDebugReport($pdo, $setting);
    foreach (['Delivery errors: <code>unknown</code>', 'Reviewed delivery errors: <code>unknown</code>'] as $line) {
        $assertSame(true, str_contains($report, $line), 'Telegram debug output must distinguish query failures from zero counts.');
    }
    $assertSame(false, str_contains($report, $setting['webhook_secret']), 'Telegram debug output must not reveal the webhook secret.');

    $assertSame(true, bluebotRecordHealthSnapshot($healthy, 0), 'Healthy history entry was not written.');
    $assertSame(true, bluebotRecordHealthSnapshot($failed, 0), 'Failed history entry was not written.');
    $history = bluebotReadHealthHistory(2);
    $assertSame(2, count($history), 'History round-trip must retain both entries.');
    $assertSame(-1, $history[0]['delivery_errors'], 'History must preserve the unknown error count.');
    $assertSame(-1, $history[0]['delivery_reviewed'], 'History must preserve the unknown reviewed count.');
    $assertSame(false, $history[0]['overall_healthy'], 'History must not report a failed payment check as healthy.');
    $assertSame(true, $history[1]['overall_healthy'], 'Healthy history entries must remain healthy.');

    // Older or inconsistent history records must not display unknown counts as healthy.
    $snapshot['overall_healthy'] = true;
    file_put_contents(bluebotHealthHistoryPath(), json_encode($snapshot) . PHP_EOL);
    $assertSame(false, bluebotReadHealthHistory(1)[0]['overall_healthy'], 'History cannot trust a healthy flag paired with unknown counts.');

    copy($root . '/panel/diagnostics.php', $fixture . '/panel/diagnostics.php');
    file_put_contents($fixture . '/panel/inc/config.php', '<?php $textbotlang = require '
        . var_export($root . '/lang/en.php', true) . '; function require_auth(): void {}');
    foreach (['icons.php', 'layout_head.php', 'layout_foot.php'] as $file) {
        file_put_contents($fixture . '/panel/inc/' . $file, '<?php');
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try {
        require $fixture . '/panel/diagnostics.php';
        $panelHtml = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $assertSame(true, substr_count($panelHtml, 'Unknown') >= 4, 'Panel stats, reviewed counts, payment errors and history must render unknown.');
    $assertSame(false, str_contains($panelHtml, '<span class="tag tag-ok">0</span>'), 'A failed payment check must not render a healthy zero badge.');
} finally {
    $_SERVER = $previousServer;
    putenv($previousApiToken === false ? 'BLUEBOT_API_TOKEN' : 'BLUEBOT_API_TOKEN=' . $previousApiToken);
    ini_set('error_log', (string) $previousErrorLog);
    if (is_dir($fixture)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($fixture);
    }
}

echo "Diagnostics failure and history tests OK.\n";
