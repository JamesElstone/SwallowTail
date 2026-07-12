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

final class SwallowtailJobStatisticsService
{
    public function jobQueueRows(): array
    {
        return [
            $this->conversionRow(),
            $this->migrationRow(),
        ];
    }

    public function metadataProfileRows(): array
    {
        return [
            $this->metadataRow(),
            $this->profileRow(),
        ];
    }

    public function reprocessExceptions(string $jobType): array
    {
        return match (strtolower(trim($jobType))) {
            'conversion' => [
                'label' => 'Conversion',
                'count' => $this->reprocessConversionExceptions(),
            ],
            'migration' => [
                'label' => 'Migration',
                'count' => $this->reprocessMigrationExceptions(),
            ],
            'metadata' => [
                'label' => 'Metadata',
                'count' => $this->reprocessMetadataExceptions(),
            ],
            'profile' => [
                'label' => 'Profile',
                'count' => $this->reprocessProfileExceptions(),
            ],
            default => [
                'label' => '',
                'count' => 0,
            ],
        };
    }

    private function conversionRow(): array
    {
        $counts = array_merge($this->blankQueueCounts(), $this->statusCounts(
            'photo_conversion_jobs',
            ['succeeded', 'failed', 'cancelled', 'obsolete', 'queued', 'processing']
        ));

        return $this->queueRow('Conversion', $counts, $this->formatCount($counts['total']));
    }

    private function migrationRow(): array
    {
        $counts = array_merge($this->blankQueueCounts(), $this->statusCounts(
            'storage_migration_job_items',
            ['succeeded', 'failed', 'queued', 'processing']
        ));
        $jobCount = max(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM storage_migration_jobs'));

        return $this->queueRow('Migration', $counts, $this->formatCount($counts['total']) . ' in ' . $this->formatCount($jobCount));
    }

    private function metadataRow(): array
    {
        $statusCounts = $this->statusCounts('photo_metadata', ['ready', 'failed', 'deferred']);
        $counts = $this->blankMetadataCounts();
        $counts['ready'] = $statusCounts['ready'];
        $counts['failed'] = $statusCounts['failed'];
        $counts['deferred'] = $statusCounts['deferred'];
        $counts['queued'] = max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
               FROM photos p
               LEFT JOIN photo_metadata pm ON pm.photo_id = p.id
              WHERE p.upload_state = 'uploaded'
                AND (pm.photo_id IS NULL OR (pm.status = 'deferred' AND pm.next_attempt_at <= CURRENT_TIMESTAMP))"
        ));
        $counts['total'] = $statusCounts['total'];

