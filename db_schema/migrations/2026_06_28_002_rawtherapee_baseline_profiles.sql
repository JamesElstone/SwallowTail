CREATE TABLE IF NOT EXISTS rawtherapee_profile_data (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  profile_path varchar(1000) NOT NULL,
  relative_path varchar(1000) NOT NULL,
  display_label varchar(512) NOT NULL,
  profile_hash char(64) NOT NULL,
  profile_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  profile_mtime int(10) unsigned NOT NULL DEFAULT 0,
  profile_content longtext NOT NULL,
  is_available tinyint(1) NOT NULL DEFAULT 1,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  scanned_at datetime NOT NULL DEFAULT current_timestamp(),
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_rawtherapee_profile_path (profile_path),
  KEY idx_rawtherapee_profile_default (is_default, is_available, display_label),
  KEY idx_rawtherapee_profile_available (is_available, display_label),
  KEY idx_rawtherapee_profile_relative_path (relative_path(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @old_profile_table = CONCAT('rawthe', 'apee_profile_data');
SET @copy_old_profile_table_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = @old_profile_table
  ),
  CONCAT(
    'INSERT INTO rawtherapee_profile_data (',
    'id, profile_path, relative_path, display_label, profile_hash, profile_bytes, profile_mtime, profile_content, is_available, is_default, scanned_at, created_at, updated_at',
    ') SELECT id, profile_path, relative_path, display_label, profile_hash, profile_bytes, profile_mtime, profile_content, is_available, 0, scanned_at, created_at, updated_at FROM `',
    @old_profile_table,
    '` ON DUPLICATE KEY UPDATE ',
    'relative_path = VALUES(relative_path), display_label = VALUES(display_label), profile_hash = VALUES(profile_hash), ',
    'profile_bytes = VALUES(profile_bytes), profile_mtime = VALUES(profile_mtime), profile_content = VALUES(profile_content), ',
    'is_available = VALUES(is_available), scanned_at = VALUES(scanned_at), updated_at = VALUES(updated_at)'
  ),
  'SELECT 1'
);
PREPARE copy_old_profile_table_stmt FROM @copy_old_profile_table_sql;
EXECUTE copy_old_profile_table_stmt;
DEALLOCATE PREPARE copy_old_profile_table_stmt;

SET @drop_old_profile_table_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = @old_profile_table
  ),
  CONCAT('DROP TABLE `', @old_profile_table, '`'),
  'SELECT 1'
);
PREPARE drop_old_profile_table_stmt FROM @drop_old_profile_table_sql;
EXECUTE drop_old_profile_table_stmt;
DEALLOCATE PREPARE drop_old_profile_table_stmt;

SET @add_profile_default_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'rawtherapee_profile_data'
       AND column_name = 'is_default'
  ),
  'SELECT 1',
  'ALTER TABLE rawtherapee_profile_data ADD COLUMN is_default tinyint(1) NOT NULL DEFAULT 0 AFTER is_available'
);
PREPARE add_profile_default_stmt FROM @add_profile_default_sql;
EXECUTE add_profile_default_stmt;
DEALLOCATE PREPARE add_profile_default_stmt;

SET @add_profile_default_index_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'rawtherapee_profile_data'
       AND index_name = 'idx_rawtherapee_profile_default'
  ),
  'SELECT 1',
  'ALTER TABLE rawtherapee_profile_data ADD KEY idx_rawtherapee_profile_default (is_default, is_available, display_label)'
);
PREPARE add_profile_default_index_stmt FROM @add_profile_default_index_sql;
EXECUTE add_profile_default_index_stmt;
DEALLOCATE PREPARE add_profile_default_index_stmt;

SET @add_photo_baseline_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'photos'
       AND column_name = 'rawtherapee_profile_id'
  ),
  'SELECT 1',
  'ALTER TABLE photos ADD COLUMN rawtherapee_profile_id bigint(20) DEFAULT NULL AFTER conversion_state'
);
PREPARE add_photo_baseline_stmt FROM @add_photo_baseline_sql;
EXECUTE add_photo_baseline_stmt;
DEALLOCATE PREPARE add_photo_baseline_stmt;

