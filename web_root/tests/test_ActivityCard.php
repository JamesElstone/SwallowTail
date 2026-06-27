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
$harness->run(_activityCard::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _activityCard) {
        $harness->skip('Activity card did not instantiate.');
    }

    $request = new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], []);
    $services = new PageServiceFramework(new AppService(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp'));

    $harness->check(_activityCard::class, 'requests user-scoped activity rows when context provides an activity user id', function () use ($harness, $instance, $services): void {
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

        foreach ([
            ['user_id' => 321, 'message_text' => 'Current user message', 'occurred_at' => '2026-06-18 16:19:03'],
            ['user_id' => 654, 'message_text' => 'Other user message', 'occurred_at' => '2026-06-18 16:20:03'],
        ] as $row) {
            InterfaceDB::prepareExecute(
                "INSERT INTO application_activity_flash_history (
                    user_id,
                    page_id,
                    action_name,
                    card_action_name,
                    message_type,
                    message_text,
                    request_method,
                    is_ajax,
                    occurred_at
                ) VALUES (
                    :user_id,
                    'logs',
                    'test action',
                    NULL,
                    'success',
                    :message_text,
                    'POST',
                    0,
                    :occurred_at
                )",
                $row
            );
        }

        try {
            $renderer = new CardRendererFramework(new CardFactoryFramework());
            $cardContext = $renderer->buildContextForCard(
                $instance,
                [
                    'page' => ['page_id' => 'logs'],
                    'activity_user_id' => 321,
                ],
                $services
            );

            $rows = (array)(($cardContext['services'] ?? [])['activity_rows'] ?? []);
            $harness->assertCount(1, $rows);
            $harness->assertSame(321, (int)($rows[0]['user_id'] ?? 0));
            $harness->assertSame('Current user message', (string)($rows[0]['message_text'] ?? ''));
        } finally {
            InterfaceDB::execute('DROP TABLE IF EXISTS application_activity_flash_history');
        }
    });

    $harness->check(_activityCard::class, 'scopes non-admin activity card context to the current user', function () use ($harness, $instance, $request, $services): void {
        $context = $instance->handle(
            $request,
            $services,
            [
                'page' => ['page_id' => 'logs'],
                'auth' => [
                    'user_id' => 321,
                    'role_id' => 2,
                ],
            ],
            ActionResultFramework::none()
        );

        $harness->assertSame(321, (int)($context['activity_user_id'] ?? 0));
    });

    $harness->check(_activityCard::class, 'leaves admin activity card context unscoped', function () use ($harness, $instance, $request, $services): void {
        $context = $instance->handle(
            $request,
            $services,
            [
                'page' => ['page_id' => 'logs'],
                'auth' => [
                    'user_id' => 1,
                    'role_id' => RoleAssignmentService::ADMIN_ROLE_ID,
                ],
            ],
            ActionResultFramework::none()
        );

        $harness->assertSame(false, array_key_exists('activity_user_id', $context));
    });
});