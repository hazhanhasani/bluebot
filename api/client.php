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
require_once __DIR__ . '/../src/Services/AppClientPanelQrResolver.php';
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
    try {
        return AppClientAuth::authorize(
            $pdo,
            clientBearer(),
            clientHeader('X-Device-Id')
        );
    } catch (Throwable $e) {
        clientResponse(false, 'Authentication required', [], 401);
    }
}

function clientPanelProtocol(string $type): string
{
    $normalized = strtolower(trim($type));

    if (in_array($normalized, [
        'wgdashboard',
        'ibsng',
        'mikrotik',
    ], true)) {
        return 'unsupported';
    }

    // Every other panel is allowed to be probed. This avoids false negatives
    // for new Xray-compatible adapters while the service endpoint still
    // validates that an actual share link or subscription URL exists.
    return 'xray';
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

function clientExtractConnection(array $runtime): array
{
    $links = clientNormalizeLinks($runtime['links'] ?? ($runtime['configs'] ?? []));
    $rawSubscription = trim((string) ($runtime['subscription_url'] ?? ''));

    // Manual/custom providers sometimes put a single share link in the
    // subscription_url field. Treat it as a normal in-memory Xray link.
    if ($rawSubscription !== '') {
        $embeddedLinks = clientNormalizeLinks($rawSubscription);
        if (!empty($embeddedLinks)) {
            $links = array_values(array_unique(array_merge($links, $embeddedLinks)));
            $rawSubscription = '';
        }
    }

    $subscriptionUrl = null;
    if ($rawSubscription !== '') {
        $parts = parse_url($rawSubscription);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (in_array($scheme, ['https', 'http'], true)) {
            $subscriptionUrl = $rawSubscription;
        }
    }

    return [
        'links' => $links,
        'subscription_url' => $subscriptionUrl,
    ];
}

function clientServiceSummary(array $invoice, ?array $panel): array
{
    $panelType = is_array($panel) ? (string) ($panel['type'] ?? '') : '';
    $protocol = is_array($panel) ? clientPanelProtocol($panelType) : 'unsupported';

    return [
        'id' => (string) ($invoice['id_invoice'] ?? ''),
        'username' => (string) ($invoice['username'] ?? ''),
        'product_name' => (string) ($invoice['name_product'] ?? ''),
        'note' => (string) ($invoice['note'] ?? ''),
        'status' => (string) ($invoice['status'] ?? $invoice['Status'] ?? ''),
        'supported' => $protocol === 'xray',
        'protocol_family' => $protocol,
        'panel_type' => $panelType,
    ];
}

function clientAndroidUpdateManifest(): array
{
    $manifestPath = __DIR__ . '/../android-client/update.json';
    $manifest = [];

    if (is_file($manifestPath)) {
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (is_array($decoded)) {
            $manifest = $decoded;
        }
    }

    $versionPath = __DIR__ . '/../version';
    $releaseVersion = is_file($versionPath)
        ? trim((string) file_get_contents($versionPath))
        : '';

    if (!preg_match('/^\d+\.\d+\.\d+$/', $releaseVersion)) {
        $releaseVersion = '0.0.0';
    }

    $tag = 'v' . $releaseVersion;
    $downloadUrl = trim((string) ($manifest['download_url'] ?? ''));
    $downloadParts = $downloadUrl !== '' ? parse_url($downloadUrl) : false;
    if (!is_array($downloadParts)
        || strtolower((string) ($downloadParts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($downloadParts['host'] ?? '')) !== 'github.com') {
        $downloadUrl = "https://github.com/hazhanhasani/bluebot/releases/download/{$tag}/blue-vpn-android-{$tag}.apk";
    }

    return [
        'latest_version_code' => max(1, (int) ($manifest['latest_version_code'] ?? 1)),
        'latest_version_name' => (string) ($manifest['latest_version_name'] ?? '0.1.0'),
        'minimum_version_code' => max(1, (int) ($manifest['minimum_version_code'] ?? 1)),
        'release_tag' => $tag,
        'download_url' => $downloadUrl,
        'release_notes' => (string) ($manifest['release_notes'] ?? ''),
        'check_interval_seconds' => max(
            3600,
            (int) ($manifest['check_interval_seconds'] ?? 21600)
        ),
    ];
}

function clientAuthResponse(array $session, string $message = 'Authenticated'): never
{
    $user = is_array($session['user'] ?? null) ? $session['user'] : [];
    $service = is_array($session['service'] ?? null) ? $session['service'] : [];

    clientResponse(true, $message, [
        'access_token' => (string) $session['token'],
        'token_type' => 'Bearer',
        'expires_in' => (int) $session['expires_in'],
        'expires_at' => (string) $session['expires_at'],
        'account' => [
            'username' => (string) ($session['account']['username'] ?? ''),
            'telegram_user_id' => (string) ($session['account']['user_id'] ?? ''),
            'service_id' => (string) ($session['account']['invoice_id'] ?? ''),
            'service_name' => (string) ($service['name_product'] ?? ''),
            'balance' => (int) ($user['Balance'] ?? 0),
        ],
    ]);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = $method === 'POST' ? clientBody() : [];
$action = trim((string) ($_GET['action'] ?? ($body['action'] ?? '')));

if ($action === 'health') {
    clientResponse(true, 'ok', [
        'api' => 'bluepanel-client',
        'version' => 4,
        'service_scoped_accounts' => true,
        'qr_login' => true,
        'connected_panel_qr_login' => true,
    ]);
}

if ($action === 'version' || $action === 'app-version') {
    if ($method !== 'GET') {
        clientResponse(false, 'Method not allowed', [], 405);
    }

    clientResponse(true, 'ok', [
        'android' => clientAndroidUpdateManifest(),
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

        clientAuthResponse($session);
    } catch (AppClientAuthRateLimitException $e) {
        clientResponse(false, 'Too many login attempts. Try again later.', [], 429);
    } catch (InvalidArgumentException $e) {
        clientResponse(false, $e->getMessage(), [], 422);
    } catch (Throwable $e) {
        clientResponse(false, 'Invalid username or password', [], 401);
    }
}

if ($action === 'qr-login') {
    if ($method !== 'POST') {
        clientResponse(false, 'Method not allowed', [], 405);
    }

    $payload = trim((string) ($body['qr_payload'] ?? ''));
    $deviceId = trim((string) ($body['device_id'] ?? clientHeader('X-Device-Id')));

    if ($payload === '' || $deviceId === '') {
        clientResponse(false, 'QR payload and device id are required', [], 422);
    }

    try {
        $session = AppClientAuth::authenticateQr(
            $pdo,
            $payload,
            $deviceId,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '')
        );
        clientAuthResponse($session, 'QR authenticated');
    } catch (AppClientAuthRateLimitException $e) {
        clientResponse(false, 'Too many QR login attempts. Try again later.', [], 429);
    } catch (InvalidArgumentException $e) {
        clientResponse(false, 'این QR برای ورود Blue VPN قابل استفاده نیست.', [], 422);
    } catch (Throwable $e) {
        clientResponse(
            false,
            'این QR در سرویس‌های پنل‌های متصل به ربات پیدا نشد. QR اصلی Subscription یا کانفیگ همان سرویس را اسکن کنید.',
            [],
            401
        );
    }
}

$session = clientRequireSession($pdo);
$userId = (string) $session['user_id'];
$invoiceId = (string) $session['invoice_id'];
$sessionService = is_array($session['service'] ?? null) ? $session['service'] : [];

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
        'service_id' => $invoiceId,
        'service_name' => (string) ($sessionService['name_product'] ?? ''),
        'balance' => (int) ($user['Balance'] ?? 0),
        'phone' => (string) ($user['number'] ?? ''),
    ]);
}

