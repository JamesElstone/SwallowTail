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
        $photoId = (int)($context['page']['photo_id'] ?? 0);
        if ($photoId <= 0) {
            return 'Preview and metadata for the selected photo.';
        }

        $photo = (new SwallowtailPhotoUiService())->photoDetails($photoId, $this->currentUserId());
        if ($photo === null) {
            return 'Preview and metadata for the selected photo.';
        }

        return $this->photoHelperText($photo, $this->metadataForPhoto($photoId));
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
        $type = !empty($photo['filtered_ready']) ? 'filtered' : (!empty($photo['original_ready']) ? 'original' : (!empty($photo['thumbnail_ready']) ? 'thumbnail' : ''));

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
        $preview = !empty($photo['filtered_ready']) ? 'Filtered ready' : (!empty($photo['original_ready']) ? 'Original JPG ready' : 'Preview pending');
        $thumbnail = !empty($photo['thumbnail_ready']) ? 'Thumbnail ready' : 'Thumbnail pending';

        return '<div class="picture-status-row">
            <span class="badge">' . HelperFramework::escape($uploadState) . '</span>
            <span class="badge">' . HelperFramework::escape($conversionState) . '</span>
            <span class="badge">' . HelperFramework::escape($preview) . '</span>
            <span class="badge">' . HelperFramework::escape($thumbnail) . '</span>
        </div>';
    }

    private function metaRow(string $label, string $value): string
    {
        return '<div><dt>' . HelperFramework::escape($label) . '</dt><dd>' . HelperFramework::escape($value) . '</dd></div>';
    }

    private function metadataForPhoto(int $photoId): array
    {
        if ($photoId <= 0) {
            return [];
        }

        try {
            if (!InterfaceDB::tableExists('photo_metadata')) {
                return [];
            }

            $metadata = InterfaceDB::fetchOne(
                'SELECT camera_model, lens_model, iso, shutter_speed, aperture, focal_length_mm
                 FROM photo_metadata
                 WHERE photo_id = :photo_id
                 LIMIT 1',
                ['photo_id' => $photoId]
            );
        } catch (Throwable) {
            return [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function photoHelperText(array $photo, array $metadata): string
    {
        $filename = trim((string)($photo['original_filename'] ?? ''));
        if ($filename === '') {
            $filename = 'Photo';
        }

        $cameraModel = trim((string)($metadata['camera_model'] ?? ''));
        $lensModel = trim((string)($metadata['lens_model'] ?? ''));
        $exposureParts = [];

        $iso = (int)($metadata['iso'] ?? 0);
        if ($iso > 0) {
            $exposureParts[] = (string)$iso . 'ASA';
        }

        $shutter = $this->formatShutterSpeed($metadata['shutter_speed'] ?? null);
        if ($shutter !== '') {
            $exposureParts[] = $shutter;
        }

        $aperture = $this->formatDecimal($metadata['aperture'] ?? null);
        if ($aperture !== '') {
            $exposureParts[] = $aperture;
        }

        $focalLength = $this->formatDecimal($metadata['focal_length_mm'] ?? null);
        if ($focalLength !== '') {
            $exposureParts[] = '@ ' . $focalLength . 'mm';
        }

        if ($cameraModel === '' && $lensModel === '' && count($exposureParts) === 0) {
            return $filename;
        }

        $summary = $filename . ' : ' . ($cameraModel !== '' ? $cameraModel : 'Unknown camera');
        if ($lensModel !== '') {
            $summary .= ' with ' . $lensModel;
        }
        if (count($exposureParts) > 0) {
            $summary .= ' [ ' . implode(' ', $exposureParts) . ' ]';
        }

        return $summary;
    }

    private function formatShutterSpeed(mixed $value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $seconds = null;
        if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $raw, $matches) === 1) {
            $numerator = (float)$matches[1];
            $denominator = (float)$matches[2];
            if ($denominator > 0.0) {
                $seconds = $numerator / $denominator;
            }
        } elseif (is_numeric($raw)) {
            $seconds = (float)$raw;
        }

        if ($seconds === null || $seconds <= 0.0) {
            return $raw;
        }

        return (string)max(1, (int)round(1 / $seconds)) . 'ms';
    }

    private function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_numeric($value)) {
            return trim((string)$value);
        }

        return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
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
