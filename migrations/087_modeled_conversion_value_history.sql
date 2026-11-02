ALTER TABLE max_channel_conversion_outbox
    ADD COLUMN value_origin VARCHAR(32) NULL,
    ADD COLUMN value_reviewed_at_unix BIGINT UNSIGNED NULL;

ALTER TABLE modeled_conversion_value_export_settings
    ADD COLUMN history_from_unix BIGINT UNSIGNED NULL,
    ADD COLUMN history_until_unix BIGINT UNSIGNED NULL,
    ADD COLUMN history_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN history_ceiling BIGINT UNSIGNED NULL,
    ADD COLUMN history_completed_at_unix BIGINT UNSIGNED NULL,
    ADD COLUMN history_scanned BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN history_collected BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN history_stats_json JSON NULL;
