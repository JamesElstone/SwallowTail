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
use Throwable;

final class SwallowtailPhotoAssetService
{
    private const IMAGE_TYPES = ['embedded', 'thumbnail', 'original', 'preview', 'final', 'rawtheapee_sample'];

    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
    ) {
    }

    public function assetForPhoto(array $photo, string $imageType, bool $requireReadableFile = true): ?array
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        $imageType = $this->normaliseImageType($imageType);
        if ($photoId <= 0 || $imageType === '' || !InterfaceDB::tableExists('photo_image_assets')) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT *
             FROM photo_image_assets
             WHERE photo_id = :photo_id
               AND image_type = :image_type
             LIMIT 1",
            [
                'photo_id' => $photoId,
                'image_type' => $imageType,
            ]
        );
        if (!is_array($row)) {
            return null;
        }

        $path = $this->absolutePathForPhoto($photo, $imageType);
        if ($path === null) {
            return null;
        }

        if ($requireReadableFile && (!is_file($path) || !is_readable($path))) {
            return null;
        }

        $row['absolute_path'] = $path;
        $row['image_type'] = $imageType;
        $row['bytes'] = max(0, (int)($row['bytes'] ?? 0));
        $row['modified_at'] = max(0, (int)($row['modified_at'] ?? 0));
        $row['sha256'] = $this->normaliseSha256((string)($row['sha256'] ?? ''));
        $row['profile_signature'] = $this->normaliseSha256((string)($row['profile_signature'] ?? ''));

        if ((int)$row['bytes'] <= 0 || (string)$row['sha256'] === '') {
            return null;
        }

        return $row;
    }

    public function assetForPhotoId(int $photoId, string $imageType, bool $requireReadableFile = true): ?array
    {
        if ($photoId <= 0) {
            return null;
        }

        $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
        if ($photo === null) {
            return null;
        }

        return $this->assetForPhoto($photo, $imageType, $requireReadableFile);
    }

    public function isFreshForSignature(array $asset, string $profileSignature): bool
    {
        $profileSignature = $this->normaliseSha256($profileSignature);

        return $profileSignature !== ''
            && $this->normaliseSha256((string)($asset['profile_signature'] ?? '')) === $profileSignature;
    }

    public function absolutePathForPhoto(array $photo, string $imageType): ?string
    {
        $base = trim((string)($photo['storage_base_location'] ?? ''));
        $checksum = trim((string)($photo['original_sha256'] ?? ''));
        $imageType = $this->normaliseImageType($imageType);
        if ($base === '' || $checksum === '' || $imageType === '') {
            return null;
        }

        try {
            return $this->storageService->imagePath($base, $checksum, $imageType);
        } catch (Throwable) {
            return null;
        }
    }

    public function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));

        return in_array($imageType, self::IMAGE_TYPES, true) ? $imageType : '';
    }

    private function normaliseSha256(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
    }
}
