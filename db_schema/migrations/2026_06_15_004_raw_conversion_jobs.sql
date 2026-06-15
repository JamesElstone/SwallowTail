/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

ALTER TABLE swallowtail_photo_derivatives
  MODIFY COLUMN derivative_type enum('original_jpeg','thumbnail','preview','jpeg') NOT NULL;

ALTER TABLE swallowtail_photo_derivatives
  ADD COLUMN IF NOT EXISTS storage_location_id bigint(20) DEFAULT NULL AFTER storage_path;

ALTER TABLE swallowtail_photo_derivatives
  ADD KEY IF NOT EXISTS idx_swallowtail_derivatives_storage_location (storage_location_id);

ALTER TABLE swallowtail_photo_derivatives
  ADD CONSTRAINT IF NOT EXISTS fk_swallowtail_derivatives_storage_location
    FOREIGN KEY (storage_location_id)
    REFERENCES swallowtail_storage_locations (id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

ALTER TABLE swallowtail_photo_conversion_jobs
  MODIFY COLUMN job_type enum('raw_derivatives','rebuild_derivatives','derivative') NOT NULL;

ALTER TABLE swallowtail_photo_conversion_jobs
  ADD COLUMN IF NOT EXISTS derivative_type enum('original_jpeg','thumbnail','preview','jpeg') DEFAULT NULL AFTER job_type,
  ADD COLUMN IF NOT EXISTS input_path varchar(1000) DEFAULT NULL AFTER derivative_type,
  ADD COLUMN IF NOT EXISTS pp3_path varchar(1000) DEFAULT NULL AFTER input_path,
  ADD COLUMN IF NOT EXISTS output_path varchar(1000) DEFAULT NULL AFTER pp3_path,
  ADD COLUMN IF NOT EXISTS output_storage_path varchar(500) DEFAULT NULL AFTER output_path,
  ADD COLUMN IF NOT EXISTS output_storage_location_id bigint(20) DEFAULT NULL AFTER output_storage_path,
  ADD COLUMN IF NOT EXISTS profile_version int(10) unsigned NOT NULL DEFAULT 1 AFTER output_storage_location_id,
  ADD COLUMN IF NOT EXISTS requested_by_user_id int(11) DEFAULT NULL AFTER profile_version,
  ADD COLUMN IF NOT EXISTS redis_notified_at datetime DEFAULT NULL AFTER requested_by_user_id,
  ADD COLUMN IF NOT EXISTS started_at datetime DEFAULT NULL AFTER redis_notified_at,
  ADD COLUMN IF NOT EXISTS completed_at datetime DEFAULT NULL AFTER started_at;

ALTER TABLE swallowtail_photo_conversion_jobs
  ADD KEY IF NOT EXISTS idx_swallowtail_conversion_jobs_derivative_unique_lookup (
    photo_id,
    derivative_type,
    profile_version,
    status
  ),
  ADD KEY IF NOT EXISTS idx_swallowtail_conversion_jobs_derivative_priority (
    status,
    derivative_type,
    priority,
    available_at,
    id
  ),
  ADD KEY IF NOT EXISTS idx_swallowtail_conversion_jobs_output_storage_location (output_storage_location_id),
  ADD KEY IF NOT EXISTS idx_swallowtail_conversion_jobs_requested_by (requested_by_user_id);

ALTER TABLE swallowtail_photo_conversion_jobs
  ADD CONSTRAINT IF NOT EXISTS fk_swallowtail_conversion_jobs_output_storage_location
    FOREIGN KEY (output_storage_location_id)
    REFERENCES swallowtail_storage_locations (id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  ADD CONSTRAINT IF NOT EXISTS fk_swallowtail_conversion_jobs_requested_by
    FOREIGN KEY (requested_by_user_id)
    REFERENCES users (id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
