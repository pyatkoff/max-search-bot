CREATE TABLE IF NOT EXISTS manager_ai_sent_texts (
    message_id BIGINT UNSIGNED NOT NULL,
    draft_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    text_value MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    text_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (message_id),
    KEY manager_ai_sent_texts_draft (draft_id),
    CONSTRAINT manager_ai_sent_texts_message_fk FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT manager_ai_sent_texts_draft_fk FOREIGN KEY (draft_id) REFERENCES manager_ai_drafts(draft_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
