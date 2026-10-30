<?php
declare(strict_types=1);

/** Real isolated SQL state. Only webhook DNS/transport and grant authority are fakes. */
final class HotelEventsTestStatement extends PDOStatement
{
    protected function __construct(private HotelEventsTestPdo $owner) {}
    public function execute(?array $params=null): bool
    {
        $this->owner->observe($this->queryString);
        $ok=parent::execute($params);
        if ($ok&&$this->rowCount()===1&&str_contains($this->queryString,'UPDATE hotel_work_events SET lease_token=')
            &&$this->owner->afterLease!==null) {
            $hook=$this->owner->afterLease;$this->owner->afterLease=null;$hook();
        }
        return $ok;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        $rows=parent::fetchAll($mode,...$args);
        if (preg_match('/\A\s*SELECT\b.*\bFROM\s+hotel_work_events\b/is',$this->queryString)===1
            &&$this->owner->afterEventRead!==null) {
            $hook=$this->owner->afterEventRead;$this->owner->afterEventRead=null;$hook();
        }
        return $rows;
    }
}

final class HotelEventsTestPdo extends PDO
{
    public array $writes=[];
    public $afterLease=null;
    public array $eventReads=[];
    public $beforeEventRead=null;
    public $afterEventRead=null;
    public bool $failEventRead=false;
    public function __construct()
    {
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false];
        if (getenv('HOTEL_WORK_EVENTS_NATIVE_MYSQL')==='1') {
            $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
            if (!str_starts_with($dsn,'mysql:')||preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$dsn,$m)!==1) throw new RuntimeException('unsafe_hotel_event_fixture_database');
            $options[PDO::ATTR_EMULATE_PREPARES]=false;
            parent::__construct($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);
            if (parent::query('SELECT DATABASE()')->fetchColumn()!==$m[1]) throw new RuntimeException('unsafe_hotel_event_fixture_database');
            foreach (['hotel_work_owner_delivery_recoveries','hotel_work_research_skips','hotel_work_events','hotel_work_delivery_recoveries','hotel_work_resolutions','hotel_work_connection_handoffs','hotel_work_receipts','hotel_work_queues','hotel_work_lane'] as $table) parent::exec('DROP TABLE IF EXISTS '.$table);
            foreach (['070_hotel_work_queue.sql','071_hotel_work_events.sql','072_hotel_work_recovery.sql','073_hotel_work_delivery_recoveries.sql','076_hotel_work_research_skips.sql','077_hotel_work_owner_delivery_recoveries.sql'] as $migration) {
                foreach (explode(';',(string)file_get_contents(dirname(__DIR__,2).'/migrations/'.$migration)) as $sql) if (trim($sql)!=='') parent::exec($sql);
            }
        } else {
            parent::__construct('sqlite::memory:',null,null,$options);
            parent::exec('CREATE TABLE hotel_work_queues (
                queue_id TEXT PRIMARY KEY,project_key TEXT NOT NULL,admin_id INTEGER NOT NULL,oauth_connection_id TEXT NOT NULL,
                mode TEXT NOT NULL,batch_limit INTEGER,start_country TEXT NOT NULL,initial_phase_counter INTEGER NOT NULL,
                phase_counter INTEGER NOT NULL,baseline_sha256 TEXT NOT NULL,plan_sha256 TEXT NOT NULL,
                stage_index INTEGER NOT NULL DEFAULT 0,state TEXT NOT NULL,claim_sha256 TEXT,current_run_id TEXT,
                accepted_batches INTEGER NOT NULL DEFAULT 0,event_ack TEXT,delivered_at TEXT,claim_deadline TEXT,
                attention_at TEXT,attention_ack TEXT,watchdog_due_at TEXT,watchdog_epoch INTEGER NOT NULL DEFAULT 0,last_watch_ack TEXT,
                created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
                CREATE TABLE hotel_work_receipts(queue_id TEXT NOT NULL,stage_index INTEGER NOT NULL,stage_id TEXT NOT NULL UNIQUE,
                receipt_sha256 TEXT NOT NULL,receipt_json TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(queue_id,stage_index));
                CREATE TABLE hotel_work_lane(lane_key TEXT PRIMARY KEY,queue_id TEXT);INSERT INTO hotel_work_lane VALUES (\'HOTEL\',NULL);
                CREATE TABLE hotel_work_events(subscription_id TEXT PRIMARY KEY,single_consumer_key TEXT UNIQUE,
                admin_id INTEGER NOT NULL,oauth_connection_id TEXT NOT NULL,project_key TEXT NOT NULL,queue_id TEXT NOT NULL,
                event_name TEXT NOT NULL,arguments_json TEXT NOT NULL,callback_url TEXT NOT NULL,signing_secret TEXT NOT NULL,
                previous_secret TEXT,previous_secret_until TEXT,verified_at TEXT NOT NULL,expires_at TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,pending_event_id TEXT,pending_body TEXT,attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at TEXT,lease_token TEXT,lease_until TEXT,last_status INTEGER,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);');
            parent::exec('CREATE TABLE hotel_work_connection_handoffs(handoff_sha256 TEXT PRIMARY KEY,queue_id TEXT NOT NULL,admin_id INTEGER NOT NULL,
                old_connection_id TEXT NOT NULL,new_connection_id TEXT NOT NULL,stage_id TEXT NOT NULL,baseline_sha256 TEXT NOT NULL,
                handoff_mode TEXT NOT NULL,expected_run_id TEXT,claim_sha256 TEXT,created_at TEXT NOT NULL);
                CREATE TABLE hotel_work_owner_delivery_recoveries(recovery_sha256 TEXT PRIMARY KEY,queue_id TEXT NOT NULL,
                stage_id TEXT NOT NULL,admin_id INTEGER NOT NULL,oauth_connection_id TEXT NOT NULL,baseline_sha256 TEXT NOT NULL,
                automatic_recovery_sha256 TEXT NOT NULL,decision_sha256 TEXT NOT NULL,host_readback_sha256 TEXT NOT NULL,
                pending_event_id TEXT NOT NULL,attention_event_id TEXT NOT NULL,delivered_at TEXT NOT NULL,
                claim_deadline TEXT NOT NULL,attention_at TEXT NOT NULL,previous_updated_at TEXT NOT NULL,
                created_at TEXT NOT NULL,UNIQUE(queue_id,stage_id));
                CREATE TABLE hotel_work_delivery_recoveries(recovery_sha256 TEXT PRIMARY KEY,queue_id TEXT NOT NULL,
                stage_id TEXT NOT NULL,admin_id INTEGER NOT NULL,oauth_connection_id TEXT NOT NULL,baseline_sha256 TEXT NOT NULL,
                resume_sha256 TEXT NOT NULL,pending_event_id TEXT NOT NULL,attention_event_id TEXT NOT NULL,
                delivered_at TEXT NOT NULL,claim_deadline TEXT NOT NULL,attention_at TEXT NOT NULL,previous_updated_at TEXT NOT NULL,
                created_at TEXT NOT NULL,UNIQUE(queue_id,stage_id));
                CREATE TABLE hotel_work_research_skips(skip_sha256 TEXT NOT NULL UNIQUE,queue_id TEXT NOT NULL,stage_index INTEGER NOT NULL,
                stage_id TEXT NOT NULL,admin_id INTEGER NOT NULL,old_connection_id TEXT NOT NULL,new_connection_id TEXT NOT NULL,
                baseline_sha256 TEXT NOT NULL,run_id TEXT NOT NULL,claim_sha256 TEXT NOT NULL,previous_state TEXT NOT NULL,
                unknown_receipt_sha256 TEXT,decision_sha256 TEXT NOT NULL,external_effects_status TEXT NOT NULL,
                external_effects_readback_sha256 TEXT NOT NULL,next_stage_index INTEGER NOT NULL,created_at TEXT NOT NULL,
                PRIMARY KEY(queue_id,stage_index),UNIQUE(queue_id,stage_id));
                CREATE TABLE hotel_work_resolutions(queue_id TEXT NOT NULL,stage_index INTEGER NOT NULL,stage_id TEXT NOT NULL UNIQUE,
                unknown_receipt_sha256 TEXT NOT NULL,resolution_sha256 TEXT NOT NULL,receipt_sha256 TEXT NOT NULL,
                receipt_json TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(queue_id,stage_index));');
        }
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HotelEventsTestStatement::class,[$this]]);
    }
    /** Reapply the real forward migration after native tests have stored evidence. */
    public function reapplyDeliveryRecoveryMigration(): void
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new LogicException('native_mysql_required_for_recovery_migration');
        $source=(string)file_get_contents(dirname(__DIR__,2).'/migrations/073_hotel_work_delivery_recoveries.sql');
        if (trim($source)==='') throw new RuntimeException('recovery_migration_source_missing');
        foreach (explode(';',$source) as $sql) if (trim($sql)!=='') $this->exec($sql);
    }
    public function observe(string $sql): void
    {
        if (preg_match('/\A\s*SELECT\b.*\bFROM\s+hotel_work_events\b/is',$sql)===1) {
            $this->eventReads[]=$sql;
            if ($this->beforeEventRead!==null) {
                $hook=$this->beforeEventRead;$this->beforeEventRead=null;$hook();
            }
            if ($this->failEventRead) throw new PDOException('PRIVATE_HOTEL_SETUP_SQL_FAILURE');
        }
        if (preg_match('/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+([a-z_]+)/i',$sql,$m)) {
            $table=strtolower($m[1]);
            if (!in_array($table,['hotel_work_queues','hotel_work_receipts','hotel_work_lane','hotel_work_events','hotel_work_connection_handoffs','hotel_work_resolutions','hotel_work_delivery_recoveries','hotel_work_research_skips','hotel_work_owner_delivery_recoveries'],true)) throw new RuntimeException('hotel_event_fixture_rejected_business_write');
            $this->writes[]=$table;
        }
    }
}

