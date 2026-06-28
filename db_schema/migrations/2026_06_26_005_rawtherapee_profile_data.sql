/*
 * Store recursively discovered RawTherapee PP3 profiles for UI sampling.
 */

CREATE TABLE IF NOT EXISTS rawtherapee_profile_data (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  profile_path varchar(1000) NOT NULL,
  relative_path varchar(1000) NOT NULL,
  display_label varchar(512) NOT NULL,
  profile_hash char(64) NOT NULL,
  profile_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  profile_mtime int(10) unsigned NOT NULL DEFAULT 0,
  profile_content longtext NOT NULL,
  is_available tinyint(1) NOT NULL DEFAULT 1,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  scanned_at datetime NOT NULL DEFAULT current_timestamp(),
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_rawtherapee_profile_path (profile_path),
  KEY idx_rawtherapee_profile_default (is_default, is_available, display_label),
  KEY idx_rawtherapee_profile_available (is_available, display_label),
  KEY idx_rawtherapee_profile_relative_path (relative_path(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE photo_conversion_jobs
  MODIFY image_type enum('embedded','thumbnail','original','preview','final','rawtherapee_sample') NOT NULL;
