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
        return 'Writable Swallowtail storage locations and current free-space status.';
    }

    public function render(array $context): string
    {
        try {
            $locations = (new SwallowtailStorageLocationService())->locations();
        } catch (Throwable $exception) {
            return '<div class="panel-soft warn">Storage status is unavailable: ' . HelperFramework::escape($exception->getMessage()) . '</div>';
        }

        if ($locations === []) {
            return '<p class="helper">No storage locations are configured.</p>';
        }

        $html = '<div class="storage-location-grid">';
        foreach ($locations as $location) {
            $html .= $this->locationCard((array)$location);
        }

        return $html . '</div>';
    }

    private function locationCard(array $location): string
    {
        $canWrite = !empty($location['can_write']);
        $isReadOnly = !empty($location['is_read_only']);
        $isFull = !empty($location['is_full']);
        $label = (string)($location['label'] ?? 'Storage location');
        $availableBytes = $location['available_bytes'] ?? null;
        $reserveBytes = (int)($location['reserve_bytes'] ?? 0);
        $statusClass = $canWrite ? 'success' : 'warning';
        $statusLabel = $canWrite ? 'Writable' : ($isReadOnly ? 'Read only' : ($isFull ? 'Marked full' : 'Unavailable'));

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
                    <dt>Reserve</dt>
                    <dd>' . HelperFramework::escape($this->formatBytes($reserveBytes)) . '</dd>
                </div>
            </dl>
            <p class="storage-location-path">' . HelperFramework::escape((string)($location['root_path'] ?? '')) . '</p>
        </article>';
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
