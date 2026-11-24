-- Each row is an independent destination receipt for an existing V2 source.
-- Activation is prospective. The normal runtime owns the setting and reservations.
CREATE TABLE IF NOT EXISTS anytour_umk_late_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    delivery_enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
    activated_at_unix BIGINT UNSIGNED NULL,
    fresh_after_source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    scan_after_source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    initial_source_id BIGINT UNSIGNED NULL,
    initial_profile_id BIGINT UNSIGNED NULL,
    CONSTRAINT ck_umk_late_activation CHECK (
        id=1 AND delivery_enabled IN (0,1) AND (delivery_enabled=0 OR activated_at_unix IS NOT NULL) AND ((activated_at_unix IS NULL AND initial_source_id IS NULL AND initial_profile_id IS NULL)
        OR (activated_at_unix IS NOT NULL AND activated_at_unix>0 AND initial_source_id IS NOT NULL AND initial_profile_id IS NOT NULL))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO anytour_umk_late_settings (id)
SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM anytour_umk_late_settings WHERE id=1);

CREATE TABLE IF NOT EXISTS anytour_umk_late_accounts (
    source_account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    early_kopeks BIGINT UNSIGNED NOT NULL,
    early_proof_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    CONSTRAINT fk_umk_account_source FOREIGN KEY (source_account_id) REFERENCES max2_common_value_accounts_v2(id) ON DELETE RESTRICT,
    CONSTRAINT ck_umk_early_cap CHECK (early_kopeks<=10000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS anytour_umk_late_events (
    source_event_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    source_account_id BIGINT UNSIGNED NOT NULL,
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    profile_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    total_kopeks BIGINT UNSIGNED NOT NULL,
    delta_kopeks BIGINT UNSIGNED NOT NULL,
    event_at_unix BIGINT UNSIGNED NOT NULL,
    delivery_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    csv_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    attempted_at_unix BIGINT UNSIGNED NULL,
    upload_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    provider_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    provider_checked_at_unix BIGINT UNSIGNED NULL,
    processed_at_unix BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_umk_attempt (attempt_key),
    UNIQUE KEY uq_umk_upload (upload_id),
    UNIQUE KEY uq_umk_late_key (event_key),
    UNIQUE KEY uq_umk_late_profile (profile_id),
    KEY ix_umk_late_account (source_account_id,source_event_id),
    KEY ix_umk_late_pending (delivery_state,source_event_id),
    CONSTRAINT fk_umk_event_source FOREIGN KEY (source_event_id) REFERENCES max2_common_value_events_v2(id) ON DELETE RESTRICT,
    CONSTRAINT fk_umk_event_account FOREIGN KEY (source_account_id) REFERENCES anytour_umk_late_accounts(source_account_id) ON DELETE RESTRICT,
    CONSTRAINT ck_umk_late_value CHECK (delta_kopeks<=total_kopeks AND total_kopeks<=10000000),
    CONSTRAINT ck_umk_late_attempt CHECK (
        (delivery_state IN ('not_needed','reserved') AND attempt_key IS NULL AND csv_sha256 IS NULL AND attempted_at_unix IS NULL
            AND upload_id IS NULL AND provider_status IS NULL AND provider_checked_at_unix IS NULL AND processed_at_unix IS NULL)
        OR (delivery_state IN ('attempted','accepted','processed','unknown','failed') AND attempt_key IS NOT NULL
            AND csv_sha256 IS NOT NULL AND attempted_at_unix IS NOT NULL)
    ),
    CONSTRAINT ck_umk_late_receipt CHECK (delivery_state NOT IN ('accepted','processed') OR upload_id IS NOT NULL),
    CONSTRAINT ck_umk_late_processed CHECK (
        (delivery_state='processed' AND processed_at_unix IS NOT NULL AND provider_status IS NOT NULL AND provider_status='PROCESSED')
        OR (delivery_state<>'processed' AND processed_at_unix IS NULL)
    ),
    CONSTRAINT ck_umk_late_state CHECK (
        (delta_kopeks=0 AND delivery_state='not_needed') OR
        (delta_kopeks>0 AND delivery_state IN ('reserved','attempted','accepted','processed','unknown','failed'))
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Only verified early observations for this destination are stored. A later proof
-- may corroborate the first observation. Contradictory amounts remain visible to the reader.
CREATE TABLE IF NOT EXISTS anytour_umk_point_proofs (
    inquiry_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tracker_record_id VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tuple_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_at_unix BIGINT UNSIGNED NOT NULL,
    amount_kopeks BIGINT UNSIGNED NOT NULL,
    source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    activated_at_unix BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (inquiry_key,tracker_record_id,tuple_sha256,activated_at_unix,issued_at_unix,amount_kopeks),
    CONSTRAINT ck_umk_point_early CHECK (issued_at_unix>activated_at_unix AND amount_kopeks>=0 AND amount_kopeks<=10000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
