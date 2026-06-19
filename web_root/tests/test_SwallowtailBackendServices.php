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

function swallowtail_backend_remove_tree(string $path): void
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

function swallowtail_backend_test_tmp_root(): string
{
    return APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'swallowtail-backend';
}

function swallowtail_backend_storage_tmp_root(): string
{
    return PROJECT_ROOT . 'tmp' . DIRECTORY_SEPARATOR . 'swallowtail-storage' . DIRECTORY_SEPARATOR . 'backend';
}

function swallowtail_backend_test_temp_file(string $prefix): string
{
    $root = swallowtail_backend_test_tmp_root();
    if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create SwallowTail backend test temp directory.');
    }

    $path = tempnam($root, $prefix);
    if (!is_string($path)) {
        throw new RuntimeException('Unable to create SwallowTail backend test temp file.');
    }

    return $path;
}

register_shutdown_function(static function (): void {
    swallowtail_backend_remove_tree(swallowtail_backend_test_tmp_root());
    swallowtail_backend_remove_tree(swallowtail_backend_storage_tmp_root());
});

$swallowtailEnableRootStorageForTests = static function (): void {
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
                $storageRoot = swallowtail_backend_storage_tmp_root();
                if (!is_dir($storageRoot) && !@mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
                    throw new RuntimeException('Unable to create SwallowTail backend storage test directory.');
                }

                AppConfigurationStore::set('swallowtail.storage.test_base_location', $storageRoot);
                AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', false);
                AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 0);
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

            AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', false);
            AppConfigurationStore::set('swallowtail.storage.round_robin_locations', false);
            AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 5);
            AppConfigurationStore::set('swallowtail.storage.test_base_location', '');
        });
    }

    $storageRoot = swallowtail_backend_storage_tmp_root();
    if (!is_dir($storageRoot) && !@mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('Unable to create SwallowTail backend storage test directory.');
    }

    AppConfigurationStore::set('swallowtail.storage.test_base_location', $storageRoot);
    AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', false);
    AppConfigurationStore::set('swallowtail.storage.round_robin_locations', false);
    AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 0);
};

$swallowtailCreateSqliteSchema = static function () use ($swallowtailEnableRootStorageForTests): void {
    $swallowtailEnableRootStorageForTests();
    InterfaceDB::execute('PRAGMA foreign_keys = OFF');

    foreach ([
        'photo_audit',
        'storage_migration_job_items',
        'storage_migration_jobs',
        'photo_conversion_jobs',
        'event_permissions',
        'event_photos',
        'photos',
        'storage_location_properties',
        'api_upload_token_cidrs',
        'api_upload_tokens',
        'signup_token_rate_limits',
        'events',
    ] as $table) {
        InterfaceDB::execute('DROP TABLE IF EXISTS ' . $table);
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
        original_quick_hash TEXT NULL,
        storage_base_location TEXT NOT NULL,
        upload_state TEXT NOT NULL DEFAULT 'uploaded',
        conversion_state TEXT NOT NULL DEFAULT 'pending',
        uploaded_by_user_id INTEGER NULL,
        uploaded_via TEXT NOT NULL DEFAULT 'api',
        upload_token_id INTEGER NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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

    InterfaceDB::execute("CREATE TABLE photo_conversion_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        job_type TEXT NOT NULL,
        image_type TEXT NULL,
        input_path TEXT NULL,
        profile_path TEXT NULL,
        output_path TEXT NULL,
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
        destination_base_location TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'queued',
        file_count INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NULL,
        completed_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
};

$swallowtailWriteRawFixture = static function (string $path, string $extension = 'cr2'): void {
    $bytes = $extension === 'cr2'
        ? "II*\0\x10\x00\x00\x00CR\2\0" . str_repeat('A', 128)
        : "\0\0\0\x18ftypcrx " . str_repeat('B', 128);

    file_put_contents($path, $bytes, LOCK_EX);
};

$swallowtailCleanupStorageFiles = static function (string $storageBaseLocation, string $checksum): void {
    $storage = new SwallowtailStorageService();
    foreach (SwallowtailStorageService::IMAGE_TYPES as $imageType) {
        try {
            @unlink($storage->imagePath($storageBaseLocation, $checksum, $imageType));
        } catch (Throwable) {
        }
    }

    try {
        $folder = dirname($storage->imagePath($storageBaseLocation, $checksum, 'source'));
        @rmdir($folder);
        @rmdir(dirname($folder));
    } catch (Throwable) {
    }
};

$swallowtailWithRootStorage = static function (callable $callback) use ($harness): mixed {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', true);
        AppConfigurationStore::set('swallowtail.storage.round_robin_locations', false);
        AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 0);

        $storage = new SwallowtailStorageService();
        $locations = $storage->storageLocations();
        if ($locations === []) {
            $harness->skip('No mounted storage location was discovered.');
        }

        return $callback($storage, (string)$locations[0]['storage_base_location']);
    } finally {
        file_put_contents($configPath, $originalConfig, LOCK_EX);
        AppConfigurationStore::config(true);
    }
};

