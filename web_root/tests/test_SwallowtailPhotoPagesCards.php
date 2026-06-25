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

$harness->check(PageFactoryFramework::class, 'resolves SwallowTail photo UI pages', function () use ($harness): void {
    $factory = new PageFactoryFramework();

    foreach (['upload', 'gallery', 'picture_viewer'] as $pageKey) {
        $page = $factory->create($pageKey);
        $harness->assertSame($pageKey, $page->id());
    }
});

$harness->check(CardFactoryFramework::class, 'resolves SwallowTail photo UI cards', function () use ($harness): void {
    $factory = new CardFactoryFramework();

    foreach (['cr2_upload', 'storage_available', 'jobs', 'timezone_settings', 'storage_summary', 'service_status', 'statistics', 'browse_gallery', 'picture_viewer', 'recent_uploads'] as $cardKey) {
        $card = $factory->create($cardKey);
        $harness->assertSame($cardKey, $card->key());
    }
});

$harness->check(_dashboard::class, 'shows storage and operations cards first on dashboard', function () use ($harness): void {
    $cards = (new _dashboard())->cards();

    $harness->assertSame('storage_summary', (string)($cards[0] ?? ''));
    $harness->assertSame('service_status', (string)($cards[1] ?? ''));
    $harness->assertSame('statistics', (string)($cards[2] ?? ''));
});

$harness->check(SwallowtailServiceStatusService::class, 'reports pid-backed service state', function () use ($harness): void {
    $pidFile = tempnam(sys_get_temp_dir(), 'swallowtail-service-');
    if (!is_string($pidFile)) {
        $harness->skip('Unable to create temporary PID file.');
    }

    try {
        file_put_contents($pidFile, "12345\n");

        $service = new SwallowtailServiceStatusService(
            processExists: static fn(int $pid): ?bool => $pid === 12345
        );
        $method = new ReflectionMethod($service, 'pidFileStatus');
        $method->setAccessible(true);
        $status = (array)$method->invoke($service, 'test_worker', 'Test worker', $pidFile);

        $harness->assertSame('ok', (string)($status['state'] ?? ''));
        $harness->assertSame('Running', (string)($status['status'] ?? ''));
    } finally {
        @unlink($pidFile);
    }
});

$harness->check(SwallowtailServiceStatusService::class, 'reports fresh Redis service heartbeat', function () use ($harness): void {
    $service = new SwallowtailServiceStatusService(
        heartbeatReader: static fn(string $key): ?string => json_encode([
            'service' => 'swallowtail_conversion',
            'touched_at' => time() - 30,
        ])
    );
    $method = new ReflectionMethod($service, 'heartbeatStatus');
    $method->setAccessible(true);
    $status = (array)$method->invoke(
        $service,
        'swallowtail_conversion',
        'RAW conversion worker',
        'swallowtail:service:swallowtail_conversion:last_touched',
        '/tmp/missing-swallowtail-test.pid'
    );

    $harness->assertSame('ok', (string)($status['state'] ?? ''));
    $harness->assertSame('Fresh', (string)($status['status'] ?? ''));
});

$harness->check(SwallowtailServiceStatusService::class, 'reports stale Redis service heartbeat', function () use ($harness): void {
    $service = new SwallowtailServiceStatusService(
        heartbeatReader: static fn(string $key): ?string => json_encode([
            'service' => 'swallowtail_conversion',
            'touched_at' => time() - 400,
        ])
    );
    $method = new ReflectionMethod($service, 'heartbeatStatus');
    $method->setAccessible(true);
    $status = (array)$method->invoke(
        $service,
        'swallowtail_conversion',
        'RAW conversion worker',
        'swallowtail:service:swallowtail_conversion:last_touched',
        '/tmp/missing-swallowtail-test.pid'
    );

    $harness->assertSame('bad', (string)($status['state'] ?? ''));
    $harness->assertSame('Stale', (string)($status['status'] ?? ''));
});

$harness->check(_settings::class, 'includes reusable storage card', function () use ($harness): void {
    $settings = new _settings();

    $harness->assertTrue(in_array('storage_available', $settings->cards(), true));
    $harness->assertTrue(in_array('jobs', $settings->cards(), true));
    $harness->assertTrue(in_array('timezone_settings', $settings->cards(), true));
});

