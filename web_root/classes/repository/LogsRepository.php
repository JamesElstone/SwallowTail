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

    public function fetchRecentPhotoAudit(int $limit = 100): array
    {
        if (!InterfaceDB::tableExists('photo_audit')) {
            return [];
        }

        $hasPhotos = InterfaceDB::tableExists('photos');
        $hasEvents = InterfaceDB::tableExists('events');
        $hasUsers = InterfaceDB::tableExists('users');
        $hasUploadTokens = InterfaceDB::tableExists('api_upload_tokens');

        $photoSelect = $hasPhotos ? 'COALESCE(photo.original_filename, \'\')' : '\'\'';
        $eventSelect = $hasEvents ? 'COALESCE(event.event_name, \'\')' : '\'\'';
        $actorSelect = $hasUsers ? 'COALESCE(actor.display_name, \'\')' : '\'\'';
        $tokenSelect = $hasUploadTokens ? 'COALESCE(token.token_label, \'\')' : '\'\'';
        $joins = '';

        if ($hasPhotos) {
            $joins .= ' LEFT JOIN photos photo
                ON photo.id = audit.photo_id';
        }
        if ($hasEvents) {
            $joins .= ' LEFT JOIN events event
                ON event.id = audit.event_id';
        }
        if ($hasUsers) {
            $joins .= ' LEFT JOIN users actor
                ON actor.id = audit.actor_user_id';
        }
        if ($hasUploadTokens) {
            $joins .= ' LEFT JOIN api_upload_tokens token
                ON token.id = audit.upload_token_id';
        }

        return InterfaceDB::fetchAll(
            'SELECT
                audit.id,
                audit.photo_id,
                audit.event_id,
                audit.actor_user_id,
                audit.upload_token_id,
                audit.action_type,
                audit.details_json,
                audit.device_id,
                audit.ip_address,
                audit.user_agent,
                audit.occurred_at,
                ' . $photoSelect . ' AS original_filename,
                ' . $eventSelect . ' AS event_name,
                ' . $actorSelect . ' AS actor_user_display_name,
                ' . $tokenSelect . ' AS upload_token_label
             FROM photo_audit audit
             ' . $joins . '
             ORDER BY audit.occurred_at DESC, audit.id DESC
             LIMIT ' . FormattingFramework::normaliseLimit($limit)
        );
    }
}
