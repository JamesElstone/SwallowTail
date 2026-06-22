<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailRawUploadApiService
{
    private const RAW_UPLOAD_WRITE_BUFFER_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly SwallowtailPhotoIngestService $photoIngestService = new SwallowtailPhotoIngestService(),
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
    ) {
    }

    public function handleUpload(RequestFramework $request, array $files = [], string $inputStream = 'php://input'): ResponseFramework
    {

        if ($request->method() !== 'POST') {

            return ResponseFramework::json([
                'success' => false,
                'errors' => ['RAW upload API expects POST.'],
            ], 405);
        }

        if ($this->photoLibraryService->isUploadTokenRequestBlocked($request)) {

            return $this->photoLibraryService->uploadTokenLockoutResponse();
        }

        $token = $this->photoLibraryService->uploadTokenFromRequest($request);
        $uploadToken = $this->photoLibraryService->authenticateUploadToken($token, $request->remoteAddress());
        $remoteAddress = $request->remoteAddress();
        $metadata = $this->photoLibraryService->uploadTokenAuditMetadata($request);

        if ($uploadToken === null) {
            $this->photoLibraryService->recordUploadTokenUsage(
                null,
                $token,
                $remoteAddress,
                'upload_token_raw_upload_failed',
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

        $temporaryFile = null;
        $temporaryStorageBaseLocation = null;
        $verifiedSha256 = null;
        $upload = $this->uploadFileFromRequest($files);
        $maxRawBytes = null;

        if ($upload === null) {
            $maxRawBytes = $this->photoIngestService->maxRawBodyBytes();
            if ($this->contentLengthExceedsRawLimit($request, $maxRawBytes)) {

                return $this->rawUploadLimitResponse();
            }

            try {
                $expectedSha256 = $this->sha256FromRequest($request);
                $staging = $this->storageService->rawUploadStagingFileForChecksum(
                    $expectedSha256,
                    $this->contentLength($request) ?? 0
                );
                $temporaryFile = (string)$staging['temporary_path'];
                $temporaryStorageBaseLocation = (string)$staging['storage_base_location'];
                $copied = $this->copyInputStreamToTemporaryFile($inputStream, $temporaryFile, $maxRawBytes, $expectedSha256);
                $verifiedSha256 = (string)$copied['sha256'];
            } catch (LengthException) {

                return $this->rawUploadLimitResponse();
            } catch (InvalidArgumentException | UnexpectedValueException $exception) {
                return ResponseFramework::json([
                    'success' => false,
                    'errors' => [$exception->getMessage()],
                ], 400);
            } catch (RuntimeException $exception) {
                return ResponseFramework::json([
                    'success' => false,
                    'errors' => [$this->publicStorageError($exception)],
                ], 503);
            }
            $upload = [
                'tmp_name' => $temporaryFile,
                'name' => $this->filenameFromRequest($request),
                'error' => UPLOAD_ERR_OK,
            ];
        }

        try {
            $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {

                return ResponseFramework::json([
                    'success' => false,
                    'errors' => [$this->uploadErrorMessage($uploadError)],
                ], 400);
            }

            $originalFilename = (string)($upload['name'] ?? $this->filenameFromRequest($request));

            try {
                $result = $this->photoIngestService->ingestLocalRawFile(
                    (string)$upload['tmp_name'],
                    $originalFilename,
                    [
                        'move_source' => $temporaryFile !== null,
                        'uploaded_via' => 'api',
                        'upload_token_id' => (int)$uploadToken['id'],
                        'max_raw_bytes' => $maxRawBytes,
                        'expected_sha256' => (string)$request->header('X-Swallowtail-Checksum-SHA256', (string)$request->post('sha256', '')),
                        'sha256' => $verifiedSha256,
                        'storage_base_location' => $temporaryStorageBaseLocation,
                        'request_metadata' => [
                            'device_id' => (string)$request->header('X-Swallowtail-Device-ID', ''),
                            'ip_address' => (string)$request->remoteAddress(),
                            'user_agent' => (string)$request->header('User-Agent', ''),
                        ],
                    ]
                );
            } catch (RuntimeException $exception) {
                $reason = $this->publicStorageError($exception);
                $diagnostics = $this->storageFailureDiagnostics($exception, $request, $uploadToken, $upload, $originalFilename, $maxRawBytes, $temporaryFile !== null);
                $this->photoLibraryService->recordUploadTokenUsage(
                    $uploadToken,
                    $token,
                    $remoteAddress,
                    'upload_token_raw_upload_failed',
                    false,
                    $reason,
                    $metadata,
                    $diagnostics
                );

                return ResponseFramework::json([
                    'success' => false,
                    'errors' => [$reason],
                    'diagnostics' => $diagnostics,
                ], 503);
            }

            if (empty($result['success'])) {
                $this->photoLibraryService->recordUploadTokenUsage(
                    $uploadToken,
                    $token,
                    $remoteAddress,
                    'upload_token_raw_upload_failed',
                    false,
                    (string)(($result['errors'] ?? [])[0] ?? 'RAW upload validation failed.'),
                    $metadata,
                    [
                        'original_filename' => $originalFilename,
                        'upload_size_bytes' => $this->uploadSizeBytes($upload),
                    ]
                );

                return ResponseFramework::json($result, 400);
            }

            $this->photoLibraryService->markUploadTokenUsed((int)$uploadToken['id']);
            $this->photoLibraryService->recordUploadTokenUsage(
                $uploadToken,
                $token,
                $remoteAddress,
                'upload_token_raw_upload_succeeded',
                true,
                'RAW upload was stored successfully.',
                $metadata,
                [
                    'original_filename' => $originalFilename,
                    'upload_size_bytes' => $this->uploadSizeBytes($upload),
                    'status' => (string)($result['status'] ?? ''),
                    'photo_id' => (int)($result['photo_id'] ?? 0),
                    'duplicate' => !empty($result['duplicate']),
                ]
            );

            return ResponseFramework::json($this->publicUploadResponse($result), !empty($result['duplicate']) ? 200 : 201);
        } finally {
            if ($temporaryFile !== null && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    private function uploadFileFromRequest(array $files): ?array
    {
        $candidate = $files['raw_file'] ?? $files['file'] ?? null;

        if (is_array($candidate) && isset($candidate['tmp_name'])) {
            return $candidate;
        }

        foreach ($files as $file) {
            if (is_array($file) && isset($file['tmp_name'])) {
                return $file;
            }
        }

        return null;
    }

    private function publicUploadResponse(array $result): array
    {
        if (!isset($result['conversion_jobs']) || !is_array($result['conversion_jobs'])) {
            return $result;
        }

        $result['conversion_job_id'] = null;
        foreach ($result['conversion_jobs'] as $job) {
            $jobId = (int)($job['job_id'] ?? 0);
            if ($jobId > 0) {
                $result['conversion_job_id'] = $jobId;
                break;
            }
        }

        return $result;
    }

    private function publicStorageError(RuntimeException $exception): string
    {
        return str_contains($exception->getMessage(), 'No writable SwallowTail storage location')
            ? 'No writable storage locations available.'
            : 'RAW upload failed while storing the file.';
    }

    private function storageFailureDiagnostics(
        RuntimeException $exception,
        RequestFramework $request,
        array $uploadToken,
        array $upload,
        string $originalFilename,
        ?int $maxRawBytes,
        bool $rawBodyUpload
    ): array {
        return [
            'storage_error' => $exception->getMessage(),
            'storage_error_type' => get_class($exception),
            'upload_token_id' => (int)($uploadToken['id'] ?? 0),
            'upload_token_label' => (string)($uploadToken['token_label'] ?? ''),
            'upload_token_created_by_user_id' => is_numeric($uploadToken['created_by_user_id'] ?? null)
                ? (int)$uploadToken['created_by_user_id']
                : null,
            'original_filename' => $originalFilename,
            'upload_size_bytes' => $this->uploadSizeBytes($upload),
            'content_length' => $this->contentLength($request),
            'max_raw_bytes' => $maxRawBytes,
            'upload_mode' => $rawBodyUpload ? 'raw_body' : 'multipart',
            'device_id' => (string)$request->header('X-Swallowtail-Device-ID', ''),
        ];
    }

    private function uploadSizeBytes(array $upload): ?int
    {
        if (isset($upload['size']) && is_numeric($upload['size'])) {
            return max(0, (int)$upload['size']);
        }

        $path = (string)($upload['tmp_name'] ?? '');

        return is_file($path) ? max(0, (int)filesize($path)) : null;
    }

    private function contentLength(RequestFramework $request): ?int
    {
        $value = trim((string)$request->server('CONTENT_LENGTH', (string)$request->header('Content-Length', '')));

        return preg_match('/^\d+$/', $value) === 1 ? (int)$value : null;
    }

    private function filenameFromRequest(RequestFramework $request): string
    {
        $filename = trim((string)$request->header('X-Swallowtail-Filename', (string)$request->query('filename', 'upload.CR2')));

        return $filename !== '' ? $filename : 'upload.CR2';
    }

    private function sha256FromRequest(RequestFramework $request): string
    {
        $sha256 = strtolower(trim((string)$request->header(
            'X-Swallowtail-Checksum-SHA256',
            (string)$request->post('sha256', (string)$request->query('sha256', ''))
        )));
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('X-Swallowtail-Checksum-SHA256 must be a 64-character hexadecimal SHA-256 checksum.');
        }

        return $sha256;
    }

    private function copyInputStreamToTemporaryFile(string $inputStream, string $temporaryFile, int $maxBytes, string $expectedSha256): array
    {
        $source = @fopen($inputStream, 'rb');
        $destination = @fopen($temporaryFile, 'wb');

        if (!is_resource($source) || !is_resource($destination)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            @unlink($temporaryFile);
            throw new RuntimeException('Unable to open RAW upload stream.');
        }

        $bytesCopied = 0;
        $writeBuffer = '';
        $writeBufferBytes = 0;
        $hash = hash_init('sha256');
        while (!feof($source)) {
            $chunk = fread($source, 1024 * 1024);
            if ($chunk === false) {
                fclose($source);
                fclose($destination);
                @unlink($temporaryFile);
                throw new RuntimeException('Unable to read RAW upload stream.');
            }

            if ($chunk === '') {
                continue;
            }

            $chunkBytes = strlen($chunk);
            hash_update($hash, $chunk);

            if ($bytesCopied + $chunkBytes > $maxBytes) {
                fclose($source);
                fclose($destination);
                @unlink($temporaryFile);
                throw new LengthException('RAW upload exceeded the configured size limit.');
            }

            $writeBuffer .= $chunk;
            $writeBufferBytes += $chunkBytes;
            if ($writeBufferBytes >= self::RAW_UPLOAD_WRITE_BUFFER_BYTES && !$this->writeRawBodyBuffer($destination, $writeBuffer)) {
                fclose($source);
                fclose($destination);
                @unlink($temporaryFile);
                throw new RuntimeException('Unable to write RAW upload stream.');
            }
            if ($writeBufferBytes >= self::RAW_UPLOAD_WRITE_BUFFER_BYTES) {
                $writeBuffer = '';
                $writeBufferBytes = 0;
            }

            $bytesCopied += $chunkBytes;
        }
        if ($writeBufferBytes > 0 && !$this->writeRawBodyBuffer($destination, $writeBuffer)) {
            fclose($source);
            fclose($destination);
            @unlink($temporaryFile);
            throw new RuntimeException('Unable to write RAW upload stream.');
        }

        fclose($source);
        fclose($destination);

        $sha256 = strtolower(hash_final($hash));
        if (!hash_equals($expectedSha256, $sha256)) {
            @unlink($temporaryFile);
            throw new UnexpectedValueException('Uploaded RAW checksum did not match the expected SHA-256 value.');
        }

        return [
            'path' => $temporaryFile,
            'bytes' => $bytesCopied,
            'sha256' => $sha256,
        ];
    }

    private function writeRawBodyBuffer($destination, string $buffer): bool
    {
        $bufferBytes = strlen($buffer);
        $offset = 0;

        while ($offset < $bufferBytes) {
            $written = fwrite($destination, substr($buffer, $offset));
            if ($written === false || $written <= 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    private function contentLengthExceedsRawLimit(RequestFramework $request, int $maxBytes): bool
    {
        $value = trim((string)$request->server('CONTENT_LENGTH', (string)$request->header('Content-Length', '')));
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return false;
        }

        return (int)$value > $maxBytes;
    }

    private function rawUploadLimitResponse(): ResponseFramework
    {
        return ResponseFramework::json([
            'success' => false,
            'errors' => ['RAW upload exceeded the configured size limit.'],
        ], 413);
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'RAW upload exceeded the configured size limit.',
            UPLOAD_ERR_PARTIAL => 'RAW upload was only partially received.',
            UPLOAD_ERR_NO_FILE => 'No RAW file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the temporary upload file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            default => 'RAW upload failed.',
        };
    }

}
