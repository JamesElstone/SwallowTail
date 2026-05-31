/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

CREATE TABLE IF NOT EXISTS swallowtail_events (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  event_name varchar(255) NOT NULL,
  event_slug varchar(180) NOT NULL,
  description longtext DEFAULT NULL,
  starts_at datetime DEFAULT NULL,
  ends_at datetime DEFAULT NULL,
  created_by_user_id int(11) DEFAULT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_swallowtail_events_slug (event_slug),
  KEY idx_swallowtail_events_active_name (is_active, event_name),
  KEY idx_swallowtail_events_created_by (created_by_user_id),
  CONSTRAINT fk_swallowtail_events_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_swallowtail_events_name_not_blank CHECK (event_name <> ''),
  CONSTRAINT chk_swallowtail_events_slug_not_blank CHECK (event_slug <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swallowtail_api_upload_tokens (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  token_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  token_label varchar(255) NOT NULL,
  created_by_user_id int(11) DEFAULT NULL,
  can_upload_raw tinyint(1) NOT NULL DEFAULT 1,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  last_used_at datetime DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_swallowtail_upload_tokens_hash (token_hash),
  KEY idx_swallowtail_upload_tokens_active (is_active, expires_at),
  KEY idx_swallowtail_upload_tokens_created_by (created_by_user_id),
  CONSTRAINT fk_swallowtail_upload_tokens_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_swallowtail_upload_tokens_label_not_blank CHECK (token_label <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swallowtail_storage_locations (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  location_label varchar(255) NOT NULL,
  root_path varchar(1000) NOT NULL,
  reserve_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 100,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  is_read_only tinyint(1) NOT NULL DEFAULT 0,
  is_full tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_swallowtail_storage_locations_root (root_path),
  KEY idx_swallowtail_storage_locations_write_order (is_active, is_read_only, is_full, sort_order),
  CONSTRAINT chk_swallowtail_storage_locations_label_not_blank CHECK (location_label <> ''),
  CONSTRAINT chk_swallowtail_storage_locations_root_not_blank CHECK (root_path <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swallowtail_photos (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  original_filename varchar(255) NOT NULL,
  original_extension varchar(10) NOT NULL,
  original_bytes bigint(20) unsigned NOT NULL,
  original_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  original_storage_path varchar(500) NOT NULL,
  storage_location_id bigint(20) DEFAULT NULL,
  upload_state enum('uploaded','quarantined','removed') NOT NULL DEFAULT 'uploaded',
  conversion_state enum('pending','processing','ready','failed','not_required') NOT NULL DEFAULT 'pending',
  uploaded_by_user_id int(11) DEFAULT NULL,
  uploaded_via enum('web','api','worker','cli') NOT NULL DEFAULT 'api',
  upload_token_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_swallowtail_photos_sha256 (original_sha256),
  KEY idx_swallowtail_photos_upload_state (upload_state, created_at),
  KEY idx_swallowtail_photos_conversion_state (conversion_state, created_at),
  KEY idx_swallowtail_photos_uploaded_by (uploaded_by_user_id),
  KEY idx_swallowtail_photos_upload_token (upload_token_id),
  KEY idx_swallowtail_photos_storage_location (storage_location_id),
  CONSTRAINT fk_swallowtail_photos_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_photos_upload_token FOREIGN KEY (upload_token_id) REFERENCES swallowtail_api_upload_tokens (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_photos_storage_location FOREIGN KEY (storage_location_id) REFERENCES swallowtail_storage_locations (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_swallowtail_photos_filename_not_blank CHECK (original_filename <> ''),
  CONSTRAINT chk_swallowtail_photos_extension CHECK (original_extension IN ('cr2','cr3')),
  CONSTRAINT chk_swallowtail_photos_bytes_positive CHECK (original_bytes > 0),
  CONSTRAINT chk_swallowtail_photos_path_not_blank CHECK (original_storage_path <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swallowtail_event_photos (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  event_id bigint(20) NOT NULL,
  photo_id bigint(20) NOT NULL,
  assigned_by_user_id int(11) DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  assigned_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_swallowtail_event_photos_event_photo (event_id, photo_id),
  KEY idx_swallowtail_event_photos_photo (photo_id),
  KEY idx_swallowtail_event_photos_assigned_by (assigned_by_user_id),
  CONSTRAINT fk_swallowtail_event_photos_event FOREIGN KEY (event_id) REFERENCES swallowtail_events (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_event_photos_photo FOREIGN KEY (photo_id) REFERENCES swallowtail_photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_event_photos_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swallowtail_event_permissions (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  event_id bigint(20) NOT NULL,
  user_id int(11) NOT NULL,
  can_view tinyint(1) NOT NULL DEFAULT 0,
  can_download_single_jpeg tinyint(1) NOT NULL DEFAULT 0,
  can_download_event_zip tinyint(1) NOT NULL DEFAULT 0,
  can_download_all_accessible tinyint(1) NOT NULL DEFAULT 0,
  can_download_original_raw tinyint(1) NOT NULL DEFAULT 0,
  granted_by_user_id int(11) DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_swallowtail_event_permissions_event_user (event_id, user_id),
  KEY idx_swallowtail_event_permissions_user (user_id),
  KEY idx_swallowtail_event_permissions_granted_by (granted_by_user_id),
  CONSTRAINT fk_swallowtail_event_permissions_event FOREIGN KEY (event_id) REFERENCES swallowtail_events (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_event_permissions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_event_permissions_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swallowtail_photo_derivatives (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  photo_id bigint(20) NOT NULL,
  derivative_type enum('thumbnail','preview','jpeg') NOT NULL,
  storage_path varchar(500) NOT NULL,
  bytes bigint(20) unsigned NOT NULL,
  sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  generated_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_swallowtail_derivatives_photo_type (photo_id, derivative_type),
  CONSTRAINT fk_swallowtail_derivatives_photo FOREIGN KEY (photo_id) REFERENCES swallowtail_photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_swallowtail_derivatives_path_not_blank CHECK (storage_path <> ''),
  CONSTRAINT chk_swallowtail_derivatives_bytes_positive CHECK (bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swallowtail_photo_conversion_jobs (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  photo_id bigint(20) NOT NULL,
  job_type enum('raw_derivatives','rebuild_derivatives') NOT NULL,
  priority enum('low','normal','high') NOT NULL DEFAULT 'normal',
  status enum('queued','processing','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  available_at datetime NOT NULL DEFAULT current_timestamp(),
  locked_at datetime DEFAULT NULL,
  locked_by varchar(255) DEFAULT NULL,
  last_error longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_swallowtail_conversion_jobs_queue (status, available_at, priority, id),
  KEY idx_swallowtail_conversion_jobs_photo (photo_id),
  CONSTRAINT fk_swallowtail_conversion_jobs_photo FOREIGN KEY (photo_id) REFERENCES swallowtail_photos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swallowtail_photo_audit (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  photo_id bigint(20) NOT NULL,
  event_id bigint(20) DEFAULT NULL,
  actor_user_id int(11) DEFAULT NULL,
  upload_token_id bigint(20) DEFAULT NULL,
  action_type varchar(64) NOT NULL,
  details_json longtext DEFAULT NULL,
  device_id varchar(128) DEFAULT NULL,
  ip_address varchar(45) DEFAULT NULL,
  user_agent varchar(1000) DEFAULT NULL,
  occurred_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_swallowtail_photo_audit_photo_time (photo_id, occurred_at),
  KEY idx_swallowtail_photo_audit_event_time (event_id, occurred_at),
  KEY idx_swallowtail_photo_audit_actor_time (actor_user_id, occurred_at),
  KEY idx_swallowtail_photo_audit_token_time (upload_token_id, occurred_at),
  CONSTRAINT fk_swallowtail_photo_audit_photo FOREIGN KEY (photo_id) REFERENCES swallowtail_photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_photo_audit_event FOREIGN KEY (event_id) REFERENCES swallowtail_events (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_photo_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_swallowtail_photo_audit_upload_token FOREIGN KEY (upload_token_id) REFERENCES swallowtail_api_upload_tokens (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_swallowtail_photo_audit_action_not_blank CHECK (action_type <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
