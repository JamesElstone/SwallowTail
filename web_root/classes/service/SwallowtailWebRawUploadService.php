<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailWebRawUploadService
{
    private const MAX_FILES = 3;

    public function __construct(
        private readonly SwallowtailPhotoIngestService $photoIngestService = new SwallowtailPhotoIngestService(),
    ) {
    }

    public function uploadCr2Files(int $userId, array $files, array $requestMetadata = []): array
    {
        if ($userId <= 0) {
            return [
                'success' => false,
                'errors' => ['A signed-in user is required before uploading CR2 files.'],
                'files' => [],
            ];
        }

        $uploads = $this->normaliseUploads($files['cr2_files'] ?? $files['raw_files'] ?? $files['raw_file'] ?? []);
        if ($uploads === []) {
            return [
                'success' => false,
                'errors' => ['Choose at least one CR2 file to upload.'],
                'files' => [],
            ];
        }

        if (count($uploads) > self::MAX_FILES) {
            return [
                'success' => false,
                'errors' => ['Upload no more than three CR2 files at once.'],
                'files' => [],
            ];
        }

        $results = [];
        $errors = [];

        foreach ($uploads as $upload) {
            $result = $this->uploadSingleCr2($userId, $upload, $requestMetadata);
            $results[] = $result;

            if (empty($result['success'])) {
                foreach ((array)($result['errors'] ?? ['CR2 upload failed.']) as $message) {
                    $errors[] = (string)$message;
                }
            }
        }

        return [
            'success' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'files' => $results,
        ];
    }

    private function uploadSingleCr2(int $userId, array $upload, array $requestMetadata): array
    {
        $filename = trim((string)($upload['name'] ?? 'upload.CR2'));
        $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'filename' => $filename,
                'errors' => [$filename . ': ' . $this->uploadErrorMessage($uploadError)],
            ];
        }

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'cr2') {
            return [
                'success' => false,
                'filename' => $filename,
                'errors' => [$filename . ': Only .CR2 files can be uploaded here.'],
            ];
        }

        $tmpName = (string)($upload['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName) || !is_readable($tmpName)) {
            return [
                'success' => false,
                'filename' => $filename,
                'errors' => [$filename . ': Uploaded file was not readable.'],
            ];
        }

        try {
            $result = $this->photoIngestService->ingestLocalRawFile($tmpName, $filename, [
                'move_source' => false,
                'uploaded_via' => 'web',
                'uploaded_by_user_id' => $userId,
                'request_metadata' => $requestMetadata,
            ]);
        } catch (RuntimeException $exception) {
            return [
                'success' => false,
                'filename' => $filename,
                'errors' => [$filename . ': ' . $this->publicStorageError($exception)],
            ];
        }

        return array_merge(
            [
                'filename' => $filename,
                'success' => !empty($result['success']),
            ],
            $result
        );
    }

    private function publicStorageError(RuntimeException $exception): string
    {
        return str_contains($exception->getMessage(), 'No writable SwallowTail storage location')
            ? 'No upload storage locations are currently available.'
            : 'The CR2 upload failed while storing the file.';
    }

    private function normaliseUploads(array $input): array
    {
        if ($input === []) {
            return [];
        }

        if (isset($input['tmp_name']) && is_array($input['tmp_name'])) {
            $uploads = [];
            $count = count($input['tmp_name']);

            for ($index = 0; $index < $count; $index++) {
                $uploads[] = [
                    'name' => (string)($input['name'][$index] ?? ''),
                    'type' => (string)($input['type'][$index] ?? ''),
                    'tmp_name' => (string)($input['tmp_name'][$index] ?? ''),
                    'error' => (int)($input['error'][$index] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int)($input['size'][$index] ?? 0),
                ];
            }

            return $uploads;
        }

        if (isset($input['tmp_name'])) {
            return [$input];
        }

        $uploads = [];
        foreach ($input as $upload) {
            if (is_array($upload) && isset($upload['tmp_name'])) {
                $uploads[] = $upload;
            }
        }

        return $uploads;
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The CR2 file exceeded the configured upload limit.',
            UPLOAD_ERR_PARTIAL => 'The CR2 file was only partially received.',
            UPLOAD_ERR_NO_FILE => 'No CR2 file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the temporary upload file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            default => 'The CR2 upload failed.',
        };
    }
}