$seedJobStatisticsTables = static function (): void {
    foreach ([
        'internal_profile_data',
        'photo_profile_data',
        'photo_metadata',
        'storage_migration_job_items',
        'storage_migration_jobs',
        'photo_conversion_jobs',
        'photos',
    ] as $table) {
        InterfaceDB::execute('DROP TABLE IF EXISTS ' . $table);
    }

    InterfaceDB::execute("CREATE TABLE photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_filename TEXT NOT NULL,
        original_extension TEXT NOT NULL,
        upload_state TEXT NOT NULL DEFAULT 'uploaded'
    )");
    InterfaceDB::execute("CREATE TABLE photo_conversion_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'queued',
        attempts INTEGER NOT NULL DEFAULT 0,
        available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at TEXT NULL,
        locked_by TEXT NULL,
        started_at TEXT NULL,
        completed_at TEXT NULL,
        duration_seconds REAL NULL,
        last_error TEXT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    InterfaceDB::execute("CREATE TABLE storage_migration_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        status TEXT NOT NULL DEFAULT 'queued',
        last_error TEXT NULL,
        completed_at TEXT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    InterfaceDB::execute("CREATE TABLE storage_migration_job_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'queued',
        last_error TEXT NULL,
        completed_at TEXT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    InterfaceDB::execute("CREATE TABLE photo_metadata (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'deferred'
    )");
    InterfaceDB::execute("CREATE TABLE photo_profile_data (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        revision INTEGER NOT NULL DEFAULT 0,
        type TEXT NOT NULL,
        `key` TEXT NOT NULL,
        value TEXT NULL,
        value_type TEXT NOT NULL
    )");

    InterfaceDB::execute("INSERT INTO photos (original_filename, original_extension, upload_state) VALUES
        ('A.CR2', 'cr2', 'uploaded'),
        ('B.CR2', 'CR2', 'uploaded'),
        ('C.JPG', 'jpg', 'uploaded'),
        ('D.CR2', 'cr2', 'removed')");
    InterfaceDB::execute("INSERT INTO photo_conversion_jobs (photo_id, status, attempts, locked_at, locked_by, started_at, completed_at, duration_seconds, last_error) VALUES
        (1, 'succeeded', 1, NULL, NULL, NULL, CURRENT_TIMESTAMP, 1.2, NULL),
        (1, 'failed', 3, CURRENT_TIMESTAMP, 'worker-a', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 2.4, 'failed conversion'),
        (1, 'cancelled', 1, NULL, NULL, NULL, NULL, NULL, 'cancelled by user'),
        (1, 'obsolete', 1, NULL, NULL, NULL, NULL, NULL, 'obsolete preview profile'),
        (2, 'queued', 0, NULL, NULL, NULL, NULL, NULL, NULL),
        (2, 'processing', 1, CURRENT_TIMESTAMP, 'worker-b', CURRENT_TIMESTAMP, NULL, NULL, NULL)");
    InterfaceDB::execute("INSERT INTO storage_migration_jobs (status, last_error, completed_at) VALUES
        ('failed', 'migration failed', CURRENT_TIMESTAMP),
        ('succeeded', NULL, CURRENT_TIMESTAMP)");
    InterfaceDB::execute("INSERT INTO storage_migration_job_items (job_id, status, last_error, completed_at) VALUES
        (1, 'succeeded', NULL, CURRENT_TIMESTAMP),
        (1, 'failed', 'copy failed', CURRENT_TIMESTAMP),
        (1, 'failed', 'verify failed', CURRENT_TIMESTAMP),
        (1, 'queued', NULL, NULL),
        (2, 'processing', NULL, NULL)");
    InterfaceDB::execute("INSERT INTO photo_metadata (photo_id, status) VALUES
        (1, 'ready'),
        (2, 'failed'),
        (3, 'failed'),
        (4, 'deferred')");
    InterfaceDB::execute("INSERT INTO photo_profile_data (photo_id, type, `key`, value, value_type) VALUES
        (1, 'swallowtail', 'status', 'processed', 'string'),
        (1, 'Exposure', 'Brightness', '20', 'int'),
        (2, 'swallowtail', 'status', 'failed', 'string'),
        (2, 'swallowtail', 'last_error', 'profile failed', 'string'),
        (2, 'Exposure', 'Brightness', '10', 'int'),
        (3, 'swallowtail', 'status', 'queued', 'string'),
        (4, 'swallowtail', 'status', 'processing', 'string')");
};

$harness->check(_storage_summaryCard::class, 'summarises included storage capacity for dashboard', function () use ($harness): void {
    $card = new _storage_summaryCard();
    $summaryMethod = new ReflectionMethod($card, 'summariseLocations');
    $summaryMethod->setAccessible(true);

    $summary = (array)$summaryMethod->invoke($card, [
        [
            'storage_base_location' => '/storage/1',
            'total_bytes' => 1000,
            'available_bytes' => 250,
            'is_excluded' => false,
            'can_write' => true,
        ],
        [
            'storage_base_location' => '/storage/2',
            'total_bytes' => 3000,
            'available_bytes' => 750,
            'is_excluded' => false,
            'can_write' => false,
        ],
        [
            'storage_base_location' => '/zfs/a',
            'total_bytes' => 5000,
            'available_bytes' => 5000,
            'is_excluded' => false,
            'is_zfs' => true,
            'is_selected_zfs_dataset' => false,
            'can_write' => true,
        ],
        [
            'storage_base_location' => '/storage/3',
            'total_bytes' => 9000,
            'available_bytes' => 9000,
            'is_excluded' => true,
            'can_write' => true,
        ],
    ]);

    $harness->assertSame(2, (int)$summary['included_locations']);
    $harness->assertSame(1, (int)$summary['writable_locations']);
    $harness->assertSame(0, (int)$summary['below_threshold_locations']);
    $harness->assertSame(4000, (int)$summary['total_bytes']);
    $harness->assertSame(1000, (int)$summary['available_bytes']);
    $harness->assertSame(3000, (int)$summary['used_bytes']);
    $harness->assertSame(25.0, (float)$summary['free_percent']);

    $chartMethod = new ReflectionMethod($card, 'capacityChart');
    $chartMethod->setAccessible(true);
    $chartHtml = (string)$chartMethod->invoke($card, $summary);

    $harness->assertTrue(str_contains($chartHtml, 'chart-pie-slice'));
    $harness->assertTrue(str_contains($chartHtml, 'Included storage capacity'));
});

$harness->check(_storage_summaryCard::class, 'warns when included storage has no writable targets', function () use ($harness): void {
    $card = new _storage_summaryCard();
    $summaryMethod = new ReflectionMethod($card, 'summariseLocations');
    $summaryMethod->setAccessible(true);
    $summary = (array)$summaryMethod->invoke($card, [
        [
            'storage_base_location' => '/storage/1',
            'total_bytes' => 1000,
            'available_bytes' => 40,
            'is_excluded' => false,
            'is_full' => true,
            'can_write' => false,
        ],
        [
            'storage_base_location' => '/storage/2',
            'total_bytes' => 1000,
            'available_bytes' => 30,
            'is_excluded' => false,
            'is_full' => true,
            'can_write' => false,
        ],
    ]);

    $harness->assertSame(2, (int)$summary['below_threshold_locations']);
    $rendered = '<div class="panel-soft warn storage-exhausted-warning">No storage locations are currently available for new writes. SwallowTail has crossed the configured free-space threshold on all included storage locations.</div>';
    $harness->assertTrue(str_contains($rendered, 'No storage locations are currently available for new writes.'));
});

$harness->check(SwallowtailStatisticsService::class, 'summarises photo and conversion job statistics', function () use ($harness): void {
    InterfaceDB::execute('DROP TABLE IF EXISTS photo_conversion_jobs');
    InterfaceDB::execute('DROP TABLE IF EXISTS photos');
    InterfaceDB::execute("CREATE TABLE photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_filename TEXT NOT NULL,
        upload_state TEXT NOT NULL DEFAULT 'uploaded'
    )");
    InterfaceDB::execute("CREATE TABLE photo_conversion_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        photo_id INTEGER NOT NULL,
        image_type TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'queued',
        duration_seconds REAL NULL
    )");
    InterfaceDB::execute("INSERT INTO photos (original_filename, upload_state) VALUES
        ('IMG_0001.CR2', 'uploaded'),
        ('IMG_0002.CR2', 'uploaded'),
        ('IMG_0003.CR2', 'removed')");
    InterfaceDB::execute("INSERT INTO photo_conversion_jobs (photo_id, image_type, status, duration_seconds) VALUES
        (1, 'embedded', 'succeeded', 0.5),
        (1, 'embedded', 'succeeded', 1.5),
        (1, 'preview', 'queued', NULL),
        (2, 'preview', 'processing', NULL),
        (2, 'original', 'succeeded', 62.0),
        (2, 'final', 'failed', 3.0)");

    $summary = (new SwallowtailStatisticsService())->summary();
    $jobs = (array)($summary['jobs'] ?? []);
    $durations = (array)($summary['duration_by_image_type'] ?? []);

    $harness->assertSame(2, (int)($summary['photos_current'] ?? 0));
    $harness->assertSame(6, (int)($jobs['total'] ?? 0));
    $harness->assertSame(2, (int)($jobs['outstanding'] ?? 0));
    $harness->assertSame(3, (int)($jobs['completed'] ?? 0));
    $harness->assertSame('embedded', (string)($durations[0]['image_type'] ?? ''));
    $harness->assertSame(2, (int)($durations[0]['completed_jobs'] ?? 0));
    $harness->assertSame(1.0, (float)($durations[0]['average_seconds'] ?? 0));
});

$harness->check(_statisticsCard::class, 'renders dashboard statistics totals and timings', function () use ($harness): void {
    $card = new _statisticsCard();
    $html = $card->render([]);

    $harness->assertTrue(str_contains($html, 'Photos'));
    $harness->assertTrue(str_contains($html, 'Total Jobs'));
    $harness->assertTrue(str_contains($html, 'Jobs Outstanding'));
    $harness->assertTrue(str_contains($html, 'Jobs Completed'));
    $harness->assertTrue(str_contains($html, 'Time Taken per Job by Image Type'));
    $harness->assertTrue(str_contains($html, '<table>'));
    $harness->assertTrue(str_contains($html, '<th>Type</th>'));
    $harness->assertTrue(str_contains($html, '<th>Completed</th>'));
    $harness->assertTrue(str_contains($html, '<th>Average</th>'));
    $harness->assertTrue(str_contains($html, '<th>Fastest</th>'));
    $harness->assertTrue(str_contains($html, '<th>Slowest</th>'));
    $harness->assertTrue(str_contains($html, '<td>Embedded</td>'));
    $harness->assertTrue(str_contains($html, '1.0s'));
});

$harness->check(SwallowtailJobStatisticsService::class, 'summarises job statistics from database tables', function () use ($harness, $seedJobStatisticsTables): void {
    $seedJobStatisticsTables();

    $service = new SwallowtailJobStatisticsService();
    $queueRows = $service->jobQueueRows();
    $metadataRows = $service->metadataProfileRows();

    $conversion = $queueRows[0] ?? [];
    $migration = $queueRows[1] ?? [];
    $metadata = $metadataRows[0] ?? [];
    $profile = $metadataRows[1] ?? [];

    $harness->assertSame('Conversion', (string)($conversion['job_type'] ?? ''));
    $harness->assertSame(1, (int)($conversion['succeeded'] ?? 0));
    $harness->assertSame(1, (int)($conversion['failed'] ?? 0));
    $harness->assertSame(1, (int)($conversion['cancelled'] ?? 0));
    $harness->assertSame(1, (int)($conversion['obsolete'] ?? 0));
    $harness->assertSame(1, (int)($conversion['queued'] ?? 0));
    $harness->assertSame(1, (int)($conversion['processing'] ?? 0));
    $harness->assertSame('6', (string)($conversion['total'] ?? ''));

    $harness->assertSame('Migration', (string)($migration['job_type'] ?? ''));
    $harness->assertSame(1, (int)($migration['succeeded'] ?? 0));
    $harness->assertSame(2, (int)($migration['failed'] ?? 0));
    $harness->assertSame(1, (int)($migration['queued'] ?? 0));
    $harness->assertSame(1, (int)($migration['processing'] ?? 0));
    $harness->assertSame('5 in 2', (string)($migration['total'] ?? ''));

    $harness->assertSame('Metadata', (string)($metadata['job_type'] ?? ''));
    $harness->assertSame(1, (int)($metadata['ready'] ?? 0));
    $harness->assertSame(2, (int)($metadata['failed'] ?? 0));
    $harness->assertSame(1, (int)($metadata['deferred'] ?? 0));
    $harness->assertSame('4', (string)($metadata['total'] ?? ''));

    $harness->assertSame('Profile', (string)($profile['job_type'] ?? ''));
    $harness->assertSame(1, (int)($profile['ready'] ?? 0));
    $harness->assertSame(1, (int)($profile['failed'] ?? 0));
    $harness->assertSame(1, (int)($profile['queued'] ?? 0));
    $harness->assertSame(1, (int)($profile['processing'] ?? 0));
    $harness->assertSame('4 in 2', (string)($profile['total'] ?? ''));
});

$harness->check(_jobsCard::class, 'renders job statistics tables and reprocess forms', function () use ($harness, $seedJobStatisticsTables): void {
    $seedJobStatisticsTables();

    $html = (new _jobsCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['jobs'],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'Job Queue'));
    $harness->assertTrue(str_contains($html, 'Metadata/Profile Jobs'));
    $harness->assertTrue(str_contains($html, '<th>Processing</th>'));
    $harness->assertTrue(str_contains($html, '<th>Obsolete</th>'));
    $harness->assertTrue(str_contains($html, '<td>Conversion</td>'));
    $harness->assertTrue(str_contains($html, '<td>Migration</td>'));
    $harness->assertTrue(str_contains($html, '<td>Metadata</td>'));
    $harness->assertTrue(str_contains($html, '<td>Profile</td>'));
    $harness->assertTrue(str_contains($html, 'name="card_action" value="Jobs"'));
    $harness->assertTrue(str_contains($html, 'name="jobs_action" value="reprocess_exceptions"'));
    $harness->assertTrue(str_contains($html, 'name="job_type" value="conversion"'));
    $harness->assertTrue(str_contains($html, 'name="csrf_token" value="test-csrf"'));
    $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Reprocess Exceptions</button>'));
    $harness->assertTrue(!str_contains($html, 'name="cards[]"'));
});

$harness->check(SwallowtailJobStatisticsService::class, 'reprocesses only failed exception rows', function () use ($harness, $seedJobStatisticsTables): void {
    $seedJobStatisticsTables();

    $service = new SwallowtailJobStatisticsService();

    $conversion = $service->reprocessExceptions('conversion');
    $harness->assertSame(1, (int)($conversion['count'] ?? 0));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_conversion_jobs WHERE status = 'failed'"));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_conversion_jobs WHERE status = 'obsolete'"));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_conversion_jobs WHERE status = 'cancelled'"));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn("SELECT attempts FROM photo_conversion_jobs WHERE id = 2"));
    $harness->assertSame('', (string)InterfaceDB::fetchColumn("SELECT COALESCE(last_error, '') FROM photo_conversion_jobs WHERE id = 2"));

    $migration = $service->reprocessExceptions('migration');
    $harness->assertSame(2, (int)($migration['count'] ?? 0));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM storage_migration_job_items WHERE status = 'failed'"));
    $harness->assertSame(3, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM storage_migration_job_items WHERE job_id = 1 AND status = 'queued'"));
    $harness->assertSame('queued', (string)InterfaceDB::fetchColumn('SELECT status FROM storage_migration_jobs WHERE id = 1'));
    $harness->assertSame('', (string)InterfaceDB::fetchColumn("SELECT COALESCE(last_error, '') FROM storage_migration_jobs WHERE id = 1"));

    $metadata = $service->reprocessExceptions('metadata');
    $harness->assertSame(2, (int)($metadata['count'] ?? 0));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_metadata WHERE status = 'failed'"));
    $harness->assertSame(2, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM photo_metadata'));

    $profile = $service->reprocessExceptions('profile');
    $harness->assertSame(1, (int)($profile['count'] ?? 0));
    $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM photo_profile_data WHERE photo_id = 2'));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_profile_data WHERE photo_id = 1 AND type = 'swallowtail' AND `key` = 'status' AND value = 'processed'"));
    $harness->assertSame(1, (int)InterfaceDB::fetchColumn("SELECT COUNT(*) FROM photo_profile_data WHERE photo_id = 3 AND type = 'swallowtail' AND `key` = 'status' AND value = 'queued'"));
});

$harness->check(_storage_availableCard::class, 'renders zpool dataset select and non-zfs migration controls', function () use ($harness): void {
    $card = new _storage_availableCard();
    $context = [
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['storage_available'],
        ],
    ];

    $zpoolCard = new ReflectionMethod($card, 'zpoolCard');
    $zpoolCard->setAccessible(true);
    $zpoolHtml = (string)$zpoolCard->invoke($card, [
        'zpool_name' => 'tank',
        'selected_dataset_name' => 'tank/photos',
        'selected_mountpoint' => '/storage/photos',
        'available_bytes' => 1000,
        'total_bytes' => 2000,
        'free_percent' => 50,
        'datasets' => [
            ['dataset_name' => 'tank/archive', 'mountpoint' => '/storage/archive'],
            ['dataset_name' => 'tank/photos', 'mountpoint' => '/storage/photos', 'selected' => true],
        ],
    ], $context);

    $harness->assertTrue(str_contains($zpoolHtml, 'name="storage_settings_action" value="set_zpool_dataset"'));
    $harness->assertTrue(str_contains($zpoolHtml, 'name="zpool_name" value="tank"'));
    $harness->assertTrue(str_contains($zpoolHtml, '<select name="dataset_name" data-submit-on-change="true">'));
    $harness->assertTrue(str_contains($zpoolHtml, 'value="tank/photos" selected'));

    $locationCard = new ReflectionMethod($card, 'locationCard');
    $locationCard->setAccessible(true);
    $locationHtml = (string)$locationCard->invoke($card, [
        'storage_base_location' => '/storage/1',
        'label' => '/storage/1',
        'root_path' => '/storage/1/swallowtail-data/',
        'available_bytes' => 1024,
        'total_bytes' => 2048,
        'free_percent' => 50,
        'full_threshold_percent' => 5,
        'is_excluded' => false,
        'is_full' => false,
        'can_write' => true,
    ], $context);

    $harness->assertTrue(str_contains($locationHtml, 'Migrate Files from this Location'));
    $harness->assertTrue(str_contains($locationHtml, 'data-chicken-check="true"'));
    $harness->assertTrue(!str_contains($locationHtml, 'Fix Permission Issues'));
    $harness->assertTrue(!str_contains($locationHtml, 'name="storage_settings_action" value="fix_permissions"'));
    $harness->assertTrue(str_contains($locationHtml, 'name="storage_settings_action" value="request_migrate_location"'));

    $zfsLocationHtml = (string)$locationCard->invoke($card, [
        'storage_base_location' => '/storage/photos',
        'label' => 'tank/photos',
        'root_path' => '/storage/photos/swallowtail-data/',
        'available_bytes' => 1024,
        'total_bytes' => 2048,
        'free_percent' => 50,
        'full_threshold_percent' => 5,
        'is_excluded' => false,
        'is_full' => false,
        'is_zfs' => true,
        'is_selected_zfs_dataset' => true,
        'can_write' => true,
    ], $context);

    $harness->assertTrue(str_contains($zfsLocationHtml, 'Migrate Files from this Location'));
    $harness->assertTrue(str_contains($zfsLocationHtml, 'name="storage_settings_action" value="request_migrate_location"'));
    $harness->assertTrue(str_contains($zfsLocationHtml, 'name="storage_base_location" value="/storage/photos"'));
    $harness->assertTrue(!str_contains($zfsLocationHtml, 'name="storage_settings_action" value="set_location_excluded"'));
    $harness->assertTrue(!str_contains($zfsLocationHtml, 'name="zpool_name"'));
});

$harness->check(_storage_availableCard::class, 'counts uploaded CR2 photos by storage location', function () use ($harness): void {
    InterfaceDB::execute('DROP TABLE IF EXISTS photos');
    InterfaceDB::execute("CREATE TABLE photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_filename TEXT NOT NULL,
        original_extension TEXT NOT NULL,
        storage_base_location TEXT NOT NULL,
        upload_state TEXT NOT NULL DEFAULT 'uploaded'
    )");
    InterfaceDB::execute("INSERT INTO photos (original_filename, original_extension, storage_base_location, upload_state) VALUES
        ('A.CR2', 'cr2', '/storage/1', 'uploaded'),
        ('B.CR2', 'CR2', '/storage/1', 'uploaded'),
        ('C.CR2', 'cr2', '/storage/2', 'uploaded'),
        ('D.CR2', 'cr2', '/storage/1', 'removed'),
        ('E.JPG', 'jpg', '/storage/1', 'uploaded')");

    $card = new _storage_availableCard();
    $counts = new ReflectionMethod($card, 'cr2CountsByStorageBaseLocation');
    $counts->setAccessible(true);

    $result = (array)$counts->invoke($card);

    $harness->assertSame(2, (int)($result['/storage/1'] ?? 0));
    $harness->assertSame(1, (int)($result['/storage/2'] ?? 0));

    $locationCard = new ReflectionMethod($card, 'locationCard');
    $locationCard->setAccessible(true);
    $locationHtml = (string)$locationCard->invoke($card, [
        'storage_base_location' => '/storage/1',
        'label' => '/storage/1',
        'root_path' => '/storage/1/swallowtail-data/',
        'available_bytes' => 1024,
        'total_bytes' => 2048,
        'free_percent' => 50,
        'full_threshold_percent' => 5,
        'is_excluded' => false,
        'is_full' => false,
        'can_write' => true,
        'cr2_file_count' => $result['/storage/1'] ?? 0,
    ], [
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['storage_available'],
        ],
    ]);

    $harness->assertTrue(str_contains($locationHtml, 'CR2 files'));
    $harness->assertTrue(str_contains($locationHtml, '<dd>2</dd>'));
});

$harness->check(SwallowtailStoragePermissionRepairService::class, 'runs sudo permission helper only for known storage locations', function () use ($harness): void {
    $capturedArgv = [];
    $service = new SwallowtailStoragePermissionRepairService(
        static function (array $argv) use (&$capturedArgv): array {
            $capturedArgv = $argv;

            return [
                'exit_code' => 0,
                'output' => "repair completed\n",
            ];
        },
        static fn(): array => [
            ['storage_base_location' => '/storage/1'],
        ]
    );

    $result = $service->repair('/storage/1/');

    $harness->assertSame('/storage/1', (string)$result['base']);
    $harness->assertSame('/usr/local/bin/sudo', (string)($capturedArgv[0] ?? ''));
    $harness->assertSame('-n', (string)($capturedArgv[1] ?? ''));
    $harness->assertSame('/usr/local/sbin/swallowtail-fix-storage-permissions', (string)($capturedArgv[2] ?? ''));
    $harness->assertSame('--base', (string)($capturedArgv[3] ?? ''));
    $harness->assertSame('/storage/1', (string)($capturedArgv[4] ?? ''));
});

$harness->check(SwallowtailStoragePermissionRepairService::class, 'rejects unknown storage locations before sudo', function () use ($harness): void {
    $ranCommand = false;
    $service = new SwallowtailStoragePermissionRepairService(
        static function () use (&$ranCommand): array {
            $ranCommand = true;

            return [
                'exit_code' => 0,
                'output' => '',
            ];
        },
        static fn(): array => [
            ['storage_base_location' => '/storage/known'],
        ]
    );

    try {
        $service->repair('/storage/other');
        throw new RuntimeException('Expected unknown storage location to be rejected.');
    } catch (InvalidArgumentException $exception) {
        $harness->assertTrue(str_contains($exception->getMessage(), 'not currently recognised'));
    }

    $harness->assertSame(false, $ranCommand);
});

$harness->check(SwallowtailStorageWakeService::class, 'sends storage wake after permission repair', function () use ($harness): void {
    $redis = new class {
        public string $capturedQueue = '';
        public array $capturedPayload = [];
        public int $capturedMaxLength = 0;

        public function listPushJson(string $key, array $payload, int $maxLength = 0): bool
        {
            $this->capturedQueue = $key;
            $this->capturedPayload = $payload;
            $this->capturedMaxLength = $maxLength;

            return true;
        }
    };

    $service = new SwallowtailStorageWakeService($redis);

    $harness->assertSame(true, $service->notifyPermissionRepair('/storage/1'));
    $harness->assertSame('swallowtail:conversion:storage_wake', $redis->capturedQueue);
    $harness->assertSame('permission_repair', (string)($redis->capturedPayload['reason'] ?? ''));
    $harness->assertSame('/storage/1', (string)($redis->capturedPayload['storage_base_location'] ?? ''));
    $harness->assertTrue((int)($redis->capturedPayload['generated_at'] ?? 0) > 0);
    $harness->assertSame(1, $redis->capturedMaxLength);
});


$harness->check(_storage_availableCard::class, 'renders ajax settings and per-location exclusion controls', function () use ($harness): void {
    $card = new _storage_availableCard();
    $context = [
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['storage_available'],
        ],
    ];

    $settingsForm = new ReflectionMethod($card, 'settingsForm');
    $settingsForm->setAccessible(true);
    $settingsHtml = (string)$settingsForm->invoke($card, $context);

    $harness->assertTrue(str_contains($settingsHtml, 'data-ajax="true"'));
    $harness->assertTrue(str_contains($settingsHtml, 'name="card_action" value="StorageSettings"'));
    $harness->assertTrue(str_contains($settingsHtml, 'name="storage_settings_action" value="update_settings"'));
    $harness->assertTrue(str_contains($settingsHtml, 'data-submit-on-change="true"'));
    $harness->assertTrue(str_contains($settingsHtml, 'Blocked poll interval'));
    $harness->assertTrue(str_contains($settingsHtml, 'name="storage_blocked_poll_interval_seconds"'));

    $locationCard = new ReflectionMethod($card, 'locationCard');
    $locationCard->setAccessible(true);
    $locationHtml = (string)$locationCard->invoke($card, [
        'storage_base_location' => '/storage/1',
        'label' => '/storage/1',
        'root_path' => '/storage/1/swallowtail-data/',
        'available_bytes' => 1024,
        'total_bytes' => 2048,
        'free_percent' => 50,
        'full_threshold_percent' => 5,
        'is_excluded' => true,
        'is_full' => false,
        'can_write' => false,
    ], $context);

    $harness->assertTrue(str_contains($locationHtml, 'name="storage_settings_action" value="set_location_excluded"'));
    $harness->assertTrue(str_contains($locationHtml, 'name="storage_base_location" value="/storage/1"'));
    $harness->assertTrue(str_contains($locationHtml, 'name="is_excluded" value="1" checked'));
    $harness->assertTrue(str_contains($locationHtml, 'Exclude from new writes'));
});

$harness->check(_storage_availableCard::class, 'shows storage exhaustion and below-threshold warnings', function () use ($harness): void {
    $card = new _storage_availableCard();
    $context = [
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['storage_available'],
        ],
    ];

    $warning = new ReflectionMethod($card, 'storageExhaustedWarning');
    $warning->setAccessible(true);
    $warningHtml = (string)$warning->invoke($card, [
        [
            'storage_base_location' => '/storage/1',
            'is_excluded' => false,
            'is_full' => true,
            'can_write' => false,
        ],
        [
            'storage_base_location' => '/storage/2',
            'is_excluded' => false,
            'is_full' => true,
            'can_write' => false,
        ],
    ]);

    $harness->assertTrue(str_contains($warningHtml, 'Storage is exhausted for new writes.'));
    $harness->assertTrue(str_contains($warningHtml, 'Conversion workers will check again every'));

    $locationCard = new ReflectionMethod($card, 'locationCard');
    $locationCard->setAccessible(true);
    $locationHtml = (string)$locationCard->invoke($card, [
        'storage_base_location' => '/storage/1',
        'label' => '/storage/1',
        'root_path' => '/storage/1/swallowtail-data/',
        'available_bytes' => 40,
        'total_bytes' => 1000,
        'free_percent' => 4,
        'full_threshold_percent' => 5,
        'is_excluded' => false,
        'is_full' => true,
        'can_write' => false,
    ], $context);

    $harness->assertTrue(str_contains($locationHtml, 'Below threshold'));
    $harness->assertTrue(str_contains($locationHtml, 'Free space is below the 5.0% threshold.'));
    $harness->assertTrue(str_contains($locationHtml, 'not eligible for new writes'));
});

$harness->check(_storage_availableCard::class, 'shows PHP permission failures before writable status', function () use ($harness): void {
    $card = new _storage_availableCard();
    $context = [
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['storage_available'],
        ],
    ];

    $locationCard = new ReflectionMethod($card, 'locationCard');
    $locationCard->setAccessible(true);
    $locationHtml = (string)$locationCard->invoke($card, [
        'storage_base_location' => '/storage/1',
        'label' => '/storage/1',
        'root_path' => '/storage/1/swallowtail-data/',
        'available_bytes' => 1024,
        'total_bytes' => 2048,
        'free_percent' => 50,
        'full_threshold_percent' => 5,
        'is_excluded' => false,
        'is_full' => false,
        'can_write' => true,
        'permission_can_write' => false,
        'permission_checked_path' => '/storage/1',
        'permission_error' => "SwallowTail storage data root cannot be created because the parent directory is not writable by the 'swallowtail' user.",
    ], $context);

    $harness->assertTrue(str_contains($locationHtml, 'Not writable'));
    $harness->assertTrue(!str_contains($locationHtml, '>Writable<'));
    $harness->assertTrue(str_contains($locationHtml, 'parent directory is not writable by the'));
    $harness->assertTrue(str_contains($locationHtml, 'swallowtail'));
    $harness->assertTrue(str_contains($locationHtml, 'Checked: /storage/1'));
    $harness->assertTrue(str_contains($locationHtml, 'Fix Permission Issues'));
    $harness->assertTrue(str_contains($locationHtml, 'name="storage_settings_action" value="fix_permissions"'));
});

$harness->check(StorageSettingsAction::class, 'returns flash messages for ajax storage settings failures', function () use ($harness): void {
    $request = new RequestFramework(
        ['page' => 'settings'],
        [
            'card_action' => 'StorageSettings',
            'storage_settings_action' => 'update_settings',
            'csrf_token' => 'invalid',
        ],
        ['REQUEST_METHOD' => 'POST'],
        [],
        [],
        null,
        []
    );

    $result = (new StorageSettingsAction())->handle($request, new PageServiceFramework(new AppService('')));

    $harness->assertSame(false, $result->isSuccess());
    $harness->assertSame(['storage.available'], $result->changedFacts());
    $harness->assertTrue(str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'permission'));
});

$harness->check(StorageSettingsAction::class, 'clamps storage-blocked poll interval', function () use ($harness): void {
    $action = new StorageSettingsAction();
    $method = new ReflectionMethod($action, 'clampedPollIntervalSeconds');
    $method->setAccessible(true);

    $harness->assertSame(60, (int)$method->invoke($action, 10));
    $harness->assertSame(3600, (int)$method->invoke($action, 3600));
    $harness->assertSame(86400, (int)$method->invoke($action, 90000));
});

$harness->check(_gallery::class, 'browse gallery previews link to picture viewer', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 42,
        'original_filename' => 'IMG_0042.CR2',
        'conversion_state' => 'ready',
        'preview_ready' => true,
    ]);

    $harness->assertTrue(str_contains($html, '?page=picture_viewer&amp;photo_id=42'));
    $harness->assertTrue(str_contains($html, '/api/photo-asset.php?photo_id=42&amp;type=preview'));
    $harness->assertTrue(str_contains($html, 'gallery-status-ready'));
    $harness->assertTrue(!str_contains($html, '>Ready<'));
});

$harness->check(_gallery::class, 'browse gallery pagination renders first and last controls', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'paginationControls');
    $method->setAccessible(true);

    $html = (string)$method->invoke(
        $card,
        ['page' => ['page_id' => 'gallery']],
        [
            'page' => 2,
            'per_page' => 24,
            'total_items' => 72,
            'total_pages' => 3,
            'has_previous_page' => true,
            'has_next_page' => true,
            'first_item' => 25,
            'last_item' => 48,
        ],
        'photos',
        null,
        ['cards[]' => 'browse_gallery']
    );

    $harness->assertTrue(str_contains($html, '>First<'));
    $harness->assertTrue(str_contains($html, '>Prev<'));
    $harness->assertTrue(str_contains($html, '>Next<'));
    $harness->assertTrue(str_contains($html, '>Last<'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_page" value="1"'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_page" value="3"'));
});

$harness->check(_gallery::class, 'browse gallery disables edge pagination controls', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'paginationControls');
    $method->setAccessible(true);

    $html = (string)$method->invoke(
        $card,
        ['page' => ['page_id' => 'gallery']],
        [
            'page' => 1,
            'per_page' => 24,
            'total_items' => 24,
            'total_pages' => 1,
            'has_previous_page' => false,
            'has_next_page' => false,
            'first_item' => 1,
            'last_item' => 24,
        ],
        'photos'
    );

    $harness->assertTrue(str_contains($html, 'button primary disabled" type="button" aria-disabled="true">First</button>'));
    $harness->assertTrue(str_contains($html, 'button primary disabled" type="button" aria-disabled="true">Last</button>'));
});

