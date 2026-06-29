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

    public function services(): array
    {
        return [
            [
                'key' => 'job_queue_rows',
                'service' => SwallowtailJobStatisticsService::class,
                'method' => 'jobQueueRows',
            ],
            [
                'key' => 'metadata_profile_rows',
                'service' => SwallowtailJobStatisticsService::class,
                'method' => 'metadataProfileRows',
            ],
        ];
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
        return '<div class="jobs-statistics">
            <h3>Job Queue</h3>
            ' . $this->jobQueueTable($context, $this->jobQueueRows($context))->render($context, $this->exportHiddenFields($context)) . '
            <h3>Metadata/Profile Jobs</h3>
            ' . $this->metadataProfileTable($context, $this->metadataProfileRows($context))->render($context, $this->exportHiddenFields($context)) . '
        </div>';
    }

    public function tables(array $context): array
    {
        return [
            $this->jobQueueTable($context, $this->jobQueueRows($context)),
            $this->metadataProfileTable($context, $this->metadataProfileRows($context)),
        ];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return 'Job statistics are unavailable: ' . (string)($error['message'] ?? 'Service error');
    }

    private function jobQueueTable(array $context, array $rows): TableFramework
    {
        return TableFramework::make('jobs_queue', $rows)
            ->filename('job-queue')
            ->exportLimit(100)
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
            ->exportLimit(100)
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

    private function jobQueueRows(array $context): array
    {
        return $this->serviceRows($context, 'job_queue_rows');
    }

    private function metadataProfileRows(array $context): array
    {
        return $this->serviceRows($context, 'metadata_profile_rows');
    }

    private function serviceRows(array $context, string $serviceKey): array
    {
        return array_values(array_filter(
            (array)(($context['services'] ?? [])[$serviceKey] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function exportHiddenFields(array $context): array
    {
        return [
            'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
        ];
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
