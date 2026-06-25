<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailCombinedProfileService
{
    private const INTERNAL_TABLE = 'internal_profile_data';

    public function __construct(
        private readonly SwallowtailProfileDataService $profileDataService = new SwallowtailProfileDataService(),
    ) {
    }

    public function combinedProfileContent(int $photoId, string $imageType): string
    {
        return $this->applyInternalProfiles($imageType, $this->photoProfileContent($photoId));
    }

    public function photoProfileContent(int $photoId): string
    {
        if ($photoId <= 0) {
            return '';
        }

        return $this->renderRows($this->profileDataService->rows($photoId));
    }

    public function applyInternalProfiles(string $imageType, string $baseProfileContent): string
    {
        $imageType = $this->normaliseImageType($imageType);
        if ($imageType === 'embedded' || !InterfaceDB::tableExists(self::INTERNAL_TABLE)) {
            return $baseProfileContent;
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT type, `key`, value, value_type
             FROM internal_profile_data
             WHERE image_type = :image_type
             ORDER BY `order`, profile_name, type, `key`",
            ['image_type' => $imageType]
        );

        if ($rows === []) {
            return $baseProfileContent;
        }

        $profile = $this->parsePp3Document($baseProfileContent);
        foreach ($rows as $row) {
            $section = trim((string)($row['type'] ?? ''));
            $key = trim((string)($row['key'] ?? ''));
            if ($section === '' || $key === '') {
                continue;
            }

            $value = $row['value'] ?? null;
            $this->setPp3Value($profile, $section, $key, $value === null ? '' : (string)$value);
        }

        return $this->renderPp3Document($profile);
    }

    private function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));
        if ($imageType === '') {
            throw new InvalidArgumentException('Image type must not be empty.');
        }

        return substr($imageType, 0, 32);
    }

    private function renderRows(array $rows): string
    {
        $profile = [
            'order' => [],
            'sections' => [],
        ];

        foreach ($rows as $row) {
            $section = trim((string)($row['type'] ?? ''));
            $key = trim((string)($row['key'] ?? ''));
            if ($section === '' || $key === '') {
                continue;
            }

            $value = $row['value'] ?? null;
            $this->setPp3Value($profile, $section, $key, $value === null ? '' : (string)$value);
        }

        return $this->renderPp3Document($profile);
    }

    private function parsePp3Document(string $contents): array
    {
        $profile = [
            'order' => [],
            'sections' => [],
        ];
        $section = '';
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]$/', $line, $match) === 1) {
                $section = (string)$match[1];
                $this->ensurePp3Section($profile, $section);
                continue;
            }
            if ($section === '' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $this->setPp3Value($profile, $section, trim($key), trim($value));
        }

        return $profile;
    }

    private function ensurePp3Section(array &$profile, string $section): void
    {
        if (isset($profile['sections'][$section])) {
            return;
        }

        $profile['order'][] = $section;
        $profile['sections'][$section] = [
            'order' => [],
            'values' => [],
        ];
    }

    private function setPp3Value(array &$profile, string $section, string $key, string $value): void
    {
        $this->ensurePp3Section($profile, $section);
        if (!array_key_exists($key, $profile['sections'][$section]['values'])) {
            $profile['sections'][$section]['order'][] = $key;
        }
        $profile['sections'][$section]['values'][$key] = $value;
    }

    private function renderPp3Document(array $profile): string
    {
        $lines = [];
        foreach ((array)$profile['order'] as $section) {
            $section = (string)$section;
            $data = (array)($profile['sections'][$section] ?? []);
            $lines[] = '[' . $section . ']';
            foreach ((array)($data['order'] ?? []) as $key) {
                $key = (string)$key;
                $lines[] = $key . '=' . (string)($data['values'][$key] ?? '');
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