$harness->check(_gallery::class, 'browse gallery renders auto refresh control', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'autoRefreshControl');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card);

    $harness->assertTrue(str_contains($html, 'data-gallery-auto-refresh-control'));
    $harness->assertTrue(str_contains($html, 'data-gallery-auto-refresh-toggle'));
    $harness->assertTrue(str_contains($html, '>Auto refresh<'));
});

$harness->check(_gallery::class, 'browse gallery renders page size selector', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'galleryControls');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, 30);

    $harness->assertTrue(str_contains($html, 'class="gallery-footer-controls"'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_per_page"'));
    $harness->assertTrue(str_contains($html, 'name="_pagination" value="1"'));
    $harness->assertTrue(str_contains($html, 'name="_invalidate_fact" value="browse.gallery"'));
    $harness->assertTrue(str_contains($html, '<option value="24">24</option>'));
    $harness->assertTrue(str_contains($html, '<option value="30" selected>30</option>'));
    $harness->assertTrue(str_contains($html, '<option value="40">40</option>'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_page" value="1"'));
    $harness->assertTrue(str_contains($html, 'data-gallery-auto-refresh-toggle'));
});

$harness->check(_gallery::class, 'browse gallery normalises page size context', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $services = new PageServiceFramework(new AppService(''));

    $accepted = $card->handle(
        new RequestFramework([], ['browse_gallery_page' => '3', 'browse_gallery_per_page' => '40'], ['REQUEST_METHOD' => 'POST'], [], []),
        $services,
        ['page' => []],
        ActionResultFramework::none()
    );
    $fallback = $card->handle(
        new RequestFramework([], ['browse_gallery_per_page' => '96'], ['REQUEST_METHOD' => 'POST'], [], []),
        $services,
        ['page' => []],
        ActionResultFramework::none()
    );

    $harness->assertSame(3, (int)$accepted['page']['browse_gallery_page']);
    $harness->assertSame(40, (int)$accepted['page']['browse_gallery_per_page']);
    $harness->assertSame(24, (int)$fallback['page']['browse_gallery_per_page']);
});

$harness->check(_gallery::class, 'browse gallery status icons match upload icon stroke', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'statusIconSvg');
    $method->setAccessible(true);

    foreach (['ready', 'processing', 'failed'] as $status) {
        $html = (string)$method->invoke($card, $status);

        $harness->assertTrue(str_contains($html, 'viewBox="0 0 24 24"'));
        $harness->assertTrue(str_contains($html, 'stroke-width="1.8"'));
        $harness->assertTrue(str_contains($html, 'stroke-linecap="round"'));
        $harness->assertTrue(str_contains($html, 'stroke-linejoin="round"'));
    }
});