$swallowtailCreateSpiceBushUserSchema = static function (): void {
    InterfaceDB::execute('DROP TABLE IF EXISTS application_activity_flash_history');
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
    InterfaceDB::execute("CREATE TABLE application_activity_flash_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        page_id TEXT NOT NULL,
        action_name TEXT NULL,
        card_action_name TEXT NULL,
        message_type TEXT NOT NULL,
        message_text TEXT NOT NULL,
        message_html_text TEXT NULL,
        request_method TEXT NULL,
        is_ajax INTEGER NOT NULL DEFAULT 0,
        device_id TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        request_uri TEXT NULL,
        occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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

$swallowtailInvokeRawUploadApi = static function (array $server, array $files): array {
    $originalGet = $_GET;
    $originalPost = $_POST;
    $originalServer = $_SERVER;
    $originalFiles = $_FILES;
    $originalCookie = $_COOKIE;
    $originalStatus = http_response_code();
    $bufferLevel = ob_get_level();

    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_FILES = $files;
    $_SERVER = array_merge([
        'REQUEST_METHOD' => 'POST',
        'REMOTE_ADDR' => '203.0.113.15',
        'REQUEST_URI' => '/api/raw-upload.php',
        'SCRIPT_NAME' => '/api/raw-upload.php',
    ], $server);

    http_response_code(200);
    ob_start();

    try {
        require APP_ROOT . 'api' . DIRECTORY_SEPARATOR . 'raw-upload.php';
        $body = (string)ob_get_clean();
        $status = (int)http_response_code();
    } finally {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }

        $_GET = $originalGet;
        $_POST = $originalPost;
        $_SERVER = $originalServer;
        $_FILES = $originalFiles;
        $_COOKIE = $originalCookie;
        http_response_code(is_int($originalStatus) && $originalStatus > 0 ? $originalStatus : 200);
    }

    return [
        'status' => $status,
        'body' => $body,
        'payload' => json_decode($body, true),
    ];
};

$swallowtailAssertContains = static function (string $needle, mixed $haystack, string $label): void {
    $value = (string)$haystack;
    if (!str_contains($value, $needle)) {
        throw new RuntimeException($label . ' did not contain ' . var_export($needle, true) . '; value=' . var_export($value, true));
    }
};

$harness->check(SwallowtailStorageService::class, 'builds deterministic image paths under discovered storage', function () use ($harness, $swallowtailWithRootStorage): void {
    $swallowtailWithRootStorage(static function (SwallowtailStorageService $service, string $baseLocation) use ($harness): void {
        $sha256 = str_repeat('a', 64);
        $sourcePath = $service->imagePath($baseLocation, $sha256, 'source');
        $profilePath = $service->imagePath($baseLocation, $sha256, 'profile');
        $filteredPath = $service->imagePath($baseLocation, $sha256, 'filtered');

        $harness->assertTrue(str_contains($sourcePath, DIRECTORY_SEPARATOR . 'swallowtail-data' . DIRECTORY_SEPARATOR . 'aa' . DIRECTORY_SEPARATOR . 'aa' . DIRECTORY_SEPARATOR));
        $harness->assertTrue(str_ends_with($sourcePath, $sha256 . '_source.cr2'));
        $harness->assertTrue(str_ends_with($profilePath, $sha256 . '_profile.pp3'));
        $harness->assertTrue(str_ends_with($filteredPath, $sha256 . '_filtered.jpg'));
        $harness->assertTrue(!str_starts_with($sourcePath, APP_ROOT));
    });
});

$harness->check(SwallowtailStorageService::class, 'marks invalid storage bases unwritable before upload', function () use ($harness, $swallowtailAssertContains): void {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    $blockedBaseRoot = dirname(swallowtail_backend_storage_tmp_root());
    if (!is_dir($blockedBaseRoot) && !@mkdir($blockedBaseRoot, 0770, true) && !is_dir($blockedBaseRoot)) {
        throw new RuntimeException('Unable to create blocked storage base fixture directory.');
    }

    $blockedBase = tempnam($blockedBaseRoot, 'swallowtail-storage-base-file-');
    if (!is_string($blockedBase)) {
        throw new RuntimeException('Unable to create blocked storage base fixture.');
    }

    try {
        AppConfigurationStore::set('swallowtail.storage.test_base_location', $blockedBase);
        AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', false);
        AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 0);
        (new SwallowtailStorageCacheService())->clear();

        $matching = array_values(array_filter(
            (new SwallowtailStorageService())->liveStorageLocations(),
            static fn(array $location): bool => str_contains(
                (string)($location['storage_base_location'] ?? ''),
                basename($blockedBase)
            )
        ));
        if ($matching === []) {
            throw new RuntimeException('Expected invalid test storage base to be discovered.');
        }

        $location = $matching[0];
        $harness->assertSame(false, !empty($location['permission_can_write']));
        $harness->assertSame(false, !empty($location['can_write']));
        $swallowtailAssertContains('not a directory', (string)($location['permission_error'] ?? ''), 'storage permission error');
    } finally {
        file_put_contents($configPath, $originalConfig, LOCK_EX);
        AppConfigurationStore::config(true);
        (new SwallowtailStorageCacheService())->clear();
        @unlink($blockedBase);
    }
});

$harness->check(SwallowtailStorageService::class, 'verifies active live storage locations are writable by PHP', function () use ($harness): void {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        AppConfigurationStore::set('swallowtail.storage.test_base_location', '');
        AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', false);
        AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 0);
        (new SwallowtailStorageCacheService())->clear();

        $locations = (new SwallowtailStorageService())->liveStorageLocations();
        $nonExcluded = array_values(array_filter($locations, static function (array $location): bool {
            $baseLocation = rtrim((string)($location['storage_base_location'] ?? ''), DIRECTORY_SEPARATOR);

            return $baseLocation !== ''
                && is_dir($baseLocation)
                && empty($location['is_excluded']);
        }));

        if ($nonExcluded === []) {
            $harness->skip('No non-excluded live storage locations exist on this development machine.');
        }

        $failures = [];
        foreach ($nonExcluded as $location) {
            $baseLocation = (string)($location['storage_base_location'] ?? '');
            if (empty($location['permission_can_write'])) {
                if (!empty($location['can_write'])) {
                    $failures[] = sprintf(
                        'base=%s failed permission checks but was marked writable',
                        $baseLocation
                    );
                }
                if (trim((string)($location['permission_error'] ?? '')) === '') {
                    $failures[] = sprintf(
                        'base=%s failed permission checks without a permission error',
                        $baseLocation
                    );
                }
                continue;
            }

            $shouldBeWritable = empty($location['is_full'])
                && (empty($location['is_zfs']) || !empty($location['is_selected_zfs_dataset']));
            if ($shouldBeWritable && empty($location['can_write'])) {
                $failures[] = sprintf(
                    'base=%s passed permission checks but was not marked writable',
                    $baseLocation
                );
            }
        }

        if ($failures !== []) {
            throw new RuntimeException('Live storage location permission checks failed: ' . implode('; ', $failures));
        }
    } finally {
        file_put_contents($configPath, $originalConfig, LOCK_EX);
        AppConfigurationStore::config(true);
        (new SwallowtailStorageCacheService())->clear();
    }
});

