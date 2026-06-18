<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(ActivityStore::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof ActivityStore) {
        $harness->skip('Activity store did not instantiate.');
    }

    $normaliseFlashMessages = new ReflectionMethod(ActivityStore::class, 'normaliseFlashMessages');
    $normaliseFlashMessages->setAccessible(true);
    $requestMetadata = new ReflectionMethod(ActivityStore::class, 'requestMetadata');
    $requestMetadata->setAccessible(true);

    $harness->check(ActivityStore::class, 'normalises scalar, plain, and HTML flash messages', function () use ($harness, $instance, $normaliseFlashMessages): void {
        $messages = $normaliseFlashMessages->invoke($instance, [
            'Saved successfully.',
            [
                'type' => 'error',
                'message' => 'Unable to save.',
            ],
            [
                'type' => 'success',
                'message_html' => '<strong>Created</strong> &amp; queued',
            ],
            [
                'type' => 'ignored',
                'message' => '',
            ],
        ]);

        $harness->assertSame([
            [
                'type' => 'success',
                'text' => 'Saved successfully.',
                'html_text' => null,
            ],
            [
                'type' => 'error',
                'text' => 'Unable to save.',
                'html_text' => null,
            ],
            [
                'type' => 'success',
                'text' => 'Created & queued',
                'html_text' => 'Created & queued',
            ],
        ], $messages);
    });

    $harness->check(ActivityStore::class, 'captures bounded request metadata', function () use ($harness, $instance, $requestMetadata): void {
        $request = new RequestFramework(
            ['page' => 'dashboard'],
            [],
            [
                'REQUEST_METHOD' => 'POST',
                'REMOTE_ADDR' => '203.0.113.10',
                'REQUEST_URI' => '/?page=dashboard&action=save',
                'HTTP_USER_AGENT' => str_repeat('a', 1200),
            ],
            [],
            [],
        );

        $metadata = $requestMetadata->invoke($instance, $request);

        $harness->assertSame('203.0.113.10', $metadata['ip_address']);
        $harness->assertSame('/?page=dashboard&action=save', $metadata['request_uri']);
        $harness->assertSame(1000, mb_strlen((string)$metadata['user_agent']));
    });

    $harness->check(ActivityStore::class, 'records API activity rows', function () use ($harness, $instance): void {
        InterfaceDB::execute('DROP TABLE IF EXISTS application_activity_flash_history');
        InterfaceDB::execute("CREATE TABLE application_activity_flash_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            page_id TEXT NOT NULL,
            action_name TEXT NULL,
            card_action_name TEXT NULL,
            message_type TEXT NOT NULL,
            message_text TEXT NOT NULL,
            message_html_text TEXT NULL,
            request_method TEXT NULL,
            is_ajax INTEGER NOT NULL DEFAULT 0,
            device_id TEXT NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            request_uri TEXT NULL,
            occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $instance->recordApiActivity(
            'api',
            'raw upload failed',
            'error',
            " RAW upload failed while storing the file.\n",
            44,
            [
                'device_id' => 'DESKTOP-C6R0CCD',
                'ip_address' => '203.0.113.15',
                'user_agent' => str_repeat('s', 1200),
            ],
            'SpiceBush desktop',
            'POST',
            '/api/raw-upload.php'
        );

        $row = InterfaceDB::fetchOne('SELECT * FROM application_activity_flash_history LIMIT 1');

        $harness->assertTrue(is_array($row));
        $harness->assertSame(44, (int)($row['user_id'] ?? 0));
        $harness->assertSame('api', (string)($row['page_id'] ?? ''));
        $harness->assertSame('raw upload failed', (string)($row['action_name'] ?? ''));
        $harness->assertSame('SpiceBush desktop', (string)($row['card_action_name'] ?? ''));
        $harness->assertSame('error', (string)($row['message_type'] ?? ''));
        $harness->assertSame('RAW upload failed while storing the file.', (string)($row['message_text'] ?? ''));
        $harness->assertSame('POST', (string)($row['request_method'] ?? ''));
        $harness->assertSame('DESKTOP-C6R0CCD', (string)($row['device_id'] ?? ''));
        $harness->assertSame(1000, mb_strlen((string)($row['user_agent'] ?? '')));
        $harness->assertSame('/api/raw-upload.php', (string)($row['request_uri'] ?? ''));

        InterfaceDB::execute('DROP TABLE IF EXISTS application_activity_flash_history');
    });
});
