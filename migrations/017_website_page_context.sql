CREATE TABLE IF NOT EXISTS website_page_context (
    chat_id BIGINT UNSIGNED NOT NULL,
    external_user_id VARCHAR(96) NOT NULL,
    page_url VARCHAR(2048) NOT NULL DEFAULT '',
    page_title VARCHAR(255) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chat_id),
    UNIQUE KEY uq_website_page_context_user (external_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
