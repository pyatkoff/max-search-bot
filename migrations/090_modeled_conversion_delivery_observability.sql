-- Once-only observation clocks. Legacy rows remain unknown; no business data backfill.
ALTER TABLE max_channel_conversion_outbox
    ADD COLUMN value_ready_at_unix BIGINT UNSIGNED NULL,
    ADD COLUMN value_api_accepted_at_unix BIGINT UNSIGNED NULL,
    ADD COLUMN value_processed_at_unix BIGINT UNSIGNED NULL,
    ADD COLUMN value_receipt_basis VARCHAR(24) NULL,
    ADD KEY idx_value_delivery_claim (project_key,counter_id,transport_target,value_contract,claimed_at_unix),
    ADD KEY idx_value_delivery_receipt (project_key,counter_id,transport_target,value_contract,value_api_accepted_at_unix),
    ADD KEY idx_value_delivery_processed (project_key,counter_id,transport_target,value_contract,value_processed_at_unix);

ALTER TABLE modeled_conversion_value_export_settings
    ADD COLUMN last_report_success_unix BIGINT UNSIGNED NULL,
    ADD COLUMN last_report_success_json JSON NULL,
    ADD COLUMN report_failure_since_unix BIGINT UNSIGNED NULL;
