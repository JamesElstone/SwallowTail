<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailPhotoIngestService
{
    private const MAX_RAW_BYTES = 1024 * 1024 * 1024;

    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly SwallowtailConversionQueueService $conversionQueueService = new SwallowtailConversionQueueService(),
        private readonly int $maxRawBytes = self::MAX_RAW_BYTES,
    ) {
    }

    public function ingestLocalRawFile(string $sourcePath, string $originalFilename, array $context = []): array
    {
        $validation = $this->validateRawFile($sourcePath, $originalFilename);

        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        $sha256 = hash_file('sha256', $sourcePath);
        if (!is_string($sha256) || $sha256 === '') {
            throw new RuntimeException('Unable to checksum RAW file.');
        }

        $quickHash = hash_file(SwallowtailPhotoLibraryService::QUICK_HASH_ALGORITHM, $sourcePath);
        if (!is_string($quickHash) || $quickHash === '') {
            throw new RuntimeException('Unable to quick-checksum RAW file.');
        }
        $quickHash = strtolower($quickHash);

        $expectedSha256 = strtolower(trim((string)($context['expected_sha256'] ?? '')));
        if ($expectedSha256 !== '' && !hash_equals($expectedSha256, $sha256)) {
            return [
                'success' => false,
                'errors' => ['Uploaded RAW checksum did not match the expected SHA-256 value.'],
            ];
        }

        $existing = $this->photoLibraryService->photoByChecksum($sha256);
        if ($existing !== null) {
            $recorded = $this->photoLibraryService->recordRawUpload([
                'sha256' => $sha256,
                'quick_hash' => $quickHash,
                'original_filename' => $originalFilename,
                'uploaded_via' => (string)($context['uploaded_via'] ?? 'api'),
                'uploaded_by_user_id' => $context['uploaded_by_user_id'] ?? null,
                'upload_token_id' => $context['upload_token_id'] ?? null,
                'request_metadata' => (array)($context['request_metadata'] ?? []),
            ]);

            return [
                'success' => true,
                'status' => 'duplicate',
                'duplicate' => true,
                'photo_id' => (int)($recorded['photo']['id'] ?? $existing['id'] ?? 0),
                'sha256' => $sha256,
                'quick_hash' => $quickHash,
                'warnings' => $validation['warnings'],
            ];
        }

        $relativePath = $this->storageService->originalRelativePath($sha256, $validation['extension']);
        $stored = $this->storageService->storeOriginalFile(
            $sourcePath,
            $relativePath,
            !empty($context['move_source'])
        );

        $recorded = $this->photoLibraryService->recordRawUpload([
            'sha256' => $sha256,
            'quick_hash' => $quickHash,
            'original_filename' => $originalFilename,
            'extension' => $validation['extension'],
            'bytes' => (int)$stored['bytes'],
            'storage_path' => $relativePath,
            'storage_location_id' => $stored['storage_location_id'] ?? null,
            'uploaded_via' => (string)($context['uploaded_via'] ?? 'api'),
            'uploaded_by_user_id' => $context['uploaded_by_user_id'] ?? null,
            'upload_token_id' => $context['upload_token_id'] ?? null,
            'request_metadata' => (array)($context['request_metadata'] ?? []),
        ]);

        $photoId = (int)($recorded['photo']['id'] ?? 0);
        $conversionJobs = $this->conversionQueueService->enqueueRawConversionJobs($photoId);
        $firstJobId = null;
        foreach ($conversionJobs as $job) {
            $jobId = (int)($job['job_id'] ?? 0);
            if ($jobId > 0) {
                $firstJobId = $jobId;
                break;
            }
        }

        return [
            'success' => true,
            'status' => 'uploaded',
            'duplicate' => false,
            'photo_id' => $photoId,
            'sha256' => $sha256,
            'quick_hash' => $quickHash,
            'storage_path' => $relativePath,
            'conversion_job_id' => $firstJobId,
            'conversion_jobs' => $conversionJobs,
            'warnings' => $validation['warnings'],
        ];
    }

    public function validateRawFile(string $sourcePath, string $originalFilename): array
    {
        $errors = [];
        $warnings = [];

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $errors[] = 'RAW file was not readable.';
        }

        $bytes = is_file($sourcePath) ? (int)filesize($sourcePath) : 0;
        if ($bytes <= 0) {
            $errors[] = 'RAW file was empty.';
        } elseif ($bytes > $this->maxRawBytes) {
            $errors[] = 'RAW file exceeded the configured upload limit.';
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension !== 'cr2') {
            $errors[] = 'Only .CR2 RAW image files are supported.';
        } elseif ($bytes > 0 && !$this->hasPlausibleCr2Signature($sourcePath, $extension)) {
            $warnings[] = 'RAW file signature could not be positively identified as CR2 RAW.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'extension' => $extension,
            'bytes' => $bytes,
        ];
    }

    private function hasPlausibleCr2Signature(string $sourcePath, string $extension): bool
    {
        $handle = @fopen($sourcePath, 'rb');
        if (!is_resource($handle)) {
            return false;
        }

        $header = (string)fread($handle, 32);
        fclose($handle);

        if ($extension === 'cr2') {
            return str_starts_with($header, "II*\0")
                || str_starts_with($header, "MM\0*")
                || str_contains($header, "CR\2");
        }

        return false;
    }
}
