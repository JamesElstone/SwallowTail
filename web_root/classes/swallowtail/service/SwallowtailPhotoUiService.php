<?php
/**
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace Swallowtail\Service;

use InterfaceDB;
use RoleAssignmentService;
use RoleRepository;
use Throwable;

final class SwallowtailPhotoUiService
{
    private const IMAGE_TYPES = ['preview', 'thumbnail', 'embedded', 'original', 'final', 'rawtheapee_sample'];
    private const DOWNLOAD_IMAGE_TYPES = ['original', 'final'];

    public function __construct(
        private readonly SwallowtailStorageService $storageService = new SwallowtailStorageService(),
        private readonly SwallowtailPhotoAssetService $assetService = new SwallowtailPhotoAssetService(),
    ) {
    }

    public function accessiblePhotos(int $userId, int $page = 1, int $perPage = 24): array
    {

        if ($userId <= 0) {
            return $this->emptyPaginated($page, $perPage);
        }

        $page = max(1, $page);
        $perPage = max(1, min(96, $perPage));
        $params = [];
        $where = $this->accessWhereSql($userId, $params, 'photo');

        $total = (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*)
             FROM photos photo
             WHERE " . $where,
            $params
        );

        $pagination = $this->pagination($total, $page, $perPage);
        $offset = (((int)$pagination['page']) - 1) * $perPage;

        $rows = InterfaceDB::fetchAll(
            "SELECT
                photo.*,
                (
                    SELECT GROUP_CONCAT(event.event_name)
                    FROM event_photos event_photo
                    INNER JOIN events event
                        ON event.id = event_photo.event_id
                    WHERE event_photo.photo_id = photo.id
                ) AS event_names,
                " . $this->effectiveCanEditSql($userId, $params, 'photo') . " AS effective_can_edit,
                " . $this->effectiveCanDownloadSingleJpegSql($userId, $params, 'photo') . " AS effective_can_download_single_jpeg
             FROM photos photo
             WHERE " . $where . "
             ORDER BY photo.created_at DESC, photo.id DESC
             LIMIT " . (string)$perPage . " OFFSET " . (string)$offset,
            $params
        );

        return [
            'rows' => array_map([$this, 'normaliseGalleryPhotoRow'], $rows),
            'pagination' => $pagination,
        ];
    }

    public function recentUploads(int $userId, int $limit = 8): array
    {

        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $params = [];
        $where = $this->accessWhereSql($userId, $params, 'photo');

        return array_map(
            [$this, 'normalisePhotoRow'],
            InterfaceDB::fetchAll(
                "SELECT
                    photo.*,
                    (
                        SELECT COUNT(*)
                        FROM photo_audit audit
                        WHERE audit.photo_id = photo.id
                          AND audit.action_type = 'raw_duplicate_detected'
                    ) AS duplicate_upload_count,
                    (
                        SELECT GROUP_CONCAT(event.event_name)
                        FROM event_photos event_photo
                        INNER JOIN events event
                            ON event.id = event_photo.event_id
                        WHERE event_photo.photo_id = photo.id
                    ) AS event_names,
                    " . $this->effectiveCanEditSql($userId, $params, 'photo') . " AS effective_can_edit
                 FROM photos photo
                 WHERE " . $where . "
                 ORDER BY photo.created_at DESC, photo.id DESC
                 LIMIT " . (string)$limit,
                $params
            )
        );
    }

    public function photoDetails(int $photoId, int $userId, bool $includeDerivativeStatus = true): ?array
    {

        if ($photoId <= 0 || $userId <= 0) {
            return null;
        }

        $params = ['photo_id' => $photoId];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');

        $photo = InterfaceDB::fetchOne(
            "SELECT
                photo.*,
                photo.storage_base_location AS storage_root_path,
                photo.storage_base_location AS location_label,
                (
                    SELECT GROUP_CONCAT(event.event_name)
                    FROM event_photos event_photo
                    INNER JOIN events event
                        ON event.id = event_photo.event_id
                    WHERE event_photo.photo_id = photo.id
                ) AS event_names,
                " . $this->effectiveCanEditSql($userId, $params, 'photo') . " AS effective_can_edit
             FROM photos photo
             WHERE " . $where . "
             LIMIT 1",
            $params
        );

        if (!is_array($photo)) {
            return null;
        }

        $photo = $this->normalisePhotoRow($photo, $includeDerivativeStatus);
        $photo['derivatives'] = $includeDerivativeStatus ? $this->photoImages($photo) : [];

        return $photo;
    }

    public function photoAsset(int $photoId, int $userId, string $type): ?array
    {

        if ($photoId <= 0 || $userId <= 0) {
            return null;
        }

        $type = strtolower(trim($type));
        if (!in_array($type, self::IMAGE_TYPES, true)) {
            return null;
        }

        if (!$this->userCanViewImageType($photoId, $userId, $type)) {
            return null;
        }

        $params = ['photo_id' => $photoId];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');
        $photo = InterfaceDB::fetchOne('SELECT * FROM photos photo WHERE ' . $where . ' LIMIT 1', $params);
        if (!is_array($photo)) {
            return null;
        }

        $info = $type === 'final'
            ? $this->assetService->assetForPhotoWithFinalFallback($photo, $type)
            : $this->assetService->assetForPhoto($photo, $type);
        if ($info === null) {
            return null;
        }

        return [
            'path' => (string)$info['absolute_path'],
            'content_type' => 'image/jpeg',
            'image_type' => $type,
            'source_image_type' => (string)($info['image_type'] ?? $type),
            'effective_image_type' => (string)($info['effective_image_type'] ?? ($info['image_type'] ?? $type)),
            'final_equivalent_original' => !empty($info['final_equivalent_original']),
            'filename' => $this->assetFilename((string)($photo['original_filename'] ?? 'photo'), $type),
            'bytes' => (int)$info['bytes'],
            'sha256' => (string)$info['sha256'],
        ];
    }

    public function userCanViewPhoto(int $photoId, int $userId): bool
    {

        if ($photoId <= 0 || $userId <= 0) {
            return false;
        }

        $params = ['photo_id' => $photoId];
        $where = 'photo.id = :photo_id AND ' . $this->accessWhereSql($userId, $params, 'photo');

        return (bool)InterfaceDB::fetchColumn(
            'SELECT 1 FROM photos photo WHERE ' . $where . ' LIMIT 1',
            $params
        );
    }

    public function userCanViewImageType(int $photoId, int $userId, string $type): bool
    {

        if ($photoId <= 0 || $userId <= 0) {
            return false;
        }

        $type = strtolower(trim($type));
        if (!in_array($type, self::IMAGE_TYPES, true)) {
            return false;
        }

        if (!$this->userCanViewPhoto($photoId, $userId)) {
            return false;
        }

        if (in_array($type, self::DOWNLOAD_IMAGE_TYPES, true)) {
            return $this->userCanDownloadSingleJpeg($photoId, $userId);
        }

        return true;
    }

    public function userCanEditPhoto(int $photoId, int $userId): bool
    {

        if ($photoId <= 0 || $userId <= 0) {
            return false;
        }

        if ($this->isAdminUser($userId)) {
            return $this->userCanViewPhoto($photoId, $userId);
        }

        return (new SwallowtailEventAccessService())->userCanEditPhoto($userId, $photoId);
    }

    public function userCanDownloadSingleJpeg(int $photoId, int $userId): bool
    {

        if ($photoId <= 0 || $userId <= 0) {
            return false;
        }

        if ($this->isAdminUser($userId)) {
            return $this->userCanViewPhoto($photoId, $userId);
        }

        return (new SwallowtailEventAccessService())->userCanDownloadSingleJpegForPhoto($userId, $photoId);
    }

    private function photoImages(array $photo): array
    {

        $images = [];
        foreach (self::IMAGE_TYPES as $type) {
            $info = $this->assetService->assetForPhoto($photo, $type);
            if ($info !== null) {
                $images[$type] = [
                    'image_type' => $type,
                    'bytes' => (int)$info['bytes'],
                    'generated_at' => date('Y-m-d H:i:s', (int)$info['modified_at']),
                    'storage_path' => (string)$info['absolute_path'],
                ];
            }
        }

        return $images;
    }

    private function accessWhereSql(int $userId, array &$params, string $photoAlias): string
    {

        if ($this->isAdminUser($userId)) {
            return $photoAlias . ".upload_state = 'uploaded'";
        }

        $this->applyGranteeParams($userId, $params, 'access');
        $params['access_upload_user_id'] = $userId;

        return $photoAlias . ".upload_state = 'uploaded'
            AND (
                " . $photoAlias . ".uploaded_by_user_id = :access_upload_user_id
                OR EXISTS (
                    SELECT 1
                    FROM event_photos access_event_photo
                    INNER JOIN event_permissions access_permission
                        ON access_permission.event_id = access_event_photo.event_id
                    WHERE access_event_photo.photo_id = " . $photoAlias . ".id
                      AND access_permission.can_view = 1
                      AND " . $this->granteeWhereSql('access_permission', 'access') . "
                      AND (access_permission.expires_at IS NULL OR access_permission.expires_at > CURRENT_TIMESTAMP)
                    LIMIT 1
                )
            )";
    }

    private function effectiveCanEditSql(int $userId, array &$params, string $photoAlias): string
    {

        if ($this->isAdminUser($userId)) {
            return '1';
        }

        $this->applyGranteeParams($userId, $params, 'edit');

        return "COALESCE((
            SELECT MAX(edit_permission.can_edit)
            FROM event_photos edit_event_photo
            INNER JOIN event_permissions edit_permission
                ON edit_permission.event_id = edit_event_photo.event_id
            WHERE edit_event_photo.photo_id = " . $photoAlias . ".id
              AND edit_permission.can_view = 1
              AND edit_permission.can_edit = 1
              AND " . $this->granteeWhereSql('edit_permission', 'edit') . "
              AND (edit_permission.expires_at IS NULL OR edit_permission.expires_at > CURRENT_TIMESTAMP)
        ), 0)";
    }

    private function effectiveCanDownloadSingleJpegSql(int $userId, array &$params, string $photoAlias): string
    {

        if ($this->isAdminUser($userId)) {
            return '1';
        }

        $this->applyGranteeParams($userId, $params, 'single_download');

        return "COALESCE((
            SELECT MAX(single_download_permission.can_download_single_jpeg)
            FROM event_photos single_download_event_photo
            INNER JOIN event_permissions single_download_permission
                ON single_download_permission.event_id = single_download_event_photo.event_id
            WHERE single_download_event_photo.photo_id = " . $photoAlias . ".id
              AND single_download_permission.can_view = 1
              AND single_download_permission.can_download_single_jpeg = 1
              AND " . $this->granteeWhereSql('single_download_permission', 'single_download') . "
              AND (single_download_permission.expires_at IS NULL OR single_download_permission.expires_at > CURRENT_TIMESTAMP)
        ), 0)";
    }

    private function applyGranteeParams(int $userId, array &$params, string $prefix): void
    {

        $params[$prefix . '_grantee_user_id'] = $userId;
        $params[$prefix . '_grantee_role_id'] = $this->roleIdForUser($userId);
    }

    private function granteeWhereSql(string $permissionAlias, string $prefix): string
    {

        return "(
            (" . $permissionAlias . ".grantee_type = 'user' AND " . $permissionAlias . ".grantee_id = :" . $prefix . "_grantee_user_id)
            OR
            (" . $permissionAlias . ".grantee_type = 'role' AND " . $permissionAlias . ".grantee_id = :" . $prefix . "_grantee_role_id)
        )";
    }

    private function isAdminUser(int $userId): bool
    {

        try {
            return (new RoleAssignmentService())->isAdminUser($userId);
        } catch (Throwable) {
            return false;
        }
    }

    private function roleIdForUser(int $userId): int
    {

        try {
            return (new RoleRepository())->userRoleId($userId);
        } catch (Throwable) {
            return 0;
        }
    }

    private function normaliseGalleryPhotoRow(array $row): array
    {

        $row['id'] = (int)($row['id'] ?? 0);
        $row['original_bytes'] = (int)($row['original_bytes'] ?? 0);
        $row['uploaded_by_user_id'] = $this->nullableInt($row['uploaded_by_user_id'] ?? null);
        $row['duplicate_upload_count'] = (int)($row['duplicate_upload_count'] ?? 0);
        $row['preview_ready'] = $this->assetService->assetForPhoto($row, 'preview') !== null;
        $row['thumbnail_ready'] = !$row['preview_ready'] && $this->assetService->assetForPhoto($row, 'thumbnail') !== null;
        $row['effective_can_edit'] = (int)($row['effective_can_edit'] ?? 0) === 1;
        $row['effective_can_download_single_jpeg'] = (int)($row['effective_can_download_single_jpeg'] ?? 0) === 1;
        $downloadAsset = !empty($row['effective_can_download_single_jpeg'])
            ? $this->assetService->assetForPhotoWithFinalFallback($row, 'final')
            : null;
        $originalAsset = !empty($row['effective_can_download_single_jpeg'])
            ? $this->assetService->assetForPhoto($row, 'original')
            : null;
        $row['single_jpeg_ready'] = $downloadAsset !== null;
        $row['original_ready'] = $originalAsset !== null;
        $row['original_asset_sha256'] = is_array($originalAsset) ? (string)($originalAsset['sha256'] ?? '') : '';

        return $row;
    }

    private function normalisePhotoRow(array $row, bool $includeDerivativeStatus = true): array
    {

        $row['id'] = (int)($row['id'] ?? 0);
        $row['original_bytes'] = (int)($row['original_bytes'] ?? 0);
        $row['uploaded_by_user_id'] = $this->nullableInt($row['uploaded_by_user_id'] ?? null);
        $row['duplicate_upload_count'] = (int)($row['duplicate_upload_count'] ?? 0);
        if (!$includeDerivativeStatus) {
            $row['preview_ready'] = false;
            $row['thumbnail_ready'] = false;
            $row['original_ready'] = false;
            $row['embedded_ready'] = false;
            $row['final_ready'] = false;
            $row['jpeg_ready'] = false;
            $row['effective_can_edit'] = (int)($row['effective_can_edit'] ?? 0) === 1;

            return $row;
        }

        $row['preview_ready'] = $this->assetService->assetForPhoto($row, 'preview') !== null;
        $row['thumbnail_ready'] = $this->assetService->assetForPhoto($row, 'thumbnail') !== null;
        $row['original_ready'] = $this->assetService->assetForPhoto($row, 'original') !== null;
        $row['embedded_ready'] = $this->assetService->assetForPhoto($row, 'embedded') !== null;
        $row['final_ready'] = $this->assetService->assetForPhotoWithFinalFallback($row, 'final') !== null;
        $row['jpeg_ready'] = $row['final_ready'];
        $row['effective_can_edit'] = (int)($row['effective_can_edit'] ?? 0) === 1;

        return $row;
    }

    private function pagination(int $total, int $page, int $perPage): array
    {

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total_items' => $total,
            'total_pages' => $totalPages,
            'has_previous_page' => $page > 1,
            'has_next_page' => $page < $totalPages,
            'first_item' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'last_item' => min($total, $page * $perPage),
        ];
    }

    private function emptyPaginated(int $page, int $perPage): array
    {

        return [
            'rows' => [],
            'pagination' => $this->pagination(0, max(1, $page), max(1, $perPage)),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {

        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private function assetFilename(string $originalFilename, string $type): string
    {

        $basename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$basename) ?? 'photo';
        $basename = trim($basename, '.-');

        return ($basename !== '' ? $basename : 'photo') . '-' . $type . '.jpg';
    }
}
