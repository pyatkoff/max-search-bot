CREATE TABLE IF NOT EXISTS telegram_archive_accounts (
    project_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bot_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    connection_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    username VARCHAR(64) NOT NULL DEFAULT '',
    connection_date BIGINT UNSIGNED NOT NULL,
    last_update_id BIGINT UNSIGNED NOT NULL,
    provider_enabled TINYINT(1) NOT NULL DEFAULT 0,
    approved TINYINT(1) NOT NULL DEFAULT 0,
    manager_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (project_key, bot_key, account_id),
    UNIQUE KEY telegram_archive_account_connection (project_key, bot_key, connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
