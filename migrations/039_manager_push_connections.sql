-- A browser installation outlives a provider endpoint. Revocation is account-owned.
CREATE TABLE IF NOT EXISTS manager_push_installations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    manager_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    user_agent VARCHAR(500) NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_manager_push_installation (manager_id,token_hash),
    KEY idx_push_installation_manager (manager_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_push_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE manager_push_subscriptions
    ADD COLUMN IF NOT EXISTS installation_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS intent_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Retry-safe adoption preserves every legacy endpoint and its delivery history.
INSERT IGNORE INTO manager_push_installations (id,manager_id,token_hash,revision,enabled,user_agent,last_seen_at,created_at,updated_at)
    SELECT id,manager_id,NULL,1,1,user_agent,updated_at,created_at,updated_at FROM manager_push_subscriptions WHERE installation_id IS NULL;
UPDATE manager_push_subscriptions SET installation_id=id WHERE installation_id IS NULL;

-- The existing migration metadata/prepared-statement pattern keeps retries safe.
SET @push_installation_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_push_subscriptions' AND INDEX_NAME='idx_push_installation_activity'
);
SET @push_installation_index_sql = IF(
    @push_installation_index_exists=0,
    'ALTER TABLE manager_push_subscriptions ADD KEY idx_push_installation_activity (installation_id,is_active,updated_at,id)',
    'DO 0'
);
PREPARE push_installation_index_stmt FROM @push_installation_index_sql;
EXECUTE push_installation_index_stmt;
DEALLOCATE PREPARE push_installation_index_stmt;
