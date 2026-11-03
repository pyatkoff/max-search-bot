ALTER TABLE modeled_conversion_value_export_settings
    ADD COLUMN history_fortnight_requested_at_unix BIGINT UNSIGNED NULL,
    ADD COLUMN history_day_receipt_json JSON NULL;
