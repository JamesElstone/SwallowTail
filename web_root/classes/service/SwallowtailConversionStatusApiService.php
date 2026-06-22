<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailConversionStatusApiService
{
    private const IMAGE_TYPES = ['original', 'embedded', 'thumbnail', 'filtered', 'profile'];

    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
    ) {
    }

    public function handleStatus(RequestFramework $request): ResponseFramework
    {
        if ($request->method() !== 'GET') {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Conversion status API expects GET.'],
            ], 405);
        }

        if ($this->photoLibraryService->isUploadTokenRequestBlocked($request)) {
            return $this->photoLibraryService->uploadTokenLockoutResponse();
        }

        $token = $this->photoLibraryService->uploadTokenFromRequest($request);
        $remoteAddress = $request->remoteAddress();
        $uploadToken = $this->photoLibraryService->authenticateUploadToken($token, $remoteAddress);
        $metadata = $this->photoLibraryService->uploadTokenAuditMetadata($request);

        if ($uploadToken === null) {
            $this->photoLibraryService->recordUploadTokenUsage(
                null,
                $token,
                $remoteAddress,
                'upload_token_conversion_status_failed',
                false,
                $this->photoLibraryService->explainUploadTokenAuthenticationFailure($token, $remoteAddress),
                $metadata
            );

            if (!empty($this->photoLibraryService->recordFailedUploadTokenRequest($request)['is_blocked'])) {
                return $this->photoLibraryService->uploadTokenLockoutResponse();
            }

            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Bearer upload token was missing, invalid, expired, disabled, or not allowed from this network.'],
            ], 401);
        }

        $this->photoLibraryService->recordUploadTokenUsage(
            $uploadToken,
            $token,
            $remoteAddress,
            'upload_token_conversion_status_succeeded',
            true,
            'Upload token conversion status request succeeded.',
            $metadata
        );

        $photoId = max(0, (int)$request->query('photo_id', 0));
        if ($photoId <= 0) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['A valid photo_id query parameter is required.'],
            ], 400);
        }

        $photo = $this->photoLibraryService->photoById($photoId);
        if ($photo === null) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Photo was not found.'],
            ], 404);
        }

        return ResponseFramework::json([
            'success' => true,
            'photo_id' => $photoId,
            'conversion_state' => (string)($photo['conversion_state'] ?? 'pending'),
            'jobs' => $this->jobsForPhoto($photoId),
            'images' => $this->imagesForPhoto($photo),
        ]);
    }

    private function jobsForPhoto(int $photoId): array
    {
        $jobs = $this->emptyImageMap(['job_id' => null, 'status' => 'not_queued']);
        $rows = InterfaceDB::fetchAll(
            "SELECT id, image_type, status
             FROM photo_conversion_jobs
             WHERE photo_id = :photo_id
             ORDER BY id DESC",
            ['photo_id' => $photoId]
        );

        foreach ($rows as $row) {
            $type = (string)($row['image_type'] ?? '');
            if (!array_key_exists($type, $jobs) || $jobs[$type]['job_id'] !== null) {
                continue;
            }

            $jobs[$type] = [
                'job_id' => (int)($row['id'] ?? 0),
                'status' => (string)($row['status'] ?? 'queued'),
            ];
        }

        return $jobs;
    }

    private function imagesForPhoto(array $photo): array
    {
        $images = $this->emptyImageMap(['ready' => false]);
        foreach (self::IMAGE_TYPES as $type) {
            $info = $this->storageService->imageInfo($photo, $type);
            if ($info === null) {
                continue;
            }

            $images[$type] = [
                'ready' => true,
                'bytes' => (int)$info['bytes'],
                'sha256' => (string)$info['sha256'],
                'modified_at' => gmdate('c', (int)$info['modified_at']),
            ];
        }

        return $images;
    }

    private function emptyImageMap(array $default): array
    {
        $map = [];
        foreach (self::IMAGE_TYPES as $type) {
            $map[$type] = $default;
        }

        return $map;
    }
}
