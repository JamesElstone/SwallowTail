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
    public const QUICK_HASH_ALGORITHM = 'fnv1a64';

    public function schemaAvailable(): bool
    {
        foreach ($this->requiredTables() as $table) {
            if (!InterfaceDB::tableExists($table)) {
                return false;
            }
        }

        return InterfaceDB::columnExists('swallowtail_photos', 'original_quick_hash');
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
            'swallowtail_api_upload_token_cidrs',
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

    public function photoByQuickHash(string $quickHash, ?int $bytes = null): ?array
    {
        $quickHash = $this->normaliseQuickHash($quickHash);
        if (!InterfaceDB::tableExists('swallowtail_photos') || !InterfaceDB::columnExists('swallowtail_photos', 'original_quick_hash')) {
            return null;
        }

        $where = 'original_quick_hash = :quick_hash';
        $params = ['quick_hash' => $quickHash];

        if ($bytes !== null) {
            if ($bytes <= 0) {
                return null;
            }

            $where .= ' AND original_bytes = :bytes';
            $params['bytes'] = $bytes;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM swallowtail_photos WHERE ' . $where . ' ORDER BY id ASC LIMIT 1',
            $params
        );

        return is_array($row) ? $row : null;
    }

    public function normaliseQuickHash(string $quickHash): string
    {
        $quickHash = strtolower(trim($quickHash));
        if (preg_match('/^[a-f0-9]{16}$/', $quickHash) !== 1) {
            throw new InvalidArgumentException('Quick checksum hash must be 16 lowercase hexadecimal characters.');
        }

        return $quickHash;
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
        $quickHash = $this->normaliseOptionalQuickHash($upload['quick_hash'] ?? null);
        $existing = $this->photoByChecksum($sha256);

        if ($existing !== null) {
            if ($quickHash !== null && trim((string)($existing['original_quick_hash'] ?? '')) === '') {
                InterfaceDB::prepareExecute(
                    "UPDATE swallowtail_photos
                     SET original_quick_hash = :quick_hash
                     WHERE id = :id
                       AND (original_quick_hash IS NULL OR original_quick_hash = '')",
                    [
                        'id' => (int)$existing['id'],
                        'quick_hash' => $quickHash,
                    ]
                );
                $existing['original_quick_hash'] = $quickHash;
            }

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
                original_quick_hash,
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
                :original_quick_hash,
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
                'original_quick_hash' => $quickHash,
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

    public function createUploadToken(
        string $label,
        ?int $createdByUserId = null,
        ?DateTimeImmutable $expiresAt = null,
        array $cidrs = []
    ): array
    {
        $this->assertSchemaAvailable();

        $token = 'stup_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $normalisedCidrs = $this->normaliseCidrs($cidrs);

        $tokenId = InterfaceDB::transaction(function () use ($tokenHash, $label, $createdByUserId, $expiresAt, $normalisedCidrs): int {
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

            $tokenId = $this->lastInsertId();
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
        if (
            !InterfaceDB::tableExists('swallowtail_api_upload_tokens')
            || !InterfaceDB::tableExists('swallowtail_api_upload_token_cidrs')
        ) {
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
        if (
            !InterfaceDB::tableExists('swallowtail_api_upload_tokens')
            || !InterfaceDB::tableExists('swallowtail_api_upload_token_cidrs')
        ) {
            return 'SwallowTail photo database tables are not available. Run the database migrations.';
        }

        $token = trim($token);
        if ($token === '') {
            return 'Bearer upload token was missing.';
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM swallowtail_api_upload_tokens WHERE token_hash = :token_hash LIMIT 1',
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
        array $metadata = []
    ): void {
        if (!InterfaceDB::tableExists('user_account_audit') || !InterfaceDB::tableExists('users')) {
            return;
        }

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

        (new UserHistoryStore())->recordAccountAudit(
            $affectedUserId,
            null,
            trim($actionType) !== '' ? trim($actionType) : 'upload_token_used',
            $reason,
            [
                'upload_token_id' => $tokenId,
                'token_label' => (string)($uploadToken['token_label'] ?? ''),
                'success' => $success,
                'client_ip' => trim((string)$remoteAddress),
                'allowed_cidrs' => array_values((array)$cidrs),
                'failure_reason' => $success ? null : $reason,
            ],
            $metadata
        );
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

    private function uploadTokenForAudit(string $token): ?array
    {
        if (!InterfaceDB::tableExists('swallowtail_api_upload_tokens')) {
            return null;
        }

        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM swallowtail_api_upload_tokens WHERE token_hash = :token_hash LIMIT 1',
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
        if (!InterfaceDB::tableExists('swallowtail_api_upload_tokens')) {
            return [];
        }

        $hasUsersTable = InterfaceDB::tableExists('users');
        $userJoinSql = $hasUsersTable
            ? "LEFT JOIN users created_by_user
                ON created_by_user.id = token.created_by_user_id"
            : '';
        $userColumnsSql = $hasUsersTable
            ? ', created_by_user.display_name AS created_by_user_display_name,
                 created_by_user.email_address AS created_by_user_email_address'
            : ", '' AS created_by_user_display_name,
                 '' AS created_by_user_email_address";

        $rows = InterfaceDB::fetchAll(
            "SELECT token.*" . $userColumnsSql . "
             FROM swallowtail_api_upload_tokens token
             " . $userJoinSql . "
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
        if ($tokenId <= 0 || !InterfaceDB::tableExists('swallowtail_api_upload_tokens')) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT * FROM swallowtail_api_upload_tokens WHERE id = :id LIMIT 1',
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
        $this->assertSchemaAvailable();

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
                "UPDATE swallowtail_api_upload_tokens
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
        if ($tokenId <= 0 || !InterfaceDB::tableExists('swallowtail_api_upload_tokens')) {
            return;
        }

        if (InterfaceDB::tableExists('swallowtail_api_upload_token_cidrs')) {
            InterfaceDB::prepareExecute(
                'DELETE FROM swallowtail_api_upload_token_cidrs WHERE token_id = :token_id',
                ['token_id' => $tokenId]
            );
        }

        InterfaceDB::prepareExecute(
            'DELETE FROM swallowtail_api_upload_tokens WHERE id = :id',
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
            throw new RuntimeException('SwallowTail photo database tables are not available. Run the database migrations.');
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

    private function normaliseOptionalQuickHash(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        return $this->normaliseQuickHash($value);
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
        if ($tokenId <= 0 || !InterfaceDB::tableExists('swallowtail_api_upload_token_cidrs')) {
            return [];
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT cidr
             FROM swallowtail_api_upload_token_cidrs
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
            'DELETE FROM swallowtail_api_upload_token_cidrs WHERE token_id = :token_id',
            ['token_id' => $tokenId]
        );

        foreach ($cidrs as $cidr) {
            InterfaceDB::prepareExecute(
                "INSERT INTO swallowtail_api_upload_token_cidrs (
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

    private function lastInsertId(): int
    {
        if (InterfaceDB::driverName() === 'sqlite') {
            return (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        }

        return (int)InterfaceDB::fetchColumn('SELECT LAST_INSERT_ID()');
    }
}
