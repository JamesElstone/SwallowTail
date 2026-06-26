<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _profiles extends PageContextFramework
{
    public function id(): string
    {
        return 'profiles';
    }

    public function title(): string
    {
        return 'Profiles';
    }

    public function subtitle(): string
    {
        return 'Internal and RawTheapee conversion profiles.';
    }

    public function cards(): array
    {
        return [
            'internal_profiles',
            'rawtheapee_profiles',
            'combined_profile_preview',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $session = new SessionAuthenticationService();
        $session->startSession();

        return [
            'page' => [
                'page_id' => 'profiles',
                'page_cards' => $this->cards(),
                'csrf_token' => $session->csrfToken(),
            ],
        ];
    }
}
