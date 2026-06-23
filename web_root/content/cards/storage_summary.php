<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _storage_summaryCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'storage_summary';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['storage.available', 'cr2.upload'];
    }

    public function title(): string
    {
        return 'Storage Summary';
    }

    public function helper(array $context): string
    {
        return 'Available capacity across included SwallowTail storage locations.';
    }

    public function render(array $context): string
    {
        try {
            $locations = (new SwallowtailStorageLocationService())->locations();
        } catch (Throwable $exception) {
            return '<div class="panel-soft warn">Storage summary is unavailable: ' . HelperFramework::escape($exception->getMessage()) . '</div>';
        }

        $summary = $this->summariseLocations($locations);
        if ((int)$summary['included_locations'] === 0) {
            return '<div class="panel-soft warn">No included storage locations are available.</div>';
        }

        $warning = (int)$summary['writable_locations'] === 0
            && (int)$summary['below_threshold_locations'] >= (int)$summary['included_locations']
            ? '<div class="panel-soft warn storage-exhausted-warning">No storage locations are currently available for new writes. SwallowTail has crossed the configured free-space threshold on all included storage locations.</div>'
            : '';

        return $warning . '<div class="storage-summary-dashboard">
            <dl class="storage-summary-metrics">
                <div>
                    <dt>Available</dt>
                    <dd>' . HelperFramework::escape($this->formatBytes($summary['available_bytes'])) . '</dd>
                </div>
                <div>
                    <dt>Used</dt>
                    <dd>' . HelperFramework::escape($this->formatBytes($summary['used_bytes'])) . '</dd>
                </div>
                <div>
                    <dt>Capacity</dt>
                    <dd>' . HelperFramework::escape($this->formatBytes($summary['total_bytes'])) . '</dd>
                </div>
                <div>
                    <dt>Free</dt>
                    <dd>' . HelperFramework::escape($summary['free_percent'] === null ? 'Unknown' : number_format((float)$summary['free_percent'], 1) . '%') . '</dd>
                </div>
                <div>
                    <dt>Included</dt>
                    <dd>' . HelperFramework::escape((string)$summary['included_locations']) . '</dd>
                </div>
                <div>
                    <dt>Writable targets</dt>
                    <dd>' . HelperFramework::escape((string)$summary['writable_locations']) . '</dd>
                </div>
                <div>
                    <dt>Below threshold</dt>
                    <dd>' . HelperFramework::escape((string)$summary['below_threshold_locations']) . '</dd>
                </div>
            </dl>
            <div class="storage-summary-chart">' . $this->capacityChart($summary) . '</div>
        </div>';
    }

    /**
     * @param array<int, array<string, mixed>> $locations
     * @return array<string, int|float|null>
     */
    private function summariseLocations(array $locations): array
    {
        $included = 0;
        $writable = 0;
        $belowThreshold = 0;
        $totalBytes = 0;
        $availableBytes = 0;
        $hasCapacityData = false;

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

            if (!is_numeric($location['total_bytes'] ?? null) || !is_numeric($location['available_bytes'] ?? null)) {
                continue;
            }

            $total = max(0, (int)$location['total_bytes']);
            $available = max(0, min($total, (int)$location['available_bytes']));
            if ($total <= 0) {
                continue;
            }

            $hasCapacityData = true;
            $totalBytes += $total;
            $availableBytes += $available;
        }

        $usedBytes = max(0, $totalBytes - $availableBytes);

        return [
            'included_locations' => $included,
            'writable_locations' => $writable,
            'below_threshold_locations' => $belowThreshold,
            'total_bytes' => $hasCapacityData ? $totalBytes : null,
            'available_bytes' => $hasCapacityData ? $availableBytes : null,
            'used_bytes' => $hasCapacityData ? $usedBytes : null,
            'free_percent' => $hasCapacityData && $totalBytes > 0 ? ($availableBytes / $totalBytes) * 100 : null,
        ];
    }

    /**
     * @param array<string, int|float|null> $summary
     */
    private function capacityChart(array $summary): string
    {
        if (($summary['total_bytes'] ?? null) === null || (int)$summary['total_bytes'] <= 0) {
            return '<div class="chart-empty-box"><span class="chart-empty-label">Capacity unavailable</span></div>';
        }

        return (new ChartService())->pie([
            [
                'label' => 'Available',
                'value' => (int)($summary['available_bytes'] ?? 0),
                'color' => '#16a34a',
            ],
            [
                'label' => 'Used',
                'value' => (int)($summary['used_bytes'] ?? 0),
                'color' => '#475569',
            ],
        ], [
            'title' => 'Included storage capacity',
            'width' => 360,
            'height' => 220,
        ]);
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
