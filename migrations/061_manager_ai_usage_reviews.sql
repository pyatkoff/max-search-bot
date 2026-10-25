-- Personal review metadata only; proposals and messages retain their sole owners.
CREATE TABLE IF NOT EXISTS manager_ai_usage_reviews (
    manager_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NOT NULL,
    case_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reviewed_at DATETIME NOT NULL,
    PRIMARY KEY (manager_id,message_id),
    CONSTRAINT fk_ai_usage_review_manager FOREIGN KEY (manager_id) REFERENCES managers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ai_usage_review_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
