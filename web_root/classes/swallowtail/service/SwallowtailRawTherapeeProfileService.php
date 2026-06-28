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

final class SwallowtailRawTherapeeProfileService
{
    private const TABLE = 'rawtherapee_profile_data';
    private const REFRESH_QUEUE_DEFAULT = 'swallowtail:metadata:rawtherapee_profiles';
    public const SAMPLE_IMAGE_TYPE = 'rawtherapee_sample';
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

        $defaultSql = InterfaceDB::columnExists(self::TABLE, 'is_default') ? 'is_default' : '0 AS is_default';

        return InterfaceDB::fetchAll(
            "SELECT id, profile_path, relative_path, display_label, profile_hash, profile_bytes, profile_mtime, " . $defaultSql . ", scanned_at
             FROM rawtherapee_profile_data
             WHERE is_available = 1
             ORDER BY is_default DESC, display_label, relative_path"
        );
    }

    public function defaultProfile(): ?array
    {
        if (!$this->tableAvailable() || !InterfaceDB::columnExists(self::TABLE, 'is_default')) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT *
             FROM rawtherapee_profile_data
             WHERE is_default = 1
               AND is_available = 1
             ORDER BY updated_at DESC, id DESC
             LIMIT 1"
        );

        return is_array($row) ? $row : null;
    }

    public function defaultProfileId(): ?int
    {
        $profile = $this->defaultProfile();
        $profileId = max(0, (int)($profile['id'] ?? 0));

        return $profileId > 0 ? $profileId : null;
    }

    public function setDefaultProfile(int $profileId): array
    {
        if ($profileId <= 0 || !$this->tableAvailable() || !InterfaceDB::columnExists(self::TABLE, 'is_default')) {
            return ['success' => false, 'message' => 'RawTherapee profile defaults are not available yet.'];
        }

        $profile = $this->profileById($profileId);
        if ($profile === null) {
            return ['success' => false, 'message' => 'RawTherapee profile was not available.'];
        }

        InterfaceDB::transaction(function () use ($profileId): void {
            InterfaceDB::prepareExecute(
                "UPDATE rawtherapee_profile_data
                 SET is_default = 0
                 WHERE is_default <> 0"
            );
            InterfaceDB::prepareExecute(
                "UPDATE rawtherapee_profile_data
                 SET is_default = 1
                 WHERE id = :id
                   AND is_available = 1",
                ['id' => $profileId]
            );
        });

        return [
            'success' => true,
            'message' => 'Default RawTherapee profile updated.',
            'profile' => $this->profileById($profileId),
        ];
    }

    public function profileForPhoto(array $photo): ?array
    {
        $profileId = max(0, (int)($photo['rawtherapee_profile_id'] ?? 0));

        return $profileId > 0 ? $this->profileById($profileId) : null;
    }

    public function dashboard(
        int $profileId = 0,
        int $photoId = 0,
        int $userId = 0,
        bool $showPreview = false,
        string $displayUrl = '',
        string $displayType = ''
    ): array
    {
        $profiles = $this->availableProfiles();
        $profileId = max(0, $profileId);

        $photoId = max(0, $photoId);
        if ($photoId <= 0) {
            $photo = $this->randomAccessibleThumbnailPhoto($userId, true);
            $photoId = max(0, (int)($photo['id'] ?? 0));
        }

        $photo = $photoId > 0
            ? (new SwallowtailCombinedProfilePreviewService())->photoForUser($photoId, $userId)
            : null;
        $displayUrl = $this->normaliseDisplayUrl($displayUrl);
        $displayType = $this->normaliseDisplayType($displayType);
        $asset = null;
        $statusUrl = '';
        $status = 'Ready';

        if (is_array($photo)) {
            if ($profileId <= 0) {
                $current = (new SwallowtailPreviewProfileService())->currentProfilePreviewState($photoId, $userId);
                $displayUrl = (string)($current['display_url'] ?? '');
                $displayType = $this->normaliseDisplayType((string)($current['display_type'] ?? ''));
                $statusUrl = (string)($current['status_url'] ?? '');
                $status = !empty($current['ready']) ? 'Ready' : 'Queued';
            } else {
                $profile = $this->profileById($profileId);
                $sampleAsset = is_array($profile) ? $this->freshSampleAssetForPhoto($photo, $profile) : null;
                if ($sampleAsset !== null) {
                    $asset = $sampleAsset;
                    $displayUrl = $this->assetUrl($photoId, $sampleAsset);
                    $displayType = 'rawtherapee';
                    $status = 'Ready';
                } else {
                    $status = 'Queued';
                }
            }
        }

        return [
            'profiles' => $profiles,
            'default_profile_id' => max(0, (int)($this->defaultProfileId() ?? 0)),
            'profile_id' => $profileId,
            'photo_id' => $photoId,
            'photo' => $photo,
            'asset' => $asset,
            'display_url' => $displayUrl,
            'display_type' => $displayType,
            'status_url' => $statusUrl,
            'status' => $status,
            'show_preview' => $photo !== null,
        ];
    }

    public function profileById(int $profileId): ?array
    {
        if ($profileId <= 0 || !$this->tableAvailable()) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT *
             FROM rawtherapee_profile_data
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
            'rawtherapee_sample_profile',
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
                 FROM rawtherapee_profile_data
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

    public function randomAccessibleThumbnailPhoto(int $userId, bool $preferRawTherapeeArtifacts = false): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $params = [
            'thumbnail_image_type' => 'thumbnail',
        ];
        $sampleJoin = '';
        $preferredOrder = '';
        if ($preferRawTherapeeArtifacts) {
            $params['sample_image_type'] = self::SAMPLE_IMAGE_TYPE;
            $sampleJoin = "
             LEFT JOIN photo_image_assets sample_asset
                ON sample_asset.photo_id = photo.id
               AND sample_asset.image_type = :sample_image_type
               AND sample_asset.bytes > 0
               AND sample_asset.sha256 <> ''
               AND LENGTH(COALESCE(sample_asset.profile_signature, '')) = 64";
            $preferredOrder = 'CASE WHEN sample_asset.id IS NULL THEN 1 ELSE 0 END, ';
        }

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
               AND thumbnail_asset.sha256 <> ''" . $sampleJoin . "
             WHERE " . $where . "
             ORDER BY " . $preferredOrder . $this->randomOrderSql() . "
             LIMIT 1",
            $params
        );

        return is_array($row) ? $row : null;
    }

    public function searchAccessibleThumbnailPhotos(int $userId, string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($userId <= 0 || $query === '') {
            return [];
        }

        $limit = max(1, min(25, $limit));
        $normalisedQuery = strtolower($query);
        $likeTerm = $this->escapeLikeTerm($normalisedQuery);
        $queryPhotoId = ctype_digit($query) ? max(0, (int)$query) : 0;
        $params = [
            'thumbnail_image_type' => 'thumbnail',
            'query_exact' => $normalisedQuery,
            'query_prefix' => $likeTerm . '%',
            'query_contains' => '%' . $likeTerm . '%',
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

        $searchClauses = [
            "LOWER(photo.original_filename) LIKE :query_contains ESCAPE '!'",
            "LOWER(photo.original_sha256) LIKE :query_contains ESCAPE '!'",
        ];
        $photoIdOrder = '';
        if ($queryPhotoId > 0) {
            $params['query_photo_id'] = $queryPhotoId;
            array_unshift($searchClauses, 'photo.id = :query_photo_id');
            $photoIdOrder = 'CASE WHEN photo.id = :query_photo_id THEN 0 ELSE 1 END, ';
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT photo.*
             FROM photos photo
             INNER JOIN photo_image_assets thumbnail_asset
                ON thumbnail_asset.photo_id = photo.id
               AND thumbnail_asset.image_type = :thumbnail_image_type
               AND thumbnail_asset.bytes > 0
               AND thumbnail_asset.sha256 <> ''
             WHERE " . $where . "
               AND (" . implode(' OR ', $searchClauses) . ")
             ORDER BY " . $photoIdOrder . "
                CASE WHEN LOWER(photo.original_sha256) = :query_exact THEN 0 ELSE 1 END,
                CASE WHEN LOWER(photo.original_filename) = :query_exact THEN 0 ELSE 1 END,
                CASE WHEN LOWER(photo.original_sha256) LIKE :query_prefix ESCAPE '!'
                       OR LOWER(photo.original_filename) LIKE :query_prefix ESCAPE '!' THEN 0 ELSE 1 END,
                CASE WHEN LOWER(photo.original_sha256) LIKE :query_contains ESCAPE '!'
                       OR LOWER(photo.original_filename) LIKE :query_contains ESCAPE '!' THEN 0 ELSE 1 END,
                photo.created_at DESC,
                photo.id DESC
             LIMIT " . (string)$limit,
            $params
        );

        return is_array($rows) ? $rows : [];
    }

    public function requestRefresh(): bool
    {
        $queue = trim((string)\Swallowtail\Store\SwallowtailConfigurationStore::get(
            'redis.rawtherapee_profile_refresh_queue',
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
            return ['success' => false, 'message' => 'RawTherapee profile was not available.'];
        }

        $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
        if ($photo === null) {
            return ['success' => false, 'message' => 'Photo was not found.'];
        }

        $storage = new SwallowtailStorageService();
        $checksum = (string)($photo['original_sha256'] ?? '');
        $base = (string)($photo['storage_base_location'] ?? '');
        $inputPath = $storage->imagePath($base, $checksum, 'source');
        $profileSignature = $this->profileSignature($profile);
        $outputPath = $storage->imageVariantPath($base, $checksum, self::SAMPLE_IMAGE_TYPE, $profileSignature);
        $assetService = new SwallowtailPhotoAssetService();
        $existingAsset = $assetService->assetForPhotoProfileSignature($photo, self::SAMPLE_IMAGE_TYPE, $profileSignature);
        if ($existingAsset !== null) {
            return [
                'success' => true,
                'job_id' => 0,
                'photo_id' => $photoId,
                'profile_id' => $profileId,
                'ready' => true,
                'message' => 'RawTherapee sample is ready.',
                'status_url' => '',
                'image_url' => $this->assetUrl($photoId, $existingAsset),
            ];
        }

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
            return [
                'success' => false,
                'message' => $this->sampleQueueFailureMessage(
                    $photoId,
                    $profileId,
                    $profile,
                    $inputPath,
                    $outputPath,
                    $profileSignature
                ),
            ];
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

    private function sampleQueueFailureMessage(
        int $photoId,
        int $profileId,
        array $profile,
        string $inputPath,
        string $outputPath,
        string $profileSignature
    ): string {
        $profilePath = (string)($profile['profile_path'] ?? '');
        $details = [
            'photo_id=' . (string)$photoId,
            'profile_id=' . (string)$profileId,
            'image_type=' . self::SAMPLE_IMAGE_TYPE,
            'signature=' . $this->shortSignature($profileSignature),
            'source=' . $this->pathDiagnostic($inputPath),
            'profile=' . $this->pathDiagnostic($profilePath),
            'jobs_table=' . $this->yesNo(InterfaceDB::tableExists('photo_conversion_jobs')),
            'profile_signature_column=' . $this->yesNo(InterfaceDB::columnExists('photo_conversion_jobs', 'profile_signature')),
        ];

        $imageTypeColumn = $this->conversionJobColumnType('image_type');
        if ($imageTypeColumn !== '') {
            $details[] = 'image_type_column=' . $imageTypeColumn;
            $details[] = 'rawtherapee_sample_allowed=' . $this->yesNo(str_contains($imageTypeColumn, self::SAMPLE_IMAGE_TYPE));
        }

        $priorityColumn = $this->conversionJobColumnType('priority');
        if ($priorityColumn !== '') {
            $details[] = 'priority_column=' . $priorityColumn;
        }

        foreach ($this->matchingQueuedJobDiagnostics($photoId, $profilePath, $inputPath, $outputPath) as $key => $value) {
            $details[] = $key . '=' . $value;
        }

        return 'Sample conversion could not be queued. Diagnostics: ' . implode('; ', $details) . '.';
    }

    private function matchingQueuedJobDiagnostics(int $photoId, string $profilePath, string $inputPath, string $outputPath): array
    {
        if (!InterfaceDB::tableExists('photo_conversion_jobs')) {
            return [];
        }

        $profilePath = trim($profilePath);
        $activeMatchingProfile = InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = :image_type
               AND " . ($profilePath === '' ? 'profile_path IS NULL' : 'profile_path = :profile_path') . "
               AND status IN ('queued', 'processing')",
            array_filter([
                'photo_id' => $photoId,
                'image_type' => self::SAMPLE_IMAGE_TYPE,
                'profile_path' => $profilePath === '' ? null : $profilePath,
            ], static fn(mixed $value): bool => $value !== null)
        );
        $exactQueued = InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = :image_type
               AND input_path = :input_path
               AND output_path = :output_path
               AND " . ($profilePath === '' ? 'profile_path IS NULL' : 'profile_path = :profile_path') . "
               AND status = 'queued'",
            array_filter([
                'photo_id' => $photoId,
                'image_type' => self::SAMPLE_IMAGE_TYPE,
                'input_path' => $inputPath,
                'output_path' => $outputPath,
                'profile_path' => $profilePath === '' ? null : $profilePath,
            ], static fn(mixed $value): bool => $value !== null)
        );
        $pathRows = InterfaceDB::fetchAll(
            "SELECT id, image_type, status, profile_signature
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND input_path = :input_path
               AND output_path = :output_path
             ORDER BY id DESC
             LIMIT 3",
            [
                'photo_id' => $photoId,
                'input_path' => $inputPath,
                'output_path' => $outputPath,
            ]
        );

        return [
            'active_same_profile' => (string)max(0, (int)$activeMatchingProfile),
            'exact_queued_match' => (string)max(0, (int)$exactQueued),
            'same_path_rows' => $this->queueRowsDiagnostic($pathRows),
        ];
    }

    private function conversionJobColumnType(string $column): string
    {
        if (!InterfaceDB::tableExists('photo_conversion_jobs') || !InterfaceDB::columnExists('photo_conversion_jobs', $column)) {
            return '';
        }

        if (InterfaceDB::driverName() === 'sqlite') {
            $rows = InterfaceDB::fetchAll('PRAGMA table_info(photo_conversion_jobs)');
            foreach ($rows as $row) {
                if (strcasecmp((string)($row['name'] ?? ''), $column) === 0) {
                    return strtolower(trim((string)($row['type'] ?? '')));
                }
            }

            return '';
        }

        $type = InterfaceDB::fetchColumn(
            "SELECT COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'photo_conversion_jobs'
               AND COLUMN_NAME = :column
             LIMIT 1",
            ['column' => $column]
        );

        return strtolower(substr(trim((string)$type), 0, 240));
    }

    private function queueRowsDiagnostic(array $rows): string
    {
        if ($rows === []) {
            return 'none';
        }

        $parts = [];
        foreach ($rows as $row) {
            $parts[] = '#' . (string)max(0, (int)($row['id'] ?? 0))
                . '/' . trim((string)($row['image_type'] ?? ''))
                . '/' . trim((string)($row['status'] ?? ''))
                . '/' . $this->shortSignature((string)($row['profile_signature'] ?? ''));
        }

        return implode(',', $parts);
    }

    private function pathDiagnostic(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return 'empty';
        }

        return basename($path)
            . ':len=' . (string)strlen($path)
            . ':exists=' . $this->yesNo(is_file($path))
            . ':readable=' . $this->yesNo(is_readable($path));
    }

    private function shortSignature(string $signature): string
    {
        $signature = strtolower(trim($signature));

        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1 ? substr($signature, 0, 12) : 'none';
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function freshSampleAssetForPhoto(array $photo, array $profile): ?array
    {
        $assetService = new SwallowtailPhotoAssetService();
        $asset = $assetService->assetForPhotoProfileSignature($photo, self::SAMPLE_IMAGE_TYPE, $this->profileSignature($profile));

        return is_array($asset) ? $asset : null;
    }

    private function assetUrl(int $photoId, array $asset): string
    {
        $profileSignature = strtolower(trim((string)($asset['profile_signature'] ?? '')));
        $query = [
            'photo_id' => $photoId,
            'type' => (string)($asset['image_type'] ?? 'preview'),
            'v' => (string)($asset['sha256'] ?? ''),
        ];
        if ((string)($asset['image_type'] ?? '') === self::SAMPLE_IMAGE_TYPE) {
            $query['profile_signature'] = $profileSignature;
            $query['v'] = $profileSignature;
        }

        return '/api/photo-imaging.php?' . http_build_query($query);
    }

    private function normaliseDisplayUrl(string $displayUrl): string
    {
        $displayUrl = trim($displayUrl);

        return str_starts_with($displayUrl, '/api/photo-imaging.php?') ? $displayUrl : '';
    }

    private function normaliseDisplayType(string $displayType): string
    {
        $displayType = strtolower(trim($displayType));

        return in_array($displayType, ['preview', 'thumbnail', 'rawtherapee'], true) ? $displayType : 'none';
    }

    private function randomOrderSql(): string
    {
        return InterfaceDB::driverName() === 'sqlite' ? 'RANDOM()' : 'RAND()';
    }

    private function escapeLikeTerm(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
