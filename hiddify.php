<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request.php';

function hiddifyRequest(string $location, string $method, string $path, ?array $payload = null): array
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!is_array($panel) || empty($panel['url_panel']) || empty($panel['secret_code'])) {
        return ['status' => null, 'body' => null, 'error' => 'Hiddify panel configuration is incomplete'];
    }

    $url = rtrim((string) $panel['url_panel'], '/') . '/' . ltrim($path, '/');
    $json = $payload === null
        ? null
        : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $send = static function (array $headers) use ($url, $method, $json): array {
        $req = new CurlRequest($url);
        $req->setHeaders($headers);
        switch (strtoupper($method)) {
            case 'GET':
                return $req->get();
            case 'POST':
                return $req->post($json ?? '{}');
            case 'PATCH':
                return $req->PATCH($json ?? '{}');
            case 'DELETE':
                return $req->delete($json);
            default:
                return ['status' => null, 'body' => null, 'error' => 'unsupported Hiddify method'];
        }
    };

    // Hiddify v2 documentation recommends the admin UUID in Hiddify-API-Key.
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Hiddify-API-Key: ' . $panel['secret_code'],
    ];
    $response = $send($headers);

    // Older Hiddify Manager builds accepted Basic auth on some GET endpoints.
    // Keep a fallback so upgrading BlueBot does not break those installations.
    if (in_array((int) ($response['status'] ?? 0), [401, 403], true)) {
        $response = $send([
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($panel['secret_code'] . ':'),
        ]);
    }

    return $response;
}

function getdatauser($username, $location)
{
    $response = hiddifyRequest($location, 'GET', '/api/v2/admin/user/');
    if (!empty($response['error'])) {
        bluebotLog('warning', 'Hiddify user request failed', [
            'panel' => (string) $location,
            'error' => (string) $response['error'],
        ]);
        return [];
    }

    $data = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($data)) {
        return [];
    }
    if (isset($data['message'])) {
        return $data;
    }

    foreach ($data as $user) {
        if (is_array($user) && (string) ($user['name'] ?? '') === (string) $username) {
            return $user;
        }
    }

    return null;
}

function serverstatus($location)
{
    return hiddifyRequest($location, 'GET', '/api/v2/admin/server_status/');
}

function adduserhi($location, array $data)
{
    return hiddifyRequest($location, 'POST', '/api/v2/admin/user/', $data);
}

function updateuserhi($username, $location, array $data)
{
    $paneldata = getdatauser($username, $location);
    if (!is_array($paneldata) || empty($paneldata['uuid'])) {
        return ['status' => null, 'body' => null, 'error' => 'user not found on panel'];
    }

    return hiddifyRequest(
        $location,
        'PATCH',
        '/api/v2/admin/user/' . rawurlencode((string) $paneldata['uuid']) . '/',
        $data
    );
}

function removeuserhi($location, $uuid)
{
    return hiddifyRequest(
        $location,
        'DELETE',
        '/api/v2/admin/user/' . rawurlencode((string) $uuid) . '/'
    );
}
