<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailJobStatisticsService;

final class _jobsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'jobs';
    }

    public function title(): string
    {
        return 'Jobs';
    }

    public function helper(array $context): string
    {
        return 'Database-backed job engine statistics and exception reprocessing.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['conversion.jobs', 'storage.migration.jobs', 'metadata.jobs', 'profile.jobs'];
    }

    public function render(array $context): string
    {
        try {
            $service = new SwallowtailJobStatisticsService();

            return '<div class="jobs-statistics">
                <h3>Job Queue</h3>
                ' . $this->jobQueueTable($context, $service->jobQueueRows())->renderTable() . '
                <h3>Metadata/Profile Jobs</h3>
                ' . $this->metadataProfileTable($context, $service->metadataProfileRows())->renderTable() . '
            </div>';
        } catch (Throwable $exception) {
            return '<div class="panel-soft warn">Job statistics are unavailable: ' . HelperFramework::escape($exception->getMessage()) . '</div>';
        }
    }

    public function tables(array $context): array
    {
        $service = new SwallowtailJobStatisticsService();

        return [
            $this->jobQueueTable($context, $service->jobQueueRows()),
            $this->metadataProfileTable($context, $service->metadataProfileRows()),
        ];
    }

    private function jobQueueTable(array $context, array $rows): TableFramework
    {
        return TableFramework::make('jobs_queue', $rows)
            ->filename('job-queue')
            ->exports(false)
            ->textColumn('job_type', 'Job type')
            ->column('succeeded', 'Succeeded', html: fn(array $row): string => $this->countCell($row, 'succeeded'), exportType: 'number')
            ->column('failed', 'Failed', html: fn(array $row): string => $this->countCell($row, 'failed'), exportType: 'number')
            ->column('cancelled', 'Cancelled', html: fn(array $row): string => $this->countCell($row, 'cancelled'), exportType: 'number')
            ->column('obsolete', 'Obsolete', html: fn(array $row): string => $this->countCell($row, 'obsolete'), exportType: 'number')
            ->column('queued', 'Queued', html: fn(array $row): string => $this->countCell($row, 'queued'), exportType: 'number')
            ->column('processing', 'Processing', html: fn(array $row): string => $this->countCell($row, 'processing'), exportType: 'number')
            ->textColumn('total', 'Total')
            ->column(
                'action',
                'Action',
                html: fn(array $row): string => $this->reprocessButtonHtml(
                    $context,
                    (string)($row['job_key'] ?? ''),
                    (int)($row['failed'] ?? 0) > 0
                ),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function metadataProfileTable(array $context, array $rows): TableFramework
    {
        return TableFramework::make('jobs_metadata_profile', $rows)
            ->filename('metadata-profile-jobs')
            ->exports(false)
            ->textColumn('job_type', 'Job type')
            ->column('ready', 'Ready', html: fn(array $row): string => $this->countCell($row, 'ready'), exportType: 'number')
            ->column('failed', 'Failed', html: fn(array $row): string => $this->countCell($row, 'failed'), exportType: 'number')
            ->column('deferred', 'Deferred', html: fn(array $row): string => $this->countCell($row, 'deferred'), exportType: 'number')
            ->column('queued', 'Queued', html: fn(array $row): string => $this->countCell($row, 'queued'), exportType: 'number')
            ->column('processing', 'Processing', html: fn(array $row): string => $this->countCell($row, 'processing'), exportType: 'number')
            ->textColumn('total', 'Total')
            ->column(
                'action',
                'Action',
                html: fn(array $row): string => $this->reprocessButtonHtml(
                    $context,
                    (string)($row['job_key'] ?? ''),
                    (int)($row['failed'] ?? 0) > 0
                ),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function countCell(array $row, string $key): string
    {
        return HelperFramework::escape(number_format(max(0, (int)($row[$key] ?? 0))));
    }

    private function reprocessButtonHtml(array $context, string $jobKey, bool $enabled): string
    {
        $jobKey = strtolower(trim($jobKey));
        if (!in_array($jobKey, ['conversion', 'migration', 'metadata', 'profile'], true)) {
            return '';
        }

        $disabled = $enabled ? '' : ' disabled';

        return '<form method="post" action="?page=settings" data-ajax="true">
            <input type="hidden" name="card_action" value="Jobs">
            <input type="hidden" name="jobs_action" value="reprocess_exceptions">
            <input type="hidden" name="job_type" value="' . HelperFramework::escape($jobKey) . '">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <button class="button primary" type="submit"' . $disabled . '>Reprocess Exceptions</button>
        </form>';
    }
}