SET @add_photo_baseline_index_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'photos'
       AND index_name = 'idx_photos_rawtherapee_profile'
  ),
  'SELECT 1',
  'ALTER TABLE photos ADD KEY idx_photos_rawtherapee_profile (rawtherapee_profile_id)'
);
PREPARE add_photo_baseline_index_stmt FROM @add_photo_baseline_index_sql;
EXECUTE add_photo_baseline_index_stmt;
DEALLOCATE PREPARE add_photo_baseline_index_stmt;

SET @add_photo_baseline_fk_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.table_constraints
     WHERE table_schema = DATABASE()
       AND table_name = 'photos'
       AND constraint_name = 'fk_photos_rawtherapee_profile'
  ),
  'SELECT 1',
  'ALTER TABLE photos ADD CONSTRAINT fk_photos_rawtherapee_profile FOREIGN KEY (rawtherapee_profile_id) REFERENCES rawtherapee_profile_data(id) ON DELETE SET NULL'
);
PREPARE add_photo_baseline_fk_stmt FROM @add_photo_baseline_fk_sql;
EXECUTE add_photo_baseline_fk_stmt;
DEALLOCATE PREPARE add_photo_baseline_fk_stmt;

SET @update_role_card_permissions_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'role_card_permissions'
  ),
  'UPDATE role_card_permissions SET card_key = ''rawtherapee_profiles'' WHERE card_key = CONCAT(''rawthe'', ''apee_profiles'')',
  'SELECT 1'
);
PREPARE update_role_card_permissions_stmt FROM @update_role_card_permissions_sql;
EXECUTE update_role_card_permissions_stmt;
DEALLOCATE PREPARE update_role_card_permissions_stmt;

SET @update_page_cards_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'page_cards'
  ),
  'UPDATE page_cards SET card_key = ''rawtherapee_profiles'' WHERE card_key = CONCAT(''rawthe'', ''apee_profiles'')',
  'SELECT 1'
);
PREPARE update_page_cards_stmt FROM @update_page_cards_sql;
EXECUTE update_page_cards_stmt;
DEALLOCATE PREPARE update_page_cards_stmt;

SET @old_sample_type = CONCAT('rawthe', 'apee_sample');
SET @jobs_enum_with_legacy_sql = CONCAT(
  'ALTER TABLE photo_conversion_jobs MODIFY image_type enum(''embedded'',''thumbnail'',''original'',''preview'',''final'',''',
  @old_sample_type,
  ''',''rawtherapee_sample'') NOT NULL'
);
PREPARE jobs_enum_with_legacy_stmt FROM @jobs_enum_with_legacy_sql;
EXECUTE jobs_enum_with_legacy_stmt;
DEALLOCATE PREPARE jobs_enum_with_legacy_stmt;

UPDATE photo_conversion_jobs
   SET image_type = 'rawtherapee_sample'
 WHERE image_type = @old_sample_type;

ALTER TABLE photo_conversion_jobs
  MODIFY image_type enum('embedded','thumbnail','original','preview','final','rawtherapee_sample') NOT NULL;

SET @assets_enum_with_legacy_sql = CONCAT(
  'ALTER TABLE photo_image_assets MODIFY image_type enum(''embedded'',''thumbnail'',''original'',''preview'',''final'',''',
  @old_sample_type,
  ''',''rawtherapee_sample'') NOT NULL'
);
PREPARE assets_enum_with_legacy_stmt FROM @assets_enum_with_legacy_sql;
EXECUTE assets_enum_with_legacy_stmt;
DEALLOCATE PREPARE assets_enum_with_legacy_stmt;

UPDATE photo_image_assets
   SET image_type = 'rawtherapee_sample'
 WHERE image_type = @old_sample_type;

ALTER TABLE photo_image_assets
  MODIFY image_type enum('embedded','thumbnail','original','preview','final','rawtherapee_sample') NOT NULL;
