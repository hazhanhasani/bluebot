<?php

declare(strict_types=1);

function bluebotRedactLogValue(mixed $value, ?string $key = null): mixed
{
    $sensitiveKey = $key !== null
        && preg_match('/token|password|passwd|secret|authorization|cookie|api[_-]?key|merchant/i', $key);

    if ($sensitiveKey) {
        return '[REDACTED]';
    }

    if (is_array($value)) {
        $result = [];
        foreach ($value as $childKey => $childValue) {
            $result[$childKey] = bluebotRedactLogValue(
                $childValue,
                is_string($childKey) ? $childKey : null
            );
        }
        return $result;
    }

    if (is_object($value)) {
        return '[OBJECT:' . get_debug_type($value) . ']';
    }

    if (is_string($value)) {
        $value = preg_replace(
            '/(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i',
            '$1[REDACTED]',
            $value
        ) ?? $value;
        $value = preg_replace(
            '/([?&](?:token|secret|password|api[_-]?key)=)[^&\s]+/i',
            '$1[REDACTED]',
            $value
        ) ?? $value;

        if (strlen($value) > 1500) {
            $value = substr($value, 0, 1500) . '…';
        }
    }

    return $value;
}

function bluebotLog(string $level, string $message, array $context = []): void
{
    $normalizedLevel = strtoupper(trim($level));
    if ($normalizedLevel === '') {
        $normalizedLevel = 'INFO';
    }

    $payload = $context === []
        ? ''
        : ' ' . (json_encode(
            bluebotRedactLogValue($context),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{"log_context":"encoding_failed"}');

    error_log('[BlueBot][' . $normalizedLevel . '] ' . $message . $payload);
}
