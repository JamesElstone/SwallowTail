<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailCombinedProfileService;
use Swallowtail\Service\SwallowtailCombinedProfilePreviewService;
use Swallowtail\Service\SwallowtailConversionQueueService;
use Swallowtail\Service\SwallowtailConversionStatusApiService;
use Swallowtail\Service\SwallowtailDataIntegrityCheckService;
use Swallowtail\Service\SwallowtailDownloadService;
use Swallowtail\Service\SwallowtailEventAccessService;
use Swallowtail\Service\SwallowtailEventManagementService;
use Swallowtail\Service\SwallowtailImageServeService;
use Swallowtail\Service\SwallowtailInternalProfilesService;
use Swallowtail\Service\SwallowtailPhotoIngestService;
use Swallowtail\Service\SwallowtailPhotoLibraryService;
use Swallowtail\Service\SwallowtailPhotoUiService;
use Swallowtail\Service\SwallowtailPingApiService;
use Swallowtail\Service\SwallowtailPreviewProfileService;
use Swallowtail\Service\SwallowtailProfileDataService;
use Swallowtail\Service\SwallowtailQuickChecksumApiService;
use Swallowtail\Service\SwallowtailRawUploadApiService;
use Swallowtail\Service\SwallowtailRawTherapeeProfileService;
use Swallowtail\Service\SwallowtailSpiceBushRegistrationApiService;
use Swallowtail\Service\SwallowtailStorageCacheService;
use Swallowtail\Service\SwallowtailStorageLocationService;
use Swallowtail\Service\SwallowtailStorageMigrationService;
use Swallowtail\Service\SwallowtailStorageService;

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
$harness->run(SwallowtailEventManagementService::class);
$harness->run(SwallowtailConversionQueueService::class);
$harness->run(SwallowtailStorageLocationService::class);
$harness->run(SwallowtailImageServeService::class);
$harness->run(SwallowtailProfileDataService::class);
$harness->run(SwallowtailCombinedProfileService::class);
$harness->run(SwallowtailPreviewProfileService::class);
$harness->run(SwallowtailDataIntegrityCheckService::class);
$harness->run(SwallowtailDownloadService::class);
$harness->run(SwallowtailRawTherapeeProfileService::class);

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

function swallowtail_backend_record_asset(
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

function swallowtail_backend_enable_final_profile_overlay(string $profileName = 'final-test'): void
{
    InterfaceDB::prepareExecute(
        "INSERT INTO internal_profile_data (image_type, profile_name, `order`, enabled, type, `key`, value, value_type)
         VALUES ('final', :profile_name, 100, 1, 'Exposure', 'Brightness', '10', 'int')",
        ['profile_name' => $profileName]
    );
}

function swallowtail_backend_create_rawtherapee_profile_table(): void
{
    InterfaceDB::execute('DROP TABLE IF EXISTS rawtherapee_profile_data');
    InterfaceDB::execute("CREATE TABLE rawtherapee_profile_data (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        profile_path TEXT NOT NULL UNIQUE,
        relative_path TEXT NOT NULL,
        display_label TEXT NOT NULL,
        profile_hash TEXT NOT NULL,
        profile_bytes INTEGER NOT NULL DEFAULT 0,
        profile_mtime INTEGER NOT NULL DEFAULT 0,
        profile_content TEXT NOT NULL,
        is_available INTEGER NOT NULL DEFAULT 1,
        is_default INTEGER NOT NULL DEFAULT 0,
        scanned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
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

    $storageRoot = swallowtail_backend_storage_tmp_root();
    if (!is_dir($storageRoot) && !@mkdir($storageRoot, 0770, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('Unable to create SwallowTail backend storage test directory.');
    }

    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', $storageRoot);
    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.round_robin_locations', false);
    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);
};

$swallowtailCreateSqliteSchema = static function () use ($swallowtailEnableRootStorageForTests): void {
    $swallowtailEnableRootStorageForTests();
    InterfaceDB::execute('PRAGMA foreign_keys = OFF');

    foreach ([
        'photo_audit',
        'storage_migration_job_items',
        'storage_migration_jobs',
        'internal_profile_data',
        'rawtherapee_profile_data',
        'photo_profile_data',
        'photo_image_assets',
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
        storage_base_location TEXT NOT NULL,
        upload_state TEXT NOT NULL DEFAULT 'uploaded',
        conversion_state TEXT NOT NULL DEFAULT 'pending',
        rawtherapee_profile_id INTEGER NULL,
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
        output_width INTEGER NULL,
        output_height INTEGER NULL,
        profile_signature TEXT NULL,
        requested_by_user_id INTEGER NULL,
        priority INTEGER NOT NULL DEFAULT 20,
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
    InterfaceDB::execute("INSERT INTO internal_profile_data (image_type, profile_name, `order`, type, `key`, value, value_type) VALUES
        ('thumbnail', 'resize', 1, 'Resize', 'Enabled', 'true', 'bool'),
        ('thumbnail', 'resize', 1, 'Resize', 'Scale', '1', 'int'),
        ('thumbnail', 'resize', 1, 'Resize', 'AppliesTo', 'Cropped area', 'string'),
        ('thumbnail', 'resize', 1, 'Resize', 'Method', 'Lanczos', 'string'),
        ('thumbnail', 'resize', 1, 'Resize', 'DataSpecified', '5', 'int'),
        ('thumbnail', 'resize', 1, 'Resize', 'Width', '0', 'int'),
        ('thumbnail', 'resize', 1, 'Resize', 'Height', '0', 'int'),
        ('thumbnail', 'resize', 1, 'Resize', 'LongEdge', '0', 'int'),
        ('thumbnail', 'resize', 1, 'Resize', 'ShortEdge', '180', 'int'),
        ('thumbnail', 'resize', 1, 'Resize', 'AllowUpscaling', 'false', 'bool')");

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
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', true);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.round_robin_locations', false);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);

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
        email_address TEXT NULL,
        mobile_number TEXT NULL,
        password_hash TEXT NULL,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        otp_required INTEGER NOT NULL DEFAULT 1,
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
        'REQUEST_URI' => '/api/upload-raw.php',
        'SCRIPT_NAME' => '/api/upload-raw.php',
    ], $server);

    http_response_code(200);
    ob_start();

    try {
        require APP_ROOT . 'api' . DIRECTORY_SEPARATOR . 'upload-raw.php';
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
        $profilePath = $service->imagePath($baseLocation, $sha256, 'preview_profile');
        $thumbnailProfilePath = $service->imagePath($baseLocation, $sha256, 'thumbnail_profile');
        $thumbnailPath = $service->imagePath($baseLocation, $sha256, 'thumbnail');
        $finalPath = $service->imagePath($baseLocation, $sha256, 'final');

        $harness->assertTrue(str_contains($sourcePath, DIRECTORY_SEPARATOR . 'swallowtail-data' . DIRECTORY_SEPARATOR . 'aa' . DIRECTORY_SEPARATOR . 'aa' . DIRECTORY_SEPARATOR));
        $harness->assertTrue(str_ends_with($sourcePath, $sha256 . '_source.cr2'));
        $harness->assertTrue(str_ends_with($profilePath, $sha256 . '_preview.pp3'));
        $harness->assertTrue(str_ends_with($thumbnailProfilePath, $sha256 . '_thumbnail.pp3'));
        $harness->assertTrue(str_ends_with($thumbnailPath, $sha256 . '_thumbnail.jpg'));
        $harness->assertTrue(str_ends_with($finalPath, $sha256 . '_final.jpg'));
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
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', $blockedBase);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);
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

$harness->check(SwallowtailStorageService::class, 'fails storage refresh when Redis cache write fails', function () use ($harness, $swallowtailAssertContains): void {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    $base = swallowtail_backend_storage_tmp_root();
    if (!is_dir($base) && !@mkdir($base, 0770, true) && !is_dir($base)) {
        throw new RuntimeException('Unable to create SwallowTail backend storage test directory.');
    }

    try {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', $base);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.port', 0);

        try {
            (new SwallowtailStorageService())->refreshStorageSnapshot();
            throw new RuntimeException('Expected storage refresh to fail when Redis cannot store the snapshot.');
        } catch (RuntimeException $exception) {
            $swallowtailAssertContains('Unable to store refreshed SwallowTail storage snapshot in Redis', $exception->getMessage(), 'storage refresh cache write failure');
        }
    } finally {
        file_put_contents($configPath, $originalConfig, LOCK_EX);
        AppConfigurationStore::config(true);
        (new SwallowtailStorageCacheService())->clear();
        swallowtail_backend_remove_tree($base);
    }
});

$harness->check(SwallowtailStorageCacheService::class, 'ignores malformed Redis storage snapshots', function () use ($harness): void {
    $redis = new class {
        public function get(string $key): ?string
        {
            return json_encode([
                'version' => 1,
                'generated_at' => time(),
                'cached_at' => time(),
            ], JSON_UNESCAPED_SLASHES);
        }
    };

    $harness->assertSame(null, (new SwallowtailStorageCacheService($redis))->snapshot(true));
});

$harness->check(SwallowtailStorageService::class, 'verifies active live storage locations are writable by PHP', function () use ($harness): void {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', '');
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);
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

    foreach ([dirname($destinationPath), dirname(dirname($destinationPath))] as $directory) {
        $directoryMode = fileperms($directory);
        if (!is_int($directoryMode)) {
            throw new RuntimeException('Unable to inspect storage hash directory permissions.');
        }

        $harness->assertSame(0770, $directoryMode & 0770);
        $harness->assertSame(02000, $directoryMode & 02000);
    }
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
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', $baseLocation);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
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
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', '');
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
        $previewPath = $storage->imagePath($sourceBase, $sha256, 'preview');
        $sourceProfilePath = $storage->imagePath($sourceBase, $sha256, 'source_profile');
        $storage->ensureDirectoryForPath($sourcePath);
        file_put_contents($sourcePath, 'source-bytes', LOCK_EX);
        file_put_contents($previewPath, 'preview-bytes', LOCK_EX);
        file_put_contents($sourceProfilePath, 'source-profile-bytes', LOCK_EX);

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
        $harness->assertSame(1, InterfaceDB::countWhere('storage_migration_job_items', [
            'job_id' => $jobId,
            'destination_base_location' => rtrim($destinationBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
        ]));
        $processed = (new SwallowtailStorageMigrationService())->processPending(1);

        $harness->assertSame(1, $processed);
        $row = InterfaceDB::fetchOne('SELECT storage_base_location FROM photos WHERE id = :id', ['id' => $photoId]);
        $harness->assertSame(rtrim($destinationBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, (string)($row['storage_base_location'] ?? ''));
        $harness->assertTrue(is_file($storage->imagePath($destinationBase, $sha256, 'source')));
        $harness->assertTrue(is_file($storage->imagePath($destinationBase, $sha256, 'preview')));
        $harness->assertTrue(is_file($storage->imagePath($destinationBase, $sha256, 'source_profile')));
        $harness->assertTrue(!is_file($sourcePath));
        $harness->assertTrue(!is_file($previewPath));
        $harness->assertTrue(!is_file($sourceProfilePath));
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

$harness->check(SwallowtailStorageMigrationService::class, 'plans migration items and excludes source location', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'swallowtail-plan-' . bin2hex(random_bytes(4));
    $sourceBase = $root . DIRECTORY_SEPARATOR . 'source';
    $source = rtrim($sourceBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    try {
        for ($index = 1; $index <= 2; $index++) {
            InterfaceDB::prepareExecute(
                "INSERT INTO photos (
                    original_filename,
                    original_extension,
                    original_bytes,
                    original_sha256,
                    storage_base_location
                ) VALUES (
                    :filename,
                    'cr2',
                    12,
                    :sha256,
                    :storage_base_location
                )",
                [
                    'filename' => 'IMG_PLAN_' . $index . '.CR2',
                    'sha256' => str_repeat((string)$index, 64),
                    'storage_base_location' => $source,
                ]
            );
        }

        $jobId = (new SwallowtailStorageMigrationService())->enqueueAndExcludeSource($sourceBase, null);

        $harness->assertTrue((int)$jobId > 0);
        $harness->assertSame(2, InterfaceDB::countWhere('storage_migration_job_items', 'job_id', $jobId));
        $harness->assertSame(2, (int)InterfaceDB::fetchColumn('SELECT total_photos FROM storage_migration_jobs WHERE id = :id', ['id' => $jobId]));
        $harness->assertSame(2, InterfaceDB::countWhere('storage_migration_job_items', [
            'job_id' => $jobId,
            'status' => 'queued',
        ]));
        $harness->assertSame(2, (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM storage_migration_job_items WHERE job_id = :job_id AND destination_base_location IS NULL',
            ['job_id' => $jobId]
        ));
        $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
            'SELECT is_excluded FROM storage_location_properties WHERE storage_base_location = :storage_base_location',
            ['storage_base_location' => $source]
        ));
    } finally {
        swallowtail_backend_remove_tree($root);
    }
});

$harness->check(SwallowtailStorageMigrationService::class, 'processes migration item batches before completing parent job', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();
    $storage = new SwallowtailStorageService();
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'swallowtail-batch-' . bin2hex(random_bytes(4));
    $sourceBase = $root . DIRECTORY_SEPARATOR . 'source';
    $destinationBase = $root . DIRECTORY_SEPARATOR . 'destination';
    $checksums = [str_repeat('a', 64), str_repeat('b', 64)];

    try {
        foreach ($checksums as $index => $sha256) {
            $sourcePath = $storage->imagePath($sourceBase, $sha256, 'source');
            $previewPath = $storage->imagePath($sourceBase, $sha256, 'preview');
            $storage->ensureDirectoryForPath($sourcePath);
            file_put_contents($sourcePath, 'source-bytes-' . $index, LOCK_EX);
            file_put_contents($previewPath, 'preview-bytes-' . $index, LOCK_EX);

            InterfaceDB::prepareExecute(
                "INSERT INTO photos (
                    original_filename,
                    original_extension,
                    original_bytes,
                    original_sha256,
                    storage_base_location
                ) VALUES (
                    :filename,
                    'cr2',
                    12,
                    :sha256,
                    :storage_base_location
                )",
                [
                    'filename' => 'IMG_BATCH_' . $index . '.CR2',
                    'sha256' => $sha256,
                    'storage_base_location' => rtrim($sourceBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                ]
            );
        }

        $jobId = (new SwallowtailStorageMigrationService())->enqueue($sourceBase, $destinationBase, null, null, null);
        $harness->assertTrue((int)$jobId > 0);

        $harness->assertSame(1, (new SwallowtailStorageMigrationService())->processPending(1));
        $harness->assertSame(1, InterfaceDB::countWhere('storage_migration_job_items', [
            'job_id' => $jobId,
            'status' => 'succeeded',
        ]));
        $harness->assertSame(1, InterfaceDB::countWhere('storage_migration_job_items', [
            'job_id' => $jobId,
            'status' => 'queued',
        ]));
        $harness->assertSame('processing', (string)InterfaceDB::fetchColumn('SELECT status FROM storage_migration_jobs WHERE id = :id', ['id' => $jobId]));

        $harness->assertSame(1, (new SwallowtailStorageMigrationService())->processPending(10));
        $harness->assertSame(2, InterfaceDB::countWhere('storage_migration_job_items', [
            'job_id' => $jobId,
            'status' => 'succeeded',
        ]));
        $harness->assertSame('succeeded', (string)InterfaceDB::fetchColumn('SELECT status FROM storage_migration_jobs WHERE id = :id', ['id' => $jobId]));
        $harness->assertSame(2, (int)InterfaceDB::fetchColumn('SELECT moved_photos FROM storage_migration_jobs WHERE id = :id', ['id' => $jobId]));
    } finally {
        foreach ([$sourceBase, $destinationBase] as $base) {
            foreach ($checksums as $sha256) {
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
                } catch (Throwable) {
                }
            }
            @rmdir($base . DIRECTORY_SEPARATOR . SwallowtailStorageService::DATA_DIRECTORY);
            @rmdir($base);
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
    $photo = InterfaceDB::fetchOne(
        'SELECT conversion_state FROM photos WHERE id = :photo_id LIMIT 1',
        ['photo_id' => (int)$result['photo_id']]
    );
    $harness->assertSame('processing', (string)($photo['conversion_state'] ?? ''));
    $storage = new SwallowtailStorageService();
    $harness->assertTrue(is_file($storage->imagePath((string)$result['storage_base_location'], (string)$result['sha256'], 'source')));
    $harness->assertTrue((string)$result['storage_base_location'] !== '');
    $harness->assertSame(0, InterfaceDB::countWhere('event_photos', 'photo_id', (int)$result['photo_id']));
    $harness->assertSame(3, InterfaceDB::countWhere('photo_conversion_jobs', 'photo_id', (int)$result['photo_id']));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'image_type' => 'embedded',
        'priority' => 75,
    ]));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'image_type' => 'thumbnail',
        'priority' => 80,
    ]));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => (int)$result['photo_id'],
        'image_type' => 'original',
        'priority' => 20,
    ]));
    $harness->assertCount(3, (array)($result['conversion_jobs'] ?? []));
    $harness->assertSame(['embedded', 'thumbnail', 'original'], array_keys((array)($result['conversion_jobs'] ?? [])));
    $harness->assertTrue((int)(($result['conversion_jobs']['embedded'] ?? [])['job_id'] ?? 0) > 0);
    $thumbnailProfile = $storage->imagePath((string)$result['storage_base_location'], (string)$result['sha256'], 'thumbnail_profile');
    $harness->assertTrue(is_file($thumbnailProfile));
    $harness->assertTrue(str_contains(file_get_contents($thumbnailProfile) ?: '', 'ShortEdge=180'));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_audit', 'action_type', 'raw_uploaded'));

    @unlink($source);
});

