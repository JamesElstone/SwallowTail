<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'timezone_settings.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_timezone_settingsCard::class, 'renders server timezone selector and settings action', function () use ($harness): void {
    $card = new _timezone_settingsCard();
    $html = $card->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['timezone_settings'],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="card_action" value="TimezoneSettings"'));
    $harness->assertTrue(str_contains($html, 'name="server_timezone"'));
    $harness->assertTrue(str_contains($html, 'value="Europe/London"'));
    $harness->assertTrue(str_contains($html, 'data-submit-on-change="true"'));
});
