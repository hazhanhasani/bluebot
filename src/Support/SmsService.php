<?php

declare(strict_types=1);

final class BluebotSms
{
    private const DEFAULT_BASE_URL = 'https://api.iranpayamak.com/ws/v1';
    private const PATTERN_CACHE_TTL = 900;
    private const RETRY_DELAYS = [60, 300, 900, 1800];

    public static function catalog(): array
    {
        $v = static fn(string $name, string $type, int $length): array => [
            'name' => $name,
            'type' => $type,
            'length' => $length,
        ];

        return [
            'phone_verification' => [
                'title' => 'تأیید شماره موبایل',
                'category' => 'احراز هویت',
                'body' => 'کد تأیید BlueBot: %code%\nاین کد را در اختیار دیگران قرار ندهید.',
                'vars' => [$v('code', 'numeric', 6)],
                'default' => 1,
            ],
            'service_activated' => [
                'title' => 'فعال‌سازی سرویس',
                'category' => 'سرویس',
                'body' => 'سرویس %service% با نام کاربری %username% فعال شد. اعتبار تا %expire_date%',
                'vars' => [$v('service', 'text', 40), $v('username', 'text', 40), $v('expire_date', 'text', 20)],
                'default' => 1,
            ],
            'service_renewed' => [
                'title' => 'تمدید سرویس',
                'category' => 'سرویس',
                'body' => 'سرویس %username% تمدید شد. اعتبار جدید تا %expire_date%',
                'vars' => [$v('username', 'text', 40), $v('expire_date', 'text', 20)],
                'default' => 1,
            ],
            'subscription_reminder' => [
                'title' => 'یادآوری پایان سرویس',
                'category' => 'سرویس',
                'body' => 'تنها %days_left% روز از سرویس %username% باقی مانده است.',
                'vars' => [$v('days_left', 'numeric', 3), $v('username', 'text', 40)],
                'default' => 1,
            ],
            'subscription_expired' => [
                'title' => 'پایان زمان سرویس',
                'category' => 'سرویس',
                'body' => 'زمان سرویس %username% به پایان رسید.',
                'vars' => [$v('username', 'text', 40)],
                'default' => 1,
            ],
            'low_remaining_volume' => [
                'title' => 'هشدار کاهش حجم',
                'category' => 'سرویس',
                'body' => 'حجم باقی‌مانده سرویس %username% حدود %remaining_volume% گیگابایت است.',
                'vars' => [$v('username', 'text', 40), $v('remaining_volume', 'text', 12)],
                'default' => 1,
            ],
            'volume_expired' => [
                'title' => 'پایان حجم سرویس',
                'category' => 'سرویس',
                'body' => 'حجم سرویس %username% به پایان رسید.',
                'vars' => [$v('username', 'text', 40)],
                'default' => 1,
            ],
            'payment_success' => [
                'title' => 'پرداخت موفق',
                'category' => 'پرداخت',
                'body' => 'پرداخت %amount% تومان با موفقیت انجام شد. شماره سفارش: %order_id%',
                'vars' => [$v('amount', 'numeric', 12), $v('order_id', 'text', 40)],
                'default' => 1,
            ],
            'payment_failed' => [
                'title' => 'پرداخت ناموفق',
                'category' => 'پرداخت',
                'body' => 'پرداخت سفارش %order_id% ناموفق بود.',
                'vars' => [$v('order_id', 'text', 40)],
            ],
            'wallet_charged' => [
                'title' => 'شارژ کیف پول',
                'category' => 'کیف پول',
                'body' => 'کیف پول شما %amount% تومان شارژ شد. موجودی: %balance% تومان',
                'vars' => [$v('amount', 'numeric', 12), $v('balance', 'numeric', 12)],
            ],
            'admin_announcement' => [
                'title' => 'اطلاعیه عمومی',
                'category' => 'اطلاع‌رسانی',
                'body' => 'اطلاعیه BlueBot: %message%',
                'vars' => [$v('message', 'text', 120)],
                'broadcast' => 1,
            ],
        ];
    }

