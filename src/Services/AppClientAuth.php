<?php

declare(strict_types=1);

final class AppClientAuthRateLimitException extends RuntimeException {}

final class AppClientAuth
{
    private const SESSION_TTL = 2592000;
    private const ELIGIBLE_STATUSES = ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'];

    private static bool $ready = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$ready) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_client_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id VARCHAR(64) NOT NULL,
            invoice_id VARCHAR(128) NULL,
            username VARCHAR(64) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            password_secret TEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_app_client_service (user_id, invoice_id),
            UNIQUE KEY uq_app_client_username (username),
            KEY idx_app_client_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::migrateServiceScope($pdo);
        self::migrateCredentialSecret($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_client_qr_links (
            fingerprint CHAR(64) NOT NULL,
            user_id VARCHAR(64) NOT NULL,
            invoice_id VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (fingerprint),
            KEY idx_app_client_qr_service (user_id, invoice_id),
            KEY idx_app_client_qr_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_client_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            device_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_app_client_token (token_hash),
            KEY idx_app_client_account (account_id),
            KEY idx_app_client_expiry (expires_at),
            CONSTRAINT fk_app_client_account FOREIGN KEY (account_id) REFERENCES app_client_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS app_client_login_guards (
            identifier_hash CHAR(64) NOT NULL PRIMARY KEY,
            failures INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            blocked_until DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::$ready = true;
    }

    /**
     * Returns services that can have their own Android credentials.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function servicesForUser(PDO $pdo, string $userId, int $limit = 30): array
    {
        self::ensureSchema($pdo);
        $limit = max(1, min(100, $limit));
        $placeholders = implode(',', array_fill(0, count(self::ELIGIBLE_STATUSES), '?'));

        $stmt = $pdo->prepare(
            "SELECT id_invoice, id_user, username, name_product, note, Service_location, status, time_sell
             FROM invoice
             WHERE id_user = ?
               AND status IN ({$placeholders})
             ORDER BY time_sell DESC
             LIMIT {$limit}"
        );
        $stmt->execute(array_merge([$userId], self::ELIGIBLE_STATUSES));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public static function serviceForUser(PDO $pdo, string $userId, string $invoiceId): ?array
    {
        self::ensureSchema($pdo);
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count(self::ELIGIBLE_STATUSES), '?'));
        $stmt = $pdo->prepare(
            "SELECT *
             FROM invoice
             WHERE id_invoice = ?
               AND id_user = ?
               AND status IN ({$placeholders})
             LIMIT 1"
        );
        $stmt->execute(array_merge([$invoiceId, $userId], self::ELIGIBLE_STATUSES));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function accountForService(PDO $pdo, string $userId, string $invoiceId): ?array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare(
            "SELECT id,user_id,invoice_id,username,password_secret,enabled,created_at,updated_at,last_login_at
             FROM app_client_accounts
             WHERE user_id = ? AND invoice_id = ?
             LIMIT 1"
        );
        $stmt->execute([$userId, $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function credentialsForService(PDO $pdo, string $userId, string $invoiceId): array
    {
        self::ensureSchema($pdo);

        $service = self::serviceForUser($pdo, $userId, $invoiceId);
        if (!is_array($service)) {
            throw new InvalidArgumentException('Service not found');
        }

        $account = self::accountForService($pdo, $userId, $invoiceId);
        if (is_array($account) && (int) ($account['enabled'] ?? 0) === 1) {
            $password = self::decryptPassword((string) ($account['password_secret'] ?? ''));
            if ($password !== '') {
                return [
                    'user_id' => $userId,
                    'invoice_id' => $invoiceId,
                    'username' => (string) $account['username'],
                    'password' => $password,
                    'service' => $service,
                ];
            }
        }

        // Existing legacy accounts only stored a one-way password hash. Generate
        // one recoverable password once without revoking already-issued sessions.
        return self::writeCredentials($pdo, $userId, $invoiceId, $service, false);
    }

    public static function createCredentials(PDO $pdo, string $userId, string $invoiceId): array
    {
        self::ensureSchema($pdo);

        $service = self::serviceForUser($pdo, $userId, $invoiceId);
        if (!is_array($service)) {
            throw new InvalidArgumentException('Service not found');
        }

        return self::writeCredentials($pdo, $userId, $invoiceId, $service, true);
    }

    /**
     * Registers the exact service QR payloads without storing the raw links.
     *
     * @param array<int,string>|string $payloads
     */
    public static function replaceQrPayloads(
        PDO $pdo,
        string $userId,
        string $invoiceId,
        array|string $payloads
    ): void {
        self::ensureSchema($pdo);

        $service = self::serviceForUser($pdo, $userId, $invoiceId);
        if (!is_array($service)) {
            throw new InvalidArgumentException('Service not found');
        }

        $pdo->prepare(
            "DELETE FROM app_client_qr_links WHERE user_id = ? AND invoice_id = ?"
        )->execute([$userId, $invoiceId]);

        self::rememberQrPayloads($pdo, $userId, $invoiceId, $payloads);
    }

    public static function authenticateQr(
        PDO $pdo,
        string $payload,
        string $deviceId,
        string $ip
    ): array {
        self::ensureSchema($pdo);
        self::validateDevice($deviceId);

        $fingerprints = self::qrFingerprints($payload);
        if ($fingerprints === []) {
            throw new InvalidArgumentException('Unsupported QR code');
        }

        $guard = hash('sha256', 'qr|' . trim($ip) . '|' . $fingerprints[0]);
        self::assertAllowed($pdo, $guard);

        $placeholders = implode(',', array_fill(0, count($fingerprints), '?'));
        $stmt = $pdo->prepare(
            "SELECT user_id,invoice_id
             FROM app_client_qr_links
             WHERE fingerprint IN ({$placeholders})
             LIMIT 1"
        );
        $stmt->execute($fingerprints);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        $resolvedFromInvoiceCache = false;

        if (!is_array($link)) {
            $link = self::resolveQrLinkFromInvoiceCache($pdo, $payload, $fingerprints);
            $resolvedFromInvoiceCache = is_array($link);
        }

        if (!is_array($link)) {
            self::failed($pdo, $guard);
            throw new RuntimeException('QR code is not linked to an active service');
        }

        $userId = (string) $link['user_id'];
        $invoiceId = (string) $link['invoice_id'];
        $user = self::user($pdo, $userId);
        $service = self::serviceForUser($pdo, $userId, $invoiceId);

        if (
            !is_array($user)
            || (string) ($user['User_Status'] ?? '') === 'block'
            || !is_array($service)
        ) {
            self::failed($pdo, $guard);
            throw new RuntimeException('QR code is not linked to an active service');
        }

        if ($resolvedFromInvoiceCache) {
            self::rememberQrPayloads(
                $pdo,
                $userId,
                $invoiceId,
                [
                    $payload,
                    (string) ($service['user_info'] ?? ''),
                ]
            );
        }

        // Ensure an app account exists even if the user never used /app.
        self::credentialsForService($pdo, $userId, $invoiceId);
        $account = self::accountForService($pdo, $userId, $invoiceId);
        if (!is_array($account) || (int) ($account['enabled'] ?? 0) !== 1) {
            self::failed($pdo, $guard);
            throw new RuntimeException('QR account is unavailable');
        }

        $pdo->prepare("DELETE FROM app_client_login_guards WHERE identifier_hash = ?")
            ->execute([$guard]);

        return self::issueSession($pdo, $account, $user, $service, $deviceId);
    }

    public static function authenticate(
        PDO $pdo,
        string $username,
        string $password,
        string $deviceId,
        string $ip
    ): array {
        self::ensureSchema($pdo);

        $username = strtolower(trim($username));
        self::validateDevice($deviceId);
        $guard = hash('sha256', $username . '|' . trim($ip));
        self::assertAllowed($pdo, $guard);

        $stmt = $pdo->prepare(
            "SELECT id,user_id,invoice_id,username,password_hash,enabled
             FROM app_client_accounts
             WHERE username = ?
             LIMIT 1"
        );
        $stmt->execute([$username]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($account)
            || (int) $account['enabled'] !== 1
            || trim((string) ($account['invoice_id'] ?? '')) === ''
            || !password_verify($password, (string) $account['password_hash'])
        ) {
            self::failed($pdo, $guard);
            throw new RuntimeException('Invalid credentials');
        }

        $user = self::user($pdo, (string) $account['user_id']);
        $service = self::serviceForUser(
            $pdo,
            (string) $account['user_id'],
            (string) $account['invoice_id']
        );

        if (
            !is_array($user)
            || (string) ($user['User_Status'] ?? '') === 'block'
            || !is_array($service)
        ) {
            self::failed($pdo, $guard);
            throw new RuntimeException('Invalid credentials');
        }

        $pdo->prepare("DELETE FROM app_client_login_guards WHERE identifier_hash = ?")
            ->execute([$guard]);

        return self::issueSession($pdo, $account, $user, $service, $deviceId);
    }

    public static function authorize(PDO $pdo, string $token, string $deviceId): array
    {
        self::ensureSchema($pdo);
        self::validateDevice($deviceId);

        if (trim($token) === '') {
            throw new RuntimeException('Authentication required');
        }

        $stmt = $pdo->prepare(
            "SELECT
                s.id AS session_id,
                s.account_id,
                s.device_hash,
                a.user_id,
                a.invoice_id,
                a.username,
                a.enabled
             FROM app_client_sessions s
             JOIN app_client_accounts a ON a.id = s.account_id
             WHERE s.token_hash = ?
               AND s.revoked_at IS NULL
               AND s.expires_at > UTC_TIMESTAMP()
             LIMIT 1"
        );
        $stmt->execute([hash('sha256', trim($token))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !is_array($row)
            || (int) $row['enabled'] !== 1
            || trim((string) ($row['invoice_id'] ?? '')) === ''
            || !hash_equals(
                (string) $row['device_hash'],
                hash('sha256', trim($deviceId))
            )
        ) {
            throw new RuntimeException('Authentication required');
        }

        $user = self::user($pdo, (string) $row['user_id']);
        $service = self::serviceForUser(
            $pdo,
            (string) $row['user_id'],
            (string) $row['invoice_id']
        );

        if (
            !is_array($user)
            || (string) ($user['User_Status'] ?? '') === 'block'
            || !is_array($service)
        ) {
            throw new RuntimeException('Authentication required');
        }

        $pdo->prepare("UPDATE app_client_sessions SET last_seen_at = ? WHERE id = ?")
            ->execute([self::now(), (int) $row['session_id']]);

        return [
            'session_id' => (int) $row['session_id'],
            'account_id' => (int) $row['account_id'],
            'username' => (string) $row['username'],
            'user_id' => (string) $row['user_id'],
            'invoice_id' => (string) $row['invoice_id'],
            'service' => $service,
            'user' => $user,
        ];
    }

    public static function logout(PDO $pdo, string $token): void
    {
        self::ensureSchema($pdo);
        $pdo->prepare(
            "UPDATE app_client_sessions
             SET revoked_at = ?
             WHERE token_hash = ? AND revoked_at IS NULL"
        )->execute([self::now(), hash('sha256', trim($token))]);
    }

    private static function migrateCredentialSecret(PDO $pdo): void
    {
        if (!self::hasColumn($pdo, 'app_client_accounts', 'password_secret')) {
            $pdo->exec(
                "ALTER TABLE app_client_accounts
                 ADD COLUMN password_secret TEXT NULL AFTER password_hash"
            );
        }
    }

    private static function writeCredentials(
        PDO $pdo,
        string $userId,
        string $invoiceId,
        array $service,
        bool $revokeSessions
    ): array {
        $account = self::accountForService($pdo, $userId, $invoiceId);
        $username = is_array($account)
            ? (string) $account['username']
            : self::uniqueUsername($pdo);
        $password = self::random(15);
        $hash = password_hash(
            $password,
            defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT
        );
        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed');
        }

        $secret = self::encryptPassword($password);
        $now = self::now();

        if (is_array($account)) {
            $pdo->prepare(
                "UPDATE app_client_accounts
                 SET password_hash = ?, password_secret = ?, enabled = 1, updated_at = ?
                 WHERE id = ?"
            )->execute([$hash, $secret, $now, (int) $account['id']]);

            if ($revokeSessions) {
                self::revokeAccount($pdo, (int) $account['id']);
            }
        } else {
            $pdo->prepare(
                "INSERT INTO app_client_accounts
                    (user_id,invoice_id,username,password_hash,password_secret,enabled,created_at,updated_at)
                 VALUES (?,?,?,?,?,1,?,?)"
            )->execute([$userId, $invoiceId, $username, $hash, $secret, $now, $now]);
        }

        return [
            'user_id' => $userId,
            'invoice_id' => $invoiceId,
            'username' => $username,
            'password' => $password,
            'service' => $service,
        ];
    }

    private static function issueSession(
        PDO $pdo,
        array $account,
        array $user,
        array $service,
        string $deviceId
    ): array {
        $token = self::random(32);
        $now = self::now();
        $deviceHash = hash('sha256', trim($deviceId));
        $accountId = (int) $account['id'];

        $pdo->prepare(
            "UPDATE app_client_sessions
             SET revoked_at = ?
             WHERE account_id = ? AND device_hash = ? AND revoked_at IS NULL"
        )->execute([$now, $accountId, $deviceHash]);

        $expires = gmdate('Y-m-d H:i:s', time() + self::SESSION_TTL);
        $pdo->prepare(
            "INSERT INTO app_client_sessions
                (account_id,token_hash,device_hash,created_at,expires_at,last_seen_at)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            $accountId,
            hash('sha256', $token),
            $deviceHash,
            $now,
            $expires,
            $now,
        ]);

        $pdo->prepare("UPDATE app_client_accounts SET last_login_at = ? WHERE id = ?")
            ->execute([$now, $accountId]);

        return [
            'token' => $token,
            'expires_at' => $expires,
            'expires_in' => self::SESSION_TTL,
            'account' => [
                'username' => (string) $account['username'],
                'user_id' => (string) $account['user_id'],
                'invoice_id' => (string) $account['invoice_id'],
            ],
            'service' => $service,
            'user' => $user,
        ];
    }

    private static function encryptPassword(string $password): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required for credential storage');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $password,
            'aes-256-gcm',
            self::credentialKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'bluebot-app-credential-v1',
            16
        );

        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Credential encryption failed');
        }

        return 'v1.'
            . self::base64UrlEncode($iv) . '.'
            . self::base64UrlEncode($tag) . '.'
            . self::base64UrlEncode($ciphertext);
    }

    private static function decryptPassword(string $secret): string
    {
        $parts = explode('.', trim($secret));
        if (count($parts) !== 4 || $parts[0] !== 'v1' || !function_exists('openssl_decrypt')) {
            return '';
        }

        $iv = self::base64UrlDecode($parts[1]);
        $tag = self::base64UrlDecode($parts[2]);
        $ciphertext = self::base64UrlDecode($parts[3]);
        if ($iv === null || $tag === null || $ciphertext === null) {
            return '';
        }

        $plain = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::credentialKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'bluebot-app-credential-v1'
        );

        return is_string($plain) ? $plain : '';
    }

    private static function credentialKey(): string
    {
        $env = getenv('BLUEBOT_APP_CREDENTIAL_KEY');
        if (is_string($env) && strlen(trim($env)) >= 32) {
            return hash('sha256', trim($env), true);
        }

        $parts = [
            (string) ($GLOBALS['APIKEY'] ?? ''),
            (string) ($GLOBALS['passworddb'] ?? ''),
            (string) ($GLOBALS['dbname'] ?? ''),
            (string) ($GLOBALS['domainhosts'] ?? ''),
        ];
        $material = implode('|', $parts);
        if (strlen(str_replace('|', '', $material)) < 24) {
            throw new RuntimeException('Credential encryption key is unavailable');
        }

        return hash('sha256', 'bluebot-app-credential-v1|' . $material, true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @param array<int,string>|string $payloads
     */
    private static function rememberQrPayloads(
        PDO $pdo,
        string $userId,
        string $invoiceId,
        array|string $payloads
    ): void {
        $values = is_array($payloads) ? $payloads : [$payloads];
        $fingerprints = [];
        foreach ($values as $payload) {
            foreach (self::qrFingerprints((string) $payload) as $fingerprint) {
                $fingerprints[$fingerprint] = true;
            }
        }

        if ($fingerprints === []) {
            return;
        }

        $now = self::now();
        $stmt = $pdo->prepare(
            "INSERT INTO app_client_qr_links
                (fingerprint,user_id,invoice_id,created_at,updated_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                invoice_id = VALUES(invoice_id),
                updated_at = VALUES(updated_at)"
        );

        foreach (array_keys($fingerprints) as $fingerprint) {
            $stmt->execute([$fingerprint, $userId, $invoiceId, $now, $now]);
        }
    }

    /**
     * Backfills QR ownership for services created before QR fingerprint syncing
     * existed. Only active invoices whose cached user_info contains the exact
     * scanned subscription/config value are eligible, and ambiguous matches are
     * rejected.
     *
     * @param array<int,string> $fingerprints
     * @return array{user_id:string,invoice_id:string}|null
     */
    private static function resolveQrLinkFromInvoiceCache(
        PDO $pdo,
        string $payload,
        array $fingerprints
    ): ?array {
        if (!self::hasColumn($pdo, 'invoice', 'user_info')) {
            return null;
        }

        $needles = [];
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($payload));
        foreach (array_merge([$normalized], preg_split('/\n+/', $normalized) ?: []) as $value) {
            $value = trim((string) $value);
            if (
                $value !== ''
                && strlen($value) <= 8192
                && self::isSupportedQrValue($value)
            ) {
                $needles[$value] = true;
            }
        }

        $needles = array_slice(array_keys($needles), 0, 8);
        if ($needles === []) {
            return null;
        }

        $statusPlaceholders = implode(',', array_fill(0, count(self::ELIGIBLE_STATUSES), '?'));
        $contains = implode(
            ' OR ',
            array_fill(0, count($needles), 'LOCATE(?, user_info) > 0')
        );

        $stmt = $pdo->prepare(
            "SELECT id_invoice,id_user,user_info
             FROM invoice
             WHERE status IN ({$statusPlaceholders})
               AND user_info IS NOT NULL
               AND user_info <> ''
               AND ({$contains})
             LIMIT 3"
        );
        $stmt->execute(array_merge(self::ELIGIBLE_STATUSES, $needles));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matches = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $cachedFingerprints = self::qrFingerprints((string) ($row['user_info'] ?? ''));
            if (array_intersect($fingerprints, $cachedFingerprints) === []) {
                continue;
            }

            $userId = trim((string) ($row['id_user'] ?? ''));
            $invoiceId = trim((string) ($row['id_invoice'] ?? ''));
            if ($userId === '' || $invoiceId === '') {
                continue;
            }

            $matches[$userId . "\0" . $invoiceId] = [
                'user_id' => $userId,
                'invoice_id' => $invoiceId,
            ];
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    /**
     * @return array<int,string>
     */
    private static function qrFingerprints(string $payload): array
    {
        $payload = str_replace(["\r\n", "\r"], "\n", trim($payload));
        if ($payload === '' || strlen($payload) > 65535) {
            return [];
        }

        $values = [$payload];
        foreach (preg_split('/\n+/', $payload) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $values[] = $line;
            }
        }

        $fingerprints = [];
        foreach (array_unique($values) as $value) {
            if (!self::isSupportedQrValue($value)) {
                continue;
            }
            $fingerprints[] = hash('sha256', $value);
        }

        return array_values(array_unique($fingerprints));
    }

    private static function isSupportedQrValue(string $value): bool
    {
        if (preg_match('/^(vless|vmess|trojan|ss|socks|hysteria2|hy2):\/\//i', $value)) {
            return true;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    private static function migrateServiceScope(PDO $pdo): void
    {
        if (!self::hasColumn($pdo, 'app_client_accounts', 'invoice_id')) {
            $pdo->exec(
                "ALTER TABLE app_client_accounts
                 ADD COLUMN invoice_id VARCHAR(128) NULL AFTER user_id"
            );
        }

        if (self::hasIndex($pdo, 'app_client_accounts', 'uq_app_client_user')) {
            $pdo->exec("ALTER TABLE app_client_accounts DROP INDEX uq_app_client_user");
        }

        if (!self::hasIndex($pdo, 'app_client_accounts', 'uq_app_client_service')) {
            $pdo->exec(
                "ALTER TABLE app_client_accounts
                 ADD UNIQUE KEY uq_app_client_service (user_id, invoice_id)"
            );
        }

        if (!self::hasIndex($pdo, 'app_client_accounts', 'idx_app_client_invoice')) {
            $pdo->exec(
                "ALTER TABLE app_client_accounts
                 ADD KEY idx_app_client_invoice (invoice_id)"
            );
        }

        // Legacy user-wide accounts are intentionally disabled. They must be
        // replaced by service-scoped credentials from /app.
        $stmt = $pdo->prepare(
            "UPDATE app_client_accounts
             SET enabled = 0, updated_at = ?
             WHERE enabled = 1
               AND (invoice_id IS NULL OR invoice_id = '')"
        );
        $stmt->execute([self::now()]);
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $quoted = $pdo->quote($column);
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$quoted}");
        return $stmt !== false && (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function hasIndex(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->query("SHOW INDEX FROM `{$table}`");
        if ($stmt === false) {
            return false;
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ((string) ($row['Key_name'] ?? '') === $index) {
                return true;
            }
        }
        return false;
    }

    private static function user(PDO $pdo, string $id): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM user WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function validateDevice(string $id): void
    {
        $length = strlen(trim($id));
        if ($length < 16 || $length > 160) {
            throw new InvalidArgumentException('Invalid device id');
        }
    }

    private static function revokeAccount(PDO $pdo, int $id): void
    {
        $pdo->prepare(
            "UPDATE app_client_sessions
             SET revoked_at = ?
             WHERE account_id = ? AND revoked_at IS NULL"
        )->execute([self::now(), $id]);
    }

    private static function uniqueUsername(PDO $pdo): string
    {
        for ($i = 0; $i < 12; $i++) {
            $username = 'bp-' . bin2hex(random_bytes(5));
            $stmt = $pdo->prepare(
                "SELECT 1 FROM app_client_accounts WHERE username = ? LIMIT 1"
            );
            $stmt->execute([$username]);
            if (!$stmt->fetchColumn()) {
                return $username;
            }
        }
        throw new RuntimeException('Unable to allocate username');
    }

    private static function assertAllowed(PDO $pdo, string $key): void
    {
        $stmt = $pdo->prepare(
            "SELECT blocked_until
             FROM app_client_login_guards
             WHERE identifier_hash = ?
             LIMIT 1"
        );
        $stmt->execute([$key]);
        $until = $stmt->fetchColumn();

        if (
            $until
            && ($timestamp = strtotime((string) $until . ' UTC')) !== false
            && $timestamp > time()
        ) {
            throw new AppClientAuthRateLimitException('Too many attempts');
        }
    }

    private static function failed(PDO $pdo, string $key): void
    {
        $stmt = $pdo->prepare(
            "SELECT failures,window_started_at
             FROM app_client_login_guards
             WHERE identifier_hash = ?
             LIMIT 1"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        $failures = 1;
        $windowStart = self::now();

        if (
            is_array($row)
            && ($timestamp = strtotime((string) $row['window_started_at'] . ' UTC')) !== false
            && $now - $timestamp <= 900
        ) {
            $failures = (int) $row['failures'] + 1;
            $windowStart = (string) $row['window_started_at'];
        }

        $blockedUntil = $failures >= 5
            ? gmdate('Y-m-d H:i:s', $now + 900)
            : null;

        $pdo->prepare(
            "INSERT INTO app_client_login_guards
                (identifier_hash,failures,window_started_at,blocked_until,updated_at)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                failures = VALUES(failures),
                window_started_at = VALUES(window_started_at),
                blocked_until = VALUES(blocked_until),
                updated_at = VALUES(updated_at)"
        )->execute([
            $key,
            $failures,
            $windowStart,
            $blockedUntil,
            self::now(),
        ]);
    }
}
