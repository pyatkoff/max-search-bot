-- Staff-only conversation annotations. Never customer transcript or CRM state.
CREATE TABLE IF NOT EXISTS manager_internal_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    author_manager_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_internal_notes_conversation_page (conversation_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_internal_note_operations (
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result_json TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
