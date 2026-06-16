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

        $token = $this->photoLibraryService->uploadTokenFromRequest($request);
        $remoteAddress = $request->remoteAddress();
        $uploadToken = $this->photoLibraryService->authenticateUploadToken($token, $remoteAddress);

        if ($uploadToken === null) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => [$this->photoLibraryService->explainUploadTokenAuthenticationFailure($token, $remoteAddress)],
            ], 401);
        }

        $this->photoLibraryService->markUploadTokenUsed((int)$uploadToken['id']);

        return ResponseFramework::json([
            'success' => true,
            'pong' => true,
        ]);
    }

}
