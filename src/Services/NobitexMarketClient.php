<?php

final class NobitexMarketClient
{
    private const DEFAULT_BASE_URL = 'https://apiv2.nobitex.ir';
    private const TON_MARKET = 'GRAMIRT';
    private const RIALS_PER_TOMAN = 10.0;

    private string $baseUrl;
    private string $publicKey;
    private string $privateKey;

    public function __construct(
        string $baseUrl = self::DEFAULT_BASE_URL,
        string $publicKey = '',
        string $privateKey = ''
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $this->baseUrl = $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL;
        $this->publicKey = trim($publicKey);
        $this->privateKey = trim($privateKey);
    }

    public function tonTomanRate(): array
    {
        $response = $this->requestJson('GET', '/v3/orderbook/' . self::TON_MARKET, false);
        if (empty($response['ok'])) {
            return [
                'ok' => false,
                'market' => self::TON_MARKET,
                'rate_toman' => null,
                'last_update' => null,
                'http' => (int) ($response['http'] ?? 0),
                'message' => (string) ($response['message'] ?? 'Nobitex orderbook request failed.'),
            ];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        if (strtolower((string) ($data['status'] ?? '')) !== 'ok') {
            return [
                'ok' => false,
                'market' => self::TON_MARKET,
                'rate_toman' => null,
                'last_update' => null,
                'http' => (int) ($response['http'] ?? 0),
                'message' => 'Nobitex returned an invalid orderbook response.',
            ];
        }

        $rawRateRial = $data['lastTradePrice'] ?? null;
        if (!is_numeric($rawRateRial) || (float) $rawRateRial <= 0) {
            return [
                'ok' => false,
                'market' => self::TON_MARKET,
                'rate_toman' => null,
                'last_update' => $data['lastUpdate'] ?? null,
                'http' => (int) ($response['http'] ?? 0),
                'message' => 'Nobitex orderbook does not contain a valid lastTradePrice.',
            ];
        }

        $rateToman = (float) $rawRateRial / self::RIALS_PER_TOMAN;

        return [
            'ok' => true,
            'market' => self::TON_MARKET,
            'raw_rate_rial' => (float) $rawRateRial,
            'rate_toman' => $rateToman,
            'conversion' => 'rial_to_toman',
            'last_update' => is_numeric($data['lastUpdate'] ?? null)
                ? (int) $data['lastUpdate']
                : null,
            'http' => (int) ($response['http'] ?? 200),
            'base_url' => $this->baseUrl,
            'message' => '',
        ];
    }

    public function gramWithdrawalInfo(): array
    {
        $response = $this->requestJson('GET', '/v2/options', false);
        if (empty($response['ok'])) {
            return [
                'ok' => false,
                'withdraw_fee_gram' => null,
                'withdraw_min_gram' => null,
                'network' => 'TON',
                'http' => (int) ($response['http'] ?? 0),
                'message' => (string) ($response['message'] ?? 'Nobitex options request failed.'),
            ];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $gramNode = $this->findCurrencyNode($data, 'gram');
        if (!is_array($gramNode)) {
            return [
                'ok' => false,
                'withdraw_fee_gram' => null,
                'withdraw_min_gram' => null,
                'network' => 'TON',
                'http' => (int) ($response['http'] ?? 0),
                'message' => 'GRAM was not found in Nobitex options.',
            ];
        }

        $terms = $this->findWithdrawalTerms($gramNode, 'ton');
        if (!is_array($terms) || !is_numeric($terms['withdrawFee'] ?? null)) {
            return [
                'ok' => false,
                'withdraw_fee_gram' => null,
                'withdraw_min_gram' => null,
                'network' => 'TON',
                'http' => (int) ($response['http'] ?? 0),
                'message' => 'GRAM/TON withdrawal fee was not found in Nobitex options.',
            ];
        }

        return [
            'ok' => true,
            'withdraw_fee_gram' => max(0.0, (float) $terms['withdrawFee']),
            'withdraw_min_gram' => is_numeric($terms['withdrawMin'] ?? null)
                ? max(0.0, (float) $terms['withdrawMin'])
                : null,
            'network' => (string) ($terms['network'] ?? 'TON'),
            'http' => (int) ($response['http'] ?? 200),
            'base_url' => $this->baseUrl,
            'message' => '',
        ];
    }

    public function testApiKey(): array
    {
        if ($this->publicKey === '' || $this->privateKey === '') {
            return [
                'ok' => false,
                'http' => 0,
                'message' => 'Nobitex Public Key and Private Key are both required.',
            ];
        }

        $response = $this->requestJson('GET', '/users/profile', true);
        if (empty($response['ok'])) {
            return [
                'ok' => false,
                'http' => (int) ($response['http'] ?? 0),
                'message' => (string) ($response['message'] ?? 'Nobitex API Key validation failed.'),
            ];
        }

        return [
            'ok' => true,
            'http' => (int) ($response['http'] ?? 200),
            'message' => '',
        ];
    }

    private function requestJson(string $method, string $path, bool $authenticated): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http' => 0, 'message' => 'cURL extension is unavailable.'];
        }

        $method = strtoupper(trim($method));
        $path = '/' . ltrim($path, '/');
        $headers = [
            'Accept: application/json',
            'User-Agent: BlueBot/1.0',
        ];

        if ($authenticated) {
            $authHeaders = $this->apiKeyHeaders($method, $path, '');
            if (empty($authHeaders['ok'])) {
                return [
                    'ok' => false,
                    'http' => 0,
                    'message' => (string) ($authHeaders['message'] ?? 'Unable to sign Nobitex request.'),
                ];
            }
            foreach (($authHeaders['headers'] ?? []) as $header) {
                $headers[] = $header;
            }
        }

        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            return ['ok' => false, 'http' => 0, 'message' => 'Unable to initialize Nobitex request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $http < 200 || $http >= 300) {
            return [
                'ok' => false,
                'http' => $http,
                'message' => $error !== '' ? $error : ('Nobitex returned HTTP ' . $http),
            ];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'http' => $http,
                'message' => 'Nobitex returned invalid JSON.',
            ];
        }

