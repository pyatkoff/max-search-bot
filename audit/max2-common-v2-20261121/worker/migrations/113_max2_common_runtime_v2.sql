-- New prospective runtime state only. Applied 112 stays immutable.
CREATE TABLE IF NOT EXISTS max2_common_settings_v2 (
    id TINYINT UNSIGNED NOT NULL,
    project_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'anytour',
    counter_id BIGINT UNSIGNED NOT NULL DEFAULT 98615635,
    goal_id BIGINT UNSIGNED NOT NULL DEFAULT 687941321,
    target VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'anytour_modeled_value_v2_max2',
    mode VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'off',
    producer_contract_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    activation_source_sha CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    activation_request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    mode_request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    activated_at_unix BIGINT UNSIGNED NULL,
    initial_profile_max_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
    profile_cursor VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
    active_tick_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_until_unix BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_tick_at_unix BIGINT UNSIGNED NULL,
    last_tick_summary_json JSON NULL,
    PRIMARY KEY (id),
    CONSTRAINT ck_m2_settings_scope CHECK (id=1 AND project_key='anytour' AND counter_id=98615635 AND goal_id=687941321 AND target='anytour_modeled_value_v2_max2'),
    CONSTRAINT ck_m2_settings_mode CHECK (mode IN ('off','collect','send')),
    CONSTRAINT ck_m2_settings_activation CHECK (mode='off' OR (activation_sha256 IS NOT NULL AND activated_at_unix IS NOT NULL)),
    CONSTRAINT ck_m2_settings_summary CHECK (last_tick_summary_json IS NULL OR OCTET_LENGTH(last_tick_summary_json)<=4096)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO max2_common_settings_v2 (id,producer_contract_sha256)
SELECT 1,'3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e'
WHERE NOT EXISTS (SELECT 1 FROM max2_common_settings_v2 WHERE id=1);

CREATE TABLE IF NOT EXISTS max2_common_ticks_v2 (
    tick_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cursor_start VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    cursor_end VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at_unix BIGINT UNSIGNED NOT NULL,
    finished_at_unix BIGINT UNSIGNED NULL,
    summary_json JSON NULL,
    PRIMARY KEY (tick_key),
    CONSTRAINT ck_m2_tick_state CHECK (state IN ('running','completed','unknown')),
    CONSTRAINT ck_m2_tick_summary CHECK (summary_json IS NULL OR OCTET_LENGTH(summary_json)<=4096)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS max2_common_profile_work_v2 (
    activation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tick_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recorded_at_unix BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (activation_sha256,profile_id),
    CONSTRAINT fk_m2_profile_tick FOREIGN KEY (tick_key) REFERENCES max2_common_ticks_v2(tick_key) ON DELETE RESTRICT,
    CONSTRAINT ck_m2_profile_outcome CHECK (outcome IN ('RESERVED','EVALUATED','ALREADY_RECORDED','HOLD','UNKNOWN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS max2_common_lookup_intents_v2 (
    intent_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tick_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    response_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    consumed_at_unix BIGINT UNSIGNED NOT NULL,
    finished_at_unix BIGINT UNSIGNED NULL,
    PRIMARY KEY (intent_key),
    UNIQUE KEY uq_m2_lookup_once (activation_sha256,profile_id,action),
    CONSTRAINT fk_m2_lookup_tick FOREIGN KEY (tick_key) REFERENCES max2_common_ticks_v2(tick_key) ON DELETE RESTRICT,
    CONSTRAINT ck_m2_lookup_action CHECK (action IN ('list','recheck')),
    CONSTRAINT ck_m2_lookup_state CHECK (state IN ('consumed','observed','unknown')),
    CONSTRAINT ck_m2_lookup_observed CHECK (state<>'observed' OR response_sha256 IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS max2_common_entry_frames_v2 (
    frame_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_user_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    customer_channel_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    customer_key VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entry_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    publication_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    entry_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    observed_at_unix BIGINT UNSIGNED NOT NULL,
    frame_json JSON NOT NULL,
    frame_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (frame_key),
    UNIQUE KEY uq_m2_entry_frame (entry_id),
    KEY ix_m2_frame_subject (project_key,platform,conversation_id,observed_at_unix),
    CONSTRAINT ck_m2_frame_scope CHECK (project_key='anytour' AND platform='max'),
    CONSTRAINT ck_m2_frame_bound CHECK (OCTET_LENGTH(frame_json)<=16384),
    CONSTRAINT ck_m2_frame_entry CHECK (
        (entry_id IS NULL AND publication_id IS NULL AND version_id IS NULL AND entry_key IS NULL)
        OR (entry_id IS NOT NULL AND publication_id IS NOT NULL AND version_id IS NOT NULL AND entry_key IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
