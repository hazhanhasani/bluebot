<?php
chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

$textbotlang = languagechange();

$adminIds = select('admin', 'id_admin', null, null, 'FETCH_COLUMN');
$adminIds = is_array($adminIds) ? $adminIds : [];

$panels = select('marzban_panel', '*', null, null, 'fetchAll');
$panels = is_array($panels) ? $panels : [];

$setting = select('setting', '*');
if (!is_array($setting)) {
    return;
}

$statusCron = json_decode((string) ($setting['cron_status'] ?? ''), true);
if (!is_array($statusCron) || empty($statusCron['uptime_panel'])) {
    return;
}

foreach ($panels as $panel) {
    if (!is_array($panel)) {
        continue;
    }

    $url = trim((string) ($panel['url_panel'] ?? ''));
    $parsedUrl = $url !== '' ? parse_url($url) : false;
    if (!is_array($parsedUrl) || empty($parsedUrl['host'])) {
        continue;
    }

    $scheme = strtolower((string) ($parsedUrl['scheme'] ?? 'https'));
    if (!in_array($scheme, ['http', 'https'], true)) {
        continue;
    }

    $address = (string) $parsedUrl['host'];
    $port = isset($parsedUrl['port'])
        ? (int) $parsedUrl['port']
        : ($scheme === 'http' ? 80 : 443);

    if ($port < 1 || $port > 65535 || checkConnection($address, $port)) {
        continue;
    }

    $textnode = sprintf(
        $textbotlang['Admin']['report']['panelDown'],
        (string) ($panel['name_panel'] ?? $address)
    );

    foreach ($adminIds as $adminId) {
        if (!isTelegramChatIdEmpty($adminId)) {
            sendmessage($adminId, $textnode, null, 'HTML');
        }
    }
}