$harness->check(SwallowtailStorageService::class, 'reports storage mkdir failures without PHP warnings', function () use ($harness, $swallowtailAssertContains): void {
    $blockedBaseRoot = dirname(swallowtail_backend_storage_tmp_root());
    if (!is_dir($blockedBaseRoot) && !@mkdir($blockedBaseRoot, 0770, true) && !is_dir($blockedBaseRoot)) {
        throw new RuntimeException('Unable to create blocked storage base fixture directory.');
    }

    $blockedBase = tempnam($blockedBaseRoot, 'swallowtail-storage-base-file-');
    if (!is_string($blockedBase)) {
        throw new RuntimeException('Unable to create blocked storage base fixture.');
    }

    $storage = new SwallowtailStorageService();
    $destinationPath = $storage->imagePath($blockedBase, str_repeat('b', 64), 'source');
    $bufferLevel = ob_get_level();
    ob_start();

    try {
        try {
            $storage->ensureDirectoryForPath($destinationPath);
            throw new RuntimeException('Expected storage mkdir failure.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $swallowtailAssertContains('Unable to create SwallowTail storage directory', $message, 'storage mkdir failure');
            $swallowtailAssertContains('php_error=', $message, 'storage mkdir failure');
        }

        $output = (string)ob_get_clean();
        $harness->assertSame('', trim($output));
    } finally {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }

        @unlink($blockedBase);
    }
});

$harness->check(SwallowtailStorageService::class, 'creates storage hash directories writable by the service group', function () use ($harness): void {
    if (DIRECTORY_SEPARATOR === '\\') {
        $harness->skip('POSIX directory modes are not available on Windows.');
    }

    $storageRoot = swallowtail_backend_storage_tmp_root();
    if (!is_dir($storageRoot) && !@mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('Unable to create SwallowTail backend storage test directory.');
    }

    $storage = new SwallowtailStorageService();
    $destinationPath = $storage->imagePath($storageRoot, str_repeat('c', 64), 'source');
    $previousUmask = umask(0022);
    try {
        $storage->ensureDirectoryForPath($destinationPath);
    } finally {
        umask($previousUmask);
    }

    $directoryMode = fileperms(dirname($destinationPath));
    if (!is_int($directoryMode)) {
        throw new RuntimeException('Unable to inspect storage hash directory permissions.');
    }

    $harness->assertSame(02770, $directoryMode & 07777);
});

$harness->check(SwallowtailStorageService::class, 'reports storage file write failures without PHP warnings', function () use ($harness, $swallowtailAssertContains, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-storage-write-failure-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    try {
        $swallowtailWriteRawFixture($source, 'cr2');
        $checksum = hash_file('sha256', $source);
        if (!is_string($checksum)) {
            throw new RuntimeException('Unable to hash RAW fixture.');
        }

        $storage = new SwallowtailStorageService();
        $baseLocation = swallowtail_backend_storage_tmp_root();
        AppConfigurationStore::set('swallowtail.storage.test_base_location', $baseLocation);
        AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', false);
        (new SwallowtailStorageCacheService())->clear();

        $destinationPath = $storage->imagePath($baseLocation, $checksum, 'source');
        $storage->ensureDirectoryForPath($destinationPath);
        if (!@mkdir($destinationPath, 0770, true) && !is_dir($destinationPath)) {
            throw new RuntimeException('Unable to create blocked storage destination fixture.');
        }

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            try {
                $storage->storeSourceFile($source, $checksum);
                throw new RuntimeException('Expected storage file write failure.');
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
                $swallowtailAssertContains('No writable SwallowTail storage location available', $message, 'storage write failure');
            }

            $output = (string)ob_get_clean();
            $harness->assertSame('', trim($output));
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
    } finally {
        AppConfigurationStore::set('swallowtail.storage.test_base_location', '');
        (new SwallowtailStorageCacheService())->clear();
        @unlink($source);
        swallowtail_backend_remove_tree(swallowtail_backend_storage_tmp_root());
    }
});

