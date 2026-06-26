<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailEventAccessService
{
    private const PERMISSION_COLUMNS = [
        'can_view',
        'can_edit',
        'can_download_single_jpeg',
        'can_download_event_zip',
        'can_download_all_accessible',
        'can_download_original_raw',
    ];

    public function __construct(
        private readonly RoleRepository $roleRepository = new RoleRepository(),
    ) {
    }

    public function userCanSeeEvent(int $userId, int $eventId): bool
    {
        return $this->permissionValue($userId, $eventId, 'can_view') === 1;
    }

    public function userCanViewPhoto(int $userId, int $photoId): bool
    {
        if ($userId <= 0 || $photoId <= 0) {
            return false;
        }

        return (bool)InterfaceDB::fetchColumn(
            "SELECT 1
             FROM event_photos event_photo
             INNER JOIN event_permissions permission
                ON permission.event_id = event_photo.event_id
             WHERE event_photo.photo_id = :photo_id
               AND permission.can_view = 1
               AND " . $this->granteeWhereSql() . "
               AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            $this->granteeParams($userId) + [
                'photo_id' => $photoId,
            ]
        );
    }

    public function userCanEditPhoto(int $userId, int $photoId): bool
    {
        if ($userId <= 0 || $photoId <= 0) {
            return false;
        }

        return (bool)InterfaceDB::fetchColumn(
            "SELECT 1
             FROM event_photos event_photo
             INNER JOIN event_permissions permission
                ON permission.event_id = event_photo.event_id
             WHERE event_photo.photo_id = :photo_id
               AND permission.can_view = 1
               AND permission.can_edit = 1
               AND " . $this->granteeWhereSql() . "
               AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            $this->granteeParams($userId) + [
                'photo_id' => $photoId,
            ]
        );
    }

    public function userCanDownloadSingleJpeg(int $userId, int $eventId): bool
    {
        return $this->permissionValue($userId, $eventId, 'can_download_single_jpeg') === 1;
    }

    public function userCanDownloadSingleJpegForPhoto(int $userId, int $photoId): bool
    {
        if ($userId <= 0 || $photoId <= 0) {
            return false;
        }

        return (bool)InterfaceDB::fetchColumn(
            "SELECT 1
             FROM event_photos event_photo
             INNER JOIN event_permissions permission
                ON permission.event_id = event_photo.event_id
             WHERE event_photo.photo_id = :photo_id
               AND permission.can_view = 1
               AND permission.can_download_single_jpeg = 1
               AND " . $this->granteeWhereSql() . "
               AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            $this->granteeParams($userId) + [
                'photo_id' => $photoId,
            ]
        );
    }

    public function userCanDownloadEventZip(int $userId, int $eventId): bool
    {
        return $this->permissionValue($userId, $eventId, 'can_download_event_zip') === 1;
    }

    public function userCanDownloadAllAccessible(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return (bool)InterfaceDB::fetchColumn(
            "SELECT 1
             FROM event_permissions permission
             WHERE permission.can_download_all_accessible = 1
               AND " . $this->granteeWhereSql() . "
               AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            $this->granteeParams($userId)
        );
    }

    public function userCanDownloadOriginalRaw(int $userId, int $eventId): bool
    {
        return $this->permissionValue($userId, $eventId, 'can_download_original_raw') === 1;
    }

    public function effectivePermissionsForEvent(int $userId, int $eventId): array
    {
        $permissions = array_fill_keys(self::PERMISSION_COLUMNS, false);
        if ($userId <= 0 || $eventId <= 0) {
            return $permissions;
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT can_view,
                    can_edit,
                    can_download_single_jpeg,
                    can_download_event_zip,
                    can_download_all_accessible,
                    can_download_original_raw
             FROM event_permissions permission
             WHERE permission.event_id = :event_id
               AND " . $this->granteeWhereSql() . "
               AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)",
            $this->granteeParams($userId) + ['event_id' => $eventId]
        );

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach (self::PERMISSION_COLUMNS as $column) {
                $permissions[$column] = $permissions[$column] || (int)($row[$column] ?? 0) === 1;
            }
        }

        return $permissions;
    }

    private function permissionValue(int $userId, int $eventId, string $column): int
    {
        if ($userId <= 0 || $eventId <= 0) {
            return 0;
        }

        if (!in_array($column, self::PERMISSION_COLUMNS, true)) {
            return 0;
        }

        $value = InterfaceDB::fetchColumn(
            "SELECT MAX(permission." . $column . ")
             FROM event_permissions permission
             WHERE permission.event_id = :event_id
               AND " . $this->granteeWhereSql() . "
               AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            $this->granteeParams($userId) + [
                'event_id' => $eventId,
            ]
        );

        return (int)$value;
    }

    private function granteeWhereSql(): string
    {
        return "(
            (permission.grantee_type = 'user' AND permission.grantee_id = :grantee_user_id)
            OR
            (permission.grantee_type = 'role' AND permission.grantee_id = :grantee_role_id)
        )";
    }

    private function granteeParams(int $userId): array
    {
        return [
            'grantee_user_id' => $userId,
            'grantee_role_id' => $this->roleRepository->userRoleId($userId),
        ];
    }
}
