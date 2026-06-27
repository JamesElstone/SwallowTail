<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

use InterfaceDB;
use RuntimeException;
use Throwable;

final class SwallowtailStorageMigrationService
{
    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
    ) {
    }

    public function enqueue(string $sourceBaseLocation, ?string $destinationBaseLocation, ?string $zpoolName, ?string $datasetName, ?int $requestedByUserId): ?int
    {
        return $this->planMigration($sourceBaseLocation, $destinationBaseLocation, $zpoolName, $datasetName, $requestedByUserId, false);
    }

    public function enqueueAndExcludeSource(string $sourceBaseLocation, ?int $requestedByUserId): ?int
    {
        return $this->planMigration($sourceBaseLocation, null, null, null, $requestedByUserId, true);
    }

    public function enqueueIfPhotosExist(string $sourceBaseLocation, ?string $destinationBaseLocation, ?string $zpoolName, ?string $datasetName, ?int $requestedByUserId): ?int
    {
        return $this->planMigration($sourceBaseLocation, $destinationBaseLocation, $zpoolName, $datasetName, $requestedByUserId, false);
    }

    private function planMigration(string $sourceBaseLocation, ?string $destinationBaseLocation, ?string $zpoolName, ?string $datasetName, ?int $requestedByUserId, bool $excludeSource): ?int
    {
        $sourceBaseLocation = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($sourceBaseLocation)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $destinationBaseLocation = $destinationBaseLocation === null || trim($destinationBaseLocation) === ''
            ? null
            : rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($destinationBaseLocation)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $photos = InterfaceDB::fetchAll(
            'SELECT id FROM photos WHERE storage_base_location = :source_base_location ORDER BY id',
            ['source_base_location' => $sourceBaseLocation]
        );
        if ($photos === []) {
            return null;
        }

        return InterfaceDB::transaction(function () use ($sourceBaseLocation, $destinationBaseLocation, $zpoolName, $datasetName, $requestedByUserId, $excludeSource, $photos): int {
            InterfaceDB::prepareExecute(
                "INSERT INTO storage_migration_jobs (
                    source_base_location,
                    destination_base_location,
                    zpool_name,
                    dataset_name,
                    requested_by_user_id,
                    status,
                    total_photos
                ) VALUES (
                    :source_base_location,
                    :destination_base_location,
                    :zpool_name,
                    :dataset_name,
                    :requested_by_user_id,
                    'queued',
                    :total_photos
                )",
                [
                    'source_base_location' => $sourceBaseLocation,
                    'destination_base_location' => $destinationBaseLocation,
                    'zpool_name' => $this->optionalString($zpoolName),
                    'dataset_name' => $this->optionalString($datasetName),
                    'requested_by_user_id' => ($requestedByUserId ?? 0) > 0 ? (int)$requestedByUserId : null,
                    'total_photos' => count($photos),
                ]
            );
            $jobId = (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM storage_migration_jobs');

            foreach ($photos as $photo) {
                InterfaceDB::prepareExecute(
                    "INSERT INTO storage_migration_job_items (
                        job_id,
                        photo_id,
                        source_base_location,
                        destination_base_location,
                        status
                    ) VALUES (
                        :job_id,
                        :photo_id,
                        :source_base_location,
                        :destination_base_location,
                        'queued'
                    )",
                    [
                        'job_id' => $jobId,
                        'photo_id' => (int)($photo['id'] ?? 0),
                        'source_base_location' => $sourceBaseLocation,
                        'destination_base_location' => $destinationBaseLocation,
                    ]
                );
            }

            if ($excludeSource) {
                $this->storageService->setLocationExcluded($sourceBaseLocation, true);
            }

            return $jobId;
        });
    }

    public function processNextJob(): bool
    {
        return $this->processPending(1) > 0;
    }

    public function processPending(int $limit = 10): int
    {
        $limit = max(1, $limit);
        $job = $this->oldestUnfinishedJob();
        if (!is_array($job)) {
            return 0;
        }

        $jobId = (int)$job['id'];
        $this->ensureJobItems($job);
        $this->recoverInterruptedItems($jobId);
        InterfaceDB::prepareExecute(
            "UPDATE storage_migration_jobs
             SET status = 'processing',
                 started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                 last_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => $jobId]
        );

        $items = $this->nextJobItems($jobId, $limit);
        $processed = 0;
        foreach ($items as $item) {
            $this->processJobItem($job, $item);
            $processed++;
        }

        $this->refreshParentJobStatus($jobId);

        return $processed;
    }

    private function oldestUnfinishedJob(): array|false
    {
        return InterfaceDB::fetchOne(
            "SELECT * FROM storage_migration_jobs
             WHERE status IN ('queued', 'processing', 'failed')
             ORDER BY created_at, id
             LIMIT 1"
        );
    }

    private function nextJobItems(int $jobId, int $limit): array
    {
        return InterfaceDB::fetchAll(
            "SELECT *
             FROM storage_migration_job_items
             WHERE job_id = :job_id
               AND status IN ('queued', 'failed')
             ORDER BY id
             LIMIT " . max(1, $limit),
            ['job_id' => $jobId]
        );
    }

    private function ensureJobItems(array $job): void
    {
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0 || InterfaceDB::countWhere('storage_migration_job_items', 'job_id', $jobId) > 0) {
            return;
        }

        $source = $this->normaliseBase((string)($job['source_base_location'] ?? ''));
        $fixedDestination = $this->optionalString($job['destination_base_location'] ?? null);
        $photos = InterfaceDB::fetchAll(
            'SELECT id FROM photos WHERE storage_base_location = :source ORDER BY id',
            ['source' => $source]
        );

        InterfaceDB::transaction(function () use ($jobId, $source, $fixedDestination, $photos): void {
            foreach ($photos as $photo) {
                InterfaceDB::prepareExecute(
                    "INSERT INTO storage_migration_job_items (
                        job_id,
                        photo_id,
                        source_base_location,
                        destination_base_location,
                        status
                    ) VALUES (
                        :job_id,
                        :photo_id,
                        :source_base_location,
                        :destination_base_location,
                        'queued'
                    )",
                    [
                        'job_id' => $jobId,
                        'photo_id' => (int)($photo['id'] ?? 0),
                        'source_base_location' => $source,
                        'destination_base_location' => $fixedDestination,
                    ]
                );
            }

            InterfaceDB::prepareExecute(
                'UPDATE storage_migration_jobs SET total_photos = :total, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['id' => $jobId, 'total' => count($photos)]
            );
        });
    }

    private function recoverInterruptedItems(int $jobId): void
    {
        InterfaceDB::prepareExecute(
            "UPDATE storage_migration_job_items
             SET status = 'failed',
                 last_error = 'Migration item was interrupted before completion.',
                 updated_at = CURRENT_TIMESTAMP
             WHERE job_id = :job_id
               AND status = 'processing'",
            ['job_id' => $jobId]
        );
    }

    private function processJobItem(array $job, array $item): void
    {
        $jobId = (int)($job['id'] ?? 0);
        $itemId = (int)($item['id'] ?? 0);
        $photo = InterfaceDB::fetchOne(
            'SELECT id, original_sha256, storage_base_location FROM photos WHERE id = :id LIMIT 1',
            ['id' => (int)($item['photo_id'] ?? 0)]
        );
        if (!is_array($photo)) {
            $this->failJobItem($jobId, $itemId, 'Photo no longer exists for migration item.');
            return;
        }

        $photoId = (int)($photo['id'] ?? 0);
        $checksum = (string)($photo['original_sha256'] ?? '');
        $source = $this->normaliseBase((string)($item['source_base_location'] ?? $job['source_base_location'] ?? ''));

        try {
            $destination = $this->optionalString($item['destination_base_location'] ?? null)
                ?? $this->optionalString($job['destination_base_location'] ?? null)
                ?? (string)$this->storageService
                ->writableLocationForChecksumExcluding($checksum, 0, [$source])['storage_base_location'];

            InterfaceDB::prepareExecute(
                "UPDATE storage_migration_job_items
                 SET status = 'processing',
                     destination_base_location = :destination_base_location,
                     last_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                [
                    'id' => $itemId,
                    'destination_base_location' => $destination,
                ]
            );

            $files = $this->copyChecksumFamily($source, $destination, $checksum);
            InterfaceDB::transaction(function () use ($photoId, $source, $destination, $files): void {
                InterfaceDB::prepareExecute(
                    'UPDATE photos SET storage_base_location = :destination, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND storage_base_location = :source',
                    [
                        'id' => $photoId,
                        'source' => $source,
                        'destination' => $destination,
                    ]
                );
                $this->photoLibraryService->recordPhotoAudit($photoId, null, null, null, 'storage_location_migrated', [
                    'source_base_location' => $source,
                    'destination_base_location' => $destination,
                    'files' => array_map('basename', $files),
                ]);
            });
            foreach ($files as $oldPath => $_newPath) {
                @unlink((string)$oldPath);
            }
            $this->removeEmptyChecksumFolders($source, $checksum);
            InterfaceDB::prepareExecute(
                "UPDATE storage_migration_job_items
                 SET status = 'succeeded',
                     file_count = :file_count,
                     last_error = NULL,
                     completed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                ['id' => $itemId, 'file_count' => count($files)]
            );
        } catch (Throwable $exception) {
            $this->failJobItem($jobId, $itemId, $exception->getMessage());
        }
    }

    private function failJobItem(int $jobId, int $itemId, string $message): void
    {
        $message = substr($message, 0, 4000);
        InterfaceDB::prepareExecute(
            "UPDATE storage_migration_job_items
             SET status = 'failed',
                 last_error = :last_error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => $itemId, 'last_error' => $message]
        );
        InterfaceDB::prepareExecute(
            "UPDATE storage_migration_jobs
             SET status = 'processing',
                 last_error = :last_error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => $jobId, 'last_error' => $message]
        );
    }

    private function refreshParentJobStatus(int $jobId): void
    {
        $succeeded = InterfaceDB::countWhere('storage_migration_job_items', [
            'job_id' => $jobId,
            'status' => 'succeeded',
        ]);
        $outstanding = InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM storage_migration_job_items
             WHERE job_id = :job_id
               AND status IN ('queued', 'processing', 'failed')",
            ['job_id' => $jobId]
        );

        if ((int)$outstanding <= 0) {
            InterfaceDB::prepareExecute(
                "UPDATE storage_migration_jobs
                 SET status = 'succeeded',
                     moved_photos = :moved_photos,
                     last_error = NULL,
                     completed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                ['id' => $jobId, 'moved_photos' => $succeeded]
            );
            return;
        }

        InterfaceDB::prepareExecute(
            "UPDATE storage_migration_jobs
             SET status = 'processing',
                 moved_photos = :moved_photos,
                 completed_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => $jobId, 'moved_photos' => $succeeded]
        );
    }

    /**
     * @return array<string, string>
     */
    private function copyChecksumFamily(string $source, string $destination, string $checksum): array
    {
        $sourcePath = $this->storageService->imagePath($source, $checksum, 'source');
        $sourceFolder = dirname($sourcePath);
        $files = glob($sourceFolder . DIRECTORY_SEPARATOR . $checksum . '_*.*') ?: [];
        if ($files === []) {
            throw new RuntimeException('No SwallowTail files were found for migration.');
        }

        $copied = [];
        foreach ($files as $oldPath) {
            if (!is_file($oldPath)) {
                continue;
            }

            $newPath = dirname($this->storageService->imagePath($destination, $checksum, 'source'))
                . DIRECTORY_SEPARATOR . basename($oldPath);
            $this->storageService->ensureDirectoryForPath($newPath);
            if (!@copy($oldPath, $newPath)) {
                throw new RuntimeException('Unable to copy storage file during migration.');
            }
            if (hash_file('sha256', $oldPath) !== hash_file('sha256', $newPath)) {
                @unlink($newPath);
                throw new RuntimeException('Copied storage file failed SHA-256 verification.');
            }
            $copied[$oldPath] = $newPath;
        }

        return $copied;
    }

    private function removeEmptyChecksumFolders(string $base, string $checksum): void
    {
        $folder = dirname($this->storageService->imagePath($base, $checksum, 'source'));
        @rmdir($folder);
        @rmdir(dirname($folder));
    }

    private function normaliseBase(string $base): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($base)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
