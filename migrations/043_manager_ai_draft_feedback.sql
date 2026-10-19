-- Private bounded draft provenance. Never a customer transcript or training queue.
CREATE TABLE IF NOT EXISTS manager_ai_drafts (
    draft_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    context_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instruction_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    latest_message_id BIGINT UNSIGNED NOT NULL,
    proposal_json VARBINARY(32768) NOT NULL,
    created_at DATETIME NOT NULL,
    insert_requested_at DATETIME NULL,
    PRIMARY KEY (draft_id),
    KEY idx_manager_ai_drafts_owner (conversation_id,manager_id,created_at),
    KEY idx_manager_ai_drafts_created (created_at),
    CONSTRAINT fk_manager_ai_drafts_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
