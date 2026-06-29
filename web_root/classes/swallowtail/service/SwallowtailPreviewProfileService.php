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
use InvalidArgumentException;
use RuntimeException;

final class SwallowtailPreviewProfileService
{
    private const DEFAULT_SOURCE_WIDTH = 6000;
    private const DEFAULT_SOURCE_HEIGHT = 4000;
    private const STATUS_IMAGE_TYPES = ['embedded', 'thumbnail', 'original', 'preview', 'final', 'rawtherapee_sample'];

    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly SwallowtailPhotoUiService $photoUiService = new SwallowtailPhotoUiService(),
        private readonly SwallowtailConversionQueueService $queueService = new SwallowtailConversionQueueService(),
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailProfileDataService $profileDataService = new SwallowtailProfileDataService(),
        private readonly SwallowtailCombinedProfileService $combinedProfileService = new SwallowtailCombinedProfileService(),
        private readonly SwallowtailPhotoAssetService $assetService = new SwallowtailPhotoAssetService(),
        private readonly SwallowtailRawTherapeeProfileService $rawTherapeeProfileService = new SwallowtailRawTherapeeProfileService(),
    ) {
    }

    public function editorState(int $photoId, int $userId): ?array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return null;
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return null;
        }

        $this->queueService->boostQueuedJobsForViewedPhoto($photoId);
        $baselineStatus = $this->profileDataService->requestUrgentProfile($photo, 'picture_editor');

        $dimensions = $this->sourceDimensions($photo);
        $settings = $baselineStatus['ready']
            ? $this->profileDataService->settingsFromRows(
                (int)$photo['id'],
                $dimensions['width'],
                $dimensions['height'],
                $this->defaultSettings($dimensions['width'], $dimensions['height'])
            )
            : $this->defaultSettings($dimensions['width'], $dimensions['height']);

        $preview = $this->previewWorkflowState($photo, $baselineStatus, $userId);
        $rawTherapeeProfiles = $this->rawTherapeeProfileService->availableProfiles();
        $rawTherapeeProfileId = InterfaceDB::columnExists('photos', 'rawtherapee_profile_id')
            ? max(0, (int)($photo['rawtherapee_profile_id'] ?? 0))
            : 0;
        $rawTherapeeDefaultProfileId = max(0, (int)($this->rawTherapeeProfileService->defaultProfileId() ?? 0));

        return [
            'photo' => $photo,
            'source_width' => $dimensions['width'],
            'source_height' => $dimensions['height'],
            'settings' => $settings,
            'baseline' => $baselineStatus,
            'preview' => $preview,
            'preview_ready' => !empty($preview['ready']),
            'preview_type' => (string)($preview['display_type'] ?? ''),
            'preview_url' => (string)($preview['display_url'] ?? ''),
            'preview_status' => (string)($preview['status'] ?? ''),
            'preview_status_url' => (string)($preview['status_url'] ?? ''),
            'rawtherapee_profiles' => $rawTherapeeProfiles,
            'rawtherapee_profile_id' => $rawTherapeeProfileId,
            'rawtherapee_default_profile_id' => $rawTherapeeDefaultProfileId,
        ];
    }

    public function currentProfilePreviewState(int $photoId, int $userId): ?array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return null;
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return null;
        }

        $this->queueService->boostQueuedJobsForViewedPhoto($photoId);
        $baselineStatus = $this->profileDataService->requestUrgentProfile($photo, 'rawtherapee_profiles_current');
        $preview = $this->previewWorkflowState($photo, $baselineStatus, $userId);

        return [
            'photo' => $photo,
            'ready' => !empty($preview['ready']),
            'status' => (string)($preview['status'] ?? ''),
            'display_type' => (string)($preview['display_type'] ?? ''),
            'display_url' => (string)($preview['display_url'] ?? ''),
            'status_url' => (string)($preview['status_url'] ?? ''),
        ];
    }

    public function enqueuePreview(int $photoId, int $userId, array $payload): array
    {
        return $this->enqueueProfiledRender($photoId, $userId, $payload, 'preview');
    }

    public function enqueueFinal(int $photoId, int $userId, array $payload): array
    {
        return $this->enqueueProfiledRender($photoId, $userId, $payload, 'final');
    }

    public function changeBaselineProfile(int $photoId, int $userId, int $profileId): array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['You do not have permission to edit this photo.'],
            ];
        }

        if (!$this->photoUiService->userCanEditPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['You do not have permission to edit this photo.'],
            ];
        }

        if (!InterfaceDB::columnExists('photos', 'rawtherapee_profile_id')) {
            return [
                'success' => false,
                'errors' => ['RawTherapee baseline profiles are not available yet.'],
            ];
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        $profileId = max(0, $profileId);
        $profile = null;
        if ($profileId > 0) {
            $profile = $this->rawTherapeeProfileService->profileById($profileId);
            if ($profile === null) {
                return [
                    'success' => false,
                    'errors' => ['RawTherapee baseline profile was not available.'],
                ];
            }
        }

        $currentProfileId = max(0, (int)($photo['rawtherapee_profile_id'] ?? 0));
        if ($currentProfileId === $profileId) {
            return [
                'success' => true,
                'profile_id' => $profileId,
                'job_id' => 0,
                'baseline' => $this->profileDataService->status($photoId),
            ];
        }

        $base = (string)($photo['storage_base_location'] ?? '');
        $sha256 = (string)($photo['original_sha256'] ?? '');
        $sourceProfilePath = $this->storageService->imagePath($base, $sha256, 'source_profile');
        $profilePath = $profile === null ? null : trim((string)($profile['profile_path'] ?? ''));

        InterfaceDB::transaction(function () use ($photoId, $profileId, $profile): void {
            InterfaceDB::prepareExecute(
                "UPDATE photos
                 SET rawtherapee_profile_id = :profile_id
                 WHERE id = :photo_id",
                [
                    'photo_id' => $photoId,
                    'profile_id' => $profileId > 0 ? $profileId : null,
                ]
            );
            $this->profileDataService->markBaselineQueued($photoId, $profile);
            $this->queueService->obsoleteActiveJobsForPhoto(
                $photoId,
                ['original', 'thumbnail', 'preview', 'final'],
                'RawTherapee baseline profile changed.'
            );
        });

        if (is_file($sourceProfilePath)) {
            @unlink($sourceProfilePath);
        }

        $jobId = $this->queueService->enqueueOriginalRefresh($photoId, $profilePath, $userId);
        $this->queueService->enqueueRawConversionJobsForTypes($photoId, ['thumbnail'], 80);
        $this->profileDataService->notifyUrgentProfile($photoId, 'baseline_profile_changed');
        $photo = $this->photoLibraryService->photoById($photoId) ?? $photo;
        $baseline = $this->profileDataService->status($photoId);

        return [
            'success' => true,
            'profile_id' => $profileId,
            'job_id' => $jobId ?? 0,
            'baseline' => $baseline,
            'preview' => $this->previewWorkflowState($photo, $baseline, $userId),
        ];
    }

    private function enqueueProfiledRender(int $photoId, int $userId, array $payload, string $imageType): array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['You do not have permission to edit this photo.'],
            ];
        }

        if (!$this->photoUiService->userCanEditPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['You do not have permission to edit this photo.'],
            ];
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        $baseline = $this->profileDataService->status($photoId);
        if (empty($baseline['ready'])) {
            $this->profileDataService->requestUrgentProfile($photo, 'preview_request');
            return [
                'success' => false,
                'errors' => ['RawTherapee baseline profile is still being prepared.'],
                'baseline' => $baseline,
            ];
        }

        $dimensions = $this->sourceDimensions($photo);
        $settings = $this->normaliseSettings($payload, $dimensions['width'], $dimensions['height']);
        $this->profileDataService->recordChangedRows($photoId, $this->profileRowsForSettings($settings));
        $profileSignature = $this->combinedProfileService->profileSignature($photoId, $imageType);

        if ($imageType === 'final' && $this->assetService->finalMatchesOriginalProfile($photo)) {
            $asset = $this->assetService->assetForPhotoWithFinalFallback($photo, 'final');

            return [
                'success' => true,
                'job_id' => 0,
                'settings' => $settings,
                'final_url' => $asset === null ? '' : $this->previewUrl($photoId, $asset),
                'final_ready' => $asset !== null,
                'final_status' => $asset === null ? 'pending' : 'loaded',
                'final_equivalent_original' => $asset !== null,
                'status_url' => $this->statusUrl($photoId, 0, 'final'),
            ];
        }

        $profilePath = $this->writeProfile($photo, $imageType);

        $jobId = $imageType === 'final'
            ? $this->queueService->enqueueFinalRefresh($photoId, $profilePath, $userId, $profileSignature)
            : $this->queueService->enqueuePreviewRefresh(
                $photoId,
                $profilePath,
                $userId,
                $profileSignature
            );

        if ($jobId === null) {
            return [
                'success' => false,
                'errors' => [ucfirst($imageType) . ' image job could not be queued.'],
            ];
        }

        $result = [
            'success' => true,
            'job_id' => $jobId,
            'settings' => $settings,
            $imageType . '_url' => '',
            'status_url' => $this->statusUrl($photoId, $jobId, $imageType),
        ];
        if ($imageType === 'preview') {
            $result['preview_url'] = '';
        }

        return $result;
    }

    public function baselineStatus(int $photoId, int $userId): array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        $status = $this->profileDataService->requestUrgentProfile($photo, 'picture_editor_poll');
        $dimensions = $this->sourceDimensions($photo);
        $settings = $status['ready']
            ? $this->profileDataService->settingsFromRows(
                $photoId,
                $dimensions['width'],
                $dimensions['height'],
                $this->defaultSettings($dimensions['width'], $dimensions['height'])
            )
            : $this->defaultSettings($dimensions['width'], $dimensions['height']);

        $preview = $this->previewWorkflowState($photo, $status, $userId);

        return [
            'success' => true,
            'baseline' => $status,
            'settings' => $settings,
            'preview' => $preview,
            'preview_ready' => !empty($preview['ready']),
            'preview_status_url' => (string)($preview['status_url'] ?? ''),
        ];
    }

    public function pictureViewerState(int $photoId, int $userId): array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        $this->queueService->boostQueuedJobsForViewedPhoto($photoId);
        $baseline = $this->profileDataService->requestUrgentProfile($photo, 'picture_viewer_final');
        $baselineReady = !empty($baseline['ready']);
        $canViewFinal = $this->photoUiService->userCanViewImageType($photoId, $userId, 'final');
        $finalProfileSignature = '';
        if ($canViewFinal && $baselineReady) {
            $finalProfileSignature = $this->combinedProfileService->profileSignature($photoId, 'final');
        }
        $finalEquivalentOriginal = $canViewFinal
            && $baselineReady
            && $this->assetService->finalMatchesOriginalProfile($photo);
        $job = $canViewFinal && !$finalEquivalentOriginal
            ? $this->activeFinalJob($photoId, $baselineReady ? $finalProfileSignature : '')
            : null;
        $displayAsset = $this->pictureViewerDisplayAsset($photo, $userId, $finalEquivalentOriginal);
        $displayType = is_array($displayAsset) ? (string)($displayAsset['image_type'] ?? '') : '';
        $finalFresh = $finalEquivalentOriginal
            ? is_array($displayAsset)
                && !empty($displayAsset['final_equivalent_original'])
            : $displayType === 'final'
            && $job === null
            && $baselineReady
            && $finalProfileSignature !== ''
            && $this->assetService->isFreshForSignature($displayAsset, $finalProfileSignature);
        $finalReady = $finalFresh && ($finalEquivalentOriginal || $displayType === 'final');
        $state = [
            'success' => true,
            'photo_id' => $photoId,
            'display_type' => $displayType,
            'display_url' => is_array($displayAsset) ? $this->previewUrl($photoId, $displayAsset) : '',
            'final_ready' => $finalReady,
            'final_status' => $finalReady || !$canViewFinal ? 'loaded' : 'queued',
            'state_url' => $this->pictureViewerStateUrl($photoId),
        ];

        if (!$canViewFinal) {
            return $state;
        }

        if (!$baselineReady) {
            return $state;
        }

        if ($finalEquivalentOriginal) {
            return $state;
        }

        if ($displayType === 'final' && $finalFresh) {
            return $state;
        }

        if ($finalProfileSignature === '') {
            return $state;
        }

        if ($job === null) {
            $profilePath = $this->writeProfile($photo, 'final');
            $jobId = $this->queueService->enqueueViewedFinalRefresh($photoId, $profilePath, $userId, $finalProfileSignature);
            if ($jobId !== null) {
                $job = [
                    'id' => $jobId,
                    'status' => 'queued',
                    'profile_signature' => $finalProfileSignature,
                ];
            }
        }

        if (is_array($job)) {
            $status = (string)($job['status'] ?? 'queued');
            $state['final_status'] = $status === 'processing' ? 'rendering' : 'queued';
            $state['job_id'] = max(0, (int)($job['id'] ?? 0));
        }

        return $state;
    }

    public function imageStatus(int $photoId, int $jobId, int $userId, string $imageType): array
    {
        $imageType = strtolower(trim($imageType));
        if (!in_array($imageType, self::STATUS_IMAGE_TYPES, true)) {
            return [
                'success' => false,
                'errors' => ['Unsupported image status type.'],
            ];
        }

        if ($jobId <= 0) {
            return $this->assetStatus($photoId, $userId, $imageType);
        }

        return $this->renderStatus($photoId, $jobId, $userId, $imageType);
    }

    private function assetStatus(int $photoId, int $userId, string $imageType): array
    {
        if ($imageType === 'rawtherapee_sample') {
            return [
                'success' => false,
                'errors' => ['RawTherapee sample status requires a conversion job.'],
            ];
        }

        if ($photoId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        $asset = $imageType === 'final'
            ? $this->assetService->assetForPhotoWithFinalFallback($photo, $imageType)
            : $this->assetService->assetForPhoto($photo, $imageType);
        if ($asset === null) {
            (new SwallowtailPhotoAssetNotificationService())->notifyPhotoAsset(
                $photo,
                $imageType,
                'photo_status_poll'
            );

            return [
                'success' => true,
                'photo_id' => $photoId,
                'image_type' => $imageType,
                'ready' => false,
                'status' => 'pending',
            ];
        }

        return [
            'success' => true,
            'photo_id' => $photoId,
            'image_type' => $imageType,
            'ready' => true,
            'status' => 'succeeded',
            $imageType . '_url' => $this->previewUrl($photoId, $asset),
        ];
    }

    private function renderStatus(int $photoId, int $jobId, int $userId, string $imageType): array
    {
        if ($photoId <= 0 || $jobId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => [ucfirst($imageType) . ' image job was not found.'],
            ];
        }

        $job = InterfaceDB::fetchOne(
            "SELECT id, status, last_error, profile_signature
             FROM photo_conversion_jobs
             WHERE id = :job_id
               AND photo_id = :photo_id
               AND image_type = :image_type
             LIMIT 1",
            [
                'job_id' => $jobId,
                'photo_id' => $photoId,
                'image_type' => $imageType,
            ]
        );

        if (!is_array($job)) {
            return [
                'success' => false,
                'errors' => [ucfirst($imageType) . ' image job was not found.'],
            ];
        }

        $status = (string)($job['status'] ?? 'queued');
        $payload = [
            'success' => true,
            'job_id' => $jobId,
            'status' => $status,
        ];

        if ($status === 'succeeded') {
            $asset = $imageType === 'rawtherapee_sample'
                ? $this->assetService->assetForPhotoIdProfileSignature($photoId, $imageType, (string)($job['profile_signature'] ?? ''))
                : $this->assetService->assetForPhotoId($photoId, $imageType);
            if ($asset !== null) {
                $payload[$imageType . '_url'] = $this->previewUrl($photoId, $asset);
            }
            if ($imageType === 'preview') {
                $payload['preview_url'] = $payload['preview_url'] ?? (isset($payload[$imageType . '_url']) ? (string)$payload[$imageType . '_url'] : '');
            }
        } elseif ($status === 'failed') {
            $payload['error'] = (string)($job['last_error'] ?? ucfirst($imageType) . ' render failed.');
        }

        return $payload;
    }

    private function previewWorkflowState(array $photo, array $baselineStatus, int $userId): array
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        $profileSignature = '';
        if (!empty($baselineStatus['ready'])) {
            $profileSignature = $this->combinedProfileService->profileSignature($photoId, 'preview');
        }
        $previewAsset = $this->assetService->assetForPhoto($photo, 'preview');
        if ($previewAsset !== null && $this->assetService->isFreshForSignature($previewAsset, $profileSignature)) {
            return [
                'ready' => true,
                'status' => 'succeeded',
                'display_type' => 'preview',
                'display_url' => $this->previewUrl($photoId, $previewAsset),
                'preview_url' => $this->previewUrl($photoId, $previewAsset),
            ];
        }

        $state = [
            'ready' => false,
            'status' => empty($baselineStatus['ready']) ? 'profile_pending' : 'preview_pending',
            'display_type' => '',
            'display_url' => '',
            'preview_url' => '',
            'status_url' => '',
        ];

        $thumbnailAsset = $this->assetService->assetForPhoto($photo, 'thumbnail');
        if ($previewAsset !== null) {
            $state['display_type'] = 'preview';
            $state['display_url'] = $this->previewUrl($photoId, $previewAsset);
        } elseif ($thumbnailAsset !== null) {
            $state['display_type'] = 'thumbnail';
            $state['display_url'] = $this->previewUrl($photoId, $thumbnailAsset);
        }

        if (empty($baselineStatus['ready'])) {
            return $state;
        }

        $job = $this->activePreviewJob($photoId, $profileSignature);
        if ($job === null) {
            $profilePath = $this->writeProfile($photo, 'preview');
            $jobId = $this->queueService->enqueuePreviewRefresh($photoId, $profilePath, $userId, $profileSignature);
            if ($jobId !== null) {
                $job = [
                    'id' => $jobId,
                    'status' => 'queued',
                ];
            }
        }

        if (is_array($job)) {
            $jobId = max(0, (int)($job['id'] ?? 0));
            if ($jobId > 0) {
                $state['status'] = (string)($job['status'] ?? 'queued');
                $state['status_url'] = $this->statusUrl($photoId, $jobId, 'preview');
            }
        }

        return $state;
    }

    private function activePreviewJob(int $photoId, string $currentProfileSignature): ?array
    {
        if ($photoId <= 0) {
            return null;
        }

        $jobs = InterfaceDB::fetchAll(
            "SELECT id, status, profile_signature
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = 'preview'
              AND status IN ('queued', 'processing')
             ORDER BY id DESC",
            ['photo_id' => $photoId]
        );

        $currentProfileSignature = $this->normaliseProfileSignature($currentProfileSignature);
        foreach ($jobs as $job) {
            if ($currentProfileSignature !== '' && $this->normaliseProfileSignature((string)($job['profile_signature'] ?? '')) === $currentProfileSignature) {
                return $job;
            }

            $this->markProfileJobObsolete((int)($job['id'] ?? 0), 'Stale preview profile signature');
        }

        return null;
    }

    private function activeFinalJob(int $photoId, string $currentProfileSignature = ''): ?array
    {
        if ($photoId <= 0) {
            return null;
        }

        $signatureColumn = $this->profileSignatureColumnAvailable();
        $jobs = InterfaceDB::fetchAll(
            "SELECT id, status, profile_signature
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = 'final'
               AND status IN ('queued', 'processing')
             ORDER BY id DESC",
            ['photo_id' => $photoId]
        );

        if ($jobs === []) {
            return null;
        }

        $currentProfileSignature = $this->normaliseProfileSignature($currentProfileSignature);
        if (!$signatureColumn || $currentProfileSignature === '') {
            return is_array($jobs[0]) ? $jobs[0] : null;
        }

        foreach ($jobs as $job) {
            if ($this->normaliseProfileSignature((string)($job['profile_signature'] ?? '')) === $currentProfileSignature) {
                return $job;
            }

            $this->markProfileJobObsolete((int)($job['id'] ?? 0), 'Stale final profile signature');
        }

        return null;
    }

    private function pictureViewerDisplayAsset(array $photo, int $userId, bool $useFinalFallback = false): ?array
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        foreach (['final', 'preview', 'embedded'] as $type) {
            $asset = $useFinalFallback && $type === 'final'
                ? $this->assetService->assetForPhotoWithFinalFallback($photo, $type)
                : $this->assetService->assetForPhoto($photo, $type);
            if ($asset !== null && $this->photoUiService->userCanViewImageType($photoId, $userId, $type)) {
                return $asset;
            }
        }

        return null;
    }

    private function markProfileJobObsolete(int $jobId, string $reason): void
    {
        if ($jobId <= 0) {
            return;
        }

        InterfaceDB::prepareExecute(
            "UPDATE photo_conversion_jobs
             SET status = 'obsolete',
                 completed_at = CURRENT_TIMESTAMP,
                 locked_at = NULL,
                 locked_by = NULL,
                 last_error = :reason
             WHERE id = :id
               AND image_type IN ('preview', 'final')
               AND status IN ('queued', 'processing')",
            [
                'id' => $jobId,
                'reason' => substr($reason, 0, 512),
            ]
        );
    }

    public function normaliseSettings(array $payload, int $sourceWidth, int $sourceHeight): array
    {
        $crop = (array)($payload['crop'] ?? []);
        $exposure = (array)($payload['exposure'] ?? []);
        $whiteBalance = (array)($payload['white_balance'] ?? []);
        $shadowsHighlights = (array)($payload['shadows_highlights'] ?? []);
        $rotation = (array)($payload['rotation'] ?? []);
        $perspective = (array)($payload['perspective'] ?? []);
        $sourceWidth = max(1, $sourceWidth);
        $sourceHeight = max(1, $sourceHeight);

        $x = $this->clampInt($crop['x'] ?? 0, 0, $sourceWidth - 1);
        $y = $this->clampInt($crop['y'] ?? 0, 0, $sourceHeight - 1);
        $width = $this->clampInt($crop['width'] ?? $sourceWidth, 1, $sourceWidth - $x);
        $height = $this->clampInt($crop['height'] ?? $sourceHeight, 1, $sourceHeight - $y);

        return [
            'crop' => [
                'enabled' => $this->boolValue($crop['enabled'] ?? true),
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
            ],
            'exposure' => [
                'enabled' => $this->boolValue($exposure['enabled'] ?? true),
                'black' => $this->clampFloat($exposure['black'] ?? 0, -100, 100),
                'lightness' => $this->clampFloat($exposure['lightness'] ?? 0, -100, 100),
                'contrast' => $this->clampFloat($exposure['contrast'] ?? 0, -100, 100),
                'saturation' => $this->clampFloat($exposure['saturation'] ?? 0, -100, 100),
            ],
            'white_balance' => [
                'enabled' => $this->boolValue($whiteBalance['enabled'] ?? true),
                'setting' => $this->optionString($whiteBalance['setting'] ?? 'Custom', ['Camera', 'Custom', 'autitcgreen'], 'Custom'),
                'temperature' => $this->clampFloat($whiteBalance['temperature'] ?? 5324, 1500, 60000),
                'green' => $this->clampFloat($whiteBalance['green'] ?? 0.846, 0.02, 5.0),
            ],
            'shadows_highlights' => [
                'enabled' => $this->boolValue($shadowsHighlights['enabled'] ?? true),
                'highlights' => $this->clampFloat($shadowsHighlights['highlights'] ?? 30, 0, 100),
                'highlight_tonal_width' => $this->clampFloat($shadowsHighlights['highlight_tonal_width'] ?? 80, 0, 100),
                'shadows' => $this->clampFloat($shadowsHighlights['shadows'] ?? 30, 0, 100),
                'shadow_tonal_width' => $this->clampFloat($shadowsHighlights['shadow_tonal_width'] ?? 80, 0, 100),
                'radius' => $this->clampFloat($shadowsHighlights['radius'] ?? 40, 1, 100),
                'lab' => $this->boolValue($shadowsHighlights['lab'] ?? true),
                'local_contrast' => $this->clampFloat($shadowsHighlights['local_contrast'] ?? 0, 0, 100),
            ],
            'rotation' => [
                'enabled' => $this->boolValue($rotation['enabled'] ?? false),
                'degree' => $this->clampFloat($rotation['degree'] ?? 0, -45, 45),
            ],
            'perspective' => [
                'enabled' => $this->boolValue($perspective['enabled'] ?? false),
                'method' => 'simple',
                'horizontal' => $this->clampFloat($perspective['horizontal'] ?? 0, -100, 100),
                'vertical' => $this->clampFloat($perspective['vertical'] ?? 0, -100, 100),
            ],
        ];
    }

    public function pp3Content(array $settings, string $baseline = ''): string
    {
        $crop = (array)$settings['crop'];
        $exposure = (array)$settings['exposure'];
        $whiteBalance = (array)($settings['white_balance'] ?? []);
        $shadowsHighlights = (array)($settings['shadows_highlights'] ?? []);
        $rotation = (array)($settings['rotation'] ?? []);
        $perspective = (array)($settings['perspective'] ?? []);
        $profile = $this->parsePp3Document($baseline);

        $this->setPp3Value($profile, 'Version', 'AppVersion', '5.9');
        $this->setPp3Value($profile, 'Version', 'Version', '349');
        $this->setPp3Value($profile, 'Exposure', 'Auto', !empty($exposure['enabled']) ? 'false' : 'false');
        $this->setPp3Value($profile, 'Exposure', 'Black', $this->formatNumber(!empty($exposure['enabled']) ? ($exposure['black'] ?? 0) : 0));
        $this->setPp3Value($profile, 'Exposure', 'Brightness', $this->formatNumber(!empty($exposure['enabled']) ? ($exposure['lightness'] ?? 0) : 0));
        $this->setPp3Value($profile, 'Exposure', 'Contrast', $this->formatNumber(!empty($exposure['enabled']) ? ($exposure['contrast'] ?? 0) : 0));
        $this->setPp3Value($profile, 'Exposure', 'Saturation', $this->formatNumber(!empty($exposure['enabled']) ? ($exposure['saturation'] ?? 0) : 0));

        $this->setPp3Value($profile, 'Crop', 'Enabled', !empty($crop['enabled']) ? 'true' : 'false');
        $this->setPp3Value($profile, 'Crop', 'X', (string)(int)($crop['x'] ?? 0));
        $this->setPp3Value($profile, 'Crop', 'Y', (string)(int)($crop['y'] ?? 0));
        $this->setPp3Value($profile, 'Crop', 'W', (string)(int)($crop['width'] ?? 1));
        $this->setPp3Value($profile, 'Crop', 'H', (string)(int)($crop['height'] ?? 1));
        $this->setPp3Value($profile, 'Crop', 'FixedRatio', 'false');
        $this->setPp3Value($profile, 'Crop', 'Ratio', 'As Image');
        $this->setPp3Value($profile, 'Crop', 'Orientation', 'As Image');
        $this->setPp3Value($profile, 'Crop', 'Guide', 'Frame');

        $this->setPp3Value($profile, 'White Balance', 'Enabled', !empty($whiteBalance['enabled']) ? 'true' : 'false');
        $this->setPp3Value($profile, 'White Balance', 'Setting', (string)($whiteBalance['setting'] ?? 'Custom'));
        $this->setPp3Value($profile, 'White Balance', 'Temperature', $this->formatNumber($whiteBalance['temperature'] ?? 5324));
        $this->setPp3Value($profile, 'White Balance', 'Green', $this->formatNumber($whiteBalance['green'] ?? 0.846));

        $this->setPp3Value($profile, 'Shadows & Highlights', 'Enabled', !empty($shadowsHighlights['enabled']) ? 'true' : 'false');
        $this->setPp3Value($profile, 'Shadows & Highlights', 'Highlights', $this->formatNumber($shadowsHighlights['highlights'] ?? 30));
        $this->setPp3Value($profile, 'Shadows & Highlights', 'HighlightTonalWidth', $this->formatNumber($shadowsHighlights['highlight_tonal_width'] ?? 80));
        $this->setPp3Value($profile, 'Shadows & Highlights', 'Shadows', $this->formatNumber($shadowsHighlights['shadows'] ?? 30));
        $this->setPp3Value($profile, 'Shadows & Highlights', 'ShadowTonalWidth', $this->formatNumber($shadowsHighlights['shadow_tonal_width'] ?? 80));
        $this->setPp3Value($profile, 'Shadows & Highlights', 'Radius', $this->formatNumber($shadowsHighlights['radius'] ?? 40));
        $this->setPp3Value($profile, 'Shadows & Highlights', 'Lab', !empty($shadowsHighlights['lab']) ? 'true' : 'false');

        $localContrast = (float)($shadowsHighlights['local_contrast'] ?? 0);
        $this->setPp3Value($profile, 'Local Contrast', 'Enabled', $localContrast > 0 ? 'true' : 'false');
        $this->setPp3Value($profile, 'Local Contrast', 'Amount', $this->formatNumber($localContrast / 30.0));
        $this->setPp3Value($profile, 'Local Contrast', 'Radius', '80');
        $this->setPp3Value($profile, 'Local Contrast', 'Darkness', '1');
        $this->setPp3Value($profile, 'Local Contrast', 'Lightness', '1');

        $this->setPp3Value($profile, 'Rotation', 'Degree', $this->formatNumber(!empty($rotation['enabled']) ? ($rotation['degree'] ?? 0) : 0));
        $this->setPp3Value($profile, 'Perspective', 'Method', 'simple');
        $this->setPp3Value($profile, 'Perspective', 'Horizontal', $this->formatNumber(!empty($perspective['enabled']) ? ($perspective['horizontal'] ?? 0) : 0));
        $this->setPp3Value($profile, 'Perspective', 'Vertical', $this->formatNumber(!empty($perspective['enabled']) ? ($perspective['vertical'] ?? 0) : 0));

        return $this->renderPp3Document($profile);
    }

    public function profileRowsForSettings(array $settings): array
    {
        $crop = (array)$settings['crop'];
        $exposure = (array)$settings['exposure'];
        $whiteBalance = (array)($settings['white_balance'] ?? []);
        $shadowsHighlights = (array)($settings['shadows_highlights'] ?? []);
        $rotation = (array)($settings['rotation'] ?? []);
        $perspective = (array)($settings['perspective'] ?? []);
        $localContrast = (float)($shadowsHighlights['local_contrast'] ?? 0);

        return [
            $this->profileRow('Exposure', 'Auto', 'false', 'bool'),
            $this->profileRow('Exposure', 'Black', $this->formatNumber(!empty($exposure['enabled']) ? ($exposure['black'] ?? 0) : 0), 'float'),
            $this->profileRow('Exposure', 'Brightness', $this->formatNumber(!empty($exposure['enabled']) ? ($exposure['lightness'] ?? 0) : 0), 'float'),
            $this->profileRow('Exposure', 'Contrast', $this->formatNumber(!empty($exposure['enabled']) ? ($exposure['contrast'] ?? 0) : 0), 'float'),
            $this->profileRow('Exposure', 'Saturation', $this->formatNumber(!empty($exposure['enabled']) ? ($exposure['saturation'] ?? 0) : 0), 'float'),
            $this->profileRow('Crop', 'Enabled', !empty($crop['enabled']) ? 'true' : 'false', 'bool'),
            $this->profileRow('Crop', 'X', (string)(int)($crop['x'] ?? 0), 'int'),
            $this->profileRow('Crop', 'Y', (string)(int)($crop['y'] ?? 0), 'int'),
            $this->profileRow('Crop', 'W', (string)(int)($crop['width'] ?? 1), 'int'),
            $this->profileRow('Crop', 'H', (string)(int)($crop['height'] ?? 1), 'int'),
            $this->profileRow('Crop', 'FixedRatio', 'false', 'bool'),
            $this->profileRow('Crop', 'Ratio', 'As Image', 'string'),
            $this->profileRow('Crop', 'Orientation', 'As Image', 'string'),
            $this->profileRow('Crop', 'Guide', 'Frame', 'string'),
            $this->profileRow('White Balance', 'Enabled', !empty($whiteBalance['enabled']) ? 'true' : 'false', 'bool'),
            $this->profileRow('White Balance', 'Setting', (string)($whiteBalance['setting'] ?? 'Custom'), 'string'),
            $this->profileRow('White Balance', 'Temperature', $this->formatNumber($whiteBalance['temperature'] ?? 5324), 'float'),
            $this->profileRow('White Balance', 'Green', $this->formatNumber($whiteBalance['green'] ?? 0.846), 'float'),
            $this->profileRow('Shadows & Highlights', 'Enabled', !empty($shadowsHighlights['enabled']) ? 'true' : 'false', 'bool'),
            $this->profileRow('Shadows & Highlights', 'Highlights', $this->formatNumber($shadowsHighlights['highlights'] ?? 30), 'float'),
            $this->profileRow('Shadows & Highlights', 'HighlightTonalWidth', $this->formatNumber($shadowsHighlights['highlight_tonal_width'] ?? 80), 'float'),
            $this->profileRow('Shadows & Highlights', 'Shadows', $this->formatNumber($shadowsHighlights['shadows'] ?? 30), 'float'),
            $this->profileRow('Shadows & Highlights', 'ShadowTonalWidth', $this->formatNumber($shadowsHighlights['shadow_tonal_width'] ?? 80), 'float'),
            $this->profileRow('Shadows & Highlights', 'Radius', $this->formatNumber($shadowsHighlights['radius'] ?? 40), 'float'),
            $this->profileRow('Shadows & Highlights', 'Lab', !empty($shadowsHighlights['lab']) ? 'true' : 'false', 'bool'),
            $this->profileRow('Local Contrast', 'Enabled', $localContrast > 0 ? 'true' : 'false', 'bool'),
            $this->profileRow('Local Contrast', 'Amount', $this->formatNumber($localContrast / 30.0), 'float'),
            $this->profileRow('Local Contrast', 'Radius', '80', 'int'),
            $this->profileRow('Local Contrast', 'Darkness', '1', 'int'),
            $this->profileRow('Local Contrast', 'Lightness', '1', 'int'),
            $this->profileRow('Rotation', 'Degree', $this->formatNumber(!empty($rotation['enabled']) ? ($rotation['degree'] ?? 0) : 0), 'float'),
            $this->profileRow('Perspective', 'Method', 'simple', 'string'),
            $this->profileRow('Perspective', 'Horizontal', $this->formatNumber(!empty($perspective['enabled']) ? ($perspective['horizontal'] ?? 0) : 0), 'float'),
            $this->profileRow('Perspective', 'Vertical', $this->formatNumber(!empty($perspective['enabled']) ? ($perspective['vertical'] ?? 0) : 0), 'float'),
        ];
    }

    private function writeProfile(array $photo, string $imageType): string
    {
        $profileType = match ($imageType) {
            'preview' => 'preview_profile',
            'final' => 'final_profile',
            'thumbnail' => 'thumbnail_profile',
            default => throw new InvalidArgumentException('Unsupported profile image type.'),
        };
        $path = $this->storageService->imagePath(
            (string)($photo['storage_base_location'] ?? ''),
            (string)($photo['original_sha256'] ?? ''),
            $profileType
        );
        $this->storageService->ensureDirectoryForPath($path);

        $profile = $this->combinedProfileService->combinedProfileContent((int)($photo['id'] ?? 0), $imageType);
        if (file_put_contents($path, $profile, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write PP3 profile.');
        }

        return $path;
    }

    private function sourceDimensions(array $photo): array
    {
        foreach (['original', 'final', 'preview', 'thumbnail', 'embedded'] as $type) {
            $path = $this->assetService->absolutePathForPhoto($photo, $type);
            if ($path === null || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $size = @getimagesize($path);
            if (is_array($size) && (int)$size[0] > 0 && (int)$size[1] > 0) {
                return [
                    'width' => (int)$size[0],
                    'height' => (int)$size[1],
                ];
            }
        }

        return [
            'width' => self::DEFAULT_SOURCE_WIDTH,
            'height' => self::DEFAULT_SOURCE_HEIGHT,
        ];
    }

    private function defaultSettings(int $width, int $height): array
    {
        return [
            'crop' => [
                'enabled' => true,
                'x' => 0,
                'y' => 0,
                'width' => max(1, $width),
                'height' => max(1, $height),
            ],
            'exposure' => [
                'enabled' => true,
                'black' => 63.0,
                'lightness' => 0.0,
                'contrast' => 26.0,
                'saturation' => 0.0,
            ],
            'white_balance' => [
                'enabled' => true,
                'setting' => 'Custom',
                'temperature' => 5324.0,
                'green' => 0.846,
            ],
            'shadows_highlights' => [
                'enabled' => true,
                'highlights' => 30.0,
                'highlight_tonal_width' => 80.0,
                'shadows' => 30.0,
                'shadow_tonal_width' => 80.0,
                'radius' => 40.0,
                'lab' => true,
                'local_contrast' => 0.0,
            ],
            'rotation' => [
                'enabled' => false,
                'degree' => 0.0,
            ],
            'perspective' => [
                'enabled' => false,
                'method' => 'simple',
                'horizontal' => 0.0,
                'vertical' => 0.0,
            ],
        ];
    }

    private function profileSignatureColumnAvailable(): bool
    {
        return InterfaceDB::columnExists('photo_conversion_jobs', 'profile_signature');
    }

    private function normaliseProfileSignature(string $signature): string
    {
        $signature = strtolower(trim($signature));

        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1 ? $signature : '';
    }

    private function previewUrl(int $photoId, array $asset): string
    {
        $imageType = (string)($asset['image_type'] ?? 'preview');
        $profileSignature = $this->normaliseProfileSignature((string)($asset['profile_signature'] ?? ''));
        $query = [
            'photo_id' => $photoId,
            'type' => in_array($imageType, ['preview', 'thumbnail', 'embedded', 'final', 'original', 'rawtherapee_sample'], true) ? $imageType : 'preview',
            'v' => $imageType === 'rawtherapee_sample' ? $profileSignature : (string)($asset['sha256'] ?? ''),
        ];
        if ($imageType === 'rawtherapee_sample') {
            $query['profile_signature'] = $profileSignature;
        }

        return '/api/photo-imaging.php?' . http_build_query($query);
    }

    private function statusUrl(int $photoId, int $jobId, string $imageType): string
    {
        return '/api/photo-status.php?' . http_build_query([
            'photo_id' => $photoId,
            'job_id' => $jobId,
            'image_type' => $imageType,
        ]);
    }

    private function pictureViewerStateUrl(int $photoId): string
    {
        return '/api/photo-info.php?' . http_build_query([
            'view' => 'viewer',
            'photo_id' => $photoId,
        ]);
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $max = max($min, $max);

        return max($min, min($max, (int)round((float)$value)));
    }

    private function clampFloat(mixed $value, float $min, float $max): float
    {
        return max($min, min($max, (float)$value));
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    private function optionString(mixed $value, array $allowed, string $fallback): string
    {
        $value = trim((string)$value);

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function profileRow(string $type, string $key, mixed $value, string $valueType): array
    {
        return [
            'type' => $type,
            'key' => $key,
            'value' => $value,
            'value_type' => $valueType,
        ];
    }

    private function parsePp3Document(string $contents): array
    {
        $profile = [
            'order' => [],
            'sections' => [],
        ];
        $section = '';
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]$/', $line, $match) === 1) {
                $section = (string)$match[1];
                $this->ensurePp3Section($profile, $section);
                continue;
            }
            if ($section === '' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $this->ensurePp3Section($profile, $section);
            if (!array_key_exists($key, $profile['sections'][$section]['values'])) {
                $profile['sections'][$section]['order'][] = $key;
            }
            $profile['sections'][$section]['values'][$key] = trim($value);
        }

        return $profile;
    }

    private function ensurePp3Section(array &$profile, string $section): void
    {
        if (isset($profile['sections'][$section])) {
            return;
        }

        $profile['order'][] = $section;
        $profile['sections'][$section] = [
            'order' => [],
            'values' => [],
        ];
    }

    private function setPp3Value(array &$profile, string $section, string $key, string $value): void
    {
        $this->ensurePp3Section($profile, $section);
        if (!array_key_exists($key, $profile['sections'][$section]['values'])) {
            $profile['sections'][$section]['order'][] = $key;
        }
        $profile['sections'][$section]['values'][$key] = $value;
    }

    private function renderPp3Document(array $profile): string
    {
        $lines = [];
        foreach ((array)$profile['order'] as $section) {
            $section = (string)$section;
            $data = (array)($profile['sections'][$section] ?? []);
            $lines[] = '[' . $section . ']';
            foreach ((array)($data['order'] ?? []) as $key) {
                $key = (string)$key;
                $lines[] = $key . '=' . (string)($data['values'][$key] ?? '');
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function formatNumber(mixed $value): string
    {
        $value = (float)$value;
        if (abs($value - round($value)) < 0.000001) {
            return (string)(int)round($value);
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
