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
        return 'Thumbnails for photos you can view.';
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
            return '<div class="panel-soft warn">Swallowtail photo tables are not available. Run the database migrations.</div>';
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
        $thumbnailUrl = '/api/photo-asset.php?photo_id=' . rawurlencode((string)$photoId) . '&type=thumbnail';
        $thumbnail = !empty($photo['thumbnail_ready'])
            ? '<img src="' . HelperFramework::escape($thumbnailUrl) . '" alt="' . HelperFramework::escape($filename) . '" loading="lazy">'
            : '<div class="gallery-placeholder">Thumbnail pending</div>';

        return '<a class="gallery-tile" href="' . HelperFramework::escape($viewerUrl) . '">
            <span class="gallery-thumb">' . $thumbnail . '</span>
            <span class="gallery-meta">
                <strong>' . HelperFramework::escape($filename) . '</strong>
                <span>' . HelperFramework::escape($this->statusLabel((string)($photo['conversion_state'] ?? 'pending'))) . '</span>
            </span>
        </a>';
    }

    private function statusLabel(string $state): string
    {
        $state = strtolower(trim($state));

        return match ($state) {
            'ready' => 'Ready',
            'processing' => 'Processing',
            'failed' => 'Conversion failed',
            'not_required' => 'No conversion needed',
            default => 'Conversion pending',
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
