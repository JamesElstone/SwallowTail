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
use InvalidArgumentException;
use RuntimeException;

final class SwallowtailDataIntegrityCheckService
{
    private const LAZY_SCAN_CURSOR_KEY = 'data_integrity.lazy_scan_after_photo_id';
    private const LAZY_SCAN_REQUESTED_KEY = 'data_integrity.lazy_loading_prevention_requested';
    private const DATA_INTEGRITY_QUEUE_DEFAULT = 'swallowtail:metadata:data_integrity';
    private const MAX_LAZY_SCAN_PHOTOS = 150;
    private const MAX_LAZY_SCAN_SECONDS = 240;
    private const CHECK_ACTIVE_CONVERSION_JOBS = 'active_conversion_jobs';
    private const CHECK_ACTIVE_STORAGE_MIGRATIONS = 'active_storage_migrations';
    private const CHECK_UPLOADED_CR2_MISSING_BASE_CONVERSIONS = 'uploaded_cr2_missing_base_conversions';
    private const CHECK_SUCCEEDED_JOBS_WITHOUT_ASSET_ROWS = 'succeeded_jobs_without_asset_rows';
    private const CHECK_PROFILED_ASSETS_WITHOUT_SIGNATURES = 'profiled_assets_without_signatures';
    private const CHECK_PROFILED_JOBS_WITHOUT_SIGNATURES = 'profiled_jobs_without_signatures';
    private const CHECK_SIGNED_ASSET_JOB_MISMATCHES = 'signed_asset_job_mismatches';
    private const CHECK_PHOTO_CONVERSION_STATE_MISMATCHES = 'photo_conversion_state_mismatches';
    private const REPAIR_MISSING_BASE_CONVERSIONS = 'repair_missing_base_conversions';
    private const REPAIR_PROFILE_SIGNATURES = 'repair_profile_signatures';
    private const REPAIR_CONVERSION_STATES = 'repair_conversion_states';

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

        \Swallowtail\Store\SwallowtailConfigurationStore::set(self::LAZY_SCAN_REQUESTED_KEY, true);
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

    public function queueProfiledDerivativesForPhoto(int $photoId): array
    {
        if ($photoId <= 0) {
            return [
                'success' => false,
                'message' => 'Photo id is required.',
            ];
        }

        if (!$this->profiledDerivativeTablesAvailable()) {
            return [
                'success' => false,
                'message' => 'Profiled derivative queueing requires photos, photo profile data, conversion jobs, and image assets tables.',
            ];
        }

        $photo = InterfaceDB::fetchOne(
            "SELECT p.*
             FROM photos p
             INNER JOIN photo_profile_data status
                ON status.photo_id = p.id
               AND status.type = 'swallowtail'
               AND status.`key` = 'status'
               AND status.value = 'processed'
             WHERE p.id = :photo_id
               AND p.upload_state = 'uploaded'
               AND LOWER(COALESCE(p.original_extension, '')) = 'cr2'
             LIMIT 1",
            ['photo_id' => $photoId]
        );
        if (!is_array($photo)) {
            return [
                'success' => true,
                'photo_id' => $photoId,
                'queued_preview' => 0,
                'queued_final' => 0,
                'already_fresh' => 0,
                'active_jobs' => 0,
                'skipped' => 1,
                'message' => 'Photo is not ready for profiled derivative queueing.',
            ];
        }

        $result = [
            'success' => true,
            'photo_id' => $photoId,
            'queued_preview' => 0,
            'queued_final' => 0,
            'already_fresh' => 0,
            'active_jobs' => 0,
            'skipped' => 0,
        ];

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

        $result['message'] = 'Profiled derivative queueing completed for photo ' . (string)$photoId . '.';

        return $result;
    }

    public function processProfiledDerivativeQueueBatch(int $limit = self::MAX_LAZY_SCAN_PHOTOS): array
    {
        if (!$this->profiledDerivativeTablesAvailable()) {
            return [
                'success' => false,
                'message' => 'Profiled derivative queueing requires photos, photo profile data, conversion jobs, and image assets tables.',
            ];
        }

        $limit = max(1, min(self::MAX_LAZY_SCAN_PHOTOS, $limit));
        $startedAt = time();
        $rows = $this->profiledDerivativeQueueCandidateRowsAfter(0, $limit);

        $result = [
            'success' => true,
            'scanned' => 0,
            'queued_preview' => 0,
            'queued_final' => 0,
            'already_fresh' => 0,
            'active_jobs' => 0,
            'skipped' => 0,
            'last_photo_id' => 0,
            'complete_pass' => false,
            'wrapped' => false,
            'message' => 'Profiled derivative queue batch completed.',
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
            $result['complete_pass'] = true;
            return $result;
        }

        $hasMore = $this->profiledDerivativeQueueCandidateCountAfter((int)$result['last_photo_id']) > 0;
        if (!$hasMore) {
            $result['complete_pass'] = true;
        }

        return $result;
    }

