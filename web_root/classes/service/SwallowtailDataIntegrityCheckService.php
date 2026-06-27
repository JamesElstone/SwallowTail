<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailDataIntegrityCheckService
{
    private const LAZY_SCAN_CURSOR_KEY = 'swallowtail.data_integrity.lazy_scan_after_photo_id';
    private const LAZY_SCAN_REQUESTED_KEY = 'swallowtail.data_integrity.lazy_loading_prevention_requested';
    private const DATA_INTEGRITY_QUEUE_DEFAULT = 'swallowtail:metadata:data_integrity';
    private const MAX_LAZY_SCAN_PHOTOS = 150;
    private const MAX_LAZY_SCAN_SECONDS = 240;

    public function __construct(
        private readonly SwallowtailPhotoAssetService $assetService = new SwallowtailPhotoAssetService(),
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailCombinedProfileService $combinedProfileService = new SwallowtailCombinedProfileService(),
        private readonly SwallowtailConversionQueueService $queueService = new SwallowtailConversionQueueService(),
        private readonly SwallowtailRedisService $redis = new SwallowtailRedisService(),
    ) {
    }

    public function status(): array
    {
        $blockers = $this->queueBlockers();

        return [
            'can_run' => (int)$blockers['total'] === 0,
            'blockers' => $blockers,
            'lazy_scan' => $this->lazyScanState(),
            'checks' => $this->integrityChecks(),
        ];
    }

    public function requestLazyLoadingPrevention(): array
    {
        $blockers = $this->queueBlockers();
        if ((int)$blockers['total'] > 0) {
            return [
                'success' => false,
                'message' => 'Data integrity actions can only run when conversion and storage migration queues are idle.',
                'blockers' => $blockers,
            ];
        }

        AppConfigurationStore::set(self::LAZY_SCAN_REQUESTED_KEY, true);
        $queued = $this->notifyDataIntegrityWorker('prevent_lazy_loading');

        return [
            'success' => true,
            'worker_notified' => $queued,
            'message' => $queued
                ? 'Lazy loading prevention was requested. The metadata service will queue profiled image work in the background.'
                : 'Lazy loading prevention was requested, but the metadata service notification could not be sent. It will run when the service next checks the request flag.',
            'blockers' => $blockers,
        ];
    }

    public function processLazyLoadingPreventionBatch(int $limit = self::MAX_LAZY_SCAN_PHOTOS): array
    {
        if (!$this->lazyLoadingPreventionRequested()) {
            return [
                'success' => true,
                'requested' => false,
                'message' => 'No lazy loading prevention request is active.',
            ];
        }

        $blockers = $this->queueBlockers();
        if ((int)$blockers['total'] > 0) {
            return [
                'success' => true,
                'requested' => true,
                'blocked' => true,
                'message' => 'Lazy loading prevention is waiting for conversion and storage migration queues to become idle.',
                'blockers' => $blockers,
            ];
        }

        if (!$this->profiledDerivativeTablesAvailable()) {
            return [
                'success' => false,
                'message' => 'Profiled derivative checks require photos, photo profile data, conversion jobs, and image assets tables.',
                'blockers' => $blockers,
            ];
        }

        $limit = max(1, min(self::MAX_LAZY_SCAN_PHOTOS, $limit));
        $startedAt = time();
        $cursor = $this->lazyScanCursor();
        $rows = $this->readyUploadedPhotosAfter($cursor, $limit);
        $wrapped = false;
        if ($rows === [] && $cursor > 0) {
            $cursor = 0;
            $rows = $this->readyUploadedPhotosAfter(0, $limit);
            $wrapped = true;
        }

        $result = [
            'success' => true,
            'scanned' => 0,
            'queued_preview' => 0,
            'queued_final' => 0,
            'already_fresh' => 0,
            'active_jobs' => 0,
            'skipped' => 0,
            'last_photo_id' => $cursor,
            'complete_pass' => false,
            'wrapped' => $wrapped,
            'requested' => true,
        ];

        foreach ($rows as $photo) {
            if (time() - $startedAt >= self::MAX_LAZY_SCAN_SECONDS) {
                break;
            }

            $photoId = max(0, (int)($photo['id'] ?? 0));
            if ($photoId <= 0) {
                continue;
            }

            $result['scanned']++;
            $result['last_photo_id'] = $photoId;

            foreach (['preview', 'final'] as $imageType) {
                $queued = $this->queueProfiledDerivativeIfNeeded($photo, $imageType);
                if ($queued === 'queued_preview') {
                    $result['queued_preview']++;
                } elseif ($queued === 'queued_final') {
                    $result['queued_final']++;
                } elseif ($queued === 'already_fresh') {
                    $result['already_fresh']++;
                } elseif ($queued === 'active_job') {
                    $result['active_jobs']++;
                } else {
                    $result['skipped']++;
                }
            }
        }

        if ((int)$result['scanned'] <= 0 || (int)$result['last_photo_id'] <= 0) {
            $this->setLazyScanCursor(0);
            $this->setLazyLoadingPreventionRequested(false);
            $result['complete_pass'] = true;
            return $result;
        }

        $hasMore = $this->readyUploadedPhotoCountAfter((int)$result['last_photo_id']) > 0;
        if ($hasMore) {
            $this->setLazyScanCursor((int)$result['last_photo_id']);
        } else {
            $this->setLazyScanCursor(0);
            $this->setLazyLoadingPreventionRequested(false);
            $result['complete_pass'] = true;
        }

        return $result;
    }

    public function integrityChecks(): array
    {
        $checks = [];
        $blockers = $this->queueBlockers();
        $checks[] = $this->checkRow('Active conversion jobs', (int)$blockers['photo_conversion_jobs'], 'queued or processing photo_conversion_jobs');
        $checks[] = $this->checkRow('Active storage migrations', (int)$blockers['storage_migration_jobs'], 'queued or processing storage migration jobs/items');
        $checks[] = $this->checkRow('Succeeded jobs without asset rows', $this->succeededJobsWithoutAssetRows(), 'completed conversions not yet recorded in photo_image_assets');
        $checks[] = $this->checkRow('Profiled assets without signatures', $this->profiledAssetsWithoutSignatures(), 'preview/final/sample assets missing profile_signature');
        $checks[] = $this->checkRow('Profiled jobs without signatures', $this->profiledSucceededJobsWithoutSignatures(), 'legacy succeeded preview/final/sample jobs missing profile_signature');
        $checks[] = $this->checkRow('Signed asset/job mismatches', $this->signedAssetJobMismatches(), 'asset signature differs from its conversion job signature');
        $checks[] = $this->checkRow('Photo conversion state mismatches', $this->photoConversionStateMismatches(), 'photos.conversion_state disagrees with job status summary');

        return $checks;
    }

    public function queueBlockers(): array
    {
        $conversion = 0;
        if (InterfaceDB::tableExists('photo_conversion_jobs') && InterfaceDB::columnExists('photo_conversion_jobs', 'status')) {
            $conversion = max(0, (int)InterfaceDB::fetchColumn(
                "SELECT COUNT(*)
                 FROM photo_conversion_jobs
                 WHERE status IN ('queued', 'processing')"
            ));
        }

        $migration = 0;
        if (InterfaceDB::tableExists('storage_migration_jobs') && InterfaceDB::columnExists('storage_migration_jobs', 'status')) {
            $migration += max(0, (int)InterfaceDB::fetchColumn(
                "SELECT COUNT(*)
                 FROM storage_migration_jobs
                 WHERE status IN ('queued', 'processing')"
            ));
        }
        if (InterfaceDB::tableExists('storage_migration_job_items') && InterfaceDB::columnExists('storage_migration_job_items', 'status')) {
            $migration += max(0, (int)InterfaceDB::fetchColumn(
                "SELECT COUNT(*)
                 FROM storage_migration_job_items
                 WHERE status IN ('queued', 'processing')"
            ));
        }

        return [
            'photo_conversion_jobs' => $conversion,
            'storage_migration_jobs' => $migration,
            'total' => $conversion + $migration,
        ];
    }

    private function queueProfiledDerivativeIfNeeded(array $photo, string $imageType): string
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        if ($photoId <= 0) {
            return 'skipped';
        }

        $profileSignature = $this->combinedProfileService->profileSignature($photoId, $imageType);
        if (!$this->isSignature($profileSignature)) {
            return 'skipped';
        }

        $asset = $this->assetService->assetForPhoto($photo, $imageType);
        if ($asset !== null && $this->assetService->isFreshForSignature($asset, $profileSignature)) {
            return 'already_fresh';
        }

        if ($this->activeProfileJobExists($photoId, $imageType, $profileSignature)) {
            return 'active_job';
        }

        $profilePath = $this->writeProfile($photo, $imageType);
        $jobId = $imageType === 'final'
            ? $this->queueService->enqueueFinalRefresh($photoId, $profilePath, null, $profileSignature)
            : $this->queueService->enqueuePreviewRefresh($photoId, $profilePath, null, $profileSignature);

        return $jobId === null ? 'skipped' : 'queued_' . $imageType;
    }

    private function writeProfile(array $photo, string $imageType): string
    {
        $profileType = match ($imageType) {
            'preview' => 'preview_profile',
            'final' => 'final_profile',
            default => throw new InvalidArgumentException('Unsupported profiled derivative type.'),
        };

        $path = $this->storageService->imagePath(
            (string)($photo['storage_base_location'] ?? ''),
            (string)($photo['original_sha256'] ?? ''),
            $profileType
        );
        $this->storageService->ensureDirectoryForPath($path);

        $profile = $this->combinedProfileService->combinedProfileContent((int)($photo['id'] ?? 0), $imageType);
        if (file_put_contents($path, $profile, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write profiled derivative PP3 profile.');
        }

        @chmod($path, 0660);

        return $path;
    }

    private function activeProfileJobExists(int $photoId, string $imageType, string $profileSignature): bool
    {
        if (!InterfaceDB::tableExists('photo_conversion_jobs') || !InterfaceDB::columnExists('photo_conversion_jobs', 'profile_signature')) {
            return false;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = :image_type
               AND profile_signature = :profile_signature
               AND status IN ('queued', 'processing')",
            [
                'photo_id' => $photoId,
                'image_type' => $imageType,
                'profile_signature' => $profileSignature,
            ]
        )) > 0;
    }

    private function readyUploadedPhotosAfter(int $photoId, int $limit): array
    {
        return InterfaceDB::fetchAll(
            "SELECT p.*
             FROM photos p
             INNER JOIN photo_profile_data status
                ON status.photo_id = p.id
               AND status.type = 'swallowtail'
               AND status.`key` = 'status'
               AND status.value = 'processed'
             WHERE p.id > :photo_id
               AND p.upload_state = 'uploaded'
               AND LOWER(COALESCE(p.original_extension, '')) = 'cr2'
             ORDER BY p.id
             LIMIT " . max(1, min(self::MAX_LAZY_SCAN_PHOTOS, $limit)),
            ['photo_id' => $photoId]
        );
    }

    private function readyUploadedPhotoCountAfter(int $photoId): int
    {
        if (!$this->profiledDerivativeTablesAvailable()) {
            return 0;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photos p
             INNER JOIN photo_profile_data status
                ON status.photo_id = p.id
               AND status.type = 'swallowtail'
               AND status.`key` = 'status'
               AND status.value = 'processed'
             WHERE p.id > :photo_id
               AND p.upload_state = 'uploaded'
               AND LOWER(COALESCE(p.original_extension, '')) = 'cr2'",
            ['photo_id' => $photoId]
        ));
    }

    private function lazyScanState(): array
    {
        $cursor = $this->lazyScanCursor();

        return [
            'cursor' => $cursor,
            'remaining_after_cursor' => $this->readyUploadedPhotoCountAfter($cursor),
            'requested' => $this->lazyLoadingPreventionRequested(),
        ];
    }

    private function lazyScanCursor(): int
    {
        return max(0, (int)AppConfigurationStore::get(self::LAZY_SCAN_CURSOR_KEY, 0));
    }

    private function setLazyScanCursor(int $photoId): void
    {
        AppConfigurationStore::set(self::LAZY_SCAN_CURSOR_KEY, max(0, $photoId));
    }

    private function lazyLoadingPreventionRequested(): bool
    {
        return (bool)AppConfigurationStore::get(self::LAZY_SCAN_REQUESTED_KEY, false, true);
    }

    private function setLazyLoadingPreventionRequested(bool $requested): void
    {
        AppConfigurationStore::set(self::LAZY_SCAN_REQUESTED_KEY, $requested);
    }

    private function notifyDataIntegrityWorker(string $reason): bool
    {
        $queue = trim((string)AppConfigurationStore::get(
            'swallowtail.redis.metadata_data_integrity_queue',
            self::DATA_INTEGRITY_QUEUE_DEFAULT
        ));
        if ($queue === '') {
            return false;
        }

        return $this->redis->listPushJson($queue, [
            'action' => 'prevent_lazy_loading',
            'reason' => substr($reason, 0, 64),
            'queued_at' => time(),
        ], 64);
    }

    private function profiledDerivativeTablesAvailable(): bool
    {
        return InterfaceDB::tableExists('photos')
            && InterfaceDB::tableExists('photo_profile_data')
            && InterfaceDB::tableExists('photo_conversion_jobs')
            && InterfaceDB::tableExists('photo_image_assets')
            && InterfaceDB::columnsExists('photos', ['id', 'upload_state', 'original_extension', 'original_sha256', 'storage_base_location'])
            && InterfaceDB::columnsExists('photo_profile_data', ['photo_id', 'type', 'key', 'value'])
            && InterfaceDB::columnsExists('photo_conversion_jobs', ['photo_id', 'image_type', 'status', 'profile_signature'])
            && InterfaceDB::columnsExists('photo_image_assets', ['photo_id', 'image_type', 'profile_signature']);
    }

    private function succeededJobsWithoutAssetRows(): int
    {
        if (!$this->profiledDerivativeTablesAvailable()) {
            return 0;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_conversion_jobs job
             LEFT JOIN photo_image_assets asset
               ON asset.photo_id = job.photo_id
              AND asset.image_type = job.image_type
             WHERE job.status = 'succeeded'
               AND asset.id IS NULL"
        ));
    }

    private function profiledAssetsWithoutSignatures(): int
    {
        if (!InterfaceDB::tableExists('photo_image_assets') || !InterfaceDB::columnsExists('photo_image_assets', ['image_type', 'profile_signature'])) {
            return 0;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_image_assets
             WHERE image_type IN ('preview', 'final', 'rawtheapee_sample')
               AND (profile_signature IS NULL OR profile_signature = '')"
        ));
    }

    private function profiledSucceededJobsWithoutSignatures(): int
    {
        if (!InterfaceDB::tableExists('photo_conversion_jobs') || !InterfaceDB::columnsExists('photo_conversion_jobs', ['image_type', 'status', 'profile_signature'])) {
            return 0;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_conversion_jobs
             WHERE status = 'succeeded'
               AND image_type IN ('preview', 'final', 'rawtheapee_sample')
               AND (profile_signature IS NULL OR profile_signature = '')"
        ));
    }

    private function signedAssetJobMismatches(): int
    {
        if (!$this->profiledDerivativeTablesAvailable() || !InterfaceDB::columnExists('photo_image_assets', 'conversion_job_id')) {
            return 0;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_image_assets asset
             INNER JOIN photo_conversion_jobs job
                ON job.id = asset.conversion_job_id
             WHERE asset.image_type IN ('preview', 'final', 'rawtheapee_sample')
               AND LENGTH(COALESCE(asset.profile_signature, '')) = 64
               AND LENGTH(COALESCE(job.profile_signature, '')) = 64
               AND asset.profile_signature <> job.profile_signature"
        ));
    }

    private function photoConversionStateMismatches(): int
    {
        if (!InterfaceDB::tableExists('photos') || !InterfaceDB::tableExists('photo_conversion_jobs')) {
            return 0;
        }
        if (!InterfaceDB::columnsExists('photos', ['id', 'upload_state', 'conversion_state']) || !InterfaceDB::columnsExists('photo_conversion_jobs', ['photo_id', 'status'])) {
            return 0;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photos photo
             INNER JOIN (
                 SELECT photo_id,
                        SUM(CASE WHEN status IN ('queued', 'processing') THEN 1 ELSE 0 END) AS active_jobs,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_jobs,
                        COUNT(*) AS total_jobs
                 FROM photo_conversion_jobs
                 GROUP BY photo_id
             ) jobs ON jobs.photo_id = photo.id
             WHERE photo.upload_state = 'uploaded'
               AND (
                    (jobs.active_jobs > 0 AND photo.conversion_state <> 'processing')
                    OR (jobs.active_jobs = 0 AND jobs.failed_jobs > 0 AND photo.conversion_state <> 'failed')
                    OR (jobs.active_jobs = 0 AND jobs.failed_jobs = 0 AND jobs.total_jobs > 0 AND photo.conversion_state <> 'ready')
               )"
        ));
    }

    private function checkRow(string $name, int $count, string $detail): array
    {
        return [
            'name' => $name,
            'status' => $count === 0 ? 'OK' : 'Review',
            'count' => max(0, $count),
            'detail' => $detail,
        ];
    }

    private function isSignature(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', strtolower(trim($value))) === 1;
    }
}
