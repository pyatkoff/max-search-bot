-- Canonical Manager account-owned replies; Manager2 remains presentation only.
CREATE TABLE IF NOT EXISTS manager_quick_replies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_manager_id BIGINT UNSIGNED NULL,
    scope VARCHAR(16) NOT NULL DEFAULT 'personal',
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    category VARCHAR(64) NOT NULL DEFAULT '',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by_manager_id BIGINT UNSIGNED NOT NULL,
    updated_by_manager_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_manager_replies_owner (owner_manager_id, scope, id),
    KEY idx_manager_replies_scope (scope, id),
    KEY idx_manager_replies_category (scope, owner_manager_id, category, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_quick_reply_favorites (
    manager_id BIGINT UNSIGNED NOT NULL,
    reply_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (manager_id, reply_id),
    KEY idx_manager_reply_favorite_reply (reply_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Receipt and mutation commit atomically. Stable keys are account-scoped and are
-- retained so a lost HTTP response can be recovered without repeating a write.
CREATE TABLE IF NOT EXISTS manager_quick_reply_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (manager_id, operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
