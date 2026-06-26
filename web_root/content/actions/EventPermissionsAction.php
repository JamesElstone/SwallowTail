<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class EventPermissionsAction implements ActionInterfaceFramework
{
    public function __construct(
        private readonly SwallowtailEventManagementService $eventService = new SwallowtailEventManagementService(),
    ) {
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();
        $userId = $this->currentUserId($session);

        if (
            $userId <= 0
            || !in_array('event_permissions', (new CardAccessFramework())->allowedCardsForUser($userId, ['event_permissions']), true)
            || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))
        ) {
            return $this->error('You do not have permission to manage event permissions, or your security token expired.');
        }

        try {
            return match (strtolower(trim((string)$request->input('event_permissions_action', '')))) {
                'create_event' => $this->createEvent($request, $userId),
                'select_event' => $this->selectEvent($request),
                'set_grant' => $this->setGrant($request, $userId),
                'search_users' => $this->searchUsers($request),
                'add_user' => $this->addUser($request, $userId),
                'assign_photos' => $this->assignPhotos($request, $userId),
                default => $this->error('Event permission action was not recognised.'),
            };
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), $this->eventQuery($request));
        }
    }

    private function createEvent(RequestFramework $request, int $userId): ActionResultFramework
    {
        $event = $this->eventService->createEvent((string)$request->input('event_name', ''), $userId);

        return $this->success('Event created.', ['event_id' => (int)$event['id']]);
    }

    private function selectEvent(RequestFramework $request): ActionResultFramework
    {
        return $this->success('', $this->eventQuery($request), []);
    }

    private function setGrant(RequestFramework $request, int $userId): ActionResultFramework
    {
        $eventId = (int)$request->input('event_id', 0);
        $this->eventService->setPermission(
            $eventId,
            (string)$request->input('grantee_type', ''),
            (int)$request->input('grantee_id', 0),
            $this->permissionsFromRequest($request),
            $userId
        );

        return $this->success('Event permissions updated.', ['event_id' => $eventId]);
    }

    private function searchUsers(RequestFramework $request): ActionResultFramework
    {
        return $this->success('', [
            'event_id' => (int)$request->input('event_id', 0),
            'user_search' => (string)$request->input('event_user_search', ''),
            'show_user_search' => true,
        ], []);
    }

    private function addUser(RequestFramework $request, int $userId): ActionResultFramework
    {
        $eventId = (int)$request->input('event_id', 0);
        $this->eventService->addUserViewPermission($eventId, (int)$request->input('user_id', 0), $userId);

        return $this->success('User permission added.', [
            'event_id' => $eventId,
            'show_user_search' => true,
        ]);
    }

    private function assignPhotos(RequestFramework $request, int $userId): ActionResultFramework
    {
        $photoIds = $request->input('photo_ids', []);
        $photoIds = is_array($photoIds) ? $photoIds : [$photoIds];
        $eventId = (int)$request->input('assignment_event_id', 0);
        $assigned = (string)$request->input('assignment_state', '1') === '1';

        $this->eventService->assignPhotosToEvent($photoIds, $eventId, $assigned, $userId);

        return $this->success('Photo event tags updated.', ['event_id' => (int)$request->input('event_id', 0)], [], ['browse.gallery']);
    }

    private function permissionsFromRequest(RequestFramework $request): array
    {
        return [
            'can_view' => $this->checkboxValue($request, 'can_view'),
            'can_edit' => $this->checkboxValue($request, 'can_edit'),
            'can_download_single_jpeg' => $this->checkboxValue($request, 'can_download_single_jpeg'),
            'can_download_event_zip' => $this->checkboxValue($request, 'can_download_event_zip'),
            'can_download_all_accessible' => $this->checkboxValue($request, 'can_download_all_accessible'),
            'can_download_original_raw' => $this->checkboxValue($request, 'can_download_original_raw'),
        ];
    }

    private function checkboxValue(RequestFramework $request, string $name): bool
    {
        $value = $request->input($name, '0');
        if (is_array($value)) {
            $value = end($value);
        }

        return (string)$value === '1';
    }

    private function eventQuery(RequestFramework $request): array
    {
        return ['event_id' => (int)$request->input('event_id', 0)];
    }

    private function success(string $message, array $query = [], array $messages = [], array $facts = ['event.permissions']): ActionResultFramework
    {
        if ($message !== '') {
            $messages[] = [
                'type' => 'success',
                'message' => $message,
            ];
        }

        return ActionResultFramework::success($facts, $messages, $query);
    }

    private function error(string $message, array $query = []): ActionResultFramework
    {
        return new ActionResultFramework(false, ['event.permissions'], [[
            'type' => 'error',
            'message' => $message,
        ]], $query);
    }

    private function currentUserId(SessionAuthenticationService $session): int
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId);
    }
}