    public static function settings(): array
    {
        global $pdo;
        try {
            $stmt = $pdo->query('SELECT * FROM sms_settings WHERE id = 1 LIMIT 1');
            return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function seedTemplates(): void
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO sms_templates
                    (event_key,title,category,body,variables_json,pattern_code,enabled,broadcast,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    title=VALUES(title),category=VALUES(category),body=VALUES(body),
                    variables_json=VALUES(variables_json),broadcast=VALUES(broadcast)'
            );
            foreach (self::catalog() as $key => $spec) {
                $stmt->execute([
                    $key,
                    $spec['title'],
                    $spec['category'],
                    $spec['body'],
                    json_encode($spec['vars'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    '',
                    !empty($spec['default']) ? 1 : 0,
                    !empty($spec['broadcast']) ? 1 : 0,
                    time(),
                ]);
            }
        } catch (Throwable $e) {
            self::log('SMS template seed failed', ['error' => $e->getMessage()]);
        }
    }

    public static function templates(): array
    {
        global $pdo;
        self::seedTemplates();
        try {
            $stmt = $pdo->query('SELECT * FROM sms_templates ORDER BY category,title');
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function encryptSecret(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('افزونه OpenSSL برای ذخیره امن API Key فعال نیست.');
        }

        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher) || $tag === '') {
            throw new RuntimeException('رمزنگاری API Key ناموفق بود.');
        }

        return 'gcm1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decryptSecret(string $encoded): string
    {
        $encoded = trim($encoded);
        if ($encoded === '') {
            return '';
        }
        if (!str_starts_with($encoded, 'gcm1:') || !function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($encoded, 5), true);
        if (!is_string($raw) || strlen($raw) < 29) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return is_string($plain) ? $plain : '';
    }

    private static function encryptionKey(): string
    {
        $path = dirname(__DIR__, 2) . '/storage/sms.key';
        if (is_file($path) && is_readable($path)) {
            $raw = trim((string) @file_get_contents($path));
            $decoded = base64_decode($raw, true);
            if (is_string($decoded) && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('ساخت مسیر امن SMS ممکن نیست.');
        }

        $key = random_bytes(32);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, base64_encode($key) . PHP_EOL, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('ذخیره کلید رمزنگاری SMS ناموفق بود.');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('فعال‌سازی کلید رمزنگاری SMS ناموفق بود.');
        }
        @chmod($path, 0600);
        return $key;
    }

    public static function normalizePhone(string $raw): string
    {
        $raw = strtr(trim($raw), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4',
            '۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
            '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        $raw = preg_replace('/[^0-9+]/', '', $raw) ?: '';
        if (str_starts_with($raw, '0098')) {
            $raw = '+98' . substr($raw, 4);
        } elseif (str_starts_with($raw, '98')) {
            $raw = '+' . $raw;
        } elseif (str_starts_with($raw, '09')) {
            $raw = '+98' . substr($raw, 1);
        } elseif (preg_match('/^9\d{9}$/', $raw)) {
            $raw = '+98' . $raw;
        }
        return preg_match('/^\+989\d{9}$/', $raw) ? $raw : '';
    }

    private static function localPhone(string $phone): string
    {
        $phone = self::normalizePhone($phone);
        return $phone !== '' ? '0' . substr($phone, 3) : '';
    }

    private static function cleanParams(array $spec, array $params): array
    {
        $out = [];
        foreach (($spec['vars'] ?? []) as $var) {
            $name = (string) ($var['name'] ?? '');
            if ($name === '' || !array_key_exists($name, $params)) {
                throw new RuntimeException('پارامتر ' . $name . ' برای پیام «' . ($spec['title'] ?? '') . '» ارسال نشده است.');
            }
            $value = trim((string) $params[$name]);
            if (($var['type'] ?? '') === 'numeric') {
                $value = strtr($value, '۰۱۲۳۴۵۶۷۸۹', '0123456789');
                $value = preg_replace('/\D+/', '', $value) ?: '';
                if ($value === '') {
                    throw new RuntimeException('پارامتر ' . $name . ' باید عددی باشد.');
                }
            }
            $out[$name] = mb_substr($value, 0, max(1, (int) ($var['length'] ?? 160)));
        }
        return $out;
    }

    private static function baseUrl(array $settings): string
    {
        $base = rtrim(trim((string) ($settings['base_url'] ?? self::DEFAULT_BASE_URL)), '/');
        if ($base === '' || stripos($base, 'edge.ippanel.com') !== false) {
            return self::DEFAULT_BASE_URL;
        }
        if (!preg_match('~^https://~i', $base)) {
            throw new RuntimeException('Base URL سرویس پیامک باید HTTPS باشد.');
        }
        return $base;
    }

    private static function lineCachePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/sms_lines.json';
    }

    public static function lineCache(): array
    {
        $path = self::lineCachePath();
        if (!is_file($path) || !is_readable($path)) {
            return ['lines' => [], 'selected' => '', 'fetched_ts' => 0];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : ['lines' => [], 'selected' => '', 'fetched_ts' => 0];
    }

    private static function collectLineRows($node, array &$out, int $depth = 0): void
    {
        if ($depth > 7 || !is_array($node)) {
            return;
        }

        $candidate = $node['line_number']
            ?? $node['lineNumber']
            ?? $node['number']
            ?? $node['sender']
            ?? $node['from']
            ?? null;

        if (is_scalar($candidate) && trim((string) $candidate) !== '') {
            $out[] = $node;
            return;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                self::collectLineRows($value, $out, $depth + 1);
            }
        }
    }

    private static function normalizeLine(array $row): ?array
    {
        $number = trim((string) (
            $row['line_number']
            ?? $row['lineNumber']
            ?? $row['number']
            ?? $row['sender']
            ?? $row['from']
            ?? ''
        ));
        $number = preg_replace('/\s+/', '', strtr($number, '۰۱۲۳۴۵۶۷۸۹', '0123456789')) ?: '';
        if ($number === '' || !preg_match('/^[+0-9A-Za-z_-]{3,32}$/', $number)) {
            return null;
        }

        $status = strtolower(trim((string) ($row['status'] ?? $row['state'] ?? 'active')));
        if ($status !== '' && !in_array($status, ['active','enabled','approved','accepted','1','true'], true)) {
            return null;
        }

        $dedicatedRaw = $row['is_dedicated'] ?? $row['dedicated'] ?? $row['isDedicated'] ?? false;
        $dedicated = in_array(strtolower((string) $dedicatedRaw), ['1','true','yes','on'], true) || $dedicatedRaw === true;

        return [
            'number' => $number,
            'is_dedicated' => $dedicated ? 1 : 0,
            'title' => mb_substr(trim(strip_tags((string) ($row['title'] ?? $row['name'] ?? ''))), 0, 120),
        ];
    }

    public static function refreshLines(bool $force = true): array
    {
        global $pdo;

        $settings = self::settings();
        $apiKey = self::decryptSecret((string) ($settings['api_key_enc'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('ابتدا API Key فراز اس‌ام‌اس را ذخیره کنید.');
        }

        $base = self::baseUrl($settings);
        $keyHash = substr(hash('sha256', $apiKey . '|' . strtolower($base)), 0, 24);
        $cache = self::lineCache();

        if (!$force
            && hash_equals($keyHash, (string) ($cache['key_hash'] ?? ''))
            && time() - (int) ($cache['fetched_ts'] ?? 0) < self::PATTERN_CACHE_TTL
            && trim((string) ($cache['selected'] ?? '')) !== '') {
            return $cache;
        }

        $payload = self::request('GET', $base . '/lines/accessible', $apiKey, $settings);
        $rows = [];
        self::collectLineRows($payload, $rows);

        $lines = [];
        foreach ($rows as $row) {
            $line = self::normalizeLine($row);
            if ($line) {
                $lines[$line['number']] = $line;
            }
        }
        $lines = array_values($lines);
        usort($lines, static function (array $a, array $b): int {
            $dedicated = (int) $b['is_dedicated'] <=> (int) $a['is_dedicated'];
            return $dedicated !== 0 ? $dedicated : strnatcasecmp($a['number'], $b['number']);
        });

        if ($lines === []) {
            throw new RuntimeException('هیچ خط ارسال فعال و قابل‌استفاده‌ای برای این API Key پیدا نشد.');
        }

        $selected = (string) $lines[0]['number'];
        $cache = [
            'key_hash' => $keyHash,
            'fetched_ts' => time(),
            'selected' => $selected,
            'lines' => $lines,
            'count' => count($lines),
        ];
        self::writeJsonFile(self::lineCachePath(), $cache);

        try {
            $stmt = $pdo->prepare('UPDATE sms_settings SET from_number=?,updated_at=? WHERE id=1');
            $stmt->execute([$selected, time()]);
        } catch (Throwable $e) {
            self::log('SMS sender line persistence failed', ['error' => $e->getMessage()]);
        }

        self::recordHealth(true, count($lines) . ' خط ارسال دریافت شد؛ خط ' . $selected . ' به‌صورت خودکار انتخاب شد.');
        return $cache;
    }

    private static function lineNumber(array $settings): string
    {
        try {
            $cache = self::refreshLines(false);
            $line = trim((string) ($cache['selected'] ?? ''));
            if ($line !== '') {
                return $line;
            }
        } catch (Throwable $e) {
            self::log('Automatic SMS sender line lookup failed', ['error' => $e->getMessage()]);
        }

        // Resilience fallback only: keep the last verified line if the provider
        // is temporarily unavailable. New API keys refresh and replace it.
        $line = preg_replace('/\s+/', '', strtr((string) ($settings['from_number'] ?? ''), '۰۱۲۳۴۵۶۷۸۹', '0123456789')) ?: '';
        return preg_match('/^[+0-9A-Za-z_-]{3,32}$/', $line) ? $line : '';
    }

    private static function request(string $method, string $url, string $apiKey, array $settings, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('افزونه cURL روی PHP فعال نیست.');
        }

        $ch = curl_init($url);
        $headers = [
            'Api-Key: ' . $apiKey,
            'Accept: application/json',
            'User-Agent: BlueBot-SMS/1.0',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => !isset($settings['verify_tls']) || (int) $settings['verify_tls'] === 1,
            CURLOPT_SSL_VERIFYHOST => (!isset($settings['verify_tls']) || (int) $settings['verify_tls'] === 1) ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ]);
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('ارتباط با سرویس پیامک برقرار نشد: ' . ($error ?: 'network error'));
        }

        $decoded = json_decode((string) $body, true);
        $data = is_array($decoded) ? $decoded : [];
        if ($status < 200 || $status >= 300) {
            $message = self::providerMessage($data, 'سرویس پیامک درخواست را رد کرد (HTTP ' . $status . ').');
            throw new RuntimeException($message);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('پاسخ سرویس پیامک JSON معتبر نیست.');
        }
        if ((isset($data['success']) && $data['success'] === false)
            || (isset($data['status']) && $data['status'] === false)
            || (isset($data['meta']['status']) && $data['meta']['status'] === false)) {
            throw new RuntimeException(self::providerMessage($data, 'سرویس پیامک درخواست را رد کرد.'));
        }

        return $data;
    }

    private static function providerMessage(array $payload, string $fallback): string
    {
        if (isset($payload['meta']['message']) && is_scalar($payload['meta']['message'])) {
            return mb_substr(strip_tags((string) $payload['meta']['message']), 0, 500);
        }
        foreach (['message','error','detail'] as $key) {
            if (!isset($payload[$key])) {
                continue;
            }
            $value = $payload[$key];
            if (is_array($value)) {
                $value = $value['message'] ?? $value['detail'] ?? json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $value = trim(strip_tags((string) $value));
            if ($value !== '') {
                return mb_substr($value, 0, 500);
            }
        }
        return $fallback;
    }

    private static function recordHealth(bool $ok, string $message): void
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare(
                'UPDATE sms_settings
                 SET last_test_ok=?,last_test_message=?,last_test_at=?,updated_at=?
                 WHERE id=1'
            );
            $stmt->execute([$ok ? 1 : 0, mb_substr(strip_tags($message), 0, 1000), time(), time()]);
        } catch (Throwable $e) {
        }
    }

