<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailPingApiService
{
    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
    ) {
    }

    public function handlePing(RequestFramework $request): ResponseFramework
    {
        if ($request->method() !== 'GET') {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Ping API expects GET.'],
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

        $this->photoLibraryService->markUploadTokenUsed((int)$uploadToken['id']);

        return ResponseFramework::json([
            'success' => true,
            'pong' => true,
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
}
