CREATE TABLE IF NOT EXISTS website_chat_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    external_user_id VARCHAR(96) NOT NULL,
    chat_id BIGINT UNSIGNED NOT NULL,
    source_key VARCHAR(96) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_website_chat_token (token_hash),
    UNIQUE KEY uq_website_chat_user (external_user_id),
    UNIQUE KEY uq_website_chat_chat (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
