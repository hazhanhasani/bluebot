<?php

declare(strict_types=1);

chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../src/Support/Logger.php';
require_once __DIR__ . '/../src/Support/SmsService.php';
require_once __DIR__ . '/../src/Support/ExternalPanelSmsSync.php';

try {
    BluebotSms::seedTemplates();
    $sync = new ExternalPanelSmsSync($pdo);
    $stats = $sync->run();

    if (($stats['activated'] ?? 0) > 0 || ($stats['renewed'] ?? 0) > 0 || ($stats['errors'] ?? 0) > 0) {
        bluebotLog('info', 'External panel SMS sync completed', $stats);
    }
} catch (Throwable $e) {
    bluebotLog('warning', 'External panel SMS cron failed', ['error' => $e->getMessage()]);
}
