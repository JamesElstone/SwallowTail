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

        $userId = $this->currentUserId();
        $photo = (new SwallowtailPhotoUiService())->photoDetails($photoId, $userId, false);
        if ($photo === null) {
            return '<div class="panel-soft warn">The selected photo was not found or is not available to your account.</div>';
        }

        $viewerState = [
            'display_type' => '',
            'display_url' => '',
            'final_status' => 'queued',
            'state_url' => '/api/photo-info.php?view=viewer&photo_id=' . rawurlencode((string)$photoId),
        ];
        $metadata = $this->metadataForPhoto($photoId);

        return '<div class="picture-viewer-layout is-details-collapsed" data-picture-viewer-layout>
            <div class="picture-viewer-media"
                data-picture-viewer="true"
                data-picture-viewer-state-url="' . HelperFramework::escape((string)($viewerState['state_url'] ?? '')) . '"
                data-picture-viewer-status="' . HelperFramework::escape((string)($viewerState['final_status'] ?? 'queued')) . '"
                data-picture-viewer-display-type="' . HelperFramework::escape((string)($viewerState['display_type'] ?? '')) . '">
                <div class="picture-viewer-overlay-actions">
                    <a class="button button-inline picture-viewer-maximized-only" href="?page=gallery">Back to Gallery</a>
                </div>
                <button class="button button-inline picture-viewer-details-toggle picture-viewer-details-open-toggle" type="button" data-picture-viewer-details-open aria-expanded="false" aria-label="Show image details">&lt;</button>
                ' . $this->renderStatusPill((string)($viewerState['final_status'] ?? 'queued')) . '
                <span class="picture-viewer-image-type" data-picture-viewer-image-type-label>' . HelperFramework::escape($this->imageTypeLabel((string)($viewerState['display_type'] ?? ''))) . '</span>
                ' . $this->mediaMarkup($photo, $viewerState) . '
                <button class="picture-viewer-fullscreen-close" type="button" data-picture-viewer-fullscreen-close aria-label="Exit full screen" hidden>&times;</button>
            </div>
            <div class="picture-viewer-details">
                <div class="picture-viewer-details-header">
                    <button class="button button-inline picture-viewer-details-toggle" type="button" data-picture-viewer-details-close aria-expanded="true" aria-label="Hide image details">&gt;</button>
                    <h3>' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo')) . '</h3>
                </div>
                ' . $this->detailsTabs($photo, $metadata) . '
            </div>
        </div>';
    }

    private function mediaMarkup(array $photo, array $viewerState): string
    {
        $filename = (string)($photo['original_filename'] ?? 'Photo');
        $assetUrl = (string)($viewerState['display_url'] ?? '');
        $type = (string)($viewerState['display_type'] ?? '');

        if ($assetUrl === '' || $type === '') {
            return '<div class="picture-viewer-placeholder" data-picture-viewer-placeholder>Image queued</div>';
        }

        return '<img src="' . HelperFramework::escape($assetUrl) . '" alt="' . HelperFramework::escape($filename) . '" data-picture-viewer-image data-picture-viewer-image-type="' . HelperFramework::escape($type) . '">';
    }

    private function renderStatusPill(string $status): string
    {
        $status = in_array($status, ['queued', 'rendering', 'loaded'], true) ? $status : 'queued';

        return '<span class="picture-viewer-status-pill" data-picture-viewer-status-pill data-picture-viewer-state="' . HelperFramework::escape($status) . '">' . HelperFramework::escape($this->labelFromState($status)) . '</span>';
    }

    private function metaRow(string $label, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = 'Unknown';
        }

        return '<div><dt>' . HelperFramework::escape($label) . '</dt><dd>' . HelperFramework::escape($value) . '</dd></div>';
    }

    private function labelFromState(string $state): string
    {
        $state = trim(str_replace('_', ' ', $state));

        return $state !== '' ? ucwords($state) : 'Unknown';
    }

    private function imageTypeLabel(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));

        return match ($imageType) {
            'embedded' => 'Embedded',
            'thumbnail' => 'Thumbnail',
            'original' => 'Original',
            'preview' => 'Preview',
            'final' => 'Final',
            default => $imageType === '' ? 'Queued' : ucwords(str_replace('_', ' ', $imageType)),
        };
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

    private function detailsTabs(array $photo, array $metadata): string
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        $prefix = 'picture-details-' . (string)$photoId;
        $cameraMake = trim((string)($metadata['camera_make'] ?? ''));
        $cameraType = strtolower($cameraMake);
        $cameraLabel = $cameraMake !== '' ? $cameraMake : 'Camera';
        $tabs = [
            'info' => [
                'label' => 'Info',
                'content' => $this->infoTab($photo, $metadata),
            ],
            'exif' => [
                'label' => 'Exif',
                'content' => $this->lazyDetailsPlaceholder(),
                'load_url' => $this->detailsTabLoadUrl($photoId, 'exif'),
            ],
            'camera' => [
                'label' => $cameraLabel,
                'content' => $this->lazyDetailsPlaceholder(),
                'load_url' => $this->detailsTabLoadUrl($photoId, 'camera'),
            ],
            'pp3' => [
                'label' => 'PP3',
                'content' => $this->lazyDetailsPlaceholder(),
                'load_url' => $this->detailsTabLoadUrl($photoId, 'pp3'),
            ],
        ];

        $inputs = '';
        $labels = '';
        $panels = '';
        $index = 0;
        foreach ($tabs as $key => $tab) {
            $index++;
            $id = $prefix . '-' . $key;
            $inputs .= '<input class="picture-details-tab-input" type="radio" name="' . HelperFramework::escape($prefix) . '" id="' . HelperFramework::escape($id) . '"' . ($index === 1 ? ' checked' : '') . '>';
            $labels .= '<label class="picture-details-tab" for="' . HelperFramework::escape($id) . '">' . HelperFramework::escape((string)$tab['label']) . '</label>';
            $loadUrl = (string)($tab['load_url'] ?? '');
            $panels .= '<section class="picture-details-tab-panel" data-picture-details-panel' . ($loadUrl !== '' ? ' data-picture-details-load-url="' . HelperFramework::escape($loadUrl) . '"' : '') . '>' . (string)$tab['content'] . '</section>';
        }

        return '<div class="picture-details-tabs">
            ' . $inputs . '
            <div class="picture-details-tablist">' . $labels . '</div>
            <div class="picture-details-tab-panels">' . $panels . '</div>
        </div>';
    }

    public function lazyDetailsTabContent(int $photoId, int $userId, string $tab): array
    {
        $tab = strtolower(trim($tab));
        if ($photoId <= 0 || $userId <= 0 || !in_array($tab, ['exif', 'camera', 'pp3'], true)) {
            return [
                'success' => false,
                'errors' => ['Details tab was not found.'],
            ];
        }

        if (!(new SwallowtailPhotoUiService())->userCanViewPhoto($photoId, $userId)) {
            return [
                'success' => false,
                'errors' => ['Photo was not found.'],
            ];
        }

        if ($tab === 'exif') {
            return [
                'success' => true,
                'html' => $this->propertiesTab($this->metadataProperties($photoId, 'exififd')),
            ];
        }

        if ($tab === 'camera') {
            $metadata = $this->metadataForPhoto($photoId);
            $cameraType = strtolower(trim((string)($metadata['camera_make'] ?? '')));

            return [
                'success' => true,
                'html' => $cameraType !== ''
                    ? $this->propertiesTab($this->metadataProperties($photoId, $cameraType))
                    : '<p class="helper">Camera make metadata is not available.</p>',
            ];
        }

        return [
            'success' => true,
            'html' => $this->pp3Tab($photoId),
        ];
    }

    private function lazyDetailsPlaceholder(): string
    {
        return '<p class="helper" data-picture-details-placeholder>Loading...</p>';
    }

    private function detailsTabLoadUrl(int $photoId, string $tab): string
    {
        return '/api/photo-info.php?' . http_build_query([
            'view' => 'details',
            'photo_id' => $photoId,
            'tab' => $tab,
        ]);
    }

    private function infoTab(array $photo, array $metadata): string
    {
        return '<dl class="picture-meta-list">
            ' . $this->metaRow('Uploaded', (string)($photo['created_at'] ?? '')) . '
            ' . $this->metaRow('Uploaded By', $this->uploadedByLabel($photo)) . '
            ' . $this->metaRow('Uploaded Via', (string)($photo['uploaded_via'] ?? '')) . '
            ' . $this->metaRow('Size', $this->formatBytes((int)($photo['original_bytes'] ?? 0))) . '
            ' . $this->metaRow('Original', strtoupper((string)($photo['original_extension'] ?? 'CR2'))) . '
            ' . $this->metaRow('Events', trim((string)($photo['event_names'] ?? '')) !== '' ? (string)$photo['event_names'] : 'Unassigned') . '
            ' . $this->metaRow('Storage Location', (string)($photo['location_label'] ?? 'Default storage')) . '
            ' . $this->metaRow('SHA-256', (string)($photo['original_sha256'] ?? '')) . '
            ' . $this->metaRow('Captured At', (string)($metadata['captured_at'] ?? ($metadata['captured_at_local'] ?? ''))) . '
            ' . $this->metaRow('Camera Timezone', (string)($metadata['camera_timezone_city_label'] ?? '')) . '
            ' . $this->metaRow('Camera Model', (string)($metadata['camera_model'] ?? '')) . '
            ' . $this->metaRow('Lens Model', (string)($metadata['lens_model'] ?? '')) . '
            ' . $this->metaRow('ISO', (string)($metadata['iso'] ?? '')) . '
            ' . $this->metaRow('Shutter Speed', (string)($metadata['shutter_speed'] ?? '')) . '
            ' . $this->metaRow('Focal Length', $this->formatDecimal($metadata['focal_length_mm'] ?? null, ' mm')) . '
            ' . $this->metaRow('Pixel Width', (string)($metadata['pixel_width'] ?? '')) . '
            ' . $this->metaRow('Pixel Height', (string)($metadata['pixel_height'] ?? '')) . '
        </dl>';
    }

    private function propertiesTab(array $properties): string
    {
        if ($properties === []) {
            return '<p class="helper">No metadata properties are available.</p>';
        }

        $rows = '';
        foreach ($properties as $property) {
            $label = $this->displayMetadataKey((string)($property['key'] ?? ''));
            $value = $this->typedMetadataValue($property['value'] ?? null, (string)($property['value_type'] ?? 'string'));
            if (trim($value) === '') {
                $value = 'Unknown';
            }

            $rows .= '<tr>
                <th scope="row">' . HelperFramework::escape($label) . '</th>
                <td>' . HelperFramework::escape($value) . '</td>
            </tr>';
        }

        return '<table class="picture-property-table"><tbody>' . $rows . '</tbody></table>';
    }

    private function displayMetadataKey(string $key): string
    {
        $key = trim(str_replace(['_', '-'], ' ', $key));
        $key = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $key) ?? $key;
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;

        return trim($key);
    }

    private function pp3Tab(int $photoId): string
    {
        if ($photoId <= 0) {
            return '<p class="helper">PP3 settings are not available.</p>';
        }

        try {
            $profile = (new SwallowtailCombinedProfileService())->combinedProfileContent($photoId, 'final');
        } catch (Throwable) {
            $profile = '';
        }

        $profile = trim($profile);
        if ($profile === '') {
            return '<p class="helper">PP3 settings are not available.</p>';
        }

        return '<pre class="picture-details-pp3">' . HelperFramework::escape($profile) . '</pre>';
    }

    private function metadataForPhoto(int $photoId): array
    {
        if ($photoId <= 0 || !InterfaceDB::tableExists('photo_metadata')) {
            return [];
        }

        $metadata = InterfaceDB::fetchOne(
            'SELECT *
             FROM photo_metadata
             WHERE photo_id = :photo_id
             LIMIT 1',
            ['photo_id' => $photoId]
        );

        return is_array($metadata) ? $metadata : [];
    }

    private function metadataProperties(int $photoId, string $type): array
    {
        $type = strtolower(trim($type));
        if ($photoId <= 0 || $type === '' || !InterfaceDB::tableExists('photo_metadata_property')) {
            return [];
        }

        return InterfaceDB::fetchAll(
            "SELECT `key`, value, value_type
             FROM photo_metadata_property
             WHERE photo_id = :photo_id
               AND type = :type
             ORDER BY LOWER(`key`), `key`",
            [
                'photo_id' => $photoId,
                'type' => $type,
            ]
        );
    }

    private function uploadedByLabel(array $photo): string
    {
        $userId = (int)($photo['uploaded_by_user_id'] ?? 0);
        if ($userId <= 0 || !InterfaceDB::tableExists('users')) {
            return '';
        }

        $user = InterfaceDB::fetchOne(
            'SELECT display_name, email_address
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $userId]
        );
        if (!is_array($user)) {
            return 'User ' . (string)$userId;
        }

        $displayName = trim((string)($user['display_name'] ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }

        $emailAddress = trim((string)($user['email_address'] ?? ''));
        return $emailAddress !== '' ? $emailAddress : 'User ' . (string)$userId;
    }

    private function typedMetadataValue(mixed $value, string $valueType): string
    {
        return match (strtolower(trim($valueType))) {
            'null' => '',
            'bool' => ((string)$value === '1' || strtolower((string)$value) === 'true') ? 'true' : 'false',
            default => trim((string)$value),
        };
    }

    private function formatDecimal(mixed $value, string $suffix = ''): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_numeric($value)) {
            return trim((string)$value) . $suffix;
        }

        return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.') . $suffix;
    }

    private function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }
}
