<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailStorageLocationService
{
    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
    ) {
    }

    public function registerLocation(string $label, string $rootPath, array $options = []): int
    {
        if (!InterfaceDB::tableExists('swallowtail_storage_locations')) {
            throw new RuntimeException('Swallowtail storage location table is not available. Run the database migrations.');
        }

        $label = trim($label) !== '' ? trim($label) : 'Storage location';
        $rootPath = (new SwallowtailStorageService($rootPath))->storageRoot();

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_storage_locations (
                location_label,
                root_path,
                reserve_bytes,
                sort_order,
                is_active,
                is_read_only,
                is_full
            ) VALUES (
                :location_label,
                :root_path,
                :reserve_bytes,
                :sort_order,
                :is_active,
                :is_read_only,
                :is_full
            )",
            [
                'location_label' => substr($label, 0, 255),
                'root_path' => $rootPath,
                'reserve_bytes' => max(0, (int)($options['reserve_bytes'] ?? 0)),
                'sort_order' => (int)($options['sort_order'] ?? 100),
                'is_active' => array_key_exists('is_active', $options) && empty($options['is_active']) ? 0 : 1,
                'is_read_only' => !empty($options['is_read_only']) ? 1 : 0,
                'is_full' => !empty($options['is_full']) ? 1 : 0,
            ]
        );

        return $this->lastInsertId();
    }

    public function locations(int $requiredBytes = 0): array
    {
        return $this->storageService->storageLocations($requiredBytes);
    }

    public function markLocationFull(int $locationId, bool $isFull = true): void
    {
        $this->updateLocationFlag($locationId, 'is_full', $isFull);
    }

    public function setLocationReadOnly(int $locationId, bool $isReadOnly = true): void
    {
        $this->updateLocationFlag($locationId, 'is_read_only', $isReadOnly);
    }

    public function movePhotoOriginalToLocation(int $photoId, int $targetLocationId): array
    {
        if (!InterfaceDB::tableExists('swallowtail_storage_locations')) {
            throw new RuntimeException('Swallowtail storage location table is not available. Run the database migrations.');
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            throw new RuntimeException('Photo was not found.');
        }

        $sourceLocationId = (int)($photo['storage_location_id'] ?? 0);
        if ($sourceLocationId <= 0) {
            throw new RuntimeException('Photo is not attached to a managed storage location.');
        }

        if ($sourceLocationId === $targetLocationId) {
            return [
                'success' => true,
                'moved' => false,
                'photo_id' => $photoId,
                'storage_location_id' => $targetLocationId,
            ];
        }

        $sourceLocation = $this->locationById($sourceLocationId);
        $targetLocation = $this->locationById($targetLocationId);

        if ($sourceLocation === null || $targetLocation === null) {
            throw new RuntimeException('Storage location was not found.');
        }

        if ((int)($targetLocation['is_active'] ?? 0) !== 1 || (int)($targetLocation['is_read_only'] ?? 0) === 1 || (int)($targetLocation['is_full'] ?? 0) === 1) {
            throw new RuntimeException('Target storage location is not writable.');
        }

        $this->storageService->moveStoredFile(
            (string)$photo['original_storage_path'],
            (string)$sourceLocation['root_path'],
            (string)$targetLocation['root_path'],
            (string)$photo['original_sha256']
        );

        InterfaceDB::prepareExecute(
            'UPDATE swallowtail_photos SET storage_location_id = :storage_location_id WHERE id = :photo_id',
            [
                'storage_location_id' => $targetLocationId,
                'photo_id' => $photoId,
            ]
        );

        $this->photoLibraryService->recordPhotoAudit(
            $photoId,
            null,
            null,
            null,
            'photo_moved_storage_location',
            [
                'from_storage_location_id' => $sourceLocationId,
                'to_storage_location_id' => $targetLocationId,
            ]
        );

        return [
            'success' => true,
            'moved' => true,
            'photo_id' => $photoId,
            'storage_location_id' => $targetLocationId,
        ];
    }

    private function locationById(int $locationId): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT * FROM swallowtail_storage_locations WHERE id = :id LIMIT 1',
            ['id' => $locationId]
        );

        return is_array($row) ? $row : null;
    }

    private function updateLocationFlag(int $locationId, string $column, bool $value): void
    {
        if (!in_array($column, ['is_full', 'is_read_only'], true)) {
            throw new InvalidArgumentException('Unsupported storage location flag.');
        }

        InterfaceDB::prepareExecute(
            'UPDATE swallowtail_storage_locations SET ' . $column . ' = :value WHERE id = :id',
            [
                'value' => $value ? 1 : 0,
                'id' => $locationId,
            ]
        );
    }

    private function lastInsertId(): int
    {
        if (InterfaceDB::driverName() === 'sqlite') {
            return (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        }

        return (int)InterfaceDB::fetchColumn('SELECT LAST_INSERT_ID()');
    }
}
