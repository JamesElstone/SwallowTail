<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$request = RequestFramework::fromGlobals();
$sessionAuthenticationService = new SessionAuthenticationService(request: $request);
$sessionAuthenticationService->startSession();
AntiFraudService::instance($request);

if ($request->method() !== 'POST') {
    ResponseFramework::json([
        'success' => false,
        'errors' => ['Gallery asset hints API expects POST.'],
    ], 405)->send();
    return;
}

$currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
$userId = $sessionAuthenticationService->authenticatedUserId($currentDeviceId);

if ($userId <= 0) {
    ResponseFramework::json([
        'success' => false,
        'errors' => ['Authentication is required.'],
    ], 401)->send();
    return;
}

$page = max(1, (int)$request->input('browse_gallery_page', 1));
$perPage = swallowtail_gallery_asset_hints_per_page((int)$request->input('browse_gallery_per_page', 24));
$reason = trim((string)$request->input('reason', 'browse_gallery_auto_refresh'));
$reason = $reason !== '' ? $reason : 'browse_gallery_auto_refresh';
$rows = (array)((new SwallowtailPhotoUiService())->accessiblePhotos($userId, $page, $perPage)['rows'] ?? []);
$notifier = new SwallowtailPhotoAssetNotificationService();
$pending = 0;
$queued = 0;

foreach ($rows as $photo) {
    if (!is_array($photo) || !swallowtail_gallery_asset_hints_photo_needs_refresh($photo)) {
        continue;
    }

    $imageType = swallowtail_gallery_asset_hints_scan_type($photo);
    if ($imageType === null) {
        continue;
    }

    ++$pending;
    if ($notifier->notifyPhotoAsset($photo, $imageType, $reason)) {
        ++$queued;
    }
}

ResponseFramework::json([
    'success' => true,
    'page' => $page,
    'per_page' => $perPage,
    'pending' => $pending,
    'queued' => $queued,
])->send();

function swallowtail_gallery_asset_hints_per_page(int $perPage): int
{
    return in_array($perPage, [24, 30, 40], true) ? $perPage : 24;
}

function swallowtail_gallery_asset_hints_photo_needs_refresh(array $photo): bool
{
    $status = swallowtail_gallery_asset_hints_status((string)($photo['conversion_state'] ?? 'pending'));

    return $status === 'processing'
        || ($status !== 'failed' && swallowtail_gallery_asset_hints_preview_type($photo) === null);
}

function swallowtail_gallery_asset_hints_scan_type(array $photo): ?string
{
    if (empty($photo['thumbnail_ready'])) {
        return 'thumbnail';
    }

    if (empty($photo['preview_ready'])) {
        return 'preview';
    }

    return null;
}

function swallowtail_gallery_asset_hints_preview_type(array $photo): ?string
{
    if (!empty($photo['preview_ready'])) {
        return 'preview';
    }

    return !empty($photo['thumbnail_ready']) ? 'thumbnail' : null;
}

function swallowtail_gallery_asset_hints_status(string $state): string
{
    $state = strtolower(trim($state));
    if ($state === 'ready' || $state === 'not_required') {
        return 'ready';
    }

    return $state === 'failed' ? 'failed' : 'processing';
}
