<?php
require_once dirname(__DIR__, 3) . '/src/Support/Logger.php';
require_once dirname(__DIR__, 3) . '/request.php';

function mikrotikRequest(array $panel, string $method, string $path, ?array $payload = null): array
{
    $url = rtrim((string) $panel['url_panel'], '/') . '/rest/' . ltrim($path, '/');
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => (string) $panel['username_panel'] . ':' . (string) $panel['password_panel'],
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 5000,
        CURLOPT_CONNECTTIMEOUT_MS => min((int) (($GLOBALS['request_exec_timeout'] ?? null) ?: 5000), 3000),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_SSL_VERIFYHOST => bluebotVerifyPanelTls() ? 2 : 0,
        CURLOPT_SSL_VERIFYPEER => bluebotVerifyPanelTls(),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
    ]);

    if ($payload !== null) {
        curl_setopt(
            $curl,
            CURLOPT_POSTFIELDS,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    $raw = curl_exec($curl);
    $error = $raw === false ? curl_error($curl) : '';
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($raw === false) {
        bluebotLog('warning', 'MikroTik REST request failed', [
            'method' => strtoupper($method),
            'url' => $url,
            'error' => $error,
        ]);
        return ['ok' => false, 'status' => $status, 'error' => $error, 'data' => ['error' => 404]];
    }

    $decoded = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = ['error' => 502, 'message' => 'Invalid RouterOS JSON response'];
    }

    $ok = $status >= 200 && $status < 300;
    if (!$ok) {
        bluebotLog('warning', 'MikroTik REST returned an error', [
            'method' => strtoupper($method),
            'url' => $url,
            'status' => $status,
            'response' => $decoded,
        ]);
    }

    return ['ok' => $ok, 'status' => $status, 'error' => null, 'data' => $decoded];
}

function mikrotikData(array $result): array
{
    return is_array($result['data'] ?? null) ? $result['data'] : [];
}

function login_mikrotik($url, $username, $password)
{
    $panel = [
        'url_panel' => $url,
        'username_panel' => $username,
        'password_panel' => $password,
    ];

    return mikrotikData(mikrotikRequest($panel, 'GET', 'system/resource'));
}

function addUser_mikrotik($name_panel, $username, $password, $group)
{
    $panel = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    $data = [
        'name' => (string) $username,
        'password' => (string) $password,
    ];

    // RouterOS v7 REST maps CRUD directly: PUT == add.
    $result = mikrotikRequest($panel, 'PUT', 'user-manager/user', $data);

    // Compatibility fallback for older RouterOS v7 builds.
    if (!$result['ok']) {
        $result = mikrotikRequest($panel, 'POST', 'user-manager/user/add', $data);
    }

    $response = mikrotikData($result);
    if (!$result['ok'] || isset($response['error'])) {
        return $response + ['error' => $response['error'] ?? ($result['status'] ?: 500)];
    }

    $profileResult = set_profile_mikrotik($name_panel, $username, $group);
    if (isset($profileResult['error'])) {
        return $profileResult;
    }

    return $response;
}

function set_profile_mikrotik($name_panel, $username, $prof_name)
{
    $panel = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    $data = [
        'user' => (string) $username,
        'profile' => (string) $prof_name,
    ];

    $result = mikrotikRequest($panel, 'PUT', 'user-manager/user-profile', $data);
    if (!$result['ok']) {
        $result = mikrotikRequest($panel, 'POST', 'user-manager/user-profile/add', $data);
    }

    $response = mikrotikData($result);
    if (!$result['ok'] && !isset($response['error'])) {
        $response['error'] = $result['status'] ?: 500;
    }

    return $response;
}

function GetUsermikrotik($name_panel, $username)
{
    $panel = select("marzban_panel", "*", "name_panel", $name_panel, "select");

    // .query is the native RouterOS REST filter and avoids unsafe URL query
    // interpolation. Fall back to the historical ?name= form when required.
    $result = mikrotikRequest($panel, 'POST', 'user-manager/user/print', [
        '.query' => ['name=' . (string) $username],
    ]);

    if (!$result['ok']) {
        $path = 'user-manager/user?name=' . rawurlencode((string) $username);
        $result = mikrotikRequest($panel, 'GET', $path);
    }

    return mikrotikData($result);
}

function GetUsermikrotik_volume($name_panel, $id)
{
    $panel = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    $result = mikrotikRequest($panel, 'POST', 'user-manager/user/monitor', [
        'once' => true,
        '.id' => (string) $id,
    ]);

    $response = mikrotikData($result);
    return isset($response[0]) && is_array($response[0]) ? $response[0] : $response;
}

function deleteUser_mikrotik($name_panel, $id)
{
    $panel = select("marzban_panel", "*", "name_panel", $name_panel, "select");

    // RouterOS v7 REST DELETE accepts the item ID in the resource path.
    $result = mikrotikRequest(
        $panel,
        'DELETE',
        'user-manager/user/' . rawurlencode((string) $id)
    );

    if (!$result['ok']) {
        $result = mikrotikRequest($panel, 'POST', 'user-manager/user/remove', [
            '.id' => (string) $id,
        ]);
    }

    $response = mikrotikData($result);
    if (!$result['ok'] && !isset($response['error'])) {
        $response['error'] = $result['status'] ?: 500;
    }

    return isset($response[0]) && is_array($response[0]) ? $response[0] : $response;
}
