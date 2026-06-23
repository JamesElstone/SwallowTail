ALTER TABLE api_upload_tokens
  ADD COLUMN hidden tinyint(1) NOT NULL DEFAULT 0 AFTER created_by_user_id;

ALTER TABLE api_upload_tokens
  ADD KEY idx_upload_tokens_hidden (hidden, is_active, expires_at);
