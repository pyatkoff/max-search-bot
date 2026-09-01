ALTER TABLE conversation_sources
    ADD COLUMN handling_mode ENUM('ai','manager','ask') NOT NULL DEFAULT 'ai' AFTER channel;
