<?php
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/request.php';

function hiddifyIsSuccessfulResponse(array $response): bool
{
    $status = (int) ($response['status'] ?? 0);

    return $status >= 200 && $status < 300;
}

function hiddifyDecodeResponse(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);

    return is_array($decoded) ? $decoded : [];
}

function hiddifyResponseError(array $response, string $fallback = 'Hiddify API request failed'): string
{
    if (!empty($response['error'])) {
        return (string) $response['error'];
    }

    $decoded = hiddifyDecodeResponse($response);
    foreach (['message', 'error'] as $key) {
        if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
            return trim((string) $decoded[$key]);
        }
    }

    if (isset($decoded['detail'])) {
        if (is_scalar($decoded['detail'])) {
            return trim((string) $decoded['detail']);
        }

        if (is_array($decoded['detail'])) {
            $messages = [];
            array_walk_recursive($decoded['detail'], static function ($value) use (&$messages): void {
                if (is_scalar($value)) {
                    $message = trim((string) $value);
                    if ($message !== '' && !in_array($message, $messages, true)) {
                        $messages[] = $message;
                    }
                }
            });
            if ($messages !== []) {
                return implode(' | ', array_slice($messages, 0, 4));
            }
        }
    }

    $status = (int) ($response['status'] ?? 0);

    return $status > 0 ? $fallback . ' (HTTP ' . $status . ')' : $fallback;
}

function hiddifyRequest(string $location, string $method, string $path, ?array $payload = null): array
{
    $panel = select("marzban_panel", "*", "name_panel", $location, "select");
    if (!is_array($panel) || empty($panel['url_panel']) || empty($panel['secret_code'])) {
        return ['status' => null, 'body' => null, 'error' => 'Hiddify panel configuration is incomplete'];
    }

    $url = rtrim((string) $panel['url_panel'], '/') . '/' . ltrim($path, '/');
    $json = null;
    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [
                'status' => null,
                'body' => null,
                'error' => 'Unable to encode Hiddify request payload: ' . json_last_error_msg(),
            ];
        }
    }

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

    // Hiddify Manager v13 keeps the v2 admin API and authenticates using the
    // admin UUID in Hiddify-API-Key.
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Hiddify-API-Key: ' . $panel['secret_code'],
    ];
    $response = $send($headers);

    // Preserve compatibility with older Hiddify installations that accepted
    // the admin UUID through HTTP Basic authentication.
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
        return ['message' => hiddifyResponseError($response)];
    }

    if (!hiddifyIsSuccessfulResponse($response)) {
        return ['message' => hiddifyResponseError($response)];
    }

    $data = hiddifyDecodeResponse($response);
    if ($data === []) {
        return null;
    }
    if (isset($data['message']) || isset($data['detail'])) {
        return ['message' => hiddifyResponseError($response)];
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

function resetuserusagehi($username, $location): array
{
    return updateuserhi((string) $username, (string) $location, [
        'current_usage_GB' => 0.0,
    ]);
}