$harness->check(SwallowtailStorageMigrationService::class, 'moves checksum file families after SHA-256 verification', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();
    $storage = new SwallowtailStorageService();
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'swallowtail-migrate-' . bin2hex(random_bytes(4));
    $sourceBase = $root . DIRECTORY_SEPARATOR . 'source';
    $destinationBase = $root . DIRECTORY_SEPARATOR . 'destination';
    $sha256 = str_repeat('e', 64);

    try {
        $sourcePath = $storage->imagePath($sourceBase, $sha256, 'source');
        $thumbnailPath = $storage->imagePath($sourceBase, $sha256, 'thumbnail');
        $storage->ensureDirectoryForPath($sourcePath);
        file_put_contents($sourcePath, 'source-bytes', LOCK_EX);
        file_put_contents($thumbnailPath, 'thumbnail-bytes', LOCK_EX);

        InterfaceDB::prepareExecute(
            "INSERT INTO photos (
                original_filename,
                original_extension,
                original_bytes,
                original_sha256,
                storage_base_location
            ) VALUES (
                'IMG_MOVE.CR2',
                'cr2',
                12,
                :sha256,
                :storage_base_location
            )",
            [
                'sha256' => $sha256,
                'storage_base_location' => rtrim($sourceBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            ]
        );
        $photoId = (int)InterfaceDB::fetchColumn('SELECT MAX(id) FROM photos');

        $jobId = (new SwallowtailStorageMigrationService())->enqueue($sourceBase, $destinationBase, null, null, null);
        $harness->assertTrue((int)$jobId > 0);
        $processed = (new SwallowtailStorageMigrationService())->processPending(1);

        $harness->assertSame(1, $processed);
        $row = InterfaceDB::fetchOne('SELECT storage_base_location FROM photos WHERE id = :id', ['id' => $photoId]);
        $harness->assertSame(rtrim($destinationBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, (string)($row['storage_base_location'] ?? ''));
        $harness->assertTrue(is_file($storage->imagePath($destinationBase, $sha256, 'source')));
        $harness->assertTrue(is_file($storage->imagePath($destinationBase, $sha256, 'thumbnail')));
        $harness->assertTrue(!is_file($sourcePath));
        $harness->assertTrue(!is_file($thumbnailPath));
        $harness->assertSame(1, InterfaceDB::countWhere('photo_audit', [
            'photo_id' => $photoId,
            'action_type' => 'storage_location_migrated',
        ]));
    } finally {
        foreach ([$sourceBase, $destinationBase] as $base) {
            foreach (SwallowtailStorageService::IMAGE_TYPES as $imageType) {
                try {
                    @unlink($storage->imagePath($base, $sha256, $imageType));
                } catch (Throwable) {
                }
            }
            try {
                $folder = dirname($storage->imagePath($base, $sha256, 'source'));
                @rmdir($folder);
                @rmdir(dirname($folder));
                @rmdir($base . DIRECTORY_SEPARATOR . SwallowtailStorageService::DATA_DIRECTORY);
                @rmdir($base);
            } catch (Throwable) {
            }
        }
        @rmdir($root);
    }
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
    $harness->assertSame(67108864, $uploadLimited->maxRawBodyBytes());
    $harness->assertSame(52428800, $postLimited->maxRawBodyBytes());
    $harness->assertSame($appLimit, $unlimited->maxRawBodyBytes());

    $source = swallowtail_backend_test_temp_file('swallowtail-limit-');
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

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService(),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );

    $result = $ingest->ingestLocalRawFile($source, 'IMG_0001.CR2', ['uploaded_via' => 'api']);

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame('uploaded', $result['status']);
    $harness->assertTrue((int)$result['photo_id'] > 0);
    $storage = new SwallowtailStorageService();
    $harness->assertTrue(is_file($storage->imagePath((string)$result['storage_base_location'], (string)$result['sha256'], 'source')));
    $harness->assertTrue((string)$result['storage_base_location'] !== '');
    $harness->assertSame(0, InterfaceDB::countWhere('event_photos', 'photo_id', (int)$result['photo_id']));
    $harness->assertSame(3, InterfaceDB::countWhere('photo_conversion_jobs', 'photo_id', (int)$result['photo_id']));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'image_type' => 'embedded',
        'priority' => 'high',
    ]));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'image_type' => 'thumbnail',
    ]));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'image_type' => 'original',
    ]));
    $harness->assertCount(3, (array)($result['conversion_jobs'] ?? []));
    $harness->assertTrue((int)(($result['conversion_jobs']['embedded'] ?? [])['job_id'] ?? 0) > 0);
    $thumbnail = InterfaceDB::fetchOne(
        "SELECT output_width, output_height
         FROM photo_conversion_jobs
         WHERE photo_id = :photo_id
           AND image_type = 'thumbnail'
         LIMIT 1",
        ['photo_id' => (int)$result['photo_id']]
    );
    $harness->assertSame(512, (int)($thumbnail['output_width'] ?? 0));
    $harness->assertSame(512, (int)($thumbnail['output_height'] ?? 0));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_audit', 'action_type', 'raw_uploaded'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoIngestService::class, 'rejects CR3 files while conversion is CR2-only', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr3');
    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService(),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0001.CR3', ['uploaded_via' => 'api']);

    $harness->assertTrue(empty($result['success']));
    $harness->assertTrue(str_contains(implode(' ', (array)($result['errors'] ?? [])), '.CR2'));
    $harness->assertSame(0, InterfaceDB::tableRowCount('photos'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoIngestService::class, 'detects duplicate RAW uploads by checksum', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $first = swallowtail_backend_test_temp_file('swallowtail-test-');
    $second = swallowtail_backend_test_temp_file('swallowtail-test-');

    if (!is_string($first) || !is_string($second)) {
        throw new RuntimeException('Unable to create RAW fixtures.');
    }

    $swallowtailWriteRawFixture($first, 'cr2');
    copy($first, $second);

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService(),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );

    $created = $ingest->ingestLocalRawFile($first, 'IMG_0002.CR2');
    $duplicate = $ingest->ingestLocalRawFile($second, 'RENAMED.CR2');

    $harness->assertTrue(!empty($created['success']));
    $harness->assertTrue(!empty($duplicate['success']));
    $harness->assertSame('duplicate', $duplicate['status']);
    $harness->assertSame((int)$created['photo_id'], (int)$duplicate['photo_id']);
    $harness->assertSame(1, InterfaceDB::tableRowCount('photos'));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_audit', 'action_type', 'raw_duplicate_detected'));

    @unlink($first);
    @unlink($second);
});

$harness->check(SwallowtailQuickChecksumApiService::class, 'reports whether a CR2 quick checksum already exists', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('Checksum token', null, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $result = (new SwallowtailPhotoIngestService(
        new SwallowtailStorageService(),
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
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));

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
    $service = new SwallowtailPingApiService(
        $library,
        new SwallowtailPhotoIngestService(
            new SwallowtailStorageService(),
            $library,
            new SwallowtailConversionQueueService(),
            1024 * 1024 * 1024,
            ['upload_max_filesize' => '8M', 'post_max_size' => '64M']
        )
    );

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
    $harness->assertSame(8388608, (int)($successPayload['max_raw_upload_bytes'] ?? 0));

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
        $harness->assertSame(0, InterfaceDB::tableRowCount('api_upload_tokens'));
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
        $harness->assertSame(1, InterfaceDB::tableRowCount('api_upload_tokens'));
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
        $harness->assertSame(0, InterfaceDB::tableRowCount('api_upload_tokens'));

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
        $harness->assertSame(0, InterfaceDB::tableRowCount('api_upload_tokens'));
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
        $harness->assertSame(1, InterfaceDB::tableRowCount('api_upload_tokens'));
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
        $harness->assertSame(0, InterfaceDB::tableRowCount('api_upload_tokens'));
    } finally {
        if (is_file($securityPath)) {
            unlink($securityPath);
        }
    }
});

