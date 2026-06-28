/*
 * Remove the obsolete misspelled RawTherapee profile table.
 *
 * The active table is rawtherapee_profile_data. This drops the legacy
 * rawtheapee_profile_data spelling if it exists in older deployments.
 */

DROP TABLE IF EXISTS rawtheapee_profile_data;
