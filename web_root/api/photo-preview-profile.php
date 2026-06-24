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
$sessionAuthenticationService = new SessionAuthenticationService(request: $request);
$sessionAuthenticationService->startSession();
AntiFraudService::instance($request);

if ($request->method() !== 'POST') {
    ResponseFramework::json([
        'success' => false,
        'errors' => ['Preview profile API expects POST.'],
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

if (!$sessionAuthenticationService->isValidCsrfToken((string)$request->input('csrf_token', ''))) {
    ResponseFramework::json([
        'success' => false,
        'errors' => ['Your security token expired. Please refresh the page and try again.'],
    ], 409)->send();
    return;
}

$photoId = max(0, (int)$request->input('photo_id', 0));
$payload = [
    'crop' => (array)$request->input('crop', []),
    'exposure' => (array)$request->input('exposure', []),
    'white_balance' => (array)$request->input('white_balance', []),
    'shadows_highlights' => (array)$request->input('shadows_highlights', []),
    'rotation' => (array)$request->input('rotation', []),
    'perspective' => (array)$request->input('perspective', []),
];

$result = (new SwallowtailPreviewProfileService())->enqueuePreview($photoId, $userId, $payload);
ResponseFramework::json($result, !empty($result['success']) ? 200 : 400)->send();
