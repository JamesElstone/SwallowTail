<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailPreviewProfileService
{
    private const DEFAULT_SOURCE_WIDTH = 6000;
    private const DEFAULT_SOURCE_HEIGHT = 4000;

    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly SwallowtailPhotoUiService $photoUiService = new SwallowtailPhotoUiService(),
        private readonly SwallowtailConversionQueueService $queueService = new SwallowtailConversionQueueService(),
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailProfileDataService $profileDataService = new SwallowtailProfileDataService(),
        private readonly SwallowtailCombinedProfileService $combinedProfileService = new SwallowtailCombinedProfileService(),
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

        $latestProfileVersion = $this->latestProfileVersion((int)$photo['id'], 'preview');
        $previewType = $this->previewImageType($photo);

        return [
            'photo' => $photo,
            'source_width' => $dimensions['width'],
            'source_height' => $dimensions['height'],
            'settings' => $settings,
            'baseline' => $baselineStatus,
            'preview_ready' => $previewType !== null,
            'preview_type' => $previewType,
            'preview_url' => $previewType !== null
                ? $this->previewUrl($photoId, $latestProfileVersion, null, $previewType)
                : '',
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

    private function enqueueProfiledRender(int $photoId, int $userId, array $payload, string $imageType): array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
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
        $profileVersion = $this->nextProfileVersion($photoId, $imageType);
        $profilePath = $this->writeProfile($photo, $imageType);

        $jobId = $imageType === 'final'
            ? $this->queueService->enqueueFinalRefresh($photoId, $profilePath, $profileVersion, $userId)
            : $this->queueService->enqueuePreviewRefresh(
                $photoId,
                $profilePath,
                $profileVersion,
                $userId
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
            'profile_version' => $profileVersion,
            'settings' => $settings,
            $imageType . '_url' => $this->previewUrl($photoId, $profileVersion, $jobId, $imageType),
            'status_url' => '/api/photo-' . $imageType . '-status.php?' . http_build_query([
                'photo_id' => $photoId,
                'job_id' => $jobId,
                'profile_version' => $profileVersion,
            ]),
        ];
        if ($imageType === 'preview') {
            $result['preview_url'] = $result['preview_url'] ?? $this->previewUrl($photoId, $profileVersion, $jobId, 'preview');
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

        return [
            'success' => true,
            'baseline' => $status,
            'settings' => $settings,
        ];
    }

    public function previewStatus(int $photoId, int $jobId, int $profileVersion, int $userId): array
    {
        return $this->renderStatus($photoId, $jobId, $profileVersion, $userId, 'preview');
    }

    public function finalStatus(int $photoId, int $jobId, int $profileVersion, int $userId): array
    {
        return $this->renderStatus($photoId, $jobId, $profileVersion, $userId, 'final');
    }

    private function renderStatus(int $photoId, int $jobId, int $profileVersion, int $userId, string $imageType): array
    {
        if ($photoId <= 0 || $jobId <= 0 || $profileVersion <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => [ucfirst($imageType) . ' image job was not found.'],
            ];
        }

        $job = InterfaceDB::fetchOne(
            "SELECT id, status, last_error
             FROM photo_conversion_jobs
             WHERE id = :job_id
               AND photo_id = :photo_id
               AND image_type = :image_type
               AND profile_version = :profile_version
             LIMIT 1",
            [
                'job_id' => $jobId,
                'photo_id' => $photoId,
                'image_type' => $imageType,
                'profile_version' => $profileVersion,
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
            'profile_version' => $profileVersion,
            'status' => $status,
        ];

        if ($status === 'succeeded') {
            $payload[$imageType . '_url'] = $this->previewUrl($photoId, $profileVersion, $jobId, $imageType);
            if ($imageType === 'preview') {
                $payload['preview_url'] = $payload['preview_url'] ?? $this->previewUrl($photoId, $profileVersion, $jobId, 'preview');
            }
        } elseif ($status === 'failed') {
            $payload['error'] = (string)($job['last_error'] ?? ucfirst($imageType) . ' render failed.');
        }

        return $payload;
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

        @chmod($path, 0660);

        return $path;
    }

    private function sourceDimensions(array $photo): array
    {
        foreach (['original', 'final', 'preview', 'embedded'] as $type) {
            $info = $this->storageService->imageInfo($photo, $type);
            if ($info === null) {
                continue;
            }

            $size = @getimagesize((string)$info['absolute_path']);
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

    private function nextProfileVersion(int $photoId, string $imageType): int
    {
        return $this->latestProfileVersion($photoId, $imageType) + 1;
    }

    private function latestProfileVersion(int $photoId, string $imageType): int
    {
        if ($photoId <= 0) {
            return 0;
        }

        return (int)InterfaceDB::fetchColumn(
            "SELECT COALESCE(MAX(profile_version), 0)
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = :image_type",
            [
                'photo_id' => $photoId,
                'image_type' => $imageType,
            ]
        );
    }

    private function previewImageType(array $photo): ?string
    {
        foreach (['preview', 'embedded'] as $type) {
            if ($this->storageService->imageInfo($photo, $type) !== null) {
                return $type;
            }
        }

        return null;
    }

    private function previewUrl(int $photoId, int $profileVersion, ?int $jobId, string $imageType = 'preview'): string
    {
        return '/api/photo-image.php?' . http_build_query([
            'photo_id' => $photoId,
            'type' => in_array($imageType, ['preview', 'embedded', 'final', 'original'], true) ? $imageType : 'preview',
            'v' => max(0, $profileVersion),
            'job_id' => $jobId,
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
