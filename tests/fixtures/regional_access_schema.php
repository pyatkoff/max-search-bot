<?php
declare(strict_types=1);

/** Explicit empty migration-099 model for isolated legacy ACL fixtures only. */
function regionalAccessFixtureSchema(PDO $pdo): void
{
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB' : '';
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_regional_access (
        manager_id BIGINT NOT NULL, project_id BIGINT NOT NULL,
        regional_scope_required INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (manager_id, project_id)
    )' . $suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_group_territories (
        group_id BIGINT NOT NULL, territory_region_id VARCHAR(20) NOT NULL,
        anchor_hierarchy_json TEXT NOT NULL, prefix_json TEXT NOT NULL,
        catalog_sha256 VARCHAR(64) NOT NULL,
        PRIMARY KEY (group_id, territory_region_id)
    )' . $suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS manager_group_territory_regions (
        group_id BIGINT NOT NULL, territory_region_id VARCHAR(20) NOT NULL,
        region_id VARCHAR(20) NOT NULL,
        PRIMARY KEY (group_id, territory_region_id, region_id)
    )' . $suffix);
}
