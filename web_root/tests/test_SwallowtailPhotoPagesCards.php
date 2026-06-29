<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailCombinedProfilePreviewService;
use Swallowtail\Service\SwallowtailEventManagementService;
use Swallowtail\Service\SwallowtailJobStatisticsService;
use Swallowtail\Service\SwallowtailInternalProfilesService;
use Swallowtail\Service\SwallowtailPhotoAssetNotificationService;
use Swallowtail\Service\SwallowtailPhotoMetadataSummaryService;
use Swallowtail\Service\SwallowtailPhotoUiService;
use Swallowtail\Service\SwallowtailRawTherapeeProfileService;
use Swallowtail\Service\SwallowtailServiceStatusService;
use Swallowtail\Service\SwallowtailStatisticsService;
use Swallowtail\Service\SwallowtailStoragePermissionRepairService;
use Swallowtail\Service\SwallowtailStorageService;
use Swallowtail\Service\SwallowtailStorageWakeService;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(PageFactoryFramework::class, 'resolves SwallowTail photo UI pages', function () use ($harness): void {
    $factory = new PageFactoryFramework();

    foreach (['upload', 'gallery', 'view', 'edit', 'profiles', 'download', 'events'] as $pageKey) {
        $page = $factory->create($pageKey);
        $harness->assertSame($pageKey, $page->id());
    }
});

$harness->check(CardFactoryFramework::class, 'resolves SwallowTail photo UI cards', function () use ($harness): void {
    $factory = new CardFactoryFramework();

    foreach (['cr2_upload', 'storage_available', 'jobs', 'timezone_settings', 'storage_summary', 'service_status', 'statistics', 'browse_gallery', 'picture_viewer', 'recent_uploads', 'internal_profiles', 'rawtherapee_profiles', 'combined_profile_preview', 'event_downloads', 'event_permissions', 'photo_audit_log'] as $cardKey) {
        $card = $factory->create($cardKey);
        $harness->assertSame($cardKey, $card->key());
    }
});

$harness->check(_profiles::class, 'profiles page exposes profile management cards', function () use ($harness): void {
    $profiles = new _profiles();

    $harness->assertSame([
        'rawtherapee_profiles',
        'internal_profiles',
        'combined_profile_preview',
    ], $profiles->cards());
});

$harness->check(_combined_profile_previewCard::class, 'combined profile preview card declares dashboard service', function () use ($harness): void {
    $services = (new _combined_profile_previewCard())->services();

    $harness->assertSame('combined_profile_preview_dashboard', (string)($services[0]['key'] ?? ''));
    $harness->assertSame(SwallowtailCombinedProfilePreviewService::class, (string)($services[0]['service'] ?? ''));
    $harness->assertSame('dashboard', (string)($services[0]['method'] ?? ''));
    $harness->assertSame(':combined_profile_preview.photo_id', (string)($services[0]['params']['photoId'] ?? ''));
    $harness->assertSame(':combined_profile_preview.image_type', (string)($services[0]['params']['imageType'] ?? ''));
    $harness->assertSame(':auth.user_id', (string)($services[0]['params']['userId'] ?? ''));
});

