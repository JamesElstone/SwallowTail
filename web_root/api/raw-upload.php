<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
}

if (!function_exists('raw_upload_trace_request_start')) {
    function raw_upload_trace_request_start(): void
    {
        logDetails();
    }

    function raw_upload_trace_request_ready(): void
    {
        logDetails();
    }

    function raw_upload_trace_service_ready(): void
    {
        logDetails();
    }

    function raw_upload_trace_handle_start(): void
    {
        logDetails();
    }

    function raw_upload_trace_response_ready(): void
    {
        logDetails();
    }

    function raw_upload_trace_response_sent(): void
    {
        logDetails();
    }
}

raw_upload_trace_request_start();

$headers = function_exists('getallheaders') ? (array)getallheaders() : [];
$request = new RequestFramework($_GET, $_POST, $_SERVER, $_FILES, $headers, null, $_COOKIE);
raw_upload_trace_request_ready();

$service = new SwallowtailRawUploadApiService();
raw_upload_trace_service_ready();

raw_upload_trace_handle_start();
$response = $service->handleUpload($request, $_FILES);
raw_upload_trace_response_ready();

$response->send();
raw_upload_trace_response_sent();