        return $this->metadataProfileRow('Metadata', $counts, $this->formatCount($counts['total']));
    }

    private function profileRow(): array
    {
        $counts = $this->blankMetadataCounts();
        $indexHint = InterfaceDB::driverName() === 'sqlite'
            ? ''
            : ' FORCE INDEX (idx_photo_profile_data_status_value)';
        $row = InterfaceDB::fetchOne(
            "SELECT
                COUNT(CASE WHEN value = 'processed' THEN 1 END) AS ready,
                COUNT(CASE WHEN value = 'failed' THEN 1 END) AS failed,
                COUNT(CASE WHEN value = 'queued' THEN 1 END) AS queued,
                COUNT(CASE WHEN value = 'processing' THEN 1 END) AS processing,
                COUNT(*) AS total
             FROM photo_profile_data" . $indexHint . "
             WHERE type = 'swallowtail'
               AND `key` = 'status'"
        );

        if (is_array($row)) {
            foreach (['ready', 'failed', 'queued', 'processing', 'total'] as $key) {
                $counts[$key] = max(0, (int)($row[$key] ?? 0));
            }
        }

        return $this->metadataProfileRow(
            'Profile',
            $counts,
            $this->formatCount($counts['total']) . ' in ' . $this->formatCount($this->uploadedCr2Count())
        );
    }

    private function statusCounts(string $table, array $statuses): array
    {
        $counts = ['total' => 0];
        foreach ($statuses as $status) {
            $counts[$status] = 0;
        }

        $rows = InterfaceDB::fetchAll('SELECT status, COUNT(*) AS row_count FROM ' . $table . ' GROUP BY status');
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            $count = max(0, (int)($row['row_count'] ?? 0));
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
            $counts['total'] += $count;
        }

        return $counts;
    }

    private function uploadedCr2Count(): int
    {
        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photos
             WHERE upload_state = 'uploaded'
               AND original_extension = 'cr2'"
        ));
    }

    private function reprocessConversionExceptions(): int
    {
        return InterfaceDB::execute(
            "UPDATE photo_conversion_jobs
             SET status = 'queued',
                 attempts = 0,
                 available_at = CURRENT_TIMESTAMP,
                 locked_at = NULL,
                 locked_by = NULL,
                 started_at = NULL,
                 completed_at = NULL,
                 duration_seconds = NULL,
                 last_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE status = 'failed'"
        );
    }

    private function reprocessMigrationExceptions(): int
    {
        $jobRows = InterfaceDB::fetchAll(
            "SELECT DISTINCT job_id
             FROM storage_migration_job_items
             WHERE status = 'failed'"
        );
        $jobIds = [];
        foreach ($jobRows as $row) {
            $jobId = max(0, (int)($row['job_id'] ?? 0));
            if ($jobId > 0) {
                $jobIds[] = $jobId;
            }
        }
        $jobIds = array_values(array_unique($jobIds));
        if ($jobIds === []) {
            return 0;
        }

        return (int)InterfaceDB::transaction(function () use ($jobIds): int {
            $count = InterfaceDB::execute(
                "UPDATE storage_migration_job_items
                 SET status = 'queued',
                     last_error = NULL,
                     completed_at = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE status = 'failed'"
            );

            foreach ($jobIds as $jobId) {
                InterfaceDB::prepareExecute(
                    "UPDATE storage_migration_jobs
                     SET status = 'queued',
                         last_error = NULL,
                         completed_at = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id",
                    ['id' => $jobId]
                );
            }

            return $count;
        });
    }

    private function reprocessMetadataExceptions(): int
    {
        return InterfaceDB::execute("DELETE FROM photo_metadata WHERE status = 'failed'");
    }

    private function reprocessProfileExceptions(): int
    {
        $rows = InterfaceDB::fetchAll(
            "SELECT DISTINCT photo_id
             FROM photo_profile_data
             WHERE type = 'swallowtail'
               AND `key` = 'status'
               AND value = 'failed'"
        );
        $photoIds = [];
        foreach ($rows as $row) {
            $photoId = max(0, (int)($row['photo_id'] ?? 0));
            if ($photoId > 0) {
                $photoIds[] = $photoId;
            }
        }
        $photoIds = array_values(array_unique($photoIds));
        if ($photoIds === []) {
            return 0;
        }

        InterfaceDB::transaction(function () use ($photoIds): void {
            foreach ($photoIds as $photoId) {
                InterfaceDB::prepareExecute(
                    'DELETE FROM photo_profile_data WHERE photo_id = :photo_id',
                    ['photo_id' => $photoId]
                );
            }
        });

        return count($photoIds);
    }

    private function queueRow(string $type, array $counts, string $total): array
    {
        return [
            'job_type' => $type,
            'succeeded' => $counts['succeeded'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'cancelled' => $counts['cancelled'] ?? 0,
            'obsolete' => $counts['obsolete'] ?? 0,
            'queued' => $counts['queued'] ?? 0,
            'processing' => $counts['processing'] ?? 0,
            'total' => $total,
            'job_key' => strtolower($type),
        ];
    }

    private function metadataProfileRow(string $type, array $counts, string $total): array
    {
        return [
            'job_type' => $type,
            'ready' => $counts['ready'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'deferred' => $counts['deferred'] ?? 0,
            'queued' => $counts['queued'] ?? 0,
            'processing' => $counts['processing'] ?? 0,
            'total' => $total,
            'job_key' => strtolower($type),
        ];
    }

    private function blankQueueCounts(): array
    {
        return [
            'succeeded' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'obsolete' => 0,
            'queued' => 0,
            'processing' => 0,
            'total' => 0,
        ];
    }

    private function blankMetadataCounts(): array
    {
        return [
            'ready' => 0,
            'failed' => 0,
            'deferred' => 0,
            'queued' => 0,
            'processing' => 0,
            'total' => 0,
        ];
    }

    private function formatCount(int $count): string
    {
        return number_format(max(0, $count));
    }
}