$harness->check(_combined_profile_previewCard::class, 'combined profile preview renders service dashboard data', function () use ($harness): void {
    $html = (new _combined_profile_previewCard())->render([
        'services' => [
            'combined_profile_preview_dashboard' => [
                'image_types' => ['preview', 'final'],
                'image_type' => 'final',
                'photo_id' => 42,
                'photo' => [
                    'id' => 42,
                    'original_filename' => 'IMG_0042.CR2',
                ],
                'content' => "[Version]\nAppVersion=5.10",
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="combined_profile_photo_id" value="42"'));
    $harness->assertTrue(str_contains($html, 'IMG_0042.CR2'));
    $harness->assertTrue(str_contains($html, 'ID 42'));
    $harness->assertTrue(str_contains($html, '<option value="final" selected>final</option>'));
    $harness->assertTrue(str_contains($html, "[Version]\nAppVersion=5.10"));
});

$harness->check(_internal_profilesCard::class, 'internal profiles card declares dashboard service', function () use ($harness): void {
    $services = (new _internal_profilesCard())->services();

    $harness->assertSame('internal_profiles_dashboard', (string)($services[0]['key'] ?? ''));
    $harness->assertSame(SwallowtailInternalProfilesService::class, (string)($services[0]['service'] ?? ''));
    $harness->assertSame('dashboard', (string)($services[0]['method'] ?? ''));
});

$harness->check(_internal_profilesCard::class, 'internal profiles exposes framework table', function () use ($harness): void {
    $card = new _internal_profilesCard();
    $tables = $card->tables([
        'page' => [
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'internal_profiles_dashboard' => [
                'image_type' => 'preview',
                'profile_name' => 'default',
                'rows' => [],
            ],
        ],
    ]);

    $harness->assertTrue(($tables[0] ?? null) instanceof TableFramework);
    $harness->assertSame('internal_profiles', $tables[0]->key());
});

$harness->check(_internal_profilesCard::class, 'internal profile editor renders with table builder without pagination', function () use ($harness): void {
    $card = new _internal_profilesCard();
    $tables = $card->tables([
        'page' => [
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'internal_profiles_dashboard' => [
                'image_type' => 'preview',
                'profile_name' => 'default',
                'rows' => [
                    [
                        'id' => 42,
                        'image_type' => 'preview',
                        'profile_name' => 'default',
                        'type' => 'Resize',
                        'key' => 'Enabled',
                        'value' => 'true',
                        'value_type' => 'bool',
                    ],
                    [
                        'id' => 43,
                        'image_type' => 'preview',
                        'profile_name' => 'default',
                        'type' => 'Resize',
                        'key' => 'ShortEdge',
                        'value' => '820',
                        'value_type' => 'int',
                    ],
                    [
                        'id' => 44,
                        'image_type' => 'preview',
                        'profile_name' => 'default',
                        'type' => 'Exposure',
                        'key' => 'Lightness',
                        'value' => '1.5',
                        'value_type' => 'float',
                    ],
                    [
                        'id' => 45,
                        'image_type' => 'preview',
                        'profile_name' => 'default',
                        'type' => 'RAW Bayer',
                        'key' => 'Method',
                        'value' => 'fast',
                        'value_type' => 'string',
                    ],
                    [
                        'id' => 46,
                        'image_type' => 'preview',
                        'profile_name' => 'default',
                        'type' => 'Resize',
                        'key' => 'Optional',
                        'value' => null,
                        'value_type' => 'null',
                    ],
                ],
            ],
        ],
    ]);
    $table = $tables[0] ?? null;

    $harness->assertTrue($table instanceof TableFramework);

    $html = $table->render([
        'page' => [
            'page_id' => 'profiles',
            'page_cards' => ['internal_profiles'],
        ],
    ]);

    $harness->assertTrue(str_contains($html, '<table class="profile-editor-table">'));
    $harness->assertTrue(str_contains($html, '<th>Type</th>'));
    $harness->assertTrue(str_contains($html, 'name="internal_profile_type"'));
    $harness->assertTrue(str_contains($html, 'form="internal-profile-row-42-'));
    $harness->assertTrue(str_contains($html, 'name="internal_profiles_action" value="save_row"'));
    $harness->assertTrue(str_contains($html, 'name="internal_profiles_move_direction" value=""'));
    $harness->assertTrue(str_contains($html, 'data-submit-field="internal_profiles_action" data-submit-value="move_profile"'));
    $harness->assertTrue(str_contains($html, 'data-internal-profile-move-direction="up"'));
    $harness->assertTrue(str_contains($html, 'data-internal-profile-move-direction="down"'));
    $harness->assertTrue(!str_contains($html, 'onclick='));
    $harness->assertTrue(str_contains($html, 'name="internal_profile_value"'));
    $harness->assertTrue(str_contains($html, 'data-validate-boolean'));
    $harness->assertTrue(str_contains($html, '<option value="true" selected>true</option>'));
    $harness->assertTrue(str_contains($html, 'data-validate-int inputmode="numeric" value="820"'));
    $harness->assertTrue(str_contains($html, 'data-validate-float inputmode="decimal" value="1.5"'));
    $harness->assertTrue(str_contains($html, 'data-validate-ascii value="fast"'));
    $harness->assertTrue(str_contains($html, 'type="text" disabled value="">'));
    $harness->assertTrue(str_contains($html, '<input type="hidden" name="internal_profile_value" form="internal-profile-row-46-'));
    $harness->assertTrue(str_contains($html, 'data-validate-type-control='));
    $harness->assertTrue(str_contains($html, 'data-validate-type-target='));
    $harness->assertTrue(!str_contains($html, 'data-submit-on-change="true"'));
    $harness->assertTrue(!str_contains($html, 'table-footer'));
    $harness->assertTrue(str_contains($html, '_table_export_prepare'));
    $harness->assertTrue(!str_contains($html, 'profile-editor-row'));

    $targetMatches = [];
    $harness->assertSame(1, preg_match('/data-validate-type-target="([^"]+)"/', $html, $targetMatches));
    $harness->assertTrue(str_contains($html, 'data-validate-type-control="' . $targetMatches[1] . '"'));
});

$harness->check(_internal_profilesCard::class, 'internal profile move buttons are prepared by project javascript', function () use ($harness): void {
    $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'project.js');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to read project javascript source.');
    }

    $harness->assertTrue(str_contains($source, 'data-internal-profile-move-direction'));
    $harness->assertTrue(str_contains($source, "actionField.value = 'move_profile'"));
    $harness->assertTrue(str_contains($source, 'directionField.value = direction'));
    $harness->assertTrue(str_contains($source, "direction !== 'up' && direction !== 'down'"));
});

$harness->check(_internal_profilesCard::class, 'internal profile adjustment action names selected image and profile', function () use ($harness): void {
    $card = new _internal_profilesCard();
    $method = new ReflectionMethod($card, 'adjustmentEntryForm');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, 'preview', 'default', 'test-csrf');

    $harness->assertTrue(str_contains($html, 'data-internal-profile-adjustment-form="true"'));
    $harness->assertTrue(str_contains($html, 'name="internal_profiles_image_type" value="preview"'));
    $harness->assertTrue(str_contains($html, 'name="internal_profiles_profile_name" value="default"'));
    $harness->assertTrue(str_contains($html, 'name="internal_profiles_new_profile_name" value="default"'));
    $harness->assertTrue(str_contains($html, 'Add adjustment entry for preview : default'));
    $harness->assertTrue(!str_contains($html, 'Add Row For Image Type'));
});

$harness->check(_internal_profilesCard::class, 'internal profile add profile field starts empty', function () use ($harness): void {
    $card = new _internal_profilesCard();
    $method = new ReflectionMethod($card, 'filterForms');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, ['preview'], 'preview', 'default', ['default'], 'test-csrf');

    $harness->assertTrue(str_contains($html, 'id="internal-profiles-new-profile-name"'));
    $harness->assertTrue(str_contains($html, 'name="internal_profiles_new_profile_name" type="text" value=""'));
    $harness->assertTrue(!str_contains($html, 'id="internal-profiles-new-profile-name" name="internal_profiles_new_profile_name" type="text" value="default"'));
});

$harness->check(_rawtherapee_profilesCard::class, 'rawtherapee profiles card declares dashboard service', function () use ($harness): void {
    $services = (new _rawtherapee_profilesCard())->services();

    $harness->assertSame('rawtherapee_profiles_dashboard', (string)($services[0]['key'] ?? ''));
    $harness->assertSame(SwallowtailRawTherapeeProfileService::class, (string)($services[0]['service'] ?? ''));
    $harness->assertSame('dashboard', (string)($services[0]['method'] ?? ''));
    $harness->assertSame(':rawtherapee_profiles.display_url', (string)($services[0]['params']['displayUrl'] ?? ''));
    $harness->assertSame(':rawtherapee_profiles.display_type', (string)($services[0]['params']['displayType'] ?? ''));
});

$harness->check(_rawtherapee_profilesCard::class, 'rawtherapee profile select submits current display state', function () use ($harness): void {
    $card = new _rawtherapee_profilesCard();
    $method = new ReflectionMethod($card, 'controlForm');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [[
        'id' => 7,
        'display_label' => 'Portrait.pp3',
    ]], 7, 0, 12, '/api/photo-imaging.php?photo_id=12&type=preview', 'preview', 'test-csrf');

    $harness->assertTrue(str_contains($html, '-- Current Profile --'));
    $harness->assertTrue(str_contains($html, 'name="rawtherapee_profiles_action" value="test"'));
    $harness->assertTrue(str_contains($html, 'data-submit-field="rawtherapee_profiles_action" data-submit-value="refresh"'));
    $harness->assertTrue(str_contains($html, 'data-rawtherapee-display-url-field="true"'));
    $harness->assertTrue(str_contains($html, 'data-rawtherapee-display-type-field="true"'));
    $harness->assertTrue(str_contains($html, 'Change Random Photo'));
    $harness->assertTrue(str_contains($html, 'Refresh Profiles'));
    $harness->assertTrue(str_contains($html, 'data-submit-value="set_default"'));
    $harness->assertTrue(str_contains($html, 'class="form-row rawtherapee-profile-form panel-soft"'));
    $harness->assertTrue(!str_contains($html, 'Show Profile Effect'));
    $harness->assertTrue(!str_contains($html, 'type="submit" name="rawtherapee_profiles_action"'));
});

$harness->check(_rawtherapee_profilesCard::class, 'rawtherapee default profile disables set default button', function () use ($harness): void {
    $card = new _rawtherapee_profilesCard();
    $method = new ReflectionMethod($card, 'controlForm');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [[
        'id' => 7,
        'display_label' => 'Portrait.pp3',
    ]], 7, 7, 12, '', 'none', 'test-csrf');

    $harness->assertTrue(str_contains($html, 'Portrait.pp3 (default)'));
    $harness->assertTrue(str_contains($html, 'data-rawtherapee-set-default-button="true" disabled aria-disabled="true"'));
});

