<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function photo_asset_trace_request_start(): void
{
    logDetails();
}

function photo_asset_trace_request_ready(): void
{
    logDetails();
}

function photo_asset_trace_antifraud_ready(): void
{
    logDetails();
}

function photo_asset_trace_session_started(): void
{
    logDetails();
}

function photo_asset_trace_user_resolved(): void
{
    logDetails();
}

function photo_asset_trace_unauthenticated(): void
{
    logDetails();
}

function photo_asset_trace_query_ready(): void
{
    logDetails();
}

function photo_asset_trace_asset_lookup_start(): void
{
    logDetails();
}

function photo_asset_trace_asset_lookup_complete(): void
{
    logDetails();
}

function photo_asset_trace_asset_not_found(): void
{
    logDetails();
}

function photo_asset_trace_response_metadata_ready(): void
{
    logDetails();
}

function photo_asset_trace_headers_ready(): void
{
    logDetails();
}

function photo_asset_trace_open_start(): void
{
    logDetails();
}

function photo_asset_trace_open_failed(): void
{
    logDetails();
}

function photo_asset_trace_stream_start(): void
{
    logDetails();
}

function photo_asset_trace_stream_complete(): void
{
    logDetails();
}

photo_asset_trace_request_start();

$request = RequestFramework::fromGlobals();
photo_asset_trace_request_ready();
AntiFraudService::instance($request);
photo_asset_trace_antifraud_ready();

$sessionAuthenticationService = new SessionAuthenticationService(request: $request);
$sessionAuthenticationService->startSession();
photo_asset_trace_session_started();

$currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
$userId = $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
photo_asset_trace_user_resolved();

if ($userId <= 0) {
    photo_asset_trace_unauthenticated();
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Authentication is required.';
    return;
}

$photoId = max(0, (int)$request->query('photo_id', 0));
$type = strtolower(trim((string)$request->query('type', 'thumbnail')));
photo_asset_trace_query_ready();

photo_asset_trace_asset_lookup_start();
$asset = (new SwallowtailPhotoUiService())->photoAsset($photoId, $userId, $type);

if ($asset === null) {
    photo_asset_trace_asset_not_found();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Photo asset was not found.';
    return;
}
photo_asset_trace_asset_lookup_complete();

$path = (string)$asset['path'];
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($asset['filename'] ?? 'photo.jpg')) ?? 'photo.jpg';
$filename = trim($filename, '.-') !== '' ? trim($filename, '.-') : 'photo.jpg';
$bytes = (int)($asset['bytes'] ?? filesize($path));
photo_asset_trace_response_metadata_ready();

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header_remove('X-XSS-Protection');
}

http_response_code(200);
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)$bytes);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, max-age=60');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cross-Origin-Resource-Policy: same-origin');
photo_asset_trace_headers_ready();

photo_asset_trace_open_start();
$handle = @fopen($path, 'rb');
if (is_resource($handle)) {
    photo_asset_trace_stream_start();
    fpassthru($handle);
    photo_asset_trace_stream_complete();
    fclose($handle);
} else {
    photo_asset_trace_open_failed();
}
