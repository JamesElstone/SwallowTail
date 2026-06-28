<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailPhotoLibraryService;
use Swallowtail\Service\SwallowtailPhotoUiService;
use Swallowtail\Service\SwallowtailEventManagementService;
use Swallowtail\Service\SwallowtailRawTherapeeProfileService;
use Swallowtail\Service\SwallowtailStorageService;
use Swallowtail\Service\SwallowtailWebRawUploadService;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(SwallowtailPhotoUiService::class);
$harness->run(SwallowtailWebRawUploadService::class);

function swallowtail_ui_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
            continue;
        }

        @unlink($item->getPathname());
    }

    @rmdir($path);
}

function swallowtail_ui_test_tmp_root(): string
{
    return APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'swallowtail-ui';
}

function swallowtail_ui_storage_tmp_root(): string
{
    return PROJECT_ROOT . 'tmp' . DIRECTORY_SEPARATOR . 'swallowtail-storage' . DIRECTORY_SEPARATOR . 'ui';
}

function swallowtail_ui_test_temp_file(string $prefix): string
{
    $root = swallowtail_ui_test_tmp_root();
    if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create SwallowTail UI test temp directory.');
    }

    $path = tempnam($root, $prefix);
    if (!is_string($path)) {
        throw new RuntimeException('Unable to create SwallowTail UI test temp file.');
    }

    return $path;
}

function swallowtail_ui_record_asset(
    int $photoId,
    string $imageType,
    string $path,
    string $sha256,
    ?string $profileSignature = null,
    ?int $conversionJobId = null
): void {
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_image_assets (
            photo_id,
            image_type,
            sha256,
            bytes,
            modified_at,
            width,
            height,
            profile_signature,
            asset_variant_key,
            conversion_job_id,
            generated_at
        ) VALUES (
            :photo_id,
            :image_type,
            :sha256,
            :bytes,
            :modified_at,
            1,
            1,
            :profile_signature,
            :asset_variant_key,
            :conversion_job_id,
            CURRENT_TIMESTAMP
        )
        ON CONFLICT(photo_id, image_type, asset_variant_key) DO UPDATE SET
            sha256 = excluded.sha256,
            bytes = excluded.bytes,
            modified_at = excluded.modified_at,
            profile_signature = excluded.profile_signature,
            asset_variant_key = excluded.asset_variant_key,
            conversion_job_id = excluded.conversion_job_id,
            updated_at = CURRENT_TIMESTAMP",
        [
            'photo_id' => $photoId,
            'image_type' => $imageType,
            'sha256' => $sha256,
            'bytes' => max(1, (int)@filesize($path)),
            'modified_at' => max(1, (int)@filemtime($path)),
            'profile_signature' => $profileSignature,
            'asset_variant_key' => $imageType === 'rawtherapee_sample' && $profileSignature !== null ? $profileSignature : '',
            'conversion_job_id' => $conversionJobId,
        ]
    );
}

register_shutdown_function(static function (): void {
    swallowtail_ui_remove_tree(swallowtail_ui_test_tmp_root());
    swallowtail_ui_remove_tree(swallowtail_ui_storage_tmp_root());
});

$swallowtailUiEnableRootStorageForTests = static function (): void {
    static $originalConfig = null;
    static $restoreRegistered = false;

    $configPath = AppConfigurationStore::configPath();
    if ($originalConfig === null) {
        $originalConfig = file_get_contents($configPath);
        if (!is_string($originalConfig)) {
            throw new RuntimeException('Unable to read fixture config.');
        }
    }

    if (!$restoreRegistered) {
        $restoreRegistered = true;
        register_shutdown_function(static function () use ($configPath, &$originalConfig): void {
            try {
                $storageRoot = swallowtail_ui_storage_tmp_root();
                if (!is_dir($storageRoot) && !@mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
                    throw new RuntimeException('Unable to create SwallowTail UI storage test directory.');
                }

                \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', $storageRoot);
                \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
                \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);
                $storage = new SwallowtailStorageService();
                $checksums = [
                    hash('sha256', "II*\0\x10\x00\x00\x00CR\2\0" . str_repeat('A', 128)),
                    str_repeat('d', 64),
                ];
                foreach ($storage->storageLocations() as $location) {
                    $baseLocation = (string)($location['storage_base_location'] ?? '');
                    if ($baseLocation === '') {
                        continue;
                    }

                    foreach ($checksums as $checksum) {
                        foreach (SwallowtailStorageService::IMAGE_TYPES as $imageType) {
                            try {
                                @unlink($storage->imagePath($baseLocation, $checksum, $imageType));
                            } catch (Throwable) {
                            }
                        }

                        try {
                            $folder = dirname($storage->imagePath($baseLocation, $checksum, 'source'));
                            @rmdir($folder);
                            @rmdir(dirname($folder));
                        } catch (Throwable) {
                        }
                    }
                }
            } catch (Throwable) {
            }

            \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
            \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.round_robin_locations', false);
            \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 5);
            \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', '');
        });
    }

    $storageRoot = swallowtail_ui_storage_tmp_root();
    if (!is_dir($storageRoot) && !@mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('Unable to create SwallowTail UI storage test directory.');
    }

    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', $storageRoot);
    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.round_robin_locations', false);
    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);
};

