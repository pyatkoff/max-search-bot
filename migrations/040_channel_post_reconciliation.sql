-- A new deployment starts discovery now, never by importing channel history.
-- Each enabled MAX channel keeps a bounded, resumable provider-read window.
CREATE TABLE IF NOT EXISTS post_channel_reconciliation (
    post_channel_id BIGINT UNSIGNED NOT NULL,
    enabled_since_ms BIGINT UNSIGNED NOT NULL,
    completed_through_ms BIGINT UNSIGNED NOT NULL,
    scan_until_ms BIGINT UNSIGNED NULL,
    before_ms BIGINT UNSIGNED NULL,
    claim_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_at DATETIME NULL,
    last_checked_at DATETIME NULL,
    error_reason VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    PRIMARY KEY (post_channel_id),
    CONSTRAINT fk_post_reconciliation_channel FOREIGN KEY (post_channel_id) REFERENCES post_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO post_channel_reconciliation (post_channel_id,enabled_since_ms,completed_through_ms)
SELECT id,TIMESTAMPDIFF(MICROSECOND,'1970-01-01 00:00:00',UTC_TIMESTAMP(3)) DIV 1000,
          TIMESTAMPDIFF(MICROSECOND,'1970-01-01 00:00:00',UTC_TIMESTAMP(3)) DIV 1000
FROM post_channels WHERE platform='max' AND is_enabled=1
AND NOT EXISTS (SELECT 1 FROM post_channel_reconciliation r WHERE r.post_channel_id=post_channels.id);