$harness->check(SwallowtailEventAccessService::class, 'keeps event access least privilege until granted', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService(), $library, new SwallowtailConversionQueueService());
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

$harness->check(SwallowtailImageServeService::class, 'resolves only authorised private image files', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0008.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $thumbnailPath = $storage->imagePath((string)($photo['storage_base_location'] ?? ''), $sha256, 'thumbnail');
    $storage->ensureDirectoryForPath($thumbnailPath);
    file_put_contents($thumbnailPath, "\xFF\xD8\xFF\xD9", LOCK_EX);

    $event = $library->createEvent('Private Gallery');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $service = new SwallowtailImageServeService();

    $harness->assertSame(null, $service->derivativeImage($photoId, 'thumbnail', 202));

    $library->grantEventPermission((int)$event['id'], 202, ['can_view' => true]);
    $image = $service->derivativeImage($photoId, 'thumbnail', 202);

    $harness->assertTrue(is_array($image));
    $harness->assertSame($thumbnailPath, (string)$image['absolute_path']);
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
    $content = $service->pp3Content($settings);

    $harness->assertSame(0, (int)$settings['crop']['x']);
    $harness->assertSame(490, (int)$settings['crop']['y']);
    $harness->assertSame(600, (int)$settings['crop']['width']);
    $harness->assertSame(10, (int)$settings['crop']['height']);
    $harness->assertSame(-100.0, (float)$settings['exposure']['black']);
    $harness->assertSame(100.0, (float)$settings['exposure']['contrast']);
    $harness->assertTrue(str_contains($content, "[Exposure]\nAuto=false\nBlack=-100\nBrightness=25.5\nContrast=100\nSaturation=-12.25"));
    $harness->assertTrue(str_contains($content, "[Crop]\nEnabled=true\nX=0\nY=490\nW=600\nH=10"));
    $harness->assertTrue(!str_contains($content, "[Resize]"));
});

