<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class StorageSettingsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canUpdate($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['storage.available'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update storage settings, or your security token expired.',
            ]]);
        }

        if ((string)$request->input('storage_settings_action', 'update_settings') === 'set_location_excluded') {
            $storageBaseLocation = trim((string)$request->input('storage_base_location', ''));
            if ($storageBaseLocation === '') {
                return new ActionResultFramework(false, ['storage.available'], [[
                    'type' => 'error',
                    'message' => 'Storage location was not supplied.',
                ]]);
            }

            (new SwallowtailStorageLocationService())->setExcluded(
                $storageBaseLocation,
                $this->checkboxValue($request, 'is_excluded')
            );

            return ActionResultFramework::success(['storage.available'], [[
                'type' => 'success',
                'message' => $this->checkboxValue($request, 'is_excluded')
                    ? 'Storage location excluded from new writes.'
                    : 'Storage location returned to new writes.',
            ]]);
        }

        AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', $this->checkboxValue($request, 'store_on_root_partition'));
        AppConfigurationStore::set('swallowtail.storage.round_robin_locations', $this->checkboxValue($request, 'round_robin_locations'));
        AppConfigurationStore::set(
            'swallowtail.storage.full_threshold_percent',
            max(0.0, min(100.0, (float)$request->input('full_threshold_percent', 5)))
        );

        return ActionResultFramework::success(['storage.available'], [[
            'type' => 'success',
            'message' => 'Storage settings updated.',
        ]]);
    }

    private function canUpdate(SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0 && in_array('storage_available', (new CardAccessFramework())->allowedCardsForUser($userId, ['storage_available']), true);
    }

    private function checkboxValue(RequestFramework $request, string $name): bool
    {
        $value = $request->input($name, '0');
        if (is_array($value)) {
            $value = end($value);
        }

        return (string)$value === '1';
    }
}
