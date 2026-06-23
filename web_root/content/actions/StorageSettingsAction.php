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

        $storageAction = (string)$request->input('storage_settings_action', 'update_settings');

        if ($storageAction === 'set_location_excluded') {
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

        if ($storageAction === 'set_zpool_dataset') {
            return $this->setZpoolDataset($request, $session);
        }

        if ($storageAction === 'request_migrate_location') {
            return $this->requestMigrateLocation($request, $session);
        }

        if ($storageAction === 'fix_permissions') {
            return $this->fixPermissions($request);
        }

        AppConfigurationStore::set('swallowtail.storage.store_on_root_partition', $this->checkboxValue($request, 'store_on_root_partition'));
        AppConfigurationStore::set('swallowtail.storage.round_robin_locations', $this->checkboxValue($request, 'round_robin_locations'));
        AppConfigurationStore::set(
            'swallowtail.storage.full_threshold_percent',
            max(0.0, min(100.0, (float)$request->input('full_threshold_percent', 5)))
        );
        AppConfigurationStore::set(
            'swallowtail.storage.storage_blocked_poll_interval_seconds',
            $this->clampedPollIntervalSeconds($request->input('storage_blocked_poll_interval_seconds', 3600))
        );
        (new SwallowtailStorageCacheService())->clear();

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

    private function setZpoolDataset(RequestFramework $request, SessionAuthenticationService $session): ActionResultFramework
    {
        $zpoolName = trim((string)$request->input('zpool_name', ''));
        $datasetName = trim((string)$request->input('dataset_name', ''));
        if ($zpoolName === '' || $datasetName === '') {
            return new ActionResultFramework(false, ['storage.available'], [[
                'type' => 'error',
                'message' => 'Zpool and dataset were not supplied.',
            ]]);
        }

        $storage = new SwallowtailStorageService();
        $snapshot = $storage->storageSnapshot(true);
        $oldMount = '';
        $newMount = '';
        $oldDataset = '';
        foreach ((array)($snapshot['zpools'] ?? []) as $zpool) {
            if ((string)($zpool['zpool_name'] ?? '') !== $zpoolName) {
                continue;
            }
            $oldMount = (string)($zpool['selected_mountpoint'] ?? '');
            $oldDataset = (string)($zpool['selected_dataset_name'] ?? '');
            foreach ((array)($zpool['datasets'] ?? []) as $dataset) {
                if ((string)($dataset['dataset_name'] ?? '') === $datasetName) {
                    $newMount = (string)($dataset['mountpoint'] ?? '');
                    break;
                }
            }
            break;
        }

        if ($newMount === '') {
            return new ActionResultFramework(false, ['storage.available'], [[
                'type' => 'error',
                'message' => 'Selected ZFS dataset is not mounted and cannot be used.',
            ]]);
        }

        $storage->setZpoolDataset($zpoolName, $datasetName);
        $jobId = null;
        if ($oldMount !== '' && $oldMount !== $newMount && $oldDataset !== '') {
            $jobId = (new SwallowtailStorageMigrationService())->enqueueIfPhotosExist(
                $oldMount,
                $newMount,
                $zpoolName,
                $datasetName,
                $this->currentUserId($session)
            );
        }

        return ActionResultFramework::success(['storage.available'], [[
            'type' => 'success',
            'message' => $jobId === null
                ? 'Zpool active dataset updated.'
                : 'Zpool active dataset updated. Existing files will be migrated by the storage service.',
        ]]);
    }

    private function requestMigrateLocation(RequestFramework $request, SessionAuthenticationService $session): ActionResultFramework
    {
        $storageBaseLocation = trim((string)$request->input('storage_base_location', ''));
        if ($storageBaseLocation === '') {
            return new ActionResultFramework(false, ['storage.available'], [[
                'type' => 'error',
                'message' => 'Storage location was not supplied.',
            ]]);
        }

        $jobId = (new SwallowtailStorageMigrationService())->enqueueIfPhotosExist(
            $storageBaseLocation,
            null,
            null,
            null,
            $this->currentUserId($session)
        );

        return ActionResultFramework::success(['storage.available'], [[
            'type' => $jobId === null ? 'success' : 'success',
            'message' => $jobId === null
                ? 'No photos currently use that storage location.'
                : 'Storage migration queued. Files will be moved by the storage service.',
        ]]);
    }

    private function fixPermissions(RequestFramework $request): ActionResultFramework
    {
        $storageBaseLocation = trim((string)$request->input('storage_base_location', ''));
        if ($storageBaseLocation === '') {
            return new ActionResultFramework(false, ['storage.available'], [[
                'type' => 'error',
                'message' => 'Storage location was not supplied.',
            ]]);
        }

        try {
            $repair = (new SwallowtailStoragePermissionRepairService())->repair($storageBaseLocation);
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['storage.available'], [[
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]]);
        }

        return ActionResultFramework::success(['storage.available', 'cr2.upload'], [[
            'type' => 'success',
            'message' => 'Storage permission repair completed for ' . (string)$repair['base'] . '.',
        ]]);
    }

    private function currentUserId(SessionAuthenticationService $session): int
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId);
    }

    private function checkboxValue(RequestFramework $request, string $name): bool
    {
        $value = $request->input($name, '0');
        if (is_array($value)) {
            $value = end($value);
        }

        return (string)$value === '1';
    }

    private function clampedPollIntervalSeconds(mixed $value): int
    {
        return max(60, min(86400, (int)$value));
    }
}