$harness->check(SwallowtailPreviewProfileService::class, 'queues authorised PP3 preview refresh outside web root', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService(), $library, new SwallowtailConversionQueueService());
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
    $harness->assertSame(1, (int)($queued['profile_version'] ?? 0));
    $harness->assertTrue((int)($queued['job_id'] ?? 0) > 0);
    $harness->assertTrue(str_contains((string)($queued['preview_url'] ?? ''), 'v=1'));
    $harness->assertTrue(str_contains((string)($queued['status_url'] ?? ''), 'profile_version=1'));

    $job = InterfaceDB::fetchOne(
        "SELECT profile_path, profile_version, requested_by_user_id, priority, output_width, output_height
         FROM photo_conversion_jobs
         WHERE id = :id",
        ['id' => (int)$queued['job_id']]
    );

    $harness->assertTrue(is_array($job));
    $profilePath = (string)($job['profile_path'] ?? '');
    $harness->assertSame(1, (int)($job['profile_version'] ?? 0));
    $harness->assertSame(303, (int)($job['requested_by_user_id'] ?? 0));
    $harness->assertSame('high', (string)($job['priority'] ?? ''));
    $harness->assertSame(0, (int)($job['output_width'] ?? 0));
    $harness->assertSame(0, (int)($job['output_height'] ?? 0));
    $harness->assertTrue($profilePath !== '');
    $harness->assertTrue(is_file($profilePath));
    $harness->assertTrue(!str_starts_with($profilePath, APP_ROOT));
    $harness->assertTrue(str_ends_with($profilePath, '_profile.pp3'));
    $harness->assertTrue(str_contains((string)file_get_contents($profilePath), "[Crop]\nEnabled=true\nX=10\nY=20\nW=100\nH=120"));

    InterfaceDB::prepareExecute(
        "UPDATE photo_conversion_jobs
         SET status = 'succeeded'
         WHERE id = :id",
        ['id' => (int)$queued['job_id']]
    );
    $status = $service->previewStatus($photoId, (int)$queued['job_id'], 1, 303);
    $harness->assertTrue(!empty($status['success']));
    $harness->assertSame('succeeded', (string)($status['status'] ?? ''));
    $harness->assertTrue(str_contains((string)($status['preview_url'] ?? ''), 'job_id=' . (string)$queued['job_id']));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'accepts token authenticated multipart RAW uploads through API entrypoint', function () use ($harness, $swallowtailCleanupStorageFiles, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema, $swallowtailInvokeRawUploadApi, $swallowtailWriteRawFixture): void {
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
    $token = $library->createUploadToken('SpiceBush test rig', 44, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $quickHash = hash_file(SwallowtailPhotoLibraryService::QUICK_HASH_ALGORITHM, $source);
    if (!is_string($quickHash)) {
        throw new RuntimeException('Unable to quick hash RAW fixture.');
    }

    $response = $swallowtailInvokeRawUploadApi([
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token['token'],
        'HTTP_USER_AGENT' => 'spicebush-test',
        'HTTP_X_SWALLOWTAIL_DEVICE_ID' => 'DESKTOP-C6R0CCD',
        'HTTP_X_SWALLOWTAIL_QUICK_CHECKSUM_FNV1A64' => $quickHash,
    ], [
        'raw_file' => [
            'tmp_name' => $source,
            'name' => 'IMG_0004.CR2',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ],
    ]);

    $payload = $response['payload'];
    $harness->assertSame(201, $response['status']);
    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(!empty($payload['success']));
    $harness->assertSame('uploaded', $payload['status'] ?? null);
    $harness->assertSame($quickHash, (string)($payload['quick_hash'] ?? ''));
    $harness->assertCount(3, (array)($payload['conversion_jobs'] ?? []));
    $harness->assertTrue((int)(($payload['conversion_jobs']['thumbnail'] ?? [])['job_id'] ?? 0) > 0);
    $harness->assertSame(1, InterfaceDB::tableRowCount('photos'));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));

    $checksum = (string)($payload['sha256'] ?? '');
    if ($checksum !== '') {
        $row = InterfaceDB::fetchOne('SELECT storage_base_location, original_quick_hash FROM photos WHERE original_sha256 = :checksum LIMIT 1', ['checksum' => $checksum]);
        if (is_array($row)) {
            $harness->assertSame($quickHash, (string)($row['original_quick_hash'] ?? ''));
            $swallowtailCleanupStorageFiles((string)($row['storage_base_location'] ?? ''), $checksum);
        }
    }

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'records authenticated RAW storage failures in account audit and activity logs', function () use ($harness, $swallowtailAssertContains, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema, $swallowtailInvokeRawUploadApi, $swallowtailWriteRawFixture): void {
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
    $token = $library->createUploadToken('SpiceBush storage test', 44, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-storage-failure-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');

    $blockedBase = swallowtail_backend_storage_tmp_root();
    $checksum = hash_file('sha256', $source);
    if (!is_string($checksum)) {
        throw new RuntimeException('Unable to hash RAW fixture.');
    }

    $storage = new SwallowtailStorageService();
    $destinationPath = $storage->imagePath($blockedBase, $checksum, 'source');
    $storage->ensureDirectoryForPath($destinationPath);
    if (!@mkdir($destinationPath, 0770, true) && !is_dir($destinationPath)) {
        throw new RuntimeException('Unable to create blocked storage destination fixture.');
    }

    AppConfigurationStore::set('swallowtail.storage.test_base_location', $blockedBase);
    AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', false);
    (new SwallowtailStorageCacheService())->clear();

    $response = $swallowtailInvokeRawUploadApi([
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token['token'],
        'HTTP_USER_AGENT' => 'spicebush-test',
        'HTTP_X_SWALLOWTAIL_DEVICE_ID' => 'DESKTOP-C6R0CCD',
    ], [
        'raw_file' => [
            'tmp_name' => $source,
            'name' => 'TEST.CR2',
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ],
    ]);
    $payload = $response['payload'];
    $auditRows = (new UserHistoryStore())->fetchRecentAccountAudit(10);
    $details = json_decode((string)($auditRows[0]['details_json'] ?? ''), true);
    $activityRows = InterfaceDB::fetchAll(
        'SELECT *
         FROM application_activity_flash_history
         WHERE user_id = :user_id AND page_id = :page_id
         ORDER BY occurred_at DESC, id DESC',
        [
            'user_id' => 44,
            'page_id' => 'api',
        ]
    );
    $logsRows = (new LogsRepository())->fetchRecentFlashActivity(10, 44, 'api');

    $harness->assertSame(503, $response['status']);
    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(empty($payload['success']));
    $harness->assertSame('No writable storage locations available.', (string)(($payload['errors'] ?? [])[0] ?? ''));
    $swallowtailAssertContains('No writable SwallowTail storage location available', ($payload['diagnostics'] ?? [])['storage_error'] ?? '', 'RAW upload diagnostics.storage_error');
    $harness->assertSame('upload_token_raw_upload_failed', (string)($auditRows[0]['action_type'] ?? ''));
    $harness->assertSame('No writable storage locations available.', (string)($auditRows[0]['reason'] ?? ''));
    $harness->assertTrue(is_array($details));
    $harness->assertSame('TEST.CR2', (string)($details['original_filename'] ?? ''));
    $swallowtailAssertContains('No writable SwallowTail storage location available', $details['storage_error'] ?? '', 'account audit storage_error detail');
    $harness->assertSame('DESKTOP-C6R0CCD', (string)($auditRows[0]['device_id'] ?? ''));
    $harness->assertCount(1, $activityRows);
    $harness->assertSame(44, (int)($activityRows[0]['user_id'] ?? 0));
    $harness->assertSame('api', (string)($activityRows[0]['page_id'] ?? ''));
    $harness->assertSame('raw upload failed', (string)($activityRows[0]['action_name'] ?? ''));
    $harness->assertSame('SpiceBush storage test', (string)($activityRows[0]['card_action_name'] ?? ''));
    $harness->assertSame('error', (string)($activityRows[0]['message_type'] ?? ''));
    $harness->assertSame('No writable storage locations available.', (string)($activityRows[0]['message_text'] ?? ''));
    $harness->assertSame('POST', (string)($activityRows[0]['request_method'] ?? ''));
    $harness->assertSame('/api/raw-upload.php', (string)($activityRows[0]['request_uri'] ?? ''));
    $harness->assertSame('DESKTOP-C6R0CCD', (string)($activityRows[0]['device_id'] ?? ''));
    $harness->assertCount(1, $logsRows);
    $harness->assertSame('Token Account', (string)($logsRows[0]['user_display_name'] ?? ''));
    $harness->assertSame('No writable storage locations available.', (string)($logsRows[0]['message_text'] ?? ''));

    AppConfigurationStore::set('swallowtail.storage.test_base_location', '');
    (new SwallowtailStorageCacheService())->clear();
    @unlink($source);
    swallowtail_backend_remove_tree($blockedBase);
});

$harness->check(SwallowtailRawUploadApiService::class, 'rejects raw body uploads when content length exceeds RAW limit', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);

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
            new SwallowtailStorageService(),
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
    $harness->assertSame(0, InterfaceDB::tableRowCount('photos'));
});

