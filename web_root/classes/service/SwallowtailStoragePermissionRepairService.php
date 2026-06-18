<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailStoragePermissionRepairService
{
    /** @var callable(array<int, string>): array{exit_code: int, output: string} */
    private $commandRunner;

    /** @var callable(): array<int, array<string, mixed>> */
    private $locationProvider;

    public function __construct(?callable $commandRunner = null, ?callable $locationProvider = null)
    {
        $this->commandRunner = $commandRunner ?? $this->defaultCommandRunner(...);
        $this->locationProvider = $locationProvider ?? static function (): array {
            $snapshot = (new SwallowtailStorageService())->storageSnapshot(true);

            return array_values(array_filter(
                (array)($snapshot['locations'] ?? []),
                static fn(mixed $location): bool => is_array($location)
            ));
        };
    }

    /**
     * @return array{base: string, output: string}
     */
    public function repair(string $storageBaseLocation): array
    {
        $storageBaseLocation = trim($storageBaseLocation);
        if ($storageBaseLocation === '') {
            throw new InvalidArgumentException('Storage location was not supplied.');
        }

        $knownBase = $this->knownStorageBase($storageBaseLocation);
        if ($knownBase === null) {
            throw new InvalidArgumentException('Storage location is not currently recognised by SwallowTail.');
        }

        $sudo = trim((string)AppConfigurationStore::get(
            'swallowtail.storage.fix_permissions_sudo',
            '/usr/local/bin/sudo'
        ));
        $helper = trim((string)AppConfigurationStore::get(
            'swallowtail.storage.fix_permissions_helper',
            '/usr/local/sbin/swallowtail-fix-storage-permissions'
        ));

        if ($sudo === '' || $helper === '') {
            throw new RuntimeException('Storage permission repair command is not configured.');
        }

        $result = ($this->commandRunner)([
            $sudo,
            '-n',
            $helper,
            '--base',
            $knownBase,
        ]);

        $exitCode = (int)($result['exit_code'] ?? 1);
        $output = trim((string)($result['output'] ?? ''));

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Storage permission repair failed.'
                . ($output !== '' ? ' ' . HelperFramework::compactText($output, 300) : '')
            );
        }

        (new SwallowtailStorageCacheService())->clear();

        return [
            'base' => $knownBase,
            'output' => $output,
        ];
    }

    private function knownStorageBase(string $storageBaseLocation): ?string
    {
        $requested = $this->normaliseForComparison($storageBaseLocation);
        if ($requested === '') {
            return null;
        }

        foreach (($this->locationProvider)() as $location) {
            $candidate = trim((string)($location['storage_base_location'] ?? ''));
            if ($candidate === '') {
                continue;
            }

            if ($this->normaliseForComparison($candidate) === $requested) {
                return $candidate;
            }
        }

        return null;
    }

    private function normaliseForComparison(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/');
    }

    /**
     * @param array<int, string> $argv
     * @return array{exit_code: int, output: string}
     */
    private function defaultCommandRunner(array $argv): array
    {
        $command = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
        $lines = [];
        $exitCode = 1;
        exec($command, $lines, $exitCode);

        return [
            'exit_code' => (int)$exitCode,
            'output' => trim(implode("\n", $lines)),
        ];
    }
}
