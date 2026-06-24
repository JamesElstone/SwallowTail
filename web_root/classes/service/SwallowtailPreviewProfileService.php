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

        $dimensions = $this->sourceDimensions($photo);
        $settings = $this->latestProfileSettings($photo);
        if ($settings === []) {
            $settings = $this->defaultSettings($dimensions['width'], $dimensions['height']);
        }

        $latestProfileVersion = $this->latestProfileVersion((int)$photo['id']);
        $previewType = $this->previewImageType($photo);

        return [
            'photo' => $photo,
            'source_width' => $dimensions['width'],
            'source_height' => $dimensions['height'],
            'settings' => $settings,
            'preview_ready' => $previewType !== null,
            'preview_url' => $previewType !== null
                ? $this->previewUrl($photoId, $latestProfileVersion, null, $previewType)
                : '',
        ];
    }

    public function enqueuePreview(int $photoId, int $userId, array $payload): array
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

        $dimensions = $this->sourceDimensions($photo);
        $settings = $this->normaliseSettings($payload, $dimensions['width'], $dimensions['height']);
        $profileVersion = $this->nextProfileVersion($photoId);
        $profilePath = $this->writeProfile($photo, $settings);

        $jobId = $this->queueService->enqueueFilteredRefresh(
            $photoId,
            $profilePath,
            $profileVersion,
            $userId
        );

        if ($jobId === null) {
            return [
                'success' => false,
                'errors' => ['Filtered image job could not be queued.'],
            ];
        }

        return [
            'success' => true,
            'job_id' => $jobId,
            'profile_version' => $profileVersion,
            'settings' => $settings,
            'preview_url' => $this->previewUrl($photoId, $profileVersion, $jobId),
            'status_url' => '/api/photo-preview-status.php?' . http_build_query([
                'photo_id' => $photoId,
                'job_id' => $jobId,
                'profile_version' => $profileVersion,
            ]),
        ];
    }

    public function previewStatus(int $photoId, int $jobId, int $profileVersion, int $userId): array
    {
        if ($photoId <= 0 || $jobId <= 0 || $profileVersion <= 0 || $userId <= 0 || !$this->photoUiService->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['Filtered image job was not found.'],
            ];
        }

        $job = InterfaceDB::fetchOne(
            "SELECT id, status, last_error
             FROM photo_conversion_jobs
             WHERE id = :job_id
               AND photo_id = :photo_id
               AND image_type = 'filtered'
               AND profile_version = :profile_version
             LIMIT 1",
            [
                'job_id' => $jobId,
                'photo_id' => $photoId,
                'profile_version' => $profileVersion,
            ]
        );

        if (!is_array($job)) {
            return [
                'success' => false,
                'errors' => ['Filtered image job was not found.'],
            ];
        }

        $status = (string)($job['status'] ?? 'queued');
        $photo = $this->photoLibraryService->photoById($photoId);
        $payload = [
            'success' => true,
            'job_id' => $jobId,
            'profile_version' => $profileVersion,
            'status' => $status,
        ];

        if (is_array($photo)) {
            $payload = array_merge($payload, $this->interimPreviewUrls($photoId, $profileVersion, $jobId, $photo));
        }

        if ($status === 'succeeded') {
            $payload['preview_url'] = $this->previewUrl($photoId, $profileVersion, $jobId);
        } elseif ($status === 'failed') {
            $payload['error'] = (string)($job['last_error'] ?? 'Filtered render failed.');
        }

        return $payload;
    }

    public function normaliseSettings(array $payload, int $sourceWidth, int $sourceHeight): array
    {
        $crop = (array)($payload['crop'] ?? []);
        $exposure = (array)($payload['exposure'] ?? []);
        $sourceWidth = max(1, $sourceWidth);
        $sourceHeight = max(1, $sourceHeight);

        $x = $this->clampInt($crop['x'] ?? 0, 0, $sourceWidth - 1);
        $y = $this->clampInt($crop['y'] ?? 0, 0, $sourceHeight - 1);
        $width = $this->clampInt($crop['width'] ?? $sourceWidth, 1, $sourceWidth - $x);
        $height = $this->clampInt($crop['height'] ?? $sourceHeight, 1, $sourceHeight - $y);

        return [
            'crop' => [
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
            ],
            'exposure' => [
                'black' => $this->clampFloat($exposure['black'] ?? 0, -100, 100),
                'lightness' => $this->clampFloat($exposure['lightness'] ?? 0, -100, 100),
                'contrast' => $this->clampFloat($exposure['contrast'] ?? 0, -100, 100),
                'saturation' => $this->clampFloat($exposure['saturation'] ?? 0, -100, 100),
            ],
        ];
    }

    public function pp3Content(array $settings): string
    {
        $crop = (array)$settings['crop'];
        $exposure = (array)$settings['exposure'];

        return implode("\n", [
            '[Version]',
            'AppVersion=5.9',
            'Version=349',
            '',
            '[Exposure]',
            'Auto=false',
            'Black=' . $this->formatNumber($exposure['black'] ?? 0),
            'Brightness=' . $this->formatNumber($exposure['lightness'] ?? 0),
            'Contrast=' . $this->formatNumber($exposure['contrast'] ?? 0),
            'Saturation=' . $this->formatNumber($exposure['saturation'] ?? 0),
            '',
            '[Crop]',
            'Enabled=true',
            'X=' . (string)(int)($crop['x'] ?? 0),
            'Y=' . (string)(int)($crop['y'] ?? 0),
            'W=' . (string)(int)($crop['width'] ?? 1),
            'H=' . (string)(int)($crop['height'] ?? 1),
            'FixedRatio=false',
            'Ratio=As Image',
            'Orientation=As Image',
            'Guide=Frame',
            '',
        ]);
    }

    private function writeProfile(array $photo, array $settings): string
    {
        $path = $this->storageService->imagePath(
            (string)($photo['storage_base_location'] ?? ''),
            (string)($photo['original_sha256'] ?? ''),
            'profile'
        );
        $this->storageService->ensureDirectoryForPath($path);

        if (file_put_contents($path, $this->pp3Content($settings), LOCK_EX) === false) {
            throw new RuntimeException('Unable to write PP3 profile.');
        }

        @chmod($path, 0660);

        return $path;
    }

    private function sourceDimensions(array $photo): array
    {
        foreach (['filtered', 'original', 'thumbnail', 'embedded'] as $type) {
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

    private function latestProfileSettings(array $photo): array
    {
        $info = $this->storageService->imageInfo($photo, 'profile');
        if ($info === null) {
            return [];
        }

        $contents = file_get_contents((string)$info['absolute_path']);
        if (!is_string($contents)) {
            return [];
        }

        return $this->parseProfileSettings($contents);
    }

    private function parseProfileSettings(string $contents): array
    {
        $section = '';
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[([^\]]+)\]$/', $line, $match) === 1) {
                $section = (string)$match[1];
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$section . '.' . trim($key)] = trim($value);
        }

        if (!isset($values['Crop.W'], $values['Crop.H'])) {
            return [];
        }

        return [
            'crop' => [
                'x' => max(0, (int)($values['Crop.X'] ?? 0)),
                'y' => max(0, (int)($values['Crop.Y'] ?? 0)),
                'width' => max(1, (int)$values['Crop.W']),
                'height' => max(1, (int)$values['Crop.H']),
            ],
            'exposure' => [
                'black' => (float)($values['Exposure.Black'] ?? 0),
                'lightness' => (float)($values['Exposure.Brightness'] ?? 0),
                'contrast' => (float)($values['Exposure.Contrast'] ?? 0),
                'saturation' => (float)($values['Exposure.Saturation'] ?? 0),
            ],
        ];
    }

    private function defaultSettings(int $width, int $height): array
    {
        return [
            'crop' => [
                'x' => 0,
                'y' => 0,
                'width' => max(1, $width),
                'height' => max(1, $height),
            ],
            'exposure' => [
                'black' => 0.0,
                'lightness' => 0.0,
                'contrast' => 0.0,
                'saturation' => 0.0,
            ],
        ];
    }

    private function nextProfileVersion(int $photoId): int
    {
        return $this->latestProfileVersion($photoId) + 1;
    }

    private function latestProfileVersion(int $photoId): int
    {
        if ($photoId <= 0) {
            return 0;
        }

        return (int)InterfaceDB::fetchColumn(
            "SELECT COALESCE(MAX(profile_version), 0)
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND image_type = 'filtered'",
            ['photo_id' => $photoId]
        );
    }

    private function previewImageType(array $photo): ?string
    {
        foreach (['filtered', 'original'] as $type) {
            if ($this->storageService->imageInfo($photo, $type) !== null) {
                return $type;
            }
        }

        return null;
    }

    private function interimPreviewUrls(int $photoId, int $profileVersion, int $jobId, array $photo): array
    {
        $urls = [];

        foreach (['thumbnail', 'original'] as $type) {
            if ($this->storageService->imageInfo($photo, $type) !== null) {
                $urls[$type . '_url'] = $this->previewUrl($photoId, $profileVersion, $jobId, $type);
            }
        }

        return $urls;
    }

    private function previewUrl(int $photoId, int $profileVersion, ?int $jobId, string $imageType = 'filtered'): string
    {
        return '/api/photo-image.php?' . http_build_query([
            'photo_id' => $photoId,
            'type' => in_array($imageType, ['filtered', 'original', 'thumbnail'], true) ? $imageType : 'filtered',
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

    private function formatNumber(mixed $value): string
    {
        $value = (float)$value;
        if (abs($value - round($value)) < 0.000001) {
            return (string)(int)round($value);
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
