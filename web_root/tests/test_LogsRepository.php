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
    $harness->check(LogsRepository::class, 'maps upload token audit rows into activity rows', function () use ($harness): void {
        InterfaceDB::execute('DROP TABLE IF EXISTS application_activity_flash_history');
        InterfaceDB::execute('DROP TABLE IF EXISTS user_account_audit');
        InterfaceDB::execute('DROP TABLE IF EXISTS users');
        InterfaceDB::execute("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            display_name TEXT NOT NULL,
            email_address TEXT NOT NULL
        )");
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
        InterfaceDB::execute("CREATE TABLE user_account_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            affected_user_id INTEGER NOT NULL,
            actor_user_id INTEGER NULL,
            action_type TEXT NOT NULL,
            reason TEXT NULL,
            details_json TEXT NULL,
            device_id TEXT NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        InterfaceDB::prepareExecute(
            'INSERT INTO users (id, display_name, email_address) VALUES (:id, :display_name, :email_address)',
            [
                'id' => 44,
                'display_name' => 'Token Account',
                'email_address' => 'token-account@example.test',
            ]
        );
        InterfaceDB::prepareExecute(
            "INSERT INTO user_account_audit (
                affected_user_id,
                action_type,
                reason,
                details_json,
                device_id,
                ip_address,
                user_agent,
                created_at
            ) VALUES (
                :affected_user_id,
                :action_type,
                :reason,
                :details_json,
                :device_id,
                :ip_address,
                :user_agent,
                :created_at
            )",
            [
                'affected_user_id' => 44,
                'action_type' => 'upload_token_raw_upload_failed',
                'reason' => 'RAW upload failed while storing the file.',
                'details_json' => json_encode([
                    'upload_token_id' => 7,
                    'token_label' => 'SpiceBush desktop',
                    'success' => false,
                    'failure_reason' => 'RAW upload failed while storing the file.',
                ], JSON_UNESCAPED_SLASHES),
                'device_id' => 'DESKTOP-C6R0CCD',
                'ip_address' => '203.0.113.15',
                'user_agent' => 'spicebush-test',
                'created_at' => '2026-06-18 16:19:03',
            ]
        );

        $rows = (new LogsRepository())->fetchRecentFlashActivity(10);

        $harness->assertCount(1, $rows);
        $harness->assertSame(44, (int)($rows[0]['user_id'] ?? 0));
        $harness->assertSame('Token Account', (string)($rows[0]['user_display_name'] ?? ''));
        $harness->assertSame('api', (string)($rows[0]['page_id'] ?? ''));
        $harness->assertSame('raw upload failed', (string)($rows[0]['action_name'] ?? ''));
        $harness->assertSame('SpiceBush desktop', (string)($rows[0]['card_action_name'] ?? ''));
        $harness->assertSame('error', (string)($rows[0]['message_type'] ?? ''));
        $harness->assertSame('/api/raw-upload.php', (string)($rows[0]['request_uri'] ?? ''));

        $filteredRows = (new LogsRepository())->fetchRecentFlashActivity(10, 44, 'api');
        $harness->assertCount(1, $filteredRows);
        $harness->assertCount(0, (new LogsRepository())->fetchRecentFlashActivity(10, 45, 'api'));
        $harness->assertCount(0, (new LogsRepository())->fetchRecentFlashActivity(10, 44, 'dashboard'));

        InterfaceDB::execute('DROP TABLE IF EXISTS application_activity_flash_history');
        InterfaceDB::execute('DROP TABLE IF EXISTS user_account_audit');
        InterfaceDB::execute('DROP TABLE IF EXISTS users');
    });
});
