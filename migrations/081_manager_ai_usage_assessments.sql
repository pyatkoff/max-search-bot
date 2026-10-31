CREATE TABLE IF NOT EXISTS manager_ai_usage_assessments (
    message_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    draft_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    comparison_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    phase VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_version INT UNSIGNED NOT NULL,
    assessment_json TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    assessment_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (message_id,version),
    UNIQUE KEY manager_ai_usage_assessment_operation (manager_id,operation_id),
    CONSTRAINT manager_ai_usage_assessment_message_fk FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT manager_ai_usage_assessment_draft_fk FOREIGN KEY (draft_id) REFERENCES manager_ai_drafts(draft_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
