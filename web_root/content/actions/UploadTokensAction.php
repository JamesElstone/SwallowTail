<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailPhotoLibraryService;

final class UploadTokensAction implements ActionInterfaceFramework
{
    public function __construct(
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
    ) {
    }

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $session = new SessionAuthenticationService();
        $session->startSession();

        if (!$this->canManage($session) || !$session->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
            return $this->error('You do not have permission to manage upload tokens, or your security token expired.');
        }

        try {
            return match (strtolower(trim((string)$request->input('upload_token_action', '')))) {
                'create' => $this->createToken($request, $session),
                'update' => $this->updateToken($request),
                'delete' => $this->deleteToken($request),
                default => $this->error('Upload token action was not recognised.'),
            };
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage());
        }
    }

    private function createToken(RequestFramework $request, SessionAuthenticationService $session): ActionResultFramework
    {
        $token = $this->photoLibraryService->createUploadToken(
            (string)$request->input('token_label', ''),
            $this->currentUserId($session),
            $this->expiresAtFromRequest($request),
            $this->cidrsFromRequest($request)
        );

        return ActionResultFramework::success(
            ['upload.tokens'],
            [[
                'type' => 'success',
                'message' => 'Upload token created. Copy the token now; it will not be shown again.',
            ]],
            [],
            [
                'upload_tokens' => [
                    'created_token' => (string)$token['token'],
                ],
            ]
        );
    }

    private function updateToken(RequestFramework $request): ActionResultFramework
    {
        $this->photoLibraryService->updateUploadToken((int)$request->input('token_id', 0), [
            'token_label' => (string)$request->input('token_label', ''),
            'expires_at' => (string)$request->input('expires_at', ''),
            'is_active' => $this->checkboxValue($request, 'is_active'),
            'can_upload_raw' => $this->checkboxValue($request, 'can_upload_raw'),
            'cidrs' => $this->cidrsFromRequest($request),
        ]);

        return ActionResultFramework::success(['upload.tokens'], [[
            'type' => 'success',
            'message' => 'Upload token updated.',
        ]]);
    }

    private function deleteToken(RequestFramework $request): ActionResultFramework
    {
        $this->photoLibraryService->deleteUploadToken((int)$request->input('token_id', 0));

        return ActionResultFramework::success(['upload.tokens'], [[
            'type' => 'success',
            'message' => 'Upload token deleted.',
        ]]);
    }

    private function canManage(SessionAuthenticationService $session): bool
    {
        $userId = $this->currentUserId($session);

        return $userId > 0 && in_array('upload_tokens', (new CardAccessFramework())->allowedCardsForUser($userId, ['upload_tokens']), true);
    }

    private function currentUserId(SessionAuthenticationService $session): int
    {
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId);
    }

    private function expiresAtFromRequest(RequestFramework $request): ?DateTimeImmutable
    {
        $value = trim((string)$request->input('expires_at', ''));
        if ($value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private function cidrsFromRequest(RequestFramework $request): array
    {
        return $this->photoLibraryService->normaliseCidrs((string)$request->input('cidrs', ''));
    }

    private function checkboxValue(RequestFramework $request, string $name): bool
    {
        $value = $request->input($name, '0');
        if (is_array($value)) {
            $value = end($value);
        }

        return (string)$value === '1';
    }

    private function error(string $message): ActionResultFramework
    {
        return new ActionResultFramework(false, ['upload.tokens'], [[
            'type' => 'error',
            'message' => $message,
        ]]);
    }
}
