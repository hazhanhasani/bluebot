<?php

declare(strict_types=1);

final class BluebotProviderCatalogService
{
    private const RESERVED_KEYS = ['manual', 'telegram_bot', 'tgtools'];

    public static function ensureStorage(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS digital_service_providers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider_key VARCHAR(50) NOT NULL,
                name VARCHAR(190) NOT NULL,
                catalog_url VARCHAR(500) NOT NULL,
                api_key TEXT NULL,
                auth_header VARCHAR(80) NOT NULL DEFAULT 'Authorization',
                auth_prefix VARCHAR(50) NOT NULL DEFAULT 'Bearer',
                products_path VARCHAR(190) NOT NULL DEFAULT 'data',
                id_field VARCHAR(100) NOT NULL DEFAULT 'id',
                name_field VARCHAR(100) NOT NULL DEFAULT 'name',
                category_field VARCHAR(100) NOT NULL DEFAULT 'category',
                price_field VARCHAR(100) NOT NULL DEFAULT 'price',
                currency VARCHAR(20) NOT NULL DEFAULT 'toman',
                exchange_rate_toman DECIMAL(20,6) NOT NULL DEFAULT 1,
                profit_percent DECIMAL(8,3) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sync_interval_minutes INT UNSIGNED NOT NULL DEFAULT 15,
                last_sync_at DATETIME NULL,
                last_sync_status VARCHAR(30) NULL,
                last_sync_message VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_digital_service_provider_key (provider_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function listProviders(PDO $pdo, bool $activeOnly = false): array
    {
        self::ensureStorage($pdo);
        $sql = "SELECT * FROM digital_service_providers";
        if ($activeOnly) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY name ASC, id ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findProvider(PDO $pdo, string $providerKey): ?array
    {
        self::ensureStorage($pdo);
        $stmt = $pdo->prepare("SELECT * FROM digital_service_providers WHERE provider_key = ? LIMIT 1");
        $stmt->execute([strtolower(trim($providerKey))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function saveProvider(PDO $pdo, array $input): array
    {
        self::ensureStorage($pdo);

        $key = strtolower(trim((string) ($input['provider_key'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $catalogUrl = trim((string) ($input['catalog_url'] ?? ''));
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        $authHeader = trim((string) ($input['auth_header'] ?? 'Authorization'));
        $authPrefix = trim((string) ($input['auth_prefix'] ?? 'Bearer'));
        $productsPath = trim((string) ($input['products_path'] ?? 'data'));
        $idField = trim((string) ($input['id_field'] ?? 'id'));
        $nameField = trim((string) ($input['name_field'] ?? 'name'));
        $categoryField = trim((string) ($input['category_field'] ?? 'category'));
        $priceField = trim((string) ($input['price_field'] ?? 'price'));
        $currency = strtolower(trim((string) ($input['currency'] ?? 'toman')));
        $exchangeRate = (float) ($input['exchange_rate_toman'] ?? 1);
        $profitPercent = (float) ($input['profit_percent'] ?? 0);
        $syncInterval = max(1, min(1440, (int) ($input['sync_interval_minutes'] ?? 15)));

        if (!preg_match('/^[a-z][a-z0-9_-]{2,49}$/', $key) || in_array($key, self::RESERVED_KEYS, true)) {
            throw new InvalidArgumentException('Provider key is invalid or reserved.');
        }
        if ($name === '' || mb_strlen($name, 'UTF-8') > 190) {
            throw new InvalidArgumentException('Provider name is invalid.');
        }
        if (!self::isSafeHttpsUrl($catalogUrl)) {
            throw new InvalidArgumentException('Catalog URL must be a public HTTPS address.');
        }
        if (!preg_match('/^[A-Za-z0-9-]{1,80}$/', $authHeader)) {
            throw new InvalidArgumentException('Authentication header name is invalid.');
        }
        foreach ([$productsPath, $idField, $nameField, $categoryField, $priceField] as $path) {
            if ($path !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,190}$/', $path)) {
                throw new InvalidArgumentException('Catalog field mapping is invalid.');
            }
        }
        if (!in_array($currency, ['toman', 'rial', 'usd', 'ton', 'other'], true)) {
            throw new InvalidArgumentException('Provider currency is not supported.');
        }
        if ($currency === 'toman') {
            $exchangeRate = 1.0;
        } elseif ($currency === 'rial') {
            $exchangeRate = 0.1;
        } elseif ($exchangeRate <= 0) {
            throw new InvalidArgumentException('Exchange rate to Toman must be greater than zero.');
        }
        if ($profitPercent < 0 || $profitPercent > 1000) {
            throw new InvalidArgumentException('Profit percent must be between 0 and 1000.');
        }
        if ($apiKey !== '' && (strlen($apiKey) > 2048 || preg_match('/[\r\n]/', $apiKey))) {
            throw new InvalidArgumentException('Provider API key is invalid.');
        }

        $existing = self::findProvider($pdo, $key);
        if (is_array($existing) && $apiKey === '') {
            $apiKey = (string) ($existing['api_key'] ?? '');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO digital_service_providers
             (provider_key, name, catalog_url, api_key, auth_header, auth_prefix,
              products_path, id_field, name_field, category_field, price_field,
              currency, exchange_rate_toman, profit_percent, active, sync_interval_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                catalog_url = VALUES(catalog_url),
                api_key = VALUES(api_key),
                auth_header = VALUES(auth_header),
                auth_prefix = VALUES(auth_prefix),
                products_path = VALUES(products_path),
                id_field = VALUES(id_field),
                name_field = VALUES(name_field),
                category_field = VALUES(category_field),
                price_field = VALUES(price_field),
                currency = VALUES(currency),
                exchange_rate_toman = VALUES(exchange_rate_toman),
                profit_percent = VALUES(profit_percent),
                sync_interval_minutes = VALUES(sync_interval_minutes),
                active = 1"
        );
        $stmt->execute([
            $key,
            $name,
            $catalogUrl,
            $apiKey !== '' ? $apiKey : null,
            $authHeader,
            $authPrefix,
            $productsPath,
            $idField,
            $nameField,
            $categoryField,
            $priceField,
            $currency,
            $exchangeRate,
            $profitPercent,
            $syncInterval,
        ]);

        return self::findProvider($pdo, $key) ?? [];
    }

    public static function setActive(PDO $pdo, string $providerKey, bool $active): void
    {
        self::ensureStorage($pdo);
        $stmt = $pdo->prepare("UPDATE digital_service_providers SET active = ?, updated_at = NOW() WHERE provider_key = ?");
        $stmt->execute([$active ? 1 : 0, strtolower(trim($providerKey))]);

        if (!$active) {
            $deactivate = $pdo->prepare(
                "UPDATE digital_service_products
                 SET active = 0, updated_at = NOW()
                 WHERE provider = ? AND code LIKE ?"
            );
            $deactivate->execute([$providerKey, 'auto-' . $providerKey . '-%']);
        }
    }

    public static function deleteProvider(PDO $pdo, string $providerKey): bool
    {
        self::ensureStorage($pdo);
        $providerKey = strtolower(trim($providerKey));

        $count = $pdo->prepare("SELECT COUNT(*) FROM digital_service_orders WHERE provider = ?");
        $count->execute([$providerKey]);
        if ((int) $count->fetchColumn() > 0) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            $deleteProducts = $pdo->prepare(
                "DELETE FROM digital_service_products WHERE provider = ? AND code LIKE ?"
            );
            $deleteProducts->execute([$providerKey, 'auto-' . $providerKey . '-%']);

            $deleteProvider = $pdo->prepare("DELETE FROM digital_service_providers WHERE provider_key = ?");
            $deleteProvider->execute([$providerKey]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function syncDueProviders(PDO $pdo): array
    {
        $stats = ['checked' => 0, 'synced' => 0, 'failed' => 0, 'created' => 0, 'updated' => 0, 'disabled' => 0];

        foreach (self::listProviders($pdo, true) as $provider) {
            $stats['checked']++;
            $interval = max(1, (int) ($provider['sync_interval_minutes'] ?? 15));
            $last = trim((string) ($provider['last_sync_at'] ?? ''));
            if ($last !== '') {
                $lastTs = strtotime($last);
                if ($lastTs !== false && time() - $lastTs < $interval * 60) {
                    continue;
                }
            }

            $result = self::syncProvider($pdo, (string) $provider['provider_key']);
            if (!empty($result['ok'])) {
                $stats['synced']++;
                $stats['created'] += (int) ($result['created'] ?? 0);
                $stats['updated'] += (int) ($result['updated'] ?? 0);
                $stats['disabled'] += (int) ($result['disabled'] ?? 0);
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    public static function syncProvider(PDO $pdo, string $providerKey): array
    {
        self::ensureStorage($pdo);
        $provider = self::findProvider($pdo, $providerKey);
        if (!is_array($provider) || (int) ($provider['active'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'Provider is missing or disabled.'];
        }

        $response = self::fetchCatalog($provider);
        if (empty($response['ok'])) {
            self::markSync($pdo, $providerKey, 'failed', (string) ($response['message'] ?? 'Catalog request failed.'));
            return ['ok' => false, 'message' => (string) ($response['message'] ?? 'Catalog request failed.')];
        }

        $body = is_array($response['data'] ?? null) ? $response['data'] : [];
        $items = self::valueAtPath($body, (string) ($provider['products_path'] ?? 'data'));
        if (!is_array($items)) {
            self::markSync($pdo, $providerKey, 'failed', 'Products path did not resolve to an array.');
            return ['ok' => false, 'message' => 'Products path did not resolve to an array.'];
        }

        if (self::isAssoc($items)) {
            $items = array_values($items);
        }

        $find = $pdo->prepare(
            "SELECT * FROM digital_service_products
             WHERE provider = ? AND provider_service_code = ?
             LIMIT 1"
        );
        $insert = $pdo->prepare(
            "INSERT INTO digital_service_products
             (code, name, type, provider, price, service_value, provider_service_code, description, metadata, active, sort_order)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, 1, ?)"
        );
        $update = $pdo->prepare(
            "UPDATE digital_service_products
             SET name = ?, type = ?, price = ?, description = ?, metadata = ?, active = 1, sort_order = ?, updated_at = NOW()
             WHERE id = ?"
        );

        $seenCodes = [];
        $created = 0;
        $updated = 0;
        $sort = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $providerId = trim((string) self::valueAtPath($item, (string) $provider['id_field']));
            $name = trim((string) self::valueAtPath($item, (string) $provider['name_field']));
            $categoryRaw = trim((string) self::valueAtPath($item, (string) $provider['category_field']));
            $costRaw = self::valueAtPath($item, (string) $provider['price_field']);

            if ($providerId === '' || $name === '' || !is_numeric($costRaw)) {
                continue;
            }

            $cost = (float) $costRaw;
            if ($cost < 0) {
                continue;
            }

            $category = self::normaliseCategory($categoryRaw, $name);
            $type = self::inferProductType($category['key'], $categoryRaw . ' ' . $name);
            $sellingPrice = self::calculateSellingPrice(
                $cost,
                (string) $provider['currency'],
                (float) $provider['exchange_rate_toman'],
                (float) $provider['profit_percent']
            );
            if ($sellingPrice <= 0) {
                continue;
            }

            $code = 'auto-' . $providerKey . '-' . substr(hash('sha256', $providerId), 0, 16);
            $seenCodes[] = $code;
            $sort++;

            $metadata = [
                'source' => 'provider-catalog',
                'auto_imported' => true,
                'provider_key' => $providerKey,
                'provider_name' => (string) $provider['name'],
                'category_key' => $category['key'],
                'category_label' => $category['label'],
                'wholesale_cost' => $cost,
                'wholesale_currency' => (string) $provider['currency'],
                'exchange_rate_toman' => (float) $provider['exchange_rate_toman'],
                'profit_percent' => (float) $provider['profit_percent'],
                'price_mode' => 'margin',
                'synced_at' => gmdate(DATE_ATOM),
                'delivery_mode' => $providerKey === 'ozvinoo' ? 'integrated' : 'manual',
            ];
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $description = 'محصول همگام‌شده از ' . (string) $provider['name'];

            $find->execute([$providerKey, $providerId]);
            $row = $find->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                $update->execute([
                    $name,
                    $type,
                    $sellingPrice,
                    $description,
                    is_string($metadataJson) ? $metadataJson : null,
                    $sort,
                    (int) $row['id'],
                ]);
                $updated++;
            } else {
                try {
                    $insert->execute([
                        $code,
                        $name,
                        $type,
                        $providerKey,
                        $sellingPrice,
                        $providerId,
                        $description,
                        is_string($metadataJson) ? $metadataJson : null,
                        $sort,
                    ]);
                    $created++;
                } catch (PDOException $e) {
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }
        }

        $disabled = self::disableMissingProducts($pdo, $providerKey, $seenCodes);
        $message = sprintf('%d created, %d updated, %d disabled', $created, $updated, $disabled);
        self::markSync($pdo, $providerKey, 'success', $message);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'disabled' => $disabled,
            'message' => $message,
        ];
    }

    public static function calculateSellingPrice(
        float $wholesaleCost,
        string $currency,
        float $exchangeRateToman,
        float $profitPercent
    ): int {
        if ($wholesaleCost < 0 || $profitPercent < 0) {
            return 0;
        }

        $currency = strtolower(trim($currency));
        $rate = match ($currency) {
            'toman' => 1.0,
            'rial' => 0.1,
            default => $exchangeRateToman,
        };

        if ($rate <= 0) {
            return 0;
        }

        $tomanCost = $wholesaleCost * $rate;
        $retail = $tomanCost * (1 + ($profitPercent / 100));
        if ($retail <= 0) {
            return 0;
        }

        return (int) (ceil($retail / 1000) * 1000);
    }

    public static function normaliseCategory(string $rawCategory, string $productName = ''): array
    {
        $haystack = mb_strtolower(trim($rawCategory . ' ' . $productName), 'UTF-8');

        $known = [
            'premium' => ['label' => '🎁 تلگرام پرمیوم', 'needles' => ['premium', 'پرمیوم']],
            'stars' => ['label' => '⭐ استارز تلگرام', 'needles' => ['stars', 'star ', 'telegram star', 'استار', 'ستاره']],
            'telegram' => ['label' => '✈️ خدمات تلگرام', 'needles' => ['telegram', 'تلگرام', 'member', 'ممبر']],
            'instagram' => ['label' => '📸 خدمات اینستاگرام', 'needles' => ['instagram', 'اینستاگرام', 'follower', 'فالور', 'like']],
            'giftcards' => ['label' => '🎁 گیفت‌کارت', 'needles' => ['gift card', 'giftcard', 'گیفت کارت', 'گیفت‌کارت', 'steam', 'playstation', 'amazon']],
            'games' => ['label' => '🎮 بازی و شارژ', 'needles' => ['game', 'gaming', 'pubg', 'free fire', 'بازی', 'شارژ بازی']],
            'apple' => ['label' => '🍎 خدمات اپل', 'needles' => ['apple', 'اپل', 'icloud']],
            'chatgpt' => ['label' => '🤖 هوش مصنوعی', 'needles' => ['chatgpt', 'openai', 'claude', 'هوش مصنوعی']],
            'virtual_number' => ['label' => '📱 شماره مجازی', 'needles' => ['virtual number', 'number', 'شماره مجازی']],
            'design' => ['label' => '🎨 طراحی و دیجیتال', 'needles' => ['design', 'website', 'banner', 'طراحی', 'سایت', 'بنر']],
        ];

        foreach ($known as $key => $info) {
            foreach ($info['needles'] as $needle) {
                if ($needle !== '' && mb_strpos($haystack, $needle, 0, 'UTF-8') !== false) {
                    return ['key' => $key, 'label' => $info['label']];
                }
            }
        }

        $label = trim($rawCategory);
        if ($label === '') {
            return ['key' => 'other', 'label' => '🧩 سایر خدمات'];
        }

        $ascii = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $label));
        $ascii = trim($ascii, '-');
        if ($ascii === '') {
            $ascii = 'cat-' . substr(hash('sha256', $label), 0, 10);
        }
        $key = substr($ascii, 0, 40);

        return ['key' => $key, 'label' => '🗂 ' . mb_substr($label, 0, 40, 'UTF-8')];
    }

    private static function inferProductType(string $categoryKey, string $text): string
    {
        if ($categoryKey === 'premium' || mb_stripos($text, 'premium', 0, 'UTF-8') !== false || mb_stripos($text, 'پرمیوم', 0, 'UTF-8') !== false) {
            return 'telegram_premium';
        }
        if ($categoryKey === 'stars' || mb_stripos($text, 'stars', 0, 'UTF-8') !== false || mb_stripos($text, 'استار', 0, 'UTF-8') !== false) {
            return 'telegram_stars';
        }
        return 'custom';
    }

    private static function disableMissingProducts(PDO $pdo, string $providerKey, array $seenCodes): int
    {
        $params = [$providerKey, 'auto-' . $providerKey . '-%'];
        $sql = "UPDATE digital_service_products
                SET active = 0, updated_at = NOW()
                WHERE provider = ? AND code LIKE ?";

        if ($seenCodes !== []) {
            $sql .= " AND code NOT IN (" . implode(',', array_fill(0, count($seenCodes), '?')) . ")";
            $params = array_merge($params, $seenCodes);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private static function fetchCatalog(array $provider): array
    {
        $url = trim((string) ($provider['catalog_url'] ?? ''));
        if (!self::isSafeHttpsUrl($url)) {
            return ['ok' => false, 'message' => 'Catalog URL is unsafe.'];
        }

        $headers = ['Accept: application/json'];
        $apiKey = trim((string) ($provider['api_key'] ?? ''));
        if ($apiKey !== '') {
            $authHeader = trim((string) ($provider['auth_header'] ?? 'Authorization'));
            $authPrefix = trim((string) ($provider['auth_prefix'] ?? 'Bearer'));
            $value = trim(($authPrefix !== '' ? $authPrefix . ' ' : '') . $apiKey);
            $headers[] = $authHeader . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Unable to initialise provider request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'BlueBot/0.5.27 ProviderCatalog',
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'message' => $error !== '' ? $error : 'Provider catalog request failed.'];
        }
        if ($http < 200 || $http >= 300) {
            return ['ok' => false, 'message' => 'Provider catalog returned HTTP ' . $http . '.'];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'Provider catalog returned invalid JSON.'];
        }

        return ['ok' => true, 'data' => $decoded];
    }

    private static function isSafeHttpsUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        $ips = gethostbynamel($host);
        if (is_array($ips) && $ips !== []) {
            foreach ($ips as $ip) {
                if (!filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function valueAtPath(array $data, string $path)
    {
        $path = trim($path);
        if ($path === '' || $path === '.') {
            return $data;
        }

        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    private static function isAssoc(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private static function markSync(PDO $pdo, string $providerKey, string $status, string $message): void
    {
        $stmt = $pdo->prepare(
            "UPDATE digital_service_providers
             SET last_sync_at = NOW(), last_sync_status = ?, last_sync_message = ?, updated_at = NOW()
             WHERE provider_key = ?"
        );
        $stmt->execute([$status, mb_substr($message, 0, 500, 'UTF-8'), $providerKey]);
    }
}
