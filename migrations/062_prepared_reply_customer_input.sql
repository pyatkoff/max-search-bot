-- Private work deduplication only. Existing immutable proposals are not backfilled.
SET @prepared_input_column=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_prepared_replies' AND COLUMN_NAME='customer_input_revision');
SET @prepared_input_sql=IF(@prepared_input_column=0,'ALTER TABLE manager_prepared_replies ADD COLUMN customer_input_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL','DO 0');
PREPARE prepared_input_migration FROM @prepared_input_sql;
EXECUTE prepared_input_migration;
DEALLOCATE PREPARE prepared_input_migration;
SET @prepared_input_index=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_prepared_replies' AND INDEX_NAME='idx_prepared_customer_input');
SET @prepared_input_sql=IF(@prepared_input_index=0,'ALTER TABLE manager_prepared_replies ADD INDEX idx_prepared_customer_input (conversation_id,manager_id,project_key,customer_input_revision,status)','DO 0');
PREPARE prepared_input_migration FROM @prepared_input_sql;
EXECUTE prepared_input_migration;
DEALLOCATE PREPARE prepared_input_migration;
