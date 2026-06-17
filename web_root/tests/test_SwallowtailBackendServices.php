<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(SwallowtailStorageService::class);
$harness->run(SwallowtailPhotoLibraryService::class);
$harness->run(SwallowtailPhotoIngestService::class);
$harness->run(SwallowtailRawUploadApiService::class);
$harness->run(SwallowtailConversionStatusApiService::class);
$harness->run(SwallowtailQuickChecksumApiService::class);
$harness->run(SwallowtailSpiceBushRegistrationApiService::class);
$harness->run(SwallowtailEventAccessService::class);
$harness->run(SwallowtailConversionQueueService::class);
$harness->run(SwallowtailStorageLocationService::class);
$harness->run(SwallowtailImageServeService::class);
$harness->run(SwallowtailPreviewProfileService::class);

$swallowtailCreateSqliteSchema = static function (): void {
    InterfaceDB::execute('PRAGMA foreign_keys = OFF');

    foreach ([
        'swallowtail_photo_audit',
        'swallowtail_photo_conversion_jobs',
        'swallowtail_photo_derivatives',
        'swallowtail_event_permissions',
        'swallowtail_event_photos',
        'swallowtail_photos',
        'swallowtail_storage_locations',
        'swallowtail_api_upload_token_cidrs',
        'swallowtail_api_upload_tokens',
        'signup_token_rate_limits',
        'swallowtail_events',
    ] as $table) {
        InterfaceDB::execute('DROP TABLE IF EXISTS ' . $table);
    }

    InterfaceDB::execute('PRAGMA foreign_keys = ON');

    InterfaceDB::execute("CREATE TABLE swallowtail_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_name TEXT NOT NULL,
        event_slug TEXT NOT NULL UNIQUE,
        created_by_user_id INTEGER NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_api_upload_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_hash TEXT NOT NULL UNIQUE,
        token_label TEXT NOT NULL,
        created_by_user_id INTEGER NULL,
        can_upload_raw INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        last_used_at TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_api_upload_token_cidrs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_id INTEGER NOT NULL,
        cidr TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (token_id, cidr)
    )");

    InterfaceDB::execute("CREATE TABLE signup_token_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_ip TEXT NOT NULL UNIQUE,
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        window_started_at TEXT NULL,
        last_failed_at TEXT NULL,
        blocked_at TEXT NULL,
        block_expires_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_storage_locations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        location_label TEXT NOT NULL,
        root_path TEXT NOT NULL UNIQUE,
        reserve_bytes INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 100,
        is_active INTEGER NOT NULL DEFAULT 1,
        is_read_only INTEGER NOT NULL DEFAULT 0,
        is_full INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_filename TEXT NOT NULL,
        original_extension TEXT NOT NULL,
        original_bytes INTEGER NOT NULL,
        original_sha256 TEXT NOT NULL UNIQUE,
        original_quick_hash TEXT NULL,
        original_storage_path TEXT NOT NULL,
        storage_location_id INTEGER NULL,
        upload_state TEXT NOT NULL DEFAULT 'uploaded',
        conversion_state TEXT NOT NULL DEFAULT 'pending',
        uploaded_by_user_id INTEGER NULL,
        uploaded_via TEXT NOT NULL DEFAULT 'api',
        upload_token_id INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_event_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        photo_id INTEGER NOT NULL,
        assigned_by_user_id INTEGER NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (event_id, photo_id)
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_event_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        can_view INTEGER NOT NULL DEFAULT 0,
        can_download_single_jpeg INTEGER NOT NULL DEFAULT 0,
        can_download_event_zip INTEGER NOT NULL DEFAULT 0,
        can_download_all_accessible INTEGER NOT NULL DEFAULT 0,
        can_download_original_raw INTEGER NOT NULL DEFAULT 0,
        granted_by_user_id INTEGER NULL,
        expires_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (event_id, user_id)
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_photo_derivatives (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        derivative_type TEXT NOT NULL,
        storage_path TEXT NOT NULL,
        storage_location_id INTEGER NULL,
        bytes INTEGER NOT NULL,
        sha256 TEXT NULL,
        generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (photo_id, derivative_type)
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_photo_conversion_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        job_type TEXT NOT NULL,
        derivative_type TEXT NULL,
        input_path TEXT NULL,
        pp3_path TEXT NULL,
        output_path TEXT NULL,
        output_storage_path TEXT NULL,
        output_storage_location_id INTEGER NULL,
        output_width INTEGER NULL,
        output_height INTEGER NULL,
        profile_version INTEGER NOT NULL DEFAULT 1,
        requested_by_user_id INTEGER NULL,
        priority TEXT NOT NULL DEFAULT 'normal',
        status TEXT NOT NULL DEFAULT 'queued',
        attempts INTEGER NOT NULL DEFAULT 0,
        available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at TEXT NULL,
        locked_by TEXT NULL,
        last_error TEXT NULL,
        redis_notified_at TEXT NULL,
        started_at TEXT NULL,
        completed_at TEXT NULL,
        duration_seconds REAL NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE swallowtail_photo_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        event_id INTEGER NULL,
        actor_user_id INTEGER NULL,
        upload_token_id INTEGER NULL,
        action_type TEXT NOT NULL,
        details_json TEXT NULL,
        device_id TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
};

$swallowtailWriteRawFixture = static function (string $path, string $extension = 'cr2'): void {
    $bytes = $extension === 'cr2'
        ? "II*\0\x10\x00\x00\x00CR\2\0" . str_repeat('A', 128)
        : "\0\0\0\x18ftypcrx " . str_repeat('B', 128);

    file_put_contents($path, $bytes, LOCK_EX);
};

$swallowtailCreateSpiceBushUserSchema = static function (): void {
    InterfaceDB::execute('DROP TABLE IF EXISTS user_account_audit');
    InterfaceDB::execute('DROP TABLE IF EXISTS user_login_rate_limits');
    InterfaceDB::execute('DROP TABLE IF EXISTS user_totp');
    InterfaceDB::execute('DROP TABLE IF EXISTS users');
    InterfaceDB::execute("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        display_name TEXT NOT NULL,
        email_address TEXT NOT NULL,
        mobile_number TEXT NULL,
        password_hash TEXT NOT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        otp_required INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        role_id INTEGER NULL,
        current_session_token_hash TEXT NULL,
        current_session_started_at TEXT NULL,
        current_session_last_seen_at TEXT NULL,
        current_session_device_id TEXT NULL,
        current_session_ip_address TEXT NULL,
        current_session_user_agent TEXT NULL,
        current_session_browser_label TEXT NULL,
        last_login_at TEXT NULL,
        password_changed_at TEXT NULL,
        account_completed_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        account_status TEXT NOT NULL DEFAULT 'active'
    )");
    InterfaceDB::execute("CREATE TABLE user_totp (
        user_id INTEGER PRIMARY KEY,
        otp_secret TEXT NULL,
        pending_otp_secret TEXT NULL,
        pending_otp_algorithm TEXT NULL,
        pending_otp_digits INTEGER NULL,
        pending_otp_period INTEGER NULL,
        pending_otp_requested_at TEXT NULL,
        otp_algorithm TEXT NOT NULL DEFAULT 'SHA1',
        otp_digits INTEGER NOT NULL DEFAULT 6,
        otp_period INTEGER NOT NULL DEFAULT 30,
        otp_enabled INTEGER NOT NULL DEFAULT 0,
        otp_confirmed_at TEXT NULL,
        otp_last_used_timestep INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    InterfaceDB::execute("CREATE TABLE user_account_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        affected_user_id INTEGER NOT NULL,
        actor_user_id INTEGER NULL,
        action_type TEXT NOT NULL,
        reason TEXT NULL,
        details_json TEXT NULL,
        device_id TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    InterfaceDB::execute("CREATE TABLE user_login_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email_address TEXT NOT NULL,
        scope_type TEXT NOT NULL DEFAULT 'email',
        scope_key TEXT NULL,
        user_id INTEGER NULL,
        consecutive_failed_password_attempts INTEGER NOT NULL DEFAULT 0,
        failed_attempt_window_started_at TEXT NULL,
        last_failed_password_attempt_at TEXT NULL,
        next_allowed_login_at TEXT NULL,
        locked_at TEXT NULL,
        lock_reason TEXT NULL,
        lock_expires_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (scope_type, scope_key)
    )");
};

$harness->check(SwallowtailStorageService::class, 'stores originals outside web_root using checksum paths', function () use ($harness): void {
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-storage';
    $service = new SwallowtailStorageService($root);
    $sha256 = str_repeat('a', 64);
    $relative = $service->originalRelativePath($sha256, 'CR2');

    $harness->assertTrue(str_contains($relative, 'originals'));
    $harness->assertTrue(str_ends_with($relative, $sha256 . '.cr2'));
    $harness->assertTrue(!str_starts_with($service->storageRoot(), APP_ROOT));

    try {
        new SwallowtailStorageService(APP_ROOT . 'uploads');
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException('Storage service allowed a web_root storage path.');
});

$harness->check(SwallowtailPhotoIngestService::class, 'clamps RAW file limit to PHP upload limits', function () use ($harness, $swallowtailWriteRawFixture): void {
    $harness->assertSame(52428800, SwallowtailPhotoIngestService::phpIniBytes('50M'));
    $harness->assertSame(1073741824, SwallowtailPhotoIngestService::phpIniBytes('1G'));
    $harness->assertSame(null, SwallowtailPhotoIngestService::phpIniBytes('-1'));
    $harness->assertSame(null, SwallowtailPhotoIngestService::phpIniBytes('not-a-size'));

    $appLimit = 1024 * 1024 * 1024;
    $uploadLimited = new SwallowtailPhotoIngestService(
        appMaxRawBytes: $appLimit,
        phpUploadLimits: ['upload_max_filesize' => '50M', 'post_max_size' => '64M']
    );
    $postLimited = new SwallowtailPhotoIngestService(
        appMaxRawBytes: $appLimit,
        phpUploadLimits: ['upload_max_filesize' => '64M', 'post_max_size' => '50M']
    );
    $unlimited = new SwallowtailPhotoIngestService(
        appMaxRawBytes: $appLimit,
        phpUploadLimits: ['upload_max_filesize' => '-1', 'post_max_size' => 'not-a-size']
    );

    $harness->assertSame(52428800, $uploadLimited->maxRawBytes());
    $harness->assertSame(52428800, $postLimited->maxRawBytes());
    $harness->assertSame($appLimit, $unlimited->maxRawBytes());

    $source = tempnam(sys_get_temp_dir(), 'swallowtail-limit-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW limit fixture.');
    }

    try {
        $swallowtailWriteRawFixture($source, 'cr2');
        $smallLimit = new SwallowtailPhotoIngestService(
            appMaxRawBytes: 64,
            phpUploadLimits: ['upload_max_filesize' => '50M', 'post_max_size' => '64M']
        );
        $validation = $smallLimit->validateRawFile($source, 'IMG_LIMIT.CR2');

        $harness->assertTrue(empty($validation['valid']));
        $harness->assertTrue(in_array('RAW file exceeded the configured upload limit.', (array)($validation['errors'] ?? []), true));
    } finally {
        @unlink($source);
    }
});

$harness->check(SwallowtailPhotoIngestService::class, 'ingests RAW files as unassigned photos and queues conversion', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-ingest';
    (new SwallowtailStorageLocationService())->registerLocation('Primary test storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($root),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );

    $result = $ingest->ingestLocalRawFile($source, 'IMG_0001.CR2', ['uploaded_via' => 'api']);

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame('uploaded', $result['status']);
    $harness->assertTrue((int)$result['photo_id'] > 0);
    $harness->assertTrue(is_file((new SwallowtailStorageService($root))->absolutePath((string)$result['storage_path'])));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('swallowtail_photos', 'storage_location_id', ['id' => (int)$result['photo_id']]));
    $harness->assertSame(0, InterfaceDB::countWhere('swallowtail_event_photos', 'photo_id', (int)$result['photo_id']));
    $harness->assertSame(5, InterfaceDB::countWhere('swallowtail_photo_conversion_jobs', 'photo_id', (int)$result['photo_id']));
    $harness->assertSame(1, InterfaceDB::countWhere('swallowtail_photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'derivative_type' => 'embedded',
        'priority' => 'high',
    ]));
    $harness->assertSame(1, InterfaceDB::countWhere('swallowtail_photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'derivative_type' => 'preview',
        'priority' => 'high',
    ]));
    $harness->assertSame(1, InterfaceDB::countWhere('swallowtail_photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'derivative_type' => 'original_jpeg',
    ]));
    $harness->assertCount(5, (array)($result['conversion_jobs'] ?? []));
    $harness->assertTrue((int)(($result['conversion_jobs']['preview'] ?? [])['job_id'] ?? 0) > 0);
    $thumbnail = InterfaceDB::fetchOne(
        "SELECT output_width, output_height
         FROM swallowtail_photo_conversion_jobs
         WHERE photo_id = :photo_id
           AND derivative_type = 'thumbnail'
         LIMIT 1",
        ['photo_id' => (int)$result['photo_id']]
    );
    $harness->assertSame(512, (int)($thumbnail['output_width'] ?? 0));
    $harness->assertSame(512, (int)($thumbnail['output_height'] ?? 0));
    $harness->assertSame(1, InterfaceDB::countWhere('swallowtail_photo_audit', 'action_type', 'raw_uploaded'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoIngestService::class, 'rejects CR3 files while conversion is CR2-only', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-cr3-rejected';
    (new SwallowtailStorageLocationService())->registerLocation('Primary CR3 rejected storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr3');
    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($root),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0001.CR3', ['uploaded_via' => 'api']);

    $harness->assertTrue(empty($result['success']));
    $harness->assertTrue(str_contains(implode(' ', (array)($result['errors'] ?? [])), '.CR2'));
    $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_photos'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoIngestService::class, 'detects duplicate RAW uploads by checksum', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-duplicates';
    (new SwallowtailStorageLocationService())->registerLocation('Primary duplicate storage', $root);
    $first = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    $second = tempnam(sys_get_temp_dir(), 'swallowtail-test-');

    if (!is_string($first) || !is_string($second)) {
        throw new RuntimeException('Unable to create RAW fixtures.');
    }

    $swallowtailWriteRawFixture($first, 'cr2');
    copy($first, $second);

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($root),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );

    $created = $ingest->ingestLocalRawFile($first, 'IMG_0002.CR2');
    $duplicate = $ingest->ingestLocalRawFile($second, 'RENAMED.CR2');

    $harness->assertTrue(!empty($created['success']));
    $harness->assertTrue(!empty($duplicate['success']));
    $harness->assertSame('duplicate', $duplicate['status']);
    $harness->assertSame((int)$created['photo_id'], (int)$duplicate['photo_id']);
    $harness->assertSame(1, InterfaceDB::tableRowCount('swallowtail_photos'));
    $harness->assertSame(1, InterfaceDB::countWhere('swallowtail_photo_audit', 'action_type', 'raw_duplicate_detected'));

    @unlink($first);
    @unlink($second);
});

$harness->check(SwallowtailQuickChecksumApiService::class, 'reports whether a CR2 quick checksum already exists', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('Checksum token', null, null, ['203.0.113.0/24']);
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-checksum';
    (new SwallowtailStorageLocationService())->registerLocation('Primary checksum storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $result = (new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($root),
        $library,
        new SwallowtailConversionQueueService()
    ))->ingestLocalRawFile($source, 'IMG_0009.CR2');

    $quickHash = (string)($result['quick_hash'] ?? '');
    $sourceBytes = (int)filesize($source);
    $harness->assertSame(16, strlen($quickHash));
    $harness->assertTrue($library->photoByQuickHash($quickHash, $sourceBytes) !== null);

    $service = new SwallowtailQuickChecksumApiService($library);
    $foundRequest = new RequestFramework(
        ['hash' => $quickHash, 'size_bytes' => (string)$sourceBytes],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        ['Authorization' => 'Bearer ' . $token['token']],
        null,
        []
    );
    $foundPayload = json_decode($service->handleCheck($foundRequest)->body(), true);

    $harness->assertTrue(is_array($foundPayload));
    $harness->assertTrue(!empty($foundPayload['success']));
    $harness->assertTrue(!empty($foundPayload['exists']));
    $harness->assertSame('fnv1a64', (string)($foundPayload['algorithm'] ?? ''));
    $harness->assertSame((int)$result['photo_id'], (int)($foundPayload['photo_id'] ?? 0));

    $missingRequest = new RequestFramework(
        ['hash' => $quickHash, 'size_bytes' => (string)($sourceBytes + 1)],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        ['Authorization' => 'Bearer ' . $token['token']],
        null,
        []
    );
    $missingPayload = json_decode($service->handleCheck($missingRequest)->body(), true);

    $harness->assertTrue(is_array($missingPayload));
    $harness->assertTrue(!empty($missingPayload['success']));
    $harness->assertTrue(empty($missingPayload['exists']));

    $badAlgorithmRequest = new RequestFramework(
        ['algorithm' => 'crc32', 'hash' => $quickHash],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        ['Authorization' => 'Bearer ' . $token['token']],
        null,
        []
    );
    $badAlgorithmPayload = json_decode($service->handleCheck($badAlgorithmRequest)->body(), true);

    $harness->assertTrue(is_array($badAlgorithmPayload));
    $harness->assertTrue(empty($badAlgorithmPayload['success']));
    $harness->assertTrue(str_contains(implode(' ', (array)($badAlgorithmPayload['errors'] ?? [])), 'Unsupported quick checksum algorithm'));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('swallowtail_api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));

    @unlink($source);
});

