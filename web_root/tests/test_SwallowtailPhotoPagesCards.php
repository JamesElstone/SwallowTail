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

    foreach (['cr2_upload', 'storage_available', 'timezone_settings', 'storage_summary', 'service_status', 'statistics', 'browse_gallery', 'picture_viewer', 'recent_uploads'] as $cardKey) {
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
    $harness->assertTrue(in_array('timezone_settings', $settings->cards(), true));
});

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
        (1, 'thumbnail', 'queued', NULL),
        (2, 'thumbnail', 'processing', NULL),
        (2, 'original', 'succeeded', 62.0),
        (2, 'filtered', 'failed', 3.0)");

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

$harness->check(_gallery::class, 'browse gallery thumbnails link to picture viewer', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 42,
        'original_filename' => 'IMG_0042.CR2',
        'conversion_state' => 'ready',
        'thumbnail_ready' => true,
    ]);

    $harness->assertTrue(str_contains($html, '?page=picture_viewer&amp;photo_id=42'));
    $harness->assertTrue(str_contains($html, '/api/photo-asset.php?photo_id=42&amp;type=thumbnail'));
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
        'thumbnail_ready' => false,
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
        'thumbnail_ready' => false,
        'embedded_ready' => false,
    ]]));

    $harness->assertSame(true, $method->invoke($card, [[
        'id' => 46,
        'original_filename' => 'IMG_0046.CR2',
        'conversion_state' => 'ready',
        'thumbnail_ready' => false,
        'embedded_ready' => false,
    ]]));

    $harness->assertSame(false, $method->invoke($card, [[
        'id' => 47,
        'original_filename' => 'IMG_0047.CR2',
        'conversion_state' => 'failed',
        'thumbnail_ready' => false,
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
        'thumbnail_ready' => true,
    ]);

    $harness->assertTrue(str_contains($html, 'gallery-status-failed'));
    $harness->assertTrue(!str_contains($html, 'data-gallery-photo-pending="1"'));
    $harness->assertTrue(!str_contains($html, '>Conversion failed<'));
});
