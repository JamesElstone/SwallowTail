<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailSpiceBushRegistrationApiService
{
    public function __construct(
        private readonly UserAuthenticationService $userAuthenticationService = new UserAuthenticationService(),
        private readonly SwallowtailPhotoLibraryService $photoLibraryService = new SwallowtailPhotoLibraryService(),
        private readonly CardAccessFramework $cardAccess = new CardAccessFramework(),
        private readonly OtpService $otpService = new OtpService(),
    ) {
    }

    public function handleRegister(RequestFramework $request): ResponseFramework
    {
        if ($request->method() !== 'POST') {
            return $this->error(['SpiceBush registration API expects POST.'], 405);
        }

        if (!$this->photoLibraryService->schemaAvailable()) {
            return $this->error(['Swallowtail photo database tables are not available. Run the database migrations.'], 503);
        }

        $username = trim((string)$request->post('username', (string)$request->post('email_address', '')));
        $password = (string)$request->post('password', '');
        $user = $this->userAuthenticationService->authenticateByEmailAddress($username, $password);

        if (!is_array($user)) {
            return $this->error(['Invalid email address or password.'], 401);
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0 || !in_array('upload_tokens', $this->cardAccess->allowedCardsForUser($userId, ['upload_tokens']), true)) {
            return $this->error(['This account is not allowed to manage upload tokens.'], 403);
        }

        if (!empty($user['must_change_password'])) {
            return $this->error(['This account must change its password before registering SpiceBush.'], 403);
        }

        if (!$this->otpSatisfied($request, $userId)) {
            return $this->error(['A valid six digit OTP code is required for this account.'], 403);
        }

        try {
            $cidrs = $this->cidrsFromRequest($request);
            $token = $this->photoLibraryService->createUploadToken(
                $this->tokenLabelFromRequest($request),
                $userId,
                null,
                $cidrs
            );
        } catch (Throwable $exception) {
            return $this->error([$exception->getMessage()], 400);
        }

        $apiUrl = $this->apiUrl($request);

        return ResponseFramework::json([
            'success' => true,
            'token' => (string)$token['token'],
            'token_id' => (int)$token['id'],
            'api_url' => $apiUrl,
            'ping_url' => $apiUrl . '/ping.php',
            'raw_upload_url' => $apiUrl . '/raw-upload.php',
            'quick_checksum_url' => $apiUrl . '/quick-checksum.php',
            'quick_checksum_algorithm' => SwallowtailPhotoLibraryService::QUICK_HASH_ALGORITHM,
            'cidrs' => (array)$token['cidrs'],
        ]);
    }

    private function cidrsFromRequest(RequestFramework $request): array
    {
        $requested = $request->post('cidrs', null);
        if ($requested !== null && trim(is_array($requested) ? implode("\n", $requested) : (string)$requested) !== '') {
            return $this->photoLibraryService->normaliseCidrs(is_array($requested) ? $requested : (string)$requested);
        }

        $remoteAddress = trim((string)$request->remoteAddress());
        if ($remoteAddress === '') {
            throw new InvalidArgumentException('A CIDR range is required when the client IP cannot be detected.');
        }

        $packed = @inet_pton($remoteAddress);
        if ($packed === false) {
            throw new InvalidArgumentException('The client IP address could not be converted into an upload-token CIDR.');
        }

        return [$remoteAddress . '/' . (strlen($packed) === 4 ? '32' : '128')];
    }

    private function tokenLabelFromRequest(RequestFramework $request): string
    {
        $label = trim((string)$request->post('token_label', ''));
        if ($label !== '') {
            return mb_substr($label, 0, 255);
        }

        $deviceId = trim((string)$request->post('device_id', ''));
        if ($deviceId !== '') {
            return mb_substr('SpiceBush ' . $deviceId, 0, 255);
        }

        return 'SpiceBush';
    }

    private function otpSatisfied(RequestFramework $request, int $userId): bool
    {
        if (!$this->otpService->isOTPenabled($userId)) {
            return true;
        }

        $otpCode = trim((string)$request->post('otp_code', (string)$request->post('otp', '')));

        return $this->otpService->checkOTP($userId, $otpCode, true);
    }

    private function apiUrl(RequestFramework $request): string
    {
        $override = rtrim(trim((string)AppConfigurationStore::get('invitation.base_url_override', '')), '/');
        if ($override !== '') {
            return $override . '/api';
        }

        $host = trim((string)$request->header('Host', (string)$request->server('HTTP_HOST', '')));
        if ($host === '') {
            $host = trim((string)$request->server('SERVER_NAME', ''));
        }

        if ($host === '') {
            return '/api';
        }

        $scheme = $request->isSecure() ? 'https' : 'http';

        return $scheme . '://' . $host . '/api';
    }

    private function error(array $errors, int $statusCode): ResponseFramework
    {
        return ResponseFramework::json([
            'success' => false,
            'errors' => array_values(array_filter(array_map(
                static fn(mixed $error): string => trim((string)$error),
                $errors
            ))),
        ], $statusCode);
    }
}
