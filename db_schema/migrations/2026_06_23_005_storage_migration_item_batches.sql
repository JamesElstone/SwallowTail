ALTER TABLE storage_migration_job_items
  MODIFY COLUMN destination_base_location varchar(1000) DEFAULT NULL;