    public static function sendPattern(string $phone, string $patternCode, array $params): array
    {
        $settings = self::settings();
        $apiKey = self::decryptSecret((string) ($settings['api_key_enc'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('API Key فراز اس‌ام‌اس / ایران‌پیامک ثبت نشده است.');
        }
        $patternCode = trim($patternCode);
        if ($patternCode === '') {
            throw new RuntimeException('کد پترن ثبت نشده است.');
        }
        $line = self::lineNumber($settings);
        if ($line === '') {
            throw new RuntimeException('شماره خط ارسال معتبر نیست.');
        }
        $phone = self::normalizePhone($phone);
        if ($phone === '') {
            throw new RuntimeException('شماره موبایل معتبر نیست.');
        }

        try {
            $response = self::request('POST', self::baseUrl($settings) . '/sms/pattern', $apiKey, $settings, [
                'code' => $patternCode,
                'attributes' => $params !== [] ? $params : (object) [],
                'recipient' => self::localPhone($phone),
                'number_format' => 'english',
                'line_number' => $line,
            ]);
            self::recordHealth(true, 'Provider accepted SMS pattern request.');
            return $response;
        } catch (Throwable $e) {
            self::recordHealth(false, $e->getMessage());
            throw $e;
        }
    }

    public static function sendTemplateNow(string $eventKey, string $phone, array $params): array
    {
        global $pdo;

        $spec = self::catalog()[$eventKey] ?? null;
        if (!$spec) {
            throw new RuntimeException('نوع پیام ناشناخته است.');
        }

        $patternCode = self::ensurePatternForEvent($eventKey);
        if ($patternCode === '') {
            throw new RuntimeException('پترن سازگار و فعال برای «' . ($spec['title'] ?? $eventKey) . '» پیدا نشد.');
        }

        return self::sendPattern($phone, $patternCode, self::cleanParams($spec, $params));
    }

    public static function refreshPatterns(bool $force = true): array
    {
        $settings = self::settings();
        $apiKey = self::decryptSecret((string) ($settings['api_key_enc'] ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('ابتدا API Key فراز اس‌ام‌اس را ذخیره کنید.');
        }

        $base = self::baseUrl($settings);
        $cachePath = self::patternCachePath();
        $keyHash = substr(hash('sha256', $apiKey . '|' . strtolower($base)), 0, 24);

        if (!$force && is_file($cachePath)) {
            $cached = json_decode((string) @file_get_contents($cachePath), true);
            if (is_array($cached)
                && hash_equals($keyHash, (string) ($cached['key_hash'] ?? ''))
                && time() - (int) ($cached['fetched_ts'] ?? 0) < self::PATTERN_CACHE_TTL) {
                return $cached;
            }
        }

        $patterns = [];
        $seenProviderCodes = [];
        $pagesFetched = 0;
        $page = 1;
        // FarazSMS may paginate the pattern collection. Keep walking until the
        // provider explicitly reports the end, returns an empty page, or starts
        // repeating a page. The high guard is only a runaway-protection limit;
        // hitting it is treated as an error so we never silently cache a partial
        // "all patterns" result.
        $maxPages = 500;
        $truncated = false;

        while ($page <= $maxPages) {
            $query = http_build_query([
                'page' => $page,
                'limit' => 100,
                'per_page' => 100,
                'status' => 'active',
                // The current FarazSMS docs contain the historical "staus"
                // spelling in the pattern filter example. Supplying both keeps
                // compatibility with deployments using either spelling.
                'staus' => 'active',
                'sort_by' => 'updated_at',
                'sort_type' => 'desc',
            ]);
            $payload = self::request(
                'GET',
                $base . '/patterns?' . $query,
                $apiKey,
                $settings
            );

            $rows = [];
            self::collectPatternRows($payload, $rows);
            $stats = self::patternPageStats($payload, $rows);

            if ($rows === []) {
                break;
            }

            $pagesFetched++;
            $newCodes = 0;
            foreach ($rows as $row) {
                $normalized = self::normalizePattern($row);
                if (!$normalized) {
                    continue;
                }
                $code = $normalized['code'];
                if (!isset($seenProviderCodes[$code])) {
                    $seenProviderCodes[$code] = true;
                    $newCodes++;
                }
                $patterns[$code] = $normalized;
            }

            $current = (int) ($stats['current_page'] ?? 0);
            $last = (int) ($stats['last_page'] ?? 0);
            $hasNext = $stats['has_next'] ?? null;

            if ($last > 0 && ($current > 0 ? $current : $page) >= $last) {
                break;
            }
            if ($hasNext === false) {
                break;
            }
            // Some deployments ignore page/limit and return page one forever.
            if ($page > 1 && $newCodes === 0) {
                break;
            }

            if ($page >= $maxPages) {
                $truncated = true;
                break;
            }
            $page++;
        }

        if ($truncated) {
            throw new RuntimeException(
                'تعداد صفحات پترن‌ها از حد ایمنی ' . $maxPages .
                ' صفحه بیشتر است؛ برای جلوگیری از ذخیره فهرست ناقص، همگام‌سازی متوقف شد.'
            );
        }

        $patterns = array_values($patterns);
        usort($patterns, static fn(array $a, array $b): int =>
            strnatcasecmp($a['description'] ?: $a['text'] ?: $a['code'], $b['description'] ?: $b['text'] ?: $b['code'])
        );

        $cache = [
            'key_hash' => $keyHash,
            'fetched_ts' => time(),
            'patterns' => $patterns,
            'count' => count($patterns),
            'pages_fetched' => $pagesFetched,
            'provider_rows_seen' => count($seenProviderCodes),
            'complete' => true,
        ];
        self::writeJsonFile($cachePath, $cache);
        self::recordHealth(true, count($patterns) . ' پترن فعال در ' . $pagesFetched . ' صفحه دریافت شد.');
        return $cache;
    }

    private static function patternPageStats(array $payload, array $rows): array
    {
        $current = 0;
        $last = 0;
        $hasNext = null;

        $candidates = [$payload];
        foreach (['meta','pagination','data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $candidates[] = $payload[$key];
                if (isset($payload[$key]['meta']) && is_array($payload[$key]['meta'])) {
                    $candidates[] = $payload[$key]['meta'];
                }
            }
        }

        foreach ($candidates as $node) {
            foreach (['current_page','currentPage','page'] as $key) {
                if ($current <= 0 && isset($node[$key]) && is_numeric($node[$key])) {
                    $current = (int) $node[$key];
                }
            }
            foreach (['last_page','lastPage','total_pages','totalPages'] as $key) {
                if ($last <= 0 && isset($node[$key]) && is_numeric($node[$key])) {
                    $last = (int) $node[$key];
                }
            }
            foreach (['has_next','hasNext','has_more','hasMore'] as $key) {
                if (array_key_exists($key, $node)) {
                    $value = $node[$key];
                    $hasNext = is_bool($value)
                        ? $value
                        : in_array(strtolower((string) $value), ['1','true','yes'], true);
                }
            }
            if ($hasNext === null && isset($node['next_page_url'])) {
                $hasNext = trim((string) $node['next_page_url']) !== '';
            }
        }

        return [
            'row_count' => count($rows),
            'current_page' => $current,
            'last_page' => $last,
            'has_next' => $hasNext,
        ];
    }

    public static function patternCache(): array
    {
        $path = self::patternCachePath();
        if (!is_file($path)) {
            return ['patterns' => [], 'count' => 0, 'fetched_ts' => 0];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : ['patterns' => [], 'count' => 0, 'fetched_ts' => 0];
    }

    private static function patternCachePath(): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/sms_patterns.json';
    }

    private static function collectPatternRows($node, array &$out, int $depth = 0): void
    {
        if ($depth > 7 || !is_array($node)) {
            return;
        }
        if (isset($node['code']) && is_scalar($node['code']) && trim((string) $node['code']) !== '') {
            $out[] = $node;
            return;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::collectPatternRows($value, $out, $depth + 1);
            }
        }
    }

    private static function normalizePattern(array $row): ?array
    {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            return null;
        }

        $status = strtolower(trim((string) ($row['status'] ?? $row['state'] ?? 'active')));
        if ($status !== '' && !in_array($status, ['active','approved','accepted','accept','1','true'], true)) {
            return null;
        }

        $text = trim(strip_tags((string) ($row['text'] ?? $row['pattern'] ?? $row['body'] ?? '')));
        $description = trim(strip_tags((string) ($row['description'] ?? $row['title'] ?? '')));
        $vars = [];
        $specs = [];

        foreach (['vars','variables','attributes'] as $key) {
            $raw = $row[$key] ?? null;
            if (!is_array($raw)) {
                continue;
            }

            foreach ($raw as $itemKey => $item) {
                $name = '';
                $type = 'text';
                $length = 160;

                if (is_string($item)) {
                    $name = $item;
                } elseif (is_array($item)) {
                    $name = (string) ($item['var'] ?? $item['name'] ?? $item['key'] ?? $item['attribute'] ?? (is_string($itemKey) ? $itemKey : ''));
                    $rawType = strtolower((string) ($item['type'] ?? $item['data_type'] ?? $item['variable_type'] ?? ''));
                    if (in_array($rawType, ['int','integer','number','numeric'], true)) {
                        $type = 'numeric';
                    }
                    foreach (['length','max_length','maxLength','limit'] as $lengthKey) {
                        if (isset($item[$lengthKey]) && is_numeric($item[$lengthKey])) {
                            $length = max(1, min(500, (int) $item[$lengthKey]));
                            break;
                        }
                    }
                }

                $name = preg_replace('/[^A-Za-z0-9_-]/', '', trim($name)) ?: '';
                if ($name === '') {
                    continue;
                }
                if (!in_array($name, $vars, true)) {
                    $vars[] = $name;
                }
                $specs[$name] = ['name' => $name, 'type' => $type, 'length' => $length];
            }
        }

        if ($text !== '' && preg_match_all('/%([A-Za-z0-9_-]+)%/', $text, $matches)) {
            foreach ($matches[1] as $name) {
                if (!in_array($name, $vars, true)) {
                    $vars[] = $name;
                }
                if (!isset($specs[$name])) {
                    $specs[$name] = ['name' => $name, 'type' => 'text', 'length' => 160];
                }
            }
        }

        sort($vars, SORT_STRING);
        ksort($specs, SORT_STRING);

        return [
            'code' => mb_substr($code, 0, 180),
            'text' => mb_substr($text, 0, 600),
            'description' => mb_substr($description, 0, 250),
            'variables' => $vars,
            'variable_specs' => array_values($specs),
        ];
    }

    public static function smartAssignPatterns(array $patterns, bool $overwrite = false): array
    {
        global $pdo;
        self::seedTemplates();
        $available = [];
        foreach ($patterns as $pattern) {
            if (is_array($pattern) && !empty($pattern['code'])) {
                $available[(string) $pattern['code']] = $pattern;
            }
        }
        $templates = self::templates();
        $used = [];
        if (!$overwrite) {
            foreach ($templates as $template) {
                $code = trim((string) ($template['pattern_code'] ?? ''));
                if ($code !== '') {
                    $used[$code] = true;
                }
            }
        }

        $assigned = 0;
        $mappings = [];
        foreach ($templates as $template) {
            $key = (string) $template['event_key'];
            $current = trim((string) ($template['pattern_code'] ?? ''));
            if (!$overwrite && $current !== '') {
                continue;
            }
            $spec = self::catalog()[$key] ?? null;
            if (!$spec) {
                continue;
            }

            $best = null;
            foreach ($available as $code => $pattern) {
                if (isset($used[$code])) {
                    continue;
                }
                if (!self::patternCompatibleWithSpec($pattern, $spec)) {
                    continue;
                }
                $haystack = self::normalizeText(($pattern['description'] ?? '') . ' ' . ($pattern['text'] ?? ''));
                $score = 70;
                foreach (self::tokens(($spec['title'] ?? '') . ' ' . ($spec['body'] ?? '')) as $token) {
                    if ($token !== '' && str_contains($haystack, $token)) {
                        $score += 8;
                    }
                }
                if ($best === null || $score > $best['score']) {
                    $best = ['code' => $code, 'score' => $score];
                }
            }
            if ($best === null || $best['score'] < 78) {
                continue;
            }

            $stmt = $pdo->prepare('UPDATE sms_templates SET pattern_code=?,updated_at=? WHERE event_key=?');
            $stmt->execute([$best['code'], time(), $key]);
            $used[$best['code']] = true;
            $assigned++;
            $mappings[] = ['event_key' => $key, 'code' => $best['code'], 'score' => $best['score']];
        }

        return ['assigned' => $assigned, 'mappings' => $mappings];
    }

    private static function patternCompatibleWithSpec(array $pattern, array $spec): bool
    {
        $expected = [];
        foreach (($spec['vars'] ?? []) as $var) {
            $expected[(string) ($var['name'] ?? '')] = (string) ($var['type'] ?? 'text');
        }
        unset($expected['']);

        $provided = [];
        foreach ((array) ($pattern['variable_specs'] ?? []) as $var) {
            if (is_array($var) && !empty($var['name'])) {
                $provided[(string) $var['name']] = (string) ($var['type'] ?? 'text');
            }
        }
        if ($provided === []) {
            foreach ((array) ($pattern['variables'] ?? []) as $name) {
                $provided[(string) $name] = 'text';
            }
        }

        if (array_keys($expected) !== array_keys($provided)) {
            $expectedNames = array_keys($expected);
            $providedNames = array_keys($provided);
            sort($expectedNames, SORT_STRING);
            sort($providedNames, SORT_STRING);
            if ($expectedNames !== $providedNames) {
                return false;
            }
        }

        foreach ($expected as $name => $type) {
            if ($type === 'numeric' && ($provided[$name] ?? 'text') !== 'numeric') {
                return false;
            }
        }

        return true;
    }

    public static function ensurePatternForEvent(string $eventKey, bool $forceRefresh = false): string
    {
        global $pdo;

        $spec = self::catalog()[$eventKey] ?? null;
        if (!$spec) {
            return '';
        }

        $loadCurrent = static function () use ($pdo, $eventKey): string {
            $stmt = $pdo->prepare('SELECT pattern_code FROM sms_templates WHERE event_key=? LIMIT 1');
            $stmt->execute([$eventKey]);
            return trim((string) ($stmt->fetchColumn() ?: ''));
        };

        $cache = self::refreshPatterns($forceRefresh);
        $byCode = [];
        foreach ((array) ($cache['patterns'] ?? []) as $pattern) {
            if (is_array($pattern) && !empty($pattern['code'])) {
                $byCode[(string) $pattern['code']] = $pattern;
            }
        }

        $current = $loadCurrent();
        if ($current !== '' && isset($byCode[$current]) && self::patternCompatibleWithSpec($byCode[$current], $spec)) {
            return $current;
        }

        self::smartAssignPatterns((array) ($cache['patterns'] ?? []), true);
        $current = $loadCurrent();
        return $current !== '' && isset($byCode[$current]) && self::patternCompatibleWithSpec($byCode[$current], $spec)
            ? $current
            : '';
    }

    private static function normalizeText(string $text): string
    {
        $text = mb_strtolower(strip_tags($text), 'UTF-8');
        $text = strtr($text, ['ي'=>'ی','ك'=>'ک','ۀ'=>'ه','ة'=>'ه']);
        $text = preg_replace('/%[A-Za-z0-9_-]+%/', ' ', $text) ?: $text;
        return trim(preg_replace('/[^\p{L}\p{N}_]+/u', ' ', $text) ?: $text);
    }

    private static function tokens(string $text): array
    {
        $text = self::normalizeText($text);
        $stop = ['blue','vpn','bluevpn','بلو','وی','پی','ان','سرویس','شما','برای','شد','است','به','از','و'];
        return array_values(array_filter(
            preg_split('/\s+/u', $text) ?: [],
            static fn(string $token): bool => mb_strlen($token, 'UTF-8') >= 3 && !in_array($token, $stop, true)
        ));
    }

    public static function queue(
        string $eventKey,
        string $phone,
        array $params,
        ?string $userId = null,
        ?string $invoiceId = null,
        ?string $orderId = null,
        string $dedupeSeed = '',
        bool $force = false
    ): ?string {
        global $pdo;

        $spec = self::catalog()[$eventKey] ?? null;
        if (!$spec) {
            return null;
        }
        $settings = self::settings();
        if (!$force && empty($settings['active'])) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM sms_templates WHERE event_key=? LIMIT 1');
            $stmt->execute([$eventKey]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$template || (!$force && empty($template['enabled']))) {
                return null;
            }

            // Pattern selection is provider-driven. A newly-created or changed
            // FarazSMS pattern can therefore be discovered without manually
            // opening the BlueBot panel first.
            try {
                $patternCode = self::ensurePatternForEvent($eventKey);
            } catch (Throwable $patternError) {
                self::log('Automatic SMS pattern lookup failed', [
                    'event' => $eventKey,
                    'error' => $patternError->getMessage(),
                ]);
                return null;
            }
            if ($patternCode === '') {
                return null;
            }

            $phone = self::normalizePhone($phone);
            if ($phone === '') {
                return null;
            }
            $clean = self::cleanParams($spec, $params);
            $seed = $dedupeSeed !== '' ? $dedupeSeed : date('YmdHi');
            $dedupe = hash('sha256', json_encode([$eventKey, $phone, $clean, $seed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $id = self::uuid4();

            $insert = $pdo->prepare(
                'INSERT INTO sms_deliveries
                 (id,event_key,user_id,invoice_id,order_id,phone,params_json,dedupe_key,status,attempts,max_attempts,provider_message_id,response_json,last_error,next_attempt_at,sending_started_at,sent_at,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute([
                $id,
                $eventKey,
                $userId,
                $invoiceId,
                $orderId,
                $phone,
                json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $dedupe,
                'pending',
                0,
                max(1, min(5, (int) ($settings['retry_max_attempts'] ?? 3))),
                '',
                '',
                '',
                time(),
                null,
                null,
                time(),
            ]);
            return $id;
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                self::log('SMS queue database error', ['error' => $e->getMessage(), 'event' => $eventKey]);
            }
            return null;
        } catch (Throwable $e) {
            self::log('SMS queue error', ['error' => $e->getMessage(), 'event' => $eventKey]);
            return null;
        }
    }

    public static function queueForUser(
        string $eventKey,
        string $userId,
        array $params,
        ?string $invoiceId = null,
        ?string $orderId = null,
        string $dedupeSeed = '',
        bool $force = false
    ): ?string {
        global $pdo;
        try {
            $stmt = $pdo->prepare('SELECT number,sms_enabled FROM user WHERE id=? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$user || (!$force && (int) ($user['sms_enabled'] ?? 1) !== 1)) {
                return null;
            }
            $phone = (string) ($user['number'] ?? '');
            return self::queue($eventKey, $phone, $params, $userId, $invoiceId, $orderId, $dedupeSeed, $force);
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function queueAndDispatchForUser(
        string $eventKey,
        string $userId,
        array $params,
        ?string $invoiceId = null,
        ?string $orderId = null,
        string $dedupeSeed = ''
    ): array {
        $id = self::queueForUser($eventKey, $userId, $params, $invoiceId, $orderId, $dedupeSeed);
        if (!$id) {
            return ['ok' => false, 'queued' => false, 'sent' => false, 'message' => 'پیامک در صف قرار نگرفت.'];
        }
        $result = self::dispatchNow($id);
        return $result + ['queued' => true, 'delivery_id' => $id];
    }

    public static function dispatchNow(string $deliveryId): array
    {
        global $pdo;
        $deliveryId = trim($deliveryId);
        if ($deliveryId === '') {
            return ['ok' => false, 'sent' => false, 'status' => 'missing', 'message' => 'شناسه پیامک خالی است.'];
        }

        $lock = 'bluebot_sms_' . substr(hash('sha256', $deliveryId), 0, 32);
        try {
            $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,0)');
            $lockStmt->execute([$lock]);
            if ((int) $lockStmt->fetchColumn() !== 1) {
                return ['ok' => true, 'sent' => false, 'status' => 'queued', 'message' => 'پیامک در صف است.'];
            }

            $stmt = $pdo->prepare('SELECT * FROM sms_deliveries WHERE id=? LIMIT 1');
            $stmt->execute([$deliveryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                return ['ok' => false, 'sent' => false, 'status' => 'missing', 'message' => 'پیامک پیدا نشد.'];
            }
            if ((string) $row['status'] === 'sent') {
                return ['ok' => true, 'sent' => true, 'status' => 'sent', 'message' => 'قبلاً ارسال شده است.'];
            }
            if (!in_array((string) $row['status'], ['pending','retry'], true)) {
                return ['ok' => false, 'sent' => false, 'status' => (string) $row['status'], 'message' => (string) ($row['last_error'] ?? '')];
            }

            $templateStmt = $pdo->prepare('SELECT * FROM sms_templates WHERE event_key=? LIMIT 1');
            $templateStmt->execute([$row['event_key']]);
            $template = $templateStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $spec = self::catalog()[(string) $row['event_key']] ?? null;
            if (!$template || !$spec || empty($template['enabled'])) {
                $pdo->prepare("UPDATE sms_deliveries SET status='skipped',last_error=?,next_attempt_at=NULL WHERE id=?")
                    ->execute(['رویداد پیامک غیرفعال است.', $deliveryId]);
                return ['ok' => false, 'sent' => false, 'status' => 'skipped', 'message' => 'رویداد پیامک غیرفعال است.'];
            }

            try {
                $patternCode = self::ensurePatternForEvent((string) $row['event_key']);
            } catch (Throwable $patternError) {
                $patternCode = '';
                self::log('Automatic SMS pattern lookup failed during dispatch', [
                    'event' => (string) $row['event_key'],
                    'error' => $patternError->getMessage(),
                ]);
            }
            if ($patternCode === '') {
                $pdo->prepare("UPDATE sms_deliveries SET status='skipped',last_error=?,next_attempt_at=NULL WHERE id=?")
                    ->execute(['پترن فعال و سازگار به‌صورت خودکار پیدا نشد.', $deliveryId]);
                return ['ok' => false, 'sent' => false, 'status' => 'skipped', 'message' => 'پترن فعال و سازگار پیدا نشد.'];
            }

            $attempts = (int) $row['attempts'] + 1;
            $pdo->prepare("UPDATE sms_deliveries SET status='sending',attempts=?,sending_started_at=? WHERE id=?")
                ->execute([$attempts, time(), $deliveryId]);

            try {
                $params = json_decode((string) $row['params_json'], true);
                $params = is_array($params) ? $params : [];
                $response = self::sendPattern((string) $row['phone'], $patternCode, self::cleanParams($spec, $params));
                $providerId = self::providerId($response);
                $pdo->prepare(
                    "UPDATE sms_deliveries
                     SET status='sent',sent_at=?,provider_message_id=?,response_json=?,last_error='',next_attempt_at=NULL,sending_started_at=NULL
                     WHERE id=?"
                )->execute([
                    time(),
                    $providerId,
                    mb_substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 8000),
                    $deliveryId,
                ]);
                return ['ok' => true, 'sent' => true, 'status' => 'sent', 'message' => 'پیامک ارسال شد.'];
            } catch (Throwable $e) {
                $max = max(1, (int) $row['max_attempts']);
                $status = $attempts >= $max ? 'failed' : 'retry';
                $next = null;
                if ($status === 'retry') {
                    $delay = self::RETRY_DELAYS[min($attempts - 1, count(self::RETRY_DELAYS) - 1)];
                    $next = time() + $delay;
                }
                $pdo->prepare(
                    'UPDATE sms_deliveries SET status=?,last_error=?,next_attempt_at=?,sending_started_at=NULL WHERE id=?'
                )->execute([$status, mb_substr($e->getMessage(), 0, 2000), $next, $deliveryId]);
                return ['ok' => false, 'sent' => false, 'status' => $status, 'message' => $e->getMessage()];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'sent' => false, 'status' => 'error', 'message' => $e->getMessage()];
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lock]);
            } catch (Throwable $e) {
            }
        }
    }

    public static function processQueue(int $limit = 50): array
    {
        global $pdo;
        $limit = max(1, min(100, $limit));
        try {
            $stale = time() - 600;
            $pdo->prepare(
                "UPDATE sms_deliveries
                 SET status='retry',next_attempt_at=?,sending_started_at=NULL,last_error=CONCAT(COALESCE(last_error,''),' | recovered stale sending')
                 WHERE status='sending' AND sending_started_at IS NOT NULL AND sending_started_at<?"
            )->execute([time(), $stale]);

            $stmt = $pdo->prepare(
                "SELECT id FROM sms_deliveries
                 WHERE status IN ('pending','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=?)
                 ORDER BY created_at ASC LIMIT {$limit}"
            );
            $stmt->execute([time()]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $sent = 0;
            $failed = 0;
            foreach ($ids as $id) {
                $result = self::dispatchNow((string) $id);
                if (!empty($result['sent'])) {
                    $sent++;
                } elseif (in_array((string) ($result['status'] ?? ''), ['failed','retry','skipped','error'], true)) {
                    $failed++;
                }
            }
            return ['processed' => count($ids), 'sent' => $sent, 'failed' => $failed];
        } catch (Throwable $e) {
            self::log('SMS queue process failed', ['error' => $e->getMessage()]);
            return ['processed' => 0, 'sent' => 0, 'failed' => 1];
        }
    }

    public static function retry(string $deliveryId): bool
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare(
                "UPDATE sms_deliveries
                 SET status='retry',attempts=0,last_error='',next_attempt_at=?,sending_started_at=NULL
                 WHERE id=?"
            );
            $stmt->execute([time(), $deliveryId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function broadcast(string $eventKey, array $params, bool $onlyActive = true): int
    {
        global $pdo;
        $spec = self::catalog()[$eventKey] ?? null;
        if (!$spec || empty($spec['broadcast'])) {
            throw new RuntimeException('این پیام برای ارسال عمومی مجاز نیست.');
        }

        $sql = "SELECT id,number FROM user WHERE number IS NOT NULL AND number<>'' AND COALESCE(sms_enabled,1)=1";
        if ($onlyActive) {
            $sql .= " AND COALESCE(User_Status,'Active') NOT IN ('block','blocked')";
        }
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $seed = 'broadcast:' . date('YmdHis') . ':' . bin2hex(random_bytes(3));
        $count = 0;
        foreach ($rows as $row) {
            if (self::queue($eventKey, (string) $row['number'], $params, (string) $row['id'], null, null, $seed . ':' . $row['id'])) {
                $count++;
            }
        }
        return $count;
    }

    public static function syncServiceStatus(array $invoice, array $user, array $userData): void
    {
        $settings = self::settings();
        if (empty($settings['active'])) {
            return;
        }

        $userId = (string) ($invoice['id_user'] ?? $user['id'] ?? '');
        $invoiceId = (string) ($invoice['id_invoice'] ?? '');
        $username = trim((string) ($invoice['username'] ?? $userData['username'] ?? ''));
        if ($userId === '' || $invoiceId === '' || $username === '') {
            return;
        }

        $expire = $userData['expire'] ?? 0;
        $expireTs = is_numeric($expire) ? (int) $expire : (strtotime((string) $expire) ?: 0);
        $cycle = $expireTs > 0 ? (string) $expireTs : (string) ($invoice['time_sell'] ?? '0');
        $status = strtolower((string) ($userData['status'] ?? ''));

        if ($expireTs > time()) {
            $daysLeft = (int) ceil(($expireTs - time()) / 86400);
            $days = json_decode((string) ($settings['reminder_days_json'] ?? '[3,2,1]'), true);
            $days = is_array($days) ? array_map('intval', $days) : [3,2,1];
            if (in_array($daysLeft, $days, true)) {
                self::queueForUser(
                    'subscription_reminder',
                    $userId,
                    ['days_left' => $daysLeft, 'username' => $username],
                    $invoiceId,
                    null,
                    'reminder:' . $invoiceId . ':' . $cycle . ':' . $daysLeft
                );
            }
        }

        $limit = max(0, (int) ($userData['data_limit'] ?? 0));
        $used = max(0, (int) ($userData['used_traffic'] ?? 0));
        if ($limit > 0) {
            $remaining = max(0, $limit - $used);
            if ($remaining <= 0) {
                self::queueForUser(
                    'volume_expired',
                    $userId,
                    ['username' => $username],
                    $invoiceId,
                    null,
                    'volume-expired:' . $invoiceId . ':' . $cycle
                );
            } else {
                $threshold = max(1, (int) ($settings['low_volume_threshold_gb'] ?? 5)) * 1024 * 1024 * 1024;
                if ($remaining <= $threshold) {
                    $gb = max(0.1, round($remaining / (1024 ** 3), 1));
                    self::queueForUser(
                        'low_remaining_volume',
                        $userId,
                        ['username' => $username, 'remaining_volume' => (string) $gb],
                        $invoiceId,
                        null,
                        'low-volume:' . $invoiceId . ':' . $cycle . ':' . (int) floor($remaining / (1024 ** 2))
                    );
                }
            }
        }

        if (($expireTs > 0 && $expireTs <= time()) || in_array($status, ['expired','on_hold'], true)) {
            self::queueForUser(
                'subscription_expired',
                $userId,
                ['username' => $username],
                $invoiceId,
                null,
                'expired:' . $invoiceId . ':' . $cycle
            );
        }
    }

    public static function formatDate(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'نامحدود';
        }
        if (function_exists('jdate')) {
            return (string) jdate('Y/m/d', $timestamp);
        }
        return date('Y/m/d', $timestamp);
    }

    public static function phoneOtpEnabled(): bool
    {
        $settings = self::settings();
        return !empty($settings['active']) && !empty($settings['otp_active']);
    }

    private static function otpHash(string $challengeId, string $userId, string $phone, string $code): string
    {
        return hash_hmac(
            'sha256',
            $challengeId . ':' . $userId . ':' . $phone . ':' . $code,
            self::encryptionKey()
        );
    }

    public static function requestPhoneOtp(string $userId, string $phone, bool $resend = false): array
    {
        global $pdo;

        if (!self::phoneOtpEnabled()) {
            throw new RuntimeException('تأیید پیامکی شماره در پنل مدیریت فعال نیست.');
        }

        $phone = self::normalizePhone($phone);
        if ($phone === '') {
            throw new RuntimeException('شماره موبایل معتبر نیست.');
        }

        $settings = self::settings();
        $ttl = max(60, min(600, (int) ($settings['otp_ttl_seconds'] ?? 120)));
        $resendDelay = max(30, min(600, (int) ($settings['otp_resend_seconds'] ?? 60)));
        $maxAttempts = max(3, min(10, (int) ($settings['otp_max_attempts'] ?? 5)));

        $stmt = $pdo->prepare(
            'SELECT * FROM sms_otp_challenges
             WHERE user_id=? AND consumed_at IS NULL
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($latest && (int) ($latest['resend_at'] ?? 0) > time()) {
            $wait = max(1, (int) $latest['resend_at'] - time());
            throw new RuntimeException($wait . ' ثانیه تا ارسال دوباره کد صبر کنید.');
        }

        if ($latest) {
            $pdo->prepare('UPDATE sms_otp_challenges SET consumed_at=? WHERE id=?')
                ->execute([time(), $latest['id']]);
        }

        $challengeId = self::uuid4();
        $code = (string) random_int(100000, 999999);
        $now = time();

        $insert = $pdo->prepare(
            'INSERT INTO sms_otp_challenges
             (id,user_id,phone,code_hash,attempts,max_attempts,expires_at,resend_at,consumed_at,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $challengeId,
            $userId,
            $phone,
            self::otpHash($challengeId, $userId, $phone, $code),
            0,
            $maxAttempts,
            $now + $ttl,
            $now + $resendDelay,
            null,
            $now,
        ]);

        try {
            self::sendTemplateNow('phone_verification', $phone, ['code' => $code]);
        } catch (Throwable $e) {
            $pdo->prepare('DELETE FROM sms_otp_challenges WHERE id=?')->execute([$challengeId]);
            throw $e;
        }

        return [
            'ok' => true,
            'challenge_id' => $challengeId,
            'phone' => $phone,
            'expires_in' => $ttl,
            'resend_after' => $resendDelay,
        ];
    }

    public static function resendPhoneOtp(string $userId): array
    {
        global $pdo;

        $stmt = $pdo->prepare(
            'SELECT phone FROM sms_otp_challenges
             WHERE user_id=? AND consumed_at IS NULL
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $phone = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($phone === '') {
            throw new RuntimeException('درخواست فعال تأیید شماره پیدا نشد؛ شماره را دوباره ارسال کنید.');
        }

        return self::requestPhoneOtp($userId, $phone, true);
    }

    public static function verifyPhoneOtp(string $userId, string $code): array
    {
        global $pdo;

        $code = strtr(trim($code), '۰۱۲۳۴۵۶۷۸۹', '0123456789');
        $code = preg_replace('/\D+/', '', $code) ?: '';
        if (strlen($code) !== 6) {
            throw new RuntimeException('کد تأیید باید دقیقاً ۶ رقم باشد.');
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM sms_otp_challenges
             WHERE user_id=? AND consumed_at IS NULL
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$challenge) {
            throw new RuntimeException('درخواست تأیید شماره پیدا نشد؛ شماره را دوباره ارسال کنید.');
        }

        if ((int) $challenge['expires_at'] <= time()) {
            $pdo->prepare('UPDATE sms_otp_challenges SET consumed_at=? WHERE id=?')
                ->execute([time(), $challenge['id']]);
            throw new RuntimeException('مهلت کد تأیید تمام شده است؛ کد جدید بگیرید.');
        }

        $attempts = (int) $challenge['attempts'] + 1;
        $maxAttempts = max(1, (int) $challenge['max_attempts']);
        $expected = self::otpHash(
            (string) $challenge['id'],
            $userId,
            (string) $challenge['phone'],
            $code
        );

        if (!hash_equals((string) $challenge['code_hash'], $expected)) {
            $consumed = $attempts >= $maxAttempts ? time() : null;
            $pdo->prepare('UPDATE sms_otp_challenges SET attempts=?,consumed_at=? WHERE id=?')
                ->execute([$attempts, $consumed, $challenge['id']]);
            $remaining = max(0, $maxAttempts - $attempts);
            throw new RuntimeException(
                $remaining > 0
                    ? 'کد تأیید نادرست است؛ ' . $remaining . ' تلاش باقی مانده.'
                    : 'تعداد تلاش‌های ناموفق تمام شد؛ کد جدید بگیرید.'
            );
        }

        $pdo->prepare('UPDATE sms_otp_challenges SET attempts=?,consumed_at=? WHERE id=?')
            ->execute([$attempts, time(), $challenge['id']]);

        return [
            'ok' => true,
            'phone' => (string) $challenge['phone'],
        ];
    }

    public static function stats(): array
    {
        global $pdo;
        $out = ['pending'=>0,'retry'=>0,'sending'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
        try {
            foreach (array_keys($out) as $status) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM sms_deliveries WHERE status=?');
                $stmt->execute([$status]);
                $out[$status] = (int) $stmt->fetchColumn();
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    public static function recent(int $limit = 100): array
    {
        global $pdo;
        $limit = max(1, min(200, $limit));
        try {
            return $pdo->query(
                "SELECT id,event_key,user_id,invoice_id,order_id,phone,status,attempts,max_attempts,provider_message_id,last_error,sent_at,created_at
                 FROM sms_deliveries ORDER BY created_at DESC LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function providerId(array $response): string
    {
        foreach (['message_id','messageId','id','uid'] as $key) {
            if (isset($response[$key]) && is_scalar($response[$key])) {
                return mb_substr((string) $response[$key], 0, 180);
            }
        }
        if (isset($response['data']) && is_array($response['data'])) {
            foreach (['message_id','messageId','id','uid'] as $key) {
                if (isset($response['data'][$key]) && is_scalar($response['data'][$key])) {
                    return mb_substr((string) $response['data'][$key], 0, 180);
                }
            }
        }
        if (array_key_exists('data', $response) && is_scalar($response['data'])) {
            return mb_substr((string) $response['data'], 0, 180);
        }
        return '';
    }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function writeJsonFile(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function log(string $message, array $context = []): void
    {
        if (function_exists('bluebotLog')) {
            bluebotLog('warning', $message, $context);
            return;
        }
        error_log($message . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : ''));
    }
}
