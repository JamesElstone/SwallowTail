<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _gallery extends PageContextFramework
{
    public function id(): string
    {
        logDetails();

        return 'gallery';
    }

    public function title(): string
    {
        logDetails();

        return 'Gallery';
    }

    public function subtitle(): string
    {
        logDetails();

        return 'Browse accessible SwallowTail thumbnails.';
    }

    public function cards(): array
    {
        logDetails();

        return [
            'browse_gallery',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        logDetails();

        return [
            'page' => [
                'page_id' => 'gallery',
                'page_cards' => $this->cards(),
            ],
        ];
    }
}
