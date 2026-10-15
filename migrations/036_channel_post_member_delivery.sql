-- One durable delivery decision per numeric Telegram album member.
-- Content history remains in post_versions; unrelated member observations cannot
-- reset an ambiguous edit or authorize a second edit of the anchor.
CREATE TABLE IF NOT EXISTS post_member_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    publication_id BIGINT UNSIGNED NOT NULL,
    external_message_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    desired_role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_at DATETIME NULL,
    write_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_reason VARCHAR(96) NOT NULL DEFAULT '',
    receipt_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_member_delivery (publication_id, external_message_id),
    KEY idx_post_member_pending (publication_id, state, id),
    CONSTRAINT fk_post_member_publication FOREIGN KEY (publication_id) REFERENCES post_publications(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
