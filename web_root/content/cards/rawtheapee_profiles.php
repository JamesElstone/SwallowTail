<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailRawTheapeeProfileService;

final class _rawtheapee_profilesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'rawtheapee_profiles';
    }

    public function title(): string
    {
        return 'RawTheapee Profiles';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'rawtheapee_profiles_dashboard',
                'service' => SwallowtailRawTheapeeProfileService::class,
                'method' => 'dashboard',
                'params' => [
                    'profileId' => ':rawtheapee_profiles.profile_id',
                    'photoId' => ':rawtheapee_profiles.photo_id',
                    'userId' => ':auth.user_id',
                    'showPreview' => ':rawtheapee_profiles.sample.success',
                ],
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'Sample discovered RawTheapee PP3 profiles against an accessible photo.';
    }

    public function handle(RequestFramework $request, PageServiceFramework $services, array $pageContext, ActionResultFramework $actionResult): array
    {
        $current = (array)($pageContext[$this->key()] ?? []);
        $actionContext = (array)($actionResult->context()[$this->key()] ?? []);
        $requestContext = [
            'profile_id' => max(0, (int)$request->input('rawtheapee_profile_id', (int)($actionContext['profile_id'] ?? $current['profile_id'] ?? 0))),
            'photo_id' => max(0, (int)$request->input('rawtheapee_photo_id', (int)($actionContext['photo_id'] ?? $current['photo_id'] ?? 0))),
        ];

        $pageContext[$this->key()] = array_replace($current, $requestContext, $actionContext);

        return $pageContext;
    }

    public function render(array $context): string
    {
        $state = (array)($context[$this->key()] ?? []);
        $sample = (array)($state['sample'] ?? []);
        $dashboard = $this->dashboard($context);
        $profiles = (array)($dashboard['profiles'] ?? []);
        $profileId = max(0, (int)($dashboard['profile_id'] ?? 0));
        $photoId = max(0, (int)($dashboard['photo_id'] ?? 0));
        $photo = is_array($dashboard['photo'] ?? null) ? (array)$dashboard['photo'] : null;
        $asset = is_array($dashboard['asset'] ?? null) ? (array)$dashboard['asset'] : null;
        $showPreview = !empty($dashboard['show_preview']);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<div class="form-grid">
            ' . $this->controlForm($profiles, $profileId, $photoId, $csrfToken) . '
            ' . $this->sampleResult($sample) . '
            ' . $this->photoPreview($photoId, $photo, $asset, $showPreview) . '
        </div>';
    }

    private function dashboard(array $context): array
    {
        return (array)(($context['services'] ?? [])['rawtheapee_profiles_dashboard'] ?? []);
    }

    private function controlForm(array $profiles, int $profileId, int $photoId, string $csrfToken): string
    {
        $options = '';
        foreach ($profiles as $profile) {
            $id = (int)($profile['id'] ?? 0);
            $options .= '<option value="' . HelperFramework::escape((string)$id) . '"' . ($id === $profileId ? ' selected' : '') . '>' . HelperFramework::escape((string)($profile['display_label'] ?? $profile['relative_path'] ?? 'Profile')) . '</option>';
        }
        if ($options === '') {
            $options = '<option value="0">No profiles found</option>';
        }

        return '<form method="post" action="?page=profiles" data-ajax="true" class="form-row full">
            <input type="hidden" name="cards[]" value="rawtheapee_profiles">
            <input type="hidden" name="card_action" value="RawTheapeeProfiles">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <input type="hidden" name="rawtheapee_profiles_action" value="test">
            <input type="hidden" name="rawtheapee_photo_id" value="' . HelperFramework::escape((string)$photoId) . '">
            <label for="rawtheapee-profile-id">Profile</label>
            <div class="input-action-row">
                <select id="rawtheapee-profile-id" name="rawtheapee_profile_id">' . $options . '</select>
                <button class="button button-inline primary" type="submit" data-submit-field="rawtheapee_profiles_action" data-submit-value="test" data-processing-text="Queueing" data-processing-state="disabled">Show Profile Effect</button>
                <button class="button button-inline" type="submit" data-submit-field="rawtheapee_profiles_action" data-submit-value="change_photo" data-processing-text="Changing" data-processing-state="disabled">Change random Photo</button>
                <button class="button button-inline" type="submit" data-submit-field="rawtheapee_profiles_action" data-submit-value="refresh" data-processing-text="Refreshing" data-processing-state="disabled">Refresh Profiles</button>
            </div>
        </form>';
    }

    private function sampleResult(array $sample): string
    {
        if (empty($sample['success'])) {
            return '';
        }

        return '<div class="panel-soft full">Sample queued. Job ' . HelperFramework::escape((string)($sample['job_id'] ?? '')) . '</div>';
    }

    private function photoPreview(int $photoId, ?array $photo, ?array $asset, bool $showPreview): string
    {
        if (!$showPreview) {
            return '<div class="panel-soft full">Press Test to see</div>';
        }

        if ($photoId <= 0 || $photo === null) {
            return '<div class="panel-soft warn full">No accessible photo is available.</div>';
        }

        if ($asset === null) {
            return '<div class="panel-soft warn full">No preview image is available.</div>';
        }

        $type = (string)($asset['image_type'] ?? 'thumbnail');
        $version = (string)($asset['sha256'] ?? '');

        return '<div class="full gallery-grid">
            <span class="gallery-tile">
                <span class="gallery-thumb"><img src="/api/photo-imaging.php?' . HelperFramework::escape(http_build_query([
                    'photo_id' => $photoId,
                    'type' => $type,
                    'v' => $version,
                ])) . '" alt="' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo')) . '"></span>
                <span class="gallery-meta">
                    <strong>' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo')) . '</strong>
                    <span class="badge info">' . HelperFramework::escape($type) . '</span>
                </span>
            </span>
        </div>';
    }

}
