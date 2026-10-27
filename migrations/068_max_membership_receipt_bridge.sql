-- Prospective fence for membership receipts carried by the existing signed MAX lookup.
-- The same worker and conversation_events journal are reused; no history is backfilled.
SET @membership_receipts_present=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_channel_attribution_settings' AND COLUMN_NAME='membership_receipts_activated_at_unix');
SET @membership_receipts_sql=IF(@membership_receipts_present=0,'ALTER TABLE max_channel_attribution_settings ADD COLUMN membership_receipts_activated_at_unix BIGINT UNSIGNED NULL','DO 0');
PREPARE membership_receipts_stmt FROM @membership_receipts_sql;
EXECUTE membership_receipts_stmt;
DEALLOCATE PREPARE membership_receipts_stmt;

SET @membership_receipts_cursor_present=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_channel_attribution_settings' AND COLUMN_NAME='membership_receipts_outbox_cursor');
SET @membership_receipts_cursor_sql=IF(@membership_receipts_cursor_present=0,'ALTER TABLE max_channel_attribution_settings ADD COLUMN membership_receipts_outbox_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0','DO 0');
PREPARE membership_receipts_cursor_stmt FROM @membership_receipts_cursor_sql;
EXECUTE membership_receipts_cursor_stmt;
DEALLOCATE PREPARE membership_receipts_cursor_stmt;

UPDATE max_channel_attribution_settings
SET membership_receipts_activated_at_unix=UNIX_TIMESTAMP()
WHERE project_key='anytour' AND counter_id='98615635'
  AND membership_receipts_activated_at_unix IS NULL;
