-- Opt-in restrictions survive removing/recreating a project grant. No account,
-- group, shift, attribution receipt or assignment is created/backfilled here.
CREATE TABLE IF NOT EXISTS manager_regional_access (
    manager_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    regional_scope_required TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (manager_id,project_id),
    CONSTRAINT fk_regional_access_manager FOREIGN KEY (manager_id) REFERENCES managers(id) ON DELETE CASCADE,
    CONSTRAINT fk_regional_access_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manager_group_territories (
    group_id BIGINT UNSIGNED NOT NULL,
    territory_region_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    anchor_hierarchy_json TEXT NOT NULL,
    prefix_json TEXT NOT NULL,
    catalog_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (group_id,territory_region_id),
    CONSTRAINT fk_group_territory_group FOREIGN KEY (group_id) REFERENCES manager_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only configured territories are materialized; this is not another full geo
-- catalog. The header and all descendants are replaced in one transaction.
CREATE TABLE IF NOT EXISTS manager_group_territory_regions (
    group_id BIGINT UNSIGNED NOT NULL,
    territory_region_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    region_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (group_id,territory_region_id,region_id),
    KEY idx_territory_region_access (region_id,group_id,territory_region_id),
    CONSTRAINT fk_territory_region_scope FOREIGN KEY (group_id,territory_region_id)
        REFERENCES manager_group_territories(group_id,territory_region_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
