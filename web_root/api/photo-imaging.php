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

$photoId = max(0, (int)$request->query('photo_id', 0));
$type = trim((string)$request->query('type', 'preview'));
$image = (new SwallowtailImageServeService())->derivativeImage($photoId, $type, $security->userId());

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

if ($request->method() === 'HEAD') {
    return;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile((string)$image['absolute_path']);
