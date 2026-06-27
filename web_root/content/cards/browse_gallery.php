<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _browse_galleryCard extends CardBaseFramework
{
    private const DEFAULT_PER_PAGE = 24;
    private const PER_PAGE_OPTIONS = [24, 30, 40];

    public function key(): string
    {
        return 'browse_gallery';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['browse.gallery', 'cr2.upload'];
    }

    public function title(): string
    {
        return 'Browse Gallery';
    }

    public function helper(array $context): string
    {
        return 'Previews for photos you can view.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = $this->applyPaginationContext($request, $pageContext);
        $pageContext['page'][$this->perPageField()] = $this->normalisePerPage(
            (int)$request->input($this->perPageField(), self::DEFAULT_PER_PAGE)
        );

        return $pageContext;
    }

    public function render(array $context): string
    {
        $userId = $this->currentUserId();
        $service = new SwallowtailPhotoUiService();
        $perPage = $this->perPage($context);

        $gallery = $service->accessiblePhotos($userId, $this->paginationPage($context), $perPage);
        $rows = (array)($gallery['rows'] ?? []);
        $pagination = (array)($gallery['pagination'] ?? []);

        if ($rows === []) {
            return '<p class="helper">No accessible photos are available yet.</p>';
        }

        $hasPendingPreviews = $this->hasPendingPreviews($rows);
        $this->notifyPendingGalleryAssets($rows);
        $pageField = $this->paginationPageField();
        $perPageField = $this->perPageField();
        $page = max(1, (int)($pagination['page'] ?? $this->paginationPage($context)));
        $canAssignEvents = $this->canManageEvents() && $this->hasEditablePhotos($rows);

        $html = '<div class="gallery-grid"
            data-gallery-auto-refresh="true"
            data-gallery-events-grid
            data-gallery-pending="' . ($hasPendingPreviews ? '1' : '0') . '"
            data-gallery-page="' . HelperFramework::escape((string)$page) . '"
            data-gallery-page-field="' . HelperFramework::escape($pageField) . '"
            data-gallery-per-page="' . HelperFramework::escape((string)$perPage) . '"
            data-gallery-per-page-field="' . HelperFramework::escape($perPageField) . '">';
        $html .= $canAssignEvents ? $this->eventAssignmentPane($context) : '';
        foreach ($rows as $photo) {
            $html .= $this->photoTile((array)$photo, $canAssignEvents);
        }
        $html .= '</div>';

        $html .= $this->paginationControls(
            $context,
            (array)$gallery['pagination'],
            'Photos',
            null,
            [
                'cards[]' => 'browse_gallery',
                $perPageField => $perPage,
            ],
            'post',
            [],
            'button primary',
            $this->galleryControls($perPage, $canAssignEvents),
            'gallery-pagination-controls'
        );

        return $html;
    }

    private function photoTile(array $photo, bool $canAssignEvents = true): string
    {
        $photoId = (int)($photo['id'] ?? 0);
        $filename = (string)($photo['original_filename'] ?? 'Photo');
        $viewerUrl = '?page=view&photo_id=' . rawurlencode((string)$photoId);
        $editorUrl = '?page=edit&photo_id=' . rawurlencode((string)$photoId);
        $downloadUrl = '/api/photo-download.php?kind=photo&photo_id=' . rawurlencode((string)$photoId);
        $previewType = $this->galleryPreviewType($photo);
        $status = $this->statusIndicatorState((string)($photo['conversion_state'] ?? 'pending'));
        $needsRefresh = $this->photoNeedsRefresh($photo);
        $pendingAttribute = $needsRefresh ? ' data-gallery-photo-pending="1"' : '';
        $statusType = $needsRefresh ? $this->galleryAssetScanType($photo) : null;
        $statusUrlAttribute = $statusType !== null
            ? ' data-gallery-photo-status-url="' . HelperFramework::escape($this->photoStatusUrl($photoId, $statusType)) . '"'
            : '';
        $preview = $previewType !== null
            ? '<img src="' . HelperFramework::escape($this->photoAssetUrl($photoId, $previewType)) . '" alt="' . HelperFramework::escape($filename) . '" loading="lazy">'
            : '<div class="gallery-placeholder">Preview pending</div>';
        $statusIndicator = $this->statusIndicator($status);
        $eventCheckbox = ($canAssignEvents && !empty($photo['effective_can_edit']))
            ? '<label class="gallery-event-select" aria-label="Select ' . HelperFramework::escape($filename) . ' for event assignment">
            <input type="checkbox" name="photo_ids[]" value="' . HelperFramework::escape((string)$photoId) . '" form="gallery-event-assignment-form">
            <span></span>
        </label>'
            : '';
        $editLink = !empty($photo['effective_can_edit'])
            ? '<a class="gallery-edit-link" href="' . HelperFramework::escape($editorUrl) . '" aria-label="Edit ' . HelperFramework::escape($filename) . '">
                ' . $this->editIconSvg() . '
            </a>'
            : '';
        $downloadLink = !empty($photo['effective_can_download_single_jpeg'])
            ? '<a class="gallery-download-link" href="' . HelperFramework::escape($downloadUrl) . '" aria-label="Download ' . HelperFramework::escape($filename) . '">
                ' . $this->downloadIconSvg() . '
            </a>'
            : '';

        return '<article class="gallery-tile" data-gallery-photo-id="' . HelperFramework::escape((string)$photoId) . '"' . $pendingAttribute . $statusUrlAttribute . '>
            <div class="gallery-thumb-shell">
                <a class="gallery-view-link gallery-thumb-link" href="' . HelperFramework::escape($viewerUrl) . '" aria-label="View ' . HelperFramework::escape($filename) . '">
                    <span class="gallery-thumb">' . $preview . $statusIndicator . '</span>
                </a>
                ' . $eventCheckbox . '
                ' . $downloadLink . '
                ' . $editLink . '
            </div>
            <a class="gallery-view-link gallery-meta-link" href="' . HelperFramework::escape($viewerUrl) . '" aria-label="View ' . HelperFramework::escape($filename) . '">
                <span class="gallery-meta">
                    <strong>' . HelperFramework::escape($filename) . '</strong>
                </span>
            </a>
        </article>';
    }

    private function galleryPreviewType(array $photo): ?string
    {
        if (!empty($photo['preview_ready'])) {
            return 'preview';
        }

        return !empty($photo['thumbnail_ready']) ? 'thumbnail' : null;
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function notifyPendingGalleryAssets(array $rows): void
    {
        $notifier = new SwallowtailPhotoAssetNotificationService();
        foreach ($rows as $photo) {
            if (!is_array($photo) || !$this->photoNeedsRefresh($photo)) {
                continue;
            }

            $imageType = $this->galleryAssetScanType($photo);
            if ($imageType === null) {
                continue;
            }

            $notifier->notifyPhotoAsset($photo, $imageType, 'browse_gallery_auto_refresh');
        }
    }

    private function galleryAssetScanType(array $photo): ?string
    {
        if (empty($photo['thumbnail_ready'])) {
            return 'thumbnail';
        }

        if (empty($photo['preview_ready'])) {
            return 'preview';
        }

        return null;
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function hasPendingPreviews(array $rows): bool
    {
        foreach ($rows as $photo) {
            if (is_array($photo) && $this->photoNeedsRefresh($photo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function hasEditablePhotos(array $rows): bool
    {
        foreach ($rows as $photo) {
            if (is_array($photo) && !empty($photo['effective_can_edit'])) {
                return true;
            }
        }

        return false;
    }

    private function photoNeedsRefresh(array $photo): bool
    {
        $status = $this->statusIndicatorState((string)($photo['conversion_state'] ?? 'pending'));

        return $status === 'processing'
            || ($status !== 'failed' && $this->galleryPreviewType($photo) === null);
    }

    private function autoRefreshControl(): string
    {
        return '<label class="gallery-auto-refresh-control" data-gallery-auto-refresh-control>
            <input type="checkbox" value="1" data-gallery-auto-refresh-toggle>
            <span>Auto refresh</span>
        </label>';
    }

    private function autoScrollControl(): string
    {
        return '<label class="gallery-auto-refresh-control" data-gallery-auto-scroll-control>
            <input type="checkbox" value="1" data-gallery-auto-scroll-toggle>
            <span>Auto scroll</span>
        </label>';
    }

    private function galleryControls(int $perPage, ?bool $canAssignEvents = null): string
    {
        $canAssignEvents ??= $this->canManageEvents();

        return '<div class="gallery-footer-controls">'
            . $this->perPageControl($perPage)
            . $this->autoRefreshControl()
            . $this->autoScrollControl()
            . $this->eventsControl($canAssignEvents)
            . '</div>';
    }

    private function eventsControl(bool $canAssignEvents): string
    {
        if (!$canAssignEvents) {
            return '';
        }

        return '<button class="button button-inline gallery-events-toggle" type="button" data-gallery-events-toggle aria-expanded="false">Assign Events</button>';
    }

    private function eventAssignmentPane(array $context): string
    {
        $events = (new SwallowtailEventManagementService())->eventOptionsForAssignment();
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $eventRows = '';

        foreach ($events as $event) {
            $eventId = (int)($event['id'] ?? 0);
            $eventRows .= '<div class="gallery-event-assignment-row">
                <span>
                    <strong>' . HelperFramework::escape((string)($event['event_name'] ?? 'Event')) . '</strong>
                    <span class="helper">' . HelperFramework::escape((string)((int)($event['photo_count'] ?? 0))) . ' photos</span>
                </span>
                <span class="actions-row">
                    <button class="button button-inline primary" type="button" value="' . HelperFramework::escape((string)$eventId) . '" data-assignment-state="1" data-gallery-assignment-event>Tag</button>
                    <button class="button button-inline" type="button" value="' . HelperFramework::escape((string)$eventId) . '" data-assignment-state="0" data-gallery-assignment-event>Untag</button>
                </span>
            </div>';
        }

        if ($eventRows === '') {
            $eventRows = '<p class="helper">No active events are available.</p>';
        }

        return '<aside class="gallery-events-pane" data-gallery-events-pane hidden>
            <div class="status-head">
                <div>
                    <h3>Events</h3>
                    <p class="helper"><span data-gallery-events-selected-count>0</span> selected</p>
                </div>
                <button class="button button-inline primary" type="button" data-gallery-event-create-toggle>Add Event</button>
            </div>
            <form id="gallery-event-assignment-form" method="post" action="?page=gallery" data-ajax="true">
                <input type="hidden" name="card_action" value="EventPermissions">
                <input type="hidden" name="event_permissions_action" value="assign_photos">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <input type="hidden" name="cards[]" value="browse_gallery">
                <input type="hidden" name="assignment_event_id" value="" data-gallery-assignment-event-id>
                <input type="hidden" name="assignment_state" value="1" data-gallery-assignment-state>
                <div class="gallery-event-assignment-list">' . $eventRows . '</div>
                <button class="button button-inline primary gallery-event-apply" type="submit" data-gallery-assignment-submit hidden disabled>Apply Selected Photos</button>
            </form>
        </aside>';
    }

    private function perPageControl(int $perPage): string
    {
        $perPage = $this->normalisePerPage($perPage);
        $options = '';

        foreach (self::PER_PAGE_OPTIONS as $option) {
            $options .= '<option value="' . HelperFramework::escape((string)$option) . '"'
                . ($option === $perPage ? ' selected' : '')
                . '>' . HelperFramework::escape((string)$option) . '</option>';
        }

        return '<form method="post" data-ajax="true" class="gallery-page-size-form">
            <input type="hidden" name="cards[]" value="browse_gallery">
            <input type="hidden" name="_pagination" value="1">
            <input type="hidden" name="_invalidate_fact" value="' . HelperFramework::escape($this->galleryInvalidationFact()) . '">
            <input type="hidden" name="' . HelperFramework::escape($this->paginationPageField()) . '" value="1">
            <label class="gallery-page-size-control">
                <span>Images</span>
                <select name="' . HelperFramework::escape($this->perPageField()) . '" aria-label="Images per page">
                    ' . $options . '
                </select>
            </label>
        </form>';
    }

    private function galleryInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'page.reload');
    }

    private function perPage(array $context): int
    {
        return $this->normalisePerPage((int)($context['page'][$this->perPageField()] ?? self::DEFAULT_PER_PAGE));
    }

    private function perPageField(): string
    {
        return $this->key() . '_per_page';
    }

    private function normalisePerPage(int $perPage): int
    {
        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    private function photoAssetUrl(int $photoId, string $type): string
    {
        return '/api/photo-imaging.php?photo_id=' . rawurlencode((string)$photoId) . '&type=' . rawurlencode($type);
    }

    private function photoStatusUrl(int $photoId, string $type): string
    {
        return '/api/photo-status.php?photo_id=' . rawurlencode((string)$photoId) . '&image_type=' . rawurlencode($type);
    }

    private function statusIndicator(string $state): string
    {
        $status = $this->statusIndicatorState($state);
        $label = match ($status) {
            'ready' => 'Ready',
            'failed' => 'Conversion failed',
            default => 'Processing',
        };

        return '<span class="gallery-status gallery-status-' . HelperFramework::escape($status) . '" aria-label="' . HelperFramework::escape($label) . '">'
            . $this->statusIconSvg($status)
            . '</span>';
    }

    private function statusIndicatorState(string $state): string
    {
        $state = strtolower(trim($state));
        if ($state === 'ready' || $state === 'not_required') {
            return 'ready';
        }
        if ($state === 'failed') {
            return 'failed';
        }

        return 'processing';
    }

    private function statusIconSvg(string $status): string
    {
        $attributes = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

        return match ($status) {
            'ready' => '<svg ' . $attributes . '><circle cx="12" cy="12" r="9"/><path d="m7 12.5 3.25 3.25L17 8.75"/></svg>',
            'failed' => '<svg ' . $attributes . '><circle cx="12" cy="12" r="9"/><path d="m8.5 8.5 7 7"/><path d="m15.5 8.5-7 7"/></svg>',
            default => '<svg ' . $attributes . '><path d="M18.5 8.5A8 8 0 0 0 5.8 7"/><path d="M18.5 8.5h-4"/><path d="M18.5 8.5v-4"/><path d="M5.5 15.5A8 8 0 0 0 18.2 17"/><path d="M5.5 15.5h4"/><path d="M5.5 15.5v4"/></svg>',
        };
    }

    private function editIconSvg(): string
    {
        $attributes = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

        return '<svg ' . $attributes . '><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    }

    private function downloadIconSvg(): string
    {
        $attributes = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

        return '<svg ' . $attributes . '><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>';
    }

    private function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }

    private function canManageEvents(): bool
    {
        $userId = $this->currentUserId();

        return $userId > 0
            && in_array('event_permissions', (new CardAccessFramework())->allowedCardsForUser($userId, ['event_permissions']), true);
    }
}
