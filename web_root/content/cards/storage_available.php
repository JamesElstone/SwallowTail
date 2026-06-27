<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _storage_availableCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'storage_available';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['storage.available', 'cr2.upload'];
    }

    public function title(): string
    {
        return 'Available Storage';
    }

    public function helper(array $context): string
    {
        return 'Writable SwallowTail storage locations and current free-space status.';
    }

    public function render(array $context): string
    {
        try {
            $snapshot = (new SwallowtailStorageService())->storageSnapshot(true);
            $locations = (array)($snapshot['locations'] ?? []);
            $zpools = (array)($snapshot['zpools'] ?? []);
            $cr2CountsByStorageBaseLocation = $this->cr2CountsByStorageBaseLocation();
        } catch (Throwable $exception) {
            return '<div class="panel-soft warn">Storage status is unavailable: ' . HelperFramework::escape($exception->getMessage()) . '</div>';
        }

        $html = $this->settingsForm($context);

        if ($locations === [] && $zpools === []) {
            return $html . '<p class="helper">No mounted storage locations are available.</p>';
        }

        $html .= $this->storageExhaustedWarning($locations);

        $html .= '<div class="storage-location-grid">';
        foreach ($zpools as $zpool) {
            $html .= $this->zpoolCard((array)$zpool, $context);
        }
        foreach ($locations as $location) {
            $location['cr2_file_count'] = $cr2CountsByStorageBaseLocation[(string)($location['storage_base_location'] ?? '')] ?? 0;
            $html .= $this->locationCard((array)$location, $context);
        }

        return $html . '</div>';
    }

    private function locationCard(array $location, array $context): string
    {
        $permissionCanWrite = array_key_exists('permission_can_write', $location)
            ? !empty($location['permission_can_write'])
            : null;
        $canWrite = !empty($location['can_write']) && $permissionCanWrite !== false;
        $isExcluded = !empty($location['is_excluded']);
        $isFull = !empty($location['is_full']);
        $isZfs = !empty($location['is_zfs']);
        $label = (string)($location['label'] ?? 'Storage location');
        $baseLocation = (string)($location['storage_base_location'] ?? '');
        $availableBytes = $location['available_bytes'] ?? null;
        $totalBytes = $location['total_bytes'] ?? null;
        $freePercent = $location['free_percent'] ?? null;
        $cr2FileCount = max(0, (int)($location['cr2_file_count'] ?? 0));
        $threshold = (float)($location['full_threshold_percent'] ?? 5);
        $permissionError = trim((string)($location['permission_error'] ?? ''));
        $permissionCheckedPath = trim((string)($location['permission_checked_path'] ?? ''));
        $statusClass = $canWrite ? 'success' : 'warning';
        $statusLabel = $this->locationStatusLabel($canWrite, $permissionCanWrite, $isZfs, $isExcluded, $isFull);
        $permissionDetail = $permissionCanWrite === false
            ? '<p class="storage-location-path storage-location-warning">'
                . HelperFramework::escape($permissionError !== '' ? $permissionError : 'PHP cannot write to this storage location.')
                . ($permissionCheckedPath !== '' ? '<br><span>Checked: ' . HelperFramework::escape($permissionCheckedPath) . '</span>' : '')
                . '</p>'
            : '';
        $thresholdDetail = $isFull && $permissionCanWrite !== false
            ? '<p class="storage-location-path storage-location-warning">Free space is below the '
                . HelperFramework::escape(number_format($threshold, 1) . '%')
                . ' threshold. This location is not eligible for new writes.</p>'
            : '';
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $canManageLocationActions = $this->canManageStorageLocationActions($context);
        $fixPermissionAction = $baseLocation === '' || $permissionCanWrite !== false ? '' : '
            <form method="post" action="?page=settings" data-ajax="true" class="storage-location-actions">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="card_action" value="StorageSettings">
                <input type="hidden" name="storage_settings_action" value="fix_permissions">
                <input type="hidden" name="storage_base_location" value="' . HelperFramework::escape($baseLocation) . '">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <button class="button warn" type="submit" data-chicken-check="true" data-chicken-title="Fix storage permissions" data-chicken-message="Run the SwallowTail storage permission repair helper for this location. It only creates or modifies the swallowtail-data directory below the selected storage base." data-chicken-confirm-text="Fix Permissions">Fix Permission Issues</button>
            </form>';
        $excludeAction = !$canManageLocationActions || $isZfs ? '' : '
            <form method="post" action="?page=settings" data-ajax="true" class="storage-location-actions">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="card_action" value="StorageSettings">
                <input type="hidden" name="storage_settings_action" value="set_location_excluded">
                <input type="hidden" name="storage_base_location" value="' . HelperFramework::escape($baseLocation) . '">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <label class="checkbox-row">
                    <input type="hidden" name="is_excluded" value="0">
                    <input type="checkbox" name="is_excluded" value="1"' . ($isExcluded ? ' checked' : '') . ' data-submit-on-change="true">
                    <span>Exclude from new writes</span>
                </label>
            </form>';
        $migrateAction = !$canManageLocationActions || $baseLocation === '' ? '' : '
            <form method="post" action="?page=settings" data-ajax="true" class="storage-location-actions">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="card_action" value="StorageSettings">
                <input type="hidden" name="storage_settings_action" value="request_migrate_location">
                <input type="hidden" name="storage_base_location" value="' . HelperFramework::escape($baseLocation) . '">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <button class="button warn" type="submit" data-chicken-check="true" data-chicken-title="Migrate storage files" data-chicken-message="Move all SwallowTail files from this location to other eligible storage. Existing photos remain available while files are copied and verified." data-chicken-confirm-text="Migrate Files">Migrate Files from this Location</button>
            </form>';
        $actions = $fixPermissionAction . $excludeAction . $migrateAction;

        return '<article class="storage-location-card">
            <div class="storage-location-head">
                <h3>' . HelperFramework::escape($label) . '</h3>
                <span class="badge ' . $statusClass . '">' . HelperFramework::escape($statusLabel) . '</span>
            </div>
            <dl class="storage-location-metrics">
                <div>
                    <dt>Available</dt>
                    <dd>' . HelperFramework::escape($this->formatBytes($availableBytes)) . '</dd>
                </div>
                <div>
                    <dt>Capacity</dt>
                    <dd>' . HelperFramework::escape($this->formatBytes($totalBytes)) . '</dd>
                </div>
                <div>
                    <dt>Free</dt>
                    <dd>' . HelperFramework::escape($freePercent === null ? 'Unknown' : number_format((float)$freePercent, 1) . '%') . '</dd>
                </div>
                <div>
                    <dt>Threshold</dt>
                    <dd>' . HelperFramework::escape(number_format($threshold, 1) . '%') . '</dd>
                </div>
                <div>
                    <dt>CR2 files</dt>
                    <dd>' . HelperFramework::escape((string)$cr2FileCount) . '</dd>
                </div>
            </dl>
            <p class="storage-location-path">' . HelperFramework::escape((string)($location['root_path'] ?? '')) . '</p>
            ' . $permissionDetail . '
            ' . $thresholdDetail . '
            ' . $actions . '
        </article>';
    }

    private function locationStatusLabel(bool $canWrite, ?bool $permissionCanWrite, bool $isZfs, bool $isExcluded, bool $isFull): string
    {
        if ($canWrite) {
            return 'Writable';
        }

        if ($permissionCanWrite === false) {
            return 'Not writable';
        }

        if ($isZfs) {
            return 'ZFS dataset';
        }

        if ($isExcluded) {
            return 'Excluded';
        }

        if ($isFull) {
            return 'Below threshold';
        }

        return 'Unavailable';
    }

    private function zpoolCard(array $zpool, array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $zpoolName = (string)($zpool['zpool_name'] ?? $zpool['storage_base_location'] ?? '');
        $datasets = (array)($zpool['datasets'] ?? []);
        $selectedDataset = (string)($zpool['selected_dataset_name'] ?? '');
        $availableBytes = $zpool['available_bytes'] ?? null;
        $totalBytes = $zpool['total_bytes'] ?? null;
        $freePercent = $zpool['free_percent'] ?? null;
        $options = '';
        foreach ($datasets as $dataset) {
            if (!is_array($dataset)) {
                continue;
            }
            $datasetName = (string)($dataset['dataset_name'] ?? '');
            if ($datasetName === '') {
                continue;
            }
            $options .= '<option value="' . HelperFramework::escape($datasetName) . '"' . ($datasetName === $selectedDataset ? ' selected' : '') . '>'
                . HelperFramework::escape($datasetName)
                . '</option>';
        }

        return '<article class="storage-location-card storage-zpool-card">
            <div class="storage-location-head">
                <h3>' . HelperFramework::escape($zpoolName) . '</h3>
                <span class="badge success">Zpool</span>
            </div>
            <dl class="storage-location-metrics">
                <div>
                    <dt>Available</dt>
                    <dd>' . HelperFramework::escape($this->formatBytes($availableBytes)) . '</dd>
                </div>
                <div>
                    <dt>Capacity</dt>
                    <dd>' . HelperFramework::escape($this->formatBytes($totalBytes)) . '</dd>
                </div>
                <div>
                    <dt>Free</dt>
                    <dd>' . HelperFramework::escape($freePercent === null ? 'Unknown' : number_format((float)$freePercent, 1) . '%') . '</dd>
                </div>
                <div>
                    <dt>Datasets</dt>
                    <dd>' . HelperFramework::escape((string)count($datasets)) . '</dd>
                </div>
            </dl>
            <p class="storage-location-path">' . HelperFramework::escape((string)($zpool['selected_mountpoint'] ?? '')) . '</p>
            <form method="post" action="?page=settings" data-ajax="true" class="storage-location-actions">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="card_action" value="StorageSettings">
                <input type="hidden" name="storage_settings_action" value="set_zpool_dataset">
                <input type="hidden" name="zpool_name" value="' . HelperFramework::escape($zpoolName) . '">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <label>
                    <span>Active dataset</span>
                    <select name="dataset_name" data-submit-on-change="true">' . $options . '</select>
                </label>
            </form>
        </article>';
    }

    private function settingsForm(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $storeOnRoot = (bool)AppConfigurationStore::get('swallowtail.storage.store_on_root_partition', false);
        $roundRobin = (bool)AppConfigurationStore::get('swallowtail.storage.round_robin_locations', false);
        $threshold = (float)AppConfigurationStore::get('swallowtail.storage.full_threshold_percent', 5);
        $blockedPollInterval = (int)AppConfigurationStore::get('swallowtail.storage.storage_blocked_poll_interval_seconds', 3600);

        return '<form method="post" action="?page=settings" data-ajax="true" class="storage-settings-form">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="StorageSettings">
            <input type="hidden" name="storage_settings_action" value="update_settings">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <fieldset class="settings-fieldset storage-settings-fieldset">
                <legend>Storage Settings</legend>
                <div class="storage-settings-row">
                    <label class="checkbox-row">
                        <input type="hidden" name="store_on_root_partition" value="0">
                        <input type="checkbox" name="store_on_root_partition" value="1"' . ($storeOnRoot ? ' checked' : '') . ' data-submit-on-change="true">
                        <span>Store on root partition</span>
                    </label>
                    <label class="checkbox-row">
                        <input type="hidden" name="round_robin_locations" value="0">
                        <input type="checkbox" name="round_robin_locations" value="1"' . ($roundRobin ? ' checked' : '') . ' data-submit-on-change="true">
                        <span>Round-Robin Storage between locations</span>
                    </label>
                    <label>
                        <span>Full threshold</span>
                        <input type="number" name="full_threshold_percent" min="0" max="100" step="0.1" value="' . HelperFramework::escape((string)$threshold) . '" data-submit-on-change="true">
                    </label>
                    <label>
                        <span>Blocked poll interval</span>
                        <input type="number" name="storage_blocked_poll_interval_seconds" min="60" max="86400" step="60" value="' . HelperFramework::escape((string)$blockedPollInterval) . '" data-submit-on-change="true">
                    </label>
                </div>
            </fieldset>
        </form>';
    }

    /**
     * @param array<int, mixed> $locations
     */
    private function storageExhaustedWarning(array $locations): string
    {
        $included = 0;
        $writable = 0;
        $belowThreshold = 0;
        $threshold = (float)AppConfigurationStore::get('swallowtail.storage.full_threshold_percent', 5);

        foreach ($locations as $location) {
            if (!is_array($location) || !empty($location['is_excluded'])) {
                continue;
            }
            if (!empty($location['is_zfs']) && empty($location['is_selected_zfs_dataset'])) {
                continue;
            }

            $included++;
            if (!empty($location['can_write'])) {
                $writable++;
            }
            if (!empty($location['is_full'])) {
                $belowThreshold++;
            }
        }

        if ($included <= 0 || $writable > 0 || $belowThreshold < $included) {
            return '';
        }

        $blockedPollInterval = (int)AppConfigurationStore::get('swallowtail.storage.storage_blocked_poll_interval_seconds', 3600);

        return '<div class="panel-soft warn storage-exhausted-warning">Storage is exhausted for new writes. All included locations are below the configured free-space threshold of '
            . HelperFramework::escape(number_format($threshold, 1) . '%')
            . '. Uploads and conversion jobs will pause until storage is added, files are moved, or the threshold is changed. Conversion workers will check again every '
            . HelperFramework::escape((string)$blockedPollInterval)
            . ' seconds.</div>';
    }

    /**
     * @return array<string, int>
     */
    private function cr2CountsByStorageBaseLocation(): array
    {
        if (
            !InterfaceDB::tableExists('photos')
            || !InterfaceDB::columnsExists('photos', ['storage_base_location', 'original_extension'])
        ) {
            return [];
        }

        $where = "LOWER(original_extension) = 'cr2'
             AND storage_base_location <> ''";
        if (InterfaceDB::columnExists('photos', 'upload_state')) {
            $where .= "
             AND upload_state = 'uploaded'";
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT storage_base_location, COUNT(*) AS cr2_file_count
             FROM photos
             WHERE " . $where . "
             GROUP BY storage_base_location"
        );
        $counts = [];
        foreach ($rows as $row) {
            $baseLocation = (string)($row['storage_base_location'] ?? '');
            if ($baseLocation === '') {
                continue;
            }

            $counts[$baseLocation] = max(0, (int)($row['cr2_file_count'] ?? 0));
        }

        return $counts;
    }

    private function hiddenFields(array $context): string
    {
        $html = '';
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }

    private function canManageStorageLocationActions(array $context): bool
    {
        return $this->currentUserRoleId($context) === RoleAssignmentService::ADMIN_ROLE_ID;
    }

    private function currentUserRoleId(array $context): int
    {
        foreach ([
            ($context['services'] ?? [])['current_user'] ?? null,
            $context['current_user'] ?? null,
            ($context['page'] ?? [])['current_user'] ?? null,
        ] as $currentUser) {
            if (is_array($currentUser) && array_key_exists('role_id', $currentUser)) {
                return (int)$currentUser['role_id'];
            }
        }

        try {
            $session = new SessionAuthenticationService();
            $session->startSession();
            $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
            $userId = $session->authenticatedUserId($deviceId);

            return $userId > 0 ? (new RoleRepository())->userRoleId($userId) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function formatBytes(mixed $bytes): string
    {
        if ($bytes === null || $bytes === false || $bytes === '') {
            return 'Unknown';
        }

        $value = max(0.0, (float)$bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        $precision = $unitIndex === 0 ? 0 : 1;

        return number_format($value, $precision) . ' ' . $units[$unitIndex];
    }
}
