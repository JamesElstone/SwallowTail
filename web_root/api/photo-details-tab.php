<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'cards' . DIRECTORY_SEPARATOR . 'picture_viewer.php';

$request = RequestFramework::fromGlobals();
$sessionAuthenticationService = new SessionAuthenticationService(request: $request);
$sessionAuthenticationService->startSession();
AntiFraudService::instance($request);

if ($request->method() !== 'GET') {
    ResponseFramework::json([
        'success' => false,
        'errors' => ['Photo details tab API expects GET.'],
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

$result = (new _picture_viewerCard())->lazyDetailsTabContent(
    max(0, (int)$request->query('photo_id', 0)),
    $userId,
    (string)$request->query('tab', '')
);

ResponseFramework::json($result, !empty($result['success']) ? 200 : 404)->send();