$harness->check(SwallowtailPingApiService::class, 'keeps token diagnostics out of ping responses and records them in account audit', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    InterfaceDB::prepareExecute(
        "INSERT INTO users (
            id,
            display_name,
            email_address,
            password_hash,
            otp_required,
            is_active,
            account_status
        ) VALUES (
            :id,
            :display_name,
            :email_address,
            :password_hash,
            0,
            1,
            'active'
        )",
        [
            'id' => 44,
            'display_name' => 'Token Account',
            'email_address' => 'token-account@example.test',
            'password_hash' => 'not-used',
        ]
    );

    $library = new SwallowtailPhotoLibraryService();
    $allowedToken = $library->createUploadToken('Allowed bridge', 44, null, ['203.0.113.0/24']);
    $blockedToken = $library->createUploadToken('Blocked bridge', 44, null, ['198.51.100.0/24']);
    $listedTokens = $library->listUploadTokens();
    $harness->assertSame('Token Account', (string)($listedTokens[0]['created_by_user_label'] ?? ''));
    $harness->assertSame('token-account@example.test', (string)($listedTokens[0]['created_by_user_email_address'] ?? ''));
    $service = new SwallowtailPingApiService($library);

    $successRequest = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        [
            'Authorization' => 'Bearer ' . $allowedToken['token'],
            'User-Agent' => 'spicebush-test',
            'X-Swallowtail-Device-ID' => 'bridge-a',
        ],
        null,
        []
    );
    $successPayload = json_decode($service->handlePing($successRequest)->body(), true);

    $harness->assertTrue(is_array($successPayload));
    $harness->assertTrue(!empty($successPayload['success']));
    $harness->assertTrue(!empty($successPayload['pong']));

    $failureRequest = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        [
            'Authorization' => 'Bearer ' . $blockedToken['token'],
            'User-Agent' => 'spicebush-test',
            'X-Swallowtail-Device-ID' => 'bridge-b',
        ],
        null,
        []
    );
    $failureResponse = $service->handlePing($failureRequest);
    $failurePayload = json_decode($failureResponse->body(), true);

    $harness->assertTrue(is_array($failurePayload));
    $harness->assertTrue(empty($failurePayload['success']));
    $harness->assertSame('Bearer upload token was rejected.', (string)(($failurePayload['errors'] ?? [])[0] ?? ''));

    $auditRows = (new UserHistoryStore())->fetchRecentAccountAudit(10);
    $harness->assertSame(2, count($auditRows));
    $harness->assertSame('upload_token_ping_failed', (string)($auditRows[0]['action_type'] ?? ''));
    $harness->assertSame('Token Account', (string)($auditRows[0]['affected_user_display_name'] ?? ''));
    $harness->assertTrue(str_contains((string)($auditRows[0]['reason'] ?? ''), 'not allowed from this network'));
    $harness->assertTrue(str_contains((string)($auditRows[0]['details_json'] ?? ''), 'Blocked bridge'));
    $harness->assertSame('upload_token_ping_succeeded', (string)($auditRows[1]['action_type'] ?? ''));
    $harness->assertTrue(str_contains((string)($auditRows[1]['details_json'] ?? ''), 'Allowed bridge'));

    $tokenLockoutRow = InterfaceDB::fetchOne(
        'SELECT failed_attempts FROM signup_token_rate_limits WHERE client_ip = :client_ip LIMIT 1',
        ['client_ip' => '203.0.113.15']
    );
    $harness->assertTrue(is_array($tokenLockoutRow));
    $harness->assertSame(1, (int)($tokenLockoutRow['failed_attempts'] ?? 0));
});

