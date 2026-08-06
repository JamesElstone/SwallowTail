<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

use AppConfigurationStore;
use InterfaceDB;
use Throwable;

final class SwallowtailProfileDataService
{
    private const TABLE = 'photo_profile_data';
    private const STATUS_TYPE = 'swallowtail';
    private const DEFAULT_PROFILE_QUEUE = 'swallowtail:metadata:profile_urgent';
    private const PROFILE_SECTION_MAX_LENGTH = 64;
    private const PROFILE_KEY_MAX_LENGTH = 191;
    private const PROFILE_REVISION_INSERT_ATTEMPTS = 3;

    private object $redis;

    public function __construct(?object $redis = null)
    {
        $this->redis = $redis ?? new SwallowtailRedisService();
    }

    public function tableAvailable(): bool
    {
        return InterfaceDB::tableExists(self::TABLE);
    }

    public function ensureQueued(array $photo, bool $viewed = false): array
    {
        $photoId = max(0, (int)($photo['id'] ?? 0));
        if ($photoId <= 0 || !$this->tableAvailable()) {
            return $this->fallbackStatus();
        }

        $status = $this->status($photoId);
        if (!in_array((string)$status['status'], ['processed', 'processing'], true)) {
            $this->setValue($photoId, self::STATUS_TYPE, 'status', 'queued', 'string');
        }

        if ($viewed) {
            $this->setValue($photoId, self::STATUS_TYPE, 'viewed_at', (string)time(), 'int');
        }

        return $this->status($photoId);
    }

    public function requestUrgentProfile(array $photo, string $reason): array
    {
        $status = $this->ensureQueued($photo, true);
        if (empty($status['ready'])) {
            $this->notifyUrgentProfile(max(0, (int)($photo['id'] ?? 0)), $reason);
        }

        return $status;
    }

    public function notifyUrgentProfile(int $photoId, string $reason): bool
    {
        if ($photoId <= 0 || !method_exists($this->redis, 'listPushJson')) {
            return false;
        }

        $queue = trim((string)\Swallowtail\Store\SwallowtailConfigurationStore::get(
            'redis.metadata_profile_queue',
            self::DEFAULT_PROFILE_QUEUE
        ));
        if ($queue === '') {
            return false;
        }

        return (bool)$this->redis->listPushJson($queue, [
            'photo_id' => $photoId,
            'reason' => substr($reason, 0, 64),
            'queued_at' => time(),
        ], 512);
    }

