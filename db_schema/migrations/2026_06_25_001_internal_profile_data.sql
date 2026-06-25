/*
 * Store reusable RawTherapee profile overlays for internal conversion profiles.
 */

CREATE TABLE IF NOT EXISTS internal_profile_data (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  image_type varchar(32) NOT NULL,
  profile_name varchar(64) NOT NULL,
  `order` int NOT NULL,
  type varchar(32) NOT NULL,
  `key` varchar(191) NOT NULL,
  value longtext DEFAULT NULL,
  value_type enum('null','bool','int','float','string') NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_internal_profile_data_key (image_type, profile_name, type, `key`),
  KEY idx_internal_profile_data_order (image_type, `order`, profile_name),
  KEY idx_internal_profile_data_lookup (type, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO internal_profile_data (
  image_type, profile_name, `order`, type, `key`, value, value_type
) VALUES
  ('preview', 'preview-performance', 1, 'RAW', 'CA', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'RAW', 'DarkFrameAuto', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'RAW', 'FlatFieldAutoSelect', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'RAW', 'FlatFieldFromMetaData', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'RAW', 'HotPixelFilter', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'RAW', 'DeadPixelFilter', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'RAW Bayer', 'Method', 'fast', 'string'),
  ('preview', 'preview-performance', 1, 'RAW Bayer', 'Border', '0', 'int'),
  ('preview', 'preview-performance', 1, 'RAW Bayer', 'CcSteps', '0', 'int'),
  ('preview', 'preview-performance', 1, 'RAW Bayer', 'LineDenoise', '0', 'int'),
  ('preview', 'preview-performance', 1, 'RAW Bayer', 'GreenEqThreshold', '0', 'int'),
  ('preview', 'preview-performance', 1, 'RAW Bayer', 'PDAFLinesFilter', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'RAW X-Trans', 'Method', 'fast', 'string'),
  ('preview', 'preview-performance', 1, 'RAW X-Trans', 'CcSteps', '0', 'int'),
  ('preview', 'preview-performance', 1, 'LensProfile', 'UseDistortion', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'LensProfile', 'UseVignette', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'LensProfile', 'UseCA', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'CACorrection', 'Auto', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Impulse Denoising', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Directional Pyramid Denoising', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Sharpening', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'PostDemosaicSharpening', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'PostResizeSharpening', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Defringing', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Locallab', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Wavelet', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Retinex', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'FattalToneMapping', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Film Simulation', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Dehaze', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'EPD', 'Enabled', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Color Management', 'ApplyLookTable', 'false', 'bool'),
  ('preview', 'preview-performance', 1, 'Color Management', 'ApplyHueSatMap', 'false', 'bool'),
  ('preview', 'preview-resize', 2, 'Resize', 'Enabled', 'true', 'bool'),
  ('preview', 'preview-resize', 2, 'Resize', 'Scale', '1', 'int'),
  ('preview', 'preview-resize', 2, 'Resize', 'AppliesTo', 'Cropped area', 'string'),
  ('preview', 'preview-resize', 2, 'Resize', 'Method', 'Lanczos', 'string'),
  ('preview', 'preview-resize', 2, 'Resize', 'DataSpecified', '4', 'int'),
  ('preview', 'preview-resize', 2, 'Resize', 'Width', '820', 'int'),
  ('preview', 'preview-resize', 2, 'Resize', 'Height', '820', 'int'),
  ('preview', 'preview-resize', 2, 'Resize', 'LongEdge', '820', 'int'),
  ('preview', 'preview-resize', 2, 'Resize', 'ShortEdge', '820', 'int'),
  ('preview', 'preview-resize', 2, 'Resize', 'AllowUpscaling', 'false', 'bool')
ON DUPLICATE KEY UPDATE
  `order` = VALUES(`order`),
  value = VALUES(value),
  value_type = VALUES(value_type),
  updated_at = CURRENT_TIMESTAMP;
