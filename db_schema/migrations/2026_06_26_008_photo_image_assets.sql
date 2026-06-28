/*
 * Move current image asset metadata into SQL and remove profile render versions.
 */

CREATE TABLE IF NOT EXISTS photo_image_assets (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  photo_id bigint(20) NOT NULL,
  image_type enum('embedded','thumbnail','original','preview','final','rawtherapee_sample') NOT NULL,
  sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  bytes bigint(20) unsigned NOT NULL,
  modified_at int(10) unsigned NOT NULL DEFAULT 0,
  width int(10) unsigned DEFAULT NULL,
  height int(10) unsigned DEFAULT NULL,
  profile_signature char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  conversion_job_id bigint(20) DEFAULT NULL,
  generated_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_photo_image_assets_photo_type (photo_id, image_type),
  KEY idx_photo_image_assets_signature (photo_id, image_type, profile_signature),
  KEY idx_photo_image_assets_job (conversion_job_id),
  CONSTRAINT fk_photo_image_assets_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_photo_image_assets_job FOREIGN KEY (conversion_job_id) REFERENCES photo_conversion_jobs (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_photo_image_assets_bytes CHECK (bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE photo_conversion_jobs
  DROP INDEX idx_conversion_jobs_unique_lookup,
  ADD KEY idx_conversion_jobs_unique_lookup (photo_id, image_type, status),
  DROP COLUMN profile_version;
