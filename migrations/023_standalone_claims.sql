SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS runtime_claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_key VARCHAR(64) NOT NULL DEFAULT 'default',
    chat_id VARCHAR(191) NOT NULL,
    name VARCHAR(191) NOT NULL DEFAULT '',
    city_id BIGINT NULL,
    country_id BIGINT NULL,
    adults INT NOT NULL DEFAULT 0,
    children INT NOT NULL DEFAULT 0,
    child_ages VARCHAR(191) NOT NULL DEFAULT '',
    stars INT NOT NULL DEFAULT 0,
    meal_id VARCHAR(64) NOT NULL DEFAULT '',
    nights VARCHAR(64) NOT NULL DEFAULT '',
    departure_date VARCHAR(64) NOT NULL DEFAULT '',
    code VARCHAR(64) NOT NULL,
    phone VARCHAR(64) NULL,
    phone_asked TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_runtime_claim_code (project_key, code),
    KEY idx_runtime_claim_chat (project_key, chat_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
