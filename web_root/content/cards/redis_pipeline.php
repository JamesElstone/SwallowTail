<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailRedisPipelineService;

final class _redis_pipelineCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'redis_pipeline';
    }

    public function title(): string
    {
        return 'Redis Pipeline';
    }

    public function helper(array $context): string
    {
        return 'Transient worker signals and a safe sample of the oldest pending messages.';
    }

    public function services(): array
    {
        return [['key' => 'redis_pipeline_rows', 'service' => SwallowtailRedisPipelineService::class, 'method' => 'pipelineRows']];
    }
    public function refreshIntervalMs(array $context): ?int
    {
        return 5000;
    }

    public function render(array $context): string
    {
        $rows = array_values(array_filter((array)(($context['services'] ?? [])['redis_pipeline_rows'] ?? []), 'is_array'));
        $html = '<div class="panel-soft">Redis lists are transient priority and wake-up signals. An empty pipeline does not mean the durable database workload is empty.</div>';
        foreach ($rows as $row) {
            $html .= $this->pipelineHtml($row);
        }
        return '<div class="redis-pipeline-statistics">' . $html . '</div>';
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return 'Redis pipeline statistics are unavailable: ' . (string)($error['message'] ?? 'Service error');
    }

    private function pipelineHtml(array $row): string
    {
        $available = !empty($row['available']);
        $length = $available ? number_format(max(0, (int)($row['length'] ?? 0))) : 'Unavailable';
        $items = '';
        foreach ((array)($row['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $age = $this->age((int)($message['queued_at'] ?? 0));
            $items .= '<li>' . HelperFramework::escape((string)($message['summary'] ?? 'Pending message'))
                . ($age === '' ? '' : ' <span class="helper">(' . HelperFramework::escape($age) . ')</span>') . '</li>';
        }
        $sample = $items === '' ? '' : '<p class="helper">Oldest pending messages (up to five):</p><ol>' . $items . '</ol>';
        return '<section class="panel-soft ' . ($available && (int)($row['length'] ?? 0) === 0 ? 'success' : 'warn') . '">
            <h3>' . HelperFramework::escape((string)($row['name'] ?? 'Redis pipeline')) . ': ' . HelperFramework::escape($length) . '</h3>
            <p>' . HelperFramework::escape((string)($row['purpose'] ?? '')) . '</p>
            <p class="helper">' . HelperFramework::escape((string)($row['key'] ?? '')) . '</p>' . $sample . '</section>';
    }

    private function age(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        $seconds = max(0, time() - $timestamp);
        if ($seconds < 60) {
            return $seconds . 's old';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm old';
        }
        return intdiv($seconds, 3600) . 'h old';
    }
}
