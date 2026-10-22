-- Immutable reviewed selections and per-card durable native creation fences.
CREATE TABLE IF NOT EXISTS manager_tour_selection_publications (
    publication_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    draft_version BIGINT UNSIGNED NOT NULL,
    draft_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_by_manager_id BIGINT UNSIGNED NOT NULL,
    binding_json TEXT NOT NULL,
    selection_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (publication_id),
    UNIQUE KEY conversation_terms (conversation_id,draft_hash),
    KEY conversation_history (conversation_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_tour_selection_cards (
    publication_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    card_index INT UNSIGNED NOT NULL,
    offer_id BIGINT UNSIGNED NOT NULL,
    callback_data VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (publication_id,card_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_tour_selection_deliveries (
    offer_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    offer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    callback_data VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_json MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (offer_id),
    UNIQUE KEY conversation_offer (conversation_id,offer_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
