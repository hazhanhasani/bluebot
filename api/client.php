<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../src/Services/AppClientAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

$ManagePanel = new ManagePanel();

function clientResponse(bool $ok, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode([
        'success' => $ok,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function clientBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        clientResponse(false, 'Invalid JSON body', [], 400);
    }
    return sanitize_recursive($decoded);
}

function clientHeader(string $name): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (is_array($headers)) {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_scalar($value) ? trim((string) $value) : '';
            }
        }
    }
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$serverKey]) && is_scalar($_SERVER[$serverKey])
        ? trim((string) $_SERVER[$serverKey])
        : '';
}

function clientBearer(): string
{
    $authorization = clientHeader('Authorization');
    if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $match)) {
        return (string) $match[1];
    }
    return '';
}

function clientRequireSession(PDO $pdo): array
{
    $token = clientBearer();
    $deviceId = clientHeader('X-Device-Id');
    try {
        return AppClientAuth::authorize($pdo, $token, $deviceId);
    } catch (Throwable $e) {
        clientResponse(false, 'Authentication required', [], 401);
    }
}

function clientSupportedPanelType(string $type): bool
{
    return in_array($type, [
        'marzban',
        'marzneshin',
        'alireza_single',
        'x-ui_single',
        'hiddify',
    ], true);
}

