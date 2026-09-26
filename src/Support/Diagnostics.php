<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

function bluebotCollectDiagnostics(PDO $pdo, array $setting): array
{
    $root = dirname(__DIR__, 2);

    $dbOk = false;
    try {
        $dbOk = (bool) $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $error) {
        bluebotLog('error', 'Debug database check failed', [
            'exception' => get_class($error),
            'reason' => $error->getMessage(),
        ]);
    }

    $readVersion = static function (string $path): string {
        if (!is_file($path) || !is_readable($path)) {
            return 'unknown';
        }

        $value = trim((string) file_get_contents($path));
        return $value !== '' ? $value : 'unknown';
    };

    try {
        $deliveryErrors = (int) $pdo
            ->query("SELECT COUNT(*) FROM Payment_report WHERE payment_Status = 'delivery_error'")
            ->fetchColumn();
        $deliveryReviewed = (int) $pdo
            ->query("SELECT COUNT(*) FROM Payment_report WHERE payment_Status = 'delivery_reviewed'")
            ->fetchColumn();
    } catch (Throwable $error) {
        $deliveryErrors = -1;
        $deliveryReviewed = -1;
        bluebotLog('error', 'Debug payment check failed', [
            'exception' => get_class($error),
            'reason' => $error->getMessage(),
        ]);
    }

    $apiEnvToken = getenv('BLUEBOT_API_TOKEN');
    $apiHashFile = $root . '/api/hash.txt';
    $apiFileToken = is_file($apiHashFile) && is_readable($apiHashFile)
        ? trim((string) file_get_contents($apiHashFile))
        : '';
    $apiTokenConfigured = (is_string($apiEnvToken) && trim($apiEnvToken) !== '') || $apiFileToken !== '';

    $webhookProtected = trim((string) ($setting['webhook_secret'] ?? '')) !== '';
    $freeBytes = @disk_free_space($root);

    return [
        'version' => $readVersion($root . '/version'),
        'mini_version' => $readVersion($root . '/app/version'),
        'php_version' => PHP_VERSION,
        'database_ok' => $dbOk,
        'storage_writable' => is_dir($root . '/storage/cache') && is_writable($root . '/storage/cache'),
        'vendor_ready' => is_file($root . '/vendor/autoload.php'),
        'installer_removed' => !is_dir($root . '/install'),
        'webhook_protected' => $webhookProtected,
        'api_token_configured' => $apiTokenConfigured,
        'delivery_errors' => $deliveryErrors,
        'delivery_reviewed' => $deliveryReviewed,
        'free_disk' => $freeBytes === false ? 'unknown' : number_format($freeBytes / 1073741824, 2) . ' GB',
        'bot_status' => (string) ($setting['Bot_Status'] ?? 'unknown'),
        'time' => date('Y-m-d H:i:s T'),
    ];
}

/**
 * Build the admin-only Telegram diagnostic report.
 * Secrets, credentials and raw webhook values must never be included here.
 */
function bluebotBuildDebugReport(PDO $pdo, array $setting, array $webhookSecret = []): string
{
    $diagnostics = bluebotCollectDiagnostics($pdo, $setting);
    $webhookProtected = trim((string) ($webhookSecret['secret'] ?? '')) !== ''
        || (bool) ($diagnostics['webhook_protected'] ?? false);

    $status = static fn(bool $ok): string => $ok ? '✅' : '❌';
    $escape = static fn(string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $deliveryText = $diagnostics['delivery_errors'] < 0
        ? 'unknown'
        : (string) $diagnostics['delivery_errors'];

    return "<b>🔵 BlueBot /debug</b>\n\n"
        . 'Version: <code>' . $escape((string) $diagnostics['version']) . "</code>\n"
        . 'Mini App: <code>' . $escape((string) $diagnostics['mini_version']) . "</code>\n"
        . 'PHP: <code>' . $escape((string) $diagnostics['php_version']) . "</code>\n"
        . 'Database: ' . $status((bool) $diagnostics['database_ok']) . "\n"
        . 'Storage writable: ' . $status((bool) $diagnostics['storage_writable']) . "\n"
        . 'Composer vendor: ' . $status((bool) $diagnostics['vendor_ready']) . "\n"
        . 'Webhook protection: ' . $status($webhookProtected) . "\n"
        . 'Installer removed: ' . $status((bool) $diagnostics['installer_removed']) . "\n"
        . 'Dedicated API token: ' . $status((bool) $diagnostics['api_token_configured']) . "\n"
        . 'Delivery errors: <code>' . $escape($deliveryText) . "</code>\n"
        . 'Reviewed delivery errors: <code>' . $escape((string) max(0, (int) $diagnostics['delivery_reviewed'])) . "</code>\n"
        . 'Free disk: <code>' . $escape((string) $diagnostics['free_disk']) . "</code>\n"
        . 'Bot status: <code>' . $escape((string) $diagnostics['bot_status']) . "</code>\n"
        . 'Time: <code>' . $escape((string) $diagnostics['time']) . "</code>\n\n"
        . '<i>No secrets are included in this report.</i>';
}


function bluebotHealthHistoryPath(): string
{
    $root = dirname(__DIR__, 2);
    $directory = $root . '/storage/logs';

    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return '';
    }

    @chmod($directory, 0750);
    return $directory . '/health.jsonl';
}

