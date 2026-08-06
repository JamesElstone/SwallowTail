<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

final class SwallowtailImageServeService
{
    private const IMAGE_TYPES = ['original', 'embedded', 'thumbnail', 'preview', 'final', 'rawtherapee_sample'];

    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly SwallowtailPhotoUiService $photoUiService = new SwallowtailPhotoUiService(),
        private readonly SwallowtailPhotoAssetService $assetService = new SwallowtailPhotoAssetService(),
    ) {
    }

    public function derivativeImage(int $photoId, string $imageType, int $userId, string $profileSignature = ''): ?array
    {
        if ($photoId <= 0 || $userId <= 0) {
            return null;
        }

        $imageType = $this->normaliseImageType($imageType);
        if ($imageType === '') {
            return null;
        }

        if (!$this->userCanServeImage($userId, $photoId, $imageType)) {
            return null;
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return null;
        }

        $info = $imageType === 'rawtherapee_sample'
            ? $this->assetService->assetForPhotoProfileSignature($photo, $imageType, $profileSignature)
            : $this->assetService->assetForPhoto($photo, $imageType);
        if ($info === null) {
            return null;
        }

        $etagSource = $imageType === 'rawtherapee_sample'
            ? (string)($info['profile_signature'] ?? '')
            : (string)($info['sha256'] ?? '');
        $cacheVersion = $this->normaliseCacheVersion($etagSource);
        $bytes = (int)$info['bytes'];
        $modifiedAt = (int)$info['modified_at'];

        return [
            'absolute_path' => (string)$info['absolute_path'],
            'bytes' => $bytes,
            'content_type' => 'image/jpeg',
            'image_type' => $imageType,
            'cache_version' => $cacheVersion,
            'etag' => '"' . hash('sha256', $etagSource . ':' . $bytes . ':' . $modifiedAt) . '"',
            'filename' => $this->filenameForImage((string)($photo['original_filename'] ?? 'photo'), $imageType),
            'last_modified' => gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT',
            'photo_id' => $photoId,
        ];
    }

    /**
     * @return array{valid: bool, cache_control: string}
     */
    public function cachePolicyForVersion(string $cacheVersion, ?string $requestedVersion): array
    {
        if ($requestedVersion === null) {
            return [
                'valid' => true,
                'cache_control' => 'private, max-age=300, must-revalidate',
            ];
        }

        $cacheVersion = $this->normaliseCacheVersion($cacheVersion);
        $requestedVersion = $this->normaliseCacheVersion($requestedVersion);
        if ($cacheVersion === '' || $requestedVersion === '' || !hash_equals($cacheVersion, $requestedVersion)) {
            return [
                'valid' => false,
                'cache_control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ];
        }

        return [
            'valid' => true,
            'cache_control' => 'private, max-age=31536000, immutable',
        ];
    }

    private function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));

        return in_array($imageType, self::IMAGE_TYPES, true) ? $imageType : '';
    }

    private function normaliseCacheVersion(string $cacheVersion): string
    {
        $cacheVersion = strtolower(trim($cacheVersion));

        return preg_match('/^[a-f0-9]{64}$/', $cacheVersion) === 1 ? $cacheVersion : '';
    }

    private function userCanServeImage(int $userId, int $photoId, string $imageType): bool
    {
        return $imageType !== '' && $this->photoUiService->userCanViewImageType($photoId, $userId, $imageType);
    }

    private function filenameForImage(string $originalFilename, string $imageType): string
    {
        $base = pathinfo(trim($originalFilename), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? 'photo';
        $base = trim($base, '.-');

        if ($base === '') {
            $base = 'photo';
        }

        return substr($base . '-' . $imageType, 0, 180) . '.jpg';
    }
}
