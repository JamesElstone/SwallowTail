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

$harness->run(LogsRepository::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(LogsRepository::class, 'fetches normal activity rows by user and page', function () use ($harness): void {
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
        InterfaceDB::prepareExecute(
            "INSERT INTO application_activity_flash_history (
                user_id,
                page_id,
                action_name,
                card_action_name,
                message_type,
                message_text,
                message_html_text,
                request_method,
                is_ajax,
                device_id,
                ip_address,
                user_agent,
                request_uri,
                occurred_at
            ) VALUES (
                :user_id,
                :page_id,
                :action_name,
                :card_action_name,
                :message_type,
                :message_text,
                :message_html_text,
                :request_method,
                0,
                :device_id,
                :ip_address,
                :user_agent,
                :request_uri,
                :occurred_at
            )",
            [
                'user_id' => 444044,
                'page_id' => 'api',
                'action_name' => 'raw upload failed',
                'card_action_name' => 'SpiceBush desktop',
                'message_type' => 'error',
                'message_text' => 'RAW upload failed while storing the file.',
                'message_html_text' => null,
                'request_method' => 'POST',
                'device_id' => 'DESKTOP-C6R0CCD',
                'ip_address' => '203.0.113.15',
                'user_agent' => 'spicebush-test',
                'request_uri' => '/api/raw-upload.php',
                'occurred_at' => '2026-06-18 16:19:03',
            ]
        );

        $rows = (new LogsRepository())->fetchRecentFlashActivity(10);

        $harness->assertCount(1, $rows);
        $harness->assertSame(444044, (int)($rows[0]['user_id'] ?? 0));
        $harness->assertSame('', (string)($rows[0]['user_display_name'] ?? ''));
        $harness->assertSame('api', (string)($rows[0]['page_id'] ?? ''));
        $harness->assertSame('raw upload failed', (string)($rows[0]['action_name'] ?? ''));
        $harness->assertSame('SpiceBush desktop', (string)($rows[0]['card_action_name'] ?? ''));
        $harness->assertSame('error', (string)($rows[0]['message_type'] ?? ''));
        $harness->assertSame('/api/raw-upload.php', (string)($rows[0]['request_uri'] ?? ''));

        $filteredRows = (new LogsRepository())->fetchRecentFlashActivity(10, 444044, 'api');
        $harness->assertCount(1, $filteredRows);
        $harness->assertCount(0, (new LogsRepository())->fetchRecentFlashActivity(10, 45, 'api'));
        $harness->assertCount(0, (new LogsRepository())->fetchRecentFlashActivity(10, 444044, 'dashboard'));

        InterfaceDB::execute('DROP TABLE IF EXISTS application_activity_flash_history');
    });
});
