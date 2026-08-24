SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS manager_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    group_key VARCHAR(64) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_manager_groups_project_key (project_id, group_key),
    KEY idx_manager_groups_project (project_id, is_active),
    CONSTRAINT fk_manager_groups_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manager_group_members (
    group_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, manager_id),
    KEY idx_manager_group_members_manager (manager_id, group_id),
    CONSTRAINT fk_manager_group_members_group FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_manager_group_members_manager FOREIGN KEY (manager_id) REFERENCES managers(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    source_key VARCHAR(96) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    channel VARCHAR(32) NULL,
    primary_group_id BIGINT UNSIGNED NULL,
    fallback_mode VARCHAR(16) NOT NULL DEFAULT 'immediate',
    fallback_group_id BIGINT UNSIGNED NULL,
    fallback_after_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conversation_sources_project_key (project_id, source_key),
    KEY idx_conversation_sources_project (project_id, is_active),
    CONSTRAINT fk_conversation_sources_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_conversation_sources_primary_group FOREIGN KEY (primary_group_id) REFERENCES manager_groups(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_conversation_sources_fallback_group FOREIGN KEY (fallback_group_id) REFERENCES manager_groups(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE conversations ADD COLUMN IF NOT EXISTS source_id BIGINT UNSIGNED NULL AFTER project_key;
