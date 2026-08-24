SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_manager_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(191) NULL,
    project_key VARCHAR(64) NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_audit_created (created_at,id),
    KEY idx_admin_audit_actor (actor_manager_id,created_at),
    KEY idx_admin_audit_entity (entity_type,entity_id,created_at),
    KEY idx_admin_audit_project (project_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
