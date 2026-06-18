<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _service_statusCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'service_status';
    }

    public function title(): string
    {
        return 'Service Status';
    }

    public function helper(array $context): string
    {
        return 'SwallowTail worker state and Redis connectivity.';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['service.status'];
    }

    public function refreshIntervalMs(array $context): ?int
    {
        return 30000;
    }

    public function render(array $context): string
    {
        try {
            $status = (new SwallowtailServiceStatusService())->status();
        } catch (Throwable $exception) {
            return '<div class="panel-soft warn">Service status is unavailable: ' . HelperFramework::escape($exception->getMessage()) . '</div>';
        }

        $checks = array_values((array)($status['services'] ?? []));
        $checks[] = (array)($status['redis'] ?? []);

        $rows = '';
        foreach ($checks as $check) {
            $rows .= $this->statusRow((array)$check);
        }

        return '<div class="panel-soft service-status-panel">
            <div class="service-status-list">' . $rows . '</div>
        </div>';
    }

    private function statusRow(array $check): string
    {
        $state = $this->stateClass((string)($check['state'] ?? 'warn'));
        $badgeClass = $this->badgeClass($state);
        $label = HelperFramework::escape((string)($check['label'] ?? 'Status check'));
        $status = HelperFramework::escape((string)($check['status'] ?? 'Unknown'));
        $detail = HelperFramework::escape((string)($check['detail'] ?? 'No detail available.'));

        return '<div class="service-status-row">
            <span class="traffic-light ' . $state . '" aria-label="' . $status . '" title="' . $status . '"></span>
            <div class="service-status-main">
                <strong>' . $label . '</strong>
                <span>' . $detail . '</span>
            </div>
            <span class="badge ' . $badgeClass . '">' . $status . '</span>
        </div>';
    }

    private function stateClass(string $state): string
    {
        return in_array($state, ['ok', 'warn', 'bad'], true) ? $state : 'warn';
    }

    private function badgeClass(string $state): string
    {
        return match ($state) {
            'ok' => 'success',
            'bad' => 'danger',
            default => 'warning',
        };
    }
}
