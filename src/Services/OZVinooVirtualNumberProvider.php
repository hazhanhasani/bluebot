<?php

declare(strict_types=1);

require_once __DIR__ . '/VirtualNumberProviderInterface.php';
require_once __DIR__ . '/OZVinooClient.php';

final class OZVinooVirtualNumberProvider implements BluebotVirtualNumberProviderInterface
{
    public function __construct(private readonly OZVinooClient $client)
    {
    }

    public function requestNumber(array $product): array
    {
        $metadata = self::metadata($product);
        $family = (string) ($metadata['api_family'] ?? 'telegram-numbers-v2');

        try {
            if ($family === 'web-v1') {
                $serviceId = max(0, (int) ($metadata['service_id'] ?? 0));
                $range = trim((string) ($metadata['range'] ?? ''));
                if ($serviceId <= 0 || $range === '') {
                    return [
                        'ok' => false,
                        'retryable' => true,
                        'message' => 'اطلاعات سرویس یا کشور شماره مجازی کامل نیست.',
                    ];
                }
                $response = $this->client->getNumberV1($serviceId, $range);
            } else {
                $countryId = trim((string) ($metadata['country_id'] ?? $product['provider_service_code'] ?? ''));
                if ($countryId === '') {
                    return [
                        'ok' => false,
                        'retryable' => true,
                        'message' => 'شناسه کشور شماره مجازی پیدا نشد.',
                    ];
                }
                $response = $this->client->buyNumber($countryId, true);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'retryable' => true, 'message' => $e->getMessage()];
        }

        if (empty($response['ok'])) {
            $http = (int) ($response['http_status'] ?? 0);
            $message = trim((string) ($response['message'] ?? '')) ?: 'درخواست شماره مجازی ناموفق بود.';
            return [
                'ok' => false,
                'retryable' => self::retryableHttp($http),
                'failure_type' => self::failureType($http, $message),
                'message' => $message,
                'response' => $response,
            ];
        }

        $data = self::bodyData($response);
        $reference = trim((string) ($data['order_id'] ?? $data['request_id'] ?? $data['id'] ?? ''));
        if ($reference === '') {
            return [
                'ok' => false,
                'retryable' => true,
                'message' => 'ارائه‌دهنده شناسه پیگیری شماره را برنگرداند.',
                'response' => $response,
            ];
        }

        return [
            'ok' => true,
            'pending' => true,
            'reference' => $reference,
            'number' => trim((string) ($data['number'] ?? '')),
            'response' => $response,
        ];
    }

    public function status(string $reference, array $product): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return ['ok' => false, 'retryable' => false, 'message' => 'شناسه پیگیری شماره خالی است.'];
        }

        $metadata = self::metadata($product);
        $family = (string) ($metadata['api_family'] ?? 'telegram-numbers-v2');

        try {
            $response = $family === 'web-v1'
                ? $this->client->getCodeV1($reference)
                : $this->client->numberStatus($reference);
        } catch (Throwable $e) {
            return ['ok' => false, 'retryable' => true, 'message' => $e->getMessage()];
        }

        $http = (int) ($response['http_status'] ?? 0);
        if ($http === 202) {
            return [
                'ok' => true,
                'status' => 'pending',
                'reference' => $reference,
                'response' => $response,
            ];
        }
        if (empty($response['ok'])) {
            $message = trim((string) ($response['message'] ?? '')) ?: 'دریافت وضعیت شماره ناموفق بود.';
            return [
                'ok' => false,
                'retryable' => self::retryableHttp($http),
                'failure_type' => self::failureType($http, $message),
                'message' => $message,
                'response' => $response,
            ];
        }

        $data = self::bodyData($response);
        $code = trim((string) ($data['code'] ?? ''));
        $number = trim((string) ($data['number'] ?? ''));
        $raw = strtolower(trim((string) ($data['status'] ?? '')));

        if ($code !== '') {
            return [
                'ok' => true,
                'status' => 'completed',
                'reference' => $reference,
                'number' => $number,
                'code' => $code,
                'response' => $response,
            ];
        }

        if (in_array($raw, ['cancel', 'cancelled', 'canceled', 'failed', 'error', 'rejected', 'expired'], true)
            || str_contains(strtolower((string) (($response['body']['message'] ?? '') ?: '')), 'cancel')) {
            return [
                'ok' => true,
                'status' => 'failed',
                'reference' => $reference,
                'number' => $number,
                'response' => $response,
            ];
        }

        return [
            'ok' => true,
            'status' => 'pending',
            'reference' => $reference,
            'number' => $number,
            'response' => $response,
        ];
    }

    public function cancel(string $reference, array $product): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return ['ok' => false, 'message' => 'شناسه پیگیری شماره خالی است.'];
        }

        $metadata = self::metadata($product);
        $family = (string) ($metadata['api_family'] ?? 'telegram-numbers-v2');

        try {
            $response = $family === 'web-v1'
                ? $this->client->logoutV1($reference)
                : $this->client->cancelOrder($reference);
        } catch (Throwable $e) {
            return ['ok' => false, 'retryable' => true, 'message' => $e->getMessage()];
        }

        return [
            'ok' => !empty($response['ok']),
            'retryable' => empty($response['ok']) && self::retryableHttp((int) ($response['http_status'] ?? 0)),
            'message' => (string) ($response['message'] ?? ''),
            'response' => $response,
        ];
    }

    public function reportBanned(string $reference, array $product): array
    {
        // OZVinoo does not expose a distinct documented "banned" endpoint in
        // the integration used by BlueBot. Keep the normalized capability
        // explicit instead of pretending a cancellation is a banned report.
        return [
            'ok' => false,
            'supported' => false,
            'retryable' => false,
            'message' => 'این Provider مسیر مستقل گزارش بن‌شدن شماره ندارد.',
        ];
    }

    private static function metadata(array $product): array
    {
        $decoded = json_decode((string) ($product['metadata'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function bodyData(array $response): array
    {
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }

    private static function failureType(int $http, string $message): string
    {
        $message = strtolower(trim($message));
        if (in_array($http, [408, 504], true)
            || str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'زمان درخواست')) {
            return 'timeout';
        }

        foreach ([
            'no number',
            'no numbers',
            'out of stock',
            'not available',
            'temporarily unavailable',
            'ناموجود',
            'موجود نیست',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return 'availability';
            }
        }

        return '';
    }

    private static function retryableHttp(int $http): bool
    {
        return $http === 0 || in_array($http, [401, 402, 408, 425, 429], true) || $http >= 500;
    }
}
