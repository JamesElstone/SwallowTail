<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TimezoneSettingsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUpdate($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['timezone.settings'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update timezone settings, or your security token expired.',
            ]]);
        }

        $timezone = trim((string)$request->input('server_timezone', ''));
        try {
            AppConfigurationStore::setTimezoneSettings([
                'server' => $timezone,
                'daylight_saving' => [
                    'enabled' => $request->input('daylight_saving_enabled', '') === '1',
                    'start' => trim((string)$request->input('daylight_saving_start', '')),
                    'end' => trim((string)$request->input('daylight_saving_end', '')),
                    'offset_minutes' => (int)$request->input('daylight_saving_offset_minutes', 60),
                ],
            ]);
        } catch (RuntimeException $exception) {
            return new ActionResultFramework(false, ['timezone.settings'], [[
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]]);
        }

        return ActionResultFramework::success(['timezone.settings'], [[
            'type' => 'success',
            'message' => 'Timezone settings updated. Server timezone is ' . $timezone . '.',
        ]]);
    }

    private function canUpdate(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('timezone_settings', (new CardAccessFramework())->allowedCardsForUser($userId, ['timezone_settings']), true);
    }
}
