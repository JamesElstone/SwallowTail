<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailStorageService
{
    public const DATA_DIRECTORY = 'swallowtail-data';
    public const IMAGE_TYPES = ['source', 'original', 'embedded', 'thumbnail', 'filtered', 'profile'];

    public function storageLocations(int $requiredBytes = 0, ?string $checksum = null): array
    {
        $locations = [];
        $excluded = $this->excludedLocations();
        $threshold = $this->fullThresholdPercent();

        foreach ($this->mountedBaseLocations() as $baseLocation) {
            $dataRoot = $this->dataRoot($baseLocation);
            $totalBytes = $this->totalBytes($baseLocation);
            $availableBytes = $this->availableBytes($baseLocation);
            $freePercent = $this->freePercent($availableBytes, $totalBytes);
            $isExcluded = in_array($baseLocation, $excluded, true);
            $isRoot = $this->isRootLocation($baseLocation);
            $belowThreshold = $freePercent !== null && $freePercent < $threshold;

            $locations[] = [
                'storage_base_location' => $baseLocation,
                'label' => $baseLocation,
                'root_path' => $dataRoot,
                'data_root' => $dataRoot,
                'total_bytes' => $totalBytes,
                'available_bytes' => $availableBytes,
                'free_percent' => $freePercent,
                'full_threshold_percent' => $threshold,
                'is_excluded' => $isExcluded,
                'is_root_partition' => $isRoot,
                'is_full' => $belowThreshold,
                'can_write' => !$isExcluded
                    && !$belowThreshold
                    && ($availableBytes === null || $availableBytes >= $requiredBytes),
            ];
        }

        usort($locations, static fn(array $a, array $b): int => strcmp((string)$a['storage_base_location'], (string)$b['storage_base_location']));

        if ($checksum !== null && $checksum !== '') {
            $chosen = $this->chooseWritableLocation($checksum, $requiredBytes, $locations);
            foreach ($locations as &$location) {
                $location['is_selected'] = $chosen !== null
                    && (string)$location['storage_base_location'] === (string)$chosen['storage_base_location'];
            }
            unset($location);
        }

        return $locations;
    }

    public function writableLocationForChecksum(string $checksum, int $requiredBytes = 0): array
    {
        $location = $this->chooseWritableLocation($checksum, $requiredBytes, $this->storageLocations($requiredBytes));
        if ($location === null) {
            throw new RuntimeException('No writable SwallowTail storage location has enough free space.');
        }

        return $location;
    }

    public function imagePath(string $storageBaseLocation, string $checksum, string $imageType): string
    {
        $checksum = $this->normaliseChecksum($checksum);
        $imageType = $this->normaliseImageType($imageType);
        $extension = match ($imageType) {
            'source' => 'cr2',
            'profile' => 'pp3',
            default => 'jpg',
        };

        return $this->dataRoot($storageBaseLocation)
            . substr($checksum, 0, 2) . DIRECTORY_SEPARATOR
            . substr($checksum, 2, 2) . DIRECTORY_SEPARATOR
            . $checksum . '_' . $imageType . '.' . $extension;
    }

    public function ensureDirectoryForPath(string $absolutePath): void
    {
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create SwallowTail storage directory.');
        }
    }

    public function storeSourceFile(string $sourcePath, string $checksum, bool $move = false): array
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('RAW source file is not readable.');
        }

        $sourceBytes = (int)filesize($sourcePath);
        $location = $this->writableLocationForChecksum($checksum, $sourceBytes);
        $destinationPath = $this->imagePath((string)$location['storage_base_location'], $checksum, 'source');
        $this->ensureDirectoryForPath($destinationPath);

        if (!is_file($destinationPath)) {
            $stored = $move
                ? (@rename($sourcePath, $destinationPath) || (@copy($sourcePath, $destinationPath) && @unlink($sourcePath)))
                : @copy($sourcePath, $destinationPath);

            if (!$stored) {
                throw new RuntimeException('Unable to store RAW file in SwallowTail storage.');
            }

            @chmod($destinationPath, 0660);
        }

        return [
            'bytes' => (int)filesize($destinationPath),
            'storage_base_location' => (string)$location['storage_base_location'],
            'absolute_path' => $destinationPath,
        ];
    }

    public function imageInfo(array $photo, string $imageType): ?array
    {
        $base = trim((string)($photo['storage_base_location'] ?? ''));
        $checksum = trim((string)($photo['original_sha256'] ?? ''));
        if ($base === '' || $checksum === '') {
            return null;
        }

        try {
            $path = $this->imagePath($base, $checksum, $imageType);
        } catch (Throwable) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $bytes = (int)filesize($path);
        if ($bytes <= 0) {
            return null;
        }

        return [
            'absolute_path' => $path,
            'bytes' => $bytes,
            'sha256' => hash_file('sha256', $path),
            'modified_at' => (int)filemtime($path),
            'image_type' => $imageType,
        ];
    }

    public function setLocationExcluded(string $storageBaseLocation, bool $isExcluded): void
    {
        if (!InterfaceDB::tableExists('storage_location_properties')) {
            throw new RuntimeException('Storage location properties table is not available. Run the database migrations.');
        }

        $storageBaseLocation = $this->normaliseAbsoluteDirectory($storageBaseLocation);
        $existingId = InterfaceDB::fetchColumn(
            'SELECT id FROM storage_location_properties WHERE storage_base_location = :storage_base_location LIMIT 1',
            ['storage_base_location' => $storageBaseLocation]
        );

        if ($existingId !== false && $existingId !== null) {
            InterfaceDB::prepareExecute(
                "UPDATE storage_location_properties
                 SET is_excluded = :is_excluded,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                [
                    'id' => (int)$existingId,
                    'is_excluded' => $isExcluded ? 1 : 0,
                ]
            );
            return;
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO storage_location_properties (
                storage_base_location,
                is_excluded
            ) VALUES (
                :storage_base_location,
                :is_excluded
            )",
            [
                'storage_base_location' => $storageBaseLocation,
                'is_excluded' => $isExcluded ? 1 : 0,
            ]
        );
    }

    public function normaliseChecksum(string $checksum): string
    {
        $checksum = strtolower(trim($checksum));
        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            throw new InvalidArgumentException('Image checksum must be a SHA-256 hex string.');
        }

        return $checksum;
    }

    public function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));
        if (!in_array($imageType, self::IMAGE_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported image type.');
        }

        return $imageType;
    }

    private function chooseWritableLocation(string $checksum, int $requiredBytes, array $locations): ?array
    {
        $this->normaliseChecksum($checksum);
        $writable = array_values(array_filter(
            $locations,
            static fn(array $location): bool => !empty($location['can_write'])
        ));

        if ($writable === []) {
            return null;
        }

        if ((bool)AppConfigurationStore::get('swallowtail.storage.round_robin_locations', false)) {
            $lastDigit = substr(strtolower($checksum), -1);
            $index = hexdec($lastDigit) % count($writable);

            return $writable[$index];
        }

        return $writable[0];
    }

    private function mountedBaseLocations(): array
    {
        $locations = DIRECTORY_SEPARATOR === '\\'
            ? $this->windowsMountedBaseLocations()
            : $this->unixMountedBaseLocations();

        $locations = array_values(array_unique(array_map(
            fn(string $path): string => $this->normaliseAbsoluteDirectory($path),
            $locations
        )));

        $includeRoot = (bool)AppConfigurationStore::get('swallowtail.storage.store_on_root_partition', false);
        $locations = array_values(array_filter($locations, fn(string $path): bool => $includeRoot || !$this->isRootLocation($path)));
        sort($locations, SORT_STRING);

        return $locations;
    }

    private function unixMountedBaseLocations(): array
    {
        $output = [];
        $result = @shell_exec('df -Pk 2>/dev/null');
        if (is_string($result) && trim($result) !== '') {
            $lines = preg_split('/\r?\n/', trim($result)) ?: [];
            array_shift($lines);
            foreach ($lines as $line) {
                $columns = preg_split('/\s+/', trim($line));
                if (is_array($columns) && count($columns) >= 6) {
                    $mount = (string)$columns[count($columns) - 1];
                    if ($mount !== '' && !str_starts_with($mount, '/dev')) {
                        $output[] = $mount;
                    }
                }
            }
        }

        return $output !== [] ? $output : [dirname(PROJECT_ROOT)];
    }

    private function windowsMountedBaseLocations(): array
    {
        $drive = preg_match('/^[A-Za-z]:[\\\\\\/]/', PROJECT_ROOT) === 1 ? substr(PROJECT_ROOT, 0, 3) : '';

        return $drive !== '' ? [$drive] : [PROJECT_ROOT];
    }

    private function excludedLocations(): array
    {
        if (!InterfaceDB::tableExists('storage_location_properties')) {
            return [];
        }

        return array_map(
            fn(array $row): string => $this->normaliseAbsoluteDirectory((string)$row['storage_base_location']),
            InterfaceDB::fetchAll('SELECT storage_base_location FROM storage_location_properties WHERE is_excluded = 1')
        );
    }

    private function dataRoot(string $baseLocation): string
    {
        $base = $this->normaliseAbsoluteDirectory($baseLocation);
        $root = $base . self::DATA_DIRECTORY . DIRECTORY_SEPARATOR;
        $this->assertRootIsPrivate($root);

        return $root;
    }

    private function totalBytes(string $rootPath): ?int
    {
        $bytes = @disk_total_space($rootPath);

        return is_float($bytes) ? (int)$bytes : null;
    }

    private function availableBytes(string $rootPath): ?int
    {
        $bytes = @disk_free_space($rootPath);

        return is_float($bytes) ? (int)$bytes : null;
    }

    private function freePercent(?int $availableBytes, ?int $totalBytes): ?float
    {
        if ($availableBytes === null || $totalBytes === null || $totalBytes <= 0) {
            return null;
        }

        return ($availableBytes / $totalBytes) * 100.0;
    }

    private function fullThresholdPercent(): float
    {
        $threshold = (float)AppConfigurationStore::get('swallowtail.storage.full_threshold_percent', 5);

        return max(0.0, min(100.0, $threshold));
    }

    private function isRootLocation(string $path): bool
    {
        $path = $this->normaliseAbsoluteDirectory($path);
        if ($path === DIRECTORY_SEPARATOR) {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\\/]$/', $path) === 1;
    }

    private function normaliseAbsoluteDirectory(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        if ($path === '') {
            throw new InvalidArgumentException('Storage base location must not be empty.');
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2}|[\\\\\\/])/', $path) !== 1) {
            $path = PROJECT_ROOT . ltrim($path, DIRECTORY_SEPARATOR);
        }

        $parts = [];
        $prefix = '';

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            $prefix = substr($path, 0, 3);
            $path = substr($path, 3);
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $path = ltrim($path, DIRECTORY_SEPARATOR);
        }

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return rtrim($prefix . implode(DIRECTORY_SEPARATOR, $parts), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    private function assertRootIsPrivate(string $root): void
    {
        $webRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, APP_ROOT), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($root, $webRoot)) {
            throw new RuntimeException('SwallowTail storage root must be outside web_root.');
        }
    }
}
