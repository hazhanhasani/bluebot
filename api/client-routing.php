<?php

declare(strict_types=1);

function clientRoutingDecodeBase64(string $raw): ?string
{
    $compact = preg_replace('/\s+/', '', trim($raw));
    if (!is_string($compact) || $compact === '') {
        return null;
    }

    $normalized = strtr($compact, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding !== 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    return is_string($decoded) && $decoded !== '' ? $decoded : null;
}

function clientRoutingExtractLinksFromText(string $text): array
{
    $scan = static function (string $value): array {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $links = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            if (!preg_match('/^(vless|vmess|trojan|ss|socks|hysteria2|hy2):\/\//i', $token)) {
                continue;
            }
            $links[] = $token;
        }
        return array_values(array_unique($links));
    };

    $direct = $scan($text);
    if ($direct !== []) {
        return $direct;
    }

    $decoded = clientRoutingDecodeBase64($text);
    return $decoded !== null ? $scan($decoded) : [];
}

function clientRoutingUrlIsSafe(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(trim((string) ($parts['host'] ?? '')));
    if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
        return false;
    }
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    $resolved = gethostbyname($host);
    if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return filter_var(
            $resolved,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    return true;
}

function clientRoutingFetchSubscriptionLinks(?string $url): array
{
    $url = trim((string) $url);
    if ($url === '' || !clientRoutingUrlIsSafe($url)) {
        return [];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 6,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => "Accept: text/plain, application/octet-stream;q=0.9, */*;q=0.8\r\n"
                . "User-Agent: BluePanel-Router/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context, 0, 1_048_576);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    return clientRoutingExtractLinksFromText($raw);
}

function clientRoutingConnectionLinks(array $connection): array
{
    $links = [];
    foreach (($connection['links'] ?? []) as $link) {
        if (is_string($link) && preg_match('/^(vless|vmess|trojan|ss|socks|hysteria2|hy2):\/\//i', trim($link))) {
            $links[] = trim($link);
        }
    }

    $subscriptionLinks = clientRoutingFetchSubscriptionLinks(
        isset($connection['subscription_url']) ? (string) $connection['subscription_url'] : null
    );

    return array_values(array_unique(array_merge($links, $subscriptionLinks)));
}

function clientRoutingLinkLabel(string $link): string
{
    if (stripos($link, 'vmess://') === 0) {
        $payload = substr($link, strlen('vmess://'));
        $payload = explode('#', $payload, 2)[0];
        $decoded = clientRoutingDecodeBase64($payload);
        if ($decoded !== null) {
            $json = json_decode($decoded, true);
            if (is_array($json) && isset($json['ps']) && is_scalar($json['ps'])) {
                return trim((string) $json['ps']);
            }
        }
    }

    $fragment = parse_url($link, PHP_URL_FRAGMENT);
    if (is_string($fragment) && $fragment !== '') {
        return trim(rawurldecode($fragment));
    }

    if (str_contains($link, '#')) {
        return trim(rawurldecode((string) substr($link, strrpos($link, '#') + 1)));
    }

    return '';
}

function clientRoutingCountryCatalog(): array
{
    return [
        'DE' => ['name' => 'آلمان', 'flag' => '🇩🇪', 'terms' => ['germany', 'deutschland', 'frankfurt', 'berlin', 'آلمان']],
        'NL' => ['name' => 'هلند', 'flag' => '🇳🇱', 'terms' => ['netherlands', 'holland', 'amsterdam', 'هلند']],
        'FI' => ['name' => 'فنلاند', 'flag' => '🇫🇮', 'terms' => ['finland', 'helsinki', 'فنلاند']],
        'FR' => ['name' => 'فرانسه', 'flag' => '🇫🇷', 'terms' => ['france', 'paris', 'فرانسه']],
        'GB' => ['name' => 'انگلیس', 'flag' => '🇬🇧', 'terms' => ['united kingdom', 'england', 'london', 'britain', 'انگلیس']],
        'US' => ['name' => 'آمریکا', 'flag' => '🇺🇸', 'terms' => ['united states', 'america', 'new york', 'los angeles', 'miami', 'usa', 'آمریکا']],
        'CA' => ['name' => 'کانادا', 'flag' => '🇨🇦', 'terms' => ['canada', 'toronto', 'montreal', 'vancouver', 'کانادا']],
        'TR' => ['name' => 'ترکیه', 'flag' => '🇹🇷', 'terms' => ['turkey', 'turkiye', 'istanbul', 'ترکیه']],
        'SE' => ['name' => 'سوئد', 'flag' => '🇸🇪', 'terms' => ['sweden', 'stockholm', 'سوئد']],
        'CH' => ['name' => 'سوئیس', 'flag' => '🇨🇭', 'terms' => ['switzerland', 'zurich', 'geneva', 'سوئیس']],
        'PL' => ['name' => 'لهستان', 'flag' => '🇵🇱', 'terms' => ['poland', 'warsaw', 'لهستان']],
        'RO' => ['name' => 'رومانی', 'flag' => '🇷🇴', 'terms' => ['romania', 'bucharest', 'رومانی']],
        'RU' => ['name' => 'روسیه', 'flag' => '🇷🇺', 'terms' => ['russia', 'moscow', 'روسیه']],
        'AE' => ['name' => 'امارات', 'flag' => '🇦🇪', 'terms' => ['united arab emirates', 'dubai', 'uae', 'امارات']],
        'IN' => ['name' => 'هند', 'flag' => '🇮🇳', 'terms' => ['india', 'mumbai', 'delhi', 'هند']],
        'SG' => ['name' => 'سنگاپور', 'flag' => '🇸🇬', 'terms' => ['singapore', 'سنگاپور']],
        'JP' => ['name' => 'ژاپن', 'flag' => '🇯🇵', 'terms' => ['japan', 'tokyo', 'osaka', 'ژاپن']],
        'KR' => ['name' => 'کره جنوبی', 'flag' => '🇰🇷', 'terms' => ['south korea', 'korea', 'seoul', 'کره']],
        'HK' => ['name' => 'هنگ‌کنگ', 'flag' => '🇭🇰', 'terms' => ['hong kong', 'هنگ کنگ', 'هنگ‌کنگ']],
        'AU' => ['name' => 'استرالیا', 'flag' => '🇦🇺', 'terms' => ['australia', 'sydney', 'melbourne', 'استرالیا']],
        'AM' => ['name' => 'ارمنستان', 'flag' => '🇦🇲', 'terms' => ['armenia', 'yerevan', 'ارمنستان']],
        'AZ' => ['name' => 'آذربایجان', 'flag' => '🇦🇿', 'terms' => ['azerbaijan', 'baku', 'آذربایجان']],
        'IR' => ['name' => 'ایران', 'flag' => '🇮🇷', 'terms' => ['iran', 'tehran', 'ایران']],
        'IQ' => ['name' => 'عراق', 'flag' => '🇮🇶', 'terms' => ['iraq', 'baghdad', 'عراق']],
        'QA' => ['name' => 'قطر', 'flag' => '🇶🇦', 'terms' => ['qatar', 'doha', 'قطر']],
        'AT' => ['name' => 'اتریش', 'flag' => '🇦🇹', 'terms' => ['austria', 'vienna', 'اتریش']],
        'BE' => ['name' => 'بلژیک', 'flag' => '🇧🇪', 'terms' => ['belgium', 'brussels', 'بلژیک']],
        'CZ' => ['name' => 'چک', 'flag' => '🇨🇿', 'terms' => ['czech', 'prague', 'چک']],
        'ES' => ['name' => 'اسپانیا', 'flag' => '🇪🇸', 'terms' => ['spain', 'madrid', 'barcelona', 'اسپانیا']],
        'IT' => ['name' => 'ایتالیا', 'flag' => '🇮🇹', 'terms' => ['italy', 'milan', 'rome', 'ایتالیا']],
        'NO' => ['name' => 'نروژ', 'flag' => '🇳🇴', 'terms' => ['norway', 'oslo', 'نروژ']],
        'DK' => ['name' => 'دانمارک', 'flag' => '🇩🇰', 'terms' => ['denmark', 'copenhagen', 'دانمارک']],
        'IE' => ['name' => 'ایرلند', 'flag' => '🇮🇪', 'terms' => ['ireland', 'dublin', 'ایرلند']],
        'PT' => ['name' => 'پرتغال', 'flag' => '🇵🇹', 'terms' => ['portugal', 'lisbon', 'پرتغال']],
        'BR' => ['name' => 'برزیل', 'flag' => '🇧🇷', 'terms' => ['brazil', 'sao paulo', 'برزیل']],
        'ZA' => ['name' => 'آفریقای جنوبی', 'flag' => '🇿🇦', 'terms' => ['south africa', 'johannesburg', 'آفریقای جنوبی']],
    ];
}

function clientRoutingLocationMeta(string $link): array
{
    $label = clientRoutingLinkLabel($link);
    $normalized = mb_strtolower($label, 'UTF-8');

    foreach (clientRoutingCountryCatalog() as $code => $country) {
        if (str_contains($label, (string) $country['flag'])) {
            return ['key' => $code, 'name' => $country['name'], 'flag' => $country['flag']];
        }
    }

    foreach (clientRoutingCountryCatalog() as $code => $country) {
        foreach ($country['terms'] as $term) {
            if (mb_stripos($normalized, mb_strtolower((string) $term, 'UTF-8'), 0, 'UTF-8') !== false) {
                return ['key' => $code, 'name' => $country['name'], 'flag' => $country['flag']];
            }
        }
        if ($label !== '' && preg_match('/(^|[^A-Za-z])' . preg_quote($code, '/') . '(?=$|[^A-Za-z])/i', $label)) {
            return ['key' => $code, 'name' => $country['name'], 'flag' => $country['flag']];
        }
    }

    return ['key' => 'OTHER', 'name' => 'سایر', 'flag' => '🌐'];
}

function clientRoutingLocationIndex(string $key): int
{
    return (int) hexdec(substr(hash('sha256', strtoupper(trim($key))), 0, 7)) + 1;
}

function clientRoutingGroups(array $links): array
{
    $groups = [];
    foreach (array_values(array_unique($links)) as $link) {
        if (!is_string($link) || trim($link) === '') {
            continue;
        }
        $meta = clientRoutingLocationMeta($link);
        $key = (string) $meta['key'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'index' => clientRoutingLocationIndex($key),
                'key' => $key,
                'name' => (string) $meta['name'],
                'flag' => (string) $meta['flag'],
                'links' => [],
            ];
        }
        $groups[$key]['links'][] = trim($link);
    }

    return array_values($groups);
}

