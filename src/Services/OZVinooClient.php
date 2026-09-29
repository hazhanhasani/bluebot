<?php

declare(strict_types=1);

final class OZVinooClient
{
    private const BASE_URL = 'https://api.ozvinoo.xyz';

    public function __construct(private readonly string $token)
    {
        $token = trim($this->token);
        if ($token === '' || strlen($token) > 2048 || preg_match('/[\r\n]/', $token)) {
            throw new InvalidArgumentException('Invalid OZVinoo API token.');
        }
    }

    public function balance(): array
    {
        return $this->request(
            'GET',
            '/web/' . rawurlencode(trim($this->token)) . '/get-balance',
            [],
            false
        );
    }

    public function stars(): array
    {
        return $this->request('GET', '/telegram-services/stars/');
    }

    public function buyStars(int $count, string $username): array
    {
        return $this->request('POST', '/telegram-services/stars/', [
            'count' => max(1, $count),
            'username' => $this->normaliseUsername($username),
        ]);
    }

    public function premium(): array
    {
        return $this->request('GET', '/telegram-services/premium/');
    }

    public function buyPremium(int $packageId, string $username): array
    {
        return $this->request('POST', '/telegram-services/premium/', [
            'id' => $packageId,
            'username' => $this->normaliseUsername($username),
        ]);
    }

    public function telegramOrderStatus(string $service, string|int $code): array
    {
        $service = strtolower(trim($service));
        if (!in_array($service, ['stars', 'premium'], true)) {
            throw new InvalidArgumentException('Unsupported OZVinoo Telegram service.');
        }

        return $this->request('GET', '/telegram-services/status/', [
            'service' => $service,
            'code' => $code,
        ]);
    }

    public function countries(bool $noneReport = true): array
    {
        return $this->request('GET', '/telegram-numbers/numbers/', [
            'none_report' => $noneReport,
        ]);
    }

    public function buyNumber(int|string $countryId, bool $noneReport = true): array
    {
        return $this->request('POST', '/telegram-numbers/numbers/', [
            'country_id' => (string) $countryId,
            'none_report' => $noneReport,
        ]);
    }

    public function numberStatus(int|string $orderId): array
    {
        return $this->request('GET', '/telegram-numbers/number-services/', [
            'order_id' => $orderId,
        ]);
    }

    public function logoutNumber(int|string $orderId): array
    {
        return $this->request('POST', '/telegram-numbers/number-services/', [
            'order_id' => $orderId,
        ]);
    }

    public function allOrders(): array
    {
        return $this->request('GET', '/numbers/getAllOrders/');
    }

    public function openOrders(): array
    {
        return $this->request('GET', '/numbers/getOpenOrders/');
    }

    public function order(int|string $orderId): array
    {
        return $this->request('GET', '/numbers/getOrder/', [
            'order_id' => $orderId,
        ]);
    }

    public function cancelOrder(int|string $orderId): array
    {
        return $this->request('POST', '/numbers/cancelOrder/', [
            'order_id' => $orderId,
        ]);
    }

    private function normaliseUsername(string $username): string
    {
        $username = ltrim(trim($username), '@');
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
            throw new InvalidArgumentException('Invalid Telegram username.');
        }

        return '@' . $username;
    }

    private function request(string $method, string $path, array $payload = [], bool $bearer = true): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('Unsupported HTTP method.');
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $url = self::BASE_URL . $path;
        if ($method === 'GET' && $payload !== []) {
            $query = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
            if ($query !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $query;
            }
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . trim($this->token);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'body' => [],
                'message' => 'Unable to initialise OZVinoo request.',
            ];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'BlueBot/OZVinoo-Official',
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } elseif ($payload !== []) {
            // OZVinoo documents JSON bodies on selected GET endpoints.
            // Query params are also sent above so the client works through
            // proxies that drop GET request bodies.
            $options[CURLOPT_CUSTOMREQUEST] = 'GET';
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
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
                'body' => [],
                'message' => $error !== '' ? $error : 'OZVinoo request failed.',
            ];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'http_status' => $http,
                'body' => [],
                'raw' => mb_substr((string) $raw, 0, 2000, 'UTF-8'),
                'message' => 'OZVinoo returned invalid JSON.',
            ];
        }

        $httpOk = $http >= 200 && $http < 300;
        $apiOk = !array_key_exists('status', $decoded) || (bool) $decoded['status'];
        if ($http === 202) {
            $apiOk = true;
        }

        $message = trim((string) ($decoded['message'] ?? $decoded['error'] ?? ''));
        if ($message === '' && !$httpOk) {
            $message = 'OZVinoo returned HTTP ' . $http . '.';
        }

        return [
            'ok' => $httpOk && $apiOk,
            'http_status' => $http,
            'body' => $decoded,
            'message' => $message,
        ];
    }
}
