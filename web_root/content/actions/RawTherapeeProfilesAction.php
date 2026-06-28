<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailRawTherapeeProfileService;

final class RawTherapeeProfilesAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();
        $userId = $this->currentUserId($session);
        if ($userId <= 0 || !$this->canAccess($userId) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return new ActionResultFramework(false, ['rawtherapee.profiles'], [[
                'type' => 'error',
                'message' => 'You do not have permission to update RawTherapee profiles, or your security token expired.',
            ]]);
        }

        $service = new SwallowtailRawTherapeeProfileService();
        $profileId = max(0, (int)$request->input('rawtherapee_profile_id', 0));
        $photoId = max(0, (int)$request->input('rawtherapee_photo_id', 0));
        $context = [
            'profile_id' => $profileId,
            'photo_id' => $photoId,
            'display_url' => $this->normaliseDisplayUrl((string)$request->input('rawtherapee_display_url', '')),
            'display_type' => $this->normaliseDisplayType((string)$request->input('rawtherapee_display_type', 'none')),
            'photo_search' => $this->normalisePhotoSearch((string)$request->input('rawtherapee_photo_search', '')),
        ];

        $action = (string)$request->input('rawtherapee_profiles_action', 'test');
        $action = trim($action) !== '' ? trim($action) : 'test';
        if ($action === 'refresh') {
            $ok = $service->requestRefresh();
            return ActionResultFramework::success(['rawtherapee.profiles'], [[
                'type' => $ok ? 'success' : 'error',
                'message' => $ok ? 'RawTherapee profile refresh requested.' : 'Unable to request RawTherapee profile refresh.',
            ]], [], ['rawtherapee_profiles' => $context]);
        }

        if ($action === 'set_default') {
            $result = $service->setDefaultProfile($profileId);
            return new ActionResultFramework(!empty($result['success']), ['rawtherapee.profiles'], [[
                'type' => !empty($result['success']) ? 'success' : 'error',
                'message' => (string)($result['message'] ?? (!empty($result['success']) ? 'Default RawTherapee profile updated.' : 'Unable to update default RawTherapee profile.')),
            ]], [], ['rawtherapee_profiles' => $context]);
        }

        if ($action === 'search_photo') {
            $context['photo_search_results'] = $context['photo_search'] !== ''
                ? $service->searchAccessibleThumbnailPhotos($userId, (string)$context['photo_search'], 10)
                : [];
            $context['photo_search_performed'] = true;

            return ActionResultFramework::success(['rawtherapee.profiles'], [], [], ['rawtherapee_profiles' => $context]);
        }

        if ($action === 'select_photo') {
            $selectedPhotoId = max(0, (int)$request->input('rawtherapee_selected_photo_id', 0));
            $selectedPhoto = $this->selectedAccessibleThumbnailPhoto($service, $userId, $selectedPhotoId);
            if ($selectedPhoto === null) {
                $context['photo_search_results'] = $context['photo_search'] !== ''
                    ? $service->searchAccessibleThumbnailPhotos($userId, (string)$context['photo_search'], 10)
                    : [];
                $context['photo_search_performed'] = true;

                return new ActionResultFramework(false, ['rawtherapee.profiles'], [[
                    'type' => 'error',
                    'message' => 'The selected photo was not available.',
                ]], [], ['rawtherapee_profiles' => $context]);
            }

            $photoId = $selectedPhotoId;
            $context['photo_id'] = $photoId;
            $context['display_url'] = '';
            $context['display_type'] = 'none';
            $context['photo_search_results'] = [$selectedPhoto];
            $context['photo_search_performed'] = true;
            $action = 'test';
        }

        if ($action === 'test' || $action === 'change_photo') {
            if ($action === 'change_photo') {
                $photoId = 0;
                $context['photo_id'] = 0;
                $context['display_url'] = '';
                $context['display_type'] = 'none';
                $context['photo_search_results'] = [];
                $context['photo_search_performed'] = false;
            }

            if ($photoId <= 0) {
                $photo = $service->randomAccessibleThumbnailPhoto($userId);
                $photoId = max(0, (int)($photo['id'] ?? 0));
                $context['photo_id'] = $photoId;
            }

            if ($profileId <= 0) {
                $context['sample'] = [
                    'success' => true,
                    'photo_id' => $photoId,
                    'profile_id' => 0,
                    'current_profile' => true,
                ];

                return ActionResultFramework::success(['rawtherapee.profiles'], [], [], ['rawtherapee_profiles' => $context]);
            }

            $result = $service->enqueueSample($photoId, $profileId, $userId);
            $context['sample'] = $result;
            return new ActionResultFramework(!empty($result['success']), ['rawtherapee.profiles'], [[
                'type' => !empty($result['success']) ? 'success' : 'error',
                'message' => (string)($result['message'] ?? (!empty($result['success']) ? 'RawTherapee sample queued.' : 'RawTherapee sample could not be queued.')),
            ]], [], ['rawtherapee_profiles' => $context]);
        }

        return new ActionResultFramework(false, ['rawtherapee.profiles'], [[
            'type' => 'error',
            'message' => 'Unknown RawTherapee profiles action.',
        ]], [], ['rawtherapee_profiles' => $context]);
    }

    private function currentUserId(SessionAuthenticationService $session): int
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId);
    }

    private function canAccess(int $userId): bool
    {
        return in_array('rawtherapee_profiles', (new CardAccessFramework())->allowedCardsForUser($userId, ['rawtherapee_profiles']), true);
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

    private function selectedAccessibleThumbnailPhoto(SwallowtailRawTherapeeProfileService $service, int $userId, int $photoId): ?array
    {
        if ($photoId <= 0) {
            return null;
        }

        foreach ($service->searchAccessibleThumbnailPhotos($userId, (string)$photoId, 10) as $photo) {
            if ((int)($photo['id'] ?? 0) === $photoId) {
                return (array)$photo;
            }
        }

        return null;
    }
}
