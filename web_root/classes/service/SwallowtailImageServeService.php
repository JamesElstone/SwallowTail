<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailImageServeService
{
    private const IMAGE_TYPES = ['original', 'embedded', 'preview', 'final'];

    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailPhotoUiService $photoUiService = new SwallowtailPhotoUiService(),
    ) {
    }

    public function derivativeImage(int $photoId, string $imageType, int $userId): ?array
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

        $info = $this->storageService->imageInfo($photo, $imageType);
        if ($info === null) {
            return null;
        }

        $etagSource = (string)($info['sha256'] ?? '');
        $bytes = (int)$info['bytes'];
        $modifiedAt = (int)$info['modified_at'];

        return [
            'absolute_path' => (string)$info['absolute_path'],
            'bytes' => $bytes,
            'content_type' => 'image/jpeg',
            'image_type' => $imageType,
            'etag' => '"' . hash('sha256', $etagSource . ':' . $bytes . ':' . $modifiedAt) . '"',
            'filename' => $this->filenameForImage((string)($photo['original_filename'] ?? 'photo'), $imageType),
            'last_modified' => gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT',
            'photo_id' => $photoId,
        ];
    }

    private function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));

        return in_array($imageType, self::IMAGE_TYPES, true) ? $imageType : '';
    }

    private function userCanServeImage(int $userId, int $photoId, string $imageType): bool
    {
        return $imageType !== '' && $this->photoUiService->userCanViewPhoto($photoId, $userId);
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