function clientRoutingPublicLocations(array $links): array
{
    return array_map(
        static fn (array $group): array => [
            'index' => (int) $group['index'],
            'name' => (string) $group['name'],
            'flag' => (string) $group['flag'],
        ],
        clientRoutingGroups($links)
    );
}

function clientRoutingLinkEndpoint(string $link): ?array
{
    $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));
    if ($scheme === 'vmess') {
        $payload = explode('#', substr($link, strlen('vmess://')), 2)[0];
        $decoded = clientRoutingDecodeBase64($payload);
        $json = $decoded !== null ? json_decode($decoded, true) : null;
        if (!is_array($json)) {
            return null;
        }
        $host = trim((string) ($json['add'] ?? ''));
        $port = (int) ($json['port'] ?? 0);
        return $host !== '' && $port > 0 && $port <= 65535 ? ['host' => $host, 'port' => $port] : null;
    }

    $host = parse_url($link, PHP_URL_HOST);
    $port = parse_url($link, PHP_URL_PORT);
    if (is_string($host) && $host !== '' && is_int($port) && $port > 0 && $port <= 65535) {
        return ['host' => trim($host, '[]'), 'port' => $port];
    }

    if ($scheme === 'ss') {
        $payload = preg_replace('/^ss:\/\//i', '', $link);
        $payload = explode('#', (string) $payload, 2)[0];
        $payload = explode('?', (string) $payload, 2)[0];
        if (is_string($payload) && !str_contains($payload, '@')) {
            $decoded = clientRoutingDecodeBase64($payload);
            if ($decoded !== null) {
                $payload = $decoded;
            }
        }
        if (is_string($payload) && str_contains($payload, '@')) {
            $hostPort = substr($payload, strrpos($payload, '@') + 1);
            if (preg_match('/^\[([^\]]+)]:(\d+)$/', $hostPort, $match)) {
                return ['host' => $match[1], 'port' => (int) $match[2]];
            }
            if (preg_match('/^([^:]+):(\d+)$/', $hostPort, $match)) {
                return ['host' => $match[1], 'port' => (int) $match[2]];
            }
        }
    }

    return null;
}

