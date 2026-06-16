<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailConversionQueueService
{
    private const DERIVATIVE_TYPES = ['embedded', 'original_jpeg', 'preview', 'thumbnail', 'jpeg'];
    private const PRIORITIES = ['low', 'normal', 'high'];

    public function enqueueRawConversion(int $photoId, string $priority = 'normal'): ?int
    {
        foreach ($this->enqueueRawConversionJobs($photoId, $priority) as $job) {
            $jobId = $this->nullablePositiveInt($job['job_id'] ?? null);
            if ($jobId !== null) {
                return $jobId;
            }
        }

        return null;
    }

    public function enqueueRawConversionJobs(int $photoId, string $priority = 'normal'): array
    {
        if ($photoId <= 0 || !InterfaceDB::tableExists('swallowtail_photo_conversion_jobs')) {
            return [];
        }

        $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
        if ($photo === null) {
            return [];
        }

        $sha256 = (string)($photo['original_sha256'] ?? '');
        $storageLocationId = $this->nullablePositiveInt($photo['storage_location_id'] ?? null);
        $storage = new SwallowtailStorageService($this->storageRootForLocation($storageLocationId));
        $inputPath = $storage->absolutePath((string)($photo['original_storage_path'] ?? ''));
        $jobs = [];

        foreach ([
            'embedded' => 'high',
            'original_jpeg' => 'normal',
            'preview' => 'high',
            'thumbnail' => 'normal',
            'jpeg' => $priority,
        ] as $derivativeType => $jobPriority) {
            $outputStoragePath = $storage->derivativeRelativePath($sha256, $derivativeType);
            $dimensions = $this->dimensionsForDerivative($derivativeType);
            $jobId = $this->enqueueDerivativeJob(
                $photoId,
                $derivativeType,
                $inputPath,
                $storage->absolutePath($outputStoragePath),
                $outputStoragePath,
                $storageLocationId,
                null,
                1,
                $jobPriority,
                null,
                $dimensions['width'],
                $dimensions['height']
            );

            $jobs[$derivativeType] = [
                'job_id' => $jobId,
                'status' => $jobId !== null ? 'queued' : 'not_queued',
            ];
        }

        return $jobs;
    }

    public function enqueueDerivativeJob(
        int $photoId,
        string $derivativeType,
        string $inputPath,
        string $outputPath,
        string $outputStoragePath,
        ?int $outputStorageLocationId,
        ?string $pp3Path = null,
        int $profileVersion = 1,
        string $priority = 'normal',
        ?int $requestedByUserId = null,
        ?int $outputWidth = null,
        ?int $outputHeight = null
    ): ?int {
        if ($photoId <= 0 || !InterfaceDB::tableExists('swallowtail_photo_conversion_jobs')) {
            return null;
        }

        $derivativeType = $this->normaliseDerivativeType($derivativeType);
        $priority = $this->normalisePriority($priority);
        $profileVersion = max(1, $profileVersion);
        $outputWidth = $this->nullablePositiveInt($outputWidth);
        $outputHeight = $this->nullablePositiveInt($outputHeight);

        if (($outputWidth === null) !== ($outputHeight === null)) {
            throw new InvalidArgumentException('Conversion output width and height must be supplied together.');
        }

        $existingJobId = InterfaceDB::fetchColumn(
            "SELECT id
             FROM swallowtail_photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND derivative_type = :derivative_type
               AND profile_version = :profile_version
               AND status IN ('queued', 'processing')
             ORDER BY id DESC
             LIMIT 1",
            [
                'photo_id' => $photoId,
                'derivative_type' => $derivativeType,
                'profile_version' => $profileVersion,
            ]
        );

        if ($existingJobId !== false && $existingJobId !== null) {
            return (int)$existingJobId;
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_photo_conversion_jobs (
                photo_id,
                job_type,
                derivative_type,
                input_path,
                pp3_path,
                output_path,
                output_storage_path,
                output_storage_location_id,
                output_width,
                output_height,
                profile_version,
                requested_by_user_id,
                priority,
                status
            ) VALUES (
                :photo_id,
                'derivative',
                :derivative_type,
                :input_path,
                :pp3_path,
                :output_path,
                :output_storage_path,
                :output_storage_location_id,
                :output_width,
                :output_height,
                :profile_version,
                :requested_by_user_id,
                :priority,
                'queued'
            )",
            [
                'photo_id' => $photoId,
                'derivative_type' => $derivativeType,
                'input_path' => $this->normaliseRequiredPath($inputPath, 1000),
                'pp3_path' => $this->normaliseOptionalPath($pp3Path, 1000),
                'output_path' => $this->normaliseRequiredPath($outputPath, 1000),
                'output_storage_path' => $this->normaliseRequiredPath($outputStoragePath, 500),
                'output_storage_location_id' => $this->nullablePositiveInt($outputStorageLocationId),
                'output_width' => $outputWidth,
                'output_height' => $outputHeight,
                'profile_version' => $profileVersion,
                'requested_by_user_id' => $this->nullablePositiveInt($requestedByUserId),
                'priority' => $priority,
            ]
        );

        $jobId = $this->lastInsertId();
        $this->notifyRedis($jobId, $derivativeType, $priority);

        return $jobId;
    }

    public function enqueuePreviewRefresh(
        int $photoId,
        string $profilePath,
        int $profileVersion,
        ?int $requestedByUserId = null
    ): ?int {
        $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
        if ($photo === null) {
            return null;
        }

        $sha256 = (string)($photo['original_sha256'] ?? '');
        $storageLocationId = $this->nullablePositiveInt($photo['storage_location_id'] ?? null);
        $storage = new SwallowtailStorageService($this->storageRootForLocation($storageLocationId));
        $outputStoragePath = $storage->derivativeRelativePath($sha256, 'preview');

        return $this->enqueueDerivativeJob(
            $photoId,
            'preview',
            $storage->absolutePath((string)($photo['original_storage_path'] ?? '')),
            $storage->absolutePath($outputStoragePath),
            $outputStoragePath,
            $storageLocationId,
            $profilePath,
            $profileVersion,
            'high',
            $requestedByUserId,
            null,
            null
        );
    }

    public function queuedJobs(int $limit = 50): array
    {
        if (!InterfaceDB::tableExists('swallowtail_photo_conversion_jobs')) {
            return [];
        }

        $limit = max(1, min(200, $limit));

        return InterfaceDB::fetchAll(
            "SELECT *
             FROM swallowtail_photo_conversion_jobs
             WHERE status = 'queued'
               AND available_at <= CURRENT_TIMESTAMP
             ORDER BY
               CASE derivative_type
                 WHEN 'embedded' THEN 1
                 WHEN 'preview' THEN 2
                 WHEN 'thumbnail' THEN 3
                 WHEN 'jpeg' THEN 4
                 WHEN 'original_jpeg' THEN 5
                 ELSE 6
               END,
               CASE priority
                 WHEN 'high' THEN 1
                 WHEN 'normal' THEN 2
                 ELSE 3
               END,
               id
             LIMIT " . $limit
        );
    }

    private function notifyRedis(int $jobId, string $derivativeType, string $priority): void
    {
        $host = trim((string)AppConfigurationStore::get('swallowtail.redis.host', '127.0.0.1'));
        $port = (int)AppConfigurationStore::get('swallowtail.redis.port', 6379);
        $urgentQueue = trim((string)AppConfigurationStore::get('swallowtail.redis.urgent_queue', 'swallowtail:conversion:urgent'));
        $normalQueue = trim((string)AppConfigurationStore::get('swallowtail.redis.normal_queue', 'swallowtail:conversion:normal'));

        if ($host === '' || $port <= 0) {
            return;
        }

        $queue = $derivativeType === 'embedded' || $derivativeType === 'preview' || $priority === 'high' ? $urgentQueue : $normalQueue;
        if ($queue === '') {
            return;
        }

        try {
            $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0.25);
            if (!is_resource($socket)) {
                return;
            }

            stream_set_timeout($socket, 1);
            $payload = json_encode(['job_id' => $jobId], JSON_THROW_ON_ERROR);
            $command = "*3\r\n"
                . "$5\r\nLPUSH\r\n"
                . '$' . strlen($queue) . "\r\n" . $queue . "\r\n"
                . '$' . strlen($payload) . "\r\n" . $payload . "\r\n";

            fwrite($socket, $command);
            fgets($socket);
            fclose($socket);

            InterfaceDB::prepareExecute(
                'UPDATE swallowtail_photo_conversion_jobs SET redis_notified_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['id' => $jobId]
            );
        } catch (Throwable) {
            if (isset($socket) && is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function storageRootForLocation(?int $storageLocationId): string
    {
        if ($storageLocationId === null || !InterfaceDB::tableExists('swallowtail_storage_locations')) {
            return '';
        }

        $root = InterfaceDB::fetchColumn(
            'SELECT root_path FROM swallowtail_storage_locations WHERE id = :id LIMIT 1',
            ['id' => $storageLocationId]
        );

        return is_scalar($root) ? (string)$root : '';
    }

    private function normaliseDerivativeType(string $derivativeType): string
    {
        $derivativeType = strtolower(trim($derivativeType));
        if (!in_array($derivativeType, self::DERIVATIVE_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported derivative type.');
        }

        return $derivativeType;
    }

    private function normalisePriority(string $priority): string
    {
        $priority = strtolower(trim($priority));

        return in_array($priority, self::PRIORITIES, true) ? $priority : 'normal';
    }

    private function normaliseRequiredPath(string $path, int $maxLength): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new InvalidArgumentException('Conversion path must not be empty.');
        }

        return substr($path, 0, $maxLength);
    }

    private function normaliseOptionalPath(?string $path, int $maxLength): ?string
    {
        $path = trim((string)$path);

        return $path === '' ? null : substr($path, 0, $maxLength);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private function dimensionsForDerivative(string $derivativeType): array
    {
        if ($derivativeType !== 'thumbnail') {
            return ['width' => null, 'height' => null];
        }

        $size = (int)AppConfigurationStore::get('swallowtail.raw_conversion.thumbnail_max_pixels', 512);
        $size = max(1, min(4096, $size));

        return ['width' => $size, 'height' => $size];
    }

    private function lastInsertId(): int
    {
        if (InterfaceDB::driverName() === 'sqlite') {
            return (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        }

        return (int)InterfaceDB::fetchColumn('SELECT LAST_INSERT_ID()');
    }
}
