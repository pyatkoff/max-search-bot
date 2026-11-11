-- Point reads of retained native MAX identity witnesses across conversation namespaces.
-- No row or financial-state backfill; the reader fails closed until this exact index exists.
ALTER TABLE max_channel_conversion_outbox
    ADD KEY ix_max_identity_point(project_key,platform,external_user_id,counter_id,id);