$swallowtailUiCreateSchema = static function () use ($swallowtailUiEnableRootStorageForTests): void {
    $swallowtailUiEnableRootStorageForTests();
    InterfaceDB::execute('PRAGMA foreign_keys = OFF');

    foreach ([
        'photo_audit',
        'storage_migration_job_items',
        'storage_migration_jobs',
        'internal_profile_data',
        'photo_profile_data',
        'photo_image_assets',
        'photo_conversion_jobs',
        'photo_metadata',
        'event_permissions',
        'event_photos',
        'photos',
        'storage_location_properties',
        'api_upload_token_cidrs',
        'api_upload_tokens',
        'events',
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

    InterfaceDB::execute("CREATE TABLE events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_name TEXT NOT NULL,
        event_slug TEXT NOT NULL UNIQUE,
        created_by_user_id INTEGER NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE api_upload_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_hash TEXT NOT NULL UNIQUE,
        token_label TEXT NOT NULL,
        created_by_user_id INTEGER NULL,
        hidden INTEGER NOT NULL DEFAULT 0,
        can_upload_raw INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        last_used_at TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE api_upload_token_cidrs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_id INTEGER NOT NULL,
        cidr TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (token_id, cidr)
    )");

    InterfaceDB::execute("CREATE TABLE storage_location_properties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        storage_base_location TEXT NOT NULL UNIQUE,
        is_excluded INTEGER NOT NULL DEFAULT 0,
        is_zfs INTEGER NOT NULL DEFAULT 0,
        dataset_name TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_filename TEXT NOT NULL,
        original_extension TEXT NOT NULL,
        original_bytes INTEGER NOT NULL,
        original_sha256 TEXT NOT NULL UNIQUE,
        storage_base_location TEXT NOT NULL,
        upload_state TEXT NOT NULL DEFAULT 'uploaded',
        conversion_state TEXT NOT NULL DEFAULT 'pending',
        uploaded_by_user_id INTEGER NULL,
        uploaded_via TEXT NOT NULL DEFAULT 'api',
        upload_token_id INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE photo_metadata (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'deferred',
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at TEXT NULL,
        last_error TEXT NULL,
        captured_at_local TEXT NULL,
        captured_at_utc TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (photo_id)
    )");

    InterfaceDB::execute("CREATE TABLE event_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        photo_id INTEGER NOT NULL,
        assigned_by_user_id INTEGER NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (event_id, photo_id)
    )");

    InterfaceDB::execute("CREATE TABLE event_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        grantee_type TEXT NOT NULL DEFAULT 'user',
        grantee_id INTEGER NOT NULL,
        can_view INTEGER NOT NULL DEFAULT 0,
        can_edit INTEGER NOT NULL DEFAULT 0,
        can_download_single_jpeg INTEGER NOT NULL DEFAULT 0,
        can_download_event_zip INTEGER NOT NULL DEFAULT 0,
        can_download_all_accessible INTEGER NOT NULL DEFAULT 0,
        can_download_original_raw INTEGER NOT NULL DEFAULT 0,
        granted_by_user_id INTEGER NULL,
        expires_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (event_id, grantee_type, grantee_id)
    )");

    InterfaceDB::execute("CREATE TABLE photo_conversion_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        job_type TEXT NOT NULL,
        image_type TEXT NULL,
        input_path TEXT NULL,
        profile_path TEXT NULL,
        output_path TEXT NULL,
        requested_by_user_id INTEGER NULL,
        priority INTEGER NOT NULL DEFAULT 20,
        status TEXT NOT NULL DEFAULT 'queued',
        attempts INTEGER NOT NULL DEFAULT 0,
        available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at TEXT NULL,
        locked_by TEXT NULL,
        last_error TEXT NULL,
        started_at TEXT NULL,
        completed_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE photo_image_assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        image_type TEXT NOT NULL,
        sha256 TEXT NOT NULL,
        bytes INTEGER NOT NULL,
        modified_at INTEGER NOT NULL,
        width INTEGER NULL,
        height INTEGER NULL,
        profile_signature TEXT NULL,
        asset_variant_key TEXT NOT NULL DEFAULT '',
        conversion_job_id INTEGER NULL,
        generated_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (photo_id, image_type, asset_variant_key)
    )");

    InterfaceDB::execute("CREATE TABLE photo_profile_data (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        revision INTEGER NOT NULL DEFAULT 0,
        type TEXT NOT NULL,
        `key` TEXT NOT NULL,
        value TEXT NULL,
        value_type TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (photo_id, type, `key`, revision)
    )");

    InterfaceDB::execute("CREATE TABLE internal_profile_data (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        image_type TEXT NOT NULL,
        profile_name TEXT NOT NULL,
        `order` INTEGER NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1,
        type TEXT NOT NULL,
        `key` TEXT NOT NULL,
        value TEXT NULL,
        value_type TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (image_type, profile_name, type, `key`)
    )");

    InterfaceDB::execute("CREATE TABLE photo_audit (
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

    InterfaceDB::execute("CREATE TABLE storage_migration_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_base_location TEXT NOT NULL,
        destination_base_location TEXT NULL,
        zpool_name TEXT NULL,
        dataset_name TEXT NULL,
        requested_by_user_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'queued',
        total_photos INTEGER NOT NULL DEFAULT 0,
        moved_photos INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        started_at TEXT NULL,
        completed_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    InterfaceDB::execute("CREATE TABLE storage_migration_job_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        photo_id INTEGER NOT NULL,
        source_base_location TEXT NOT NULL,
        destination_base_location TEXT NULL,
        status TEXT NOT NULL DEFAULT 'queued',
        file_count INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        completed_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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
    $source = swallowtail_ui_test_temp_file('swallowtail-ui-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create upload fixture.');
    }
    $swallowtailUiWriteCr2Fixture($source);

    $result = (new SwallowtailWebRawUploadService())->uploadCr2Files(902, $swallowtailUiUploadFile($source));

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, InterfaceDB::tableRowCount('photos'));
    $photo = InterfaceDB::fetchOne('SELECT uploaded_via, uploaded_by_user_id FROM photos LIMIT 1');
    $harness->assertSame('web', (string)($photo['uploaded_via'] ?? ''));
    $harness->assertSame(902, (int)($photo['uploaded_by_user_id'] ?? 0));
    $harness->assertSame(3, InterfaceDB::tableRowCount('photo_conversion_jobs'));
    $harness->assertSame(
        ['embedded', 'thumbnail', 'original'],
        array_map(
            static fn(array $row): string => (string)($row['image_type'] ?? ''),
            InterfaceDB::fetchAll('SELECT image_type FROM photo_conversion_jobs ORDER BY id')
        )
    );

    @unlink($source);
});

$harness->check(SwallowtailWebRawUploadService::class, 'rejects invalid CR2 web upload inputs', function () use ($harness, $swallowtailUiCreateSchema, $swallowtailUiWriteCr2Fixture): void {
    $swallowtailUiCreateSchema();
    $source = swallowtail_ui_test_temp_file('swallowtail-ui-');
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
    $source = swallowtail_ui_test_temp_file('swallowtail-ui-');
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
    $harness->assertSame(1, InterfaceDB::tableRowCount('photos'));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_audit', 'action_type', 'raw_duplicate_detected'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoUiService::class, 'returns admin uploader and event-permitted gallery rows', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $library = new SwallowtailPhotoLibraryService();
    $event = $library->createEvent('Accessible Event');
    $locations = (new SwallowtailStorageService())->storageLocations();
    $baseLocation = (string)($locations[0]['storage_base_location'] ?? '');

    foreach ([
        ['owned.CR2', 'a', 902],
        ['event.CR2', 'b', null],
        ['hidden.CR2', 'c', null],
    ] as $photo) {
        InterfaceDB::prepareExecute(
            "INSERT INTO photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                storage_base_location,
                uploaded_by_user_id,
                uploaded_via
            ) VALUES (
                :filename,
                'cr2',
                100,
                :sha256,
                :storage_base_location,
                :user_id,
                'web'
            )",
            [
                'filename' => $photo[0],
                'sha256' => str_repeat($photo[1], 64),
                'storage_base_location' => $baseLocation,
                'user_id' => $photo[2],
            ]
        );
    }

    $eventPhotoId = (int)InterfaceDB::fetchColumn("SELECT id FROM photos WHERE original_filename = 'event.CR2'");
    $library->assignPhotoToEvent($eventPhotoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 903, ['can_view' => true]);

    $service = new SwallowtailPhotoUiService();
    $adminRows = $service->accessiblePhotos(901)['rows'];
    $uploaderRows = $service->accessiblePhotos(902)['rows'];
    $viewerRows = $service->accessiblePhotos(903)['rows'];
    $noAccessRows = $service->accessiblePhotos(904)['rows'];
    $clampedGallery = $service->accessiblePhotos(901, 99, 2);
    $adminFilteredGallery = $service->accessiblePhotos(901, 1, 1, 'uploaded', 'desc', (int)$event['id']);
    $viewerFilteredGallery = $service->accessiblePhotos(903, 1, 24, 'uploaded', 'desc', (int)$event['id']);
    $uploaderFilteredGallery = $service->accessiblePhotos(902, 1, 24, 'uploaded', 'desc', (int)$event['id']);

    $harness->assertSame(3, count($adminRows));
    $harness->assertSame(1, count($uploaderRows));
    $harness->assertSame('owned.CR2', (string)$uploaderRows[0]['original_filename']);
    $harness->assertSame(1, count($viewerRows));
    $harness->assertSame('event.CR2', (string)$viewerRows[0]['original_filename']);
    $harness->assertSame(0, count($noAccessRows));
    $harness->assertSame(1, count((array)$clampedGallery['rows']));
    $harness->assertSame(2, (int)$clampedGallery['pagination']['page']);
    $harness->assertSame(3, (int)$clampedGallery['pagination']['total_items']);
    $harness->assertSame(3, (int)$clampedGallery['pagination']['first_item']);
    $harness->assertSame(3, (int)$clampedGallery['pagination']['last_item']);
    $harness->assertSame(1, count((array)$adminFilteredGallery['rows']));
    $harness->assertSame('event.CR2', (string)$adminFilteredGallery['rows'][0]['original_filename']);
    $harness->assertSame([(int)$event['id']], (array)$adminFilteredGallery['rows'][0]['event_ids']);
    $harness->assertSame(1, (int)$adminFilteredGallery['pagination']['total_items']);
    $harness->assertSame(1, count((array)$viewerFilteredGallery['rows']));
    $harness->assertSame('event.CR2', (string)$viewerFilteredGallery['rows'][0]['original_filename']);
    $harness->assertSame(0, count((array)$uploaderFilteredGallery['rows']));
    $harness->assertSame(0, (int)$uploaderFilteredGallery['pagination']['total_items']);
});

$harness->check(SwallowtailRawTherapeeProfileService::class, 'searches accessible thumbnail photos by id filename and checksum', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $locations = (new SwallowtailStorageService())->storageLocations();
    $baseLocation = (string)($locations[0]['storage_base_location'] ?? '');
    $photoIds = [];

    foreach ([
        ['RecallExact.CR2', str_repeat('1', 64), 902, '2026-06-25 10:00:00'],
        ['RecallLater.CR2', str_repeat('2', 64), 902, '2026-06-25 11:00:00'],
        ['RecallHidden.CR2', str_repeat('3', 64), null, '2026-06-25 12:00:00'],
    ] as $photo) {
        InterfaceDB::prepareExecute(
            "INSERT INTO photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                storage_base_location,
                uploaded_by_user_id,
                uploaded_via,
                created_at
            ) VALUES (
                :filename,
                'cr2',
                100,
                :sha256,
                :storage_base_location,
                :user_id,
                'web',
                :created_at
            )",
            [
                'filename' => $photo[0],
                'sha256' => $photo[1],
                'storage_base_location' => $baseLocation,
                'user_id' => $photo[2],
                'created_at' => $photo[3],
            ]
        );
        $photoId = (int)InterfaceDB::fetchColumn('SELECT id FROM photos WHERE original_sha256 = :sha256 LIMIT 1', [
            'sha256' => $photo[1],
        ]);
        $photoIds[] = $photoId;
        InterfaceDB::prepareExecute(
            "INSERT INTO photo_image_assets (
                photo_id,
                image_type,
                sha256,
                bytes,
                modified_at
            ) VALUES (
                :photo_id,
                'thumbnail',
                :sha256,
                10,
                1
            )",
            [
                'photo_id' => $photoId,
                'sha256' => str_repeat('f', 64),
            ]
        );
    }

    $service = new SwallowtailRawTherapeeProfileService();
    $adminFilenameRows = $service->searchAccessibleThumbnailPhotos(901, 'Recall', 10);
    $uploaderFilenameRows = $service->searchAccessibleThumbnailPhotos(902, 'Recall', 10);
    $noAccessRows = $service->searchAccessibleThumbnailPhotos(904, 'Recall', 10);
    $idRows = $service->searchAccessibleThumbnailPhotos(901, (string)$photoIds[0], 10);
    $checksumRows = $service->searchAccessibleThumbnailPhotos(902, str_repeat('2', 12), 10);

    $harness->assertSame(3, count($adminFilenameRows));
    $harness->assertSame('RecallHidden.CR2', (string)$adminFilenameRows[0]['original_filename']);
    $harness->assertSame(2, count($uploaderFilenameRows));
    $harness->assertSame('RecallLater.CR2', (string)$uploaderFilenameRows[0]['original_filename']);
    $harness->assertSame(0, count($noAccessRows));
    $harness->assertSame($photoIds[0], (int)$idRows[0]['id']);
    $harness->assertSame('RecallLater.CR2', (string)$checksumRows[0]['original_filename']);
});

