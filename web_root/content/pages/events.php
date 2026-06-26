<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _events extends PageContextFramework
{
    public function id(): string
    {
        return 'events';
    }

    public function title(): string
    {
        return 'Events';
    }

    public function subtitle(): string
    {
        return 'Manage event access for roles and individual users.';
    }

    public function cards(): array
    {
        return [
            'event_permissions',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $eventService = new SwallowtailEventManagementService();
        $selectedEventId = (int)($actionResult->query()['event_id'] ?? $request->input('event_id', 0));
        if ($selectedEventId <= 0) {
            $selectedEventId = $eventService->defaultEventId();
        }

        return [
            'page' => [
                'page_id' => 'events',
                'page_cards' => $this->cards(),
                'csrf_token' => $sessionAuthenticationService->csrfToken(),
                'selected_event_id' => $selectedEventId,
            ],
            'event_permissions' => [
                'user_search' => (string)($actionResult->query()['user_search'] ?? $request->input('event_user_search', '')),
                'show_user_search' => (bool)($actionResult->query()['show_user_search'] ?? false),
            ],
        ];
    }
}
