<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailCombinedProfilePreviewService;
use Swallowtail\Service\SwallowtailPhotoAssetService;
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

    public function helper(array $context): string
    {
        return 'Sample discovered RawTheapee PP3 profiles against an accessible photo.';
    }

    public function handle(RequestFramework $request, PageServiceFramework $services, array $pageContext, ActionResultFramework $actionResult): array
    {
        $current = (array)($pageContext[$this->key()] ?? []);
        $actionContext = (array)($actionResult->context()[$this->key()] ?? []);
        $pageContext[$this->key()] = array_replace($current, $actionContext, [
            'profile_id' => max(0, (int)$request->input('rawtheapee_profile_id', (int)($actionContext['profile_id'] ?? $current['profile_id'] ?? 0))),
            'photo_id' => max(0, (int)$request->input('rawtheapee_photo_id', (int)($actionContext['photo_id'] ?? $current['photo_id'] ?? 0))),
        ]);

        return $pageContext;
    }

    public function render(array $context): string
    {
        $service = new SwallowtailRawTheapeeProfileService();
        if (!$service->tableAvailable()) {
            return '<div class="panel-soft warn">rawtheapee_profile_data is not available. Run database migrations.</div>';
        }

        $userId = $this->currentUserId();
        $profiles = $service->availableProfiles();
        $state = (array)($context[$this->key()] ?? []);
        $profileId = max(0, (int)($state['profile_id'] ?? 0));
        if ($profileId <= 0 && $profiles !== []) {
            $profileId = (int)($profiles[0]['id'] ?? 0);
        }
        $photoId = max(0, (int)($state['photo_id'] ?? 0));
        $photo = $photoId > 0 ? (new SwallowtailCombinedProfilePreviewService())->photoForUser($photoId, $userId) : null;
        if ($photo === null) {
            $photo = $service->randomAccessiblePhoto($userId);
            $photoId = max(0, (int)($photo['id'] ?? 0));
        }
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<div class="form-grid">
            ' . $this->controlForm($profiles, $profileId, $photoId, $csrfToken) . '
            ' . $this->sampleResult((array)($state['sample'] ?? [])) . '
            ' . $this->photoPreview($photoId, $photo) . '
        </div>';
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
            <input type="hidden" name="rawtheapee_photo_id" value="' . HelperFramework::escape((string)$photoId) . '">
            <input type="hidden" name="rawtheapee_profiles_action" value="test">
            <label for="rawtheapee-profile-id">Profile</label>
            <div class="input-action-row">
                <select id="rawtheapee-profile-id" name="rawtheapee_profile_id">' . $options . '</select>
                <button class="button button-inline primary" type="submit" data-submit-field="rawtheapee_profiles_action" data-submit-value="test" data-processing-text="Queueing" data-processing-state="disabled">Test</button>
                <button class="button button-inline" type="submit" data-submit-field="rawtheapee_profiles_action" data-submit-value="refresh" data-processing-text="Refreshing" data-processing-state="disabled">Refresh</button>
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

    private function photoPreview(int $photoId, ?array $photo): string
    {
        if ($photoId <= 0 || $photo === null) {
            return '<div class="panel-soft warn full">No accessible photo is available.</div>';
        }

        $assetService = new SwallowtailPhotoAssetService();
        $sampleAsset = $assetService->assetForPhoto($photo, SwallowtailRawTheapeeProfileService::SAMPLE_IMAGE_TYPE);
        $previewAsset = $assetService->assetForPhoto($photo, 'preview');
        $thumbnailAsset = $assetService->assetForPhoto($photo, 'thumbnail');
        $embeddedAsset = $assetService->assetForPhoto($photo, 'embedded');
        $asset = $sampleAsset ?? $previewAsset ?? $thumbnailAsset ?? $embeddedAsset;
        $type = is_array($asset) ? (string)($asset['image_type'] ?? 'embedded') : 'embedded';
        $version = is_array($asset) ? (string)($asset['sha256'] ?? '') : '';

        return '<div class="full gallery-grid">
            <span class="gallery-tile">
                <span class="gallery-thumb"><img src="/api/photo-imaging.php?' . HelperFramework::escape(http_build_query([
                    'photo_id' => $photoId,
                    'type' => $type,
                    'v' => $version,
                ])) . '" alt="' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo')) . '"></span>
                <span class="gallery-meta"><strong>' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo')) . '</strong></span>
            </span>
        </div>';
    }

    protected function currentUserId(): int
    {
        $session = new SessionAuthenticationService();
        $session->startSession();
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId);
    }
}
