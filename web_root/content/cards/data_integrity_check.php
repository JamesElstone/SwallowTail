<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _data_integrity_checkCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'data_integrity_check';
    }

    public function title(): string
    {
        return 'Data Integrity Check';
    }

    public function helper(array $context): string
    {
        return 'Checks data consistency and queues missing profiled image work after workers are idle.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['data.integrity', 'conversion.jobs', 'storage.migration.jobs'];
    }

    public function render(array $context): string
    {
        try {
            $status = (new SwallowtailDataIntegrityCheckService())->status();
        } catch (Throwable $exception) {
            return '<div class="panel-soft warn">Data integrity status is unavailable: ' . HelperFramework::escape($exception->getMessage()) . '</div>';
        }

        $canRun = !empty($status['can_run']);
        $blockers = (array)($status['blockers'] ?? []);
        $lazyScan = (array)($status['lazy_scan'] ?? []);
        $requested = !empty($lazyScan['requested']);

        $message = $canRun
            ? '<div class="panel-soft success">Conversion and storage migration queues are idle.</div>'
            : '<div class="panel-soft warn">Data integrity actions are disabled until queued/processing conversion and storage migration work has finished. Conversion: '
                . HelperFramework::escape(number_format(max(0, (int)($blockers['photo_conversion_jobs'] ?? 0))))
                . '; storage migration: '
                . HelperFramework::escape(number_format(max(0, (int)($blockers['storage_migration_jobs'] ?? 0))))
                . '.</div>';

        return $message . '
            <div class="settings-action-row">
                ' . $this->actionForm($context, 'prevent_lazy_loading', 'Prevent Lazy Loading', !$canRun) . '
                ' . $this->actionForm($context, 'run_checks', 'Run Integrity Checks', !$canRun) . '
            </div>
            <dl class="storage-summary-metrics">
                <div>
                    <dt>Requested</dt>
                    <dd>' . HelperFramework::escape($requested ? 'Yes' : 'No') . '</dd>
                </div>
                <div>
                    <dt>Scan cursor</dt>
                    <dd>' . HelperFramework::escape(number_format(max(0, (int)($lazyScan['cursor'] ?? 0)))) . '</dd>
                </div>
                <div>
                    <dt>Remaining after cursor</dt>
                    <dd>' . HelperFramework::escape(number_format(max(0, (int)($lazyScan['remaining_after_cursor'] ?? 0)))) . '</dd>
                </div>
            </dl>
            ' . $this->checksTable((array)($status['checks'] ?? []));
    }

    private function actionForm(array $context, string $action, string $label, bool $disabled): string
    {
        return '<form method="post" action="?page=settings" data-ajax="true">
            <input type="hidden" name="card_action" value="DataIntegrityCheck">
            <input type="hidden" name="data_integrity_action" value="' . HelperFramework::escape($action) . '">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <button class="button primary" type="submit"' . ($disabled ? ' disabled' : '') . '>' . HelperFramework::escape($label) . '</button>
        </form>';
    }

    private function checksTable(array $checks): string
    {
        if ($checks === []) {
            return '<div class="panel-soft warn">No data integrity checks are available.</div>';
        }

        $rows = '';
        foreach ($checks as $check) {
            $status = (string)($check['status'] ?? '');
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($check['name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($status) . '</td>
                <td>' . HelperFramework::escape(number_format(max(0, (int)($check['count'] ?? 0)))) . '</td>
                <td>' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</td>
            </tr>';
        }

        return '<div class="table-responsive"><table class="data-table">
            <thead><tr><th>Check</th><th>Status</th><th>Count</th><th>Detail</th></tr></thead>
            <tbody>' . $rows . '</tbody>
        </table></div>';
    }
}
