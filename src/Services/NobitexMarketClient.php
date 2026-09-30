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
}
