-- Empty append-only evidence for explicit corrections of a known rejected final stage.
-- No campaign, runtime, enable, budget, original evidence or acceptance is changed here.
CREATE TABLE IF NOT EXISTS hotel_server_corrections (
 campaign_id VARBINARY(80) NOT NULL,
 stage_index INTEGER NOT NULL,
 correction_sha256 CHAR(64) NOT NULL,
 correction_bytes LONGBLOB NOT NULL,
 candidate_sha256 CHAR(64) NOT NULL,
 candidate_bytes LONGBLOB NOT NULL,
 PRIMARY KEY(campaign_id,stage_index),
 UNIQUE(correction_sha256)
) ENGINE=InnoDB;
CREATE TRIGGER hotel_server_corrections_immutable_update BEFORE UPDATE ON hotel_server_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_corrections_immutable_delete BEFORE DELETE ON hotel_server_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
