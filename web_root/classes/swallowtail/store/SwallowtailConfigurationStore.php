<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Store;

use AppConfigurationStore;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class SwallowtailConfigurationStore
{
    public static function defaults(): array
    {
        return [
            'timezone' => [
                'server' => 'Europe/London',
                'daylight_saving' => [
                    'enabled' => false,
                    'start' => '03-31',
                    'end' => '10-31',
                    'offset_minutes' => 60,
                ],
            ],
            'storage' => [
                'store_on_root_partition' => false,
                'round_robin_locations' => false,
                'full_threshold_percent' => 5,
                'storage_blocked_poll_interval_seconds' => 3600,
            ],
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'urgent_queue' => 'swallowtail:conversion:urgent',
                'normal_queue' => 'swallowtail:conversion:normal',
                'preempt_queue' => 'swallowtail:conversion:preempt',
                'storage_wake_queue' => 'swallowtail:conversion:storage_wake',
                'metadata_profile_queue' => 'swallowtail:metadata:profile_urgent',
                'metadata_asset_queue' => 'swallowtail:metadata:asset_urgent',
                'metadata_data_integrity_queue' => 'swallowtail:metadata:data_integrity',
                'rawtherapee_profile_refresh_queue' => 'swallowtail:metadata:rawtherapee_profiles',
            ],
            'rawtherapee' => [
                'profile_root' => '/usr/local/share/rawtherapee/profiles',
            ],
            'trace' => [
                'raw_upload_timing' => false,
            ],
        ];
    }

    public static function config(bool $reload = false): array
    {
        $configured = AppConfigurationStore::get('swallowtail', [], $reload);

        return array_replace_recursive(self::defaults(), is_array($configured) ? $configured : []);
    }

    public static function get(string $path, mixed $default = null, bool $reload = false): mixed
    {
        $config = self::config($reload);
        $segments = array_values(array_filter(explode('.', trim($path)), static fn(string $part): bool => $part !== ''));

        if ($segments === []) {
            return $default;
        }

        $value = $config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $path, mixed $value): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Swallowtail configuration path is required.');
        }

        AppConfigurationStore::set('swallowtail.' . $path, $value);

        return self::config(true);
    }

    public static function setTimezoneSettings(array $settings): array
    {
        $timezone = trim((string)($settings['server'] ?? ''));
        if ($timezone === '' || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Choose a valid server timezone.');
        }

        $current = self::get('timezone', []);
        $current = is_array($current) ? $current : [];
        $timezoneConfig = array_replace($current, [
            'server' => $timezone,
            'daylight_saving' => self::normaliseDaylightSavingSettings($settings['daylight_saving'] ?? []),
        ]);

        self::set('timezone', $timezoneConfig);

        return AppConfigurationStore::config(true);
    }

    private static function normaliseDaylightSavingSettings(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $enabled = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $start = self::normaliseMonthDayDate((string)($settings['start'] ?? ''));
        $end = self::normaliseMonthDayDate((string)($settings['end'] ?? ''));
        $offset = (int)($settings['offset_minutes'] ?? 60);
        if (!in_array($offset, [60, 30, 0, -30, -60], true)) {
            throw new RuntimeException('Choose a valid daylight saving offset.');
        }

        return [
            'enabled' => $enabled,
            'start' => $start,
            'end' => $end,
            'offset_minutes' => $offset,
        ];
    }

    private static function normaliseMonthDayDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            throw new RuntimeException('Choose valid daylight saving start and end dates.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('Choose valid daylight saving start and end dates.');
        }

        return $parsed->format('m-d');
    }
}
