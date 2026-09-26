<?php
require_once 'config.php';
require_once 'request.php';
ini_set('error_log', 'error_log');
function alirezaCookiePath($code_panel)
{
    return sys_get_temp_dir() . '/bluebot_alireza_' . md5((string) $code_panel) . '.cookie';
}

function alirezaPrepareCookieFile($code_panel)
{
    $path = alirezaCookiePath($code_panel);
    if (!is_file($path)) {
        @touch($path);
    }
    @chmod($path, 0600);
    return $path;
}
function panel_login_cookie($code_panel)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    if (!is_array($panel) || empty($panel['url_panel'])) {
        return json_encode(['success' => false, 'msg' => 'panel not found']);
    }
    $cookieFile = alirezaPrepareCookieFile($code_panel);
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $panel['url_panel'] . '/login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 10000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => "username=" . urlencode($panel['username_panel']) . "&password=" . urlencode($panel['password_panel']),
        CURLOPT_COOKIEJAR => $cookieFile,
    ));
    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return json_encode(array(
            'success' => false,
            'msg' => $error
        ));
    }
    curl_close($curl);
    @chmod($cookieFile, 0600);
    return $response;
}
function login($code_panel, $verify = true)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $cookieFile = alirezaCookiePath($code_panel);
    if ($panel['datelogin'] != null && $verify) {
        $date = json_decode($panel['datelogin'], true);
        if (isset($date['time']) && !empty($date['access_token'])) {
            $start_date = time() - strtotime($date['time']);
            if ($start_date <= 3000) {
                file_put_contents($cookieFile, $date['access_token'], LOCK_EX);
                @chmod($cookieFile, 0600);
                return;
            }
        }
    }
    $response = panel_login_cookie($panel['code_panel']);
    $cookieContent = is_file($cookieFile) ? file_get_contents($cookieFile) : false;
    if ($cookieContent !== false && $cookieContent !== '') {
        $data = json_encode(array(
            'time' => date('Y/m/d H:i:s'),
            'access_token' => $cookieContent
        ));
        update("marzban_panel", "datelogin", $data, 'name_panel', $panel['name_panel']);
    }
    if (!is_string($response))
        return array('success' => false);
    return json_decode($response, true);
}

function get_clinetsalireza($username, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => $marzban_list_get['url_panel'] . '/xui/API/inbounds',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_SSL_VERIFYHOST => bluebotVerifyPanelTls() ? 2 : 0,
        CURLOPT_SSL_VERIFYPEER => bluebotVerifyPanelTls(),
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json'
        ),
        CURLOPT_COOKIEFILE => alirezaCookiePath($marzban_list_get['code_panel']),
    ));
    $output = [];
    $rawResponse = curl_exec($curl);
    $curlError = $rawResponse === false ? curl_error($curl) : '';
    curl_close($curl);

    if ($rawResponse === false) {
        bluebotLog('warning', 'Alireza clients request failed', [
            'panel' => (string) $namepanel,
            'error' => $curlError,
        ]);
        @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
        return [];
    }

    $decodedResponse = json_decode($rawResponse, true);
    $response = is_array($decodedResponse) ? ($decodedResponse['obj'] ?? null) : null;
    if (!is_array($response)) {
        bluebotLog('warning', 'Alireza clients request returned an invalid response', [
            'panel' => (string) $namepanel,
        ]);
        @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
        return [];
    }

    foreach ($response as $client) {
        $settings = json_decode((string) ($client['settings'] ?? ''), true);
        $clientdata = is_array($settings) && isset($settings['clients']) && is_array($settings['clients']) ? $settings['clients'] : [];
        foreach ($clientdata as $clinets) {
            if ($clinets['email'] == $username) {
                $output[] = $clinets;
                break;
            }
        }
        $clientStats = isset($client['clientStats']) && is_array($client['clientStats']) ? $client['clientStats'] : [];
        foreach ($clientStats as $clinetsup) {
            if ($clinetsup['email'] == $username) {
                $output[] = $clinetsup;
                break;
            }
        }

    }
    @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
    return $output;
}
function addClientalireza_singel($namepanel, $usernameac, $Expire, $Total, $Uuid, $Flow, $subid, $inboundid)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $config = array(
        "id" => intval($inboundid),
        'settings' => json_encode(array(
            'clients' => array(
                array(
                    "id" => $Uuid,
                    "flow" => $Flow,
                    "email" => $usernameac,
                    "totalGB" => $Total,
                    "expiryTime" => $Expire,
                    "enable" => true,
                    "tgId" => "",
                    "subId" => $subid,
                    "reset" => 0
                )
            ),
            'decryption' => 'none',
            'fallbacks' => array(),
        ))
    );

    $configpanel = json_encode($config, true);
    $url = $marzban_list_get['url_panel'] . '/xui/API/inbounds/addClient';
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setCookie(alirezaCookiePath($marzban_list_get['code_panel']));
    $response = $req->post($configpanel);
    @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
    return $response;
}
function alirezaClientIdentifier(array $client): string
{
    // Current x-ui API uses protocol-specific client IDs:
    // VMess/VLESS => id, Trojan => password, Shadowsocks => email.
    foreach (['id', 'password', 'email'] as $field) {
        if (isset($client[$field]) && (string) $client[$field] !== '') {
            return (string) $client[$field];
        }
    }

    return '';
}

