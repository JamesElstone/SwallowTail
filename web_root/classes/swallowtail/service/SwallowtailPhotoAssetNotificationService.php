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
use Throwable;

final class SwallowtailPhotoAssetNotificationService
{
    private const DEFAULT_ASSET_QUEUE = 'swallowtail:metadata:asset_urgent';
    private const IMAGE_TYPES = ['embedded', 'thumbnail', 'original', 'preview', 'final', 'rawtheapee_sample'];

    public function __construct(
        private readonly object $redis = new SwallowtailRedisService(),
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
    ) {
    }

    public function notifyPhotoAsset(array $photo, string $imageType, string $reason): bool
    {
        if (!method_exists($this->redis, 'listPushJson')) {
            return false;
        }

        $photoId = max(0, (int)($photo['id'] ?? 0));
        $imageType = strtolower(trim($imageType));
        if ($photoId <= 0 || !in_array($imageType, self::IMAGE_TYPES, true)) {
            return false;
        }

        $path = $this->assetPath($photo, $imageType);
        if ($path === null || !is_file($path) || !is_readable($path) || (int)filesize($path) <= 0) {
            return false;
        }

        $queue = trim((string)\Swallowtail\Store\SwallowtailConfigurationStore::get(
            'redis.metadata_asset_queue',
            self::DEFAULT_ASSET_QUEUE
        ));
        if ($queue === '') {
            return false;
        }

        return (bool)$this->redis->listPushJson($queue, [
            'job_id' => 0,
            'photo_id' => $photoId,
            'image_type' => $imageType,
            'output_path' => $path,
            'profile_signature' => '',
            'reason' => substr($reason, 0, 64),
            'queued_at' => time(),
        ], 1024);
    }

    private function assetPath(array $photo, string $imageType): ?string
    {
        $base = trim((string)($photo['storage_base_location'] ?? ''));
        $checksum = strtolower(trim((string)($photo['original_sha256'] ?? '')));
        if ($base === '' || $checksum === '') {
            return null;
        }

        try {
            return $this->storageService->imagePath($base, $checksum, $imageType);
        } catch (Throwable) {
            return null;
        }
    }
}
