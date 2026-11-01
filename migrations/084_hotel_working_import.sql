-- Immutable data import; original queue baseline and accepted history are unchanged.
CREATE TABLE IF NOT EXISTS hotel_working_imports (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 request_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 input_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 data_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 origin_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body_bytes LONGBLOB NOT NULL, receipt_bytes LONGBLOB NOT NULL
) ENGINE=InnoDB;
