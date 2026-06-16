/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

ALTER TABLE swallowtail_photo_conversion_jobs
  ADD COLUMN IF NOT EXISTS output_width int(10) unsigned DEFAULT NULL AFTER output_storage_location_id,
  ADD COLUMN IF NOT EXISTS output_height int(10) unsigned DEFAULT NULL AFTER output_width;
