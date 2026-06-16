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
$harness->run(SwallowtailPhotoUiService::class);
$harness->run(SwallowtailWebRawUploadService::class);

$swallowtailUiCreateSchema = static function (): void {
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

    InterfaceDB::execute("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        display_name TEXT NOT NULL,
        email_address TEXT NULL,
        mobile_number TEXT NULL,
        password_hash TEXT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        otp_required INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        role_id INTEGER NOT NULL DEFAULT 0,
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

    InterfaceDB::execute('DELETE FROM users WHERE id IN (901, 902, 903, 904)');
    foreach ([
        [901, 'Admin Test User', -1],
        [902, 'Uploader Test User', 5],
        [903, 'Viewer Test User', 5],
        [904, 'No Access Test User', 5],
    ] as $user) {
        InterfaceDB::prepareExecute(
            "INSERT INTO users (
                id,
                display_name,
                email_address,
                mobile_number,
                password_hash,
                role_id
            ) VALUES (
                :id,
                :display_name,
                :email_address,
                '',
                '',
                :role_id
            )",
            [
                'id' => $user[0],
                'display_name' => $user[1],
                'email_address' => 'swallowtail-ui-' . (string)$user[0] . '@example.test',
                'role_id' => $user[2],
            ]
        );
        UserAuthenticationService::forgetUserByIdCache((int)$user[0]);
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

$swallowtailUiWriteCr2Fixture = static function (string $path): void {
    file_put_contents($path, "II*\0\x10\x00\x00\x00CR\2\0" . str_repeat('A', 128), LOCK_EX);
};

$swallowtailUiUploadFile = static function (string $path, string $name = 'IMG_9001.CR2'): array {
    return [
        'cr2_files' => [
            'tmp_name' => [$path],
            'name' => [$name],
            'type' => ['image/x-cr2'],
            'error' => [UPLOAD_ERR_OK],
            'size' => [is_file($path) ? filesize($path) : 0],
        ],
    ];
};

$harness->check(SwallowtailWebRawUploadService::class, 'accepts signed-in CR2 web uploads and records web ownership', function () use ($harness, $swallowtailUiCreateSchema, $swallowtailUiWriteCr2Fixture, $swallowtailUiUploadFile): void {
    $swallowtailUiCreateSchema();
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-ui-upload';
    (new SwallowtailStorageLocationService())->registerLocation('UI upload storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-ui-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create upload fixture.');
    }
    $swallowtailUiWriteCr2Fixture($source);

    $result = (new SwallowtailWebRawUploadService())->uploadCr2Files(902, $swallowtailUiUploadFile($source));

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, InterfaceDB::tableRowCount('swallowtail_photos'));
    $photo = InterfaceDB::fetchOne('SELECT uploaded_via, uploaded_by_user_id FROM swallowtail_photos LIMIT 1');
    $harness->assertSame('web', (string)($photo['uploaded_via'] ?? ''));
    $harness->assertSame(902, (int)($photo['uploaded_by_user_id'] ?? 0));
    $harness->assertSame(5, InterfaceDB::tableRowCount('swallowtail_photo_conversion_jobs'));

    @unlink($source);
});

$harness->check(SwallowtailWebRawUploadService::class, 'rejects invalid CR2 web upload inputs', function () use ($harness, $swallowtailUiCreateSchema, $swallowtailUiWriteCr2Fixture): void {
    $swallowtailUiCreateSchema();
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-ui-invalid';
    (new SwallowtailStorageLocationService())->registerLocation('UI invalid storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-ui-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create upload fixture.');
    }
    $swallowtailUiWriteCr2Fixture($source);

    $unauthenticated = (new SwallowtailWebRawUploadService())->uploadCr2Files(0, []);
    $harness->assertTrue(empty($unauthenticated['success']));

    $tooMany = (new SwallowtailWebRawUploadService())->uploadCr2Files(902, [
        'cr2_files' => [
            'tmp_name' => [$source, $source, $source, $source],
            'name' => ['A.CR2', 'B.CR2', 'C.CR2', 'D.CR2'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [1, 1, 1, 1],
        ],
    ]);
    $harness->assertTrue(empty($tooMany['success']));

    $wrongExtension = (new SwallowtailWebRawUploadService())->uploadCr2Files(902, [
        'cr2_files' => [
            'tmp_name' => [$source],
            'name' => ['A.CR3'],
            'error' => [UPLOAD_ERR_OK],
            'size' => [filesize($source)],
        ],
    ]);
    $harness->assertTrue(empty($wrongExtension['success']));

    @unlink($source);
});

