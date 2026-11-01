-- Administrative retirement only. Empty audit means no queue is retired.
-- No acceptance, worker launch, OAuth change or lane mutation at installation.
CREATE TABLE IF NOT EXISTS hotel_work_retirements (
 queue_id VARBINARY(80) NOT NULL PRIMARY KEY,
 admin_id BIGINT UNSIGNED NOT NULL,
 owner_connection_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 original_state_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 backup_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 isolation_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_bytes LONGBLOB NOT NULL,
 receipt_bytes VARBINARY(4096) NOT NULL,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS hotel_retirement_gate(guard_id INTEGER NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT IGNORE INTO hotel_retirement_gate(guard_id) VALUES(1),(2);
CREATE TRIGGER hotel_retired_0_insert BEFORE INSERT ON hotel_work_queues FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_0_update BEFORE UPDATE ON hotel_work_queues FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_0_delete BEFORE DELETE ON hotel_work_queues FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_1_insert BEFORE INSERT ON hotel_work_receipts FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_1_update BEFORE UPDATE ON hotel_work_receipts FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_1_delete BEFORE DELETE ON hotel_work_receipts FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_2_insert BEFORE INSERT ON hotel_work_events FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_2_update BEFORE UPDATE ON hotel_work_events FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_2_delete BEFORE DELETE ON hotel_work_events FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_3_insert BEFORE INSERT ON hotel_work_connection_handoffs FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_3_update BEFORE UPDATE ON hotel_work_connection_handoffs FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_3_delete BEFORE DELETE ON hotel_work_connection_handoffs FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_4_insert BEFORE INSERT ON hotel_work_resolutions FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_4_update BEFORE UPDATE ON hotel_work_resolutions FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_4_delete BEFORE DELETE ON hotel_work_resolutions FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_5_insert BEFORE INSERT ON hotel_work_delivery_recoveries FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_5_update BEFORE UPDATE ON hotel_work_delivery_recoveries FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_5_delete BEFORE DELETE ON hotel_work_delivery_recoveries FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_6_insert BEFORE INSERT ON hotel_work_research_skips FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_6_update BEFORE UPDATE ON hotel_work_research_skips FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_6_delete BEFORE DELETE ON hotel_work_research_skips FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_7_insert BEFORE INSERT ON hotel_work_consumer_delivery_repairs FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_7_update BEFORE UPDATE ON hotel_work_consumer_delivery_repairs FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_7_delete BEFORE DELETE ON hotel_work_consumer_delivery_repairs FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_8_insert BEFORE INSERT ON hotel_work_execution_attempts FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_8_update BEFORE UPDATE ON hotel_work_execution_attempts FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_8_delete BEFORE DELETE ON hotel_work_execution_attempts FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_9_insert BEFORE INSERT ON hotel_work_execution_attempt_entries FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_9_update BEFORE UPDATE ON hotel_work_execution_attempt_entries FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_9_delete BEFORE DELETE ON hotel_work_execution_attempt_entries FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_10_insert BEFORE INSERT ON hotel_work_execution_attempt_associations FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_10_update BEFORE UPDATE ON hotel_work_execution_attempt_associations FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id,NEW.queue_id) FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_10_delete BEFORE DELETE ON hotel_work_execution_attempt_associations FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN(OLD.queue_id) FOR UPDATE),2,guard_id) WHERE guard_id=1;
CREATE TRIGGER hotel_retired_lane_insert BEFORE INSERT ON hotel_work_lane FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id=NEW.queue_id FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retired_lane_update BEFORE UPDATE ON hotel_work_lane FOR EACH ROW SET NEW.queue_id=IF(EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id=NEW.queue_id FOR UPDATE),(SELECT guard_id FROM hotel_retirement_gate),NEW.queue_id);
CREATE TRIGGER hotel_retirement_immutable_update BEFORE UPDATE ON hotel_work_retirements FOR EACH ROW SET NEW.queue_id=(SELECT guard_id FROM hotel_retirement_gate);
CREATE TRIGGER hotel_retirement_immutable_delete BEFORE DELETE ON hotel_work_retirements FOR EACH ROW UPDATE hotel_retirement_gate SET guard_id=2 WHERE guard_id=1;

