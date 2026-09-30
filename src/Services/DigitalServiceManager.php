<?php

require_once __DIR__ . '/TgToolsClient.php';
require_once __DIR__ . '/OZVinooClient.php';
require_once __DIR__ . '/NobitexMarketClient.php';
require_once __DIR__ . '/DigitalServiceProviderCatalog.php';

final class BluebotDigitalServices
{
    private const STATUS_PENDING = 'pending_approval';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_DELIVERED = 'delivered';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_FAILED = 'failed';
    private const OZVINOO_CATALOG_SCHEMA_VERSION = 2;

    public static function isAvailable(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'digital_service_products'");
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function listActive(PDO $pdo): array
    {
        if (!self::isAvailable($pdo)) {
            return [];
        }

        $stmt = $pdo->query(
            "SELECT * FROM digital_service_products
             WHERE active = 1
             ORDER BY sort_order ASC, id ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function ensureMainKeyboardButton(PDO $pdo): array
    {
        if (!self::isAvailable($pdo)) {
            return ['ok' => false, 'changed' => false, 'enabled' => false];
        }

        // One-time compatibility migration for installations upgraded from
        // versions that predate the Digital Services main-menu button.
        // Once initialized, a store owner may disable the button intentionally
        // from Panel > Keyboard without the runtime turning it back on.
        if (self::setting($pdo, 'digital_services_keyboard_initialized', '0') === '1') {
            return [
                'ok' => true,
                'changed' => false,
                'enabled' => self::mainKeyboardHasDigitalServices($pdo),
            ];
        }

        try {
            $stmt = $pdo->query("SELECT keyboardmain FROM setting LIMIT 1");
            $raw = $stmt->fetchColumn();
            $decoded = json_decode((string) $raw, true);
            $rows = is_array($decoded['keyboard'] ?? null)
                ? array_values($decoded['keyboard'])
                : [];

            if ($rows === []) {
                return ['ok' => false, 'changed' => false, 'enabled' => false];
            }

            $hasButton = false;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $button) {
                    if (is_array($button) && ($button['text'] ?? '') === 'text_digital_services') {
                        $hasButton = true;
                        break 2;
                    }
                }
            }

            $changed = false;
            if (!$hasButton) {
                array_splice(
                    $rows,
                    min(1, count($rows)),
                    0,
                    [[['text' => 'text_digital_services']]]
                );
                $json = json_encode(
                    ['keyboard' => array_values($rows)],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if (!is_string($json)) {
                    return ['ok' => false, 'changed' => false, 'enabled' => false];
                }

                $update = $pdo->prepare("UPDATE setting SET keyboardmain = ? LIMIT 1");
                $update->execute([$json]);
                $changed = true;
                $hasButton = true;
            }

            self::setSetting($pdo, 'digital_services_keyboard_initialized', '1', false);

            return [
                'ok' => true,
                'changed' => $changed,
                'enabled' => $hasButton,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'changed' => false,
                'enabled' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public static function mainKeyboardHasDigitalServices(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT keyboardmain FROM setting LIMIT 1");
            $decoded = json_decode((string) $stmt->fetchColumn(), true);
            foreach ((array) ($decoded['keyboard'] ?? []) as $row) {
                foreach ((array) $row as $button) {
                    if (is_array($button) && ($button['text'] ?? '') === 'text_digital_services') {
                        return true;
                    }
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    public static function setProductAdminDisabled(PDO $pdo, int $productId, bool $disabled): bool
    {
        $product = self::findProduct($pdo, $productId, false);
        if (!is_array($product)) {
            return false;
        }

        $metadata = self::productMetadata($product);
        if ($disabled) {
            $metadata['admin_disabled'] = true;
            $active = 0;
        } else {
            unset($metadata['admin_disabled']);
            $active = 1;
        }

        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE digital_service_products
             SET active = ?, metadata = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$active, $json, $productId]);

        return $stmt->rowCount() > 0 || (int) ($product['active'] ?? 0) === $active;
    }

    public static function productAdminDisabled(array $product): bool
    {
        $metadata = self::productMetadata($product);
        return !empty($metadata['admin_disabled']);
    }

    public static function mergeAdminProductMetadata(array $existing, array $synced): array
    {
        $existingMetadata = self::productMetadata($existing);
        if (!empty($existingMetadata['admin_disabled'])) {
            $synced['admin_disabled'] = true;
        }

        if (!empty($existingMetadata['admin_category_override'])) {
            $synced['admin_category_override'] = true;
            if (isset($existingMetadata['category_key'])) {
                $synced['category_key'] = $existingMetadata['category_key'];
            }
            if (isset($existingMetadata['category_label'])) {
                $synced['category_label'] = $existingMetadata['category_label'];
            }
        }

        return $synced;
    }

    public static function providerManagedActive(array $existing, int $providerDefault = 1): int
    {
        return self::productAdminDisabled($existing) ? 0 : ($providerDefault > 0 ? 1 : 0);
    }

    public static function findProduct(PDO $pdo, int $id, bool $activeOnly = true): ?array
    {
        if ($id <= 0 || !self::isAvailable($pdo)) {
            return null;
        }

        $sql = "SELECT * FROM digital_service_products WHERE id = ?";
        if ($activeOnly) {
            $sql .= " AND active = 1";
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function generatedProviderProductCode(string $type, int $serviceValue): string
    {
        $serviceValue = max(1, $serviceValue);
        return match ($type) {
            'telegram_stars' => 'tgtools-stars-' . $serviceValue,
            'telegram_premium' => 'tgtools-premium-' . $serviceValue . 'm',
            default => 'digital-' . substr(hash('sha256', $type . ':' . $serviceValue), 0, 12),
        };
    }

    public static function generatedProviderProductName(string $type, int $serviceValue): string
    {
        $serviceValue = max(1, $serviceValue);
        return match ($type) {
            'telegram_stars' => '⭐ ' . number_format($serviceValue) . ' استار تلگرام',
            'telegram_premium' => '🎁 تلگرام پرمیوم ' . $serviceValue . ' ماهه',
            default => 'سرویس دیجیتال ' . $serviceValue,
        };
    }

    public static function tgToolsTonRateStatus(PDO $pdo): array
    {
        return [
            'rate_toman' => max(0.0, (float) self::setting($pdo, 'tgtools_ton_toman_rate', '0')),
            'source' => self::setting($pdo, 'tgtools_ton_rate_source', 'nobitex'),
            'market' => self::setting($pdo, 'tgtools_ton_rate_market', 'GRAMIRT'),
            'last_sync' => (int) self::setting($pdo, 'tgtools_ton_rate_last_sync', '0'),
            'last_market_update' => (int) self::setting($pdo, 'tgtools_ton_rate_market_update', '0'),
            'last_error' => self::setting($pdo, 'tgtools_ton_rate_last_error', ''),
        ];
    }

    public static function refreshTgToolsTonRateFromNobitex(
        PDO $pdo,
        bool $force = false,
        int $maxAgeSeconds = 60
    ): array {
        $maxAgeSeconds = max(15, min(3600, $maxAgeSeconds));
        $status = self::tgToolsTonRateStatus($pdo);

        if (!$force
            && (float) ($status['rate_toman'] ?? 0) > 0
            && (int) ($status['last_sync'] ?? 0) > 0
            && (time() - (int) $status['last_sync']) < $maxAgeSeconds) {
            return [
                'ok' => true,
                'skipped' => true,
                'cached' => true,
                'rate_toman' => (float) $status['rate_toman'],
                'market' => (string) ($status['market'] ?? 'GRAMIRT'),
                'last_sync' => (int) $status['last_sync'],
                'last_market_update' => (int) ($status['last_market_update'] ?? 0),
                'repriced' => 0,
                'message' => '',
            ];
        }

        $response = (new NobitexMarketClient())->tonTomanRate();
        if (empty($response['ok'])) {
            $message = trim((string) ($response['message'] ?? '')) ?: 'Nobitex rate request failed.';
            self::setSetting($pdo, 'tgtools_ton_rate_last_error', $message, false);

            return [
                'ok' => false,
                'skipped' => false,
                'cached' => (float) ($status['rate_toman'] ?? 0) > 0,
                'rate_toman' => (float) ($status['rate_toman'] ?? 0),
                'market' => 'GRAMIRT',
                'last_sync' => (int) ($status['last_sync'] ?? 0),
                'last_market_update' => (int) ($status['last_market_update'] ?? 0),
                'repriced' => 0,
                'message' => $message,
                'response' => $response,
            ];
        }

        $rate = (float) ($response['rate_toman'] ?? 0);
        if ($rate <= 0) {
            return [
                'ok' => false,
                'skipped' => false,
                'cached' => (float) ($status['rate_toman'] ?? 0) > 0,
                'rate_toman' => (float) ($status['rate_toman'] ?? 0),
                'market' => 'GRAMIRT',
                'last_sync' => (int) ($status['last_sync'] ?? 0),
                'last_market_update' => (int) ($status['last_market_update'] ?? 0),
                'repriced' => 0,
                'message' => 'Nobitex returned an invalid GRAMIRT rate.',
            ];
        }

        $now = time();
        self::setSetting($pdo, 'tgtools_ton_toman_rate', self::decimalString($rate), false);
        self::setSetting($pdo, 'tgtools_ton_rate_source', 'nobitex', false);
        self::setSetting($pdo, 'tgtools_ton_rate_market', 'GRAMIRT', false);
        self::setSetting($pdo, 'tgtools_ton_rate_last_sync', (string) $now, false);
        self::setSetting(
            $pdo,
            'tgtools_ton_rate_market_update',
            is_numeric($response['last_update'] ?? null) ? (string) (int) $response['last_update'] : '0',
            false
        );
        self::setSetting($pdo, 'tgtools_ton_rate_last_error', '', false);

        $repriced = self::repriceTgToolsCatalogFromTonRate($pdo, $rate);

        return [
            'ok' => true,
            'skipped' => false,
            'cached' => false,
            'rate_toman' => $rate,
            'market' => 'GRAMIRT',
            'last_sync' => $now,
            'last_market_update' => is_numeric($response['last_update'] ?? null)
                ? (int) $response['last_update']
                : 0,
            'repriced' => $repriced,
            'message' => '',
            'response' => $response,
        ];
    }

    private static function repriceTgToolsCatalogFromTonRate(PDO $pdo, float $tonRateToman): int
    {
        if ($tonRateToman <= 0 || !self::isAvailable($pdo)) {
            return 0;
        }

        $stmt = $pdo->query(
            "SELECT * FROM digital_service_products
             WHERE provider = 'tgtools'
               AND type IN ('telegram_stars', 'telegram_premium')"
        );
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($products === []) {
            return 0;
        }

        $update = $pdo->prepare(
            "UPDATE digital_service_products
             SET price = ?, active = ?, metadata = ?, updated_at = NOW()
             WHERE id = ?"
        );

        $updated = 0;
        foreach ($products as $product) {
            $metadata = self::productMetadata($product);
            $wholesaleTon = $metadata['wholesale_ton'] ?? null;
            if (!is_numeric($wholesaleTon) || (float) $wholesaleTon <= 0) {
                continue;
            }

            $type = (string) ($product['type'] ?? '');
            $fallbackProfit = $type === 'telegram_stars'
                ? (float) self::setting($pdo, 'tgtools_stars_profit_percent', '0')
                : (float) self::setting($pdo, 'tgtools_premium_profit_percent', '0');
            $profitPercent = is_numeric($metadata['profit_percent'] ?? null)
                ? (float) $metadata['profit_percent']
                : $fallbackProfit;

            $price = BluebotProviderCatalogService::calculateSellingPrice(
                (float) $wholesaleTon,
                'ton',
                $tonRateToman,
                max(0.0, min(1000.0, $profitPercent))
            );
            if ($price <= 0) {
                continue;
            }

            $metadata['ton_rate_toman'] = $tonRateToman;
            $metadata['ton_rate_source'] = 'nobitex';
            $metadata['ton_rate_market'] = 'GRAMIRT';
            $metadata['ton_rate_synced_at'] = gmdate(DATE_ATOM);
            $metadata = self::mergeAdminProductMetadata($product, $metadata);

            $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($metadataJson)) {
                continue;
            }

            $active = self::providerManagedActive($product, 1);
            $update->execute([$price, $active, $metadataJson, (int) $product['id']]);
            $updated++;
        }

        return $updated;
    }

    private static function decimalString(float $value): string
    {
        $text = number_format($value, 8, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * Stars/Premium providers are amount/period based and do not expose an
     * opaque "service code" that an admin should have to know. BlueBot keeps
     * its own deterministic internal codes and maps by type + service_value.
     *
     * Newly discovered presets are intentionally inactive with price=0 until
     * the store owner sets a retail price.
     */
    public static function ensureTgToolsCatalog(PDO $pdo): array
    {
        if (!self::isAvailable($pdo)) {
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'remote_ok' => false];
        }

        $client = new TgToolsClient(trim(self::setting($pdo, 'tgtools_api_key', '')));
        $priceResponse = $client->prices();
        $remoteOk = !empty($priceResponse['ok']);
        $priceData = $remoteOk && is_array($priceResponse['data'] ?? null)
            ? $priceResponse['data']
            : [];

        $starsProfit = max(0.0, min(1000.0, (float) self::setting($pdo, 'tgtools_stars_profit_percent', '0')));
        $premiumProfit = max(0.0, min(1000.0, (float) self::setting($pdo, 'tgtools_premium_profit_percent', '0')));
        self::refreshTgToolsTonRateFromNobitex($pdo, false, 60);
        $tonRateToman = max(0.0, (float) self::setting($pdo, 'tgtools_ton_toman_rate', '0'));

        $definitions = [];
        $seenStars = [];

        if (is_array($priceData['packages'] ?? null)) {
            foreach ($priceData['packages'] as $package) {
                if (!is_array($package)) {
                    continue;
                }
                $qty = (int) ($package['qty'] ?? 0);
                if ($qty <= 0 || $qty > 1000000 || isset($seenStars[$qty])) {
                    continue;
                }
                $seenStars[$qty] = true;
                $definitions[] = [
                    'type' => 'telegram_stars',
                    'value' => $qty,
                    'name' => self::generatedProviderProductName('telegram_stars', $qty),
                    'sort' => 100 + $qty,
                    'wholesale_ton' => is_numeric($package['ton'] ?? null) ? (float) $package['ton'] : null,
                    'wholesale_usd' => is_numeric($package['usd'] ?? null) ? (float) $package['usd'] : null,
                    'profit_percent' => $starsProfit,
                ];
            }
        }

        if ($seenStars === []) {
            foreach ([50, 100, 250, 500, 1000] as $qty) {
                $definitions[] = [
                    'type' => 'telegram_stars',
                    'value' => $qty,
                    'name' => self::generatedProviderProductName('telegram_stars', $qty),
                    'sort' => 100 + $qty,
                    'wholesale_ton' => null,
                    'wholesale_usd' => null,
                    'profit_percent' => $starsProfit,
                ];
            }
        }

        foreach ([3, 6, 12] as $months) {
            $tonKey = 'premium_' . $months . 'm_ton';
            $usdKey = 'premium_' . $months . 'm_usd';
            $definitions[] = [
                'type' => 'telegram_premium',
                'value' => $months,
                'name' => self::generatedProviderProductName('telegram_premium', $months),
                'sort' => 10000 + $months,
                'wholesale_ton' => is_numeric($priceData[$tonKey] ?? null) ? (float) $priceData[$tonKey] : null,
                'wholesale_usd' => is_numeric($priceData[$usdKey] ?? null) ? (float) $priceData[$usdKey] : null,
                'profit_percent' => $premiumProfit,
            ];
        }

        $find = $pdo->prepare(
            "SELECT * FROM digital_service_products
             WHERE provider = 'tgtools' AND type = ? AND service_value = ?
             ORDER BY id ASC
             LIMIT 1"
        );
        $update = $pdo->prepare(
            "UPDATE digital_service_products
             SET name = ?,
                 provider = 'tgtools',
                 provider_service_code = NULL,
                 price = ?,
                 active = ?,
                 description = ?,
                 metadata = ?,
                 sort_order = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $insert = $pdo->prepare(
            "INSERT INTO digital_service_products
             (code, name, type, provider, price, service_value, provider_service_code, description, metadata, active, sort_order)
             VALUES (?, ?, ?, 'tgtools', ?, ?, NULL, ?, ?, ?, ?)"
        );

        $created = 0;
        $updated = 0;
        $priced = 0;
        $commissionRate = is_numeric($priceData['commission_rate'] ?? null)
            ? (float) $priceData['commission_rate']
            : null;

        foreach ($definitions as $definition) {
            $wholesaleTon = is_numeric($definition['wholesale_ton'] ?? null)
                ? (float) $definition['wholesale_ton']
                : null;
            $profitPercent = (float) ($definition['profit_percent'] ?? 0);

            $autoPrice = 0;
            if ($wholesaleTon !== null && $wholesaleTon > 0 && $tonRateToman > 0) {
                $autoPrice = BluebotProviderCatalogService::calculateSellingPrice(
                    $wholesaleTon,
                    'ton',
                    $tonRateToman,
                    $profitPercent
                );
            }

            $metadata = [
                'source' => $remoteOk ? 'tgtools-live-prices' : 'tgtools-fallback-catalog',
                'auto_generated' => true,
                'no_provider_service_code' => true,
                'price_mode' => 'margin',
                'profit_percent' => $profitPercent,
                'ton_rate_toman' => $tonRateToman,
                'ton_rate_source' => 'nobitex',
                'ton_rate_market' => 'GRAMIRT',
                'category_key' => (string) $definition['type'] === 'telegram_stars' ? 'stars' : 'premium',
                'category_label' => (string) $definition['type'] === 'telegram_stars'
                    ? '⭐ استارز تلگرام'
                    : '🎁 تلگرام پرمیوم',
                'synced_at' => gmdate(DATE_ATOM),
            ];
            if ($wholesaleTon !== null) {
                $metadata['wholesale_ton'] = $wholesaleTon;
            }
            if (is_numeric($definition['wholesale_usd'] ?? null)) {
                $metadata['wholesale_usd'] = (float) $definition['wholesale_usd'];
            }
            if ($commissionRate !== null) {
                $metadata['commission_rate'] = $commissionRate;
            }

            $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $description = 'محصول TGTools با قیمت‌گذاری خودکار بر اساس حاشیه سود.';

            $find->execute([$definition['type'], $definition['value']]);
            $row = $find->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                $currentPrice = max(0, (int) ($row['price'] ?? 0));
                $price = $autoPrice > 0 ? $autoPrice : $currentPrice;
                $providerActive = $price > 0 ? 1 : 0;
                $active = self::providerManagedActive($row, $providerActive);
                $metadata = self::mergeAdminProductMetadata($row, $metadata);
                $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($autoPrice > 0) {
                    $priced++;
                }

                $update->execute([
                    $definition['name'],
                    $price,
                    $active,
                    $description,
                    is_string($metadataJson) ? $metadataJson : null,
                    $definition['sort'],
                    (int) $row['id'],
                ]);
                $updated++;
                continue;
            }

            $code = self::generatedProviderProductCode(
                (string) $definition['type'],
                (int) $definition['value']
            );
            $active = $autoPrice > 0 ? 1 : 0;
            if ($autoPrice > 0) {
                $priced++;
            }

            try {
                $insert->execute([
                    $code,
                    $definition['name'],
                    $definition['type'],
                    $autoPrice,
                    $definition['value'],
                    $description,
                    is_string($metadataJson) ? $metadataJson : null,
                    $active,
                    $definition['sort'],
                ]);
                $created++;
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'priced' => $priced,
            'remote_ok' => $remoteOk,
            'remote_error' => $remoteOk ? '' : (string) ($priceResponse['message'] ?? 'TGTools price endpoint unavailable'),
            'price_data' => $priceData,
            'stars_profit_percent' => $starsProfit,
            'premium_profit_percent' => $premiumProfit,
            'ton_rate_toman' => $tonRateToman,
        ];
    }

    public static function maybeSyncTgToolsCatalog(PDO $pdo, int $intervalSeconds = 900): array
    {
        $intervalSeconds = max(60, $intervalSeconds);
        $lastSync = (int) self::setting($pdo, 'tgtools_catalog_last_sync', '0');

        if ($lastSync > 0 && (time() - $lastSync) < $intervalSeconds) {
            return ['ok' => true, 'skipped' => true, 'created' => 0, 'updated' => 0];
        }

        $result = self::ensureTgToolsCatalog($pdo);
        self::setSetting($pdo, 'tgtools_catalog_last_sync', (string) time(), false);
        return $result;
    }

    public static function tgToolsWalletStatus(PDO $pdo): array
    {
        $apiKey = trim(self::setting($pdo, 'tgtools_api_key', ''));
        if ($apiKey === '') {
            return [
                'ok' => false,
                'configured' => false,
                'balance_ton' => null,
                'deposit_address' => '',
                'message' => 'کلید API تی‌جی‌تولز تنظیم نشده است.',
            ];
        }

        $response = (new TgToolsClient($apiKey))->wallet();
        if (empty($response['ok'])) {
            return [
                'ok' => false,
                'configured' => true,
                'balance_ton' => null,
                'deposit_address' => '',
                'message' => trim((string) ($response['message'] ?? '')) ?: 'دریافت موجودی کیف پول API تی‌جی‌تولز ناموفق بود.',
                'response' => $response,
            ];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $balance = self::findScalarByKeys(
            $data,
            ['balanceton', 'tonbalance', 'availableton', 'availablebalance', 'walletbalance', 'balance']
        );
        $address = self::findScalarByKeys(
            $data,
            ['depositaddress', 'walletaddress', 'tonaddress', 'address']
        );

        return [
            'ok' => true,
            'configured' => true,
            'balance_ton' => is_numeric($balance) ? (float) $balance : null,
            'deposit_address' => is_scalar($address) ? trim((string) $address) : '',
            'message' => '',
            'response' => $response,
        ];
    }

    public static function maybeBootstrapOZVinooCatalog(PDO $pdo): array
    {
        if (!self::isAvailable($pdo)) {
            return ['ok' => false, 'skipped' => true, 'message' => 'Digital services are unavailable.'];
        }

        $apiKey = trim(self::setting($pdo, 'ozvinoo_api_key', ''));
        if ($apiKey === '') {
            return ['ok' => true, 'skipped' => true, 'message' => 'OZVinoo API key is not configured.'];
        }

        $interval = max(1, min(1440, (int) self::setting($pdo, 'ozvinoo_sync_interval_minutes', '15')));
        $lastSync = (int) self::setting($pdo, 'ozvinoo_catalog_last_sync', '0');
        $catalogSchemaVersion = (int) self::setting($pdo, 'ozvinoo_catalog_schema_version', '0');
        $requiresCatalogMigration = $catalogSchemaVersion < self::OZVINOO_CATALOG_SCHEMA_VERSION;
        $activeProducts = (int) $pdo->query(
            "SELECT COUNT(*) FROM digital_service_products WHERE provider = 'ozvinoo' AND active = 1"
        )->fetchColumn();

        if (!$requiresCatalogMigration
            && $activeProducts > 0
            && $lastSync > 0
            && (time() - $lastSync) < ($interval * 60)) {
            return [
                'ok' => true,
                'skipped' => true,
                'products' => $activeProducts,
                'message' => 'OZVinoo official catalog is already fresh.',
            ];
        }

        return self::syncOZVinooCatalog($pdo);
    }

    public static function syncOZVinooCatalog(PDO $pdo): array
    {
        if (!self::isAvailable($pdo)) {
            return ['ok' => false, 'message' => 'Digital services are unavailable.'];
        }

        $apiKey = trim(self::setting($pdo, 'ozvinoo_api_key', ''));
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'API Key عضوینو تنظیم نشده است.'];
        }

        $profitPercent = max(0.0, min(1000.0, (float) self::setting($pdo, 'ozvinoo_profit_percent', '0')));
        $syncInterval = max(1, min(1440, (int) self::setting($pdo, 'ozvinoo_sync_interval_minutes', '15')));

        BluebotProviderCatalogService::saveProvider($pdo, [
            'provider_key' => 'ozvinoo',
            'name' => 'OZVinoo',
            'catalog_url' => 'https://api.ozvinoo.xyz/telegram-services/stars/',
            'api_key' => $apiKey,
            'auth_header' => 'Authorization',
            'auth_prefix' => 'Bearer',
            'products_path' => 'data',
            'id_field' => 'id',
            'name_field' => 'name',
            'category_field' => 'category',
            'price_field' => 'price',
            'currency' => 'toman',
            'exchange_rate_toman' => 1,
            'profit_percent' => $profitPercent,
            'sync_interval_minutes' => $syncInterval,
        ]);

        $client = new OZVinooClient($apiKey);
        $responses = [
            'telegram_stars' => $client->stars(),
            'telegram_premium' => $client->premium(),
            'virtual_number' => $client->countries(true),
        ];

        $definitions = [];
        $successfulTypes = [];
        $messages = [];

        if (!empty($responses['telegram_stars']['ok'])) {
            $successfulTypes[] = 'telegram_stars';
            foreach (self::ozvinooResponseList($responses['telegram_stars']) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = trim((string) (self::findScalarByKeys($item, ['package_name', 'name', 'title', 'label']) ?? ''));
                $count = self::ozvinooPositiveInt(
                    self::findScalarByKeys($item, ['count', 'stars', 'star_count', 'quantity'])
                );
                if ($count <= 0) {
                    $count = self::ozvinooNumberFromText($name);
                }
                $price = self::findScalarByKeys($item, ['price', 'cost', 'amount_toman', 'toman']);
                if ($count <= 0 || !is_numeric($price) || (float) $price <= 0) {
                    continue;
                }

                $definitions[] = [
                    'code' => 'auto-ozvinoo-stars-' . $count,
                    'name' => $name !== '' ? $name : ('⭐ ' . number_format($count) . ' استارز تلگرام'),
                    'type' => 'telegram_stars',
                    'price' => (float) $price,
                    'service_value' => $count,
                    'provider_service_code' => (string) $count,
                    'description' => 'Telegram Stars via OZVinoo official API.',
                    'metadata' => [
                        'source' => 'ozvinoo-official-api',
                        'api_family' => 'telegram-services',
                        'category_key' => 'stars',
                        'category_label' => '⭐ استارز تلگرام',
                    ],
                ];
            }
        } else {
            if ((int) ($responses['telegram_stars']['http_status'] ?? 0) === 404) {
                $successfulTypes[] = 'telegram_stars';
            }
            $messages[] = 'Stars: ' . (string) ($responses['telegram_stars']['message'] ?? 'request failed');
        }

        if (!empty($responses['telegram_premium']['ok'])) {
            $successfulTypes[] = 'telegram_premium';
            foreach (self::ozvinooResponseList($responses['telegram_premium']) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $packageId = self::findScalarByKeys($item, ['id', 'package_id', 'packageId']);
                $price = self::findScalarByKeys($item, ['price', 'cost', 'amount_toman', 'toman']);
                if ($packageId === null || $packageId === '' || !is_numeric($price) || (float) $price <= 0) {
                    continue;
                }

                $name = trim((string) (self::findScalarByKeys($item, ['package_name', 'name', 'title', 'label']) ?? ''));
                $months = self::ozvinooPositiveInt(
                    self::findScalarByKeys($item, ['months', 'month_count', 'duration_months', 'duration'])
                );
                if ($months <= 0) {
                    $months = max(1, self::ozvinooNumberFromText($name));
                }

                $definitions[] = [
                    'code' => 'auto-ozvinoo-premium-' . substr(hash('sha256', (string) $packageId), 0, 12),
                    'name' => $name !== '' ? $name : ('🎁 تلگرام پرمیوم ' . $months . ' ماهه'),
                    'type' => 'telegram_premium',
                    'price' => (float) $price,
                    'service_value' => $months,
                    'provider_service_code' => (string) $packageId,
                    'description' => 'Telegram Premium via OZVinoo official API.',
                    'metadata' => [
                        'source' => 'ozvinoo-official-api',
                        'api_family' => 'telegram-services',
                        'category_key' => 'premium',
                        'category_label' => '🎁 تلگرام پرمیوم',
                        'package_id' => (string) $packageId,
                    ],
                ];
            }
        } else {
            if ((int) ($responses['telegram_premium']['http_status'] ?? 0) === 404) {
                $successfulTypes[] = 'telegram_premium';
            }
            $messages[] = 'Premium: ' . (string) ($responses['telegram_premium']['message'] ?? 'request failed');
        }

        $numberDefinitionCount = 0;
        $virtualNumberApplicationIds = [];
        $virtualNumberCatalogComplete = false;

        // Callinoo/OZVinoo virtual numbers are application-first: Telegram,
        // WhatsApp, Google, Instagram, Discord, Apple, etc. Synchronize every
        // application exposed by the documented /web API, then every country
        // available for that application. V2 Telegram countries are only a
        // fallback when the application catalog is unavailable.
        $applicationsGetResponse = $client->applications('GET');
        $applicationGetItems = !empty($applicationsGetResponse['ok'])
            ? self::ozvinooResponseList($applicationsGetResponse)
            : [];

        $applicationsPostResponse = [];
        $applicationPostItems = [];
        $applicationPostAttempted = count($applicationGetItems) <= 1;
        if ($applicationPostAttempted) {
            $applicationsPostResponse = $client->applications('POST');
            $applicationPostItems = !empty($applicationsPostResponse['ok'])
                ? self::ozvinooResponseList($applicationsPostResponse)
                : [];
        }

        $applicationItemsById = [];
        foreach ([$applicationGetItems, $applicationPostItems] as $candidateItems) {
            foreach ($candidateItems as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidateServiceId = self::ozvinooPositiveInt(
                    self::findScalarByKeys($candidate, ['id', 'service_id', 'serviceId', 'application_id'])
                );
                if ($candidateServiceId <= 0) {
                    continue;
                }
                // POST may contain a richer object than GET, so later data wins.
                $applicationItemsById[$candidateServiceId] = isset($applicationItemsById[$candidateServiceId])
                    ? array_replace($applicationItemsById[$candidateServiceId], $candidate)
                    : $candidate;
            }
        }
        $applicationItems = array_values($applicationItemsById);
        $applicationsResponse = !empty($applicationsGetResponse['ok'])
            ? $applicationsGetResponse
            : $applicationsPostResponse;

        $applicationPriceFailures = 0;
        $applicationPriceSuccesses = 0;
        $applicationCatalogReady = false;

        foreach ($applicationItems as $application) {
            if (!is_array($application)) {
                continue;
            }

            $serviceId = self::ozvinooPositiveInt(
                self::findScalarByKeys($application, ['id', 'service_id', 'serviceId', 'application_id'])
            );
            if ($serviceId <= 0) {
                continue;
            }

            $applicationName = trim((string) (self::findScalarByKeys(
                $application,
                ['title', 'name', 'application', 'app_name', 'appName', 'label']
            ) ?? ''));
            $applicationCode = strtolower(trim((string) (self::findScalarByKeys(
                $application,
                ['code', 'slug', 'short_code', 'shortCode']
            ) ?? '')));

            if ($applicationName === '') {
                $applicationName = $applicationCode !== ''
                    ? strtoupper($applicationCode)
                    : ('Service ' . $serviceId);
            }
            if ($applicationCode === '') {
                $applicationCode = 'app_' . $serviceId;
            }

            $pricesResponse = $client->prices($serviceId, 'GET');
            $priceItems = !empty($pricesResponse['ok'])
                ? self::ozvinooResponseList($pricesResponse)
                : [];

            if ($priceItems === []) {
                $pricesPostResponse = $client->prices($serviceId, 'POST');
                $pricePostItems = !empty($pricesPostResponse['ok'])
                    ? self::ozvinooResponseList($pricesPostResponse)
                    : [];
                if ($pricePostItems !== []) {
                    $pricesResponse = $pricesPostResponse;
                    $priceItems = $pricePostItems;
                }
            }

            if (empty($pricesResponse['ok']) || $priceItems === []) {
                $applicationPriceFailures++;
                continue;
            }
            $applicationPriceSuccesses++;

            foreach ($priceItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $country = trim((string) (self::findScalarByKeys(
                    $item,
                    ['country', 'countery', 'country_name', 'countryName', 'name', 'title']
                ) ?? ''));
                $range = self::findScalarByKeys($item, [
                    'range',
                    'country_code',
                    'countryCode',
                    'dial_code',
                    'dialCode',
                    'prefix',
                    'code',
                ]);
                $price = self::findScalarByKeys($item, ['price', 'cost', 'amount_toman', 'toman']);
                $availability = strtolower(trim((string) (self::findScalarByKeys(
                    $item,
                    ['count', 'status', 'availability', 'available']
                ) ?? '')));

                if ($country === ''
                    || $range === null
                    || $range === ''
                    || !is_numeric($price)
                    || (float) $price <= 0) {
                    continue;
                }
                if (str_contains($availability, 'ناموجود')
                    || in_array($availability, ['0', 'false', 'no', 'unavailable', 'out_of_stock'], true)) {
                    continue;
                }

                $identity = $serviceId . ':' . (string) $range . ':' . $country;
                $definitions[] = [
                    'code' => 'auto-ozvinoo-number-v1-' . substr(hash('sha256', $identity), 0, 12),
                    'name' => '📱 ' . $applicationName . ' · ' . $country,
                    'type' => 'virtual_number',
                    'price' => (float) $price,
                    'service_value' => 1,
                    'provider_service_code' => 'v1:' . $serviceId . ':' . (string) $range,
                    'description' => $applicationName . ' virtual number via Callinoo/OZVinoo documented /web API.',
                    'metadata' => [
                        'source' => 'ozvinoo-official-api',
                        'api_family' => 'web-v1',
                        'category_key' => 'virtual_number',
                        'category_label' => '📱 شماره مجازی',
                        'application_id' => $serviceId,
                        'application_code' => $applicationCode,
                        'application_name' => $applicationName,
                        'service_id' => $serviceId,
                        'country' => $country,
                        'range' => (string) $range,
                    ],
                ];
                $numberDefinitionCount++;
                $virtualNumberApplicationIds[$serviceId] = true;
            }
        }

        $applicationCatalogReady = !empty($applicationsResponse['ok'])
            && $applicationItems !== []
            && $applicationPriceSuccesses > 0;

        if ($applicationPostAttempted && count($applicationItems) <= 1) {
            $onlyApplicationName = '';
            if (isset($applicationItems[0]) && is_array($applicationItems[0])) {
                $onlyApplicationName = trim((string) (self::findScalarByKeys(
                    $applicationItems[0],
                    ['title', 'name', 'application', 'app_name', 'appName', 'label']
                ) ?? ''));
            }
            $messages[] = 'Applications API exposed only '
                . count($applicationItems)
                . ' platform(s): GET ' . count($applicationGetItems)
                . ' / POST ' . count($applicationPostItems)
                . ($onlyApplicationName !== '' ? ' · ' . $onlyApplicationName : '');
        }

        if ($numberDefinitionCount > 0) {
            if (!in_array('virtual_number', $successfulTypes, true)) {
                $successfulTypes[] = 'virtual_number';
            }
            // Disable missing virtual-number products only when every
            // application price request completed. A partial provider outage
            // must never hide otherwise valid products.
            $virtualNumberCatalogComplete = $applicationPriceFailures === 0;
            if ($applicationPriceFailures > 0) {
                $messages[] = 'Numbers: ' . $applicationPriceFailures
                    . ' application price request(s) failed; existing products were preserved.';
            }
        } else {
            if (empty($applicationsResponse['ok'])) {
                $messages[] = 'Numbers applications: '
                    . (string) ($applicationsResponse['message'] ?? 'request failed');
            } elseif ($applicationPriceSuccesses === 0 && $applicationItems !== []) {
                $messages[] = 'Numbers prices: all application price requests failed.';
            }

            // V2 currently represents Telegram numbers. Keep it as a fallback
            // so number sales remain available even if the /web application
            // catalog is temporarily unavailable.
            if (!empty($responses['virtual_number']['ok'])) {
                foreach (self::ozvinooResponseList($responses['virtual_number']) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $countryId = self::findScalarByKeys($item, [
                        'id',
                        'country_id',
                        'countryId',
                        'country_identifier',
                        'countryIdentifier',
                    ]);
                    $country = trim((string) (self::findScalarByKeys(
                        $item,
                        ['country', 'countery', 'country_name', 'countryName', 'name', 'title']
                    ) ?? ''));
                    $price = self::findScalarByKeys($item, ['price', 'cost', 'amount_toman', 'toman']);
                    if ($countryId === null || $countryId === '' || $country === '' || !is_numeric($price) || (float) $price <= 0) {
                        continue;
                    }

                    $range = self::findScalarByKeys($item, ['range', 'dial_code', 'prefix']);
                    $definitions[] = [
                        'code' => 'auto-ozvinoo-number-v2-' . substr(hash('sha256', (string) $countryId), 0, 12),
                        'name' => '📱 Telegram · ' . $country,
                        'type' => 'virtual_number',
                        'price' => (float) $price,
                        'service_value' => 1,
                        'provider_service_code' => (string) $countryId,
                        'description' => 'Telegram virtual number via OZVinoo V2 API.',
                        'metadata' => [
                            'source' => 'ozvinoo-official-api',
                            'api_family' => 'telegram-numbers-v2',
                            'category_key' => 'virtual_number',
                            'category_label' => '📱 شماره مجازی',
                            'application_id' => 0,
                            'application_code' => 'telegram',
                            'application_name' => 'Telegram',
                            'country_id' => (string) $countryId,
                            'country' => $country,
                            'range' => is_scalar($range) ? (string) $range : '',
                            'none_report' => true,
                        ],
                    ];
                    $numberDefinitionCount++;
                    $virtualNumberApplicationIds[0] = true;
                }

                if ($numberDefinitionCount > 0) {
                    if (!in_array('virtual_number', $successfulTypes, true)) {
                        $successfulTypes[] = 'virtual_number';
                    }
                    $virtualNumberCatalogComplete = true;
                }
            } else {
                $messages[] = 'Numbers V2: '
                    . (string) ($responses['virtual_number']['message'] ?? 'request failed');
            }
        }

        if ($successfulTypes === []) {
            $message = $messages !== [] ? implode(' | ', $messages) : 'OZVinoo official endpoints are unavailable.';
            self::ozvinooMarkProviderSync($pdo, 'failed', $message);
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'disabled' => 0, 'message' => $message];
        }

        $find = $pdo->prepare("SELECT * FROM digital_service_products WHERE code = ? LIMIT 1");
        $insert = $pdo->prepare(
            "INSERT INTO digital_service_products
             (code, name, type, provider, price, service_value, provider_service_code, description, metadata, active, sort_order)
             VALUES (?, ?, ?, 'ozvinoo', ?, ?, ?, ?, ?, 1, ?)"
        );
        $update = $pdo->prepare(
            "UPDATE digital_service_products
             SET name = ?, type = ?, provider = 'ozvinoo', price = ?, service_value = ?,
                 provider_service_code = ?, description = ?, metadata = ?, active = ?,
                 sort_order = ?, updated_at = NOW()
             WHERE id = ?"
        );

        $created = 0;
        $updated = 0;
        $disabled = 0;
        $sort = 2000;
        $seenByType = [];

        foreach ($definitions as $definition) {
            $sellingPrice = BluebotProviderCatalogService::calculateSellingPrice(
                (float) $definition['price'],
                'toman',
                1.0,
                $profitPercent
            );
            if ($sellingPrice <= 0) {
                continue;
            }

            $type = (string) $definition['type'];
            $code = (string) $definition['code'];
            $seenByType[$type][] = $code;
            $sort++;

            $metadata = (array) $definition['metadata'];
            $metadata['auto_imported'] = true;
            $metadata['provider_key'] = 'ozvinoo';
            $metadata['wholesale_cost'] = (float) $definition['price'];
            $metadata['wholesale_currency'] = 'toman';
            $metadata['profit_percent'] = $profitPercent;
            $metadata['price_mode'] = 'margin';
            $metadata['synced_at'] = gmdate(DATE_ATOM);
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $find->execute([$code]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                $metadata = self::mergeAdminProductMetadata($existing, $metadata);
                $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $active = self::providerManagedActive($existing, 1);

                $update->execute([
                    (string) $definition['name'],
                    $type,
                    $sellingPrice,
                    max(1, (int) $definition['service_value']),
                    (string) $definition['provider_service_code'],
                    (string) $definition['description'],
                    is_string($metadataJson) ? $metadataJson : null,
                    $active,
                    $sort,
                    (int) $existing['id'],
                ]);
                $updated++;
            } else {
                $insert->execute([
                    $code,
                    (string) $definition['name'],
                    $type,
                    $sellingPrice,
                    max(1, (int) $definition['service_value']),
                    (string) $definition['provider_service_code'],
                    (string) $definition['description'],
                    is_string($metadataJson) ? $metadataJson : null,
                    $sort,
                ]);
                $created++;
            }
        }

        foreach ($successfulTypes as $type) {
            if ($type === 'virtual_number' && !$virtualNumberCatalogComplete) {
                continue;
            }
            $seenCodes = array_values(array_unique($seenByType[$type] ?? []));
            if ($seenCodes === []) {
                $stmt = $pdo->prepare(
                    "UPDATE digital_service_products
                     SET active = 0, updated_at = NOW()
                     WHERE provider = 'ozvinoo' AND type = ? AND code LIKE 'auto-ozvinoo-%'"
                );
                $stmt->execute([$type]);
                $disabled += $stmt->rowCount();
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($seenCodes), '?'));
            $params = array_merge([$type], $seenCodes);
            $stmt = $pdo->prepare(
                "UPDATE digital_service_products
                 SET active = 0, updated_at = NOW()
                 WHERE provider = 'ozvinoo'
                   AND type = ?
                   AND code LIKE 'auto-ozvinoo-%'
                   AND code NOT IN ($placeholders)"
            );
            $stmt->execute($params);
            $disabled += $stmt->rowCount();
        }

        self::setSetting($pdo, 'ozvinoo_catalog_last_sync', (string) time(), false);
        if ($applicationCatalogReady) {
            self::setSetting(
                $pdo,
                'ozvinoo_catalog_schema_version',
                (string) self::OZVINOO_CATALOG_SCHEMA_VERSION,
                false
            );
        }
        self::setSetting($pdo, 'ozvinoo_api_style', 'official-v1', false);
        self::setSetting($pdo, 'ozvinoo_catalog_path', '/telegram-services/stars/', false);
        self::setSetting($pdo, 'ozvinoo_order_path', '/telegram-services/stars/', false);
        self::setSetting($pdo, 'ozvinoo_auth_header', 'Authorization', false);
        self::setSetting($pdo, 'ozvinoo_auth_prefix', 'Bearer', false);

        $typeCounts = [
            'stars' => count($seenByType['telegram_stars'] ?? []),
            'premium' => count($seenByType['telegram_premium'] ?? []),
            'number_apps' => count($virtualNumberApplicationIds),
            'number_apps_get' => count($applicationGetItems),
            'number_apps_post' => count($applicationPostItems),
            'number_apps_post_attempted' => $applicationPostAttempted ? 1 : 0,
            'numbers' => count($seenByType['virtual_number'] ?? []),
        ];
        $summary = 'Stars ' . $typeCounts['stars']
            . ' · Premium ' . $typeCounts['premium']
            . ' · Number apps ' . $typeCounts['number_apps']
            . ' (GET ' . $typeCounts['number_apps_get']
            . ($applicationPostAttempted ? ' / POST ' . $typeCounts['number_apps_post'] : ' / POST n/a')
            . ') · Numbers ' . $typeCounts['numbers'];
        $message = $messages === []
            ? 'Official OZVinoo catalogs synchronized. ' . $summary
            : 'Partial sync: ' . $summary . ' | ' . implode(' | ', $messages);
        self::ozvinooMarkProviderSync($pdo, $messages === [] ? 'success' : 'partial', $message);

        return [
            'ok' => true,
            'skipped' => false,
            'created' => $created,
            'updated' => $updated,
            'disabled' => $disabled,
            'message' => $message,
            'catalog_url' => 'https://api.ozvinoo.xyz/telegram-services/stars/',
            'api_style' => 'official-v1',
            'type_counts' => $typeCounts,
        ];
    }

    public static function ozvinooWalletStatus(PDO $pdo): array
    {
        $apiKey = trim(self::setting($pdo, 'ozvinoo_api_key', ''));
        if ($apiKey === '') {
            return [
                'ok' => false,
                'configured' => false,
                'balance' => null,
                'message' => 'API Key عضوینو تنظیم نشده است.',
            ];
        }

        try {
            $response = (new OZVinooClient($apiKey))->balance();
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'configured' => true,
                'balance' => null,
                'message' => $e->getMessage(),
            ];
        }

        $body = self::ozvinooResponseBody($response);
        $balance = self::findScalarByKeys($body, ['balance']);
        return [
            'ok' => !empty($response['ok']) && is_numeric($balance),
            'configured' => true,
            'balance' => is_numeric($balance) ? (float) $balance : null,
            'message' => !empty($response['ok'])
                ? ''
                : trim((string) ($response['message'] ?? 'دریافت موجودی عضوینو ناموفق بود.')),
            'response' => $response,
        ];
    }

    private static function ozvinooResponseBody(array $response): array
    {
        $body = $response['body'] ?? [];
        return is_array($body) ? $body : [];
    }

    private static function ozvinooResponseList(array $response): array
    {
        $body = self::ozvinooResponseBody($response);
        $data = $body['data'] ?? $body;
        if (!is_array($data)) {
            return [];
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        $values = array_values($data);
        if ($values !== [] && count(array_filter($values, 'is_array')) === count($values)) {
            return $values;
        }

        return [];
    }

    private static function ozvinooPositiveInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) floor((float) $value)) : 0;
    }

    private static function ozvinooNumberFromText(string $text): int
    {
        return preg_match('/(\d{1,9})/', $text, $match) ? max(0, (int) $match[1]) : 0;
    }

    private static function ozvinooMarkProviderSync(PDO $pdo, string $status, string $message): void
    {
        $stmt = $pdo->prepare(
            "UPDATE digital_service_providers
             SET last_sync_at = NOW(), last_sync_status = ?, last_sync_message = ?, updated_at = NOW()
             WHERE provider_key = 'ozvinoo'"
        );
        $stmt->execute([$status, mb_substr($message, 0, 500, 'UTF-8')]);
    }

    public static function categoryForProduct(array $product): string
    {
        // Explicit admin/provider category always wins. This lets the dedicated
        // Categories page fully control how every digital service appears.
        $metadata = self::productMetadata($product);
        $key = strtolower(trim((string) ($metadata['category_key'] ?? '')));
        if ($key !== '' && preg_match('/^[a-z0-9_-]{1,40}$/', $key)) {
            return $key;
        }

        $type = (string) ($product['type'] ?? '');
        if ($type === 'telegram_premium') {
            return 'premium';
        }
        if ($type === 'telegram_stars') {
            return 'stars';
        }
        if ($type === 'virtual_number') {
            return 'virtual_number';
        }

        return 'other';
    }

    public static function ensureManagedCategorySchema(PDO $pdo): bool
    {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS digital_service_categories (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    category_key VARCHAR(40) NOT NULL,
                    name VARCHAR(120) NOT NULL,
                    emoji VARCHAR(32) NOT NULL DEFAULT '',
                    sort_order INT NOT NULL DEFAULT 0,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_digital_service_category_key (category_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $defaults = [
                ['premium', 'تلگرام پرمیوم', '🎁', 10],
                ['stars', 'استارز تلگرام', '⭐', 20],
                ['virtual_number', 'شماره مجازی', '📱', 30],
                ['telegram', 'خدمات تلگرام', '✈️', 40],
                ['instagram', 'خدمات اینستاگرام', '📸', 50],
                ['youtube', 'خدمات یوتیوب', '▶️', 60],
                ['twitter', 'خدمات X / توییتر', '𝕏', 70],
                ['tiktok', 'خدمات تیک‌تاک', '🎵', 80],
                ['spotify', 'خدمات اسپاتیفای', '🎧', 90],
                ['linkedin', 'خدمات لینکدین', '💼', 100],
                ['facebook', 'خدمات فیسبوک', '📘', 110],
                ['whatsapp', 'خدمات واتساپ', '🟢', 120],
                ['giftcards', 'گیفت‌کارت', '🎁', 130],
                ['games', 'بازی و شارژ', '🎮', 140],
                ['apple', 'خدمات اپل', '🍎', 150],
                ['chatgpt', 'هوش مصنوعی', '🤖', 160],
                ['design', 'طراحی و گرافیک', '🎨', 170],
                ['other', 'سایر خدمات', '🧩', 999],
            ];

            $renameLegacyVirtualNumber = $pdo->prepare(
                "UPDATE digital_service_categories
                 SET name = 'شماره مجازی', updated_at = NOW()
                 WHERE category_key = 'virtual_number'
                   AND name = 'شماره مجازی تلگرام'"
            );
            $renameLegacyVirtualNumber->execute();

            $count = (int) $pdo->query("SELECT COUNT(*) FROM digital_service_categories")->fetchColumn();
            if ($count === 0) {
                $insert = $pdo->prepare(
                    "INSERT INTO digital_service_categories
                     (category_key, name, emoji, sort_order, active)
                     VALUES (?, ?, ?, ?, 1)"
                );
                foreach ($defaults as [$key, $name, $emoji, $sort]) {
                    $insert->execute([$key, $name, $emoji, $sort]);
                }
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function managedCategories(PDO $pdo, bool $activeOnly = false): array
    {
        self::ensureManagedCategorySchema($pdo);
        try {
            $exists = $pdo->query("SHOW TABLES LIKE 'digital_service_categories'")->fetchColumn();
            if (!$exists) {
                return [];
            }
            $sql = "SELECT * FROM digital_service_categories";
            if ($activeOnly) {
                $sql .= " WHERE active = 1";
            }
            $sql .= " ORDER BY sort_order ASC, id ASC";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function ensureManagedCategories(PDO $pdo): void
    {
        $categories = self::managedCategories($pdo);
        if ($categories === []) {
            return;
        }

        $known = [];
        foreach ($categories as $category) {
            $known[(string) ($category['category_key'] ?? '')] = true;
        }

        $insert = $pdo->prepare(
            "INSERT IGNORE INTO digital_service_categories
             (category_key, name, emoji, sort_order, active)
             VALUES (?, ?, ?, ?, 1)"
        );
        $sort = 500;
        foreach (self::listActive($pdo) as $product) {
            $key = self::categoryForProduct($product);
            if ($key === '' || isset($known[$key])) {
                continue;
            }
            $metadata = self::productMetadata($product);
            $rawLabel = trim((string) ($metadata['category_label'] ?? ''));
            $name = $rawLabel !== '' ? $rawLabel : ucfirst(str_replace(['-', '_'], ' ', $key));
            $insert->execute([$key, $name, '', $sort++]);
            $known[$key] = true;
        }
    }

    public static function managedCategory(PDO $pdo, string $category): ?array
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM digital_service_categories WHERE category_key = ? LIMIT 1"
            );
            $stmt->execute([$category]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function updateProductCategory(PDO $pdo, int $productId, string $category): bool
    {
        $category = strtolower(trim($category));
        if ($productId <= 0 || !preg_match('/^[a-z0-9_-]{1,40}$/', $category)) {
            return false;
        }

        $product = self::findProduct($pdo, $productId, false);
        if (!is_array($product)) {
            return false;
        }

        $metadata = self::productMetadata($product);
        $metadata['category_key'] = $category;
        $metadata['category_label'] = self::categoryLabel($category, $pdo);
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE digital_service_products SET metadata = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$json, $productId]);
        return true;
    }

    public static function categoryLabel(string $category, ?PDO $pdo = null): string
    {
        if ($pdo instanceof PDO) {
            $managed = self::managedCategory($pdo, $category);
            if (is_array($managed)) {
                $emoji = trim((string) ($managed['emoji'] ?? ''));
                $name = trim((string) ($managed['name'] ?? ''));
                if ($name !== '') {
                    return trim($emoji . ' ' . $name);
                }
            }
        }

        $fixed = [
            'premium' => '🎁 تلگرام پرمیوم',
            'stars' => '⭐ استارز تلگرام',
            'telegram' => '✈️ خدمات تلگرام',
            'instagram' => '📸 خدمات اینستاگرام',
            'youtube' => '▶️ خدمات یوتیوب',
            'twitter' => '𝕏 خدمات X / توییتر',
            'tiktok' => '🎵 خدمات تیک‌تاک',
            'spotify' => '🎧 خدمات اسپاتیفای',
            'linkedin' => '💼 خدمات لینکدین',
            'facebook' => '📘 خدمات فیسبوک',
            'whatsapp' => '🟢 خدمات واتساپ',
            'likee' => '💜 خدمات Likee',
            'naver' => '🟩 Naver TV',
            'giftcards' => '🎁 گیفت‌کارت',
            'games' => '🎮 بازی و شارژ',
            'apple' => '🍎 خدمات اپل',
            'chatgpt' => '🤖 هوش مصنوعی',
            'virtual_number' => '📱 شماره مجازی',
            'design' => '🎨 طراحی و گرافیک',
            'other' => '🧩 سایر خدمات',
        ];
        if (isset($fixed[$category])) {
            return $fixed[$category];
        }

        if ($pdo instanceof PDO) {
            foreach (self::listActive($pdo) as $product) {
                if (self::categoryForProduct($product) !== $category) {
                    continue;
                }
                $metadata = self::productMetadata($product);
                $label = trim((string) ($metadata['category_label'] ?? ''));
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return '🛍 خدمات';
    }

    private static function categorySortOrder(PDO $pdo, string $category): int
    {
        $managed = self::managedCategory($pdo, $category);
        if (is_array($managed)) {
            return (int) ($managed['sort_order'] ?? 500);
        }

        return match ($category) {
            'premium' => 10,
            'stars' => 20,
            'virtual_number' => 30,
            'telegram' => 40,
            'instagram' => 50,
            'youtube' => 60,
            'twitter' => 70,
            'tiktok' => 80,
            'spotify' => 90,
            'linkedin' => 100,
            'facebook' => 110,
            'whatsapp' => 120,
            'other' => 999,
            default => 500,
        };
    }

    private static function categoryEnabled(PDO $pdo, string $category): bool
    {
        $managed = self::managedCategory($pdo, $category);
        return !is_array($managed) || (int) ($managed['active'] ?? 1) === 1;
    }

    public static function categoryKeyboard(PDO $pdo, string $backText): string
    {
        self::ensureManagedCategories($pdo);
        $categories = [];
        foreach (self::listActive($pdo) as $product) {
            $category = self::categoryForProduct($product);
            if (!self::categoryEnabled($pdo, $category)) {
                continue;
            }
            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'count' => 0,
                    'label' => self::categoryLabel($category, $pdo),
                    'sort' => self::categorySortOrder($pdo, $category),
                ];
            }
            $categories[$category]['count']++;
        }

        uksort($categories, static function (string $a, string $b) use ($categories): int {
            $pa = (int) ($categories[$a]['sort'] ?? 500);
            $pb = (int) ($categories[$b]['sort'] ?? 500);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp($a, $b);
        });

        if (isset($categories['virtual_number'])) {
            $applicationCount = count(self::virtualNumberApplications($pdo));
            if ($applicationCount > 0) {
                $categories['virtual_number']['count'] = $applicationCount;
            }
        }

        $rows = [];
        foreach ($categories as $category => $info) {
            if ((int) ($info['count'] ?? 0) <= 0) {
                continue;
            }
            $rows[] = [[
                'text' => (string) $info['label'] . ' · ' . number_format((int) $info['count']),
                'callback_data' => 'ds_category:' . $category,
            ]];
        }

        $rows[] = [[
            'text' => $backText,
            'callback_data' => 'backuser',
        ]];

        return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public static function virtualNumberApplications(PDO $pdo): array
    {
        $applications = [];

        foreach (self::listActive($pdo) as $product) {
            if ((string) ($product['type'] ?? '') !== 'virtual_number') {
                continue;
            }
            if (self::categoryForProduct($product) !== 'virtual_number') {
                continue;
            }

            $metadata = self::productMetadata($product);
            $applicationId = max(0, (int) ($metadata['application_id'] ?? $metadata['service_id'] ?? 0));
            $applicationName = trim((string) ($metadata['application_name'] ?? ''));
            $applicationCode = strtolower(trim((string) ($metadata['application_code'] ?? '')));

            if ($applicationName === '') {
                $apiFamily = (string) ($metadata['api_family'] ?? '');
                $isLegacyTelegram = $apiFamily === 'telegram-numbers-v2'
                    || ($apiFamily === 'web-v1' && !isset($metadata['application_name']));
                $applicationName = $isLegacyTelegram ? 'Telegram' : 'سرویس شماره مجازی';
            }
            if ($applicationCode === '') {
                $applicationCode = $applicationName === 'Telegram'
                    ? 'telegram'
                    : ($applicationId > 0 ? ('app_' . $applicationId) : 'virtual_number');
            }

            $key = (string) $applicationId;
            if (!isset($applications[$key])) {
                $applications[$key] = [
                    'id' => $applicationId,
                    'code' => $applicationCode,
                    'name' => $applicationName,
                    'icon' => self::virtualNumberApplicationIcon($applicationName, $applicationCode),
                    'count' => 0,
                    'min_price' => null,
                ];
            }

            $applications[$key]['count']++;
            $price = (float) ($product['price'] ?? 0);
            if ($price > 0 && (
                $applications[$key]['min_price'] === null
                || $price < (float) $applications[$key]['min_price']
            )) {
                $applications[$key]['min_price'] = $price;
            }
        }

        uasort($applications, static function (array $a, array $b): int {
            $aName = strtolower((string) ($a['name'] ?? ''));
            $bName = strtolower((string) ($b['name'] ?? ''));

            $aTelegram = str_contains($aName, 'telegram') || str_contains($aName, 'تلگرام');
            $bTelegram = str_contains($bName, 'telegram') || str_contains($bName, 'تلگرام');
            if ($aTelegram !== $bTelegram) {
                return $aTelegram ? -1 : 1;
            }

            $idCompare = ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            return $idCompare !== 0
                ? $idCompare
                : strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return array_values($applications);
    }

    public static function virtualNumberApplication(PDO $pdo, int $applicationId): ?array
    {
        foreach (self::virtualNumberApplications($pdo) as $application) {
            if ((int) ($application['id'] ?? -1) === $applicationId) {
                return $application;
            }
        }

        return null;
    }

    public static function virtualNumberApplicationsKeyboard(PDO $pdo, string $backText): string
    {
        $buttons = [];
        foreach (self::virtualNumberApplications($pdo) as $application) {
            $buttons[] = [
                'text' => trim(
                    (string) ($application['icon'] ?? '📱')
                    . ' '
                    . (string) ($application['name'] ?? 'سرویس')
                    . ' · '
                    . number_format((int) ($application['count'] ?? 0))
                ),
                'callback_data' => 'ds_vn_app:' . (int) ($application['id'] ?? 0) . ':1',
            ];
        }

        $rows = [];
        foreach (array_chunk($buttons, 2) as $pair) {
            $rows[] = $pair;
        }

        if ($rows === []) {
            $rows[] = [[
                'text' => 'فعلاً شماره‌ای موجود نیست',
                'callback_data' => 'ds_home',
            ]];
        }

        $rows[] = [[
            'text' => '↩️ دسته‌بندی‌ها',
            'callback_data' => 'ds_home',
        ]];
        $rows[] = [[
            'text' => $backText,
            'callback_data' => 'backuser',
        ]];

        return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public static function virtualNumberApplicationCatalog(
        PDO $pdo,
        int $applicationId,
        int $page = 1,
        int $perPage = 18
    ): array {
        $products = [];

        foreach (self::listActive($pdo) as $product) {
            if ((string) ($product['type'] ?? '') !== 'virtual_number') {
                continue;
            }

            $metadata = self::productMetadata($product);
            $productApplicationId = max(0, (int) ($metadata['application_id'] ?? $metadata['service_id'] ?? 0));
            if ($productApplicationId !== $applicationId) {
                continue;
            }

            $products[] = $product;
        }

        usort($products, static function (array $a, array $b): int {
            $aMeta = self::productMetadata($a);
            $bMeta = self::productMetadata($b);
            $aCountry = (string) ($aMeta['country'] ?? $a['name'] ?? '');
            $bCountry = (string) ($bMeta['country'] ?? $b['name'] ?? '');
            return strnatcasecmp($aCountry, $bCountry);
        });

        $count = count($products);
        $perPage = max(6, min(30, $perPage));
        $pages = max(1, (int) ceil($count / $perPage));
        $page = max(1, min($pages, $page));
        $slice = array_slice($products, ($page - 1) * $perPage, $perPage);

        $rows = [];
        foreach ($slice as $product) {
            $metadata = self::productMetadata($product);
            $country = trim((string) ($metadata['country'] ?? ''));
            if ($country === '') {
                $country = trim((string) ($product['name'] ?? 'شماره مجازی'));
            }

            $rows[] = [[
                'text' => '🌍 ' . $country . ' · ' . number_format((float) ($product['price'] ?? 0)) . ' تومان',
                'callback_data' => 'ds_product:' . (int) $product['id'],
            ]];
        }

        if ($pages > 1) {
            $pager = [];
            if ($page > 1) {
                $pager[] = [
                    'text' => '‹ قبلی',
                    'callback_data' => 'ds_vn_app:' . $applicationId . ':' . ($page - 1),
                ];
            }
            $pager[] = [
                'text' => $page . ' / ' . $pages,
                'callback_data' => 'ds_vn_app:' . $applicationId . ':' . $page,
            ];
            if ($page < $pages) {
                $pager[] = [
                    'text' => 'بعدی ›',
                    'callback_data' => 'ds_vn_app:' . $applicationId . ':' . ($page + 1),
                ];
            }
            $rows[] = $pager;
        }

        $rows[] = [[
            'text' => '↩️ پلتفرم‌ها',
            'callback_data' => 'ds_category:virtual_number',
        ]];
        $rows[] = [[
            'text' => '🏠 دسته‌بندی‌ها',
            'callback_data' => 'ds_home',
        ]];

        return [
            'application' => self::virtualNumberApplication($pdo, $applicationId),
            'count' => $count,
            'page' => $page,
            'pages' => $pages,
            'keyboard' => json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE),
        ];
    }

    private static function virtualNumberApplicationIcon(string $name, string $code = ''): string
    {
        $value = mb_strtolower(trim($name . ' ' . $code), 'UTF-8');
        $map = [
            ['telegram', '✈️'], ['تلگرام', '✈️'],
            ['whatsapp', '🟢'], ['واتساپ', '🟢'],
            ['instagram', '📸'], ['اینستاگرام', '📸'],
            ['google', '🔎'], ['گوگل', '🔎'],
            ['facebook', '📘'], ['فیسبوک', '📘'],
            ['discord', '🎮'], ['دیسکورد', '🎮'],
            ['apple', '🍎'], ['اپل', '🍎'],
            ['microsoft', '💻'], ['مایکروسافت', '💻'],
            ['amazon', '📦'], ['آمازون', '📦'],
            ['tiktok', '🎵'], ['تیک تاک', '🎵'], ['تیک‌تاک', '🎵'],
            ['twitter', '𝕏'], ['x.com', '𝕏'], ['توییتر', '𝕏'],
            ['uber', '🚕'], ['اوبر', '🚕'],
            ['linkedin', '💼'], ['لینکدین', '💼'],
            ['steam', '🎮'], ['استیم', '🎮'],
            ['paypal', '💸'], ['پی پال', '💸'], ['پی‌پال', '💸'],
            ['yahoo', '🌀'], ['یاهو', '🌀'],
            ['signal', '📶'], ['سیگنال', '📶'],
            ['wechat', '💬'], ['وی چت', '💬'], ['وی‌چت', '💬'],
        ];

        foreach ($map as [$needle, $icon]) {
            if (str_contains($value, $needle)) {
                return $icon;
            }
        }

        return '📱';
    }

    public static function catalogKeyboard(PDO $pdo, string $backText, ?string $category = null): string
    {
        $rows = [];
        if ($category !== null && !self::categoryEnabled($pdo, $category)) {
            $rows[] = [[
                'text' => '↩️ دسته‌بندی‌ها',
                'callback_data' => 'ds_home',
            ]];
            return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
        }
        foreach (self::listActive($pdo) as $product) {
            if ($category !== null && self::categoryForProduct($product) !== $category) {
                continue;
            }

            $label = sprintf(
                '%s · %s تومان',
                trim((string) $product['name']),
                number_format((float) $product['price'])
            );
            $rows[] = [[
                'text' => $label,
                'callback_data' => 'ds_product:' . (int) $product['id'],
            ]];
        }

        $rows[] = [[
            'text' => $category !== null ? '↩️ دسته‌بندی‌ها' : $backText,
            'callback_data' => $category !== null ? 'ds_home' : 'backuser',
        ]];

        return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }

    public static function productKeyboard(array $product, string $backText): string
    {
        $category = self::categoryForProduct($product);
        $backCallback = 'ds_category:' . $category;
        if ((string) ($product['type'] ?? '') === 'virtual_number') {
            $metadata = self::productMetadata($product);
            $applicationId = max(0, (int) ($metadata['application_id'] ?? $metadata['service_id'] ?? 0));
            $backCallback = 'ds_vn_app:' . $applicationId . ':1';
        }

        return json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '🛒 ثبت سفارش',
                    'callback_data' => 'ds_buy:' . (int) $product['id'],
                    'style' => 'success',
                ]],
                [[
                    'text' => '↩️ بازگشت',
                    'callback_data' => $backCallback,
                ]],
                [[
                    'text' => $backText,
                    'callback_data' => 'backuser',
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function targetKeyboard(array $product): string
    {
        return json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '↩️ بازگشت به سرویس',
                    'callback_data' => 'ds_product:' . (int) $product['id'],
                ]],
                [[
                    'text' => '🏠 دسته‌بندی‌ها',
                    'callback_data' => 'ds_home',
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function confirmKeyboard(int $productId, string $backText): string
    {
        return json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '✅ تأیید و ثبت سفارش',
                    'callback_data' => 'ds_confirm:' . $productId,
                    'style' => 'success',
                ]],
                [[
                    'text' => $backText,
                    'callback_data' => 'ds_home',
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function adminKeyboard(int $orderId): string
    {
        return json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '✅ تأیید و ارسال',
                    'callback_data' => 'ds_approve:' . $orderId,
                    'style' => 'success',
                ]],
                [[
                    'text' => '❌ رد و برگشت وجه',
                    'callback_data' => 'ds_reject:' . $orderId,
                    'style' => 'danger',
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function presetTarget(array $product): ?string
    {
        if ((string) ($product['provider'] ?? '') !== 'ozvinoo'
            || (string) ($product['type'] ?? '') !== 'virtual_number') {
            return null;
        }

        $metadata = self::productMetadata($product);
        $countryId = trim((string) ($metadata['country_id'] ?? $product['provider_service_code'] ?? ''));
        if ($countryId === '') {
            return null;
        }

        return 'country:' . $countryId;
    }

    public static function validateTarget(array $product, string $target): array
    {
        $presetTarget = self::presetTarget($product);
        if ($presetTarget !== null) {
            return [true, $presetTarget];
        }
        $target = trim($target);
        if ($target === '' || mb_strlen($target, 'UTF-8') > 255) {
            return [false, 'شناسه مقصد معتبر نیست.'];
        }

        $type = (string) ($product['type'] ?? '');
        $provider = (string) ($product['provider'] ?? 'manual');

        if ($provider === 'ozvinoo' && in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            $normalized = ltrim($target, '@');
            if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $normalized)) {
                return [false, 'یوزرنیم معتبر تلگرام را وارد کنید؛ مثال: @username'];
            }
            return [true, $normalized];
        }

        if ($provider === 'tgtools' && in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            $normalized = ltrim($target, '@');
            if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $normalized)) {
                return [false, 'یوزرنیم معتبر تلگرام را وارد کنید؛ مثال: @username'];
            }
            return [true, $normalized];
        }

        if ($type === 'telegram_premium' && $provider === 'telegram_bot') {
            $normalized = ltrim($target, '@');
            if (!ctype_digit($normalized) || (int) $normalized <= 0) {
                return [false, 'شناسه عددی معتبر تلگرام را وارد کنید.'];
            }
            return [true, $normalized];
        }

        if (in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            if (!preg_match('/^@?[A-Za-z0-9_]{5,32}$/', $target) && !ctype_digit($target)) {
                return [false, 'یوزرنیم یا Telegram User ID معتبر وارد کنید.'];
            }
        }

        return [true, $target];
    }

    public static function createWalletOrder(PDO $pdo, array $user, array $product, string $target): array
    {
        $userId = trim((string) ($user['id'] ?? ''));
        $productId = (int) ($product['id'] ?? 0);
        $price = (int) ($product['price'] ?? 0);

        if ($userId === '' || $productId <= 0 || $price <= 0) {
            throw new RuntimeException('Invalid digital service order payload.');
        }

        [$valid, $targetOrError] = self::validateTarget($product, $target);
        if (!$valid) {
            throw new InvalidArgumentException($targetOrError);
        }
        $target = (string) $targetOrError;

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT * FROM user WHERE id = ? FOR UPDATE");
            $lock->execute([$userId]);
            $freshUser = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($freshUser)) {
                throw new RuntimeException('User not found.');
            }

            // The user row is the order-intent lock. This makes the confirm
            // button idempotent even when Telegram delivers duplicate callbacks.
            $flowData = json_decode((string) ($freshUser['Processing_value'] ?? ''), true);
            $flowData = is_array($flowData) ? $flowData : [];
            $flowProductId = (int) ($flowData['digital_service_id'] ?? 0);
            $flowTarget = trim((string) ($flowData['digital_service_target'] ?? ''));
            [$flowTargetValid, $normalizedFlowTarget] = self::validateTarget($product, $flowTarget);

            if ((string) ($freshUser['step'] ?? '') !== 'digital_service_confirm'
                || $flowProductId !== $productId
                || !$flowTargetValid
                || (string) $normalizedFlowTarget !== $target) {
                throw new DomainException('ORDER_STATE_INVALID');
            }

            $freshProductStmt = $pdo->prepare(
                "SELECT * FROM digital_service_products WHERE id = ? AND active = 1 FOR UPDATE"
            );
            $freshProductStmt->execute([$productId]);
            $freshProduct = $freshProductStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($freshProduct)) {
                throw new RuntimeException('Service is unavailable.');
            }

            [$freshTargetValid, $freshTargetOrError] = self::validateTarget($freshProduct, $target);
            if (!$freshTargetValid) {
                throw new InvalidArgumentException((string) $freshTargetOrError);
            }
            $target = (string) $freshTargetOrError;

            $freshPrice = (int) ($freshProduct['price'] ?? 0);
            if ($freshPrice <= 0) {
                throw new RuntimeException('Service price is invalid.');
            }

            $minBalance = ($freshUser['agent'] ?? 'f') === 'n2' && (int) ($freshUser['maxbuyagent'] ?? 0) !== 0
                ? -(int) $freshUser['maxbuyagent']
                : 0;
            $balance = (int) ($freshUser['Balance'] ?? 0);
            if ($balance - $freshPrice < $minBalance) {
                throw new DomainException('INSUFFICIENT_BALANCE');
            }

            // Claim the intent before money moves. A second concurrent click
            // waits for this row lock and then sees that the intent is consumed.
            $claimIntent = $pdo->prepare(
                "UPDATE user
                 SET step = 'digital_service_processing', Processing_value = '0'
                 WHERE id = ?"
            );
            $claimIntent->execute([$userId]);

            $debit = $pdo->prepare("UPDATE user SET Balance = Balance - ? WHERE id = ?");
            $debit->execute([$freshPrice, $userId]);
            if ($debit->rowCount() !== 1) {
                throw new RuntimeException('Unable to debit wallet.');
            }

            $orderCode = 'DS-' . strtoupper(bin2hex(random_bytes(6)));
            $insert = $pdo->prepare(
                "INSERT INTO digital_service_orders
                (order_code, user_id, service_id, service_code, service_name, target, amount, quantity, provider, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $orderCode,
                $userId,
                $productId,
                (string) $freshProduct['code'],
                (string) $freshProduct['name'],
                $target,
                $freshPrice,
                max(1, (int) ($freshProduct['service_value'] ?? 1)),
                (string) ($freshProduct['provider'] ?? 'manual'),
                self::STATUS_PENDING,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $finishIntent = $pdo->prepare(
                "UPDATE user SET step = 'home', Processing_value = '0' WHERE id = ?"
            );
            $finishIntent->execute([$userId]);

            $pdo->commit();
            clearSelectCache('user');

            return self::findOrder($pdo, $orderId) ?? [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function findOrder(PDO $pdo, int $orderId): ?array
    {
        if ($orderId <= 0 || !self::isAvailable($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function notifyAdmins(PDO $pdo, array $order): void
    {
        $admins = select('admin', 'id_admin', null, null, 'FETCH_COLUMN');
        if (!is_array($admins)) {
            return;
        }

        $text = self::adminOrderText($order);
        $keyboard = self::adminKeyboard((int) $order['id']);
        foreach (array_unique(array_map('strval', $admins)) as $adminId) {
            if ($adminId === '' || $adminId === '0') {
                continue;
            }
            sendmessage($adminId, $text, $keyboard, 'HTML');
        }
    }

    public static function adminOrderText(array $order): string
    {
        return "🛍 <b>سفارش فروش خدمات</b>

"
            . "🧾 کد: <code>" . self::escape((string) ($order['order_code'] ?? '—')) . "</code>
"
            . "👤 کاربر: <code>" . self::escape((string) ($order['user_id'] ?? '—')) . "</code>
"
            . "📦 سرویس: <b>" . self::escape((string) ($order['service_name'] ?? '—')) . "</b>
"
            . "🎯 مقصد: <code>" . self::escape((string) ($order['target'] ?? '—')) . "</code>
"
            . "💳 مبلغ: <b>" . number_format((float) ($order['amount'] ?? 0)) . " تومان</b>
"
            . "🔌 Provider: <code>" . self::escape((string) ($order['provider'] ?? 'manual')) . "</code>

"
            . "ارسال فقط بعد از زدن «تأیید و ارسال» انجام می‌شود.";
    }

    public static function approveAndDeliver(PDO $pdo, int $orderId, string $adminId): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found.');
            }

            $status = (string) ($order['status'] ?? '');
            if ($status === self::STATUS_DELIVERED) {
                $pdo->commit();
                return ['ok' => true, 'already_done' => true, 'order' => $order];
            }
            if (!in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
                throw new RuntimeException('Order is not ready for delivery.');
            }
            if ($status === self::STATUS_FAILED && (int) ($order['refunded'] ?? 0) === 1) {
                throw new RuntimeException('This failed order was already refunded. Create a new order before retrying.');
            }

            $claim = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, admin_id = ?, approved_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            );
            $claim->execute([self::STATUS_PROCESSING, $adminId, $orderId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $order = self::findOrder($pdo, $orderId);
        if (!is_array($order)) {
            throw new RuntimeException('Order disappeared after approval.');
        }

        $product = self::findProduct($pdo, (int) $order['service_id'], false);
        if (!is_array($product)) {
            return self::failAndRefundProviderOrder(
                $pdo,
                $orderId,
                'Product snapshot is no longer available.',
                []
            );
        }

        try {
            $delivery = self::deliver($pdo, $order, $product);
        } catch (Throwable $e) {
            return self::markRetryableProviderFailure(
                $pdo,
                $orderId,
                $e->getMessage(),
                [],
                ['retryable' => true]
            );
        }

        if (empty($delivery['ok'])) {
            $error = (string) ($delivery['error'] ?? 'Unknown provider error');
            $response = is_array($delivery['response'] ?? null) ? $delivery['response'] : $delivery;

            if (!empty($delivery['retryable'])) {
                return self::markRetryableProviderFailure(
                    $pdo,
                    $orderId,
                    $error,
                    $response,
                    $delivery
                );
            }

            return self::failAndRefundProviderOrder(
                $pdo,
                $orderId,
                $error,
                $response
            );
        }

        if (!empty($delivery['pending'])) {
            $responseJson = json_encode($delivery['response'] ?? $delivery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $reference = trim((string) ($delivery['reference'] ?? ''));
            $pending = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, provider_reference = ?, provider_response = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $pending->execute([
                self::STATUS_PROCESSING,
                $reference !== '' ? $reference : null,
                is_string($responseJson) ? $responseJson : null,
                $orderId,
            ]);

            $processingOrder = self::findOrder($pdo, $orderId) ?? $order;
            $pendingMessage = trim((string) ($delivery['customer_message_pending'] ?? ''));
            if ($pendingMessage === '') {
                $pendingMessage = "⏳ <b>سفارش شما تأیید شد و در حال ارسال است</b>\n\n"
                    . "🧾 کد: <code>" . self::escape((string) $processingOrder['order_code']) . "</code>\n"
                    . "📦 " . self::escape((string) $processingOrder['service_name']);
            }
            sendmessage(
                (string) $processingOrder['user_id'],
                $pendingMessage,
                null,
                'HTML'
            );

            return ['ok' => true, 'pending' => true, 'order' => $processingOrder, 'delivery' => $delivery];
        }

        return self::finalizeDeliveredOrder($pdo, $orderId, $delivery);
    }

    public static function rejectAndRefund(PDO $pdo, int $orderId, string $adminId): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found.');
            }

            $status = (string) ($order['status'] ?? '');
            if ($status === self::STATUS_REJECTED) {
                $pdo->commit();
                return ['ok' => true, 'already_done' => true, 'order' => $order];
            }
            if ($status === self::STATUS_DELIVERED) {
                throw new RuntimeException('Delivered order cannot be refunded automatically.');
            }
            if ($status === self::STATUS_PROCESSING) {
                throw new RuntimeException('Order is being processed. Retry after delivery state is known.');
            }

            if ((int) ($order['refunded'] ?? 0) !== 1) {
                $refund = $pdo->prepare("UPDATE user SET Balance = Balance + ? WHERE id = ?");
                $refund->execute([(int) $order['amount'], (string) $order['user_id']]);
                if ($refund->rowCount() !== 1) {
                    throw new RuntimeException('Refund failed.');
                }
            }

            $update = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, refunded = 1, admin_id = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([self::STATUS_REJECTED, $adminId, $orderId]);
            $pdo->commit();
            clearSelectCache('user');

            $finalOrder = self::findOrder($pdo, $orderId) ?? $order;
            sendmessage(
                (string) $finalOrder['user_id'],
                "❌ <b>سفارش رد شد و مبلغ به کیف پول برگشت</b>

"
                    . "🧾 کد: <code>" . self::escape((string) $finalOrder['order_code']) . "</code>
"
                    . "💰 مبلغ برگشتی: <b>" . number_format((float) $finalOrder['amount']) . " تومان</b>",
                null,
                'HTML'
            );

            return ['ok' => true, 'order' => $finalOrder];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function deliver(PDO $pdo, array $order, array $product): array
    {
        $provider = (string) ($product['provider'] ?? 'manual');
        $type = (string) ($product['type'] ?? '');

        if ($provider === 'manual') {
            return [
                'ok' => true,
                'reference' => 'manual:' . ($order['order_code'] ?? $order['id']),
                'response' => ['mode' => 'manual', 'confirmed_by_admin' => true],
            ];
        }

        if ($provider === 'telegram_bot' && $type === 'telegram_premium') {
            $months = (int) ($product['service_value'] ?? 0);
            $starsByMonths = [3 => 1000, 6 => 1500, 12 => 2500];
            if (!isset($starsByMonths[$months])) {
                return ['ok' => false, 'error' => 'Premium month_count must be 3, 6, or 12.'];
            }

            $target = ltrim((string) $order['target'], '@');
            if (!ctype_digit($target) || (int) $target <= 0) {
                return ['ok' => false, 'error' => 'Telegram Premium auto-delivery requires numeric user_id.'];
            }

            $response = telegram('giftPremiumSubscription', [
                'user_id' => (int) $target,
                'month_count' => $months,
                'star_count' => $starsByMonths[$months],
                'text' => 'هدیه Premium از طرف فروشگاه',
            ]);

            return [
                'ok' => is_array($response) && !empty($response['ok']),
                'reference' => 'telegram:' . $target . ':' . $months,
                'response' => $response,
                'error' => is_array($response) ? (string) ($response['description'] ?? '') : 'Telegram API request failed.',
            ];
        }

        if ($provider === 'tgtools') {
            return self::deliverTgTools($pdo, $order, $product);
        }

        if ($provider === 'ozvinoo') {
            return self::deliverOZVinoo($pdo, $order, $product);
        }

        $registeredProvider = BluebotProviderCatalogService::findProvider($pdo, $provider);
        if (is_array($registeredProvider)) {
            return [
                'ok' => true,
                'reference' => 'manual-provider:' . $provider . ':' . ($order['order_code'] ?? $order['id']),
                'response' => [
                    'mode' => 'manual',
                    'provider' => $provider,
                    'confirmed_by_admin' => true,
                ],
            ];
        }

        return ['ok' => false, 'error' => 'Unsupported digital service provider.'];
    }

    public static function reconcileOZVinooProcessing(PDO $pdo, int $limit = 25): array
    {
        $stats = ['checked' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];

        if (!self::isAvailable($pdo)) {
            return $stats;
        }

        $apiKey = trim(self::setting($pdo, 'ozvinoo_api_key', ''));
        if ($apiKey === '') {
            return $stats;
        }

        $limit = max(1, min(100, $limit));
        $stmt = $pdo->query(
            "SELECT * FROM digital_service_orders
             WHERE provider = 'ozvinoo'
               AND status = 'processing'
               AND provider_reference IS NOT NULL
               AND provider_reference <> ''
             ORDER BY id ASC
             LIMIT " . $limit
        );
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $client = new OZVinooClient($apiKey);

        foreach ($orders as $order) {
            $stats['checked']++;
            $product = self::findProduct($pdo, (int) ($order['service_id'] ?? 0), false);
            if (!is_array($product)) {
                $stats['errors']++;
                continue;
            }

            $type = (string) ($product['type'] ?? '');
            $reference = trim((string) ($order['provider_reference'] ?? ''));
            if ($reference === '') {
                $stats['errors']++;
                continue;
            }

            try {
                if ($type === 'telegram_stars' || $type === 'telegram_premium') {
                    $service = $type === 'telegram_stars' ? 'stars' : 'premium';
                    $response = $client->telegramOrderStatus($service, $reference);
                    if (empty($response['ok'])) {
                        $stats['errors']++;
                        continue;
                    }

                    $body = self::ozvinooResponseBody($response);
                    $data = is_array($body['data'] ?? null) ? $body['data'] : [];
                    $state = $data['status'] ?? null;
                    if ($state === true || in_array(strtolower((string) $state), ['ok', 'done', 'success', 'completed', 'delivered'], true)) {
                        self::finalizeDeliveredOrder($pdo, (int) $order['id'], [
                            'ok' => true,
                            'reference' => $reference,
                            'response' => $response,
                        ]);
                        $stats['completed']++;
                        continue;
                    }

                    if (in_array(strtolower((string) $state), ['failed', 'error', 'cancel', 'cancelled', 'canceled', 'rejected'], true)) {
                        self::failAndRefundProviderOrder(
                            $pdo,
                            (int) $order['id'],
                            'OZVinoo order failed.',
                            $response
                        );
                        $stats['failed']++;
                        continue;
                    }

                    $stats['pending']++;
                    continue;
                }

                if ($type === 'virtual_number') {
                    $metadata = self::productMetadata($product);
                    $apiFamily = (string) ($metadata['api_family'] ?? 'telegram-numbers-v2');
                    $response = $apiFamily === 'web-v1'
                        ? $client->getCodeV1($reference)
                        : $client->numberStatus($reference);
                    $http = (int) ($response['http_status'] ?? 0);
                    if ($http === 202) {
                        $stats['pending']++;
                        continue;
                    }
                    if (empty($response['ok'])) {
                        $stats['errors']++;
                        continue;
                    }

                    $body = self::ozvinooResponseBody($response);
                    $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
                    $code = trim((string) ($data['code'] ?? ''));
                    $number = trim((string) ($data['number'] ?? ''));
                    $state = strtolower(trim((string) ($data['status'] ?? '')));

                    if ($code !== '') {
                        $message = "✅ <b>شماره مجازی شما آماده است</b>\n\n"
                            . "📱 شماره: <code>" . self::escape($number !== '' ? $number : '—') . "</code>\n"
                            . "🔐 کد ورود: <code>" . self::escape($code) . "</code>\n"
                            . "🧾 سفارش: <code>" . self::escape((string) $order['order_code']) . "</code>";

                        self::finalizeDeliveredOrder($pdo, (int) $order['id'], [
                            'ok' => true,
                            'reference' => $reference,
                            'response' => $response,
                            'customer_message' => $message,
                        ]);
                        $stats['completed']++;
                        continue;
                    }

                    if (in_array($state, ['cancel', 'cancelled', 'canceled', 'failed', 'error'], true)
                        || str_contains(strtolower((string) ($body['message'] ?? '')), 'cancel')) {
                        self::failAndRefundProviderOrder(
                            $pdo,
                            (int) $order['id'],
                            'OZVinoo virtual-number order was cancelled.',
                            $response
                        );
                        $stats['failed']++;
                        continue;
                    }

                    $stats['pending']++;
                    continue;
                }

                $stats['errors']++;
            } catch (Throwable $e) {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    private static function deliverOZVinooOfficial(PDO $pdo, array $order, array $product, string $apiKey): array
    {
        if ($apiKey === '') {
            return [
                'ok' => false,
                'retryable' => true,
                'code' => 'OZVINOO_API_KEY_MISSING',
                'error' => 'API Key عضوینو تنظیم نشده است.',
            ];
        }

        $type = (string) ($product['type'] ?? '');
        $client = new OZVinooClient($apiKey);

        try {
            if ($type === 'telegram_stars') {
                $username = ltrim(trim((string) ($order['target'] ?? '')), '@');
                if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
                    return ['ok' => false, 'error' => 'یوزرنیم تلگرام مقصد معتبر نیست.'];
                }
                $response = $client->buyStars(max(50, (int) ($product['service_value'] ?? 50)), $username);
            } elseif ($type === 'telegram_premium') {
                $username = ltrim(trim((string) ($order['target'] ?? '')), '@');
                if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
                    return ['ok' => false, 'error' => 'یوزرنیم تلگرام مقصد معتبر نیست.'];
                }
                $packageId = (int) ($product['provider_service_code'] ?? 0);
                if ($packageId <= 0) {
                    return ['ok' => false, 'retryable' => true, 'error' => 'شناسه بسته Premium عضوینو پیدا نشد.'];
                }
                $response = $client->buyPremium($packageId, $username);
            } elseif ($type === 'virtual_number') {
                $metadata = self::productMetadata($product);
                $apiFamily = (string) ($metadata['api_family'] ?? 'telegram-numbers-v2');
                if ($apiFamily === 'web-v1') {
                    $serviceId = max(0, (int) ($metadata['service_id'] ?? 0));
                    $range = trim((string) ($metadata['range'] ?? ''));
                    if ($serviceId <= 0 || $range === '') {
                        return ['ok' => false, 'retryable' => true, 'error' => 'اطلاعات سرویس/کشور شماره مجازی عضوینو کامل نیست.'];
                    }
                    $response = $client->getNumberV1($serviceId, $range);
                } else {
                    $countryId = trim((string) ($metadata['country_id'] ?? $product['provider_service_code'] ?? ''));
                    if ($countryId === '') {
                        return ['ok' => false, 'retryable' => true, 'error' => 'شناسه کشور شماره مجازی عضوینو پیدا نشد.'];
                    }
                    $response = $client->buyNumber($countryId, true);
                }
            } else {
                return ['ok' => false, 'error' => 'Unsupported OZVinoo official service type.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'retryable' => true, 'error' => $e->getMessage()];
        }

        $http = (int) ($response['http_status'] ?? 0);
        if (empty($response['ok'])) {
            return [
                'ok' => false,
                'retryable' => self::ozvinooRetryableHttp($http),
                'error' => trim((string) ($response['message'] ?? '')) ?: 'درخواست عضوینو ناموفق بود.',
                'response' => $response,
            ];
        }

        $body = self::ozvinooResponseBody($response);
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $reference = '';

        if ($type === 'virtual_number') {
            $reference = trim((string) ($data['order_id'] ?? $data['request_id'] ?? $data['id'] ?? ''));
        } else {
            $reference = trim((string) ($data['code'] ?? $data['order_id'] ?? $data['id'] ?? ''));
        }

        if ($reference === '') {
            return [
                'ok' => false,
                'retryable' => true,
                'error' => 'عضوینو شناسه پیگیری سفارش برنگرداند.',
                'response' => $response,
            ];
        }

        $result = [
            'ok' => true,
            'pending' => true,
            'reference' => $reference,
            'response' => $response,
        ];

        if ($type === 'virtual_number') {
            $number = trim((string) ($data['number'] ?? ''));
            if ($number !== '') {
                $result['customer_message_pending'] = "📱 <b>شماره برای شما رزرو شد</b>\n\n"
                    . "شماره: <code>" . self::escape($number) . "</code>\n"
                    . "⏳ در انتظار دریافت کد ورود هستیم. به‌محض آماده‌شدن، کد خودکار برای شما ارسال می‌شود.";
            }
        }

        return $result;
    }

    private static function ozvinooRetryableHttp(int $http): bool
    {
        return $http === 0 || in_array($http, [401, 402, 408, 425, 429], true) || $http >= 500;
    }

    public static function reconcileTgToolsProcessing(PDO $pdo, int $limit = 25): array
    {
        $stats = ['checked' => 0, 'completed' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];

        if (!self::isAvailable($pdo)) {
            return $stats;
        }

        $apiKey = trim(self::setting($pdo, 'tgtools_api_key', ''));
        if ($apiKey === '') {
            return $stats;
        }

        $limit = max(1, min(100, $limit));
        $stmt = $pdo->query(
            "SELECT * FROM digital_service_orders
             WHERE provider = 'tgtools'
               AND status = 'processing'
               AND provider_reference IS NOT NULL
               AND provider_reference <> ''
             ORDER BY id ASC
             LIMIT " . $limit
        );
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $client = new TgToolsClient($apiKey);

        foreach ($orders as $order) {
            $stats['checked']++;
            $transactionId = (int) ($order['provider_reference'] ?? 0);
            if ($transactionId <= 0) {
                $stats['errors']++;
                continue;
            }

            $statusResponse = $client->purchaseStatus($transactionId);
            if (empty($statusResponse['ok'])) {
                $stats['errors']++;
                continue;
            }

            $data = is_array($statusResponse['data'] ?? null) ? $statusResponse['data'] : [];
            $status = self::tgToolsStatus($data);

            if ($status === 'completed') {
                $result = self::finalizeDeliveredOrder($pdo, (int) $order['id'], [
                    'ok' => true,
                    'reference' => (string) $transactionId,
                    'response' => $statusResponse,
                ]);
                if (!empty($result['ok'])) {
                    $stats['completed']++;
                } else {
                    $stats['errors']++;
                }
                continue;
            }

            if ($status === 'failed') {
                self::failAndRefundProviderOrder(
                    $pdo,
                    (int) $order['id'],
                    'TGTools delivery failed.',
                    $statusResponse
                );
                $stats['failed']++;
                continue;
            }

            $stats['pending']++;
        }

        return $stats;
    }

    private static function deliverTgTools(PDO $pdo, array $order, array $product): array
    {
        $apiKey = trim(self::setting($pdo, 'tgtools_api_key', ''));
        if ($apiKey === '') {
            return [
                'ok' => false,
                'retryable' => true,
                'code' => 'TGTOOLS_API_KEY_MISSING',
                'error' => 'کلید API تی‌جی‌تولز تنظیم نشده است.',
            ];
        }

        $type = (string) ($product['type'] ?? '');
        if (!in_array($type, ['telegram_stars', 'telegram_premium'], true)) {
            return ['ok' => false, 'error' => 'TGTools provider only supports Telegram Stars and Premium.'];
        }

        $username = ltrim(trim((string) ($order['target'] ?? '')), '@');
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
            return ['ok' => false, 'error' => 'یوزرنیم تلگرام مقصد معتبر نیست.'];
        }

        $client = new TgToolsClient($apiKey);
        $wallet = self::tgToolsWalletStatus($pdo);
        $metadata = self::productMetadata($product);
        $requiredTon = is_numeric($metadata['wholesale_ton'] ?? null)
            ? (float) $metadata['wholesale_ton']
            : null;
        $balanceTon = is_numeric($wallet['balance_ton'] ?? null)
            ? (float) $wallet['balance_ton']
            : null;

        if (!empty($wallet['ok'])
            && $requiredTon !== null
            && $requiredTon > 0
            && $balanceTon !== null
            && $balanceTon + 0.000000001 < $requiredTon) {
            return [
                'ok' => false,
                'retryable' => true,
                'code' => 'TGTOOLS_WALLET_INSUFFICIENT',
                'error' => self::tgToolsInsufficientWalletMessage($wallet, $requiredTon),
                'response' => ['wallet' => $wallet, 'required_ton' => $requiredTon],
            ];
        }

        $lookup = $client->lookupUser($username);
        if (empty($lookup['ok'])) {
            return [
                'ok' => false,
                'retryable' => ((int) ($lookup['http_status'] ?? 0)) >= 500 || (int) ($lookup['http_status'] ?? 0) === 0,
                'error' => trim((string) ($lookup['message'] ?? '')) ?: 'اعتبارسنجی یوزرنیم در تی‌جی‌تولز ناموفق بود.',
                'response' => $lookup,
            ];
        }

        $profile = is_array($lookup['data'] ?? null) ? $lookup['data'] : [];
        if (array_key_exists('found', $profile) && !$profile['found']) {
            return ['ok' => false, 'error' => 'یوزرنیم تلگرام در تی‌جی‌تولز پیدا نشد.', 'response' => $lookup];
        }

        $serviceValue = max(1, (int) ($product['service_value'] ?? 1));
        $trackingCode = (string) ($order['order_code'] ?? ('bluebot-' . (int) ($order['id'] ?? 0)));

        if ($type === 'telegram_premium') {
            if (!in_array($serviceValue, [3, 6, 12], true)) {
                return ['ok' => false, 'error' => 'مدت Premium باید ۳، ۶ یا ۱۲ ماه باشد.'];
            }
            if (array_key_exists('premiumEligible', $profile) && !$profile['premiumEligible']) {
                return ['ok' => false, 'error' => 'این حساب تلگرام واجد شرایط دریافت Premium نیست.', 'response' => $lookup];
            }
            $purchase = $client->purchasePremium($username, $serviceValue, $trackingCode);
        } else {
            $purchase = $client->purchaseStars($username, $serviceValue, $trackingCode);
        }

        if (empty($purchase['ok'])) {
            $message = trim((string) ($purchase['message'] ?? ''));
            $httpStatus = (int) ($purchase['http_status'] ?? 0);
            $lowerMessage = strtolower($message);
            $insufficient = str_contains($lowerMessage, 'insufficient wallet balance')
                || str_contains($lowerMessage, 'insufficient balance');

            if ($insufficient) {
                $freshWallet = self::tgToolsWalletStatus($pdo);
                return [
                    'ok' => false,
                    'retryable' => true,
                    'code' => 'TGTOOLS_WALLET_INSUFFICIENT',
                    'error' => self::tgToolsInsufficientWalletMessage($freshWallet, $requiredTon),
                    'response' => ['purchase' => $purchase, 'wallet' => $freshWallet, 'required_ton' => $requiredTon],
                ];
            }

            return [
                'ok' => false,
                'retryable' => $httpStatus === 0 || $httpStatus >= 500,
                'error' => $message !== '' ? $message : 'درخواست خرید از تی‌جی‌تولز ناموفق بود.',
                'response' => $purchase,
            ];
        }

        $data = is_array($purchase['data'] ?? null) ? $purchase['data'] : [];
        $transactionId = (int) ($data['transactionId'] ?? $data['id'] ?? 0);
        if ($transactionId <= 0) {
            return [
                'ok' => false,
                'retryable' => true,
                'error' => 'تی‌جی‌تولز شناسه تراکنش برنگرداند؛ قبل از تلاش مجدد تاریخچه تراکنش‌ها را بررسی کنید.',
                'response' => $purchase,
            ];
        }

        $status = self::tgToolsStatus($data);
        if ($status === 'failed') {
            return [
                'ok' => false,
                'error' => trim((string) ($data['message'] ?? $purchase['message'] ?? 'تی‌جی‌تولز سفارش را رد کرد.')),
                'reference' => (string) $transactionId,
                'response' => $purchase,
            ];
        }

        return [
            'ok' => true,
            'pending' => $status !== 'completed',
            'reference' => (string) $transactionId,
            'response' => $purchase,
        ];
    }

    private static function tgToolsStatus(array $data): string
    {
        $raw = strtolower(trim((string) ($data['status'] ?? '')));
        if (in_array($raw, ['completed', 'complete', 'delivered', 'success', 'succeeded'], true)) {
            return 'completed';
        }
        if (in_array($raw, ['failed', 'error', 'rejected', 'cancelled', 'canceled', 'refunded'], true)) {
            return 'failed';
        }
        return 'pending';
    }

    private static function finalizeDeliveredOrder(PDO $pdo, int $orderId, array $delivery): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found during delivery finalization.');
            }

            if ((string) ($order['status'] ?? '') === self::STATUS_DELIVERED) {
                $pdo->commit();
                return ['ok' => true, 'already_done' => true, 'order' => $order, 'delivery' => $delivery];
            }

            if ((string) ($order['status'] ?? '') !== self::STATUS_PROCESSING) {
                throw new RuntimeException('Order is not processing.');
            }

            $responseJson = json_encode($delivery['response'] ?? $delivery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $reference = trim((string) ($delivery['reference'] ?? $order['provider_reference'] ?? ''));

            $done = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, provider_reference = ?, provider_response = ?, delivered_at = NOW(), updated_at = NOW()
                 WHERE id = ?"
            );
            $done->execute([
                self::STATUS_DELIVERED,
                $reference !== '' ? $reference : null,
                is_string($responseJson) ? $responseJson : null,
                $orderId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $finalOrder = self::findOrder($pdo, $orderId) ?? $order;
        $customerMessage = trim((string) ($delivery['customer_message'] ?? ''));
        if ($customerMessage === '') {
            $customerMessage = "✅ <b>سفارش شما ارسال شد</b>\n\n"
                . "🧾 کد: <code>" . self::escape((string) $finalOrder['order_code']) . "</code>\n"
                . "📦 " . self::escape((string) $finalOrder['service_name']) . "\n"
                . "🎯 <code>" . self::escape((string) $finalOrder['target']) . "</code>";
        }
        sendmessage(
            (string) $finalOrder['user_id'],
            $customerMessage,
            null,
            'HTML'
        );

        return ['ok' => true, 'order' => $finalOrder, 'delivery' => $delivery];
    }

    private static function markRetryableProviderFailure(
        PDO $pdo,
        int $orderId,
        string $error,
        array $response,
        array $delivery = []
    ): array {
        $payload = json_encode(
            [
                'error' => $error,
                'retryable' => true,
                'response' => $response,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $stmt = $pdo->prepare(
            "UPDATE digital_service_orders
             SET status = ?, refunded = 0, provider_response = ?, updated_at = NOW()
             WHERE id = ? AND status = ?"
        );
        $stmt->execute([
            self::STATUS_FAILED,
            is_string($payload) ? $payload : $error,
            $orderId,
            self::STATUS_PROCESSING,
        ]);

        return [
            'ok' => false,
            'retryable' => true,
            'refunded' => false,
            'order' => self::findOrder($pdo, $orderId),
            'error' => $error,
            'code' => (string) ($delivery['code'] ?? ''),
            'delivery' => $delivery,
        ];
    }

    private static function failAndRefundProviderOrder(PDO $pdo, int $orderId, string $error, array $response): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM digital_service_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found during provider failure handling.');
            }

            if ((string) ($order['status'] ?? '') !== self::STATUS_PROCESSING) {
                $pdo->commit();
                return ['ok' => true, 'already_done' => true, 'order' => $order];
            }

            if ((int) ($order['refunded'] ?? 0) !== 1) {
                $refund = $pdo->prepare("UPDATE user SET Balance = Balance + ? WHERE id = ?");
                $refund->execute([(int) $order['amount'], (string) $order['user_id']]);
                if ($refund->rowCount() !== 1) {
                    throw new RuntimeException('Provider failure refund could not be credited.');
                }
            }

            $payload = json_encode(
                ['error' => $error, 'response' => $response],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $update = $pdo->prepare(
                "UPDATE digital_service_orders
                 SET status = ?, refunded = 1, provider_response = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $update->execute([
                self::STATUS_FAILED,
                is_string($payload) ? $payload : $error,
                $orderId,
            ]);
            $pdo->commit();
            clearSelectCache('user');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $finalOrder = self::findOrder($pdo, $orderId) ?? $order;
        sendmessage(
            (string) $finalOrder['user_id'],
            "❌ <b>ارسال سفارش ناموفق بود</b>\n\n"
                . "🧾 کد: <code>" . self::escape((string) $finalOrder['order_code']) . "</code>\n"
                . "💰 مبلغ سفارش به کیف پول شما برگشت داده شد.",
            null,
            'HTML'
        );

        return ['ok' => false, 'refunded' => true, 'order' => $finalOrder, 'error' => $error];
    }

    private static function deliverOZVinoo(PDO $pdo, array $order, array $product): array
    {
        $baseUrl = rtrim(self::setting($pdo, 'ozvinoo_base_url', 'https://api.ozvinoo.xyz'), '/');
        $orderPath = trim(self::setting($pdo, 'ozvinoo_order_path', ''));
        $apiKey = trim(self::setting($pdo, 'ozvinoo_api_key', ''));
        $authHeader = trim(self::setting($pdo, 'ozvinoo_auth_header', 'Authorization'));
        $authPrefix = trim(self::setting($pdo, 'ozvinoo_auth_prefix', 'Bearer'));
        $apiStyle = strtolower(trim(self::setting($pdo, 'ozvinoo_api_style', 'rest')));
        $serviceCode = trim((string) ($product['provider_service_code'] ?? ''));
        $metadata = self::productMetadata($product);

        if (in_array((string) ($product['type'] ?? ''), ['telegram_stars', 'telegram_premium', 'virtual_number'], true)) {
            return self::deliverOZVinooOfficial($pdo, $order, $product, $apiKey);
        }

        if (($metadata['api_style'] ?? '') === 'smm') {
            $apiStyle = 'smm';
        }

        if ($orderPath === '' || $serviceCode === '') {
            return [
                'ok' => false,
                'retryable' => true,
                'error' => 'تنظیمات عضوینو کامل نیست: مسیر ثبت سفارش یا کد سرویس پیدا نشده است.',
            ];
        }

        if (!str_starts_with($orderPath, '/')) {
            $orderPath = '/' . $orderPath;
        }

        $url = $baseUrl . $orderPath;
        if (!preg_match('#^https://api\.ozvinoo\.xyz(?::\d+)?/#i', $url)) {
            return ['ok' => false, 'error' => 'OZVinoo endpoint is outside the allowed host.'];
        }

        if ($apiStyle === 'smm') {
            return self::deliverOZVinooSmm(
                $url,
                $apiKey,
                $authHeader,
                $authPrefix,
                $order,
                $product,
                $serviceCode
            );
        }

        $payload = [
            'service' => $serviceCode,
            'target' => (string) $order['target'],
            'quantity' => max(1, (int) ($product['service_value'] ?? 1)),
            'reference' => (string) $order['order_code'],
        ];

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey !== '') {
            $headerValue = trim(($authPrefix !== '' ? $authPrefix . ' ' : '') . $apiKey);
            $headers[] = $authHeader . ': ' . $headerValue;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'retryable' => true, 'error' => 'Unable to initialise OZVinoo request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'retryable' => true,
                'error' => $error !== '' ? $error : 'OZVinoo request failed.',
            ];
        }

        $decoded = json_decode((string) $raw, true);
        $response = is_array($decoded) ? $decoded : ['raw' => mb_substr((string) $raw, 0, 2000, 'UTF-8')];
        $success = $http >= 200 && $http < 300
            && (!isset($response['success']) || (bool) $response['success'])
            && (!isset($response['ok']) || (bool) $response['ok'])
            && empty($response['error']);

        $reference = '';
        foreach (['id', 'order_id', 'orderId', 'reference'] as $key) {
            if (isset($response[$key]) && is_scalar($response[$key])) {
                $reference = (string) $response[$key];
                break;
            }
        }

        return [
            'ok' => $success,
            'retryable' => !$success && ($http === 0 || $http >= 500),
            'reference' => $reference,
            'response' => ['http_status' => $http, 'body' => $response],
            'error' => $success
                ? ''
                : trim((string) ($response['error'] ?? $response['message'] ?? ('OZVinoo returned HTTP ' . $http))),
        ];
    }

    private static function deliverOZVinooSmm(
        string $url,
        string $apiKey,
        string $authHeader,
        string $authPrefix,
        array $order,
        array $product,
        string $serviceCode
    ): array {
        if ($apiKey === '') {
            return ['ok' => false, 'retryable' => true, 'error' => 'API Key عضوینو تنظیم نشده است.'];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $headerValue = trim(($authPrefix !== '' ? $authPrefix . ' ' : '') . $apiKey);
        if ($headerValue !== '') {
            $headers[] = $authHeader . ': ' . $headerValue;
        }

        $payload = http_build_query([
            'key' => $apiKey,
            'action' => 'add',
            'service' => $serviceCode,
            'link' => (string) $order['target'],
            'quantity' => max(1, (int) ($product['service_value'] ?? $order['quantity'] ?? 1)),
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'retryable' => true, 'error' => 'Unable to initialise OZVinoo SMM request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'BlueBot/0.5.31 OZVinoo',
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'retryable' => true,
                'error' => $error !== '' ? $error : 'OZVinoo SMM request failed.',
            ];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'retryable' => $http === 0 || $http >= 500,
                'response' => ['http_status' => $http, 'raw' => mb_substr((string) $raw, 0, 2000, 'UTF-8')],
                'error' => 'پاسخ عضوینو JSON معتبر نبود.',
            ];
        }

        $errorText = trim((string) ($decoded['error'] ?? $decoded['message'] ?? ''));
        $reference = '';
        foreach (['order', 'order_id', 'orderId', 'id', 'reference'] as $key) {
            if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                $reference = trim((string) $decoded[$key]);
                if ($reference !== '') {
                    break;
                }
            }
        }

        $success = $http >= 200 && $http < 300 && $errorText === '' && $reference !== '';

        return [
            'ok' => $success,
            'retryable' => !$success && ($http === 0 || $http >= 500),
            'reference' => $reference,
            'response' => ['http_status' => $http, 'body' => $decoded],
            'error' => $success
                ? ''
                : ($errorText !== '' ? $errorText : 'عضوینو شناسه سفارش برنگرداند.'),
        ];
    }

    private static function markFailed(PDO $pdo, int $orderId, string $error, $response = null): array
    {
        $payload = json_encode(
            ['error' => $error, 'response' => $response],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $stmt = $pdo->prepare(
            "UPDATE digital_service_orders
             SET status = ?, provider_response = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([self::STATUS_FAILED, is_string($payload) ? $payload : $error, $orderId]);

        return [
            'ok' => false,
            'error' => $error,
            'order' => self::findOrder($pdo, $orderId),
        ];
    }

    private static function tgToolsInsufficientWalletMessage(array $wallet, ?float $requiredTon): string
    {
        $parts = ['موجودی کیف پول API تی‌جی‌تولز برای این سفارش کافی نیست.'];

        if (is_numeric($wallet['balance_ton'] ?? null)) {
            $parts[] = 'موجودی API: ' . rtrim(rtrim(number_format((float) $wallet['balance_ton'], 6, '.', ''), '0'), '.') . ' TON';
        }
        if ($requiredTon !== null && $requiredTon > 0) {
            $parts[] = 'حداقل هزینه این محصول: ' . rtrim(rtrim(number_format($requiredTon, 6, '.', ''), '0'), '.') . ' TON';
        }
        if (trim((string) ($wallet['deposit_address'] ?? '')) !== '') {
            $parts[] = 'آدرس واریز TGTools: ' . trim((string) $wallet['deposit_address']);
        }

        $parts[] = 'اتصال Tonkeeper به سایت به‌تنهایی موجودی API را شارژ نمی‌کند؛ موجودی باید در کیف پول TGTools قابل مشاهده باشد.';
        return implode("
", $parts);
    }

    private static function findScalarByKeys(array $data, array $keys, int $depth = 0)
    {
        if ($depth > 4) {
            return null;
        }

        $lookup = array_map(static fn (string $key): string => strtolower($key), $keys);
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $lookup, true) && is_scalar($value)) {
                return $value;
            }
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = self::findScalarByKeys($value, $keys, $depth + 1);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }

        return null;
    }

    private static function productMetadata(array $product): array
    {
        $decoded = json_decode((string) ($product['metadata'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function setSetting(PDO $pdo, string $key, string $value, bool $secret = false): void
    {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO digital_service_settings (setting_key, setting_value, is_secret)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)"
            );
            $stmt->execute([$key, $value, $secret ? 1 : 0]);
        } catch (Throwable $e) {
            // Catalog sync should never break order processing because a cache
            // timestamp could not be persisted.
        }
    }

    private static function setting(PDO $pdo, string $key, string $default = ''): string
    {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM digital_service_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? $default : (string) $value;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

