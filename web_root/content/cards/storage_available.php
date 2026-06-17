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
            $locations = (new SwallowtailStorageLocationService())->locations();
        } catch (Throwable $exception) {
            return '<div class="panel-soft warn">Storage status is unavailable: ' . HelperFramework::escape($exception->getMessage()) . '</div>';
        }

        $html = $this->settingsForm($context);

        if ($locations === []) {
            return $html . '<p class="helper">No mounted storage locations are available.</p>';
        }

        $html .= '<div class="storage-location-grid">';
        foreach ($locations as $location) {
            $html .= $this->locationCard((array)$location, $context);
        }

        return $html . '</div>';
    }

    private function locationCard(array $location, array $context): string
    {
        $canWrite = !empty($location['can_write']);
        $isExcluded = !empty($location['is_excluded']);
        $isFull = !empty($location['is_full']);
        $label = (string)($location['label'] ?? 'Storage location');
        $baseLocation = (string)($location['storage_base_location'] ?? '');
        $availableBytes = $location['available_bytes'] ?? null;
        $totalBytes = $location['total_bytes'] ?? null;
        $freePercent = $location['free_percent'] ?? null;
        $threshold = (float)($location['full_threshold_percent'] ?? 5);
        $statusClass = $canWrite ? 'success' : 'warning';
        $statusLabel = $canWrite ? 'Writable' : ($isExcluded ? 'Excluded' : ($isFull ? 'Below threshold' : 'Unavailable'));
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

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
            </dl>
            <p class="storage-location-path">' . HelperFramework::escape((string)($location['root_path'] ?? '')) . '</p>
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
            </form>
        </article>';
    }

    private function settingsForm(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $storeOnRoot = (bool)AppConfigurationStore::get('swallowtail.storage.store_on_root_partition', false);
        $roundRobin = (bool)AppConfigurationStore::get('swallowtail.storage.round_robin_locations', false);
        $threshold = (float)AppConfigurationStore::get('swallowtail.storage.full_threshold_percent', 5);

        return '<form method="post" action="?page=settings" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="card_action" value="StorageSettings">
            <input type="hidden" name="storage_settings_action" value="update_settings">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
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
        </form>';
    }

    private function hiddenFields(array $context): string
    {
        $html = '';
        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
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
