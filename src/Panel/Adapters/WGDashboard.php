<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/request.php';
require_once dirname(__DIR__, 3) . '/src/Support/Logger.php';
ini_set('error_log', 'error_log');


function wgDashboardRequest($namepanel, string $method, string $path, ?array $payload = null): array
{
    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (!is_array($panel)) {
        return ['status' => null, 'body' => null, 'error' => 'panel not found'];
    }

    $req = new CurlRequest(rtrim((string) $panel['url_panel'], '/') . '/' . ltrim($path, '/'));
    $req->setHeaders([
        'Accept: application/json',
        'Content-Type: application/json',
        'wg-dashboard-apikey: ' . (string) $panel['password_panel'],
    ]);

    $body = $payload === null
        ? null
        : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    switch (strtoupper($method)) {
        case 'GET':
            return $req->get();
        case 'POST':
            return $req->post($body ?? '{}');
        case 'DELETE':
            return $req->delete($body);
        default:
            return ['status' => null, 'body' => null, 'error' => 'unsupported WGDashboard method'];
    }
}

function wgDashboardConfigName(array $panel): string
{
    return rawurlencode((string) ($panel['inboundid'] ?? ''));
}

function wgDashboardInvoicePublicKey($username): ?string
{
    $raw = selectValue("invoice", "user_info", "username", (string) $username, null);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    $publicKey = is_array($decoded) ? trim((string) ($decoded['public_key'] ?? '')) : '';

    return $publicKey !== '' ? $publicKey : null;
}

function get_userwg($username, $namepanel)
{
    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (!is_array($panel)) {
        return ['status' => false, 'error' => 'panel not found'];
    }

    $response = wgDashboardRequest(
        $namepanel,
        'GET',
        '/api/getWireguardConfigurationInfo?configurationName=' . rawurlencode((string) $panel['inboundid'])
    );

    if (!empty($response['error'])) {
        bluebotLog('warning', 'WGDashboard request failed', [
            'panel' => (string) $namepanel,
            'error' => (string) $response['error'],
        ]);
        return ['status' => false, 'error' => $response['error']];
    }

    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded) || empty($decoded['status'])) {
        return is_array($decoded) ? $decoded : ['status' => false, 'error' => 'invalid response'];
    }

    $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    $activePeers = is_array($data['configurationPeers'] ?? null) ? $data['configurationPeers'] : [];
    $restrictedPeers = is_array($data['configurationRestrictedPeers'] ?? null) ? $data['configurationRestrictedPeers'] : [];

    foreach ($activePeers as $userinfo) {
        if (is_array($userinfo) && (string) ($userinfo['name'] ?? '') === (string) $username) {
            return $userinfo;
        }
    }

    foreach ($restrictedPeers as $userinfo) {
        if (is_array($userinfo) && (string) ($userinfo['name'] ?? '') === (string) $username) {
            if (!isset($userinfo['configuration']) || !is_array($userinfo['configuration'])) {
                $userinfo['configuration'] = [];
            }
            $userinfo['configuration']['Status'] = false;
            return $userinfo;
        }
    }

    return [];
}

