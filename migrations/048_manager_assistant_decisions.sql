-- Private, revision-bound manager decisions. No AI text or customer history is copied.
CREATE TABLE IF NOT EXISTS manager_assistant_decisions (
    manager_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    recommendation_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    context_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    instruction_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision ENUM('done','irrelevant','open') NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,conversation_id,recommendation_revision),
    CONSTRAINT fk_assistant_decision_manager FOREIGN KEY (manager_id) REFERENCES managers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assistant_decision_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
