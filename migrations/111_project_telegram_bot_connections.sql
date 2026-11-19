SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS project_telegram_bot_connections (
    project_id BIGINT UNSIGNED NOT NULL,
    bot_id VARCHAR(19) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    credentials_json TEXT NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (project_id),
    UNIQUE KEY uq_project_telegram_bot_id (bot_id),
    CONSTRAINT fk_project_telegram_bot_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
