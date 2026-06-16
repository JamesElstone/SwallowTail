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
    private readonly string $storageRoot;

    public function __construct(string $storageRoot = '')
    {
        $configuredRoot = trim($storageRoot);

        if ($configuredRoot === '' && class_exists('AppConfigurationStore')) {
            $configuredRoot = trim((string)AppConfigurationStore::get(
                'swallowtail.storage.root',
                (string)AppConfigurationStore::get('uploads.upload_base_dir', '')
            ));
        }

        if ($configuredRoot === '') {
            $configuredRoot = PROJECT_ROOT . 'uploads';
        }

        $this->storageRoot = $this->normaliseAbsoluteDirectory($configuredRoot);
        $this->assertRootIsPrivate($this->storageRoot);
    }

    public function storageRoot(): string
    {
        return $this->storageRoot;
    }

    public function storageLocations(int $requiredBytes = 0): array
    {
        $locations = [];

        if (class_exists('InterfaceDB') && InterfaceDB::tableExists('swallowtail_storage_locations')) {
            $rows = InterfaceDB::fetchAll(
                "SELECT *
                 FROM swallowtail_storage_locations
                 WHERE is_active = 1
                 ORDER BY sort_order, id"
            );

            foreach ($rows as $row) {
                $rootPath = trim((string)($row['root_path'] ?? ''));
                if ($rootPath === '') {
                    continue;
                }

                $rootPath = $this->normaliseAbsoluteDirectory($rootPath);
                $this->assertRootIsPrivate($rootPath);

                $availableBytes = $this->availableBytes($rootPath);
                $reserveBytes = max(0, (int)($row['reserve_bytes'] ?? 0));

                $locations[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'label' => (string)($row['location_label'] ?? ''),
                    'root_path' => $rootPath,
                    'is_read_only' => (int)($row['is_read_only'] ?? 0),
                    'is_full' => (int)($row['is_full'] ?? 0),
                    'reserve_bytes' => $reserveBytes,
                    'available_bytes' => $availableBytes,
                    'can_write' => (int)($row['is_read_only'] ?? 0) === 0
                        && (int)($row['is_full'] ?? 0) === 0
                        && ($availableBytes === null || $availableBytes - $reserveBytes >= $requiredBytes),
                ];
            }
        }

        if ($locations === []) {
            $availableBytes = $this->availableBytes($this->storageRoot);
            $locations[] = [
                'id' => null,
                'label' => 'Default storage',
                'root_path' => $this->storageRoot,
                'is_read_only' => 0,
                'is_full' => 0,
                'reserve_bytes' => 0,
                'available_bytes' => $availableBytes,
                'can_write' => $availableBytes === null || $availableBytes >= $requiredBytes,
            ];
        }

        return $locations;
    }

    public function writableLocation(int $requiredBytes = 0): array
    {
        foreach ($this->storageLocations($requiredBytes) as $location) {
            if (!empty($location['can_write'])) {
                return $location;
            }
        }

        throw new RuntimeException('No writable Swallowtail storage location has enough free space.');
    }

    public function originalRelativePath(string $sha256, string $extension): string
    {
        $sha256 = strtolower(trim($sha256));
        $extension = strtolower(ltrim(trim($extension), '.'));

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Original checksum must be a SHA-256 hex string.');
        }

        if ($extension !== 'cr2') {
            throw new InvalidArgumentException('Unsupported RAW extension.');
        }

        return implode(DIRECTORY_SEPARATOR, [
            'originals',
            substr($sha256, 0, 2),
            substr($sha256, 2, 2),
            $sha256 . '.' . $extension,
        ]);
    }

    public function derivativeRelativePath(string $sha256, string $type, string $extension = 'jpg'): string
    {
        $sha256 = strtolower(trim($sha256));
        $type = strtolower(trim($type));
        $extension = strtolower(ltrim(trim($extension), '.'));

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Derivative checksum must be a SHA-256 hex string.');
        }

        if (!in_array($type, ['embedded', 'original_jpeg', 'thumbnail', 'preview', 'jpeg'], true)) {
            throw new InvalidArgumentException('Unsupported derivative type.');
        }

        if ($extension !== 'jpg' && $extension !== 'jpeg') {
            throw new InvalidArgumentException('Unsupported derivative extension.');
        }

        $suffix = match ($type) {
            'embedded' => '_embedded',
            'original_jpeg' => '_original',
            'preview' => '_preview',
            'thumbnail' => '_thumbnail',
            default => '',
        };

        return implode(DIRECTORY_SEPARATOR, [
            'derivatives',
            $type,
            substr($sha256, 0, 2),
            substr($sha256, 2, 2),
            $sha256 . $suffix . '.' . $extension,
        ]);
    }

    public function absolutePath(string $relativePath, ?string $rootPath = null): string
    {
        $relativePath = $this->normaliseRelativePath($relativePath);
        $rootPath = $rootPath !== null && trim($rootPath) !== ''
            ? $this->normaliseAbsoluteDirectory($rootPath)
            : $this->storageRoot;

        $this->assertRootIsPrivate($rootPath);

        return $rootPath . $relativePath;
    }

    public function ensureDirectoryForRelativePath(string $relativePath, ?string $rootPath = null): void
    {
        $absolutePath = $this->absolutePath($relativePath, $rootPath);
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Swallowtail storage directory.');
        }
    }

    public function storeOriginalFile(string $sourcePath, string $relativePath, bool $move = false): array
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('RAW source file is not readable.');
        }

        $sourceBytes = (int)filesize($sourcePath);
        $location = $this->writableLocation($sourceBytes);
        $rootPath = (string)$location['root_path'];

        $this->ensureDirectoryForRelativePath($relativePath, $rootPath);
        $destinationPath = $this->absolutePath($relativePath, $rootPath);

        if (is_file($destinationPath)) {
            return [
                'bytes' => (int)filesize($destinationPath),
                'storage_location_id' => $location['id'],
                'storage_root_path' => $rootPath,
            ];
        }

        $stored = $move
            ? (@rename($sourcePath, $destinationPath) || (@copy($sourcePath, $destinationPath) && @unlink($sourcePath)))
            : @copy($sourcePath, $destinationPath);

        if (!$stored) {
            throw new RuntimeException('Unable to store RAW file in Swallowtail storage.');
        }

        @chmod($destinationPath, 0660);

        return [
            'bytes' => (int)filesize($destinationPath),
            'storage_location_id' => $location['id'],
            'storage_root_path' => $rootPath,
        ];
    }

    public function moveStoredFile(string $relativePath, string $fromRootPath, string $toRootPath, string $expectedSha256): void
    {
        $expectedSha256 = strtolower(trim($expectedSha256));
        if (preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1) {
            throw new InvalidArgumentException('Expected checksum must be a SHA-256 hex string.');
        }

        $fromPath = $this->absolutePath($relativePath, $fromRootPath);
        $toPath = $this->absolutePath($relativePath, $toRootPath);

        if (!is_file($fromPath) || !is_readable($fromPath)) {
            throw new RuntimeException('Stored RAW source file is not readable.');
        }

        if (hash_file('sha256', $fromPath) !== $expectedSha256) {
            throw new RuntimeException('Stored RAW source checksum did not match before move.');
        }

        $this->ensureDirectoryForRelativePath($relativePath, $toRootPath);

        if (!@copy($fromPath, $toPath)) {
            throw new RuntimeException('Unable to copy RAW file to the target storage location.');
        }

        if (hash_file('sha256', $toPath) !== $expectedSha256) {
            @unlink($toPath);
            throw new RuntimeException('Stored RAW target checksum did not match after move.');
        }

        @chmod($toPath, 0660);
        @unlink($fromPath);
    }

    public function assertPathInsideRoot(string $absolutePath): void
    {
        $normalisedPath = $this->normaliseAbsoluteDirectory(dirname($absolutePath)) . basename($absolutePath);

        if (!str_starts_with($normalisedPath, $this->storageRoot)) {
            throw new RuntimeException('Resolved path is outside the Swallowtail storage root.');
        }
    }

    private function normaliseRelativePath(string $relativePath): string
    {
        $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($relativePath));

        if ($relativePath === '' || preg_match('/^(?:[A-Za-z]:[\\\\\\/]|[\\\\\\/]{2}|[\\\\\\/])/', $relativePath) === 1) {
            throw new InvalidArgumentException('Storage path must be relative.');
        }

        $parts = [];
        foreach (explode(DIRECTORY_SEPARATOR, $relativePath) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                throw new InvalidArgumentException('Storage path cannot contain parent directory segments.');
            }

            $parts[] = $part;
        }

        if ($parts === []) {
            throw new InvalidArgumentException('Storage path must not be empty.');
        }

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function normaliseAbsoluteDirectory(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));

        if ($path === '') {
            throw new InvalidArgumentException('Storage root must not be empty.');
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
            throw new RuntimeException('Swallowtail storage root must be outside web_root.');
        }
    }

    private function availableBytes(string $rootPath): ?int
    {
        $probePath = is_dir($rootPath) ? $rootPath : dirname($rootPath);
        $bytes = @disk_free_space($probePath);

        return is_float($bytes) ? (int)$bytes : null;
    }
}
