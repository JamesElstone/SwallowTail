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

$uploadTokenRow = static function (int $id, int $userId, string $label): array {
    return [
        'id' => $id,
        'token_label' => $label,
        'created_by_user_id' => $userId,
        'created_by_user_label' => $userId === 42 ? 'Camera Admin' : 'Other Admin',
        'created_by_user_email_address' => $userId === 42 ? 'camera-admin@example.test' : 'other-admin@example.test',
        'hidden' => 0,
        'is_active' => 1,
        'can_upload_raw' => 1,
        'cidrs' => ['203.0.113.0/24'],
        'created_at' => '2026-06-16 12:00:00',
        'last_used_at' => null,
        'expires_at' => '2026-06-17 12:00:00',
    ];
};

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
                'created_by_user_id' => 42,
                'created_by_user_label' => 'Camera Admin',
                'created_by_user_email_address' => 'camera-admin@example.test',
                'hidden' => 0,
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
    $harness->assertTrue(str_contains($html, '<div class="upload-token-access-flags">'));
    $harness->assertTrue(str_contains($html, '<span class="table-sort-label">User</span>'));
    $harness->assertTrue(str_contains($html, '<label for="table-filter-upload_tokens-upload_tokens_filter">Filter</label>'));
    $harness->assertTrue(str_contains($html, '<option value="owned" selected>Owned Tokens</option>'));
    $harness->assertTrue(str_contains($html, '<option value="all">All Tokens</option>'));
    $harness->assertTrue(
        str_contains($html, 'Upload tokens 1-1 of 1')
        || str_contains($html, 'Upload tokens 1 of 1')
    );
    $harness->assertTrue(str_contains($html, 'Camera Admin'));
    $harness->assertTrue(str_contains($html, 'ID 42'));
    $harness->assertTrue(str_contains($html, 'camera-admin@example.test'));
    $harness->assertTrue(str_contains($html, '203.0.113.0/24'));
    $harness->assertTrue(str_contains($html, '2001:db8::/32'));
});

$harness->check(_upload_tokensCard::class, 'filters upload tokens to the current owner and paginates rows', function () use ($harness, $uploadTokenRow): void {
    $rows = [];
    foreach (range(1, 12) as $id) {
        $rows[] = $uploadTokenRow($id, 42, 'Owned token ' . str_pad((string)$id, 2, '0', STR_PAD_LEFT));
    }
    $rows[] = $uploadTokenRow(99, 7, 'Other token');

    $html = (new _upload_tokensCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['upload_tokens'],
        ],
        'upload_tokens' => [
            'ownership_filter' => 'owned',
            'current_user_id' => 42,
        ],
        'services' => [
            'upload_tokens' => $rows,
        ],
    ]);

    $harness->assertTrue(str_contains($html, '<option value="owned" selected>Owned Tokens</option>'));
    $harness->assertTrue(str_contains($html, 'Upload tokens 1-10 of 12'));
    $harness->assertTrue(str_contains($html, 'Owned token 01'));
    $harness->assertTrue(str_contains($html, 'Owned token 10'));
    $harness->assertSame(false, str_contains($html, 'Owned token 11'));
    $harness->assertSame(false, str_contains($html, 'Other token'));
    $harness->assertTrue(str_contains($html, 'name="upload_tokens_filter" value="owned"'));
    $harness->assertTrue(str_contains($html, 'name="upload_tokens_page" value="2"'));
});

$harness->check(_upload_tokensCard::class, 'does not render hidden upload tokens', function () use ($harness, $uploadTokenRow): void {
    $visible = $uploadTokenRow(1, 42, 'Visible token');
    $hidden = $uploadTokenRow(2, 42, 'Hidden token');
    $hidden['hidden'] = 1;

    $html = (new _upload_tokensCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['upload_tokens'],
        ],
        'upload_tokens' => [
            'ownership_filter' => 'all',
            'current_user_id' => 42,
        ],
        'services' => [
            'upload_tokens' => [$visible, $hidden],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'Visible token'));
    $harness->assertSame(false, str_contains($html, 'Hidden token'));
    $harness->assertTrue(
        str_contains($html, 'Upload tokens 1-1 of 1')
        || str_contains($html, 'Upload tokens 1 of 1')
    );
});

$harness->check(_upload_tokensCard::class, 'shows every upload token when all tokens are selected', function () use ($harness, $uploadTokenRow): void {
    $html = (new _upload_tokensCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['upload_tokens'],
        ],
        'upload_tokens' => [
            'ownership_filter' => 'all',
            'current_user_id' => 42,
        ],
        'services' => [
            'upload_tokens' => [
                $uploadTokenRow(1, 42, 'Owned token'),
                $uploadTokenRow(2, 7, 'Other token'),
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, '<option value="all" selected>All Tokens</option>'));
    $harness->assertTrue(str_contains($html, 'Upload tokens 1-2 of 2'));
    $harness->assertTrue(str_contains($html, 'Owned token'));
    $harness->assertTrue(str_contains($html, 'Other token'));
    $harness->assertTrue(str_contains($html, 'name="upload_tokens_filter" value="all"'));
});
