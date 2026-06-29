/*
 * Store RawTherapee profile samples as separate asset variants per profile signature.
 *
 * This migration was briefly shipped under a misspelled filename. Keep the SQL
 * idempotent so installations that applied that file can safely record the
 * corrected migration name and continue to later enum repair migrations.
 */

SET @add_asset_variant_key_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'photo_image_assets'
       AND column_name = 'asset_variant_key'
  ),
  'SELECT 1',
  'ALTER TABLE photo_image_assets ADD COLUMN asset_variant_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER profile_signature'
);
PREPARE add_asset_variant_key_stmt FROM @add_asset_variant_key_sql;
EXECUTE add_asset_variant_key_stmt;
DEALLOCATE PREPARE add_asset_variant_key_stmt;

UPDATE photo_image_assets
SET asset_variant_key = profile_signature
WHERE CAST(image_type AS CHAR) IN (CONCAT('rawthe', 'apee_sample'), 'rawtherapee_sample')
  AND asset_variant_key = ''
  AND profile_signature REGEXP '^[0-9a-f]{64}$';

SET @drop_old_asset_unique_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'photo_image_assets'
       AND index_name = 'uq_photo_image_assets_photo_type'
  ),
  'ALTER TABLE photo_image_assets DROP INDEX uq_photo_image_assets_photo_type',
  'SELECT 1'
);
PREPARE drop_old_asset_unique_stmt FROM @drop_old_asset_unique_sql;
EXECUTE drop_old_asset_unique_stmt;
DEALLOCATE PREPARE drop_old_asset_unique_stmt;

SET @add_asset_variant_unique_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'photo_image_assets'
       AND index_name = 'uq_photo_image_assets_photo_type_variant'
  ),
  'SELECT 1',
  'ALTER TABLE photo_image_assets ADD UNIQUE KEY uq_photo_image_assets_photo_type_variant (photo_id, image_type, asset_variant_key)'
);
PREPARE add_asset_variant_unique_stmt FROM @add_asset_variant_unique_sql;
EXECUTE add_asset_variant_unique_stmt;
DEALLOCATE PREPARE add_asset_variant_unique_stmt;

SET @add_asset_variant_index_sql = IF(
  EXISTS(
    SELECT 1
      FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'photo_image_assets'
       AND index_name = 'idx_photo_image_assets_variant'
  ),
  'SELECT 1',
  'ALTER TABLE photo_image_assets ADD KEY idx_photo_image_assets_variant (photo_id, image_type, asset_variant_key, profile_signature)'
);
PREPARE add_asset_variant_index_stmt FROM @add_asset_variant_index_sql;
EXECUTE add_asset_variant_index_stmt;
DEALLOCATE PREPARE add_asset_variant_index_stmt;