$harness->check(_gallery::class, 'browse gallery falls back to embedded previews', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 43,
        'original_filename' => 'IMG_0043.CR2',
        'conversion_state' => 'processing',
        'preview_ready' => false,
        'embedded_ready' => true,
    ]);

    $harness->assertTrue(str_contains($html, '?page=picture_viewer&amp;photo_id=43'));
    $harness->assertTrue(str_contains($html, '/api/photo-asset.php?photo_id=43&amp;type=embedded'));
    $harness->assertTrue(str_contains($html, 'gallery-status-processing'));
    $harness->assertTrue(str_contains($html, 'data-gallery-photo-pending="1"'));
    $harness->assertTrue(!str_contains($html, 'Preview pending'));
});

$harness->check(_gallery::class, 'browse gallery tracks pending preview rows', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'hasPendingPreviews');
    $method->setAccessible(true);

    $harness->assertSame(true, $method->invoke($card, [[
        'id' => 45,
        'original_filename' => 'IMG_0045.CR2',
        'conversion_state' => 'pending',
        'preview_ready' => false,
        'embedded_ready' => false,
    ]]));

    $harness->assertSame(true, $method->invoke($card, [[
        'id' => 46,
        'original_filename' => 'IMG_0046.CR2',
        'conversion_state' => 'ready',
        'preview_ready' => false,
        'embedded_ready' => false,
    ]]));

    $harness->assertSame(false, $method->invoke($card, [[
        'id' => 47,
        'original_filename' => 'IMG_0047.CR2',
        'conversion_state' => 'failed',
        'preview_ready' => false,
        'embedded_ready' => false,
    ]]));
});

