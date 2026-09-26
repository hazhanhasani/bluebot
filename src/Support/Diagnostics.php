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
