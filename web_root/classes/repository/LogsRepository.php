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
        $rows = array_merge(
            $this->fetchApplicationActivityRows($limit, $userId, $pageId),
            $this->fetchUploadTokenActivityRows($limit, $userId, $pageId)
        );

        usort($rows, static function (array $left, array $right): int {
            $timeCompare = strcmp((string)($right['occurred_at'] ?? ''), (string)($left['occurred_at'] ?? ''));
            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0);
        });

        return array_slice($rows, 0, $limit);
    }

    private function fetchApplicationActivityRows(int $limit, int $userId, string $pageId): array
    {
        if (!InterfaceDB::tableExists('application_activity_flash_history')) {
            return [];
        }

        $whereParts = [];
        $params = [];
        $this->appendActivityFilters($whereParts, $params, 'activity.user_id', 'activity.page_id', $userId, $pageId);
        $where = $whereParts === [] ? '' : ' WHERE ' . implode(' AND ', $whereParts);

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
                COALESCE(users.display_name, \'\') AS user_display_name
             FROM application_activity_flash_history activity
             LEFT JOIN users
                ON users.id = activity.user_id
             ' . $where . '
             ORDER BY activity.occurred_at DESC, activity.id DESC
             LIMIT ' . $limit,
            $params
        );
    }

    private function fetchUploadTokenActivityRows(int $limit, int $userId, string $pageId): array
    {
        if (!InterfaceDB::tableExists('user_account_audit') || !InterfaceDB::tableExists('users')) {
            return [];
        }

        $pageId = trim($pageId);
        if ($pageId !== '' && $pageId !== 'api') {
            return [];
        }

        $whereParts = ["audit.action_type LIKE 'upload_token_%'"];
        $params = [];
        $this->appendActivityFilters($whereParts, $params, 'audit.affected_user_id', '', $userId, '');
        $where = ' WHERE ' . implode(' AND ', $whereParts);
        $auditRows = InterfaceDB::fetchAll(
            'SELECT
                audit.id,
                audit.affected_user_id,
                audit.action_type,
                audit.reason,
                audit.details_json,
                audit.device_id,
                audit.ip_address,
                audit.user_agent,
                audit.created_at,
                COALESCE(users.display_name, \'\') AS user_display_name
             FROM user_account_audit audit
             LEFT JOIN users
                ON users.id = audit.affected_user_id
             ' . $where . '
             ORDER BY audit.created_at DESC, audit.id DESC
             LIMIT ' . $limit,
            $params
        );

        return array_map(
            fn(array $row): array => $this->uploadTokenAuditRowToActivity($row),
            $auditRows
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

    private function uploadTokenAuditRowToActivity(array $row): array
    {
        $details = json_decode((string)($row['details_json'] ?? ''), true);
        $details = is_array($details) ? $details : [];
        $actionType = (string)($row['action_type'] ?? '');
        $success = array_key_exists('success', $details)
            ? !empty($details['success'])
            : !str_ends_with($actionType, '_failed');

        return [
            'id' => (int)($row['id'] ?? 0),
            'user_id' => (int)($row['affected_user_id'] ?? 0),
            'page_id' => 'api',
            'action_name' => $this->uploadTokenActivityAction($actionType),
            'card_action_name' => $this->uploadTokenActivityDetail($details),
            'message_type' => $success ? 'success' : 'error',
            'message_text' => $this->uploadTokenActivityMessage($row, $details),
            'message_html_text' => null,
            'request_method' => $this->uploadTokenActivityMethod($actionType),
            'is_ajax' => 0,
            'device_id' => $row['device_id'] ?? null,
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'request_uri' => $this->uploadTokenActivityUri($actionType),
            'occurred_at' => $row['created_at'] ?? null,
            'user_display_name' => $row['user_display_name'] ?? '',
        ];
    }

    private function uploadTokenActivityAction(string $actionType): string
    {
        $label = preg_replace('/^upload_token_/', '', $actionType) ?? $actionType;
        $label = str_replace('_', ' ', $label);

        return trim($label) !== '' ? $label : 'upload token';
    }

    private function uploadTokenActivityDetail(array $details): ?string
    {
        $tokenLabel = trim((string)($details['token_label'] ?? ''));

        return $tokenLabel !== '' ? $tokenLabel : null;
    }

    private function uploadTokenActivityMessage(array $row, array $details): string
    {
        $reason = trim((string)($row['reason'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        $failureReason = trim((string)($details['failure_reason'] ?? ''));
        if ($failureReason !== '') {
            return $failureReason;
        }

        return 'Upload token request was recorded.';
    }

    private function uploadTokenActivityMethod(string $actionType): ?string
    {
        return str_contains($actionType, 'raw_upload') ? 'POST' : 'GET';
    }

    private function uploadTokenActivityUri(string $actionType): string
    {
        if (str_contains($actionType, 'raw_upload')) {
            return '/api/raw-upload.php';
        }
        if (str_contains($actionType, 'quick_checksum')) {
            return '/api/quick-checksum.php';
        }
        if (str_contains($actionType, 'conversion_status')) {
            return '/api/conversion-status.php';
        }
        if (str_contains($actionType, 'ping')) {
            return '/api/ping.php';
        }

        return '/api';
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
