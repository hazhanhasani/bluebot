<?php

declare(strict_types=1);

/**
 * Build the admin-only BlueBot diagnostic report.
 *
 * Secrets, credentials and raw webhook values must never be included here.
 */
function bluebotBuildDebugReport(PDO $pdo, array $setting, array $webhookSecret = []): string
{
    $root = dirname(__DIR__, 2);

    $dbOk = false;
    try {
        $dbOk = (bool) $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $error) {
        error_log('BlueBot /debug database check failed: ' . $error->getMessage());
    }

    $readVersion = static function (string $path): string {
        if (!is_file($path) || !is_readable($path)) {
            return 'unknown';
        }

        $value = trim((string) file_get_contents($path));
        return $value !== '' ? $value : 'unknown';
    };

    $version = $readVersion($root . '/version');
    $miniVersion = $readVersion($root . '/app/version');

    $storagePath = $root . '/storage/cache';
    $storageOk = is_dir($storagePath) && is_writable($storagePath);
    $vendorOk = is_file($root . '/vendor/autoload.php');
    $installerPresent = is_dir($root . '/install');
    $webhookProtected = trim((string) ($webhookSecret['secret'] ?? '')) !== '';

    try {
        $deliveryErrors = (int) $pdo
            ->query("SELECT COUNT(*) FROM Payment_report WHERE payment_Status = 'delivery_error'")
            ->fetchColumn();
    } catch (Throwable $error) {
        $deliveryErrors = -1;
        error_log('BlueBot /debug payment check failed: ' . $error->getMessage());
    }

    $freeBytes = @disk_free_space($root);
    $freeDisk = $freeBytes === false
        ? 'unknown'
        : number_format($freeBytes / 1073741824, 2) . ' GB';

    $status = static fn(bool $ok): string => $ok ? '✅' : '❌';
    $escape = static fn(string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    $deliveryText = $deliveryErrors < 0 ? 'unknown' : (string) $deliveryErrors;

    return "<b>🔵 BlueBot /debug</b>\n\n"
        . 'Version: <code>' . $escape($version) . "</code>\n"
        . 'Mini App: <code>' . $escape($miniVersion) . "</code>\n"
        . 'PHP: <code>' . $escape(PHP_VERSION) . "</code>\n"
        . 'Database: ' . $status($dbOk) . "\n"
        . 'Storage writable: ' . $status($storageOk) . "\n"
        . 'Composer vendor: ' . $status($vendorOk) . "\n"
        . 'Webhook protection: ' . $status($webhookProtected) . "\n"
        . 'Installer removed: ' . $status(!$installerPresent) . "\n"
        . 'Delivery errors: <code>' . $escape($deliveryText) . "</code>\n"
        . 'Free disk: <code>' . $escape($freeDisk) . "</code>\n"
        . 'Bot status: <code>' . $escape((string) ($setting['Bot_Status'] ?? 'unknown')) . "</code>\n"
        . 'Time: <code>' . $escape(date('Y-m-d H:i:s T')) . "</code>\n\n"
        . '<i>No secrets are included in this report.</i>';
}
