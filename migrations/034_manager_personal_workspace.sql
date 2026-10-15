-- Private workspace preferences only. No conversation, assignment or CRM state.
CREATE TABLE IF NOT EXISTS manager_personal_workspace_states (
    manager_id BIGINT UNSIGNED NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    state_json MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The command receipt commits atomically with its account revision.
CREATE TABLE IF NOT EXISTS manager_personal_workspace_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
