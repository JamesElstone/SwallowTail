/*
 * Track the effective profile used by profile-backed conversion jobs.
 */

ALTER TABLE photo_conversion_jobs
  ADD COLUMN profile_signature char(64) DEFAULT NULL AFTER profile_version,
  ADD KEY idx_conversion_jobs_profile_signature (photo_id, image_type, profile_signature, status);
