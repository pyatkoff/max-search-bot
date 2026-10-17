-- Private analysis archive, deliberately separate from live queue/transcript owners.
-- No existing business rows, routing, read markers or analytics are changed.
CREATE TABLE IF NOT EXISTS telegram_dialogue_connections (
    project_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bot_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connection_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    is_enabled TINYINT UNSIGNED NOT NULL,
    last_update_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (project_key, bot_key, connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_dialogue_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    chat_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    version_at BIGINT UNSIGNED NULL,
    source_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_deleted TINYINT UNSIGNED NOT NULL,
    record_json LONGTEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_telegram_dialogue_message (project_key, account_id, chat_id, message_id),
    KEY ix_telegram_dialogue_export (project_key, account_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
