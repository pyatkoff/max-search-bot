-- Separate finite saved-result phase; no old control, native grant or business row changes.
CREATE TABLE IF NOT EXISTS development_work_roadmap_phases (
 queue_id VARCHAR(128) NOT NULL PRIMARY KEY, execution_connection_id CHAR(32) NOT NULL,
 request_sha256 CHAR(64) NOT NULL, archive_sha256 CHAR(64) NOT NULL,
 manifest_sha256 CHAR(64) NOT NULL, manifest_bytes MEDIUMBLOB NOT NULL,
 binding_sha256 CHAR(64) NOT NULL, binding_bytes MEDIUMBLOB NOT NULL,
 implementation_sha256 CHAR(64) NOT NULL, state VARCHAR(24) NOT NULL,
 admitted_at BIGINT NOT NULL, deadline_at BIGINT NOT NULL, last_slot BIGINT NULL,
 read_count INT NOT NULL DEFAULT 0, review_calls INT NOT NULL DEFAULT 0,
 reserved_tokens BIGINT NOT NULL DEFAULT 0, reserved_micros BIGINT NOT NULL DEFAULT 0,
 last_reason VARCHAR(80) NULL, UNIQUE KEY uq_roadmap_manifest(manifest_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS development_work_roadmap_steps (
 queue_id VARCHAR(128) NOT NULL, task_id VARCHAR(128) NOT NULL, ordinal INT NOT NULL,
 kind VARCHAR(64) NOT NULL, input_sha256 CHAR(64) NOT NULL, input_bytes MEDIUMBLOB NOT NULL,
 created_cron_id CHAR(64) NULL, state VARCHAR(24) NOT NULL,
 producer_run_id VARCHAR(128) NULL, producer_cron_id CHAR(64) NULL, producer_at BIGINT NULL,
 candidate_sha256 CHAR(64) NULL, candidate_bytes MEDIUMBLOB NULL,
 snapshot_sha256 CHAR(64) NULL, snapshot_bytes MEDIUMBLOB NULL, snapshot_at BIGINT NULL,
 PRIMARY KEY(queue_id,task_id), UNIQUE KEY uq_roadmap_ordinal(queue_id,ordinal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS development_work_roadmap_reads (
 read_id CHAR(64) NOT NULL PRIMARY KEY, queue_id VARCHAR(128) NOT NULL, task_id VARCHAR(128) NOT NULL,
 cron_id CHAR(64) NOT NULL, cron_slot BIGINT NOT NULL, run_id VARCHAR(128) NOT NULL,
 started_at BIGINT NOT NULL, state VARCHAR(24) NOT NULL, result_sha256 CHAR(64) NULL,
 result_bytes MEDIUMBLOB NULL, snapshot_bytes MEDIUMBLOB NULL,
 UNIQUE KEY uq_roadmap_read_slot(queue_id,cron_slot), UNIQUE KEY uq_roadmap_read_run(run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS development_work_roadmap_reviews (
 attempt_id CHAR(64) NOT NULL PRIMARY KEY, queue_id VARCHAR(128) NOT NULL, task_id VARCHAR(128) NOT NULL,
 cron_id CHAR(64) NOT NULL, cron_slot BIGINT NOT NULL, started_at BIGINT NOT NULL, state VARCHAR(24) NOT NULL,
 request_sha256 CHAR(64) NOT NULL, request_bytes MEDIUMBLOB NOT NULL,
 response_id VARCHAR(256) NULL, response_sha256 CHAR(64) NULL, response_bytes MEDIUMBLOB NULL,
 actual_request_bytes MEDIUMBLOB NULL, report_sha256 CHAR(64) NULL, report_bytes MEDIUMBLOB NULL,
 metadata_json TEXT NULL, UNIQUE KEY uq_roadmap_review_task(queue_id,task_id),
 UNIQUE KEY uq_roadmap_review_slot(queue_id,cron_slot), UNIQUE KEY uq_roadmap_review_response(response_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
