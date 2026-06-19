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

        if (!$service->schemaAvailable()) {
            return '<div class="panel-soft warn">SwallowTail photo tables are not available. Run the database migrations.</div>';
        }

        $gallery = $service->accessiblePhotos($userId, $this->paginationPage($context), 24);
        $rows = (array)($gallery['rows'] ?? []);

        if ($rows === []) {
            return '<p class="helper">No accessible photos are available yet.</p>';
        }

        $html = '<div class="gallery-grid">';
        foreach ($rows as $photo) {
            $html .= $this->photoTile((array)$photo);
        }
        $html .= '</div>';

        $html .= $this->paginationControls(
            $context,
            (array)$gallery['pagination'],
            'photos',
            null,
            ['cards[]' => 'browse_gallery']
        );

        return $html;
    }

    private function photoTile(array $photo): string
    {
        $photoId = (int)($photo['id'] ?? 0);
        $filename = (string)($photo['original_filename'] ?? 'Photo');
        $viewerUrl = '?page=picture_viewer&photo_id=' . rawurlencode((string)$photoId);
        $previewType = $this->galleryPreviewType($photo);
        $thumbnail = $previewType !== null
            ? '<img src="' . HelperFramework::escape($this->photoAssetUrl($photoId, $previewType)) . '" alt="' . HelperFramework::escape($filename) . '" loading="lazy">'
            : '<div class="gallery-placeholder">Preview pending</div>';
        $statusIndicator = $this->statusIndicator((string)($photo['conversion_state'] ?? 'pending'));

        return '<a class="gallery-tile" href="' . HelperFramework::escape($viewerUrl) . '">
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
        return match ($status) {
            'ready' => '<svg viewBox="0 0 36 36" aria-hidden="true" focusable="false"><circle cx="18" cy="18" r="15" fill="none" stroke="currentColor" stroke-width="1"/><path d="M10 18.5 L16 24.5 L27 12" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'failed' => '<svg viewBox="0 0 36 36" aria-hidden="true" focusable="false"><circle cx="18" cy="18" r="15" fill="none" stroke="currentColor" stroke-width="1"/><path d="M12 12 L24 24 M24 12 L12 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            default => '<svg viewBox="0 0 36 36" aria-hidden="true" focusable="false"><path d="M8 25 A15 15 0 0 1 11 9" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round"/><path d="M28 11 A15 15 0 0 1 25 27" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round"/><path d="M28 11 L33 12 L30 16" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 25 L3 24 L6 20" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"/></svg>',
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
