<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailEventManagementService;

final class _event_permissionsCard extends CardBaseFramework
{
    private const PERMISSIONS = [
        'can_view' => 'View',
        'can_edit' => 'Edit',
        'can_download_single_jpeg' => 'JPEG',
        'can_download_event_zip' => 'ZIP',
        'can_download_all_accessible' => 'All',
        'can_download_original_raw' => 'RAW',
    ];

    public function key(): string
    {
        return 'event_permissions';
    }

    public function title(): string
    {
        return 'Permissions';
    }

    public function helper(array $context): string
    {
        return 'Grant event access to roles or individual users.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['event.permissions'];
    }

    public function render(array $context): string
    {
        $service = new SwallowtailEventManagementService();
        $events = $service->listEvents();
        $selectedEventId = (int)($context['page']['selected_event_id'] ?? 0);
        if ($selectedEventId <= 0 && $events !== []) {
            $selectedEventId = (int)($events[0]['id'] ?? 0);
        }

        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $html = '<div class="event-permissions">';
        $html .= $this->eventToolbar($context, $events, $selectedEventId, $csrfToken);

        if ($selectedEventId <= 0) {
            return $html . '<p class="helper">Create an event before assigning permissions.</p></div>';
        }

        $event = $service->eventById($selectedEventId);
        if ($event === null) {
            return $html . '<div class="panel-soft warn">The selected event was not found.</div></div>';
        }

        $html .= '<div class="event-permissions-summary">
            <strong>' . HelperFramework::escape((string)($event['event_name'] ?? 'Event')) . '</strong>
            <span class="helper">' . HelperFramework::escape((string)((int)($event['photo_count'] ?? 0))) . ' photos</span>
        </div>';
        $html .= $this->rolePermissions($context, $service->rolePermissionRows($selectedEventId), $selectedEventId, $csrfToken);
        $html .= $this->userPermissions($context, $service, $selectedEventId, $csrfToken);

        return $html . '</div>';
    }

    private function eventToolbar(array $context, array $events, int $selectedEventId, string $csrfToken): string
    {
        $options = '';
        foreach ($events as $event) {
            $eventId = (int)($event['id'] ?? 0);
            $options .= '<option value="' . HelperFramework::escape((string)$eventId) . '"'
                . ($eventId === $selectedEventId ? ' selected' : '')
                . '>' . HelperFramework::escape((string)($event['event_name'] ?? 'Event')) . '</option>';
        }

        return '<div class="event-permissions-toolbar">
            <form method="post" action="?page=events" data-ajax="true" class="event-select-form">
                ' . $this->hiddenFields($context, $csrfToken, 'select_event') . '
                <label>
                    <span>Event</span>
                    <select class="input" name="event_id" data-submit-on-change="true">' . $options . '</select>
                </label>
            </form>
            <form method="post" action="?page=events" data-ajax="true" class="event-create-form">
                ' . $this->hiddenFields($context, $csrfToken, 'create_event') . '
                <label>
                    <span>Create Event</span>
                    <input class="input" name="event_name" type="text" required>
                </label>
                <button class="button button-inline primary" type="submit">Create</button>
            </form>
        </div>';
    }

    private function rolePermissions(array $context, array $rows, int $eventId, string $csrfToken): string
    {
        $html = '<section class="event-permission-section">
            <div class="status-head">
                <div>
                    <h3>Role Permissions</h3>
                    <p class="helper">Role grants apply to everyone currently assigned to that role.</p>
                </div>
            </div>
            <div class="event-permission-grid">';

        if ($rows === []) {
            return $html . '<div class="panel-soft">No roles are available yet. Create a role before assigning role-based event permissions.</div></div></section>';
        }

        foreach ($rows as $row) {
            $html .= $this->permissionRow(
                $context,
                $eventId,
                'role',
                (int)($row['grantee_id'] ?? 0),
                (string)($row['role_name'] ?? 'Role'),
                (string)((int)($row['assigned_user_count'] ?? 0)) . ' users affected',
                $row,
                $csrfToken,
                ''
            );
        }

        return $html . '</div></section>';
    }

    private function userPermissions(array $context, SwallowtailEventManagementService $service, int $eventId, string $csrfToken): string
    {
        $rows = $service->userPermissionRows($eventId);
        $search = trim((string)(($context[$this->key()] ?? [])['user_search'] ?? ''));
        $showSearch = !empty(($context[$this->key()] ?? [])['show_user_search']) || $search !== '';
        $html = '<section class="event-permission-section">
            <div class="status-head">
                <div>
                    <h3>User Permissions</h3>
                    <p class="helper">Only direct one-off user grants are listed here.</p>
                </div>
                <button class="button button-inline" type="button" data-event-user-picker-toggle>+ Add User Permissions</button>
            </div>
            <div class="event-user-picker"' . ($showSearch ? '' : ' hidden') . ' data-event-user-picker>
                ' . $this->userSearchForm($context, $eventId, $csrfToken, $search)
                . $this->userSearchResults($context, $service->searchUsers($eventId, $search), $eventId, $csrfToken)
            . '</div>';

        if ($rows === []) {
            $html .= '<p class="helper">No direct user permissions have been added for this event.</p>';
        } else {
            $html .= '<div class="event-permission-grid">';
            foreach ($rows as $row) {
                $inherited = $this->inheritedSummary((array)($row['inherited_permissions'] ?? []));
                $html .= $this->permissionRow(
                    $context,
                    $eventId,
                    'user',
                    (int)($row['grantee_id'] ?? 0),
                    $this->userLabel($row),
                    trim((string)($row['role_name'] ?? '')) !== '' ? 'Role: ' . (string)$row['role_name'] : '',
                    $row,
                    $csrfToken,
                    $inherited
                );
            }
            $html .= '</div>';
        }

        return $html . '</section>';
    }

