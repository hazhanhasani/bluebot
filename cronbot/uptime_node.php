<?php
chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Panel/Adapters/Marzban.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';

$textbotlang = languagechange();

$errorTopic = select('topicid', 'idreport', 'report', 'errorreport', 'select');
$errorreport = is_array($errorTopic) ? ($errorTopic['idreport'] ?? null) : null;

$setting = select('setting', '*');
if (!is_array($setting)) {
    return;
}

$statusCron = json_decode((string) ($setting['cron_status'] ?? ''), true);
if (!is_array($statusCron) || empty($statusCron['uptime_node'])) {
    return;
}

$channelReport = trim((string) ($setting['Channel_Report'] ?? ''));
if ($channelReport === '') {
    return;
}

$marzbanList = select('marzban_panel', '*', 'type', 'marzban', 'fetchAll');
if (!is_array($marzbanList)) {
    return;
}

foreach ($marzbanList as $location) {
    if (!is_array($location) || empty($location['name_panel'])) {
        continue;
    }

    $response = Get_Nodes($location['name_panel']);
    if (!is_array($response)
        || !empty($response['error'])
        || (!empty($response['status']) && (int) $response['status'] !== 200)) {
        continue;
    }

    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded)) {
        continue;
    }

    $nodes = isset($decoded['nodes']) && is_array($decoded['nodes'])
        ? $decoded['nodes']
        : $decoded;

    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $status = trim((string) ($node['status'] ?? 'unknown'));
        if (in_array($status, ['connected', 'disabled'], true)) {
            continue;
        }

        $textnode = sprintf(
            $textbotlang['Admin']['report']['nodeDown'],
            (string) ($node['name'] ?? 'unknown'),
            $status,
            (string) ($node['message'] ?? '')
        );

        telegram('sendmessage', [
            'chat_id' => $channelReport,
            'message_thread_id' => $errorreport,
            'text' => $textnode,
            'parse_mode' => 'HTML',
        ]);
    }
}
