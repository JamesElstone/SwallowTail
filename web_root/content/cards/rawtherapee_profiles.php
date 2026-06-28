<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailRawTherapeeProfileService;

final class _rawtherapee_profilesCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'rawtherapee_profiles';
    }

    public function title(): string
    {
        return 'RawTherapee Profiles';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'rawtherapee_profiles_dashboard',
                'service' => SwallowtailRawTherapeeProfileService::class,
                'method' => 'dashboard',
                'params' => [
                    'profileId' => ':rawtherapee_profiles.profile_id',
                    'photoId' => ':rawtherapee_profiles.photo_id',
                    'userId' => ':auth.user_id',
                    'showPreview' => ':rawtherapee_profiles.sample.success',
                    'displayUrl' => ':rawtherapee_profiles.display_url',
                    'displayType' => ':rawtherapee_profiles.display_type',
                ],
            ],
        ];
    }

    public function helper(array $context): string
    {
        return 'Sample discovered RawTherapee PP3 profiles against an accessible photo.';
    }

    public function handle(RequestFramework $request, PageServiceFramework $services, array $pageContext, ActionResultFramework $actionResult): array
    {
        $current = (array)($pageContext[$this->key()] ?? []);
        $actionContext = (array)($actionResult->context()[$this->key()] ?? []);
        $requestContext = [
            'profile_id' => max(0, (int)$request->input('rawtherapee_profile_id', (int)($actionContext['profile_id'] ?? $current['profile_id'] ?? 0))),
            'photo_id' => max(0, (int)$request->input('rawtherapee_photo_id', (int)($actionContext['photo_id'] ?? $current['photo_id'] ?? 0))),
            'display_url' => $this->normaliseDisplayUrl((string)$request->input('rawtherapee_display_url', (string)($actionContext['display_url'] ?? $current['display_url'] ?? ''))),
            'display_type' => $this->normaliseDisplayType((string)$request->input('rawtherapee_display_type', (string)($actionContext['display_type'] ?? $current['display_type'] ?? 'none'))),
            'photo_search' => $this->normalisePhotoSearch((string)$request->input('rawtherapee_photo_search', (string)($actionContext['photo_search'] ?? $current['photo_search'] ?? ''))),
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
        $defaultProfileId = max(0, (int)($dashboard['default_profile_id'] ?? 0));
        $photoId = max(0, (int)($dashboard['photo_id'] ?? 0));
        $photo = is_array($dashboard['photo'] ?? null) ? (array)$dashboard['photo'] : null;
        $asset = is_array($dashboard['asset'] ?? null) ? (array)$dashboard['asset'] : null;
        $displayUrl = (string)($dashboard['display_url'] ?? '');
        $displayType = (string)($dashboard['display_type'] ?? 'none');
        $showPreview = !empty($dashboard['show_preview']);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $profileLabel = $this->profileLabel($profiles, $profileId);
        $status = (string)($dashboard['status'] ?? $this->statusLabel($sample, $asset, $showPreview));
        $imageShown = $this->imageShownLabel($displayType, $showPreview);
        $statusUrl = (string)($sample['status_url'] ?? $dashboard['status_url'] ?? '');
        $photoSearch = $this->normalisePhotoSearch((string)($state['photo_search'] ?? ''));
        $photoSearchResults = (array)($state['photo_search_results'] ?? []);
        $photoSearchPerformed = !empty($state['photo_search_performed']);

        return '<div class="rawtherapee-profile-layout" data-rawtherapee-profile-panel="true" data-rawtherapee-profile-status-url="' . HelperFramework::escape($statusUrl) . '">
            <div class="rawtherapee-profile-controls">
                ' . $this->controlForm($profiles, $profileId, $defaultProfileId, $photoId, $displayUrl, $displayType, $csrfToken) . '
                ' . $this->photoSearchPanel($profileId, $photoId, $displayUrl, $displayType, $csrfToken, $photoSearch, $photoSearchResults, $photoSearchPerformed) . '
                ' . $this->details($status, $sample, $photo, $profileLabel, $imageShown) . '
            </div>
            ' . $this->photoPreview($photoId, $photo, $displayUrl, $displayType, $showPreview) . '
        </div>';
    }

    private function dashboard(array $context): array
    {
        return (array)(($context['services'] ?? [])['rawtherapee_profiles_dashboard'] ?? []);
    }

    private function controlForm(array $profiles, int $profileId, int $defaultProfileId, int $photoId, string $displayUrl, string $displayType, string $csrfToken): string
    {
        $options = '<option value="0"' . ($profileId === 0 ? ' selected' : '') . '>-- Current Profile --</option>';
        foreach ($profiles as $profile) {
            $id = (int)($profile['id'] ?? 0);
            $label = (string)($profile['display_label'] ?? $profile['relative_path'] ?? 'Profile');
            if ($id === $defaultProfileId) {
                $label .= ' (default)';
            }
            $options .= '<option value="' . HelperFramework::escape((string)$id) . '"' . ($id === $profileId ? ' selected' : '') . '>' . HelperFramework::escape($label) . '</option>';
        }
        if ($profiles === []) {
            $options .= '<option value="0" disabled>No RawTherapee profiles found</option>';
        }
        $defaultDisabled = $profileId <= 0 || $profileId === $defaultProfileId;
        $defaultDisabledAttr = $defaultDisabled ? ' disabled aria-disabled="true"' : '';

        return '<form method="post" action="?page=profiles" data-ajax="true" class="form-row rawtherapee-profile-form panel-soft">
            ' . $this->hiddenFields($profileId, $displayUrl, $displayType, $csrfToken, false) . '
            <input type="hidden" name="rawtherapee_profiles_action" value="test">
            <input type="hidden" name="rawtherapee_photo_id" value="' . HelperFramework::escape((string)$photoId) . '">
            <label for="rawtherapee-profile-id">Profile</label>
            <div class="input-action-row">
                <select id="rawtherapee-profile-id" name="rawtherapee_profile_id" data-rawtherapee-default-profile-id="' . HelperFramework::escape((string)$defaultProfileId) . '">' . $options . '</select>
                <button class="button button-inline" type="submit" data-submit-field="rawtherapee_profiles_action" data-submit-value="set_default" data-processing-text="Saving" data-processing-state="disabled" data-rawtherapee-set-default-button="true"' . $defaultDisabledAttr . '>Set Default</button>
                <button class="button button-inline" type="submit" data-submit-field="rawtherapee_profiles_action" data-submit-value="change_photo" data-processing-text="Changing" data-processing-state="disabled">Change Random Photo</button>
                <button class="button button-inline" type="submit" data-submit-field="rawtherapee_profiles_action" data-submit-value="refresh" data-processing-text="Refreshing" data-processing-state="disabled">Refresh Profiles</button>
            </div>
        </form>';
    }

    private function photoSearchPanel(
        int $profileId,
        int $photoId,
        string $displayUrl,
        string $displayType,
        string $csrfToken,
        string $photoSearch,
        array $results,
        bool $performed
    ): string {
        $resultHtml = '';
        if ($performed) {
            if ($photoSearch === '') {
                $resultHtml = '<p class="helper">Enter a filename, checksum, or photo ID.</p>';
            } elseif ($results === []) {
                $resultHtml = '<p class="helper">No accessible photo matched.</p>';
            } else {
                $items = '';
                foreach ($results as $photo) {
                    $items .= $this->photoSearchResult((array)$photo, $profileId, $displayUrl, $displayType, $csrfToken, $photoSearch);
                }
                $resultHtml = '<div class="rawtherapee-photo-search-results">' . $items . '</div>';
            }
        }

        return '<section class="panel-soft rawtherapee-photo-search-panel">
            <form method="post" action="?page=profiles" data-ajax="true" class="form-row rawtherapee-photo-search-form">
                ' . $this->hiddenFields($profileId, $displayUrl, $displayType, $csrfToken) . '
                <input type="hidden" name="rawtherapee_profiles_action" value="search_photo">
                <input type="hidden" name="rawtherapee_photo_id" value="' . HelperFramework::escape((string)$photoId) . '">
                <label for="rawtherapee-photo-search">Recall Photo</label>
                <div class="input-action-row">
                    <input id="rawtherapee-photo-search" class="input" type="search" name="rawtherapee_photo_search" value="' . HelperFramework::escape($photoSearch) . '" placeholder="Filename, checksum, or photo ID">
                    <button class="button button-inline" type="submit" data-processing-text="Searching" data-processing-state="disabled">Search</button>
                </div>
            </form>
            ' . $resultHtml . '
        </section>';
    }

    private function photoSearchResult(
        array $photo,
        int $profileId,
        string $displayUrl,
        string $displayType,
        string $csrfToken,
        string $photoSearch
    ): string {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        $filename = trim((string)($photo['original_filename'] ?? ''));
        $checksum = strtolower(trim((string)($photo['original_sha256'] ?? '')));
        $shortChecksum = $checksum !== '' ? substr($checksum, 0, 12) . '...' . substr($checksum, -8) : 'none';

        return '<form method="post" action="?page=profiles" data-ajax="true" class="rawtherapee-photo-search-result">
            ' . $this->hiddenFields($profileId, $displayUrl, $displayType, $csrfToken) . '
            <input type="hidden" name="rawtherapee_profiles_action" value="select_photo">
            <input type="hidden" name="rawtherapee_selected_photo_id" value="' . HelperFramework::escape((string)$photoId) . '">
            <input type="hidden" name="rawtherapee_photo_search" value="' . HelperFramework::escape($photoSearch) . '">
            <span class="rawtherapee-photo-search-meta">
                <strong>' . HelperFramework::escape($filename !== '' ? $filename : 'Photo ' . (string)$photoId) . '</strong>
                <span>ID ' . HelperFramework::escape((string)$photoId) . '</span>
                <span>' . HelperFramework::escape($shortChecksum) . '</span>
            </span>
            <button class="button button-inline" type="submit" data-processing-text="Using" data-processing-state="disabled">Use Photo</button>
        </form>';
    }

    private function details(string $status, array $sample, ?array $photo, string $profileLabel, string $imageShown): string
    {
        $jobId = trim((string)($sample['job_id'] ?? ''));
        $filename = $photo !== null ? (string)($photo['original_filename'] ?? '') : '';
        $photoId = $photo !== null ? max(0, (int)($photo['id'] ?? 0)) : 0;
        $checksum = $photo !== null ? strtolower(trim((string)($photo['original_sha256'] ?? ''))) : '';
        $statusImage = $this->statusImage($status);

        return '<section class="panel-soft rawtherapee-profile-status-panel">
            <div class="rawtherapee-profile-status-heading">
                <img class="rawtherapee-profile-state-image" data-rawtherapee-profile-state-image="true" data-ready-src="/swallowtail_butterfly_42x42.png" data-busy-src="/swallowtail_256.gif" src="' . HelperFramework::escape($statusImage) . '" alt="" loading="lazy">
                <dl class="rawtherapee-profile-details">
                    <div>
                        <dt>Status</dt>
                        <dd data-rawtherapee-profile-status="true">' . HelperFramework::escape($status) . '</dd>
                    </div>
                    <div>
                        <dt>Job ID</dt>
                        <dd>' . HelperFramework::escape($jobId !== '' ? $jobId : 'none') . '</dd>
                    </div>
                    <div>
                        <dt>Original Filename</dt>
                        <dd>' . HelperFramework::escape($filename !== '' ? $filename : 'none') . '</dd>
                    </div>
                    <div>
                        <dt>Checksum</dt>
                        <dd>' . HelperFramework::escape($checksum !== '' ? $checksum : 'none') . '</dd>
                    </div>
                    <div>
                        <dt>Photo ID</dt>
                        <dd>' . HelperFramework::escape($photoId > 0 ? (string)$photoId : 'none') . '</dd>
                    </div>
                    <div>
                        <dt>Profile Applied</dt>
                        <dd>' . HelperFramework::escape($profileLabel !== '' ? $profileLabel : 'none') . '</dd>
                    </div>
                    <div>
                        <dt>Image Shown</dt>
                        <dd data-rawtherapee-profile-image-shown="true">' . HelperFramework::escape($imageShown) . '</dd>
                    </div>
                </dl>
            </div>
        </section>';
    }

    private function photoPreview(int $photoId, ?array $photo, string $displayUrl, string $displayType, bool $showPreview): string
    {
        if (!$showPreview) {
            return '<div class="rawtherapee-profile-preview"><div class="panel-soft">Press Test to see</div></div>';
        }

        if ($photoId <= 0 || $photo === null) {
            return '<div class="rawtherapee-profile-preview"><div class="panel-soft warn">No accessible photo is available.</div></div>';
        }

        if ($displayUrl === '') {
            return '<div class="rawtherapee-profile-preview"><div class="panel-soft warn">No preview image is available yet.</div></div>';
        }

        return '<div class="rawtherapee-profile-preview">
            <figure class="rawtherapee-profile-preview-frame">
                <span class="rawtherapee-profile-image-shell">
                    <img class="rawtherapee-profile-image" data-rawtherapee-profile-image="true" data-rawtherapee-profile-image-type="' . HelperFramework::escape($displayType) . '" src="' . HelperFramework::escape($displayUrl) . '" alt="' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo')) . '">
                </span>
            </figure>
        </div>';
    }

    private function profileLabel(array $profiles, int $profileId): string
    {
        if ($profileId <= 0) {
            return 'Current Profile';
        }

        foreach ($profiles as $profile) {
            if ((int)($profile['id'] ?? 0) === $profileId) {
                return (string)($profile['display_label'] ?? $profile['relative_path'] ?? 'Profile');
            }
        }

        return '';
    }

    private function statusLabel(array $sample, ?array $asset, bool $showPreview): string
    {
        if (!empty($sample['current_profile'])) {
            return 'Ready';
        }

        if ($showPreview && $asset !== null && (string)($asset['image_type'] ?? '') === SwallowtailRawTherapeeProfileService::SAMPLE_IMAGE_TYPE) {
            return 'Ready';
        }

        return !empty($sample['success']) ? 'Queued' : 'Ready';
    }

    private function imageShownLabel(string $displayType, bool $showPreview): string
    {
        if (!$showPreview) {
            return 'none';
        }

        $displayType = $this->normaliseDisplayType($displayType);

        return $displayType !== 'none' ? $displayType : 'none';
    }

    private function normaliseDisplayUrl(string $displayUrl): string
    {
        $displayUrl = trim($displayUrl);

        return str_starts_with($displayUrl, '/api/photo-imaging.php?') ? $displayUrl : '';
    }

    private function normaliseDisplayType(string $displayType): string
    {
        $displayType = strtolower(trim($displayType));

        return in_array($displayType, ['preview', 'thumbnail', 'rawtherapee'], true) ? $displayType : 'none';
    }

    private function normalisePhotoSearch(string $query): string
    {
        return substr(trim($query), 0, 255);
    }

    private function hiddenFields(int $profileId, string $displayUrl, string $displayType, string $csrfToken, bool $includeProfileId = true): string
    {
        $profileField = $includeProfileId
            ? '<input type="hidden" name="rawtherapee_profile_id" value="' . HelperFramework::escape((string)$profileId) . '">'
            : '';

        return '<input type="hidden" name="cards[]" value="rawtherapee_profiles">
            <input type="hidden" name="card_action" value="RawTherapeeProfiles">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            ' . $profileField . '
            <input type="hidden" name="rawtherapee_display_url" value="' . HelperFramework::escape($displayUrl) . '" data-rawtherapee-display-url-field="true">
            <input type="hidden" name="rawtherapee_display_type" value="' . HelperFramework::escape($displayType) . '" data-rawtherapee-display-type-field="true">';
    }

    private function statusImage(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, ['queued', 'queuing', 'rendering', 'loading'], true)
            ? '/swallowtail_256.gif'
            : '/swallowtail_butterfly_42x42.png';
    }

}
