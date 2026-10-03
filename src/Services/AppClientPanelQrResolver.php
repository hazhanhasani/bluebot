<?php

declare(strict_types=1);

/**
 * Resolves a scanned subscription/config QR against services that actually
 * exist on panels connected to BlueBot. This deliberately does not depend on
 * the invoice table, so services created manually in a connected panel can
 * authenticate in Blue VPN as well.
 */
final class AppClientPanelQrResolver
{
    private const MAX_PANELS = 30;
    private const MAX_CANDIDATES_PER_PANEL = 16;
    private const MAX_CATALOG_ROWS = 600;

    /**
     * @return array{panel_name:string,username:string,service_key:string,runtime:array<string,mixed>}|null
     */
    public static function resolve(PDO $pdo, string $payload): ?array
    {
        $payload = trim($payload);
        if ($payload === '' || strlen($payload) > 65535 || !class_exists('ManagePanel')) {
            return null;
        }

        $signatures = self::signatures($payload);
        if ($signatures === []) {
            return null;
        }

        $identities = self::identities($payload);
        $hints = self::usernameHints($payload);

        try {
            $stmt = $pdo->query(
                'SELECT * FROM marzban_panel LIMIT ' . self::MAX_PANELS
            );
            $panels = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            return null;
        }

        if (!is_array($panels) || $panels === []) {
            return null;
        }

        $payloadHost = self::payloadHost($payload);
        usort($panels, static function (array $a, array $b) use ($payloadHost): int {
            return self::panelHostScore($b, $payloadHost) <=> self::panelHostScore($a, $payloadHost);
        });

        $previousTimeout = $GLOBALS['request_exec_timeout'] ?? null;
        $GLOBALS['request_exec_timeout'] = min(
            3000,
            max(1200, (int) ($previousTimeout ?: 3000))
        );

        try {
            $manager = new ManagePanel();

            foreach ($panels as $panel) {
                if (!self::supportedPanel($panel)) {
                    continue;
                }

                $panelName = trim((string) ($panel['name_panel'] ?? ''));
                if ($panelName === '') {
                    continue;
                }

                $candidates = $hints;
                foreach (self::catalogRows($panel) as $row) {
                    if (
                        self::rowMatchesPayload($row, $signatures)
                        || self::rowMatchesIdentity($row, $identities)
                    ) {
                        $username = self::rowUsername($row);
                        if ($username !== '') {
                            $candidates[] = $username;
                        }
                    }
                }

                $candidates = array_values(array_unique(array_filter(
                    array_map(static fn($value): string => trim((string) $value), $candidates),
                    static fn(string $value): bool => $value !== '' && strlen($value) <= 190
                )));
                $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES_PER_PANEL);

                foreach ($candidates as $username) {
                    try {
                        $runtime = $manager->DataUser($panelName, $username);
                    } catch (Throwable $e) {
                        continue;
                    }

                    if (!is_array($runtime) || self::runtimeUnavailable($runtime)) {
                        continue;
                    }

                    if (!self::runtimeMatches($runtime, $signatures, $identities)) {
                        continue;
                    }

                    $resolvedUsername = trim((string) ($runtime['username'] ?? $username));
                    if ($resolvedUsername === '') {
                        $resolvedUsername = $username;
                    }

                    return [
                        'panel_name' => $panelName,
                        'username' => $resolvedUsername,
                        'service_key' => self::serviceKey($panelName, $resolvedUsername),
                        'runtime' => $runtime,
                    ];
                }
            }
        } finally {
            if ($previousTimeout === null) {
                unset($GLOBALS['request_exec_timeout']);
            } else {
                $GLOBALS['request_exec_timeout'] = $previousTimeout;
            }
        }

