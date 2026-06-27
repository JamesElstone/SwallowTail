<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ApiSecurityService
{
    private readonly SessionAuthenticationService $sessionAuthenticationService;
    private readonly AntiFraudService $antiFraudService;
    private readonly int $userId;

    public function __construct(private readonly RequestFramework $request)
    {
        $this->sessionAuthenticationService = new SessionAuthenticationService(request: $request);
        $this->sessionAuthenticationService->startSession();
        $this->antiFraudService = AntiFraudService::instance($request);

        $currentDeviceId = trim((string)$this->antiFraudService->requestValue('Client-Device-ID'));
        $this->userId = $this->sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }

    /**
     * @param array<int, string> $methods
     */
    public function requireBrowserApi(string $name, array $methods, bool $requireCsrf = false): ?ResponseFramework
    {
        $methods = array_values(array_unique(array_map(
            static fn(string $method): string => strtoupper(trim($method)),
            $methods
        )));

        if ($methods === [] || !in_array($this->request->method(), $methods, true)) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => [$name . ' expects ' . implode(', ', $methods) . '.'],
            ], 405);
        }

        if ($this->userId <= 0) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Authentication is required.'],
            ], 401);
        }

        if ($requireCsrf && !$this->sessionAuthenticationService->isValidCsrfToken((string)$this->request->input('csrf_token', ''))) {
            return ResponseFramework::json([
                'success' => false,
                'errors' => ['Your security token expired. Please refresh the page and try again.'],
            ], 409);
        }

        return null;
    }

    public function userId(): int
    {
        return $this->userId;
    }
}
