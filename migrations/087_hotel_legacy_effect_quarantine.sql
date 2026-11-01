-- Dormant preservation barrier, not cancellation, acceptance or a new queue.
CREATE TABLE IF NOT EXISTS hotel_legacy_effect_quarantine (
 queue_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 stage_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 original_run_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 queue_bytes LONGBLOB NOT NULL,
 working_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 working_bytes LONGBLOB NOT NULL,
 packet_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 receipt_bytes LONGBLOB NOT NULL
) ENGINE=InnoDB;
-- Constraint gate for single-statement DELETE triggers. A protected DELETE
-- attempts a duplicate primary key. Ordinary deletes leave the row unchanged.
CREATE TABLE IF NOT EXISTS hotel_legacy_quarantine_gate(guard_id INTEGER NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT IGNORE INTO hotel_legacy_quarantine_gate(guard_id) VALUES(1),(2);
CREATE TRIGGER hotel_quarantine_queue_update BEFORE UPDATE ON hotel_work_queues FOR EACH ROW SET NEW.queue_id=IF(OLD.write_protocol='quarantine_v1' AND NOT((NEW.queue_id <=> OLD.queue_id) AND (NEW.project_key <=> OLD.project_key) AND (NEW.admin_id <=> OLD.admin_id) AND (NEW.oauth_connection_id <=> OLD.oauth_connection_id) AND (NEW.mode <=> OLD.mode) AND (NEW.batch_limit <=> OLD.batch_limit) AND (NEW.start_country <=> OLD.start_country) AND (NEW.initial_phase_counter <=> OLD.initial_phase_counter) AND (NEW.phase_counter <=> OLD.phase_counter) AND (NEW.baseline_sha256 <=> OLD.baseline_sha256) AND (NEW.plan_sha256 <=> OLD.plan_sha256) AND (NEW.stage_index <=> OLD.stage_index) AND (NEW.state <=> OLD.state) AND (NEW.claim_sha256 <=> OLD.claim_sha256) AND (NEW.current_run_id <=> OLD.current_run_id) AND (NEW.accepted_batches <=> OLD.accepted_batches) AND (NEW.write_protocol <=> OLD.write_protocol) AND (NEW.data_snapshot_sha256 <=> OLD.data_snapshot_sha256)),(SELECT guard_id FROM hotel_legacy_quarantine_gate),NEW.queue_id);
CREATE TRIGGER hotel_quarantine_queue_delete BEFORE DELETE ON hotel_work_queues FOR EACH ROW UPDATE hotel_legacy_quarantine_gate SET guard_id=IF(OLD.write_protocol='quarantine_v1',2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_quarantine_receipt_insert BEFORE INSERT ON hotel_work_receipts FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_queues WHERE queue_id=NEW.queue_id AND write_protocol='quarantine_v1' FOR UPDATE),(SELECT guard_id FROM hotel_legacy_quarantine_gate),NEW.queue_id);
CREATE TRIGGER hotel_quarantine_receipt_update BEFORE UPDATE ON hotel_work_receipts FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_queues WHERE queue_id IN(OLD.queue_id,NEW.queue_id) AND write_protocol='quarantine_v1' FOR UPDATE),(SELECT guard_id FROM hotel_legacy_quarantine_gate),NEW.queue_id);
CREATE TRIGGER hotel_quarantine_receipt_delete BEFORE DELETE ON hotel_work_receipts FOR EACH ROW UPDATE hotel_legacy_quarantine_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_queues WHERE queue_id=OLD.queue_id AND write_protocol='quarantine_v1' FOR UPDATE),2,guard_id) WHERE guard_id=1;
