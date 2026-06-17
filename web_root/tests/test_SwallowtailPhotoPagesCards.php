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

    foreach (['cr2_upload', 'storage_available', 'browse_gallery', 'picture_viewer', 'recent_uploads'] as $cardKey) {
        $card = $factory->create($cardKey);
        $harness->assertSame($cardKey, $card->key());
    }
});

$harness->check(_settings::class, 'includes reusable storage card', function () use ($harness): void {
    $settings = new _settings();

    $harness->assertTrue(in_array('storage_available', $settings->cards(), true));
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
