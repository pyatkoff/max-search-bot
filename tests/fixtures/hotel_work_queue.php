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
                foreach (['hotel_work_resolutions','hotel_work_connection_handoffs','hotel_work_receipts','hotel_work_queues','hotel_work_lane'] as $table) parent::exec('DROP TABLE IF EXISTS '.$table);
                foreach (['070_hotel_work_queue.sql','072_hotel_work_recovery.sql'] as $migration) foreach (explode(';',(string)file_get_contents(dirname(__DIR__,2).'/migrations/'.$migration)) as $sql) if (trim($sql)!=='') parent::exec($sql);
            }
        } else {
            parent::__construct($sqliteFile===null?'sqlite::memory:':'sqlite:'.$sqliteFile,null,null,$options);
            parent::exec('PRAGMA busy_timeout=5000');
            if ($reset) parent::exec('CREATE TABLE hotel_work_queues (
                queue_id TEXT PRIMARY KEY,project_key TEXT NOT NULL,admin_id INTEGER NOT NULL,oauth_connection_id TEXT NOT NULL,
                mode TEXT NOT NULL,batch_limit INTEGER,start_country TEXT NOT NULL,initial_phase_counter INTEGER NOT NULL,
                phase_counter INTEGER NOT NULL,baseline_sha256 TEXT NOT NULL,plan_sha256 TEXT NOT NULL,
                stage_index INTEGER NOT NULL DEFAULT 0,state TEXT NOT NULL,claim_sha256 TEXT,current_run_id TEXT,
                accepted_batches INTEGER NOT NULL DEFAULT 0,event_ack TEXT,delivered_at TEXT,claim_deadline TEXT,
                attention_at TEXT,attention_ack TEXT,watchdog_due_at TEXT,watchdog_epoch INTEGER NOT NULL DEFAULT 0,last_watch_ack TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
                CREATE TABLE hotel_work_receipts(queue_id TEXT NOT NULL,stage_index INTEGER NOT NULL,stage_id TEXT NOT NULL UNIQUE,
                receipt_sha256 TEXT NOT NULL,receipt_json TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(queue_id,stage_index));
                CREATE TABLE hotel_work_lane(lane_key TEXT PRIMARY KEY,queue_id TEXT);INSERT INTO hotel_work_lane VALUES (\'HOTEL\',NULL);
                CREATE TABLE hotel_work_connection_handoffs(handoff_sha256 TEXT PRIMARY KEY,queue_id TEXT NOT NULL,admin_id INTEGER NOT NULL,
                old_connection_id TEXT NOT NULL,new_connection_id TEXT NOT NULL,stage_id TEXT NOT NULL,baseline_sha256 TEXT NOT NULL,
                handoff_mode TEXT NOT NULL,expected_run_id TEXT,claim_sha256 TEXT,created_at TEXT NOT NULL);
                CREATE TABLE hotel_work_resolutions(queue_id TEXT NOT NULL,stage_index INTEGER NOT NULL,stage_id TEXT NOT NULL UNIQUE,
                unknown_receipt_sha256 TEXT NOT NULL,resolution_sha256 TEXT NOT NULL,receipt_sha256 TEXT NOT NULL,
                receipt_json TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(queue_id,stage_index));');
        }
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HotelWorkTestStatement::class,[$this]]);
    }
    public function observe(string $sql): void
    {
        if (preg_match('/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+([a-z_]+)/i',$sql,$m)) {
            if (!in_array(strtolower($m[1]),['hotel_work_queues','hotel_work_receipts','hotel_work_lane','hotel_work_connection_handoffs','hotel_work_resolutions'],true)) throw new RuntimeException('hotel_fixture_rejected_business_write');
            $this->writes[]=strtolower($m[1]);
        }
    }
}