if ($action === 'services') {
    if ($method !== 'GET') {
        clientResponse(false, 'Method not allowed', [], 405);
    }

    if (empty($sessionService)) {
        clientResponse(true, 'ok', ['services' => []]);
    }

    $panel = select(
        'marzban_panel',
        '*',
        'name_panel',
        (string) ($sessionService['Service_location'] ?? ''),
        'select'
    );

    clientResponse(true, 'ok', [
        'services' => [
            clientServiceSummary(
                $sessionService,
                is_array($panel) ? $panel : null
            ),
        ],
    ]);
}

if ($action === 'service') {
    if ($method !== 'GET') {
        clientResponse(false, 'Method not allowed', [], 405);
    }

    $requestedId = trim((string) ($_GET['id'] ?? ''));
    if ($requestedId === '') {
        clientResponse(false, 'Service id is required', [], 422);
    }

    if (!hash_equals($invoiceId, $requestedId)) {
        clientResponse(false, 'Service not found', [], 404);
    }

    $invoice = $sessionService;
    if (
        !is_array($invoice)
        || !hash_equals((string) ($invoice['id_invoice'] ?? ''), $invoiceId)
    ) {
        clientResponse(false, 'Service not found', [], 404);
    }

    $panel = select(
        'marzban_panel',
        '*',
        'name_panel',
        (string) ($invoice['Service_location'] ?? ''),
        'select'
    );

    if (!is_array($panel)) {
        clientResponse(false, 'Service panel not found', [], 422);
    }

    $panelType = (string) ($panel['type'] ?? '');
    if (clientPanelProtocol($panelType) !== 'xray') {
        clientResponse(
            false,
            'This service uses a protocol that is not supported by the Android client',
            [],
            422
        );
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
            'panel_type' => $panelType,
            'reason' => $e->getMessage(),
        ]);
        clientResponse(false, 'Service data unavailable', [], 502);
    }

    if (
        !is_array($runtime)
        || (string) ($runtime['status'] ?? '') === 'Unsuccessful'
    ) {
        clientResponse(false, 'Service data unavailable', [], 502);
    }

    $connection = clientExtractConnection($runtime);
    if (empty($connection['links']) && empty($connection['subscription_url'])) {
        clientResponse(false, 'No compatible Xray connection profile is available', [], 422);
    }

    $dataLimit = is_numeric($runtime['data_limit'] ?? null)
        ? (float) $runtime['data_limit']
        : 0.0;
    $usedTraffic = is_numeric($runtime['used_traffic'] ?? null)
        ? (float) $runtime['used_traffic']
        : 0.0;
    $expire = is_numeric($runtime['expire'] ?? null)
        ? (int) $runtime['expire']
        : 0;

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
            'panel_type' => $panelType,
            'links' => $connection['links'],
            'subscription_url' => $connection['subscription_url'],
        ],
    ]);
}

clientResponse(false, 'Unknown action', [], 404);
