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


function bluebotAuditLogPath(): string
{
    $root = dirname(__DIR__, 2);
    $directory = $root . '/storage/logs';

    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return '';
    }

    @chmod($directory, 0750);
    return $directory . '/audit.log';
}

function bluebotRotateAuditLog(string $path, int $maxBytes = 5242880): void
{
    if ($path === '' || !is_file($path)) {
        return;
    }

    $size = @filesize($path);
    if ($size === false || $size < $maxBytes) {
        return;
    }

    $archive = $path . '.1';
    if (is_file($archive)) {
        @unlink($archive);
    }
    @rename($path, $archive);
    if (is_file($archive)) {
        @chmod($archive, 0640);
    }
}

function bluebotAudit(string $event, array $context = []): void
{
    $event = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($event)) ?: 'unknown';
    $sanitizedContext = bluebotRedactLogValue($context);

    $path = bluebotAuditLogPath();
    if ($path !== '') {
        bluebotRotateAuditLog($path);

        $record = [
            'time' => date(DATE_ATOM),
            'event' => $event,
            'context' => is_array($sanitizedContext) ? $sanitizedContext : [],
        ];

        $encoded = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (is_string($encoded)) {
            @file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
            @chmod($path, 0640);
        }
    }

    bluebotLog('audit', $event, $context);
}

function bluebotReadAuditLog(int $limit = 100): array
{
    $limit = max(1, min($limit, 500));
    $path = bluebotAuditLogPath();

    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $records = [];
    foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            continue;
        }

        $records[] = [
            'time' => (string) ($decoded['time'] ?? ''),
            'event' => (string) ($decoded['event'] ?? 'unknown'),
            'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
        ];
    }

    return $records;
}