$harness->check(SwallowtailPingApiService::class, 'locks out repeated unknown upload token failures by client IP', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $clientIp = '203.0.113.241';
    $pingService = new SwallowtailPingApiService();

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $request = new RequestFramework(
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => $clientIp],
            [],
            ['Authorization' => 'Bearer missing-token-' . $attempt],
            null,
            []
        );
        $payload = json_decode($pingService->handlePing($request)->body(), true);

        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(empty($payload['success']));
        $harness->assertSame('Bearer upload token was rejected.', (string)(($payload['errors'] ?? [])[0] ?? ''));
    }

    $lockedRequest = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => $clientIp],
        [],
        ['Authorization' => 'Bearer missing-token-5'],
        null,
        []
    );
    $lockedPayload = json_decode($pingService->handlePing($lockedRequest)->body(), true);

    $harness->assertTrue(is_array($lockedPayload));
    $harness->assertTrue(empty($lockedPayload['success']));
    $harness->assertSame('Too many invalid token attempts. Please try again later.', (string)(($lockedPayload['errors'] ?? [])[0] ?? ''));

    $activeBlocks = (new SignupTokenRateLimitService())->activeBlocks();
    $harness->assertTrue(in_array($clientIp, array_map(static fn(array $row): string => (string)($row['client_ip'] ?? ''), $activeBlocks), true));

    $blockedChecksumRequest = new RequestFramework(
        ['algorithm' => 'sha256'],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => $clientIp],
        [],
        ['Authorization' => 'Bearer missing-token-6'],
        null,
        []
    );
    $blockedChecksumPayload = json_decode((new SwallowtailQuickChecksumApiService())->handleCheck($blockedChecksumRequest)->body(), true);

    $harness->assertTrue(is_array($blockedChecksumPayload));
    $harness->assertTrue(empty($blockedChecksumPayload['success']));
    $harness->assertSame('Too many invalid token attempts. Please try again later.', (string)(($blockedChecksumPayload['errors'] ?? [])[0] ?? ''));
});