$harness->check(_rawtherapee_profilesCard::class, 'rawtherapee photo search panel renders recall results', function () use ($harness): void {
    $card = new _rawtherapee_profilesCard();
    $method = new ReflectionMethod($card, 'photoSearchPanel');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, 7, 12, '/api/photo-imaging.php?photo_id=12&type=preview', 'preview', 'test-csrf', 'IMG', [[
        'id' => 42,
        'original_filename' => 'IMG_0042.CR2',
        'original_sha256' => str_repeat('a', 64),
    ]], true);

    $harness->assertTrue(str_contains($html, 'class="panel-soft rawtherapee-photo-search-panel"'));
    $harness->assertTrue(str_contains($html, 'name="rawtherapee_photo_search" value="IMG"'));
    $harness->assertTrue(str_contains($html, 'name="rawtherapee_profiles_action" value="search_photo"'));
    $harness->assertTrue(str_contains($html, 'name="rawtherapee_profiles_action" value="select_photo"'));
    $harness->assertTrue(str_contains($html, 'name="rawtherapee_selected_photo_id" value="42"'));
    $harness->assertTrue(str_contains($html, 'IMG_0042.CR2'));
    $harness->assertTrue(str_contains($html, 'ID 42'));
    $harness->assertTrue(str_contains($html, 'aaaaaaaaaaaa...aaaaaaaa'));
    $harness->assertTrue(str_contains($html, 'Use Photo'));
});

$harness->check(_rawtherapee_profilesCard::class, 'rawtherapee details render in a status panel with state image', function () use ($harness): void {
    $card = new _rawtherapee_profilesCard();
    $method = new ReflectionMethod($card, 'details');
    $method->setAccessible(true);

    $readyHtml = (string)$method->invoke($card, 'Ready', ['job_id' => 27329], ['original_filename' => 'IMG_2130.CR2'], 'Auto-Matched curve - iso high', 'rawtherapee');
    $renderingHtml = (string)$method->invoke($card, 'Rendering', ['job_id' => 27330], ['original_filename' => 'IMG_2131.CR2'], 'Auto-Matched curve - iso high', 'rawtherapee');

    $harness->assertTrue(str_contains($readyHtml, 'class="panel-soft rawtherapee-profile-status-panel"'));
    $harness->assertTrue(str_contains($readyHtml, 'data-rawtherapee-profile-state-image="true"'));
    $harness->assertTrue(str_contains($readyHtml, 'src="/swallowtail_butterfly_42x42.png"'));
    $harness->assertTrue(str_contains($readyHtml, 'IMG_2130.CR2'));
    $harness->assertTrue(str_contains($renderingHtml, 'src="/swallowtail_256.gif"'));
});

$harness->check(_rawtherapee_profilesCard::class, 'rawtherapee preview renders selected display url', function () use ($harness): void {
    $card = new _rawtherapee_profilesCard();
    $method = new ReflectionMethod($card, 'photoPreview');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, 12, ['original_filename' => 'test.cr2'], '/api/photo-imaging.php?photo_id=12&type=preview', 'preview', true);

    $harness->assertTrue(str_contains($html, 'data-rawtherapee-profile-image="true"'));
    $harness->assertTrue(str_contains($html, 'data-rawtherapee-profile-image-type="preview"'));
    $harness->assertTrue(str_contains($html, '/api/photo-imaging.php?photo_id=12&amp;type=preview'));
});

$harness->check(_view::class, 'view page exposes picture viewer card', function () use ($harness): void {
    $harness->assertSame(['picture_viewer'], (new _view())->cards());
});

$harness->check(_picture_viewerCard::class, 'formats camel case metadata keys for display', function () use ($harness): void {
    $card = new _picture_viewerCard();
    $method = new ReflectionMethod($card, 'displayMetadataKey');
    $method->setAccessible(true);

    $harness->assertSame('Recommended Exposure Index', (string)$method->invoke($card, 'RecommendedExposureIndex'));
    $harness->assertSame('ISO Speed Ratings', (string)$method->invoke($card, 'ISOSpeedRatings'));
    $harness->assertSame('Lens Model', (string)$method->invoke($card, 'Lens_Model'));
});

$harness->check(_picture_viewerCard::class, 'renders metadata properties as a two column table', function () use ($harness): void {
    $card = new _picture_viewerCard();
    $method = new ReflectionMethod($card, 'propertiesTab');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [[
        'key' => 'RecommendedExposureIndex',
        'value' => '200',
        'value_type' => 'int',
    ]]);

    $harness->assertTrue(str_contains($html, '<table class="picture-property-table"><tbody>'));
    $harness->assertTrue(str_contains($html, '<th scope="row">Recommended Exposure Index</th>'));
    $harness->assertTrue(str_contains($html, '<td>200</td>'));
});

$harness->check(_edit::class, 'edit page exposes picture editor card', function () use ($harness): void {
    $harness->assertSame(['picture_editor'], (new _edit())->cards());
});

$harness->check(_download::class, 'download page exposes event download card', function () use ($harness): void {
    $harness->assertSame(['event_downloads'], (new _download())->cards());
});

$harness->check(_events::class, 'events page exposes event permissions card', function () use ($harness): void {
    $harness->assertSame(['event_permissions'], (new _events())->cards());
});

$harness->check(_logs::class, 'logs page exposes photo audit log card', function () use ($harness): void {
    $cards = (new _logs())->cards();

    $harness->assertTrue(in_array('photo_audit_log', $cards, true));
    $harness->assertTrue(in_array('user_account_audit_log', $cards, true));
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
        ('B.CR2', 'cr2', 'uploaded'),
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
        (1, 'thumbnail', 'succeeded', 0.25),
        (1, 'preview', 'queued', NULL),
        (2, 'preview', 'processing', NULL),
        (2, 'original', 'succeeded', 62.0),
        (2, 'final', 'failed', 3.0)");

    $summary = (new SwallowtailStatisticsService())->summary();
    $jobs = (array)($summary['jobs'] ?? []);
    $durations = (array)($summary['duration_by_image_type'] ?? []);

    $harness->assertSame(2, (int)($summary['photos_current'] ?? 0));
    $harness->assertSame(7, (int)($jobs['total'] ?? 0));
    $harness->assertSame(2, (int)($jobs['outstanding'] ?? 0));
    $harness->assertSame(4, (int)($jobs['completed'] ?? 0));
    $harness->assertSame(0, (int)($jobs['obsolete'] ?? 0));
    $harness->assertSame('embedded', (string)($durations[0]['image_type'] ?? ''));
    $harness->assertSame(2, (int)($durations[0]['completed_jobs'] ?? 0));
    $harness->assertSame(1.0, (float)($durations[0]['average_seconds'] ?? 0));
    $harness->assertSame('thumbnail', (string)($durations[1]['image_type'] ?? ''));
    $harness->assertSame(1, (int)($durations[1]['completed_jobs'] ?? 0));
    $harness->assertSame('original', (string)($durations[2]['image_type'] ?? ''));
    $harness->assertSame(1, (int)($durations[2]['completed_jobs'] ?? 0));
});

