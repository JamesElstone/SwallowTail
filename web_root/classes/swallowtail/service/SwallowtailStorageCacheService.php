<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

final class SwallowtailStorageCacheService
{
    public const SNAPSHOT_KEY = 'swallowtail:storage:snapshot';
    public const TTL_SECONDS = 360;

    public function __construct(
        private readonly object $redis = new SwallowtailRedisService(),
    ) {
    }

    public function snapshot(bool $allowStale = false): ?array
    {
        $json = $this->redis->get(self::SNAPSHOT_KEY);
        if ($json === null || trim($json) === '') {
            return null;
        }

        $snapshot = json_decode($json, true);
        if (!is_array($snapshot)) {
            return null;
        }
        if (!isset($snapshot['locations']) || !is_array($snapshot['locations'])) {
            return null;
        }

        if (!$allowStale && $this->isStale($snapshot)) {
            return null;
        }

        return $snapshot;
    }

    public function store(array $snapshot): bool
    {
        $snapshot['cached_at'] = time();
        $snapshot['ttl_seconds'] = self::TTL_SECONDS;

        return $this->redis->setJson(self::SNAPSHOT_KEY, $snapshot, self::TTL_SECONDS);
    }

    public function clear(): void
    {
        $this->redis->delete(self::SNAPSHOT_KEY);
    }

    public function status(): array
    {
        $snapshot = $this->snapshot(true);
        $cachedAt = is_array($snapshot) ? (int)($snapshot['cached_at'] ?? $snapshot['generated_at'] ?? 0) : 0;
        $age = $cachedAt > 0 ? max(0, time() - $cachedAt) : null;

        return [
            'redis_available' => $this->redis->ping(),
            'has_snapshot' => is_array($snapshot),
            'is_stale' => !is_array($snapshot) || $this->isStale($snapshot),
            'age_seconds' => $age,
            'ttl_seconds' => self::TTL_SECONDS,
            'snapshot' => $snapshot,
        ];
    }

    private function isStale(array $snapshot): bool
    {
        $cachedAt = (int)($snapshot['cached_at'] ?? $snapshot['generated_at'] ?? 0);

        return $cachedAt <= 0 || (time() - $cachedAt) > self::TTL_SECONDS;
    }
}
