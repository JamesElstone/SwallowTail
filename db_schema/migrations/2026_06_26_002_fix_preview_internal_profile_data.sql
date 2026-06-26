/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

/*
 * Normalize legacy preview internal profile names and resize previews by the
 * shortest side.
 */

INSERT INTO internal_profile_data (
  image_type, profile_name, `order`, type, `key`, value, value_type
)
SELECT
  image_type,
  CASE profile_name
    WHEN 'preview-performance' THEN 'performance'
    WHEN 'preview-resize' THEN 'resize'
  END AS profile_name,
  `order`,
  type,
  `key`,
  value,
  value_type
FROM internal_profile_data
WHERE image_type = 'preview'
  AND profile_name IN ('preview-performance', 'preview-resize')
ON DUPLICATE KEY UPDATE
  `order` = VALUES(`order`),
  value = VALUES(value),
  value_type = VALUES(value_type),
  updated_at = CURRENT_TIMESTAMP;

DELETE FROM internal_profile_data
WHERE image_type = 'preview'
  AND profile_name IN ('preview-performance', 'preview-resize');

INSERT INTO internal_profile_data (
  image_type, profile_name, `order`, type, `key`, value, value_type
) VALUES
  ('preview', 'resize', 2, 'Resize', 'Enabled', 'true', 'bool'),
  ('preview', 'resize', 2, 'Resize', 'Scale', '1', 'int'),
  ('preview', 'resize', 2, 'Resize', 'AppliesTo', 'Cropped area', 'string'),
  ('preview', 'resize', 2, 'Resize', 'Method', 'Lanczos', 'string'),
  ('preview', 'resize', 2, 'Resize', 'DataSpecified', '5', 'int'),
  ('preview', 'resize', 2, 'Resize', 'Width', '0', 'int'),
  ('preview', 'resize', 2, 'Resize', 'Height', '0', 'int'),
  ('preview', 'resize', 2, 'Resize', 'LongEdge', '0', 'int'),
  ('preview', 'resize', 2, 'Resize', 'ShortEdge', '820', 'int'),
  ('preview', 'resize', 2, 'Resize', 'AllowUpscaling', 'false', 'bool')
ON DUPLICATE KEY UPDATE
  `order` = VALUES(`order`),
  value = VALUES(value),
  value_type = VALUES(value_type),
  updated_at = CURRENT_TIMESTAMP;
