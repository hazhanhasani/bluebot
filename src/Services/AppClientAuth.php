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
            "SELECT id,user_id,invoice_id,username,enabled,created_at,updated_at,last_login_at
             FROM app_client_accounts
             WHERE user_id = ? AND invoice_id = ?
             LIMIT 1"
        );
        $stmt->execute([$userId, $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public static function createCredentials(PDO $pdo, string $userId, string $invoiceId): array
    {
        self::ensureSchema($pdo);

        $service = self::serviceForUser($pdo, $userId, $invoiceId);
        if (!is_array($service)) {
            throw new InvalidArgumentException('Service not found');
        }

        $account = self::accountForService($pdo, $userId, $invoiceId);
        $username = is_array($account) ? (string) $account['username'] : self::uniqueUsername($pdo);
        $password = self::random(15);
        $hash = password_hash(
            $password,
            defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT
        );
        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed');
        }

        $now = self::now();
        if (is_array($account)) {
            $pdo->prepare(
                "UPDATE app_client_accounts
                 SET password_hash = ?, enabled = 1, updated_at = ?
                 WHERE id = ?"
            )->execute([$hash, $now, (int) $account['id']]);
            self::revokeAccount($pdo, (int) $account['id']);
        } else {
            $pdo->prepare(
                "INSERT INTO app_client_accounts
                    (user_id, invoice_id, username, password_hash, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?)"
            )->execute([$userId, $invoiceId, $username, $hash, $now, $now]);
        }

        return [
            'user_id' => $userId,
            'invoice_id' => $invoiceId,
            'username' => $username,
            'password' => $password,
            'service' => $service,
        ];
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
