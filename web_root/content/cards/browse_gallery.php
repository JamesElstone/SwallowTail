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
        logDetails();

        return 'browse_gallery';
    }

    protected function additionalInvalidationFacts(): array
    {
        logDetails();

        return ['browse.gallery', 'cr2.upload'];
    }

    public function title(): string
    {
        logDetails();

        return 'Browse Gallery';
    }

    public function helper(array $context): string
    {
        logDetails();

        return 'Previews for photos you can view.';
    }

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        logDetails();

        return $this->applyPaginationContext($request, $pageContext);
    }

    public function render(array $context): string
    {
        logDetails();

        $userId = $this->currentUserId();
        $service = new SwallowtailPhotoUiService();

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
        logDetails();

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
        logDetails();

        if (!empty($photo['thumbnail_ready'])) {
            return 'thumbnail';
        }

        return !empty($photo['embedded_ready']) ? 'embedded' : null;
    }

    private function photoAssetUrl(int $photoId, string $type): string
    {
        logDetails();

        return '/api/photo-asset.php?photo_id=' . rawurlencode((string)$photoId) . '&type=' . rawurlencode($type);
    }

    private function statusIndicator(string $state): string
    {
        logDetails();

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
        logDetails();

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
        logDetails();

        $attributes = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

        return match ($status) {
            'ready' => '<svg ' . $attributes . '><circle cx="12" cy="12" r="9"/><path d="m7 12.5 3.25 3.25L17 8.75"/></svg>',
            'failed' => '<svg ' . $attributes . '><circle cx="12" cy="12" r="9"/><path d="m8.5 8.5 7 7"/><path d="m15.5 8.5-7 7"/></svg>',
            default => '<svg ' . $attributes . '><path d="M18.5 8.5A8 8 0 0 0 5.8 7"/><path d="M18.5 8.5h-4"/><path d="M18.5 8.5v-4"/><path d="M5.5 15.5A8 8 0 0 0 18.2 17"/><path d="M5.5 15.5h4"/><path d="M5.5 15.5v4"/></svg>',
        };
    }

    private function currentUserId(): int
    {
        logDetails();

        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }
}
