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
    private const IMAGE_TYPES = ['embedded', 'filtered', 'thumbnail', 'original'];
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
        if ($photoId <= 0 || !InterfaceDB::tableExists('photo_conversion_jobs')) {
            return [];
        }

        $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
        if ($photo === null) {
            return [];
        }

        $storage = new SwallowtailStorageService();
        $sha256 = (string)($photo['original_sha256'] ?? '');
        $base = (string)($photo['storage_base_location'] ?? '');
        $sourcePath = $storage->imagePath($base, $sha256, 'source');
        $jobs = [];

        foreach ([
            'embedded' => 'high',
            'thumbnail' => $priority,
            'original' => 'normal',
        ] as $imageType => $jobPriority) {
            $outputPath = $storage->imagePath($base, $sha256, $imageType);
            $dimensions = $this->dimensionsForImageType($imageType);
            $jobId = $this->enqueueImageJob(
                $photoId,
                $imageType,
                $sourcePath,
                $outputPath,
                null,
                1,
                $jobPriority,
                null,
                $dimensions['width'],
                $dimensions['height']
            );

            $jobs[$imageType] = [
                'job_id' => $jobId,
                'status' => $jobId !== null ? 'queued' : 'not_queued',
            ];
        }

        return $jobs;
    }

    public function enqueueFilteredRefresh(
        int $photoId,
        string $profilePath,
        int $profileVersion,
        ?int $requestedByUserId = null,
        ?int $outputWidth = null,
        ?int $outputHeight = null
    ): ?int {
        $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
        if ($photo === null) {
            return null;
        }

        $storage = new SwallowtailStorageService();
        $sha256 = (string)($photo['original_sha256'] ?? '');
        $base = (string)($photo['storage_base_location'] ?? '');
        $filteredPath = $storage->imagePath($base, $sha256, 'filtered');
        $sourcePath = $storage->imagePath($base, $sha256, 'source');

        $filteredJobId = $this->enqueueImageJob(
            $photoId,
            'filtered',
            $sourcePath,
            $filteredPath,
            $profilePath,
            $profileVersion,
            'high',
            $requestedByUserId,
            $outputWidth,
            $outputHeight
        );

        $thumb = $this->dimensionsForImageType('thumbnail');
        $this->enqueueImageJob(
            $photoId,
            'thumbnail',
            $sourcePath,
            $storage->imagePath($base, $sha256, 'thumbnail'),
            $profilePath,
            $profileVersion,
            'normal',
            $requestedByUserId,
            $thumb['width'],
            $thumb['height']
        );

        return $filteredJobId;
    }

    public function enqueueImageJob(
        int $photoId,
        string $imageType,
        string $inputPath,
        string $outputPath,
        ?string $profilePath = null,
        int $profileVersion = 1,
        string $priority = 'normal',
        ?int $requestedByUserId = null,
        ?int $outputWidth = null,
        ?int $outputHeight = null
    ): ?int {
        if ($photoId <= 0 || !InterfaceDB::tableExists('photo_conversion_jobs')) {
            return null;
        }

        $imageType = $this->normaliseImageType($imageType);
        $priority = $this->normalisePriority($priority);
        $profileVersion = max(1, $profileVersion);
        $outputWidth = $this->nullablePositiveInt($outputWidth);
        $outputHeight = $this->nullablePositiveInt($outputHeight);

        if (($outputWidth === null) !== ($outputHeight === null)) {
            throw new InvalidArgumentException('Conversion output width and height must be supplied together.');
        }

        $existingJobId = InterfaceDB::fetchColumn(
            "SELECT id
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = :image_type
               AND profile_version = :profile_version
               AND status IN ('queued', 'processing')
             ORDER BY id DESC
             LIMIT 1",
            [
                'photo_id' => $photoId,
                'image_type' => $imageType,
                'profile_version' => $profileVersion,
            ]
        );

        if ($existingJobId !== false && $existingJobId !== null) {
            $this->setPhotoConversionState($photoId, 'processing');
            return (int)$existingJobId;
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO photo_conversion_jobs (
                photo_id,
                job_type,
                image_type,
                input_path,
                profile_path,
                output_path,
                output_width,
                output_height,
                profile_version,
                requested_by_user_id,
                priority,
                status
            ) VALUES (
                :photo_id,
                'image',
                :image_type,
                :input_path,
                :profile_path,
                :output_path,
                :output_width,
                :output_height,
                :profile_version,
                :requested_by_user_id,
                :priority,
                'queued'
            )",
            [
                'photo_id' => $photoId,
                'image_type' => $imageType,
                'input_path' => $this->normaliseRequiredPath($inputPath, 1000),
                'profile_path' => $this->normaliseOptionalPath($profilePath, 1000),
                'output_path' => $this->normaliseRequiredPath($outputPath, 1000),
                'output_width' => $outputWidth,
                'output_height' => $outputHeight,
                'profile_version' => $profileVersion,
                'requested_by_user_id' => $this->nullablePositiveInt($requestedByUserId),
                'priority' => $priority,
            ]
        );

        $jobId = $this->lastInsertId();
        $this->setPhotoConversionState($photoId, 'processing');
        $this->notifyRedis($jobId, $imageType, $priority);

        return $jobId;
    }

    public function queuedJobs(int $limit = 50): array
    {
        if (!InterfaceDB::tableExists('photo_conversion_jobs')) {
            return [];
        }

        $limit = max(1, min(200, $limit));

        return InterfaceDB::fetchAll(
            "SELECT *
             FROM photo_conversion_jobs
             WHERE status = 'queued'
               AND available_at <= CURRENT_TIMESTAMP
             ORDER BY
               CASE image_type
                 WHEN 'embedded' THEN 1
                 WHEN 'filtered' THEN 2
                 WHEN 'thumbnail' THEN 3
                 WHEN 'original' THEN 4
                 ELSE 5
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

    private function notifyRedis(int $jobId, string $imageType, string $priority): void
    {
        $host = trim((string)AppConfigurationStore::get('swallowtail.redis.host', '127.0.0.1'));
        $port = (int)AppConfigurationStore::get('swallowtail.redis.port', 6379);
        $urgentQueue = trim((string)AppConfigurationStore::get('swallowtail.redis.urgent_queue', 'swallowtail:conversion:urgent'));
        $normalQueue = trim((string)AppConfigurationStore::get('swallowtail.redis.normal_queue', 'swallowtail:conversion:normal'));

        if ($host === '' || $port <= 0) {
            return;
        }

        $queue = $imageType === 'embedded' || $imageType === 'filtered' || $priority === 'high' ? $urgentQueue : $normalQueue;
        if ($queue === '') {
            return;
        }

        try {
            $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0.1);
            if (!is_resource($socket)) {
                return;
            }

            stream_set_timeout($socket, 0, 200000);
            $payload = json_encode(['job_id' => $jobId], JSON_THROW_ON_ERROR);
            $command = "*3\r\n"
                . "$5\r\nLPUSH\r\n"
                . '$' . strlen($queue) . "\r\n" . $queue . "\r\n"
                . '$' . strlen($payload) . "\r\n" . $payload . "\r\n";

            fwrite($socket, $command);
            fgets($socket);
            fclose($socket);

            InterfaceDB::prepareExecute(
                'UPDATE photo_conversion_jobs SET redis_notified_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['id' => $jobId]
            );
        } catch (Throwable) {
            if (isset($socket) && is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function setPhotoConversionState(int $photoId, string $state): void
    {
        if ($photoId <= 0 || !InterfaceDB::tableExists('photos')) {
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE photos SET conversion_state = :state WHERE id = :photo_id',
            [
                'photo_id' => $photoId,
                'state' => $state,
            ]
        );
    }

    private function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));
        if (!in_array($imageType, self::IMAGE_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported image type.');
        }

        return $imageType;
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

    private function dimensionsForImageType(string $imageType): array
    {
        if ($imageType !== 'thumbnail') {
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
