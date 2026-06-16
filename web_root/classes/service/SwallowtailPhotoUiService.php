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
    private const DERIVATIVE_TYPES = ['thumbnail', 'preview', 'jpeg'];

    public function schemaAvailable(): bool
    {
        foreach ([
            'swallowtail_photos',
            'swallowtail_photo_derivatives',
            'swallowtail_storage_locations',
            'swallowtail_event_photos',
            'swallowtail_event_permissions',
            'swallowtail_events',
            'swallowtail_photo_audit',
        ] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    public function accessiblePhotos(int $userId, int $page = 1, int $perPage = 24): array
    {
        if ($userId <= 0 || !$this->schemaAvailable()) {
            return $this->emptyPaginated($page, $perPage);
        }

        $page = max(1, $page);
        $perPage = max(1, min(96, $perPage));
        $offset = ($page - 1) * $perPage;
        $params = [];
        $where = $this->accessWhereSql($userId, $params, 'photo');
        $limitSql = (string)$perPage;
        $offsetSql = (string)$offset;

        $total = (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM swallowtail_photos photo
             WHERE " . $where,
            $params
        );

        $rows = InterfaceDB::fetchAll(
            "SELECT
                photo.*,
                thumbnail.id AS thumbnail_derivative_id,
                preview.id AS preview_derivative_id,
                jpeg.id AS jpeg_derivative_id,
                (
                    SELECT GROUP_CONCAT(event.event_name)
                    FROM swallowtail_event_photos event_photo
                    INNER JOIN swallowtail_events event
                        ON event.id = event_photo.event_id
                    WHERE event_photo.photo_id = photo.id
                ) AS event_names
             FROM swallowtail_photos photo
             LEFT JOIN swallowtail_photo_derivatives thumbnail
                ON thumbnail.photo_id = photo.id
               AND thumbnail.derivative_type = 'thumbnail'
             LEFT JOIN swallowtail_photo_derivatives preview
                ON preview.photo_id = photo.id
               AND preview.derivative_type = 'preview'
             LEFT JOIN swallowtail_photo_derivatives jpeg
                ON jpeg.photo_id = photo.id
               AND jpeg.derivative_type = 'jpeg'
             WHERE " . $where . "
             ORDER BY photo.created_at DESC, photo.id DESC
             LIMIT " . $limitSql . " OFFSET " . $offsetSql,
            $params
        );

        return [
            'rows' => array_map([$this, 'normalisePhotoRow'], $rows),
            'pagination' => $this->pagination($total, $page, $perPage),
        ];
    }

    public function recentUploads(int $userId, int $limit = 8): array
    {
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
                    thumbnail.id AS thumbnail_derivative_id,
                    preview.id AS preview_derivative_id,
                    jpeg.id AS jpeg_derivative_id,
                    (
                        SELECT COUNT(*)
                        FROM swallowtail_photo_audit audit
                        WHERE audit.photo_id = photo.id
                          AND audit.action_type = 'raw_duplicate_detected'
                    ) AS duplicate_upload_count,
                    (
                        SELECT GROUP_CONCAT(event.event_name)
                        FROM swallowtail_event_photos event_photo
                        INNER JOIN swallowtail_events event
                            ON event.id = event_photo.event_id
                        WHERE event_photo.photo_id = photo.id
                    ) AS event_names
                 FROM swallowtail_photos photo
                 LEFT JOIN swallowtail_photo_derivatives thumbnail
                    ON thumbnail.photo_id = photo.id
                   AND thumbnail.derivative_type = 'thumbnail'
                 LEFT JOIN swallowtail_photo_derivatives preview
                    ON preview.photo_id = photo.id
                   AND preview.derivative_type = 'preview'
                 LEFT JOIN swallowtail_photo_derivatives jpeg
                    ON jpeg.photo_id = photo.id
                   AND jpeg.derivative_type = 'jpeg'
                 WHERE " . $where . "
                 ORDER BY photo.created_at DESC, photo.id DESC
                 LIMIT " . (string)$limit,
                $params
            )
        );
    }

    public function photoDetails(int $photoId, int $userId): ?array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->schemaAvailable()) {
            return null;
        }

        $params = ['photo_id' => $photoId];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');

        $photo = InterfaceDB::fetchOne(
            "SELECT
                photo.*,
                location.location_label,
                location.root_path AS storage_root_path,
                thumbnail.id AS thumbnail_derivative_id,
                preview.id AS preview_derivative_id,
                jpeg.id AS jpeg_derivative_id,
                (
                    SELECT GROUP_CONCAT(event.event_name)
                    FROM swallowtail_event_photos event_photo
                    INNER JOIN swallowtail_events event
                        ON event.id = event_photo.event_id
                    WHERE event_photo.photo_id = photo.id
                ) AS event_names
             FROM swallowtail_photos photo
             LEFT JOIN swallowtail_storage_locations location
                ON location.id = photo.storage_location_id
             LEFT JOIN swallowtail_photo_derivatives thumbnail
                ON thumbnail.photo_id = photo.id
               AND thumbnail.derivative_type = 'thumbnail'
             LEFT JOIN swallowtail_photo_derivatives preview
                ON preview.photo_id = photo.id
               AND preview.derivative_type = 'preview'
             LEFT JOIN swallowtail_photo_derivatives jpeg
                ON jpeg.photo_id = photo.id
               AND jpeg.derivative_type = 'jpeg'
             WHERE " . $where . "
             LIMIT 1",
            $params
        );

        if (!is_array($photo)) {
            return null;
        }

        $photo = $this->normalisePhotoRow($photo);
        $photo['derivatives'] = $this->photoDerivatives($photoId);

        return $photo;
    }

    public function photoAsset(int $photoId, int $userId, string $type): ?array
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->schemaAvailable()) {
            return null;
        }

        $type = strtolower(trim($type));
        if (!in_array($type, self::DERIVATIVE_TYPES, true)) {
            return null;
        }

        $params = [
            'photo_id' => $photoId,
            'derivative_type' => $type,
        ];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');

        $row = InterfaceDB::fetchOne(
            "SELECT
                photo.original_filename,
                derivative.storage_path,
                derivative.bytes,
                derivative.sha256,
                COALESCE(derivative_location.root_path, photo_location.root_path) AS root_path
             FROM swallowtail_photos photo
             INNER JOIN swallowtail_photo_derivatives derivative
                ON derivative.photo_id = photo.id
               AND derivative.derivative_type = :derivative_type
             LEFT JOIN swallowtail_storage_locations derivative_location
                ON derivative_location.id = derivative.storage_location_id
             LEFT JOIN swallowtail_storage_locations photo_location
                ON photo_location.id = photo.storage_location_id
             WHERE " . $where . "
             LIMIT 1",
            $params
        );

        if (!is_array($row)) {
            return null;
        }

        $rootPath = trim((string)($row['root_path'] ?? ''));
        $storagePath = trim((string)($row['storage_path'] ?? ''));
        if ($storagePath === '') {
            return null;
        }

        $storage = new SwallowtailStorageService($rootPath);
        $absolutePath = $storage->absolutePath($storagePath);
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        return [
            'path' => $absolutePath,
            'content_type' => 'image/jpeg',
            'filename' => $this->assetFilename((string)($row['original_filename'] ?? 'photo'), $type),
            'bytes' => (int)filesize($absolutePath),
            'sha256' => (string)($row['sha256'] ?? ''),
        ];
    }

    public function userCanViewPhoto(int $photoId, int $userId): bool
    {
        if ($photoId <= 0 || $userId <= 0 || !$this->schemaAvailable()) {
            return false;
        }

        $params = ['photo_id' => $photoId];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');

        return (bool)InterfaceDB::fetchColumn(
            'SELECT 1 FROM swallowtail_photos photo WHERE ' . $where . ' LIMIT 1',
            $params
        );
    }

    private function photoDerivatives(int $photoId): array
    {
        $rows = InterfaceDB::fetchAll(
            "SELECT derivative_type, bytes, generated_at, storage_path
             FROM swallowtail_photo_derivatives
             WHERE photo_id = :photo_id
             ORDER BY derivative_type",
            ['photo_id' => $photoId]
        );

        $derivatives = [];
        foreach ($rows as $row) {
            $type = (string)($row['derivative_type'] ?? '');
            if ($type === '') {
                continue;
            }

            $derivatives[$type] = $row;
        }

        return $derivatives;
    }

    private function accessWhereSql(int $userId, array &$params, string $photoAlias): string
    {
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
                    FROM swallowtail_event_photos access_event_photo
                    INNER JOIN swallowtail_event_permissions access_permission
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
        try {
            return (new RoleAssignmentService())->isAdminUser($userId);
        } catch (Throwable) {
            return false;
        }
    }

    private function normalisePhotoRow(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['original_bytes'] = (int)($row['original_bytes'] ?? 0);
        $row['uploaded_by_user_id'] = $this->nullableInt($row['uploaded_by_user_id'] ?? null);
        $row['storage_location_id'] = $this->nullableInt($row['storage_location_id'] ?? null);
        $row['duplicate_upload_count'] = (int)($row['duplicate_upload_count'] ?? 0);
        $row['thumbnail_ready'] = !empty($row['thumbnail_derivative_id']);
        $row['preview_ready'] = !empty($row['preview_derivative_id']);
        $row['jpeg_ready'] = !empty($row['jpeg_derivative_id']);

        return $row;
    }

    private function pagination(int $total, int $page, int $perPage): array
    {
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
        return [
            'rows' => [],
            'pagination' => $this->pagination(0, max(1, $page), max(1, $perPage)),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private function assetFilename(string $originalFilename, string $type): string
    {
        $basename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$basename) ?? 'photo';
        $basename = trim($basename, '.-');

        return ($basename !== '' ? $basename : 'photo') . '-' . $type . '.jpg';
    }
}
