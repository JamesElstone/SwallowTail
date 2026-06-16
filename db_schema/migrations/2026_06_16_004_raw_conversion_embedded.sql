/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

ALTER TABLE swallowtail_photo_derivatives
  MODIFY COLUMN derivative_type enum('embedded','original_jpeg','thumbnail','preview','jpeg') NOT NULL;

ALTER TABLE swallowtail_photo_conversion_jobs
  MODIFY COLUMN derivative_type enum('embedded','original_jpeg','thumbnail','preview','jpeg') DEFAULT NULL;