    private function permissionRow(
        array $context,
        int $eventId,
        string $granteeType,
        int $granteeId,
        string $title,
        string $meta,
        array $permissions,
        string $csrfToken,
        string $inherited
    ): string {
        $toggles = '';
        foreach (self::PERMISSIONS as $key => $label) {
            $id = 'event-permission-' . $granteeType . '-' . $granteeId . '-' . $key;
            $toggles .= '<label class="event-permission-toggle" for="' . HelperFramework::escape($id) . '">
                <input id="' . HelperFramework::escape($id) . '" type="checkbox" name="' . HelperFramework::escape($key) . '" value="1"' . (!empty($permissions[$key]) ? ' checked' : '') . '>
                <span>' . HelperFramework::escape($label) . '</span>
            </label>';
        }

        return '<form method="post" action="?page=events" data-ajax="true" class="event-permission-row">
            ' . $this->hiddenFields($context, $csrfToken, 'set_grant', $eventId) . '
            <input type="hidden" name="grantee_type" value="' . HelperFramework::escape($granteeType) . '">
            <input type="hidden" name="grantee_id" value="' . HelperFramework::escape((string)$granteeId) . '">
            <div class="event-permission-row-head">
                <strong>' . HelperFramework::escape($title) . '</strong>
                ' . (trim($meta) !== '' ? '<span class="helper">' . HelperFramework::escape($meta) . '</span>' : '') . '
                ' . ($inherited !== '' ? '<span class="helper">' . HelperFramework::escape($inherited) . '</span>' : '') . '
            </div>
            <div class="event-permission-toggles">' . $toggles . '</div>
            <button class="button button-inline primary" type="submit">Save</button>
        </form>';
    }

    private function userSearchForm(array $context, int $eventId, string $csrfToken, string $search): string
    {
        return '<form method="post" action="?page=events" data-ajax="true" class="event-user-search-form">
            ' . $this->hiddenFields($context, $csrfToken, 'search_users', $eventId) . '
            <label>
                <span>Find User</span>
                <input class="input" name="event_user_search" type="search" value="' . HelperFramework::escape($search) . '" placeholder="Name or email">
            </label>
            <button class="button button-inline" type="submit">Search</button>
        </form>';
    }

    private function userSearchResults(array $context, array $rows, int $eventId, string $csrfToken): string
    {
        if ($rows === []) {
            return '<p class="helper">Search for at least two characters to add a direct user grant.</p>';
        }

        $html = '<div class="event-user-search-results">';
        foreach ($rows as $row) {
            $html .= '<form method="post" action="?page=events" data-ajax="true" class="event-user-search-result">
                ' . $this->hiddenFields($context, $csrfToken, 'add_user', $eventId) . '
                <input type="hidden" name="user_id" value="' . HelperFramework::escape((string)((int)($row['id'] ?? 0))) . '">
                <span><strong>' . HelperFramework::escape($this->userLabel($row)) . '</strong>'
                    . (trim((string)($row['role_name'] ?? '')) !== '' ? '<span class="helper">Role: ' . HelperFramework::escape((string)$row['role_name']) . '</span>' : '')
                . '</span>
                <button class="button button-inline primary" type="submit">Add View</button>
            </form>';
        }

        return $html . '</div>';
    }

    private function hiddenFields(array $context, string $csrfToken, string $action, int $eventId = 0): string
    {
        if ($eventId <= 0) {
            $eventId = (int)($context['page']['selected_event_id'] ?? 0);
        }

        return '<input type="hidden" name="card_action" value="EventPermissions">
            <input type="hidden" name="event_permissions_action" value="' . HelperFramework::escape($action) . '">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <input type="hidden" name="cards[]" value="event_permissions">
            <input type="hidden" name="event_id" value="' . HelperFramework::escape((string)$eventId) . '">';
    }

    private function userLabel(array $row): string
    {
        $displayName = trim((string)($row['display_name'] ?? ''));
        $email = trim((string)($row['email_address'] ?? ''));
        if ($displayName !== '' && $email !== '') {
            return $displayName . ' <' . $email . '>';
        }

        return $displayName !== '' ? $displayName : ($email !== '' ? $email : 'User #' . (string)((int)($row['id'] ?? $row['user_id'] ?? 0)));
    }

    private function inheritedSummary(array $permissions): string
    {
        $labels = [];
        foreach (self::PERMISSIONS as $key => $label) {
            if (!empty($permissions[$key])) {
                $labels[] = $label;
            }
        }

        return $labels === [] ? '' : 'Inherited: ' . implode(', ', $labels);
    }
}
