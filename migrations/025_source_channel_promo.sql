ALTER TABLE conversation_sources
    ADD COLUMN suppress_channel_offer TINYINT(1) NOT NULL DEFAULT 0 AFTER handling_mode;

-- Existing regional AnyTour source markers are links from our own channels.
-- Generic transport sources (max:anytour-main / telegram:anytour-main) stay enabled
-- so paid traffic into a messenger can still receive the channel offer.
UPDATE conversation_sources s
JOIN projects p ON p.id=s.project_id
SET s.suppress_channel_offer=1
WHERE p.project_key='anytour'
  AND s.source_key IN (
      'max_anytour_msk',
      'max_anytour_msk1',
      'max_anytour_spb',
      'tg_anytour_msk',
      'tg_anytour_msk2'
  );
