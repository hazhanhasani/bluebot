<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request.php';

function solidlayerPanel(string $location): ?array
{
    $panel = select('marzban_panel', '*', 'name_panel', $location, 'select');

    return is_array($panel) ? $panel : null;
}

function solidlayerRequest(string $location, string $method, string $path, ?array $payload = null, array $query = []): array
{
    $panel = solidlayerPanel($location);
    if ($panel === null) {
        return [
            'status' => null,
            'body' => null,
            'error' => 'Panel not found.',
        ];
    }

    $baseUrl = rtrim((string) ($panel['url_panel'] ?? ''), '/');
    $apiKey = trim((string) ($panel['password_panel'] ?? ''));

    if ($baseUrl === '' || $apiKey === '') {
        return [
            'status' => null,
            'body' => null,
            'error' => 'SolidLayer panel URL or API key is missing.',
        ];
    }

    $url = $baseUrl . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $request = new CurlRequest($url);
    $request->setHeaders([
        'Accept: application/json',
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
    ]);

    $json = $payload === null
        ? null
        : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload !== null && $json === false) {
        return [
            'status' => null,
            'body' => null,
            'error' => 'Unable to encode SolidLayer request.',
        ];
    }

    switch (strtoupper($method)) {
        case 'GET':
            return $request->get();
        case 'POST':
            return $request->post($json ?? '{}');
        case 'PUT':
            return $request->put($json ?? '{}');
        case 'DELETE':
            return $request->delete($json);
        default:
            return [
                'status' => null,
                'body' => null,
                'error' => 'Unsupported SolidLayer HTTP method.',
            ];
    }
}

function solidlayerDecode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);

    return is_array($decoded) ? $decoded : [];
}

function solidlayerError(array $response, string $fallback = 'SolidLayer request failed.'): ?string
{
    if (!empty($response['error'])) {
        return (string) $response['error'];
    }

    $status = (int) ($response['status'] ?? 0);
    if ($status >= 200 && $status < 300) {
        return null;
    }

    $body = solidlayerDecode($response);
    if (!empty($body['message'])) {
        return (string) $body['message'];
    }

    if (!empty($body['detail'])) {
        return is_string($body['detail']) ? $body['detail'] : json_encode($body['detail']);
    }

    if (!empty($body['errors']) && is_array($body['errors'])) {
        $messages = [];
        foreach ($body['errors'] as $error) {
            if (is_array($error) && !empty($error['message'])) {
                $messages[] = (string) $error['message'];
            }
        }
        if ($messages !== []) {
            return implode('; ', $messages);
        }
    }

    return $status > 0 ? $fallback . ' HTTP ' . $status : $fallback;
}

function solidlayerNormalizeServiceIds($value): array
{
    if ($value === null || $value === '' || $value === false) {
        return [];
    }

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        } else {
            $value = preg_split('/[\s,;|]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
    }

    if (!is_array($value)) {
        $value = [$value];
    }

    $ids = [];
    foreach ($value as $item) {
        if (is_array($item)) {
            $item = $item['id'] ?? null;
        }

        if (is_numeric($item) && (int) $item > 0) {
            $ids[] = (int) $item;
        }
    }

    return array_values(array_unique($ids));
}

function solidlayerGetServices(string $location): array
{
    $response = solidlayerRequest($location, 'GET', '/api/services', null, [
        'page' => 1,
        'limit' => 100,
        'sort_by' => 'id',
        'sort_dir' => 'asc',
    ]);

    if (solidlayerError($response) !== null) {
        return [];
    }

    $body = solidlayerDecode($response);
    $items = $body['items'] ?? [];

    return is_array($items) ? $items : [];
}

