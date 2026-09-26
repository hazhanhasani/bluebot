<?php
require_once 'config.php';
require_once __DIR__ . '/src/Support/Logger.php';
ini_set('error_log', 'error_log');

function suiCurlJson($curl): array
{
    $raw = curl_exec($curl);
    $error = $raw === false ? curl_error($curl) : '';
    curl_close($curl);

    if ($raw === false) {
        bluebotLog('warning', 'S-UI request failed', ['error' => $error]);
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}


function get_Clients_ui($username, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $curl = curl_init();
    $url = rtrim($marzban_list_get['url_panel'], '/') . '/apiv2/clients';
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Token: ' . $marzban_list_get['password_panel']
        ),
    ));
    $response = suiCurlJson($curl);
    if ($response === [])
        return [];
    if (empty($response['success']))
        return [];
    if (!isset($response['obj']['clients']))
        return array();
    foreach ($response['obj']['clients'] as $data) {
        if ($data['name'] == $username)
            return $data;
    }
    return [];
}
function GetClientsS_UI($username, $namepanel)
{
    $userdata = get_Clients_ui($username, $namepanel);
    if (count($userdata) == 0)
        return [];
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => rtrim($marzban_list_get['url_panel'], '/') . '/apiv2/clients?id=' . rawurlencode((string) $userdata['id']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Token: ' . $marzban_list_get['password_panel']
        ),
    ));
    $response = suiCurlJson($curl);
    if ($response === [])
        return [];
    if (empty($response['success']))
        return [];
    return $response['obj']['clients'][0] ?? [];
}
function addClientS_ui($namepanel, $usernameac, $Expire, $Total, $inboundid, $note)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if ($usernameac == null)
        return array(
            'success' => false,
            'msg' => "error"
        );
    $password = bin2hex(random_bytes(16));
    $configpanel = array(
        "object" => 'clients',
        'action' => "new",
        "data" => json_encode(array(
            "enable" => true,
            "name" => $usernameac,
            "config" => array(
                "mixed" => array(
                    "username" => $usernameac
                    ,
                    "password" => generateAuthStr()
                ),
                "socks" => array(
                    "username" => $usernameac,
                    "password" => generateAuthStr()
                ),
                "http" => array(
                    "username" => $usernameac,
                    "password" => generateAuthStr()
                ),
                "shadowsocks" => array(
                    "name" => $usernameac,
                    "password" => $password
                ),
                "shadowsocks16" => array(
                    "name" => $usernameac,
                    "password" => $password
                ),
                "shadowtls" => array(
                    "name" => $usernameac,
                    "password" => $password
                ),
                "vmess" => array(
                    "name" => $usernameac,
                    "uuid" => generateUUID(),
                    "alterId" => 0
                ),
                "vless" => array(
                    "name" => $usernameac,
                    "uuid" => generateUUID(),
                    "flow" => ""
                ),
                "trojan" => array(
                    "name" => $usernameac,
                    "password" => generateAuthStr()
                ),
                "naive" => array(
                    "username" => $usernameac,
                    "password" => generateAuthStr()
                ),
                "hysteria" => array(
                    "name" => $usernameac,
                    "auth_str" => generateAuthStr()
                ),
                "tuic" => array(
                    "name" => $usernameac,
                    "uuid" => generateUUID(),
                    "password" => generateAuthStr()
                ),
                "hysteria2" => array(
                    "name" => $usernameac,
                    "password" => generateAuthStr()
                )
            ),
            "inbounds" => $inboundid,
            "links" => [],
            "volume" => $Total,
            "expiry" => $Expire,
            "desc" => $note
        )),
    );
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => rtrim($marzban_list_get['url_panel'], '/') . '/apiv2/save',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $configpanel,
        CURLOPT_HTTPHEADER => array(
            'Token: ' . $marzban_list_get['password_panel']
        ),
    ));
    return suiCurlJson($curl);
}
function updateClientS_ui($namepanel, array $config)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => rtrim($marzban_list_get['url_panel'], '/') . '/apiv2/save',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $config,
        CURLOPT_HTTPHEADER => array(
            'Token: ' . $marzban_list_get['password_panel']
        ),
    ));

    return suiCurlJson($curl);
}
function ResetUserDataUsages_ui($usernamepanel, $namepanel)
{
    $clients = GetClientsS_UI($usernamepanel, $namepanel);
    if (!is_array($clients) || empty($clients['id'])) {
        return array(
            'success' => false,
            'msg' => 'client not found'
        );
    }
    $configpanel = array(
        "object" => 'clients',
        'action' => "edit",
        "data" => json_encode(array(
            "id" => $clients['id'],
            "enable" => $clients['enable'],
            "name" => $clients['name'],
            "config" => $clients['config'],
            "inbounds" => $clients['inbounds'],
            "links" => $clients['links'],
            "volume" => $clients['volume'],
            "expiry" => $clients['expiry'],
            "desc" => $clients['desc'],
            "up" => 0,
            "down" => 0
        )),
    );
    $result = updateClientS_ui($namepanel, $configpanel);
    return $result;
}
function removeClientS_ui($location, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location, "select");
    $data_user = GetClientsS_UI($username, $location);
    $curl = curl_init();
    if (!is_array($data_user) || empty($data_user['id'])) {
        return array(
            'success' => false,
            'msg' => 'client not found'
        );
    }
    $configpanel = array(
        "object" => 'clients',
        'action' => "del",
        "data" => json_encode(array('id' => (int) $data_user['id'])),
    );
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => rtrim($marzban_list_get['url_panel'], '/') . '/apiv2/save',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $configpanel,
        CURLOPT_HTTPHEADER => array(
            'Token: ' . $marzban_list_get['password_panel']
        ),
    ));

    return suiCurlJson($curl);
}
function get_onlineclients_ui($name_panel, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => rtrim($marzban_list_get['url_panel'], '/') . '/apiv2/onlines',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Token: ' . $marzban_list_get['password_panel']
        ),
    ));
    $decoded = suiCurlJson($curl);
    if ($decoded === [])
        return "offline";
    $response = $decoded['obj']['user'] ?? null;
    if (!is_array($response))
        return "offline";
    if (in_array($username, $response))
        return "online";
    return "offline";

}
function get_settig($name_panel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => rtrim($marzban_list_get['url_panel'], '/') . '/apiv2/settings',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => ($GLOBALS['request_exec_timeout'] ?? null) ?: 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Token: ' . $marzban_list_get['password_panel']
        ),
    ));
    $decoded = suiCurlJson($curl);
    if ($decoded === [])
        return [];
    $response = $decoded['obj'] ?? null;
    if (!is_array($response))
        return [];
    return $response;

}