        return [
            'ok' => true,
            'http' => $http,
            'data' => $data,
            'message' => '',
        ];
    }

    private function apiKeyHeaders(string $method, string $fullPath, string $rawBody): array
    {
        if ($this->publicKey === '' || $this->privateKey === '') {
            return ['ok' => false, 'headers' => [], 'message' => 'Nobitex API Key is not configured.'];
        }
        if (!function_exists('sodium_crypto_sign_detached')) {
            return ['ok' => false, 'headers' => [], 'message' => 'PHP sodium extension is required for Nobitex API Key signatures.'];
        }

        $privateKeyBytes = $this->base64UrlDecode($this->privateKey);
        if (!is_string($privateKeyBytes)) {
            return ['ok' => false, 'headers' => [], 'message' => 'Nobitex Private Key encoding is invalid.'];
        }

        if (strlen($privateKeyBytes) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $keypair = sodium_crypto_sign_seed_keypair($privateKeyBytes);
            $secretKey = sodium_crypto_sign_secretkey($keypair);
        } elseif (strlen($privateKeyBytes) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $secretKey = $privateKeyBytes;
        } else {
            return ['ok' => false, 'headers' => [], 'message' => 'Nobitex Private Key length is invalid.'];
        }

        $timestamp = (string) time();
        $payload = $timestamp . strtoupper($method) . $fullPath . $rawBody;
        $signature = sodium_crypto_sign_detached($payload, $secretKey);
        sodium_memzero($secretKey);

        return [
            'ok' => true,
            'headers' => [
                'Nobitex-Key: ' . $this->publicKey,
                'Nobitex-Signature: ' . $this->base64UrlEncode($signature),
                'Nobitex-Timestamp: ' . $timestamp,
            ],
            'message' => '',
        ];
    }

    private function base64UrlDecode(string $value): string|false
    {
        $value = trim($value);
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function findCurrencyNode(array $node, string $currency): ?array
    {
        $currency = strtolower($currency);

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (strtolower((string) $key) === $currency) {
                return $value;
            }

            foreach (['code', 'symbol', 'currency', 'slug', 'name'] as $field) {
                if (isset($value[$field]) && strtolower(trim((string) $value[$field])) === $currency) {
                    return $value;
                }
            }

            $found = $this->findCurrencyNode($value, $currency);
            if (is_array($found)) {
                return $found;
            }
        }

        return null;
    }

    private function findWithdrawalTerms(array $node, string $networkNeedle): ?array
    {
        $networkNeedle = strtolower($networkNeedle);

        $networkText = strtolower(trim((string) (
            $node['network']
            ?? $node['name']
            ?? $node['title']
            ?? $node['code']
            ?? ''
        )));

        if (isset($node['withdrawFee']) && is_numeric($node['withdrawFee'])) {
            if ($networkText === '' || str_contains($networkText, $networkNeedle)) {
                return [
                    'withdrawFee' => $node['withdrawFee'],
                    'withdrawMin' => $node['withdrawMin'] ?? null,
                    'network' => (string) ($node['network'] ?? 'TON'),
                ];
            }
        }

        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = $this->findWithdrawalTerms($value, $networkNeedle);
            if (is_array($found)) {
                return $found;
            }
        }

        return null;
    }
}
