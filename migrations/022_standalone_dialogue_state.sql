SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS runtime_dialogue_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_key VARCHAR(64) NOT NULL DEFAULT 'default',
    chat_id VARCHAR(191) NOT NULL,
    status_id INT NOT NULL,
    value_text TEXT NULL,
    message_id VARCHAR(191) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_runtime_dialogue_chat (project_key, chat_id, id),
    KEY idx_runtime_dialogue_status (project_key, chat_id, status_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
