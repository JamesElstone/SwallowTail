<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'upload_tokens.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_upload_tokensCard::class, 'renders create form, one-time token panel, and token management rows', function () use ($harness): void {
    $html = (new _upload_tokensCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['upload_tokens'],
        ],
        'upload_tokens' => [
            'created_token' => 'stup_plaintext_once',
        ],
        'services' => [
            'upload_tokens' => [[
                'id' => 7,
                'token_label' => 'Bridge A',
                'is_active' => 1,
                'can_upload_raw' => 1,
                'cidrs' => ['203.0.113.0/24', '2001:db8::/32'],
                'created_at' => '2026-06-16 12:00:00',
                'last_used_at' => null,
                'expires_at' => '2026-06-17 12:00:00',
            ]],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'Create Upload Token'));
    $harness->assertTrue(str_contains($html, 'stup_plaintext_once'));
    $harness->assertTrue(str_contains($html, 'card_action" value="UploadTokens"'));
    $harness->assertTrue(str_contains($html, 'upload_token_action" value="create"'));
    $harness->assertTrue(str_contains($html, 'upload_token_action" value="update"'));
    $harness->assertTrue(str_contains($html, 'upload_token_action" value="delete"'));
    $harness->assertTrue(str_contains($html, '203.0.113.0/24'));
    $harness->assertTrue(str_contains($html, '2001:db8::/32'));
});
