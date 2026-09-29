<?php

declare(strict_types=1);

final class ExternalPanelSmsSync
{
    private const RECENT_LIMIT = 120;
    private const NEW_EVENT_WINDOW = 900;
    private const RESET_TRAFFIC_MIN_BYTES = 10485760;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function run(): array
    {
        $stats = [
            'panels' => 0,
            'users' => 0,
            'baselined' => 0,
            'activated' => 0,
            'renewed' => 0,
            'queued' => 0,
            'sent' => 0,
            'skipped_no_phone' => 0,
            'errors' => 0,
        ];

        $panels = $this->enabledPanels();
        foreach ($panels as $panel) {
            $stats['panels']++;
            try {
                $users = $this->recentUsers($panel);
                foreach ($users as $rawUser) {
                    if (!is_array($rawUser)) {
                        continue;
                    }
                    $stats['users']++;
                    $result = $this->syncUser($panel, $rawUser);
                    foreach ($result as $key => $value) {
                        if (isset($stats[$key])) {
                            $stats[$key] += (int) $value;
                        }
                    }
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                if (function_exists('bluebotLog')) {
                    bluebotLog('warning', 'External panel SMS sync failed', [
                        'panel' => (string) ($panel['name_panel'] ?? ''),
                        'type' => (string) ($panel['type'] ?? ''),
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    error_log('External panel SMS sync failed: ' . $e->getMessage());
                }
            }
        }

        return $stats;
    }

    private function enabledPanels(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM marzban_panel
             WHERE COALESCE(status,'active') <> 'disabled'
               AND type IN ('marzban')
             ORDER BY id ASC"
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * PasarGuard is API-compatible with the Marzban adapter used by BlueBot.
     * We intentionally poll only the newest created/edited users, avoiding a
     * full user-table scan every minute on large panels.
     */
    private function recentUsers(array $panel): array
    {
        $type = strtolower(trim((string) ($panel['type'] ?? '')));
        if ($type !== 'marzban') {
            return [];
        }

        return $this->recentMarzbanUsers($panel);
    }

    private function recentMarzbanUsers(array $panel): array
    {
        $codePanel = trim((string) ($panel['code_panel'] ?? ''));
        if ($codePanel === '') {
            return [];
        }

        if (!function_exists('token_panel')) {
            throw new RuntimeException('Marzban/PasarGuard adapter is not loaded.');
        }

        $token = token_panel($codePanel);
        if (!is_array($token) || empty($token['access_token'])) {
            $message = is_array($token) ? (string) ($token['error'] ?? 'Panel authentication failed.') : 'Panel authentication failed.';
            throw new RuntimeException($message);
        }

        $baseUrl = rtrim(trim((string) ($panel['url_panel'] ?? '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $queries = [
            ['offset' => 0, 'limit' => self::RECENT_LIMIT, 'sort' => '-created_at'],
            ['offset' => 0, 'limit' => self::RECENT_LIMIT, 'sort' => '-edit_at'],
        ];

        $users = [];
        $modernWorked = false;

        foreach ($queries as $query) {
            $response = $this->panelGetJson(
                $baseUrl . '/api/users?' . http_build_query($query),
                (string) $token['access_token']
            );

            if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300) {
                $rows = $this->extractUsers((array) ($response['data'] ?? []));
                if ($rows !== []) {
                    $modernWorked = true;
                }
                foreach ($rows as $row) {
                    $key = $this->externalIdentity($row);
                    if ($key !== '') {
                        $users[$key] = $row;
                    }
                }
                continue;
            }

            if (!in_array((int) ($response['status'] ?? 0), [400, 404, 422], true)) {
                throw new RuntimeException('Panel user list returned HTTP ' . (int) ($response['status'] ?? 0));
            }
        }

        if ($modernWorked || $users !== []) {
            return array_values($users);
        }

        if (function_exists('getusers')) {
            foreach (['active', 'on_hold', 'disabled', 'limited', 'expired'] as $status) {
                $legacy = getusers((string) ($panel['name_panel'] ?? ''), $status);
                foreach ($this->extractUsers(is_array($legacy) ? $legacy : []) as $row) {
                    $key = $this->externalIdentity($row);
                    if ($key !== '') {
                        $users[$key] = $row;
                    }
                }
            }
        }

        return array_values($users);
    }

    private function panelGetJson(string $url, string $accessToken): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is required.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
                'User-Agent: BlueBot-External-SMS/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Panel connection failed: ' . ($error ?: 'network error'));
        }

        $decoded = json_decode((string) $body, true);
        return [
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function extractUsers(array $payload): array
    {
        if (isset($payload['users']) && is_array($payload['users'])) {
            return array_values(array_filter($payload['users'], 'is_array'));
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        foreach (['data', 'result', 'items'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                if (isset($payload[$key]['users']) && is_array($payload[$key]['users'])) {
                    return array_values(array_filter($payload[$key]['users'], 'is_array'));
                }
                if (array_is_list($payload[$key])) {
                    return array_values(array_filter($payload[$key], 'is_array'));
                }
            }
        }

        return [];
    }

    private function syncUser(array $panel, array $rawUser): array
    {
        $result = [
            'baselined' => 0,
            'activated' => 0,
            'renewed' => 0,
            'queued' => 0,
            'sent' => 0,
            'skipped_no_phone' => 0,
            'errors' => 0,
        ];

        $user = $this->normalizeExternalUser($rawUser);
        if ($user['username'] === '') {
            return $result;
        }

        $panelCode = trim((string) ($panel['code_panel'] ?? ''));
        if ($panelCode === '') {
            return $result;
        }

        $previous = $this->loadState($panelCode, $user['external_id'], $user['username']);
        $phone = $this->extractPhone($user['note']);
        if ($phone === '') {
            $phone = $this->fallbackPhoneFromBluebot($panel, $user['username']);
        }

        $fingerprint = $this->fingerprint($user, $phone);
        $now = time();
        $event = null;

        if (!$previous) {
            if ($phone !== '' && $user['created_at'] > 0 && $user['created_at'] >= $now - self::NEW_EVENT_WINDOW) {
                $event = 'service_activated';
            } elseif ($phone !== '' && $user['edit_at'] > 0 && $user['edit_at'] >= $now - self::NEW_EVENT_WINDOW) {
                $event = 'service_renewed';
            } else {
                $result['baselined']++;
            }
        } else {
            $oldPhone = (string) ($previous['phone'] ?? '');
            $phoneChanged = $phone !== '' && $oldPhone !== $phone;
            $expireRaised = (int) $user['expire'] > (int) ($previous['expire_at'] ?? 0);
            $limitRaised = (int) $user['data_limit'] > (int) ($previous['data_limit'] ?? 0);
            $trafficReset = (int) ($previous['used_traffic'] ?? 0) >= self::RESET_TRAFFIC_MIN_BYTES
                && (int) $user['used_traffic'] + self::RESET_TRAFFIC_MIN_BYTES < (int) ($previous['used_traffic'] ?? 0);
            $becameActive = !in_array((string) ($previous['status'] ?? ''), ['active', 'unknown'], true)
                && in_array($user['status'], ['active', 'unknown'], true);

            if ($phoneChanged && in_array($user['status'], ['active', 'unknown'], true)) {
                $event = 'service_activated';
            } elseif ($phone !== '' && ($expireRaised || $limitRaised || $trafficReset || $becameActive)) {
                $event = 'service_renewed';
            }
        }

        if ($event !== null && $phone === '') {
            $result['skipped_no_phone']++;
            $event = null;
        }

        if ($event !== null) {
            try {
                $params = $event === 'service_activated'
                    ? [
                        'service' => 'اشتراک',
                        'username' => $user['username'],
                        'expire_date' => $this->formatExpire($user['expire']),
                    ]
                    : [
                        'username' => $user['username'],
                        'expire_date' => $this->formatExpire($user['expire']),
                    ];

                $dedupeSeed = implode('|', [
                    'external-panel',
                    $panelCode,
                    $user['external_id'] ?: $user['username'],
                    $event,
                    $fingerprint,
                ]);

                $deliveryId = BluebotSms::queue(
                    $event,
                    $phone,
                    $params,
                    null,
                    null,
                    'panel:' . $panelCode . ':' . ($user['external_id'] ?: $user['username']),
                    $dedupeSeed
                );

                if ($deliveryId) {
                    $result['queued']++;
                    $dispatch = BluebotSms::dispatchNow($deliveryId);
                    if (!empty($dispatch['sent'])) {
                        $result['sent']++;
                    }
                }

                if ($event === 'service_activated') {
                    $result['activated']++;
                } else {
                    $result['renewed']++;
                }
            } catch (Throwable $e) {
                $result['errors']++;
                if (function_exists('bluebotLog')) {
                    bluebotLog('warning', 'External panel event SMS failed', [
                        'panel' => (string) ($panel['name_panel'] ?? ''),
                        'username' => $user['username'],
                        'event' => $event,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } elseif ($phone === '') {
            $result['skipped_no_phone']++;
        }

        $this->saveState($panel, $user, $phone, $fingerprint, $event !== null ? $now : null);
        return $result;
    }

    private function normalizeExternalUser(array $row): array
    {
        $username = trim((string) ($row['username'] ?? $row['email'] ?? $row['name'] ?? ''));
        $externalId = trim((string) ($row['id'] ?? $row['uuid'] ?? $row['user_id'] ?? ''));
        $note = '';
        foreach (['note', 'remark', 'description', 'comment'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                $note = trim((string) $row[$key]);
                break;
            }
        }

        return [
            'external_id' => mb_substr($externalId, 0, 191),
            'username' => mb_substr($username, 0, 191),
            'note' => mb_substr($note, 0, 1000),
            'status' => strtolower(trim((string) ($row['status'] ?? 'unknown'))) ?: 'unknown',
            'expire' => $this->parseTimestamp($row['expire'] ?? $row['expire_at'] ?? $row['expiryTime'] ?? 0),
            'data_limit' => max(0, (int) ($row['data_limit'] ?? $row['totalGB'] ?? $row['total'] ?? 0)),
            'used_traffic' => max(0, (int) ($row['used_traffic'] ?? $row['usage'] ?? 0)),
            'created_at' => $this->parseTimestamp($row['created_at'] ?? $row['createdAt'] ?? 0),
            'edit_at' => $this->parseTimestamp($row['edit_at'] ?? $row['updated_at'] ?? $row['updatedAt'] ?? 0),
        ];
    }

    private function parseTimestamp($value): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (int) $value;
            if ($number > 20000000000) {
                $number = (int) floor($number / 1000);
            }
            return max(0, $number);
        }

        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        $time = strtotime($value);
        return $time === false ? 0 : $time;
    }

    private function extractPhone(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = strtr($text, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);

        if (!preg_match_all('/(?:\+98|0098|98|0)?9(?:[\s-]?\d){9}/', $text, $matches)) {
            return '';
        }

        foreach ($matches[0] as $candidate) {
            $phone = BluebotSms::normalizePhone((string) $candidate);
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    private function fallbackPhoneFromBluebot(array $panel, string $username): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT u.number
                 FROM invoice i
                 INNER JOIN user u ON u.id = i.id_user
                 WHERE i.username = ? AND i.Service_location = ? AND COALESCE(u.sms_enabled,1) = 1
                 ORDER BY i.time_sell DESC, i.id_invoice DESC
                 LIMIT 1"
            );
            $stmt->execute([$username, (string) ($panel['name_panel'] ?? '')]);
            return BluebotSms::normalizePhone((string) ($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            return '';
        }
    }

    private function externalIdentity(array $row): string
    {
        $id = trim((string) ($row['id'] ?? $row['uuid'] ?? $row['user_id'] ?? ''));
        if ($id !== '') {
            return 'id:' . $id;
        }
        $username = trim((string) ($row['username'] ?? $row['email'] ?? $row['name'] ?? ''));
        return $username !== '' ? 'u:' . mb_strtolower($username, 'UTF-8') : '';
    }

    private function fingerprint(array $user, string $phone): string
    {
        return hash('sha256', json_encode([
            'phone' => $phone,
            'note' => $user['note'],
            'status' => $user['status'],
            'expire' => $user['expire'],
            'data_limit' => $user['data_limit'],
            'used_traffic' => $user['used_traffic'],
            'edit_at' => $user['edit_at'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function loadState(string $panelCode, string $externalId, string $username): ?array
    {
        if ($externalId !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM external_panel_sms_state WHERE panel_code=? AND external_id=? LIMIT 1'
            );
            $stmt->execute([$panelCode, $externalId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM external_panel_sms_state WHERE panel_code=? AND username=? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$panelCode, $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function saveState(array $panel, array $user, string $phone, string $fingerprint, ?int $notifiedAt): void
    {
        $externalId = $user['external_id'] !== '' ? $user['external_id'] : 'username:' . hash('sha256', $user['username']);
        $now = time();

        $stmt = $this->pdo->prepare(
            'INSERT INTO external_panel_sms_state
                (panel_code,panel_name,panel_type,external_id,username,phone,note,status,expire_at,data_limit,used_traffic,created_at_panel,edit_at_panel,fingerprint,last_seen_at,last_notified_at,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                panel_name=VALUES(panel_name),panel_type=VALUES(panel_type),username=VALUES(username),phone=VALUES(phone),note=VALUES(note),
                status=VALUES(status),expire_at=VALUES(expire_at),data_limit=VALUES(data_limit),used_traffic=VALUES(used_traffic),
                created_at_panel=VALUES(created_at_panel),edit_at_panel=VALUES(edit_at_panel),fingerprint=VALUES(fingerprint),
                last_seen_at=VALUES(last_seen_at),last_notified_at=COALESCE(VALUES(last_notified_at),last_notified_at),updated_at=VALUES(updated_at)'
        );
        $stmt->execute([
            (string) ($panel['code_panel'] ?? ''),
            (string) ($panel['name_panel'] ?? ''),
            (string) ($panel['type'] ?? ''),
            $externalId,
            $user['username'],
            $phone,
            $user['note'],
            $user['status'],
            $user['expire'],
            $user['data_limit'],
            $user['used_traffic'],
            $user['created_at'],
            $user['edit_at'],
            $fingerprint,
            $now,
            $notifiedAt,
            $now,
            $now,
        ]);
    }

    private function formatExpire(int $expire): string
    {
        return BluebotSms::formatDate($expire);
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS external_panel_sms_state (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                panel_code VARCHAR(100) NOT NULL DEFAULT '',
                panel_name VARCHAR(200) NOT NULL DEFAULT '',
                panel_type VARCHAR(50) NOT NULL DEFAULT '',
                external_id VARCHAR(191) NOT NULL DEFAULT '',
                username VARCHAR(191) NOT NULL DEFAULT '',
                phone VARCHAR(30) NOT NULL DEFAULT '',
                note TEXT NULL,
                status VARCHAR(50) NOT NULL DEFAULT '',
                expire_at BIGINT NOT NULL DEFAULT 0,
                data_limit BIGINT NOT NULL DEFAULT 0,
                used_traffic BIGINT NOT NULL DEFAULT 0,
                created_at_panel BIGINT NOT NULL DEFAULT 0,
                edit_at_panel BIGINT NOT NULL DEFAULT 0,
                fingerprint CHAR(64) NOT NULL DEFAULT '',
                last_seen_at BIGINT NOT NULL DEFAULT 0,
                last_notified_at BIGINT NULL,
                created_at BIGINT NOT NULL DEFAULT 0,
                updated_at BIGINT NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_external_panel_user (panel_code, external_id),
                KEY idx_external_panel_username (panel_code, username),
                KEY idx_external_panel_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
