<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _picture_viewer extends PageContextFramework
{
    public function id(): string
    {
        return 'picture_viewer';
    }

    public function title(): string
    {
        return 'Picture Viewer';
    }

    public function subtitle(): string
    {
        return 'Inspect an accessible Swallowtail photo.';
    }

    public function cards(): array
    {
        return [
            'picture_viewer',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        return [
            'page' => [
                'page_id' => 'picture_viewer',
                'page_cards' => $this->cards(),
                'photo_id' => max(0, (int)$request->query('photo_id', 0)),
            ],
        ];
    }
}
