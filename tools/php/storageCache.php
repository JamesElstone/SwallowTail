<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailServiceStatusService;
use Swallowtail\Service\SwallowtailStorageCacheService;
use Swallowtail\Service\SwallowtailStorageMigrationService;
use Swallowtail\Service\SwallowtailStorageService;

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$command = (string)($argv[1] ?? 'status');
$storage = new SwallowtailStorageService();

try {
    $payload = match ($command) {
        'refresh' => [
            'success' => true,
            'command' => 'refresh',
            'snapshot' => $storage->refreshStorageSnapshot(),
        ],
        'discover' => [
            'success' => true,
            'command' => 'discover',
            'snapshot' => $storage->liveStorageSnapshot(),
        ],
        'status' => [
            'success' => true,
            'command' => 'status',
            'cache' => (new SwallowtailStorageCacheService())->status(),
        ],
        'process-migrations' => (static function () use ($argv): array {
            $limit = max(1, (int)($argv[2] ?? 10));
            $processed = (new SwallowtailStorageMigrationService())->processPending($limit);
            $conversionActive = InterfaceDB::tableExists('photo_conversion_jobs')
                ? (int)InterfaceDB::fetchColumn("SELECT COUNT(1) FROM photo_conversion_jobs WHERE status IN ('queued','processing')")
                : 0;
            $migrationRemaining = InterfaceDB::tableExists('storage_migration_job_items')
                ? (int)InterfaceDB::fetchColumn("SELECT COUNT(1) FROM storage_migration_job_items WHERE status IN ('queued','failed')")
                : 0;
            $migrationFailed = InterfaceDB::tableExists('storage_migration_job_items')
                ? (int)InterfaceDB::fetchColumn("SELECT COUNT(1) FROM storage_migration_job_items WHERE status = 'failed'")
                : 0;

            return [
                'success' => true,
                'command' => 'process-migrations',
                'migration_item_limit' => $limit,
                'processed' => $processed,
                'processed_items' => $processed,
                'conversion_active_jobs' => $conversionActive,
                'migration_items_remaining' => $migrationRemaining,
                'migration_failed_items' => $migrationFailed,
            ];
        })(),
        'touch-service' => [
            'success' => (new SwallowtailServiceStatusService())->touchService((string)($argv[2] ?? '')),
            'command' => 'touch-service',
            'service' => (string)($argv[2] ?? ''),
        ],
        default => [
            'success' => false,
            'errors' => ['Unknown storage cache command.'],
        ],
    };
} catch (Throwable $exception) {
    $payload = [
        'success' => false,
        'errors' => [$exception->getMessage()],
    ];
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($payload['success']) ? 0 : 1);
