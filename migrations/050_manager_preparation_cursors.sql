-- Private scan position for the existing preparation queue; no customer state.
CREATE TABLE IF NOT EXISTS manager_preparation_cursors (
    project_key VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    lane VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    after_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (project_key,lane)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
