<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailRawTheapeeProfileService
{
    private const TABLE = 'rawtheapee_profile_data';
    private const REFRESH_QUEUE_DEFAULT = 'swallowtail:metadata:rawtheapee_profiles';
    public const SAMPLE_IMAGE_TYPE = 'rawtheapee_sample';
    public const SAMPLE_PRIORITY = 65;

    public function tableAvailable(): bool
    {
        return InterfaceDB::tableExists(self::TABLE);
    }

    public function availableProfiles(): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        return InterfaceDB::fetchAll(
            "SELECT id, profile_path, relative_path, display_label, profile_bytes, profile_mtime, scanned_at
             FROM rawtheapee_profile_data
             WHERE is_available = 1
             ORDER BY display_label, relative_path"
        );
    }

    public function profileById(int $profileId): ?array
    {
        if ($profileId <= 0 || !$this->tableAvailable()) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT *
             FROM rawtheapee_profile_data
             WHERE id = :id
               AND is_available = 1
             LIMIT 1",
            ['id' => $profileId]
        );

        return is_array($row) ? $row : null;
    }

    public function randomAccessiblePhoto(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $gallery = (new SwallowtailPhotoUiService())->accessiblePhotos($userId, 1, 96);
        $rows = array_values(array_filter((array)($gallery['rows'] ?? []), static fn(mixed $row): bool => is_array($row)));
        if ($rows === []) {
            return null;
        }

        return (array)$rows[array_rand($rows)];
    }

    public function requestRefresh(): bool
    {
        $queue = trim((string)AppConfigurationStore::get(
            'swallowtail.redis.rawtheapee_profile_refresh_queue',
            self::REFRESH_QUEUE_DEFAULT
        ));
        if ($queue === '') {
            return false;
        }

        return (new SwallowtailRedisService())->listPushJson($queue, [
            'reason' => 'profiles_card_refresh',
            'requested_at' => time(),
        ], 16);
    }

    public function enqueueSample(int $photoId, int $profileId, int $userId): array
    {
        if ($photoId <= 0 || $userId <= 0 || !(new SwallowtailPhotoUiService())->userCanViewPhoto($photoId, $userId)) {
            return ['success' => false, 'message' => 'Photo was not available.'];
        }

        $profile = $this->profileById($profileId);
        if ($profile === null) {
            return ['success' => false, 'message' => 'RawTheapee profile was not available.'];
        }

        $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
        if ($photo === null) {
            return ['success' => false, 'message' => 'Photo was not found.'];
        }

        $storage = new SwallowtailStorageService();
        $checksum = (string)($photo['original_sha256'] ?? '');
        $base = (string)($photo['storage_base_location'] ?? '');
        $inputPath = $storage->imagePath($base, $checksum, 'source');
        $outputPath = $storage->imagePath($base, $checksum, self::SAMPLE_IMAGE_TYPE);

        $jobId = (new SwallowtailConversionQueueService())->enqueueImageJob(
            $photoId,
            self::SAMPLE_IMAGE_TYPE,
            $inputPath,
            $outputPath,
            (string)$profile['profile_path'],
            self::SAMPLE_PRIORITY,
            $userId,
            null,
            null,
            ''
        );

        if ($jobId === null) {
            return ['success' => false, 'message' => 'Sample conversion could not be queued.'];
        }

        return [
            'success' => true,
            'job_id' => $jobId,
            'photo_id' => $photoId,
            'profile_id' => $profileId,
            'status_url' => '/api/photo-status.php?' . http_build_query([
                'photo_id' => $photoId,
                'job_id' => $jobId,
                'image_type' => self::SAMPLE_IMAGE_TYPE,
            ]),
            'image_url' => '',
        ];
    }
}
