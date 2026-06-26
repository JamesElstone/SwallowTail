/*
 * Allow internal profile overlay rows to be disabled without deleting them.
 */

ALTER TABLE internal_profile_data
  ADD COLUMN enabled tinyint(1) NOT NULL DEFAULT 1 AFTER `order`;
