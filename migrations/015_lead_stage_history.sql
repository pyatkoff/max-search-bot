CREATE TABLE IF NOT EXISTS lead_stage_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    from_stage_key VARCHAR(64) NULL,
    to_stage_key VARCHAR(64) NOT NULL,
    changed_by_manager_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lead_stage_history_conversation (conversation_id, id),
    KEY idx_lead_stage_history_created (created_at),
    KEY idx_lead_stage_history_manager (changed_by_manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
