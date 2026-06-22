<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailQuickChecksumApiService
{
    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
    ) {
    }

    public function handleCheck(RequestFramework $request): ResponseFramework
    {
        $this->traceHandleCheckStart();

        if ($request->method() !== 'GET') {
            $this->traceMethodRejected();

            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Quick checksum API expects GET.'],
            ], 405);
        }

        $this->traceTokenBlockCheckStart();
        if ($this->photoLibraryService->isUploadTokenRequestBlocked($request)) {
            $this->traceTokenBlockCheckBlocked();

            return $this->photoLibraryService->uploadTokenLockoutResponse();
        }
        $this->traceTokenBlockCheckComplete();

        $this->traceTokenAuthenticationStart();
        $token = $this->photoLibraryService->uploadTokenFromRequest($request);
        $remoteAddress = $request->remoteAddress();
        $uploadToken = $this->photoLibraryService->authenticateUploadToken($token, $remoteAddress);
        $metadata = $this->photoLibraryService->uploadTokenAuditMetadata($request);
        $this->traceTokenAuthenticationComplete();

        if ($uploadToken === null) {
            $this->traceTokenFailureAuditStart();
            $this->photoLibraryService->recordUploadTokenUsage(
                null,
                $token,
                $remoteAddress,
                'upload_token_quick_checksum_failed',
                false,
                $this->photoLibraryService->explainUploadTokenAuthenticationFailure($token, $remoteAddress),
                $metadata
            );
            $this->traceTokenFailureAuditComplete();

            $this->traceFailedTokenRecordStart();
            if (!empty($this->photoLibraryService->recordFailedUploadTokenRequest($request)['is_blocked'])) {
                $this->traceFailedTokenRecordBlocked();

                return $this->photoLibraryService->uploadTokenLockoutResponse();
            }
            $this->traceFailedTokenRecordComplete();

            $this->traceAuthenticationRejectedResponseReady();

            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Bearer upload token was missing, invalid, expired, disabled, or not allowed from this network.'],
            ], 401);
        }

        $this->traceSuccessAuditStart();
        $this->photoLibraryService->recordUploadTokenUsage(
            $uploadToken,
            $token,
            $remoteAddress,
            'upload_token_quick_checksum_succeeded',
            true,
            'Upload token quick checksum request was accepted.',
            $metadata
        );
        $this->traceSuccessAuditComplete();

        $this->traceRequestParametersStart();
        $algorithm = strtolower(trim((string)$request->query('algorithm', SwallowtailPhotoLibraryService::UPLOAD_CHECKSUM_ALGORITHM)));
        if ($algorithm !== SwallowtailPhotoLibraryService::UPLOAD_CHECKSUM_ALGORITHM) {
            $this->traceUnsupportedAlgorithm();

            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Unsupported quick checksum algorithm. Use sha256.'],
            ], 400);
        }

        try {
            $sha256 = $this->photoLibraryService->normaliseSha256((string)$request->query('hash', ''));
            $sizeBytes = $this->sizeBytesFromRequest($request);
        } catch (InvalidArgumentException $exception) {
            $this->traceRequestParametersRejected();

            return ResponseFramework::json([
                'success' => false,
                'errors' => [$exception->getMessage()],
            ], 400);
        }
        $this->traceRequestParametersComplete();

        $this->tracePhotoLookupStart();
        $photo = $this->photoLibraryService->photoByChecksumAndSize($sha256, $sizeBytes);
        $this->tracePhotoLookupComplete();

        $this->traceMarkTokenUsedStart();
        $this->photoLibraryService->markUploadTokenUsed((int)$uploadToken['id']);
        $this->traceMarkTokenUsedComplete();

        $this->traceSuccessResponseReady();

        return ResponseFramework::json([
            'success' => true,
            'exists' => $photo !== null,
            'algorithm' => $algorithm,
            'hash' => $sha256,
            'size_bytes' => $sizeBytes,
            'matched_on' => $sizeBytes === null ? 'hash' : 'hash_size',
            'photo_id' => is_array($photo) ? (int)($photo['id'] ?? 0) : null,
        ]);
    }

    private function sizeBytesFromRequest(RequestFramework $request): ?int
    {
        $value = $request->query('size_bytes', $request->query('bytes', null));
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        $value = trim((string)$value);
        if (preg_match('/^\d+$/', $value) !== 1 || (int)$value <= 0) {
            throw new InvalidArgumentException('size_bytes must be a positive integer when supplied.');
        }

        return (int)$value;
    }

    private function traceHandleCheckStart(): void
    {
        logDetails();
    }

    private function traceMethodRejected(): void
    {
        logDetails();
    }

    private function traceTokenBlockCheckStart(): void
    {
        logDetails();
    }

    private function traceTokenBlockCheckComplete(): void
    {
        logDetails();
    }

    private function traceTokenBlockCheckBlocked(): void
    {
        logDetails();
    }

    private function traceTokenAuthenticationStart(): void
    {
        logDetails();
    }

    private function traceTokenAuthenticationComplete(): void
    {
        logDetails();
    }

    private function traceTokenFailureAuditStart(): void
    {
        logDetails();
    }

    private function traceTokenFailureAuditComplete(): void
    {
        logDetails();
    }

    private function traceFailedTokenRecordStart(): void
    {
        logDetails();
    }

    private function traceFailedTokenRecordComplete(): void
    {
        logDetails();
    }

    private function traceFailedTokenRecordBlocked(): void
    {
        logDetails();
    }

    private function traceAuthenticationRejectedResponseReady(): void
    {
        logDetails();
    }

    private function traceSuccessAuditStart(): void
    {
        logDetails();
    }

    private function traceSuccessAuditComplete(): void
    {
        logDetails();
    }

    private function traceRequestParametersStart(): void
    {
        logDetails();
    }

    private function traceRequestParametersComplete(): void
    {
        logDetails();
    }

    private function traceRequestParametersRejected(): void
    {
        logDetails();
    }

    private function traceUnsupportedAlgorithm(): void
    {
        logDetails();
    }

    private function tracePhotoLookupStart(): void
    {
        logDetails();
    }

    private function tracePhotoLookupComplete(): void
    {
        logDetails();
    }

    private function traceMarkTokenUsedStart(): void
    {
        logDetails();
    }

    private function traceMarkTokenUsedComplete(): void
    {
        logDetails();
    }

    private function traceSuccessResponseReady(): void
    {
        logDetails();
    }
}