$harness->check(SwallowtailEventManagementService::class, 'assigns and unassigns event photos only when actor can edit photo', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $library = new SwallowtailPhotoLibraryService();
    $sourceEvent = $library->createEvent('Source Event');
    $targetEvent = $library->createEvent('Target Event');
    $baseLocation = swallowtail_ui_storage_tmp_root();

    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            uploaded_by_user_id,
            uploaded_via
        ) VALUES (
            'taggable.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            NULL,
            'web'
        )",
        [
            'sha256' => str_repeat('9', 64),
            'storage_base_location' => $baseLocation,
        ]
    );

    $photoId = (int)InterfaceDB::fetchColumn("SELECT id FROM photos WHERE original_filename = 'taggable.CR2'");
    $library->assignPhotoToEvent($photoId, (int)$sourceEvent['id']);
    $library->grantEventPermission((int)$sourceEvent['id'], 903, ['can_view' => true]);

    $service = new SwallowtailEventManagementService();
    $service->assignPhotosToEvent([$photoId], (int)$targetEvent['id'], true, 901);
    $harness->assertSame(1, InterfaceDB::countWhere('event_photos', [
        'photo_id' => $photoId,
        'event_id' => (int)$targetEvent['id'],
    ]));

    $service->assignPhotosToEvent([$photoId], (int)$targetEvent['id'], false, 901);
    $harness->assertSame(0, InterfaceDB::countWhere('event_photos', [
        'photo_id' => $photoId,
        'event_id' => (int)$targetEvent['id'],
    ]));

    $failed = false;
    try {
        $service->assignPhotosToEvent([$photoId], (int)$targetEvent['id'], true, 903);
    } catch (RuntimeException) {
        $failed = true;
    }

    $harness->assertSame(true, $failed);
    $harness->assertSame(0, InterfaceDB::countWhere('event_photos', [
        'photo_id' => $photoId,
        'event_id' => (int)$targetEvent['id'],
    ]));
});

