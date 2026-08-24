SET NAMES utf8mb4;

ALTER TABLE managers
    ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL AFTER login,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER is_active,
    ADD COLUMN IF NOT EXISTS is_working TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

CREATE TABLE IF NOT EXISTS manager_conversation_reads (
    manager_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (manager_id, conversation_id),
    KEY idx_manager_reads_conversation (conversation_id, manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