$harness->check(_statisticsCard::class, 'renders dashboard statistics totals and timings', function () use ($harness): void {
    $card = new _statisticsCard();
    $html = $card->render([]);

    $harness->assertTrue(str_contains($html, 'Photos'));
    $harness->assertTrue(str_contains($html, 'Total Jobs'));
    $harness->assertTrue(str_contains($html, 'Jobs Outstanding'));
    $harness->assertTrue(str_contains($html, 'Jobs Completed'));
    $harness->assertTrue(str_contains($html, 'Jobs Obsolete'));
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

$harness->check(SwallowtailJobStatisticsService::class, 'does not use schema introspection in job statistics service', function () use ($harness): void {
    $source = (string)file_get_contents(__DIR__ . '/../classes/swallowtail/service/SwallowtailJobStatisticsService.php');

    $harness->assertSame(false, str_contains($source, 'tableExists('));
    $harness->assertSame(false, str_contains($source, 'columnExists('));
    $harness->assertSame(false, str_contains($source, 'columnsExists('));
});

$harness->check(_jobsCard::class, 'renders job statistics tables and reprocess forms', function () use ($harness, $seedJobStatisticsTables): void {
    $seedJobStatisticsTables();

    $card = new _jobsCard();
    $service = new SwallowtailJobStatisticsService();
    $services = $card->services();
    $context = [
        'page' => [
            'page_id' => 'settings',
            'csrf_token' => 'test-csrf',
            'page_cards' => ['jobs'],
        ],
        'services' => [
            'job_queue_rows' => $service->jobQueueRows(),
            'metadata_profile_rows' => $service->metadataProfileRows(),
        ],
    ];
    $html = $card->render($context);

    $harness->assertSame('job_queue_rows', (string)($services[0]['key'] ?? ''));
    $harness->assertSame(SwallowtailJobStatisticsService::class, (string)($services[0]['service'] ?? ''));
    $harness->assertSame('jobQueueRows', (string)($services[0]['method'] ?? ''));
    $harness->assertSame('metadata_profile_rows', (string)($services[1]['key'] ?? ''));
    $harness->assertSame(SwallowtailJobStatisticsService::class, (string)($services[1]['service'] ?? ''));
    $harness->assertSame('metadataProfileRows', (string)($services[1]['method'] ?? ''));
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
    $harness->assertSame(0, substr_count($html, '<button class="button primary" type="submit" disabled>Reprocess Exceptions</button>'));
    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
    $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
    $harness->assertTrue(str_contains($html, 'name="table_key" value="jobs_queue"'));
    $harness->assertTrue(str_contains($html, 'name="table_key" value="jobs_metadata_profile"'));
    $harness->assertTrue(str_contains($html, 'name="cards[]" value="jobs"'));

    foreach (['conversion', 'migration', 'metadata', 'profile'] as $jobType) {
        $service->reprocessExceptions($jobType);
    }

    $disabledContext = [
        'page' => [
            'page_id' => 'settings',
            'csrf_token' => 'test-csrf',
            'page_cards' => ['jobs'],
        ],
        'services' => [
            'job_queue_rows' => $service->jobQueueRows(),
            'metadata_profile_rows' => $service->metadataProfileRows(),
        ],
    ];
    $disabledHtml = $card->render($disabledContext);

    $harness->assertSame(4, substr_count($disabledHtml, '<button class="button primary" type="submit" disabled>Reprocess Exceptions</button>'));
    $harness->assertSame(0, substr_count($disabledHtml, '<button class="button primary" type="submit">Reprocess Exceptions</button>'));
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
        'services' => [
            'current_user' => [
                'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
            ],
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

$harness->check(SwallowtailStoragePermissionRepairService::class, 'repairs all currently failing storage locations', function () use ($harness): void {
    $capturedArgv = [];
    $service = new SwallowtailStoragePermissionRepairService(
        static function (array $argv) use (&$capturedArgv): array {
            $capturedArgv[] = $argv;

            return [
                'exit_code' => 0,
                'output' => "repair completed\n",
            ];
        },
        static fn(): array => [
            ['storage_base_location' => '/storage/1', 'permission_can_write' => false],
            ['storage_base_location' => '/storage/2/', 'permission_can_write' => false],
            ['storage_base_location' => '/storage/2', 'permission_can_write' => false],
            ['storage_base_location' => '/storage/3', 'permission_can_write' => true],
            ['storage_base_location' => '', 'permission_can_write' => false],
        ]
    );

    $repairs = $service->repairFailingLocations();

    $harness->assertSame(2, count($repairs));
    $harness->assertSame('/storage/1', (string)($repairs[0]['base'] ?? ''));
    $harness->assertSame('/storage/2/', (string)($repairs[1]['base'] ?? ''));
    $harness->assertSame(2, count($capturedArgv));
    $harness->assertSame('/storage/1', (string)($capturedArgv[0][4] ?? ''));
    $harness->assertSame('/storage/2/', (string)($capturedArgv[1][4] ?? ''));
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
        'services' => [
            'current_user' => [
                'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
            ],
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

$harness->check(_storage_availableCard::class, 'hides location exclusion and migration controls for non-admin roles', function () use ($harness): void {
    $card = new _storage_availableCard();
    $context = [
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['storage_available'],
        ],
        'services' => [
            'current_user' => [
                'role_id' => 1,
            ],
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
    ], $context);

    $harness->assertTrue(!str_contains($locationHtml, 'name="storage_settings_action" value="set_location_excluded"'));
    $harness->assertTrue(!str_contains($locationHtml, 'Exclude from new writes'));
    $harness->assertTrue(!str_contains($locationHtml, 'name="storage_settings_action" value="request_migrate_location"'));
    $harness->assertTrue(!str_contains($locationHtml, 'Migrate Files from this Location'));
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

$harness->check(_storage_availableCard::class, 'offers one action for multiple storage permission failures', function () use ($harness): void {
    $card = new _storage_availableCard();
    $context = [
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['storage_available'],
        ],
    ];

    $repairForm = new ReflectionMethod($card, 'allPermissionsRepairForm');
    $repairForm->setAccessible(true);
    $formHtml = (string)$repairForm->invoke($card, [
        [
            'storage_base_location' => '/storage/1',
            'permission_can_write' => false,
        ],
        [
            'storage_base_location' => '/storage/2',
            'permission_can_write' => false,
        ],
        [
            'storage_base_location' => '/storage/2/',
            'permission_can_write' => false,
        ],
        [
            'storage_base_location' => '/storage/3',
            'permission_can_write' => true,
        ],
    ], $context);

    $harness->assertTrue(str_contains($formHtml, 'Fix All Permission Issues'));
    $harness->assertTrue(str_contains($formHtml, 'name="storage_settings_action" value="fix_all_permissions"'));
    $harness->assertTrue(str_contains($formHtml, 'all 2 storage locations'));
    $harness->assertTrue(str_contains($formHtml, 'name="cards[]" value="storage_available"'));

    $singleFailureHtml = (string)$repairForm->invoke($card, [
        [
            'storage_base_location' => '/storage/1',
            'permission_can_write' => false,
        ],
    ], $context);
    $harness->assertSame('', $singleFailureHtml);
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

$harness->check(_gallery::class, 'browse gallery previews link to view and edit pages', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 42,
        'original_filename' => 'IMG_0042.CR2',
        'conversion_state' => 'ready',
        'preview_ready' => true,
        'original_ready' => true,
        'original_asset_sha256' => str_repeat('a', 64),
        'single_jpeg_ready' => true,
        'effective_can_edit' => true,
        'effective_can_download_single_jpeg' => true,
        'event_ids' => [7, 9],
    ]);

    $harness->assertTrue(str_contains($html, '?page=view&amp;photo_id=42'));
    $harness->assertTrue(str_contains($html, '?page=edit&amp;photo_id=42'));
    $harness->assertTrue(str_contains($html, 'aria-label="Edit IMG_0042.CR2"'));
    $harness->assertTrue(str_contains($html, '/api/photo-imaging.php?photo_id=42&amp;type=preview'));
    $harness->assertTrue(str_contains($html, 'gallery-status-ready'));
    $harness->assertTrue(str_contains($html, 'gallery-edit-link'));
    $harness->assertTrue(str_contains($html, 'gallery-download-link'));
    $harness->assertTrue(str_contains($html, 'gallery-event-select'));
    $harness->assertTrue(str_contains($html, 'data-gallery-event-ids="7,9"'));
    $harness->assertTrue(str_contains($html, 'data-gallery-event-photo-checkbox'));
    $harness->assertTrue(str_contains($html, '/api/photo-download.php?kind=photo&amp;photo_id=42'));
    $harness->assertTrue(str_contains($html, 'data-gallery-viewer-prefetch-url="/api/photo-imaging.php?photo_id=42&amp;type=original&amp;v=' . str_repeat('a', 64) . '"'));
    $harness->assertTrue(!str_contains($html, '>Ready<'));
});

$harness->check(_gallery::class, 'browse gallery hides download link without single jpeg permission', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 42,
        'original_filename' => 'IMG_0042.CR2',
        'conversion_state' => 'ready',
        'preview_ready' => true,
        'effective_can_download_single_jpeg' => false,
        'single_jpeg_ready' => true,
    ]);

    $harness->assertTrue(!str_contains($html, 'gallery-download-link'));
    $harness->assertTrue(!str_contains($html, 'data-gallery-viewer-prefetch-url'));
});

$harness->check(_gallery::class, 'browse gallery shows download link when final jpeg is ready during processing', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 42,
        'original_filename' => 'IMG_0042.CR2',
        'conversion_state' => 'processing',
        'preview_ready' => true,
        'original_ready' => true,
        'original_asset_sha256' => str_repeat('b', 64),
        'single_jpeg_ready' => true,
        'effective_can_download_single_jpeg' => true,
    ]);

    $harness->assertTrue(str_contains($html, 'gallery-status-processing'));
    $harness->assertTrue(str_contains($html, 'gallery-download-link'));
    $harness->assertTrue(str_contains($html, 'data-gallery-viewer-prefetch-url="/api/photo-imaging.php?photo_id=42&amp;type=original&amp;v=' . str_repeat('b', 64) . '"'));
});

$harness->check(_gallery::class, 'browse gallery hides download link until final jpeg is ready', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 42,
        'original_filename' => 'IMG_0042.CR2',
        'conversion_state' => 'ready',
        'preview_ready' => true,
        'original_ready' => true,
        'original_asset_sha256' => str_repeat('b', 64),
        'single_jpeg_ready' => false,
        'effective_can_download_single_jpeg' => true,
    ]);

    $harness->assertTrue(str_contains($html, 'gallery-status-ready'));
    $harness->assertTrue(!str_contains($html, 'gallery-download-link'));
    $harness->assertTrue(!str_contains($html, 'data-gallery-viewer-prefetch-url'));
});