$harness->check(SwallowtailPhotoIngestService::class, 'requests profile generation after upload ingest', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $redis = new class {
        public array $pushes = [];

        public function listPushJson(string $key, array $payload, int $maxLength = 0): bool
        {
            $this->pushes[] = [
                'key' => $key,
                'payload' => $payload,
                'max_length' => $maxLength,
            ];

            return true;
        }
    };

    try {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_profile_queue', 'swallowtail:metadata:profile_upload_test');
        $source = swallowtail_backend_test_temp_file('swallowtail-test-');
        if (!is_string($source)) {
            throw new RuntimeException('Unable to create RAW fixture.');
        }

        $swallowtailWriteRawFixture($source, 'cr2');
        $ingest = new SwallowtailPhotoIngestService(
            profileDataService: new SwallowtailProfileDataService($redis)
        );
        $result = $ingest->ingestLocalRawFile($source, 'IMG_0010.CR2', ['uploaded_via' => 'api']);
        $photoId = (int)($result['photo_id'] ?? 0);
        $status = InterfaceDB::fetchColumn(
            "SELECT value
             FROM photo_profile_data
             WHERE photo_id = :photo_id
               AND type = 'swallowtail'
               AND `key` = 'status'
             LIMIT 1",
            ['photo_id' => $photoId]
        );

        $harness->assertTrue(!empty($result['success']));
        $harness->assertSame('queued', (string)$status);
        $harness->assertCount(1, $redis->pushes);
        $harness->assertSame('swallowtail:metadata:profile_upload_test', $redis->pushes[0]['key']);
        $harness->assertSame($photoId, (int)$redis->pushes[0]['payload']['photo_id']);
        $harness->assertSame('raw_upload', (string)$redis->pushes[0]['payload']['reason']);
        $harness->assertSame(512, (int)$redis->pushes[0]['max_length']);

        @unlink($source);
    } finally {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_profile_queue', 'swallowtail:metadata:profile_urgent');
    }
});

$harness->check(SwallowtailConversionQueueService::class, 'lists queued jobs in numeric priority order', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (
            photo_id,
            job_type,
            image_type,
            input_path,
            output_path,
            priority,
            status
        ) VALUES
            (1, 'image', 'original', '/tmp/source.cr2', '/tmp/original.jpg', 20, 'queued'),
            (1, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview.jpg', 30, 'queued'),
            (1, 'image', 'final', '/tmp/source.cr2', '/tmp/final.jpg', 10, 'queued'),
            (1, 'image', 'embedded', '/tmp/source.cr2', '/tmp/embedded.jpg', 40, 'queued')"
    );

    $rows = (new SwallowtailConversionQueueService())->queuedJobs(10);
    $types = array_map(static fn(array $row): string => (string)($row['image_type'] ?? ''), $rows);

    $harness->assertSame(['embedded', 'preview', 'original', 'final'], $types);
});

$harness->check(SwallowtailConversionQueueService::class, 'boosts queued viewed-photo jobs and sends preempt signals', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (
            id,
            photo_id,
            job_type,
            image_type,
            input_path,
            output_path,
            priority,
            status
        ) VALUES
            (9101, 77, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview.jpg', 30, 'queued'),
            (9102, 77, 'image', 'original', '/tmp/source.cr2', '/tmp/original.jpg', 20, 'queued'),
            (9103, 77, 'image', 'embedded', '/tmp/source.cr2', '/tmp/embedded.jpg', 40, 'queued'),
            (9104, 77, 'image', 'final', '/tmp/source.cr2', '/tmp/final.jpg', 60, 'queued'),
            (9105, 77, 'image', 'original', '/tmp/source.cr2', '/tmp/original-processing.jpg', 20, 'processing'),
            (9106, 77, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview-done.jpg', 20, 'succeeded')"
    );

    $notifications = [];
    $queue = new SwallowtailConversionQueueService(static function (int $jobId, string $imageType, int $priority, string $messageType) use (&$notifications): void {
        $notifications[] = [
            'job_id' => $jobId,
            'image_type' => $imageType,
            'priority' => $priority,
            'message_type' => $messageType,
        ];
    });

    $boosted = $queue->boostQueuedJobsForViewedPhoto(77);
    $rows = InterfaceDB::fetchAll(
        'SELECT id, priority FROM photo_conversion_jobs WHERE photo_id = 77 ORDER BY id'
    );
    $priorities = [];
    foreach ($rows as $row) {
        $priorities[(int)$row['id']] = (int)$row['priority'];
    }
    $preempts = array_values(array_filter(
        $notifications,
        static fn(array $notification): bool => (string)$notification['message_type'] === 'preempt'
    ));

    $harness->assertSame([9101, 9102, 9103, 9104], array_map(static fn(array $row): int => (int)$row['job_id'], $boosted));
    $harness->assertSame(95, $priorities[9101] ?? 0);
    $harness->assertSame(30, $priorities[9102] ?? 0);
    $harness->assertSame(90, $priorities[9103] ?? 0);
    $harness->assertSame(85, $priorities[9104] ?? 0);
    $harness->assertSame(20, $priorities[9105] ?? 0);
    $harness->assertSame(20, $priorities[9106] ?? 0);
    $harness->assertCount(3, $preempts);
    $harness->assertSame(['preview', 'embedded', 'final'], array_map(static fn(array $notification): string => (string)$notification['image_type'], $preempts));
    $harness->assertSame([95, 90, 85], array_map(static fn(array $notification): int => (int)$notification['priority'], $preempts));
});

$harness->check(SwallowtailConversionQueueService::class, 'viewer final enqueue bumps existing queued final to priority 85', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state
        ) VALUES (
            88,
            'IMG_0088.CR2',
            'cr2',
            100,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            '/tmp/swallowtail',
            'uploaded'
        )"
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (
            id,
            photo_id,
            job_type,
            image_type,
            input_path,
            profile_path,
            output_path,
            profile_signature,
            priority,
            status
        ) VALUES (
            9201,
            88,
            'image',
            'final',
            '/tmp/source.cr2',
            '/tmp/final.pp3',
            '/tmp/final.jpg',
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            10,
            'queued'
        )"
    );

    $notifications = [];
    $queue = new SwallowtailConversionQueueService(static function (int $jobId, string $imageType, int $priority, string $messageType) use (&$notifications): void {
        $notifications[] = [
            'job_id' => $jobId,
            'image_type' => $imageType,
            'priority' => $priority,
            'message_type' => $messageType,
        ];
    });

    $jobId = $queue->enqueueViewedFinalRefresh(88, '/tmp/final.pp3', 3, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    $priority = (int)InterfaceDB::fetchColumn('SELECT priority FROM photo_conversion_jobs WHERE id = 9201');
    $preempts = array_values(array_filter(
        $notifications,
        static fn(array $notification): bool => (string)$notification['message_type'] === 'preempt'
    ));

    $harness->assertSame(9201, $jobId);
    $harness->assertSame(85, $priority);
    $harness->assertCount(1, $preempts);
    $harness->assertSame(85, (int)($preempts[0]['priority'] ?? 0));
});

$harness->check(SwallowtailDataIntegrityCheckService::class, 'reports retired state and missing base conversion issues', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::execute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES
            (201, 'IMG_0201.CR2', 'cr2', 100, '2012012012012012012012012012012012012012012012012012012012012012', '/tmp/swallowtail', 'uploaded', 'pending'),
            (202, 'IMG_0202.CR2', 'cr2', 100, '2022022022022022022022022022022022022022022022022022022022022022', '/tmp/swallowtail', 'uploaded', 'ready'),
            (203, 'IMG_0203.CR2', 'cr2', 100, '2032032032032032032032032032032032032032032032032032032032032032', '/tmp/swallowtail', 'uploaded', 'pending'),
            (204, 'IMG_0204.CR2', 'cr2', 100, '2042042042042042042042042042042042042042042042042042042042042042', '/tmp/swallowtail', 'uploaded', 'processing'),
            (205, 'IMG_0205.CR2', 'cr2', 100, '2052052052052052052052052052052052052052052052052052052052052052', '/tmp/swallowtail', 'uploaded', 'ready')"
    );

    InterfaceDB::execute(
        "INSERT INTO photo_conversion_jobs (
            photo_id,
            job_type,
            image_type,
            input_path,
            output_path,
            priority,
            status
        ) VALUES
            (201, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview.jpg', 10, 'obsolete'),
            (201, 'image', 'final', '/tmp/source.cr2', '/tmp/final.jpg', 10, 'cancelled'),
            (202, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview.jpg', 10, 'succeeded'),
            (202, 'image', 'final', '/tmp/source.cr2', '/tmp/final.jpg', 10, 'succeeded'),
            (203, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview.jpg', 10, 'succeeded'),
            (203, 'image', 'final', '/tmp/source.cr2', '/tmp/final.jpg', 10, 'cancelled'),
            (204, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview.jpg', 10, 'queued'),
            (205, 'image', 'embedded', '/tmp/source.cr2', '/tmp/embedded.jpg', 10, 'succeeded'),
            (205, 'image', 'thumbnail', '/tmp/source.cr2', '/tmp/thumbnail.jpg', 10, 'succeeded'),
            (205, 'image', 'original', '/tmp/source.cr2', '/tmp/original.jpg', 10, 'succeeded')"
    );

    $stateMismatchCount = null;
    $missingBaseConversionCount = null;
    foreach ((new SwallowtailDataIntegrityCheckService())->integrityChecks() as $check) {
        if (($check['name'] ?? '') === 'Photo conversion state mismatches') {
            $stateMismatchCount = (int)($check['count'] ?? 0);
        }
        if (($check['name'] ?? '') === 'Uploaded CR2 photos missing base conversions') {
            $missingBaseConversionCount = (int)($check['count'] ?? 0);
        }
    }

    $harness->assertSame(1, $stateMismatchCount);
    $harness->assertSame(4, $missingBaseConversionCount);
});

$harness->check(SwallowtailDataIntegrityCheckService::class, 'repairs missing base conversions precisely', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('a', 64);
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES (
            301,
            'IMG_0301.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    InterfaceDB::execute(
        "INSERT INTO photo_conversion_jobs (
            photo_id,
            job_type,
            image_type,
            input_path,
            output_path,
            priority,
            status
        ) VALUES
            (301, 'image', 'embedded', '/tmp/source.cr2', '/tmp/embedded.jpg', 40, 'succeeded'),
            (301, 'image', 'original', '/tmp/source.cr2', '/tmp/original.jpg', 20, 'succeeded')"
    );

    $result = (new SwallowtailDataIntegrityCheckService())->repairMissingBaseConversions();
    $queuedRows = InterfaceDB::fetchAll(
        "SELECT image_type, status
         FROM photo_conversion_jobs
         WHERE photo_id = 301
           AND status = 'queued'
         ORDER BY image_type"
    );
    $photoState = InterfaceDB::fetchColumn('SELECT conversion_state FROM photos WHERE id = 301');

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, (int)($result['queued_jobs'] ?? 0));
    $harness->assertCount(1, $queuedRows);
    $harness->assertSame('thumbnail', (string)($queuedRows[0]['image_type'] ?? ''));
    $harness->assertSame('processing', (string)$photoState);
});

$harness->check(SwallowtailDataIntegrityCheckService::class, 'repairs profiled signature backfills', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $assetSignature = str_repeat('b', 64);
    $jobSignature = str_repeat('c', 64);
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (
            id,
            photo_id,
            job_type,
            image_type,
            input_path,
            output_path,
            profile_signature,
            priority,
            status
        ) VALUES
            (4101, 401, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview.jpg', :job_signature, 10, 'succeeded'),
            (4102, 401, 'image', 'final', '/tmp/source.cr2', '/tmp/final.jpg', NULL, 10, 'succeeded')",
        [
            'job_signature' => $jobSignature,
        ]
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_image_assets (
            id,
            photo_id,
            image_type,
            sha256,
            bytes,
            modified_at,
            profile_signature,
            conversion_job_id
        ) VALUES
            (4201, 401, 'preview', :asset_sha, 12, 123456789, NULL, 4101),
            (4202, 401, 'final', :asset_sha, 12, 123456789, :asset_signature, 4102)",
        [
            'asset_sha' => str_repeat('d', 64),
            'asset_signature' => $assetSignature,
        ]
    );

    $result = (new SwallowtailDataIntegrityCheckService())->repairProfileSignatures();
    $previewAssetSignature = InterfaceDB::fetchColumn('SELECT profile_signature FROM photo_image_assets WHERE id = 4201');
    $finalJobSignature = InterfaceDB::fetchColumn('SELECT profile_signature FROM photo_conversion_jobs WHERE id = 4102');

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, (int)($result['assets_backfilled'] ?? 0));
    $harness->assertSame(1, (int)($result['jobs_backfilled'] ?? 0));
    $harness->assertSame($jobSignature, (string)$previewAssetSignature);
    $harness->assertSame($assetSignature, (string)$finalJobSignature);
});

$harness->check(SwallowtailDataIntegrityCheckService::class, 'repairs unsigned preview rows from current profile signatures', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('f', 64);
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES (
            431,
            'IMG_0431.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    InterfaceDB::execute(
        "INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
            (431, 0, 'swallowtail', 'status', 'processed', 'string'),
            (431, 0, 'Exposure', 'Brightness', '1', 'int')"
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (
            id,
            photo_id,
            job_type,
            image_type,
            input_path,
            output_path,
            profile_signature,
            priority,
            status
        ) VALUES (
            4301,
            431,
            'image',
            'preview',
            '/tmp/source.cr2',
            '/tmp/preview.jpg',
            NULL,
            10,
            'succeeded'
        )"
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_image_assets (
            id,
            photo_id,
            image_type,
            sha256,
            bytes,
            modified_at,
            profile_signature,
            conversion_job_id
        ) VALUES (
            4302,
            431,
            'preview',
            :asset_sha,
            12,
            123456789,
            NULL,
            4301
        )",
        ['asset_sha' => str_repeat('1', 64)]
    );

    $result = (new SwallowtailDataIntegrityCheckService())->repairProfileSignatures();
    $jobSignature = InterfaceDB::fetchColumn('SELECT profile_signature FROM photo_conversion_jobs WHERE id = 4301');
    $assetSignature = InterfaceDB::fetchColumn('SELECT profile_signature FROM photo_image_assets WHERE id = 4302');

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, (int)($result['assets_backfilled'] ?? 0));
    $harness->assertSame(1, (int)($result['jobs_backfilled'] ?? 0));
    $harness->assertSame(0, (int)($result['queued_profile_jobs'] ?? -1));
    $harness->assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)$jobSignature) === 1);
    $harness->assertSame((string)$jobSignature, (string)$assetSignature);
});

$harness->check(SwallowtailDataIntegrityCheckService::class, 'queues profiled preview but not duplicate final for matching profile data', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('9', 64);
    $storage = new SwallowtailStorageService();
    $originalPath = $storage->imagePath($baseLocation, $sha256, 'original');
    $storage->ensureDirectoryForPath($originalPath);
    file_put_contents($originalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    $finalPath = $storage->imagePath($baseLocation, $sha256, 'final');
    $storage->ensureDirectoryForPath($finalPath);
    file_put_contents($finalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES (
            432,
            'IMG_0432.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    swallowtail_backend_record_asset(432, 'original', $originalPath, str_repeat('8', 64));
    InterfaceDB::execute(
        "INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
            (432, 0, 'swallowtail', 'status', 'processed', 'string')"
    );
    $finalSignature = (new SwallowtailCombinedProfileService())->profileSignature(432, 'final');
    swallowtail_backend_record_asset(432, 'final', $finalPath, str_repeat('7', 64), $finalSignature);

    $result = (new SwallowtailDataIntegrityCheckService())->processProfiledDerivativeQueueBatch(10);
    $jobs = InterfaceDB::fetchAll(
        "SELECT image_type, priority, status, profile_path, profile_signature
         FROM photo_conversion_jobs
         WHERE photo_id = 432
           AND image_type IN ('preview', 'final')
         ORDER BY image_type"
    );

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, (int)($result['queued_preview'] ?? 0));
    $harness->assertSame(0, (int)($result['queued_final'] ?? -1));
    $harness->assertSame(1, (int)($result['already_fresh'] ?? 0));
    $harness->assertSame(1, (int)($result['scanned'] ?? 0));
    $harness->assertSame(true, !empty($result['complete_pass']));
    $harness->assertCount(1, $jobs);
    $harness->assertSame('preview', (string)($jobs[0]['image_type'] ?? ''));
    $harness->assertSame(70, (int)($jobs[0]['priority'] ?? 0));
    $harness->assertSame('queued', (string)($jobs[0]['status'] ?? ''));
    $harness->assertTrue(is_file((string)($jobs[0]['profile_path'] ?? '')));
    $harness->assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($jobs[0]['profile_signature'] ?? '')) === 1);

    $second = (new SwallowtailDataIntegrityCheckService())->processProfiledDerivativeQueueBatch(10);
    $harness->assertTrue(!empty($second['success']));
    $harness->assertSame(0, (int)($second['scanned'] ?? -1));
    $harness->assertSame(0, (int)($second['queued_preview'] ?? -1));
    $harness->assertSame(0, (int)($second['queued_final'] ?? -1));
    $harness->assertSame(true, !empty($second['complete_pass']));
});

