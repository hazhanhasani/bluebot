<?php

require_once __DIR__ . '/TgToolsClient.php';
require_once __DIR__ . '/DigitalServiceProviderCatalog.php';

final class BluebotDigitalServices
{
    private const STATUS_PENDING = 'pending_approval';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_DELIVERED = 'delivered';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_FAILED = 'failed';

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
             WHERE type = ? AND service_value = ?
             ORDER BY (provider = 'tgtools') DESC, id ASC
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
                $active = $price > 0 ? 1 : 0;
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

        $provider = BluebotProviderCatalogService::findProvider($pdo, 'ozvinoo');
        $productCountStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM digital_service_products WHERE provider = 'ozvinoo' AND active = 1"
        );
        $productCountStmt->execute();
        $activeProducts = (int) $productCountStmt->fetchColumn();

        if (is_array($provider) && $activeProducts > 0) {
            return ['ok' => true, 'skipped' => true, 'products' => $activeProducts];
        }

        $baseUrl = rtrim(self::setting($pdo, 'ozvinoo_base_url', 'https://api.ozvinoo.xyz'), '/');
        $catalogPath = trim(self::setting($pdo, 'ozvinoo_catalog_path', ''));
        $orderPath = trim(self::setting($pdo, 'ozvinoo_order_path', ''));
        $authHeader = trim(self::setting($pdo, 'ozvinoo_auth_header', 'Authorization'));
        $authPrefix = trim(self::setting($pdo, 'ozvinoo_auth_prefix', 'Bearer'));
        $profitPercent = max(0.0, min(1000.0, (float) self::setting($pdo, 'ozvinoo_profit_percent', '0')));
        $currency = strtolower(trim(self::setting($pdo, 'ozvinoo_currency', 'toman')));
        $exchangeRate = max(0.000001, (float) self::setting($pdo, 'ozvinoo_exchange_rate_toman', '1'));
        $syncInterval = max(1, min(1440, (int) self::setting($pdo, 'ozvinoo_sync_interval_minutes', '15')));

        $paths = [];
        if ($catalogPath !== '') {
            $paths[] = str_starts_with($catalogPath, '/') ? $catalogPath : '/' . $catalogPath;
        }
        if ($orderPath !== '') {
            $normalizedOrderPath = str_starts_with($orderPath, '/') ? $orderPath : '/' . $orderPath;
            $orderDir = rtrim(str_replace('\\', '/', dirname($normalizedOrderPath)), '/.');
            if ($orderDir !== '') {
                foreach (['/services', '/products', '/catalog', '/packages'] as $sibling) {
                    $paths[] = $orderDir . $sibling;
                }
            }
        }
        foreach ([
            '/api/v2',
            '/api/v1',
            '/api',
            '/v2',
            '/v1',
            '/api/services',
            '/api/service',
            '/services',
            '/api/services/list',
            '/api/v1/services',
            '/api/v1/services/list',
            '/v1/services',
            '/api/products',
            '/products',
            '/api/v1/products',
            '/v1/products',
            '/api/catalog',
            '/catalog',
            '/api/packages',
            '/packages',
        ] as $path) {
            $paths[] = $path;
        }

        $urls = array_map(
            static fn (string $path): string => $baseUrl . '/' . ltrim($path, '/'),
            array_values(array_unique($paths))
        );

        $discovery = BluebotProviderCatalogService::discoverCatalogUrl(
            $urls,
            $apiKey,
            $authHeader,
            $authPrefix
        );
        if (empty($discovery['ok'])) {
            return [
                'ok' => false,
                'skipped' => false,
                'message' => (string) ($discovery['message'] ?? 'OZVinoo catalog endpoint was not detected.'),
            ];
        }

        $catalogUrl = (string) $discovery['url'];
        $apiStyle = strtolower(trim((string) ($discovery['api_style'] ?? 'rest')));
        $detectedAuthHeader = trim((string) ($discovery['auth_header'] ?? $authHeader));
        $detectedAuthPrefix = trim((string) ($discovery['auth_prefix'] ?? $authPrefix));
        $detectedPath = (string) parse_url($catalogUrl, PHP_URL_PATH);
        if ($detectedPath !== '') {
            self::setSetting($pdo, 'ozvinoo_catalog_path', $detectedPath, false);
            if ($apiStyle === 'smm') {
                // Standard SMM APIs use the same endpoint for
                // action=services and action=add.
                self::setSetting($pdo, 'ozvinoo_order_path', $detectedPath, false);
            }
        }
        self::setSetting($pdo, 'ozvinoo_api_style', $apiStyle === 'smm' ? 'smm' : 'rest', false);
        self::setSetting($pdo, 'ozvinoo_auth_header', $detectedAuthHeader, false);
        self::setSetting($pdo, 'ozvinoo_auth_prefix', $detectedAuthPrefix, false);

        BluebotProviderCatalogService::saveProvider($pdo, [
            'provider_key' => 'ozvinoo',
            'name' => 'OZVinoo',
            'catalog_url' => $catalogUrl,
            'api_key' => $apiKey,
            'auth_header' => $detectedAuthHeader,
            'auth_prefix' => $detectedAuthPrefix,
            'products_path' => (string) ($discovery['products_path'] ?? 'auto'),
            'id_field' => (string) ($discovery['id_field'] ?? 'auto'),
            'name_field' => (string) ($discovery['name_field'] ?? 'auto'),
            'category_field' => (string) ($discovery['category_field'] ?? 'auto'),
            'price_field' => (string) ($discovery['price_field'] ?? 'auto'),
            'currency' => in_array($currency, ['toman', 'rial', 'usd', 'ton', 'other'], true) ? $currency : 'toman',
            'exchange_rate_toman' => $exchangeRate,
            'profit_percent' => $profitPercent,
            'sync_interval_minutes' => $syncInterval,
        ]);

        $sync = BluebotProviderCatalogService::syncProvider($pdo, 'ozvinoo');
        return array_merge(
            ['ok' => !empty($sync['ok']), 'skipped' => false, 'catalog_url' => $catalogUrl],
            $sync
        );
    }

    public static function categoryForProduct(array $product): string
    {
        $type = (string) ($product['type'] ?? '');
        if ($type === 'telegram_premium') {
            return 'premium';
        }
        if ($type === 'telegram_stars') {
            return 'stars';
        }

        $metadata = self::productMetadata($product);
        $key = strtolower(trim((string) ($metadata['category_key'] ?? '')));
        if ($key !== '' && preg_match('/^[a-z0-9_-]{1,40}$/', $key)) {
            return $key;
        }

        return 'other';
    }

    public static function categoryLabel(string $category, ?PDO $pdo = null): string
    {
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

    public static function categoryKeyboard(PDO $pdo, string $backText): string
    {
        $categories = [];
        foreach (self::listActive($pdo) as $product) {
            $category = self::categoryForProduct($product);
            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'count' => 0,
                    'label' => self::categoryLabel($category, $pdo),
                ];
            }
            $categories[$category]['count']++;
        }

        $priority = [
            'premium' => 10,
            'stars' => 20,
            'telegram' => 30,
            'instagram' => 40,
            'youtube' => 50,
            'twitter' => 60,
            'tiktok' => 70,
            'spotify' => 80,
            'linkedin' => 90,
            'facebook' => 100,
            'whatsapp' => 110,
            'virtual_number' => 120,
            'design' => 130,
            'likee' => 140,
            'naver' => 150,
            'giftcards' => 160,
            'games' => 170,
            'apple' => 180,
            'chatgpt' => 190,
            'other' => 999,
        ];

        uksort($categories, static function (string $a, string $b) use ($priority): int {
            $pa = $priority[$a] ?? 500;
            $pb = $priority[$b] ?? 500;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp($a, $b);
        });

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

    public static function catalogKeyboard(PDO $pdo, string $backText, ?string $category = null): string
    {
        $rows = [];
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

        return json_encode([
            'inline_keyboard' => [
                [[
                    'text' => '🛒 ثبت سفارش',
                    'callback_data' => 'ds_buy:' . (int) $product['id'],
                    'style' => 'success',
                ]],
                [[
                    'text' => '↩️ بازگشت',
                    'callback_data' => 'ds_category:' . $category,
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

    public static function validateTarget(array $product, string $target): array
    {
        $target = trim($target);
        if ($target === '' || mb_strlen($target, 'UTF-8') > 255) {
            return [false, 'شناسه مقصد معتبر نیست.'];
        }

        $type = (string) ($product['type'] ?? '');
        $provider = (string) ($product['provider'] ?? 'manual');

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
            sendmessage(
                (string) $processingOrder['user_id'],
                "⏳ <b>سفارش شما تأیید شد و در حال ارسال است</b>\n\n"
                    . "🧾 کد: <code>" . self::escape((string) $processingOrder['order_code']) . "</code>\n"
                    . "📦 " . self::escape((string) $processingOrder['service_name']),
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
        sendmessage(
            (string) $finalOrder['user_id'],
            "✅ <b>سفارش شما ارسال شد</b>\n\n"
                . "🧾 کد: <code>" . self::escape((string) $finalOrder['order_code']) . "</code>\n"
                . "📦 " . self::escape((string) $finalOrder['service_name']) . "\n"
                . "🎯 <code>" . self::escape((string) $finalOrder['target']) . "</code>",
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

