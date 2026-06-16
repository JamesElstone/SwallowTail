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

$harness->check(UploadTokensAction::class, 'rejects missing permission or invalid csrf before mutating tokens', function () use ($harness): void {
    $request = new RequestFramework(
        ['page' => 'settings'],
        [
            'upload_token_action' => 'create',
            'token_label' => 'Bridge A',
            'cidrs' => '203.0.113.0/24',
            'csrf_token' => 'invalid',
        ],
        ['REQUEST_METHOD' => 'POST'],
        [],
        [],
        null,
        []
    );

    $result = (new UploadTokensAction())->handle($request, new PageServiceFramework(new AppService('')));

    $harness->assertSame(false, $result->isSuccess());
    $harness->assertSame(['upload.tokens'], $result->changedFacts());
    $harness->assertTrue(str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'permission'));
});