$harness->check(SwallowtailRawUploadApiService::class, 'stops raw body streaming when RAW limit is exceeded', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-stream-test-');

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
            new SwallowtailStorageService(),
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
    $harness->assertSame(0, InterfaceDB::tableRowCount('photos'));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'accepts token authenticated raw body uploads under RAW limit', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-body-test-');

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
            new SwallowtailStorageService(),
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
    $harness->assertSame(1, InterfaceDB::tableRowCount('photos'));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'raw body uploads ignore multipart upload_max_filesize limit', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('SpiceBush test rig', null, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-raw-body-upload-limit-test-');

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
            'X-Swallowtail-Filename' => 'SPICEBUSH_0004.CR2',
            'User-Agent' => 'spicebush-test',
        ],
        null,
        []
    );

    $service = new SwallowtailRawUploadApiService(
        new SwallowtailPhotoIngestService(
            new SwallowtailStorageService(),
            $library,
            new SwallowtailConversionQueueService(),
            4096,
            ['upload_max_filesize' => '1', 'post_max_size' => '64M']
        ),
        $library
    );
    $response = $service->handleUpload($request, [], $source);
    $payload = json_decode($response->body(), true);

    $harness->assertSame(201, $response->statusCode());
    $harness->assertTrue(is_array($payload));
    $harness->assertTrue(!empty($payload['success']));
    $harness->assertSame('uploaded', $payload['status'] ?? null);
    $harness->assertSame(1, InterfaceDB::tableRowCount('photos'));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'rejects multipart RAW uploads over effective RAW limit', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-multipart-test-');

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
            new SwallowtailStorageService(),
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
    $harness->assertSame(0, InterfaceDB::tableRowCount('photos'));

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'rejects upload tokens outside their CIDR', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('ESP32 test rig', null, null, ['198.51.100.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-test-');

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
        new SwallowtailPhotoIngestService(new SwallowtailStorageService(), $library, new SwallowtailConversionQueueService()),
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
    $harness->assertSame(0, InterfaceDB::tableRowCount('photos'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoLibraryService::class, 'manages upload token CIDRs without storing plaintext tokens', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $created = $library->createUploadToken('Bridge A', 12, null, ['203.0.113.0/24', '2001:db8::/32']);
    $tokenId = (int)$created['id'];
    $stored = $library->uploadTokenById($tokenId);

    $harness->assertTrue(str_starts_with((string)$created['token'], 'stup_'));
    $harness->assertSame(2, InterfaceDB::tableRowCount('api_upload_token_cidrs'));
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
    $harness->assertSame(0, InterfaceDB::tableRowCount('api_upload_tokens'));
    $harness->assertSame(0, InterfaceDB::tableRowCount('api_upload_token_cidrs'));
});

$harness->check(SwallowtailConversionStatusApiService::class, 'returns conversion jobs and filesystem image readiness', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $library = new SwallowtailPhotoLibraryService();
    $token = $library->createUploadToken('Status token', null, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService(), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0008.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $storage = new SwallowtailStorageService();
    $thumbnailPath = $storage->imagePath((string)($photo['storage_base_location'] ?? ''), (string)($photo['original_sha256'] ?? ''), 'thumbnail');
    $storage->ensureDirectoryForPath($thumbnailPath);
    file_put_contents($thumbnailPath, "\xFF\xD8\xFF\xD9", LOCK_EX);

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
    $harness->assertSame('queued', (string)(($payload['jobs']['original'] ?? [])['status'] ?? ''));
    $harness->assertTrue(!empty(($payload['images']['thumbnail'] ?? [])['ready']));
    $harness->assertTrue(!array_key_exists('storage_path', (array)($payload['images']['thumbnail'] ?? [])));
    $harness->assertTrue(empty(($payload['images']['filtered'] ?? [])['ready']));

    @unlink($source);
});

$harness->check(SwallowtailStorageLocationService::class, 'filters discovered storage by root, threshold, and exclusion settings', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWithRootStorage): void {
    $swallowtailCreateSqliteSchema();

    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', false);
        AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 0);
        $withoutRoot = (new SwallowtailStorageService())->storageLocations();
        foreach ($withoutRoot as $location) {
            $harness->assertTrue(empty($location['is_root_partition']));
        }

        $swallowtailWithRootStorage(static function (SwallowtailStorageService $storage, string $baseLocation) use ($harness): void {
            $locations = $storage->storageLocations();
            $harness->assertTrue($locations !== []);
            $writableLocations = array_values(array_filter(
                $locations,
                static fn(array $location): bool => !empty($location['can_write'])
            ));
            if ($writableLocations === []) {
                $harness->skip('Storage settings test needs at least one writable storage location.');
            }

            $baseLocation = (string)$writableLocations[0]['storage_base_location'];
            $harness->assertTrue(str_ends_with((string)$writableLocations[0]['data_root'], DIRECTORY_SEPARATOR . 'swallowtail-data' . DIRECTORY_SEPARATOR));
            $harness->assertTrue(!empty($writableLocations[0]['permission_can_write']));

            AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 100);
            $thresholdLocations = $storage->storageLocations();
            $thresholdLocation = null;
            foreach ($thresholdLocations as $location) {
                if ((string)($location['storage_base_location'] ?? '') === $baseLocation) {
                    $thresholdLocation = $location;
                    break;
                }
            }
            $harness->assertTrue(is_array($thresholdLocation));
            $harness->assertTrue(empty($thresholdLocation['can_write']));

            AppConfigurationStore::set('swallowtail.storage.full_threshold_percent', 0);
            (new SwallowtailStorageLocationService($storage))->setExcluded($baseLocation, true);
            $excludedLocations = $storage->storageLocations();
            $excludedLocation = null;
            foreach ($excludedLocations as $location) {
                if ((string)($location['storage_base_location'] ?? '') === $baseLocation) {
                    $excludedLocation = $location;
                    break;
                }
            }
            $harness->assertTrue(is_array($excludedLocation));
            $harness->assertTrue(!empty($excludedLocation['is_excluded']));
            $harness->assertTrue(empty($excludedLocation['can_write']));
        });
    } finally {
        file_put_contents($configPath, $originalConfig, LOCK_EX);
        AppConfigurationStore::config(true);
    }
});