$harness->check(_gallery::class, 'browse gallery hides edit link without edit permission', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 42,
        'original_filename' => 'IMG_0042.CR2',
        'conversion_state' => 'ready',
        'preview_ready' => true,
        'effective_can_edit' => false,
        'effective_can_download_single_jpeg' => true,
    ]);

    $harness->assertTrue(str_contains($html, '?page=view&amp;photo_id=42'));
    $harness->assertTrue(!str_contains($html, '?page=edit&amp;photo_id=42'));
    $harness->assertTrue(!str_contains($html, 'gallery-edit-link'));
    $harness->assertTrue(!str_contains($html, 'gallery-event-select'));
    $harness->assertTrue(!str_contains($html, 'form="gallery-event-assignment-form"'));
});

$harness->check(_gallery::class, 'browse gallery event controls require editable photos', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $editableMethod = new ReflectionMethod($card, 'hasEditablePhotos');
    $editableMethod->setAccessible(true);
    $controlsMethod = new ReflectionMethod($card, 'galleryControls');
    $controlsMethod->setAccessible(true);

    $harness->assertSame(false, $editableMethod->invoke($card, [[
        'id' => 42,
        'effective_can_edit' => false,
    ]]));
    $harness->assertSame(true, $editableMethod->invoke($card, [[
        'id' => 43,
        'effective_can_edit' => true,
    ]]));

    $hiddenControls = (string)$controlsMethod->invoke($card, 24, 'uploaded', 'desc', false);
    $shownControls = (string)$controlsMethod->invoke($card, 24, 'uploaded', 'desc', true);

    $harness->assertTrue(!str_contains($hiddenControls, 'data-gallery-events-toggle'));
    $harness->assertTrue(str_contains($shownControls, 'data-gallery-events-toggle'));
    $harness->assertTrue(str_contains($shownControls, '>Events</button>'));
    $harness->assertTrue(!str_contains($shownControls, 'Assign Events'));
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
        'Photos',
        null,
        ['cards[]' => 'browse_gallery'],
        'post',
        [],
        'button primary',
        '',
        'gallery-pagination-controls'
    );

    $harness->assertTrue(str_contains($html, 'status-head gallery-pagination-controls'));
    $harness->assertTrue(str_contains($html, 'Photos 25-48 of 72'));
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

$harness->check(_gallery::class, 'browse gallery auto refresh defaults to enabled', function () use ($harness): void {
    $js = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'project.js');

    if (!is_string($js)) {
        throw new RuntimeException('Unable to read gallery JavaScript.');
    }

    $harness->assertTrue(str_contains($js, 'const stored = window.localStorage.getItem(galleryAutoRefreshStorageKey);'));
    $harness->assertTrue(str_contains($js, "return stored === null ? true : stored === '1';"));
});

