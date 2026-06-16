/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

ALTER TABLE swallowtail_photo_conversion_jobs
  ADD COLUMN IF NOT EXISTS duration_seconds decimal(10,3) DEFAULT NULL AFTER completed_at;
