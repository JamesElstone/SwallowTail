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
        $snapshot = (new SwallowtailStorageCacheService())->snapshot();
        $locations = is_array($snapshot) && isset($snapshot['locations']) && is_array($snapshot['locations'])
            ? $snapshot['locations']
            : $this->liveStorageLocations();

        return $this->prepareLocationsForRequest($locations, $requiredBytes, $checksum);
    }

    public function storageSnapshot(bool $allowStale = false): array
    {
        $snapshot = (new SwallowtailStorageCacheService())->snapshot($allowStale);
        if (is_array($snapshot)) {
            $snapshot['locations'] = $this->prepareLocationsForRequest((array)($snapshot['locations'] ?? []), 0, null);

            return $snapshot;
        }

        return $this->liveStorageSnapshot();
    }

    public function refreshStorageSnapshot(): array
    {
        $snapshot = $this->liveStorageSnapshot();
        (new SwallowtailStorageCacheService())->store($snapshot);

        return $snapshot;
    }

    public function liveStorageSnapshot(): array
    {
        $locations = $this->liveStorageLocations();
        $zpools = $this->zpoolPanels($locations);

        return [
            'version' => 1,
            'generated_at' => time(),
            'generated_at_iso' => gmdate('c'),
            'locations' => $locations,
            'zpools' => $zpools,
            'mount_signature' => $this->mountSignature($locations),
        ];
    }

    public function liveStorageLocations(): array
    {
        $locations = [];
        $properties = $this->locationProperties();
        $threshold = $this->fullThresholdPercent();
        $zfsDatasetsByMount = $this->zfsDatasetsByMountpoint();
        $selectedDatasets = $this->selectedZfsDatasets($zfsDatasetsByMount, $properties);

        foreach ($this->mountedBaseLocations() as $baseLocation) {
            $dataRoot = $this->dataRoot($baseLocation);
            $totalBytes = $this->totalBytes($baseLocation);
            $availableBytes = $this->availableBytes($baseLocation);
            $freePercent = $this->freePercent($availableBytes, $totalBytes);
            $dataset = $zfsDatasetsByMount[$baseLocation] ?? null;
            $isZfs = is_array($dataset);
            $zpoolName = $isZfs ? (string)($dataset['zpool_name'] ?? '') : '';
            $datasetName = $isZfs ? (string)($dataset['dataset_name'] ?? '') : '';
            $propertyKey = $isZfs && $zpoolName !== '' ? $zpoolName : $baseLocation;
            $property = (array)($properties[$propertyKey] ?? []);
            $isExcluded = !empty($property['is_excluded']);
            $isRoot = $this->isRootLocation($baseLocation);
            $belowThreshold = $freePercent !== null && $freePercent < $threshold;
            $isSelectedZfsDataset = $isZfs
                && $zpoolName !== ''
                && $datasetName !== ''
                && (string)($selectedDatasets[$zpoolName]['dataset_name'] ?? '') === $datasetName;

            $locations[] = [
                'storage_base_location' => $baseLocation,
                'label' => $isZfs && $datasetName !== '' ? $datasetName : $baseLocation,
                'root_path' => $dataRoot,
                'data_root' => $dataRoot,
                'total_bytes' => $totalBytes,
                'available_bytes' => $availableBytes,
                'free_percent' => $freePercent,
                'full_threshold_percent' => $threshold,
                'is_excluded' => $isExcluded,
                'is_root_partition' => $isRoot,
                'is_zfs' => $isZfs,
                'is_zpool_panel' => false,
                'zpool_name' => $zpoolName,
                'dataset_name' => $datasetName,
                'is_selected_zfs_dataset' => $isSelectedZfsDataset,
                'is_full' => $belowThreshold,
                'can_write' => !$isExcluded
                    && !$belowThreshold
                    && (!$isZfs || $isSelectedZfsDataset)
                    && ($availableBytes === null || $availableBytes >= 0),
            ];
        }

        usort($locations, static fn(array $a, array $b): int => strcmp((string)$a['storage_base_location'], (string)$b['storage_base_location']));

        return $locations;
    }

    public function writableLocationForChecksumExcluding(string $checksum, int $requiredBytes = 0, array $excludedBaseLocations = []): array
    {
        $excluded = array_map(fn(string $path): string => $this->normaliseAbsoluteDirectory($path), $excludedBaseLocations);
        $locations = array_values(array_filter(
            $this->storageLocations($requiredBytes),
            static fn(array $location): bool => !in_array((string)($location['storage_base_location'] ?? ''), $excluded, true)
        ));
        $location = $this->chooseWritableLocation($checksum, $requiredBytes, $locations);
        if ($location === null) {
            throw new RuntimeException('No writable SwallowTail storage location has enough free space.');
        }

        return $location;
    }

    public function setZpoolDataset(string $zpoolName, string $datasetName): void
    {
        if (!InterfaceDB::tableExists('storage_location_properties')) {
            throw new RuntimeException('Storage location properties table is not available. Run the database migrations.');
        }

        $zpoolName = trim($zpoolName);
        $datasetName = trim($datasetName);
        if ($zpoolName === '' || $datasetName === '') {
            throw new InvalidArgumentException('Zpool and dataset names must not be empty.');
        }

        $existingId = InterfaceDB::fetchColumn(
            'SELECT id FROM storage_location_properties WHERE storage_base_location = :storage_base_location AND is_zfs = 1 LIMIT 1',
            ['storage_base_location' => $zpoolName]
        );

        if ($existingId !== false && $existingId !== null) {
            InterfaceDB::prepareExecute(
                "UPDATE storage_location_properties
                 SET dataset_name = :dataset_name,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                [
                    'id' => (int)$existingId,
                    'dataset_name' => $datasetName,
                ]
            );
        } else {
            InterfaceDB::prepareExecute(
                "INSERT INTO storage_location_properties (
                    storage_base_location,
                    is_excluded,
                    is_zfs,
                    dataset_name
                ) VALUES (
                    :storage_base_location,
                    0,
                    1,
                    :dataset_name
                )",
                [
                    'storage_base_location' => $zpoolName,
                    'dataset_name' => $datasetName,
                ]
            );
        }

        (new SwallowtailStorageCacheService())->clear();
    }

    /**
     * @param array<int, array<string, mixed>> $locations
     */
    private function prepareLocationsForRequest(array $locations, int $requiredBytes, ?string $checksum): array
    {
        foreach ($locations as &$location) {
            if (!is_array($location)) {
                $location = [];
                continue;
            }

            $availableBytes = is_numeric($location['available_bytes'] ?? null) ? (int)$location['available_bytes'] : null;
            $location['can_write'] = empty($location['is_excluded'])
                && empty($location['is_full'])
                && (empty($location['is_zfs']) || !empty($location['is_selected_zfs_dataset']))
                && ($availableBytes === null || $availableBytes >= $requiredBytes);
        }
        unset($location);

        $locations = array_values(array_filter($locations, static fn(array $location): bool => $location !== []));
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
            (new SwallowtailStorageCacheService())->clear();
            return;
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO storage_location_properties (
                storage_base_location,
                is_excluded,
                is_zfs
            ) VALUES (
                :storage_base_location,
                :is_excluded,
                0
            )",
            [
                'storage_base_location' => $storageBaseLocation,
                'is_excluded' => $isExcluded ? 1 : 0,
            ]
        );

        (new SwallowtailStorageCacheService())->clear();
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

        $testBaseLocation = trim((string)AppConfigurationStore::get('swallowtail.storage.test_base_location', ''));
        if ($testBaseLocation !== '') {
            $locations[] = $testBaseLocation;
        }

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

    /**
     * @param array<int, array<string, mixed>> $locations
     * @return array<int, array<string, mixed>>
     */
    private function zpoolPanels(array $locations): array
    {
        $pools = $this->zpoolList();
        $datasetsByPool = [];
        foreach ($locations as $location) {
            if (empty($location['is_zfs']) || empty($location['zpool_name']) || empty($location['dataset_name'])) {
                continue;
            }
            $zpoolName = (string)$location['zpool_name'];
            $datasetsByPool[$zpoolName][] = [
                'dataset_name' => (string)$location['dataset_name'],
                'mountpoint' => (string)$location['storage_base_location'],
                'selected' => !empty($location['is_selected_zfs_dataset']),
                'available_bytes' => $location['available_bytes'] ?? null,
                'total_bytes' => $location['total_bytes'] ?? null,
                'free_percent' => $location['free_percent'] ?? null,
            ];
        }

        $zpools = [];
        foreach ($datasetsByPool as $zpoolName => $datasets) {
            usort($datasets, static fn(array $a, array $b): int => strcmp((string)$a['dataset_name'], (string)$b['dataset_name']));
            $selected = null;
            foreach ($datasets as $dataset) {
                if (!empty($dataset['selected'])) {
                    $selected = $dataset;
                    break;
                }
            }
            $selected ??= $datasets[0] ?? [];
            $pool = (array)($pools[$zpoolName] ?? []);
            $totalBytes = $pool['total_bytes'] ?? ($selected['total_bytes'] ?? null);
            $availableBytes = $pool['available_bytes'] ?? ($selected['available_bytes'] ?? null);

            $zpools[] = [
                'storage_base_location' => $zpoolName,
                'label' => $zpoolName,
                'is_zfs' => true,
                'is_zpool_panel' => true,
                'zpool_name' => $zpoolName,
                'datasets' => $datasets,
                'selected_dataset_name' => (string)($selected['dataset_name'] ?? ''),
                'selected_mountpoint' => (string)($selected['mountpoint'] ?? ''),
                'total_bytes' => $totalBytes,
                'available_bytes' => $availableBytes,
                'free_percent' => $this->freePercent(
                    is_numeric($availableBytes) ? (int)$availableBytes : null,
                    is_numeric($totalBytes) ? (int)$totalBytes : null
                ),
            ];
        }

        usort($zpools, static fn(array $a, array $b): int => strcmp((string)$a['zpool_name'], (string)$b['zpool_name']));

        return $zpools;
    }

    private function mountSignature(array $locations): string
    {
        $parts = [];
        foreach ($locations as $location) {
            $parts[] = implode('|', [
                (string)($location['storage_base_location'] ?? ''),
                (string)($location['dataset_name'] ?? ''),
                (string)($location['zpool_name'] ?? ''),
            ]);
        }

        sort($parts, SORT_STRING);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function zfsDatasetsByMountpoint(): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [];
        }

        $result = @shell_exec('zfs list -H -p -o name,mountpoint 2>/dev/null');
        if (!is_string($result) || trim($result) === '') {
            return [];
        }

        $datasets = [];
        foreach (preg_split('/\r?\n/', trim($result)) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line), 2);
            if (!is_array($columns) || count($columns) < 2) {
                continue;
            }
            $datasetName = trim((string)$columns[0]);
            $mountpoint = trim((string)$columns[1]);
            if ($datasetName === '' || $mountpoint === '' || in_array($mountpoint, ['-', 'none', 'legacy'], true)) {
                continue;
            }
            $normalisedMount = $this->normaliseAbsoluteDirectory($mountpoint);
            $zpoolName = explode('/', $datasetName, 2)[0];
            $datasets[$normalisedMount] = [
                'dataset_name' => $datasetName,
                'zpool_name' => $zpoolName,
                'mountpoint' => $normalisedMount,
            ];
        }

        return $datasets;
    }

    /**
     * @return array<string, array<string, int|null>>
     */
    private function zpoolList(): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return [];
        }

        $result = @shell_exec('zpool list -H -p -o name,size,alloc,free 2>/dev/null');
        if (!is_string($result) || trim($result) === '') {
            return [];
        }

        $pools = [];
        foreach (preg_split('/\r?\n/', trim($result)) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (!is_array($columns) || count($columns) < 4) {
                continue;
            }
            $name = trim((string)$columns[0]);
            if ($name === '') {
                continue;
            }
            $pools[$name] = [
                'total_bytes' => is_numeric($columns[1]) ? (int)$columns[1] : null,
                'used_bytes' => is_numeric($columns[2]) ? (int)$columns[2] : null,
                'available_bytes' => is_numeric($columns[3]) ? (int)$columns[3] : null,
            ];
        }

        return $pools;
    }

    /**
     * @param array<string, array<string, mixed>> $zfsDatasetsByMount
     * @param array<string, array<string, mixed>> $properties
     * @return array<string, array<string, string>>
     */
    private function selectedZfsDatasets(array $zfsDatasetsByMount, array $properties): array
    {
        $datasetsByPool = [];
        foreach ($zfsDatasetsByMount as $dataset) {
            $zpoolName = (string)($dataset['zpool_name'] ?? '');
            if ($zpoolName === '') {
                continue;
            }
            $datasetsByPool[$zpoolName][] = $dataset;
        }

        $selected = [];
        foreach ($datasetsByPool as $zpoolName => $datasets) {
            usort($datasets, static fn(array $a, array $b): int => strcmp((string)$a['dataset_name'], (string)$b['dataset_name']));
            $configuredDataset = trim((string)(($properties[$zpoolName] ?? [])['dataset_name'] ?? ''));
            $chosen = $datasets[0] ?? [];
            foreach ($datasets as $dataset) {
                if ($configuredDataset !== '' && (string)$dataset['dataset_name'] === $configuredDataset) {
                    $chosen = $dataset;
                    break;
                }
            }
            if ($chosen !== []) {
                $selected[$zpoolName] = [
                    'dataset_name' => (string)$chosen['dataset_name'],
                    'mountpoint' => (string)$chosen['mountpoint'],
                ];
            }
        }

        return $selected;
    }

    private function windowsMountedBaseLocations(): array
    {
        $drive = preg_match('/^[A-Za-z]:[\\\\\\/]/', PROJECT_ROOT) === 1 ? substr(PROJECT_ROOT, 0, 3) : '';

        return $drive !== '' ? [$drive] : [PROJECT_ROOT];
    }

    private function locationProperties(): array
    {
        try {
            if (!InterfaceDB::tableExists('storage_location_properties')) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $properties = [];
        foreach (InterfaceDB::fetchAll('SELECT storage_base_location, is_excluded, is_zfs, dataset_name FROM storage_location_properties') as $row) {
            $isZfs = !empty($row['is_zfs']);
            $key = $isZfs
                ? trim((string)$row['storage_base_location'])
                : $this->normaliseAbsoluteDirectory((string)$row['storage_base_location']);
            if ($key === '') {
                continue;
            }
            $properties[$key] = [
                'is_excluded' => !empty($row['is_excluded']),
                'is_zfs' => $isZfs,
                'dataset_name' => (string)($row['dataset_name'] ?? ''),
            ];
        }

        return $properties;
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
