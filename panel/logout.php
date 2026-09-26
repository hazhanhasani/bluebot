<?php
require_once __DIR__ . '/../src/Support/Logger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$admin = (string) ($_SESSION['admin_user'] ?? 'unknown');
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

bluebotAudit('admin.logout', [
    'admin' => $admin,
    'ip' => $ip,
]);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?: '',
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

session_destroy();
header('Location: login.php');
exit;
