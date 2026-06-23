<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailStorageWakeService
{
    public function __construct(
        private readonly object $redis = new SwallowtailRedisService(),
    ) {
    }

    public function notifyPermissionRepair(string $storageBaseLocation): bool
    {
        $queue = trim((string)AppConfigurationStore::get(
            'swallowtail.redis.storage_wake_queue',
            'swallowtail:conversion:storage_wake'
        ));
        if ($queue === '') {
            return false;
        }

        return $this->redis->listPushJson($queue, [
            'reason' => 'permission_repair',
            'storage_base_location' => $storageBaseLocation,
            'generated_at' => time(),
        ], 1);
    }
}
