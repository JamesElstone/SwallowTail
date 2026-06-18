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

    foreach (['cr2_upload', 'storage_available', 'storage_summary', 'service_status', 'browse_gallery', 'picture_viewer', 'recent_uploads'] as $cardKey) {
        $card = $factory->create($cardKey);
        $harness->assertSame($cardKey, $card->key());
    }
});

$harness->check(_dashboard::class, 'shows storage summary first on dashboard', function () use ($harness): void {
    $cards = (new _dashboard())->cards();

    $harness->assertSame('storage_summary', (string)($cards[0] ?? ''));
    $harness->assertSame('service_status', (string)($cards[1] ?? ''));
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
    $harness->assertTrue(str_contains($locationHtml, 'name="storage_settings_action" value="request_migrate_location"'));
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
});
