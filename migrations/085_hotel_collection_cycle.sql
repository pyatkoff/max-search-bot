CREATE TABLE IF NOT EXISTS hotel_collection_reviews (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 intake_stage CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 producer_stage CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 producer_operation CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 producer_run VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 review_task VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 capability_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 private_receipt LONGBLOB NOT NULL, instance_id VARCHAR(128) NULL,
 verdict_bytes LONGBLOB NULL, acceptance_bytes LONGBLOB NULL, finalize_sha256 CHAR(64) NULL,
 PRIMARY KEY(queue_id,intake_stage), UNIQUE KEY producer_review(queue_id,producer_operation)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_collection_outbox (
 event_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state VARCHAR(16) NOT NULL
) ENGINE=InnoDB;