$harness->check(SwallowtailPhotoUiService::class, 'sorts accessible gallery rows by upload filename and timestamp', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $baseLocation = swallowtail_ui_storage_tmp_root();

    foreach ([
        ['alpha.CR2', 'a', '2026-06-25 10:00:00', '2024-03-01 09:00:00'],
        ['Beta.CR2', 'b', '2026-06-25 11:00:00', '2024-02-01 09:00:00'],
        ['gamma.CR2', 'c', '2026-06-25 12:00:00', null],
    ] as $photo) {
        InterfaceDB::prepareExecute(
            "INSERT INTO photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                storage_base_location,
                uploaded_by_user_id,
                uploaded_via,
                created_at
            ) VALUES (
                :filename,
                'cr2',
                100,
                :sha256,
                :storage_base_location,
                902,
                'web',
                :created_at
            )",
            [
                'filename' => $photo[0],
                'sha256' => str_repeat($photo[1], 64),
                'storage_base_location' => $baseLocation,
                'created_at' => $photo[2],
            ]
        );

        if ($photo[3] !== null) {
            InterfaceDB::prepareExecute(
                "INSERT INTO photo_metadata (photo_id, status, captured_at_local, captured_at_utc)
                 VALUES (
                    (SELECT id FROM photos WHERE original_sha256 = :sha256 LIMIT 1),
                    'ready',
                    :captured_at,
                    :captured_at
                 )",
                [
                    'sha256' => str_repeat($photo[1], 64),
                    'captured_at' => $photo[3],
                ]
            );
        }
    }

    $filenames = static function (array $gallery): array {
        return array_map(
            static fn(array $row): string => (string)($row['original_filename'] ?? ''),
            (array)($gallery['rows'] ?? [])
        );
    };

    $service = new SwallowtailPhotoUiService();

    $harness->assertSame(['gamma.CR2', 'Beta.CR2', 'alpha.CR2'], $filenames($service->accessiblePhotos(901, 1, 24, 'uploaded', 'desc')));
    $harness->assertSame(['alpha.CR2', 'Beta.CR2', 'gamma.CR2'], $filenames($service->accessiblePhotos(901, 1, 24, 'uploaded', 'asc')));
    $harness->assertSame(['alpha.CR2', 'Beta.CR2', 'gamma.CR2'], $filenames($service->accessiblePhotos(901, 1, 24, 'filename', 'asc')));
    $harness->assertSame(['gamma.CR2', 'Beta.CR2', 'alpha.CR2'], $filenames($service->accessiblePhotos(901, 1, 24, 'filename', 'desc')));
    $harness->assertSame(['Beta.CR2', 'alpha.CR2', 'gamma.CR2'], $filenames($service->accessiblePhotos(901, 1, 24, 'timestamp', 'asc')));
    $harness->assertSame(['alpha.CR2', 'Beta.CR2', 'gamma.CR2'], $filenames($service->accessiblePhotos(901, 1, 24, 'timestamp', 'desc')));
});

