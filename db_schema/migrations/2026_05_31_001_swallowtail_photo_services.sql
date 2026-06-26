/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

CREATE TABLE IF NOT EXISTS events (
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
  UNIQUE KEY uq_events_slug (event_slug),
  KEY idx_events_active_name (is_active, event_name),
  KEY idx_events_created_by (created_by_user_id),
  CONSTRAINT fk_events_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_events_name_not_blank CHECK (event_name <> ''),
  CONSTRAINT chk_events_slug_not_blank CHECK (event_slug <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_upload_tokens (
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
  UNIQUE KEY uq_upload_tokens_hash (token_hash),
  KEY idx_upload_tokens_active (is_active, expires_at),
  KEY idx_upload_tokens_created_by (created_by_user_id),
  CONSTRAINT fk_upload_tokens_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_upload_tokens_label_not_blank CHECK (token_label <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_location_properties (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  storage_base_location varchar(1000) NOT NULL,
  is_excluded tinyint(1) NOT NULL DEFAULT 0,
  is_zfs tinyint(1) NOT NULL DEFAULT 0,
  dataset_name varchar(1000) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_storage_location_properties_base (storage_base_location),
  KEY idx_storage_location_properties_excluded (is_excluded),
  KEY idx_storage_location_properties_zfs (is_zfs, storage_base_location(191)),
  CONSTRAINT chk_storage_location_properties_base_not_blank CHECK (storage_base_location <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photos (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  original_filename varchar(255) NOT NULL,
  original_extension varchar(10) NOT NULL,
  original_bytes bigint(20) unsigned NOT NULL,
  original_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  original_quick_hash char(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  storage_base_location varchar(1000) NOT NULL,
  upload_state enum('uploaded','quarantined','removed') NOT NULL DEFAULT 'uploaded',
  conversion_state enum('pending','processing','ready','failed','not_required') NOT NULL DEFAULT 'pending',
  uploaded_by_user_id int(11) DEFAULT NULL,
  uploaded_via enum('web','api','worker','cli') NOT NULL DEFAULT 'api',
  upload_token_id bigint(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_photos_sha256 (original_sha256),
  KEY idx_photos_quick_hash (original_quick_hash, original_bytes),
  KEY idx_photos_upload_state (upload_state, created_at),
  KEY idx_photos_conversion_state (conversion_state, created_at),
  KEY idx_photos_uploaded_by (uploaded_by_user_id),
  KEY idx_photos_upload_token (upload_token_id),
  KEY idx_photos_storage_base (storage_base_location(191)),
  CONSTRAINT fk_photos_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_photos_upload_token FOREIGN KEY (upload_token_id) REFERENCES api_upload_tokens (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_photos_filename_not_blank CHECK (original_filename <> ''),
  CONSTRAINT chk_photos_extension CHECK (original_extension = 'cr2'),
  CONSTRAINT chk_photos_bytes_positive CHECK (original_bytes > 0),
  CONSTRAINT chk_photos_storage_base_not_blank CHECK (storage_base_location <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_photos (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  event_id bigint(20) NOT NULL,
  photo_id bigint(20) NOT NULL,
  assigned_by_user_id int(11) DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  assigned_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_photos_event_photo (event_id, photo_id),
  KEY idx_event_photos_photo (photo_id),
  KEY idx_event_photos_assigned_by (assigned_by_user_id),
  CONSTRAINT fk_event_photos_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_event_photos_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_event_photos_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_permissions (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  event_id bigint(20) NOT NULL,
  grantee_type enum('user','role') NOT NULL DEFAULT 'user',
  grantee_id int(11) NOT NULL,
  can_view tinyint(1) NOT NULL DEFAULT 0,
  can_edit tinyint(1) NOT NULL DEFAULT 0,
  can_download_single_jpeg tinyint(1) NOT NULL DEFAULT 0,
  can_download_event_zip tinyint(1) NOT NULL DEFAULT 0,
  can_download_all_accessible tinyint(1) NOT NULL DEFAULT 0,
  can_download_original_raw tinyint(1) NOT NULL DEFAULT 0,
  granted_by_user_id int(11) DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_permissions_event_grantee (event_id, grantee_type, grantee_id),
  KEY idx_event_permissions_grantee (grantee_type, grantee_id, event_id),
  KEY idx_event_permissions_granted_by (granted_by_user_id),
  CONSTRAINT fk_event_permissions_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_event_permissions_granted_by FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photo_conversion_jobs (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  photo_id bigint(20) NOT NULL,
  job_type enum('image') NOT NULL DEFAULT 'image',
  image_type enum('embedded','thumbnail','original','preview','final') NOT NULL,
  input_path varchar(1000) NOT NULL,
  profile_path varchar(1000) DEFAULT NULL,
  output_path varchar(1000) NOT NULL,
  output_width int(10) unsigned DEFAULT NULL,
  output_height int(10) unsigned DEFAULT NULL,
  profile_version int(10) unsigned NOT NULL DEFAULT 1,
  requested_by_user_id int(11) DEFAULT NULL,
  priority enum('low','normal','high') NOT NULL DEFAULT 'normal',
  status enum('queued','processing','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  available_at datetime NOT NULL DEFAULT current_timestamp(),
  redis_notified_at datetime DEFAULT NULL,
  started_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  duration_seconds decimal(10,3) DEFAULT NULL,
  locked_at datetime DEFAULT NULL,
  locked_by varchar(255) DEFAULT NULL,
  last_error longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_conversion_jobs_unique_lookup (photo_id, image_type, profile_version, status),
  KEY idx_conversion_jobs_priority (status, image_type, priority, available_at, id),
  KEY idx_conversion_jobs_requested_by (requested_by_user_id),
  CONSTRAINT fk_conversion_jobs_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_conversion_jobs_paths CHECK (input_path <> '' AND output_path <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_upload_token_cidrs (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  token_id bigint(20) NOT NULL,
  cidr varchar(64) NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_upload_token_cidrs_token_cidr (token_id, cidr),
  CONSTRAINT fk_upload_token_cidrs_token FOREIGN KEY (token_id) REFERENCES api_upload_tokens (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_upload_token_cidrs_not_blank CHECK (cidr <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photo_audit (
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
  KEY idx_photo_audit_photo_time (photo_id, occurred_at),
  KEY idx_photo_audit_event_time (event_id, occurred_at),
  KEY idx_photo_audit_actor_time (actor_user_id, occurred_at),
  KEY idx_photo_audit_token_time (upload_token_id, occurred_at),
  CONSTRAINT fk_photo_audit_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_photo_audit_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_photo_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_photo_audit_upload_token FOREIGN KEY (upload_token_id) REFERENCES api_upload_tokens (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_photo_audit_action_not_blank CHECK (action_type <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