$harness->check(SwallowtailWebRawUploadService::class, 'reports duplicate CR2 uploads without duplicate photo rows', function () use ($harness, $swallowtailUiCreateSchema, $swallowtailUiWriteCr2Fixture, $swallowtailUiUploadFile): void {
    $swallowtailUiCreateSchema();
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-ui-duplicate';
    (new SwallowtailStorageLocationService())->registerLocation('UI duplicate storage', $root);
    $source = tempnam(sys_get_temp_dir(), 'swallowtail-ui-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create upload fixture.');
    }
    $swallowtailUiWriteCr2Fixture($source);

    $service = new SwallowtailWebRawUploadService();
    $first = $service->uploadCr2Files(902, $swallowtailUiUploadFile($source, 'FIRST.CR2'));
    $duplicate = $service->uploadCr2Files(902, $swallowtailUiUploadFile($source, 'SECOND.CR2'));

    $harness->assertTrue(!empty($first['success']));
    $harness->assertTrue(!empty($duplicate['success']));
    $harness->assertTrue(!empty($duplicate['files'][0]['duplicate']));
    $harness->assertSame(1, InterfaceDB::tableRowCount('swallowtail_photos'));
    $harness->assertSame(1, InterfaceDB::countWhere('swallowtail_photo_audit', 'action_type', 'raw_duplicate_detected'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoUiService::class, 'returns admin uploader and event-permitted gallery rows', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-ui-gallery';
    $locationId = (new SwallowtailStorageLocationService())->registerLocation('UI gallery storage', $root);
    $library = new SwallowtailPhotoLibraryService();
    $event = $library->createEvent('Accessible Event');

    foreach ([
        ['owned.CR2', 'a', 902],
        ['event.CR2', 'b', null],
        ['hidden.CR2', 'c', null],
    ] as $photo) {
        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                original_storage_path,
                storage_location_id,
                uploaded_by_user_id,
                uploaded_via
            ) VALUES (
                :filename,
                'cr2',
                100,
                :sha256,
                :path,
                :location_id,
                :user_id,
                'web'
            )",
            [
                'filename' => $photo[0],
                'sha256' => str_repeat($photo[1], 64),
                'path' => 'originals/' . $photo[1] . '.cr2',
                'location_id' => $locationId,
                'user_id' => $photo[2],
            ]
        );
    }

    $eventPhotoId = (int)InterfaceDB::fetchColumn("SELECT id FROM swallowtail_photos WHERE original_filename = 'event.CR2'");
    $library->assignPhotoToEvent($eventPhotoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 903, ['can_view' => true]);

    $service = new SwallowtailPhotoUiService();
    $adminRows = $service->accessiblePhotos(901)['rows'];
    $uploaderRows = $service->accessiblePhotos(902)['rows'];
    $viewerRows = $service->accessiblePhotos(903)['rows'];
    $noAccessRows = $service->accessiblePhotos(904)['rows'];

    $harness->assertSame(3, count($adminRows));
    $harness->assertSame(1, count($uploaderRows));
    $harness->assertSame('owned.CR2', (string)$uploaderRows[0]['original_filename']);
    $harness->assertSame(1, count($viewerRows));
    $harness->assertSame('event.CR2', (string)$viewerRows[0]['original_filename']);
    $harness->assertSame(0, count($noAccessRows));
});

$harness->check(SwallowtailPhotoUiService::class, 'resolves only authorized private derivative assets', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $root = PROJECT_ROOT . 'debug' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'swallowtail-ui-assets';
    $locationId = (new SwallowtailStorageLocationService())->registerLocation('UI asset storage', $root);
    $storage = new SwallowtailStorageService($root);
    $relative = $storage->derivativeRelativePath(str_repeat('d', 64), 'thumbnail');
    $storage->ensureDirectoryForRelativePath($relative);
    $absolute = $storage->absolutePath($relative);
    file_put_contents($absolute, "\xff\xd8\xff\xd9", LOCK_EX);

    InterfaceDB::prepareExecute(
        "INSERT INTO swallowtail_photos (
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            original_storage_path,
            storage_location_id,
            uploaded_by_user_id,
            uploaded_via
        ) VALUES (
            'asset.CR2',
            'cr2',
            100,
            :sha256,
            'originals/dd/dd/asset.cr2',
            :location_id,
            902,
            'web'
        )",
        [
            'sha256' => str_repeat('d', 64),
            'location_id' => $locationId,
        ]
    );
    $photoId = (int)InterfaceDB::fetchColumn("SELECT id FROM swallowtail_photos WHERE original_filename = 'asset.CR2'");
    InterfaceDB::prepareExecute(
        "INSERT INTO swallowtail_photo_derivatives (
            photo_id,
            derivative_type,
            storage_path,
            storage_location_id,
            bytes
        ) VALUES (
            :photo_id,
            'thumbnail',
            :storage_path,
            :location_id,
            4
        )",
        [
            'photo_id' => $photoId,
            'storage_path' => $relative,
            'location_id' => $locationId,
        ]
    );

    $service = new SwallowtailPhotoUiService();
    $asset = $service->photoAsset($photoId, 902, 'thumbnail');
    $denied = $service->photoAsset($photoId, 904, 'thumbnail');

    $harness->assertTrue(is_array($asset));
    $harness->assertSame($absolute, (string)$asset['path']);
    $harness->assertSame(null, $denied);
});
