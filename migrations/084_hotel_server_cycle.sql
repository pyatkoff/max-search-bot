-- Application/ConversationDb only. Empty dormant tables: no campaign or activation.
-- The HotelDatabase projection migration is deliberately outside the default glob.
CREATE TABLE IF NOT EXISTS hotel_server_campaigns (
 campaign_id VARBINARY(80) NOT NULL PRIMARY KEY,
 state VARCHAR(16) NOT NULL,
 enabled INTEGER NOT NULL DEFAULT 0,
 cursor INTEGER NOT NULL DEFAULT 0,
 new_accepted INTEGER NOT NULL DEFAULT 0,
 historical_accepted INTEGER NOT NULL DEFAULT 19,
 baseline_sha256 CHAR(64) NOT NULL,
 inputs_sha256 CHAR(64) NOT NULL,
 bootstrap_sha256 CHAR(64) NOT NULL,
 plan_bytes LONGBLOB NOT NULL,
 reserved_calls INTEGER NOT NULL DEFAULT 0,
 reserved_tokens BIGINT NOT NULL DEFAULT 0,
 reserved_micros BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_inputs (
 campaign_id VARBINARY(80) NOT NULL PRIMARY KEY,
 inputs_sha256 CHAR(64) NOT NULL,
 package_bytes LONGBLOB NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_baselines (
 campaign_id VARBINARY(80) NOT NULL,
 baseline_sha256 CHAR(64) NOT NULL,
 parent_sha256 CHAR(64) NULL,
 document_bytes LONGBLOB NOT NULL,
 PRIMARY KEY(campaign_id,baseline_sha256)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_exclusions (
 campaign_id VARBINARY(80) NOT NULL,
 semantic_key CHAR(64) NOT NULL,
 kind VARCHAR(32) NOT NULL,
 PRIMARY KEY(campaign_id,semantic_key)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_attempts (
 attempt_id CHAR(64) NOT NULL PRIMARY KEY,
 campaign_id VARBINARY(80) NOT NULL,
 stage_index INTEGER NOT NULL,
 role VARCHAR(16) NOT NULL,
 principal VARBINARY(256) NOT NULL,
 state VARCHAR(16) NOT NULL,
 baseline_sha256 CHAR(64) NOT NULL,
 request_sha256 CHAR(64) NOT NULL,
 request_bytes LONGBLOB NOT NULL,
 result_sha256 CHAR(64) NULL,
 result_bytes LONGBLOB NULL,
 UNIQUE(campaign_id,stage_index,role)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_candidates (
 campaign_id VARBINARY(80) NOT NULL,
 stage_index INTEGER NOT NULL,
 candidate_sha256 CHAR(64) NOT NULL,
 producer_attempt CHAR(64) NOT NULL,
 candidate_bytes LONGBLOB NOT NULL,
 PRIMARY KEY(campaign_id,stage_index),
 UNIQUE(producer_attempt)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_reviews (
 campaign_id VARBINARY(80) NOT NULL,
 stage_index INTEGER NOT NULL,
 review_attempt CHAR(64) NOT NULL,
 candidate_sha256 CHAR(64) NOT NULL,
 approved INTEGER NOT NULL,
 review_bytes LONGBLOB NOT NULL,
 PRIMARY KEY(campaign_id,stage_index),
 UNIQUE(review_attempt)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_receipts (
 campaign_id VARBINARY(80) NOT NULL,
 stage_index INTEGER NOT NULL,
 receipt_sha256 CHAR(64) NOT NULL,
 receipt_bytes LONGBLOB NOT NULL,
 PRIMARY KEY(campaign_id,stage_index),
 UNIQUE(receipt_sha256)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_records (
 semantic_key CHAR(64) NOT NULL PRIMARY KEY,
 campaign_id VARBINARY(80) NOT NULL,
 candidate_sha256 CHAR(64) NOT NULL,
 record_bytes LONGBLOB NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_outbox (
 campaign_id VARBINARY(80) NOT NULL,
 stage_index INTEGER NOT NULL,
 role VARCHAR(16) NOT NULL,
 state VARCHAR(16) NOT NULL,
 attempt_id CHAR(64) NULL,
 PRIMARY KEY(campaign_id,stage_index,role)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_server_catalog_outbox (
 receipt_sha256 CHAR(64) NOT NULL PRIMARY KEY,
 campaign_id VARBINARY(80) NOT NULL,
 payload_bytes LONGBLOB NOT NULL,
 state VARCHAR(16) NOT NULL
) ENGINE=InnoDB;
CREATE TRIGGER hotel_server_inputs_immutable_update BEFORE UPDATE ON hotel_server_inputs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_inputs_immutable_delete BEFORE DELETE ON hotel_server_inputs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_baselines_immutable_update BEFORE UPDATE ON hotel_server_baselines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_baselines_immutable_delete BEFORE DELETE ON hotel_server_baselines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_candidates_immutable_update BEFORE UPDATE ON hotel_server_candidates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_candidates_immutable_delete BEFORE DELETE ON hotel_server_candidates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_reviews_immutable_update BEFORE UPDATE ON hotel_server_reviews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_reviews_immutable_delete BEFORE DELETE ON hotel_server_reviews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_receipts_immutable_update BEFORE UPDATE ON hotel_server_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_receipts_immutable_delete BEFORE DELETE ON hotel_server_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_records_immutable_update BEFORE UPDATE ON hotel_server_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
CREATE TRIGGER hotel_server_records_immutable_delete BEFORE DELETE ON hotel_server_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='immutable hotel server evidence';
