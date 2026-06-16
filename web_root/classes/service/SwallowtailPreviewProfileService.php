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

        $dimensions = $this->sourceDimensions($photo);
        $settings = $this->latestProfileSettings($photo);

        if ($settings === []) {
            $settings = $this->defaultSettings($dimensions['width'], $dimensions['height']);
        }

        $latestProfileVersion = max($this->latestProfileVersion($photoId), $this->latestProfileFileVersion($photo));

        return [
            'photo' => $photo,
            'source_width' => $dimensions['width'],
            'source_height' => $dimensions['height'],
            'settings' => $settings,
            'preview_ready' => InterfaceDB::countWhere('swallowtail_photo_derivatives', [
                'photo_id' => $photoId,
                'derivative_type' => 'preview',
            ]) > 0,
            'preview_url' => $this->previewUrl($photoId, $latestProfileVersion, null),
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
        $profileVersion = $this->nextProfileVersion($photo);
        $profilePath = $this->writeProfile($photo, $profileVersion, $settings);
        $previewMax = $this->previewMaxPixels();

        $jobId = $this->queueService->enqueuePreviewRefresh(
            $photoId,
            $profilePath,
            $profileVersion,
            $userId,
            $previewMax,
            $previewMax
        );

        if ($jobId === null) {
            return [
                'success' => false,
                'errors' => ['Preview job could not be queued.'],
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
                'errors' => ['Preview job was not found.'],
            ];
        }

        $job = InterfaceDB::fetchOne(
            "SELECT id, status, last_error
             FROM swallowtail_photo_conversion_jobs
             WHERE id = :job_id
               AND photo_id = :photo_id
               AND derivative_type = 'preview'
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
                'errors' => ['Preview job was not found.'],
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
            $payload['preview_url'] = $this->previewUrl($photoId, $profileVersion, $jobId);
        } elseif ($status === 'failed') {
            $payload['error'] = (string)($job['last_error'] ?? 'Preview render failed.');
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

    public function pp3Content(array $settings, int $previewMaxPixels): string
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
            '[Resize]',
            'Enabled=true',
            'Scale=1',
            'AppliesTo=Cropped area',
            'Method=Lanczos',
            'DataSpecified=3',
            'Width=' . (string)$previewMaxPixels,
            'Height=' . (string)$previewMaxPixels,
            'AllowUpscaling=false',
            '',
        ]);
    }

    private function writeProfile(array $photo, int $profileVersion, array $settings): string
    {
        $storage = new SwallowtailStorageService($this->storageRootForPhoto($photo));
        $relativePath = $this->profileRelativePath((string)$photo['original_sha256'], $profileVersion);
        $storage->ensureDirectoryForRelativePath($relativePath);
        $path = $storage->absolutePath($relativePath);

        if (file_put_contents($path, $this->pp3Content($settings, $this->previewMaxPixels()), LOCK_EX) === false) {
            throw new RuntimeException('Unable to write preview PP3 profile.');
        }

        @chmod($path, 0660);

        return $path;
    }

    private function sourceDimensions(array $photo): array
    {
        foreach (['original_jpeg', 'preview', 'thumbnail'] as $type) {
            $path = $this->absoluteDerivativePath((int)$photo['id'], $type, $photo);
            if ($path === null) {
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

    private function absoluteDerivativePath(int $photoId, string $type, array $photo): ?string
    {
        if (!InterfaceDB::tableExists('swallowtail_photo_derivatives')) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT storage_path, storage_location_id
             FROM swallowtail_photo_derivatives
             WHERE photo_id = :photo_id
               AND derivative_type = :derivative_type
             LIMIT 1",
            [
                'photo_id' => $photoId,
                'derivative_type' => $type,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        try {
            $locationId = $this->nullablePositiveInt($row['storage_location_id'] ?? $photo['storage_location_id'] ?? null);
            $storage = new SwallowtailStorageService($this->storageRootForLocation($locationId));
            $path = $storage->absolutePath((string)$row['storage_path']);
        } catch (Throwable) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    private function latestProfileSettings(array $photo): array
    {
        $version = max($this->latestProfileVersion((int)$photo['id']), $this->latestProfileFileVersion($photo));
        if ($version <= 0) {
            return [];
        }

        try {
            $storage = new SwallowtailStorageService($this->storageRootForPhoto($photo));
            $path = $storage->absolutePath($this->profileRelativePath((string)$photo['original_sha256'], $version));
        } catch (Throwable) {
            return [];
        }

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
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

    private function nextProfileVersion(array $photo): int
    {
        return max($this->latestProfileVersion((int)$photo['id']), $this->latestProfileFileVersion($photo)) + 1;
    }

    private function latestProfileVersion(int $photoId): int
    {
        if ($photoId <= 0 || !InterfaceDB::tableExists('swallowtail_photo_conversion_jobs')) {
            return 0;
        }

        return (int)InterfaceDB::fetchColumn(
            "SELECT COALESCE(MAX(profile_version), 0)
             FROM swallowtail_photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND derivative_type = 'preview'",
            ['photo_id' => $photoId]
        );
    }

    private function latestProfileFileVersion(array $photo): int
    {
        $sha256 = (string)($photo['original_sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return 0;
        }

        try {
            $storage = new SwallowtailStorageService($this->storageRootForPhoto($photo));
            $directory = dirname($storage->absolutePath($this->profileRelativePath($sha256, 1)));
        } catch (Throwable) {
            return 0;
        }

        if (!is_dir($directory)) {
            return 0;
        }

        $max = 0;
        foreach (glob($directory . DIRECTORY_SEPARATOR . 'preview-v*.pp3') ?: [] as $file) {
            if (preg_match('/preview-v(\d+)\.pp3$/', (string)$file, $match) === 1) {
                $max = max($max, (int)$match[1]);
            }
        }

        return $max;
    }

    private function profileRelativePath(string $sha256, int $profileVersion): string
    {
        $sha256 = strtolower(trim($sha256));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new InvalidArgumentException('Photo checksum is not valid.');
        }

        return implode(DIRECTORY_SEPARATOR, [
            'profiles',
            substr($sha256, 0, 2),
            substr($sha256, 2, 2),
            $sha256,
            'preview-v' . max(1, $profileVersion) . '.pp3',
        ]);
    }

    private function previewUrl(int $photoId, int $profileVersion, ?int $jobId): string
    {
        return '/api/photo-image.php?' . http_build_query([
            'photo_id' => $photoId,
            'type' => 'preview',
            'v' => max(0, $profileVersion),
            'job_id' => $jobId,
        ]);
    }

    private function storageRootForPhoto(array $photo): string
    {
        return $this->storageRootForLocation($this->nullablePositiveInt($photo['storage_location_id'] ?? null));
    }

    private function storageRootForLocation(?int $storageLocationId): string
    {
        if ($storageLocationId === null || !InterfaceDB::tableExists('swallowtail_storage_locations')) {
            return '';
        }

        $root = InterfaceDB::fetchColumn(
            'SELECT root_path FROM swallowtail_storage_locations WHERE id = :id LIMIT 1',
            ['id' => $storageLocationId]
        );

        return is_scalar($root) ? (string)$root : '';
    }

    private function previewMaxPixels(): int
    {
        $size = (int)AppConfigurationStore::get('swallowtail.raw_conversion.preview_max_pixels', 1600);

        return max(256, min(4096, $size));
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

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }
}
