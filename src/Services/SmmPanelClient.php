<?php

declare(strict_types=1);

/**
 * Minimal client for the common SMM-panel POST contract.
 *
 * Supported actions:
 * - services
 * - add
 * - status / multi-status
 * - refill / refill status
 * - cancel
 * - balance
 *
 * API keys are sent only in the request body. HTTPS is required for generic
 * providers. TivaNovin is allowed to use its documented legacy HTTP endpoint
 * on the exact tivanovin.ir host only.
 */
final class SmmPanelClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $providerKey;
    private string $transport;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $providerKey = 'generic',
        string $transport = 'post'
    ) {
        $this->providerKey = strtolower(trim($providerKey));
        $this->baseUrl = self::normaliseBaseUrl($baseUrl);
        $this->apiKey = trim($apiKey);
        $this->transport = strtolower(trim($transport));

        if (!in_array($this->transport, ['post', 'get'], true)) {
            throw new InvalidArgumentException('Unsupported SMM transport.');
        }

        if ($this->apiKey === '' || strlen($this->apiKey) > 2048 || preg_match('/[\r\n]/', $this->apiKey)) {
            throw new InvalidArgumentException('SMM API key is missing or invalid.');
        }
        if (!$this->isAllowedEndpoint($this->baseUrl)) {
            throw new InvalidArgumentException('SMM API endpoint is not allowed.');
        }
    }

    public function services(): array
    {
        $response = $this->request(['action' => 'services']);
        if (empty($response['ok'])) {
            return $response;
        }

        $data = $response['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return [
                'ok' => false,
                'http_status' => (int) ($response['http_status'] ?? 0),
                'message' => 'SMM provider returned an empty or invalid service list.',
                'data' => $data,
            ];
        }

        return $response;
    }

    public function balance(): array
    {
        $response = $this->request(['action' => 'balance']);
        if (empty($response['ok'])) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $balance = $data['balance'] ?? null;
        if (!is_numeric($balance)) {
            return [
                'ok' => false,
                'http_status' => (int) ($response['http_status'] ?? 0),
                'message' => trim((string) ($data['error'] ?? $data['message'] ?? 'Provider balance is missing.')),
                'data' => $data,
            ];
        }

        return [
            'ok' => true,
            'http_status' => (int) ($response['http_status'] ?? 0),
            'balance' => (float) $balance,
            'currency' => strtoupper(trim((string) ($data['currency'] ?? ''))),
            'data' => $data,
        ];
    }

    public function addOrder(
        string $service,
        string $link,
        int $quantity,
        ?int $runs = null,
        ?int $interval = null
    ): array {
        $service = trim($service);
        $link = trim($link);
        $quantity = max(1, $quantity);

        if ($service === '' || $link === '') {
            return ['ok' => false, 'message' => 'Service code and target are required.'];
        }

        $payload = [
            'action' => 'add',
            'service' => $service,
            'link' => $link,
            'quantity' => $quantity,
        ];
        if ($runs !== null && $runs > 0) {
            $payload['runs'] = $runs;
        }
        if ($interval !== null && $interval > 0) {
            $payload['interval'] = $interval;
        }

        $response = $this->request($payload);
        if (empty($response['ok'])) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $reference = '';
        foreach (['order', 'order_id', 'orderId', 'id'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $reference = trim((string) $data[$key]);
                if ($reference !== '') {
                    break;
                }
            }
        }

        if ($reference === '') {
            return [
                'ok' => false,
                'http_status' => (int) ($response['http_status'] ?? 0),
                'message' => trim((string) ($data['error'] ?? $data['message'] ?? 'Provider did not return an order id.')),
                'data' => $data,
            ];
        }

        return [
            'ok' => true,
            'http_status' => (int) ($response['http_status'] ?? 0),
            'reference' => $reference,
            'data' => $data,
        ];
    }

    public function status(string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return ['ok' => false, 'message' => 'Provider order id is required.'];
        }

        $response = $this->request([
            'action' => 'status',
            'order' => $orderId,
        ]);
        if (empty($response['ok'])) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rawStatus = trim((string) ($data['status'] ?? ''));
        if ($rawStatus === '') {
            return [
                'ok' => false,
                'http_status' => (int) ($response['http_status'] ?? 0),
                'message' => trim((string) ($data['error'] ?? $data['message'] ?? 'Provider order status is missing.')),
                'data' => $data,
            ];
        }

        return [
            'ok' => true,
            'http_status' => (int) ($response['http_status'] ?? 0),
            'status' => self::normaliseStatus($rawStatus),
            'raw_status' => $rawStatus,
            'charge' => isset($data['charge']) && is_numeric($data['charge']) ? (float) $data['charge'] : null,
            'start_count' => isset($data['start_count']) && is_numeric($data['start_count']) ? (int) $data['start_count'] : null,
            'remains' => isset($data['remains']) && is_numeric($data['remains']) ? (int) $data['remains'] : null,
            'data' => $data,
        ];
    }

    public function statuses(array $orderIds): array
    {
        $ids = [];
        foreach ($orderIds as $orderId) {
            $id = trim((string) $orderId);
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id)) {
                $ids[] = $id;
            }
            if (count($ids) >= 100) {
                break;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return ['ok' => false, 'message' => 'At least one provider order id is required.'];
        }

        return $this->request([
            'action' => 'status',
            'orders' => implode(',', $ids),
        ]);
    }

    public function statusesNormalized(array $orderIds): array
    {
        $response = $this->statuses($orderIds);
        if (empty($response['ok'])) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $orders = [];
        foreach ($data as $key => $value) {
            $reference = trim((string) $key);
            if (is_array($value)) {
                $reference = trim((string) ($value['order'] ?? $reference));
                $rawStatus = trim((string) ($value['status'] ?? ''));
                if ($reference === '' || $rawStatus === '') {
                    continue;
                }
                $orders[$reference] = [
                    'ok' => true,
                    'reference' => $reference,
                    'status' => self::normaliseStatus($rawStatus),
                    'raw_status' => $rawStatus,
                    'charge' => isset($value['charge']) && is_numeric($value['charge']) ? (float) $value['charge'] : null,
                    'start_count' => isset($value['start_count']) && is_numeric($value['start_count']) ? (int) $value['start_count'] : null,
                    'remains' => isset($value['remains']) && is_numeric($value['remains']) ? (int) $value['remains'] : null,
                    'data' => $value,
                ];
                continue;
            }

            if ($reference !== '') {
                $orders[$reference] = [
                    'ok' => false,
                    'reference' => $reference,
                    'message' => is_scalar($value) ? trim((string) $value) : 'Invalid provider status response.',
                ];
            }
        }

        if ($orders === []) {
            return [
                'ok' => false,
                'http_status' => (int) ($response['http_status'] ?? 0),
                'message' => 'Provider multi-status response could not be normalized.',
                'data' => $data,
            ];
        }

        return [
            'ok' => true,
            'http_status' => (int) ($response['http_status'] ?? 0),
            'orders' => $orders,
            'data' => $data,
        ];
    }

    public function refill(string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return ['ok' => false, 'message' => 'Provider order id is required.'];
        }

        $response = $this->request([
            'action' => 'refill',
            'order' => $orderId,
        ]);
        if (empty($response['ok'])) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $refillId = isset($data['refill']) && is_scalar($data['refill'])
            ? trim((string) $data['refill'])
            : '';
        if ($refillId === '') {
            return [
                'ok' => false,
                'http_status' => (int) ($response['http_status'] ?? 0),
                'message' => trim((string) ($data['error'] ?? $data['message'] ?? 'Provider did not return a refill id.')),
                'data' => $data,
            ];
        }

        return [
            'ok' => true,
            'http_status' => (int) ($response['http_status'] ?? 0),
            'refill' => $refillId,
            'data' => $data,
        ];
    }

    public function refillStatus(string $refillId): array
    {
        $refillId = trim($refillId);
        if ($refillId === '') {
            return ['ok' => false, 'message' => 'Provider refill id is required.'];
        }

        $response = $this->request([
            'action' => 'refill_status',
            'refill' => $refillId,
        ]);
        if (empty($response['ok'])) {
            return $response;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $status = trim((string) ($data['status'] ?? ''));
        if ($status === '') {
            return [
                'ok' => false,
                'http_status' => (int) ($response['http_status'] ?? 0),
                'message' => trim((string) ($data['error'] ?? $data['message'] ?? 'Provider refill status is missing.')),
                'data' => $data,
            ];
        }

        return [
            'ok' => true,
            'http_status' => (int) ($response['http_status'] ?? 0),
            'status' => self::normaliseStatus($status),
            'raw_status' => $status,
            'data' => $data,
        ];
    }

    public function cancel(array $orderIds): array
    {
        $ids = [];
        foreach ($orderIds as $orderId) {
            $id = trim((string) $orderId);
            if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id)) {
                $ids[] = $id;
            }
            if (count($ids) >= 100) {
                break;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return ['ok' => false, 'message' => 'At least one provider order id is required.'];
        }

        return $this->request([
            'action' => 'cancel',
            'orders' => implode(',', $ids),
        ]);
    }

    public static function normaliseStatus(string $status): string
    {
        $value = strtolower(trim($status));
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;

        if (in_array($value, ['completed', 'complete', 'success', 'succeeded', 'delivered'], true)) {
            return 'completed';
        }
        if (in_array($value, ['canceled', 'cancelled', 'failed', 'error', 'rejected', 'refunded'], true)) {
            return 'failed';
        }
        if (in_array($value, ['partial', 'partially completed'], true)) {
            return 'partial';
        }
        return 'pending';
    }

    private function request(array $payload): array
    {
        $payload = ['key' => $this->apiKey] + $payload;
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $requestUrl = $this->transport === 'get'
            ? $this->baseUrl . (str_contains($this->baseUrl, '?') ? '&' : '?') . $body
            : $this->baseUrl;

        $ch = curl_init($requestUrl);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Unable to initialise SMM request.'];
        }

        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
        $protocol = $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS;

        $headers = ['Accept: application/json'];
        if ($this->transport === 'post') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => $protocol,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'BlueBot/0.5.36 SmmPanelClient',
        ];
        if ($this->transport === 'post') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'http_status' => $http,
                'retryable' => true,
                'message' => $error !== '' ? $error : 'SMM provider request failed.',
            ];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'http_status' => $http,
                'retryable' => $http === 0 || $http >= 500,
                'message' => 'SMM provider returned invalid JSON.',
                'raw' => mb_substr((string) $raw, 0, 2000, 'UTF-8'),
            ];
        }

        $errorText = trim((string) ($decoded['error'] ?? ''));
        if ($errorText === '' && isset($decoded['status']) && strtolower(trim((string) $decoded['status'])) === 'error') {
            $errorText = trim((string) ($decoded['message'] ?? 'Provider returned an error status.'));
        }

        $ok = $http >= 200 && $http < 300 && $errorText === '';
        return [
            'ok' => $ok,
            'http_status' => $http,
            'retryable' => !$ok && ($http === 0 || $http >= 500),
            'message' => $ok ? '' : ($errorText !== '' ? $errorText : trim((string) ($decoded['message'] ?? ('Provider returned HTTP ' . $http)))),
            'data' => $decoded,
        ];
    }

    private function isAllowedEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($scheme === 'http') {
            if ($this->providerKey !== 'tivanovin') {
                return false;
            }
            if (!in_array($host, ['tivanovin.ir', 'www.tivanovin.ir'], true)) {
                return false;
            }
            if ($port !== null && $port !== 80) {
                return false;
            }
            $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
            if (rtrim($path, '/') !== '/api') {
                return false;
            }
        } elseif ($scheme === 'https') {
            if ($port !== null && $port !== 443) {
                return false;
            }
        } else {
            return false;
        }

        return self::isPublicHost($host);
    }

    private static function isPublicHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        $ips = gethostbynamel($host);
        if (is_array($ips) && $ips !== []) {
            foreach ($ips as $ip) {
                if (!filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function normaliseBaseUrl(string $url): string
    {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('SMM API endpoint is invalid.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('SMM API endpoint is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        return $scheme . '://' . $host . $port . $path;
    }
}
