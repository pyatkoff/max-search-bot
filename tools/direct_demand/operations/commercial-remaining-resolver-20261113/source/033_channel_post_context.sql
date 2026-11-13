-- Explicitly managed channel publications only. No channel is enabled by migration.
-- Source, bot and project identities remain separate from the opaque start token.
CREATE TABLE IF NOT EXISTS post_channels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    bot_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    canonical_bot_username VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    channel_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(191) NOT NULL DEFAULT '',
    bot_url VARCHAR(255) NOT NULL,
    button_text VARCHAR(80) NOT NULL DEFAULT 'Рассчитать на мои даты',
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    config_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_channels_platform_channel (platform, channel_id),
    KEY idx_post_channels_scope (project_id, platform, bot_key, is_enabled),
    CONSTRAINT fk_post_channels_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_post_channels_source FOREIGN KEY (source_id) REFERENCES conversation_sources(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_publications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_channel_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    external_message_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    start_payload VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    current_version_id BIGINT UNSIGNED NULL,
    -- Keep the accepted event clock (Telegram edit_date has seconds precision)
    -- separate from immutable content versions, including same-body observations.
    observed_revision_ms BIGINT UNSIGNED NULL,
    telegram_sequence VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_publications_identity (post_channel_id, external_message_id),
    UNIQUE KEY uq_post_publications_token (start_payload),
    CONSTRAINT fk_post_publications_channel FOREIGN KEY (post_channel_id) REFERENCES post_channels(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_post_publications_source FOREIGN KEY (source_id) REFERENCES conversation_sources(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshots are append-only. Delivery state belongs to a version, not a dialogue.
-- A write_started operation can only become sent/unknown/requires_attention;
-- lease expiry must never authorize a second external write.
CREATE TABLE IF NOT EXISTS post_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    publication_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    snapshot_json MEDIUMTEXT NOT NULL,
    state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    claim_token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    claimed_at DATETIME NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    write_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_reason VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    receipt_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_versions_number (publication_id, version_number),
    KEY idx_post_versions_pending (state, id),
    CONSTRAINT fk_post_versions_publication FOREIGN KEY (publication_id) REFERENCES post_publications(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every accepted event receives a receipt, including same-content self edits.
-- This prevents an old bot-button edit from reverting a later SMM content edit.
CREATE TABLE IF NOT EXISTS post_observations (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (publication_id, source_event_key),
    CONSTRAINT fk_post_observations_publication FOREIGN KEY (publication_id) REFERENCES post_publications(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_post_observations_version FOREIGN KEY (version_id) REFERENCES post_versions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each authenticated entry pins the exact immutable version visible at entry.
CREATE TABLE IF NOT EXISTS post_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    publication_id BIGINT UNSIGNED NULL,
    version_id BIGINT UNSIGNED NULL,
    availability_reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    entry_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_entries_event (conversation_id, entry_key),
    KEY idx_post_entries_conversation (conversation_id, id),
    CONSTRAINT fk_post_entries_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_post_entries_publication FOREIGN KEY (publication_id) REFERENCES post_publications(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_post_entries_version FOREIGN KEY (version_id) REFERENCES post_versions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
