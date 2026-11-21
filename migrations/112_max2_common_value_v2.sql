-- New, prospective common-goal storage only. No old settings, outbox or backfill.
-- Number 112 must be rechecked against the app head immediately before publication.
CREATE TABLE IF NOT EXISTS max2_common_value_accounts_v2 (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counter_id BIGINT UNSIGNED NOT NULL,
    goal_id BIGINT UNSIGNED NOT NULL,
    target VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    project_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    platform VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    customer_key VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    conversation_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    inquiry_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    inquiry_journal_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    inquiry_native_message_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    inquiry_recorded_at_unix BIGINT UNSIGNED NOT NULL,
    binding_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    binding_kind VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tracker_record_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    immutable_tuple_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    entry_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    publication_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    version_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    offer_id VARCHAR(23) CHARACTER SET ascii COLLATE ascii_bin NULL,
    client_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    identity_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    initial_common_kopeks BIGINT UNSIGNED NOT NULL,
    max2_model_version VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    binding_proof_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    producer_contract_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proof_observed_at_unix BIGINT UNSIGNED NOT NULL,
    point_recheck_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    activation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activated_at_unix BIGINT UNSIGNED NOT NULL,
    created_at_unix BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_m2_common_conversation (counter_id,target,project_key,platform,conversation_id),
    UNIQUE KEY uq_m2_common_inquiry (counter_id,target,inquiry_key),
    UNIQUE KEY uq_m2_common_receipt (counter_id,target,tracker_record_id),
    CONSTRAINT ck_m2_common_account_target CHECK (counter_id=98615635 AND goal_id=687941321 AND target='anytour_modeled_value_v2_max2'),
    CONSTRAINT ck_m2_common_account_version CHECK (version='max2_common_observed_inquiry_value_v2'),
    CONSTRAINT ck_m2_common_account_project CHECK (project_key='anytour' AND platform IN ('max','telegram')),
    CONSTRAINT ck_m2_common_account_amount CHECK (initial_common_kopeks<=10000000),
    CONSTRAINT ck_m2_common_account_binding CHECK (
        (binding_kind='PROVEN_NO_NEW_EARLY_RESERVE' AND tracker_record_id IS NULL AND initial_common_kopeks=0)
        OR (binding_kind='UNIQUE_OBSERVED_MAX2' AND platform='max' AND tracker_record_id IS NOT NULL
            AND immutable_tuple_sha256 IS NOT NULL AND initial_common_kopeks>0)),
    CONSTRAINT ck_m2_common_account_prospective CHECK (inquiry_recorded_at_unix>=activated_at_unix AND created_at_unix>=activated_at_unix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS max2_common_value_events_v2 (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    counter_id BIGINT UNSIGNED NOT NULL,
    goal_id BIGINT UNSIGNED NOT NULL,
    target VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    model_version VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    total_kopeks BIGINT UNSIGNED NOT NULL,
    delta_kopeks BIGINT UNSIGNED NOT NULL,
    delivery_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_at_unix BIGINT UNSIGNED NOT NULL,
    created_at_unix BIGINT UNSIGNED NOT NULL,
    attempt_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    csv_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempt_started_at_unix BIGINT UNSIGNED NULL,
    upload_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    provider_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    source_quantity TINYINT UNSIGNED NULL,
    line_quantity TINYINT UNSIGNED NULL,
    accepted_at_unix BIGINT UNSIGNED NULL,
    processed_at_unix BIGINT UNSIGNED NULL,
    transport_receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    last_result_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_m2_common_event (event_key),
    UNIQUE KEY uq_m2_common_profile (account_id,profile_id),
    UNIQUE KEY uq_m2_common_source_profile (counter_id,target,profile_id),
    UNIQUE KEY uq_m2_common_attempt (attempt_key),
    UNIQUE KEY uq_m2_common_upload (counter_id,upload_id),
    KEY ix_m2_common_delivery (delivery_state,id),
    CONSTRAINT fk_m2_common_event_account FOREIGN KEY (account_id) REFERENCES max2_common_value_accounts_v2(id) ON DELETE RESTRICT,
    CONSTRAINT ck_m2_common_event_target CHECK (counter_id=98615635 AND goal_id=687941321 AND target='anytour_modeled_value_v2_max2'),
    CONSTRAINT ck_m2_common_event_money CHECK (total_kopeks<=10000000 AND delta_kopeks<=total_kopeks),
    CONSTRAINT ck_m2_common_event_state CHECK (
        (delta_kopeks=0 AND delivery_state='not_needed') OR
        (delta_kopeks>0 AND delivery_state IN ('reserved','attempted','accepted','processed','unknown','failed'))),
    CONSTRAINT ck_m2_common_single_attempt CHECK (
        (delivery_state IN ('reserved','not_needed') AND attempt_count=0 AND attempt_key IS NULL AND csv_sha256 IS NULL
            AND attempt_started_at_unix IS NULL AND upload_id IS NULL AND accepted_at_unix IS NULL AND processed_at_unix IS NULL)
        OR (delivery_state IN ('attempted','accepted','processed','unknown','failed') AND attempt_count=1
            AND attempt_key IS NOT NULL AND csv_sha256 IS NOT NULL AND attempt_started_at_unix IS NOT NULL)),
    CONSTRAINT ck_m2_common_processed CHECK (delivery_state<>'processed' OR
        (upload_id IS NOT NULL AND provider_status IS NOT NULL AND provider_status='PROCESSED'
            AND source_quantity IS NOT NULL AND source_quantity=1 AND line_quantity IS NOT NULL AND line_quantity=1 AND processed_at_unix IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