$harness->check(SwallowtailSpiceBushRegistrationApiService::class, 'creates an upload token for a user allowed to manage upload tokens', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    $configPath = AppConfigurationStore::configPath();
    $originalConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';
    $securityPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'spicebush-register-' . bin2hex(random_bytes(6)) . '.keys';
    $auth = new UserAuthenticationService($securityPath, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => 'https://swallowtail.example.test',
            'trusted_proxy_ips' => [],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $created = $auth->createUser('SpiceBush Admin', 'spicebush-admin@example.test', 'SpiceBush Pass 1!', true);
        $userId = (int)($created['user_id'] ?? 0);
        UserAuthenticationService::forgetUserByIdCache($userId);
        InterfaceDB::prepareExecute(
            'UPDATE users SET role_id = :role_id WHERE id = :id',
            ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID, 'id' => $userId]
        );
        UserAuthenticationService::forgetUserByIdCache($userId);

        $request = new RequestFramework(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'HTTPS' => 'on', 'HTTP_HOST' => 'swallowtail.example.test'],
            [],
            ['Content-Type' => 'application/json', 'Host' => 'swallowtail.example.test'],
            json_encode([
                'username' => 'spicebush-admin@example.test',
                'password' => 'SpiceBush Pass 1!',
                'device_id' => 'spicebush-test-rig',
            ], JSON_THROW_ON_ERROR),
            []
        );
        $service = new SwallowtailSpiceBushRegistrationApiService(
            $auth,
            new SwallowtailPhotoLibraryService(),
            new CardAccessFramework(new RoleRepository(), $auth)
        );
        $payload = json_decode($service->handleRegister($request)->body(), true);

        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(!empty($payload['success']));
        $harness->assertTrue(str_starts_with((string)($payload['token'] ?? ''), 'stup_'));
        $harness->assertSame('https://swallowtail.example.test/api', (string)($payload['api_url'] ?? ''));
        $harness->assertSame(['203.0.113.15/32'], (array)($payload['cidrs'] ?? []));
        $harness->assertTrue((new SwallowtailPhotoLibraryService())->authenticateUploadToken((string)$payload['token'], '203.0.113.15') !== null);
    } finally {
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
        if ($originalConfig !== '') {
            file_put_contents($configPath, $originalConfig);
            AppConfigurationStore::config(true);
        }
    }
});

$harness->check(SwallowtailSpiceBushRegistrationApiService::class, 'rejects registration when API URL cannot be trusted', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    $configPath = AppConfigurationStore::configPath();
    $originalConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';
    $securityPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'spicebush-register-untrusted-url-' . bin2hex(random_bytes(6)) . '.keys';
    $auth = new UserAuthenticationService($securityPath, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => [],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $created = $auth->createUser('SpiceBush Admin', 'spicebush-untrusted-url@example.test', 'SpiceBush Pass 1!', true);
        $userId = (int)($created['user_id'] ?? 0);
        UserAuthenticationService::forgetUserByIdCache($userId);
        InterfaceDB::prepareExecute(
            'UPDATE users SET role_id = :role_id WHERE id = :id',
            ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID, 'id' => $userId]
        );
        UserAuthenticationService::forgetUserByIdCache($userId);

        $request = new RequestFramework(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'HTTPS' => 'on', 'HTTP_HOST' => 'swallowtail.example.test'],
            [],
            ['Content-Type' => 'application/json', 'Host' => 'swallowtail.example.test'],
            json_encode([
                'username' => 'spicebush-untrusted-url@example.test',
                'password' => 'SpiceBush Pass 1!',
                'device_id' => 'spicebush-test-rig',
            ], JSON_THROW_ON_ERROR),
            []
        );
        $service = new SwallowtailSpiceBushRegistrationApiService(
            $auth,
            new SwallowtailPhotoLibraryService(),
            new CardAccessFramework(new RoleRepository(), $auth)
        );
        $response = $service->handleRegister($request);
        $payload = json_decode($response->body(), true);

        $harness->assertSame(503, $response->statusCode());
        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(empty($payload['success']));
        $harness->assertTrue(str_contains(implode(' ', (array)($payload['errors'] ?? [])), 'External Base Web URL'));
        $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_api_upload_tokens'));
    } finally {
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
        if ($originalConfig !== '') {
            file_put_contents($configPath, $originalConfig);
            AppConfigurationStore::config(true);
        }
    }
});

$harness->check(SwallowtailSpiceBushRegistrationApiService::class, 'uses forwarded API URL only from trusted reverse proxies', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    $configPath = AppConfigurationStore::configPath();
    $originalConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';
    $securityPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'spicebush-register-trusted-proxy-' . bin2hex(random_bytes(6)) . '.keys';
    $auth = new UserAuthenticationService($securityPath, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => ['198.51.100.10'],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $created = $auth->createUser('SpiceBush Admin', 'spicebush-trusted-proxy@example.test', 'SpiceBush Pass 1!', true);
        $userId = (int)($created['user_id'] ?? 0);
        UserAuthenticationService::forgetUserByIdCache($userId);
        InterfaceDB::prepareExecute(
            'UPDATE users SET role_id = :role_id WHERE id = :id',
            ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID, 'id' => $userId]
        );
        UserAuthenticationService::forgetUserByIdCache($userId);

        $request = new RequestFramework(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '198.51.100.10', 'HTTP_HOST' => 'internal.invalid'],
            [],
            [
                'Content-Type' => 'application/json',
                'Host' => 'internal.invalid',
                'X-Forwarded-Host' => 'swallowtail.example.test:8443',
                'X-Forwarded-Proto' => 'https',
            ],
            json_encode([
                'username' => 'spicebush-trusted-proxy@example.test',
                'password' => 'SpiceBush Pass 1!',
                'device_id' => 'spicebush-test-rig',
                'cidrs' => ['203.0.113.15/32'],
            ], JSON_THROW_ON_ERROR),
            []
        );
        $service = new SwallowtailSpiceBushRegistrationApiService(
            $auth,
            new SwallowtailPhotoLibraryService(),
            new CardAccessFramework(new RoleRepository(), $auth)
        );
        $payload = json_decode($service->handleRegister($request)->body(), true);

        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(!empty($payload['success']));
        $harness->assertSame('https://swallowtail.example.test:8443/api', (string)($payload['api_url'] ?? ''));
        $harness->assertTrue(str_starts_with((string)($payload['token'] ?? ''), 'stup_'));
        $harness->assertSame(1, InterfaceDB::tableRowCount('swallowtail_api_upload_tokens'));
    } finally {
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
        if ($originalConfig !== '') {
            file_put_contents($configPath, $originalConfig);
            AppConfigurationStore::config(true);
        }
    }
});