function ipslast($namepanel)
{

    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $url = $marzban_list_get['url_panel'] . '/api/getAvailableIPs/' . wgDashboardConfigName($marzban_list_get);
    $headers = array(
        'Accept: application/json',
        'wg-dashboard-apikey: ' . $marzban_list_get['password_panel']
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $response = $req->get();
    return $response;
}
function downloadconfig($namepanel, $publickey)
{

    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $url = $marzban_list_get['url_panel'] . "/api/downloadPeer/" . wgDashboardConfigName($marzban_list_get) . "?id=" . rawurlencode((string) $publickey);
    $headers = array(
        'Accept: application/json',
        'wg-dashboard-apikey: ' . $marzban_list_get['password_panel']
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $response = $req->get();
    return $response;
}
function addpear($namepanel, $usernameac)
{

    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $pubandprivate = publickey();
    $ipconfig = ipslast($namepanel);
    if (!empty($ipconfig['status']) && $ipconfig['status'] != 200) {
        return array(
            'status' => false,
            'msg' => 'error code : ' . $ipconfig['status']
        );
    }
    if (!empty($ipconfig['error'])) {
        return array(
            'status' => false,
            'msg' => $ipconfig['error']
        );
    }
    $ipconfig = json_decode($ipconfig['body'], true);
    if (!empty($ipconfig['status']) && $ipconfig['status'] == false)
        return $ipconfig;
    $key = array_keys($ipconfig['data'])[0];
    $ipconfig = $ipconfig['data'][$key][0];
    $config = array(
        'name' => $usernameac,
        'allowed_ips' => [$ipconfig],
        'private_key' => $pubandprivate['private_key'],
        'public_key' => $pubandprivate['public_key'],
        'preshared_key' => $pubandprivate['preshared_key'],
    );
    $configpanel = json_encode($config);
    $url = $marzban_list_get['url_panel'] . '/api/addPeers/' . wgDashboardConfigName($marzban_list_get);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
        'wg-dashboard-apikey: ' . $marzban_list_get['password_panel']
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $response = $req->post($configpanel);
    $result_response = $response['body'];
    $response['body'] = $config;
    $response['body']['response'] = $result_response;
    return $response;
}
function setjob($namepanel, $type, $value, $publickey)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $data = json_encode(array(
        "Job" => array(
            "JobID" => generateUUID(),
            "Configuration" => $marzban_list_get['inboundid'],
            "Peer" => $publickey,
            "Field" => $type,
            "Operator" => "lgt",
            "Value" => strval($value),
            "CreationDate" => "",
            "ExpireDate" => null,
            "Action" => "restrict"
        )
    ));
    $url = $marzban_list_get['url_panel'] . '/api/savePeerScheduleJob';
    $headers = array(
        'Accept: application/json',
        'wg-dashboard-apikey: ' . $marzban_list_get['password_panel'],
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $response = $req->post($data);
    return $response;

}
function updatepear($namepanel, array $config)
{

    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $url = $marzban_list_get['url_panel'] . '/api/updatePeerSettings/' . wgDashboardConfigName($marzban_list_get);
    $headers = array(
        'Accept: application/json',
        'wg-dashboard-apikey: ' . $marzban_list_get['password_panel']
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $response = $req->post($config);
    return $response;
}
function deletejob($namepanel, array $config)
{

    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $configpanel = json_encode($config);
    $url = $marzban_list_get['url_panel'] . '/api/deletePeerScheduleJob';
    $headers = array(
        'Accept: application/json',
        'wg-dashboard-apikey: ' . $marzban_list_get['password_panel'],
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $response = $req->post($configpanel);
    return $response;
}
function ResetUserDataUsagewg($publickey, $namepanel)
{

    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $config = array(
        "id" => $publickey,
        "type" => "total"
    );
    $configpanel = json_encode($config, true);
    $url = $marzban_list_get['url_panel'] . '/api/resetPeerData/' . wgDashboardConfigName($marzban_list_get);
    $headers = array(
        'Accept: application/json',
        'wg-dashboard-apikey: ' . $marzban_list_get['password_panel'],
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $response = $req->post($configpanel);
    return $response;
}
function remove_userwg($location, $username)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!is_array($panel)) {
        return ['status' => null, 'body' => null, 'error' => 'panel not found'];
    }

    $publicKey = wgDashboardInvoicePublicKey($username);
    if ($publicKey === null) {
        return ['status' => null, 'body' => null, 'error' => 'peer public key not found'];
    }

    // Restricted peers must be re-enabled before WGDashboard accepts deletion.
    $allow = allowAccessPeers($location, $username);
    if (is_array($allow) && !empty($allow['error'])) {
        return $allow;
    }

    return wgDashboardRequest(
        $location,
        'POST',
        '/api/deletePeers/' . wgDashboardConfigName($panel),
        ['peers' => [$publicKey]]
    );
}
function allowAccessPeers($location, $username)
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!is_array($panel)) {
        return ['status' => null, 'body' => null, 'error' => 'panel not found'];
    }

    $publicKey = wgDashboardInvoicePublicKey($username);
    if ($publicKey === null) {
        return ['status' => null, 'body' => null, 'error' => 'peer public key not found'];
    }

    return wgDashboardRequest(
        $location,
        'POST',
        '/api/allowAccessPeers/' . wgDashboardConfigName($panel),
        ['peers' => [$publicKey]]
    );
}