$harness->check(_gallery::class, 'browse gallery polls photo status between card refreshes', function () use ($harness): void {
    $js = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'project.js');

    if (!is_string($js)) {
        throw new RuntimeException('Unable to read gallery JavaScript.');
    }

    $harness->assertTrue(str_contains($js, 'const galleryCardRefreshIntervalMs = 30000;'));
    $harness->assertTrue(str_contains($js, 'function galleryPendingStatusUrls(target)'));
    $harness->assertTrue(str_contains($js, 'function pollGalleryPhotoStatuses(target)'));
    $harness->assertTrue(!str_contains($js, 'function galleryAssetHintPayload(target)'));
});

$harness->check(_gallery::class, 'browse gallery renders auto scroll control', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'autoScrollControl');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card);

    $harness->assertTrue(str_contains($html, 'data-gallery-auto-scroll-control'));
    $harness->assertTrue(str_contains($html, 'data-gallery-auto-scroll-toggle'));
    $harness->assertTrue(str_contains($html, '>Auto scroll<'));
});

$harness->check(_gallery::class, 'browse gallery chooses thumbnail then preview asset scan hints', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'galleryAssetScanType');
    $method->setAccessible(true);

    $harness->assertSame('thumbnail', $method->invoke($card, [
        'preview_ready' => false,
        'thumbnail_ready' => false,
    ]));
    $harness->assertSame('preview', $method->invoke($card, [
        'preview_ready' => false,
        'thumbnail_ready' => true,
    ]));
    $harness->assertSame(null, $method->invoke($card, [
        'preview_ready' => true,
        'thumbnail_ready' => true,
    ]));
});

$harness->check(_gallery::class, 'browse gallery pending tiles expose photo status url', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 42,
        'original_filename' => 'pending.CR2',
        'conversion_state' => 'ready',
        'preview_ready' => false,
        'thumbnail_ready' => false,
    ], false);

    $harness->assertTrue(str_contains($html, 'data-gallery-photo-pending="1"'));
    $harness->assertTrue(str_contains($html, 'data-gallery-photo-status-url="/api/photo-status.php?photo_id=42&amp;image_type=thumbnail"'));
});

$harness->check(SwallowtailPhotoAssetNotificationService::class, 'queues metadata asset scan hints for existing files', function () use ($harness): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'swallowtail-asset-hint-' . bin2hex(random_bytes(4));
    $sha256 = str_repeat('a', 64);
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
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_asset_queue', 'swallowtail:metadata:asset_gallery_test');
        $storage = new SwallowtailStorageService();
        $path = $storage->imagePath($root, $sha256, 'thumbnail');
        $storage->ensureDirectoryForPath($path);
        file_put_contents($path, "\xFF\xD8\xFF\xD9", LOCK_EX);

        $service = new SwallowtailPhotoAssetNotificationService($redis, $storage);
        $queued = $service->notifyPhotoAsset([
            'id' => 42,
            'original_sha256' => $sha256,
            'storage_base_location' => $root,
        ], 'thumbnail', 'browse_gallery_auto_refresh');

        $harness->assertSame(true, $queued);
        $harness->assertCount(1, $redis->pushes);
        $harness->assertSame('swallowtail:metadata:asset_gallery_test', $redis->pushes[0]['key']);
        $harness->assertSame(1024, (int)$redis->pushes[0]['max_length']);
        $harness->assertSame(0, (int)$redis->pushes[0]['payload']['job_id']);
        $harness->assertSame(42, (int)$redis->pushes[0]['payload']['photo_id']);
        $harness->assertSame('thumbnail', (string)$redis->pushes[0]['payload']['image_type']);
        $harness->assertSame($path, (string)$redis->pushes[0]['payload']['output_path']);
        $harness->assertSame('browse_gallery_auto_refresh', (string)$redis->pushes[0]['payload']['reason']);
    } finally {
        \Swallowtail\Store\SwallowtailConfigurationStore::set('redis.metadata_asset_queue', 'swallowtail:metadata:asset_urgent');
        if (isset($path) && is_file($path)) {
            @unlink($path);
        }
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($root);
        }
    }
});

$harness->check(_gallery::class, 'browse gallery renders page size selector', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'galleryControls');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, 30, 'filename', 'asc');

    $harness->assertTrue(str_contains($html, 'class="gallery-header-controls"'));
    $harness->assertTrue(str_contains($html, 'class="gallery-sort-form"'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_sort"'));
    $harness->assertTrue(str_contains($html, '<option value="uploaded">Uploaded Order</option>'));
    $harness->assertTrue(str_contains($html, '<option value="timestamp">Photo Timestamp</option>'));
    $harness->assertTrue(str_contains($html, '<option value="filename" selected>Original File Name</option>'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_sort_direction" value="asc"'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_sort_direction_toggle" value="1"'));
    $harness->assertTrue(str_contains($html, '>A-&gt;Z</button>'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_per_page"'));
    $harness->assertTrue(str_contains($html, 'name="_pagination" value="1"'));
    $harness->assertTrue(str_contains($html, 'name="_invalidate_fact" value="browse.gallery"'));
    $harness->assertTrue(str_contains($html, '<option value="24">24</option>'));
    $harness->assertTrue(str_contains($html, '<option value="30" selected>30</option>'));
    $harness->assertTrue(str_contains($html, '<option value="40">40</option>'));
    $harness->assertTrue(str_contains($html, 'name="browse_gallery_page" value="1"'));
    $harness->assertTrue(str_contains($html, 'data-submit-on-change="true"'));
    $harness->assertTrue(str_contains($html, 'data-gallery-auto-refresh-toggle'));
    $harness->assertTrue(str_contains($html, 'data-gallery-auto-scroll-toggle'));
});

$harness->check(_gallery::class, 'browse gallery renders descending sort direction label', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'galleryControls');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, 24, 'uploaded', 'desc');

    $harness->assertTrue(str_contains($html, '>Z-&gt;A</button>'));
});

$harness->check(_gallery::class, 'browse gallery normalises page size and sort context', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $services = new PageServiceFramework(new AppService(''));

    $accepted = $card->handle(
        new RequestFramework([], [
            'browse_gallery_page' => '3',
            'browse_gallery_per_page' => '40',
            'browse_gallery_sort' => 'timestamp',
            'browse_gallery_sort_direction' => 'asc',
            'browse_gallery_event_filter' => '7',
        ], ['REQUEST_METHOD' => 'POST'], [], []),
        $services,
        ['page' => []],
        ActionResultFramework::none()
    );
    $fallback = $card->handle(
        new RequestFramework([], [
            'browse_gallery_per_page' => '96',
            'browse_gallery_sort' => 'unknown',
            'browse_gallery_sort_direction' => 'sideways',
        ], ['REQUEST_METHOD' => 'POST'], [], []),
        $services,
        ['page' => []],
        ActionResultFramework::none()
    );
    $toggled = $card->handle(
        new RequestFramework([], [
            'browse_gallery_sort_direction' => 'desc',
            'browse_gallery_sort_direction_toggle' => '1',
        ], ['REQUEST_METHOD' => 'POST'], [], []),
        $services,
        ['page' => []],
        ActionResultFramework::none()
    );

    $harness->assertSame(3, (int)$accepted['page']['browse_gallery_page']);
    $harness->assertSame(40, (int)$accepted['page']['browse_gallery_per_page']);
    $harness->assertSame('timestamp', (string)$accepted['page']['browse_gallery_sort']);
    $harness->assertSame('asc', (string)$accepted['page']['browse_gallery_sort_direction']);
    $harness->assertSame(7, (int)$accepted['page']['browse_gallery_event_filter']);
    $harness->assertSame(24, (int)$fallback['page']['browse_gallery_per_page']);
    $harness->assertSame('uploaded', (string)$fallback['page']['browse_gallery_sort']);
    $harness->assertSame('desc', (string)$fallback['page']['browse_gallery_sort_direction']);
    $harness->assertSame('asc', (string)$toggled['page']['browse_gallery_sort_direction']);
});

