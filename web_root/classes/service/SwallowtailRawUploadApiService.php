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
    public function __construct(
        private readonly SwallowtailPhotoIngestService $photoIngestService = new SwallowtailPhotoIngestService(),
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
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

        if (!$this->photoLibraryService->schemaAvailable()) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Swallowtail photo database tables are not available. Run the database migrations.'],
            ], 503);
        }

        $token = $this->tokenFromRequest($request);
        $uploadToken = $this->photoLibraryService->authenticateUploadToken($token, $request->remoteAddress());

        if ($uploadToken === null) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Bearer upload token was missing, invalid, expired, disabled, or not allowed from this network.'],
            ], 401);
        }

        $temporaryFile = null;
        $upload = $this->uploadFileFromRequest($files);

        if ($upload === null) {
            $temporaryFile = $this->copyInputStreamToTemporaryFile($inputStream);
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

            $result = $this->photoIngestService->ingestLocalRawFile(
                (string)$upload['tmp_name'],
                (string)($upload['name'] ?? $this->filenameFromRequest($request)),
                [
                    'move_source' => $temporaryFile !== null,
                    'uploaded_via' => 'api',
                    'upload_token_id' => (int)$uploadToken['id'],
                    'expected_sha256' => (string)$request->header('X-Swallowtail-Checksum-SHA256', (string)$request->post('sha256', '')),
                    'request_metadata' => [
                        'device_id' => (string)$request->header('X-Swallowtail-Device-ID', ''),
                        'ip_address' => (string)$request->remoteAddress(),
                        'user_agent' => (string)$request->header('User-Agent', ''),
                    ],
                ]
            );

            if (empty($result['success'])) {
                return ResponseFramework::json($result, 400);
            }

            $this->photoLibraryService->markUploadTokenUsed((int)$uploadToken['id']);

            return ResponseFramework::json($this->publicUploadResponse($result), !empty($result['duplicate']) ? 200 : 201);
        } finally {
            if ($temporaryFile !== null && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    private function tokenFromRequest(RequestFramework $request): string
    {
        $authorization = trim((string)$request->header('Authorization', ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
            return trim($match[1]);
        }

        return '';
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

        unset($result['conversion_jobs']['embedded']);

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

    private function filenameFromRequest(RequestFramework $request): string
    {
        $filename = trim((string)$request->header('X-Swallowtail-Filename', (string)$request->query('filename', 'upload.CR2')));

        return $filename !== '' ? $filename : 'upload.CR2';
    }

    private function copyInputStreamToTemporaryFile(string $inputStream): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'swallowtail-raw-');
        if (!is_string($temporaryFile)) {
            throw new RuntimeException('Unable to allocate temporary RAW upload file.');
        }

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

        stream_copy_to_stream($source, $destination);
        fclose($source);
        fclose($destination);

        return $temporaryFile;
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
