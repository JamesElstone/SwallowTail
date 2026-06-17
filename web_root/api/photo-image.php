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
$method = $request->method();

if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Image endpoint expects GET.';
    return;
}

$antiFraudService = AntiFraudService::instance($request);
$sessionAuthenticationService = new SessionAuthenticationService(request: $request);
$sessionAuthenticationService->startSession();

$currentDeviceId = trim((string)$antiFraudService->requestValue('Client-Device-ID'));
$currentUserId = $sessionAuthenticationService->authenticatedUserId($currentDeviceId);

if ($currentUserId <= 0) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo 'Authentication is required.';
    return;
}

$photoId = max(0, (int)$request->query('photo_id', 0));
$derivativeType = trim((string)$request->query('type', 'filtered'));
$image = (new SwallowtailImageServeService())->derivativeImage($photoId, $derivativeType, $currentUserId);

if ($image === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo 'Image was not found.';
    return;
}

$etag = (string)$image['etag'];
$lastModified = (string)$image['last_modified'];
$ifNoneMatch = trim((string)$request->header('If-None-Match', ''));
$ifModifiedSince = trim((string)$request->header('If-Modified-Since', ''));

if ($ifNoneMatch === $etag || ($ifNoneMatch === '' && $ifModifiedSince === $lastModified)) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: private, max-age=300, must-revalidate');
    return;
}

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header_remove('X-XSS-Protection');
}

http_response_code(200);
header('Content-Type: ' . (string)$image['content_type']);
header('Content-Length: ' . (string)$image['bytes']);
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$image['filename']) . '"');
header('Cache-Control: private, max-age=300, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cross-Origin-Resource-Policy: same-origin');

if ($method === 'HEAD') {
    return;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile((string)$image['absolute_path']);