        return null;
    }

    public static function serviceKey(string $panelName, string $username): string
    {
        return 'panel-' . substr(hash('sha256', $panelName . "\0" . $username), 0, 32);
    }

    private static function supportedPanel(array $panel): bool
    {
        $type = strtolower(trim((string) ($panel['type'] ?? '')));

        return $type !== ''
            && !in_array($type, ['manualsale', 'wgdashboard', 'ibsng', 'mikrotik'], true);
    }

    private static function runtimeUnavailable(array $runtime): bool
    {
        $status = strtolower(trim((string) ($runtime['status'] ?? '')));
        return $status === '' || $status === 'unsuccessful';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function catalogRows(array $panel): array
    {
        $type = strtolower(trim((string) ($panel['type'] ?? '')));
        $name = trim((string) ($panel['name_panel'] ?? ''));
        if ($name === '') {
            return [];
        }

        try {
            $data = match ($type) {
                'marzban' => self::marzbanCatalog($name),
                'marzneshin' => self::responseData(
                    function_exists('marzneshinRequest')
                        ? marzneshinRequest($name, 'GET', '/api/users?offset=0&limit=250')
                        : []
                ),
                'solidlayer' => self::responseData(
                    function_exists('solidlayerRequest')
                        ? solidlayerRequest(
                            $name,
                            'GET',
                            '/api/subscriptions',
                            null,
                            ['page' => 1, 'limit' => 250, 'sort_by' => 'id', 'sort_dir' => 'asc']
                        )
                        : []
                ),
                'hiddify' => self::responseData(
                    function_exists('hiddifyRequest')
                        ? hiddifyRequest($name, 'GET', '/api/v2/admin/user/')
                        : []
                ),
                'x-ui_single' => self::responseData(
                    function_exists('xuiRequest')
                        ? xuiRequest($panel, 'GET', '/panel/api/inbounds/list')
                        : []
                ),
                'alireza_single' => self::alirezaCatalog($panel),
                's_ui' => self::suiCatalog($panel),
                'rebecca' => self::rebeccaCatalog($panel),
                default => [],
            };
        } catch (Throwable $e) {
            return [];
        }

        $rows = [];
        self::collectRows($data, $rows);
        return array_slice($rows, 0, self::MAX_CATALOG_ROWS);
    }

    private static function marzbanCatalog(string $name): array
    {
        if (!function_exists('getusers')) {
            return [];
        }

        $out = [];
        foreach (['active', 'on_hold', 'disabled'] as $status) {
            try {
                $data = getusers($name, $status);
                if (is_array($data)) {
                    $out[] = $data;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return $out;
    }

    private static function alirezaCatalog(array $panel): array
    {
        if (
            !function_exists('login')
            || !function_exists('alirezaCookiePath')
            || !class_exists('CurlRequest')
        ) {
            return [];
        }

        $code = (string) ($panel['code_panel'] ?? '');
        $base = rtrim((string) ($panel['url_panel'] ?? ''), '/');
        if ($code === '' || $base === '') {
            return [];
        }

        login($code);
        $request = new CurlRequest($base . '/xui/API/inbounds');
        $request->setHeaders(['Accept: application/json']);
        $request->setCookie(alirezaCookiePath($code));
        return self::responseData($request->get());
    }

    private static function suiCatalog(array $panel): array
    {
        if (!class_exists('CurlRequest')) {
            return [];
        }

        $base = rtrim((string) ($panel['url_panel'] ?? ''), '/');
        $token = trim((string) ($panel['password_panel'] ?? ''));
        if ($base === '' || $token === '') {
            return [];
        }

        $request = new CurlRequest($base . '/apiv2/clients');
        $request->setHeaders([
            'Accept: application/json',
            'Token: ' . $token,
        ]);
        return self::responseData($request->get());
    }

    private static function rebeccaCatalog(array $panel): array
    {
        if (!class_exists('CurlRequest')) {
            return [];
        }

        $base = rtrim((string) ($panel['url_panel'] ?? ''), '/');
        $token = trim((string) ($panel['password_panel'] ?? ''));
        if ($base === '' || $token === '') {
            return [];
        }

        $request = new CurlRequest($base . '/api/users?offset=0&limit=250');
        $request->setHeaders(['Accept: application/json']);
        $request->setBearerToken($token);
        return self::responseData($request->get());
    }

    private static function responseData($response): array
    {
        if (!is_array($response)) {
            return [];
        }

        if (isset($response['body']) && is_string($response['body'])) {
            $decoded = json_decode($response['body'], true);
            return is_array($decoded) ? $decoded : [];
        }

        return $response;
    }

    /**
     * Collect nested client/user-shaped rows, including client arrays embedded
     * as JSON strings inside X-UI inbound settings.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private static function collectRows($value, array &$rows, int $depth = 0): void
    {
        if ($depth > 8 || count($rows) >= self::MAX_CATALOG_ROWS) {
            return;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (
                $trimmed !== ''
                && strlen($trimmed) <= 131072
                && (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[')
            ) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    self::collectRows($decoded, $rows, $depth + 1);
                }
            }
            return;
        }

        if (!is_array($value)) {
            return;
        }

        if (self::looksLikeServiceRow($value)) {
            $rows[] = $value;
            if (count($rows) >= self::MAX_CATALOG_ROWS) {
                return;
            }
        }

        foreach ($value as $child) {
            if (is_array($child) || is_string($child)) {
                self::collectRows($child, $rows, $depth + 1);
            }
        }
    }

    private static function looksLikeServiceRow(array $row): bool
    {
        foreach ([
            'username', 'email', 'name', 'id', 'uuid', 'password',
            'subId', 'sub_id', 'access_key', 'subscription_url',
            'subscription', 'links', 'proxies',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }
        return false;
    }

    private static function rowUsername(array $row): string
    {
        foreach (['username', 'email', 'name', 'remark'] as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '' && strlen($value) <= 190) {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function rowMatchesPayload(array $row, array $targetSignatures): bool
    {
        foreach (self::scalarStrings($row) as $value) {
            $signatures = self::signatures($value);
            if ($signatures !== [] && array_intersect($targetSignatures, $signatures) !== []) {
                return true;
            }
        }
        return false;
    }

    private static function rowMatchesIdentity(array $row, array $targetIdentities): bool
    {
        if ($targetIdentities === []) {
            return false;
        }

        $lookup = array_fill_keys($targetIdentities, true);
        foreach (self::scalarStrings($row) as $value) {
            $normalized = strtolower(trim($value));
            if ($normalized !== '' && isset($lookup[$normalized])) {
                return true;
            }

            foreach (self::identities($value) as $identity) {
                if (isset($lookup[$identity])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array<int,string>
     */
    private static function scalarStrings($value, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }

        if (is_scalar($value)) {
            $string = trim((string) $value);
            return $string !== '' && strlen($string) <= 8192 ? [$string] : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $child) {
            if (is_string($child)) {
                $trimmed = trim($child);
                if (
                    $trimmed !== ''
                    && strlen($trimmed) <= 131072
                    && (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[')
                ) {
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        $out = array_merge($out, self::scalarStrings($decoded, $depth + 1));
                        continue;
                    }
                }
            }
            $out = array_merge($out, self::scalarStrings($child, $depth + 1));
            if (count($out) > 300) {
                break;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 300);
    }

    private static function runtimeMatches(
        array $runtime,
        array $targetSignatures,
        array $targetIdentities
    ): bool {
        $values = [];

        $subscription = trim((string) ($runtime['subscription_url'] ?? ''));
        if ($subscription !== '') {
            $values[] = $subscription;
        }

        foreach (['links', 'configs'] as $key) {
            $source = $runtime[$key] ?? [];
            if (is_string($source)) {
                $source = preg_split('/\\R+/', $source) ?: [];
            }
            if (is_array($source)) {
                foreach ($source as $item) {
                    if (is_scalar($item) && trim((string) $item) !== '') {
                        $values[] = trim((string) $item);
                    }
                }
            }
        }

        $identityLookup = array_fill_keys($targetIdentities, true);

        foreach ($values as $value) {
            if (array_intersect($targetSignatures, self::signatures($value)) !== []) {
                return true;
            }

            foreach (self::identities($value) as $identity) {
                if (isset($identityLookup[$identity])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private static function signatures(string $payload): array
    {
        $payload = str_replace(["\r\n", "\r"], "\n", trim($payload));
        if ($payload === '' || strlen($payload) > 65535) {
            return [];
        }

        $values = [$payload];
        foreach (preg_split('/\n+/', $payload) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $values[] = $line;
            }
        }

        $out = [];
        foreach (array_unique($values) as $value) {
            if (!self::supportedValue($value)) {
                continue;
            }

            $out[] = hash('sha256', $value);
            $semantic = self::semanticValue($value);
            if ($semantic !== '' && $semantic !== $value) {
                $out[] = hash('sha256', $semantic);
            }
        }

        return array_values(array_unique($out));
    }

    private static function semanticValue(string $value): string
    {
        if (stripos($value, 'vmess://') === 0) {
            $encoded = substr($value, 8);
            $decoded = self::base64UrlDecode($encoded);
            if ($decoded !== null) {
                $json = json_decode($decoded, true);
                if (is_array($json)) {
                    unset($json['ps'], $json['remarks'], $json['name']);
                    ksort($json);
                    $normalized = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (is_string($normalized)) {
                        return 'vmess://' . rtrim(strtr(base64_encode($normalized), '+/', '-_'), '=');
                    }
                }
            }
            return $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme'])) {
            return $value;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '') {
            return $value;
        }

        $user = isset($parts['user']) ? rawurlencode(rawurldecode((string) $parts['user'])) : '';
        $pass = isset($parts['pass']) ? ':' . rawurlencode(rawurldecode((string) $parts['pass'])) : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        $query = '';
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $params);
            if (is_array($params)) {
                ksort($params);
                $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                $query = $queryString !== '' ? '?' . $queryString : '';
            }
        }

        return $scheme . '://' . $auth . $host . $port . $path . $query;
    }

    private static function supportedValue(string $value): bool
    {
        if (preg_match('/^(vless|vmess|trojan|ss|socks|hysteria2|hy2):\/\//i', $value)) {
            return true;
        }

        $parts = parse_url($value);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    /**
     * @return array<int,string>
     */
    private static function identities(string $payload): array
    {
        $values = [];

        foreach (preg_split('/\\R+/', trim($payload)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match_all('/\\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\b/i', $line, $matches)) {
                foreach ($matches[0] as $match) {
                    $values[] = strtolower((string) $match);
                }
            }

            if (stripos($line, 'vmess://') === 0) {
                $decoded = self::base64UrlDecode(substr($line, 8));
                $json = $decoded !== null ? json_decode($decoded, true) : null;
                if (is_array($json)) {
                    foreach (['id', 'aid', 'email'] as $key) {
                        if (isset($json[$key]) && is_scalar($json[$key])) {
                            $values[] = strtolower(trim((string) $json[$key]));
                        }
                    }
                }
                continue;
            }

            $parts = parse_url($line);
            if (!is_array($parts)) {
                continue;
            }

            foreach (['user', 'pass'] as $key) {
                if (isset($parts[$key]) && trim((string) $parts[$key]) !== '') {
                    $values[] = strtolower(rawurldecode((string) $parts[$key]));
                }
            }

            if (!empty($parts['query'])) {
                parse_str((string) $parts['query'], $query);
                foreach (['id', 'uuid', 'token', 'sub', 'subid', 'username', 'user', 'email'] as $key) {
                    if (isset($query[$key]) && is_scalar($query[$key])) {
                        $values[] = strtolower(trim((string) $query[$key]));
                    }
                }
            }

            $path = trim((string) ($parts['path'] ?? ''), '/');
            if ($path !== '') {
                $segments = explode('/', $path);
                $last = rawurldecode((string) end($segments));
                if ($last !== '' && strlen($last) <= 512) {
                    $values[] = strtolower($last);
                    foreach (self::encodedTokenClaims($last) as $claim) {
                        $values[] = $claim;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(
            $values,
            static fn(string $value): bool => $value !== '' && strlen($value) <= 512
        )));
    }

    /**
     * Extract stable printable claims from signed/base64url subscription tokens.
     * Some panels expose a numeric user/service id in the token while their
     * admin catalog omits the public subscription URL.
     *
     * @return array<int,string>
     */
    private static function encodedTokenClaims(string $token): array
    {
        $claims = [];
        $parts = preg_split('/\\./', trim($token), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_slice($parts, 0, 4) as $part) {
            if (
                strlen($part) < 4
                || strlen($part) > 768
                || preg_match('/^[A-Za-z0-9_-]+$/', $part) !== 1
            ) {
                continue;
            }

            $decoded = self::base64UrlDecode($part);
            if (
                $decoded === null
                || $decoded === ''
                || strlen($decoded) > 1024
                || preg_match('/^[\\x20-\\x7E]+$/', $decoded) !== 1
            ) {
                continue;
            }

            foreach (preg_split('/[^A-Za-z0-9._@-]+/', $decoded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
                $value = strtolower(trim((string) $value));
                if ($value !== '' && strlen($value) <= 190) {
                    $claims[] = $value;
                }
            }
        }

        return array_values(array_unique($claims));
    }

    /**
     * @return array<int,string>
     */
    private static function usernameHints(string $payload): array
    {
        $out = [];

        foreach (preg_split('/\R+/', trim($payload)) ?: [] as $line) {
            $parts = parse_url(trim((string) $line));
            if (!is_array($parts)) {
                continue;
            }

            if (!empty($parts['fragment'])) {
                $fragment = trim(rawurldecode((string) $parts['fragment']));
                if ($fragment !== '' && strlen($fragment) <= 190) {
                    $out[] = $fragment;
                }
            }

            if (!empty($parts['query'])) {
                parse_str((string) $parts['query'], $query);
                foreach (['username', 'user', 'email', 'name'] as $key) {
                    if (isset($query[$key]) && is_scalar($query[$key])) {
                        $value = trim((string) $query[$key]);
                        if ($value !== '' && strlen($value) <= 190) {
                            $out[] = $value;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function payloadHost(string $payload): string
    {
        foreach (preg_split('/\R+/', trim($payload)) ?: [] as $line) {
            $parts = parse_url(trim((string) $line));
            if (is_array($parts) && !empty($parts['host'])) {
                return strtolower(rtrim((string) $parts['host'], '.'));
            }
        }
        return '';
    }

    private static function panelHostScore(array $panel, string $payloadHost): int
    {
        if ($payloadHost === '') {
            return 0;
        }

        foreach (['url_panel', 'linksubx'] as $key) {
            $url = trim((string) ($panel[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            $parts = parse_url($url);
            $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
            if ($host !== '' && hash_equals($host, $payloadHost)) {
                return 10;
            }
        }

        return 0;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $value = trim($value);
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
