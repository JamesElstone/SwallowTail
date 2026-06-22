<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailPhotoUiService
{
    private const IMAGE_TYPES = ['thumbnail', 'original', 'embedded', 'filtered'];

    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
    ) {
        logDetails();
    }

    public function schemaAvailable(): bool
    {
        logDetails();

        foreach ([
            'photos',
            'event_photos',
            'event_permissions',
            'events',
            'photo_audit',
        ] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    public function accessiblePhotos(int $userId, int $page = 1, int $perPage = 24): array
    {
        logDetails();

        if ($userId <= 0 || !$this->schemaAvailable()) {
            return $this->emptyPaginated($page, $perPage);
        }

        $page = max(1, $page);
        $perPage = max(1, min(96, $perPage));
        $params = [];
        $where = $this->accessWhereSql($userId, $params, 'photo');

        $total = (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photos photo
             WHERE " . $where,
            $params
        );

        $pagination = $this->pagination($total, $page, $perPage);
        $offset = (((int)$pagination['page']) - 1) * $perPage;

        $rows = InterfaceDB::fetchAll(
            "SELECT
                photo.*,
                (
                    SELECT GROUP_CONCAT(event.event_name)
                    FROM event_photos event_photo
                    INNER JOIN events event
                        ON event.id = event_photo.event_id
                    WHERE event_photo.photo_id = photo.id
                ) AS event_names
             FROM photos photo
             WHERE " . $where . "
             ORDER BY photo.created_at DESC, photo.id DESC
             LIMIT " . (string)$perPage . " OFFSET " . (string)$offset,
            $params
        );

        return [
            'rows' => array_map([$this, 'normaliseGalleryPhotoRow'], $rows),
            'pagination' => $pagination,
        ];
    }

    public function recentUploads(int $userId, int $limit = 8): array
    {
        logDetails();

        if ($userId <= 0 || !$this->schemaAvailable()) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $params = [];
        $where = $this->accessWhereSql($userId, $params, 'photo') . " AND photo.uploaded_via = 'web'";

        return array_map(
            [$this, 'normalisePhotoRow'],
            InterfaceDB::fetchAll(
                "SELECT
                    photo.*,
                    (
                        SELECT COUNT(*)
                        FROM photo_audit audit
                        WHERE audit.photo_id = photo.id
                          AND audit.action_type = 'raw_duplicate_detected'
                    ) AS duplicate_upload_count,
                    (
                        SELECT GROUP_CONCAT(event.event_name)
                        FROM event_photos event_photo
                        INNER JOIN events event
                            ON event.id = event_photo.event_id
                        WHERE event_photo.photo_id = photo.id
                    ) AS event_names
                 FROM photos photo
                 WHERE " . $where . "
                 ORDER BY photo.created_at DESC, photo.id DESC
                 LIMIT " . (string)$limit,
                $params
            )
        );
    }

    public function photoDetails(int $photoId, int $userId): ?array
    {
        logDetails();

        if ($photoId <= 0 || $userId <= 0) {
            return null;
        }

        $params = ['photo_id' => $photoId];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');

        $photo = InterfaceDB::fetchOne(
            "SELECT
                photo.*,
                photo.storage_base_location AS storage_root_path,
                photo.storage_base_location AS location_label,
                (
                    SELECT GROUP_CONCAT(event.event_name)
                    FROM event_photos event_photo
                    INNER JOIN events event
                        ON event.id = event_photo.event_id
                    WHERE event_photo.photo_id = photo.id
                ) AS event_names
             FROM photos photo
             WHERE " . $where . "
             LIMIT 1",
            $params
        );

        if (!is_array($photo)) {
            return null;
        }

        $photo = $this->normalisePhotoRow($photo);
        $photo['derivatives'] = $this->photoImages($photo);

        return $photo;
    }

    public function photoAsset(int $photoId, int $userId, string $type): ?array
    {
        logDetails();

        if ($photoId <= 0 || $userId <= 0) {
            return null;
        }

        $type = strtolower(trim($type));
        if (!in_array($type, self::IMAGE_TYPES, true)) {
            return null;
        }

        $params = ['photo_id' => $photoId];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');
        $this->tracePhotoAssetRowLookupStart();
        $photo = InterfaceDB::fetchOne('SELECT * FROM photos photo WHERE ' . $where . ' LIMIT 1', $params);
        if (!is_array($photo)) {
            $this->tracePhotoAssetRowNotFound();
            return null;
        }
        $this->tracePhotoAssetRowLookupComplete();

        $this->tracePhotoAssetImageInfoStart();
        $info = $this->storageService->imageInfo($photo, $type);
        if ($info === null) {
            $this->tracePhotoAssetImageInfoMissing();
            return null;
        }
        $this->tracePhotoAssetImageInfoComplete();

        return [
            'path' => (string)$info['absolute_path'],
            'content_type' => 'image/jpeg',
            'filename' => $this->assetFilename((string)($photo['original_filename'] ?? 'photo'), $type),
            'bytes' => (int)$info['bytes'],
            'sha256' => (string)$info['sha256'],
        ];
    }

    public function userCanViewPhoto(int $photoId, int $userId): bool
    {
        logDetails();

        if ($photoId <= 0 || $userId <= 0 || !$this->schemaAvailable()) {
            return false;
        }

        $params = ['photo_id' => $photoId];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');

        return (bool)InterfaceDB::fetchColumn(
            'SELECT 1 FROM photos photo WHERE ' . $where . ' LIMIT 1',
            $params
        );
    }

    private function tracePhotoAssetRowLookupStart(): void
    {
        logDetails();
    }

    private function tracePhotoAssetRowLookupComplete(): void
    {
        logDetails();
    }

    private function tracePhotoAssetRowNotFound(): void
    {
        logDetails();
    }

    private function tracePhotoAssetImageInfoStart(): void
    {
        logDetails();
    }

    private function tracePhotoAssetImageInfoComplete(): void
    {
        logDetails();
    }

    private function tracePhotoAssetImageInfoMissing(): void
    {
        logDetails();
    }

    private function photoImages(array $photo): array
    {
        logDetails();

        $images = [];
        foreach (self::IMAGE_TYPES as $type) {
            $info = $this->storageService->imageInfo($photo, $type);
            if ($info !== null) {
                $images[$type] = [
                    'image_type' => $type,
                    'bytes' => (int)$info['bytes'],
                    'generated_at' => date('Y-m-d H:i:s', (int)$info['modified_at']),
                    'storage_path' => (string)$info['absolute_path'],
                ];
            }
        }

        return $images;
    }

    private function accessWhereSql(int $userId, array &$params, string $photoAlias): string
    {
        logDetails();

        if ($this->isAdminUser($userId)) {
            return $photoAlias . ".upload_state = 'uploaded'";
        }

        $params['access_user_id'] = $userId;
        $params['access_upload_user_id'] = $userId;

        return $photoAlias . ".upload_state = 'uploaded'
            AND (
                " . $photoAlias . ".uploaded_by_user_id = :access_upload_user_id
                OR EXISTS (
                    SELECT 1
                    FROM event_photos access_event_photo
                    INNER JOIN event_permissions access_permission
                        ON access_permission.event_id = access_event_photo.event_id
                    WHERE access_event_photo.photo_id = " . $photoAlias . ".id
                      AND access_permission.user_id = :access_user_id
                      AND access_permission.can_view = 1
                      AND (access_permission.expires_at IS NULL OR access_permission.expires_at > CURRENT_TIMESTAMP)
                    LIMIT 1
                )
            )";
    }

    private function isAdminUser(int $userId): bool
    {
        logDetails();

        try {
            return (new RoleAssignmentService())->isAdminUser($userId);
        } catch (Throwable) {
            return false;
        }
    }

    private function normaliseGalleryPhotoRow(array $row): array
    {
        logDetails();

        $row['id'] = (int)($row['id'] ?? 0);
        $row['original_bytes'] = (int)($row['original_bytes'] ?? 0);
        $row['uploaded_by_user_id'] = $this->nullableInt($row['uploaded_by_user_id'] ?? null);
        $row['duplicate_upload_count'] = (int)($row['duplicate_upload_count'] ?? 0);
        $row['thumbnail_ready'] = $this->storageService->imageReady($row, 'thumbnail');
        $row['embedded_ready'] = !$row['thumbnail_ready'] && $this->storageService->imageReady($row, 'embedded');

        return $row;
    }

    private function normalisePhotoRow(array $row): array
    {
        logDetails();

        $row['id'] = (int)($row['id'] ?? 0);
        $row['original_bytes'] = (int)($row['original_bytes'] ?? 0);
        $row['uploaded_by_user_id'] = $this->nullableInt($row['uploaded_by_user_id'] ?? null);
        $row['duplicate_upload_count'] = (int)($row['duplicate_upload_count'] ?? 0);
        $row['thumbnail_ready'] = $this->storageService->imageInfo($row, 'thumbnail') !== null;
        $row['original_ready'] = $this->storageService->imageInfo($row, 'original') !== null;
        $row['embedded_ready'] = $this->storageService->imageInfo($row, 'embedded') !== null;
        $row['filtered_ready'] = $this->storageService->imageInfo($row, 'filtered') !== null;
        $row['preview_ready'] = $row['filtered_ready'] || $row['original_ready'];
        $row['jpeg_ready'] = $row['filtered_ready'];

        return $row;
    }

    private function pagination(int $total, int $page, int $perPage): array
    {
        logDetails();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total_items' => $total,
            'total_pages' => $totalPages,
            'has_previous_page' => $page > 1,
            'has_next_page' => $page < $totalPages,
            'first_item' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'last_item' => min($total, $page * $perPage),
        ];
    }

    private function emptyPaginated(int $page, int $perPage): array
    {
        logDetails();

        return [
            'rows' => [],
            'pagination' => $this->pagination(0, max(1, $page), max(1, $perPage)),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        logDetails();

        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private function assetFilename(string $originalFilename, string $type): string
    {
        logDetails();

        $basename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$basename) ?? 'photo';
        $basename = trim($basename, '.-');

        return ($basename !== '' ? $basename : 'photo') . '-' . $type . '.jpg';
    }
}
