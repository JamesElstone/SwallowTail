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
AntiFraudService::instance($request);

$sessionAuthenticationService = new SessionAuthenticationService(request: $request);
$sessionAuthenticationService->startSession();

$currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
$userId = $sessionAuthenticationService->authenticatedUserId($currentDeviceId);

if ($userId <= 0) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Authentication is required.';
    return;
}

$photoId = max(0, (int)$request->query('photo_id', 0));
$type = strtolower(trim((string)$request->query('type', 'thumbnail')));
$asset = (new SwallowtailPhotoUiService())->photoAsset($photoId, $userId, $type);

if ($asset === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Photo asset was not found.';
    return;
}

$path = (string)$asset['path'];
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($asset['filename'] ?? 'photo.jpg')) ?? 'photo.jpg';
$filename = trim($filename, '.-') !== '' ? trim($filename, '.-') : 'photo.jpg';
$bytes = (int)($asset['bytes'] ?? filesize($path));

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

$handle = @fopen($path, 'rb');
if (is_resource($handle)) {
    fpassthru($handle);
    fclose($handle);
}