function solidlayerResolveServiceIds(string $location, $configured = null): array
{
    $ids = solidlayerNormalizeServiceIds($configured);
    if ($ids !== []) {
        return $ids;
    }

    $panel = solidlayerPanel($location);
    if ($panel !== null) {
        $ids = solidlayerNormalizeServiceIds($panel['inbounds'] ?? null);
        if ($ids !== []) {
            return $ids;
        }
    }

    $ids = [];
    foreach (solidlayerGetServices($location) as $service) {
        if (is_array($service) && !empty($service['id'])) {
            $ids[] = (int) $service['id'];
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

function solidlayerGetSubscription(string $location, string $username): array
{
    $response = solidlayerRequest($location, 'GET', '/api/subscriptions', null, [
        'page' => 1,
        'limit' => 10,
        'usernames' => $username,
    ]);

    $error = solidlayerError($response, 'Unable to get SolidLayer subscription.');
    if ($error !== null) {
        return [
            'ok' => false,
            'error' => $error,
            'response' => $response,
        ];
    }

    $body = solidlayerDecode($response);
    $items = $body['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }

    foreach ($items as $item) {
        if (is_array($item) && (string) ($item['username'] ?? '') === $username) {
            return [
                'ok' => true,
                'data' => $item,
                'response' => $response,
            ];
        }
    }

    return [
        'ok' => false,
        'error' => 'Subscription not found.',
        'response' => $response,
    ];
}

function solidlayerGetSubscriptionLinks(string $location, string $username): array
{
    $response = solidlayerRequest(
        $location,
        'GET',
        '/api/subscriptions/' . rawurlencode($username) . '/links'
    );

    if (solidlayerError($response) !== null) {
        return [];
    }

    $body = solidlayerDecode($response);
    $links = [];

    foreach (($body['links'] ?? []) as $item) {
        if (is_array($item) && !empty($item['link'])) {
            $links[] = (string) $item['link'];
        }
    }

    return array_values(array_unique($links));
}

function solidlayerAbsoluteSubscriptionUrl(string $location, string $url): string
{
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    $panel = solidlayerPanel($location);
    $baseUrl = rtrim((string) ($panel['url_panel'] ?? ''), '/');

    return $baseUrl === '' ? $url : $baseUrl . '/' . ltrim($url, '/');
}

function solidlayerCreateSubscription(
    string $location,
    string $username,
    int $limitUsage,
    int $limitExpire,
    string $note = '',
    $configuredServices = null
): array {
    $payload = [
        'username' => $username,
        'limit_usage' => max(0, $limitUsage),
        'limit_expire' => max(0, $limitExpire),
    ];

    if ($note !== '') {
        $payload['note'] = $note;
    }

    $services = solidlayerResolveServiceIds($location, $configuredServices);
    if ($services !== []) {
        $payload['services'] = $services;
    }

    $response = solidlayerRequest($location, 'POST', '/api/subscriptions', [$payload]);
    $error = solidlayerError($response, 'Unable to create SolidLayer subscription.');

    if ($error !== null) {
        return [
            'ok' => false,
            'error' => $error,
            'response' => $response,
        ];
    }

    $body = solidlayerDecode($response);
    if (isset($body[0]) && is_array($body[0])) {
        $body = $body[0];
    }

    if (!is_array($body) || empty($body['username'])) {
        $lookup = solidlayerGetSubscription($location, $username);
        if (!empty($lookup['ok'])) {
            $body = $lookup['data'];
        }
    }

    if (!is_array($body) || empty($body['username'])) {
        return [
            'ok' => false,
            'error' => 'SolidLayer created the subscription but returned no subscription data.',
            'response' => $response,
        ];
    }

    return [
        'ok' => true,
        'data' => $body,
        'response' => $response,
    ];
}

function solidlayerMapUpdateFields(array $config): array
{
    $mapped = [];

    $aliases = [
        'data_limit' => 'limit_usage',
        'expire' => 'limit_expire',
        'inbounds' => 'services',
    ];

    foreach ($aliases as $source => $target) {
        if (array_key_exists($source, $config)) {
            $config[$target] = $config[$source];
        }
    }

    $allowed = [
        'access_key',
        'email',
        'enabled',
        'expiry_grace_days',
        'limit_expire',
        'limit_usage',
        'limited_grace_days',
        'note',
        'on_hold_timeout_days',
        'phone',
        'services',
        'telegram_id',
        'username',
    ];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $config)) {
            continue;
        }

        $value = $config[$field];
        if ($field === 'services') {
            $value = solidlayerNormalizeServiceIds($value);
            if ($value === []) {
                continue;
            }
        } elseif (in_array($field, ['limit_expire', 'limit_usage'], true)) {
            $value = max(0, (int) $value);
        }

        $mapped[$field] = $value;
    }

    return $mapped;
}

function solidlayerUpdateSubscription(string $location, string $username, array $config): array
{
    $fields = solidlayerMapUpdateFields($config);
    if ($fields === []) {
        return [
            'ok' => true,
            'data' => null,
        ];
    }

    $payload = array_merge([
        'usernames' => [$username],
    ], $fields);

    $response = solidlayerRequest($location, 'PUT', '/api/subscriptions', $payload);
    $error = solidlayerError($response, 'Unable to update SolidLayer subscription.');

    if ($error !== null) {
        return [
            'ok' => false,
            'error' => $error,
            'response' => $response,
        ];
    }

    return [
        'ok' => true,
        'data' => solidlayerDecode($response),
        'response' => $response,
    ];
}

function solidlayerSubscriptionAction(string $location, string $action, string $username): array
{
    $allowed = ['reset', 'revoke', 'enable', 'disable'];
    if (!in_array($action, $allowed, true)) {
        return [
            'ok' => false,
            'error' => 'Unsupported SolidLayer subscription action.',
        ];
    }

    $response = solidlayerRequest(
        $location,
        'POST',
        '/api/subscriptions/' . $action,
        ['usernames' => [$username]]
    );

    $error = solidlayerError($response, 'SolidLayer action failed.');
    if ($error !== null) {
        return [
            'ok' => false,
            'error' => $error,
            'response' => $response,
        ];
    }

    return [
        'ok' => true,
        'data' => solidlayerDecode($response),
        'response' => $response,
    ];
}

function solidlayerDeleteSubscription(string $location, string $username): array
{
    $response = solidlayerRequest(
        $location,
        'DELETE',
        '/api/subscriptions',
        ['usernames' => [$username]]
    );

    $error = solidlayerError($response, 'Unable to delete SolidLayer subscription.');
    if ($error !== null) {
        return [
            'ok' => false,
            'error' => $error,
            'response' => $response,
        ];
    }

    return [
        'ok' => true,
        'data' => solidlayerDecode($response),
        'response' => $response,
    ];
}

function solidlayerSubscriptionStatus(array $subscription): string
{
    if (array_key_exists('enabled', $subscription) && !$subscription['enabled']) {
        return 'disabled';
    }

    if (!empty($subscription['on_hold_since'])
        || (array_key_exists('activated', $subscription) && !$subscription['activated'])) {
        return 'on_hold';
    }

    $expire = (int) ($subscription['limit_expire'] ?? 0);
    if ($expire > 0 && $expire <= time()) {
        return 'expired';
    }

    $limit = (int) ($subscription['limit_usage'] ?? 0);
    $used = max(
        0,
        (int) ($subscription['total_usage'] ?? 0) - (int) ($subscription['reset_usage'] ?? 0)
    );
    if ($limit > 0 && $used >= $limit) {
        return 'limited';
    }

    return 'active';
}

function solidlayerBluebotUser(string $location, string $username): array
{
    $result = solidlayerGetSubscription($location, $username);
    if (empty($result['ok'])) {
        return [
            'status' => 'Unsuccessful',
            'msg' => $result['error'] ?? 'Subscription not found.',
        ];
    }

    $subscription = $result['data'];
    $links = solidlayerGetSubscriptionLinks($location, $username);
    $subscriptionUrl = solidlayerAbsoluteSubscriptionUrl(
        $location,
        (string) ($subscription['subscription_link'] ?? '')
    );

    $usedTraffic = max(
        0,
        (int) ($subscription['total_usage'] ?? 0) - (int) ($subscription['reset_usage'] ?? 0)
    );

    $onlineAt = 0;
    if (!empty($subscription['last_online_at'])) {
        $parsed = strtotime((string) $subscription['last_online_at']);
        $onlineAt = $parsed === false ? 0 : $parsed;
    }

    return [
        'status' => solidlayerSubscriptionStatus($subscription),
        'username' => (string) ($subscription['username'] ?? $username),
        'data_limit' => (int) ($subscription['limit_usage'] ?? 0),
        'expire' => (int) ($subscription['limit_expire'] ?? 0),
        'online_at' => $onlineAt,
        'used_traffic' => $usedTraffic,
        'links' => $links,
        'subscription_url' => $subscriptionUrl,
        'sub_updated_at' => (string) ($subscription['updated_at'] ?? ''),
        'sub_last_user_agent' => (string) ($subscription['last_client_agent'] ?? ''),
        'uuid' => null,
        'data_limit_reset' => 'no_reset',
    ];
}
