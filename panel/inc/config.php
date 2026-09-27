<?php

require_once __DIR__ . '/../../src/Support/TrustedProxy.php';

function bluebotPanelIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '' || !bluebotIsCloudflareProxyIp($remote)) {
        return false;
    }

    $cfVisitor = (string) ($_SERVER['HTTP_CF_VISITOR'] ?? '');
    if ($cfVisitor !== '') {
        $decoded = json_decode($cfVisitor, true);
        if (is_array($decoded) && strtolower((string) ($decoded['scheme'] ?? '')) === 'https') {
            return true;
        }
    }

    return strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
}

function bluebotPanelClientIp(): string
{
    $resolved = bluebotResolveWebhookClientIp($_SERVER);
    if ($resolved !== '') {
        return $resolved;
    }

    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => bluebotPanelIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

require __DIR__ . '/../../config.php';
require __DIR__ . '/../../function.php';

// Panel UI language strings (loaded from lang/fa.php via languagechange())
$textbotlang = languagechange();

function db_query(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch(PDO $pdo, string $sql, array $params = []): ?array
{
    return db_query($pdo, $sql, $params)->fetch() ?: null;
}

function db_fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    return db_query($pdo, $sql, $params)->fetchAll();
}

function db_count(PDO $pdo, string $sql, array $params = []): int
{
    return (int) db_query($pdo, $sql, $params)->fetchColumn();
}
function require_auth(): void
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    global $pdo;
    if (empty($_SESSION['admin_user'])) {
        header('Location: login.php');
        exit;
    }
    try {
        $admin = db_fetch($pdo, "SELECT id_admin, rule FROM admin WHERE username = ?", [$_SESSION['admin_user']]);
        if (!$admin || $admin['rule'] !== 'administrator') {
            session_destroy();
            header('Location: login.php');
            exit;
        }
    } catch (Exception $e) {
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check_value(string $token): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', $token);
}

function csrf_check_post(): void
{
    global $textbotlang;
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die($textbotlang['panel']['configInvalidRequest']);
    }
}

function csrf_check_get(): void
{
    global $textbotlang;
    $token = $_GET['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die($textbotlang['panel']['configInvalidRequest']);
    }
}

function flash(string $key, string $msg): void
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    $_SESSION["flash_{$key}"] = $msg;
}

function get_flash(string $key): ?string
{
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    $msg = $_SESSION["flash_{$key}"] ?? null;
    unset($_SESSION["flash_{$key}"]);
    return $msg;
}

function trunc(string $str, int $max = 30): string
{
    return mb_strlen($str, 'UTF-8') > $max
        ? mb_substr($str, 0, $max, 'UTF-8') . '…'
        : $str;
}

function safe_date($ts, string $fmt = 'Y/m/d'): string
{
    if (!$ts)
        return '—';
    if (!is_numeric($ts))
        return htmlspecialchars((string) $ts);
    return date($fmt, (int) $ts);
}
function login_rate_file(string $ip): string
{
    return sys_get_temp_dir() . '/bluebot-panel-login-' . hash('sha256', $ip) . '.json';
}

function check_login_rate(string $ip): bool
{
    $file = login_rate_file($ip);
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        // Fail closed when the limiter cannot persist its state.
        bluebotLog('warning', 'Panel login rate limiter storage unavailable', [
            'ip' => $ip,
        ]);
        return false;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $data = json_decode(is_string($raw) ? $raw : '', true);
    $data = is_array($data) ? $data : [];

    $now = time();
    $data = array_values(array_filter(
        $data,
        static fn($timestamp): bool => is_numeric($timestamp)
            && $now - (int) $timestamp >= 0
            && $now - (int) $timestamp < 900
    ));

    $allowed = count($data) < 10;
    if ($allowed) {
        $data[] = $now;
    }

    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES);
    rewind($handle);
    ftruncate($handle, 0);
    if (is_string($encoded)) {
        fwrite($handle, $encoded);
        fflush($handle);
    }

    @chmod($file, 0600);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $allowed;
}

function clear_login_rate(string $ip): void
{
    @unlink(login_rate_file($ip));
}

function user_role_label(string $agent): string
{
    global $textbotlang;
    return match ($agent) {
        'n' => $textbotlang['panel']['configRoleN'],
        'n2' => $textbotlang['panel']['configRoleN2'],
        'all' => $textbotlang['panel']['configRoleAll'],
        default => $textbotlang['panel']['configRoleDefault'],
    };
}

function user_role_tag(string $agent): string
{
    return match ($agent) {
        'f' => 'tag-info',
        'n' => 'tag-info',
        'n2' => 'tag-warn',
        'all' => 'tag-ok',
        default => 'tag-plain',
    };
}