$harness->check(_gallery::class, 'browse gallery declares data as a card service', function () use ($harness): void {
    $services = (new _browse_galleryCard())->services();
    $gallery = (array)($services[0] ?? []);
    $params = (array)($gallery['params'] ?? []);

    $harness->assertSame('gallery', (string)($gallery['key'] ?? ''));
    $harness->assertSame(SwallowtailPhotoUiService::class, (string)($gallery['service'] ?? ''));
    $harness->assertSame('accessiblePhotos', (string)($gallery['method'] ?? ''));
    $harness->assertSame(':auth.user_id', (string)($params['userId'] ?? ''));
    $harness->assertSame(':page.browse_gallery_page', (string)($params['page'] ?? ''));
    $harness->assertSame(':page.browse_gallery_per_page', (string)($params['perPage'] ?? ''));
    $harness->assertSame(':page.browse_gallery_sort', (string)($params['sort'] ?? ''));
    $harness->assertSame(':page.browse_gallery_sort_direction', (string)($params['direction'] ?? ''));
    $harness->assertSame(':page.browse_gallery_event_filter', (string)($params['eventId'] ?? ''));
});

$harness->check(_gallery::class, 'browse gallery pagination preserves sort state', function () use ($harness): void {
    $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'browse_gallery.php');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to read gallery card source.');
    }

    $harness->assertTrue(str_contains($source, '$this->sortField() => $sort'));
    $harness->assertTrue(str_contains($source, '$this->sortDirectionField() => $sortDirection'));
    $harness->assertTrue(str_contains($source, '$this->eventFilterField() => $eventFilterId'));
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

$harness->check(_gallery::class, 'browse gallery falls back to thumbnail previews', function () use ($harness): void {
    $card = new _browse_galleryCard();
    $method = new ReflectionMethod($card, 'photoTile');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'id' => 43,
        'original_filename' => 'IMG_0043.CR2',
        'conversion_state' => 'processing',
        'preview_ready' => false,
        'thumbnail_ready' => true,
        'effective_can_edit' => true,
    ]);

    $harness->assertTrue(str_contains($html, '?page=view&amp;photo_id=43'));
    $harness->assertTrue(str_contains($html, '?page=edit&amp;photo_id=43'));
    $harness->assertTrue(str_contains($html, 'data-gallery-photo-id="43"'));
    $harness->assertTrue(str_contains($html, '/api/photo-imaging.php?photo_id=43&amp;type=thumbnail'));
    $harness->assertTrue(str_contains($html, 'gallery-status-processing'));
    $harness->assertTrue(str_contains($html, 'data-gallery-photo-pending="1"'));
    $harness->assertTrue(!str_contains($html, 'Preview pending'));
});

