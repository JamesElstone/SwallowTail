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
        private readonly int $appMaxRawBytes = self::MAX_RAW_BYTES,
        private readonly ?array $phpUploadLimits = null,
    ) {
    }

    public function maxRawBytes(): int
    {
        return $this->maxRawBytesForPhpKeys(['upload_max_filesize', 'post_max_size']);
    }

    public function maxRawBodyBytes(): int
    {
        return $this->maxRawBytesForPhpKeys(['post_max_size']);
    }

    /**
     * @param array<int, string> $phpLimitKeys
     */
    private function maxRawBytesForPhpKeys(array $phpLimitKeys): int
    {
        $limits = [max(1, $this->appMaxRawBytes)];

        foreach ($phpLimitKeys as $key) {
            $bytes = self::phpIniBytes($this->phpUploadLimit($key));
            if ($bytes !== null && $bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    public static function phpIniBytes(mixed $value): ?int
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $value = strtolower(trim((string)$value));
        if ($value === '' || str_starts_with($value, '-')) {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgtpezy]?)b?$/i', $value, $matches) !== 1) {
            return null;
        }

        $number = (float)$matches[1];
        if ($number <= 0) {
            return null;
        }

        $powers = [
            '' => 0,
            'k' => 1,
            'm' => 2,
            'g' => 3,
            't' => 4,
            'p' => 5,
            'e' => 6,
            'z' => 7,
            'y' => 8,
        ];
        $power = $powers[strtolower((string)($matches[2] ?? ''))] ?? 0;
        $bytes = $number * (1024 ** $power);

        return $bytes >= PHP_INT_MAX ? PHP_INT_MAX : (int)floor($bytes);
    }

    public function ingestLocalRawFile(string $sourcePath, string $originalFilename, array $context = []): array
    {
        $validation = $this->validateRawFile(
            $sourcePath,
            $originalFilename,
            $this->contextMaxRawBytes($context)
        );

        if (!$validation['valid']) {

            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        $sha256 = $this->contextSha256($context);
        if ($sha256 === null) {
            $sha256 = hash_file('sha256', $sourcePath);
            if (!is_string($sha256) || $sha256 === '') {
                throw new RuntimeException('Unable to checksum RAW file.');
            }
            $sha256 = strtolower($sha256);
        }

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
                'warnings' => $validation['warnings'],
            ];
        }

        $stored = $this->storageService->storeSourceFile(
            $sourcePath,
            $sha256,
            !empty($context['move_source']),
            $this->contextStorageBaseLocation($context)
        );

        $recorded = $this->photoLibraryService->recordRawUpload([
            'sha256' => $sha256,
            'original_filename' => $originalFilename,
            'extension' => $validation['extension'],
            'bytes' => (int)$stored['bytes'],
            'storage_base_location' => $stored['storage_base_location'] ?? '',
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
            'storage_base_location' => $stored['storage_base_location'] ?? '',
            'conversion_job_id' => $firstJobId,
            'conversion_jobs' => $conversionJobs,
            'warnings' => $validation['warnings'],
        ];
    }

    public function validateRawFile(string $sourcePath, string $originalFilename, ?int $maxRawBytes = null): array
    {
        $errors = [];
        $warnings = [];
        $maxRawBytes = $maxRawBytes !== null ? max(1, $maxRawBytes) : $this->maxRawBytes();

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $errors[] = 'RAW file was not readable.';
        }

        $bytes = is_file($sourcePath) ? (int)filesize($sourcePath) : 0;
        if ($bytes <= 0) {
            $errors[] = 'RAW file was empty.';
        } elseif ($bytes > $maxRawBytes) {
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

    private function contextMaxRawBytes(array $context): ?int
    {
        if (!array_key_exists('max_raw_bytes', $context)) {
            return null;
        }

        $value = (int)$context['max_raw_bytes'];

        return $value > 0 ? $value : null;
    }

    private function phpUploadLimit(string $key): mixed
    {
        if (is_array($this->phpUploadLimits) && array_key_exists($key, $this->phpUploadLimits)) {
            return $this->phpUploadLimits[$key];
        }

        return ini_get($key);
    }

    private function contextSha256(array $context): ?string
    {
        $sha256 = strtolower(trim((string)($context['sha256'] ?? '')));
        if ($sha256 === '') {
            return null;
        }

        return preg_match('/^[a-f0-9]{64}$/', $sha256) === 1 ? $sha256 : null;
    }

    private function contextStorageBaseLocation(array $context): ?string
    {
        $location = trim((string)($context['storage_base_location'] ?? ''));

        return $location !== '' ? $location : null;
    }

}
