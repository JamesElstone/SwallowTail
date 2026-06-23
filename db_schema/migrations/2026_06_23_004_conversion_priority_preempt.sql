ALTER TABLE photo_conversion_jobs
  MODIFY status enum('queued','processing','succeeded','failed','cancelled','obsolete') NOT NULL DEFAULT 'queued';

DROP INDEX IF EXISTS idx_conversion_jobs_priority ON photo_conversion_jobs;

ALTER TABLE photo_conversion_jobs
  MODIFY priority varchar(20) NOT NULL DEFAULT '20';

UPDATE photo_conversion_jobs
   SET priority = CASE LOWER(priority)
       WHEN 'high' THEN '40'
       WHEN 'normal' THEN '20'
       WHEN 'low' THEN '10'
       ELSE priority
   END;

UPDATE photo_conversion_jobs
   SET priority = '20'
 WHERE priority NOT REGEXP '^[0-9]+$';

ALTER TABLE photo_conversion_jobs
  MODIFY priority int(10) unsigned NOT NULL DEFAULT 20;

ALTER TABLE photo_conversion_jobs
  ADD INDEX idx_conversion_jobs_priority (status, priority, available_at, id);
