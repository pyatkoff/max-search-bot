-- Forward repair for the known unjournaled 104 / existing 086 collision.
-- MigrationRunner admits only the exact source, existing 086 value definition,
-- and compatible context schema before executing this file. It records this
-- repair normally, then adopts 104 explicitly as baseline=1, execution_ms=0.
-- The legacy value_rub column and every existing row remain unchanged.

SET @funnel_context_repair_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='advertising_visit_observations' AND COLUMN_NAME='post_ref')=0,
    'ALTER TABLE advertising_visit_observations ADD COLUMN post_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''''',
    'DO 0'
);
PREPARE funnel_context_repair_stmt FROM @funnel_context_repair_sql;
EXECUTE funnel_context_repair_stmt;
DEALLOCATE PREPARE funnel_context_repair_stmt;

SET @funnel_context_repair_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='advertising_visit_observations' AND COLUMN_NAME='offer_ref')=0,
    'ALTER TABLE advertising_visit_observations ADD COLUMN offer_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''''',
    'DO 0'
);
PREPARE funnel_context_repair_stmt FROM @funnel_context_repair_sql;
EXECUTE funnel_context_repair_stmt;
DEALLOCATE PREPARE funnel_context_repair_stmt;

SET @funnel_context_repair_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='advertising_visit_observations' AND COLUMN_NAME='context_json')=0,
    'ALTER TABLE advertising_visit_observations ADD COLUMN context_json TEXT NULL',
    'DO 0'
);
PREPARE funnel_context_repair_stmt FROM @funnel_context_repair_sql;
EXECUTE funnel_context_repair_stmt;
DEALLOCATE PREPARE funnel_context_repair_stmt;

SET @funnel_context_repair_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_channel_conversion_outbox' AND COLUMN_NAME='funnel_context_json')=0,
    'ALTER TABLE max_channel_conversion_outbox ADD COLUMN funnel_context_json TEXT NULL',
    'DO 0'
);
PREPARE funnel_context_repair_stmt FROM @funnel_context_repair_sql;
EXECUTE funnel_context_repair_stmt;
DEALLOCATE PREPARE funnel_context_repair_stmt;

SET @funnel_context_repair_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_channel_conversion_outbox' AND COLUMN_NAME='funnel_policy_sha256')=0,
    'ALTER TABLE max_channel_conversion_outbox ADD COLUMN funnel_policy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
    'DO 0'
);
PREPARE funnel_context_repair_stmt FROM @funnel_context_repair_sql;
EXECUTE funnel_context_repair_stmt;
DEALLOCATE PREPARE funnel_context_repair_stmt;
