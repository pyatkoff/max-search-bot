-- Forward-only private server-issued context. No historical reconstruction or media bytes.
CREATE TABLE IF NOT EXISTS manager_prepared_reply_inputs (
    receipt_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    admin_id BIGINT UNSIGNED NOT NULL,
    connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    packet_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    packet_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_at DATETIME NOT NULL,
    PRIMARY KEY (receipt_id),
    KEY idx_prepared_input_job (job_id,issued_at),
    CONSTRAINT fk_prepared_input_job FOREIGN KEY (job_id)
        REFERENCES manager_prepared_replies(job_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Written in the same transaction as the first successful pending-to-ready CAS.
CREATE TABLE IF NOT EXISTS manager_prepared_reply_input_links (
    job_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (job_id),
    UNIQUE KEY uq_prepared_input_receipt (receipt_id),
    CONSTRAINT fk_prepared_input_link_job FOREIGN KEY (job_id)
        REFERENCES manager_prepared_replies(job_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_prepared_input_link_receipt FOREIGN KEY (receipt_id)
        REFERENCES manager_prepared_reply_inputs(receipt_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
