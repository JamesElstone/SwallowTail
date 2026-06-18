<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailStorageMigrationService
{
    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
    ) {
    }

    public function enqueue(string $sourceBaseLocation, ?string $destinationBaseLocation, ?string $zpoolName, ?string $datasetName, ?int $requestedByUserId): ?int
    {
        if (!InterfaceDB::tableExists('storage_migration_jobs')) {
            return null;
        }

        $sourceBaseLocation = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($sourceBaseLocation)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $destinationBaseLocation = $destinationBaseLocation === null || trim($destinationBaseLocation) === ''
            ? null
            : rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($destinationBaseLocation)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        InterfaceDB::prepareExecute(
            "INSERT INTO storage_migration_jobs (
                source_base_location,
                destination_base_location,
                zpool_name,
                dataset_name,
                requested_by_user_id,
                status
            ) VALUES (
                :source_base_location,
                :destination_base_location,
                :zpool_name,
                :dataset_name,
                :requested_by_user_id,
                'queued'
            )",
            [
                'source_base_location' => $sourceBaseLocation,
                'destination_base_location' => $destinationBaseLocation,
                'zpool_name' => $this->optionalString($zpoolName),
                'dataset_name' => $this->optionalString($datasetName),
                'requested_by_user_id' => ($requestedByUserId ?? 0) > 0 ? (int)$requestedByUserId : null,
            ]
        );

        return (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM storage_migration_jobs');
    }

    public function enqueueIfPhotosExist(string $sourceBaseLocation, ?string $destinationBaseLocation, ?string $zpoolName, ?string $datasetName, ?int $requestedByUserId): ?int
    {
        if (!InterfaceDB::tableExists('photos')) {
            return null;
        }

        $count = InterfaceDB::countWhere('photos', 'storage_base_location', $this->normaliseBase($sourceBaseLocation));
        if ($count <= 0) {
            return null;
        }

        return $this->enqueue($sourceBaseLocation, $destinationBaseLocation, $zpoolName, $datasetName, $requestedByUserId);
    }

    public function processNextJob(): bool
    {
        if (!InterfaceDB::tableExists('storage_migration_jobs') || !InterfaceDB::tableExists('photos')) {
            return false;
        }

        $job = InterfaceDB::fetchOne(
            "SELECT * FROM storage_migration_jobs
             WHERE status IN ('queued', 'failed')
             ORDER BY created_at, id
             LIMIT 1"
        );
        if (!is_array($job)) {
            return false;
        }

        $jobId = (int)$job['id'];
        InterfaceDB::prepareExecute(
            "UPDATE storage_migration_jobs
             SET status = 'processing',
                 started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
                 last_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => $jobId]
        );

        try {
            $this->processJob($jobId, $job);
            InterfaceDB::prepareExecute(
                "UPDATE storage_migration_jobs
                 SET status = 'succeeded',
                     completed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                ['id' => $jobId]
            );

            return true;
        } catch (Throwable $exception) {
            InterfaceDB::prepareExecute(
                "UPDATE storage_migration_jobs
                 SET status = 'failed',
                     last_error = :last_error,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                [
                    'id' => $jobId,
                    'last_error' => substr($exception->getMessage(), 0, 4000),
                ]
            );

            return true;
        }
    }

    public function processPending(int $limit = 10): int
    {
        $processed = 0;
        for ($index = 0; $index < max(1, $limit); $index++) {
            if (!$this->processNextJob()) {
                break;
            }
            $processed++;
        }

        return $processed;
    }

    private function processJob(int $jobId, array $job): void
    {
        $source = $this->normaliseBase((string)($job['source_base_location'] ?? ''));
        $fixedDestination = $this->optionalString($job['destination_base_location'] ?? null);
        $photos = InterfaceDB::fetchAll(
            'SELECT id, original_sha256, storage_base_location FROM photos WHERE storage_base_location = :source ORDER BY id',
            ['source' => $source]
        );

        InterfaceDB::prepareExecute(
            'UPDATE storage_migration_jobs SET total_photos = :total WHERE id = :id',
            ['id' => $jobId, 'total' => count($photos)]
        );

        foreach ($photos as $photo) {
            $this->processPhoto($jobId, $photo, $source, $fixedDestination);
        }
    }

    private function processPhoto(int $jobId, array $photo, string $source, ?string $fixedDestination): void
    {
        $photoId = (int)($photo['id'] ?? 0);
        $checksum = (string)($photo['original_sha256'] ?? '');
        $destination = $fixedDestination ?? (string)$this->storageService
            ->writableLocationForChecksumExcluding($checksum, 0, [$source])['storage_base_location'];

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
                'processing'
            )",
            [
                'job_id' => $jobId,
                'photo_id' => $photoId,
                'source_base_location' => $source,
                'destination_base_location' => $destination,
            ]
        );
        $itemId = (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM storage_migration_job_items');

        try {
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
                "UPDATE storage_migration_job_items SET status = 'succeeded', file_count = :file_count, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                ['id' => $itemId, 'file_count' => count($files)]
            );
            InterfaceDB::prepareExecute(
                'UPDATE storage_migration_jobs SET moved_photos = moved_photos + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['id' => $jobId]
            );
        } catch (Throwable $exception) {
            InterfaceDB::prepareExecute(
                "UPDATE storage_migration_job_items SET status = 'failed', last_error = :last_error, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
                ['id' => $itemId, 'last_error' => substr($exception->getMessage(), 0, 4000)]
            );
            throw $exception;
        }
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
