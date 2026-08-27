ALTER TABLE conversations ADD COLUMN lead_outcome VARCHAR(32) NULL AFTER lead_stage_key;
ALTER TABLE conversations ADD COLUMN lead_close_reason VARCHAR(64) NULL AFTER lead_outcome;
ALTER TABLE conversations ADD COLUMN lead_outcome_note VARCHAR(500) NULL AFTER lead_close_reason;
ALTER TABLE conversations ADD COLUMN lead_outcome_updated_at DATETIME NULL AFTER lead_outcome_note;
ALTER TABLE conversations ADD COLUMN lead_outcome_manager_id INT NULL AFTER lead_outcome_updated_at;
CREATE INDEX idx_conversations_lead_outcome ON conversations (lead_outcome);
