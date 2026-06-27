<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class LogsRepository
{
    public function fetchRecentFlashActivity(int $limit = 100, int $userId = 0, string $pageId = ''): array
    {
        $limit = FormattingFramework::normaliseLimit($limit);
        if (!InterfaceDB::tableExists('application_activity_flash_history')) {
            return [];
        }

        $whereParts = [];
        $params = [];
        $this->appendActivityFilters($whereParts, $params, 'activity.user_id', 'activity.page_id', $userId, $pageId);
        $where = $whereParts === [] ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $hasUsersTable = InterfaceDB::tableExists('users');
        $userDisplaySelect = $hasUsersTable ? 'COALESCE(users.display_name, \'\')' : '\'\'';
        $userJoin = $hasUsersTable
            ? ' LEFT JOIN users
                ON users.id = activity.user_id'
            : '';

        return InterfaceDB::fetchAll(
            'SELECT
                activity.id,
                activity.user_id,
                activity.page_id,
                activity.action_name,
                activity.card_action_name,
                activity.message_type,
                activity.message_text,
                activity.message_html_text,
                activity.request_method,
                activity.is_ajax,
                activity.device_id,
                activity.ip_address,
                activity.user_agent,
                activity.request_uri,
                activity.occurred_at,
                ' . $userDisplaySelect . ' AS user_display_name
             FROM application_activity_flash_history activity
             ' . $userJoin . '
             ' . $where . '
             ORDER BY activity.occurred_at DESC, activity.id DESC
             LIMIT ' . $limit,
            $params
        );
    }

    /**
     * @param array<int, string> $whereParts
     * @param array<string, mixed> $params
     */
    private function appendActivityFilters(
        array &$whereParts,
        array &$params,
        string $userColumn,
        string $pageColumn,
        int $userId,
        string $pageId
    ): void {
        if ($userId > 0) {
            $whereParts[] = $userColumn . ' = :user_id';
            $params['user_id'] = $userId;
        }

        $pageId = trim($pageId);
        if ($pageId !== '' && $pageColumn !== '') {
            $whereParts[] = $pageColumn . ' = :page_id';
            $params['page_id'] = $pageId;
        }
    }


    public function fetchRecentLogonHistory(int $limit = 100, int $userId = 0): array
    {
        if (!InterfaceDB::tableExists('user_logon_history')) {
            return [];
        }

        $where = '';
        $params = [];

        if ($userId > 0) {
            $where = ' WHERE history.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        return InterfaceDB::fetchAll(
            'SELECT
                history.id,
                history.user_id,
                history.attempted_email_address,
                history.event_type,
                history.success,
                history.reason,
                history.session_token_hash,
                history.device_id,
                history.ip_address,
                history.user_agent,
                history.browser_label,
                history.occurred_at,
                COALESCE(users.display_name, \'\') AS user_display_name
             FROM user_logon_history history
             LEFT JOIN users
                ON users.id = history.user_id
             ' . $where . '
             ORDER BY history.occurred_at DESC, history.id DESC
             LIMIT ' . FormattingFramework::normaliseLimit($limit),
            $params
        );
    }

}
