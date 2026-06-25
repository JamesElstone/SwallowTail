<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _picture_viewerCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'picture_viewer';
    }

    public function title(): string
    {
        return 'Picture Viewer';
    }

    public function helper(array $context): string
    {
        return (new SwallowtailPhotoMetadataSummaryService())->helperForPhoto(
            (int)($context['page']['photo_id'] ?? 0),
            $this->currentUserId()
        );
    }

    public function render(array $context): string
    {
        $photoId = (int)($context['page']['photo_id'] ?? 0);
        if ($photoId <= 0) {
            return '<p class="helper">Select a photo from the gallery to view it here.</p>';
        }

        $photo = (new SwallowtailPhotoUiService())->photoDetails($photoId, $this->currentUserId());
        if ($photo === null) {
            return '<div class="panel-soft warn">The selected photo was not found or is not available to your account.</div>';
        }

        (new SwallowtailProfileDataService())->requestUrgentProfile($photo, 'picture_viewer');

        return '<div class="picture-viewer-layout">
            <div class="picture-viewer-media">
                ' . $this->mediaMarkup($photo) . '
            </div>
            <div class="picture-viewer-details">
                <div class="actions-row">
                    <a class="button button-inline" href="?page=gallery">Back to Gallery</a>
                </div>
                <h3>' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo')) . '</h3>
                ' . $this->statusBadges($photo) . '
                <dl class="picture-meta-list">
                    ' . $this->metaRow('Uploaded', (string)($photo['created_at'] ?? '')) . '
                    ' . $this->metaRow('Size', $this->formatBytes((int)($photo['original_bytes'] ?? 0))) . '
                    ' . $this->metaRow('Original', strtoupper((string)($photo['original_extension'] ?? 'CR2'))) . '
                    ' . $this->metaRow('Events', trim((string)($photo['event_names'] ?? '')) !== '' ? (string)$photo['event_names'] : 'Unassigned') . '
                    ' . $this->metaRow('Storage', (string)($photo['location_label'] ?? 'Default storage')) . '
                    ' . $this->metaRow('SHA-256', (string)($photo['original_sha256'] ?? '')) . '
                </dl>
            </div>
        </div>';
    }

    private function mediaMarkup(array $photo): string
    {
        $photoId = (int)($photo['id'] ?? 0);
        $filename = (string)($photo['original_filename'] ?? 'Photo');
        $type = !empty($photo['preview_ready']) ? 'preview' : (!empty($photo['embedded_ready']) ? 'embedded' : '');

        if ($type === '') {
            return '<div class="picture-viewer-placeholder">Preview pending</div>';
        }

        $assetUrl = '/api/photo-asset.php?photo_id=' . rawurlencode((string)$photoId) . '&type=' . rawurlencode($type);

        return '<img src="' . HelperFramework::escape($assetUrl) . '" alt="' . HelperFramework::escape($filename) . '">';
    }

    private function statusBadges(array $photo): string
    {
        $conversionState = $this->labelFromState((string)($photo['conversion_state'] ?? 'pending'));
        $uploadState = $this->labelFromState((string)($photo['upload_state'] ?? 'uploaded'));
        $preview = !empty($photo['preview_ready']) ? 'Preview ready' : (!empty($photo['embedded_ready']) ? 'Embedded ready' : 'Preview pending');
        $final = !empty($photo['final_ready']) ? 'Final ready' : 'Final pending';

        return '<div class="picture-status-row">
            <span class="badge">' . HelperFramework::escape($uploadState) . '</span>
            <span class="badge">' . HelperFramework::escape($conversionState) . '</span>
            <span class="badge">' . HelperFramework::escape($preview) . '</span>
            <span class="badge">' . HelperFramework::escape($final) . '</span>
        </div>';
    }

    private function metaRow(string $label, string $value): string
    {
        return '<div><dt>' . HelperFramework::escape($label) . '</dt><dd>' . HelperFramework::escape($value) . '</dd></div>';
    }

    private function labelFromState(string $state): string
    {
        $state = trim(str_replace('_', ' ', $state));

        return $state !== '' ? ucwords($state) : 'Unknown';
    }

    private function formatBytes(int $bytes): string
    {
        $value = max(0.0, (float)$bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, $unitIndex === 0 ? 0 : 1) . ' ' . $units[$unitIndex];
    }

    private function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }
}