$harness->check(SwallowtailSpiceBushRegistrationApiService::class, 'records failed registration passwords in the login rate limit table', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    $securityPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'spicebush-register-rate-limit-' . bin2hex(random_bytes(6)) . '.keys';
    $auth = new UserAuthenticationService($securityPath, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        $created = $auth->createUser('SpiceBush Admin', 'spicebush-rate-limit@example.test', 'SpiceBush Pass 1!', true);
        $userId = (int)($created['user_id'] ?? 0);
        UserAuthenticationService::forgetUserByIdCache($userId);
        InterfaceDB::prepareExecute(
            'UPDATE users SET role_id = :role_id WHERE id = :id',
            ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID, 'id' => $userId]
        );
        UserAuthenticationService::forgetUserByIdCache($userId);

        $service = new SwallowtailSpiceBushRegistrationApiService(
            $auth,
            new SwallowtailPhotoLibraryService(),
            new CardAccessFramework(new RoleRepository(), $auth)
        );

        $payload = [];
        for ($attempt = 0; $attempt < 3; $attempt += 1) {
            $request = new RequestFramework(
                [],
                [],
                ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'HTTP_HOST' => 'swallowtail.example.test'],
                [],
                ['Content-Type' => 'application/json', 'Host' => 'swallowtail.example.test'],
                json_encode([
                    'username' => 'spicebush-rate-limit@example.test',
                    'password' => 'wrong-password',
                    'device_id' => 'spicebush-test-rig',
                ], JSON_THROW_ON_ERROR),
                []
            );
            $payload = json_decode($service->handleRegister($request)->body(), true);
        }

        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(empty($payload['success']));
        $harness->assertTrue(str_contains(implode(' ', (array)($payload['errors'] ?? [])), 'Please wait'));
        $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_api_upload_tokens'));

        $emailLimit = InterfaceDB::fetchOne(
            "SELECT consecutive_failed_password_attempts, user_id, next_allowed_login_at
             FROM user_login_rate_limits
             WHERE scope_type = 'email'
               AND scope_key = :scope_key
             LIMIT 1",
            ['scope_key' => 'spicebush-rate-limit@example.test']
        );

        $harness->assertTrue(is_array($emailLimit));
        $harness->assertSame(3, (int)($emailLimit['consecutive_failed_password_attempts'] ?? 0));
        $harness->assertSame($userId, (int)($emailLimit['user_id'] ?? 0));
        $harness->assertTrue(trim((string)($emailLimit['next_allowed_login_at'] ?? '')) !== '');
    } finally {
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
    }
});

$harness->check(SwallowtailSpiceBushRegistrationApiService::class, 'requires OTP for upload token registration when enabled', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    $securityPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'spicebush-register-otp-missing-' . bin2hex(random_bytes(6)) . '.keys';
    $auth = new UserAuthenticationService($securityPath, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        $created = $auth->createUser('SpiceBush OTP Admin', 'spicebush-otp-missing@example.test', 'SpiceBush Pass 1!', true);
        $userId = (int)($created['user_id'] ?? 0);
        UserAuthenticationService::forgetUserByIdCache($userId);
        InterfaceDB::prepareExecute(
            'UPDATE users SET role_id = :role_id WHERE id = :id',
            ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID, 'id' => $userId]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO user_totp (
                user_id,
                otp_secret,
                otp_algorithm,
                otp_digits,
                otp_period,
                otp_enabled,
                otp_confirmed_at,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :otp_secret,
                :otp_algorithm,
                :otp_digits,
                :otp_period,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )',
            [
                'user_id' => $userId,
                'otp_secret' => 'JBSWY3DPEHPK3PXP',
                'otp_algorithm' => 'SHA1',
                'otp_digits' => 6,
                'otp_period' => 30,
            ]
        );
        UserAuthenticationService::forgetUserByIdCache($userId);

        $request = new RequestFramework(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'HTTP_HOST' => 'swallowtail.example.test'],
            [],
            ['Content-Type' => 'application/json', 'Host' => 'swallowtail.example.test'],
            json_encode([
                'username' => 'spicebush-otp-missing@example.test',
                'password' => 'SpiceBush Pass 1!',
                'otp_code' => '',
                'device_id' => 'spicebush-test-rig',
            ], JSON_THROW_ON_ERROR),
            []
        );
        $service = new SwallowtailSpiceBushRegistrationApiService(
            $auth,
            new SwallowtailPhotoLibraryService(),
            new CardAccessFramework(new RoleRepository(), $auth)
        );
        $payload = json_decode($service->handleRegister($request)->body(), true);

        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(empty($payload['success']));
        $harness->assertTrue(str_contains(implode(' ', (array)($payload['errors'] ?? [])), 'OTP'));
        $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_api_upload_tokens'));
    } finally {
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
    }
});

$harness->check(SwallowtailSpiceBushRegistrationApiService::class, 'accepts valid OTP for upload token registration when enabled', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    $configPath = AppConfigurationStore::configPath();
    $originalConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';
    $securityPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'spicebush-register-otp-valid-' . bin2hex(random_bytes(6)) . '.keys';
    $auth = new UserAuthenticationService($securityPath, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => 'https://swallowtail.example.test',
            'trusted_proxy_ips' => [],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $created = $auth->createUser('SpiceBush OTP Admin', 'spicebush-otp-valid@example.test', 'SpiceBush Pass 1!', true);
        $userId = (int)($created['user_id'] ?? 0);
        UserAuthenticationService::forgetUserByIdCache($userId);
        $otpSecret = 'JBSWY3DPEHPK3PXP';
        $verificationService = new OtpVerificationService();
        $otpCode = $verificationService->generateCodeForTimestep(
            6,
            'SHA1',
            $otpSecret,
            $verificationService->currentTimestep(time(), 30)
        );

        InterfaceDB::prepareExecute(
            'UPDATE users SET role_id = :role_id WHERE id = :id',
            ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID, 'id' => $userId]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO user_totp (
                user_id,
                otp_secret,
                otp_algorithm,
                otp_digits,
                otp_period,
                otp_enabled,
                otp_confirmed_at,
                created_at,
                updated_at
            ) VALUES (
                :user_id,
                :otp_secret,
                :otp_algorithm,
                :otp_digits,
                :otp_period,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )',
            [
                'user_id' => $userId,
                'otp_secret' => $otpSecret,
                'otp_algorithm' => 'SHA1',
                'otp_digits' => 6,
                'otp_period' => 30,
            ]
        );
        UserAuthenticationService::forgetUserByIdCache($userId);

        $request = new RequestFramework(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'HTTP_HOST' => 'swallowtail.example.test'],
            [],
            ['Content-Type' => 'application/json', 'Host' => 'swallowtail.example.test'],
            json_encode([
                'username' => 'spicebush-otp-valid@example.test',
                'password' => 'SpiceBush Pass 1!',
                'otp_code' => $otpCode,
                'device_id' => 'spicebush-test-rig',
            ], JSON_THROW_ON_ERROR),
            []
        );
        $service = new SwallowtailSpiceBushRegistrationApiService(
            $auth,
            new SwallowtailPhotoLibraryService(),
            new CardAccessFramework(new RoleRepository(), $auth)
        );
        $payload = json_decode($service->handleRegister($request)->body(), true);

        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(!empty($payload['success']));
        $harness->assertTrue(str_starts_with((string)($payload['token'] ?? ''), 'stup_'));
        $harness->assertSame(1, InterfaceDB::tableRowCount('swallowtail_api_upload_tokens'));
    } finally {
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
        if ($originalConfig !== '') {
            file_put_contents($configPath, $originalConfig);
            AppConfigurationStore::config(true);
        }
    }
});

$harness->check(SwallowtailSpiceBushRegistrationApiService::class, 'rejects valid credentials without upload token card access', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    $securityPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'spicebush-denied-' . bin2hex(random_bytes(6)) . '.keys';
    $auth = new UserAuthenticationService($securityPath, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        $created = $auth->createUser('SpiceBush Viewer', 'spicebush-viewer@example.test', 'SpiceBush Pass 1!', true);
        UserAuthenticationService::forgetUserByIdCache((int)($created['user_id'] ?? 0));
        $request = new RequestFramework(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'HTTP_HOST' => 'swallowtail.example.test'],
            [],
            ['Content-Type' => 'application/json', 'Host' => 'swallowtail.example.test'],
            json_encode([
                'username' => 'spicebush-viewer@example.test',
                'password' => 'SpiceBush Pass 1!',
            ], JSON_THROW_ON_ERROR),
            []
        );
        $service = new SwallowtailSpiceBushRegistrationApiService(
            $auth,
            new SwallowtailPhotoLibraryService(),
            new CardAccessFramework(new RoleRepository(), $auth)
        );
        $payload = json_decode($service->handleRegister($request)->body(), true);

        $harness->assertTrue(is_array($payload));
        $harness->assertTrue(empty($payload['success']));
        $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_api_upload_tokens'));
    } finally {
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
    }
});

