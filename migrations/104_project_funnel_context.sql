-- Forward-only context for the existing observation/outbox owners; no historical backfill.
ALTER TABLE advertising_visit_observations
    ADD COLUMN post_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    ADD COLUMN offer_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
    ADD COLUMN context_json TEXT NULL;

-- Reuse the existing durable conversion queue and receipt state machine.
ALTER TABLE max_channel_conversion_outbox
    ADD COLUMN funnel_context_json TEXT NULL,
    ADD COLUMN funnel_policy_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN value_rub DECIMAL(11,2) NULL;
