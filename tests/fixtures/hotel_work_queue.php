<?php
declare(strict_types=1);

final class HotelWorkTestStatement extends PDOStatement
{
    protected function __construct(private HotelWorkTestPdo $owner) {}
    public function execute(?array $params=null): bool { $this->owner->observe($this->queryString);return parent::execute($params); }
}

final class HotelWorkTestPdo extends PDO
{
    public array $writes=[];
    public function __construct(?string $sqliteFile=null,bool $reset=true)
    {
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false];
        $mysql=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1';
        if ($mysql) {
            $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
            if (!str_starts_with($dsn,'mysql:')||preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)!==1) throw new RuntimeException('unsafe_hotel_fixture_database');
            $options[PDO::ATTR_EMULATE_PREPARES]=false;
            parent::__construct($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
            if (parent::query('SELECT DATABASE()')->fetchColumn()!==$m[1]) throw new RuntimeException('unsafe_hotel_fixture_database');
            if ($reset) {
                foreach (['hotel_journal_requests','hotel_journal_snapshots','hotel_journal_operations','hotel_journal_heads','hotel_work_execution_attempt_associations','hotel_work_execution_attempt_entries','hotel_work_execution_attempts','hotel_work_consumer_delivery_repairs','hotel_work_research_skips','hotel_work_delivery_recoveries','hotel_work_resolutions','hotel_work_connection_handoffs','hotel_work_receipts','hotel_work_queues','hotel_work_lane'] as $table) parent::exec('DROP TABLE IF EXISTS '.$table);
                foreach (['070_hotel_work_queue.sql','072_hotel_work_recovery.sql','073_hotel_work_delivery_recoveries.sql','076_hotel_work_research_skips.sql','077_hotel_work_consumer_delivery_repairs.sql','078_hotel_work_execution_attempts.sql','082_hotel_operation_journal.sql'] as $migration) foreach (explode(';',(string)file_get_contents(dirname(__DIR__,2).'/migrations/'.$migration)) as $sql) if (trim($sql)!=='') parent::exec($sql);
            }
        } else {
            parent::__construct($sqliteFile===null?'sqlite::memory:':'sqlite:'.$sqliteFile,null,null,$options);
            parent::exec('PRAGMA busy_timeout=5000');
            if ($reset) parent::exec('CREATE TABLE hotel_work_queues (
                queue_id TEXT PRIMARY KEY,project_key TEXT NOT NULL,admin_id INTEGER NOT NULL,oauth_connection_id TEXT NOT NULL,
                mode TEXT NOT NULL,batch_limit INTEGER,start_country TEXT NOT NULL,initial_phase_counter INTEGER NOT NULL,
                phase_counter INTEGER NOT NULL,baseline_sha256 TEXT NOT NULL,plan_sha256 TEXT NOT NULL,
                write_protocol TEXT NOT NULL DEFAULT \'legacy\',stage_index INTEGER NOT NULL DEFAULT 0,state TEXT NOT NULL,claim_sha256 TEXT,current_run_id TEXT,
                accepted_batches INTEGER NOT NULL DEFAULT 0,event_ack TEXT,delivered_at TEXT,claim_deadline TEXT,
                attention_at TEXT,attention_ack TEXT,watchdog_due_at TEXT,watchdog_epoch INTEGER NOT NULL DEFAULT 0,last_watch_ack TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
                CREATE TABLE hotel_work_receipts(queue_id TEXT NOT NULL,stage_index INTEGER NOT NULL,stage_id TEXT NOT NULL UNIQUE,
                receipt_sha256 TEXT NOT NULL,receipt_json TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(queue_id,stage_index));
                CREATE TABLE hotel_work_lane(lane_key TEXT PRIMARY KEY,queue_id TEXT);INSERT INTO hotel_work_lane VALUES (\'HOTEL\',NULL);
                CREATE TABLE hotel_work_connection_handoffs(handoff_sha256 TEXT PRIMARY KEY,queue_id TEXT NOT NULL,admin_id INTEGER NOT NULL,
                old_connection_id TEXT NOT NULL,new_connection_id TEXT NOT NULL,stage_id TEXT NOT NULL,baseline_sha256 TEXT NOT NULL,
                handoff_mode TEXT NOT NULL,expected_run_id TEXT,claim_sha256 TEXT,created_at TEXT NOT NULL);
                CREATE TABLE hotel_work_delivery_recoveries(recovery_sha256 TEXT PRIMARY KEY,queue_id TEXT NOT NULL,
                stage_id TEXT NOT NULL,admin_id INTEGER NOT NULL,oauth_connection_id TEXT NOT NULL,baseline_sha256 TEXT NOT NULL,
                resume_sha256 TEXT NOT NULL,pending_event_id TEXT NOT NULL,attention_event_id TEXT NOT NULL,
                delivered_at TEXT NOT NULL,claim_deadline TEXT NOT NULL,attention_at TEXT NOT NULL,previous_updated_at TEXT NOT NULL,
                created_at TEXT NOT NULL,UNIQUE(queue_id,stage_id));
                CREATE TABLE hotel_work_consumer_delivery_repairs(repair_sha256 TEXT PRIMARY KEY,queue_id TEXT NOT NULL,
                stage_id TEXT NOT NULL,stage_index INTEGER NOT NULL,request_sha256 TEXT NOT NULL,audit_json BLOB NOT NULL,
                created_at TEXT NOT NULL,event_ack TEXT,delivered_at TEXT,claim_deadline TEXT,attention_at TEXT,attention_ack TEXT,
                updated_at TEXT NOT NULL,UNIQUE(queue_id,stage_id));
                CREATE TABLE hotel_work_research_skips(skip_sha256 TEXT NOT NULL UNIQUE,queue_id TEXT NOT NULL,stage_index INTEGER NOT NULL,
                stage_id TEXT NOT NULL,admin_id INTEGER NOT NULL,old_connection_id TEXT NOT NULL,new_connection_id TEXT NOT NULL,
                baseline_sha256 TEXT NOT NULL,run_id TEXT NOT NULL,claim_sha256 TEXT NOT NULL,previous_state TEXT NOT NULL,
                unknown_receipt_sha256 TEXT,decision_sha256 TEXT NOT NULL,external_effects_status TEXT NOT NULL,
                external_effects_readback_sha256 TEXT NOT NULL,next_stage_index INTEGER NOT NULL,created_at TEXT NOT NULL,
                PRIMARY KEY(queue_id,stage_index),UNIQUE(queue_id,stage_id));
                CREATE TABLE hotel_work_resolutions(queue_id TEXT NOT NULL,stage_index INTEGER NOT NULL,stage_id TEXT NOT NULL UNIQUE,
                unknown_receipt_sha256 TEXT NOT NULL,resolution_sha256 TEXT NOT NULL,receipt_sha256 TEXT NOT NULL,
                receipt_json TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(queue_id,stage_index));
                CREATE TABLE hotel_work_execution_attempts(permit_sha256 TEXT PRIMARY KEY,queue_id TEXT NOT NULL,stage_id TEXT NOT NULL,
                stage_index INTEGER NOT NULL,request_sha256 TEXT NOT NULL,attempt_token TEXT NOT NULL UNIQUE,canonical_run_id TEXT NOT NULL,
                claim_sha256 TEXT NOT NULL,audit_json BLOB NOT NULL,created_at TEXT NOT NULL,UNIQUE(queue_id,stage_id));
                CREATE TABLE hotel_work_execution_attempt_entries(entry_sha256 TEXT PRIMARY KEY,permit_sha256 TEXT NOT NULL UNIQUE,
                queue_id TEXT NOT NULL,stage_id TEXT NOT NULL,request_sha256 TEXT NOT NULL,instance_id TEXT NOT NULL UNIQUE,
                audit_json BLOB NOT NULL,created_at TEXT NOT NULL,UNIQUE(queue_id,stage_id));
                CREATE TABLE hotel_work_execution_attempt_associations(association_sha256 TEXT PRIMARY KEY,permit_sha256 TEXT NOT NULL UNIQUE,
                entry_sha256 TEXT NOT NULL UNIQUE,queue_id TEXT NOT NULL,stage_id TEXT NOT NULL,request_sha256 TEXT NOT NULL,
                producer_run_id TEXT NOT NULL UNIQUE,packet_sha256 TEXT NOT NULL,audit_json BLOB NOT NULL,created_at TEXT NOT NULL,
                UNIQUE(queue_id,stage_id));');
        }
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HotelWorkTestStatement::class,[$this]]);
    }
    /** Reapply the real forward migration after native tests have stored evidence. */
    public function reapplyDeliveryRecoveryMigration(): void
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new LogicException('native_mysql_required_for_recovery_migration');
        $source=(string)file_get_contents(dirname(__DIR__,2).'/migrations/073_hotel_work_delivery_recoveries.sql');
        if (trim($source)==='') throw new RuntimeException('recovery_migration_source_missing');
        foreach (explode(';',$source) as $sql) if (trim($sql)!=='') $this->exec($sql);
    }
    public function reapplyConsumerDeliveryRepairMigration(): void
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new LogicException('native_mysql_required_for_repair_migration');
        $source=(string)file_get_contents(dirname(__DIR__,2).'/migrations/077_hotel_work_consumer_delivery_repairs.sql');
        if (trim($source)==='') throw new RuntimeException('repair_migration_source_missing');
        foreach (explode(';',$source) as $sql) if (trim($sql)!=='') $this->exec($sql);
    }
    public function reapplyExecutionAttemptMigration(): void
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new LogicException('native_mysql_required_for_execution_attempt_migration');
        $source=(string)file_get_contents(dirname(__DIR__,2).'/migrations/078_hotel_work_execution_attempts.sql');
        if (trim($source)==='') throw new RuntimeException('execution_attempt_migration_source_missing');
        foreach (explode(';',$source) as $sql) if (trim($sql)!=='') $this->exec($sql);
    }
    public function observe(string $sql): void
    {
        if (preg_match('/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+([a-z_]+)/i',$sql,$m)) {
            if (!in_array(strtolower($m[1]),['hotel_work_queues','hotel_work_receipts','hotel_work_lane','hotel_work_connection_handoffs','hotel_work_resolutions','hotel_work_delivery_recoveries','hotel_work_research_skips','hotel_work_consumer_delivery_repairs','hotel_work_execution_attempts','hotel_work_execution_attempt_entries','hotel_work_execution_attempt_associations','hotel_journal_heads','hotel_journal_operations','hotel_journal_requests','hotel_journal_snapshots','hotel_collection_outbox'],true)) throw new RuntimeException('hotel_fixture_rejected_business_write');
            $this->writes[]=strtolower($m[1]);
        }
    }
}

