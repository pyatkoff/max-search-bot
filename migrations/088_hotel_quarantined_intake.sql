-- One same-claim quarantined intake. No runtime activation or automatic adoption.
CREATE TABLE IF NOT EXISTS hotel_quarantined_intakes (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 control_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 review_task CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 capability_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 packet_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 packet_bytes LONGBLOB NOT NULL, members_bytes LONGBLOB NOT NULL,
 protected_bytes LONGBLOB NOT NULL,
 producer_operation VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 producer_run VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 working_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 private_receipt LONGBLOB NOT NULL,required_reads_bytes LONGBLOB NOT NULL, instance_id VARCHAR(128) NULL,
 verdict_bytes LONGBLOB NULL, acceptance_bytes LONGBLOB NULL,
 accepted_working_bytes LONGBLOB NULL,accepted_working_sha256 CHAR(64) NULL,
 finalize_sha256 CHAR(64) NULL,
 PRIMARY KEY(queue_id,stage_id), UNIQUE KEY quarantine_packet_once(queue_id,packet_sha256)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_quarantined_read_coverage (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 read_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 value_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 next_offset INTEGER NOT NULL,total_bytes INTEGER NOT NULL,
 PRIMARY KEY(queue_id,stage_id,read_key)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_quarantine_intake_permits (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 session_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 stage_index INTEGER NOT NULL,claim_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target_phase INTEGER NOT NULL,target_accepted INTEGER NOT NULL,
 PRIMARY KEY(queue_id,session_id)
) ENGINE=InnoDB;
DROP TRIGGER IF EXISTS hotel_quarantine_queue_update;
DROP TRIGGER IF EXISTS hotel_quarantine_receipt_insert;
CREATE TRIGGER hotel_quarantine_queue_update BEFORE UPDATE ON hotel_work_queues FOR EACH ROW SET NEW.queue_id=IF(OLD.write_protocol='quarantine_v1' AND NOT(((NEW.queue_id <=> OLD.queue_id) AND (NEW.project_key <=> OLD.project_key) AND (NEW.admin_id <=> OLD.admin_id) AND (NEW.oauth_connection_id <=> OLD.oauth_connection_id) AND (NEW.mode <=> OLD.mode) AND (NEW.batch_limit <=> OLD.batch_limit) AND (NEW.start_country <=> OLD.start_country) AND (NEW.initial_phase_counter <=> OLD.initial_phase_counter) AND (NEW.phase_counter <=> OLD.phase_counter) AND (NEW.baseline_sha256 <=> OLD.baseline_sha256) AND (NEW.plan_sha256 <=> OLD.plan_sha256) AND (NEW.stage_index <=> OLD.stage_index) AND (NEW.state <=> OLD.state) AND (NEW.claim_sha256 <=> OLD.claim_sha256) AND (NEW.current_run_id <=> OLD.current_run_id) AND (NEW.accepted_batches <=> OLD.accepted_batches) AND (NEW.write_protocol <=> OLD.write_protocol) AND (NEW.data_snapshot_sha256 <=> OLD.data_snapshot_sha256)) OR (((NEW.queue_id <=> OLD.queue_id) AND (NEW.project_key <=> OLD.project_key) AND (NEW.admin_id <=> OLD.admin_id) AND (NEW.oauth_connection_id <=> OLD.oauth_connection_id) AND (NEW.mode <=> OLD.mode) AND (NEW.batch_limit <=> OLD.batch_limit) AND (NEW.start_country <=> OLD.start_country) AND (NEW.initial_phase_counter <=> OLD.initial_phase_counter) AND (NEW.baseline_sha256 <=> OLD.baseline_sha256) AND (NEW.plan_sha256 <=> OLD.plan_sha256) AND (NEW.write_protocol <=> OLD.write_protocol) AND (NEW.data_snapshot_sha256 <=> OLD.data_snapshot_sha256)) AND EXISTS(SELECT 1 FROM hotel_quarantine_intake_permits p JOIN hotel_work_receipts r ON r.queue_id=p.queue_id AND r.stage_id=p.stage_id AND r.receipt_sha256=p.receipt_sha256 WHERE p.queue_id=OLD.queue_id AND p.session_id=CONNECTION_ID() AND p.stage_index=OLD.stage_index AND p.claim_sha256=OLD.claim_sha256 AND OLD.state='claimed' AND OLD.current_run_id IS NULL AND OLD.mode='continuous' AND NEW.stage_index=OLD.stage_index+1 AND NEW.state='ready' AND NEW.claim_sha256 IS NULL AND NEW.current_run_id IS NULL AND NEW.accepted_batches=p.target_accepted AND p.target_accepted=OLD.accepted_batches+1 AND NEW.phase_counter=p.target_phase AND p.target_phase=1-OLD.phase_counter FOR UPDATE))),(SELECT guard_id FROM hotel_legacy_quarantine_gate),NEW.queue_id);
CREATE TRIGGER hotel_quarantine_receipt_insert BEFORE INSERT ON hotel_work_receipts FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_queues WHERE queue_id=NEW.queue_id AND write_protocol='quarantine_v1' FOR UPDATE) AND NOT(EXISTS(SELECT 1 FROM hotel_quarantine_intake_permits p WHERE p.queue_id=NEW.queue_id AND p.session_id=CONNECTION_ID() AND p.stage_id=NEW.stage_id AND p.stage_index=NEW.stage_index AND p.receipt_sha256=NEW.receipt_sha256 AND p.receipt_sha256=SHA2(NEW.receipt_json,256) FOR UPDATE)),(SELECT guard_id FROM hotel_legacy_quarantine_gate),NEW.queue_id);
