DROP INDEX IF EXISTS idx_photos_quick_hash ON photos;

ALTER TABLE photos
  DROP COLUMN IF EXISTS original_quick_hash;
