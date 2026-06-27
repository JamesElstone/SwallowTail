<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class InternalProfilesAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();
        if (!$this->canAccess($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['internal.profiles'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update internal profiles, or your security token expired.',
            ]]);
        }

        $service = new SwallowtailInternalProfilesService();
        $action = (string)$request->input('internal_profiles_action', '');
        $context = [
            'image_type' => $service->normaliseImageType((string)$request->input('internal_profiles_image_type', 'preview')),
            'profile_name' => $service->normaliseProfileName((string)$request->input('internal_profiles_profile_name', 'default')),
        ];

        try {
            if ($action === 'add_profile') {
                $newName = $service->normaliseProfileName((string)$request->input('internal_profiles_new_profile_name', (string)$context['profile_name']));
                $context['profile_name'] = $newName;
                $context['draft'] = true;
                return ActionResultFramework::success(['internal.profiles'], [], [], ['internal_profiles' => $context]);
            }

            if ($action === 'save_row') {
                $context = $service->saveRow(
                    max(0, (int)$request->input('internal_profile_id', 0)),
                    (string)$context['image_type'],
                    (string)$context['profile_name'],
                    (string)$request->input('internal_profile_type', ''),
                    (string)$request->input('internal_profile_key', ''),
                    $request->input('internal_profile_value', ''),
                    (string)$request->input('internal_profile_value_type', 'string')
                );
                return ActionResultFramework::success(['internal.profiles'], [[
                    'type' => 'success',
                    'message' => 'Internal profile row saved.',
                ]], [], ['internal_profiles' => $context]);
            }

            if ($action === 'move_profile') {
                $moved = $service->moveProfile(
                    max(0, (int)$request->input('internal_profiles_move_id', $request->input('internal_profile_id', 0))),
                    (string)$request->input('internal_profiles_move_direction', '')
                );
                return ActionResultFramework::success(['internal.profiles'], [], [], ['internal_profiles' => $moved ?? $context]);
            }
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['internal.profiles'], [[
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]], [], ['internal_profiles' => $context]);
        }

        return new ActionResultFramework(false, ['internal.profiles'], [[
            'type' => 'error',
            'message' => 'Unknown internal profile action.',
        ]], [], ['internal_profiles' => $context]);
    }

    private function canAccess(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('internal_profiles', (new CardAccessFramework())->allowedCardsForUser($userId, ['internal_profiles']), true);
    }
}
