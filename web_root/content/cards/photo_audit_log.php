<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Repository\PhotoAuditRepository;

final class _photo_audit_logCard extends CardBaseFramework
{
    private const PAGE_SIZE = 5;

    public function key(): string
    {
        return 'photo_audit_log';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'photo_audit_rows',
                'service' => PhotoAuditRepository::class,
                'method' => 'fetchRecentPhotoAudit',
                'params' => [
                    'limit' => 200,
                ],
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'Recent photo library audit events, including uploads, duplicates, event changes, and storage migration activity.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyTableSortContext($request, $pageContext, $this->key());
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render(
            $context,
            [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]
        );
    }

    public function tables(array $context): array
    {
        return [$this->configuredTable($context)];
    }

    private function configuredTable(array $context): TableFramework
    {
        $hiddenFields = [
            'page' => (string)($context['page']['page_id'] ?? ''),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ];
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Photo audit events',
                $this->paginationPageField(),
                $hiddenFields
            );
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('photo-audit-log')
            ->exportLimit(200)
            ->empty('No photo audit events have been recorded yet.')
            ->classes(wrapperClass: 'table-scroll photo-audit-table')
            ->textColumn('occurred_at', 'Time')
            ->primarySecondaryColumn(
                'photo_label',
                'Photo',
                secondaryKey: 'photo_id_label',
                primaryFallback: 'Unknown photo',
                secondaryPreviewLength: 80
            )
            ->textColumn('event_label', 'Event', fallback: 'No event')
            ->primarySecondaryColumn(
                'actor_label',
                'Actor / Source',
                secondaryKey: 'source_label',
                primaryFallback: 'System',
                secondaryFallback: 'No upload token',
                secondaryPreviewLength: 96
            )
            ->badgeColumn(
                'action_type',
                'Action',
                badgeClass: 'info',
                labelSeparator: '_'
            )
            ->column(
                'details_json',
                'Details',
                html: static function (array $row): string {
                    $summary = HelperFramework::jsonSummary((string)($row['details_json'] ?? ''));

                    return $summary !== ''
                        ? HelperFramework::escape($summary)
                        : '<span class="helper">No details</span>';
                },
                export: static fn(array $row): string => HelperFramework::jsonSummary((string)($row['details_json'] ?? ''))
            )
            ->primarySecondaryColumn(
                'ip_address',
                'IP / User Agent',
                secondaryKey: 'user_agent',
                primaryFallback: 'Unknown IP',
                secondaryPreviewLength: 96,
                secondaryClass: 'helper',
                secondaryPreviewClass: 'log-agent-preview',
                cellClass: 'log-agent-cell'
            );
    }

    private function rows(array $context): array
    {
        return array_map(
            fn(array $row): array => $this->normaliseRow($row),
            array_filter(
                (array)(($context['services'] ?? [])['photo_audit_rows'] ?? []),
                static fn(mixed $row): bool => is_array($row)
            )
        );
    }

    private function normaliseRow(array $row): array
    {
        $photoId = (int)($row['photo_id'] ?? 0);
        $eventId = (int)($row['event_id'] ?? 0);
        $actorId = (int)($row['actor_user_id'] ?? 0);
        $uploadTokenId = (int)($row['upload_token_id'] ?? 0);
        $filename = trim((string)($row['original_filename'] ?? ''));
        $eventName = trim((string)($row['event_name'] ?? ''));
        $actorName = trim((string)($row['actor_user_display_name'] ?? ''));
        $tokenLabel = trim((string)($row['upload_token_label'] ?? ''));

        $row['photo_label'] = $filename !== '' ? $filename : ($photoId > 0 ? 'Photo #' . (string)$photoId : 'Unknown photo');
        $row['photo_id_label'] = $photoId > 0 ? 'Photo ID: ' . (string)$photoId : '';
        $row['event_label'] = $eventName !== '' ? $eventName : ($eventId > 0 ? 'Event #' . (string)$eventId : 'No event');
        $row['actor_label'] = $actorName !== '' ? $actorName : ($actorId > 0 ? 'User #' . (string)$actorId : 'System');
        $row['source_label'] = $tokenLabel !== ''
            ? 'Upload token: ' . $tokenLabel
            : ($uploadTokenId > 0 ? 'Upload token #' . (string)$uploadTokenId : 'No upload token');

        return $row;
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'photo.audit.log');
    }
}
