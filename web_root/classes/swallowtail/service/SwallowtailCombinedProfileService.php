<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

use InterfaceDB;
use InvalidArgumentException;
use Throwable;

final class SwallowtailCombinedProfileService
{
    private const INTERNAL_TABLE = 'internal_profile_data';

    public function __construct(
        private readonly SwallowtailProfileDataService $profileDataService = new SwallowtailProfileDataService(),
    ) {
    }

    public function combinedProfileContent(int $photoId, string $imageType): string
    {
        $this->requestProfileDataIfMissing($photoId, $imageType);

        return $this->applyInternalProfiles($imageType, $this->photoProfileContent($photoId));
    }

    public function profileSignature(int $photoId, string $imageType): string
    {
        if ($photoId <= 0 || !$this->profileDataService->tableAvailable()) {
            return '';
        }

        $this->requestProfileDataIfMissing($photoId, $imageType);
        $status = $this->profileDataService->status($photoId);
        if (empty($status['ready'])) {
            return '';
        }

        $imageType = $this->normaliseImageType($imageType);
        if (InterfaceDB::driverName() !== 'sqlite') {
            $signature = $this->sqlProfileSignature($photoId, $imageType);
            if ($signature !== '') {
                return $signature;
            }
        }

        return hash('sha256', implode("\n", array_column($this->profileSignatureRows($photoId, $imageType), 'part')));
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
               AND enabled = 1
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

    private function requestProfileDataIfMissing(int $photoId, string $imageType): void
    {
        if ($photoId <= 0 || !$this->profileDataService->tableAvailable()) {
            return;
        }

        $status = $this->profileDataService->status($photoId);
        if (!empty($status['ready'])) {
            return;
        }

        $this->profileDataService->requestUrgentProfile(
            ['id' => $photoId],
            'combined_profile_' . $this->normaliseImageType($imageType)
        );
    }

    private function sqlProfileSignature(int $photoId, string $imageType): string
    {
        if (
            !InterfaceDB::tableExists(self::INTERNAL_TABLE)
            || !InterfaceDB::columnExists(self::INTERNAL_TABLE, 'enabled')
        ) {
            return '';
        }

        try {
            try {
                InterfaceDB::execute('SET SESSION group_concat_max_len = 1048576');
            } catch (Throwable) {
            }

            $signature = InterfaceDB::fetchColumn(
                "SELECT SHA2(COALESCE(GROUP_CONCAT(signature_part ORDER BY signature_order SEPARATOR '\n'), ''), 256)
                 FROM (
                     SELECT
                         CONCAT(
                             'photo', CHAR(9), profile.type, CHAR(9), profile.`key`, CHAR(9),
                             profile.revision, CHAR(9), profile.value_type, CHAR(9), COALESCE(profile.value, '')
                         ) AS signature_part,
                         CONCAT(
                             'photo', CHAR(9), profile.type, CHAR(9), profile.`key`, CHAR(9),
                             LPAD(profile.revision, 10, '0'), CHAR(9), profile.id
                         ) AS signature_order
                     FROM photo_profile_data profile
                     INNER JOIN (
                         SELECT type, `key`, MAX(revision) AS revision
                         FROM photo_profile_data
                         WHERE photo_id = :photo_id_latest
                           AND type <> 'swallowtail'
                         GROUP BY type, `key`
                     ) latest
                       ON latest.type = profile.type
                      AND latest.`key` = profile.`key`
                      AND latest.revision = profile.revision
                     WHERE profile.photo_id = :photo_id_profile
                       AND profile.type <> 'swallowtail'
                     UNION ALL
                     SELECT
                         CONCAT(
                             'internal', CHAR(9), image_type, CHAR(9), profile_name, CHAR(9), `order`, CHAR(9),
                             type, CHAR(9), `key`, CHAR(9), value_type, CHAR(9), COALESCE(value, '')
                         ) AS signature_part,
                         CONCAT(
                             'internal', CHAR(9), LPAD(`order`, 10, '0'), CHAR(9), profile_name, CHAR(9),
                             type, CHAR(9), `key`, CHAR(9), id
                         ) AS signature_order
                     FROM internal_profile_data
                     WHERE image_type = :image_type
                       AND enabled = 1
                 ) signature_rows",
                [
                    'photo_id_latest' => $photoId,
                    'photo_id_profile' => $photoId,
                    'image_type' => $imageType,
                ]
            );
        } catch (Throwable) {
            return '';
        }

        $signature = strtolower(trim((string)$signature));

        return preg_match('/^[a-f0-9]{64}$/', $signature) === 1 ? $signature : '';
    }

    /**
     * @return list<array{sort: string, part: string}>
     */
    private function profileSignatureRows(int $photoId, string $imageType): array
    {
        $rows = [];
        foreach ($this->profileDataService->rows($photoId) as $row) {
            $type = (string)($row['type'] ?? '');
            $key = (string)($row['key'] ?? '');
            $revision = (int)($row['revision'] ?? 0);
            $valueType = (string)($row['value_type'] ?? '');
            $value = $row['value'] ?? null;
            $rows[] = [
                'sort' => 'photo' . "\t" . $type . "\t" . $key . "\t" . str_pad((string)$revision, 10, '0', STR_PAD_LEFT),
                'part' => 'photo' . "\t" . $type . "\t" . $key . "\t" . (string)$revision . "\t" . $valueType . "\t" . ($value === null ? '' : (string)$value),
            ];
        }

        if ($imageType !== 'embedded' && InterfaceDB::tableExists(self::INTERNAL_TABLE)) {
            $enabledFilter = InterfaceDB::columnExists(self::INTERNAL_TABLE, 'enabled') ? ' AND enabled = 1' : '';
            $internalRows = InterfaceDB::fetchAll(
                "SELECT id, image_type, profile_name, `order`, type, `key`, value, value_type
                 FROM internal_profile_data
                 WHERE image_type = :image_type" . $enabledFilter . "
                 ORDER BY `order`, profile_name, type, `key`, id",
                ['image_type' => $imageType]
            );

            foreach ($internalRows as $row) {
                $order = (int)($row['order'] ?? 0);
                $profileName = (string)($row['profile_name'] ?? '');
                $type = (string)($row['type'] ?? '');
                $key = (string)($row['key'] ?? '');
                $id = (int)($row['id'] ?? 0);
                $valueType = (string)($row['value_type'] ?? '');
                $value = $row['value'] ?? null;
                $rows[] = [
                    'sort' => 'internal' . "\t" . str_pad((string)$order, 10, '0', STR_PAD_LEFT) . "\t" . $profileName . "\t" . $type . "\t" . $key . "\t" . (string)$id,
                    'part' => 'internal' . "\t" . (string)$imageType . "\t" . $profileName . "\t" . (string)$order . "\t" . $type . "\t" . $key . "\t" . $valueType . "\t" . ($value === null ? '' : (string)$value),
                ];
            }
        }

        usort($rows, static fn(array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return $rows;
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
