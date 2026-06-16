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
    private const DERIVATIVE_TYPES = ['original_jpeg', 'preview', 'thumbnail', 'jpeg'];

    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
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

        if (!$this->photoLibraryService->schemaAvailable()) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Swallowtail photo database tables are not available. Run the database migrations.'],
            ], 503);
        }

        $uploadToken = $this->photoLibraryService->authenticateUploadToken(
            $this->tokenFromRequest($request),
            $request->remoteAddress()
        );

        if ($uploadToken === null) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Bearer upload token was missing, invalid, expired, disabled, or not allowed from this network.'],
            ], 401);
        }

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
            'derivatives' => $this->derivativesForPhoto($photoId),
        ]);
    }

    private function tokenFromRequest(RequestFramework $request): string
    {
        $authorization = trim((string)$request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
            return trim($match[1]);
        }

        return '';
    }

    private function jobsForPhoto(int $photoId): array
    {
        $jobs = $this->emptyDerivativeMap(['job_id' => null, 'status' => 'not_queued']);
        if (!InterfaceDB::tableExists('swallowtail_photo_conversion_jobs')) {
            return $jobs;
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT id, derivative_type, status
             FROM swallowtail_photo_conversion_jobs
             WHERE photo_id = :photo_id
             ORDER BY id DESC",
            ['photo_id' => $photoId]
        );

        foreach ($rows as $row) {
            $type = (string)($row['derivative_type'] ?? '');
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

    private function derivativesForPhoto(int $photoId): array
    {
        $derivatives = $this->emptyDerivativeMap(['ready' => false]);
        if (!InterfaceDB::tableExists('swallowtail_photo_derivatives')) {
            return $derivatives;
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT derivative_type, storage_path, bytes, generated_at
             FROM swallowtail_photo_derivatives
             WHERE photo_id = :photo_id",
            ['photo_id' => $photoId]
        );

        foreach ($rows as $row) {
            $type = (string)($row['derivative_type'] ?? '');
            if (!array_key_exists($type, $derivatives)) {
                continue;
            }

            $derivatives[$type] = [
                'ready' => true,
                'storage_path' => (string)($row['storage_path'] ?? ''),
                'bytes' => (int)($row['bytes'] ?? 0),
                'generated_at' => (string)($row['generated_at'] ?? ''),
            ];
        }

        return $derivatives;
    }

    private function emptyDerivativeMap(array $default): array
    {
        $map = [];
        foreach (self::DERIVATIVE_TYPES as $type) {
            $map[$type] = $default;
        }

        return $map;
    }
}