$harness->check(SwallowtailDataIntegrityCheckService::class, 'queues profiled final when final profile differs from original', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('7', 64);
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES (
            433,
            'IMG_0433.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    InterfaceDB::execute(
        "INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
            (433, 0, 'swallowtail', 'status', 'processed', 'string'),
            (433, 0, 'Version', 'AppVersion', '5.12', 'float'),
            (433, 0, 'Exposure', 'Brightness', '1', 'int')"
    );
    swallowtail_backend_enable_final_profile_overlay('final-diff-data-integrity');

    $result = (new SwallowtailDataIntegrityCheckService())->processProfiledDerivativeQueueBatch(10);
    $jobs = InterfaceDB::fetchAll(
        "SELECT image_type, priority, status, profile_path, profile_signature
         FROM photo_conversion_jobs
         WHERE photo_id = 433
           AND image_type IN ('preview', 'final')
         ORDER BY image_type"
    );

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, (int)($result['queued_preview'] ?? 0));
    $harness->assertSame(1, (int)($result['queued_final'] ?? 0));
    $harness->assertCount(2, $jobs);
    $harness->assertSame('final', (string)($jobs[0]['image_type'] ?? ''));
    $harness->assertSame(55, (int)($jobs[0]['priority'] ?? 0));
    $harness->assertSame('queued', (string)($jobs[0]['status'] ?? ''));
    $harness->assertTrue(is_file((string)($jobs[0]['profile_path'] ?? '')));
    $harness->assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($jobs[0]['profile_signature'] ?? '')) === 1);
    $harness->assertSame('preview', (string)($jobs[1]['image_type'] ?? ''));
    $harness->assertSame(70, (int)($jobs[1]['priority'] ?? 0));
    $harness->assertSame('queued', (string)($jobs[1]['status'] ?? ''));
    $harness->assertTrue(is_file((string)($jobs[1]['profile_path'] ?? '')));
    $harness->assertTrue(preg_match('/^[a-f0-9]{64}$/', (string)($jobs[1]['profile_signature'] ?? '')) === 1);
});

$harness->check(SwallowtailRawTherapeeProfileService::class, 'queues sample jobs with profile signatures', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();
    swallowtail_backend_create_rawtherapee_profile_table();

    $profilePath = swallowtail_backend_test_temp_file('swallowtail-rawtherapee-profile-');
    file_put_contents($profilePath, "[Version]\nAppVersion=5.10\n", LOCK_EX);
    $profileContent = (string)file_get_contents($profilePath);
    $baseLocation = swallowtail_backend_storage_tmp_root();
    InterfaceDB::prepareExecute(
        "INSERT INTO rawtherapee_profile_data (
            id,
            profile_path,
            relative_path,
            display_label,
            profile_hash,
            profile_bytes,
            profile_mtime,
            profile_content,
            is_available
        ) VALUES (
            701,
            :profile_path,
            'sample.pp3',
            'Sample',
            :profile_hash,
            :profile_bytes,
            :profile_mtime,
            :profile_content,
            1
        )",
        [
            'profile_path' => $profilePath,
            'profile_hash' => hash('sha256', $profileContent),
            'profile_bytes' => strlen($profileContent),
            'profile_mtime' => (int)filemtime($profilePath),
            'profile_content' => $profileContent,
        ]
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            uploaded_by_user_id,
            upload_state,
            conversion_state
        ) VALUES (
            702,
            'IMG_0702.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            44,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => str_repeat('2', 64),
            'storage_base_location' => $baseLocation,
        ]
    );

    $service = new SwallowtailRawTherapeeProfileService();
    $expectedSignature = $service->profileSignatureForPath($profilePath);
    $result = $service->enqueueSample(702, 701, 44);
    $job = InterfaceDB::fetchOne(
        'SELECT profile_signature, output_path FROM photo_conversion_jobs WHERE id = :id',
        ['id' => (int)($result['job_id'] ?? 0)]
    );
    $jobSignature = is_array($job) ? (string)($job['profile_signature'] ?? '') : '';
    $outputPath = is_array($job) ? (string)($job['output_path'] ?? '') : '';

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM rawtherapee_profile_data WHERE is_default = 1"));
    $harness->assertSame($expectedSignature, $jobSignature);
    $harness->assertTrue(preg_match('/^[a-f0-9]{64}$/', $jobSignature) === 1);
    $harness->assertTrue(str_ends_with($outputPath, '_rawtherapee_sample_' . $expectedSignature . '.jpg'));

    @unlink($profilePath);
});

$harness->check(SwallowtailRawTherapeeProfileService::class, 'sets exactly one default rawtherapee profile', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();
    swallowtail_backend_create_rawtherapee_profile_table();

    InterfaceDB::prepareExecute(
        "INSERT INTO rawtherapee_profile_data (
            id, profile_path, relative_path, display_label, profile_hash, profile_content, is_available, is_default
        ) VALUES
            (801, '/tmp/profile-one.pp3', 'one.pp3', 'One', :hash_one, '[Version]', 1, 1),
            (802, '/tmp/profile-two.pp3', 'two.pp3', 'Two', :hash_two, '[Version]', 1, 0)",
        [
            'hash_one' => str_repeat('1', 64),
            'hash_two' => str_repeat('2', 64),
        ]
    );

    $result = (new SwallowtailRawTherapeeProfileService())->setDefaultProfile(802);

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM rawtherapee_profile_data WHERE is_default = 1"));
    $harness->assertSame(802, (int)InterfaceDB::fetchColumn("SELECT id FROM rawtherapee_profile_data WHERE is_default = 1"));
});

$harness->check(SwallowtailPhotoLibraryService::class, 'new raw uploads inherit default rawtherapee profile id', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();
    swallowtail_backend_create_rawtherapee_profile_table();

    InterfaceDB::prepareExecute(
        "INSERT INTO rawtherapee_profile_data (
            id, profile_path, relative_path, display_label, profile_hash, profile_content, is_available, is_default
        ) VALUES (
            811, '/tmp/default.pp3', 'default.pp3', 'Default', :hash, '[Version]', 1, 1
        )",
        ['hash' => str_repeat('a', 64)]
    );

    $sha256 = hash('sha256', 'baseline-default-upload');
    $result = (new SwallowtailPhotoLibraryService())->recordRawUpload([
        'sha256' => $sha256,
        'original_filename' => 'IMG_BASELINE.CR2',
        'extension' => 'cr2',
        'bytes' => 123,
        'storage_base_location' => swallowtail_backend_storage_tmp_root(),
        'uploaded_by_user_id' => 44,
        'uploaded_via' => 'api',
    ]);
    $photo = (array)($result['photo'] ?? []);

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(811, (int)($photo['rawtherapee_profile_id'] ?? 0));
});

$harness->check(SwallowtailRawTherapeeProfileService::class, 'reuses exact rawtherapee sample variants by profile signature', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();
    swallowtail_backend_create_rawtherapee_profile_table();

    $profilePath = swallowtail_backend_test_temp_file('swallowtail-rawtherapee-reuse-profile-');
    file_put_contents($profilePath, "[Version]\nAppVersion=5.10\n[Exposure]\nBrightness=1\n", LOCK_EX);
    $profileContent = (string)file_get_contents($profilePath);
    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('a', 64);
    InterfaceDB::prepareExecute(
        "INSERT INTO rawtherapee_profile_data (
            id,
            profile_path,
            relative_path,
            display_label,
            profile_hash,
            profile_bytes,
            profile_mtime,
            profile_content,
            is_available
        ) VALUES (
            711,
            :profile_path,
            'reuse.pp3',
            'Reuse',
            :profile_hash,
            :profile_bytes,
            :profile_mtime,
            :profile_content,
            1
        )",
        [
            'profile_path' => $profilePath,
            'profile_hash' => hash('sha256', $profileContent),
            'profile_bytes' => strlen($profileContent),
            'profile_mtime' => (int)filemtime($profilePath),
            'profile_content' => $profileContent,
        ]
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            uploaded_by_user_id,
            upload_state,
            conversion_state
        ) VALUES (
            712,
            'IMG_0712.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            44,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );

    $service = new SwallowtailRawTherapeeProfileService();
    $signature = $service->profileSignatureForPath($profilePath);
    $samplePath = (new SwallowtailStorageService())->imageVariantPath($baseLocation, $sha256, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, $signature);
    (new SwallowtailStorageService())->ensureDirectoryForPath($samplePath);
    file_put_contents($samplePath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset(712, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, $samplePath, str_repeat('9', 64), $signature, 0);
    $otherSignature = str_repeat('8', 64);
    $otherSamplePath = (new SwallowtailStorageService())->imageVariantPath($baseLocation, $sha256, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, $otherSignature);
    file_put_contents($otherSamplePath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset(712, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, $otherSamplePath, str_repeat('7', 64), $otherSignature, 0);

    $result = $service->enqueueSample(712, 711, 44);

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(0, (int)($result['job_id'] ?? -1));
    $harness->assertTrue(str_contains((string)($result['image_url'] ?? ''), 'profile_signature=' . $signature));
    $harness->assertTrue(str_contains((string)($result['image_url'] ?? ''), 'v=' . $signature));
    $harness->assertSame(2, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_image_assets WHERE photo_id = 712 AND image_type = 'rawtherapee_sample'"));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_conversion_jobs WHERE photo_id = 712 AND image_type = 'rawtherapee_sample'"));

    @unlink($profilePath);
});

$harness->check(SwallowtailPreviewProfileService::class, 'rawtherapee sample status and serving require profile signatures', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
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
            role_id,
            account_status
        ) VALUES (
            44,
            'RawTherapee Status Admin',
            'rawtherapee-status-admin@example.test',
            'not-used',
            0,
            1,
            :role_id,
            'active'
        )",
        ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID]
    );

    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('b', 64);
    $signature = str_repeat('c', 64);
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            uploaded_by_user_id,
            upload_state,
            conversion_state
        ) VALUES (
            722,
            'IMG_0722.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            44,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    $samplePath = (new SwallowtailStorageService())->imageVariantPath($baseLocation, $sha256, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, $signature);
    (new SwallowtailStorageService())->ensureDirectoryForPath($samplePath);
    file_put_contents($samplePath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (
            id,
            photo_id,
            job_type,
            image_type,
            input_path,
            profile_path,
            output_path,
            profile_signature,
            priority,
            status,
            completed_at
        ) VALUES (
            721,
            722,
            'image',
            'rawtherapee_sample',
            '/tmp/source.cr2',
            '/tmp/profile.pp3',
            :output_path,
            :profile_signature,
            65,
            'succeeded',
            CURRENT_TIMESTAMP
        )",
        [
            'output_path' => $samplePath,
            'profile_signature' => $signature,
        ]
    );
    swallowtail_backend_record_asset(722, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, $samplePath, str_repeat('d', 64), $signature, 721);

    $status = (new SwallowtailPreviewProfileService())->imageStatus(722, 721, 44, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE);
    $url = (string)($status['rawtherapee_sample_url'] ?? '');
    $image = (new SwallowtailImageServeService())->derivativeImage(722, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, 44, $signature);
    $missingSignature = (new SwallowtailImageServeService())->derivativeImage(722, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, 44);
    $wrongSignature = (new SwallowtailImageServeService())->derivativeImage(722, SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE, 44, str_repeat('e', 64));

    $harness->assertSame(true, (bool)($status['success'] ?? false));
    $harness->assertSame('succeeded', (string)($status['status'] ?? ''));
    $harness->assertTrue(str_contains($url, 'profile_signature=' . $signature));
    $harness->assertTrue(str_contains($url, 'v=' . $signature));
    $harness->assertTrue(is_array($image));
    $harness->assertSame($samplePath, (string)($image['absolute_path'] ?? ''));
    $harness->assertSame(null, $missingSignature);
    $harness->assertSame(null, $wrongSignature);
});

$harness->check(SwallowtailRawTherapeeProfileService::class, 'prefers photos with rawtherapee artifacts for initial profile selection', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
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
            role_id,
            account_status
        ) VALUES (
            44,
            'RawTherapee Admin',
            'rawtherapee-admin@example.test',
            'not-used',
            0,
            1,
            :role_id,
            'active'
        )",
        ['role_id' => RoleAssignmentService::ADMIN_ROLE_ID]
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state,
            uploaded_by_user_id
        ) VALUES
            (901, 'plain.CR2', 'cr2', 100, :plain_sha, '/storage', 'uploaded', 'processed', 44),
            (902, 'sampled.CR2', 'cr2', 100, :sampled_sha, '/storage', 'uploaded', 'processed', 44)",
        [
            'plain_sha' => str_repeat('1', 64),
            'sampled_sha' => str_repeat('2', 64),
        ]
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_image_assets (
            photo_id,
            image_type,
            sha256,
            bytes,
            modified_at,
            profile_signature,
            asset_variant_key,
            generated_at
        ) VALUES
            (901, 'thumbnail', :plain_thumb_sha, 10, 123, NULL, '', CURRENT_TIMESTAMP),
            (902, 'thumbnail', :sampled_thumb_sha, 10, 123, NULL, '', CURRENT_TIMESTAMP),
            (902, 'rawtherapee_sample', :sample_sha, 10, 123, :sample_signature, :sample_signature, CURRENT_TIMESTAMP)",
        [
            'plain_thumb_sha' => str_repeat('3', 64),
            'sampled_thumb_sha' => str_repeat('4', 64),
            'sample_sha' => str_repeat('5', 64),
            'sample_signature' => str_repeat('6', 64),
        ]
    );

    $photo = (new SwallowtailRawTherapeeProfileService())->randomAccessibleThumbnailPhoto(44, true);

    $harness->assertTrue(is_array($photo));
    $harness->assertSame(902, (int)($photo['id'] ?? 0));
});

$harness->check(SwallowtailDataIntegrityCheckService::class, 'repairs rawtherapee sample signatures from profile metadata', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();
    swallowtail_backend_create_rawtherapee_profile_table();

    $profilePath = '/profiles/sample-repair.pp3';
    $profileContent = "[Version]\nAppVersion=5.10\n";
    $expectedSignature = (new SwallowtailRawTherapeeProfileService())->profileSignature([
        'profile_path' => $profilePath,
        'relative_path' => 'sample-repair.pp3',
        'profile_bytes' => strlen($profileContent),
        'profile_mtime' => 1719500000,
    ]);
    InterfaceDB::prepareExecute(
        "INSERT INTO rawtherapee_profile_data (
            id,
            profile_path,
            relative_path,
            display_label,
            profile_hash,
            profile_bytes,
            profile_mtime,
            profile_content,
            is_available
        ) VALUES (
            801,
            :profile_path,
            'sample-repair.pp3',
            'Sample Repair',
            :profile_hash,
            :profile_bytes,
            1719500000,
            :profile_content,
            1
        )",
        [
            'profile_path' => $profilePath,
            'profile_hash' => hash('sha256', $profileContent),
            'profile_bytes' => strlen($profileContent),
            'profile_content' => $profileContent,
        ]
    );
    InterfaceDB::execute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES
            (802, 'IMG_0802.CR2', 'cr2', 100, '3333333333333333333333333333333333333333333333333333333333333333', '/tmp/swallowtail', 'uploaded', 'ready'),
            (803, 'IMG_0803.CR2', 'cr2', 100, '4444444444444444444444444444444444444444444444444444444444444444', '/tmp/swallowtail', 'uploaded', 'ready')"
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (
            id,
            photo_id,
            job_type,
            image_type,
            input_path,
            profile_path,
            output_path,
            profile_signature,
            priority,
            status
        ) VALUES
            (8101, 802, 'image', 'rawtherapee_sample', '/tmp/source.cr2', :profile_path, '/tmp/sample.jpg', NULL, 65, 'succeeded'),
            (8102, 803, 'image', 'rawtherapee_sample', '/tmp/source.cr2', :profile_path, '/tmp/sample.jpg', NULL, 65, 'succeeded')",
        ['profile_path' => $profilePath]
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_image_assets (
            id,
            photo_id,
            image_type,
            sha256,
            bytes,
            modified_at,
            profile_signature,
            conversion_job_id
        ) VALUES
            (8201, 802, 'rawtherapee_sample', :asset_sha_1, 12, 123456789, NULL, 8101),
            (8202, 803, 'rawtherapee_sample', :asset_sha_2, 12, 123456789, NULL, NULL)",
        [
            'asset_sha_1' => str_repeat('5', 64),
            'asset_sha_2' => str_repeat('6', 64),
        ]
    );

    $result = (new SwallowtailDataIntegrityCheckService())->repairProfileSignatures();
    $unsignedAssets = InterfaceDB::fetchColumn(
        "SELECT COUNT(*)
         FROM photo_image_assets
         WHERE image_type = 'rawtherapee_sample'
           AND (profile_signature IS NULL OR profile_signature = '')"
    );
    $unsignedJobs = InterfaceDB::fetchColumn(
        "SELECT COUNT(*)
         FROM photo_conversion_jobs
         WHERE image_type = 'rawtherapee_sample'
           AND (profile_signature IS NULL OR profile_signature = '')"
    );
    $assetSignature = InterfaceDB::fetchColumn('SELECT profile_signature FROM photo_image_assets WHERE id = 8201');
    $fallbackAsset = InterfaceDB::fetchOne('SELECT profile_signature, conversion_job_id FROM photo_image_assets WHERE id = 8202');

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(2, (int)($result['jobs_backfilled'] ?? 0));
    $harness->assertSame(2, (int)($result['assets_backfilled'] ?? 0));
    $harness->assertSame(0, (int)($result['unsupported_sample_rows'] ?? -1));
    $harness->assertSame(0, (int)$unsignedAssets);
    $harness->assertSame(0, (int)$unsignedJobs);
    $harness->assertSame($expectedSignature, (string)$assetSignature);
    $harness->assertTrue(is_array($fallbackAsset));
    $harness->assertSame($expectedSignature, (string)($fallbackAsset['profile_signature'] ?? ''));
    $harness->assertSame(8102, (int)($fallbackAsset['conversion_job_id'] ?? 0));
});

