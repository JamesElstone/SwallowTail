<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailStatisticsService
{
    public function summary(): array
    {
        return [
            'photos_current' => $this->uploadedPhotoCount(),
            'jobs' => $this->jobCounts(),
            'duration_by_image_type' => $this->durationByImageType(),
        ];
    }

    private function uploadedPhotoCount(): int
    {
        if (!InterfaceDB::tableExists('photos')) {
            return 0;
        }

        if (InterfaceDB::columnExists('photos', 'upload_state')) {
            return max(0, (int)InterfaceDB::fetchColumn(
                "SELECT COUNT(*)
                 FROM photos
                 WHERE upload_state = 'uploaded'"
            ));
        }

        return max(0, InterfaceDB::tableRowCount('photos'));
    }

    private function jobCounts(): array
    {
        $counts = [
            'total' => 0,
            'outstanding' => 0,
            'completed' => 0,
        ];

        if (!InterfaceDB::tableExists('photo_conversion_jobs')) {
            return $counts;
        }

        $counts['total'] = max(0, InterfaceDB::tableRowCount('photo_conversion_jobs'));
        if (!InterfaceDB::columnExists('photo_conversion_jobs', 'status')) {
            return $counts;
        }

        $counts['outstanding'] = max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_conversion_jobs
             WHERE status IN ('queued', 'processing')"
        ));
        $counts['completed'] = max(0, (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photo_conversion_jobs
             WHERE status = 'succeeded'"
        ));

        return $counts;
    }

    private function durationByImageType(): array
    {
        if (
            !InterfaceDB::tableExists('photo_conversion_jobs')
            || !InterfaceDB::columnsExists('photo_conversion_jobs', ['image_type', 'status', 'duration_seconds'])
        ) {
            return [];
        }

        $rows = InterfaceDB::fetchAll(
            "SELECT
                image_type,
                COUNT(*) AS completed_jobs,
                AVG(duration_seconds) AS average_seconds,
                MIN(duration_seconds) AS fastest_seconds,
                MAX(duration_seconds) AS slowest_seconds
             FROM photo_conversion_jobs
             WHERE status = 'succeeded'
               AND duration_seconds IS NOT NULL
             GROUP BY image_type
             ORDER BY CASE image_type
                WHEN 'embedded' THEN 1
                WHEN 'thumbnail' THEN 2
                WHEN 'original' THEN 3
                WHEN 'filtered' THEN 4
                ELSE 5
             END, image_type"
        );

        return array_map(static function (array $row): array {
            return [
                'image_type' => (string)($row['image_type'] ?? ''),
                'completed_jobs' => max(0, (int)($row['completed_jobs'] ?? 0)),
                'average_seconds' => max(0.0, (float)($row['average_seconds'] ?? 0)),
                'fastest_seconds' => max(0.0, (float)($row['fastest_seconds'] ?? 0)),
                'slowest_seconds' => max(0.0, (float)($row['slowest_seconds'] ?? 0)),
            ];
        }, $rows);
    }
}
