<?php

declare(strict_types=1);

/**
 * Minimal client for the common SMM-panel POST contract.
 *
 * Supported actions:
 * - services
 * - add
 * - status
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

    public function __construct(string $baseUrl, string $apiKey, string $providerKey = 'generic')
    {
        $this->providerKey = strtolower(trim($providerKey));
        $this->baseUrl = self::normaliseBaseUrl($baseUrl);
        $this->apiKey = trim($apiKey);

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

    public function addOrder(string $service, string $link, int $quantity): array
    {
        $service = trim($service);
        $link = trim($link);
        $quantity = max(1, $quantity);

        if ($service === '' || $link === '') {
            return ['ok' => false, 'message' => 'Service code and target are required.'];
        }

        $response = $this->request([
            'action' => 'add',
            'service' => $service,
            'link' => $link,
            'quantity' => $quantity,
        ]);
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

        $ch = curl_init($this->baseUrl);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Unable to initialise SMM request.'];
        }

        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
        $protocol = $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS;

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => $protocol,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'BlueBot/0.5.36 SmmPanelClient',
        ]);

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
