SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS project_client_connections (
    project_id BIGINT UNSIGNED NOT NULL,
    bot_key VARCHAR(191) NOT NULL,
    token_fingerprint CHAR(64) NOT NULL,
    settings_json MEDIUMTEXT NOT NULL,
    credentials_json TEXT NOT NULL,
    materials_text MEDIUMTEXT NOT NULL,
    registration_fingerprint CHAR(64) NULL,
    last_check_json TEXT NULL,
    activation_operation_id CHAR(32) NULL,
    activation_actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id),
    UNIQUE KEY uq_client_bot (bot_key),
    UNIQUE KEY uq_client_token (token_fingerprint),
    CONSTRAINT fk_client_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_client_operations (
    actor_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) NOT NULL,
    project_id BIGINT UNSIGNED NULL,
    operation_kind VARCHAR(24) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    state VARCHAR(24) NOT NULL,
    mutation_claimed TINYINT(1) NOT NULL DEFAULT 0,
    result_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (actor_id,operation_id),
    KEY idx_client_operation_project (project_id),
    CONSTRAINT fk_client_operation_actor FOREIGN KEY (actor_id) REFERENCES managers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_client_operation_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
