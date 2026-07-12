ALTER TABLE storage_migration_jobs
  ADD COLUMN IF NOT EXISTS migration_mode enum('evacuate','rebalance') NOT NULL DEFAULT 'evacuate' AFTER requested_by_user_id,
  ADD COLUMN IF NOT EXISTS target_free_percent decimal(6,3) DEFAULT NULL AFTER migration_mode,
  ADD COLUMN IF NOT EXISTS planned_bytes bigint(20) NOT NULL DEFAULT 0 AFTER total_photos,
  ADD COLUMN IF NOT EXISTS moved_bytes bigint(20) NOT NULL DEFAULT 0 AFTER moved_photos;

ALTER TABLE storage_migration_job_items
  MODIFY COLUMN status enum('queued','processing','succeeded','failed','skipped') NOT NULL DEFAULT 'queued',
  ADD COLUMN IF NOT EXISTS planned_bytes bigint(20) NOT NULL DEFAULT 0 AFTER file_count,
  ADD COLUMN IF NOT EXISTS moved_bytes bigint(20) NOT NULL DEFAULT 0 AFTER planned_bytes;

CREATE TABLE IF NOT EXISTS photo_operation_leases (
  photo_id bigint(20) NOT NULL,
  operation_type enum('conversion','migration') NOT NULL,
  owner_token varchar(255) NOT NULL,
  acquired_at datetime NOT NULL DEFAULT current_timestamp(),
  heartbeat_at datetime NOT NULL DEFAULT current_timestamp(),
  expires_at datetime NOT NULL,
  PRIMARY KEY (photo_id),
  KEY idx_photo_operation_leases_expiry (expires_at),
  CONSTRAINT fk_photo_operation_leases_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_photo_operation_leases_owner_not_blank CHECK (owner_token <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