function clientRoutingHostIsSafeForProbe(string $host): bool
{
    $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    $resolved = gethostbyname($host);
    if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return filter_var(
            $resolved,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    return true;
}

function clientRoutingProbeLatencyMs(string $link, float $timeoutSeconds = 0.45): ?float
{
    $endpoint = clientRoutingLinkEndpoint($link);
    if ($endpoint === null) {
        return null;
    }

    $host = (string) $endpoint['host'];
    $port = (int) $endpoint['port'];
    if (!clientRoutingHostIsSafeForProbe($host)) {
        return null;
    }

    $formattedHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
    $target = 'tcp://' . $formattedHost . ':' . $port;
    $started = microtime(true);
    $socket = @stream_socket_client(
        $target,
        $errno,
        $error,
        max(0.1, min($timeoutSeconds, 0.8)),
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        return null;
    }

    fclose($socket);
    return max(0.0, (microtime(true) - $started) * 1000.0);
}

function clientRoutingSelectBestLink(array $links): array
{
    $candidates = array_values(array_unique(array_filter(
        $links,
        static fn ($link): bool => is_string($link) && trim($link) !== ''
    )));
    if ($candidates === []) {
        throw new InvalidArgumentException('No compatible connection candidates are available.');
    }

    $bestLink = (string) $candidates[0];
    $bestLatency = null;
    foreach (array_slice($candidates, 0, 8) as $candidate) {
        $latency = clientRoutingProbeLatencyMs((string) $candidate);
        if ($latency === null) {
            continue;
        }
        if ($bestLatency === null || $latency < $bestLatency) {
            $bestLatency = $latency;
            $bestLink = (string) $candidate;
        }
    }

    return ['link' => $bestLink, 'latency_ms' => $bestLatency];
}

function clientRoutingSelectForLocation(array $links, ?int $locationIndex): array
{
    $groups = clientRoutingGroups($links);
    if ($groups === []) {
        throw new InvalidArgumentException('No compatible connection candidates are available.');
    }

    $selectedGroup = null;
    if ($locationIndex !== null && $locationIndex >= 0) {
        foreach ($groups as $group) {
            if ((int) $group['index'] === $locationIndex) {
                $selectedGroup = $group;
                break;
            }
        }
        if ($selectedGroup === null) {
            throw new InvalidArgumentException('Requested location is not available.');
        }
    }

    $candidates = $selectedGroup !== null
        ? (array) $selectedGroup['links']
        : array_merge(...array_map(static fn (array $group): array => (array) $group['links'], $groups));

    $best = clientRoutingSelectBestLink($candidates);
    $meta = $selectedGroup !== null
        ? [
            'index' => (int) $selectedGroup['index'],
            'name' => (string) $selectedGroup['name'],
            'flag' => (string) $selectedGroup['flag'],
        ]
        : null;

    return [
        'link' => (string) $best['link'],
        'latency_ms' => $best['latency_ms'],
        'location' => $meta,
    ];
}
