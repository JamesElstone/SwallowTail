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
    private const IMAGE_TYPES = ['embedded', 'thumbnail', 'original', 'preview', 'final'];
    private const PRIORITY_FINAL = 10;
    private const PRIORITY_ORIGINAL = 20;
    private const PRIORITY_THUMBNAIL = 45;
    private const PRIORITY_EMBEDDED = 40;
    private const PRIORITY_VIEWED_OTHER = 50;
    private const PRIORITY_VIEWED_ORIGINAL = 51;
    private const PRIORITY_PREVIEW = 50;
    private const PRIORITY_PREEMPT_THRESHOLD = 50;
    private const ENQUEUE_ATTEMPTS = 3;

    public function __construct(private readonly ?Closure $redisNotifier = null)
    {
    }

    public function enqueueRawConversion(int $photoId, string|int $priority = self::PRIORITY_EMBEDDED): ?int
    {
        foreach ($this->enqueueRawConversionJobs($photoId, $priority) as $job) {
            $jobId = $this->nullablePositiveInt($job['job_id'] ?? null);
            if ($jobId !== null) {
                return $jobId;
            }
        }

        return null;
    }

    public function enqueueRawConversionJobs(int $photoId, string|int $priority = self::PRIORITY_EMBEDDED): array
    {
        if ($photoId <= 0) {
            return [];
        }

        $notifyAfterCommit = !InterfaceDB::inTransaction();
        $jobs = $this->withRetryableQueueTransaction(function () use ($photoId, $priority): array {
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
                'embedded' => self::PRIORITY_EMBEDDED,
                'thumbnail' => self::PRIORITY_THUMBNAIL,
                'original' => self::PRIORITY_ORIGINAL,
            ] as $imageType => $jobPriority) {
                $jobPriority = $this->normalisePriority($jobPriority);
                $outputPath = $storage->imagePath($base, $sha256, $imageType);
                $profilePath = $imageType === 'thumbnail'
                    ? $this->writeThumbnailProfile($photo, $storage)
                    : null;
                $jobId = $this->enqueueImageJob(
                    $photoId,
                    $imageType,
                    $sourcePath,
                    $outputPath,
                    $profilePath,
                    1,
                    $jobPriority,
                    null
                );

                $jobs[$imageType] = [
                    'job_id' => $jobId,
                    'image_type' => $imageType,
                    'priority' => $jobPriority,
                    'status' => $jobId !== null ? 'queued' : 'not_queued',
                ];
            }

            $this->setPhotoConversionState($photoId, 'processing');

            return $jobs;
        });

        if ($notifyAfterCommit) {
            $this->notifyRedisForJobs($jobs);
        }

        return $jobs;
    }

    public function enqueuePreviewRefresh(
        int $photoId,
        string $profilePath,
        int $profileVersion,
        ?int $requestedByUserId = null
    ): ?int {
        return $this->enqueueProfiledRefresh($photoId, 'preview', $profilePath, $profileVersion, self::PRIORITY_PREVIEW, $requestedByUserId);
    }

    public function enqueueFinalRefresh(
        int $photoId,
        string $profilePath,
        int $profileVersion,
        ?int $requestedByUserId = null
    ): ?int {
        return $this->enqueueProfiledRefresh($photoId, 'final', $profilePath, $profileVersion, self::PRIORITY_FINAL, $requestedByUserId);
    }

    private function enqueueProfiledRefresh(
        int $photoId,
        string $imageType,
        string $profilePath,
        int $profileVersion,
        int $priority,
        ?int $requestedByUserId
    ): ?int {
        $notifyAfterCommit = !InterfaceDB::inTransaction();
        $jobs = $this->withRetryableQueueTransaction(function () use ($photoId, $imageType, $profilePath, $profileVersion, $priority, $requestedByUserId): array {
            $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
            if ($photo === null) {
                return [];
            }

            $storage = new SwallowtailStorageService();
            $sha256 = (string)($photo['original_sha256'] ?? '');
            $base = (string)($photo['storage_base_location'] ?? '');
            $outputPath = $storage->imagePath($base, $sha256, $imageType);
            $sourcePath = $storage->imagePath($base, $sha256, 'source');

            $jobId = $this->enqueueImageJob(
                $photoId,
                $imageType,
                $sourcePath,
                $outputPath,
                $profilePath,
                $profileVersion,
                $priority,
                $requestedByUserId
            );

            if ($imageType === 'preview') {
                $this->markObsoletePreviewJobs($photoId, [$jobId]);
            }
            $this->setPhotoConversionState($photoId, 'processing');

            return [
                $imageType => [
                    'job_id' => $jobId,
                    'image_type' => $imageType,
                    'priority' => $priority,
                ],
            ];
        });

        if ($notifyAfterCommit) {
            $this->notifyRedisForJobs($jobs);
        }

        return $this->nullablePositiveInt($jobs[$imageType]['job_id'] ?? null);
    }

    public function enqueueImageJob(
        int $photoId,
        string $imageType,
        string $inputPath,
        string $outputPath,
        ?string $profilePath = null,
        int $profileVersion = 1,
        string|int $priority = self::PRIORITY_ORIGINAL,
        ?int $requestedByUserId = null,
        ?int $outputWidth = null,
        ?int $outputHeight = null
    ): ?int {
        if ($photoId <= 0) {
            return null;
        }

        $imageType = $this->normaliseImageType($imageType);
        $priority = $this->normalisePriority($priority);
        $profilePath = $this->normaliseOptionalPath($profilePath, 1000);
        $profileVersion = max(1, $profileVersion);
        $outputWidth = $this->nullablePositiveInt($outputWidth);
        $outputHeight = $this->nullablePositiveInt($outputHeight);
        $inputPath = $this->normaliseRequiredPath($inputPath, 1000);
        $outputPath = $this->normaliseRequiredPath($outputPath, 1000);

        if (($outputWidth === null) !== ($outputHeight === null)) {
            throw new InvalidArgumentException('Conversion output width and height must be supplied together.');
        }

        $existingJobId = InterfaceDB::fetchColumn(
            "SELECT id
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = :image_type
               AND profile_version = :profile_version
               AND " . ($profilePath === null ? 'profile_path IS NULL' : 'profile_path = :profile_path') . "
               AND status IN ('queued', 'processing')
             ORDER BY id DESC
             LIMIT 1",
            array_filter([
                'photo_id' => $photoId,
                'image_type' => $imageType,
                'profile_version' => $profileVersion,
                'profile_path' => $profilePath,
            ], static fn(mixed $value): bool => $value !== null)
        );

        if ($existingJobId !== false && $existingJobId !== null) {
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
                'input_path' => $inputPath,
                'profile_path' => $profilePath,
                'output_path' => $outputPath,
                'output_width' => $outputWidth,
                'output_height' => $outputHeight,
                'profile_version' => $profileVersion,
                'requested_by_user_id' => $this->nullablePositiveInt($requestedByUserId),
                'priority' => $priority,
            ]
        );

        $jobId = InterfaceDB::fetchColumn(
            "SELECT id
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = :image_type
               AND profile_version = :profile_version
               AND input_path = :input_path
               AND output_path = :output_path
               AND " . ($profilePath === null ? 'profile_path IS NULL' : 'profile_path = :profile_path') . "
               AND status = 'queued'
             ORDER BY id DESC
             LIMIT 1",
            array_filter([
                'photo_id' => $photoId,
                'image_type' => $imageType,
                'profile_version' => $profileVersion,
                'input_path' => $inputPath,
                'output_path' => $outputPath,
                'profile_path' => $profilePath,
            ], static fn(mixed $value): bool => $value !== null)
        );

        return $this->nullablePositiveInt($jobId);
    }

    public function queuedJobs(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return InterfaceDB::fetchAll(
            "SELECT *
             FROM photo_conversion_jobs
             WHERE status = 'queued'
               AND available_at <= CURRENT_TIMESTAMP
             ORDER BY
               priority DESC,
               id
             LIMIT " . $limit
        );
    }

    /**
     * @return list<array{job_id: int, image_type: string, priority: int, previous_priority: int}>
     */
    public function boostQueuedJobsForViewedPhoto(int $photoId): array
    {
        if ($photoId <= 0) {
            return [];
        }

        $notifyAfterCommit = !InterfaceDB::inTransaction();
        $jobs = $this->withRetryableQueueTransaction(function () use ($photoId): array {
            $rows = InterfaceDB::fetchAll(
                "SELECT id, image_type, priority
                 FROM photo_conversion_jobs
                 WHERE photo_id = :photo_id
                   AND status = 'queued'",
                ['photo_id' => $photoId]
            );
            $boosted = [];

            foreach ($rows as $row) {
                $jobId = $this->nullablePositiveInt($row['id'] ?? null);
                if ($jobId === null) {
                    continue;
                }

                $imageType = (string)($row['image_type'] ?? '');
                $currentPriority = max(0, (int)($row['priority'] ?? 0));
                $priority = $this->viewedPhotoPriorityForType($imageType);

                if ($currentPriority >= $priority) {
                    continue;
                }

                $updated = InterfaceDB::execute(
                    "UPDATE photo_conversion_jobs
                     SET priority = :priority
                     WHERE id = :id
                       AND status = 'queued'
                       AND priority < :minimum_priority",
                    [
                        'id' => $jobId,
                        'priority' => $priority,
                        'minimum_priority' => $priority,
                    ]
                );

                if ($updated <= 0) {
                    continue;
                }

                $boosted[] = [
                    'job_id' => $jobId,
                    'image_type' => $imageType,
                    'priority' => $priority,
                    'previous_priority' => $currentPriority,
                ];
            }

            return $boosted;
        });

        if ($notifyAfterCommit) {
            $this->notifyRedisForJobs($jobs);
        }

        return $jobs;
    }

    private function notifyRedis(int $jobId, string $imageType, int $priority): void
    {
        if ($this->redisNotifier instanceof Closure) {
            $this->invokeRedisNotifier([$jobId, $imageType, $priority, 'queue']);
            if ($priority >= self::PRIORITY_PREEMPT_THRESHOLD) {
                $this->invokeRedisNotifier([$jobId, $imageType, $priority, 'preempt']);
            }

            return;
        }

        $host = trim((string)AppConfigurationStore::get('swallowtail.redis.host', '127.0.0.1'));
        $port = (int)AppConfigurationStore::get('swallowtail.redis.port', 6379);
        $urgentQueue = trim((string)AppConfigurationStore::get('swallowtail.redis.urgent_queue', 'swallowtail:conversion:urgent'));
        $normalQueue = trim((string)AppConfigurationStore::get('swallowtail.redis.normal_queue', 'swallowtail:conversion:normal'));
        $preemptQueue = trim((string)AppConfigurationStore::get('swallowtail.redis.preempt_queue', 'swallowtail:conversion:preempt'));

        if ($host === '' || $port <= 0) {
            return;
        }

        $queue = $priority >= self::PRIORITY_EMBEDDED ? $urgentQueue : $normalQueue;
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
            $this->writeRedisListPush($socket, $queue, $payload);
            if ($priority >= self::PRIORITY_PREEMPT_THRESHOLD && $preemptQueue !== '') {
                $preemptPayload = json_encode([
                    'job_id' => $jobId,
                    'priority' => $priority,
                    'reason' => 'high_priority_job',
                ], JSON_THROW_ON_ERROR);
                $this->writeRedisListPush($socket, $preemptQueue, $preemptPayload);
            }
            fclose($socket);
        } catch (Throwable) {
            if (isset($socket) && is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function viewedPhotoPriorityForType(string $imageType): int
    {
        return match (strtolower(trim($imageType))) {
            'original' => self::PRIORITY_VIEWED_ORIGINAL,
            'preview' => self::PRIORITY_PREVIEW,
            default => self::PRIORITY_VIEWED_OTHER,
        };
    }

    /**
     * @param iterable<array<string, mixed>> $jobs
     */
    private function notifyRedisForJobs(array $jobs): void
    {
        foreach ($jobs as $job) {
            $jobId = $this->nullablePositiveInt($job['job_id'] ?? null);
            if ($jobId === null) {
                continue;
            }

            $this->notifyRedis(
                $jobId,
                (string)($job['image_type'] ?? ''),
                $this->normalisePriority($job['priority'] ?? self::PRIORITY_ORIGINAL)
            );
        }
    }

    private function invokeRedisNotifier(array $arguments): void
    {
        if (!$this->redisNotifier instanceof Closure) {
            return;
        }

        $reflection = new ReflectionFunction($this->redisNotifier);
        $argumentCount = $reflection->isVariadic() ? count($arguments) : $reflection->getNumberOfParameters();
        ($this->redisNotifier)(...array_slice($arguments, 0, $argumentCount));
    }

    private function writeRedisListPush(mixed $socket, string $queue, string $payload): void
    {
        $command = "*3\r\n"
            . "$5\r\nLPUSH\r\n"
            . '$' . strlen($queue) . "\r\n" . $queue . "\r\n"
            . '$' . strlen($payload) . "\r\n" . $payload . "\r\n";

        fwrite($socket, $command);
        fgets($socket);
    }

    private function withRetryableQueueTransaction(callable $callback): array
    {
        if (InterfaceDB::inTransaction()) {
            return InterfaceDB::transaction($callback);
        }

        for ($attempt = 1; $attempt <= self::ENQUEUE_ATTEMPTS; $attempt++) {
            try {
                return InterfaceDB::transaction($callback);
            } catch (RuntimeException $exception) {
                if ($attempt >= self::ENQUEUE_ATTEMPTS || !$this->isRetryableDatabaseConcurrencyException($exception)) {
                    throw $exception;
                }

                usleep(50000 * $attempt);
            }
        }

        return [];
    }

    private function isRetryableDatabaseConcurrencyException(RuntimeException $exception): bool
    {
        $code = (string)$exception->getCode();
        $message = $exception->getMessage();

        return $code === '40001'
            || str_contains($message, 'SQLSTATE[40001]')
            || str_contains($message, 'Deadlock found when trying to get lock')
            || str_contains($message, 'SQLExecute[1213]');
    }

    private function setPhotoConversionState(int $photoId, string $state): void
    {
        if ($photoId <= 0) {
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

    private function markObsoletePreviewJobs(int $photoId, array $keepJobIds): void
    {
        $keepJobIds = array_values(array_filter(array_map(
            static fn(mixed $value): int => (int)$value,
            $keepJobIds
        ), static fn(int $value): bool => $value > 0));

        $params = ['photo_id' => $photoId];
        $keepPlaceholders = [];
        $notInSql = '';
        foreach ($keepJobIds as $index => $jobId) {
            $key = 'keep_job_' . (string)$index;
            $params[$key] = $jobId;
            $keepPlaceholders[] = ':' . $key;
        }

        if ($keepJobIds !== []) {
            $notInSql = ' AND id NOT IN (' . implode(', ', $keepPlaceholders) . ')';
        }

        InterfaceDB::prepareExecute(
            "UPDATE photo_conversion_jobs
             SET status = 'obsolete',
                 completed_at = CURRENT_TIMESTAMP,
                 locked_at = NULL,
                 locked_by = NULL,
                 last_error = 'Obsolete preview profile'
             WHERE photo_id = :photo_id
               AND status IN ('queued', 'processing')
               AND image_type = 'preview'" . $notInSql,
            $params
        );
    }

    private function writeThumbnailProfile(array $photo, SwallowtailStorageService $storage): string
    {
        $path = $storage->imagePath(
            (string)($photo['storage_base_location'] ?? ''),
            (string)($photo['original_sha256'] ?? ''),
            'thumbnail_profile'
        );
        $storage->ensureDirectoryForPath($path);

        $profile = (new SwallowtailCombinedProfileService())->combinedProfileContent((int)($photo['id'] ?? 0), 'thumbnail');
        if (file_put_contents($path, $profile, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write thumbnail PP3 profile.');
        }

        @chmod($path, 0660);

        return $path;
    }

    private function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));
        if (!in_array($imageType, self::IMAGE_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported image type.');
        }

        return $imageType;
    }

    private function normalisePriority(mixed $priority): int
    {
        if (is_int($priority)) {
            return max(0, $priority);
        }

        if (is_numeric($priority)) {
            return max(0, (int)$priority);
        }

        return match (strtolower(trim((string)$priority))) {
            'high' => self::PRIORITY_EMBEDDED,
            'low' => self::PRIORITY_FINAL,
            'normal' => self::PRIORITY_ORIGINAL,
            default => self::PRIORITY_ORIGINAL,
        };
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

}
