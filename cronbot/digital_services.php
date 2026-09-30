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

    $tonRate = BluebotDigitalServices::refreshTgToolsTonRateFromNobitex($pdo, false, 60);
    if (empty($tonRate['skipped'])) {
        bluebotLog(!empty($tonRate['ok']) ? 'info' : 'warning', 'Nobitex GRAMIRT TON rate refresh checked', [
            'ok' => !empty($tonRate['ok']),
            'rate_toman' => (float) ($tonRate['rate_toman'] ?? 0),
            'repriced' => (int) ($tonRate['repriced'] ?? 0),
            'message' => (string) ($tonRate['message'] ?? ''),
        ]);
    }

    $catalog = BluebotDigitalServices::maybeSyncTgToolsCatalog($pdo, 900);
    if (empty($catalog['skipped'])) {
        if (empty($catalog['remote_ok'])) {
            bluebotLog('warning', 'TGTools live catalog refresh used fallback data', [
                'created' => (int) ($catalog['created'] ?? 0),
                'updated' => (int) ($catalog['updated'] ?? 0),
                'error' => (string) ($catalog['remote_error'] ?? ''),
            ]);
        } elseif (($catalog['created'] ?? 0) > 0 || ($catalog['updated'] ?? 0) > 0) {
            bluebotLog('info', 'TGTools live catalog synchronized', [
                'created' => (int) ($catalog['created'] ?? 0),
                'updated' => (int) ($catalog['updated'] ?? 0),
            ]);
        }
    }

    $ozBootstrap = BluebotDigitalServices::maybeBootstrapOZVinooCatalog($pdo);
    if (empty($ozBootstrap['skipped'])) {
        bluebotLog(!empty($ozBootstrap['ok']) ? 'info' : 'warning', 'OZVinoo official catalog sync checked', [
            'ok' => !empty($ozBootstrap['ok']),
            'created' => (int) ($ozBootstrap['created'] ?? 0),
            'updated' => (int) ($ozBootstrap['updated'] ?? 0),
            'message' => (string) ($ozBootstrap['message'] ?? ''),
            'catalog_url' => (string) ($ozBootstrap['catalog_url'] ?? ''),
        ]);
    }

    $providerStats = BluebotProviderCatalogService::syncDueProviders($pdo);
    if (($providerStats['synced'] ?? 0) > 0 || ($providerStats['failed'] ?? 0) > 0) {
        bluebotLog('info', 'Digital service provider catalogs synchronized', $providerStats);
    }

    $genericSmmStats = BluebotDigitalServices::reconcileRegisteredSmmProcessing($pdo, 50);
    if (($genericSmmStats['completed'] ?? 0) > 0
        || ($genericSmmStats['failed'] ?? 0) > 0
        || ($genericSmmStats['partial'] ?? 0) > 0
        || ($genericSmmStats['errors'] ?? 0) > 0) {
        bluebotLog('info', 'Generic SMM digital service reconciliation completed', $genericSmmStats);
    }

    $tivaStats = BluebotDigitalServices::reconcileTivaNovinProcessing($pdo, 25);
    if (($tivaStats['completed'] ?? 0) > 0
        || ($tivaStats['failed'] ?? 0) > 0
        || ($tivaStats['partial'] ?? 0) > 0
        || ($tivaStats['errors'] ?? 0) > 0) {
        bluebotLog('info', 'TivaNovin digital service reconciliation completed', $tivaStats);
    }

    $stats = BluebotDigitalServices::reconcileTgToolsProcessing($pdo, 25);
    if (($stats['completed'] ?? 0) > 0 || ($stats['failed'] ?? 0) > 0 || ($stats['errors'] ?? 0) > 0) {
        bluebotLog('info', 'TGTools digital service reconciliation completed', $stats);
    }

    $ozStats = BluebotDigitalServices::reconcileOZVinooProcessing($pdo, 25);
    if (($ozStats['completed'] ?? 0) > 0 || ($ozStats['failed'] ?? 0) > 0 || ($ozStats['errors'] ?? 0) > 0) {
        bluebotLog('info', 'OZVinoo digital service reconciliation completed', $ozStats);
    }
} catch (Throwable $e) {
    bluebotLog('warning', 'Digital service cron failed', [
        'error' => $e->getMessage(),
    ]);
}
