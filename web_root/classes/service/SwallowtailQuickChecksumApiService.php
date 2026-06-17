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
        if ($request->method() !== 'GET') {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Quick checksum API expects GET.'],
            ], 405);
        }

        if (!$this->photoLibraryService->schemaAvailable()) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['SwallowTail photo database tables are not available. Run the database migrations.'],
            ], 503);
        }

        $uploadToken = $this->photoLibraryService->authenticateUploadToken(
            $this->photoLibraryService->uploadTokenFromRequest($request),
            $request->remoteAddress()
        );

        if ($uploadToken === null) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Bearer upload token was missing, invalid, expired, disabled, or not allowed from this network.'],
            ], 401);
        }

        $algorithm = strtolower(trim((string)$request->query('algorithm', SwallowtailPhotoLibraryService::QUICK_HASH_ALGORITHM)));
        if ($algorithm !== SwallowtailPhotoLibraryService::QUICK_HASH_ALGORITHM) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Unsupported quick checksum algorithm. Use fnv1a64.'],
            ], 400);
        }

        try {
            $quickHash = $this->photoLibraryService->normaliseQuickHash((string)$request->query('hash', ''));
            $sizeBytes = $this->sizeBytesFromRequest($request);
        } catch (InvalidArgumentException $exception) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => [$exception->getMessage()],
            ], 400);
        }

        $photo = $this->photoLibraryService->photoByQuickHash($quickHash, $sizeBytes);
        $this->photoLibraryService->markUploadTokenUsed((int)$uploadToken['id']);

        return ResponseFramework::json([
            'success' => true,
            'exists' => $photo !== null,
            'algorithm' => $algorithm,
            'hash' => $quickHash,
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
}
