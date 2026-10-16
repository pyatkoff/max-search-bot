-- Preserve each original wire target before the explicit shared-goal cutover.
-- This migration never changes receipts, event instants, identity or mode.
ALTER TABLE max_channel_conversion_outbox
    ADD COLUMN transport_target VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER target;

UPDATE max_channel_conversion_outbox
SET transport_target=CASE target
    WHEN 'max_bot_activity_start' THEN 'max_channel_activity_start'
    WHEN 'max_manager_reply' THEN 'max_channel_manager_reply'
    WHEN 'max_customer_reply_after_manager' THEN 'max_channel_customer_reply_after_manager'
    ELSE '' END
WHERE project_key='anytour' AND counter_id='98615635';

ALTER TABLE max_channel_attribution_settings
    ADD COLUMN goal_set VARCHAR(16) NOT NULL DEFAULT 'channel',
    ADD COLUMN shared_goals_activated_at_unix BIGINT UNSIGNED NULL,
    ADD COLUMN shared_goals_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN request_event_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0;
