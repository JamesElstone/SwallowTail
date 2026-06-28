/*
 * Repair RawTherapee sample image-type enum spelling in live schemas.
 */

ALTER TABLE photo_conversion_jobs
  MODIFY image_type enum('embedded','thumbnail','original','preview','final','rawtheapee_sample','rawtherapee_sample') NOT NULL;

UPDATE photo_conversion_jobs
   SET image_type = 'rawtherapee_sample'
 WHERE image_type = 'rawtheapee_sample';

ALTER TABLE photo_conversion_jobs
  MODIFY image_type enum('embedded','thumbnail','original','preview','final','rawtherapee_sample') NOT NULL;

ALTER TABLE photo_image_assets
  MODIFY image_type enum('embedded','thumbnail','original','preview','final','rawtheapee_sample','rawtherapee_sample') NOT NULL;

UPDATE photo_image_assets
   SET image_type = 'rawtherapee_sample'
 WHERE image_type = 'rawtheapee_sample';

ALTER TABLE photo_image_assets
  MODIFY image_type enum('embedded','thumbnail','original','preview','final','rawtherapee_sample') NOT NULL;
