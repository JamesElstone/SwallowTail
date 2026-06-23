<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _statisticsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'statistics';
    }

    public function title(): string
    {
        return 'Statistics';
    }

    public function helper(array $context): string
    {
        return 'Current photo totals, conversion job state, and completed-job timings.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['cr2.upload', 'conversion.jobs'];
    }

    public function refreshIntervalMs(array $context): ?int
    {
        return 30000;
    }

    public function render(array $context): string
    {
        try {
            $statistics = (new SwallowtailStatisticsService())->summary();
        } catch (Throwable $exception) {
            return '<div class="panel-soft warn">Statistics are unavailable: ' . HelperFramework::escape($exception->getMessage()) . '</div>';
        }

        $jobs = (array)($statistics['jobs'] ?? []);

        return '<div class="statistics-dashboard">
            <dl class="statistics-metrics">
                ' . $this->metric('Photos', (int)($statistics['photos_current'] ?? 0), 'Currently in the system') . '
                ' . $this->metric('Total Jobs', (int)($jobs['total'] ?? 0), 'All conversion jobs') . '
                ' . $this->metric('Jobs Outstanding', (int)($jobs['outstanding'] ?? 0), 'Queued or processing') . '
                ' . $this->metric('Jobs Completed', (int)($jobs['completed'] ?? 0), 'Succeeded jobs') . '
            </dl>
            <div class="statistics-duration">
                <h3>Time Taken per Job by Image Type</h3>
                ' . $this->durationRows((array)($statistics['duration_by_image_type'] ?? [])) . '
            </div>
        </div>';
    }

    private function metric(string $label, int $value, string $detail): string
    {
        return '<div>
            <dt>' . HelperFramework::escape($label) . '</dt>
            <dd>' . HelperFramework::escape(number_format(max(0, $value))) . '</dd>
            <span>' . HelperFramework::escape($detail) . '</span>
        </div>';
    }

    private function durationRows(array $rows): string
    {
        if ($rows === []) {
            return '<div class="panel-soft warn">No completed job timing data is available yet.</div>';
        }

        $html = '<div class="table-scroll statistics-duration-table">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Completed</th>
                        <th>Average</th>
                        <th>Fastest</th>
                        <th>Slowest</th>
                    </tr>
                </thead>
                <tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $html .= $this->durationRow($row);
        }

        return $html . '</tbody>
            </table>
        </div>';
    }

    private function durationRow(array $row): string
    {
        $imageType = $this->imageTypeLabel((string)($row['image_type'] ?? ''));
        $completedJobs = max(0, (int)($row['completed_jobs'] ?? 0));

        return '<tr>
            <td>' . HelperFramework::escape($imageType) . '</td>
            <td>' . HelperFramework::escape(number_format($completedJobs)) . '</td>
            <td>' . HelperFramework::escape($this->formatDuration((float)($row['average_seconds'] ?? 0))) . '</td>
            <td>' . HelperFramework::escape($this->formatDuration((float)($row['fastest_seconds'] ?? 0))) . '</td>
            <td>' . HelperFramework::escape($this->formatDuration((float)($row['slowest_seconds'] ?? 0))) . '</td>
        </tr>';
    }

    private function imageTypeLabel(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));

        return match ($imageType) {
            'embedded' => 'Embedded',
            'thumbnail' => 'Thumbnail',
            'original' => 'Original',
            'filtered' => 'Filtered',
            default => $imageType === '' ? 'Unknown' : ucwords(str_replace('_', ' ', $imageType)),
        };
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        if ($seconds < 1.0) {
            return number_format($seconds, 3) . 's';
        }

        if ($seconds < 60.0) {
            return number_format($seconds, 1) . 's';
        }

        $wholeSeconds = (int)round($seconds);
        $minutes = intdiv($wholeSeconds, 60);
        $remainingSeconds = $wholeSeconds % 60;
        if ($minutes < 60) {
            return $remainingSeconds > 0 ? $minutes . 'm ' . $remainingSeconds . 's' : $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? $hours . 'h ' . $remainingMinutes . 'm' : $hours . 'h';
    }
}
