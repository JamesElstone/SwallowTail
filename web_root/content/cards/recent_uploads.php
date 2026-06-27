<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\SwallowtailPhotoUiService;

final class _recent_uploadsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'recent_uploads';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['recent.uploads', 'cr2.upload'];
    }

    public function title(): string
    {
        return 'Recent Uploads';
    }

    public function helper(array $context): string
    {
        return 'Recent uploads and conversion readiness.';
    }

    public function render(array $context): string
    {
        $rows = (new SwallowtailPhotoUiService())->recentUploads($this->currentUserId(), 8);
        if ($rows === []) {
            return '<p class="helper">No recent uploads yet.</p>';
        }

        $html = '<div class="recent-upload-list">';
        foreach ($rows as $photo) {
            $html .= $this->uploadRow((array)$photo);
        }

        return $html . '</div>';
    }

    private function uploadRow(array $photo): string
    {
        $photoId = (int)($photo['id'] ?? 0);
        $duplicateCount = (int)($photo['duplicate_upload_count'] ?? 0);
        $duplicateBadge = $duplicateCount > 0
            ? '<span class="badge warning">' . HelperFramework::escape((string)$duplicateCount) . ' duplicate' . ($duplicateCount === 1 ? '' : 's') . '</span>'
            : '';

        return '<article class="recent-upload-row">
            <div>
                <a href="?page=view&photo_id=' . rawurlencode((string)$photoId) . '"><strong>' . HelperFramework::escape((string)($photo['original_filename'] ?? 'Photo')) . '</strong></a>
                <p>' . HelperFramework::escape((string)($photo['created_at'] ?? '')) . ' · ' . HelperFramework::escape($this->formatBytes((int)($photo['original_bytes'] ?? 0))) . '</p>
            </div>
            <div class="recent-upload-badges">
                <span class="badge">' . HelperFramework::escape($this->labelFromState((string)($photo['conversion_state'] ?? 'pending'))) . '</span>
                ' . (!empty($photo['preview_ready']) ? '<span class="badge success">Preview</span>' : '<span class="badge warning">Preview pending</span>') . '
                ' . $duplicateBadge . '
            </div>
        </article>';
    }

    private function labelFromState(string $state): string
    {
        $state = trim(str_replace('_', ' ', $state));

        return $state !== '' ? ucwords($state) : 'Unknown';
    }

    private function formatBytes(int $bytes): string
    {
        $value = max(0.0, (float)$bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, $unitIndex === 0 ? 0 : 1) . ' ' . $units[$unitIndex];
    }

    private function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
    }
}