$harness->check(SwallowtailDataIntegrityCheckService::class, 'repairs photo conversion state mismatches', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::execute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES (
            501,
            'IMG_0501.CR2',
            'cr2',
            100,
            'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            '/tmp/swallowtail',
            'uploaded',
            'pending'
        )"
    );
    InterfaceDB::execute(
        "INSERT INTO photo_conversion_jobs (
            photo_id,
            job_type,
            image_type,
            input_path,
            output_path,
            priority,
            status
        ) VALUES
            (501, 'image', 'preview', '/tmp/source.cr2', '/tmp/preview.jpg', 10, 'succeeded'),
            (501, 'image', 'final', '/tmp/source.cr2', '/tmp/final.jpg', 10, 'succeeded')"
    );

    $result = (new SwallowtailDataIntegrityCheckService())->repairPhotoConversionStates();
    $photoState = InterfaceDB::fetchColumn('SELECT conversion_state FROM photos WHERE id = 501');

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(1, (int)($result['updated_states'] ?? 0));
    $harness->assertSame('ready', (string)$photoState);
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

$harness->check(SwallowtailQuickChecksumApiService::class, 'reports whether a CR2 SHA-256 checksum already exists', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
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

    $sha256 = (string)($result['sha256'] ?? '');
    $sourceBytes = (int)filesize($source);
    $harness->assertSame(64, strlen($sha256));
    $harness->assertTrue($library->photoByChecksumAndSize($sha256, $sourceBytes) !== null);

    $service = new SwallowtailQuickChecksumApiService($library);
    $foundRequest = new RequestFramework(
        ['algorithm' => 'sha256', 'hash' => $sha256, 'size_bytes' => (string)$sourceBytes],
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
    $harness->assertSame('sha256', (string)($foundPayload['algorithm'] ?? ''));
    $harness->assertSame((int)$result['photo_id'], (int)($foundPayload['photo_id'] ?? 0));

    $missingRequest = new RequestFramework(
        ['algorithm' => 'sha256', 'hash' => $sha256, 'size_bytes' => (string)($sourceBytes + 1)],
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

    $fnvAlgorithmRequest = new RequestFramework(
        ['algorithm' => 'fnv1a64', 'hash' => substr($sha256, 0, 16)],
        [],
        ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.15'],
        [],
        ['Authorization' => 'Bearer ' . $token['token']],
        null,
        []
    );
    $fnvAlgorithmPayload = json_decode($service->handleCheck($fnvAlgorithmRequest)->body(), true);

    $harness->assertTrue(is_array($fnvAlgorithmPayload));
    $harness->assertTrue(empty($fnvAlgorithmPayload['success']));
    $harness->assertTrue(str_contains(implode(' ', (array)($fnvAlgorithmPayload['errors'] ?? [])), 'Unsupported quick checksum algorithm'));

    $badAlgorithmRequest = new RequestFramework(
        ['algorithm' => 'crc32', 'hash' => $sha256],
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
    $harness->assertTrue(!$access->userCanEditPhoto(101, (int)$result['photo_id']));

    $library->grantEventPermission((int)$event['id'], 101, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
        'can_download_original_raw' => false,
    ]);

    $harness->assertTrue($access->userCanSeeEvent(101, (int)$event['id']));
    $harness->assertTrue($access->userCanViewPhoto(101, (int)$result['photo_id']));
    $harness->assertTrue($access->userCanEditPhoto(101, (int)$result['photo_id']));
    $harness->assertTrue($access->userCanDownloadSingleJpeg(101, (int)$event['id']));
    $harness->assertTrue(!$access->userCanDownloadOriginalRaw(101, (int)$event['id']));

    @unlink($source);
});

$harness->check(SwallowtailEventAccessService::class, 'combines user and role event grants additively', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    foreach ([[401, 'Family Viewer', 7], [402, 'Admin Viewer', -1], [403, 'Expired Viewer', 8]] as $user) {
        InterfaceDB::prepareExecute(
            "INSERT INTO users (
                id,
                display_name,
                email_address,
                password_hash,
                role_id
            ) VALUES (
                :id,
                :display_name,
                :email_address,
                '',
                :role_id
            )",
            [
                'id' => $user[0],
                'display_name' => $user[1],
                'email_address' => 'swallowtail-event-' . (string)$user[0] . '@example.test',
                'role_id' => $user[2],
            ]
        );
    }

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService(), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0012.CR2');
    $photoId = (int)$result['photo_id'];
    $event = $library->createEvent('Family Party');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);

    $access = new SwallowtailEventAccessService();
    $library->grantEventRolePermission((int)$event['id'], 7, [
        'can_view' => true,
        'can_edit' => false,
    ]);

    $harness->assertTrue($access->userCanViewPhoto(401, $photoId));
    $harness->assertTrue(!$access->userCanEditPhoto(401, $photoId));

    $library->grantEventPermission((int)$event['id'], 401, [
        'can_view' => true,
        'can_edit' => true,
    ]);

    $harness->assertTrue($access->userCanEditPhoto(401, $photoId));

    InterfaceDB::prepareExecute(
        "INSERT INTO event_permissions (
            event_id,
            grantee_type,
            grantee_id,
            can_view,
            can_edit,
            expires_at
        ) VALUES (
            :event_id,
            'role',
            8,
            1,
            1,
            '2000-01-01 00:00:00'
        )",
        ['event_id' => (int)$event['id']]
    );

    $harness->assertTrue(!$access->userCanViewPhoto(403, $photoId));
    $harness->assertTrue((new SwallowtailPhotoUiService())->userCanEditPhoto($photoId, 402));

    @unlink($source);
});

$harness->check(SwallowtailEventManagementService::class, 'keeps event user permission search lazy and limited', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    InterfaceDB::execute("DROP TABLE IF EXISTS role_card_permissions");
    InterfaceDB::execute("DROP TABLE IF EXISTS roles");
    InterfaceDB::execute("CREATE TABLE roles (
        id INTEGER PRIMARY KEY,
        role_name TEXT NOT NULL
    )");
    InterfaceDB::execute("CREATE TABLE role_card_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_id INTEGER NOT NULL,
        card_key TEXT NOT NULL
    )");
    InterfaceDB::prepareExecute("INSERT INTO roles (id, role_name) VALUES (7, 'Family'), (8, 'Friends')");

    for ($i = 0; $i < 12; $i++) {
        InterfaceDB::prepareExecute(
            "INSERT INTO users (
                id,
                display_name,
                email_address,
                password_hash,
                role_id
            ) VALUES (
                :id,
                :display_name,
                :email_address,
                '',
                :role_id
            )",
            [
                'id' => 500 + $i,
                'display_name' => 'Searchable Person ' . (string)$i,
                'email_address' => 'searchable-' . (string)$i . '@example.test',
                'role_id' => $i % 2 === 0 ? 7 : 8,
            ]
        );
    }

    $library = new SwallowtailPhotoLibraryService();
    $event = $library->createEvent('Searchable Event');
    $eventId = (int)$event['id'];
    $library->grantEventPermission($eventId, 500, ['can_view' => true]);

    $service = new SwallowtailEventManagementService();
    $userRows = $service->userPermissionRows($eventId);
    $searchRows = $service->searchUsers($eventId, 'Searchable');
    $roleRows = $service->rolePermissionRows($eventId);

    $harness->assertCount(1, $userRows);
    $harness->assertSame(500, (int)($userRows[0]['grantee_id'] ?? 0));
    $harness->assertSame(8, count($searchRows));
    $harness->assertTrue(!in_array(500, array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $searchRows), true));
    $harness->assertCount(2, $roleRows);

    $service->setPermission($eventId, 'role', 7, [
        'can_edit' => true,
    ], 501);

    $grant = InterfaceDB::fetchOne(
        "SELECT can_view, can_edit
         FROM event_permissions
         WHERE event_id = :event_id
           AND grantee_type = 'role'
           AND grantee_id = 7
         LIMIT 1",
        ['event_id' => $eventId]
    );

    $harness->assertTrue(is_array($grant));
    $harness->assertSame(1, (int)($grant['can_view'] ?? 0));
    $harness->assertSame(1, (int)($grant['can_edit'] ?? 0));
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
    $previewPath = $storage->imagePath((string)($photo['storage_base_location'] ?? ''), $sha256, 'preview');
    $finalPath = $storage->imagePath((string)($photo['storage_base_location'] ?? ''), $sha256, 'final');
    $storage->ensureDirectoryForPath($previewPath);
    file_put_contents($previewPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    file_put_contents($finalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset($photoId, 'preview', $previewPath, str_repeat('a', 64));
    swallowtail_backend_record_asset($photoId, 'final', $finalPath, str_repeat('b', 64));

    $event = $library->createEvent('Private Gallery');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $service = new SwallowtailImageServeService();

    $harness->assertSame(null, $service->derivativeImage($photoId, 'preview', 202));

    $library->grantEventPermission((int)$event['id'], 202, ['can_view' => true]);
    $image = $service->derivativeImage($photoId, 'preview', 202);
    $finalDenied = $service->derivativeImage($photoId, 'final', 202);

    $harness->assertTrue(is_array($image));
    $harness->assertSame($previewPath, (string)$image['absolute_path']);
    $harness->assertSame('image/jpeg', (string)$image['content_type']);
    $harness->assertTrue(str_contains((string)$image['etag'], '"'));
    $harness->assertSame(null, $finalDenied);

    $library->grantEventPermission((int)$event['id'], 202, [
        'can_view' => true,
        'can_download_single_jpeg' => true,
    ]);
    $finalImage = $service->derivativeImage($photoId, 'final', 202);
    $harness->assertTrue(is_array($finalImage));
    $harness->assertSame($finalPath, (string)$finalImage['absolute_path']);
    $harness->assertSame('final', (string)$finalImage['image_type']);

    @unlink($source);
});

$harness->check(SwallowtailImageServeService::class, 'serves admin-visible unassigned original images', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();

    foreach ([[901, 'Admin Test User', -1], [902, 'No Access Test User', 5]] as $user) {
        InterfaceDB::prepareExecute(
            "INSERT INTO users (
                id,
                display_name,
                email_address,
                password_hash,
                role_id
            ) VALUES (
                :id,
                :display_name,
                :email_address,
                '',
                :role_id
            )",
            [
                'id' => $user[0],
                'display_name' => $user[1],
                'email_address' => 'swallowtail-image-' . (string)$user[0] . '@example.test',
                'role_id' => $user[2],
            ]
        );
    }

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0011.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $originalPath = $storage->imagePath((string)($photo['storage_base_location'] ?? ''), $sha256, 'original');

    $storage->ensureDirectoryForPath($originalPath);
    file_put_contents($originalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset($photoId, 'original', $originalPath, str_repeat('c', 64));

    $service = new SwallowtailImageServeService();
    $harness->assertSame(null, $service->derivativeImage($photoId, 'original', 902));

    $image = $service->derivativeImage($photoId, 'original', 901);

    $harness->assertTrue(is_array($image));
    $harness->assertSame($originalPath, (string)$image['absolute_path']);
    $harness->assertSame('original', (string)$image['image_type']);

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
    $content = $service->pp3Content($settings, "[Common Properties for Transformations]\nMethod=log\n");

    $harness->assertSame(0, (int)$settings['crop']['x']);
    $harness->assertSame(490, (int)$settings['crop']['y']);
    $harness->assertSame(600, (int)$settings['crop']['width']);
    $harness->assertSame(10, (int)$settings['crop']['height']);
    $harness->assertSame(-100.0, (float)$settings['exposure']['black']);
    $harness->assertSame(100.0, (float)$settings['exposure']['contrast']);
    $harness->assertTrue(str_contains($content, "[Exposure]\nAuto=false\nBlack=-100\nBrightness=25.5\nContrast=100\nSaturation=-12.25"));
    $harness->assertTrue(str_contains($content, "[Crop]\nEnabled=true\nX=0\nY=490\nW=600\nH=10"));
    $harness->assertTrue(str_contains($content, "[Common Properties for Transformations]"));
    $harness->assertTrue(!str_contains($content, "[Common Properties for Transforma]"));
    $harness->assertTrue(!str_contains($content, "[Resize]"));
});

$harness->check(SwallowtailProfileDataService::class, 'uses latest per-setting profile revisions', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::execute("INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
        (7, 0, 'Exposure', 'Contrast', '0', 'int'),
        (7, 1, 'Exposure', 'Contrast', '20', 'int'),
        (7, 2, 'Exposure', 'Contrast', '30', 'int'),
        (7, 0, 'Crop', 'Enabled', 'false', 'bool'),
        (7, 1, 'Crop', 'Enabled', 'true', 'bool'),
        (7, 1, 'Crop', 'X', '10', 'int'),
        (7, 2, 'Crop', 'X', '20', 'int'),
        (7, 3, 'Crop', 'X', '30', 'int'),
        (7, 0, 'White Balance', 'Temperature', '6023', 'int'),
        (7, 1, 'White Balance', 'Temperature', '6099', 'int'),
        (7, 0, 'swallowtail', 'status', 'processed', 'string')");

    $service = new SwallowtailProfileDataService();
    $rows = $service->rowsByIdentity(7);

    $harness->assertSame('30', (string)($rows["Exposure\0Contrast"]['value'] ?? ''));
    $harness->assertSame(2, (int)($rows["Exposure\0Contrast"]['revision'] ?? -1));
    $harness->assertSame('true', (string)($rows["Crop\0Enabled"]['value'] ?? ''));
    $harness->assertSame(1, (int)($rows["Crop\0Enabled"]['revision'] ?? -1));
    $harness->assertSame('30', (string)($rows["Crop\0X"]['value'] ?? ''));
    $harness->assertSame(3, (int)($rows["Crop\0X"]['revision'] ?? -1));
    $harness->assertSame('6099', (string)($rows["White Balance\0Temperature"]['value'] ?? ''));
    $harness->assertTrue(!isset($rows["swallowtail\0status"]));
});

$harness->check(SwallowtailProfileDataService::class, 'records only changed submitted profile settings', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::execute("INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
        (8, 0, 'Exposure', 'Contrast', '0', 'int'),
        (8, 0, 'Crop', 'X', '10', 'int'),
        (8, 0, 'Crop', 'Y', '20', 'int')");

    $service = new SwallowtailProfileDataService();
    $first = $service->recordChangedRows(8, [
        ['type' => 'Exposure', 'key' => 'Contrast', 'value' => '20', 'value_type' => 'int'],
        ['type' => 'Crop', 'key' => 'X', 'value' => '10', 'value_type' => 'int'],
        ['type' => 'Crop', 'key' => 'Y', 'value' => '25', 'value_type' => 'int'],
    ]);
    $second = $service->recordChangedRows(8, [
        ['type' => 'Exposure', 'key' => 'Contrast', 'value' => '20', 'value_type' => 'int'],
        ['type' => 'Crop', 'key' => 'X', 'value' => '10', 'value_type' => 'int'],
        ['type' => 'Crop', 'key' => 'Y', 'value' => '25', 'value_type' => 'int'],
    ]);
    $third = $service->recordChangedRows(8, [
        ['type' => 'Exposure', 'key' => 'Contrast', 'value' => '30', 'value_type' => 'int'],
        ['type' => 'Common Properties for Transformations', 'key' => 'Method', 'value' => 'log', 'value_type' => 'string'],
    ]);

    $harness->assertSame(2, $first);
    $harness->assertSame(0, $second);
    $harness->assertSame(2, $third);
    $harness->assertSame(3, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_profile_data WHERE photo_id = 8 AND type = 'Exposure' AND `key` = 'Contrast'"));
    $harness->assertSame(2, (int)InterfaceDB::fetchColumn("SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = 8 AND type = 'Exposure' AND `key` = 'Contrast'"));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = 8 AND type = 'Crop' AND `key` = 'Y'"));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_profile_data WHERE photo_id = 8 AND type = 'Crop' AND `key` = 'X'"));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_profile_data WHERE photo_id = 8 AND type = 'Common Properties for Transformations' AND `key` = 'Method'"));
});

