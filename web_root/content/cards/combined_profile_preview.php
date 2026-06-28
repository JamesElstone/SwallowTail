<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailCombinedProfilePreviewService;

final class _combined_profile_previewCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'combined_profile_preview';
    }

    public function title(): string
    {
        return 'Combined Profile Preview';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'combined_profile_preview_dashboard',
                'service' => SwallowtailCombinedProfilePreviewService::class,
                'method' => 'dashboard',
                'params' => [
                    'photoId' => ':combined_profile_preview.photo_id',
                    'imageType' => ':combined_profile_preview.image_type',
                    'userId' => ':auth.user_id',
                ],
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'View the combined photo PP3 plus internal overlays.';
    }

    public function handle(RequestFramework $request, PageServiceFramework $services, array $pageContext, ActionResultFramework $actionResult): array
    {
        $service = new SwallowtailCombinedProfilePreviewService();
        $current = (array)($pageContext[$this->key()] ?? []);
        $pageContext[$this->key()] = array_replace($current, [
            'image_type' => $service->normaliseImageType((string)$request->input('combined_profile_image_type', (string)($current['image_type'] ?? 'preview'))),
            'photo_id' => max(0, (int)$request->input('combined_profile_photo_id', (int)($current['photo_id'] ?? 0))),
        ]);

        return $pageContext;
    }

    public function render(array $context): string
    {
        $dashboard = $this->dashboard($context);
        $imageTypes = (array)($dashboard['image_types'] ?? SwallowtailCombinedProfilePreviewService::IMAGE_TYPES);
        $imageType = (string)($dashboard['image_type'] ?? 'preview');
        $photoId = max(0, (int)($dashboard['photo_id'] ?? 0));

        if ($photoId <= 0 || !is_array($dashboard['photo'] ?? null)) {
            return '<div class="panel-soft warn">No accessible photo is available.</div>';
        }

        $content = (string)($dashboard['content'] ?? '');

        return '<div class="form-grid">
            ' . $this->filterForm($imageTypes, $imageType, $photoId) . '
            <div class="form-row full">
                <textarea class="input preformatted-panel" readonly rows="22">' . HelperFramework::escape($content) . '</textarea>
            </div>
        </div>';
    }

    private function dashboard(array $context): array
    {
        return (array)(($context['services'] ?? [])['combined_profile_preview_dashboard'] ?? []);
    }

    private function filterForm(array $imageTypes, string $imageType, int $photoId): string
    {
        $options = '';
        foreach ($imageTypes as $type) {
            $type = (string)$type;
            $options .= '<option value="' . HelperFramework::escape($type) . '"' . ($type === $imageType ? ' selected' : '') . '>' . HelperFramework::escape($type) . '</option>';
        }

        return '<form method="post" action="?page=profiles" data-ajax="true" class="form-row full">
            <input type="hidden" name="cards[]" value="combined_profile_preview">
            <input type="hidden" name="_card_refresh" value="1">
            <input type="hidden" name="_invalidate_fact" value="combined.profile.preview">
            <input type="hidden" name="combined_profile_photo_id" value="' . HelperFramework::escape((string)$photoId) . '">
            <label for="combined-profile-image-type">Image type</label>
            <select id="combined-profile-image-type" name="combined_profile_image_type">' . $options . '</select>
        </form>';
    }
}
