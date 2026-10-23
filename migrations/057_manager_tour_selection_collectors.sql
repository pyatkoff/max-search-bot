-- Connection metadata only. Requests still require the canonical Manager session/CSRF.
CREATE TABLE IF NOT EXISTS manager_tour_selection_collectors (
    connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    device_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (connection_id),
    KEY collector_actor (manager_id,conversation_id,device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
