ALTER TABLE event_permissions
  ADD COLUMN IF NOT EXISTS grantee_type enum('user','role') NOT NULL DEFAULT 'user' AFTER event_id,
  ADD COLUMN IF NOT EXISTS grantee_id int(11) NOT NULL DEFAULT 0 AFTER grantee_type,
  ADD COLUMN IF NOT EXISTS can_edit tinyint(1) NOT NULL DEFAULT 0 AFTER can_view;

UPDATE event_permissions
SET grantee_type = 'user',
    grantee_id = user_id
WHERE grantee_id = 0
  AND user_id IS NOT NULL;

ALTER TABLE event_permissions
  DROP FOREIGN KEY IF EXISTS fk_event_permissions_user,
  DROP INDEX IF EXISTS uq_event_permissions_event_user,
  DROP INDEX IF EXISTS idx_event_permissions_user,
  ADD UNIQUE KEY IF NOT EXISTS uq_event_permissions_event_grantee (event_id, grantee_type, grantee_id),
  ADD KEY IF NOT EXISTS idx_event_permissions_grantee (grantee_type, grantee_id, event_id),
  DROP COLUMN IF EXISTS user_id;
