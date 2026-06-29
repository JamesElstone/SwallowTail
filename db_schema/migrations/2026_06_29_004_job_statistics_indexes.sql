/*
 * Add indexes used by the jobs statistics dashboard.
 */

ALTER TABLE photo_profile_data
  ADD KEY IF NOT EXISTS idx_photo_profile_data_status_value (type, `key`, value(191));

ALTER TABLE storage_migration_job_items
  ADD KEY IF NOT EXISTS idx_storage_migration_job_items_status (status);
