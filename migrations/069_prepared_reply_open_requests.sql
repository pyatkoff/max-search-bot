-- Opened-chat work is explicitly bound to its authorized viewer. Background
-- collection and immutable existing results keep their original purpose.
SET @prepared_open_column=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_prepared_replies' AND COLUMN_NAME='purpose');
SET @prepared_open_sql=IF(@prepared_open_column=0,'ALTER TABLE manager_prepared_replies ADD COLUMN purpose ENUM(''background'',''open_chat'') NOT NULL DEFAULT ''background''','DO 0');
PREPARE prepared_open_migration FROM @prepared_open_sql;
EXECUTE prepared_open_migration;
DEALLOCATE PREPARE prepared_open_migration;
SET @prepared_open_column=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_prepared_replies' AND COLUMN_NAME='requested_by_manager_id');
SET @prepared_open_sql=IF(@prepared_open_column=0,'ALTER TABLE manager_prepared_replies ADD COLUMN requested_by_manager_id BIGINT UNSIGNED NULL','DO 0');
PREPARE prepared_open_migration FROM @prepared_open_sql;
EXECUTE prepared_open_migration;
DEALLOCATE PREPARE prepared_open_migration;
SET @prepared_open_column=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_prepared_replies' AND COLUMN_NAME='viewer_access_revision');
SET @prepared_open_sql=IF(@prepared_open_column=0,'ALTER TABLE manager_prepared_replies ADD COLUMN viewer_access_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL','DO 0');
PREPARE prepared_open_migration FROM @prepared_open_sql;
EXECUTE prepared_open_migration;
DEALLOCATE PREPARE prepared_open_migration;
SET @prepared_open_index=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_prepared_replies' AND INDEX_NAME='idx_prepared_open_request');
SET @prepared_open_sql=IF(@prepared_open_index=0,'ALTER TABLE manager_prepared_replies ADD INDEX idx_prepared_open_request (conversation_id,requested_by_manager_id,purpose,context_revision,instruction_revision,created_at)','DO 0');
PREPARE prepared_open_migration FROM @prepared_open_sql;
EXECUTE prepared_open_migration;
DEALLOCATE PREPARE prepared_open_migration;
