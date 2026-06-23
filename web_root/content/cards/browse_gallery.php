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
        return $this->applyPaginationContext($request, $pageContext);
    }

    public function render(array $context): string
    {
        $userId = $this->currentUserId();
        $service = new SwallowtailPhotoUiService();

        $gallery = $service->accessiblePhotos($userId, $this->paginationPage($context), 24);
        $rows = (array)($gallery['rows'] ?? []);
        $pagination = (array)($gallery['pagination'] ?? []);

        if ($rows === []) {
            return '<p class="helper">No accessible photos are available yet.</p>';
        }

        $hasPendingPreviews = $this->hasPendingPreviews($rows);
        $pageField = $this->paginationPageField();
        $page = max(1, (int)($pagination['page'] ?? $this->paginationPage($context)));

        $html = '<div class="gallery-grid"
            data-gallery-auto-refresh="true"
            data-gallery-pending="' . ($hasPendingPreviews ? '1' : '0') . '"
            data-gallery-page="' . HelperFramework::escape((string)$page) . '"
            data-gallery-page-field="' . HelperFramework::escape($pageField) . '">';
        foreach ($rows as $photo) {
            $html .= $this->photoTile((array)$photo);
        }
        $html .= '</div>';

        $html .= $this->paginationControls(
            $context,
            (array)$gallery['pagination'],
            'photos',
            null,
            ['cards[]' => 'browse_gallery'],
            'post',
            [],
            'button primary',
            $this->autoRefreshControl()
        );

        return $html;
    }

    private function photoTile(array $photo): string
    {
        $photoId = (int)($photo['id'] ?? 0);
        $filename = (string)($photo['original_filename'] ?? 'Photo');
        $viewerUrl = '?page=picture_viewer&photo_id=' . rawurlencode((string)$photoId);
        $previewType = $this->galleryPreviewType($photo);
        $status = $this->statusIndicatorState((string)($photo['conversion_state'] ?? 'pending'));
        $pendingAttribute = $this->photoNeedsRefresh($photo) ? ' data-gallery-photo-pending="1"' : '';
        $thumbnail = $previewType !== null
            ? '<img src="' . HelperFramework::escape($this->photoAssetUrl($photoId, $previewType)) . '" alt="' . HelperFramework::escape($filename) . '" loading="lazy">'
            : '<div class="gallery-placeholder">Preview pending</div>';
        $statusIndicator = $this->statusIndicator($status);

        return '<a class="gallery-tile" href="' . HelperFramework::escape($viewerUrl) . '"' . $pendingAttribute . '>
            <span class="gallery-thumb">' . $thumbnail . $statusIndicator . '</span>
            <span class="gallery-meta">
                <strong>' . HelperFramework::escape($filename) . '</strong>
            </span>
        </a>';
    }

    private function galleryPreviewType(array $photo): ?string
    {
        if (!empty($photo['thumbnail_ready'])) {
            return 'thumbnail';
        }

        return !empty($photo['embedded_ready']) ? 'embedded' : null;
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

    private function photoAssetUrl(int $photoId, string $type): string
    {
        return '/api/photo-asset.php?photo_id=' . rawurlencode((string)$photoId) . '&type=' . rawurlencode($type);
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

    private function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }
}
