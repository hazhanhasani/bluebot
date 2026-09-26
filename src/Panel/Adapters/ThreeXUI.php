<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/request.php';
ini_set('error_log', 'error_log');

function xuiRequest(array $panel, string $method, string $path, ?array $payload = null): array
{
    $url = rtrim((string) $panel['url_panel'], '/') . '/' . ltrim($path, '/');
    $req = new CurlRequest($url);
    $req->setHeaders([
        'Accept: application/json',
        'Content-Type: application/json',
    ]);
    $req->setBearerToken((string) $panel['password_panel']);

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
            return ['status' => null, 'body' => null, 'error' => 'unsupported x-ui method'];
    }
}

function xuiResponseObject(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded)) {
        return [];
    }

    $obj = $decoded['obj'] ?? [];
    return is_array($obj) ? $obj : [];
}

function xuiInboundIds($value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : preg_split('/[\s,;|]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    if (!is_array($value)) {
        $value = [$value];
    }

    $ids = [];
    foreach ($value as $id) {
        if (is_numeric($id) && (int) $id > 0) {
            $ids[] = (int) $id;
        }
    }

    return array_values(array_unique($ids));
}

function get_clinets($username, $panel)
{
    return xuiRequest(
        $panel,
        'GET',
        '/panel/api/clients/get/' . rawurlencode((string) $username)
    );
}

function addClient($panel, $usernameac, $Expire, $subId, $Total, $inboundid, $name_product, $note = "")
{
    if ($name_product == "usertest") {
        if ($panel['on_hold_test'] == "1") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    } else {
        if ($panel['conecton'] == "onconecton") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    }

    $client = [
        "email" => (string) $usernameac,
        "totalGB" => (int) $Total,
        "expiryTime" => (int) $timeservice,
        "tgId" => 0,
        "comment" => (string) $note,
        "enable" => true,
        "subId" => (string) $subId
    ];

    return xuiRequest($panel, 'POST', '/panel/api/clients/add', [
        "inboundIds" => xuiInboundIds($inboundid),
        "client" => $client,
    ]);
}

function updateClient($panel, $email, array $config)
{
    // 3x-ui v3.8+ treats client updates as replacement, not PATCH.
    // Preserve fields BlueBot does not manage (limitIp, limitHwid, reset policy,
    // group, protocol credentials, etc.) by merging over the current record.
    $currentResponse = get_clinets($email, $panel);
    $currentObj = xuiResponseObject($currentResponse);

    if (isset($currentObj['client']) && is_array($currentObj['client'])) {
        $current = $currentObj['client'];
    } else {
        $current = $currentObj;
        unset($current['inboundIds'], $current['externalIds'], $current['traffic']);
    }

    if ($current === []) {
        // Keep backward compatibility with older 3x-ui builds which accepted
        // a partial update body.
        $current = ['email' => (string) $email];
    }

    $payload = array_replace($current, $config);
    $payload['email'] = (string) ($payload['email'] ?? $email);

    return xuiRequest(
        $panel,
        'POST',
        '/panel/api/clients/update/' . rawurlencode((string) $email),
        $payload
    );
}

function ResetUserDataUsagex_uisin($usernamepanel, $panel)
{
    return xuiRequest(
        $panel,
        'POST',
        '/panel/api/clients/resetTraffic/' . rawurlencode((string) $usernamepanel),
        []
    );
}

function removeClient($panel, $username)
{
    // keepTraffic is required by current 3x-ui OpenAPI. 0 keeps the historical
    // BlueBot behavior and removes the traffic record with the client.
    return xuiRequest(
        $panel,
        'POST',
        '/panel/api/clients/del/' . rawurlencode((string) $username) . '?keepTraffic=0',
        []
    );
}

function status_server_xui($panel)
{
    return xuiRequest($panel, 'GET', '/panel/api/server/status');
}

function attach_service($panel, $username, $configpanel)
{
    // Current 3x-ui expects {"inboundIds":[...]} JSON. Older BlueBot sent a
    // form-encoded bare array which newer releases reject.
    if (is_string($configpanel)) {
        $decoded = json_decode($configpanel, true);
        $configpanel = is_array($decoded) ? $decoded : $configpanel;
    }

    $ids = is_array($configpanel) && array_key_exists('inboundIds', $configpanel)
        ? xuiInboundIds($configpanel['inboundIds'])
        : xuiInboundIds($configpanel);

    return xuiRequest(
        $panel,
        'POST',
        '/panel/api/clients/' . rawurlencode((string) $username) . '/attach',
        ['inboundIds' => $ids]
    );
}

function used_data_3xui($panel, $username)
{
    return xuiRequest(
        $panel,
        'GET',
        '/panel/api/clients/traffic/' . rawurlencode((string) $username)
    );
}