$harness->check(SwallowtailEventAccessService::class, 'keeps event access least privilege until granted', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-access';
    (new SwallowtailStorageLocationService())->registerLocation('Primary access storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService($root), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0003.CR2');
    $event = $library->createEvent('Private Wedding');
    $access = new SwallowtailEventAccessService();

    $harness->assertTrue(!$access->userCanViewPhoto(101, (int)$result['photo_id']));

    $library->assignPhotoToEvent((int)$result['photo_id'], (int)$event['id']);
    $harness->assertTrue(!$access->userCanViewPhoto(101, (int)$result['photo_id']));

    $library->grantEventPermission((int)$event['id'], 101, [
        'can_view' => true,
        'can_download_single_jpeg' => true,
        'can_download_original_raw' => false,
    ]);

    $harness->assertTrue($access->userCanSeeEvent(101, (int)$event['id']));
    $harness->assertTrue($access->userCanViewPhoto(101, (int)$result['photo_id']));
    $harness->assertTrue($access->userCanDownloadSingleJpeg(101, (int)$event['id']));
    $harness->assertTrue(!$access->userCanDownloadOriginalRaw(101, (int)$event['id']));

    @unlink($source);
});

$harness->check(SwallowtailImageServeService::class, 'resolves only authorised private derivative images', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-image-serve';
    (new SwallowtailStorageLocationService())->registerLocation('Primary image serve storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService($root);
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0008.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $previewStoragePath = $storage->derivativeRelativePath($sha256, 'preview');
    $storage->ensureDirectoryForRelativePath($previewStoragePath);
    $previewPath = $storage->absolutePath($previewStoragePath);
    file_put_contents($previewPath, "\xFF\xD8\xFF\xD9", LOCK_EX);

    InterfaceDB::prepareExecute(
        "INSERT INTO swallowtail_photo_derivatives (
            photo_id,
            derivative_type,
            storage_path,
            storage_location_id,
            bytes,
            sha256
        ) VALUES (
            :photo_id,
            'preview',
            :storage_path,
            :storage_location_id,
            :bytes,
            :sha256
        )",
        [
            'photo_id' => $photoId,
            'storage_path' => $previewStoragePath,
            'storage_location_id' => $photo['storage_location_id'] ?? null,
            'bytes' => filesize($previewPath),
            'sha256' => hash_file('sha256', $previewPath),
        ]
    );

    $event = $library->createEvent('Private Gallery');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $service = new SwallowtailImageServeService();

    $harness->assertSame(null, $service->derivativeImage($photoId, 'preview', 202));

    $library->grantEventPermission((int)$event['id'], 202, ['can_view' => true]);
    $image = $service->derivativeImage($photoId, 'preview', 202);

    $harness->assertTrue(is_array($image));
    $harness->assertSame($previewPath, (string)$image['absolute_path']);
    $harness->assertSame('image/jpeg', (string)$image['content_type']);
    $harness->assertTrue(str_contains((string)$image['etag'], '"'));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'normalises preview edit settings and writes expected PP3', function () use ($harness): void {
    $service = new SwallowtailPreviewProfileService();
    $settings = $service->normaliseSettings([
        'crop' => [
            'x' => -20,
            'y' => 490,
            'width' => 700,
            'height' => 100,
        ],
        'exposure' => [
            'black' => -120,
            'lightness' => 25.5,
            'contrast' => 135,
            'saturation' => -12.25,
        ],
    ], 600, 500);
    $content = $service->pp3Content($settings, 1600);

    $harness->assertSame(0, (int)$settings['crop']['x']);
    $harness->assertSame(490, (int)$settings['crop']['y']);
    $harness->assertSame(600, (int)$settings['crop']['width']);
    $harness->assertSame(10, (int)$settings['crop']['height']);
    $harness->assertSame(-100.0, (float)$settings['exposure']['black']);
    $harness->assertSame(100.0, (float)$settings['exposure']['contrast']);
    $harness->assertTrue(str_contains($content, "[Exposure]\nAuto=false\nBlack=-100\nBrightness=25.5\nContrast=100\nSaturation=-12.25"));
    $harness->assertTrue(str_contains($content, "[Crop]\nEnabled=true\nX=0\nY=490\nW=600\nH=10"));
    $harness->assertTrue(str_contains($content, "[Resize]\nEnabled=true"));
    $harness->assertTrue(str_contains($content, "AppliesTo=Cropped area\nMethod=Lanczos"));
    $harness->assertTrue(str_contains($content, "Width=1600\nHeight=1600"));
});

$harness->check(SwallowtailPreviewProfileService::class, 'queues authorised PP3 preview refresh outside web root', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-preview-profile-' . bin2hex(random_bytes(6));
    (new SwallowtailStorageLocationService())->registerLocation('Primary preview profile storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService($root), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0009.CR2');
    $photoId = (int)$result['photo_id'];
    $event = $library->createEvent('Preview Edit Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, ['can_view' => true]);

    $service = new SwallowtailPreviewProfileService();
    $denied = $service->enqueuePreview($photoId, 404, [
        'crop' => ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 120],
        'exposure' => ['black' => 1, 'lightness' => 2, 'contrast' => 3, 'saturation' => 4],
    ]);
    $queued = $service->enqueuePreview($photoId, 303, [
        'crop' => ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 120],
        'exposure' => ['black' => 1, 'lightness' => 2, 'contrast' => 3, 'saturation' => 4],
    ]);

    $harness->assertTrue(empty($denied['success']));
    $harness->assertTrue(!empty($queued['success']));
    $harness->assertSame(2, (int)($queued['profile_version'] ?? 0));
    $harness->assertTrue((int)($queued['job_id'] ?? 0) > 0);
    $harness->assertTrue(str_contains((string)($queued['preview_url'] ?? ''), 'v=2'));
    $harness->assertTrue(str_contains((string)($queued['status_url'] ?? ''), 'profile_version=2'));

    $job = InterfaceDB::fetchOne(
        "SELECT pp3_path, profile_version, requested_by_user_id, priority, output_width, output_height
         FROM swallowtail_photo_conversion_jobs
         WHERE id = :id",
        ['id' => (int)$queued['job_id']]
    );

    $harness->assertTrue(is_array($job));
    $profilePath = (string)($job['pp3_path'] ?? '');
    $harness->assertSame(2, (int)($job['profile_version'] ?? 0));
    $harness->assertSame(303, (int)($job['requested_by_user_id'] ?? 0));
    $harness->assertSame('high', (string)($job['priority'] ?? ''));
    $harness->assertSame(1600, (int)($job['output_width'] ?? 0));
    $harness->assertSame(1600, (int)($job['output_height'] ?? 0));
    $harness->assertTrue($profilePath !== '');
    $harness->assertTrue(is_file($profilePath));
    $harness->assertTrue(!str_starts_with($profilePath, APP_ROOT));
    $harness->assertTrue(str_contains($profilePath, DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR));
    $harness->assertTrue(str_contains((string)file_get_contents($profilePath), "[Crop]\nEnabled=true\nX=10\nY=20\nW=100\nH=120"));

    InterfaceDB::prepareExecute(
        "UPDATE swallowtail_photo_conversion_jobs
         SET status = 'succeeded'
         WHERE id = :id",
        ['id' => (int)$queued['job_id']]
    );
    $status = $service->previewStatus($photoId, (int)$queued['job_id'], 2, 303);
    $harness->assertTrue(!empty($status['success']));
    $harness->assertSame('succeeded', (string)($status['status'] ?? ''));
    $harness->assertTrue(str_contains((string)($status['preview_url'] ?? ''), 'job_id=' . (string)$queued['job_id']));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'accepts token authenticated multipart RAW uploads', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api';
    (new SwallowtailStorageLocationService())->registerLocation('Primary API storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');

    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        ['Authorization' => 'Bearer ' . $token['token'], 'User-Agent' => 'swallowtail-test'],
        null,
        []
    );

    $service = new SwallowtailRawUploadApiService(
        new SwallowtailPhotoIngestService(new SwallowtailStorageService($root), $library, new SwallowtailConversionQueueService()),
        $library
    );

    $response = $service->handleUpload($request, [
        'raw_file' => [
            'tmp_name' => $source,
            'name' => 'IMG_0004.CR2',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ],
    ]);

    $payload = json_decode($response->body(), true);

    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(!empty($payload['success']));
    $harness->assertSame('uploaded', $payload['status'] ?? null);
    $harness->assertCount(4, (array)($payload['conversion_jobs'] ?? []));
    $harness->assertTrue((int)(($payload['conversion_jobs']['thumbnail'] ?? [])['job_id'] ?? 0) > 0);
    $harness->assertSame(1, InterfaceDB::tableRowCount('swallowtail_photos'));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('swallowtail_api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'rejects raw body uploads when content length exceeds RAW limit', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api-content-length';
    (new SwallowtailStorageLocationService())->registerLocation('Primary API content length storage', $root);

    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'CONTENT_LENGTH' => '65'],
        [],
        [
            'Authorization' => 'Bearer ' . $token['token'],
            'X-Swallowtail-Filename' => 'IMG_0004.CR2',
            'User-Agent' => 'swallowtail-test',
        ],
        null,
        []
    );

    $service = new SwallowtailRawUploadApiService(
        new SwallowtailPhotoIngestService(
            new SwallowtailStorageService($root),
            $library,
            new SwallowtailConversionQueueService(),
            64,
            ['upload_max_filesize' => '50M', 'post_max_size' => '64M']
        ),
        $library
    );
    $missingInput = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-swallowtail-raw-input';
    $response = $service->handleUpload($request, [], $missingInput);
    $payload = json_decode($response->body(), true);

    $harness->assertSame(413, $response->statusCode());
    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(empty($payload['success']));
    $harness->assertSame('RAW upload exceeded the configured size limit.', (string)(($payload['errors'] ?? [])[0] ?? ''));
    $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_photos'));
});

