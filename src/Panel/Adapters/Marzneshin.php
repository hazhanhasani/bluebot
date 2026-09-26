<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/request.php';

function token_panelm($code_panel)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    if (!is_array($panel)) {
        return ['error' => 'panel not found'];
    }

    if (!empty($panel['datelogin'])) {
        $cached = json_decode($panel['datelogin'], true);
        if (is_array($cached) && !empty($cached['time']) && !empty($cached['access_token'])) {
            $age = time() - (strtotime($cached['time']) ?: 0);
            if ($age >= 0 && $age <= 3600) {
                return $cached;
            }
        }
    }

    $req = new CurlRequest(rtrim($panel['url_panel'], '/') . '/api/admins/token');
    $req->setHeaders([
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ]);
    $response = $req->post([
        'username' => $panel['username_panel'],
        'password' => $panel['password_panel'],
    ]);

    if (!empty($response['error'])) {
        return ['error' => $response['error']];
    }

    $body = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($body)) {
        return ['error' => 'invalid token response'];
    }

    if (!empty($body['access_token'])) {
        $cached = [
            'time' => date('Y/m/d H:i:s'),
            'access_token' => $body['access_token'],
        ];
        update("marzban_panel", "datelogin", json_encode($cached), 'name_panel', $panel['name_panel']);
        return $cached + $body;
    }

    return $body;
}

function marzneshinRequest($location, string $method, string $path, ?array $payload = null): array
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!is_array($panel)) {
        return ['status' => null, 'body' => null, 'error' => 'panel not found'];
    }

    $token = token_panelm($panel['code_panel']);
    if (empty($token['access_token'])) {
        return [
            'status' => null,
            'body' => null,
            'error' => $token['error'] ?? $token['detail'] ?? 'authentication failed',
        ];
    }

    $req = new CurlRequest(rtrim($panel['url_panel'], '/') . '/' . ltrim($path, '/'));
    $req->setHeaders([
        'Accept: application/json',
        'Content-Type: application/json',
    ]);
    $req->setBearerToken($token['access_token']);

    $body = $payload === null
        ? null
        : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    switch (strtoupper($method)) {
        case 'GET':
            return $req->get();
        case 'POST':
            return $req->post($body ?? '{}');
        case 'PUT':
            return $req->put($body ?? '{}');
        case 'DELETE':
            return $req->delete($body);
        default:
            return ['status' => null, 'body' => null, 'error' => 'unsupported Marzneshin method'];
    }
}

function getuserm($username_account, $location)
{
    return marzneshinRequest(
        $location,
        'GET',
        '/api/users/' . rawurlencode((string) $username_account)
    );
}

function ResetUserDataUsagem($username_account, $location)
{
    return marzneshinRequest(
        $location,
        'POST',
        '/api/users/' . rawurlencode((string) $username_account) . '/reset',
        []
    );
}

function revoke_subm($username_account, $location)
{
    return marzneshinRequest(
        $location,
        'POST',
        '/api/users/' . rawurlencode((string) $username_account) . '/revoke_sub',
        []
    );
}

function adduserm($location, $data_limit, $username_ac, $timestamp, $name_product, $note = '', $data_limit_reset = 'no_reset')
{
    $product = $name_product !== false
        ? select('product', "*", "name_product", $name_product, "select")
        : false;
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");

    if (!is_array($panel)) {
        return ['status' => null, 'body' => null, 'error' => 'panel not found'];
    }

    if (is_array($product) && !empty($product['inbounds'])) {
        $panel['proxies'] = $product['inbounds'];
    }

    if (!panelProtocolsConfigured($panel['proxies'] ?? null)) {
        return panelProtocolsMissingError($panel['name_panel']);
    }

    $serviceIds = json_decode((string) $panel['proxies'], true);
    if (!is_array($serviceIds)) {
        $serviceIds = [];
    }
    $serviceIds = array_values(array_unique(array_map('intval', array_filter($serviceIds, 'is_numeric'))));

    $data = [
        'service_ids' => $serviceIds,
        'data_limit' => max(0, (int) $data_limit),
        'username' => (string) $username_ac,
        'note' => (string) $note,
        'data_limit_reset_strategy' => (string) $data_limit_reset,
    ];

    $isTrial = $name_product === 'usertest';
    $startOnFirstUse = $isTrial
        ? (($panel['on_hold_test'] ?? '0') !== '0')
        : (($panel['conecton'] ?? 'offconecton') !== 'offconecton');

    if ((int) $timestamp === 0) {
        // UserCreate requires expire_strategy on current Marzneshin releases.
        $data['expire_strategy'] = 'never';
        $data['expire_date'] = null;
    } elseif ($startOnFirstUse) {
        $data['expire_strategy'] = 'start_on_first_use';
        $data['expire_date'] = null;
        $data['usage_duration'] = max(1, (int) $timestamp - time());
    } else {
        $data['expire_strategy'] = 'fixed_date';
        $data['expire_date'] = date(DATE_ATOM, (int) $timestamp);
    }

    return marzneshinRequest($location, 'POST', '/api/users', $data);
}

function Get_System_Statsm($location)
{
    return marzneshinRequest($location, 'GET', '/api/system/stats/users');
}

function removeuserm($location, $username_account)
{
    return marzneshinRequest(
        $location,
        'DELETE',
        '/api/users/' . rawurlencode((string) $username_account)
    );
}

function Modifyuserm($location, $username_account, array $data)
{
    // Current Marzneshin UserModify accepts partial fields; normalize fixed-date
    // timestamps generated by BlueBot into the documented datetime value.
    if (isset($data['expire_date']) && is_numeric($data['expire_date'])) {
        $data['expire_date'] = date(DATE_ATOM, (int) $data['expire_date']);
    }

    return marzneshinRequest(
        $location,
        'PUT',
        '/api/users/' . rawurlencode((string) $username_account),
        $data
    );
}

function enableuser($location, $username_account)
{
    return marzneshinRequest(
        $location,
        'POST',
        '/api/users/' . rawurlencode((string) $username_account) . '/enable',
        []
    );
}

function disableduser($location, $username_account)
{
    return marzneshinRequest(
        $location,
        'POST',
        '/api/users/' . rawurlencode((string) $username_account) . '/disable',
        []
    );
}
