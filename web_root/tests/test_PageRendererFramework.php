<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

class PageRendererLegacyLayoutTestPage implements PageInterfaceFramework
{
    public function id(): string { return 'legacy_layout_test'; }
    public function title(): string { return 'Legacy Layout Test'; }
    public function subtitle(): string { return 'Legacy cards'; }
    public function pageStackClass(): string { return ''; }
    public function services(): array { return []; }
    public function cards(): array { return ['alpha', 'beta']; }
    public function handle(RequestFramework $request, PageServiceFramework $services): ResponseFramework
    {
        return ResponseFramework::html('');
    }
}

final class PageRendererCardLayoutTestPage extends PageRendererLegacyLayoutTestPage
{
    public function id(): string { return 'card_layout_test'; }
    public function cards(): array { return ['alpha', 'beta', 'epsilon']; }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Details',
                'cards' => ['alpha', 'beta'],
            ],
            [
                'tab' => 'Review',
                'layout' => 'split',
                'cards' => ['gamma', 'denied'],
            ],
            [
                'tab' => 'Empty',
                'cards' => ['denied'],
            ],
            [
                'tab' => 'Unsupported',
                'layout' => 'wide',
                'cards' => ['delta'],
            ],
        ];
    }
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(PageRendererFramework::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof PageRendererFramework) {
        $harness->skip('Page renderer did not instantiate.');
    }

    $resolveCardLayout = new ReflectionMethod(PageRendererFramework::class, 'resolveCardLayout');
    $resolveCardLayout->setAccessible(true);
    $shouldRenderTabs = new ReflectionMethod(PageRendererFramework::class, 'shouldRenderTabs');
    $shouldRenderTabs->setAccessible(true);
    $selectedTabIndex = new ReflectionMethod(PageRendererFramework::class, 'selectedTabIndex');
    $selectedTabIndex->setAccessible(true);
    $requestedVisibleCard = new ReflectionMethod(PageRendererFramework::class, 'requestedVisibleCard');
    $requestedVisibleCard->setAccessible(true);
    $brandMark = new ReflectionMethod(PageRendererFramework::class, 'brandMark');
    $brandMark->setAccessible(true);
    $renderDeveloperOptionsStatus = new ReflectionMethod(PageRendererFramework::class, 'renderDeveloperOptionsStatus');
    $renderDeveloperOptionsStatus->setAccessible(true);

    $harness->check(PageRendererFramework::class, 'normalises legacy cards to one stack layout without tabs', function () use ($harness, $instance, $resolveCardLayout, $shouldRenderTabs): void {
        $layout = $resolveCardLayout->invoke($instance, new PageRendererLegacyLayoutTestPage(), [
            'page' => [
                'page_cards' => ['alpha', 'beta'],
            ],
        ]);

        $harness->assertSame([
            [
                'tab' => null,
                'layout' => 'stack',
                'cards' => ['alpha', 'beta'],
                'explicit' => false,
            ],
        ], $layout);
        $harness->assertSame(false, $shouldRenderTabs->invoke($instance, $layout));
    });

    $harness->check(PageRendererFramework::class, 'normalises cardLayout tabs and filters denied cards', function () use ($harness, $instance, $resolveCardLayout, $shouldRenderTabs): void {
        $layout = $resolveCardLayout->invoke($instance, new PageRendererCardLayoutTestPage(), [
            'page' => [
                'page_cards' => ['alpha', 'beta', 'gamma', 'delta', 'epsilon'],
            ],
        ]);

        $harness->assertSame([
            [
                'tab' => 'Details',
                'layout' => 'stack',
                'cards' => ['alpha', 'beta', 'epsilon'],
                'explicit' => true,
            ],
            [
                'tab' => 'Review',
                'layout' => 'split',
                'cards' => ['gamma'],
                'explicit' => true,
            ],
            [
                'tab' => 'Unsupported',
                'layout' => 'stack',
                'cards' => ['delta'],
                'explicit' => true,
            ],
        ], $layout);
        $harness->assertSame(true, $shouldRenderTabs->invoke($instance, $layout));
    });

    $harness->check(PageRendererFramework::class, 'uses action result show_card before requested show_card', function () use ($harness, $instance, $requestedVisibleCard): void {
        $request = new RequestFramework(
            ['page' => 'card_layout_test'],
            ['show_card' => 'alpha'],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
        );
        $actionResult = ActionResultFramework::success(
            ['page.reload'],
            [],
            ['show_card' => 'gamma'],
        );

        $harness->assertSame(
            'gamma',
            $requestedVisibleCard->invoke($instance, new PageRendererCardLayoutTestPage(), $request, [
                'page' => [
                    'page_cards' => ['alpha', 'beta', 'gamma', 'delta'],
                ],
            ], $actionResult)
        );
    });

    $harness->check(PageRendererFramework::class, 'selects full render tab containing requested show_card', function () use ($harness, $instance, $selectedTabIndex): void {
        $layout = [
            [
                'tab' => 'Details',
                'layout' => 'stack',
                'cards' => ['alpha', 'beta'],
                'explicit' => true,
            ],
            [
                'tab' => 'Review',
                'layout' => 'split',
                'cards' => ['gamma'],
                'explicit' => true,
            ],
        ];
        $request = new RequestFramework(
            ['page' => 'card_layout_test'],
            ['show_card' => 'gamma'],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
        );

        $harness->assertSame(1, $selectedTabIndex->invoke(
            $instance,
            $layout,
            $request,
            ActionResultFramework::success()
        ));
    });

    $harness->check(PageRendererFramework::class, 'falls back to first tab when requested show_card is unavailable', function () use ($harness, $instance, $selectedTabIndex): void {
        $layout = [
            [
                'tab' => 'Details',
                'layout' => 'stack',
                'cards' => ['alpha', 'beta'],
                'explicit' => true,
            ],
            [
                'tab' => 'Review',
                'layout' => 'split',
                'cards' => ['gamma'],
                'explicit' => true,
            ],
        ];
        $request = new RequestFramework(
            ['page' => 'card_layout_test'],
            ['show_card' => 'denied'],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
        );

        $harness->assertSame(0, $selectedTabIndex->invoke(
            $instance,
            $layout,
            $request,
            ActionResultFramework::success()
        ));
    });

    $harness->check(PageRendererFramework::class, 'reads the sidebar brand mark from application config', function () use ($harness, $instance, $brandMark): void {
        $harness->assertSame('T', $brandMark->invoke($instance));
    });

    $harness->check(PageRendererFramework::class, 'renders developer options badge only when enabled', function () use ($harness, $instance, $renderDeveloperOptionsStatus): void {
        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        try {
            AppConfigurationStore::set('developer_options', true);
            $harness->assertTrue(str_contains((string)$renderDeveloperOptionsStatus->invoke($instance), 'Developer Options: On'));

            AppConfigurationStore::set('developer_options', false);
            $harness->assertSame('', $renderDeveloperOptionsStatus->invoke($instance));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });

    $harness->check(PageRendererFramework::class, 'frontend rebinds page card tabs after AJAX card replacement', function () use ($harness): void {
        $script = file_get_contents(APP_JS . 'index.js');

        if (!is_string($script)) {
            throw new RuntimeException('Unable to read frontend script.');
        }

        $replacePosition = strpos($script, 'current.replaceWith(replacement);');
        $rebindPosition = strpos($script, 'initialisePageCardTabs(replacement);');

        $harness->assertTrue($replacePosition !== false);
        $harness->assertTrue($rebindPosition !== false);
        $harness->assertTrue($replacePosition < $rebindPosition);
    });

    $harness->check(PageRendererFramework::class, 'frontend preserves maximized cards after AJAX card replacement', function () use ($harness): void {
        $script = file_get_contents(APP_JS . 'index.js');

        if (!is_string($script)) {
            throw new RuntimeException('Unable to read frontend script.');
        }

        $harness->assertTrue(str_contains($script, "const wasMaximized = current.classList.contains('card-maximized');"));
        $harness->assertTrue(str_contains($script, 'setCardMaximized(replacement, true);'));
        $harness->assertTrue(str_contains($script, "const cardMaximizedStorageKey = 'card:maximized';"));
        $harness->assertTrue(str_contains($script, 'function cardStorageIdentity(card)'));
        $harness->assertTrue(str_contains($script, 'function restoreStoredCardMaximized(card)'));
        $harness->assertTrue(str_contains($script, 'restoreStoredCardMaximized(card);'));
        $harness->assertTrue(
            strpos($script, "const wasMaximized = current.classList.contains('card-maximized');") <
            strpos($script, 'current.replaceWith(replacement);')
        );
    });

    $harness->check(PageRendererFramework::class, 'frontend page card switchers can target page-level tab root', function () use ($harness): void {
        $script = file_get_contents(APP_JS . 'index.js');

        if (!is_string($script)) {
            throw new RuntimeException('Unable to read frontend script.');
        }

        $harness->assertTrue(str_contains(
            $script,
            "control.closest('.page-card-tabs') || document.querySelector('.page-card-tabs')"
        ));
    });

    $harness->check(PageRendererFramework::class, 'frontend picture editor polls through interim preview images', function () use ($harness): void {
        $script = file_get_contents(APP_JS . 'index.js');

        if (!is_string($script)) {
            throw new RuntimeException('Unable to read frontend script.');
        }

        $harness->assertTrue(str_contains($script, 'displayedPreviewStage'));
        $harness->assertTrue(str_contains($script, 'response?.thumbnail_url'));
        $harness->assertTrue(str_contains($script, 'response?.original_url'));
        $harness->assertTrue(str_contains($script, 'Thumbnail ready; rendering filtered'));
        $harness->assertTrue(str_contains($script, 'Original ready; rendering filtered'));
        $harness->assertTrue(str_contains($script, "displayedPreviewStage = 'filtered';"));
    });

    $harness->check(PageRendererFramework::class, 'frontend picture editor reverts to neutral settings through preview flow', function () use ($harness): void {
        $script = file_get_contents(APP_JS . 'index.js');

        if (!is_string($script)) {
            throw new RuntimeException('Unable to read frontend script.');
        }

        $harness->assertTrue(str_contains($script, 'data-picture-editor-revert'));
        $harness->assertTrue(str_contains($script, 'function revertToOriginal()'));
        $harness->assertTrue(str_contains($script, 'crop: { x: 0, y: 0, width: sourceWidth, height: sourceHeight }'));
        $harness->assertTrue(str_contains($script, 'exposure: { black: 0, lightness: 0, contrast: 0, saturation: 0 }'));
        $harness->assertTrue(str_contains($script, 'syncExposureControls();'));
        $harness->assertTrue(str_contains($script, 'scheduleSubmit();'));
    });
});