$harness->check(SwallowtailCombinedProfileService::class, 'combines photo profile rows with ordered internal overlays', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::execute("INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
        (7, 0, 'swallowtail', 'status', 'processed', 'string'),
        (7, 0, 'Exposure', 'Brightness', '12', 'int'),
        (7, 0, 'RAW Bayer', 'Method', 'amaze', 'string'),
        (7, 1, 'RAW Bayer', 'Method', 'igv', 'string'),
        (7, 0, 'Common Properties for Transformations', 'Method', 'log', 'string')");
    InterfaceDB::execute("INSERT INTO internal_profile_data (image_type, profile_name, `order`, enabled, type, `key`, value, value_type) VALUES
        ('preview', 'first', 1, 1, 'RAW Bayer', 'Method', 'amaze', 'string'),
        ('preview', 'second', 2, 1, 'RAW Bayer', 'Method', 'fast', 'string'),
        ('preview', 'second', 2, 1, 'Resize', 'ShortEdge', '820', 'int'),
        ('preview', 'second', 2, 1, 'Resize', 'DataSpecified', '5', 'int'),
        ('preview', 'disabled', 3, 0, 'Resize', 'LongEdge', '999', 'int'),
        ('embedded', 'ignored', 1, 1, 'Resize', 'LongEdge', '100', 'int')");

    $service = new SwallowtailCombinedProfileService();
    $base = $service->photoProfileContent(7);
    $preview = $service->applyInternalProfiles('preview', $base);
    $combined = $service->combinedProfileContent(7, 'preview');

    $harness->assertTrue(str_contains($base, "[Exposure]\nBrightness=12"));
    $harness->assertTrue(str_contains($base, "[RAW Bayer]\nMethod=igv"));
    $harness->assertTrue(str_contains($base, "[Common Properties for Transformations]\nMethod=log"));
    $harness->assertTrue(!str_contains($base, "[Common Properties for Transforma]"));
    $harness->assertTrue(str_contains($preview, "[RAW Bayer]\nMethod=fast"));
    $harness->assertSame($preview, $combined);
    $harness->assertTrue(str_contains($preview, "[Resize]"));
    $harness->assertTrue(str_contains($preview, "ShortEdge=820"));
    $harness->assertTrue(str_contains($preview, "DataSpecified=5"));
    $harness->assertTrue(!str_contains($preview, "LongEdge=999"));
    $harness->assertSame($base, $service->applyInternalProfiles('final', $base));
    $harness->assertSame($base, $service->applyInternalProfiles('embedded', $base));

    try {
        $service->applyInternalProfiles('', $base);
        throw new RuntimeException('Empty image type was not rejected.');
    } catch (InvalidArgumentException) {
    }
});

$harness->check(SwallowtailInternalProfilesService::class, 'saves typed internal profile values with validation', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $service = new SwallowtailInternalProfilesService();
    $service->saveRow(0, 'preview', 'typed_validation', 'Section', 'BoolKey', ' TRUE ', 'bool');
    $service->saveRow(0, 'preview', 'typed_validation', 'Section', 'IntKey', '123', 'int');
    $service->saveRow(0, 'preview', 'typed_validation', 'Section', 'FloatKey', '.5', 'float');
    $service->saveRow(0, 'preview', 'typed_validation', 'Section', 'StringKey', 'plain ASCII', 'string');
    $service->saveRow(0, 'preview', 'typed_validation', 'Section', 'NullKey', 'ignored', 'null');

    $rows = InterfaceDB::fetchAll(
        "SELECT `key`, value, value_type FROM internal_profile_data WHERE image_type = 'preview' AND profile_name = 'typed_validation' ORDER BY `key`"
    );
    $byKey = [];
    foreach ($rows as $row) {
        $byKey[(string)$row['key']] = $row;
    }

    $harness->assertSame('true', (string)$byKey['BoolKey']['value']);
    $harness->assertSame('bool', (string)$byKey['BoolKey']['value_type']);
    $harness->assertSame('123', (string)$byKey['IntKey']['value']);
    $harness->assertSame('.5', (string)$byKey['FloatKey']['value']);
    $harness->assertSame('plain ASCII', (string)$byKey['StringKey']['value']);
    $harness->assertSame(null, $byKey['NullKey']['value']);
    $harness->assertSame('null', (string)$byKey['NullKey']['value_type']);
});

$harness->check(SwallowtailInternalProfilesService::class, 'rejects invalid typed internal profile values', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $service = new SwallowtailInternalProfilesService();
    $cases = [
        ['BadBool', 'yes', 'bool', 'Value must be true or false.'],
        ['BadInt', '12a', 'int', 'Value must contain digits only.'],
        ['BadFloat', '1.', 'float', 'Value must be a decimal number.'],
        ['BadString', 'cafe' . chr(195) . chr(169), 'string', 'Value must contain ASCII characters only.'],
    ];

    foreach ($cases as [$key, $value, $valueType, $message]) {
        try {
            $service->saveRow(0, 'preview', 'typed_validation_errors', 'Section', $key, $value, $valueType);
            throw new RuntimeException('Invalid value was not rejected for ' . $key . '.');
        } catch (InvalidArgumentException $exception) {
            $harness->assertSame($message, $exception->getMessage());
        }
    }

    $count = (int)InterfaceDB::fetchColumn(
        "SELECT COUNT(*) FROM internal_profile_data WHERE image_type = 'preview' AND profile_name = 'typed_validation_errors'"
    );
    $harness->assertSame(0, $count);
});

$harness->check(SwallowtailCombinedProfileService::class, 'signs latest profile rows and enabled internal overlays', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    InterfaceDB::execute("INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
        (7, 0, 'swallowtail', 'status', 'processed', 'string'),
        (7, 0, 'Exposure', 'Brightness', '12', 'int'),
        (7, 0, 'RAW Bayer', 'Method', 'amaze', 'string'),
        (7, 1, 'RAW Bayer', 'Method', 'igv', 'string')");
    InterfaceDB::execute("INSERT INTO internal_profile_data (image_type, profile_name, `order`, enabled, type, `key`, value, value_type) VALUES
        ('preview', 'performance', 1, 1, 'RAW Bayer', 'Method', 'fast', 'string'),
        ('preview', 'disabled', 2, 0, 'Resize', 'LongEdge', '999', 'int')");

    $service = new SwallowtailCombinedProfileService();
    $initial = $service->profileSignature(7, 'preview');
    $harness->assertSame(1, preg_match('/^[a-f0-9]{64}$/', $initial));

    InterfaceDB::execute("INSERT INTO internal_profile_data (image_type, profile_name, `order`, enabled, type, `key`, value, value_type) VALUES
        ('preview', 'disabled-extra', 3, 0, 'Resize', 'ShortEdge', '640', 'int')");
    $disabledOverlay = $service->profileSignature(7, 'preview');
    $harness->assertSame($initial, $disabledOverlay);

    InterfaceDB::execute("INSERT INTO internal_profile_data (image_type, profile_name, `order`, enabled, type, `key`, value, value_type) VALUES
        ('preview', 'enabled-extra', 4, 1, 'Resize', 'ShortEdge', '820', 'int')");
    $enabledOverlay = $service->profileSignature(7, 'preview');
    $harness->assertTrue($enabledOverlay !== $initial);

    InterfaceDB::execute("INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
        (7, 2, 'RAW Bayer', 'Method', 'lmmse', 'string')");
    $latestRevision = $service->profileSignature(7, 'preview');
    $harness->assertTrue($latestRevision !== $enabledOverlay);
});

$harness->check(SwallowtailCombinedProfileService::class, 'requests metadata profile data when combined profile rows are missing', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $redis = new class {
        public array $pushes = [];

        public function listPushJson(string $key, array $payload, int $maxLength = 0): bool
        {
            $this->pushes[] = [
                'key' => $key,
                'payload' => $payload,
                'max_length' => $maxLength,
            ];

            return true;
        }
    };

    try {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_profile_queue', 'swallowtail:metadata:profile_combiner_test');
        $service = new SwallowtailCombinedProfileService(new SwallowtailProfileDataService($redis));
        $content = $service->combinedProfileContent(91, 'final');

        $harness->assertSame('', trim($content));
        $harness->assertSame('queued', (string)InterfaceDB::fetchColumn(
            "SELECT value FROM photo_profile_data WHERE photo_id = 91 AND type = 'swallowtail' AND `key` = 'status' LIMIT 1"
        ));
        $harness->assertCount(1, $redis->pushes);
        $harness->assertSame('swallowtail:metadata:profile_combiner_test', $redis->pushes[0]['key']);
        $harness->assertSame(91, (int)$redis->pushes[0]['payload']['photo_id']);
        $harness->assertSame('combined_profile_final', (string)$redis->pushes[0]['payload']['reason']);
    } finally {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_profile_queue', 'swallowtail:metadata:profile_urgent');
    }
});

$harness->check(SwallowtailCombinedProfilePreviewService::class, 'selects accessible example photos without derivative assets', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailCreateSpiceBushUserSchema): void {
    $swallowtailCreateSqliteSchema();
    $swallowtailCreateSpiceBushUserSchema();
    InterfaceDB::execute('DROP TABLE IF EXISTS photo_image_assets');

    InterfaceDB::prepareExecute(
        "INSERT INTO users (id, display_name, role_id, account_status)
         VALUES (44, 'Preview User', 0, 'active')"
    );
    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state,
            uploaded_by_user_id
        ) VALUES
            (910, 'example-preview.CR2', 'cr2', 100, :example_sha, '/storage', 'uploaded', 'processed', 44),
            (911, 'removed-preview.CR2', 'cr2', 100, :removed_sha, '/storage', 'removed', 'processed', 44),
            (912, 'other-user.CR2', 'cr2', 100, :other_sha, '/storage', 'uploaded', 'processed', 45)",
        [
            'example_sha' => str_repeat('a', 64),
            'removed_sha' => str_repeat('b', 64),
            'other_sha' => str_repeat('c', 64),
        ]
    );

    $service = new SwallowtailCombinedProfilePreviewService();
    $randomPhoto = $service->randomAccessiblePhoto(44);
    $explicitPhoto = $service->photoForUser(910, 44);
    $removedPhoto = $service->photoForUser(911, 44);
    $otherUserPhoto = $service->photoForUser(912, 44);

    $harness->assertTrue(is_array($randomPhoto));
    $harness->assertSame(910, (int)($randomPhoto['id'] ?? 0));
    $harness->assertSame('example-preview.CR2', (string)($randomPhoto['original_filename'] ?? ''));
    $harness->assertTrue(!array_key_exists('preview_ready', $randomPhoto));
    $harness->assertTrue(!array_key_exists('thumbnail_ready', $randomPhoto));
    $harness->assertSame(910, (int)($explicitPhoto['id'] ?? 0));
    $harness->assertSame(null, $removedPhoto);
    $harness->assertSame(null, $otherUserPhoto);
});

$harness->check(SwallowtailPreviewProfileService::class, 'uses thumbnail as temporary display without marking preview ready', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0010.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    $previewPath = $storage->imagePath($base, $sha256, 'preview');
    $thumbnailPath = $storage->imagePath($base, $sha256, 'thumbnail');

    $storage->ensureDirectoryForPath($thumbnailPath);
    @unlink($previewPath);
    file_put_contents($thumbnailPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset($photoId, 'thumbnail', $thumbnailPath, str_repeat('d', 64));

    $event = $library->createEvent('Thumbnail Preview Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, ['can_view' => true, 'can_edit' => true]);

    $state = (new SwallowtailPreviewProfileService())->editorState($photoId, 303);
    $previewUrl = is_array($state) ? (string)($state['preview_url'] ?? '') : '';

    $harness->assertTrue(is_array($state));
    $harness->assertSame(false, (bool)($state['preview_ready'] ?? true));
    $harness->assertSame('profile_pending', (string)($state['preview_status'] ?? ''));
    $harness->assertTrue(str_contains($previewUrl, 'type=thumbnail'));
    $harness->assertTrue(!str_contains($previewUrl, 'type=preview'));
    $harness->assertSame(0, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'preview',
    ]));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'queues initial preview after profile becomes ready', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0011.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    @unlink($storage->imagePath($base, $sha256, 'preview'));
    $thumbnailPath = $storage->imagePath($base, $sha256, 'thumbnail');
    $storage->ensureDirectoryForPath($thumbnailPath);
    file_put_contents($thumbnailPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset($photoId, 'thumbnail', $thumbnailPath, str_repeat('e', 64));

    $event = $library->createEvent('Initial Preview Queue Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, ['can_view' => true, 'can_edit' => true]);

    $service = new SwallowtailPreviewProfileService();
    $pending = $service->editorState($photoId, 303);
    $harness->assertTrue(is_array($pending));
    $harness->assertSame('', (string)($pending['preview_status_url'] ?? ''));
    $harness->assertSame(0, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'preview',
    ]));

    (new SwallowtailProfileDataService())->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');
    $ready = $service->baselineStatus($photoId, 303);
    $statusUrl = (string)($ready['preview_status_url'] ?? '');
    $harness->assertSame(true, (bool)($ready['baseline']['ready'] ?? false));
    $harness->assertSame(false, (bool)($ready['preview_ready'] ?? true));
    $harness->assertTrue(str_contains($statusUrl, '/api/photo-status.php?'));
    $harness->assertTrue(str_contains($statusUrl, 'image_type=preview'));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'preview',
    ]));

    $again = $service->baselineStatus($photoId, 303);
    $harness->assertSame($statusUrl, (string)($again['preview_status_url'] ?? ''));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'preview',
    ]));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'queues initial preview immediately when profile is already ready', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService(), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0012.CR2');
    $photoId = (int)$result['photo_id'];
    (new SwallowtailProfileDataService())->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');

    $event = $library->createEvent('Ready Initial Preview Queue Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, ['can_view' => true, 'can_edit' => true]);

    $state = (new SwallowtailPreviewProfileService())->editorState($photoId, 303);
    $harness->assertTrue(is_array($state));
    $harness->assertSame(false, (bool)($state['preview_ready'] ?? true));
    $harness->assertTrue(str_contains((string)($state['preview_status_url'] ?? ''), 'image_type=preview'));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'preview',
    ]));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'loads original as effective final when final profile matches original', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0018.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    $originalPath = $storage->imagePath($base, $sha256, 'original');
    $storage->ensureDirectoryForPath($originalPath);
    file_put_contents($originalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset($photoId, 'original', $originalPath, str_repeat('8', 64));

    $event = $library->createEvent('Effective Original Final Viewer Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
    ]);
    (new SwallowtailProfileDataService())->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');

    $state = (new SwallowtailPreviewProfileService())->pictureViewerState($photoId, 303);

    $harness->assertSame('original', (string)($state['display_type'] ?? ''));
    $harness->assertSame('loaded', (string)($state['final_status'] ?? ''));
    $harness->assertSame(true, (bool)($state['final_ready'] ?? false));
    $harness->assertTrue(str_contains((string)($state['display_url'] ?? ''), 'type=original'));
    $harness->assertTrue(str_contains((string)($state['display_url'] ?? ''), 'v=' . str_repeat('8', 64)));
    $harness->assertSame(0, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'final',
    ]));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'queues final when profile data has an edit revision', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService(), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0020.CR2');
    $photoId = (int)$result['photo_id'];

    $event = $library->createEvent('Edited Final Viewer Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
    ]);
    $profileData = new SwallowtailProfileDataService();
    $profileData->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');
    $profileData->setValue($photoId, 'Exposure', 'Brightness', '1', 'int');
    $profileData->recordChangedRows($photoId, [
        ['type' => 'Exposure', 'key' => 'Brightness', 'value' => '2', 'value_type' => 'int'],
    ]);

    $state = (new SwallowtailPreviewProfileService())->pictureViewerState($photoId, 303);
    $job = InterfaceDB::fetchOne(
        "SELECT status, priority
         FROM photo_conversion_jobs
         WHERE photo_id = :photo_id
           AND image_type = 'final'
         LIMIT 1",
        ['photo_id' => $photoId]
    );

    $harness->assertSame('queued', (string)($state['final_status'] ?? ''));
    $harness->assertSame(false, (bool)($state['final_ready'] ?? true));
    $harness->assertTrue(is_array($job));
    $harness->assertSame('queued', (string)($job['status'] ?? ''));
    $harness->assertSame(85, (int)($job['priority'] ?? 0));

    @unlink($source);
});

$harness->check(SwallowtailDownloadService::class, 'event final files use original assets when final profile matches original', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('6', 64);
    $storage = new SwallowtailStorageService();
    $originalPath = $storage->imagePath($baseLocation, $sha256, 'original');
    $storage->ensureDirectoryForPath($originalPath);
    file_put_contents($originalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);

    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES (
            601,
            'Event Photo.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    swallowtail_backend_record_asset(601, 'original', $originalPath, str_repeat('6', 64));
    InterfaceDB::execute(
        "INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
            (601, 0, 'swallowtail', 'status', 'processed', 'string'),
            (601, 0, 'Version', 'AppVersion', '5.12', 'float')"
    );

    $library = new SwallowtailPhotoLibraryService();
    $event = $library->createEvent('Final Fallback Download Event');
    $library->assignPhotoToEvent(601, (int)$event['id']);

    $service = new SwallowtailDownloadService($storage);
    $method = new ReflectionMethod($service, 'eventFiles');
    $method->setAccessible(true);
    $files = $method->invoke($service, (int)$event['id'], 'final');

    $harness->assertCount(1, $files);
    $harness->assertSame($originalPath, (string)($files[0]['path'] ?? ''));
    $harness->assertSame('Event-Photo_final.jpg', (string)($files[0]['zip_name'] ?? ''));
});

$harness->check(SwallowtailDownloadService::class, 'single final JPEG download uses original asset when profiles match', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('5', 64);
    $storage = new SwallowtailStorageService();
    $originalPath = $storage->imagePath($baseLocation, $sha256, 'original');
    $storage->ensureDirectoryForPath($originalPath);
    file_put_contents($originalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);

    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES (
            602,
            'Single Download.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    swallowtail_backend_record_asset(602, 'original', $originalPath, str_repeat('5', 64));
    InterfaceDB::execute(
        "INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
            (602, 0, 'swallowtail', 'status', 'processed', 'string')"
    );

    $library = new SwallowtailPhotoLibraryService();
    $event = $library->createEvent('Single Final Fallback Download Event');
    $library->assignPhotoToEvent(602, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_download_single_jpeg' => true,
    ]);

    $download = (new SwallowtailDownloadService($storage))->singleJpeg(303, 602);

    $harness->assertSame($originalPath, (string)($download['path'] ?? ''));
    $harness->assertSame('final', (string)($download['image_type'] ?? ''));
    $harness->assertSame('original', (string)($download['source_image_type'] ?? ''));
    $harness->assertSame('Single-Download-final.jpg', (string)($download['filename'] ?? ''));
});

