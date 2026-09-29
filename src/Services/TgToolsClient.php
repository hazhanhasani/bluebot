<?php

declare(strict_types=1);

final class TgToolsClient
{
    private const BASE_URL = 'https://api.tg-tools.shop';

    public function __construct(private readonly string $apiKey)
    {
    }

    public function prices(): array
    {
        return $this->request('GET', '/api/purchase/prices', null, false);
    }

    public function lookupUser(string $username): array
    {
        $username = ltrim(trim($username), '@');
        return $this->request('GET', '/api/purchase/user/' . rawurlencode($username), null, false);
    }

    public function purchaseStars(string $username, int $amount, string $trackingCode): array
    {
        return $this->request('POST', '/api/purchase/stars', [
            'recipientUsername' => ltrim(trim($username), '@'),
            'amount' => $amount,
            'paymentMethod' => 'ton',
            'trackingCode' => $trackingCode,
        ]);
    }

    public function purchasePremium(string $username, int $months, string $trackingCode): array
    {
        return $this->request('POST', '/api/purchase/premium', [
            'recipientUsername' => ltrim(trim($username), '@'),
            'months' => $months,
            'paymentMethod' => 'ton',
            'trackingCode' => $trackingCode,
        ]);
    }

    public function purchaseStatus(int $transactionId): array
    {
        return $this->request('GET', '/api/transaction/' . $transactionId);
    }

    public function wallet(): array
    {
        return $this->request('GET', '/api/wallet');
    }

    private function request(string $method, string $path, ?array $payload = null, bool $auth = true): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('Unsupported TGTools HTTP method.');
        }

        $url = self::BASE_URL . '/' . ltrim($path, '/');
        if (!str_starts_with($url, self::BASE_URL . '/')) {
            throw new RuntimeException('TGTools endpoint is outside the allowed host.');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($auth) {
            $apiKey = trim($this->apiKey);
            if ($apiKey === '') {
                return [
                    'ok' => false,
                    'http_status' => 0,
                    'data' => [],
                    'message' => 'TGTools API key is not configured.',
                ];
            }
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'http_status' => 0,
                'data' => [],
                'message' => 'Unable to initialize TGTools request.',
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
            CURLOPT_USERAGENT => 'BlueBot/0.5.28 TGToolsClient',
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $encoded = json_encode($payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                curl_close($ch);
                return [
                    'ok' => false,
                    'http_status' => 0,
                    'data' => [],
                    'message' => 'Unable to encode TGTools request payload.',
                ];
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'http_status' => $http,
                'data' => [],
                'message' => $curlError !== '' ? $curlError : 'TGTools request failed.',
            ];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'http_status' => $http,
                'data' => [],
                'message' => 'TGTools returned an invalid JSON response.',
            ];
        }

        $apiSuccess = !array_key_exists('isSuccess', $decoded) || (bool) $decoded['isSuccess'];
        $data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        $message = trim((string) ($decoded['message'] ?? $data['message'] ?? $data['error'] ?? ''));
        $ok = $http >= 200 && $http < 300 && $apiSuccess;

        if (!$ok && $message === '') {
            $message = 'TGTools returned HTTP ' . $http . '.';
        }

        return [
            'ok' => $ok,
            'http_status' => $http,
            'data' => $data,
            'message' => $message,
        ];
    }
}
