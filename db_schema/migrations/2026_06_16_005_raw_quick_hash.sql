/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

ALTER TABLE swallowtail_photos
  ADD COLUMN IF NOT EXISTS original_quick_hash char(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER original_sha256;

ALTER TABLE swallowtail_photos
  ADD KEY IF NOT EXISTS idx_swallowtail_photos_quick_hash (original_quick_hash, original_bytes);