$harness->check(SwallowtailDownloadService::class, 'single final JPEG download waits for current final signature', function () use ($harness, $swallowtailCreateSqliteSchema): void {
    $swallowtailCreateSqliteSchema();

    $baseLocation = swallowtail_backend_storage_tmp_root();
    $sha256 = str_repeat('6', 64);
    $storage = new SwallowtailStorageService();
    $finalPath = $storage->imagePath($baseLocation, $sha256, 'final');
    $storage->ensureDirectoryForPath($finalPath);
    file_put_contents($finalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);

    InterfaceDB::prepareExecute(
        "INSERT INTO photos (
            id,
            original_filename,
            original_extension,
            original_bytes,
            original_sha256,
            storage_base_location,
            upload_state,
            conversion_state
        ) VALUES (
            603,
            'Stale Single Download.CR2',
            'cr2',
            100,
            :sha256,
            :storage_base_location,
            'uploaded',
            'ready'
        )",
        [
            'sha256' => $sha256,
            'storage_base_location' => $baseLocation,
        ]
    );
    swallowtail_backend_record_asset(603, 'final', $finalPath, str_repeat('6', 64), str_repeat('a', 64));
    InterfaceDB::execute(
        "INSERT INTO photo_profile_data (photo_id, revision, type, `key`, value, value_type) VALUES
            (603, 0, 'swallowtail', 'status', 'processed', 'string')"
    );
    swallowtail_backend_enable_final_profile_overlay('stale-single-download');

    $library = new SwallowtailPhotoLibraryService();
    $event = $library->createEvent('Stale Single Final Download Event');
    $library->assignPhotoToEvent(603, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_download_single_jpeg' => true,
    ]);

    try {
        (new SwallowtailDownloadService($storage))->singleJpeg(303, 603);
    } catch (RuntimeException $exception) {
        $harness->assertSame('No final JPEG is available for that photo yet.', $exception->getMessage());
        return;
    }

    throw new RuntimeException('Stale final JPEG was downloadable.');
});

$harness->check(SwallowtailPreviewProfileService::class, 'polls active final job even when an older final image exists', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0013.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    $finalPath = $storage->imagePath($base, $sha256, 'final');
    $finalProfilePath = $storage->imagePath($base, $sha256, 'final_profile');
    $storage->ensureDirectoryForPath($finalPath);
    file_put_contents($finalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    file_put_contents($finalProfilePath, "[Exposure]\nBrightness=10\n", LOCK_EX);

    $event = $library->createEvent('Stale Final Viewer Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
    ]);
    (new SwallowtailProfileDataService())->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');
    swallowtail_backend_enable_final_profile_overlay('final-diff-active-job');
    $currentProfileSignature = (new SwallowtailCombinedProfileService())->profileSignature($photoId, 'final');

    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (photo_id, job_type, image_type, input_path, profile_path, output_path, profile_signature, priority, status, completed_at)
         VALUES (:photo_id, 'image', 'final', :input_path, :profile_path, :output_path, :profile_signature, 10, 'succeeded', CURRENT_TIMESTAMP)",
        [
            'photo_id' => $photoId,
            'input_path' => $storage->imagePath($base, $sha256, 'source'),
            'profile_path' => $finalProfilePath,
            'output_path' => $finalPath,
            'profile_signature' => $currentProfileSignature,
        ]
    );
    swallowtail_backend_record_asset($photoId, 'final', $finalPath, str_repeat('1', 64), $currentProfileSignature);
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (photo_id, job_type, image_type, input_path, profile_path, output_path, profile_signature, priority, status)
         VALUES (:photo_id, 'image', 'final', :input_path, :profile_path, :output_path, :profile_signature, 10, 'queued')",
        [
            'photo_id' => $photoId,
            'input_path' => $storage->imagePath($base, $sha256, 'source'),
            'profile_path' => $finalProfilePath,
            'output_path' => $finalPath,
            'profile_signature' => $currentProfileSignature,
        ]
    );

    $state = (new SwallowtailPreviewProfileService())->pictureViewerState($photoId, 303);
    $activeJobId = (int)InterfaceDB::fetchColumn(
        "SELECT id FROM photo_conversion_jobs WHERE photo_id = :photo_id AND image_type = 'final' AND status IN ('queued', 'processing') LIMIT 1",
        ['photo_id' => $photoId]
    );

    $harness->assertSame('final', (string)($state['display_type'] ?? ''));
    $harness->assertSame('queued', (string)($state['final_status'] ?? ''));
    $harness->assertSame(false, (bool)($state['final_ready'] ?? true));
    $harness->assertSame($activeJobId, (int)($state['job_id'] ?? 0));
    $harness->assertTrue(str_contains((string)($state['display_url'] ?? ''), 'type=final'));
    $harness->assertTrue(str_contains((string)($state['display_url'] ?? ''), 'v=' . str_repeat('1', 64)));
    $harness->assertSame(85, (int)InterfaceDB::fetchColumn(
        "SELECT priority FROM photo_conversion_jobs WHERE id = :id LIMIT 1",
        ['id' => $activeJobId]
    ));

    InterfaceDB::prepareExecute(
        "UPDATE photo_conversion_jobs SET status = 'succeeded', completed_at = CURRENT_TIMESTAMP WHERE id = :id",
        ['id' => $activeJobId]
    );
    swallowtail_backend_record_asset($photoId, 'final', $finalPath, str_repeat('2', 64), $currentProfileSignature, $activeJobId);
    $loaded = (new SwallowtailPreviewProfileService())->pictureViewerState($photoId, 303);

    $harness->assertSame('loaded', (string)($loaded['final_status'] ?? ''));
    $harness->assertSame(true, (bool)($loaded['final_ready'] ?? false));
    $harness->assertTrue(str_contains((string)($loaded['display_url'] ?? ''), 'v=' . str_repeat('2', 64)));
    $harness->assertSame(2, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'final',
    ]));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'obsoletes stale active final job and queues current signature', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0015.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    $finalPath = $storage->imagePath($base, $sha256, 'final');
    $finalProfilePath = $storage->imagePath($base, $sha256, 'final_profile');
    $storage->ensureDirectoryForPath($finalPath);
    file_put_contents($finalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    file_put_contents($finalProfilePath, "[Exposure]\nBrightness=10\n", LOCK_EX);

    $event = $library->createEvent('Stale Active Final Viewer Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
    ]);
    $profileData = new SwallowtailProfileDataService();
    $profileData->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');
    $profileData->setValue($photoId, 'Exposure', 'Brightness', '18', 'int');
    swallowtail_backend_enable_final_profile_overlay('final-diff-obsolete-stale');
    $currentProfileSignature = (new SwallowtailCombinedProfileService())->profileSignature($photoId, 'final');
    $staleProfileSignature = str_repeat('a', 64);

    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (photo_id, job_type, image_type, input_path, profile_path, output_path, profile_signature, priority, status, completed_at)
         VALUES (:photo_id, 'image', 'final', :input_path, :profile_path, :output_path, :profile_signature, 10, 'succeeded', CURRENT_TIMESTAMP)",
        [
            'photo_id' => $photoId,
            'input_path' => $storage->imagePath($base, $sha256, 'source'),
            'profile_path' => $finalProfilePath,
            'output_path' => $finalPath,
            'profile_signature' => $staleProfileSignature,
        ]
    );
    swallowtail_backend_record_asset($photoId, 'final', $finalPath, str_repeat('1', 64), $staleProfileSignature);
    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (photo_id, job_type, image_type, input_path, profile_path, output_path, profile_signature, priority, status)
         VALUES (:photo_id, 'image', 'final', :input_path, :profile_path, :output_path, :profile_signature, 10, 'queued')",
        [
            'photo_id' => $photoId,
            'input_path' => $storage->imagePath($base, $sha256, 'source'),
            'profile_path' => $finalProfilePath,
            'output_path' => $finalPath,
            'profile_signature' => $staleProfileSignature,
        ]
    );

    $state = (new SwallowtailPreviewProfileService())->pictureViewerState($photoId, 303);
    $newJob = InterfaceDB::fetchOne(
        "SELECT id, profile_signature, priority
         FROM photo_conversion_jobs
         WHERE photo_id = :photo_id
           AND image_type = 'final'
           AND status = 'queued'
         ORDER BY id DESC
         LIMIT 1",
        ['photo_id' => $photoId]
    );

    $harness->assertSame('queued', (string)($state['final_status'] ?? ''));
    $harness->assertSame(false, (bool)($state['final_ready'] ?? true));
    $harness->assertTrue(str_contains((string)($state['display_url'] ?? ''), 'v=' . str_repeat('1', 64)));
    $harness->assertTrue(is_array($newJob));
    $harness->assertSame($currentProfileSignature, (string)($newJob['profile_signature'] ?? ''));
    $harness->assertSame(85, (int)($newJob['priority'] ?? 0));
    $harness->assertSame('obsolete', (string)InterfaceDB::fetchColumn(
        "SELECT status FROM photo_conversion_jobs WHERE photo_id = :photo_id AND image_type = 'final' AND profile_signature = :profile_signature AND status = 'obsolete' LIMIT 1",
        ['photo_id' => $photoId, 'profile_signature' => $staleProfileSignature]
    ));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'shows stale final temporarily while queueing fresh final signature', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0016.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    $finalPath = $storage->imagePath($base, $sha256, 'final');
    $finalProfilePath = $storage->imagePath($base, $sha256, 'final_profile');
    $storage->ensureDirectoryForPath($finalPath);
    file_put_contents($finalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    file_put_contents($finalProfilePath, "[Exposure]\nBrightness=10\n", LOCK_EX);

    $event = $library->createEvent('Stale Existing Final Viewer Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
    ]);
    $profileData = new SwallowtailProfileDataService();
    $profileData->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');
    $profileData->setValue($photoId, 'Exposure', 'Brightness', '22', 'int');
    swallowtail_backend_enable_final_profile_overlay('final-diff-stale-display');
    $currentProfileSignature = (new SwallowtailCombinedProfileService())->profileSignature($photoId, 'final');
    $staleProfileSignature = str_repeat('b', 64);

    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (photo_id, job_type, image_type, input_path, profile_path, output_path, profile_signature, priority, status, completed_at)
         VALUES (:photo_id, 'image', 'final', :input_path, :profile_path, :output_path, :profile_signature, 10, 'succeeded', CURRENT_TIMESTAMP)",
        [
            'photo_id' => $photoId,
            'input_path' => $storage->imagePath($base, $sha256, 'source'),
            'profile_path' => $finalProfilePath,
            'output_path' => $finalPath,
            'profile_signature' => $staleProfileSignature,
        ]
    );
    swallowtail_backend_record_asset($photoId, 'final', $finalPath, str_repeat('1', 64), $staleProfileSignature);

    $state = (new SwallowtailPreviewProfileService())->pictureViewerState($photoId, 303);
    $freshJob = InterfaceDB::fetchOne(
        "SELECT profile_signature, status
         FROM photo_conversion_jobs
         WHERE photo_id = :photo_id
           AND image_type = 'final'
           AND profile_signature = :profile_signature
         ORDER BY id DESC
         LIMIT 1",
        [
            'photo_id' => $photoId,
            'profile_signature' => $currentProfileSignature,
        ]
    );

    $harness->assertSame('final', (string)($state['display_type'] ?? ''));
    $harness->assertSame('queued', (string)($state['final_status'] ?? ''));
    $harness->assertSame(false, (bool)($state['final_ready'] ?? true));
    $harness->assertTrue(str_contains((string)($state['display_url'] ?? ''), 'v=' . str_repeat('1', 64)));
    $harness->assertTrue(is_array($freshJob));
    $harness->assertSame('queued', (string)($freshJob['status'] ?? ''));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'loads final when succeeded job matches current signature', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0017.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    $finalPath = $storage->imagePath($base, $sha256, 'final');
    $finalProfilePath = $storage->imagePath($base, $sha256, 'final_profile');
    $storage->ensureDirectoryForPath($finalPath);
    file_put_contents($finalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    file_put_contents($finalProfilePath, "[Exposure]\nBrightness=10\n", LOCK_EX);

    $event = $library->createEvent('Fresh Existing Final Viewer Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
    ]);
    $profileData = new SwallowtailProfileDataService();
    $profileData->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');
    $profileData->setValue($photoId, 'Exposure', 'Brightness', '28', 'int');
    swallowtail_backend_enable_final_profile_overlay('final-diff-fresh-display');
    $currentProfileSignature = (new SwallowtailCombinedProfileService())->profileSignature($photoId, 'final');

    InterfaceDB::prepareExecute(
        "INSERT INTO photo_conversion_jobs (photo_id, job_type, image_type, input_path, profile_path, output_path, profile_signature, priority, status, completed_at)
         VALUES (:photo_id, 'image', 'final', :input_path, :profile_path, :output_path, :profile_signature, 10, 'succeeded', CURRENT_TIMESTAMP)",
        [
            'photo_id' => $photoId,
            'input_path' => $storage->imagePath($base, $sha256, 'source'),
            'profile_path' => $finalProfilePath,
            'output_path' => $finalPath,
            'profile_signature' => $currentProfileSignature,
        ]
    );
    swallowtail_backend_record_asset($photoId, 'final', $finalPath, str_repeat('5', 64), $currentProfileSignature);

    $state = (new SwallowtailPreviewProfileService())->pictureViewerState($photoId, 303);

    $harness->assertSame('loaded', (string)($state['final_status'] ?? ''));
    $harness->assertSame(true, (bool)($state['final_ready'] ?? false));
    $harness->assertTrue(str_contains((string)($state['display_url'] ?? ''), 'v=' . str_repeat('5', 64)));
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'final',
    ]));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'keeps polling while final profile metadata is pending', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService(new SwallowtailStorageService(), $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0014.CR2');
    $photoId = (int)$result['photo_id'];

    $event = $library->createEvent('Pending Final Profile Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
    ]);

    $redis = new class {
        public array $pushes = [];

        public function listPushJson(string $key, array $payload, int $maxLength = 0): bool
        {
            $this->pushes[] = [
                'key' => $key,
                'payload' => $payload,
                'max_length' => $maxLength,
            ];

            return true;
        }
    };

    try {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_profile_queue', 'swallowtail:metadata:profile_viewer_pending_test');
        $service = new SwallowtailPreviewProfileService(
            profileDataService: new SwallowtailProfileDataService($redis)
        );
        $state = $service->pictureViewerState($photoId, 303);

        $harness->assertSame('queued', (string)($state['final_status'] ?? ''));
        $harness->assertSame(false, (bool)($state['final_ready'] ?? true));
        $harness->assertSame(0, InterfaceDB::countWhere('photo_conversion_jobs', [
            'photo_id' => $photoId,
            'image_type' => 'final',
        ]));
        $harness->assertCount(1, $redis->pushes);
        $harness->assertSame('swallowtail:metadata:profile_viewer_pending_test', $redis->pushes[0]['key']);
        $harness->assertSame($photoId, (int)$redis->pushes[0]['payload']['photo_id']);
        $harness->assertSame('picture_viewer_final', (string)$redis->pushes[0]['payload']['reason']);
    } finally {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_profile_queue', 'swallowtail:metadata:profile_urgent');
        @unlink($source);
    }
});