    public function status(int $photoId): array
    {
        if ($photoId <= 0 || !$this->tableAvailable()) {
            return $this->fallbackStatus();
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT `key`, value, value_type
             FROM photo_profile_data
             WHERE photo_id = :photo_id
               AND type = :type
               AND revision = 0",
            ['photo_id' => $photoId, 'type' => self::STATUS_TYPE]
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string)($row['key'] ?? '')] = $this->typedValue($row['value'] ?? null, (string)($row['value_type'] ?? 'string'));
        }

        $status = (string)($values['status'] ?? 'missing');

        return [
            'success' => true,
            'status' => $status,
            'ready' => $status === 'processed',
            'error' => (string)($values['last_error'] ?? ''),
            'source_profile_path' => (string)($values['source_profile_path'] ?? ''),
            'rawtherapee_version' => (string)($values['rawtherapee_version'] ?? ''),
            'rawtherapee_profile_id' => (int)($values['rawtherapee_profile_id'] ?? 0),
            'rawtherapee_profile_path' => (string)($values['rawtherapee_profile_path'] ?? ''),
            'rawtherapee_profile_hash' => (string)($values['rawtherapee_profile_hash'] ?? ''),
            'viewed_at' => (int)($values['viewed_at'] ?? 0),
        ];
    }

    public function markBaselineQueued(int $photoId, ?array $profile): void
    {
        if ($photoId <= 0 || !$this->tableAvailable()) {
            return;
        }

        InterfaceDB::transaction(function () use ($photoId, $profile): void {
            $this->setValue($photoId, self::STATUS_TYPE, 'status', 'queued', 'string');
            $this->setValue($photoId, self::STATUS_TYPE, 'last_error', '', 'string');
            $this->setValue($photoId, self::STATUS_TYPE, 'rawtherapee_profile_id', max(0, (int)($profile['id'] ?? 0)), 'int');
            $this->setValue($photoId, self::STATUS_TYPE, 'rawtherapee_profile_path', (string)($profile['profile_path'] ?? ''), 'string');
            $this->setValue($photoId, self::STATUS_TYPE, 'rawtherapee_profile_hash', (string)($profile['profile_hash'] ?? ''), 'string');
        });
    }

    public function rows(int $photoId): array
    {
        if ($photoId <= 0 || !$this->tableAvailable()) {
            return [];
        }

        return InterfaceDB::fetchAll(
            "SELECT profile.type, profile.`key`, profile.value, profile.value_type, profile.revision
             FROM photo_profile_data profile
             INNER JOIN (
                 SELECT type, `key`, MAX(revision) AS revision
                 FROM photo_profile_data
                 WHERE photo_id = :photo_id
                   AND type <> :status_type
                 GROUP BY type, `key`
             ) latest
               ON latest.type = profile.type
              AND latest.`key` = profile.`key`
              AND latest.revision = profile.revision
             WHERE profile.photo_id = :photo_id
               AND profile.type <> :status_type
             ORDER BY profile.type, profile.`key`",
            ['photo_id' => $photoId, 'status_type' => self::STATUS_TYPE]
        );
    }

    public function rowsByIdentity(int $photoId): array
    {
        $rows = [];
        foreach ($this->rows($photoId) as $row) {
            $section = (string)($row['type'] ?? '');
            $key = (string)($row['key'] ?? '');
            if ($section === '' || $key === '') {
                continue;
            }
            $rows[$this->identityKey($section, $key)] = $row;
        }

        return $rows;
    }

    public function recordChangedRows(int $photoId, array $rows): int
    {
        if ($photoId <= 0 || !$this->tableAvailable() || $rows === []) {
            return 0;
        }

        $current = $this->rowsByIdentity($photoId);
        $changed = [];
        foreach ($rows as $row) {
            $section = trim((string)($row['type'] ?? ''));
            $key = trim((string)($row['key'] ?? ''));
            if ($section === '' || $key === '') {
                continue;
            }

            $valueType = $this->normaliseValueType((string)($row['value_type'] ?? 'string'));
            $value = $row['value'] ?? null;
            $identity = $this->identityKey($section, $key);
            if (!$this->rowValueDiffers($current[$identity] ?? null, $value, $valueType)) {
                continue;
            }

            $changed[] = [
                'type' => substr($section, 0, self::PROFILE_SECTION_MAX_LENGTH),
                'key' => substr($key, 0, self::PROFILE_KEY_MAX_LENGTH),
                'value' => $value === null ? null : (string)$value,
                'value_type' => $valueType,
            ];
        }

        if ($changed === []) {
            return 0;
        }

        return (int)InterfaceDB::transaction(function () use ($photoId, $changed): int {
            $inserted = 0;
            foreach ($changed as $row) {
                for ($attempt = 1; $attempt <= self::PROFILE_REVISION_INSERT_ATTEMPTS; $attempt++) {
                    $revision = $this->nextRevision($photoId, $row['type'], $row['key']);
                    try {
                        InterfaceDB::prepareExecute(
                            "INSERT INTO photo_profile_data (
                                photo_id, revision, type, `key`, value, value_type
                            ) VALUES (
                                :photo_id, :revision, :type, :key, :value, :value_type
                            )",
                            [
                                'photo_id' => $photoId,
                                'revision' => $revision,
                                'type' => $row['type'],
                                'key' => $row['key'],
                                'value' => $row['value'],
                                'value_type' => $row['value_type'],
                            ]
                        );
                        break;
                    } catch (Throwable $exception) {
                        if ($attempt >= self::PROFILE_REVISION_INSERT_ATTEMPTS
                            || !$this->revisionExists($photoId, $row['type'], $row['key'], $revision)) {
                            throw $exception;
                        }
                    }
                }
                $inserted++;
            }

            return $inserted;
        });
    }

    public function settingsFromRows(int $photoId, int $width, int $height, array $fallback): array
    {
        $settings = $fallback;
        foreach ($this->rows($photoId) as $row) {
            $type = (string)($row['type'] ?? '');
            $key = (string)($row['key'] ?? '');
            $value = $this->typedValue($row['value'] ?? null, (string)($row['value_type'] ?? 'string'));

            if ($type === 'Exposure') {
                if ($key === 'Black') {
                    $settings['exposure']['black'] = (float)$value;
                } elseif ($key === 'Brightness') {
                    $settings['exposure']['lightness'] = (float)$value;
                } elseif ($key === 'Contrast') {
                    $settings['exposure']['contrast'] = (float)$value;
                } elseif ($key === 'Saturation') {
                    $settings['exposure']['saturation'] = (float)$value;
                }
            } elseif ($type === 'Crop') {
                if ($key === 'Enabled') {
                    $settings['crop']['enabled'] = (bool)$value;
                } elseif ($key === 'X') {
                    $settings['crop']['x'] = max(0, (int)$value);
                } elseif ($key === 'Y') {
                    $settings['crop']['y'] = max(0, (int)$value);
                } elseif ($key === 'W') {
                    $settings['crop']['width'] = max(1, (int)$value);
                } elseif ($key === 'H') {
                    $settings['crop']['height'] = max(1, (int)$value);
                }
            } elseif ($type === 'White Balance') {
                if ($key === 'Enabled') {
                    $settings['white_balance']['enabled'] = (bool)$value;
                } elseif ($key === 'Setting') {
                    $settings['white_balance']['setting'] = (string)$value;
                } elseif ($key === 'Temperature') {
                    $settings['white_balance']['temperature'] = (float)$value;
                } elseif ($key === 'Green') {
                    $settings['white_balance']['green'] = (float)$value;
                }
            } elseif ($type === 'Shadows & Highlights') {
                if ($key === 'Enabled') {
                    $settings['shadows_highlights']['enabled'] = (bool)$value;
                } elseif ($key === 'Highlights') {
                    $settings['shadows_highlights']['highlights'] = (float)$value;
                } elseif ($key === 'HighlightTonalWidth') {
                    $settings['shadows_highlights']['highlight_tonal_width'] = (float)$value;
                } elseif ($key === 'Shadows') {
                    $settings['shadows_highlights']['shadows'] = (float)$value;
                } elseif ($key === 'ShadowTonalWidth') {
                    $settings['shadows_highlights']['shadow_tonal_width'] = (float)$value;
                } elseif ($key === 'Radius') {
                    $settings['shadows_highlights']['radius'] = (float)$value;
                } elseif ($key === 'Lab') {
                    $settings['shadows_highlights']['lab'] = (bool)$value;
                }
            } elseif ($type === 'Local Contrast') {
                if ($key === 'Enabled') {
                    $settings['shadows_highlights']['local_contrast_enabled'] = (bool)$value;
                } elseif ($key === 'Amount') {
                    $settings['shadows_highlights']['local_contrast'] = (float)$value * 30.0;
                }
            } elseif ($type === 'Rotation' && $key === 'Degree') {
                $settings['rotation']['degree'] = (float)$value;
                $settings['rotation']['enabled'] = abs((float)$value) > 0.000001;
            } elseif ($type === 'Perspective') {
                if ($key === 'Method') {
                    $settings['perspective']['method'] = (string)$value;
                } elseif ($key === 'Horizontal') {
                    $settings['perspective']['horizontal'] = (float)$value;
                    $settings['perspective']['enabled'] = $settings['perspective']['enabled'] || abs((float)$value) > 0.000001;
                } elseif ($key === 'Vertical') {
                    $settings['perspective']['vertical'] = (float)$value;
                    $settings['perspective']['enabled'] = $settings['perspective']['enabled'] || abs((float)$value) > 0.000001;
                }
            }
        }

        $settings['crop']['width'] = max(1, min((int)$settings['crop']['width'], max(1, $width - (int)$settings['crop']['x'])));
        $settings['crop']['height'] = max(1, min((int)$settings['crop']['height'], max(1, $height - (int)$settings['crop']['y'])));

        return $settings;
    }

    public function setValue(int $photoId, string $type, string $key, mixed $value, string $valueType): void
    {
        $type = substr(trim($type), 0, self::PROFILE_SECTION_MAX_LENGTH);
        $key = substr(trim($key), 0, self::PROFILE_KEY_MAX_LENGTH);
        if ($photoId <= 0 || $type === '' || $key === '') {
            return;
        }
        $valueType = $this->normaliseValueType($valueType);
        $storedValue = $value === null ? null : (string)$value;
        $params = [
            'photo_id' => $photoId,
            'type' => $type,
            'key' => $key,
            'value' => $storedValue,
            'value_type' => $valueType,
        ];
        InterfaceDB::prepareExecute(
            "UPDATE photo_profile_data
             SET value = :value,
                 value_type = :value_type,
                 updated_at = CURRENT_TIMESTAMP
             WHERE photo_id = :photo_id
               AND type = :type
               AND `key` = :key
               AND revision = 0",
            $params
        );
        $existing = InterfaceDB::fetchColumn(
            "SELECT id FROM photo_profile_data WHERE photo_id = :photo_id AND type = :type AND `key` = :key AND revision = 0 LIMIT 1",
            $params
        );

        if ($existing !== false && $existing !== null) {
            return;
        }

        try {
            InterfaceDB::prepareExecute(
                "INSERT INTO photo_profile_data (
                    photo_id, revision, type, `key`, value, value_type
                ) VALUES (
                    :photo_id, 0, :type, :key, :value, :value_type
                )",
                $params
            );
        } catch (Throwable $exception) {
            $existing = InterfaceDB::fetchColumn(
                "SELECT id FROM photo_profile_data WHERE photo_id = :photo_id AND type = :type AND `key` = :key AND revision = 0 LIMIT 1",
                $params
            );
            if ($existing === false || $existing === null) {
                throw $exception;
            }
            InterfaceDB::prepareExecute(
                "UPDATE photo_profile_data
                 SET value = :value,
                     value_type = :value_type,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id",
                ['id' => (int)$existing, 'value' => $storedValue, 'value_type' => $valueType]
            );
        }
    }

    private function nextRevision(int $photoId, string $type, string $key): int
    {
        $lockingRead = InterfaceDB::isOdbcDriver() ? ' FOR UPDATE' : '';

        return (int)InterfaceDB::fetchColumn(
            "SELECT COALESCE(MAX(revision), 0) + 1
             FROM photo_profile_data
             WHERE photo_id = :photo_id
               AND type = :type
               AND `key` = :key" . $lockingRead,
            ['photo_id' => $photoId, 'type' => $type, 'key' => $key]
        );
    }

    private function revisionExists(int $photoId, string $type, string $key, int $revision): bool
    {
        return InterfaceDB::fetchColumn(
            "SELECT id
             FROM photo_profile_data
             WHERE photo_id = :photo_id
               AND type = :type
               AND `key` = :key
               AND revision = :revision
             LIMIT 1",
            ['photo_id' => $photoId, 'type' => $type, 'key' => $key, 'revision' => $revision]
        ) !== false;
    }

    private function identityKey(string $section, string $key): string
    {
        return $section . "\0" . $key;
    }

    private function rowValueDiffers(?array $current, mixed $value, string $valueType): bool
    {
        if ($current === null) {
            return true;
        }

        $currentType = $this->normaliseValueType((string)($current['value_type'] ?? 'string'));
        $currentValue = $current['value'] ?? null;
        if ($valueType === 'null') {
            return $currentValue !== null && (string)$currentValue !== '';
        }
        if ($currentType === 'null') {
            return $value !== null && (string)$value !== '';
        }
        if ($valueType === 'bool' || $currentType === 'bool') {
            return (bool)$this->typedValue($currentValue, 'bool') !== (bool)$this->typedValue($value, 'bool');
        }
        if ($valueType === 'float' || $currentType === 'float') {
            return abs((float)$currentValue - (float)$value) > 0.000001;
        }
        if ($valueType === 'int' || $currentType === 'int') {
            return (int)$currentValue !== (int)$value;
        }

        return (string)$currentValue !== (string)$value;
    }

    private function normaliseValueType(string $valueType): string
    {
        return in_array($valueType, ['null', 'bool', 'int', 'float', 'string'], true) ? $valueType : 'string';
    }

    private function typedValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'null' => null,
            'bool' => in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true),
            'int' => (int)$value,
            'float' => (float)$value,
            default => (string)$value,
        };
    }

    private function fallbackStatus(): array
    {
        return [
            'success' => true,
            'status' => 'processed',
            'ready' => true,
            'error' => '',
            'source_profile_path' => '',
            'rawtherapee_version' => '',
            'rawtherapee_profile_id' => 0,
            'rawtherapee_profile_path' => '',
            'rawtherapee_profile_hash' => '',
            'viewed_at' => 0,
        ];
    }
}
