SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS projects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_key VARCHAR(64) NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_key (project_key),
    KEY idx_projects_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE managers
    ADD COLUMN IF NOT EXISTS role VARCHAR(32) NOT NULL DEFAULT 'manager' AFTER display_name;

CREATE TABLE IF NOT EXISTS manager_projects (
    manager_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (manager_id, project_id),
    KEY idx_manager_projects_project (project_id, manager_id),
    CONSTRAINT fk_manager_projects_manager
        FOREIGN KEY (manager_id) REFERENCES managers(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_manager_projects_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
