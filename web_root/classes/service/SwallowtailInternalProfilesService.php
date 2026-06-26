<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailInternalProfilesService
{
    private const TABLE = 'internal_profile_data';
    public const IMAGE_TYPES = ['thumbnail', 'original', 'preview', 'final'];
    public const VALUE_TYPES = ['null', 'bool', 'int', 'float', 'string'];

    public function tableAvailable(): bool
    {
        return InterfaceDB::tableExists(self::TABLE);
    }

    public function imageTypes(): array
    {
        return self::IMAGE_TYPES;
    }

    public function valueTypes(): array
    {
        return self::VALUE_TYPES;
    }

    public function normaliseImageType(string $imageType): string
    {
        $imageType = strtolower(trim($imageType));

        return in_array($imageType, self::IMAGE_TYPES, true) ? $imageType : self::IMAGE_TYPES[0];
    }

    public function normaliseProfileName(string $profileName, string $fallback = 'default'): string
    {
        $profileName = trim($profileName);
        $profileName = preg_replace('/\s+/', '_', $profileName) ?? '';
        $profileName = preg_replace('/[^A-Za-z0-9._-]+/', '', $profileName) ?? '';
        $profileName = trim($profileName, '.-_');

        return substr($profileName !== '' ? $profileName : $fallback, 0, 64);
    }

    public function profileNames(string $imageType): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        $imageType = $this->normaliseImageType($imageType);
        $rows = InterfaceDB::fetchAll(
            "SELECT profile_name
             FROM internal_profile_data
             WHERE image_type = :image_type
             GROUP BY profile_name
             ORDER BY MIN(`order`), profile_name",
            ['image_type' => $imageType]
        );

        return array_values(array_map(static fn(array $row): string => (string)($row['profile_name'] ?? ''), $rows));
    }

    public function rows(string $imageType, string $profileName): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        $imageType = $this->normaliseImageType($imageType);
        $profileName = $this->normaliseProfileName($profileName);

        return InterfaceDB::fetchAll(
            "SELECT id, image_type, profile_name, `order`, enabled, type, `key`, value, value_type
             FROM internal_profile_data
             WHERE image_type = :image_type
               AND profile_name = :profile_name
             ORDER BY `order`, type, `key`, id",
            [
                'image_type' => $imageType,
                'profile_name' => $profileName,
            ]
        );
    }

    public function saveRow(int $id, string $imageType, string $profileName, string $type, string $key, mixed $value, string $valueType): array
    {
        if (!$this->tableAvailable()) {
            throw new RuntimeException('Internal profile table is not available.');
        }

        $imageType = $this->normaliseImageType($imageType);
        $profileName = $this->normaliseProfileName($profileName);
        $type = substr(trim($type), 0, 64);
        $key = substr(trim($key), 0, 191);
        $valueType = $this->normaliseValueType($valueType);
        $storedValue = $valueType === 'null' ? null : (string)$value;

        if ($type === '' || $key === '') {
            throw new InvalidArgumentException('Type and key are required.');
        }

        if ($id > 0) {
            InterfaceDB::prepareExecute(
                "UPDATE internal_profile_data
                 SET type = :type,
                     `key` = :key,
                     value = :value,
                     value_type = :value_type,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                [
                    'id' => $id,
                    'type' => $type,
                    'key' => $key,
                    'value' => $storedValue,
                    'value_type' => $valueType,
                ]
            );
        } else {
            $order = $this->nextOrder($imageType);
            InterfaceDB::prepareExecute(
                "INSERT INTO internal_profile_data (
                    image_type, profile_name, `order`, enabled, type, `key`, value, value_type
                ) VALUES (
                    :image_type, :profile_name, :order_value, 1, :type, :key, :value, :value_type
                )",
                [
                    'image_type' => $imageType,
                    'profile_name' => $profileName,
                    'order_value' => $order,
                    'type' => $type,
                    'key' => $key,
                    'value' => $storedValue,
                    'value_type' => $valueType,
                ]
            );
        }

        return [
            'image_type' => $imageType,
            'profile_name' => $profileName,
        ];
    }

    public function updateValueType(int $id, string $valueType): ?array
    {
        if ($id <= 0 || !$this->tableAvailable()) {
            return null;
        }

        $row = InterfaceDB::fetchOne('SELECT image_type, profile_name FROM internal_profile_data WHERE id = :id LIMIT 1', ['id' => $id]);
        if (!is_array($row)) {
            return null;
        }

        InterfaceDB::prepareExecute(
            "UPDATE internal_profile_data
             SET value_type = :value_type,
                 value = CASE WHEN :value_type_for_null = 'null' THEN NULL ELSE value END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id",
            [
                'id' => $id,
                'value_type' => $this->normaliseValueType($valueType),
                'value_type_for_null' => $this->normaliseValueType($valueType),
            ]
        );

        return [
            'image_type' => $this->normaliseImageType((string)($row['image_type'] ?? '')),
            'profile_name' => $this->normaliseProfileName((string)($row['profile_name'] ?? '')),
        ];
    }

    public function moveProfile(int $id, string $direction): ?array
    {
        if ($id <= 0 || !$this->tableAvailable()) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            "SELECT image_type, profile_name, `order`
             FROM internal_profile_data
             WHERE id = :id
             LIMIT 1",
            ['id' => $id]
        );
        if (!is_array($row)) {
            return null;
        }

        $imageType = $this->normaliseImageType((string)($row['image_type'] ?? ''));
        $profileName = $this->normaliseProfileName((string)($row['profile_name'] ?? ''));
        $profiles = $this->orderedProfiles($imageType);
        $index = array_search($profileName, array_column($profiles, 'profile_name'), true);
        if ($index === false) {
            return ['image_type' => $imageType, 'profile_name' => $profileName];
        }

        $swapIndex = strtolower(trim($direction)) === 'up' ? (int)$index - 1 : (int)$index + 1;
        if (!array_key_exists($swapIndex, $profiles)) {
            return ['image_type' => $imageType, 'profile_name' => $profileName];
        }

        $left = $profiles[(int)$index];
        $right = $profiles[$swapIndex];
        InterfaceDB::transaction(function () use ($imageType, $left, $right): void {
            InterfaceDB::prepareExecute(
                "UPDATE internal_profile_data SET `order` = :order_value WHERE image_type = :image_type AND profile_name = :profile_name",
                [
                    'image_type' => $imageType,
                    'profile_name' => (string)$left['profile_name'],
                    'order_value' => (int)$right['order'],
                ]
            );
            InterfaceDB::prepareExecute(
                "UPDATE internal_profile_data SET `order` = :order_value WHERE image_type = :image_type AND profile_name = :profile_name",
                [
                    'image_type' => $imageType,
                    'profile_name' => (string)$right['profile_name'],
                    'order_value' => (int)$left['order'],
                ]
            );
        });

        return ['image_type' => $imageType, 'profile_name' => $profileName];
    }

    private function orderedProfiles(string $imageType): array
    {
        return InterfaceDB::fetchAll(
            "SELECT profile_name, MIN(`order`) AS `order`
             FROM internal_profile_data
             WHERE image_type = :image_type
             GROUP BY profile_name
             ORDER BY MIN(`order`), profile_name",
            ['image_type' => $imageType]
        );
    }

    private function nextOrder(string $imageType): int
    {
        return ((int)InterfaceDB::fetchColumn(
            'SELECT COALESCE(MAX(`order`), 0) + 1 FROM internal_profile_data WHERE image_type = :image_type',
            ['image_type' => $imageType]
        ));
    }

    private function normaliseValueType(string $valueType): string
    {
        return in_array($valueType, self::VALUE_TYPES, true) ? $valueType : 'string';
    }
}