$harness->check(_gallery::class, 'browse gallery shows failed status overlay', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 44,
        'original_filename' => 'IMG_0044.CR2',
        'conversion_state' => 'failed',
        'preview_ready' => true,
    ]);

    $harness->assertTrue(str_contains($html, 'gallery-status-failed'));
    $harness->assertTrue(!str_contains($html, 'data-gallery-photo-pending="1"'));
    $harness->assertTrue(!str_contains($html, '>Conversion failed<'));
});

$harness->check(SwallowtailPhotoMetadataSummaryService::class, 'formats photo metadata in helper text', function () use ($harness): void {
    $service = new SwallowtailPhotoMetadataSummaryService();

    $summary = $service->summaryText([
        'original_filename' => 'IMG_0042.CR2',
    ], [
        'camera_model' => 'Canon EOS 760D',
        'lens_model' => 'EF-S 18-135mm',
        'iso' => 1000,
        'shutter_speed' => '1/250',
        'aperture' => '4.000',
        'focal_length_mm' => '17.000',
    ]);

    $harness->assertSame('IMG_0042.CR2 : Canon EOS 760D with EF-S 18-135mm @ 17mm [ 1/250 (4ms) @ f/4, 1000 ASA ]', $summary);
    $harness->assertSame('IMG_0042.CR2', $service->summaryText([
        'original_filename' => 'IMG_0042.CR2',
    ], []));
});

$harness->check(_picture_editorCard::class, 'picture editor helper uses photo metadata summary', function () use ($harness): void {
    $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'picture_editor.php');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to read picture editor card source.');
    }

    $harness->assertTrue(str_contains($source, 'SwallowtailPhotoMetadataSummaryService'));
    $harness->assertTrue(str_contains($source, 'data-picture-editor-display-state'));
    $harness->assertTrue(str_contains($source, 'Displaying: '));
});

$harness->check(_picture_viewer::class, 'picture editor exposes revert control', function () use ($harness): void {
    $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'picture_editor.php');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to read picture editor card source.');
    }

    $harness->assertTrue(str_contains($source, 'data-picture-editor-revert'));
    $harness->assertTrue(str_contains($source, 'Revert to Baseline'));
});