$harness->check(SwallowtailRawUploadApiService::class, 'stops raw body streaming when RAW limit is exceeded', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api-stream-limit';
    (new SwallowtailStorageLocationService())->registerLocation('Primary API stream limit storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-stream-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'CONTENT_LENGTH' => '64'],
        [],
        [
            'Authorization' => 'Bearer ' . $token['token'],
            'X-Swallowtail-Filename' => 'IMG_0004.CR2',
            'User-Agent' => 'swallowtail-test',
        ],
        null,
        []
    );

    $service = new SwallowtailRawUploadApiService(
        new SwallowtailPhotoIngestService(
            new SwallowtailStorageService($root),
            $library,
            new SwallowtailConversionQueueService(),
            64,
            ['upload_max_filesize' => '50M', 'post_max_size' => '64M']
        ),
        $library
    );
    $temporaryPattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'swallowtail-raw-*';
    $before = glob($temporaryPattern) ?: [];
    sort($before);
    $response = $service->handleUpload($request, [], $source);
    $after = glob($temporaryPattern) ?: [];
    sort($after);
    $payload = json_decode($response->body(), true);

    $harness->assertSame(413, $response->statusCode());
    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(empty($payload['success']));
    $harness->assertSame('RAW upload exceeded the configured size limit.', (string)(($payload['errors'] ?? [])[0] ?? ''));
    $harness->assertSame($before, $after);
    $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_photos'));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'accepts token authenticated raw body uploads under RAW limit', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api-raw-body';
    (new SwallowtailStorageLocationService())->registerLocation('Primary API raw body storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-body-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'CONTENT_LENGTH' => (string)filesize($source)],
        [],
        [
            'Authorization' => 'Bearer ' . $token['token'],
            'X-Swallowtail-Filename' => 'IMG_0004.CR2',
            'User-Agent' => 'swallowtail-test',
        ],
        null,
        []
    );

    $service = new SwallowtailRawUploadApiService(
        new SwallowtailPhotoIngestService(
            new SwallowtailStorageService($root),
            $library,
            new SwallowtailConversionQueueService(),
            4096,
            ['upload_max_filesize' => '50M', 'post_max_size' => '64M']
        ),
        $library
    );
    $response = $service->handleUpload($request, [], $source);
    $payload = json_decode($response->body(), true);

    $harness->assertSame(201, $response->statusCode());
    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(!empty($payload['success']));
    $harness->assertSame('uploaded', $payload['status'] ?? null);
    $harness->assertSame(1, InterfaceDB::tableRowCount('swallowtail_photos'));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('swallowtail_api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'rejects multipart RAW uploads over effective RAW limit', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api-multipart-limit';
    (new SwallowtailStorageLocationService())->registerLocation('Primary API multipart limit storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-multipart-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        ['Authorization' => 'Bearer ' . $token['token'], 'User-Agent' => 'swallowtail-test'],
        null,
        []
    );

    $service = new SwallowtailRawUploadApiService(
        new SwallowtailPhotoIngestService(
            new SwallowtailStorageService($root),
            $library,
            new SwallowtailConversionQueueService(),
            64,
            ['upload_max_filesize' => '50M', 'post_max_size' => '64M']
        ),
        $library
    );
    $response = $service->handleUpload($request, [
        'raw_file' => [
            'tmp_name' => $source,
            'name' => 'IMG_0004.CR2',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ],
    ]);
    $payload = json_decode($response->body(), true);

    $harness->assertSame(400, $response->statusCode());
    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(empty($payload['success']));
    $harness->assertSame('RAW file exceeded the configured upload limit.', (string)(($payload['errors'] ?? [])[0] ?? ''));
    $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_photos'));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'rejects upload tokens outside their CIDR', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['198.51.100.0/24']);
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api-cidr';
    (new SwallowtailStorageLocationService())->registerLocation('Primary API CIDR storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        ['Authorization' => 'Bearer ' . $token['token']],
        null,
        []
    );

    $service = new SwallowtailRawUploadApiService(
        new SwallowtailPhotoIngestService(new SwallowtailStorageService($root), $library, new SwallowtailConversionQueueService()),
        $library
    );
    $response = $service->handleUpload($request, [
        'raw_file' => [
            'tmp_name' => $source,
            'name' => 'IMG_0004.CR2',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ],
    ]);
    $payload = json_decode($response->body(), true);

    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(empty($payload['success']));
    $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_photos'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoLibraryService::class, 'manages upload token CIDRs without storing plaintext tokens', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $created = $library->createUploadToken('Bridge A', 12, null, ['203.0.113.0/24', '2001:db8::/32']);
    $tokenId = (int)$created['id'];
    $stored = $library->uploadTokenById($tokenId);

    $harness->assertTrue(str_starts_with((string)$created['token'], 'stup_'));
    $harness->assertSame(2, InterfaceDB::tableRowCount('swallowtail_api_upload_token_cidrs'));
    $harness->assertTrue($stored !== null);
    $harness->assertTrue(!array_key_exists('token', $stored));
    $harness->assertTrue($library->authenticateUploadToken((string)$created['token'], '203.0.113.42') !== null);
    $harness->assertTrue($library->authenticateUploadToken((string)$created['token'], '198.51.100.42') === null);

    $updated = $library->updateUploadToken($tokenId, [
        'token_label' => 'Bridge A moved',
        'is_active' => false,
        'can_upload_raw' => true,
        'expires_at' => '',
        'cidrs' => ['198.51.100.0/24'],
    ]);

    $harness->assertSame('Bridge A moved', (string)$updated['token_label']);
    $harness->assertSame(1, count((array)$updated['cidrs']));
    $harness->assertTrue($library->authenticateUploadToken((string)$created['token'], '198.51.100.42') === null);

    $library->updateUploadToken($tokenId, [
        'token_label' => 'Bridge A moved',
        'is_active' => true,
        'can_upload_raw' => true,
        'expires_at' => '',
        'cidrs' => ['198.51.100.0/24'],
    ]);
    $harness->assertTrue($library->authenticateUploadToken((string)$created['token'], '198.51.100.42') !== null);

    $library->deleteUploadToken($tokenId);
    $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_api_upload_tokens'));
    $harness->assertSame(0, InterfaceDB::tableRowCount('swallowtail_api_upload_token_cidrs'));
});