$harness->check(SwallowtailPreviewProfileService::class, 'queues authorised PP3 preview refresh outside web root', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    AppConfigurationStore::config(true);
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
    (new SwallowtailProfileDataService())->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');
    InterfaceDB::execute("INSERT INTO internal_profile_data (image_type, profile_name, `order`, type, `key`, value, value_type) VALUES
        ('preview', 'performance', 1, 'RAW Bayer', 'Method', 'fast', 'string'),
        ('preview', 'resize', 2, 'Resize', 'ShortEdge', '820', 'int'),
        ('preview', 'resize', 2, 'Resize', 'DataSpecified', '5', 'int')");
    $event = $library->createEvent('Preview Edit Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, ['can_view' => true, 'can_edit' => true]);

    $service = new SwallowtailPreviewProfileService();
    $denied = $service->enqueuePreview($photoId, 404, [
        'crop' => ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 120],
        'exposure' => ['black' => 1, 'lightness' => 2, 'contrast' => 3, 'saturation' => 4],
    ]);
    $queued = $service->enqueuePreview($photoId, 303, [
        'crop' => ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 120],
        'exposure' => ['black' => 1, 'lightness' => 2, 'contrast' => 3, 'saturation' => 4],
    ]);

    if (!empty($denied['success'])) {
        throw new RuntimeException('Preview edit unexpectedly allowed an unauthorized user.');
    }
    if (empty($queued['success'])) {
        throw new RuntimeException('Preview edit did not queue successfully: ' . json_encode($queued, JSON_UNESCAPED_SLASHES));
    }
    if ((int)($queued['job_id'] ?? 0) <= 0) {
        throw new RuntimeException('Preview edit did not return a positive job id.');
    }
    if ((string)($queued['preview_url'] ?? '') !== '') {
        throw new RuntimeException('Preview URL was returned before metadata recorded an asset SHA: ' . (string)($queued['preview_url'] ?? ''));
    }
    if (str_contains((string)($queued['status_url'] ?? ''), 'profile' . '_version=')) {
        throw new RuntimeException('Preview status URL still included the removed version parameter: ' . (string)($queued['status_url'] ?? ''));
    }
    if (!str_contains((string)($queued['status_url'] ?? ''), '/api/photo-status.php?') || !str_contains((string)($queued['status_url'] ?? ''), 'image_type=preview')) {
        throw new RuntimeException('Preview status URL did not use the generic image status API: ' . (string)($queued['status_url'] ?? ''));
    }

    $job = InterfaceDB::fetchOne(
        "SELECT profile_path, profile_signature, requested_by_user_id, priority, output_width, output_height
         FROM photo_conversion_jobs
         WHERE id = :id",
        ['id' => (int)$queued['job_id']]
    );

    if (!is_array($job)) {
        throw new RuntimeException('Preview job row was not found.');
    }
    $profilePath = (string)($job['profile_path'] ?? '');
    $harness->assertSame(1, preg_match('/^[a-f0-9]{64}$/', (string)($job['profile_signature'] ?? '')));
    $harness->assertSame(303, (int)($job['requested_by_user_id'] ?? 0));
    $harness->assertSame(70, (int)($job['priority'] ?? 0));
    $harness->assertSame(0, (int)($job['output_width'] ?? 0));
    $harness->assertSame(0, (int)($job['output_height'] ?? 0));
    if ($profilePath === '') {
        throw new RuntimeException('Preview job did not store a profile path.');
    }
    if (!is_file($profilePath)) {
        throw new RuntimeException('Preview profile path was not written: ' . $profilePath);
    }
    if (str_starts_with($profilePath, APP_ROOT . 'web_root' . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Preview profile path was inside web_root: ' . $profilePath);
    }
    if (!str_ends_with($profilePath, '_preview.pp3')) {
        throw new RuntimeException('Preview profile path did not use the preview profile suffix: ' . $profilePath);
    }
    $profileContents = (string)file_get_contents($profilePath);
    foreach (["[Crop]", "Enabled=true", "X=10", "Y=20", "W=100", "H=120"] as $needle) {
        if (!str_contains($profileContents, $needle)) {
            throw new RuntimeException('Preview profile file did not contain the expected crop setting: ' . $needle);
        }
    }
    if ((int)InterfaceDB::fetchColumn("SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'Exposure' AND `key` = 'Contrast'", ['photo_id' => $photoId]) !== 1) {
        throw new RuntimeException('Preview edit did not record first Exposure.Contrast revision.');
    }
    if ((int)InterfaceDB::fetchColumn("SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'Crop' AND `key` = 'X'", ['photo_id' => $photoId]) !== 1) {
        throw new RuntimeException('Preview edit did not record first Crop.X revision.');
    }
    if ((int)InterfaceDB::fetchColumn("SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'White Balance' AND `key` = 'Temperature'", ['photo_id' => $photoId]) !== 1) {
        throw new RuntimeException('Preview edit did not record default White Balance.Temperature revision.');
    }
    if ((int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'swallowtail' AND `key` = 'status' AND revision = 0", ['photo_id' => $photoId]) !== 1) {
        throw new RuntimeException('Preview edit did not keep swallowtail status at revision zero.');
    }
    if (!str_contains($profileContents, "[RAW Bayer]") || !str_contains($profileContents, "Method=fast")) {
        throw new RuntimeException('Preview profile file did not contain the internal performance overlay.');
    }
    if (!str_contains($profileContents, "[Resize]") || !str_contains($profileContents, "ShortEdge=820") || !str_contains($profileContents, "DataSpecified=5")) {
        throw new RuntimeException('Preview profile file did not contain the internal resize overlay.');
    }

    $second = $service->enqueuePreview($photoId, 303, [
        'crop' => ['x' => 30, 'y' => 40, 'width' => 90, 'height' => 110],
        'exposure' => ['black' => 2, 'lightness' => 3, 'contrast' => 4, 'saturation' => 5],
    ]);
    if (empty($second['success'])) {
        throw new RuntimeException('Second preview edit did not queue successfully: ' . json_encode($second, JSON_UNESCAPED_SLASHES));
    }
    $harness->assertSame(2, (int)InterfaceDB::fetchColumn("SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'Exposure' AND `key` = 'Contrast'", ['photo_id' => $photoId]));
    $harness->assertSame(2, (int)InterfaceDB::fetchColumn("SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'Crop' AND `key` = 'X'", ['photo_id' => $photoId]));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'White Balance' AND `key` = 'Temperature'", ['photo_id' => $photoId]));
    $harness->assertSame('obsolete', (string)InterfaceDB::fetchColumn(
        'SELECT status FROM photo_conversion_jobs WHERE id = :id LIMIT 1',
        ['id' => (int)$queued['job_id']]
    ));

    $storage = new SwallowtailStorageService();
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    $previewPath = $storage->imagePath($base, $sha256, 'preview');
    $thumbnailPath = $storage->imagePath($base, $sha256, 'thumbnail');
    $originalPath = $storage->imagePath($base, $sha256, 'original');
    $storage->ensureDirectoryForPath($previewPath);
    file_put_contents($previewPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    file_put_contents($thumbnailPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    file_put_contents($originalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset($photoId, 'thumbnail', $thumbnailPath, str_repeat('7', 64));

    $thumbnailStatus = $service->imageStatus($photoId, 0, 303, 'thumbnail');
    $harness->assertSame(true, (bool)($thumbnailStatus['success'] ?? false));
    $harness->assertSame('succeeded', (string)($thumbnailStatus['status'] ?? ''));
    $harness->assertSame(true, (bool)($thumbnailStatus['ready'] ?? false));
    $harness->assertTrue(str_contains((string)($thumbnailStatus['thumbnail_url'] ?? ''), 'type=thumbnail'));
    $harness->assertTrue(str_contains((string)($thumbnailStatus['thumbnail_url'] ?? ''), 'v=' . str_repeat('7', 64)));

    $queuedStatus = $service->imageStatus($photoId, (int)$second['job_id'], 303, 'preview');
    $harness->assertSame('queued', (string)($queuedStatus['status'] ?? ''));
    $harness->assertTrue(!array_key_exists('original_url', $queuedStatus));
    $harness->assertTrue(!array_key_exists('preview_url', $queuedStatus));
    $invalidStatus = $service->imageStatus($photoId, (int)$second['job_id'], 303, 'thumbnail');
    $harness->assertSame(false, (bool)($invalidStatus['success'] ?? true));

    InterfaceDB::prepareExecute(
        "UPDATE photo_conversion_jobs
         SET status = 'succeeded'
         WHERE id = :id",
        ['id' => (int)$second['job_id']]
    );
    swallowtail_backend_record_asset($photoId, 'preview', $previewPath, str_repeat('6', 64), (string)InterfaceDB::fetchColumn(
        "SELECT profile_signature FROM photo_conversion_jobs WHERE id = :id LIMIT 1",
        ['id' => (int)$second['job_id']]
    ), (int)$second['job_id']);
    $status = $service->imageStatus($photoId, (int)$second['job_id'], 303, 'preview');
    if (empty($status['success'])) {
        throw new RuntimeException('Preview status did not succeed: ' . json_encode($status, JSON_UNESCAPED_SLASHES));
    }
    $harness->assertSame('succeeded', (string)($status['status'] ?? ''));
    if (!str_contains((string)($status['preview_url'] ?? ''), 'v=' . str_repeat('6', 64))) {
        throw new RuntimeException('Preview status URL did not include the asset SHA: ' . (string)($status['preview_url'] ?? ''));
    }
    $harness->assertTrue(str_contains((string)($status['preview_url'] ?? ''), 'type=preview'));

    @unlink($source);
});

$harness->check(SwallowtailPreviewProfileService::class, 'skips editor final queue when final profile matches original', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    AppConfigurationStore::config(true);
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $storage = new SwallowtailStorageService();
    $library = new SwallowtailPhotoLibraryService();
    $ingest = new SwallowtailPhotoIngestService($storage, $library, new SwallowtailConversionQueueService());
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0019.CR2');
    $photoId = (int)$result['photo_id'];
    $photo = $library->photoById($photoId);
    $sha256 = (string)($photo['original_sha256'] ?? '');
    $base = (string)($photo['storage_base_location'] ?? '');
    $originalPath = $storage->imagePath($base, $sha256, 'original');
    $storage->ensureDirectoryForPath($originalPath);
    file_put_contents($originalPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset($photoId, 'original', $originalPath, str_repeat('9', 64));

    $profileData = new SwallowtailProfileDataService();
    $profileData->setValue($photoId, 'swallowtail', 'status', 'processed', 'string');
    $payload = [
        'crop' => ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 120],
        'exposure' => ['black' => 1, 'lightness' => 2, 'contrast' => 3, 'saturation' => 4],
    ];
    $service = new SwallowtailPreviewProfileService();
    $settings = $service->normaliseSettings($payload, 6000, 4000);
    $rowsMethod = new ReflectionMethod($service, 'profileRowsForSettings');
    $rowsMethod->setAccessible(true);
    foreach ($rowsMethod->invoke($service, $settings) as $row) {
        $profileData->setValue(
            $photoId,
            (string)($row['type'] ?? ''),
            (string)($row['key'] ?? ''),
            $row['value'] ?? null,
            (string)($row['value_type'] ?? 'string')
        );
    }
    $event = $library->createEvent('Skip Final Edit Event');
    $library->assignPhotoToEvent($photoId, (int)$event['id']);
    $library->grantEventPermission((int)$event['id'], 303, [
        'can_view' => true,
        'can_edit' => true,
        'can_download_single_jpeg' => true,
    ]);

    $queued = $service->enqueueFinal($photoId, 303, $payload);

    $harness->assertTrue(!empty($queued['success']));
    $harness->assertSame(0, (int)($queued['job_id'] ?? -1));
    $harness->assertSame('loaded', (string)($queued['final_status'] ?? ''));
    $harness->assertSame(true, (bool)($queued['final_ready'] ?? false));
    $harness->assertTrue(str_contains((string)($queued['final_url'] ?? ''), 'type=original'));
    $harness->assertTrue(str_contains((string)($queued['status_url'] ?? ''), 'image_type=final'));
    $harness->assertSame(0, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'final',
    ]));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
        "SELECT MAX(revision) FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'Exposure' AND `key` = 'Contrast'",
        ['photo_id' => $photoId]
    ));

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
    $sha256 = hash_file('sha256', $source);
    if (!is_string($sha256)) {
        throw new RuntimeException('Unable to SHA-256 hash RAW fixture.');
    }

    $response = $swallowtailInvokeRawUploadApi([
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token['token'],
        'HTTP_USER_AGENT' => 'spicebush-test',
        'HTTP_X_SWALLOWTAIL_DEVICE_ID' => 'DESKTOP-C6R0CCD',
        'HTTP_X_SWALLOWTAIL_CHECKSUM_SHA256' => $sha256,
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
    $harness->assertSame($sha256, (string)($payload['sha256'] ?? ''));
    $harness->assertTrue(!array_key_exists('quick_hash', $payload));
    $harness->assertCount(3, (array)($payload['conversion_jobs'] ?? []));
    $harness->assertTrue((int)(($payload['conversion_jobs']['embedded'] ?? [])['job_id'] ?? 0) > 0);
    $harness->assertTrue((int)(($payload['conversion_jobs']['thumbnail'] ?? [])['job_id'] ?? 0) > 0);
    $harness->assertTrue((int)(($payload['conversion_jobs']['original'] ?? [])['job_id'] ?? 0) > 0);
    $harness->assertSame(1, InterfaceDB::tableRowCount('photos'));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));

    $checksum = (string)($payload['sha256'] ?? '');
    if ($checksum !== '') {
        $row = InterfaceDB::fetchOne('SELECT storage_base_location FROM photos WHERE original_sha256 = :checksum LIMIT 1', ['checksum' => $checksum]);
        if (is_array($row)) {
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

    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', $blockedBase);
    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
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
    $harness->assertSame('/api/upload-raw.php', (string)($activityRows[0]['request_uri'] ?? ''));
    $harness->assertSame('DESKTOP-C6R0CCD', (string)($activityRows[0]['device_id'] ?? ''));
    $harness->assertCount(1, $logsRows);
    $harness->assertSame('Token Account', (string)($logsRows[0]['user_display_name'] ?? ''));
    $harness->assertSame('No writable storage locations available.', (string)($logsRows[0]['message_text'] ?? ''));

    \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.test_base_location', '');
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
    $sha256 = hash_file('sha256', $source);
    if (!is_string($sha256)) {
        throw new RuntimeException('Unable to SHA-256 hash RAW fixture.');
    }
    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'CONTENT_LENGTH' => '64'],
        [],
        [
            'Authorization' => 'Bearer ' . $token['token'],
            'X-Swallowtail-Filename' => 'IMG_0004.CR2',
            'X-Swallowtail-Checksum-SHA256' => $sha256,
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
    $storage = new SwallowtailStorageService();
    $staging = $storage->rawUploadStagingFileForChecksum($sha256, filesize($source));
    $temporaryPattern = rtrim(dirname((string)$staging['temporary_path']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*_source.cr2';
    @unlink((string)$staging['temporary_path']);
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
    $token = $library->createUploadToken('ESP32 test rig', 12, null, ['203.0.113.0/24']);
    $source = swallowtail_backend_test_temp_file('swallowtail-body-test-');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }

    $swallowtailWriteRawFixture($source, 'cr2');
    $sha256 = hash_file('sha256', $source);
    if (!is_string($sha256)) {
        throw new RuntimeException('Unable to SHA-256 hash RAW fixture.');
    }
    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'CONTENT_LENGTH' => (string)filesize($source)],
        [],
        [
            'Authorization' => 'Bearer ' . $token['token'],
            'X-Swallowtail-Filename' => 'IMG_0004.CR2',
            'X-Swallowtail-Checksum-SHA256' => $sha256,
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
    $harness->assertSame($sha256, (string)($payload['sha256'] ?? ''));
    $harness->assertSame(1, InterfaceDB::tableRowCount('photos'));
    $harness->assertSame(1, InterfaceDB::countWhereNotNull('api_upload_tokens', 'last_used_at', ['id' => (int)$token['id']]));
    $row = InterfaceDB::fetchOne('SELECT storage_base_location, uploaded_by_user_id, upload_token_id FROM photos WHERE original_sha256 = :sha256 LIMIT 1', ['sha256' => $sha256]);
    $harness->assertTrue(is_array($row));
    $harness->assertSame(12, (int)($row['uploaded_by_user_id'] ?? 0));
    $harness->assertSame((int)$token['id'], (int)($row['upload_token_id'] ?? 0));
    $temporaryPattern = rtrim((string)($row['storage_base_location'] ?? ''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . SwallowtailStorageService::DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . '*_source.cr2';
    $harness->assertSame([], glob($temporaryPattern) ?: []);

    @unlink($source);
});

$harness->check(SwallowtailRawUploadApiService::class, 'keeps raw upload timing out of PHP error logs by default', function () use ($harness): void {
    $method = new ReflectionMethod(SwallowtailRawUploadApiService::class, 'logRawUploadTiming');
    $service = new SwallowtailRawUploadApiService();
    $logFile = swallowtail_backend_test_temp_file('swallowtail-raw-upload-timing-log-');
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    $originalLogErrors = ini_get('log_errors');
    $originalErrorLog = ini_get('error_log');
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('trace.raw_upload_timing', false);
        ini_set('log_errors', '1');
        ini_set('error_log', $logFile);

        $method->invoke($service, ['status' => 'test_disabled']);

        $harness->assertSame('', (string)file_get_contents($logFile));
    } finally {
        ini_set('log_errors', is_string($originalLogErrors) ? $originalLogErrors : '');
        ini_set('error_log', is_string($originalErrorLog) ? $originalErrorLog : '');
        file_put_contents($configPath, $originalConfig, LOCK_EX);
        AppConfigurationStore::config(true);
        @unlink($logFile);
    }
});

$harness->check(SwallowtailRawUploadApiService::class, 'writes raw upload timing to PHP error logs when trace option is enabled', function () use ($harness): void {
    $method = new ReflectionMethod(SwallowtailRawUploadApiService::class, 'logRawUploadTiming');
    $service = new SwallowtailRawUploadApiService();
    $logFile = swallowtail_backend_test_temp_file('swallowtail-raw-upload-timing-log-');
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = file_get_contents($configPath);
    $originalLogErrors = ini_get('log_errors');
    $originalErrorLog = ini_get('error_log');
    if (!is_string($originalConfig)) {
        throw new RuntimeException('Unable to read fixture config.');
    }

    try {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('trace.raw_upload_timing', true);
        ini_set('log_errors', '1');
        ini_set('error_log', $logFile);

        $method->invoke($service, ['status' => 'test_enabled']);

        $content = (string)file_get_contents($logFile);
        $harness->assertTrue(str_contains($content, 'SwallowTail raw upload timing: '));
        $harness->assertTrue(str_contains($content, '"status":"test_enabled"'));
    } finally {
        ini_set('log_errors', is_string($originalLogErrors) ? $originalLogErrors : '');
        ini_set('error_log', is_string($originalErrorLog) ? $originalErrorLog : '');
        file_put_contents($configPath, $originalConfig, LOCK_EX);
        AppConfigurationStore::config(true);
        @unlink($logFile);
    }
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
    $sha256 = hash_file('sha256', $source);
    if (!is_string($sha256)) {
        throw new RuntimeException('Unable to SHA-256 hash RAW fixture.');
    }
    $request = new RequestFramework(
        [],
        [],
        ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.15', 'CONTENT_LENGTH' => (string)filesize($source)],
        [],
        [
            'Authorization' => 'Bearer ' . $token['token'],
            'X-Swallowtail-Filename' => 'SPICEBUSH_0004.CR2',
            'X-Swallowtail-Checksum-SHA256' => $sha256,
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
    $harness->assertSame($sha256, (string)($payload['sha256'] ?? ''));
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
    $hidden = $library->uploadTokenById($tokenId);
    $harness->assertTrue(is_array($hidden));
    $harness->assertSame(1, (int)($hidden['hidden'] ?? 0));
    $harness->assertSame(0, (int)($hidden['can_upload_raw'] ?? 1));
    $harness->assertSame(0, (int)($hidden['is_active'] ?? 1));
    $harness->assertTrue(trim((string)($hidden['expires_at'] ?? '')) !== '');
    $harness->assertSame(0, count($library->listUploadTokens()));
    $harness->assertSame(1, InterfaceDB::tableRowCount('api_upload_tokens'));
    $harness->assertSame(1, InterfaceDB::tableRowCount('api_upload_token_cidrs'));
    $harness->assertTrue($library->authenticateUploadToken((string)$created['token'], '198.51.100.42') === null);
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
    $previewPath = $storage->imagePath((string)($photo['storage_base_location'] ?? ''), (string)($photo['original_sha256'] ?? ''), 'preview');
    $storage->ensureDirectoryForPath($previewPath);
    file_put_contents($previewPath, "\xFF\xD8\xFF\xD9", LOCK_EX);
    swallowtail_backend_record_asset($photoId, 'preview', $previewPath, str_repeat('a', 64));

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
    $harness->assertTrue(!empty(($payload['images']['preview'] ?? [])['ready']));
    $harness->assertTrue(!array_key_exists('storage_path', (array)($payload['images']['preview'] ?? [])));
    $harness->assertTrue(empty(($payload['images']['final'] ?? [])['ready']));

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
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.store_on_root_partition', false);
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);
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

            \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 100);
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

            \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.full_threshold_percent', 0);
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
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.round_robin_locations', true);
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
        \Swallowtail\Store\SwallowtailConfigurationStore::set('storage.round_robin_locations', true);

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
    $removeQuickHashPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_22_001_remove_original_quick_hash.sql';
    $metadataPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_23_003_normalize_photo_metadata.sql';
    $conversionPriorityPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_23_004_conversion_priority_preempt.sql';
    $profileDataPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_24_001_photo_profile_data.sql';
    $internalProfileDataPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_25_001_internal_profile_data.sql';
    $profileRevisionPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_25_002_photo_profile_data_revisions.sql';
    $thumbnailImageTypePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_25_004_thumbnail_image_type.sql';
    $reassertPreviewFinalPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_26_001_reassert_preview_final_conversion_types.sql';
    $fixPreviewProfileDataPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_26_002_fix_preview_internal_profile_data.sql';
    $widenProfileSectionsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_26_003_widen_profile_section_names.sql';
    $internalProfileDataEnabledPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_26_004_internal_profile_data_enabled.sql';
    $rawTherapeeProfileDataPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_26_005_rawtherapee_profile_data.sql';
    $eventEditPermissionPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_26_006_event_edit_permission.sql';
    $conversionProfileSignaturePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_26_007_conversion_profile_signature.sql';
    $photoImageAssetsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_26_008_photo_image_assets.sql';
    $rawTherapeeSampleVariantsPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_28_001_rawtherapee_sample_asset_variants.sql';
    $fixRawTherapeeSampleEnumPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_06_29_001_fix_rawtherapee_sample_enum.sql';
    $sql = file_get_contents($path);
    $conversionSql = file_get_contents($conversionPath);
    $hardeningSql = file_get_contents($hardeningPath);
    $tokenCidrsSql = file_get_contents($tokenCidrsPath);
    $durationSql = file_get_contents($durationPath);
    $embeddedSql = file_get_contents($embeddedPath);
    $quickHashSql = file_get_contents($quickHashPath);
    $storageMigrationSql = file_get_contents($storageMigrationPath);
    $removeQuickHashSql = file_get_contents($removeQuickHashPath);
    $metadataSql = file_get_contents($metadataPath);
    $conversionPrioritySql = file_get_contents($conversionPriorityPath);
    $profileDataSql = file_get_contents($profileDataPath);
    $internalProfileDataSql = file_get_contents($internalProfileDataPath);
    $profileRevisionSql = file_get_contents($profileRevisionPath);
    $thumbnailImageTypeSql = file_get_contents($thumbnailImageTypePath);
    $reassertPreviewFinalSql = file_get_contents($reassertPreviewFinalPath);
    $fixPreviewProfileDataSql = file_get_contents($fixPreviewProfileDataPath);
    $widenProfileSectionsSql = file_get_contents($widenProfileSectionsPath);
    $internalProfileDataEnabledSql = file_get_contents($internalProfileDataEnabledPath);
    $rawTherapeeProfileDataSql = file_get_contents($rawTherapeeProfileDataPath);
    $eventEditPermissionSql = file_get_contents($eventEditPermissionPath);
    $conversionProfileSignatureSql = file_get_contents($conversionProfileSignaturePath);
    $photoImageAssetsSql = file_get_contents($photoImageAssetsPath);
    $rawTherapeeSampleVariantsSql = file_get_contents($rawTherapeeSampleVariantsPath);
    $fixRawTherapeeSampleEnumSql = file_get_contents($fixRawTherapeeSampleEnumPath);

    if (!is_string($sql) || !is_string($conversionSql) || !is_string($hardeningSql) || !is_string($tokenCidrsSql) || !is_string($durationSql) || !is_string($embeddedSql) || !is_string($quickHashSql) || !is_string($storageMigrationSql) || !is_string($removeQuickHashSql) || !is_string($metadataSql) || !is_string($conversionPrioritySql) || !is_string($profileDataSql) || !is_string($internalProfileDataSql) || !is_string($profileRevisionSql) || !is_string($thumbnailImageTypeSql) || !is_string($reassertPreviewFinalSql) || !is_string($fixPreviewProfileDataSql) || !is_string($widenProfileSectionsSql) || !is_string($internalProfileDataEnabledSql) || !is_string($rawTherapeeProfileDataSql) || !is_string($eventEditPermissionSql) || !is_string($conversionProfileSignatureSql) || !is_string($photoImageAssetsSql) || !is_string($rawTherapeeSampleVariantsSql) || !is_string($fixRawTherapeeSampleEnumSql)) {
        throw new RuntimeException('SwallowTail migration could not be read.');
    }

    $sql .= "\n" . $conversionSql . "\n" . $hardeningSql . "\n" . $tokenCidrsSql . "\n" . $durationSql . "\n" . $embeddedSql . "\n" . $quickHashSql . "\n" . $storageMigrationSql . "\n" . $removeQuickHashSql . "\n" . $metadataSql . "\n" . $conversionPrioritySql . "\n" . $profileDataSql . "\n" . $internalProfileDataSql . "\n" . $profileRevisionSql . "\n" . $thumbnailImageTypeSql . "\n" . $reassertPreviewFinalSql . "\n" . $fixPreviewProfileDataSql . "\n" . $widenProfileSectionsSql . "\n" . $internalProfileDataEnabledSql . "\n" . $rawTherapeeProfileDataSql . "\n" . $eventEditPermissionSql . "\n" . $conversionProfileSignatureSql . "\n" . $photoImageAssetsSql . "\n" . $rawTherapeeSampleVariantsSql . "\n" . $fixRawTherapeeSampleEnumSql;

    foreach ([
        'CREATE TABLE IF NOT EXISTS events',
        'CREATE TABLE IF NOT EXISTS storage_location_properties',
        'CREATE TABLE IF NOT EXISTS storage_migration_jobs',
        'CREATE TABLE IF NOT EXISTS storage_migration_job_items',
        'CREATE TABLE IF NOT EXISTS photos',
        'CREATE TABLE IF NOT EXISTS event_permissions',
        "grantee_type enum('user','role') NOT NULL DEFAULT 'user'",
        'grantee_id int(11) NOT NULL',
        'can_edit tinyint(1) NOT NULL DEFAULT 0',
        'UNIQUE KEY uq_event_permissions_event_grantee (event_id, grantee_type, grantee_id)',
        'KEY idx_event_permissions_grantee (grantee_type, grantee_id, event_id)',
        'ADD COLUMN IF NOT EXISTS grantee_type',
        'ADD COLUMN IF NOT EXISTS grantee_id',
        'UPDATE event_permissions',
        'DROP COLUMN IF EXISTS user_id',
        'CREATE TABLE IF NOT EXISTS api_upload_tokens',
        'CREATE TABLE IF NOT EXISTS api_upload_token_cidrs',
        'CREATE TABLE IF NOT EXISTS photo_conversion_jobs',
        'storage_base_location',
        'is_zfs',
        'dataset_name',
        'image_type enum',
        'profile_path',
        'profile_signature char(64) DEFAULT NULL',
        'KEY idx_conversion_jobs_profile_signature (photo_id, image_type, profile_signature, status)',
        'asset_variant_key char(64)',
        'UNIQUE KEY uq_photo_image_assets_photo_type_variant (photo_id, image_type, asset_variant_key)',
        'KEY idx_photo_image_assets_variant (photo_id, image_type, asset_variant_key, profile_signature)',
        'output_width',
        'duration_seconds',
        "'embedded'",
        "'thumbnail'",
        "'preview'",
        "'final'",
        'DROP INDEX IF EXISTS idx_photos_quick_hash ON photos',
        'DROP COLUMN IF EXISTS original_quick_hash',
        'CREATE TABLE IF NOT EXISTS photo_metadata',
        'CREATE TABLE IF NOT EXISTS photo_metadata_property',
        'CREATE TABLE IF NOT EXISTS photo_profile_data',
        'CREATE TABLE IF NOT EXISTS internal_profile_data',
        'CREATE TABLE IF NOT EXISTS rawtherapee_profile_data',
        'UNIQUE KEY uq_photo_profile_data_key (photo_id, type, `key`)',
        'ADD COLUMN revision int NOT NULL DEFAULT 0 AFTER photo_id',
        'ADD UNIQUE KEY uq_photo_profile_data_key (photo_id, type, `key`, revision)',
        'ADD KEY idx_photo_profile_data_effective (photo_id, type, `key`, revision)',
        'UNIQUE KEY uq_internal_profile_data_key (image_type, profile_name, type, `key`)',
        'KEY idx_internal_profile_data_order (image_type, `order`, profile_name)',
        'image_type varchar(32) NOT NULL',
        'profile_name varchar(64) NOT NULL',
        '`order` int NOT NULL',
        'enabled tinyint(1) NOT NULL DEFAULT 1',
        'ADD COLUMN enabled tinyint(1) NOT NULL DEFAULT 1 AFTER `order`',
        "'performance', 1",
        "'resize', 2",
        "'RAW Bayer', 'Method', 'fast'",
        "'Resize', 'LongEdge', '820'",
        "'Resize', 'DataSpecified', '5'",
        "'Resize', 'Width', '0'",
        "'Resize', 'Height', '0'",
        "'Resize', 'LongEdge', '0'",
        "'Resize', 'ShortEdge', '820'",
        "'preview-performance'",
        "'preview-resize'",
        "'thumbnail', 'resize', 1",
        "'Resize', 'ShortEdge', '180'",
        "status enum('queued','processing','succeeded','failed','cancelled','obsolete')",
        'MODIFY priority int(10) unsigned NOT NULL DEFAULT 20',
        'ADD INDEX idx_conversion_jobs_priority (status, priority, available_at, id)',
        'DROP TABLE IF EXISTS photo_metadata_property',
        'DROP TABLE IF EXISTS photo_metadata',
        "`key` varchar(191) NOT NULL",
        "MODIFY type varchar(64) NOT NULL",
        "value_type enum('null','bool','int','float','string') NOT NULL",
        "'rawtherapee_sample'",
        "WHERE image_type = 'rawtheapee_sample'",
        'UNIQUE KEY uq_rawtherapee_profile_path (profile_path)',
        "CHECK (original_extension = 'cr2')",
    ] as $needle) {
        $harness->assertTrue(str_contains($sql, $needle));
    }

    foreach ([
        'CREATE TABLE IF NOT EXISTS swallowtail_storage_locations',
        'CREATE TABLE IF NOT EXISTS swallowtail_photo_derivatives',
        'output_storage_path',
        'metadata_json longtext',
        'captured_timezone_offset_minutes',
        'captured_timezone_source',
        'server_timezone_name_at_upload',
    ] as $needle) {
        $targetSql = in_array($needle, ['metadata_json longtext', 'captured_timezone_offset_minutes', 'captured_timezone_source', 'server_timezone_name_at_upload'], true)
            ? $metadataSql
            : $sql;
        $harness->assertTrue(!str_contains($targetSql, $needle));
    }
});

$harness->check(SwallowtailProfileDataService::class, 'queues urgent profile notification for viewed photos', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $redis = new class {
        public array $pushes = [];

        public function listPushJson(string $key, array $payload, int $maxLength = 0): bool
        {
            $this->pushes[] = [
                'key' => $key,
                'payload' => $payload,
                'max_length' => $maxLength,
            ];

            return true;
        }
    };

    try {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_profile_queue', 'swallowtail:metadata:profile_urgent_test');
        $ingest = new SwallowtailPhotoIngestService(
            new SwallowtailStorageService(),
            new SwallowtailPhotoLibraryService(),
            new SwallowtailConversionQueueService()
        );
        $result = $ingest->ingestLocalRawFile($source, 'IMG_0009.CR2');
        $photoId = (int)$result['photo_id'];
        $service = new SwallowtailProfileDataService($redis);
        $status = $service->requestUrgentProfile(['id' => $photoId], 'picture_viewer');

        $harness->assertSame('queued', (string)$status['status']);
        $harness->assertSame('queued', (string)InterfaceDB::fetchColumn(
            "SELECT value FROM photo_profile_data WHERE photo_id = :photo_id AND type = 'swallowtail' AND `key` = 'status' LIMIT 1",
            ['photo_id' => $photoId]
        ));
        $harness->assertCount(1, $redis->pushes);
        $harness->assertSame('swallowtail:metadata:profile_urgent_test', $redis->pushes[0]['key']);
        $harness->assertSame(512, $redis->pushes[0]['max_length']);
        $harness->assertSame($photoId, (int)$redis->pushes[0]['payload']['photo_id']);
        $harness->assertSame('picture_viewer', (string)$redis->pushes[0]['payload']['reason']);
    } finally {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_profile_queue', 'swallowtail:metadata:profile_urgent');
        @unlink($source);
    }
});

$harness->check(SwallowtailConversionQueueService::class, 'deduplicates image jobs by photo type and profile signature', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
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
    $profile = (new SwallowtailStorageService())->imagePath((string)$result['storage_base_location'], (string)$result['sha256'], 'preview_profile');
    $profileSignature = str_repeat('c', 64);
    $first = $queue->enqueuePreviewRefresh($photoId, $profile, 2, $profileSignature);
    $second = $queue->enqueuePreviewRefresh($photoId, $profile, 2, $profileSignature);

    $harness->assertSame($first, $second);
    $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
        'photo_id' => $photoId,
        'image_type' => 'preview',
        'profile_signature' => $profileSignature,
    ]));

    @unlink($source);
});

