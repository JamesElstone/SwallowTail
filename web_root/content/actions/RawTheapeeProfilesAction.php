<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class RawTheapeeProfilesAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();
        $userId = $this->currentUserId($session);
        if ($userId <= 0 || !$this->canAccess($userId) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['rawtheapee.profiles'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update RawTheapee profiles, or your security token expired.',
            ]]);
        }

        $service = new SwallowtailRawTheapeeProfileService();
        $profileId = max(0, (int)$request->input('rawtheapee_profile_id', 0));
        $photoId = max(0, (int)$request->input('rawtheapee_photo_id', 0));
        $context = [
            'profile_id' => $profileId,
            'photo_id' => $photoId,
        ];

        $action = (string)$request->input('rawtheapee_profiles_action', '');
        if ($action === 'refresh') {
            $ok = $service->requestRefresh();
            return ActionResultFramework::success(['rawtheapee.profiles'], [[
                'type' => $ok ? 'success' : 'error',
                'message' => $ok ? 'RawTheapee profile refresh requested.' : 'Unable to request RawTheapee profile refresh.',
            ]], [], ['rawtheapee_profiles' => $context]);
        }

        if ($action === 'test') {
            $result = $service->enqueueSample($photoId, $profileId, $userId);
            $context['sample'] = $result;
            return new ActionResultFramework(!empty($result['success']), ['rawtheapee.profiles'], [[
                'type' => !empty($result['success']) ? 'success' : 'error',
                'message' => (string)($result['message'] ?? (!empty($result['success']) ? 'RawTheapee sample queued.' : 'RawTheapee sample could not be queued.')),
            ]], [], ['rawtheapee_profiles' => $context]);
        }

        return new ActionResultFramework(false, ['rawtheapee.profiles'], [[
            'type' => 'error',
            'message' => 'Unknown RawTheapee profiles action.',
        ]], [], ['rawtheapee_profiles' => $context]);
    }

    private function currentUserId(SessionAuthenticationService $session): int
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId);
    }

    private function canAccess(int $userId): bool
    {
        return in_array('rawtheapee_profiles', (new CardAccessFramework())->allowedCardsForUser($userId, ['rawtheapee_profiles']), true);
    }
}
