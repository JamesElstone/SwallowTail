<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

final class SwallowtailCombinedProfilePreviewService
{
    public const IMAGE_TYPES = ['thumbnail', 'original', 'preview', 'final'];

    public function imageTypes(): array
    {
        return self::IMAGE_TYPES;
    }

    public function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));

        return in_array($imageType, self::IMAGE_TYPES, true) ? $imageType : 'preview';
    }

    public function randomAccessiblePhoto(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $gallery = (new SwallowtailPhotoUiService())->accessiblePhotos($userId, 1, 96);
        $rows = array_values(array_filter((array)($gallery['rows'] ?? []), static fn(mixed $row): bool => is_array($row)));
        if ($rows === []) {
            return null;
        }

        return (array)$rows[array_rand($rows)];
    }

    public function photoForUser(int $photoId, int $userId): ?array
    {
        if ($photoId <= 0 || $userId <= 0 || !(new SwallowtailPhotoUiService())->userCanViewPhoto($photoId, $userId)) {
            return null;
        }

        return (new SwallowtailPhotoLibraryService())->photoById($photoId);
    }

    public function combinedContent(int $photoId, string $imageType): string
    {
        return (new SwallowtailCombinedProfileService())->combinedProfileContent(
            $photoId,
            $this->normaliseImageType($imageType)
        );
    }
}