$harness->check(SwallowtailConversionQueueService::class, 'sends Redis preempt signals for high priority preview jobs', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
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
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0008.CR2');
    $notifications = [];
    $queue = new SwallowtailConversionQueueService(static function (int $jobId, string $imageType, int $priority, string $messageType) use (&$notifications): void {
        $notifications[] = [
            'job_id' => $jobId,
            'image_type' => $imageType,
            'priority' => $priority,
            'message_type' => $messageType,
        ];
    });

    $profile = (new SwallowtailStorageService())->imagePath((string)$result['storage_base_location'], (string)$result['sha256'], 'preview_profile');
    $previewJobId = $queue->enqueuePreviewRefresh((int)$result['photo_id'], $profile, 2, str_repeat('d', 64));

    $harness->assertTrue((int)$previewJobId > 0);
    $previewJob = InterfaceDB::fetchOne(
        "SELECT image_type, status
         FROM photo_conversion_jobs
         WHERE id = :id
         LIMIT 1",
        ['id' => (int)$previewJobId]
    );
    $harness->assertTrue(is_array($previewJob));
    $harness->assertSame('preview', (string)($previewJob['image_type'] ?? ''));
    $harness->assertSame('queued', (string)($previewJob['status'] ?? ''));
    $preempts = array_values(array_filter(
        $notifications,
        static fn(array $notification): bool => (string)$notification['message_type'] === 'preempt'
    ));
    $harness->assertCount(1, $preempts);
    $harness->assertSame(['preview'], array_map(static fn(array $notification): string => (string)$notification['image_type'], $preempts));
    $harness->assertSame([70], array_map(static fn(array $notification): int => (int)$notification['priority'], $preempts));
    $harness->assertSame($previewJobId, (int)$preempts[0]['job_id']);

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

$harness->check(SwallowtailConversionQueueService::class, 'notifies Redis only after RAW conversion batch is durable', function () use ($harness, $swallowtailCreateSqliteSchema, $swallowtailWriteRawFixture): void {
    $swallowtailCreateSqliteSchema();

    $source = swallowtail_backend_test_temp_file('swallowtail-test-');
    if (!is_string($source)) {
        throw new RuntimeException('Unable to create RAW fixture.');
    }
    $swallowtailWriteRawFixture($source, 'cr2');

    $rawNotificationJobIds = [];
    $queue = new SwallowtailConversionQueueService(static function (int $jobId) use ($harness, &$rawNotificationJobIds): void {
        $job = InterfaceDB::fetchOne(
            'SELECT photo_id, image_type FROM photo_conversion_jobs WHERE id = :id LIMIT 1',
            ['id' => $jobId]
        );
        $harness->assertTrue(is_array($job));
        $imageType = (string)($job['image_type'] ?? '');
        if (!in_array($imageType, ['embedded', 'thumbnail', 'original'], true)) {
            return;
        }

        $rawNotificationJobIds[$jobId] = true;
        $photoId = (int)($job['photo_id'] ?? 0);
        $harness->assertTrue(InterfaceDB::countWhere('photo_conversion_jobs', 'photo_id', $photoId) >= 3);
        $photo = InterfaceDB::fetchOne(
            'SELECT conversion_state FROM photos WHERE id = :id LIMIT 1',
            ['id' => $photoId]
        );
        $harness->assertSame('processing', (string)($photo['conversion_state'] ?? ''));
    });

    $ingest = new SwallowtailPhotoIngestService(
        new SwallowtailStorageService(),
        new SwallowtailPhotoLibraryService(),
        $queue
    );
    $result = $ingest->ingestLocalRawFile($source, 'IMG_0007.CR2');

    $harness->assertTrue(!empty($result['success']));
    $harness->assertSame(3, count($rawNotificationJobIds));
    foreach (['embedded', 'thumbnail', 'original'] as $imageType) {
        $harness->assertSame(1, InterfaceDB::countWhere('photo_conversion_jobs', [
            'photo_id' => (int)$result['photo_id'],
            'image_type' => $imageType,
        ]));
    }

    @unlink($source);
});
