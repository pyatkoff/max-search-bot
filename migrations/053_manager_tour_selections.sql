-- Editable staff drafts only. Sent offers and their choices remain append-only.
CREATE TABLE IF NOT EXISTS manager_tour_selection_drafts (
    conversation_id BIGINT UNSIGNED NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    draft_json MEDIUMTEXT NOT NULL,
    updated_by_manager_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_tour_selection_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    saved_version BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
