<?php

declare(strict_types=1);

final class BluebotProviderCatalogService
{
    private const RESERVED_KEYS = ['manual', 'telegram_bot', 'tgtools', 'tivanovin'];
    private const DISCOVERY_MAX_ATTEMPTS = 12;
    private const DISCOVERY_BUDGET_SECONDS = 8.0;
    private const DISCOVERY_CONNECT_TIMEOUT_SECONDS = 2;
    private const DISCOVERY_REQUEST_TIMEOUT_SECONDS = 4;

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

    public static function discoverCatalogUrl(
        array $urls,
        string $apiKey = '',
        string $authHeader = 'Authorization',
        string $authPrefix = 'Bearer'
    ): array {
        $errors = [];
        $candidates = array_values(array_unique(array_filter(array_map('trim', $urls))));
        $authProfiles = self::authProfiles($apiKey, $authHeader, $authPrefix);
        $startedAt = microtime(true);
        $attempts = 0;
        $budgetExhausted = false;
        $discoveredEndpointUrls = [];

        $canProbe = static function () use (&$attempts, $startedAt, &$budgetExhausted): bool {
            if ($attempts >= self::DISCOVERY_MAX_ATTEMPTS
                || microtime(true) - $startedAt >= self::DISCOVERY_BUDGET_SECONDS) {
                $budgetExhausted = true;
                return false;
            }
            $attempts++;
            return true;
        };

        // Fast pass: try one configured REST request per endpoint before
        // multiplying requests across every authentication style.
        $primaryProfile = $authProfiles[0] ?? ['header' => '', 'prefix' => '', 'label' => 'none'];
        foreach (array_slice($candidates, 0, 4) as $url) {
            if (!self::isSafeHttpsUrl($url)) {
                continue;
            }
            if (!$canProbe()) {
                break;
            }

            $response = self::requestCatalogUrl(
                $url,
                $apiKey,
                (string) $primaryProfile['header'],
                (string) $primaryProfile['prefix']
            );
            if (!empty($response['ok'])) {
                $body = is_array($response['data'] ?? null) ? $response['data'] : [];
                $mapping = self::autoDiscoverCatalogMapping($body);
                if (!empty($mapping['ok'])) {
                    return [
                        'ok' => true,
                        'api_style' => 'rest',
                        'url' => $url,
                        'auth_header' => (string) $primaryProfile['header'],
                        'auth_prefix' => (string) $primaryProfile['prefix'],
                        'products_path' => (string) $mapping['products_path'],
                        'id_field' => (string) $mapping['id_field'],
                        'name_field' => (string) $mapping['name_field'],
                        'category_field' => (string) $mapping['category_field'],
                        'price_field' => (string) $mapping['price_field'],
                    ];
                }
                foreach (self::discoverLinkedApiEndpoints($body, $url) as $linkedUrl) {
                    if (!in_array($linkedUrl, $discoveredEndpointUrls, true)) {
                        $discoveredEndpointUrls[] = $linkedUrl;
                    }
                }
                $keys = self::diagnosticTopLevelKeys($body);
                $errors[] = $url . ' [GET/' . $primaryProfile['label'] . ']: JSON found but no product list was detected'
                    . ($keys !== '' ? ' (keys: ' . $keys . ')' : '');
            } else {
                $errors[] = $url . ' [GET/' . $primaryProfile['label'] . ']: '
                    . (string) ($response['message'] ?? 'request failed');
            }
        }

        // DRF/FastAPI-style API roots often return a JSON object whose values
        // are links to resources rather than the resources themselves. Probe
        // those same-host links first; this is more reliable than guessing paths.
        if (!$budgetExhausted && $discoveredEndpointUrls !== []) {
            foreach (array_slice($discoveredEndpointUrls, 0, 8) as $url) {
                if (!$canProbe()) {
                    break;
                }

                $response = self::requestCatalogUrl(
                    $url,
                    $apiKey,
                    (string) $primaryProfile['header'],
                    (string) $primaryProfile['prefix']
                );
                if (empty($response['ok'])) {
                    $errors[] = $url . ' [GET/discovered]: '
                        . (string) ($response['message'] ?? 'request failed');
                    continue;
                }

                $body = is_array($response['data'] ?? null) ? $response['data'] : [];
                $mapping = self::autoDiscoverCatalogMapping($body);
                if (!empty($mapping['ok'])) {
                    return [
                        'ok' => true,
                        'api_style' => 'rest',
                        'url' => $url,
                        'auth_header' => (string) $primaryProfile['header'],
                        'auth_prefix' => (string) $primaryProfile['prefix'],
                        'products_path' => (string) $mapping['products_path'],
                        'id_field' => (string) $mapping['id_field'],
                        'name_field' => (string) $mapping['name_field'],
                        'category_field' => (string) $mapping['category_field'],
                        'price_field' => (string) $mapping['price_field'],
                    ];
                }

                foreach (self::discoverLinkedApiEndpoints($body, $url) as $linkedUrl) {
                    if (!in_array($linkedUrl, $discoveredEndpointUrls, true)) {
                        $discoveredEndpointUrls[] = $linkedUrl;
                    }
                }
                $keys = self::diagnosticTopLevelKeys($body);
                $errors[] = $url . ' [GET/discovered]: JSON found but no product list was detected'
                    . ($keys !== '' ? ' (keys: ' . $keys . ')' : '');
            }
        }

        // SMM fast pass: API panels usually expose action=services on a short
        // list of well-known endpoints. Body-only auth is intentionally tried
        // first because many SMM panels put the key only in the POST body.
        if (!$budgetExhausted && trim($apiKey) !== '') {
            $smmCandidates = [];
            foreach ($candidates as $url) {
                if (!self::isSafeHttpsUrl($url)) {
                    continue;
                }
                $parts = parse_url($url);
                if (!is_array($parts)) {
                    continue;
                }
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                $host = (string) ($parts['host'] ?? '');
                $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
                if ($scheme !== 'https' || $host === '') {
                    continue;
                }
                $origin = $scheme . '://' . $host . $port;
                foreach (['/api/', '/api/v2/', '/api/v1/', '/api/v2', '/api/v1', '/api', '/v2/', '/v1/', '/v2', '/v1', '/'] as $path) {
                    $smmCandidates[] = rtrim($origin, '/') . $path;
                }
                break;
            }

            $smmProfiles = [];
            foreach ($authProfiles as $profile) {
                if ((string) ($profile['label'] ?? '') === 'body-only') {
                    $smmProfiles[] = $profile;
                    break;
                }
            }
            $smmProfiles[] = $primaryProfile;

            foreach (array_values(array_unique($smmCandidates)) as $url) {
                foreach ($smmProfiles as $profile) {
                    if (!$canProbe()) {
                        break 2;
                    }

                    $response = self::requestSmmServices(
                        $url,
                        $apiKey,
                        (string) $profile['header'],
                        (string) $profile['prefix']
                    );
                    if (empty($response['ok'])) {
                        $errors[] = $url . ' [SMM/' . $profile['label'] . ']: '
                            . (string) ($response['message'] ?? 'request failed');
                        continue;
                    }

                    $body = is_array($response['data'] ?? null) ? $response['data'] : [];
                    $mapping = self::autoDiscoverCatalogMapping($body);
                    if (!empty($mapping['ok'])) {
                        return [
                            'ok' => true,
                            'api_style' => 'smm',
                            'url' => $url,
                            'auth_header' => (string) $profile['header'],
                            'auth_prefix' => (string) $profile['prefix'],
                            'products_path' => 'smm:' . (string) $mapping['products_path'],
                            'id_field' => (string) $mapping['id_field'],
                            'name_field' => (string) $mapping['name_field'],
                            'category_field' => (string) $mapping['category_field'],
                            'price_field' => (string) $mapping['price_field'],
                        ];
                    }

                    $errors[] = $url . ' [SMM/' . $profile['label'] . ']: JSON found but no service list was detected';
                }
            }
        }

        // Final bounded pass: try alternate auth headers on the first few REST
        // endpoints only. This keeps a button click deterministic instead of
        // blocking PHP for minutes when the provider is unavailable.
        if (!$budgetExhausted && count($authProfiles) > 1) {
            foreach (array_slice($candidates, 0, 4) as $url) {
                if (!self::isSafeHttpsUrl($url)) {
                    continue;
                }
                foreach (array_slice($authProfiles, 1) as $profile) {
                    if (!$canProbe()) {
                        break 2;
                    }

                    $response = self::requestCatalogUrl(
                        $url,
                        $apiKey,
                        (string) $profile['header'],
                        (string) $profile['prefix']
                    );
                    if (empty($response['ok'])) {
                        $errors[] = $url . ' [GET/' . $profile['label'] . ']: '
                            . (string) ($response['message'] ?? 'request failed');
                        continue;
                    }

                    $body = is_array($response['data'] ?? null) ? $response['data'] : [];
                    $mapping = self::autoDiscoverCatalogMapping($body);
                    if (!empty($mapping['ok'])) {
                        return [
                            'ok' => true,
                            'api_style' => 'rest',
                            'url' => $url,
                            'auth_header' => (string) $profile['header'],
                            'auth_prefix' => (string) $profile['prefix'],
                            'products_path' => (string) $mapping['products_path'],
                            'id_field' => (string) $mapping['id_field'],
                            'name_field' => (string) $mapping['name_field'],
                            'category_field' => (string) $mapping['category_field'],
                            'price_field' => (string) $mapping['price_field'],
                        ];
                    }
                }
            }
        }

        $summary = $errors !== []
            ? implode(' | ', array_slice($errors, 0, 6))
            : 'No compatible catalog endpoint was detected.';
        if ($budgetExhausted) {
            $summary .= ' | Discovery stopped after the safe time/request budget; no long-running probe was allowed.';
        }

        return [
            'ok' => false,
            'attempts' => $attempts,
            'timed_out' => $budgetExhausted,
            'message' => $summary,
        ];
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
        if ($authHeader !== '' && !preg_match('/^[A-Za-z0-9-]{1,80}$/', $authHeader)) {
            throw new InvalidArgumentException('Authentication header name is invalid.');
        }
        if ($productsPath !== '' && !preg_match('/^(?:smm:)?[A-Za-z0-9_.-]{1,190}$/', $productsPath)) {
            throw new InvalidArgumentException('Catalog products path is invalid.');
        }
        foreach ([$idField, $nameField, $categoryField, $priceField] as $path) {
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
            if (strtolower((string) ($provider['provider_key'] ?? '')) === 'ozvinoo') {
                // OZVinoo has dedicated official endpoints and is synchronized
                // by BluebotDigitalServices::maybeBootstrapOZVinooCatalog().
                continue;
            }
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
        $mapping = self::resolveCatalogMapping($body, $provider);
        if (empty($mapping['ok'])) {
            $message = (string) ($mapping['message'] ?? 'Unable to detect provider products.');
            self::markSync($pdo, $providerKey, 'failed', $message);
            return ['ok' => false, 'message' => $message];
        }

        $items = $mapping['items'];
        $provider['products_path'] = $mapping['products_path'];
        $provider['id_field'] = $mapping['id_field'];
        $provider['name_field'] = $mapping['name_field'];
        $provider['category_field'] = $mapping['category_field'];
        $provider['price_field'] = $mapping['price_field'];

        self::persistDiscoveredMapping($pdo, $providerKey, $mapping);
        $smmStyle = str_starts_with((string) ($mapping['products_path'] ?? ''), 'smm:');

        $find = $pdo->prepare(
            "SELECT * FROM digital_service_products
             WHERE provider = ? AND provider_service_code = ?
             LIMIT 1"
        );
        $insert = $pdo->prepare(
            "INSERT INTO digital_service_products
             (code, name, type, provider, price, service_value, provider_service_code, description, metadata, active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
        );
        $update = $pdo->prepare(
            "UPDATE digital_service_products
             SET name = ?, type = ?, price = ?, service_value = ?, description = ?, metadata = ?, active = ?, sort_order = ?, updated_at = NOW()
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
            if (is_string($costRaw)) {
                $costRaw = str_replace([',', ' ', '٬'], '', trim($costRaw));
            }

            if ($providerId === '' || $name === '' || !is_numeric($costRaw)) {
                continue;
            }

            $cost = (float) $costRaw;
            if ($cost < 0) {
                continue;
            }

            $serviceValue = 1;
            $minQuantity = null;
            $maxQuantity = null;
            $ratePerThousand = null;
            $providerDescription = '';
            $providerType = '';
            $dripfeed = null;
            if ($smmStyle) {
                $minRaw = self::firstNumericValue($item, ['min', 'minimum', 'min_quantity', 'minQuantity']);
                $maxRaw = self::firstNumericValue($item, ['max', 'maximum', 'max_quantity', 'maxQuantity']);
                $minQuantity = $minRaw !== null ? max(1, (int) floor($minRaw)) : 1;
                $maxQuantity = $maxRaw !== null ? max($minQuantity, (int) floor($maxRaw)) : null;
                $serviceValue = $minQuantity;
                $providerDescription = trim((string) self::valueAtPath($item, 'desc'));
                $providerType = trim((string) self::valueAtPath($item, 'type'));
                $dripfeedRaw = self::valueAtPath($item, 'dripfeed');
                if (is_bool($dripfeedRaw) || is_numeric($dripfeedRaw)) {
                    $dripfeed = (bool) $dripfeedRaw;
                }

                // Standard SMM APIs publish "rate" per 1000 units. Import a
                // safe fixed package using the provider's minimum quantity so
                // the customer sees a real payable product immediately.
                $ratePerThousand = $cost;
                $cost = $ratePerThousand * ($serviceValue / 1000);
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
                'delivery_mode' => in_array($providerKey, ['ozvinoo', 'tivanovin'], true) ? 'integrated' : 'manual',
                'api_style' => $smmStyle ? 'smm' : 'rest',
                'service_value' => $serviceValue,
            ];
            if ($smmStyle) {
                $metadata['minimum_quantity'] = $minQuantity;
                $metadata['maximum_quantity'] = $maxQuantity;
                $metadata['wholesale_rate_per_1000'] = $ratePerThousand;
                $metadata['price_basis'] = 'minimum-package';
                if ($providerDescription !== '') {
                    $metadata['provider_description'] = $providerDescription;
                }
                if ($providerType !== '') {
                    $metadata['provider_type'] = $providerType;
                }
                if ($dripfeed !== null) {
                    $metadata['dripfeed'] = $dripfeed;
                }
            }
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $description = $providerDescription !== ''
                ? $providerDescription
                : 'محصول همگام‌شده از ' . (string) $provider['name'];

            $find->execute([$providerKey, $providerId]);
            $row = $find->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                $metadata = BluebotDigitalServices::mergeAdminProductMetadata($row, $metadata);
                $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $active = BluebotDigitalServices::providerManagedActive($row, 1);

                $update->execute([
                    $name,
                    $type,
                    $sellingPrice,
                    $serviceValue,
                    $description,
                    is_string($metadataJson) ? $metadataJson : null,
                    $active,
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
                        $serviceValue,
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

        // Keep the high-value Telegram purchase families separate, then map
        // the public OZVinoo service families into stable customer categories.
        $known = [
            'premium' => ['label' => '🎁 تلگرام پرمیوم', 'needles' => ['telegram premium', 'تلگرام پرمیوم', 'پرمیوم اکانت']],
            'stars' => ['label' => '⭐ استارز تلگرام', 'needles' => ['telegram stars', 'telegram star', 'استارز', 'استار تلگرام']],
            'telegram' => ['label' => '✈️ خدمات تلگرام', 'needles' => ['telegram', 'تلگرام', 'member', 'ممبر']],
            'instagram' => ['label' => '📸 خدمات اینستاگرام', 'needles' => ['instagram', 'اینستاگرام']],
            'youtube' => ['label' => '▶️ خدمات یوتیوب', 'needles' => ['youtube', 'یوتیوب']],
            'twitter' => ['label' => '𝕏 خدمات X / توییتر', 'needles' => ['twitter', 'توییتر', 'x.com']],
            'tiktok' => ['label' => '🎵 خدمات تیک‌تاک', 'needles' => ['tiktok', 'tik tok', 'تیک تاک', 'تیک‌تاک']],
            'spotify' => ['label' => '🎧 خدمات اسپاتیفای', 'needles' => ['spotify', 'اسپاتیفای']],
            'linkedin' => ['label' => '💼 خدمات لینکدین', 'needles' => ['linkedin', 'لینکدین']],
            'facebook' => ['label' => '📘 خدمات فیسبوک', 'needles' => ['facebook', 'فیسبوک']],
            'whatsapp' => ['label' => '🟢 خدمات واتساپ', 'needles' => ['whatsapp', 'واتساپ']],
            'likee' => ['label' => '💜 خدمات Likee', 'needles' => ['likee', 'لایکی']],
            'naver' => ['label' => '🟩 Naver TV', 'needles' => ['naver', 'ناور']],
            'virtual_number' => ['label' => '📱 شماره مجازی', 'needles' => ['virtual number', 'شماره مجازی']],
            'design' => ['label' => '🎨 طراحی و گرافیک', 'needles' => ['design', 'graphic', 'banner', 'طراحی', 'گرافیک', 'بنر']],
            'giftcards' => ['label' => '🎁 گیفت‌کارت', 'needles' => ['gift card', 'giftcard', 'گیفت کارت', 'گیفت‌کارت', 'steam', 'playstation', 'amazon']],
            'games' => ['label' => '🎮 بازی و شارژ', 'needles' => ['game', 'gaming', 'pubg', 'free fire', 'بازی', 'شارژ بازی']],
            'apple' => ['label' => '🍎 خدمات اپل', 'needles' => ['apple', 'اپل', 'icloud']],
            'chatgpt' => ['label' => '🤖 هوش مصنوعی', 'needles' => ['chatgpt', 'openai', 'claude', 'هوش مصنوعی']],
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

    private static function resolveCatalogMapping(array $body, array $provider): array
    {
        $productsPath = trim((string) ($provider['products_path'] ?? 'auto'));
        $smmStyle = str_starts_with($productsPath, 'smm:');
        if ($smmStyle) {
            $productsPath = substr($productsPath, 4);
            if ($productsPath === '') {
                $productsPath = '.';
            }
        }
        $idField = trim((string) ($provider['id_field'] ?? 'auto'));
        $nameField = trim((string) ($provider['name_field'] ?? 'auto'));
        $categoryField = trim((string) ($provider['category_field'] ?? 'auto'));
        $priceField = trim((string) ($provider['price_field'] ?? 'auto'));

        $needsAuto = in_array('auto', [$productsPath, $idField, $nameField, $categoryField, $priceField], true);
        if (!$needsAuto) {
            $items = self::valueAtPath($body, $productsPath);
            if (!is_array($items)) {
                return ['ok' => false, 'message' => 'Products path did not resolve to an array.'];
            }
            if (self::isAssoc($items)) {
                $items = array_values($items);
            }
            return [
                'ok' => true,
                'items' => $items,
                'products_path' => $smmStyle ? 'smm:' . $productsPath : $productsPath,
                'id_field' => $idField,
                'name_field' => $nameField,
                'category_field' => $categoryField,
                'price_field' => $priceField,
            ];
        }

        return self::autoDiscoverCatalogMapping($body);
    }

    private static function autoDiscoverCatalogMapping(array $body): array
    {
        $paths = [
            'data.services', 'data.products', 'data.items', 'data.list', 'data.results', 'data.rows',
            'result.services', 'result.products', 'result.items', 'result.list', 'result.results',
            'response.services', 'response.products', 'response.items', 'response.data',
            'services', 'products', 'items', 'list', 'results', 'rows', 'data', 'result', 'response', '.',
        ];

        foreach (self::collectArrayPaths($body, '', 0, 5) as $path) {
            if (!in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        foreach ($paths as $productsPath) {
            $items = $productsPath === '.' ? $body : self::valueAtPath($body, $productsPath);
            if (!is_array($items) || $items === []) {
                continue;
            }

            $items = self::normaliseItemCollection($items);
            if ($items === []) {
                continue;
            }

            $sample = null;
            foreach ($items as $item) {
                if (is_array($item) && $item !== []) {
                    $sample = $item;
                    break;
                }
            }
            if (!is_array($sample)) {
                continue;
            }

            $idField = self::firstMatchingField($sample, [
                '__bluebot_key', 'id', 'service_id', 'serviceId', 'service.id',
                'service', 'code', 'service_code', 'serviceCode', 'sku', 'product_id', 'productId',
            ]);
            $nameField = self::firstMatchingField($sample, [
                'name', 'title', 'service_name', 'serviceName', 'service.name', 'label',
                'product_name', 'productName',
            ]);
            $priceField = self::firstMatchingField($sample, [
                'price', 'cost', 'rate', 'amount', 'base_price', 'basePrice',
                'wholesale_price', 'wholesalePrice', 'pricing.price', 'pricing.amount',
                'price.amount', 'final_price', 'finalPrice',
            ]);
            $categoryField = self::firstMatchingField($sample, [
                'category_name', 'categoryName', 'category.name', 'category.title',
                'group_name', 'groupName', 'group.name', 'category', 'group', 'type',
                'platform', 'network',
            ]);

            if ($idField === '' || $nameField === '' || $priceField === '') {
                continue;
            }

            return [
                'ok' => true,
                'items' => array_values($items),
                'products_path' => $productsPath,
                'id_field' => $idField,
                'name_field' => $nameField,
                'category_field' => $categoryField !== '' ? $categoryField : 'name',
                'price_field' => $priceField,
            ];
        }

        return ['ok' => false, 'message' => 'BlueBot could not auto-detect the provider product list/fields.'];
    }

    private static function collectArrayPaths(array $node, string $prefix, int $depth, int $maxDepth): array
    {
        if ($depth > $maxDepth) {
            return [];
        }

        $paths = [];
        foreach ($node as $key => $value) {
            if (!is_array($value) || $value === []) {
                continue;
            }

            $segment = (string) $key;
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                continue;
            }
            $path = $prefix === '' ? $segment : $prefix . '.' . $segment;

            $hasArrayItem = false;
            foreach ($value as $item) {
                if (is_array($item)) {
                    $hasArrayItem = true;
                    break;
                }
            }
            if ($hasArrayItem) {
                $paths[] = $path;
            }

            if (self::isAssoc($value)) {
                foreach (self::collectArrayPaths($value, $path, $depth + 1, $maxDepth) as $nestedPath) {
                    $paths[] = $nestedPath;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private static function normaliseItemCollection(array $items): array
    {
        if (!self::isAssoc($items)) {
            return array_values(array_filter($items, 'is_array'));
        }

        $normalised = [];
        foreach ($items as $key => $value) {
            if (!is_array($value) || $value === []) {
                continue;
            }
            $value['__bluebot_key'] = (string) $key;
            $normalised[] = $value;
        }

        return $normalised;
    }

    private static function firstMatchingField(array $item, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = self::valueAtPath($item, $candidate);
            if ($value !== null && $value !== '' && !is_array($value) && !is_object($value)) {
                return $candidate;
            }
        }

        // One level of nested objects covers common API envelopes such as
        // pricing.price, service.id and category.name.
        foreach ($item as $key => $value) {
            if (!is_array($value) || self::isAssoc($value) === false) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $value)
                    && $value[$candidate] !== null
                    && $value[$candidate] !== ''
                    && !is_array($value[$candidate])) {
                    return (string) $key . '.' . $candidate;
                }
            }
        }

        return '';
    }

    private static function persistDiscoveredMapping(PDO $pdo, string $providerKey, array $mapping): void
    {
        $stmt = $pdo->prepare(
            "UPDATE digital_service_providers
             SET products_path = ?, id_field = ?, name_field = ?, category_field = ?, price_field = ?, updated_at = NOW()
             WHERE provider_key = ?"
        );
        $stmt->execute([
            (string) $mapping['products_path'],
            (string) $mapping['id_field'],
            (string) $mapping['name_field'],
            (string) $mapping['category_field'],
            (string) $mapping['price_field'],
            $providerKey,
        ]);
    }

    private static function fetchCatalog(array $provider): array
    {
        $url = trim((string) ($provider['catalog_url'] ?? ''));
        $apiKey = trim((string) ($provider['api_key'] ?? ''));
        $authHeader = trim((string) ($provider['auth_header'] ?? 'Authorization'));
        $authPrefix = trim((string) ($provider['auth_prefix'] ?? 'Bearer'));
        $productsPath = trim((string) ($provider['products_path'] ?? ''));

        if (str_starts_with($productsPath, 'smm:')) {
            return self::requestSmmServices($url, $apiKey, $authHeader, $authPrefix);
        }

        $response = self::requestCatalogUrl($url, $apiKey, $authHeader, $authPrefix);
        if (!empty($response['ok'])) {
            return $response;
        }

        // Existing OZVinoo installs may still have an old GET catalog URL.
        // Try the SMM services contract before reporting a sync failure.
        if (strtolower((string) ($provider['provider_key'] ?? '')) === 'ozvinoo' && $apiKey !== '') {
            $smm = self::requestSmmServices($url, $apiKey, $authHeader, $authPrefix);
            if (!empty($smm['ok'])) {
                return $smm;
            }
        }

        return $response;
    }

    private static function discoverLinkedApiEndpoints(array $body, string $sourceUrl): array
    {
        $sourceHost = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
        $sourceScheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
        if ($sourceHost === '' || $sourceScheme !== 'https') {
            return [];
        }

        $base = $sourceScheme . '://' . $sourceHost;
        $sourcePort = parse_url($sourceUrl, PHP_URL_PORT);
        if (is_int($sourcePort) && $sourcePort > 0) {
            $base .= ':' . $sourcePort;
        }

        $found = [];
        $walk = static function ($value, string $key, int $depth) use (&$walk, &$found, $base, $sourceHost): void {
            if ($depth > 4 || count($found) >= 20) {
                return;
            }

            if (is_array($value)) {
                foreach ($value as $childKey => $childValue) {
                    $walk($childValue, (string) $childKey, $depth + 1);
                }
                return;
            }

            if (!is_string($value)) {
                return;
            }

            $raw = trim($value);
            if ($raw === '') {
                return;
            }

            $candidate = '';
            if (str_starts_with($raw, '/')) {
                $candidate = $base . $raw;
            } elseif (filter_var($raw, FILTER_VALIDATE_URL)) {
                $candidate = $raw;
            }

            if ($candidate === '' || !self::isSafeHttpsUrl($candidate)) {
                return;
            }
            $candidateHost = strtolower((string) parse_url($candidate, PHP_URL_HOST));
            if ($candidateHost !== $sourceHost) {
                return;
            }

            $keyText = mb_strtolower($key, 'UTF-8');
            $pathText = mb_strtolower((string) parse_url($candidate, PHP_URL_PATH), 'UTF-8');
            $haystack = $keyText . ' ' . $pathText;
            $priority = 50;
            foreach ([
                'service' => 1,
                'product' => 2,
                'package' => 3,
                'catalog' => 4,
                'plan' => 5,
                'item' => 6,
                'category' => 20,
            ] as $needle => $score) {
                if (str_contains($haystack, $needle)) {
                    $priority = min($priority, $score);
                }
            }
            $found[$candidate] = min($found[$candidate] ?? 999, $priority);
        };

        $walk($body, '', 0);
        asort($found, SORT_NUMERIC);
        return array_keys($found);
    }

    private static function diagnosticTopLevelKeys(array $body): string
    {
        if ($body === []) {
            return '';
        }

        $keys = array_keys($body);
        $labels = [];
        foreach (array_slice($keys, 0, 12) as $key) {
            $text = trim((string) $key);
            if ($text !== '' && mb_strlen($text, 'UTF-8') <= 80) {
                $labels[] = $text;
            }
        }

        return implode(', ', $labels);
    }

    private static function authProfiles(
        string $apiKey,
        string $authHeader,
        string $authPrefix
    ): array {
        if (trim($apiKey) === '') {
            return [[
                'header' => '',
                'prefix' => '',
                'label' => 'none',
            ]];
        }

        $profiles = [
            ['header' => trim($authHeader), 'prefix' => trim($authPrefix), 'label' => 'configured'],
            ['header' => '', 'prefix' => '', 'label' => 'body-only'],
            ['header' => 'Authorization', 'prefix' => 'Bearer', 'label' => 'bearer'],
            ['header' => 'Authorization', 'prefix' => '', 'label' => 'authorization-raw'],
            ['header' => 'X-API-Key', 'prefix' => '', 'label' => 'x-api-key'],
            ['header' => 'X-Api-Key', 'prefix' => '', 'label' => 'x-api-key-alt'],
            ['header' => 'Api-Key', 'prefix' => '', 'label' => 'api-key'],
        ];

        $unique = [];
        $result = [];
        foreach ($profiles as $profile) {
            $header = trim((string) $profile['header']);
            $prefix = trim((string) $profile['prefix']);
            if ($header !== '' && !preg_match('/^[A-Za-z0-9-]{1,80}$/', $header)) {
                continue;
            }
            $key = strtolower($header) . '|' . $prefix;
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $result[] = $profile;
        }

        return $result;
    }

    private static function requestCatalogUrl(
        string $url,
        string $apiKey,
        string $authHeader,
        string $authPrefix,
        int $redirectsRemaining = 1
    ): array {
        if (!self::isSafeHttpsUrl($url)) {
            return ['ok' => false, 'message' => 'Catalog URL is unsafe.'];
        }

        $headers = ['Accept: application/json'];
        if ($apiKey !== '' && trim($authHeader) !== '') {
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
            CURLOPT_CONNECTTIMEOUT => self::DISCOVERY_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::DISCOVERY_REQUEST_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'BlueBot/0.5.36 ProviderCatalog',
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'message' => $error !== '' ? $error : 'Provider catalog request failed.'];
        }
        if ($http >= 300 && $http < 400 && $redirectsRemaining > 0 && $redirectUrl !== '') {
            $sourceHost = strtolower((string) parse_url($url, PHP_URL_HOST));
            $redirectHost = strtolower((string) parse_url($redirectUrl, PHP_URL_HOST));
            if ($sourceHost !== '' && $sourceHost === $redirectHost && self::isSafeHttpsUrl($redirectUrl)) {
                return self::requestCatalogUrl(
                    $redirectUrl,
                    $apiKey,
                    $authHeader,
                    $authPrefix,
                    $redirectsRemaining - 1
                );
            }
        }
        if ($http < 200 || $http >= 300) {
            $suffix = $redirectUrl !== '' ? ' Redirect: ' . $redirectUrl : '';
            return ['ok' => false, 'message' => 'Provider catalog returned HTTP ' . $http . '.' . $suffix];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'Provider catalog returned invalid JSON.'];
        }

        return ['ok' => true, 'data' => $decoded];
    }

    private static function requestSmmServices(
        string $url,
        string $apiKey,
        string $authHeader,
        string $authPrefix,
        int $redirectsRemaining = 1
    ): array {
        $legacyTivaHttp = self::isSafeTivaNovinLegacyUrl($url);
        if (!self::isSafeHttpsUrl($url) && !$legacyTivaHttp) {
            return ['ok' => false, 'message' => 'SMM catalog URL is unsafe.'];
        }
        if (trim($apiKey) === '') {
            return ['ok' => false, 'message' => 'SMM API key is missing.'];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $headerValue = trim(($authPrefix !== '' ? $authPrefix . ' ' : '') . $apiKey);
        if ($headerValue !== '' && trim($authHeader) !== '') {
            $headers[] = $authHeader . ': ' . $headerValue;
        }

        $payload = http_build_query([
            'key' => $apiKey,
            'action' => 'services',
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Unable to initialise SMM provider request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::DISCOVERY_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::DISCOVERY_REQUEST_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => $legacyTivaHttp ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'BlueBot/0.5.36 ProviderCatalog',
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'message' => $error !== '' ? $error : 'SMM provider request failed.'];
        }
        if ($http >= 300 && $http < 400 && $redirectsRemaining > 0 && $redirectUrl !== '') {
            $sourceHost = strtolower((string) parse_url($url, PHP_URL_HOST));
            $redirectHost = strtolower((string) parse_url($redirectUrl, PHP_URL_HOST));
            if (
                $sourceHost !== ''
                && $sourceHost === $redirectHost
                && (self::isSafeHttpsUrl($redirectUrl) || self::isSafeTivaNovinLegacyUrl($redirectUrl))
            ) {
                return self::requestSmmServices(
                    $redirectUrl,
                    $apiKey,
                    $authHeader,
                    $authPrefix,
                    $redirectsRemaining - 1
                );
            }
        }
        if ($http < 200 || $http >= 300) {
            $suffix = $redirectUrl !== '' ? ' Redirect: ' . $redirectUrl : '';
            return ['ok' => false, 'message' => 'SMM provider returned HTTP ' . $http . '.' . $suffix];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'SMM provider returned invalid JSON.'];
        }

        if (isset($decoded['error']) && trim((string) $decoded['error']) !== '') {
            return ['ok' => false, 'message' => trim((string) $decoded['error'])];
        }

        return ['ok' => true, 'data' => $decoded];
    }

    private static function firstNumericValue(array $item, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = self::valueAtPath($item, (string) $path);
            if (is_string($value)) {
                $value = str_replace([',', ' ', '٬'], '', trim($value));
            }
            if (is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    private static function isSafeHttpsUrl(string $url): bool
    {
        static $hostSafetyCache = [];

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

        if (array_key_exists($host, $hostSafetyCache)) {
            return (bool) $hostSafetyCache[$host];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $safe = (bool) filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            $hostSafetyCache[$host] = $safe;
            return $safe;
        }

        $ips = gethostbynamel($host);
        if (is_array($ips) && $ips !== []) {
            foreach ($ips as $ip) {
                if (!filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )) {
                    $hostSafetyCache[$host] = false;
                    return false;
                }
            }
        }

        $hostSafetyCache[$host] = true;
        return true;
    }

    private static function isSafeTivaNovinLegacyUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'http'
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if (!in_array($host, ['tivanovin.ir', 'www.tivanovin.ir'], true)) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 80) {
            return false;
        }

        $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        if (rtrim($path, '/') !== '/api') {
            return false;
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
