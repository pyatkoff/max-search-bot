SET @preparation_policy_column=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_prepared_replies' AND COLUMN_NAME='preparation_policy_revision');
SET @preparation_policy_sql=IF(@preparation_policy_column=0,'ALTER TABLE manager_prepared_replies ADD COLUMN preparation_policy_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL','DO 0');
PREPARE preparation_policy_migration FROM @preparation_policy_sql;
EXECUTE preparation_policy_migration;
DEALLOCATE PREPARE preparation_policy_migration;

CREATE TABLE IF NOT EXISTS manager_preparation_queue_policies (
    project_key VARCHAR(64) NOT NULL PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL,
    rule_json LONGTEXT NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
