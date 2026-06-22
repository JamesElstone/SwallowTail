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
        private readonly SwallowtailPhotoIngestService $photoIngestService = new SwallowtailPhotoIngestService(),
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
                'upload_token_ping_failed',
                false,
                $this->photoLibraryService->explainUploadTokenAuthenticationFailure($token, $remoteAddress),
                $metadata
            );

            if (!empty($this->photoLibraryService->recordFailedUploadTokenRequest($request)['is_blocked'])) {
                return $this->photoLibraryService->uploadTokenLockoutResponse();
            }

            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Bearer upload token was rejected.'],
            ], 401);
        }

        $this->photoLibraryService->markUploadTokenUsed((int)$uploadToken['id']);
        $this->photoLibraryService->recordUploadTokenUsage(
            $uploadToken,
            $token,
            $remoteAddress,
            'upload_token_ping_succeeded',
            true,
            'Upload token ping succeeded.',
            $metadata
        );

        return ResponseFramework::json([
            'success' => true,
            'pong' => true,
            'max_raw_upload_bytes' => $this->photoIngestService->maxRawBytes(),
        ]);
    }

}
