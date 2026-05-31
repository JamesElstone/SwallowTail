<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailPhotoLibraryService
{
    public function schemaAvailable(): bool
    {
        foreach ($this->requiredTables() as $table) {
            if (!InterfaceDB::tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    public function requiredTables(): array
    {
        return [
            'swallowtail_events',
            'swallowtail_storage_locations',
            'swallowtail_photos',
            'swallowtail_event_photos',
            'swallowtail_event_permissions',
            'swallowtail_api_upload_tokens',
            'swallowtail_photo_audit',
            'swallowtail_photo_derivatives',
            'swallowtail_photo_conversion_jobs',
        ];
    }

    public function photoByChecksum(string $sha256): ?array
    {
        $sha256 = strtolower(trim($sha256));
        if ($sha256 === '' || !InterfaceDB::tableExists('swallowtail_photos')) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM swallowtail_photos WHERE original_sha256 = :sha256 LIMIT 1',
            ['sha256' => $sha256]
        );

        return is_array($row) ? $row : null;
    }

    public function photoById(int $photoId): ?array
    {
        if ($photoId <= 0 || !InterfaceDB::tableExists('swallowtail_photos')) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM swallowtail_photos WHERE id = :id LIMIT 1',
            ['id' => $photoId]
        );

        return is_array($row) ? $row : null;
    }

    public function recordRawUpload(array $upload): array
    {
        $this->assertSchemaAvailable();

        $sha256 = strtolower(trim((string)($upload['sha256'] ?? '')));
        $existing = $this->photoByChecksum($sha256);

        if ($existing !== null) {
            $this->recordPhotoAudit(
                (int)$existing['id'],
                null,
                $this->nullablePositiveInt($upload['uploaded_by_user_id'] ?? null),
                $this->nullablePositiveInt($upload['upload_token_id'] ?? null),
                'raw_duplicate_detected',
                [
                    'original_filename' => (string)($upload['original_filename'] ?? ''),
                    'uploaded_via' => (string)($upload['uploaded_via'] ?? ''),
                ],
                (array)($upload['request_metadata'] ?? [])
            );

            return [
                'success' => true,
                'duplicate' => true,
                'photo' => $existing,
            ];
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                original_storage_path,
                storage_location_id,
                upload_state,
                conversion_state,
                uploaded_by_user_id,
                uploaded_via,
                upload_token_id
            ) VALUES (
                :original_filename,
                :original_extension,
                :original_bytes,
                :original_sha256,
                :original_storage_path,
                :storage_location_id,
                'uploaded',
                'pending',
                :uploaded_by_user_id,
                :uploaded_via,
                :upload_token_id
            )",
            [
                'original_filename' => $this->normaliseFilename((string)($upload['original_filename'] ?? 'upload.raw')),
                'original_extension' => strtolower(trim((string)($upload['extension'] ?? ''))),
                'original_bytes' => max(0, (int)($upload['bytes'] ?? 0)),
                'original_sha256' => $sha256,
                'original_storage_path' => trim((string)($upload['storage_path'] ?? '')),
                'storage_location_id' => $this->nullablePositiveInt($upload['storage_location_id'] ?? null),
                'uploaded_by_user_id' => $this->nullablePositiveInt($upload['uploaded_by_user_id'] ?? null),
                'uploaded_via' => $this->normaliseUploadSource((string)($upload['uploaded_via'] ?? 'api')),
                'upload_token_id' => $this->nullablePositiveInt($upload['upload_token_id'] ?? null),
            ]
        );

        $photoId = $this->lastInsertId();
        $photo = $this->photoById($photoId);

        $this->recordPhotoAudit(
            $photoId,
            null,
            $this->nullablePositiveInt($upload['uploaded_by_user_id'] ?? null),
            $this->nullablePositiveInt($upload['upload_token_id'] ?? null),
            'raw_uploaded',
            [
                'original_filename' => (string)($upload['original_filename'] ?? ''),
                'sha256' => $sha256,
                'bytes' => max(0, (int)($upload['bytes'] ?? 0)),
                'uploaded_via' => (string)($upload['uploaded_via'] ?? ''),
            ],
            (array)($upload['request_metadata'] ?? [])
        );

        return [
            'success' => true,
            'duplicate' => false,
            'photo' => $photo,
        ];
    }

    public function createEvent(string $name, ?int $createdByUserId = null, string $slug = ''): array
    {
        $this->assertSchemaAvailable();

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Event name must not be empty.');
        }

        $slug = $slug !== '' ? $this->normaliseSlug($slug) : $this->normaliseSlug($name);

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_events (
                event_name,
                event_slug,
                created_by_user_id
            ) VALUES (
                :event_name,
                :event_slug,
                :created_by_user_id
            )",
            [
                'event_name' => $name,
                'event_slug' => $slug,
                'created_by_user_id' => $this->nullablePositiveInt($createdByUserId),
            ]
        );

        return [
            'id' => $this->lastInsertId(),
            'event_name' => $name,
            'event_slug' => $slug,
        ];
    }

    public function assignPhotoToEvent(int $photoId, int $eventId, ?int $assignedByUserId = null): void
    {
        $this->assertSchemaAvailable();

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_event_photos (
                event_id,
                photo_id,
                assigned_by_user_id
            ) VALUES (
                :event_id,
                :photo_id,
                :assigned_by_user_id
            )",
            [
                'event_id' => $eventId,
                'photo_id' => $photoId,
                'assigned_by_user_id' => $this->nullablePositiveInt($assignedByUserId),
            ]
        );

        $this->recordPhotoAudit($photoId, $eventId, $assignedByUserId, null, 'photo_assigned_to_event');
    }

    public function grantEventPermission(int $eventId, int $userId, array $permissions, ?int $grantedByUserId = null): void
    {
        $this->assertSchemaAvailable();

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_event_permissions (
                event_id,
                user_id,
                can_view,
                can_download_single_jpeg,
                can_download_event_zip,
                can_download_all_accessible,
                can_download_original_raw,
                granted_by_user_id
            ) VALUES (
                :event_id,
                :user_id,
                :can_view,
                :can_download_single_jpeg,
                :can_download_event_zip,
                :can_download_all_accessible,
                :can_download_original_raw,
                :granted_by_user_id
            )",
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'can_view' => !empty($permissions['can_view']) ? 1 : 0,
                'can_download_single_jpeg' => !empty($permissions['can_download_single_jpeg']) ? 1 : 0,
                'can_download_event_zip' => !empty($permissions['can_download_event_zip']) ? 1 : 0,
                'can_download_all_accessible' => !empty($permissions['can_download_all_accessible']) ? 1 : 0,
                'can_download_original_raw' => !empty($permissions['can_download_original_raw']) ? 1 : 0,
                'granted_by_user_id' => $this->nullablePositiveInt($grantedByUserId),
            ]
        );
    }

    public function createUploadToken(string $label, ?int $createdByUserId = null, ?DateTimeImmutable $expiresAt = null): array
    {
        $this->assertSchemaAvailable();

        $token = 'stup_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_api_upload_tokens (
                token_hash,
                token_label,
                created_by_user_id,
                expires_at
            ) VALUES (
                :token_hash,
                :token_label,
                :created_by_user_id,
                :expires_at
            )",
            [
                'token_hash' => $tokenHash,
                'token_label' => trim($label) !== '' ? trim($label) : 'Upload token',
                'created_by_user_id' => $this->nullablePositiveInt($createdByUserId),
                'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            ]
        );

        return [
            'id' => $this->lastInsertId(),
            'token' => $token,
            'token_hash' => $tokenHash,
        ];
    }

    public function authenticateUploadToken(string $token): ?array
    {
        if (!InterfaceDB::tableExists('swallowtail_api_upload_tokens')) {
            return null;
        }

        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT *
             FROM swallowtail_api_upload_tokens
             WHERE token_hash = :token_hash
               AND can_upload_raw = 1
               AND is_active = 1
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            ['token_hash' => hash('sha256', $token)]
        );

        return is_array($row) ? $row : null;
    }

    public function markUploadTokenUsed(int $tokenId): void
    {
        if ($tokenId <= 0 || !InterfaceDB::tableExists('swallowtail_api_upload_tokens')) {
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE swallowtail_api_upload_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $tokenId]
        );
    }

    public function recordPhotoAudit(
        int $photoId,
        ?int $eventId,
        ?int $actorUserId,
        ?int $uploadTokenId,
        string $actionType,
        array $details = [],
        array $requestMetadata = []
    ): void {
        if ($photoId <= 0 || !InterfaceDB::tableExists('swallowtail_photo_audit')) {
            return;
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_photo_audit (
                photo_id,
                event_id,
                actor_user_id,
                upload_token_id,
                action_type,
                details_json,
                device_id,
                ip_address,
                user_agent
            ) VALUES (
                :photo_id,
                :event_id,
                :actor_user_id,
                :upload_token_id,
                :action_type,
                :details_json,
                :device_id,
                :ip_address,
                :user_agent
            )",
            [
                'photo_id' => $photoId,
                'event_id' => $this->nullablePositiveInt($eventId),
                'actor_user_id' => $this->nullablePositiveInt($actorUserId),
                'upload_token_id' => $this->nullablePositiveInt($uploadTokenId),
                'action_type' => trim($actionType) !== '' ? trim($actionType) : 'unknown',
                'details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'device_id' => $this->normaliseOptionalString($requestMetadata['device_id'] ?? null, 128),
                'ip_address' => $this->normaliseOptionalString($requestMetadata['ip_address'] ?? null, 45),
                'user_agent' => $this->normaliseOptionalString($requestMetadata['user_agent'] ?? null, 1000),
            ]
        );
    }

    private function assertSchemaAvailable(): void
    {
        if (!$this->schemaAvailable()) {
            throw new RuntimeException('Swallowtail photo database tables are not available. Run the database migrations.');
        }
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private function normaliseUploadSource(string $source): string
    {
        $source = strtolower(trim($source));

        return in_array($source, ['web', 'api', 'worker', 'cli'], true) ? $source : 'api';
    }

    private function normaliseFilename(string $filename): string
    {
        $filename = trim(basename(str_replace('\\', '/', $filename)));
        $filename = preg_replace('/[^\w .-]+/u', '-', $filename) ?? 'upload.raw';
        $filename = trim($filename, ". \t\n\r\0\x0B-");

        return $filename !== '' ? substr($filename, 0, 255) : 'upload.raw';
    }

    private function normaliseSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? substr($slug, 0, 180) : 'event';
    }

    private function normaliseOptionalString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : substr($value, 0, $maxLength);
    }

    private function lastInsertId(): int
    {
        if (InterfaceDB::driverName() === 'sqlite') {
            return (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        }

        return (int)InterfaceDB::fetchColumn('SELECT LAST_INSERT_ID()');
    }
}
