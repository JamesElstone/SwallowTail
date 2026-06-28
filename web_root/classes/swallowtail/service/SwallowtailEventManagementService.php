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
use RoleRepository;

final class SwallowtailEventManagementService
{
    private const PERMISSION_KEYS = [
        'can_view',
        'can_edit',
        'can_download_single_jpeg',
        'can_download_event_zip',
        'can_download_all_accessible',
        'can_download_original_raw',
    ];

    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly RoleRepository $roleRepository = new RoleRepository(),
    ) {
    }

    public function listEvents(): array
    {
        $rows = InterfaceDB::fetchAll(
            "SELECT event.*,
                    (
                        SELECT COUNT(*)
                        FROM event_photos event_photo
                        WHERE event_photo.event_id = event.id
                    ) AS photo_count
             FROM events event
             ORDER BY event.is_active DESC, event.starts_at DESC, event.event_name ASC, event.id ASC"
        );

        return array_values(array_map([$this, 'normaliseEventRow'], $rows));
    }

    public function defaultEventId(): int
    {
        return (int)InterfaceDB::fetchColumn(
            "SELECT id
             FROM events
             ORDER BY is_active DESC, starts_at DESC, event_name ASC, id ASC
             LIMIT 1"
        );
    }

    public function eventById(int $eventId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM events WHERE id = :id LIMIT 1',
            ['id' => $eventId]
        );

        return is_array($row) ? $this->normaliseEventRow($row) : null;
    }

    public function createEvent(string $name, ?int $createdByUserId = null): array
    {
        return $this->photoLibraryService->createEvent($name, $createdByUserId);
    }

    public function setPermission(
        int $eventId,
        string $granteeType,
        int $granteeId,
        array $permissions,
        ?int $grantedByUserId = null
    ): void {
        $this->photoLibraryService->grantEventGranteePermission(
            $eventId,
            $granteeType,
            $granteeId,
            $this->normalisePermissions($permissions),
            $grantedByUserId
        );
    }

    public function addUserViewPermission(int $eventId, int $userId, ?int $grantedByUserId = null): void
    {
        $this->setPermission($eventId, 'user', $userId, ['can_view' => true], $grantedByUserId);
    }

    public function deletePermission(int $eventId, string $granteeType, int $granteeId): void
    {
        $this->photoLibraryService->revokeEventGranteePermission($eventId, $granteeType, $granteeId);
    }

    public function rolePermissionRows(int $eventId): array
    {
        $roles = $this->roleRepository->listRoles();
        $permissions = $this->permissionLookup($eventId, 'role');
        $rows = [];

        foreach ($roles as $role) {
            $roleId = (int)($role['id'] ?? 0);
            if ($roleId <= 0) {
                continue;
            }

            $grant = $permissions[$roleId] ?? [];
            $rows[] = array_merge(
                [
                    'grantee_type' => 'role',
                    'grantee_id' => $roleId,
                    'role_id' => $roleId,
                    'role_name' => (string)($role['role_name'] ?? ''),
                    'assigned_user_count' => (int)($role['assigned_user_count'] ?? 0),
                ],
                $this->permissionDefaults($grant)
            );
        }

        return $rows;
    }

    public function userPermissionRows(int $eventId): array
    {
        $rows = InterfaceDB::fetchAll(
            "SELECT permission.*,
                    account_user.display_name,
                    account_user.email_address,
                    account_user.role_id,
                    role.role_name
             FROM event_permissions permission
             INNER JOIN users account_user
                ON account_user.id = permission.grantee_id
             LEFT JOIN roles role
                ON role.id = account_user.role_id
             WHERE permission.event_id = :event_id
               AND permission.grantee_type = 'user'
             ORDER BY account_user.display_name ASC, account_user.email_address ASC, account_user.id ASC",
            ['event_id' => $eventId]
        );

        $rolePermissions = $this->permissionLookup($eventId, 'role');
        $normalised = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $roleId = (int)($row['role_id'] ?? 0);
            $normalised[] = array_merge(
                [
                    'grantee_type' => 'user',
                    'grantee_id' => (int)($row['grantee_id'] ?? 0),
                    'user_id' => (int)($row['grantee_id'] ?? 0),
                    'display_name' => (string)($row['display_name'] ?? ''),
                    'email_address' => (string)($row['email_address'] ?? ''),
                    'role_id' => $roleId,
                    'role_name' => (string)($row['role_name'] ?? ''),
                    'inherited_permissions' => $this->permissionDefaults($rolePermissions[$roleId] ?? []),
                ],
                $this->permissionDefaults($row)
            );
        }

        return $normalised;
    }

    public function searchUsers(int $eventId, string $query, int $limit = 8): array
    {
        $eventId = max(0, $eventId);
        $query = trim($query);
        if ($eventId <= 0 || strlen($query) < 2) {
            return [];
        }

        $limit = max(1, min(12, $limit));
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';

        $rows = InterfaceDB::fetchAll(
            "SELECT account_user.id,
                    account_user.display_name,
                    account_user.email_address,
                    account_user.role_id,
                    role.role_name
             FROM users account_user
             LEFT JOIN roles role
                ON role.id = account_user.role_id
             WHERE NOT EXISTS (
                    SELECT 1
                    FROM event_permissions permission
                    WHERE permission.event_id = :event_id
                      AND permission.grantee_type = 'user'
                      AND permission.grantee_id = account_user.id
                )
               AND (
                    account_user.display_name LIKE :query
                    OR account_user.email_address LIKE :query
                )
             ORDER BY account_user.display_name ASC, account_user.email_address ASC, account_user.id ASC
             LIMIT " . (string)$limit,
            [
                'event_id' => $eventId,
                'query' => $like,
            ]
        );

        return array_values(array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'display_name' => (string)($row['display_name'] ?? ''),
                'email_address' => (string)($row['email_address'] ?? ''),
                'role_id' => (int)($row['role_id'] ?? 0),
                'role_name' => (string)($row['role_name'] ?? ''),
            ];
        }, $rows));
    }

    public function eventOptionsForAssignment(): array
    {
        return array_values(array_filter(
            $this->listEvents(),
            static fn(array $event): bool => !empty($event['is_active'])
        ));
    }

    public function assignPhotosToEvent(array $photoIds, int $eventId, bool $assigned, ?int $actorUserId = null): void
    {
        $eventId = max(0, $eventId);
        $photoIds = array_values(array_unique(array_filter(array_map('intval', $photoIds), static fn(int $id): bool => $id > 0)));
        if ($eventId <= 0 || $photoIds === []) {
            return;
        }

        InterfaceDB::transaction(function () use ($photoIds, $eventId, $assigned, $actorUserId): void {
            foreach ($photoIds as $photoId) {
                if ($assigned) {
                    if (InterfaceDB::countWhere('event_photos', [
                        'event_id' => $eventId,
                        'photo_id' => $photoId,
                    ]) <= 0) {
                        $this->photoLibraryService->assignPhotoToEvent($photoId, $eventId, $actorUserId);
                    }
                    continue;
                }

                InterfaceDB::prepareExecute(
                    'DELETE FROM event_photos WHERE event_id = :event_id AND photo_id = :photo_id',
                    [
                        'event_id' => $eventId,
                        'photo_id' => $photoId,
                    ]
                );
            }
        });
    }

    public function normalisePermissions(array $payload): array
    {
        $permissions = [];
        foreach (self::PERMISSION_KEYS as $key) {
            $permissions[$key] = !empty($payload[$key]);
        }

        if (!empty($permissions['can_edit'])) {
            $permissions['can_view'] = true;
        }

        return $permissions;
    }

    private function permissionLookup(int $eventId, string $granteeType): array
    {
        if ($eventId <= 0 || !in_array($granteeType, ['user', 'role'], true)) {
            return [];
        }

        $lookup = [];
        foreach (InterfaceDB::fetchAll(
            "SELECT *
             FROM event_permissions
             WHERE event_id = :event_id
               AND grantee_type = :grantee_type",
            [
                'event_id' => $eventId,
                'grantee_type' => $granteeType,
            ]
        ) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lookup[(int)($row['grantee_id'] ?? 0)] = $row;
        }

        return $lookup;
    }

    private function permissionDefaults(array $row): array
    {
        $permissions = [];
        foreach (self::PERMISSION_KEYS as $key) {
            $permissions[$key] = (int)($row[$key] ?? 0) === 1;
        }

        return $permissions;
    }

    private function normaliseEventRow(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['created_by_user_id'] = $this->nullableInt($row['created_by_user_id'] ?? null);
        $row['is_active'] = (int)($row['is_active'] ?? 0) === 1;
        $row['photo_count'] = (int)($row['photo_count'] ?? 0);

        return $row;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }
}
