-- Private link to the prepared source; only explicit manager previews create it.
SET @prepared_feedback_column=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_ai_drafts' AND COLUMN_NAME='preparation_job_id');
SET @prepared_feedback_sql=IF(@prepared_feedback_column=0,'ALTER TABLE manager_ai_drafts ADD COLUMN preparation_job_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL','DO 0');
PREPARE prepared_feedback_migration FROM @prepared_feedback_sql;
EXECUTE prepared_feedback_migration;
DEALLOCATE PREPARE prepared_feedback_migration;