final class HotelEventsFixture
{
    public HotelEventsTestPdo $pdo;
    public HotelWorkQueue $queue;
    public HotelWorkEventService $events;
    public ManagerPreparationWebhook $webhook;
    public int $at=1793100000;
    public array $requests=[];
    public array $statuses=[];
    public array $grants=[];
    public int $authCalls=0;
    public $verifyHook=null;
    public $authorizeHook=null;
    public bool $challengeGood=true;
    public string $connection;
    public string $secret;

    public function __construct(bool $create=true)
    {
        $this->connection=str_repeat('a',32);$this->secret='whsec_'.base64_encode(str_repeat('k',32));
        ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
        $this->pdo=new HotelEventsTestPdo();$clock=fn():int=>$this->at;
        $this->queue=new HotelWorkQueue($this->pdo,$clock);
        if ($create) $this->queue->create(1,$this->connection,['queue_id'=>'PILOT','baseline_sha256'=>str_repeat('0',64)]);
        $this->grants=[$this->connection=>['admin'=>1,'active'=>true,'scopes'=>['hotel_work:write']],
            str_repeat('b',32)=>['admin'=>1,'active'=>true,'scopes'=>['queue:read','prepared:write']],
            str_repeat('c',32)=>['admin'=>1,'active'=>true,'scopes'=>['hotel_work:write']],
            str_repeat('f',32)=>['admin'=>2,'active'=>true,'scopes'=>['hotel_work:write']]];
        $transport=function(string $url,array $headers,string $body,array $destination):array {
            $decoded=json_decode($body,true,16,JSON_THROW_ON_ERROR);$map=[];
            foreach ($headers as $header) {[$key,$value]=explode(': ',$header,2);$map[strtolower($key)]=$value;}
            $this->requests[]=['url'=>$url,'headers'=>$map,'body'=>$body,'data'=>$decoded,'destination'=>$destination];
            if (($decoded['type']??null)==='verification') {
                if ($this->verifyHook!==null) { $hook=$this->verifyHook;$this->verifyHook=null;$hook(); }
                return ['status'=>200,'body'=>json_encode(['challenge'=>$this->challengeGood?$decoded['challenge']:'wrong'],JSON_THROW_ON_ERROR)];
            }
            return ['status'=>array_shift($this->statuses)??204,'body'=>''];
        };
        $this->webhook=new ManagerPreparationWebhook($transport,static fn(string $host):array=>['8.8.8.8'],$clock);
        $authorized=function(string $connection,int $admin,array $required):bool {
            ++$this->authCalls;
            if ($this->authorizeHook!==null) ($this->authorizeHook)();
            $grant=$this->grants[$connection]??null;
            return $required===['hotel_work:write']&&$grant!==null&&$grant['active']&&$grant['admin']===$admin&&!array_diff($required,$grant['scopes']);
        };
        $this->events=new HotelWorkEventService($this->pdo,$this->webhook,$clock,$authorized,$this->queue);
    }
    public function params(string $name='hotel_work.pending',string $url='https://callbacks.example/hotels',?string $secret=null): array
    {
        return ['name'=>$name,'arguments'=>['project_key'=>'anytour','queue_id'=>'PILOT'],
            'delivery'=>['mode'=>'webhook','url'=>$url,'secret'=>$secret??$this->secret],'ttlMs'=>86400000];
    }
    public function subscribe(bool $attention=true): void
    {
        $this->events->subscribe($this->params(),1,$this->connection);
        if ($attention) $this->events->subscribe($this->params('hotel_work.attention'),1,$this->connection);
        $this->requests=[];$this->authCalls=0;
    }
    public function row(string $name='hotel_work.pending'): array
    {
        $q=$this->pdo->prepare('SELECT * FROM hotel_work_events WHERE event_name=? AND oauth_connection_id=?');$q->execute([$name,$this->connection]);return $q->fetch(PDO::FETCH_ASSOC);
    }
    public function status(): array { return $this->queue->status(1,$this->connection,'PILOT'); }
    public function claim(): array
    {
        $args=['queue_id'=>'PILOT','stage_id'=>$this->status()['stage_id'],'claim_key'=>str_repeat('d',64)];
        $this->queue->claim(1,$this->connection,$args);return $args;
    }
    public function completeResearch(array $args): void
    {
        $this->queue->started(1,$this->connection,$args+['run_id'=>'SYNTHETIC_RUN']);
        $receipt=['run_id'=>'SYNTHETIC_RUN','outcome'=>'accepted_readback','packet_sha256'=>str_repeat('1',64),
            'readback_sha256'=>str_repeat('2',64),'ledger_sha256'=>str_repeat('3',64),'hotel_count'=>1,
            'record_count'=>1,'source_count'=>0,'read_update_count'=>0,'criteria'=>null];
        $this->queue->complete(1,$this->connection,$args+['receipt'=>$receipt]);
    }
}

