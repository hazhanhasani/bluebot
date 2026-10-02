<?php

declare(strict_types=1);

final class AppClientAuthRateLimitException extends RuntimeException {}

final class AppClientAuth
{
    private const SESSION_TTL = 2592000;
    private static bool $ready = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$ready) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_client_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id VARCHAR(64) NOT NULL,
            username VARCHAR(64) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_app_client_user (user_id), UNIQUE KEY uq_app_client_username (username)
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
            PRIMARY KEY (id), UNIQUE KEY uq_app_client_token (token_hash),
            KEY idx_app_client_account (account_id), KEY idx_app_client_expiry (expires_at),
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

    public static function accountForUser(PDO $pdo, string $userId): ?array
    {
        self::ensureSchema($pdo);
        $s = $pdo->prepare("SELECT id,user_id,username,enabled,created_at,updated_at,last_login_at FROM app_client_accounts WHERE user_id=? LIMIT 1");
        $s->execute([$userId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    public static function createCredentials(PDO $pdo, string $userId): array
    {
        self::ensureSchema($pdo);
        $account = self::accountForUser($pdo, $userId);
        $username = $account['username'] ?? self::uniqueUsername($pdo);
        $password = self::random(15);
        $hash = password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
        if (!is_string($hash)) throw new RuntimeException('Password hashing failed');
        $now = self::now();
        if ($account) {
            $pdo->prepare("UPDATE app_client_accounts SET password_hash=?,enabled=1,updated_at=? WHERE id=?")
                ->execute([$hash, $now, (int)$account['id']]);
            self::revokeAccount($pdo, (int)$account['id']);
        } else {
            $pdo->prepare("INSERT INTO app_client_accounts (user_id,username,password_hash,enabled,created_at,updated_at) VALUES (?,?,?,1,?,?)")
                ->execute([$userId, $username, $hash, $now, $now]);
        }
        return ['user_id'=>$userId,'username'=>(string)$username,'password'=>$password];
    }

    public static function authenticate(PDO $pdo, string $username, string $password, string $deviceId, string $ip): array
    {
        self::ensureSchema($pdo);
        $username = strtolower(trim($username));
        self::validateDevice($deviceId);
        $guard = hash('sha256', $username . '|' . trim($ip));
        self::assertAllowed($pdo, $guard);
        $s = $pdo->prepare("SELECT id,user_id,username,password_hash,enabled FROM app_client_accounts WHERE username=? LIMIT 1");
        $s->execute([$username]);
        $a = $s->fetch(PDO::FETCH_ASSOC);
        if (!$a || (int)$a['enabled'] !== 1 || !password_verify($password, (string)$a['password_hash'])) {
            self::failed($pdo, $guard);
            throw new RuntimeException('Invalid credentials');
        }
        $user = self::user($pdo, (string)$a['user_id']);
        if (!$user || (string)($user['User_Status'] ?? '') === 'block') {
            self::failed($pdo, $guard);
            throw new RuntimeException('Invalid credentials');
        }
        $pdo->prepare("DELETE FROM app_client_login_guards WHERE identifier_hash=?")->execute([$guard]);
        $token = self::random(32); $now = self::now(); $deviceHash = hash('sha256', trim($deviceId));
        $pdo->prepare("UPDATE app_client_sessions SET revoked_at=? WHERE account_id=? AND device_hash=? AND revoked_at IS NULL")
            ->execute([$now, (int)$a['id'], $deviceHash]);
        $expires = gmdate('Y-m-d H:i:s', time() + self::SESSION_TTL);
        $pdo->prepare("INSERT INTO app_client_sessions (account_id,token_hash,device_hash,created_at,expires_at,last_seen_at) VALUES (?,?,?,?,?,?)")
            ->execute([(int)$a['id'], hash('sha256',$token), $deviceHash, $now, $expires, $now]);
        $pdo->prepare("UPDATE app_client_accounts SET last_login_at=? WHERE id=?")->execute([$now,(int)$a['id']]);
        return ['token'=>$token,'expires_at'=>$expires,'expires_in'=>self::SESSION_TTL,'account'=>['username'=>$a['username'],'user_id'=>$a['user_id']],'user'=>$user];
    }

    public static function authorize(PDO $pdo, string $token, string $deviceId): array
    {
        self::ensureSchema($pdo); self::validateDevice($deviceId);
        if (trim($token) === '') throw new RuntimeException('Authentication required');
        $s = $pdo->prepare("SELECT s.id session_id,s.account_id,s.device_hash,a.user_id,a.username,a.enabled FROM app_client_sessions s JOIN app_client_accounts a ON a.id=s.account_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP() LIMIT 1");
        $s->execute([hash('sha256',trim($token))]); $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['enabled'] !== 1 || !hash_equals((string)$r['device_hash'], hash('sha256',trim($deviceId)))) throw new RuntimeException('Authentication required');
        $user = self::user($pdo,(string)$r['user_id']);
        if (!$user || (string)($user['User_Status'] ?? '') === 'block') throw new RuntimeException('Authentication required');
        $pdo->prepare("UPDATE app_client_sessions SET last_seen_at=? WHERE id=?")->execute([self::now(),(int)$r['session_id']]);
        return ['session_id'=>(int)$r['session_id'],'account_id'=>(int)$r['account_id'],'username'=>(string)$r['username'],'user_id'=>(string)$r['user_id'],'user'=>$user];
    }

    public static function logout(PDO $pdo, string $token): void
    {
        self::ensureSchema($pdo);
        $pdo->prepare("UPDATE app_client_sessions SET revoked_at=? WHERE token_hash=? AND revoked_at IS NULL")
            ->execute([self::now(), hash('sha256',trim($token))]);
    }

    private static function user(PDO $pdo, string $id): ?array { $s=$pdo->prepare("SELECT * FROM user WHERE id=? LIMIT 1"); $s->execute([$id]); $r=$s->fetch(PDO::FETCH_ASSOC); return is_array($r)?$r:null; }
    private static function random(int $bytes): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function validateDevice(string $id): void { $n=strlen(trim($id)); if ($n<16 || $n>160) throw new InvalidArgumentException('Invalid device id'); }
    private static function revokeAccount(PDO $pdo, int $id): void { $pdo->prepare("UPDATE app_client_sessions SET revoked_at=? WHERE account_id=? AND revoked_at IS NULL")->execute([self::now(),$id]); }

    private static function uniqueUsername(PDO $pdo): string
    {
        for ($i=0;$i<12;$i++) { $u='bp-'.bin2hex(random_bytes(5)); $s=$pdo->prepare("SELECT 1 FROM app_client_accounts WHERE username=? LIMIT 1"); $s->execute([$u]); if (!$s->fetchColumn()) return $u; }
        throw new RuntimeException('Unable to allocate username');
    }

    private static function assertAllowed(PDO $pdo, string $key): void
    {
        $s=$pdo->prepare("SELECT blocked_until FROM app_client_login_guards WHERE identifier_hash=? LIMIT 1"); $s->execute([$key]); $until=$s->fetchColumn();
        if ($until && ($ts=strtotime((string)$until.' UTC')) !== false && $ts>time()) throw new AppClientAuthRateLimitException('Too many attempts');
    }

    private static function failed(PDO $pdo, string $key): void
    {
        $s=$pdo->prepare("SELECT failures,window_started_at FROM app_client_login_guards WHERE identifier_hash=? LIMIT 1"); $s->execute([$key]); $r=$s->fetch(PDO::FETCH_ASSOC); $now=time(); $fail=1; $start=self::now();
        if ($r && ($ts=strtotime((string)$r['window_started_at'].' UTC')) !== false && $now-$ts<=900) { $fail=(int)$r['failures']+1; $start=(string)$r['window_started_at']; }
        $blocked=$fail>=5 ? gmdate('Y-m-d H:i:s',$now+900) : null;
        $pdo->prepare("INSERT INTO app_client_login_guards (identifier_hash,failures,window_started_at,blocked_until,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE failures=VALUES(failures),window_started_at=VALUES(window_started_at),blocked_until=VALUES(blocked_until),updated_at=VALUES(updated_at)")
            ->execute([$key,$fail,$start,$blocked,self::now()]);
    }
}
