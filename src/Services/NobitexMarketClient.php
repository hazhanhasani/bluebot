<?php

final class NobitexMarketClient
{
    private const BASE_URL = 'https://api.nobitex.ir';
    private const TON_MARKET = 'GRAMIRT';
    private const RIALS_PER_TOMAN = 10.0;

    public function tonTomanRate(): array
    {
        $url = self::BASE_URL . '/v3/orderbook/' . self::TON_MARKET;

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'market' => self::TON_MARKET,
                'rate_toman' => null,
                'last_update' => null,
                'message' => 'cURL extension is unavailable.',
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'market' => self::TON_MARKET,
                'rate_toman' => null,
                'last_update' => null,
                'message' => 'Unable to initialize Nobitex request.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: BlueBot/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $http < 200 || $http >= 300) {
            return [
                'ok' => false,
                'market' => self::TON_MARKET,
                'rate_toman' => null,
                'last_update' => null,
                'http' => $http,
                'message' => $error !== '' ? $error : ('Nobitex returned HTTP ' . $http),
            ];
        }

        $data = json_decode($body, true);
        if (!is_array($data) || strtolower((string) ($data['status'] ?? '')) !== 'ok') {
            return [
                'ok' => false,
                'market' => self::TON_MARKET,
                'rate_toman' => null,
                'last_update' => null,
                'http' => $http,
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
                'http' => $http,
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
            'http' => $http,
            'message' => '',
        ];
    }

    public function gramWithdrawalInfo(): array
    {
        $url = self::BASE_URL . '/v2/options';

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'withdraw_fee_gram' => null,
                'withdraw_min_gram' => null,
                'network' => 'TON',
                'message' => 'cURL extension is unavailable.',
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'withdraw_fee_gram' => null,
                'withdraw_min_gram' => null,
                'network' => 'TON',
                'message' => 'Unable to initialize Nobitex options request.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: BlueBot/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $http < 200 || $http >= 300) {
            return [
                'ok' => false,
                'withdraw_fee_gram' => null,
                'withdraw_min_gram' => null,
                'network' => 'TON',
                'http' => $http,
                'message' => $error !== '' ? $error : ('Nobitex options returned HTTP ' . $http),
            ];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'withdraw_fee_gram' => null,
                'withdraw_min_gram' => null,
                'network' => 'TON',
                'http' => $http,
                'message' => 'Nobitex options returned invalid JSON.',
            ];
        }

        $gramNode = $this->findCurrencyNode($data, 'gram');
        if (!is_array($gramNode)) {
            return [
                'ok' => false,
                'withdraw_fee_gram' => null,
                'withdraw_min_gram' => null,
                'network' => 'TON',
                'http' => $http,
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
                'http' => $http,
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
            'http' => $http,
            'message' => '',
        ];
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
