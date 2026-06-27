<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

use Swallowtail\Service\ApiSecurityService;
use Swallowtail\Service\SwallowtailPreviewProfileService;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$request = RequestFramework::fromGlobals();
$security = new ApiSecurityService($request);
$error = $security->requireBrowserApi('Photo update API', ['POST'], true);
if ($error instanceof ResponseFramework) {
    $error->send();
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
$action = strtolower(trim((string)$request->input('action', $request->query('action', 'preview_profile'))));
$service = new SwallowtailPreviewProfileService();
$result = match ($action) {
    'final_profile' => $service->enqueueFinal($photoId, $security->userId(), $payload),
    default => $service->enqueuePreview($photoId, $security->userId(), $payload),
};

ResponseFramework::json($result, !empty($result['success']) ? 200 : 400)->send();
