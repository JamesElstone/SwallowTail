<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailConversionQueueService
{
    public function enqueueRawConversion(int $photoId, string $priority = 'normal'): ?int
    {
        if ($photoId <= 0 || !InterfaceDB::tableExists('swallowtail_photo_conversion_jobs')) {
            return null;
        }

        $priority = strtolower(trim($priority));
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            $priority = 'normal';
        }

        $existingJobId = InterfaceDB::fetchColumn(
            "SELECT id
             FROM swallowtail_photo_conversion_jobs
             WHERE photo_id = :photo_id
               AND job_type = 'raw_derivatives'
               AND status IN ('queued', 'processing')
             ORDER BY id DESC
             LIMIT 1",
            ['photo_id' => $photoId]
        );

        if ($existingJobId !== false && $existingJobId !== null) {
            return (int)$existingJobId;
        }

        InterfaceDB::prepareExecute(
            "INSERT INTO swallowtail_photo_conversion_jobs (
                photo_id,
                job_type,
                priority,
                status
            ) VALUES (
                :photo_id,
                'raw_derivatives',
                :priority,
                'queued'
            )",
            [
                'photo_id' => $photoId,
                'priority' => $priority,
            ]
        );

        return $this->lastInsertId();
    }

    public function queuedJobs(int $limit = 50): array
    {
        if (!InterfaceDB::tableExists('swallowtail_photo_conversion_jobs')) {
            return [];
        }

        $limit = max(1, min(200, $limit));

        return InterfaceDB::fetchAll(
            "SELECT *
             FROM swallowtail_photo_conversion_jobs
             WHERE status = 'queued'
               AND available_at <= CURRENT_TIMESTAMP
             ORDER BY
               CASE priority
                 WHEN 'high' THEN 1
                 WHEN 'normal' THEN 2
                 ELSE 3
               END,
               id
             LIMIT " . $limit
        );
    }

    private function lastInsertId(): int
    {
        if (InterfaceDB::driverName() === 'sqlite') {
            return (int)InterfaceDB::fetchColumn('SELECT last_insert_rowid()');
        }

        return (int)InterfaceDB::fetchColumn('SELECT LAST_INSERT_ID()');
    }
}
