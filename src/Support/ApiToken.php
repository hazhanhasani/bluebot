<?php

declare(strict_types=1);

function bluebotApiTokenPath(): string
{
    return dirname(__DIR__, 2) . '/api/hash.txt';
}

function bluebotDedicatedApiTokens(): array
{
    $tokens = [];

    $envToken = getenv('BLUEBOT_API_TOKEN');
    if (is_string($envToken) && trim($envToken) !== '') {
        $tokens[] = trim($envToken);
    }

    $path = bluebotApiTokenPath();
    if (is_file($path) && is_readable($path)) {
        $fileToken = trim((string) file_get_contents($path));
        if ($fileToken !== '') {
            $tokens[] = $fileToken;
        }
    }

    return array_values(array_unique(array_filter(
        $tokens,
        static fn($token): bool => is_string($token) && trim($token) !== ''
    )));
}

function bluebotHasDedicatedApiToken(): bool
{
    return bluebotDedicatedApiTokens() !== [];
}

function bluebotGenerateDedicatedApiToken(int $bytes = 32): string
{
    $bytes = max(16, min($bytes, 64));
    $path = bluebotApiTokenPath();
    $directory = dirname($path);

    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create API token directory.');
    }

    $token = bin2hex(random_bytes($bytes));
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));

    if (@file_put_contents($tmp, $token . PHP_EOL, LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('Unable to write API token.');
    }

    @chmod($tmp, 0640);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to activate API token.');
    }

    @chmod($path, 0640);

    return $token;
}