$harness->check(SwallowtailPhotoUiService::class, 'falls back to upload sorting when timestamp metadata is unavailable', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $baseLocation = swallowtail_ui_storage_tmp_root();

    foreach ([
        ['old.CR2', '1', '2026-06-25 10:00:00'],
        ['new.CR2', '2', '2026-06-25 11:00:00'],
    ] as $photo) {
        InterfaceDB::prepareExecute(
            "INSERT INTO photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                storage_base_location,
                uploaded_by_user_id,
                uploaded_via,
                created_at
            ) VALUES (
                :filename,
                'cr2',
                100,
                :sha256,
                :storage_base_location,
                902,
                'web',
                :created_at
            )",
            [
                'filename' => $photo[0],
                'sha256' => str_repeat($photo[1], 64),
                'storage_base_location' => $baseLocation,
                'created_at' => $photo[2],
            ]
        );
    }

    InterfaceDB::execute('DROP TABLE IF EXISTS photo_metadata');

    $rows = (array)(new SwallowtailPhotoUiService())->accessiblePhotos(901, 1, 24, 'timestamp', 'asc')['rows'];

    $harness->assertSame(
        ['old.CR2', 'new.CR2'],
        array_map(static fn(array $row): string => (string)($row['original_filename'] ?? ''), $rows)
    );
});

