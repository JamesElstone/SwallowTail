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
    private const DERIVATIVE_TYPES = ['original_jpeg', 'preview', 'thumbnail', 'jpeg'];

    public function __construct(
        private readonly SwallowtailEventAccessService $accessService = new SwallowtailEventAccessService(),
    ) {
    }

    public function derivativeImage(int $photoId, string $derivativeType, int $userId): ?array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->tablesAvailable()) {
            return null;
        }

        $derivativeType = $this->normaliseDerivativeType($derivativeType);
        if ($derivativeType === '') {
            return null;
        }

        if (!$this->userCanServeDerivative($userId, $photoId, $derivativeType)) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT
                derivative.*,
                photo.original_filename,
                photo.storage_location_id AS photo_storage_location_id
             FROM swallowtail_photo_derivatives derivative
             INNER JOIN swallowtail_photos photo
                ON photo.id = derivative.photo_id
             WHERE derivative.photo_id = :photo_id
               AND derivative.derivative_type = :derivative_type
             LIMIT 1",
            [
                'photo_id' => $photoId,
                'derivative_type' => $derivativeType,
            ]
        );

        if (!is_array($row)) {
            return null;
        }

        $storagePath = trim((string)($row['storage_path'] ?? ''));
        if ($storagePath === '' || !$this->looksLikeJpegPath($storagePath)) {
            return null;
        }

        $storageLocationId = $this->nullablePositiveInt(
            $row['storage_location_id'] ?? $row['photo_storage_location_id'] ?? null
        );

        try {
            $storage = new SwallowtailStorageService($this->storageRootForLocation($storageLocationId));
            $absolutePath = $storage->absolutePath($storagePath);
        } catch (Throwable) {
            return null;
        }

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $bytes = (int)filesize($absolutePath);
        if ($bytes <= 0) {
            return null;
        }

        $modifiedAt = (int)filemtime($absolutePath);
        $sha256 = trim((string)($row['sha256'] ?? ''));
        $etagSource = $sha256 !== '' ? $sha256 : hash_file('sha256', $absolutePath);

        return [
            'absolute_path' => $absolutePath,
            'bytes' => $bytes,
            'content_type' => 'image/jpeg',
            'derivative_type' => $derivativeType,
            'etag' => '"' . hash('sha256', $etagSource . ':' . $bytes . ':' . $modifiedAt) . '"',
            'filename' => $this->filenameForDerivative((string)($row['original_filename'] ?? 'photo'), $derivativeType),
            'last_modified' => gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT',
            'photo_id' => $photoId,
            'storage_path' => $storagePath,
        ];
    }

    private function tablesAvailable(): bool
    {
        return InterfaceDB::tableExists('swallowtail_photos')
            && InterfaceDB::tableExists('swallowtail_photo_derivatives')
            && InterfaceDB::tableExists('swallowtail_event_photos')
            && InterfaceDB::tableExists('swallowtail_event_permissions');
    }

    private function normaliseDerivativeType(string $derivativeType): string
    {
        $derivativeType = strtolower(trim($derivativeType));

        return in_array($derivativeType, self::DERIVATIVE_TYPES, true) ? $derivativeType : '';
    }

    private function userCanServeDerivative(int $userId, int $photoId, string $derivativeType): bool
    {
        if ($derivativeType !== 'jpeg') {
            return $this->accessService->userCanViewPhoto($userId, $photoId);
        }

        if ($this->accessService->userCanDownloadAllAccessible($userId)) {
            return $this->accessService->userCanViewPhoto($userId, $photoId);
        }

        return (bool)InterfaceDB::fetchColumn(
            "SELECT 1
             FROM swallowtail_event_photos event_photo
             INNER JOIN swallowtail_event_permissions permission
                ON permission.event_id = event_photo.event_id
             WHERE event_photo.photo_id = :photo_id
               AND permission.user_id = :user_id
               AND permission.can_view = 1
               AND permission.can_download_single_jpeg = 1
               AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            [
                'photo_id' => $photoId,
                'user_id' => $userId,
            ]
        );
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

    private function looksLikeJpegPath(string $storagePath): bool
    {
        $extension = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));

        return $extension === 'jpg' || $extension === 'jpeg';
    }

    private function filenameForDerivative(string $originalFilename, string $derivativeType): string
    {
        $base = pathinfo(trim($originalFilename), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? 'photo';
        $base = trim($base, '.-');

        if ($base === '') {
            $base = 'photo';
        }

        return substr($base . '-' . str_replace('_', '-', $derivativeType), 0, 180) . '.jpg';
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }
}
