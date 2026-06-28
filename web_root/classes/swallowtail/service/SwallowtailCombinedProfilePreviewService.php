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
use RoleAssignmentService;

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

    public function dashboard(int $photoId, string $imageType, int $userId): array
    {
        $imageType = $this->normaliseImageType($imageType);
        $photoId = max(0, $photoId);
        $photo = $photoId > 0 ? $this->photoForUser($photoId, $userId) : null;

        if ($photo === null) {
            $photo = $this->randomAccessiblePhoto($userId);
            $photoId = max(0, (int)($photo['id'] ?? 0));
        }

        return [
            'image_types' => $this->imageTypes(),
            'image_type' => $imageType,
            'photo_id' => $photoId,
            'photo' => $photo,
            'content' => $photoId > 0 && $photo !== null ? $this->combinedContent($photoId, $imageType) : '',
        ];
    }

    public function randomAccessiblePhoto(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $params = [];
        $rows = InterfaceDB::fetchAll(
            "SELECT photo.*
             FROM photos photo
             WHERE " . $this->accessWhereSql($userId, $params) . "
             ORDER BY photo.created_at DESC, photo.id DESC
             LIMIT 96",
            $params
        );
        $rows = array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row)));
        if ($rows === []) {
            return null;
        }

        return (array)$rows[array_rand($rows)];
    }

    public function photoForUser(int $photoId, int $userId): ?array
    {
        if ($photoId <= 0 || $userId <= 0) {
            return null;
        }

        $params = ['photo_id' => $photoId];
        $row = InterfaceDB::fetchOne(
            "SELECT photo.*
             FROM photos photo
             WHERE photo.id = :photo_id
               AND " . $this->accessWhereSql($userId, $params) . "
             LIMIT 1",
            $params
        );

        return is_array($row) ? $row : null;
    }

    public function combinedContent(int $photoId, string $imageType): string
    {
        return (new SwallowtailCombinedProfileService())->combinedProfileContent(
            $photoId,
            $this->normaliseImageType($imageType)
        );
    }

    private function accessWhereSql(int $userId, array &$params): string
    {
        $roleId = $this->roleIdForUser($userId);
        if ($roleId === RoleAssignmentService::ADMIN_ROLE_ID) {
            return "photo.upload_state = 'uploaded'";
        }

        $params['access_upload_user_id'] = $userId;
        $params['access_grantee_user_id'] = $userId;
        $params['access_grantee_role_id'] = $roleId;

        return "photo.upload_state = 'uploaded'
            AND (
                photo.uploaded_by_user_id = :access_upload_user_id
                OR EXISTS (
                    SELECT 1
                    FROM event_photos access_event_photo
                    INNER JOIN event_permissions access_permission
                        ON access_permission.event_id = access_event_photo.event_id
                    WHERE access_event_photo.photo_id = photo.id
                      AND access_permission.can_view = 1
                      AND (
                          (access_permission.grantee_type = 'user' AND access_permission.grantee_id = :access_grantee_user_id)
                          OR
                          (access_permission.grantee_type = 'role' AND access_permission.grantee_id = :access_grantee_role_id)
                      )
                      AND (access_permission.expires_at IS NULL OR access_permission.expires_at > CURRENT_TIMESTAMP)
                    LIMIT 1
                )
            )";
    }

    private function roleIdForUser(int $userId): int
    {
        return (new \CardAccessFramework())->roleIdForUser($userId);
    }
}