function clientNormalizeLinks($value): array
{
    if (is_array($value)) {
        $links = $value;
    } elseif (is_string($value) && trim($value) !== '') {
        $links = preg_split('/\R+/', trim($value)) ?: [];
    } else {
        $links = [];
    }

    $out = [];
    foreach ($links as $link) {
        if (!is_scalar($link)) {
            continue;
        }
        $link = trim((string) $link);
        if ($link === '') {
            continue;
        }
        if (!preg_match('/^(vless|vmess|trojan|ss|socks|hysteria2|hy2):\/\//i', $link)) {
            continue;
        }
        $out[] = $link;
    }
    return array_values(array_unique($out));
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = $method === 'POST' ? clientBody() : [];
$action = trim((string) ($_GET['action'] ?? ($body['action'] ?? '')));

if ($action === 'health') {
    clientResponse(true, 'ok', [
        'api' => 'bluepanel-client',
        'version' => 1,
    ]);
}

if ($action === 'login') {
    if ($method !== 'POST') {
        clientResponse(false, 'Method not allowed', [], 405);
    }
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $deviceId = trim((string) ($body['device_id'] ?? clientHeader('X-Device-Id')));
    if ($username === '' || $password === '' || $deviceId === '') {
        clientResponse(false, 'Username, password and device id are required', [], 422);
    }
    try {
        $session = AppClientAuth::authenticate(
            $pdo,
            $username,
            $password,
            $deviceId,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );
        $user = is_array($session['user'] ?? null) ? $session['user'] : [];
        clientResponse(true, 'Authenticated', [
            'access_token' => (string) $session['token'],
            'token_type' => 'Bearer',
            'expires_in' => (int) $session['expires_in'],
            'expires_at' => (string) $session['expires_at'],
            'account' => [
                'username' => (string) ($session['account']['username'] ?? ''),
                'telegram_user_id' => (string) ($session['account']['user_id'] ?? ''),
                'balance' => (int) ($user['Balance'] ?? 0),
            ],
        ]);
    } catch (AppClientAuthRateLimitException $e) {
        clientResponse(false, 'Too many login attempts. Try again later.', [], 429);
    } catch (InvalidArgumentException $e) {
        clientResponse(false, $e->getMessage(), [], 422);
    } catch (Throwable $e) {
        clientResponse(false, 'Invalid username or password', [], 401);
    }
}

$session = clientRequireSession($pdo);
$userId = (string) $session['user_id'];

if ($action === 'logout') {
    if ($method !== 'POST') {
        clientResponse(false, 'Method not allowed', [], 405);
    }
    AppClientAuth::logout($pdo, clientBearer());
    clientResponse(true, 'Logged out');
}

if ($action === 'me') {
    if ($method !== 'GET') {
        clientResponse(false, 'Method not allowed', [], 405);
    }
    $user = is_array($session['user'] ?? null) ? $session['user'] : [];
    clientResponse(true, 'ok', [
        'username' => (string) $session['username'],
        'telegram_user_id' => $userId,
        'balance' => (int) ($user['Balance'] ?? 0),
        'phone' => (string) ($user['number'] ?? ''),
    ]);
}

if ($action === 'services') {
    if ($method !== 'GET') {
        clientResponse(false, 'Method not allowed', [], 405);
    }
    $stmt = $pdo->prepare(
        "SELECT id_invoice, username, name_product, note, Service_location, status, time_sell
         FROM invoice
         WHERE id_user = :user_id
           AND status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')
         ORDER BY time_sell DESC
         LIMIT 100"
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $items = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $panel = select('marzban_panel', '*', 'name_panel', (string) ($row['Service_location'] ?? ''), 'select');
        $panelType = is_array($panel) ? (string) ($panel['type'] ?? '') : '';
        $items[] = [
            'id' => (string) ($row['id_invoice'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'product_name' => (string) ($row['name_product'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'supported' => clientSupportedPanelType($panelType),
            'protocol_family' => clientSupportedPanelType($panelType) ? 'xray' : 'unsupported',
        ];
    }
    clientResponse(true, 'ok', ['services' => $items]);
}

if ($action === 'service') {
    if ($method !== 'GET') {
        clientResponse(false, 'Method not allowed', [], 405);
    }
    $invoiceId = trim((string) ($_GET['id'] ?? ''));
    if ($invoiceId === '') {
        clientResponse(false, 'Service id is required', [], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM invoice
         WHERE id_invoice = :id_invoice
           AND id_user = :user_id
           AND status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')
         LIMIT 1"
    );
    $stmt->execute([
        ':id_invoice' => $invoiceId,
        ':user_id' => $userId,
    ]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($invoice)) {
        clientResponse(false, 'Service not found', [], 404);
    }

    $panel = select('marzban_panel', '*', 'name_panel', (string) $invoice['Service_location'], 'select');
    if (!is_array($panel) || !clientSupportedPanelType((string) ($panel['type'] ?? ''))) {
        clientResponse(false, 'This service is not supported by the Android client yet', [], 422);
    }

    try {
        $runtime = $ManagePanel->DataUser(
            (string) $invoice['Service_location'],
            (string) $invoice['username']
        );
    } catch (Throwable $e) {
        bluebotLog('warning', 'Android client service lookup failed', [
            'user_id' => $userId,
            'invoice_id' => $invoiceId,
            'reason' => $e->getMessage(),
        ]);
        clientResponse(false, 'Service data unavailable', [], 502);
    }

    if (!is_array($runtime)) {
        clientResponse(false, 'Service data unavailable', [], 502);
    }

    $dataLimit = is_numeric($runtime['data_limit'] ?? null) ? (float) $runtime['data_limit'] : 0.0;
    $usedTraffic = is_numeric($runtime['used_traffic'] ?? null) ? (float) $runtime['used_traffic'] : 0.0;
    $expire = is_numeric($runtime['expire'] ?? null) ? (int) $runtime['expire'] : 0;
    $links = clientNormalizeLinks($runtime['links'] ?? ($runtime['configs'] ?? []));
    $subscriptionUrl = trim((string) ($runtime['subscription_url'] ?? ''));

    if (empty($links) && $subscriptionUrl === '') {
        clientResponse(false, 'No compatible connection profile is available', [], 422);
    }

    clientResponse(true, 'ok', [
        'service' => [
            'id' => (string) ($invoice['id_invoice'] ?? ''),
            'username' => (string) ($runtime['username'] ?? $invoice['username']),
            'product_name' => (string) ($invoice['name_product'] ?? ''),
            'status' => (string) ($runtime['status'] ?? $invoice['status'] ?? 'unknown'),
            'traffic' => [
                'total_bytes' => max(0, (int) $dataLimit),
                'used_bytes' => max(0, (int) $usedTraffic),
                'remaining_bytes' => max(0, (int) ($dataLimit - $usedTraffic)),
            ],
            'expires_at' => $expire > 0 ? $expire : null,
        ],
        'connection' => [
            'type' => 'xray',
            'links' => $links,
            'subscription_url' => $subscriptionUrl !== '' ? $subscriptionUrl : null,
        ],
    ]);
}

clientResponse(false, 'Unknown action', [], 404);
