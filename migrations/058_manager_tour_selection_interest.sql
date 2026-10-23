-- Anonymous observations only. Native choices/inquiries stay in conversation_events.
CREATE TABLE IF NOT EXISTS manager_tour_selection_interest (
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    publication_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    link_version BIGINT UNSIGNED NOT NULL,
    visitor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bucket VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    card_index SMALLINT NOT NULL,
    hotel_group SMALLINT NOT NULL,
    platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (event_id),
    KEY interest_publication (conversation_id,publication_id,created_at),
    KEY interest_daily_bound (conversation_id,created_at),
    KEY interest_visitor_bound (publication_id,visitor_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
