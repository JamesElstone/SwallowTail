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
    InterfaceDB::execute('DROP TABLE IF EXISTS users');
    InterfaceDB::execute("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        display_name TEXT NOT NULL,
        email_address TEXT NOT NULL,
        mobile_number TEXT NULL,
        password_hash TEXT NOT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        otp_required INTEGER NOT NULL DEFAULT 0,
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
};

$harness->check(SwallowtailStorageService::class, 'stores originals outside web_root using checksum paths', function () use ($harness): void {
    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-storage';
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

$harness->check(SwallowtailPhotoIngestService::class, 'ingests RAW files as unassigned photos and queues conversion', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-ingest';
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

    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-cr3-rejected';
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

    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-duplicates';
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
    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-checksum';
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

$harness->check(SwallowtailSpiceBushRegistrationApiService::class, 'creates an upload token for a user allowed to manage upload tokens', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    $securityPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'spicebush-register-' . bin2hex(random_bytes(6)) . '.keys';
    $auth = new UserAuthenticationService($securityPath, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        $created = $auth->createUser('SpiceBush Admin', 'spicebush-admin@example.test', 'SpiceBush Pass 1!', true);
        $userId = (int)($created['user_id'] ?? 0);
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
        $auth->createUser('SpiceBush Viewer', 'spicebush-viewer@example.test', 'SpiceBush Pass 1!', true);
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

    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-access';
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

    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-image-serve';
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

$harness->check(SwallowtailRawUploadApiService::class, 'accepts token authenticated multipart RAW uploads', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api';
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

$harness->check(SwallowtailRawUploadApiService::class, 'rejects upload tokens outside their CIDR', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['198.51.100.0/24']);
    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api-cidr';
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
    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-status';
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

    $primaryRoot = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-primary-full';
    $secondaryRoot = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-secondary-active';
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

$harness->check('Swallowtail migration', 'defines the photo backend tables', function () use ($harness): void {
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
        throw new RuntimeException('Swallowtail migration could not be read.');
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

    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-dedupe';
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

    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-redis-down';
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
