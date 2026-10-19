-- Worker metadata and locally validated proposals only; source history is not copied.
-- UTC timestamps are written explicitly by ManagerPreparedReplyService.
CREATE TABLE IF NOT EXISTS manager_prepared_replies (
    job_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    context_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instruction_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    latest_message_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','ready') NOT NULL,
    proposal_json VARBINARY(32768) NULL,
    proposal_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL,
    prepared_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (job_id),
    KEY idx_manager_prepared_pending (status,expires_at,created_at),
    KEY idx_manager_prepared_owner (conversation_id,manager_id,project_key,context_revision),
    CONSTRAINT fk_manager_prepared_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