$harness->check(SwallowtailPhotoUiService::class, 'returns recent uploads from all accessible upload sources', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $baseLocation = swallowtail_ui_storage_tmp_root();

    foreach ([
        ['web-owned.CR2', '1', 902, 'web', '2026-06-25 10:00:00'],
        ['api-owned.CR2', '2', 902, 'api', '2026-06-25 11:00:00'],
        ['api-other.CR2', '3', 904, 'api', '2026-06-25 12:00:00'],
    ] as $photo) {
        InterfaceDB::prepareExecute(
            "INSERT INTO photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                storage_base_location,
                uploaded_by_user_id,
                uploaded_via,
                created_at
            ) VALUES (
                :filename,
                'cr2',
                100,
                :sha256,
                :storage_base_location,
                :user_id,
                :uploaded_via,
                :created_at
            )",
            [
                'filename' => $photo[0],
                'sha256' => str_repeat($photo[1], 64),
                'storage_base_location' => $baseLocation,
                'user_id' => $photo[2],
                'uploaded_via' => $photo[3],
                'created_at' => $photo[4],
            ]
        );
    }

    $uploaderRows = (new SwallowtailPhotoUiService())->recentUploads(902);
    $adminRows = (new SwallowtailPhotoUiService())->recentUploads(901);

    $harness->assertSame(2, count($uploaderRows));
    $harness->assertSame('api-owned.CR2', (string)$uploaderRows[0]['original_filename']);
    $harness->assertSame('web-owned.CR2', (string)$uploaderRows[1]['original_filename']);
    $harness->assertSame(3, count($adminRows));
    $harness->assertSame('api-other.CR2', (string)$adminRows[0]['original_filename']);
});

