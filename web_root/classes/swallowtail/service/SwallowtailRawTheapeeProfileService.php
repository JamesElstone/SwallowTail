<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

use AppConfigurationStore;
use InterfaceDB;
use RoleAssignmentService;

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

    public function dashboard(int $profileId = 0, int $photoId = 0, int $userId = 0, bool $showPreview = false): array
    {
        $profiles = $this->availableProfiles();
        $profileId = max(0, $profileId);

        $photoId = max(0, $photoId);
        $photo = $showPreview && $photoId > 0
            ? (new SwallowtailCombinedProfilePreviewService())->photoForUser($photoId, $userId)
            : null;
        $asset = is_array($photo) ? $this->previewAssetForPhoto($photo, $profileId > 0) : null;

        return [
            'profiles' => $profiles,
            'profile_id' => $profileId,
            'photo_id' => $photoId,
            'photo' => $photo,
            'asset' => $asset,
            'show_preview' => $showPreview,
        ];
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

    public function profileSignature(array $profile): string
    {
        $path = trim((string)($profile['profile_path'] ?? ''));
        $fileHash = '';
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $hash = hash_file('sha256', $path);
            if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                $fileHash = $hash;
            }
        }

        return hash('sha256', implode("\n", [
            'rawtheapee_sample_profile',
            $fileHash,
            $path,
            (string)($profile['relative_path'] ?? ''),
            (string)max(0, (int)($profile['profile_bytes'] ?? 0)),
            (string)max(0, (int)($profile['profile_mtime'] ?? 0)),
        ]));
    }

    public function profileSignatureForPath(string $profilePath): string
    {
        $profilePath = trim($profilePath);
        if ($profilePath === '') {
            return '';
        }

        if ($this->tableAvailable()) {
            $row = InterfaceDB::fetchOne(
                "SELECT *
                 FROM rawtheapee_profile_data
                 WHERE profile_path = :profile_path
                 LIMIT 1",
                ['profile_path' => $profilePath]
            );
            if (is_array($row)) {
                return $this->profileSignature($row);
            }
        }

        if (is_file($profilePath) && is_readable($profilePath)) {
            return $this->profileSignature(['profile_path' => $profilePath]);
        }

        return '';
    }

    public function randomAccessibleThumbnailPhoto(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $params = [
            'thumbnail_image_type' => 'thumbnail',
        ];
        $where = "photo.upload_state = 'uploaded'";
        $roleId = $this->roleIdForUser($userId);

        if ($roleId !== RoleAssignmentService::ADMIN_ROLE_ID) {
            $params['access_upload_user_id'] = $userId;
            $params['access_grantee_user_id'] = $userId;
            $params['access_grantee_role_id'] = $roleId;
            $where .= "
                AND (
                    photo.uploaded_by_user_id = :access_upload_user_id
                    OR EXISTS (
                        SELECT 1
                        FROM event_photos access_event_photo
                        INNER JOIN event_permissions access_permission
                            ON access_permission.event_id = access_event_photo.event_id
                        WHERE access_event_photo.photo_id = photo.id
                          AND access_permission.can_view = 1
                          AND (
                              (access_permission.grantee_type = 'user' AND access_permission.grantee_id = :access_grantee_user_id)
                              OR
                              (access_permission.grantee_type = 'role' AND access_permission.grantee_id = :access_grantee_role_id)
                          )
                          AND (access_permission.expires_at IS NULL OR access_permission.expires_at > CURRENT_TIMESTAMP)
                        LIMIT 1
                    )
                )";
        }

        $row = InterfaceDB::fetchOne(
            "SELECT photo.*
             FROM photos photo
             INNER JOIN photo_image_assets thumbnail_asset
                ON thumbnail_asset.photo_id = photo.id
               AND thumbnail_asset.image_type = :thumbnail_image_type
               AND thumbnail_asset.bytes > 0
               AND thumbnail_asset.sha256 <> ''
             WHERE " . $where . "
             ORDER BY " . $this->randomOrderSql() . "
             LIMIT 1",
            $params
        );

        return is_array($row) ? $row : null;
    }

    public function requestRefresh(): bool
    {
        $queue = trim((string)\Swallowtail\Store\SwallowtailConfigurationStore::get(
            'redis.rawtheapee_profile_refresh_queue',
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
        $profileSignature = $this->profileSignature($profile);

        $queue = new SwallowtailConversionQueueService();
        $jobId = $queue->enqueueImageJob(
            $photoId,
            self::SAMPLE_IMAGE_TYPE,
            $inputPath,
            $outputPath,
            (string)$profile['profile_path'],
            self::SAMPLE_PRIORITY,
            $userId,
            null,
            null,
            $profileSignature
        );

        if ($jobId === null) {
            return ['success' => false, 'message' => 'Sample conversion could not be queued.'];
        }

        $queue->notifyQueuedJob($jobId, self::SAMPLE_IMAGE_TYPE, self::SAMPLE_PRIORITY);

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

    private function roleIdForUser(int $userId): int
    {
        return (new \CardAccessFramework())->roleIdForUser($userId);
    }

    private function previewAssetForPhoto(array $photo, bool $includeSample): ?array
    {
        $assetService = new SwallowtailPhotoAssetService();
        $sampleAsset = $includeSample ? $assetService->assetForPhoto($photo, self::SAMPLE_IMAGE_TYPE) : null;
        $previewAsset = $assetService->assetForPhoto($photo, 'preview');
        $thumbnailAsset = $assetService->assetForPhoto($photo, 'thumbnail');
        $asset = $sampleAsset ?? $previewAsset ?? $thumbnailAsset;

        return is_array($asset) ? $asset : null;
    }

    private function randomOrderSql(): string
    {
        return InterfaceDB::driverName() === 'sqlite' ? 'RANDOM()' : 'RAND()';
    }
}
