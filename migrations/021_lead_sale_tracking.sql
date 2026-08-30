ALTER TABLE conversations
    ADD COLUMN lead_sale_amount DECIMAL(12,2) NULL AFTER lead_outcome_note,
    ADD COLUMN lead_sale_date DATE NULL AFTER lead_sale_amount;
