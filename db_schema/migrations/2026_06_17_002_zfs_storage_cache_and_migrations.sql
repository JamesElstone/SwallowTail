ALTER TABLE storage_location_properties
  ADD COLUMN IF NOT EXISTS is_zfs tinyint(1) NOT NULL DEFAULT 0 AFTER is_excluded,
  ADD COLUMN IF NOT EXISTS dataset_name varchar(1000) DEFAULT NULL AFTER is_zfs,
  ADD KEY IF NOT EXISTS idx_storage_location_properties_zfs (is_zfs, storage_base_location(191));

CREATE TABLE IF NOT EXISTS storage_migration_jobs (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  source_base_location varchar(1000) NOT NULL,
  destination_base_location varchar(1000) DEFAULT NULL,
  zpool_name varchar(255) DEFAULT NULL,
  dataset_name varchar(1000) DEFAULT NULL,
  requested_by_user_id int(11) DEFAULT NULL,
  status enum('queued','processing','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
  total_photos int(11) NOT NULL DEFAULT 0,
  moved_photos int(11) NOT NULL DEFAULT 0,
  last_error longtext DEFAULT NULL,
  started_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_storage_migration_jobs_status_created (status, created_at),
  KEY idx_storage_migration_jobs_source (source_base_location(191)),
  KEY idx_storage_migration_jobs_requested_by (requested_by_user_id),
  CONSTRAINT fk_storage_migration_jobs_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT chk_storage_migration_jobs_source_not_blank CHECK (source_base_location <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_migration_job_items (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  job_id bigint(20) NOT NULL,
  photo_id bigint(20) NOT NULL,
  source_base_location varchar(1000) NOT NULL,
  destination_base_location varchar(1000) NOT NULL,
  status enum('queued','processing','succeeded','failed') NOT NULL DEFAULT 'queued',
  file_count int(11) NOT NULL DEFAULT 0,
  last_error longtext DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_storage_migration_job_items_job_status (job_id, status),
  KEY idx_storage_migration_job_items_photo (photo_id),
  CONSTRAINT fk_storage_migration_job_items_job FOREIGN KEY (job_id) REFERENCES storage_migration_jobs (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_storage_migration_job_items_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_storage_migration_job_items_source_not_blank CHECK (source_base_location <> ''),
  CONSTRAINT chk_storage_migration_job_items_destination_not_blank CHECK (destination_base_location <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
