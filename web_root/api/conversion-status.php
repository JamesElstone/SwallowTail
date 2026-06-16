<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$headers = function_exists('getallheaders') ? (array)getallheaders() : [];
$request = new RequestFramework($_GET, $_POST, $_SERVER, $_FILES, $headers, null, $_COOKIE);

$service = new SwallowtailConversionStatusApiService();
$service->handleStatus($request)->send();