    public function integrityChecks(): array
    {
        $checks = [];
        $blockers = $this->queueBlockers();
        $checks[] = $this->checkRow(self::CHECK_ACTIVE_CONVERSION_JOBS, 'Active conversion jobs', (int)$blockers['photo_conversion_jobs'], 'queued or processing photo_conversion_jobs');
        $checks[] = $this->checkRow(self::CHECK_ACTIVE_STORAGE_MIGRATIONS, 'Active storage migrations', (int)$blockers['storage_migration_jobs'], 'queued or processing storage migration jobs/items');
        $checks[] = $this->checkRow(self::CHECK_UPLOADED_CR2_MISSING_BASE_CONVERSIONS, 'Uploaded CR2 photos missing base conversions', $this->uploadedCr2PhotosMissingBaseConversions(), 'uploaded CR2 photos missing succeeded embedded/thumbnail/original jobs', self::REPAIR_MISSING_BASE_CONVERSIONS);
        $checks[] = $this->checkRow(self::CHECK_SUCCEEDED_JOBS_WITHOUT_ASSET_ROWS, 'Succeeded jobs without asset rows', $this->succeededJobsWithoutAssetRows(), 'completed conversions not yet recorded in photo_image_assets');
        $checks[] = $this->checkRow(self::CHECK_PROFILED_ASSETS_WITHOUT_SIGNATURES, 'Profiled assets without signatures', $this->profiledAssetsWithoutSignatures(), 'preview/final/sample assets missing profile_signature', self::REPAIR_PROFILE_SIGNATURES);
        $checks[] = $this->checkRow(self::CHECK_PROFILED_JOBS_WITHOUT_SIGNATURES, 'Profiled jobs without signatures', $this->profiledSucceededJobsWithoutSignatures(), 'legacy succeeded preview/final/sample jobs missing profile_signature', self::REPAIR_PROFILE_SIGNATURES);
        $checks[] = $this->checkRow(self::CHECK_SIGNED_ASSET_JOB_MISMATCHES, 'Signed asset/job mismatches', $this->signedAssetJobMismatches(), 'asset signature differs from its conversion job signature');
        $checks[] = $this->checkRow(self::CHECK_PHOTO_CONVERSION_STATE_MISMATCHES, 'Photo conversion state mismatches', $this->photoConversionStateMismatches(), 'photos.conversion_state disagrees with job status summary', self::REPAIR_CONVERSION_STATES);

        return $checks;
    }

    public function repairSafeIssues(): array
    {
        $blocked = $this->repairBlockedResult();
        if ($blocked !== null) {
            return $blocked;
        }

        $signatures = $this->repairProfileSignaturesInternal();
        $states = $this->repairPhotoConversionStatesInternal();
        $base = $this->repairMissingBaseConversionsInternal();

        $message = 'Safe repair completed: queued '
            . number_format((int)$base['queued_jobs'])
            . ' base conversion job(s) for '
            . number_format((int)$base['queued_photos'])
            . ' photo(s); updated '
            . number_format((int)$states['updated_states'])
            . ' photo conversion state(s); backfilled '
            . number_format((int)$signatures['assets_backfilled'])
            . ' asset signature(s) and '
            . number_format((int)$signatures['jobs_backfilled'])
            . ' job signature(s); queued '
            . number_format((int)$signatures['queued_profile_jobs'])
            . ' profiled refresh job(s).';

        return [
            'success' => true,
            'message' => $message,
            'missing_base_conversions' => $base,
            'profile_signatures' => $signatures,
            'conversion_states' => $states,
        ];
    }

    public function repairMissingBaseConversions(): array
    {
        $blocked = $this->repairBlockedResult();
        if ($blocked !== null) {
            return $blocked;
        }

        $result = $this->repairMissingBaseConversionsInternal();
        $result['success'] = true;
        $result['message'] = 'Queued ' . number_format((int)$result['queued_jobs'])
            . ' base conversion job(s) for '
            . number_format((int)$result['queued_photos'])
            . ' uploaded CR2 photo(s).';

        return $result;
    }

    public function repairProfileSignatures(): array
    {
        $blocked = $this->repairBlockedResult();
        if ($blocked !== null) {
            return $blocked;
        }

        $result = $this->repairProfileSignaturesInternal();
        $result['success'] = true;
        $result['message'] = 'Profile signature repair completed: backfilled '
            . number_format((int)$result['assets_backfilled'])
            . ' asset signature(s) and '
            . number_format((int)$result['jobs_backfilled'])
            . ' job signature(s); queued '
            . number_format((int)$result['queued_profile_jobs'])
            . ' profiled refresh job(s).'
            . ((int)$result['unsupported_sample_rows'] > 0
                ? ' RawTheapee sample rows cannot be repaired automatically and may need to be regenerated from the profile card.'
                : '');

        return $result;
    }

    public function repairPhotoConversionStates(): array
    {
        $blocked = $this->repairBlockedResult();
        if ($blocked !== null) {
            return $blocked;
        }

        $result = $this->repairPhotoConversionStatesInternal();
        $result['success'] = true;
        $result['message'] = 'Updated '
            . number_format((int)$result['updated_states'])
            . ' photo conversion state(s) from conversion job summaries.';

        return $result;
    }

