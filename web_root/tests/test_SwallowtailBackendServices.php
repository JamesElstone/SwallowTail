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
$harness->run(SwallowtailEventAccessService::class);
$harness->run(SwallowtailConversionQueueService::class);
$harness->run(SwallowtailStorageLocationService::class);

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

$swallowtailWriteRawFixture = static function (string $path, string $extension = 'cr3'): void {
    $bytes = $extension === 'cr2'
        ? "II*\0\x10\x00\x00\x00CR\2\0" . str_repeat('A', 128)
        : "\0\0\0\x18ftypcrx " . str_repeat('B', 128);

    file_put_contents($path, $bytes, LOCK_EX);
};

$harness->check(SwallowtailStorageService::class, 'stores originals outside web_root using checksum paths', function () use ($harness): void {
    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-storage';
    $service = new SwallowtailStorageService($root);
    $sha256 = str_repeat('a', 64);
    $relative = $service->originalRelativePath($sha256, 'CR3');

    $harness->assertTrue(str_contains($relative, 'originals'));
    $harness->assertTrue(str_ends_with($relative, $sha256 . '.cr3'));
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

    $swallowtailWriteRawFixture($source, 'cr3');

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService($root),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );

    $result = $ingest->ingestLocalRawFile($source, 'IMG_0001.CR3', ['uploaded_via' => 'api']);

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

$harness->check(SwallowtailEventAccessService::class, 'keeps event access least privilege until granted', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-access';
    (new SwallowtailStorageLocationService())->registerLocation('Primary access storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr3');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService($root), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0003.CR3');
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

$harness->check(SwallowtailRawUploadApiService::class, 'accepts token authenticated multipart RAW uploads', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig');
    $root = PROJECT_ROOT . 'file_logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-api';
    (new SwallowtailStorageLocationService())->registerLocation('Primary API storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr3');

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
            'name' => 'IMG_0004.CR3',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ],
    ]);

    $payload = json_decode($response->body(), true);

    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(!empty($payload['success']));
    $harness->assertSame('uploaded', $payload['status'] ?? null);
    $harness->assertSame(1, InterfaceDB::tableRowCount('swallowtail_photos'));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('swallowtail_api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));

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
    $swallowtailWriteRawFixture($source, 'cr3');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService($primaryRoot), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0005.CR3');

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
    $embeddedPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_004_raw_conversion_embedded.sql';
    $sql = file_get_contents($path);
    $conversionSql = file_get_contents($conversionPath);
    $hardeningSql = file_get_contents($hardeningPath);
    $embeddedSql = file_get_contents($embeddedPath);

    if (!is_string($sql) || !is_string($conversionSql) || !is_string($hardeningSql) || !is_string($embeddedSql)) {
        throw new RuntimeException('Swallowtail migration could not be read.');
    }

    $sql .= "\n" . $conversionSql . "\n" . $hardeningSql . "\n" . $embeddedSql;

    foreach ([
        'CREATE TABLE IF NOT EXISTS swallowtail_events',
        'CREATE TABLE IF NOT EXISTS swallowtail_storage_locations',
        'CREATE TABLE IF NOT EXISTS swallowtail_photos',
        'CREATE TABLE IF NOT EXISTS swallowtail_event_permissions',
        'CREATE TABLE IF NOT EXISTS swallowtail_api_upload_tokens',
        'CREATE TABLE IF NOT EXISTS swallowtail_photo_conversion_jobs',
        'derivative_type enum',
        'output_storage_path',
        'output_width',
        "'embedded'",
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
