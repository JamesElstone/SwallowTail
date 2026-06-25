/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

ALTER TABLE photo_conversion_jobs
  MODIFY image_type enum('embedded','thumbnail','original','preview','final') NOT NULL;

INSERT INTO internal_profile_data (
  image_type, profile_name, `order`, type, `key`, value, value_type
) VALUES
  ('thumbnail', 'resize', 1, 'Resize', 'Enabled', 'true', 'bool'),
  ('thumbnail', 'resize', 1, 'Resize', 'Scale', '1', 'int'),
  ('thumbnail', 'resize', 1, 'Resize', 'AppliesTo', 'Cropped area', 'string'),
  ('thumbnail', 'resize', 1, 'Resize', 'Method', 'Lanczos', 'string'),
  ('thumbnail', 'resize', 1, 'Resize', 'DataSpecified', '5', 'int'),
  ('thumbnail', 'resize', 1, 'Resize', 'Width', '0', 'int'),
  ('thumbnail', 'resize', 1, 'Resize', 'Height', '0', 'int'),
  ('thumbnail', 'resize', 1, 'Resize', 'LongEdge', '0', 'int'),
  ('thumbnail', 'resize', 1, 'Resize', 'ShortEdge', '180', 'int'),
  ('thumbnail', 'resize', 1, 'Resize', 'AllowUpscaling', 'false', 'bool')
ON DUPLICATE KEY UPDATE
  `order` = VALUES(`order`),
  value = VALUES(value),
  value_type = VALUES(value_type),
  updated_at = CURRENT_TIMESTAMP;