    public function detailSummary(string $checkKey, int $limit = 10): array
    {
        $limit = max(1, min(25, $limit));
        $checkKey = strtolower(trim($checkKey));

        return match ($checkKey) {
            self::CHECK_UPLOADED_CR2_MISSING_BASE_CONVERSIONS => $this->missingBaseConversionDetails($limit),
            self::CHECK_PROFILED_ASSETS_WITHOUT_SIGNATURES => $this->profiledAssetsWithoutSignatureDetails($limit),
            self::CHECK_PROFILED_JOBS_WITHOUT_SIGNATURES => $this->profiledJobsWithoutSignatureDetails($limit),
            self::CHECK_PHOTO_CONVERSION_STATE_MISMATCHES => $this->photoConversionStateMismatchDetails($limit),
            default => [
                'success' => true,
                'message' => 'No detailed row sample is available for this check.',
            ],
        };
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

    private function profiledDerivativeQueueCandidateRowsAfter(int $photoId, int $limit): array
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
               AND (
                    (
                        NOT EXISTS (
                            SELECT 1
                            FROM photo_image_assets preview_asset
                            WHERE preview_asset.photo_id = p.id
                              AND preview_asset.image_type = 'preview'
                            LIMIT 1
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM photo_conversion_jobs preview_job
                            WHERE preview_job.photo_id = p.id
                              AND preview_job.image_type = 'preview'
                              AND preview_job.status IN ('queued', 'processing', 'succeeded')
                            LIMIT 1
                        )
                    )
                    OR (
                        NOT EXISTS (
                            SELECT 1
                            FROM photo_image_assets final_asset
                            WHERE final_asset.photo_id = p.id
                              AND final_asset.image_type = 'final'
                            LIMIT 1
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM photo_conversion_jobs final_job
                            WHERE final_job.photo_id = p.id
                              AND final_job.image_type = 'final'
                              AND final_job.status IN ('queued', 'processing', 'succeeded')
                            LIMIT 1
                        )
                    )
               )
             ORDER BY p.id
             LIMIT " . max(1, min(self::MAX_LAZY_SCAN_PHOTOS, $limit)),
            ['photo_id' => $photoId]
        );
    }

    private function profiledDerivativeQueueCandidateCountAfter(int $photoId): int
    {
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
               AND LOWER(COALESCE(p.original_extension, '')) = 'cr2'
               AND (
                    (
                        NOT EXISTS (
                            SELECT 1
                            FROM photo_image_assets preview_asset
                            WHERE preview_asset.photo_id = p.id
                              AND preview_asset.image_type = 'preview'
                            LIMIT 1
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM photo_conversion_jobs preview_job
                            WHERE preview_job.photo_id = p.id
                              AND preview_job.image_type = 'preview'
                              AND preview_job.status IN ('queued', 'processing', 'succeeded')
                            LIMIT 1
                        )
                    )
                    OR (
                        NOT EXISTS (
                            SELECT 1
                            FROM photo_image_assets final_asset
                            WHERE final_asset.photo_id = p.id
                              AND final_asset.image_type = 'final'
                            LIMIT 1
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM photo_conversion_jobs final_job
                            WHERE final_job.photo_id = p.id
                              AND final_job.image_type = 'final'
                              AND final_job.status IN ('queued', 'processing', 'succeeded')
                            LIMIT 1
                        )
                    )
               )",
            ['photo_id' => $photoId]
        ));
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
        return max(0, (int)\Swallowtail\Store\SwallowtailConfigurationStore::get(self::LAZY_SCAN_CURSOR_KEY, 0));
    }

    private function setLazyScanCursor(int $photoId): void
    {
        \Swallowtail\Store\SwallowtailConfigurationStore::set(self::LAZY_SCAN_CURSOR_KEY, max(0, $photoId));
    }

    private function lazyLoadingPreventionRequested(): bool
    {
        return (bool)\Swallowtail\Store\SwallowtailConfigurationStore::get(self::LAZY_SCAN_REQUESTED_KEY, false, true);
    }

    private function setLazyLoadingPreventionRequested(bool $requested): void
    {
        \Swallowtail\Store\SwallowtailConfigurationStore::set(self::LAZY_SCAN_REQUESTED_KEY, $requested);
    }

    private function notifyDataIntegrityWorker(string $reason): bool
    {
        $queue = trim((string)\Swallowtail\Store\SwallowtailConfigurationStore::get(
            'redis.metadata_data_integrity_queue',
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

    private function uploadedCr2PhotosMissingBaseConversions(): int
    {
        if (!InterfaceDB::tableExists('photos') || !InterfaceDB::tableExists('photo_conversion_jobs')) {
            return 0;
        }
        if (
            !InterfaceDB::columnsExists('photos', ['id', 'upload_state', 'original_extension'])
            || !InterfaceDB::columnsExists('photo_conversion_jobs', ['photo_id', 'image_type', 'status'])
        ) {
            return 0;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photos photo
             LEFT JOIN (
                 SELECT photo_id,
                        COUNT(DISTINCT CASE
                            WHEN image_type IN ('embedded', 'thumbnail', 'original')
                             AND status = 'succeeded'
                            THEN image_type
                            ELSE NULL
                        END) AS completed_base_types
                 FROM photo_conversion_jobs
                 GROUP BY photo_id
             ) jobs ON jobs.photo_id = photo.id
             WHERE photo.upload_state = 'uploaded'
               AND LOWER(COALESCE(photo.original_extension, '')) = 'cr2'
               AND COALESCE(jobs.completed_base_types, 0) < 3"
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
                        SUM(CASE WHEN status NOT IN ('cancelled', 'obsolete') THEN 1 ELSE 0 END) AS non_cancelled_jobs,
                        SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded_jobs
                 FROM photo_conversion_jobs
                 GROUP BY photo_id
             ) jobs ON jobs.photo_id = photo.id
             WHERE photo.upload_state = 'uploaded'
               AND (
                    (jobs.active_jobs > 0 AND photo.conversion_state <> 'processing')
                    OR (jobs.active_jobs = 0 AND jobs.failed_jobs > 0 AND photo.conversion_state <> 'failed')
                    OR (
                        jobs.active_jobs = 0
                        AND jobs.failed_jobs = 0
                        AND jobs.non_cancelled_jobs > 0
                        AND jobs.succeeded_jobs >= jobs.non_cancelled_jobs
                        AND photo.conversion_state <> 'ready'
                    )
                    OR (
                        jobs.active_jobs = 0
                        AND jobs.failed_jobs = 0
                        AND (
                            jobs.non_cancelled_jobs = 0
                            OR jobs.succeeded_jobs < jobs.non_cancelled_jobs
                        )
                        AND photo.conversion_state <> 'pending'
                    )
            )"
        ));
    }

    private function repairBlockedResult(): ?array
    {
        $blockers = $this->queueBlockers();
        if ((int)($blockers['total'] ?? 0) <= 0) {
            return null;
        }

        return [
            'success' => false,
            'message' => 'Data integrity repairs can only run when conversion and storage migration queues are idle.',
            'blockers' => $blockers,
        ];
    }

    private function repairMissingBaseConversionsInternal(): array
    {
        $queuedPhotos = 0;
        $queuedJobs = 0;
        $skippedPhotos = 0;

        foreach ($this->missingBaseConversionRows() as $row) {
            $photoId = max(0, (int)($row['id'] ?? 0));
            $missingTypes = (array)($row['missing_types'] ?? []);
            if ($photoId <= 0 || $missingTypes === []) {
                $skippedPhotos++;
                continue;
            }

            $jobs = $this->queueService->enqueueRawConversionJobsForTypes($photoId, $missingTypes);
            $photoQueuedJobs = 0;
            foreach ($jobs as $job) {
                if (max(0, (int)($job['job_id'] ?? 0)) > 0) {
                    $photoQueuedJobs++;
                }
            }

            if ($photoQueuedJobs > 0) {
                $queuedPhotos++;
                $queuedJobs += $photoQueuedJobs;
            } else {
                $skippedPhotos++;
            }
        }

        return [
            'queued_photos' => $queuedPhotos,
            'queued_jobs' => $queuedJobs,
            'skipped_photos' => $skippedPhotos,
        ];
    }

    private function repairProfileSignaturesInternal(): array
    {
        if (
            !InterfaceDB::tableExists('photo_image_assets')
            || !InterfaceDB::tableExists('photo_conversion_jobs')
            || !InterfaceDB::columnsExists('photo_image_assets', ['id', 'conversion_job_id', 'image_type', 'profile_signature'])
            || !InterfaceDB::columnsExists('photo_conversion_jobs', ['id', 'image_type', 'profile_signature'])
        ) {
            return [
                'assets_backfilled' => 0,
                'jobs_backfilled' => 0,
                'queued_profile_jobs' => 0,
                'queued_profile_photos' => 0,
                'skipped_profile_rows' => 0,
                'unsupported_sample_rows' => 0,
            ];
        }

        $backfill = InterfaceDB::transaction(function (): array {
            $assetsBackfilled = 0;
            $jobsBackfilled = $this->backfillRawTheapeeSampleJobSignatures();
            $jobsBackfilled += $this->backfillPreviewFinalJobSignaturesFromCurrentProfiles();
            $assetRows = InterfaceDB::fetchAll(
                "SELECT asset.id, job.profile_signature
                 FROM photo_image_assets asset
                 INNER JOIN photo_conversion_jobs job
                    ON job.id = asset.conversion_job_id
                   AND job.image_type = asset.image_type
                 WHERE asset.image_type IN ('preview', 'final', 'rawtheapee_sample')
                   AND (asset.profile_signature IS NULL OR asset.profile_signature = '')
                   AND LENGTH(COALESCE(job.profile_signature, '')) = 64"
            );
            foreach ($assetRows as $row) {
                $assetId = max(0, (int)($row['id'] ?? 0));
                $signature = strtolower(trim((string)($row['profile_signature'] ?? '')));
                if ($assetId <= 0 || !$this->isSignature($signature)) {
                    continue;
                }

                $assetsBackfilled += InterfaceDB::execute(
                    "UPDATE photo_image_assets
                     SET profile_signature = :profile_signature
                     WHERE id = :id
                       AND (profile_signature IS NULL OR profile_signature = '')",
                    [
                        'id' => $assetId,
                        'profile_signature' => $signature,
                    ]
                );
            }

            $assetsBackfilled += $this->backfillAssetsFromLatestSignedJobs();
            $jobRows = InterfaceDB::fetchAll(
                "SELECT job.id, asset.profile_signature
                 FROM photo_conversion_jobs job
                 INNER JOIN photo_image_assets asset
                    ON asset.conversion_job_id = job.id
                   AND asset.image_type = job.image_type
                 WHERE job.image_type IN ('preview', 'final', 'rawtheapee_sample')
                   AND (job.profile_signature IS NULL OR job.profile_signature = '')
                   AND LENGTH(COALESCE(asset.profile_signature, '')) = 64"
            );
            foreach ($jobRows as $row) {
                $jobId = max(0, (int)($row['id'] ?? 0));
                $signature = strtolower(trim((string)($row['profile_signature'] ?? '')));
                if ($jobId <= 0 || !$this->isSignature($signature)) {
                    continue;
                }

                $jobsBackfilled += InterfaceDB::execute(
                    "UPDATE photo_conversion_jobs
                     SET profile_signature = :profile_signature
                     WHERE id = :id
                       AND (profile_signature IS NULL OR profile_signature = '')",
                    [
                        'id' => $jobId,
                        'profile_signature' => $signature,
                    ]
                );
            }

            return [
                'assets_backfilled' => $assetsBackfilled,
                'jobs_backfilled' => $jobsBackfilled,
            ];
        });

        $queued = $this->queueUnsignedProfileSignatureRefreshes();
        $unsupportedSampleRows = $this->unsupportedUnsignedSampleProfileRowCount();

        return $backfill + $queued + [
            'unsupported_sample_rows' => $unsupportedSampleRows,
        ];
    }

    private function backfillPreviewFinalJobSignaturesFromCurrentProfiles(): int
    {
        if (
            !InterfaceDB::columnsExists('photo_conversion_jobs', ['id', 'photo_id', 'image_type', 'status', 'profile_signature'])
            || !InterfaceDB::tableExists('photo_profile_data')
        ) {
            return 0;
        }

        $updated = 0;
        $signatures = [];
        foreach (InterfaceDB::fetchAll(
            "SELECT id, photo_id, image_type
             FROM photo_conversion_jobs
             WHERE status = 'succeeded'
               AND image_type IN ('preview', 'final')
               AND (profile_signature IS NULL OR profile_signature = '')
             ORDER BY id"
        ) as $row) {
            $jobId = max(0, (int)($row['id'] ?? 0));
            $photoId = max(0, (int)($row['photo_id'] ?? 0));
            $imageType = strtolower(trim((string)($row['image_type'] ?? '')));
            if ($jobId <= 0 || $photoId <= 0 || !in_array($imageType, ['preview', 'final'], true)) {
                continue;
            }

            $key = $photoId . ':' . $imageType;
            if (!array_key_exists($key, $signatures)) {
                $signatures[$key] = $this->combinedProfileService->profileSignature($photoId, $imageType);
            }

            $signature = (string)$signatures[$key];
            if (!$this->isSignature($signature)) {
                continue;
            }

            $updated += InterfaceDB::execute(
                "UPDATE photo_conversion_jobs
                 SET profile_signature = :profile_signature
                 WHERE id = :id
                   AND (profile_signature IS NULL OR profile_signature = '')",
                [
                    'id' => $jobId,
                    'profile_signature' => $signature,
                ]
            );
        }

        return $updated;
    }

    private function backfillRawTheapeeSampleJobSignatures(): int
    {
        if (!InterfaceDB::columnsExists('photo_conversion_jobs', ['id', 'image_type', 'profile_path', 'profile_signature'])) {
            return 0;
        }

        $rawTheapee = new SwallowtailRawTheapeeProfileService();
        $updated = 0;
        foreach (InterfaceDB::fetchAll(
            "SELECT id, profile_path
             FROM photo_conversion_jobs
             WHERE image_type = 'rawtheapee_sample'
               AND (profile_signature IS NULL OR profile_signature = '')
               AND profile_path IS NOT NULL
               AND profile_path <> ''
             ORDER BY id"
        ) as $row) {
            $jobId = max(0, (int)($row['id'] ?? 0));
            $signature = $rawTheapee->profileSignatureForPath((string)($row['profile_path'] ?? ''));
            if ($jobId <= 0 || !$this->isSignature($signature)) {
                continue;
            }

            $updated += InterfaceDB::execute(
                "UPDATE photo_conversion_jobs
                 SET profile_signature = :profile_signature
                 WHERE id = :id
                   AND (profile_signature IS NULL OR profile_signature = '')",
                [
                    'id' => $jobId,
                    'profile_signature' => $signature,
                ]
            );
        }

        return $updated;
    }

    private function backfillAssetsFromLatestSignedJobs(): int
    {
        if (
            !InterfaceDB::columnsExists('photo_image_assets', ['id', 'photo_id', 'image_type', 'profile_signature', 'conversion_job_id'])
            || !InterfaceDB::columnsExists('photo_conversion_jobs', ['id', 'photo_id', 'image_type', 'status', 'profile_signature'])
        ) {
            return 0;
        }

        $updated = 0;
        $assetRows = InterfaceDB::fetchAll(
            "SELECT id, photo_id, image_type
             FROM photo_image_assets
             WHERE image_type IN ('preview', 'final', 'rawtheapee_sample')
               AND (profile_signature IS NULL OR profile_signature = '')
             ORDER BY id"
        );

        foreach ($assetRows as $asset) {
            $assetId = max(0, (int)($asset['id'] ?? 0));
            $photoId = max(0, (int)($asset['photo_id'] ?? 0));
            $imageType = strtolower(trim((string)($asset['image_type'] ?? '')));
            if ($assetId <= 0 || $photoId <= 0 || !in_array($imageType, ['preview', 'final', 'rawtheapee_sample'], true)) {
                continue;
            }

            $job = InterfaceDB::fetchOne(
                "SELECT id, profile_signature
                 FROM photo_conversion_jobs
                 WHERE photo_id = :photo_id
                   AND image_type = :image_type
                   AND status = 'succeeded'
                   AND LENGTH(COALESCE(profile_signature, '')) = 64
                 ORDER BY id DESC
                 LIMIT 1",
                [
                    'photo_id' => $photoId,
                    'image_type' => $imageType,
                ]
            );
            if (!is_array($job)) {
                continue;
            }

            $jobId = max(0, (int)($job['id'] ?? 0));
            $signature = strtolower(trim((string)($job['profile_signature'] ?? '')));
            if ($jobId <= 0 || !$this->isSignature($signature)) {
                continue;
            }

            $updated += InterfaceDB::execute(
                "UPDATE photo_image_assets
                 SET profile_signature = :profile_signature,
                     conversion_job_id = COALESCE(conversion_job_id, :conversion_job_id)
                 WHERE id = :id
                   AND (profile_signature IS NULL OR profile_signature = '')",
                [
                    'id' => $assetId,
                    'profile_signature' => $signature,
                    'conversion_job_id' => $jobId,
                ]
            );
        }

        return $updated;
    }

    private function queueUnsignedProfileSignatureRefreshes(): array
    {
        $queuedJobs = 0;
        $queuedPhotos = [];
        $skippedRows = 0;

        foreach ($this->unsignedProfileSignatureRefreshRows() as $row) {
            $photoId = max(0, (int)($row['id'] ?? 0));
            $imageType = strtolower(trim((string)($row['image_type'] ?? '')));
            if ($photoId <= 0 || !in_array($imageType, ['preview', 'final'], true)) {
                $skippedRows++;
                continue;
            }

            $result = $this->queueProfiledDerivativeIfNeeded($row, $imageType);
            if ($result === 'queued_preview' || $result === 'queued_final') {
                $queuedJobs++;
                $queuedPhotos[$photoId] = true;
                continue;
            }

            if ($result !== 'active_job') {
                $skippedRows++;
            }
        }

        return [
            'queued_profile_jobs' => $queuedJobs,
            'queued_profile_photos' => count($queuedPhotos),
            'skipped_profile_rows' => $skippedRows,
        ];
    }

    private function unsignedProfileSignatureRefreshRows(): array
    {
        if (
            !InterfaceDB::tableExists('photos')
            || !InterfaceDB::tableExists('photo_image_assets')
            || !InterfaceDB::tableExists('photo_conversion_jobs')
            || !InterfaceDB::columnsExists('photos', ['id', 'original_sha256', 'storage_base_location'])
            || !InterfaceDB::columnsExists('photo_image_assets', ['photo_id', 'image_type', 'profile_signature'])
            || !InterfaceDB::columnsExists('photo_conversion_jobs', ['photo_id', 'image_type', 'status', 'profile_signature'])
        ) {
            return [];
        }

        $rows = [];
        foreach (InterfaceDB::fetchAll(
            "SELECT DISTINCT photo.id,
                    photo.original_sha256,
                    photo.storage_base_location,
                    asset.image_type
             FROM photo_image_assets asset
             INNER JOIN photos photo ON photo.id = asset.photo_id
             WHERE asset.image_type IN ('preview', 'final')
               AND (asset.profile_signature IS NULL OR asset.profile_signature = '')
             ORDER BY photo.id, asset.image_type"
        ) as $row) {
            $key = (string)(int)($row['id'] ?? 0) . ':' . (string)($row['image_type'] ?? '');
            $rows[$key] = $row;
        }

        foreach (InterfaceDB::fetchAll(
            "SELECT DISTINCT photo.id,
                    photo.original_sha256,
                    photo.storage_base_location,
                    job.image_type
             FROM photo_conversion_jobs job
             INNER JOIN photos photo ON photo.id = job.photo_id
             WHERE job.status = 'succeeded'
               AND job.image_type IN ('preview', 'final')
               AND (job.profile_signature IS NULL OR job.profile_signature = '')
             ORDER BY photo.id, job.image_type"
        ) as $row) {
            $key = (string)(int)($row['id'] ?? 0) . ':' . (string)($row['image_type'] ?? '');
            $rows[$key] = $row;
        }

        return array_values($rows);
    }

    private function unsupportedUnsignedSampleProfileRowCount(): int
    {
        $count = 0;
        if (InterfaceDB::tableExists('photo_image_assets') && InterfaceDB::columnsExists('photo_image_assets', ['image_type', 'profile_signature'])) {
            $count += max(0, (int)InterfaceDB::fetchColumn(
                "SELECT COUNT(*)
                 FROM photo_image_assets
                 WHERE image_type = 'rawtheapee_sample'
                   AND (profile_signature IS NULL OR profile_signature = '')"
            ));
        }

        if (InterfaceDB::tableExists('photo_conversion_jobs') && InterfaceDB::columnsExists('photo_conversion_jobs', ['image_type', 'status', 'profile_signature'])) {
            $count += max(0, (int)InterfaceDB::fetchColumn(
                "SELECT COUNT(*)
                 FROM photo_conversion_jobs
                 WHERE status = 'succeeded'
                   AND image_type = 'rawtheapee_sample'
                   AND (profile_signature IS NULL OR profile_signature = '')"
            ));
        }

        return $count;
    }

    private function repairPhotoConversionStatesInternal(): array
    {
        $updated = 0;
        foreach ($this->photoConversionStateRows() as $row) {
            $photoId = max(0, (int)($row['id'] ?? 0));
            $expected = $this->photoStateFromJobCounts($row);
            $current = strtolower(trim((string)($row['conversion_state'] ?? '')));
            if ($photoId <= 0 || $expected === $current) {
                continue;
            }

            $updated += InterfaceDB::execute(
                "UPDATE photos
                 SET conversion_state = :conversion_state
                 WHERE id = :id
                   AND conversion_state <> :conversion_state",
                [
                    'id' => $photoId,
                    'conversion_state' => $expected,
                ]
            );
        }

        return [
            'updated_states' => $updated,
        ];
    }

    private function missingBaseConversionRows(int $limit = 0): array
    {
        if (!InterfaceDB::tableExists('photos') || !InterfaceDB::tableExists('photo_conversion_jobs')) {
            return [];
        }
        if (
            !InterfaceDB::columnsExists('photos', ['id', 'upload_state', 'original_extension'])
            || !InterfaceDB::columnsExists('photo_conversion_jobs', ['photo_id', 'image_type', 'status'])
        ) {
            return [];
        }

        $limitSql = $limit > 0 ? ' LIMIT ' . max(1, min(500, $limit)) : '';
        $rows = InterfaceDB::fetchAll(
            "SELECT photo.id,
                    COALESCE(jobs.has_embedded, 0) AS has_embedded,
                    COALESCE(jobs.has_thumbnail, 0) AS has_thumbnail,
                    COALESCE(jobs.has_original, 0) AS has_original
             FROM photos photo
             LEFT JOIN (
                 SELECT photo_id,
                        MAX(CASE WHEN image_type = 'embedded' AND status = 'succeeded' THEN 1 ELSE 0 END) AS has_embedded,
                        MAX(CASE WHEN image_type = 'thumbnail' AND status = 'succeeded' THEN 1 ELSE 0 END) AS has_thumbnail,
                        MAX(CASE WHEN image_type = 'original' AND status = 'succeeded' THEN 1 ELSE 0 END) AS has_original
                 FROM photo_conversion_jobs
                 GROUP BY photo_id
             ) jobs ON jobs.photo_id = photo.id
             WHERE photo.upload_state = 'uploaded'
               AND LOWER(COALESCE(photo.original_extension, '')) = 'cr2'
               AND (
                    COALESCE(jobs.has_embedded, 0) = 0
                    OR COALESCE(jobs.has_thumbnail, 0) = 0
                    OR COALESCE(jobs.has_original, 0) = 0
               )
             ORDER BY photo.id" . $limitSql
        );

        foreach ($rows as &$row) {
            $missing = [];
            foreach (['embedded', 'thumbnail', 'original'] as $imageType) {
                if ((int)($row['has_' . $imageType] ?? 0) <= 0) {
                    $missing[] = $imageType;
                }
            }
            $row['missing_types'] = $missing;
        }
        unset($row);

        return $rows;
    }

    private function photoConversionStateRows(): array
    {
        if (!InterfaceDB::tableExists('photos') || !InterfaceDB::tableExists('photo_conversion_jobs')) {
            return [];
        }
        if (!InterfaceDB::columnsExists('photos', ['id', 'upload_state', 'conversion_state']) || !InterfaceDB::columnsExists('photo_conversion_jobs', ['photo_id', 'status'])) {
            return [];
        }

        return InterfaceDB::fetchAll(
            "SELECT photo.id,
                    photo.conversion_state,
                    COALESCE(jobs.active_jobs, 0) AS active_jobs,
                    COALESCE(jobs.failed_jobs, 0) AS failed_jobs,
                    COALESCE(jobs.non_cancelled_jobs, 0) AS non_cancelled_jobs,
                    COALESCE(jobs.succeeded_jobs, 0) AS succeeded_jobs
             FROM photos photo
             INNER JOIN (
                 SELECT photo_id,
                        SUM(CASE WHEN status IN ('queued', 'processing') THEN 1 ELSE 0 END) AS active_jobs,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_jobs,
                        SUM(CASE WHEN status NOT IN ('cancelled', 'obsolete') THEN 1 ELSE 0 END) AS non_cancelled_jobs,
                        SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded_jobs
                 FROM photo_conversion_jobs
                 GROUP BY photo_id
             ) jobs ON jobs.photo_id = photo.id
             WHERE photo.upload_state = 'uploaded'
             ORDER BY photo.id"
        );
    }

    private function photoStateFromJobCounts(array $row): string
    {
        $active = max(0, (int)($row['active_jobs'] ?? 0));
        $failed = max(0, (int)($row['failed_jobs'] ?? 0));
        $nonCancelled = max(0, (int)($row['non_cancelled_jobs'] ?? 0));
        $succeeded = max(0, (int)($row['succeeded_jobs'] ?? 0));

        if ($active > 0) {
            return 'processing';
        }
        if ($failed > 0) {
            return 'failed';
        }
        if ($nonCancelled > 0 && $succeeded >= $nonCancelled) {
            return 'ready';
        }

        return 'pending';
    }

    private function missingBaseConversionDetails(int $limit): array
    {
        $items = [];
        foreach ($this->missingBaseConversionRows($limit) as $row) {
            $items[] = '#' . (string)(int)($row['id'] ?? 0) . ' missing ' . implode('/', (array)($row['missing_types'] ?? []));
        }

        return [
            'success' => true,
            'message' => $items === []
                ? 'No uploaded CR2 photos are missing base conversions.'
                : 'First affected photos: ' . implode('; ', $items),
        ];
    }

    private function profiledAssetsWithoutSignatureDetails(int $limit): array
    {
        if (!InterfaceDB::tableExists('photo_image_assets') || !InterfaceDB::columnsExists('photo_image_assets', ['id', 'photo_id', 'image_type', 'profile_signature'])) {
            return ['success' => true, 'message' => 'Profiled asset rows are not available.'];
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT id, photo_id, image_type
             FROM photo_image_assets
             WHERE image_type IN ('preview', 'final', 'rawtheapee_sample')
               AND (profile_signature IS NULL OR profile_signature = '')
             ORDER BY id
             LIMIT " . $limit
        );

        $items = array_map(
            static fn(array $row): string => '#' . (string)(int)($row['id'] ?? 0) . ' photo ' . (string)(int)($row['photo_id'] ?? 0) . ' ' . (string)($row['image_type'] ?? ''),
            $rows
        );

        return [
            'success' => true,
            'message' => $items === []
                ? 'No profiled asset rows are missing signatures.'
                : 'First affected assets: ' . implode('; ', $items),
        ];
    }

    private function profiledJobsWithoutSignatureDetails(int $limit): array
    {
        if (!InterfaceDB::tableExists('photo_conversion_jobs') || !InterfaceDB::columnsExists('photo_conversion_jobs', ['id', 'photo_id', 'image_type', 'status', 'profile_signature'])) {
            return ['success' => true, 'message' => 'Profiled job rows are not available.'];
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT id, photo_id, image_type
             FROM photo_conversion_jobs
             WHERE status = 'succeeded'
               AND image_type IN ('preview', 'final', 'rawtheapee_sample')
               AND (profile_signature IS NULL OR profile_signature = '')
             ORDER BY id
             LIMIT " . $limit
        );

        $items = array_map(
            static fn(array $row): string => '#' . (string)(int)($row['id'] ?? 0) . ' photo ' . (string)(int)($row['photo_id'] ?? 0) . ' ' . (string)($row['image_type'] ?? ''),
            $rows
        );

        return [
            'success' => true,
            'message' => $items === []
                ? 'No profiled conversion jobs are missing signatures.'
                : 'First affected jobs: ' . implode('; ', $items),
        ];
    }

    private function photoConversionStateMismatchDetails(int $limit): array
    {
        $items = [];
        foreach ($this->photoConversionStateRows() as $row) {
            $expected = $this->photoStateFromJobCounts($row);
            $current = strtolower(trim((string)($row['conversion_state'] ?? '')));
            if ($expected === $current) {
                continue;
            }

            $items[] = '#' . (string)(int)($row['id'] ?? 0) . ' ' . $current . ' -> ' . $expected;
            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'success' => true,
            'message' => $items === []
                ? 'No photo conversion state mismatches were found.'
                : 'First affected photos: ' . implode('; ', $items),
        ];
    }

    private function checkRow(string $key, string $name, int $count, string $detail, string $repairAction = ''): array
    {
        $row = [
            'key' => $key,
            'name' => $name,
            'status' => $count === 0 ? 'OK' : 'Review',
            'count' => max(0, $count),
            'detail' => $detail,
        ];

        if ($repairAction !== '') {
            $row['repair_action'] = $repairAction;
        }

        return $row;
    }

    private function isSignature(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', strtolower(trim($value))) === 1;
    }
}
