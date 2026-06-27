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
        $counts = $this->blankQueueCounts();
        if (!InterfaceDB::tableExists('photo_conversion_jobs') || !InterfaceDB::columnExists('photo_conversion_jobs', 'status')) {
            return $this->queueRow('Conversion', $counts, '0');
        }

        $counts = array_merge($counts, $this->statusCounts(
            'photo_conversion_jobs',
            ['succeeded', 'failed', 'cancelled', 'obsolete', 'queued', 'processing']
        ));

        return $this->queueRow('Conversion', $counts, $this->formatCount($counts['total']));
    }

    private function migrationRow(): array
    {
        $counts = $this->blankQueueCounts();
        if (InterfaceDB::tableExists('storage_migration_job_items') && InterfaceDB::columnExists('storage_migration_job_items', 'status')) {
            $counts = array_merge($counts, $this->statusCounts(
                'storage_migration_job_items',
                ['succeeded', 'failed', 'queued', 'processing']
            ));
        }

        $jobCount = InterfaceDB::tableExists('storage_migration_jobs')
            ? max(0, InterfaceDB::tableRowCount('storage_migration_jobs'))
            : 0;

        return $this->queueRow('Migration', $counts, $this->formatCount($counts['total']) . ' in ' . $this->formatCount($jobCount));
    }

    private function metadataRow(): array
    {
        $counts = $this->blankMetadataCounts();
        if (InterfaceDB::tableExists('photo_metadata') && InterfaceDB::columnExists('photo_metadata', 'status')) {
            $statusCounts = $this->statusCounts('photo_metadata', ['ready', 'failed', 'deferred']);
            $counts['ready'] = $statusCounts['ready'];
            $counts['failed'] = $statusCounts['failed'];
            $counts['deferred'] = $statusCounts['deferred'];
            $counts['total'] = $statusCounts['total'];
        }

        return $this->metadataProfileRow('Metadata', $counts, $this->formatCount($counts['total']));
    }

    private function profileRow(): array
    {
        $counts = $this->blankMetadataCounts();
        if (InterfaceDB::tableExists('photo_profile_data') && InterfaceDB::columnsExists('photo_profile_data', ['type', 'key', 'value'])) {
            $row = InterfaceDB::fetchOne(
                "SELECT
                    COALESCE(SUM(CASE WHEN value = 'processed' THEN 1 ELSE 0 END), 0) AS ready,
                    COALESCE(SUM(CASE WHEN value = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(CASE WHEN value = 'queued' THEN 1 ELSE 0 END), 0) AS queued,
                    COALESCE(SUM(CASE WHEN value = 'processing' THEN 1 ELSE 0 END), 0) AS processing,
                    COUNT(*) AS total
                 FROM photo_profile_data
                 WHERE type = 'swallowtail'
                   AND `key` = 'status'"
            );
            if (is_array($row)) {
                foreach (['ready', 'failed', 'queued', 'processing', 'total'] as $key) {
                    $counts[$key] = max(0, (int)($row[$key] ?? 0));
                }
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
        $selects = [];
        foreach ($statuses as $status) {
            $selects[] = "COALESCE(SUM(CASE WHEN status = '" . $status . "' THEN 1 ELSE 0 END), 0) AS " . $status;
        }
        $selects[] = 'COUNT(*) AS total';

        $row = InterfaceDB::fetchOne('SELECT ' . implode(",\n", $selects) . ' FROM ' . $table);
        $counts = ['total' => 0];
        foreach ($statuses as $status) {
            $counts[$status] = max(0, (int)(is_array($row) ? ($row[$status] ?? 0) : 0));
        }
        $counts['total'] = max(0, (int)(is_array($row) ? ($row['total'] ?? 0) : 0));

        return $counts;
    }

    private function uploadedCr2Count(): int
    {
        if (!InterfaceDB::tableExists('photos') || !InterfaceDB::columnsExists('photos', ['original_extension', 'upload_state'])) {
            return 0;
        }

        return max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photos
             WHERE upload_state = 'uploaded'
               AND LOWER(COALESCE(original_extension, '')) = 'cr2'"
        ));
    }

    private function reprocessConversionExceptions(): int
    {
        if (!InterfaceDB::tableExists('photo_conversion_jobs') || !InterfaceDB::columnsExists('photo_conversion_jobs', [
            'status',
            'attempts',
            'available_at',
            'locked_at',
            'locked_by',
            'started_at',
            'completed_at',
            'duration_seconds',
            'last_error',
            'updated_at',
        ])) {
            return 0;
        }

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
        if (!InterfaceDB::tableExists('storage_migration_job_items') || !InterfaceDB::columnsExists('storage_migration_job_items', [
            'job_id',
            'status',
            'last_error',
            'completed_at',
            'updated_at',
        ])) {
            return 0;
        }

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

            if (InterfaceDB::tableExists('storage_migration_jobs') && InterfaceDB::columnsExists('storage_migration_jobs', [
                'status',
                'last_error',
                'completed_at',
                'updated_at',
            ])) {
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
            }

            return $count;
        });
    }

    private function reprocessMetadataExceptions(): int
    {
        if (!InterfaceDB::tableExists('photo_metadata') || !InterfaceDB::columnExists('photo_metadata', 'status')) {
            return 0;
        }

        return InterfaceDB::execute("DELETE FROM photo_metadata WHERE status = 'failed'");
    }

    private function reprocessProfileExceptions(): int
    {
        if (!InterfaceDB::tableExists('photo_profile_data') || !InterfaceDB::columnsExists('photo_profile_data', ['photo_id', 'type', 'key', 'value'])) {
            return 0;
        }

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
