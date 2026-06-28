<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

use ActivityStore;
use DateTimeImmutable;
use DateTimeInterface;
use InterfaceDB;
use InvalidArgumentException;
use RequestFramework;
use ResponseFramework;
use RuntimeException;
use SignupTokenRateLimitService;
use Throwable;
use UserHistoryStore;

final class SwallowtailPhotoLibraryService
{
    public const UPLOAD_CHECKSUM_ALGORITHM = 'sha256';

    public function photoByChecksum(string $sha256): ?array
    {
        try {
            $sha256 = $this->normaliseSha256($sha256);
        } catch (InvalidArgumentException) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM photos WHERE original_sha256 = :sha256 LIMIT 1',
            ['sha256' => $sha256]
        );

        return is_array($row) ? $row : null;
    }

    public function photoByChecksumAndSize(string $sha256, ?int $bytes = null): ?array
    {
        $photo = $this->photoByChecksum($sha256);
        if ($photo === null || $bytes === null) {
            return $photo;
        }

        return (int)($photo['original_bytes'] ?? 0) === $bytes ? $photo : null;
    }

    public function normaliseSha256(string $sha256): string
    {
        $sha256 = strtolower(trim($sha256));
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('SHA-256 checksum must be 64 hexadecimal characters.');
        }

        return $sha256;
    }

    public function photoById(int $photoId): ?array
    {
        if ($photoId <= 0) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM photos WHERE id = :id LIMIT 1',
            ['id' => $photoId]
        );

        return is_array($row) ? $row : null;
    }

    public function recordRawUpload(array $upload): array
    {
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

        $columns = [
            'original_filename',
            'original_extension',
            'original_bytes',
            'original_sha256',
            'storage_base_location',
            'upload_state',
            'conversion_state',
            'uploaded_by_user_id',
            'uploaded_via',
            'upload_token_id',
        ];
        $values = [
            ':original_filename',
            ':original_extension',
            ':original_bytes',
            ':original_sha256',
            ':storage_base_location',
            "'uploaded'",
            "'pending'",
            ':uploaded_by_user_id',
            ':uploaded_via',
            ':upload_token_id',
        ];
        $params = [
            'original_filename' => $this->normaliseFilename((string)($upload['original_filename'] ?? 'upload.raw')),
            'original_extension' => strtolower(trim((string)($upload['extension'] ?? ''))),
            'original_bytes' => max(0, (int)($upload['bytes'] ?? 0)),
            'original_sha256' => $sha256,
            'storage_base_location' => trim((string)($upload['storage_base_location'] ?? '')),
            'uploaded_by_user_id' => $this->nullablePositiveInt($upload['uploaded_by_user_id'] ?? null),
            'uploaded_via' => $this->normaliseUploadSource((string)($upload['uploaded_via'] ?? 'api')),
            'upload_token_id' => $this->nullablePositiveInt($upload['upload_token_id'] ?? null),
        ];

        if (InterfaceDB::columnExists('photos', 'rawtherapee_profile_id')) {
            $columns[] = 'rawtherapee_profile_id';
            $values[] = ':rawtherapee_profile_id';
            $params['rawtherapee_profile_id'] = (new SwallowtailRawTherapeeProfileService())->defaultProfileId();
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO photos (" . implode(",\n                ", $columns) . "
            ) VALUES (
                " . implode(",\n                ", $values) . "
            )",
            $params
        );

        $photo = $this->photoByChecksum($sha256);
        if ($photo === null) {
            throw new RuntimeException('Uploaded photo row was not found after insert.');
        }
        $photoId = (int)$photo['id'];

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
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Event name must not be empty.');
        }

        $slug = $slug !== '' ? $this->normaliseSlug($slug) : $this->normaliseSlug($name);

        InterfaceDB::prepareExecute(
            "INSERT INTO events (
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

        $eventId = InterfaceDB::fetchColumn(
            'SELECT id FROM events WHERE event_slug = :event_slug LIMIT 1',
            ['event_slug' => $slug]
        );
        $eventId = (int)$eventId;
        if ($eventId <= 0) {
            throw new RuntimeException('Event row was not found after insert.');
        }

        return [
            'id' => $eventId,
            'event_name' => $name,
            'event_slug' => $slug,
        ];
    }

    public function assignPhotoToEvent(int $photoId, int $eventId, ?int $assignedByUserId = null): void
    {
        InterfaceDB::prepareExecute(
            "INSERT INTO event_photos (
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
        $this->grantEventGranteePermission($eventId, 'user', $userId, $permissions, $grantedByUserId);
    }

    public function grantEventRolePermission(int $eventId, int $roleId, array $permissions, ?int $grantedByUserId = null): void
    {
        $this->grantEventGranteePermission($eventId, 'role', $roleId, $permissions, $grantedByUserId);
    }

    public function grantEventGranteePermission(
        int $eventId,
        string $granteeType,
        int $granteeId,
        array $permissions,
        ?int $grantedByUserId = null
    ): void
    {
        $granteeType = $this->normaliseEventPermissionGranteeType($granteeType);
        if ($eventId <= 0 || $granteeId <= 0) {
            throw new InvalidArgumentException('Event permission target was invalid.');
        }

        InterfaceDB::transaction(function () use ($eventId, $granteeType, $granteeId, $permissions, $grantedByUserId): void {
            InterfaceDB::prepareExecute(
                "DELETE FROM event_permissions
                 WHERE event_id = :event_id
                   AND grantee_type = :grantee_type
                   AND grantee_id = :grantee_id",
                [
                    'event_id' => $eventId,
                    'grantee_type' => $granteeType,
                    'grantee_id' => $granteeId,
                ]
            );

            if (!$this->permissionPayloadHasAnyGrant($permissions)) {
                return;
            }

            InterfaceDB::prepareExecute(
                "INSERT INTO event_permissions (
                    event_id,
                    grantee_type,
                    grantee_id,
                    can_view,
                    can_edit,
                    can_download_single_jpeg,
                    can_download_event_zip,
                    can_download_all_accessible,
                    can_download_original_raw,
                    granted_by_user_id
                ) VALUES (
                    :event_id,
                    :grantee_type,
                    :grantee_id,
                    :can_view,
                    :can_edit,
                    :can_download_single_jpeg,
                    :can_download_event_zip,
                    :can_download_all_accessible,
                    :can_download_original_raw,
                    :granted_by_user_id
                )",
                [
                    'event_id' => $eventId,
                    'grantee_type' => $granteeType,
                    'grantee_id' => $granteeId,
                    'can_view' => !empty($permissions['can_view']) ? 1 : 0,
                    'can_edit' => !empty($permissions['can_edit']) ? 1 : 0,
                    'can_download_single_jpeg' => !empty($permissions['can_download_single_jpeg']) ? 1 : 0,
                    'can_download_event_zip' => !empty($permissions['can_download_event_zip']) ? 1 : 0,
                    'can_download_all_accessible' => !empty($permissions['can_download_all_accessible']) ? 1 : 0,
                    'can_download_original_raw' => !empty($permissions['can_download_original_raw']) ? 1 : 0,
                    'granted_by_user_id' => $this->nullablePositiveInt($grantedByUserId),
                ]
            );
        });
    }

    public function revokeEventGranteePermission(int $eventId, string $granteeType, int $granteeId): void
    {
        $granteeType = $this->normaliseEventPermissionGranteeType($granteeType);
        if ($eventId <= 0 || $granteeId <= 0) {
            return;
        }

        InterfaceDB::prepareExecute(
            "DELETE FROM event_permissions
             WHERE event_id = :event_id
               AND grantee_type = :grantee_type
               AND grantee_id = :grantee_id",
            [
                'event_id' => $eventId,
                'grantee_type' => $granteeType,
                'grantee_id' => $granteeId,
            ]
        );
    }

    public function createUploadToken(
        string $label,
        ?int $createdByUserId = null,
        ?DateTimeImmutable $expiresAt = null,
        array $cidrs = []
    ): array
    {
        $token = 'stup_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $normalisedCidrs = $this->normaliseCidrs($cidrs);

        $tokenId = InterfaceDB::transaction(function () use ($tokenHash, $label, $createdByUserId, $expiresAt, $normalisedCidrs): int {
            InterfaceDB::prepareExecute(
                "INSERT INTO api_upload_tokens (
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

            $tokenId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM api_upload_tokens WHERE token_hash = :token_hash LIMIT 1',
                ['token_hash' => $tokenHash]
            );
            if ($tokenId <= 0) {
                throw new RuntimeException('Upload token row was not found after insert.');
            }
            $this->replaceUploadTokenCidrs($tokenId, $normalisedCidrs);

            return $tokenId;
        });

        return [
            'id' => $tokenId,
            'token' => $token,
            'token_hash' => $tokenHash,
            'cidrs' => $normalisedCidrs,
        ];
    }

    public function authenticateUploadToken(string $token, ?string $remoteAddress = null): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT *
             FROM api_upload_tokens
             WHERE token_hash = :token_hash
               AND hidden = 0
               AND can_upload_raw = 1
               AND is_active = 1
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
             LIMIT 1",
            ['token_hash' => hash('sha256', $token)]
        );

        if (!is_array($row)) {
            return null;
        }

        $cidrs = $this->cidrsForUploadToken((int)($row['id'] ?? 0));
        if (!$this->ipAllowedByCidrs((string)$remoteAddress, $cidrs)) {
            return null;
        }

        $row['cidrs'] = $cidrs;

        return $row;
    }

    public function uploadTokenFromRequest(RequestFramework $request): string
    {
        $authorization = trim((string)$request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
            return trim($match[1]);
        }

        foreach (['X-SwallowTail-Upload-Token', 'X-Swallowtail-Upload-Token'] as $header) {
            $token = trim((string)$request->header($header, ''));
            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }

    public function explainUploadTokenAuthenticationFailure(string $token, ?string $remoteAddress = null): string
    {
        $token = trim($token);
        if ($token === '') {
            return 'Bearer upload token was missing.';
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM api_upload_tokens WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => hash('sha256', $token)]
        );

        if (!is_array($row)) {
            return 'Bearer upload token was not found. Register SpiceBush again to issue a fresh token.';
        }

        if ((int)($row['is_active'] ?? 0) !== 1) {
            return 'Bearer upload token is disabled. Enable it in SwallowTail or register SpiceBush again.';
        }

        if ((int)($row['can_upload_raw'] ?? 0) !== 1) {
            return 'Bearer upload token is not allowed to upload RAW files.';
        }

        if ($this->uploadTokenExpired((string)($row['expires_at'] ?? ''))) {
            return 'Bearer upload token has expired. Register SpiceBush again to issue a fresh token.';
        }

        $cidrs = $this->cidrsForUploadToken((int)($row['id'] ?? 0));
        if (!$this->ipAllowedByCidrs((string)$remoteAddress, $cidrs)) {
            $ip = trim((string)$remoteAddress);
            $cidrText = $cidrs === [] ? 'none' : implode(', ', $cidrs);
            return sprintf(
                'Bearer upload token is not allowed from this network. Client IP %s is outside allowed CIDR range(s): %s.',
                $ip !== '' ? $ip : 'unknown',
                $cidrText
            );
        }

        return 'Bearer upload token was rejected.';
    }

    public function uploadTokenAuditMetadata(RequestFramework $request): array
    {
        $deviceId = trim((string)$request->header('X-Swallowtail-Device-ID', ''));
        if ($deviceId === '') {
            $deviceId = trim((string)$request->header('X-AntiFraud-Client-Device-ID', ''));
        }

        return [
            'device_id' => $deviceId,
            'ip_address' => (string)$request->remoteAddress(),
            'user_agent' => (string)$request->header('User-Agent', ''),
        ];
    }

    public function isUploadTokenRequestBlocked(RequestFramework $request): bool
    {
        return (new SignupTokenRateLimitService())->isBlocked($request);
    }

    public function recordFailedUploadTokenRequest(RequestFramework $request): array
    {
        return (new SignupTokenRateLimitService())->recordFailedToken($request);
    }

    public function uploadTokenLockoutResponse(): ResponseFramework
    {
        return ResponseFramework::json([
            'success' => false,
            'errors' => ['Too many invalid token attempts. Please try again later.'],
        ], 429);
    }

    public function recordUploadTokenUsage(
        ?array $uploadToken,
        string $token,
        ?string $remoteAddress,
        string $actionType,
        bool $success,
        string $reason = '',
        array $metadata = [],
        array $details = []
    ): void {
        $uploadToken = is_array($uploadToken) ? $uploadToken : $this->uploadTokenForAudit($token);
        if (!is_array($uploadToken)) {
            return;
        }

        $affectedUserId = $this->uploadTokenAccountUserId($uploadToken);
        if ($affectedUserId === null) {
            return;
        }

        $tokenId = (int)($uploadToken['id'] ?? 0);
        if ($tokenId <= 0) {
            return;
        }

        $cidrs = $uploadToken['cidrs'] ?? $this->cidrsForUploadToken($tokenId);
        $reason = trim($reason) !== ''
            ? trim($reason)
            : ($success ? 'Upload token request was accepted.' : 'Upload token request was rejected.');

        $auditDetails = array_merge([
            'upload_token_id' => $tokenId,
            'token_label' => (string)($uploadToken['token_label'] ?? ''),
            'created_by_user_id' => $affectedUserId,
            'success' => $success,
            'client_ip' => trim((string)$remoteAddress),
            'allowed_cidrs' => array_values((array)$cidrs),
            'failure_reason' => $success ? null : $reason,
        ], $details);

        $actionType = trim($actionType) !== '' ? trim($actionType) : 'upload_token_used';
        $this->recordUploadTokenActivity($affectedUserId, $actionType, $success, $reason, $metadata, $auditDetails);

        (new UserHistoryStore())->recordAccountAudit(
            $affectedUserId,
            null,
            $actionType,
            $reason,
            $auditDetails,
            $metadata
        );
    }

    public function markUploadTokenUsed(int $tokenId): void
    {
        if ($tokenId <= 0) {
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE api_upload_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $tokenId]
        );
    }

    private function uploadTokenForAudit(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM api_upload_tokens WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => hash('sha256', $token)]
        );

        if (!is_array($row)) {
            return null;
        }

        $row['cidrs'] = $this->cidrsForUploadToken((int)($row['id'] ?? 0));

        return $row;
    }

    private function uploadTokenAccountUserId(array $uploadToken): ?int
    {
        foreach (['user_id', 'owner_user_id', 'associated_user_id', 'created_by_user_id'] as $key) {
            $userId = $this->nullablePositiveInt($uploadToken[$key] ?? null);
            if ($userId !== null) {
                return $userId;
            }
        }

        return null;
    }

    private function recordUploadTokenActivity(
        int $userId,
        string $actionType,
        bool $success,
        string $reason,
        array $metadata,
        array $details
    ): void {
        if ($userId <= 0) {
            return;
        }

        (new ActivityStore())->recordApiActivity(
            'api',
            $this->uploadTokenActivityAction($actionType),
            $success ? 'success' : 'error',
            $reason,
            $userId,
            $metadata,
            $this->uploadTokenActivityDetail($details),
            $this->uploadTokenActivityMethod($actionType),
            $this->uploadTokenActivityUri($actionType)
        );
    }

    private function uploadTokenActivityAction(string $actionType): string
    {
        $label = preg_replace('/^upload_token_/', '', $actionType) ?? $actionType;
        $label = str_replace('_', ' ', $label);

        return trim($label) !== '' ? trim($label) : 'upload token';
    }

    private function uploadTokenActivityDetail(array $details): ?string
    {
        $tokenLabel = trim((string)($details['token_label'] ?? ''));

        return $tokenLabel !== '' ? $tokenLabel : null;
    }

    private function uploadTokenActivityMethod(string $actionType): ?string
    {
        return str_contains($actionType, 'raw_upload') ? 'POST' : 'GET';
    }

    private function uploadTokenActivityUri(string $actionType): string
    {
        if (str_contains($actionType, 'raw_upload')) {
            return '/api/upload-raw.php';
        }
        if (str_contains($actionType, 'quick_checksum')) {
            return '/api/upload-checksum.php';
        }
        if (str_contains($actionType, 'conversion_status')) {
            return '/api/upload-status.php';
        }
        if (str_contains($actionType, 'ping')) {
            return '/api/remote-ping.php';
        }

        return '/api';
    }

    private function uploadTokenUserLabel(array $uploadToken): string
    {
        $displayName = trim((string)($uploadToken['created_by_user_display_name'] ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }

        $emailAddress = trim((string)($uploadToken['created_by_user_email_address'] ?? ''));
        if ($emailAddress !== '') {
            return $emailAddress;
        }

        $userId = $this->uploadTokenAccountUserId($uploadToken);

        return $userId !== null ? 'User #' . $userId : 'Unassigned';
    }

    public function listUploadTokens(): array
    {
        $rows = InterfaceDB::fetchAll(
            "SELECT token.*,
                    created_by_user.display_name AS created_by_user_display_name,
                    created_by_user.email_address AS created_by_user_email_address
             FROM api_upload_tokens token
             LEFT JOIN users created_by_user
                ON created_by_user.id = token.created_by_user_id
             WHERE token.hidden = 0
             ORDER BY token.is_active DESC, token.created_at DESC, token.id DESC"
        );
        $tokens = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $tokenId = (int)($row['id'] ?? 0);
            $row['cidrs'] = $this->cidrsForUploadToken($tokenId);
            $row['cidr_summary'] = implode(', ', $row['cidrs']);
            $row['created_by_user_label'] = $this->uploadTokenUserLabel($row);
            $tokens[] = $row;
        }

        return $tokens;
    }

    public function uploadTokenById(int $tokenId): ?array
    {
        if ($tokenId <= 0) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM api_upload_tokens WHERE id = :id LIMIT 1',
            ['id' => $tokenId]
        );

        if (!is_array($row)) {
            return null;
        }

        $row['cidrs'] = $this->cidrsForUploadToken($tokenId);
        $row['cidr_summary'] = implode(', ', $row['cidrs']);

        return $row;
    }

    public function updateUploadToken(int $tokenId, array $changes): array
    {
        if ($tokenId <= 0 || $this->uploadTokenById($tokenId) === null) {
            throw new InvalidArgumentException('Upload token was not found.');
        }

        $label = trim((string)($changes['token_label'] ?? $changes['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Upload token label is required.');
        }

        $cidrs = $this->normaliseCidrs((array)($changes['cidrs'] ?? []));
        $isActive = !empty($changes['is_active']) ? 1 : 0;
        $canUploadRaw = array_key_exists('can_upload_raw', $changes) && empty($changes['can_upload_raw']) ? 0 : 1;
        $expiresAt = $this->normaliseExpiresAt($changes['expires_at'] ?? null);

        InterfaceDB::transaction(function () use ($tokenId, $label, $isActive, $canUploadRaw, $expiresAt, $cidrs): void {
            InterfaceDB::prepareExecute(
                "UPDATE api_upload_tokens
                 SET token_label = :token_label,
                     can_upload_raw = :can_upload_raw,
                     is_active = :is_active,
                     expires_at = :expires_at
                 WHERE id = :id",
                [
                    'id' => $tokenId,
                    'token_label' => $label,
                    'can_upload_raw' => $canUploadRaw,
                    'is_active' => $isActive,
                    'expires_at' => $expiresAt,
                ]
            );

            $this->replaceUploadTokenCidrs($tokenId, $cidrs);
        });

        $updated = $this->uploadTokenById($tokenId);
        if ($updated === null) {
            throw new RuntimeException('Upload token was not found after update.');
        }

        return $updated;
    }

    public function deleteUploadToken(int $tokenId): void
    {
        if ($tokenId <= 0) {
            return;
        }

        InterfaceDB::prepareExecute(
            "UPDATE api_upload_tokens
             SET hidden = 1,
                 can_upload_raw = 0,
                 is_active = 0,
                 expires_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            ['id' => $tokenId]
        );
    }

    public function normaliseCidrs(array|string $cidrs): array
    {
        if (is_string($cidrs)) {
            $cidrs = preg_split('/[\s,]+/', $cidrs) ?: [];
        }

        $normalised = [];
        foreach ($cidrs as $cidr) {
            $cidr = strtolower(trim((string)$cidr));
            if ($cidr === '') {
                continue;
            }

            $normalised[] = $this->normaliseCidr($cidr);
        }

        $normalised = array_values(array_unique($normalised));
        if ($normalised === []) {
            throw new InvalidArgumentException('At least one CIDR range is required.');
        }

        return $normalised;
    }

    public function ipAllowedByCidrs(string $ipAddress, array $cidrs): bool
    {
        $ipAddress = trim($ipAddress);
        $ipBytes = @inet_pton($ipAddress);
        if ($ipBytes === false || $cidrs === []) {
            return false;
        }

        foreach ($cidrs as $cidr) {
            $parts = explode('/', trim((string)$cidr), 2);
            if (count($parts) !== 2) {
                continue;
            }

            $networkBytes = @inet_pton($parts[0]);
            if ($networkBytes === false || strlen($networkBytes) !== strlen($ipBytes)) {
                continue;
            }

            $prefix = (int)$parts[1];
            if ($this->binaryAddressInPrefix($ipBytes, $networkBytes, $prefix)) {
                return true;
            }
        }

        return false;
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
        if ($photoId <= 0) {
            return;
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO photo_audit (
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

    private function normaliseEventPermissionGranteeType(string $granteeType): string
    {
        $granteeType = strtolower(trim($granteeType));
        if (!in_array($granteeType, ['user', 'role'], true)) {
            throw new InvalidArgumentException('Event permission grantee type was invalid.');
        }

        return $granteeType;
    }

    private function permissionPayloadHasAnyGrant(array $permissions): bool
    {
        foreach ([
            'can_view',
            'can_edit',
            'can_download_single_jpeg',
            'can_download_event_zip',
            'can_download_all_accessible',
            'can_download_original_raw',
        ] as $key) {
            if (!empty($permissions[$key])) {
                return true;
            }
        }

        return false;
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

    private function normaliseCidr(string $cidr): string
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Upload token CIDR values must include a prefix length.');
        }

        $address = trim($parts[0]);
        $prefixRaw = trim($parts[1]);
        if ($address === '' || $prefixRaw === '' || preg_match('/^\d+$/', $prefixRaw) !== 1) {
            throw new InvalidArgumentException('Invalid upload token CIDR range.');
        }

        $bytes = @inet_pton($address);
        if ($bytes === false) {
            throw new InvalidArgumentException('Invalid upload token CIDR address.');
        }

        $bits = strlen($bytes) * 8;
        $prefix = (int)$prefixRaw;
        if ($prefix < 0 || $prefix > $bits) {
            throw new InvalidArgumentException('Invalid upload token CIDR prefix length.');
        }

        return strtolower($address) . '/' . $prefix;
    }

    private function uploadTokenExpired(string $expiresAt): bool
    {
        $expiresAt = trim($expiresAt);
        if ($expiresAt === '') {
            return false;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT CASE WHEN :expires_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS expired',
            ['expires_at' => $expiresAt]
        );

        return is_array($row) && (int)($row['expired'] ?? 0) === 1;
    }

    private function cidrsForUploadToken(int $tokenId): array
    {
        if ($tokenId <= 0) {
            return [];
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT cidr
             FROM api_upload_token_cidrs
             WHERE token_id = :token_id
             ORDER BY cidr",
            ['token_id' => $tokenId]
        );
        $cidrs = [];

        foreach ($rows as $row) {
            $cidr = trim((string)($row['cidr'] ?? ''));
            if ($cidr !== '') {
                $cidrs[] = $cidr;
            }
        }

        return $cidrs;
    }

    private function replaceUploadTokenCidrs(int $tokenId, array $cidrs): void
    {
        InterfaceDB::prepareExecute(
            'DELETE FROM api_upload_token_cidrs WHERE token_id = :token_id',
            ['token_id' => $tokenId]
        );

        foreach ($cidrs as $cidr) {
            InterfaceDB::prepareExecute(
                "INSERT INTO api_upload_token_cidrs (
                    token_id,
                    cidr
                ) VALUES (
                    :token_id,
                    :cidr
                )",
                [
                    'token_id' => $tokenId,
                    'cidr' => $cidr,
                ]
            );
        }
    }

    private function binaryAddressInPrefix(string $ipBytes, string $networkBytes, int $prefix): bool
    {
        $bits = strlen($ipBytes) * 8;
        if ($prefix < 0 || $prefix > $bits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }

    private function normaliseExpiresAt(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new InvalidArgumentException('Upload token expiry date was invalid.');
        }
    }

    private function normaliseOptionalString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : substr($value, 0, $maxLength);
    }
}