$harness->check(SwallowtailConversionStatusApiService::class, 'returns conversion jobs and derivative readiness', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('Status token', null, null, ['203.0.113.0/24']);
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-status';
    (new SwallowtailStorageLocationService())->registerLocation('Primary status storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService($root), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0008.CR2');
    $photoId = (int)$result['photo_id'];
    InterfaceDB::prepareExecute(
        "INSERT INTO swallowtail_photo_derivatives (
            photo_id,
            derivative_type,
            storage_path,
            bytes
        ) VALUES (
            :photo_id,
            'thumbnail',
            'derivatives/thumbnail/test.jpg',
            100
        )",
        ['photo_id' => $photoId]
    );

    $request = new RequestFramework(
        ['photo_id' => (string)$photoId],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        ['Authorization' => 'Bearer ' . $token['token']],
        null,
        []
    );
    $payload = json_decode((new SwallowtailConversionStatusApiService($library))->handleStatus($request)->body(), true);

    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(!empty($payload['success']));
    $harness->assertSame($photoId, (int)($payload['photo_id'] ?? 0));
    $harness->assertSame('queued', (string)(($payload['jobs']['preview'] ?? [])['status'] ?? ''));
    $harness->assertTrue(!empty(($payload['derivatives']['thumbnail'] ?? [])['ready']));
    $harness->assertTrue(empty(($payload['derivatives']['preview'] ?? [])['ready']));

    @unlink($source);
});

$harness->check(SwallowtailStorageLocationService::class, 'chooses writable mounted storage and moves originals between locations', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $primaryRoot = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-primary-full';
    $secondaryRoot = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-secondary-active';
    $locationService = new SwallowtailStorageLocationService();
    $primaryId = $locationService->registerLocation('Primary full storage', $primaryRoot, ['is_full' => true, 'sort_order' => 1]);
    $secondaryId = $locationService->registerLocation('Secondary active storage', $secondaryRoot, ['sort_order' => 2]);

    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService($primaryRoot), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0005.CR2');

    $harness->assertTrue(!empty($result['success']));
    $photo = $library->photoById((int)$result['photo_id']);
    $harness->assertSame($secondaryId, (int)($photo['storage_location_id'] ?? 0));

    $locationService->markLocationFull($primaryId, false);
    $move = $locationService->movePhotoOriginalToLocation((int)$result['photo_id'], $primaryId);
    $photoAfterMove = $library->photoById((int)$result['photo_id']);

    $harness->assertTrue(!empty($move['success']));
    $harness->assertTrue(!empty($move['moved']));
    $harness->assertSame($primaryId, (int)($photoAfterMove['storage_location_id'] ?? 0));
    $harness->assertSame(1, InterfaceDB::countWhere('swallowtail_photo_audit', 'action_type', 'photo_moved_storage_location'));

    @unlink($source);
});

$harness->check('SwallowTail migration', 'defines the photo backend tables', function () use ($harness): void {
    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_05_31_001_swallowtail_photo_services.sql';
    $conversionPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_15_004_raw_conversion_jobs.sql';
    $hardeningPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_001_raw_conversion_hardening.sql';
    $tokenCidrsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_002_upload_token_cidrs.sql';
    $durationPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_003_raw_conversion_duration.sql';
    $embeddedPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_004_raw_conversion_embedded.sql';
    $quickHashPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_005_raw_quick_hash.sql';
    $sql = file_get_contents($path);
    $conversionSql = file_get_contents($conversionPath);
    $hardeningSql = file_get_contents($hardeningPath);
    $tokenCidrsSql = file_get_contents($tokenCidrsPath);
    $durationSql = file_get_contents($durationPath);
    $embeddedSql = file_get_contents($embeddedPath);
    $quickHashSql = file_get_contents($quickHashPath);

    if (!is_string($sql) || !is_string($conversionSql) || !is_string($hardeningSql) || !is_string($tokenCidrsSql) || !is_string($durationSql) || !is_string($embeddedSql) || !is_string($quickHashSql)) {
        throw new RuntimeException('SwallowTail migration could not be read.');
    }

    $sql .= "\n" . $conversionSql . "\n" . $hardeningSql . "\n" . $tokenCidrsSql . "\n" . $durationSql . "\n" . $embeddedSql . "\n" . $quickHashSql;

    foreach ([
        'CREATE TABLE IF NOT EXISTS swallowtail_events',
        'CREATE TABLE IF NOT EXISTS swallowtail_storage_locations',
        'CREATE TABLE IF NOT EXISTS swallowtail_photos',
        'CREATE TABLE IF NOT EXISTS swallowtail_event_permissions',
        'CREATE TABLE IF NOT EXISTS swallowtail_api_upload_tokens',
        'CREATE TABLE IF NOT EXISTS swallowtail_api_upload_token_cidrs',
        'CREATE TABLE IF NOT EXISTS swallowtail_photo_conversion_jobs',
        'derivative_type enum',
        'output_storage_path',
        'output_width',
        'duration_seconds',
        "'embedded'",
        'original_quick_hash',
        'idx_swallowtail_photos_quick_hash',
        "CHECK (original_extension = 'cr2')",
    ] as $needle) {
        $harness->assertTrue(str_contains($sql, $needle));
    }
});

$harness->check(SwallowtailConversionQueueService::class, 'deduplicates derivative jobs by photo type and profile version', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-dedupe';
    (new SwallowtailStorageLocationService())->registerLocation('Primary dedupe storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($root),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0006.CR2');
    $queue = new SwallowtailConversionQueueService();
    $photoId = (int)$result['photo_id'];
    $first = $queue->enqueuePreviewRefresh($photoId, $root . DIRECTORY_SEPARATOR . 'profile-v2.pp3', 2, 12);
    $second = $queue->enqueuePreviewRefresh($photoId, $root . DIRECTORY_SEPARATOR . 'profile-v2.pp3', 2, 12);

    $harness->assertSame($first, $second);
    $harness->assertSame(1, InterfaceDB::countWhere('swallowtail_photo_conversion_jobs', [
        'photo_id' => $photoId,
        'derivative_type' => 'preview',
        'profile_version' => 2,
    ]));

    @unlink($source);
});

$harness->check(SwallowtailConversionQueueService::class, 'does not require Redis for durable derivative enqueue', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-redis-down';
    (new SwallowtailStorageLocationService())->registerLocation('Primary redis-down storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($root),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0007.CR2');

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(5, InterfaceDB::countWhere('swallowtail_photo_conversion_jobs', 'photo_id', (int)$result['photo_id']));

    @unlink($source);
});
