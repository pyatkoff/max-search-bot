-- Existing rows remain text receipts, including every historical UNKNOWN.
SET @manager_media_kind_exists=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='manager_send_attempts' AND column_name='operation_kind');
SET @manager_media_kind_sql=IF(@manager_media_kind_exists=0,'ALTER TABLE manager_send_attempts ADD COLUMN operation_kind VARCHAR(16) NOT NULL DEFAULT ''text''','DO 0');
PREPARE manager_media_kind_stmt FROM @manager_media_kind_sql;
EXECUTE manager_media_kind_stmt;
DEALLOCATE PREPARE manager_media_kind_stmt;
