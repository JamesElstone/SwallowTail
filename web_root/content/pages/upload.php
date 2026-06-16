<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _upload extends PageContextFramework
{
    public function id(): string
    {
        return 'upload';
    }

    public function title(): string
    {
        return 'Upload';
    }

    public function subtitle(): string
    {
        return 'Add CR2 RAW image files to Swallowtail storage.';
    }

    public function cards(): array
    {
        return [
            'cr2_upload',
            'recent_uploads',
            'storage_available',
        ];
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework {
        if ($request->action() !== 'upload-cr2') {
            return ActionResultFramework::none();
        }

        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();

        if (!$sessionAuthenticationService->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return $this->uploadResult(false, ['Your security token expired. Please refresh the page and try again.']);
        }

        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->uploadResult(false, ['A signed-in user is required before uploading CR2 files.']);
        }

        if (!in_array('cr2_upload', (new CardAccessFramework())->allowedCardsForUser($userId, ['cr2_upload']), true)) {
            return $this->uploadResult(false, ['You do not have permission to upload CR2 files.']);
        }

        $result = (new SwallowtailWebRawUploadService())->uploadCr2Files($userId, $request->files(), [
            'device_id' => (string)AntiFraudService::instance()->requestValue('Client-Device-ID'),
            'ip_address' => (string)$request->remoteAddress(),
            'user_agent' => (string)$request->header('User-Agent', ''),
        ]);

        if (empty($result['success'])) {
            return $this->uploadResult(false, (array)($result['errors'] ?? ['CR2 upload failed.']), $result);
        }

        $uploadedCount = 0;
        $duplicateCount = 0;

        foreach ((array)($result['files'] ?? []) as $fileResult) {
            if (!empty($fileResult['duplicate'])) {
                $duplicateCount++;
            } elseif (!empty($fileResult['success'])) {
                $uploadedCount++;
            }
        }

        $parts = [];
        if ($uploadedCount > 0) {
            $parts[] = (string)$uploadedCount . ' uploaded';
        }
        if ($duplicateCount > 0) {
            $parts[] = (string)$duplicateCount . ' duplicate' . ($duplicateCount === 1 ? '' : 's');
        }

        return $this->uploadResult(
            true,
            [$parts !== [] ? 'CR2 upload complete: ' . implode(', ', $parts) . '.' : 'CR2 upload complete.'],
            $result
        );
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();

        return [
            'page' => [
                'page_id' => 'upload',
                'page_cards' => $this->cards(),
                'csrf_token' => $sessionAuthenticationService->csrfToken(),
            ],
        ];
    }

    private function uploadResult(bool $success, array $messages, array $uploadContext = []): ActionResultFramework
    {
        $flashMessages = [];
        foreach ($messages as $message) {
            $flashMessages[] = [
                'type' => $success ? 'success' : 'error',
                'message' => (string)$message,
            ];
        }

        return new ActionResultFramework(
            $success,
            ['cr2.upload', 'recent.uploads', 'storage.available', 'browse.gallery'],
            $flashMessages,
            ['show_card' => 'recent_uploads'],
            ['upload_result' => $uploadContext]
        );
    }
}
