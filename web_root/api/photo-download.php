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
$security = new ApiSecurityService($request);
$error = $security->requireBrowserApi('Photo download API', ['GET']);
if ($error instanceof ResponseFramework) {
    http_response_code($error->statusCode());
    header('Content-Type: text/plain; charset=utf-8');
    echo implode(' ', json_decode($error->body(), true, 512, JSON_THROW_ON_ERROR)['errors'] ?? ['Download was not allowed.']);
    return;
}

$download = new SwallowtailDownloadService();
$temporaryPath = null;

try {
    $kind = strtolower(trim((string)$request->query('kind', 'event')));
    if ($kind === 'photo') {
        $asset = $download->singleJpeg($security->userId(), max(0, (int)$request->query('photo_id', 0)));
    } else {
        $asset = $download->eventZip(
            $security->userId(),
            max(0, (int)$request->query('event_id', 0)),
            (string)$request->query('type', 'preview')
        );
        $temporaryPath = (string)$asset['path'];
    }

    swallowtail_stream_download($asset);
} catch (Throwable $exception) {
    swallowtail_download_error(404, $exception->getMessage());
} finally {
    if ($temporaryPath !== null && $temporaryPath !== '') {
        @unlink($temporaryPath);
    }
}

function swallowtail_stream_download(array $asset): void
{
    $path = (string)($asset['path'] ?? '');
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        swallowtail_download_error(404, 'Download was not found.');
        return;
    }

    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($asset['filename'] ?? 'download')) ?? 'download';
    $filename = trim($filename, '.-') !== '' ? trim($filename, '.-') : 'download';
    $contentType = trim((string)($asset['content_type'] ?? 'application/octet-stream'));
    $bytes = (int)($asset['bytes'] ?? filesize($path));

    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header_remove('X-XSS-Protection');
    }

    http_response_code(200);
    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/octet-stream'));
    header('Content-Length: ' . (string)$bytes);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    $handle = @fopen($path, 'rb');
    if (is_resource($handle)) {
        fpassthru($handle);
        fclose($handle);
    }
}

function swallowtail_download_error(int $statusCode, string $message): void
{
    if (!headers_sent()) {
        header_remove('X-Powered-By');
        header_remove('X-XSS-Protection');
    }

    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    echo $message;
}
