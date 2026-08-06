<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\ApiSecurityService;
use Swallowtail\Service\SwallowtailImageServeService;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$request = RequestFramework::fromGlobals();
$security = new ApiSecurityService($request);
$error = $security->requireBrowserApi('Photo imaging API', ['GET', 'HEAD']);
if ($error instanceof ResponseFramework) {
    $error->send();
    return;
}

$userId = $security->userId();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$photoId = max(0, (int)$request->query('photo_id', 0));
$type = trim((string)$request->query('type', 'preview'));
$profileSignature = strtolower(trim((string)$request->query('profile_signature', '')));
$imageService = new SwallowtailImageServeService();
$image = $imageService->derivativeImage($photoId, $type, $userId, $profileSignature);

if ($image === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo 'Image was not found.';
    return;
}

$queryValues = $request->queryValues();
$requestedVersion = array_key_exists('v', $queryValues) && is_scalar($queryValues['v'])
    ? (string)$queryValues['v']
    : (array_key_exists('v', $queryValues) ? '' : null);
$cachePolicy = $imageService->cachePolicyForVersion((string)($image['cache_version'] ?? ''), $requestedVersion);
if (!$cachePolicy['valid']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: ' . $cachePolicy['cache_control']);
    echo 'Image version was not found.';
    return;
}

$etag = (string)$image['etag'];
$lastModified = (string)$image['last_modified'];
$cacheControl = (string)$cachePolicy['cache_control'];
$ifNoneMatch = trim((string)$request->header('If-None-Match', ''));
$ifModifiedSince = trim((string)$request->header('If-Modified-Since', ''));

if (!headers_sent()) {
    header_remove('Expires');
    header_remove('Pragma');
    header_remove('X-Powered-By');
    header_remove('X-XSS-Protection');
}

if ($ifNoneMatch === $etag || ($ifNoneMatch === '' && $ifModifiedSince === $lastModified)) {
    http_response_code(304);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: ' . $cacheControl);
    return;
}

http_response_code(200);
header('Content-Type: ' . (string)$image['content_type']);
header('Content-Length: ' . (string)$image['bytes']);
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)$image['filename']) . '"');
header('Cache-Control: ' . $cacheControl);
header('ETag: ' . $etag);
header('Last-Modified: ' . $lastModified);
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cross-Origin-Resource-Policy: same-origin');

if ($request->method() === 'HEAD') {
    return;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile((string)$image['absolute_path']);