$harness->check(SwallowtailPhotoUiService::class, 'uses lightweight gallery preview readiness', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $storage = new SwallowtailStorageService();
    $baseLocation = swallowtail_ui_storage_tmp_root();
    $photos = [
        ['preview.CR2', str_repeat('e', 64), ['preview', 'thumbnail']],
        ['thumbnail.CR2', str_repeat('f', 64), ['thumbnail']],
        ['pending.CR2', str_repeat('9', 64), []],
    ];

    foreach ($photos as $photo) {
        InterfaceDB::prepareExecute(
            "INSERT INTO photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                storage_base_location,
                uploaded_by_user_id,
                uploaded_via
            ) VALUES (
                :filename,
                'cr2',
                100,
                :sha256,
                :storage_base_location,
                902,
                'web'
            )",
            [
                'filename' => $photo[0],
                'sha256' => $photo[1],
                'storage_base_location' => $baseLocation,
            ]
        );

        foreach ($photo[2] as $imageType) {
            $absolute = $storage->imagePath($baseLocation, (string)$photo[1], (string)$imageType);
            $storage->ensureDirectoryForPath($absolute);
            file_put_contents($absolute, "\xff\xd8\xff\xd9", LOCK_EX);
            $photoId = (int)InterfaceDB::fetchColumn(
                "SELECT id FROM photos WHERE original_sha256 = :sha256 LIMIT 1",
                ['sha256' => (string)$photo[1]]
            );
            swallowtail_ui_record_asset($photoId, (string)$imageType, $absolute, str_repeat($imageType === 'preview' ? 'a' : 'b', 64));
        }
    }

    $rowsByName = [];
    foreach ((new SwallowtailPhotoUiService())->accessiblePhotos(902)['rows'] as $row) {
        $rowsByName[(string)($row['original_filename'] ?? '')] = $row;
    }

    $harness->assertSame(true, (bool)$rowsByName['preview.CR2']['preview_ready']);
    $harness->assertSame(false, (bool)$rowsByName['preview.CR2']['thumbnail_ready']);
    $harness->assertSame(false, array_key_exists('original_ready', $rowsByName['preview.CR2']));
    $harness->assertSame(false, array_key_exists('final_ready', $rowsByName['preview.CR2']));
    $harness->assertSame(false, (bool)$rowsByName['thumbnail.CR2']['preview_ready']);
    $harness->assertSame(true, (bool)$rowsByName['thumbnail.CR2']['thumbnail_ready']);
    $harness->assertSame(false, (bool)$rowsByName['pending.CR2']['preview_ready']);
    $harness->assertSame(false, (bool)$rowsByName['pending.CR2']['thumbnail_ready']);
});

