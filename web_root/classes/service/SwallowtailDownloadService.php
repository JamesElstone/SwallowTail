<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailDownloadService
{
    private const EVENT_IMAGE_TYPES = ['preview', 'embedded', 'source', 'final'];

    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailEventAccessService $eventAccessService = new SwallowtailEventAccessService(),
        private readonly SwallowtailPhotoAssetService $assetService = new SwallowtailPhotoAssetService(),
    ) {
    }

    public function downloadableEventsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $admin = $this->isAdminUser($userId);
        $events = $admin
            ? (new SwallowtailEventManagementService())->listEvents()
            : $this->permittedDownloadEvents($userId);

        $rows = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventId = (int)($event['id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }

            $permissions = $admin
                ? $this->adminPermissions()
                : $this->eventAccessService->effectivePermissionsForEvent($userId, $eventId);

            if (empty($permissions['can_download_event_zip']) && !$admin) {
                continue;
            }

            $rows[] = [
                'id' => $eventId,
                'event_name' => (string)($event['event_name'] ?? 'Event'),
                'event_slug' => (string)($event['event_slug'] ?? ''),
                'photo_count' => (int)($event['photo_count'] ?? 0),
                'permissions' => $permissions,
                'options' => $this->eventOptionsForPermissions($permissions),
            ];
        }

        return $rows;
    }

    public function eventZip(int $userId, int $eventId, string $requestedType): array
    {
        $imageType = $this->normaliseRequestedType($requestedType);
        $event = (new SwallowtailEventManagementService())->eventById($eventId);
        if ($userId <= 0 || $event === null) {
            throw new RuntimeException('Download was not found.');
        }

        $permissions = $this->isAdminUser($userId)
            ? $this->adminPermissions()
            : $this->eventAccessService->effectivePermissionsForEvent($userId, $eventId);
        $this->assertCanDownloadEventType($permissions, $imageType);

        $files = $this->eventFiles($eventId, $imageType);
        if ($files === []) {
            throw new RuntimeException('No files are available for that download yet.');
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP downloads are unavailable because the PHP zip extension is not installed.');
        }

        $zipPath = $this->temporaryZipPath($this->estimatedBytes($files));
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Unable to create the download zip file.');
        }

        try {
            foreach ($files as $file) {
                $zip->addFile((string)$file['path'], (string)$file['zip_name']);
            }
        } finally {
            $zip->close();
        }

        if (!is_file($zipPath) || (int)filesize($zipPath) <= 0) {
            @unlink($zipPath);
            throw new RuntimeException('The download zip file could not be prepared.');
        }

        return [
            'path' => $zipPath,
            'filename' => $this->eventZipFilename($event, $imageType),
            'bytes' => (int)filesize($zipPath),
            'content_type' => 'application/zip',
        ];
    }

    public function singleJpeg(int $userId, int $photoId): array
    {
        $ui = new SwallowtailPhotoUiService($this->storageService);
        if (!$ui->userCanDownloadSingleJpeg($photoId, $userId)) {
            throw new RuntimeException('Photo download was not found.');
        }

        $asset = $ui->photoAsset($photoId, $userId, 'final');
        if ($asset === null) {
            throw new RuntimeException('No final JPEG is available for that photo yet.');
        }

        return $asset + [
            'content_type' => 'image/jpeg',
        ];
    }

    private function permittedDownloadEvents(int $userId): array
    {
        $rows = InterfaceDB::fetchAll(
            "SELECT event.*,
                    (
                        SELECT COUNT(*)
                        FROM event_photos event_photo
                        WHERE event_photo.event_id = event.id
                    ) AS photo_count
             FROM events event
             WHERE EXISTS (
                    SELECT 1
                    FROM event_permissions permission
                    WHERE permission.event_id = event.id
                      AND permission.can_view = 1
                      AND permission.can_download_event_zip = 1
                      AND " . $this->granteeWhereSql('permission') . "
                      AND (permission.expires_at IS NULL OR permission.expires_at > CURRENT_TIMESTAMP)
                )
             ORDER BY event.is_active DESC, event.event_name ASC, event.id ASC",
            $this->granteeParams($userId)
        );

        return array_values(array_filter($rows, 'is_array'));
    }

    private function eventFiles(int $eventId, string $imageType): array
    {
        $rows = InterfaceDB::fetchAll(
            "SELECT photo.*
             FROM event_photos event_photo
             INNER JOIN photos photo
                ON photo.id = event_photo.photo_id
             WHERE event_photo.event_id = :event_id
               AND photo.upload_state = 'uploaded'
             ORDER BY event_photo.sort_order ASC, photo.original_filename ASC, photo.id ASC",
            ['event_id' => $eventId]
        );

        $files = [];
        $usedNames = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $info = in_array($imageType, ['source', 'final_profile'], true)
                ? $this->localStoredFileInfo($row, $imageType)
                : $this->assetService->assetForPhoto($row, $imageType);
            if ($info === null) {
                continue;
            }

            $zipName = $this->uniqueZipName(
                $this->downloadFilename((string)($row['original_filename'] ?? 'photo'), $imageType),
                $usedNames
            );

            $files[] = [
                'path' => (string)$info['absolute_path'],
                'zip_name' => $zipName,
                'bytes' => (int)$info['bytes'],
            ];
        }

        return $files;
    }

    private function localStoredFileInfo(array $photo, string $imageType): ?array
    {
        $checksum = strtolower(trim((string)($photo['original_sha256'] ?? '')));
        $base = trim((string)($photo['storage_base_location'] ?? ''));
        if ($checksum === '' || $base === '') {
            return null;
        }

        $path = $this->storageService->imagePath($base, $checksum, $imageType);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $bytes = $imageType === 'source' ? (int)($photo['original_bytes'] ?? 0) : (int)filesize($path);
        if ($bytes <= 0) {
            $bytes = (int)filesize($path);
        }
        if ($bytes <= 0) {
            return null;
        }

        return [
            'absolute_path' => $path,
            'bytes' => $bytes,
        ];
    }

    private function assertCanDownloadEventType(array $permissions, string $imageType): void
    {
        if (empty($permissions['can_download_event_zip'])) {
            throw new RuntimeException('You do not have permission to download event zip files.');
        }

        if ($imageType === 'source' && empty($permissions['can_download_original_raw'])) {
            throw new RuntimeException('You do not have permission to download source RAW files.');
        }

        if ($imageType === 'final_profile' && empty($permissions['can_edit'])) {
            throw new RuntimeException('You do not have permission to download final PP3 files.');
        }
    }

    private function normaliseRequestedType(string $requestedType): string
    {
        $requestedType = strtolower(trim($requestedType));
        if ($requestedType === 'final_pp3' || $requestedType === 'final_profile') {
            return 'final_profile';
        }

        if (!in_array($requestedType, self::EVENT_IMAGE_TYPES, true)) {
            throw new RuntimeException('Unsupported download type.');
        }

        return $requestedType;
    }

    private function eventOptionsForPermissions(array $permissions): array
    {
        $options = [];
        foreach (self::EVENT_IMAGE_TYPES as $type) {
            if ($type === 'source' && empty($permissions['can_download_original_raw'])) {
                continue;
            }

            $options[] = [
                'type' => $type,
                'label' => ucfirst($type),
            ];
        }

        if (!empty($permissions['can_edit'])) {
            $options[] = [
                'type' => 'final_profile',
                'label' => 'Final PP3',
            ];
        }

        return $options;
    }

    private function temporaryZipPath(int $requiredBytes): string
    {
        $locations = $this->storageService->storageLocations(max(1, $requiredBytes));
        foreach ($locations as $location) {
            if (empty($location['can_write'])) {
                continue;
            }

            $root = trim((string)($location['data_root'] ?? $location['root_path'] ?? ''));
            if ($root === '') {
                continue;
            }

            $directory = rtrim($root, "\\/") . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
            $path = $directory . 'swallowtail-download-' . bin2hex(random_bytes(16)) . '.zip';
            $this->storageService->ensureDirectoryForPath($path);
            if (@touch($path)) {
                @unlink($path);
                return $path;
            }
        }

        throw new RuntimeException('No writable SwallowTail storage location is available for download scratch space.');
    }

    private function eventZipFilename(array $event, string $imageType): string
    {
        $slug = trim((string)($event['event_slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower((string)($event['event_name'] ?? 'event'));
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? 'event';
            $slug = trim($slug, '-');
        }

        $label = $imageType === 'final_profile' ? 'final-pp3' : $imageType;

        return ($slug !== '' ? $slug : 'event') . '-' . $label . '.zip';
    }

    private function downloadFilename(string $originalFilename, string $imageType): string
    {
        $basename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $basename) ?? 'photo';
        $basename = trim($basename, '.-');
        $basename = $basename !== '' ? $basename : 'photo';

        return match ($imageType) {
            'source' => $this->safeOriginalFilename($originalFilename, 'cr2'),
            'final_profile' => $basename . '_final.pp3',
            default => $basename . '_' . $imageType . '.jpg',
        };
    }

    private function safeOriginalFilename(string $originalFilename, string $fallbackExtension): string
    {
        $filename = basename(str_replace('\\', '/', $originalFilename));
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? '';
        $filename = trim($filename, '.-');
        if ($filename === '') {
            return 'photo.' . $fallbackExtension;
        }

        return $filename;
    }

    private function uniqueZipName(string $filename, array &$usedNames): string
    {
        $candidate = $filename;
        $index = 2;
        while (isset($usedNames[strtolower($candidate)])) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $candidate = $extension !== ''
                ? $basename . '-' . (string)$index . '.' . $extension
                : $filename . '-' . (string)$index;
            $index++;
        }

        $usedNames[strtolower($candidate)] = true;

        return $candidate;
    }

    private function estimatedBytes(array $files): int
    {
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += max(0, (int)($file['bytes'] ?? 0));
        }

        return $bytes;
    }

    private function adminPermissions(): array
    {
        return [
            'can_view' => true,
            'can_edit' => true,
            'can_download_single_jpeg' => true,
            'can_download_event_zip' => true,
            'can_download_all_accessible' => true,
            'can_download_original_raw' => true,
        ];
    }

    private function isAdminUser(int $userId): bool
    {
        try {
            return (new RoleAssignmentService())->isAdminUser($userId);
        } catch (Throwable) {
            return false;
        }
    }

    private function granteeWhereSql(string $permissionAlias): string
    {
        return "(
            (" . $permissionAlias . ".grantee_type = 'user' AND " . $permissionAlias . ".grantee_id = :grantee_user_id)
            OR
            (" . $permissionAlias . ".grantee_type = 'role' AND " . $permissionAlias . ".grantee_id = :grantee_role_id)
        )";
    }

    private function granteeParams(int $userId): array
    {
        return [
            'grantee_user_id' => $userId,
            'grantee_role_id' => (new RoleRepository())->userRoleId($userId),
        ];
    }
}
