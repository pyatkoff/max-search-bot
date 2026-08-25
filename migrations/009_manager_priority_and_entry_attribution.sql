ALTER TABLE managers ADD COLUMN priority INT NOT NULL DEFAULT 0 AFTER is_working;
ALTER TABLE conversations ADD COLUMN entry_channel VARCHAR(64) NULL AFTER channel,
    ADD COLUMN attribution_region VARCHAR(64) NULL AFTER entry_channel,
    ADD COLUMN attribution_campaign VARCHAR(64) NULL AFTER attribution_region;

CREATE TABLE IF NOT EXISTS manager_priority_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    manager_id BIGINT UNSIGNED NOT NULL,
    rule_type VARCHAR(32) NOT NULL,
    rule_value VARCHAR(191) NOT NULL,
    bonus INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    comment VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_priority_manager (manager_id,is_active),
    KEY idx_priority_match (rule_type,rule_value,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
