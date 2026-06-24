<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _picture_editorCard extends CardBaseFramework
{
    public function title(): string
    {
        return 'Picture Editor';
    }

    public function helper(array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $photoId = max(0, (int)($context['page']['photo_id'] ?? 0));
        if ($photoId <= 0) {
            return '<div class="panel-soft warn full">No photo selected.</div>';
        }

        $userId = $this->currentUserId();
        $state = (new SwallowtailPreviewProfileService())->editorState($photoId, $userId);
        if ($state === null) {
            return '<div class="panel-soft warn full">Photo was not found or is not available to this user.</div>';
        }

        $photo = (array)$state['photo'];
        $settings = (array)$state['settings'];
        $sourceWidth = max(1, (int)$state['source_width']);
        $sourceHeight = max(1, (int)$state['source_height']);
        $previewReady = !empty($state['preview_ready']);
        $previewUrl = $previewReady ? (string)$state['preview_url'] : '';
        $previewType = (string)($state['preview_type'] ?? '');
        $baseline = (array)($state['baseline'] ?? []);
        $baselineReady = !empty($baseline['ready']);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        $crop = (array)$settings['crop'];
        $exposure = (array)$settings['exposure'];
        $whiteBalance = (array)($settings['white_balance'] ?? []);
        $shadowsHighlights = (array)($settings['shadows_highlights'] ?? []);
        $rotation = (array)($settings['rotation'] ?? []);
        $perspective = (array)($settings['perspective'] ?? []);

        return '<div class="picture-editor"
                data-picture-editor="true"
                data-photo-id="' . HelperFramework::escape((string)$photoId) . '"
                data-csrf-token="' . HelperFramework::escape($csrfToken) . '"
                data-profile-url="/api/photo-preview-profile.php"
                data-profile-status-url="/api/photo-profile-status.php?photo_id=' . HelperFramework::escape((string)$photoId) . '"
                data-source-width="' . HelperFramework::escape((string)$sourceWidth) . '"
                data-source-height="' . HelperFramework::escape((string)$sourceHeight) . '"
                data-preview-type="' . HelperFramework::escape($previewType) . '"
                data-baseline-ready="' . ($baselineReady ? '1' : '0') . '"
                data-settings="' . HelperFramework::escape(json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '">
            <div class="picture-editor-main">
                <div class="picture-editor-stage" data-picture-editor-stage>
                    ' . ($previewReady
                        ? '<img src="' . HelperFramework::escape($previewUrl) . '" alt="' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo preview')) . '" data-picture-editor-image>'
                        : '<div class="picture-editor-empty" data-picture-editor-empty>Preview pending</div>') . '
                    <div class="picture-editor-crop" data-picture-editor-crop>
                        <span data-picture-editor-handle="nw"></span>
                        <span data-picture-editor-handle="ne"></span>
                        <span data-picture-editor-handle="sw"></span>
                        <span data-picture-editor-handle="se"></span>
                    </div>
                </div>
                <div class="picture-editor-status" data-picture-editor-status>' . ($previewReady ? 'Ready' : 'Preview pending') . '</div>
            </div>
            <div class="picture-editor-controls">
                <div class="picture-editor-profile-state" data-picture-editor-profile-state>' . ($baselineReady ? 'Profile ready' : 'Preparing profile') . '</div>
                ' . $this->accordionPanel('Exposure',
                    $this->checkboxField('exposure.enabled', 'Enabled', !empty($exposure['enabled']))
                    . $this->rangeField('exposure.black', 'Black', (float)($exposure['black'] ?? 0), -100, 100, 1)
                    . $this->rangeField('exposure.lightness', 'Lightness', (float)($exposure['lightness'] ?? 0), -100, 100, 1)
                    . $this->rangeField('exposure.contrast', 'Contrast', (float)($exposure['contrast'] ?? 0), -100, 100, 1)
                    . $this->rangeField('exposure.saturation', 'Saturation', (float)($exposure['saturation'] ?? 0), -100, 100, 1)
                ) . '
                ' . $this->accordionPanel('Crop',
                    $this->checkboxField('crop.enabled', 'Enabled', !empty($crop['enabled']))
                    . '<div class="picture-editor-readout">
                        <span>Coordinates</span>
                        <output data-picture-editor-crop-readout>'
                            . HelperFramework::escape((string)(int)($crop['x'] ?? 0))
                            . ', '
                            . HelperFramework::escape((string)(int)($crop['y'] ?? 0))
                            . ' '
                            . HelperFramework::escape((string)(int)($crop['width'] ?? $sourceWidth))
                            . ' x '
                            . HelperFramework::escape((string)(int)($crop['height'] ?? $sourceHeight))
                        . '</output>
                    </div>
                    <div class="picture-editor-readout" data-picture-editor-crop-state>Crop follows original/filtered previews.</div>'
                ) . '
                ' . $this->accordionPanel('White Balance',
                    $this->checkboxField('white_balance.enabled', 'Enabled', !empty($whiteBalance['enabled']))
                    . $this->rangeField('white_balance.temperature', 'Temperature', (float)($whiteBalance['temperature'] ?? 5324), 1500, 60000, 1)
                    . $this->rangeField('white_balance.green', 'Tint', (float)($whiteBalance['green'] ?? 0.846), 0.02, 5, 0.001)
                ) . '
                ' . $this->accordionPanel('Shadows & Highlights',
                    $this->checkboxField('shadows_highlights.enabled', 'Enabled', !empty($shadowsHighlights['enabled']))
                    . $this->rangeField('shadows_highlights.highlights', 'Highlights', (float)($shadowsHighlights['highlights'] ?? 30), 0, 100, 1)
                    . $this->rangeField('shadows_highlights.highlight_tonal_width', 'Highlight Tonal Width', (float)($shadowsHighlights['highlight_tonal_width'] ?? 80), 0, 100, 1)
                    . $this->rangeField('shadows_highlights.shadows', 'Shadows', (float)($shadowsHighlights['shadows'] ?? 30), 0, 100, 1)
                    . $this->rangeField('shadows_highlights.shadow_tonal_width', 'Shadow Tonal Width', (float)($shadowsHighlights['shadow_tonal_width'] ?? 80), 0, 100, 1)
                    . $this->rangeField('shadows_highlights.local_contrast', 'Local Contrast', (float)($shadowsHighlights['local_contrast'] ?? 0), 0, 100, 1)
                    . $this->rangeField('shadows_highlights.radius', 'Radius', (float)($shadowsHighlights['radius'] ?? 40), 1, 100, 1)
                    . $this->checkboxField('shadows_highlights.lab', 'High Quality', !empty($shadowsHighlights['lab']))
                ) . '
                ' . $this->accordionPanel('Rotation',
                    $this->checkboxField('rotation.enabled', 'Enabled', !empty($rotation['enabled']))
                    . $this->rangeField('rotation.degree', 'Degrees', (float)($rotation['degree'] ?? 0), -45, 45, 0.1)
                ) . '
                ' . $this->accordionPanel('Perspective',
                    $this->checkboxField('perspective.enabled', 'Enabled', !empty($perspective['enabled']))
                    . $this->rangeField('perspective.horizontal', 'Horizontal', (float)($perspective['horizontal'] ?? 0), -100, 100, 1)
                    . $this->rangeField('perspective.vertical', 'Vertical', (float)($perspective['vertical'] ?? 0), -100, 100, 1)
                ) . '
                <button class="button button-inline picture-editor-revert" type="button" data-picture-editor-revert>Revert to Baseline</button>
            </div>
        </div>';
    }

    private function accordionPanel(string $heading, string $body): string
    {
        return '<details class="picture-editor-panel" data-picture-editor-panel>
            <summary>' . HelperFramework::escape($heading) . '</summary>
            <div class="picture-editor-panel-body">' . $body . '</div>
        </details>';
    }

    private function checkboxField(string $key, string $label, bool $checked): string
    {
        $id = 'picture-editor-' . str_replace('.', '-', $key);

        return '<label class="picture-editor-toggle" for="' . HelperFramework::escape($id) . '">
            <input id="' . HelperFramework::escape($id) . '" type="checkbox" value="1" ' . ($checked ? 'checked' : '') . ' data-picture-editor-check="' . HelperFramework::escape($key) . '">
            <span>' . HelperFramework::escape($label) . '</span>
        </label>';
    }

    private function rangeField(string $key, string $label, float $value, int|float $min, int|float $max, int|float $step): string
    {
        $id = 'picture-editor-' . str_replace('.', '-', $key);
        $value = max($min, min($max, $value));

        return '<label class="picture-editor-field" for="' . HelperFramework::escape($id) . '">
            <span>' . HelperFramework::escape($label) . '</span>
            <input id="' . HelperFramework::escape($id) . '" type="range" min="' . (string)$min . '" max="' . (string)$max . '" step="' . (string)$step . '" value="' . HelperFramework::escape((string)$value) . '" data-picture-editor-field="' . HelperFramework::escape($key) . '">
            <input type="number" min="' . (string)$min . '" max="' . (string)$max . '" step="' . (string)$step . '" value="' . HelperFramework::escape((string)$value) . '" data-picture-editor-number="' . HelperFramework::escape($key) . '">
        </label>';
    }

    private function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $deviceId = (string)AntiFraudService::instance()->requestValue('Client-Device-ID');

        return $sessionAuthenticationService->authenticatedUserId($deviceId);
    }
}
