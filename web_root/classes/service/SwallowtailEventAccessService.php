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
    public function userCanSeeEvent(int $userId, int $eventId): bool
    {
        return $this->permissionValue($userId, $eventId, 'can_view') === 1;
    }

    public function userCanViewPhoto(int $userId, int $photoId): bool
    {
        if ($userId <= 0 || $photoId <= 0 || !$this->tablesAvailable()) {
            return false;
        }

        return (bool)InterfaceDB::fetchColumn(
            "SELECT 1
             FROM event_photos event_photo
             INNER JOIN event_permissions permission
                ON permission.event_id = event_photo.event_id
             WHERE event_photo.photo_id = :photo_id
               AND permission.user_id = :user_id
               AND permission.can_view = 1
               AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            [
                'photo_id' => $photoId,
                'user_id' => $userId,
            ]
        );
    }

    public function userCanDownloadSingleJpeg(int $userId, int $eventId): bool
    {
        return $this->permissionValue($userId, $eventId, 'can_download_single_jpeg') === 1;
    }

    public function userCanDownloadEventZip(int $userId, int $eventId): bool
    {
        return $this->permissionValue($userId, $eventId, 'can_download_event_zip') === 1;
    }

    public function userCanDownloadAllAccessible(int $userId): bool
    {
        if ($userId <= 0 || !InterfaceDB::tableExists('event_permissions')) {
            return false;
        }

        return (bool)InterfaceDB::fetchColumn(
            "SELECT 1
             FROM event_permissions
             WHERE user_id = :user_id
               AND can_download_all_accessible = 1
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            ['user_id' => $userId]
        );
    }

    public function userCanDownloadOriginalRaw(int $userId, int $eventId): bool
    {
        return $this->permissionValue($userId, $eventId, 'can_download_original_raw') === 1;
    }

    private function permissionValue(int $userId, int $eventId, string $column): int
    {
        if ($userId <= 0 || $eventId <= 0 || !$this->tablesAvailable()) {
            return 0;
        }

        if (!in_array($column, [
            'can_view',
            'can_download_single_jpeg',
            'can_download_event_zip',
            'can_download_all_accessible',
            'can_download_original_raw',
        ], true)) {
            return 0;
        }

        $value = InterfaceDB::fetchColumn(
            "SELECT " . $column . "
             FROM event_permissions
             WHERE user_id = :user_id
               AND event_id = :event_id
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
             ORDER BY id DESC
             LIMIT 1",
            [
                'user_id' => $userId,
                'event_id' => $eventId,
            ]
        );

        return (int)$value;
    }

    private function tablesAvailable(): bool
    {
        return InterfaceDB::tableExists('event_permissions')
            && InterfaceDB::tableExists('event_photos');
    }
}
