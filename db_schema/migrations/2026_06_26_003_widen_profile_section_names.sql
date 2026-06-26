/*
 * Preserve full RawTherapee PP3 section names in generated processing profiles.
 */

ALTER TABLE photo_profile_data
  MODIFY type varchar(64) NOT NULL;

ALTER TABLE internal_profile_data
  MODIFY type varchar(64) NOT NULL;
