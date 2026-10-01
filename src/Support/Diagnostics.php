<?php

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/UpdateManager.php';
require_once __DIR__ . '/ApiToken.php';

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

    $digitalServices = [
        'available' => false,
        'active_products' => 0,
        'orders_pending' => 0,
        'orders_processing' => 0,
        'orders_failed_review' => 0,
        'orders_partial_review' => 0,
        'orders_stale_processing' => 0,
        'orders_delivered' => 0,
        'provider_health_suspended' => 0,
        'order_rate_limit_per_minute' => 10,
        'providers' => [],
    ];
    try {
        $ordersTable = (bool) $pdo->query("SHOW TABLES LIKE 'digital_service_orders'")->fetchColumn();
        $productsTable = (bool) $pdo->query("SHOW TABLES LIKE 'digital_service_products'")->fetchColumn();
        $providersTable = (bool) $pdo->query("SHOW TABLES LIKE 'digital_service_providers'")->fetchColumn();
        $settingsTable = (bool) $pdo->query("SHOW TABLES LIKE 'digital_service_settings'")->fetchColumn();
        $providerHealthTable = (bool) $pdo->query("SHOW TABLES LIKE 'digital_service_provider_health'")->fetchColumn();

        $digitalServices['available'] = $ordersTable && $productsTable;
        if ($productsTable) {
            $digitalServices['active_products'] = (int) $pdo
                ->query("SELECT COUNT(*) FROM digital_service_products WHERE active = 1")
                ->fetchColumn();
        }
        if ($ordersTable) {
            $digitalServices['orders_pending'] = (int) $pdo
                ->query("SELECT COUNT(*) FROM digital_service_orders WHERE status = 'pending_approval'")
                ->fetchColumn();
            $digitalServices['orders_processing'] = (int) $pdo
                ->query("SELECT COUNT(*) FROM digital_service_orders WHERE status = 'processing'")
                ->fetchColumn();
            $digitalServices['orders_failed_review'] = (int) $pdo
                ->query("SELECT COUNT(*) FROM digital_service_orders WHERE status = 'failed' AND refunded = 0")
                ->fetchColumn();
            $digitalServices['orders_partial_review'] = (int) $pdo
                ->query("SELECT COUNT(*) FROM digital_service_orders WHERE status = 'partial_review' AND refunded = 0")
                ->fetchColumn();
            $digitalServices['orders_stale_processing'] = (int) $pdo
                ->query("SELECT COUNT(*) FROM digital_service_orders
                         WHERE status = 'processing'
                           AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)")
                ->fetchColumn();
            $digitalServices['orders_delivered'] = (int) $pdo
                ->query("SELECT COUNT(*) FROM digital_service_orders WHERE status = 'delivered'")
                ->fetchColumn();
        }

        if ($providerHealthTable) {
            $digitalServices['provider_health_suspended'] = (int) $pdo
                ->query("SELECT COUNT(*) FROM digital_service_provider_health
                         WHERE suspended_until IS NOT NULL AND suspended_until > NOW()")
                ->fetchColumn();
        }

        if ($settingsTable) {
            $rateLimitStmt = $pdo->prepare(
                "SELECT setting_value FROM digital_service_settings
                 WHERE setting_key = 'digital_order_rate_limit_per_minute'
                 LIMIT 1"
            );
            $rateLimitStmt->execute();
            $rateLimit = $rateLimitStmt->fetchColumn();
            if ($rateLimit !== false && is_numeric($rateLimit)) {
                $digitalServices['order_rate_limit_per_minute'] = max(0, (int) $rateLimit);
            }
        }

        if ($providersTable) {
            $providerRows = $pdo->query(
                "SELECT provider_key, name, active, last_sync_at, last_sync_status
                 FROM digital_service_providers
                 ORDER BY provider_key ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $approvalModes = [];
            if ($settingsTable) {
                $settingsStmt = $pdo->query(
                    "SELECT setting_key, setting_value
                     FROM digital_service_settings
                     WHERE setting_key LIKE 'provider_approval_%'"
                );
                foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $key = substr((string) ($row['setting_key'] ?? ''), strlen('provider_approval_'));
                    if ($key !== '') {
                        $approvalModes[$key] = (string) ($row['setting_value'] ?? 'manual');
                    }
                }
            }

            foreach ($providerRows as $row) {
                $key = strtolower(trim((string) ($row['provider_key'] ?? '')));
                if ($key === '') {
                    continue;
                }
                $digitalServices['providers'][] = [
                    'key' => $key,
                    'name' => (string) ($row['name'] ?? $key),
                    'active' => (int) ($row['active'] ?? 0) === 1,
                    'mode' => strtolower((string) ($approvalModes[$key] ?? 'manual')),
                    'last_sync_at' => (string) ($row['last_sync_at'] ?? ''),
                    'last_sync_status' => (string) ($row['last_sync_status'] ?? ''),
                ];
            }
        }
    } catch (Throwable $error) {
        bluebotLog('warning', 'Debug digital-services check failed', [
            'exception' => get_class($error),
            'reason' => $error->getMessage(),
        ]);
    }

    $apiTokenConfigured = bluebotHasDedicatedApiToken();

    $webhookProtected = trim((string) ($setting['webhook_secret'] ?? '')) !== '';
    $freeBytes = @disk_free_space($root);

    return [
        'version' => function_exists('bluebotUpdateCurrentVersion')
            ? bluebotUpdateCurrentVersion()
            : $readVersion($root . '/version'),
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
        'digital_services' => $digitalServices,
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

    $digital = is_array($diagnostics['digital_services'] ?? null)
        ? $diagnostics['digital_services']
        : [];
    $digitalProviders = [];
    foreach ((array) ($digital['providers'] ?? []) as $provider) {
        $providerKey = $escape((string) ($provider['key'] ?? 'provider'));
        $providerMode = (($provider['mode'] ?? 'manual') === 'automatic') ? 'auto' : 'manual';
        $providerState = !empty($provider['active']) ? 'on' : 'off';
        $syncState = trim((string) ($provider['last_sync_status'] ?? ''));
        $digitalProviders[] = $providerKey
            . ':' . $providerState
            . '/' . $providerMode
            . ($syncState !== '' ? '/' . $escape($syncState) : '');
    }
    $digitalProviderText = $digitalProviders !== [] ? implode(', ', $digitalProviders) : 'none';

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
        . "<b>🛍 Digital Services</b>\n"
        . 'Module: ' . $status((bool) ($digital['available'] ?? false)) . "\n"
        . 'Active products: <code>' . number_format((int) ($digital['active_products'] ?? 0)) . "</code>\n"
        . 'Pending: <code>' . number_format((int) ($digital['orders_pending'] ?? 0)) . "</code>\n"
        . 'Processing: <code>' . number_format((int) ($digital['orders_processing'] ?? 0)) . "</code>\n"
        . 'Needs review: <code>' . number_format((int) ($digital['orders_failed_review'] ?? 0)) . "</code>\n"
        . 'Partial review: <code>' . number_format((int) ($digital['orders_partial_review'] ?? 0)) . "</code>\n"
        . 'Stale processing (>30m): <code>' . number_format((int) ($digital['orders_stale_processing'] ?? 0)) . "</code>\n"
        . 'Delivered: <code>' . number_format((int) ($digital['orders_delivered'] ?? 0)) . "</code>\n"
        . 'Provider health suspensions: <code>' . number_format((int) ($digital['provider_health_suspended'] ?? 0)) . "</code>\n"
        . 'Order rate limit/min: <code>' . number_format((int) ($digital['order_rate_limit_per_minute'] ?? 0)) . "</code>\n"
        . 'Providers: <code>' . $digitalProviderText . "</code>\n\n"
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

function bluebotRecordHealthSnapshot(array $diagnostics, int $minInterval = 300): bool
{
    $path = bluebotHealthHistoryPath();
    if ($path === '') {
        return false;
    }

    $minInterval = max(0, $minInterval);
    $mtime = is_file($path) ? @filemtime($path) : false;
    $versionChanged = false;

    if (is_file($path) && is_readable($path)) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines) && $lines !== []) {
            $last = json_decode((string) end($lines), true);
            if (is_array($last)) {
                $previousVersion = (string) ($last['version'] ?? '');
                $currentVersion = (string) ($diagnostics['version'] ?? '');
                $versionChanged = $currentVersion !== '' && $currentVersion !== $previousVersion;
            }
        }
    }

    if (!$versionChanged && $minInterval > 0 && $mtime !== false && (time() - $mtime) < $minInterval) {
        return true;
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
            'storage_writable' => (bool) ($decoded['storage_writable'] ?? false),
            'vendor_ready' => (bool) ($decoded['vendor_ready'] ?? false),
            'installer_removed' => (bool) ($decoded['installer_removed'] ?? false),
            'webhook_protected' => (bool) ($decoded['webhook_protected'] ?? false),
            'api_token_configured' => (bool) ($decoded['api_token_configured'] ?? false),
            'delivery_errors' => max(0, (int) ($decoded['delivery_errors'] ?? 0)),
            'delivery_reviewed' => max(0, (int) ($decoded['delivery_reviewed'] ?? 0)),
            'version' => (string) ($decoded['version'] ?? 'unknown'),
            'mini_version' => (string) ($decoded['mini_version'] ?? 'unknown'),
            'php_version' => (string) ($decoded['php_version'] ?? 'unknown'),
            'free_disk' => (string) ($decoded['free_disk'] ?? 'unknown'),
            'bot_status' => (string) ($decoded['bot_status'] ?? 'unknown'),
        ];
    }

    return $history;
}
