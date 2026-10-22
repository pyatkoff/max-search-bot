-- Explicit, revocable public projection of an immutable reviewed publication.
CREATE TABLE IF NOT EXISTS manager_tour_selection_links (
    conversation_id BIGINT UNSIGNED NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    publication_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_active TINYINT UNSIGNED NOT NULL,
    updated_by_manager_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (conversation_id),
    UNIQUE KEY public_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS manager_tour_selection_link_history (
    conversation_id BIGINT UNSIGNED NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    publication_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_active TINYINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (conversation_id,version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
