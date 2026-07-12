<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _queues extends PageContextFramework
{
    public function id(): string
    {
        return 'queues';
    }

    public function title(): string
    {
        return 'Queues';
    }

    public function subtitle(): string
    {
        return 'Monitor durable background work and transient Redis pipeline signals.';
    }

    public function cards(): array
    {
        return ['jobs', 'redis_pipeline'];
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
                'page_id' => 'queues',
                'page_cards' => $this->cards(),
                'csrf_token' => $sessionAuthenticationService->csrfToken(),
            ],
        ];
    }
}
