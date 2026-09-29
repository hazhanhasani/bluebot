<?php

declare(strict_types=1);

chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../src/Support/Logger.php';
require_once __DIR__ . '/../src/Services/DigitalServiceManager.php';

try {
    if (!BluebotDigitalServices::isAvailable($pdo)) {
        return;
    }

    $stats = BluebotDigitalServices::reconcileTgToolsProcessing($pdo, 25);
    if (($stats['completed'] ?? 0) > 0 || ($stats['failed'] ?? 0) > 0 || ($stats['errors'] ?? 0) > 0) {
        bluebotLog('info', 'TGTools digital service reconciliation completed', $stats);
    }
} catch (Throwable $e) {
    bluebotLog('warning', 'TGTools digital service cron failed', [
        'error' => $e->getMessage(),
    ]);
}