function updateClientalireza($namepanel, $username, array $config)
{
    $clients = get_clinetsalireza($username, $namepanel);
    $UsernameData = $clients[0] ?? null;
    if (!is_array($UsernameData)) {
        return ['status' => 404, 'body' => null, 'error' => 'client not found'];
    }

    $clientId = alirezaClientIdentifier($UsernameData);
    if ($clientId === '') {
        return ['status' => 400, 'body' => null, 'error' => 'client identifier not found'];
    }

    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $configpanel = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = rtrim($marzban_list_get['url_panel'], '/') . '/xui/API/inbounds/updateClient/' . rawurlencode($clientId);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setCookie(alirezaCookiePath($marzban_list_get['code_panel']));
    $response = $req->post($configpanel);
    @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
    return $response;
}
function ResetUserDataUsagealirezasin($usernamepanel, $namepanel)
{
    $data_user = get_clinetsalireza($usernamepanel, $namepanel)[0];
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    login($marzban_list_get['code_panel']);
    $url = rtrim($marzban_list_get['url_panel'], '/') . "/xui/API/inbounds/" . rawurlencode((string) $marzban_list_get['inboundid']) . "/resetClientTraffic/" . rawurlencode((string) $data_user['email']);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setCookie(alirezaCookiePath($marzban_list_get['code_panel']));
    $response = $req->post(array());
    @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
    return $response;
}
function removeClientalireza_single($location, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location, "select");
    $clients = get_clinetsalireza($username, $location);
    $data_user = $clients[0] ?? null;
    if (!is_array($data_user)) {
        return ['status' => 404, 'body' => null, 'error' => 'client not found'];
    }

    $clientId = alirezaClientIdentifier($data_user);
    if ($clientId === '') {
        return ['status' => 400, 'body' => null, 'error' => 'client identifier not found'];
    }

    login($marzban_list_get['code_panel']);
    $url = rtrim($marzban_list_get['url_panel'], '/') . "/xui/API/inbounds/"
        . rawurlencode((string) $marzban_list_get['inboundid'])
        . "/delClient/" . rawurlencode($clientId);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
    );
    $req = new CurlRequest($url);
    $req->setHeaders($headers);
    $req->setCookie(alirezaCookiePath($marzban_list_get['code_panel']));
    $response = $req->post(array());
    @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
    return $response;
}
function get_onlineclialireza($name_panel, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    login($marzban_list_get['code_panel']);
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $marzban_list_get['url_panel'] . '/xui/API/inbounds/onlines',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 10000,
        CURLOPT_CONNECTTIMEOUT_MS => min((int) (($GLOBALS['request_exec_timeout'] ?? null) ?: 10000), 5000),
        CURLOPT_SSL_VERIFYHOST => bluebotVerifyPanelTls() ? 2 : 0,
        CURLOPT_SSL_VERIFYPEER => bluebotVerifyPanelTls(),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json'
        ),
        CURLOPT_COOKIEFILE => alirezaCookiePath($marzban_list_get['code_panel']),
    ));
    $rawResponse = curl_exec($curl);
    if ($rawResponse === false) {
        bluebotLog('warning', 'Alireza online-clients request failed', [
            'panel' => (string) $name_panel,
            'error' => curl_error($curl),
        ]);
        curl_close($curl);
        @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
        return "offline";
    }
    $decoded = json_decode($rawResponse, true);
    curl_close($curl);
    @unlink(alirezaCookiePath($marzban_list_get['code_panel']));
    $response = is_array($decoded) ? ($decoded['obj'] ?? null) : null;
    if (!is_array($response))
        return "offline";
    if (in_array($username, $response, true))
        return "online";
    return "offline";

}