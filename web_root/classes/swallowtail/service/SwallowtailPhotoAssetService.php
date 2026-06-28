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
        private readonly SwallowtailCombinedProfileService $combinedProfileService = new SwallowtailCombinedProfileService(),
    ) {
    }

    public function assetForPhoto(array $photo, string $imageType, bool $requireReadableFile = true): ?array
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        $imageType = $this->normaliseImageType($imageType);
        if ($photoId <= 0 || $imageType === '' || !InterfaceDB::tableExists('photo_image_assets')) {
            return null;
        }
        if ($imageType === 'rawtheapee_sample') {
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
        return is_array($row) ? $this->assetFromRow($photo, $imageType, $row, $requireReadableFile) : null;
    }

    public function assetForPhotoProfileSignature(array $photo, string $imageType, string $profileSignature, bool $requireReadableFile = true): ?array
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        $imageType = $this->normaliseImageType($imageType);
        $profileSignature = $this->normaliseSha256($profileSignature);
        if (
            $photoId <= 0
            || $imageType !== 'rawtheapee_sample'
            || $profileSignature === ''
            || !InterfaceDB::tableExists('photo_image_assets')
        ) {
            return null;
        }

        $variantColumn = InterfaceDB::columnExists('photo_image_assets', 'asset_variant_key');
        $row = InterfaceDB::fetchOne(
            "SELECT *
             FROM photo_image_assets
             WHERE photo_id = :photo_id
               AND image_type = :image_type
               AND " . ($variantColumn ? 'asset_variant_key = :asset_variant_key' : 'profile_signature = :profile_signature') . "
             LIMIT 1",
            [
                'photo_id' => $photoId,
                'image_type' => $imageType,
                'asset_variant_key' => $profileSignature,
                'profile_signature' => $profileSignature,
            ]
        );

        return is_array($row) ? $this->assetFromRow($photo, $imageType, $row, $requireReadableFile) : null;
    }

    public function assetForPhotoWithFinalFallback(array $photo, string $imageType, bool $requireReadableFile = true): ?array
    {
        $imageType = $this->normaliseImageType($imageType);
        if ($imageType !== 'final') {
            return $this->assetForPhoto($photo, $imageType, $requireReadableFile);
        }

        if ($this->finalMatchesOriginalProfile($photo)) {
            $asset = $this->assetForPhoto($photo, 'original', $requireReadableFile);
            if ($asset !== null) {
                $asset['requested_image_type'] = 'final';
                $asset['effective_image_type'] = 'original';
                $asset['final_equivalent_original'] = true;

                return $asset;
            }
        }

        $asset = $this->assetForPhoto($photo, 'final', $requireReadableFile);
        if ($asset !== null) {
            $photoId = max(0, (int)($photo['id'] ?? 0));
            if (!$this->isFreshForSignature($asset, $this->combinedProfileService->profileSignature($photoId, 'final'))) {
                return null;
            }

            $asset['requested_image_type'] = 'final';
            $asset['effective_image_type'] = 'final';
            $asset['final_equivalent_original'] = false;
        }

        return $asset;
    }

    public function finalMatchesOriginalProfile(array $photo): bool
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));

        return $photoId > 0
            && !$this->hasEditedProfileRevisions($photoId)
            && $this->combinedProfileService->profileSignaturesMatch($photoId, 'original', 'final');
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

    public function assetForPhotoIdProfileSignature(int $photoId, string $imageType, string $profileSignature, bool $requireReadableFile = true): ?array
    {
        if ($photoId <= 0) {
            return null;
        }

        $photo = (new SwallowtailPhotoLibraryService())->photoById($photoId);
        if ($photo === null) {
            return null;
        }

        return $this->assetForPhotoProfileSignature($photo, $imageType, $profileSignature, $requireReadableFile);
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
        if ($base === '' || $checksum === '' || $imageType === '' || $imageType === 'rawtheapee_sample') {
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

    private function assetFromRow(array $photo, string $imageType, array $row, bool $requireReadableFile): ?array
    {
        $profileSignature = $this->normaliseSha256((string)($row['profile_signature'] ?? ''));
        $path = $imageType === 'rawtheapee_sample'
            ? $this->absoluteVariantPathForPhoto($photo, $imageType, $profileSignature)
            : $this->absolutePathForPhoto($photo, $imageType);
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
        $row['profile_signature'] = $profileSignature;
        $row['asset_variant_key'] = $this->normaliseSha256((string)($row['asset_variant_key'] ?? ''));

        if ((int)$row['bytes'] <= 0 || (string)$row['sha256'] === '') {
            return null;
        }

        return $row;
    }

    private function absoluteVariantPathForPhoto(array $photo, string $imageType, string $profileSignature): ?string
    {
        $base = trim((string)($photo['storage_base_location'] ?? ''));
        $checksum = trim((string)($photo['original_sha256'] ?? ''));
        $imageType = $this->normaliseImageType($imageType);
        $profileSignature = $this->normaliseSha256($profileSignature);
        if ($base === '' || $checksum === '' || $imageType !== 'rawtheapee_sample' || $profileSignature === '') {
            return null;
        }

        try {
            return $this->storageService->imageVariantPath($base, $checksum, $imageType, $profileSignature);
        } catch (Throwable) {
            return null;
        }
    }

    private function normaliseSha256(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '';
    }

    private function hasEditedProfileRevisions(int $photoId): bool
    {
        if ($photoId <= 0 || !InterfaceDB::tableExists('photo_profile_data')) {
            return false;
        }

        return (bool)InterfaceDB::fetchColumn(
            "SELECT 1
             FROM photo_profile_data
             WHERE photo_id = :photo_id
               AND type <> 'swallowtail'
               AND revision > 0
             LIMIT 1",
            ['photo_id' => $photoId]
        );
    }
}
