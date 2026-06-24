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
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        $crop = (array)$settings['crop'];
        $exposure = (array)$settings['exposure'];

        return '<div class="picture-editor"
                data-picture-editor="true"
                data-photo-id="' . HelperFramework::escape((string)$photoId) . '"
                data-csrf-token="' . HelperFramework::escape($csrfToken) . '"
                data-profile-url="/api/photo-preview-profile.php"
                data-source-width="' . HelperFramework::escape((string)$sourceWidth) . '"
                data-source-height="' . HelperFramework::escape((string)$sourceHeight) . '"
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
                ' . $this->rangeField('black', 'Black', (float)($exposure['black'] ?? 0), -100, 100, 1) . '
                ' . $this->rangeField('lightness', 'Lightness', (float)($exposure['lightness'] ?? 0), -100, 100, 1) . '
                ' . $this->rangeField('contrast', 'Contrast', (float)($exposure['contrast'] ?? 0), -100, 100, 1) . '
                ' . $this->rangeField('saturation', 'Saturation', (float)($exposure['saturation'] ?? 0), -100, 100, 1) . '
                <div class="picture-editor-readout">
                    <span>Crop</span>
                    <output data-picture-editor-crop-readout>'
                        . HelperFramework::escape((string)(int)($crop['width'] ?? $sourceWidth))
                        . ' x '
                        . HelperFramework::escape((string)(int)($crop['height'] ?? $sourceHeight))
                    . '</output>
                </div>
                <button class="button button-inline picture-editor-revert" type="button" data-picture-editor-revert>Revert to Original</button>
            </div>
        </div>';
    }

    private function rangeField(string $key, string $label, float $value, int $min, int $max, int $step): string
    {
        $id = 'picture-editor-' . $key;
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
