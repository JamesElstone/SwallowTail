<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _download extends PageContextFramework
{
    public function id(): string
    {
        return 'download';
    }

    public function title(): string
    {
        return 'Download';
    }

    public function subtitle(): string
    {
        return 'Download accessible event files.';
    }

    public function cards(): array
    {
        return [
            'event_downloads',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        return [
            'page' => [
                'page_id' => 'download',
                'page_cards' => $this->cards(),
            ],
        ];
    }
}
