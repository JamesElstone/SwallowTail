<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailDataIntegrityCheckService;

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

    public function refreshIntervalMs(array $context): ?int
    {
        try {
            $blockers = (new SwallowtailDataIntegrityCheckService())->queueBlockers();
        } catch (Throwable) {
            return null;
        }

        return (int)($blockers['total'] ?? 0) > 0 ? 15000 : null;
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

        $message = $canRun
            ? '<div class="panel-soft success">Conversion and storage migration queues are idle.</div>'
            : '<div class="panel-soft warn">Data integrity actions are disabled until queued/processing conversion and storage migration work has finished. Conversion: '
                . HelperFramework::escape(number_format(max(0, (int)($blockers['photo_conversion_jobs'] ?? 0))))
                . '; storage migration: '
                . HelperFramework::escape(number_format(max(0, (int)($blockers['storage_migration_jobs'] ?? 0))))
                . '.</div>';

        return $message . '
            <div class="settings-action-row">
                ' . $this->actionForm($context, 'run_checks', 'Run Integrity Checks', !$canRun) . '
            </div>
            ' . $this->checksTable((array)($status['checks'] ?? []), $context, $canRun);
    }

    private function actionForm(array $context, string $action, string $label, bool $disabled, string $buttonClass = 'button primary'): string
    {
        return '<form method="post" action="?page=settings" data-ajax="true">
            <input type="hidden" name="card_action" value="DataIntegrityCheck">
            <input type="hidden" name="data_integrity_action" value="' . HelperFramework::escape($action) . '">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <button class="' . HelperFramework::escape($buttonClass) . '" type="submit"' . ($disabled ? ' disabled' : '') . '>' . HelperFramework::escape($label) . '</button>
        </form>';
    }

    private function checksTable(array $checks, array $context, bool $canRun): string
    {
        if ($checks === []) {
            return '<div class="panel-soft warn">No data integrity checks are available.</div>';
        }

        $repairableIssues = $this->repairableIssueCount($checks);
        $repairDisabled = !$canRun || $repairableIssues <= 0;
        $rows = '';
        foreach ($checks as $check) {
            $status = (string)($check['status'] ?? '');
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($check['name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($status) . '</td>
                <td>' . HelperFramework::escape(number_format(max(0, (int)($check['count'] ?? 0)))) . '</td>
                <td>' . HelperFramework::escape((string)($check['detail'] ?? '')) . '</td>
                <td>' . $this->checkActions($context, $check, $canRun) . '</td>
            </tr>';
        }

        return '<div class="table-responsive"><table class="data-table">
            <thead><tr><th>Check</th><th>Status</th><th>Count</th><th>Detail</th><th>Actions</th></tr></thead>
            <tbody>' . $rows . '</tbody>
            <tfoot><tr><td colspan="5">
                <div class="settings-action-row">
                    ' . $this->actionForm($context, 'repair_safe_issues', 'Repair Safe Issues', $repairDisabled) . '
                </div>
            </td></tr></tfoot>
        </table></div>';
    }

    private function repairableIssueCount(array $checks): int
    {
        $count = 0;
        foreach ($checks as $check) {
            if ((string)($check['repair_action'] ?? '') === '') {
                continue;
            }

            $count += max(0, (int)($check['count'] ?? 0));
        }

        return $count;
    }

    private function checkActions(array $context, array $check, bool $canRun): string
    {
        $count = max(0, (int)($check['count'] ?? 0));
        if ($count <= 0) {
            return '-';
        }

        $key = (string)($check['key'] ?? '');
        $actions = $this->checkActionForm($context, 'details', 'Details', false, $key, 'button button-inline');
        $repairAction = (string)($check['repair_action'] ?? '');
        if ($repairAction !== '') {
            $actions .= $this->checkActionForm($context, $repairAction, 'Repair', !$canRun, $key, 'button button-inline primary');
        }

        return '<div class="actions-row-nowrap">' . $actions . '</div>';
    }

    private function checkActionForm(array $context, string $action, string $label, bool $disabled, string $checkKey, string $buttonClass): string
    {
        return '<form method="post" action="?page=settings" data-ajax="true">
            <input type="hidden" name="card_action" value="DataIntegrityCheck">
            <input type="hidden" name="data_integrity_action" value="' . HelperFramework::escape($action) . '">
            <input type="hidden" name="data_integrity_check_key" value="' . HelperFramework::escape($checkKey) . '">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape((string)($context['page']['csrf_token'] ?? '')) . '">
            <button class="' . HelperFramework::escape($buttonClass) . '" type="submit"' . ($disabled ? ' disabled' : '') . '>' . HelperFramework::escape($label) . '</button>
        </form>';
    }
}
