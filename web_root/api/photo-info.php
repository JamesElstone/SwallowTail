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
$security = new ApiSecurityService($request);
$error = $security->requireBrowserApi('Photo info API', ['GET']);
if ($error instanceof ResponseFramework) {
    $error->send();
    return;
}

$photoId = max(0, (int)$request->query('photo_id', 0));
$view = strtolower(trim((string)$request->query('view', 'viewer')));
$userId = $security->userId();

$result = match ($view) {
    'details' => (new _picture_viewerCard())->lazyDetailsTabContent(
        $photoId,
        $userId,
        (string)$request->query('tab', '')
    ),
    'profile' => (new SwallowtailPreviewProfileService())->baselineStatus($photoId, $userId),
    default => (new SwallowtailPreviewProfileService())->pictureViewerState($photoId, $userId),
};

ResponseFramework::json($result, !empty($result['success']) ? 200 : 404)->send();
