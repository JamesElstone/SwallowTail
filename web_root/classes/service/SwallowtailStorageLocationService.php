<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailStorageLocationService
{
    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
    ) {
    }

    public function locations(int $requiredBytes = 0): array
    {
        return $this->storageService->storageLocations($requiredBytes);
    }

    public function setExcluded(string $storageBaseLocation, bool $isExcluded): void
    {
        $this->storageService->setLocationExcluded($storageBaseLocation, $isExcluded);
    }
}
