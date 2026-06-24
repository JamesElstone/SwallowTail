<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SwallowtailPhotoMetadataSummaryService
{
    public const DEFAULT_HELPER = 'Preview and metadata for the selected photo.';

    public function helperForPhoto(int $photoId, int $userId): string
    {
        if ($photoId <= 0 || $userId <= 0) {
            return self::DEFAULT_HELPER;
        }

        try {
            $photo = (new SwallowtailPhotoUiService())->photoDetails($photoId, $userId);
        } catch (Throwable) {
            return self::DEFAULT_HELPER;
        }

        if ($photo === null) {
            return self::DEFAULT_HELPER;
        }

        return $this->summaryText($photo, $this->metadataForPhoto($photoId));
    }

    public function summaryText(array $photo, array $metadata): string
    {
        $filename = trim((string)($photo['original_filename'] ?? ''));
        if ($filename === '') {
            $filename = 'Photo';
        }

        $cameraModel = trim((string)($metadata['camera_model'] ?? ''));
        $lensModel = trim((string)($metadata['lens_model'] ?? ''));
        $shutter = $this->formatShutterSpeed($metadata['shutter_speed'] ?? null);
        $aperture = $this->formatDecimal($metadata['aperture'] ?? null);
        $focalLength = $this->formatDecimal($metadata['focal_length_mm'] ?? null);
        $iso = (int)($metadata['iso'] ?? 0);
        $exposureParts = [];

        if ($shutter !== '') {
            $exposureParts[] = $shutter;
        }
        if ($aperture !== '') {
            $exposureParts[] = '@ f/' . $aperture;
        }
        if ($iso > 0) {
            $exposureParts[] = (string)$iso . ' ASA';
        }

        if ($cameraModel === '' && $lensModel === '' && $focalLength === '' && count($exposureParts) === 0) {
            return $filename;
        }

        $summary = $filename . ' : ' . ($cameraModel !== '' ? $cameraModel : 'Unknown camera');
        if ($lensModel !== '') {
            $summary .= ' with ' . $lensModel;
        }
        if ($focalLength !== '') {
            $summary .= ' @ ' . $focalLength . 'mm';
        }
        $exposureText = $this->formatExposureSummary($exposureParts);
        if ($exposureText !== '') {
            $summary .= ' [ ' . $exposureText . ' ]';
        }

        return $summary;
    }

    private function formatExposureSummary(array $parts): string
    {
        if (count($parts) === 0) {
            return '';
        }

        $summary = (string)array_shift($parts);
        if (count($parts) > 0 && str_starts_with((string)$parts[0], '@ ')) {
            $summary .= ' ' . (string)array_shift($parts);
        }
        if (count($parts) > 0) {
            $summary .= ', ' . implode(', ', array_map('strval', $parts));
        }

        return $summary;
    }

    private function metadataForPhoto(int $photoId): array
    {
        if ($photoId <= 0) {
            return [];
        }

        try {
            if (!InterfaceDB::tableExists('photo_metadata')) {
                return [];
            }

            $metadata = InterfaceDB::fetchOne(
                'SELECT camera_model, lens_model, iso, shutter_speed, aperture, focal_length_mm
                 FROM photo_metadata
                 WHERE photo_id = :photo_id
                 LIMIT 1',
                ['photo_id' => $photoId]
            );
        } catch (Throwable) {
            return [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function formatShutterSpeed(mixed $value): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $seconds = null;
        if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $raw, $matches) === 1) {
            $numerator = (float)$matches[1];
            $denominator = (float)$matches[2];
            if ($denominator > 0.0) {
                $seconds = $numerator / $denominator;
            }
        } elseif (is_numeric($raw)) {
            $seconds = (float)$raw;
        }

        if ($seconds === null || $seconds <= 0.0) {
            return $raw;
        }

        $milliseconds = max(1, (int)round($seconds * 1000));

        return $this->formatShutterLabel($raw, $seconds) . ' (' . (string)$milliseconds . 'ms)';
    }

    private function formatShutterLabel(string $raw, float $seconds): string
    {
        if (str_contains($raw, '/')) {
            return $raw;
        }

        if ($seconds > 0.0 && $seconds < 1.0) {
            return '1/' . (string)max(1, (int)round(1 / $seconds));
        }

        return $this->formatDecimal($seconds) . 's';
    }

    private function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!is_numeric($value)) {
            return trim((string)$value);
        }

        return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    }
}
