<?php
declare(strict_types=1);

namespace Swallowtail\Service;

use InterfaceDB;

final class SwallowtailPhotoOperationLeaseService
{
    public function acquire(int $photoId, string $operationType, string $ownerToken, int $ttlSeconds = 900): bool
    {
        if ($photoId <= 0 || !in_array($operationType, ['conversion', 'migration'], true) || trim($ownerToken) === '') {
            return false;
        }

        return InterfaceDB::transaction(function () use ($photoId, $operationType, $ownerToken, $ttlSeconds): bool {
            InterfaceDB::prepareExecute(
                'DELETE FROM photo_operation_leases WHERE photo_id = :photo_id AND expires_at <= CURRENT_TIMESTAMP',
                ['photo_id' => $photoId]
            );
            try {
                InterfaceDB::prepareExecute(
                    "INSERT INTO photo_operation_leases (photo_id, operation_type, owner_token, expires_at)
                     VALUES (:photo_id, :operation_type, :owner_token, :expires_at)",
                    [
                        'photo_id' => $photoId,
                        'operation_type' => $operationType,
                        'owner_token' => $ownerToken,
                        'expires_at' => date('Y-m-d H:i:s', time() + max(30, $ttlSeconds)),
                    ]
                );
                return true;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function release(int $photoId, string $ownerToken): void
    {
        InterfaceDB::prepareExecute(
            'DELETE FROM photo_operation_leases WHERE photo_id = :photo_id AND owner_token = :owner_token',
            ['photo_id' => $photoId, 'owner_token' => $ownerToken]
        );
    }

    public function heartbeat(int $photoId, string $ownerToken, int $ttlSeconds = 900): void
    {
        InterfaceDB::prepareExecute(
            'UPDATE photo_operation_leases SET heartbeat_at = CURRENT_TIMESTAMP, expires_at = :expires_at WHERE photo_id = :photo_id AND owner_token = :owner_token',
            [
                'photo_id' => $photoId,
                'owner_token' => $ownerToken,
                'expires_at' => date('Y-m-d H:i:s', time() + max(30, $ttlSeconds)),
            ]
        );
    }
}
