<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _edit extends PageContextFramework
{
    public function id(): string
    {
        return 'edit';
    }

    public function title(): string
    {
        return 'Edit';
    }

    public function subtitle(): string
    {
        return 'Edit an accessible SwallowTail photo.';
    }

    public function cards(): array
    {
        return [
            'picture_editor',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        return [
            'page' => [
                'page_id' => 'edit',
                'page_cards' => $this->cards(),
                'photo_id' => max(0, (int)$request->query('photo_id', 0)),
                'csrf_token' => (new SessionAuthenticationService())->csrfToken(),
            ],
        ];
    }
}
