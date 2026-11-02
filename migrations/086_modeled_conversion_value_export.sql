-- Explicit owner-authorized experimental goal; existing send lanes and rows are unchanged.
-- One existing durable outbox and one existing cron process.
ALTER TABLE max_channel_conversion_outbox
    ADD COLUMN value_rub DECIMAL(12,2) NULL,
    ADD COLUMN value_profile_id BIGINT UNSIGNED NULL,
    ADD COLUMN value_stage VARCHAR(40) NULL,
    ADD COLUMN value_contract VARCHAR(64) NULL;

CREATE TABLE IF NOT EXISTS modeled_conversion_value_export_settings (
    project_key VARCHAR(64) NOT NULL PRIMARY KEY,
    counter_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mode VARCHAR(16) NOT NULL DEFAULT 'off',
    goal_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    goal_intent VARCHAR(16) NOT NULL DEFAULT 'none',
    activated_at_unix BIGINT UNSIGNED NULL,
    profile_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    base_value_rub INT UNSIGNED NOT NULL DEFAULT 25000,
    contract_version VARCHAR(64) NOT NULL DEFAULT 'semantic_stage_value_25000_v1',
    last_tick_unix BIGINT UNSIGNED NULL,
    last_receipt_json JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO modeled_conversion_value_export_settings(project_key,counter_id)
VALUES('anytour','98615635');
