-- A single explicitly admitted consumer-delivery generation. Original queue
-- pointers and resume/recovery evidence remain immutable. Only this cursor moves.
CREATE TABLE IF NOT EXISTS hotel_work_consumer_delivery_repairs (
    repair_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    queue_id VARBINARY(80) NOT NULL,
    stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    stage_index INT UNSIGNED NOT NULL,
    request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    audit_json VARBINARY(8192) NOT NULL,
    created_at DATETIME NOT NULL,
    event_ack VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    delivered_at DATETIME NULL,
    claim_deadline DATETIME NULL,
    attention_at DATETIME NULL,
    attention_ack VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (repair_sha256),
    UNIQUE KEY uq_hotel_consumer_delivery_repair_stage (queue_id,stage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
