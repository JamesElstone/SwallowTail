/*
 * Remove unused conversion job fields.
 *
 * Output dimensions are now represented by generated PP3 profile content and
 * recorded on photo_image_assets after render. Redis wakeups are transient queue
 * messages, so photo_conversion_jobs no longer stores a notification timestamp.
 */

ALTER TABLE photo_conversion_jobs
  DROP COLUMN IF EXISTS output_width,
  DROP COLUMN IF EXISTS output_height,
  DROP COLUMN IF EXISTS redis_notified_at;
