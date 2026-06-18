<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

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
        'process-migrations' => [
            'success' => true,
            'command' => 'process-migrations',
            'processed' => (new SwallowtailStorageMigrationService())->processPending((int)($argv[2] ?? 10)),
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
