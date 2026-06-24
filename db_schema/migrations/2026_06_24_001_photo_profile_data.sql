/*
 * Store RawTherapee baseline processing profile data for picture editing.
 */

CREATE TABLE IF NOT EXISTS photo_profile_data (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  photo_id bigint(20) NOT NULL,
  type varchar(32) NOT NULL,
  `key` varchar(191) NOT NULL,
  value longtext DEFAULT NULL,
  value_type enum('null','bool','int','float','string') NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_photo_profile_data_key (photo_id, type, `key`),
  KEY idx_photo_profile_data_photo (photo_id),
  KEY idx_photo_profile_data_lookup (type, `key`),
  CONSTRAINT fk_photo_profile_data_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