function bluebotRotateHealthHistory(string $path, int $maxBytes = 1048576): void
{
    if ($path === '' || !is_file($path)) {
        return;
    }

    $size = @filesize($path);
    if ($size === false || $size < $maxBytes) {
        return;
    }

    $archive = $path . '.1';
    if (is_file($archive)) {
        @unlink($archive);
    }

    @rename($path, $archive);
    if (is_file($archive)) {
        @chmod($archive, 0640);
    }
}

function bluebotHealthSnapshot(array $diagnostics): array
{
    $coreHealthy = (bool) ($diagnostics['database_ok'] ?? false)
        && (bool) ($diagnostics['storage_writable'] ?? false)
        && (bool) ($diagnostics['vendor_ready'] ?? false)
        && (bool) ($diagnostics['installer_removed'] ?? false);

    $securityHealthy = (bool) ($diagnostics['webhook_protected'] ?? false)
        && (bool) ($diagnostics['api_token_configured'] ?? false);

    $deliveryErrors = max(0, (int) ($diagnostics['delivery_errors'] ?? 0));

    return [
        'time' => date(DATE_ATOM),
        'overall_healthy' => $coreHealthy && $securityHealthy && $deliveryErrors === 0,
        'core_healthy' => $coreHealthy,
        'security_healthy' => $securityHealthy,
        'database_ok' => (bool) ($diagnostics['database_ok'] ?? false),
        'storage_writable' => (bool) ($diagnostics['storage_writable'] ?? false),
        'vendor_ready' => (bool) ($diagnostics['vendor_ready'] ?? false),
        'installer_removed' => (bool) ($diagnostics['installer_removed'] ?? false),
        'webhook_protected' => (bool) ($diagnostics['webhook_protected'] ?? false),
        'api_token_configured' => (bool) ($diagnostics['api_token_configured'] ?? false),
        'delivery_errors' => $deliveryErrors,
        'delivery_reviewed' => max(0, (int) ($diagnostics['delivery_reviewed'] ?? 0)),
        'version' => (string) ($diagnostics['version'] ?? 'unknown'),
        'mini_version' => (string) ($diagnostics['mini_version'] ?? 'unknown'),
        'php_version' => (string) ($diagnostics['php_version'] ?? PHP_VERSION),
        'free_disk' => (string) ($diagnostics['free_disk'] ?? 'unknown'),
        'bot_status' => (string) ($diagnostics['bot_status'] ?? 'unknown'),
    ];
}

function bluebotRecordHealthSnapshot(array $diagnostics): bool
{
    $path = bluebotHealthHistoryPath();
    if ($path === '') {
        return false;
    }

    bluebotRotateHealthHistory($path);

    $encoded = json_encode(
        bluebotHealthSnapshot($diagnostics),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (!is_string($encoded)) {
        return false;
    }

    $written = @file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        return false;
    }

    @chmod($path, 0640);
    return true;
}

function bluebotReadHealthHistory(int $limit = 20): array
{
    $limit = max(1, min($limit, 100));
    $path = bluebotHealthHistoryPath();

    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $history = [];
    foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }

        $history[] = [
            'time' => (string) ($decoded['time'] ?? ''),
            'overall_healthy' => (bool) ($decoded['overall_healthy'] ?? false),
            'core_healthy' => (bool) ($decoded['core_healthy'] ?? false),
            'security_healthy' => (bool) ($decoded['security_healthy'] ?? false),
            'database_ok' => (bool) ($decoded['database_ok'] ?? false),
            'delivery_errors' => max(0, (int) ($decoded['delivery_errors'] ?? 0)),
            'version' => (string) ($decoded['version'] ?? 'unknown'),
        ];
    }

    return $history;
}
