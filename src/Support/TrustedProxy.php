<?php

declare(strict_types=1);

/**
 * Return true when an IP belongs to the given CIDR.
 */
function bluebotIpInCidr(string $ip, string $cidr): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP) || strpos($cidr, '/') === false) {
        return false;
    }

    [$network, $prefixText] = explode('/', $cidr, 2);
    if (!filter_var($network, FILTER_VALIDATE_IP) || !ctype_digit($prefixText)) {
        return false;
    }

    $ipPacked = @inet_pton($ip);
    $networkPacked = @inet_pton($network);
    if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
        return false;
    }

    $bits = strlen($ipPacked) * 8;
    $prefix = (int) $prefixText;
    if ($prefix < 0 || $prefix > $bits) {
        return false;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;

    if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipPacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
}

/**
 * Cloudflare proxy ranges published by Cloudflare.
 *
 * CF-Connecting-IP is trusted only when REMOTE_ADDR belongs to one of these
 * ranges. This prevents clients from spoofing the header by connecting to the
 * origin directly.
 */
function bluebotCloudflareProxyCidrs(): array
{
    return [
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '108.162.192.0/18',
        '131.0.72.0/22',
        '141.101.64.0/18',
        '162.158.0.0/15',
        '172.64.0.0/13',
        '173.245.48.0/20',
        '188.114.96.0/20',
        '190.93.240.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
}

function bluebotIsCloudflareProxyIp(string $ip): bool
{
    foreach (bluebotCloudflareProxyCidrs() as $cidr) {
        if (bluebotIpInCidr($ip, $cidr)) {
            return true;
        }
    }

    return false;
}

function bluebotTelegramCidrs(): array
{
    return [
        '149.154.160.0/20',
        '91.108.4.0/22',
        '2001:67c:4e8::/48',
    ];
}

function bluebotIsTelegramIp(string $ip): bool
{
    foreach (bluebotTelegramCidrs() as $cidr) {
        if (bluebotIpInCidr($ip, $cidr)) {
            return true;
        }
    }

    return false;
}

/**
 * Resolve the real webhook client without trusting arbitrary proxy headers.
 */
function bluebotResolveWebhookClientIp(array $server): string
{
    $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        return '';
    }

    if (!bluebotIsCloudflareProxyIp($remote)) {
        return $remote;
    }

    $forwarded = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
    if (!filter_var($forwarded, FILTER_VALIDATE_IP)) {
        return '';
    }

    return $forwarded;
}

function bluebotTelegramWebhookIpAllowed(array $server): bool
{
    $clientIp = bluebotResolveWebhookClientIp($server);
    return $clientIp !== '' && bluebotIsTelegramIp($clientIp);
}
