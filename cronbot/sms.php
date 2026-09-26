<?php

declare(strict_types=1);

chdir(__DIR__);
ini_set('error_log', 'error_log');
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Support/Logger.php';
require_once __DIR__ . '/../src/Support/SmsService.php';

try {
    BluebotSms::seedTemplates();
    BluebotSms::processQueue(60);
} catch (Throwable $e) {
    bluebotLog('warning', 'SMS cron failed', ['error' => $e->getMessage()]);
}
