/*
 * Normalize photo metadata storage and force metadata reprocessing.
 */

DROP TABLE IF EXISTS photo_metadata_property;
DROP TABLE IF EXISTS photo_metadata;

CREATE TABLE IF NOT EXISTS photo_metadata (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  photo_id bigint(20) NOT NULL,
  status enum('ready','deferred','failed') NOT NULL DEFAULT 'deferred',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime DEFAULT NULL,
  last_error longtext DEFAULT NULL,
  captured_at_local datetime DEFAULT NULL,
  camera_timezone_city_code int(11) DEFAULT NULL,
  camera_timezone_city_label varchar(100) DEFAULT NULL,
  camera_daylight_savings_minutes int(11) DEFAULT NULL,
  captured_at_utc datetime DEFAULT NULL,
  camera_make varchar(100) DEFAULT NULL,
  camera_model varchar(150) DEFAULT NULL,
  camera_serial varchar(100) DEFAULT NULL,
  lens_model varchar(255) DEFAULT NULL,
  lens_serial varchar(100) DEFAULT NULL,
  iso int(10) unsigned DEFAULT NULL,
  shutter_speed varchar(50) DEFAULT NULL,
  aperture decimal(8,3) DEFAULT NULL,
  focal_length_mm decimal(8,3) DEFAULT NULL,
  pixel_width int(10) unsigned DEFAULT NULL,
  pixel_height int(10) unsigned DEFAULT NULL,
  orientation int(10) unsigned DEFAULT NULL,
  extracted_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_photo_metadata_photo (photo_id),
  KEY idx_photo_metadata_status_next (status, next_attempt_at, photo_id),
  CONSTRAINT fk_photo_metadata_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photo_metadata_property (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  photo_id bigint(20) NOT NULL,
  type varchar(32) NOT NULL,
  `key` varchar(191) NOT NULL,
  value longtext DEFAULT NULL,
  value_type enum('null','bool','int','float','string') NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_photo_metadata_property_tag (photo_id, type, `key`),
  KEY idx_photo_metadata_property_photo (photo_id),
  KEY idx_photo_metadata_property_lookup (type, `key`),
  CONSTRAINT fk_photo_metadata_property_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