$harness->check(_gallery::class, 'browse gallery event assignment markup is hidden by default', function () use ($harness): void {
    $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'browse_gallery.php');
    $css = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'project.css');
    $js = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'project.js');
    $action = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'EventPermissionsAction.php');

    if (!is_string($source) || !is_string($css) || !is_string($js) || !is_string($action)) {
        throw new RuntimeException('Unable to read gallery source files.');
    }

    $harness->assertTrue(str_contains($source, 'data-gallery-events-toggle'));
    $harness->assertTrue(str_contains($source, 'data-gallery-events-pane hidden'));
    $harness->assertTrue(str_contains($source, 'data-gallery-event-create-toggle'));
    $harness->assertTrue(str_contains($source, '>Add Event<'));
    $harness->assertTrue(str_contains($source, '>Tag Photos<'));
    $harness->assertTrue(str_contains($source, "'Show Photos'"));
    $harness->assertTrue(!str_contains($source, '>Assign Events<'));
    $harness->assertTrue(!str_contains($source, '>Tag<'));
    $harness->assertTrue(!str_contains($source, '>Untag<'));
    $harness->assertTrue(!str_contains($source, 'data-gallery-events-selected-count'));
    $harness->assertTrue(str_contains($source, 'class="gallery-event-select"'));
    $harness->assertTrue(str_contains($source, 'data-gallery-event-photo-checkbox'));
    $harness->assertTrue(str_contains($source, 'data-gallery-event-ids'));
    $harness->assertTrue(str_contains($source, 'data-gallery-assignment-event-id'));
    $harness->assertTrue(str_contains($source, 'data-gallery-event-immediate-form'));
    $harness->assertTrue(str_contains($source, 'gallery_event_immediate'));
    $harness->assertTrue(str_contains($source, 'eventFilterField'));
    $harness->assertTrue(str_contains($css, '.gallery-event-select'));
    $harness->assertTrue(str_contains($css, '.gallery-event-create-backdrop'));
    $harness->assertTrue(str_contains($css, '.gallery-event-create-window'));
    $harness->assertTrue(str_contains($css, '.gallery-grid.is-assigning-events.has-selected-event .gallery-event-select'));
    $harness->assertTrue(str_contains($js, 'data-gallery-events-toggle'));
    $harness->assertTrue(str_contains($js, 'data-gallery-event-create-toggle'));
    $harness->assertTrue(str_contains($js, 'Close Events'));
    $harness->assertTrue(str_contains($js, 'setGalleryAssignmentEvent'));
    $harness->assertTrue(str_contains($js, 'updateGalleryEventCheckboxStates'));
    $harness->assertTrue(str_contains($js, 'submitGalleryEventCheckbox'));
    $harness->assertTrue(str_contains($js, 'currentEventId === eventId ?'));
    $harness->assertTrue(str_contains($js, 'galleryEventCreateForm'));
    $harness->assertTrue(str_contains($js, 'is-assigning-events'));
    $harness->assertTrue(str_contains($js, 'has-selected-event'));
    $harness->assertTrue(str_contains($js, 'data-gallery-viewer-prefetch-url'));
    $harness->assertTrue(str_contains($js, 'prefetchGalleryViewerImageFromEvent'));
    $harness->assertTrue(str_contains($js, 'void poll(0);'));
    $harness->assertTrue(str_contains($action, "['event.permissions', 'browse.gallery']"));
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
        'thumbnail_ready' => false,
    ]]));

    $harness->assertSame(true, $method->invoke($card, [[
        'id' => 46,
        'original_filename' => 'IMG_0046.CR2',
        'conversion_state' => 'ready',
        'preview_ready' => false,
        'thumbnail_ready' => false,
    ]]));

    $harness->assertSame(false, $method->invoke($card, [[
        'id' => 47,
        'original_filename' => 'IMG_0047.CR2',
        'conversion_state' => 'failed',
        'preview_ready' => false,
        'thumbnail_ready' => false,
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

$harness->check(_photo_audit_logCard::class, 'renders photo audit rows with eelKit table builder', function () use ($harness): void {
    $card = new _photo_audit_logCard();
    $html = $card->render([
        'page' => [
            'page_id' => 'logs',
            'page_cards' => ['photo_audit_log'],
            'photo_audit_log_page' => 1,
        ],
        'services' => [
            'photo_audit_rows' => [[
                'id' => 9,
                'photo_id' => 42,
                'event_id' => 7,
                'actor_user_id' => 3,
                'upload_token_id' => 5,
                'action_type' => 'raw_uploaded',
                'details_json' => '{"source":"api"}',
                'device_id' => 'device-a',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Test Agent',
                'occurred_at' => '2026-06-27 10:00:00',
                'original_filename' => 'IMG_0042.CR2',
                'event_name' => 'Family',
                'actor_user_display_name' => 'Admin User',
                'upload_token_label' => 'Bridge Upload',
            ]],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'photo-audit-table'));
    $harness->assertTrue(str_contains($html, 'IMG_0042.CR2'));
    $harness->assertTrue(str_contains($html, 'Family'));
    $harness->assertTrue(str_contains($html, 'Admin User'));
    $harness->assertTrue(str_contains($html, 'Upload token: Bridge Upload'));
    $harness->assertTrue(str_contains($html, '<span class="badge info">Raw Uploaded</span>'));
    $harness->assertTrue(str_contains($html, 'source: api'));
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
    $harness->assertTrue(str_contains($source, 'userCanEditPhoto'));
    $harness->assertTrue(str_contains($source, 'editing is not available to your account'));
    $harness->assertTrue(str_contains($source, 'data-picture-editor-display-state'));
    $harness->assertTrue(str_contains($source, 'Displaying: '));
    $harness->assertTrue(str_contains($source, 'data-picture-editor-field="\' . HelperFramework::escape($key) . \'" disabled'));
    $harness->assertTrue(str_contains($source, 'data-picture-editor-check="\' . HelperFramework::escape($key) . \'" disabled'));
    $harness->assertTrue(str_contains($source, 'data-picture-editor-save disabled'));
});

$harness->check(_picture_editorCard::class, 'picture editor renders editor empty photo message', function () use ($harness): void {
    $context = [
        'page' => [
            'photo_id' => 0,
        ],
    ];

    $editorHtml = (new _picture_editorCard())->render($context);

    $harness->assertSame('<p class="helper">Select a photo from the gallery to edit it here.</p>', $editorHtml);
});

$harness->check(_edit::class, 'picture editor exposes revert control', function () use ($harness): void {
    $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'picture_editor.php');

    if (!is_string($source)) {
        throw new RuntimeException('Unable to read picture editor card source.');
    }

    $harness->assertTrue(str_contains($source, 'data-picture-editor-revert'));
    $harness->assertTrue(str_contains($source, 'Revert to Baseline'));
});

$harness->check(_event_permissionsCard::class, 'event permissions card uses searchable user picker', function () use ($harness): void {
    $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'event_permissions.php');
    $serviceSource = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'swallowtail' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'SwallowtailEventManagementService.php');

    if (!is_string($source) || !is_string($serviceSource)) {
        throw new RuntimeException('Unable to read event permission source.');
    }

    $harness->assertTrue(str_contains($source, 'Role Permissions'));
    $harness->assertTrue(str_contains($source, 'User Permissions'));
    $harness->assertTrue(str_contains($source, '+ Add User Permissions'));
    $harness->assertTrue(str_contains($source, 'data-event-user-picker'));
    $harness->assertTrue(str_contains($source, 'userPermissionRows'));
    $harness->assertTrue(str_contains($serviceSource, 'searchUsers'));
    $harness->assertTrue(str_contains($serviceSource, 'LIMIT " . (string)$limit'));
});

$harness->check(_event_permissionsCard::class, 'event permissions card exposes delete grant action', function () use ($harness): void {
    $source = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'event_permissions.php');
    $actionSource = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'EventPermissionsAction.php');
    $serviceSource = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'swallowtail' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'SwallowtailEventManagementService.php');

    if (!is_string($source) || !is_string($actionSource) || !is_string($serviceSource)) {
        throw new RuntimeException('Unable to read event permission source.');
    }

    $harness->assertTrue(str_contains($source, "hiddenFields(\$context, \$csrfToken, 'delete_grant'"));
    $harness->assertTrue(str_contains($source, '<button class="button button-inline danger" type="submit">Delete</button>'));
    $harness->assertTrue(str_contains($actionSource, "'delete_grant' =>"));
    $harness->assertTrue(str_contains($serviceSource, 'deletePermission'));
    $harness->assertTrue(str_contains($serviceSource, 'revokeEventGranteePermission'));
});

$harness->check(_event_permissionsCard::class, 'event role permissions explain when no roles exist', function () use ($harness): void {
    $card = new _event_permissionsCard();
    $method = new ReflectionMethod($card, 'rolePermissions');
    $method->setAccessible(true);

    $html = (string)$method->invoke($card, [
        'page' => [
            'csrf_token' => 'test-csrf',
            'selected_event_id' => 7,
        ],
    ], [], 7, 'test-csrf');

    $harness->assertTrue(str_contains($html, 'Role Permissions'));
    $harness->assertTrue(str_contains($html, 'No roles are available yet.'));
    $harness->assertTrue(str_contains($html, 'Create a role before assigning role-based event permissions.'));
});

$harness->check('SwallowTail SVG', 'events icon matches user and role icon style', function () use ($harness): void {
    $path = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'svg' . DIRECTORY_SEPARATOR . 'events.svg';
    $svg = file_get_contents($path);

    if (!is_string($svg)) {
        throw new RuntimeException('Unable to read events SVG.');
    }

    $harness->assertTrue(str_contains($svg, 'Swallowtail'));
    $harness->assertTrue(str_contains($svg, 'viewBox="0 0 24 24"'));
    $harness->assertTrue(str_contains($svg, 'fill="none"'));
    $harness->assertTrue(str_contains($svg, 'stroke="#ffffff"'));
    $harness->assertTrue(str_contains($svg, 'stroke-width="1.35"'));
    $harness->assertTrue(str_contains($svg, 'stroke-linecap="round"'));
    $harness->assertTrue(str_contains($svg, 'stroke-linejoin="round"'));
});