$harness->check(SwallowtailStorageService::class, 'selects writable locations by checksum modulo when enabled', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWithRootStorage): void {
    $swallowtailCreateSqliteSchema();

    $swallowtailWithRootStorage(static function (SwallowtailStorageService $storage) use ($harness): void {
        AppConfigurationStore::set('swallowtail.storage.round_robin_locations', true);
        $locations = array_values(array_filter(
            $storage->storageLocations(),
            static fn(array $location): bool => !empty($location['can_write'])
        ));

        if (count($locations) < 2) {
            $harness->skip('Checksum modulo selection needs at least two writable mounted locations.');
        }

        $checksum = str_repeat('a', 63) . '8';
        $chosen = $storage->writableLocationForChecksum($checksum);
        $expected = $locations[hexdec('8') % count($locations)];
        $harness->assertSame((string)$expected['storage_base_location'], (string)$chosen['storage_base_location']);
    });
});

$harness->check(SwallowtailStorageService::class, 'keeps fallback writable locations after checksum selection', function () use ($harness): void {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        AppConfigurationStore::set('swallowtail.storage.round_robin_locations', true);

        $storage = new SwallowtailStorageService();
        $method = new ReflectionMethod($storage, 'writableLocationsForChecksum');
        $method->setAccessible(true);
        $ordered = $method->invoke($storage, str_repeat('a', 63) . '1', [
            ['storage_base_location' => '/storage/a', 'can_write' => true],
            ['storage_base_location' => '/storage/b', 'can_write' => true],
            ['storage_base_location' => '/storage/c', 'can_write' => true],
        ]);

        $harness->assertSame('/storage/b', (string)($ordered[0]['storage_base_location'] ?? ''));
        $harness->assertSame('/storage/c', (string)($ordered[1]['storage_base_location'] ?? ''));
        $harness->assertSame('/storage/a', (string)($ordered[2]['storage_base_location'] ?? ''));
    } finally {
        file_put_contents($configPath, $originalConfig, LOCK_EX);
        AppConfigurationStore::config(true);
    }
});

$harness->check('SwallowTail migration', 'defines the photo backend tables', function () use ($harness): void {
    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_05_31_001_swallowtail_photo_services.sql';
    $conversionPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_15_004_raw_conversion_jobs.sql';
    $hardeningPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_001_raw_conversion_hardening.sql';
    $tokenCidrsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_002_upload_token_cidrs.sql';
    $durationPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_003_raw_conversion_duration.sql';
    $embeddedPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_004_raw_conversion_embedded.sql';
    $quickHashPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_16_005_raw_quick_hash.sql';
    $storageMigrationPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_17_002_zfs_storage_cache_and_migrations.sql';
    $sql = file_get_contents($path);
    $conversionSql = file_get_contents($conversionPath);
    $hardeningSql = file_get_contents($hardeningPath);
    $tokenCidrsSql = file_get_contents($tokenCidrsPath);
    $durationSql = file_get_contents($durationPath);
    $embeddedSql = file_get_contents($embeddedPath);
    $quickHashSql = file_get_contents($quickHashPath);
    $storageMigrationSql = file_get_contents($storageMigrationPath);

    if (!is_string($sql) || !is_string($conversionSql) || !is_string($hardeningSql) || !is_string($tokenCidrsSql) || !is_string($durationSql) || !is_string($embeddedSql) || !is_string($quickHashSql) || !is_string($storageMigrationSql)) {
        throw new RuntimeException('SwallowTail migration could not be read.');
    }

    $sql .= "\n" . $conversionSql . "\n" . $hardeningSql . "\n" . $tokenCidrsSql . "\n" . $durationSql . "\n" . $embeddedSql . "\n" . $quickHashSql . "\n" . $storageMigrationSql;

    foreach ([
        'CREATE TABLE IF NOT EXISTS events',
        'CREATE TABLE IF NOT EXISTS storage_location_properties',
        'CREATE TABLE IF NOT EXISTS storage_migration_jobs',
        'CREATE TABLE IF NOT EXISTS storage_migration_job_items',
        'CREATE TABLE IF NOT EXISTS photos',
        'CREATE TABLE IF NOT EXISTS event_permissions',
        'CREATE TABLE IF NOT EXISTS api_upload_tokens',
        'CREATE TABLE IF NOT EXISTS api_upload_token_cidrs',
        'CREATE TABLE IF NOT EXISTS photo_conversion_jobs',
        'storage_base_location',
        'is_zfs',
        'dataset_name',
        'image_type enum',
        'profile_path',
        'output_width',
        'duration_seconds',
        "'embedded'",
        "'filtered'",
        'original_quick_hash',
        'idx_photos_quick_hash',
        "CHECK (original_extension = 'cr2')",
    ] as $needle) {
        $harness->assertTrue(str_contains($sql, $needle));
    }

    foreach ([
        'CREATE TABLE IF NOT EXISTS swallowtail_storage_locations',
        'CREATE TABLE IF NOT EXISTS swallowtail_photo_derivatives',
        'output_storage_path',
    ] as $needle) {
        $harness->assertTrue(!str_contains($sql, $needle));
    }
});

$harness->check(SwallowtailConversionQueueService::class, 'deduplicates image jobs by photo type and profile version', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService(),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0006.CR2');
    $queue = new SwallowtailConversionQueueService();
    $photoId = (int)$result['photo_id'];
    $profile = (new SwallowtailStorageService())->imagePath((string)$result['storage_base_location'], (string)$result['sha256'], 'profile');
    $first = $queue->enqueueFilteredRefresh($photoId, $profile, 2, 12);
    $second = $queue->enqueueFilteredRefresh($photoId, $profile, 2, 12);

    $harness->assertSame($first, $second);
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'filtered',
        'profile_version' => 2,
    ]));

    @unlink($source);
});

$harness->check(SwallowtailConversionQueueService::class, 'does not require Redis for durable image enqueue', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService(),
        new SwallowtailPhotoLibraryService(),
        new SwallowtailConversionQueueService()
    );
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0007.CR2');

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(3, InterfaceDB::countWhere('photo_conversion_jobs', 'photo_id', (int)$result['photo_id']));

    @unlink($source);
});


