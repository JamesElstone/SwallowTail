/*
 * Replace legacy conversion job types with preview/final.
 */

ALTER TABLE photo_conversion_jobs
  MODIFY image_type enum('embedded','original','preview','final') NOT NULL;
