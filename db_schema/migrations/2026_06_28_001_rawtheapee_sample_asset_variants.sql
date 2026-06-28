/*
 * Store RawTheapee profile samples as separate asset variants per profile signature.
 */

ALTER TABLE photo_image_assets
  ADD COLUMN asset_variant_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER profile_signature;

UPDATE photo_image_assets
SET asset_variant_key = profile_signature
WHERE image_type = 'rawtheapee_sample'
  AND profile_signature REGEXP '^[0-9a-f]{64}$';

ALTER TABLE photo_image_assets
  DROP INDEX uq_photo_image_assets_photo_type,
  ADD UNIQUE KEY uq_photo_image_assets_photo_type_variant (photo_id, image_type, asset_variant_key),
  ADD KEY idx_photo_image_assets_variant (photo_id, image_type, asset_variant_key, profile_signature);
