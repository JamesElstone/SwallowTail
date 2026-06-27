<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _event_downloadsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'event_downloads';
    }

    public function title(): string
    {
        return 'Event Downloads';
    }

    public function helper(array $context): string
    {
        return 'ZIP archives for events your account can download.';
    }

    public function render(array $context): string
    {
        $userId = $this->currentUserIdFromContext($context);
        if ($userId <= 0) {
            return '<p class="helper">No event downloads are available to your account.</p>';
        }

        $events = (new SwallowtailDownloadService())->downloadableEventsForUser($userId);
        if ($events === []) {
            return '<p class="helper">No event downloads are available to your account.</p>';
        }

        $html = '<div class="download-event-list">';
        foreach ($events as $event) {
            $html .= $this->eventRow((array)$event);
        }

        return $html . '</div>';
    }

    private function eventRow(array $event): string
    {
        $eventId = (int)($event['id'] ?? 0);
        $photoCount = (int)($event['photo_count'] ?? 0);
        $countLabel = (string)$photoCount . ' photo' . ($photoCount === 1 ? '' : 's');

        return '<article class="download-event-row">
            <div class="download-event-summary">
                <h3>' . HelperFramework::escape((string)($event['event_name'] ?? 'Event')) . '</h3>
                <p class="helper">' . HelperFramework::escape($countLabel) . '</p>
            </div>
            <div class="download-event-actions">
                ' . $this->downloadLinks($eventId, (array)($event['options'] ?? [])) . '
            </div>
        </article>';
    }

    private function downloadLinks(int $eventId, array $options): string
    {
        $html = '';
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $type = (string)($option['type'] ?? '');
            $label = (string)($option['label'] ?? $type);
            if ($eventId <= 0 || $type === '') {
                continue;
            }

            $url = '/api/photo-download.php?kind=event&event_id='
                . rawurlencode((string)$eventId)
                . '&type='
                . rawurlencode($type);
            $html .= '<a class="button button-inline" href="' . HelperFramework::escape($url) . '">'
                . HelperFramework::escape($label)
                . '</a>';
        }

        return $html !== '' ? $html : '<span class="helper">No files available.</span>';
    }

    private function currentUserIdFromContext(array $context): int
    {
        return max(0, (int)(($context['auth'] ?? [])['user_id'] ?? 0));
    }
}
