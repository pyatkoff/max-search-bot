-- Reuse the existing outbox/worker. All existing rows retain platform=max.
-- Telegram remains OFF until an explicit, prospective activation.
SET @tg_settings_present=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_channel_attribution_settings' AND COLUMN_NAME='telegram_mode');
SET @tg_settings_sql=IF(@tg_settings_present=0,'ALTER TABLE max_channel_attribution_settings ADD COLUMN telegram_mode VARCHAR(16) NOT NULL DEFAULT ''off'', ADD COLUMN telegram_activated_at_unix BIGINT UNSIGNED NULL, ADD COLUMN telegram_signal_floor BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN telegram_signal_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0','DO 0');
PREPARE tg_settings_stmt FROM @tg_settings_sql;
EXECUTE tg_settings_stmt;
DEALLOCATE PREPARE tg_settings_stmt;

SET @tg_outbox_present=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_channel_conversion_outbox' AND COLUMN_NAME='platform');
SET @tg_outbox_sql=IF(@tg_outbox_present=0,'ALTER TABLE max_channel_conversion_outbox ADD COLUMN platform VARCHAR(16) NOT NULL DEFAULT ''max'', ADD COLUMN identity_source_key VARCHAR(80) NOT NULL DEFAULT '''', ADD COLUMN source_signal_id BIGINT UNSIGNED NULL, ADD COLUMN identity_observation_id BIGINT UNSIGNED NULL','DO 0');
PREPARE tg_outbox_stmt FROM @tg_outbox_sql;
EXECUTE tg_outbox_stmt;
DEALLOCATE PREPARE tg_outbox_stmt;
