/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

CREATE TABLE IF NOT EXISTS swallowtail_api_upload_token_cidrs (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  token_id bigint(20) NOT NULL,
  cidr varchar(64) NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_swallowtail_upload_token_cidr (token_id, cidr),
  KEY idx_swallowtail_upload_token_cidrs_token (token_id),
  CONSTRAINT fk_swallowtail_upload_token_cidrs_token FOREIGN KEY (token_id) REFERENCES swallowtail_api_upload_tokens (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_swallowtail_upload_token_cidrs_not_blank CHECK (cidr <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE swallowtail_photos
  DROP CONSTRAINT IF EXISTS chk_swallowtail_photos_extension;

ALTER TABLE swallowtail_photos
  ADD CONSTRAINT chk_swallowtail_photos_extension CHECK (original_extension = 'cr2');
