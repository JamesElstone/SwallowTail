<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (!function_exists('quick_checksum_trace_request_start')) {
    function quick_checksum_trace_request_start(): void
    {
        logDetails();
    }

    function quick_checksum_trace_request_ready(): void
    {
        logDetails();
    }

    function quick_checksum_trace_service_ready(): void
    {
        logDetails();
    }

    function quick_checksum_trace_handle_start(): void
    {
        logDetails();
    }

    function quick_checksum_trace_response_ready(): void
    {
        logDetails();
    }

    function quick_checksum_trace_response_sent(): void
    {
        logDetails();
    }
}

quick_checksum_trace_request_start();

$headers = function_exists('getallheaders') ? (array)getallheaders() : [];
$request = new RequestFramework($_GET, $_POST, $_SERVER, $_FILES, $headers, null, $_COOKIE);
quick_checksum_trace_request_ready();

$service = new SwallowtailQuickChecksumApiService();
quick_checksum_trace_service_ready();

quick_checksum_trace_handle_start();
$response = $service->handleCheck($request);
quick_checksum_trace_response_ready();

$response->send();
quick_checksum_trace_response_sent();