$harness->check(SwallowtailPhotoUiService::class, 'resolves only authorized private image assets', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();
    $storage = new SwallowtailStorageService();
    $baseLocation = swallowtail_ui_storage_tmp_root();
    $sha256 = str_repeat('d', 64);
    $absolute = $storage->imagePath($baseLocation, $sha256, 'preview');
    $thumbnailAbsolute = $storage->imagePath($baseLocation, $sha256, 'thumbnail');
    $finalAbsolute = $storage->imagePath($baseLocation, $sha256, 'final');
    $storage->ensureDirectoryForPath($absolute);
    file_put_contents($absolute, "\xff\xd8\xff\xd9", LOCK_EX);
    file_put_contents($thumbnailAbsolute, "\xff\xd8\xff\xd9", LOCK_EX);
    file_put_contents($finalAbsolute, "\xff\xd8\xff\xd9", LOCK_EX);

    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            uploaded_by_user_id,
            uploaded_via
        ) VALUES (
            'asset.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            902,
            'web'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    $photoId = (int)InterfaceDB::fetchColumn("SELECT id FROM photos WHERE original_filename = 'asset.CR2'");
    swallowtail_ui_record_asset($photoId, 'preview', $absolute, str_repeat('c', 64));
    swallowtail_ui_record_asset($photoId, 'thumbnail', $thumbnailAbsolute, str_repeat('d', 64));
    swallowtail_ui_record_asset($photoId, 'final', $finalAbsolute, str_repeat('e', 64));
    $library = new SwallowtailPhotoLibraryService();
    $event = $library->createEvent('Private Asset Gallery');
    $eventId = (int)($event['id'] ?? 0);
    $library->assignPhotoToEvent($photoId, $eventId);
    $library->grantEventPermission($eventId, 903, ['can_view' => true]);

    $service = new SwallowtailPhotoUiService();
    $asset = $service->photoAsset($photoId, 902, 'preview');
    $thumbnail = $service->photoAsset($photoId, 902, 'thumbnail');
    $viewerPreview = $service->photoAsset($photoId, 903, 'preview');
    $viewerFinalDenied = $service->photoAsset($photoId, 903, 'final');
    $denied = $service->photoAsset($photoId, 904, 'preview');

    $harness->assertTrue(is_array($asset));
    $harness->assertSame($absolute, (string)$asset['path']);
    $harness->assertSame('preview', (string)$asset['image_type']);
    $harness->assertTrue(is_array($thumbnail));
    $harness->assertSame($thumbnailAbsolute, (string)$thumbnail['path']);
    $harness->assertSame('thumbnail', (string)$thumbnail['image_type']);
    $harness->assertTrue(is_array($viewerPreview));
    $harness->assertSame($absolute, (string)$viewerPreview['path']);
    $harness->assertSame(null, $viewerFinalDenied);

    $library->grantEventPermission($eventId, 903, [
        'can_view' => true,
        'can_download_single_jpeg' => true,
    ]);
    $viewerFinal = $service->photoAsset($photoId, 903, 'final');
    $harness->assertTrue(is_array($viewerFinal));
    $harness->assertSame($finalAbsolute, (string)$viewerFinal['path']);
    $harness->assertSame('final', (string)$viewerFinal['image_type']);
    $harness->assertSame(null, $denied);
});

$harness->check(SwallowtailPhotoUiService::class, 'resolves final downloads to original asset when profiles match', function () use ($harness, $swallowtailUiCreateSchema): void {
    $swallowtailUiCreateSchema();

    $baseLocation = swallowtail_ui_storage_tmp_root();
    $sha256 = str_repeat('f', 64);
    $storage = new SwallowtailStorageService();
    $originalAbsolute = $storage->imagePath($baseLocation, $sha256, 'original');
    $storage->ensureDirectoryForPath($originalAbsolute);
    file_put_contents($originalAbsolute, "\xff\xd8\xff\xd9", LOCK_EX);

    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            uploaded_by_user_id,
            uploaded_via,
            upload_state,
            conversion_state
        ) VALUES (
            'fallback.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            902,
            'web',
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    $photoId = (int)InterfaceDB::fetchColumn("SELECT id FROM photos WHERE original_filename = 'fallback.CR2'");
    swallowtail_ui_record_asset($photoId, 'original', $originalAbsolute, str_repeat('a', 64));
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
            (:photo_id_status, 0, 'swallowtail', 'status', 'processed', 'string')",
        [
            'photo_id_status' => $photoId,
        ]
    );

    $library = new SwallowtailPhotoLibraryService();
    $event = $library->createEvent('Final Fallback Asset Gallery');
    $eventId = (int)($event['id'] ?? 0);
    $library->assignPhotoToEvent($photoId, $eventId);
    $library->grantEventPermission($eventId, 903, [
        'can_view' => true,
        'can_download_single_jpeg' => true,
    ]);

    $service = new SwallowtailPhotoUiService();
    $asset = $service->photoAsset($photoId, 903, 'final');
    $details = $service->photoDetails($photoId, 903);

    $harness->assertTrue(is_array($asset));
    $harness->assertSame($originalAbsolute, (string)$asset['path']);
    $harness->assertSame('final', (string)$asset['image_type']);
    $harness->assertSame('original', (string)$asset['source_image_type']);
    $harness->assertSame('original', (string)$asset['effective_image_type']);
    $harness->assertSame(true, (bool)($asset['final_equivalent_original'] ?? false));
    $harness->assertTrue(str_ends_with((string)$asset['filename'], '-final.jpg'));
    $harness->assertTrue(is_array($details));
    $harness->assertSame(true, (bool)($details['final_ready'] ?? false));
});

