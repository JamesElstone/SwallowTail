/*
 * Swallowtail
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License.
 * See LICENSE file for details.
 */

/*
 * Reassert the conversion image-type enum after deployments where the
 * earlier preview/final migrations were recorded but the table stayed on an
 * obsolete image-type enum.
 */

ALTER TABLE photo_conversion_jobs
  MODIFY image_type enum('embedded','thumbnail','original','preview','final') NOT NULL;
