/*
 * Add per-setting revisions to RawTherapee photo profile data.
 */

ALTER TABLE photo_profile_data
  ADD COLUMN revision int NOT NULL DEFAULT 0 AFTER photo_id,
  DROP INDEX uq_photo_profile_data_key,
  ADD UNIQUE KEY uq_photo_profile_data_key (photo_id, type, `key`, revision),
  ADD KEY idx_photo_profile_data_effective (photo_id, type, `key`, revision);
