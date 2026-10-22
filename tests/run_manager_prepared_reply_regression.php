<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparedReplyService.php';
require_once dirname(__DIR__).'/services/ManagerDraftPilotCollector.php';

/** Exercise the real authorization/snapshot services; only the cache may write. */
final class PreparedReplyStatement extends PDOStatement
{
    protected function __construct(private PreparedReplyPdo $owner) {}
    public function execute(?array $params = null): bool
    {
        $this->owner->observe($this->queryString);
        return parent::execute($params);
    }
}

final class PreparedReplyPdo extends PDO
{
    public const TABLES = ['manager_supervision_sources','manager_supervision','admin_audit_log','manager_assignments','lead_stage_history','manager_preparation_cursors','lead_tasks','manager_prepared_replies','manager_conversation_reads','messages','conversation_events','manager_group_members','conversation_sources','conversations','manager_customer_names','customers','manager_projects','managers','projects'];
    public static bool $mysqlFixture = false;
    public bool $guard = false;
    public bool $supervisorWrites = false;
    public ?Closure $beforeSupervisorAudit = null;
    public int $sourceWriteAttempts = 0;
    public int $cacheWrites = 0;
    public array $executed = [];
    public ?Closure $beforeCursorUpdate = null;
    public ?Closure $beforeRetire = null;
    public bool $failSnapshotOnce = false;

    public function __construct()
    {
        $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_STRINGIFY_FETCHES=>false];
        if (self::$mysqlFixture) {
            // Explicit opt-in to a disposable test database. Never drop a table
            // until both the requested and selected database satisfy this fence.
            $dsn = getenv('PREPARED_REPLY_MYSQL_DSN');
            if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')
                || preg_match('/(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D', $dsn, $match) !== 1) {
                throw new RuntimeException('unsafe_mysql_fixture_database');
            }
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
            parent::__construct($dsn, getenv('PREPARED_REPLY_MYSQL_USER') ?: null, getenv('PREPARED_REPLY_MYSQL_PASS') ?: null, $options);
            if (parent::query('SELECT DATABASE()')->fetchColumn() !== $match[1]) throw new RuntimeException('unsafe_mysql_fixture_database');
            parent::exec('SET NAMES utf8mb4');
            $this->resetMysqlTables();
        } else {
            parent::__construct('sqlite::memory:', null, null, $options);
        }
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PreparedReplyStatement::class, [$this]]);
    }

    public function resetMysqlTables(): void
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        $database = parent::query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || preg_match('/\Amaxbot_prepared_test(?:_[a-zA-Z0-9_]+)?\z/D', $database) !== 1) throw new RuntimeException('unsafe_mysql_fixture_database');
        foreach (self::TABLES as $table) parent::exec('DROP TABLE IF EXISTS '.$table);
    }

    public function observe(string $sql): void
    {
        if (!$this->guard) return;
        if ($this->beforeCursorUpdate !== null && str_starts_with($sql, 'UPDATE manager_preparation_cursors SET')) {
            $callback=$this->beforeCursorUpdate; $this->beforeCursorUpdate=null; $callback($this);
        }
        if ($this->failSnapshotOnce && preg_match('/\bFROM messages\b/i',$sql)){$this->failSnapshotOnce=false;throw new RuntimeException('synthetic_snapshot_failure');}
        if ($this->beforeRetire !== null && str_contains($sql,"WHERE job_id=? AND status='pending' AND context_revision=")){$callback=$this->beforeRetire;$this->beforeRetire=null;$callback($this);}
        $this->executed[] = $sql;
        if (preg_match('/\A\s*SELECT\b/i', $sql)) return;
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            && in_array($sql, ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','SET TRANSACTION READ ONLY'], true)) return;
        if (preg_match('/\A\s*(?:INSERT(?:\s+(?:OR\s+)?IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:manager_prepared_replies|manager_preparation_cursors)\b/i', $sql)) {
            ++$this->cacheWrites;
            return;
        }
        if($this->supervisorWrites && preg_match('/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:manager_supervision(?:_sources)?|admin_audit_log)\b/i',$sql)){
            if($this->beforeSupervisorAudit!==null && str_starts_with($sql,'INSERT INTO admin_audit_log')){$callback=$this->beforeSupervisorAudit;$this->beforeSupervisorAudit=null;$callback();}
            return;
        }
        ++$this->sourceWriteAttempts;
        throw new RuntimeException('fixture_rejected_source_write');
    }

    public function exec(string $statement): int|false
    {
        $this->observe($statement);
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->observe($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    /** Synthetic source changes belong to the test, never to the service. */
    public function mutate(string $sql): void
    {
        $before = $this->guard;
        $this->guard = false;
        try { parent::exec($sql); } finally { $this->guard = $before; }
    }
}

$preparedReplyPassed = 0;
function preparedReplyCheck(string $name, bool $ok): void
{
    global $preparedReplyPassed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$preparedReplyPassed;
    echo 'PASS '.$name."\n";
}

function preparedReplyFixture(string $channel = 'telegram'): PreparedReplyPdo
{
    $pdo = new PreparedReplyPdo();
    (new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $pdo);
    foreach ([ProjectAccessService::class, ManagerAuthService::class, RoutingAccessService::class] as $class) {
        (new ReflectionProperty($class, 'schemaReady'))->setValue(null, false);
    }
    ProjectConfig::resetForTests(['id'=>'anytour', 'brand'=>['name'=>'Synthetic project']]);
    $schema = "CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT,is_test INTEGER NOT NULL DEFAULT 0);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,created_at TEXT,channel TEXT,metadata_json TEXT,sender_id TEXT,external_message_id TEXT);
CREATE INDEX idx_messages_conversation ON messages(conversation_id,id);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER,updated_at TEXT,PRIMARY KEY(manager_id,conversation_id));
CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY,conversation_id INTEGER,assigned_manager_id INTEGER,status TEXT,due_at_utc TEXT);
CREATE TABLE manager_preparation_cursors(project_key VARCHAR(64),lane VARCHAR(10),after_id INTEGER NOT NULL,version INTEGER NOT NULL,PRIMARY KEY(project_key,lane));
CREATE TABLE manager_prepared_replies(job_id CHAR(64) PRIMARY KEY,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,project_key TEXT NOT NULL,context_revision CHAR(64) NOT NULL,instruction_revision CHAR(64) NOT NULL,latest_message_id INTEGER NOT NULL,status TEXT NOT NULL,proposal_json TEXT,proposal_sha256 CHAR(64),created_at TEXT NOT NULL,prepared_at TEXT,expires_at TEXT);
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other project',1);
INSERT INTO managers VALUES(1,'owner','PRIVATE manager','manager','PRIVATE@example.test',1,1),(2,'admin','PRIVATE admin','admin','',1,1),(3,'outside','Outside project','admin','',1,1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2);
INSERT INTO customers VALUES(10,'PRIVATE customer','79995554433','PRIVATE@example.test');
INSERT INTO manager_customer_names VALUES(10,'PRIVATE working label',1);
INSERT INTO conversations VALUES(101,10,'anytour',0,'telegram','','','','manager','new',1,'2026-10-19 10:00:00','2026-10-19 10:01:00',NULL,'PRIVATE external chat',0);
INSERT INTO manager_conversation_reads VALUES(1,101,1,'2026-10-19 10:00:00');
INSERT INTO messages VALUES(1,101,'outbound','manager','Здравствуйте!','2026-10-19 10:00:00','telegram',NULL,'PRIVATE','PRIVATE');
INSERT INTO messages VALUES(2,101,'inbound','customer','Подберите тур в Турцию на двоих, бюджет 200000 рублей.','2026-10-19 10:01:00','telegram',NULL,'PRIVATE','PRIVATE');
";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        // The production cache migration has a BIGINT UNSIGNED conversation FK
        // and binary bounded proposal storage; exercise those actual constraints.
        $schema = str_replace('CREATE TABLE conversations(id INTEGER PRIMARY KEY', 'CREATE TABLE conversations(id BIGINT UNSIGNED PRIMARY KEY', $schema);
        $schema = preg_replace('/CREATE TABLE manager_preparation_cursors\([^;]+;/s', '', $schema);
        $schema = preg_replace('/CREATE TABLE manager_prepared_replies\([^;]+;/s', '', $schema);
        $schema = preg_replace('/(CREATE TABLE [^;]+\));/s', '$1 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;', $schema);
        if (!is_string($schema)) throw new RuntimeException('invalid_mysql_fixture_schema');
        $migration = file_get_contents(dirname(__DIR__).'/migrations/044_manager_prepared_replies.sql');
        if (!is_string($migration)) throw new RuntimeException('missing_mysql_fixture_migration');
        $pdo->exec($schema);
        $pdo->exec($migration);
        $pdo->exec(file_get_contents(dirname(__DIR__).'/migrations/050_manager_preparation_cursors.sql'));
    } else {
        $pdo->exec($schema);
    }
    if ($channel !== 'telegram') {
        $pdo->prepare('UPDATE conversations SET channel=?')->execute([$channel]);
        $pdo->prepare('UPDATE messages SET channel=?')->execute([$channel]);
    }
    $pdo->guard = true;
    return $pdo;
}

function preparedReplySourceState(PreparedReplyPdo $pdo): array
{
    $state = [];
    foreach (['lead_tasks','projects','manager_projects','managers','customers','manager_customer_names','conversations','conversation_sources','manager_group_members','conversation_events','messages','manager_conversation_reads'] as $table) {
        $state[$table] = $pdo->query('SELECT * FROM '.$table)->fetchAll();
    }
    return $state;
}

function preparedReplySnapshot(int $actor = 1): array
{
    return ManagerAiDraftContextService::snapshot(101, $actor);
}

function preparedReplyRow(array $snapshot, array $changes = []): array
{
    return array_replace(['conversation_id'=>101,'manager_id'=>1,'project_key'=>'anytour',
        'context_revision'=>$snapshot['revision'],'latest_message_id'=>$snapshot['latest_message_id']], $changes);
}

function preparedReplyProposal(array $changes = []): array
{
    return array_replace(['ok'=>true,'should_draft'=>true,'draft'=>'Подскажите, пожалуйста, из какого города планируете вылет и на какие даты?',
        'next_action'=>'clarify','reason'=>'Для подбора не указаны город вылета и даты поездки.',
        'checks'=>[],'evidence_message_ids'=>[2],'history_incomplete'=>false], $changes);
}

/** Return the server-generated opaque identity, never manufacture a job ID. */
function preparedReplyQueue(array $snapshot): string
{
    $queued = ManagerPreparedReplyService::enqueue([preparedReplyRow($snapshot)]);
    if (($queued['ok'] ?? false) !== true || count($queued['job_ids'] ?? []) !== 1) throw new RuntimeException('FAIL enqueue fixture');
    return $queued['job_ids'][0];
}

/** Same native fixtures execute this on SQLite and CI MySQL. */
function preparedReplyCoverage(): void
{
    $pdo = preparedReplyFixture();
    $pdo->mutate('DELETE FROM messages; DELETE FROM conversations');
    $seed = static function(int $id, bool $answered, bool $due = false) use (&$pdo): void {
        $pdo->prepare("INSERT INTO conversations VALUES(?,10,'anytour',0,'telegram','','','','manager','new',1,'2020-01-01 10:00:00','2020-01-01 10:01:00',NULL,'synthetic',0)")->execute([$id]);
        $pdo->prepare("INSERT INTO messages VALUES(?,?,'inbound','customer','Нужен тур','2020-01-01 10:00:00','telegram',NULL,'synthetic','synthetic')")->execute([$id*10,$id]);
        if ($answered) $pdo->prepare("INSERT INTO messages VALUES(?,?,'outbound','manager','Уточняю условия','2020-01-01 10:01:00','telegram',NULL,'synthetic','synthetic')")->execute([$id*10+1,$id]);
        if ($due) $pdo->prepare("INSERT INTO lead_tasks VALUES(?,?,1,'open','2020-01-02 10:00:00')")->execute([$id,$id]);
    };
    // Fixtures, not service writes. Dates deliberately exceed the old72h tail.
    $pdo->guard = false;
    foreach (range(1001,1045) as $id) $seed($id,false);
    foreach (range(2001,2009) as $id) $seed($id,true,true);
    foreach (range(3001,3014) as $id) $seed($id,true);
    foreach ([3001=>'callback',3002=>'start',3003=>'bot_started'] as $id=>$type) {
        $pdo->prepare("INSERT INTO messages VALUES(?,?,'inbound','customer','Synthetic control','2020-01-01 10:02:00','telegram',?,'synthetic','synthetic')")->execute([$id*10+2,$id,json_encode(['type'=>$type],JSON_THROW_ON_ERROR)]);
    }
    $pdo->exec("INSERT INTO lead_tasks VALUES(1001,1001,1,'open','2020-01-02 10:00:00')");
    $pdo->guard = true;
    $before = preparedReplySourceState($pdo);
    $first = ManagerPreparedReplyService::collectAndEnqueue([1]);
    preparedReplyCheck('scheduled preparation has a20-candidate bound', ($first['ok']??false) && $first['candidate_count']===20 && $first['exported_count']===20);
    $ids = array_map('intval',$pdo->query('SELECT conversation_id FROM manager_prepared_replies ORDER BY conversation_id')->fetchAll(PDO::FETCH_COLUMN));
    preparedReplyCheck('waiting,existing due,and answered active clients all advance in firstpass', $ids===array_merge(range(1001,1010),range(2001,2005),range(3001,3005)));
    for ($pass=0;$pass<7;$pass++) {
        $batch = ManagerPreparedReplyService::collectAndEnqueue([1]);
        preparedReplyCheck('rotation retains each bounded pass', ($batch['ok']??false) && $batch['candidate_count']<=20 && $batch['exported_count']<=20);
    }
    $ids = array_map('intval',$pdo->query('SELECT conversation_id FROM manager_prepared_replies ORDER BY conversation_id')->fetchAll(PDO::FETCH_COLUMN));
    preparedReplyCheck('all68eligible clients eventually enter existingqueue without repeated fresh jobs', $ids===array_merge(range(1001,1045),range(2001,2009),range(3001,3014)));
    preparedReplyCheck('rotation preserves three collection cursors plus one bounded verification cursor', (int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_cursors')->fetchColumn()===4 && (int)$pdo->query("SELECT COUNT(*) FROM manager_preparation_cursors WHERE lane IN ('waiting','due','active')")->fetchColumn()===3 && (int)$pdo->query('SELECT MIN(version) FROM manager_preparation_cursors')->fetchColumn()===8);
    preparedReplyCheck('coverage does not change tasks,messages,assignment,readmarkers or businessdata', preparedReplySourceState($pdo)===$before && $pdo->sourceWriteAttempts===0);

    // Old ordinary work must not hide a later unanswered enquiry or due reminder.
    // Every lane still gets capacity; none of these reads may consume a job.
    $old=gmdate('Y-m-d H:i:s',time()-600);$middle=gmdate('Y-m-d H:i:s',time()-300);$recent=gmdate('Y-m-d H:i:s',time()-60);
    $pdo->guard=false;
    $pdo->prepare('UPDATE manager_prepared_replies SET created_at=CASE WHEN conversation_id>=3000 THEN ? WHEN conversation_id>=2000 THEN ? ELSE ? END')->execute([$old,$middle,$recent]);
    $pdo->guard=true;
    $unchanged=$pdo->query('SELECT * FROM manager_prepared_replies ORDER BY job_id')->fetchAll();
    $pending=ManagerPreparedReplyService::pending(20);
    $selected=array_column($pending['jobs']??[],'conversation_id');
    $counts=static fn(array $ids):array=>[count(array_filter($ids,static fn(int $id):bool=>$id<2000)),count(array_filter($ids,static fn(int $id):bool=>$id>=2000&&$id<3000)),count(array_filter($ids,static fn(int $id):bool=>$id>=3000))];
    preparedReplyCheck('worker batch reserves10waiting5due5active even behind older ordinary work', ($pending['ok']??false)&&$counts($selected)===[10,5,5]&&count(array_unique($selected))===20);
    preparedReplyCheck('callback,start,and bot-started events do not manufacture waiting priority', count(array_intersect(array_slice($selected,0,10),[3001,3002,3003]))===0);
    foreach ([3,8,19] as $limit) {
        $batch=ManagerPreparedReplyService::pending($limit);
        $parts=$counts(array_column($batch['jobs']??[],'conversation_id'));
        preparedReplyCheck('bounded worker batch '.$limit.' retains progress in all3lanes', ($batch['ok']??false)&&count($batch['jobs'])===$limit&&min($parts)>0);
    }
    foreach ([1,2] as $limit) {
        $batch=ManagerPreparedReplyService::pending($limit);
        preparedReplyCheck('tiny worker batch '.$limit.' retains oldest-first progress', ($batch['ok']??false)&&count($batch['jobs'])===$limit&&$counts(array_column($batch['jobs'],'conversation_id'))===[0,0,$limit]);
    }
    preparedReplyCheck('worker prioritization leaves jobs and business state unchanged', $unchanged===$pdo->query('SELECT * FROM manager_prepared_replies ORDER BY job_id')->fetchAll()&&preparedReplySourceState($pdo)===$before&&$pdo->sourceWriteAttempts===0);
    $pdo->executed=[];
    ManagerPreparedReplyService::pending(20);
    preparedReplyCheck('worker selection uses3bounded metadata reads not one query per client', count($pdo->executed)===3&&count(array_filter($pdo->executed,static fn(string $sql):bool=>str_starts_with($sql,'SELECT ')))===3);
    // Deadline changes are current priority, not a frozen lane written to a job.
    $pdo->mutate("UPDATE lead_tasks SET status='done' WHERE conversation_id=2001; UPDATE lead_tasks SET due_at_utc='2099-01-01 00:00:00' WHERE conversation_id=2002; UPDATE lead_tasks SET assigned_manager_id=3 WHERE conversation_id=2003");
    $changed=ManagerPreparedReplyService::pending(20)['jobs'];
    $dueIds=array_column(array_slice($changed,10,5),'conversation_id');
    preparedReplyCheck('pending priority ignores completed,future,and different-owner tasks', count(array_diff($dueIds,range(2004,2009)))===0);
    $pdo->mutate("UPDATE lead_tasks SET status='open',due_at_utc='2020-01-02 10:00:00',assigned_manager_id=1");
    // Completing each bounded page drains every lane, including spare-capacity
    // fills at its end. This is fixture-only completion, never a worker claim.
    $processed=[];
    for($pass=0;$pass<5;$pass++) {
        $batch=ManagerPreparedReplyService::pending(20)['jobs'];
        foreach($batch as $row) {
            $processed[]=$row['conversation_id'];
            $pdo->guard=false;
            $pdo->prepare("UPDATE manager_prepared_replies SET status='ready' WHERE job_id=?")->execute([$row['job_id']]);
            $pdo->guard=true;
        }
    }
    sort($processed);
    preparedReplyCheck('worker pages drain all68clients without duplicates or ordinary-lane starvation', $processed===array_merge(range(1001,1045),range(2001,2009),range(3001,3014)));
    $pdo->mutate("UPDATE manager_prepared_replies SET status='pending'");

    // Eligibility is still source/owner scoped in every lane, before and after capture.
    $pdo->mutate("UPDATE conversations SET status='closed' WHERE id=3001; UPDATE conversations SET is_test=1 WHERE id=3002; UPDATE conversations SET project_key='other' WHERE id=3003; UPDATE conversations SET manager_id=3 WHERE id=3004; UPDATE lead_tasks SET status='done' WHERE conversation_id=2001; UPDATE lead_tasks SET due_at_utc='2099-01-01 00:00:00' WHERE conversation_id=2002; UPDATE lead_tasks SET assigned_manager_id=3 WHERE conversation_id=2003");
    $page=ManagerDraftPilotCollector::collect($pdo,[1],20,null,null,null,true,['lane'=>'due','after_id'=>0]);
    preparedReplyCheck('only arrived open tasks belonging to actual owner are due', ($page['ok']??false) && array_column($page['conversations'],'conversation_id')===range(2004,2009));
    $page=ManagerDraftPilotCollector::collect($pdo,[1],20,null,null,null,true,['lane'=>'active','after_id'=>0]);
    $active=array_column($page['conversations'],'conversation_id');
    preparedReplyCheck('closed,test,otherproject,otherowner are excluded and future tasks stay ordinaryactive', ($page['ok']??false) && $active===array_merge(range(2001,2003),range(3005,3014)));
    $pdo->mutate("INSERT INTO conversation_sources VALUES(99,'Synthetic restricted',1,999,'none',NULL,NULL); UPDATE conversations SET source_id=99 WHERE id=3005");
    $page=ManagerDraftPilotCollector::collect($pdo,[1],20,null,null,null,true,['lane'=>'active','after_id'=>0]);
    preparedReplyCheck('expanded active scan keeps canonical source visibility fence', ($page['ok']??false) && !in_array(3005,array_column($page['conversations'],'conversation_id'),true));
    $bad=ManagerDraftPilotCollector::collect($pdo,[1],20,null,null,null,true,['lane'=>'active','after_id'=>-1]);
    preparedReplyCheck('invalid rotating cursor failsclosed', ($bad['ok']??true)===false);

    $pdo = preparedReplyFixture();
    $pdo->mutate('DELETE FROM messages; DELETE FROM conversations');
    $pdo->guard=false;
    foreach (range(1001,1015) as $id) $seed($id,false);
    $pdo->guard=true;
    $filled=ManagerPreparedReplyService::collectAndEnqueue([1]);
    preparedReplyCheck('unused due and active slots fill with remaining waiting clients', ($filled['ok']??false) && $filled['candidate_count']===15 && $filled['queue']['inserted']===15);
    $pdo->beforeCursorUpdate=static function(PreparedReplyPdo $db): void {
        $db->mutate("UPDATE manager_preparation_cursors SET after_id=999,version=version+1 WHERE lane='waiting'");
    };
    $competing=ManagerPreparedReplyService::collectAndEnqueue([1]);
    $cursor=$pdo->query("SELECT after_id,version FROM manager_preparation_cursors WHERE lane='waiting'")->fetch(PDO::FETCH_ASSOC);
    preparedReplyCheck('late pass cannot overwrite a competing newer cursor version', ($competing['ok']??false) && (int)$cursor['after_id']===999 && (int)$cursor['version']===2);

    $pdo = preparedReplyFixture(); $snapshot = preparedReplySnapshot(); $job = preparedReplyQueue($snapshot);
    ManagerPreparedReplyService::complete($job,$snapshot['revision'],preparedReplyProposal());
    $pdo->mutate("UPDATE manager_prepared_replies SET created_at='2020-01-01 00:00:00',prepared_at='2020-01-01 00:00:01',expires_at='2020-01-01 01:00:01'");
    $old = $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
    $renewed = ManagerPreparedReplyService::enqueue([preparedReplyRow($snapshot)],true);
    $new = $renewed['job_ids'][0]??'';
    preparedReplyCheck('scheduled expired result renews as a distinct immutable job', ($renewed['inserted']??0)===1 && $new!==$job && count(ManagerPreparedReplyService::pending()['jobs'])===1);
    $q=$pdo->prepare('SELECT * FROM manager_prepared_replies WHERE job_id=?');$q->execute([$job]);
    preparedReplyCheck('renewal preserves original proposal and source provenance', $q->fetchAll()===$old);
    $again=ManagerPreparedReplyService::enqueue([preparedReplyRow($snapshot)],true);
    preparedReplyCheck('repeated scheduled pass shares the same live renewal', ($again['inserted']??-1)===0 && ($again['job_ids']??[])===[$new] && (int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_replies')->fetchColumn()===2);
    preparedReplyCheck('old expired worker cannot finish after renewal', !ManagerPreparedReplyService::context($job)['ok'] && !ManagerPreparedReplyService::complete($job,$snapshot['revision'],preparedReplyProposal())['ok']);
    preparedReplyCheck('current renewal can complete normally', ManagerPreparedReplyService::complete($new,$snapshot['revision'],preparedReplyProposal())['ok'] && ManagerPreparedReplyService::prepared(101,1,$snapshot)['prepared']);

    $pdo=preparedReplyFixture();$snapshot=preparedReplySnapshot();$job=preparedReplyQueue($snapshot);
    $hold=preparedReplyProposal(['should_draft'=>false,'draft'=>null,'next_action'=>'no_contact','reason'=>'Исходящий ответ не нужен.']);
    preparedReplyCheck('synthetic no_contact result validates', ManagerPreparedReplyService::complete($job,$snapshot['revision'],$hold)['ok']);
    $pdo->mutate("UPDATE manager_prepared_replies SET expires_at='2000-01-01 00:00:00'");
    $kept=ManagerPreparedReplyService::enqueue([preparedReplyRow($snapshot)],true);
    preparedReplyCheck('expired unchanged no_contact is not silently regenerated', ($kept['inserted']??-1)===0 && ($kept['job_ids']??[])===[$job]);
    $pdo->mutate("UPDATE messages SET text='Новые даты поездки' WHERE id=2");$changed=preparedReplySnapshot();
    $fresh=ManagerPreparedReplyService::enqueue([preparedReplyRow($changed)],true);
    preparedReplyCheck('new conditions invalidate no_contact rather than creating permanent exclusion', ($fresh['inserted']??0)===1 && ($fresh['job_ids'][0]??'')!==$job);
}

function preparedReplyReferenceRecovery(): void
{
    $catalog=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $catalog->sqliteCreateFunction('UNIX_TIMESTAMP',static fn(string $value):int=>(int)strtotime($value.' UTC'),1);
    $catalog->exec("CREATE TABLE catalog_departures(id INTEGER PRIMARY KEY,name TEXT,name_genitive TEXT,is_active INTEGER); CREATE TABLE catalog_countries(id INTEGER PRIMARY KEY,name TEXT,is_active INTEGER); INSERT INTO catalog_departures VALUES(1,'Москва','Москвы',1); INSERT INTO catalog_countries VALUES(4,'Турция',1)");
    foreach(['catalog_departure_countries','catalog_departure_countries_direct','catalog_departure_countries_charter','catalog_departure_countries_direct_charter'] as $table){
        $catalog->exec("CREATE TABLE {$table}(departure_id INTEGER,country_id INTEGER,is_active INTEGER,synced_at TEXT)");
        $catalog->prepare("INSERT INTO {$table} VALUES(1,4,1,?)")->execute([gmdate('Y-m-d H:i:s',time()-120)]);
    }
    (new ReflectionProperty(HotelDatabase::class,'pdo'))->setValue(null,$catalog);
    try{
        $pdo=preparedReplyFixture();$snapshot=preparedReplySnapshot();$old=preparedReplyQueue($snapshot);
        $before=preparedReplySourceState($pdo);
        foreach(['catalog_departure_countries','catalog_departure_countries_direct','catalog_departure_countries_charter','catalog_departure_countries_direct_charter'] as $table)$catalog->exec("UPDATE {$table} SET synced_at='".gmdate('Y-m-d H:i:s',time()-60)."'");
        $current=preparedReplySnapshot();
        preparedReplyCheck('catalog sync changes full revision without changing latest message', $current['latest_message_id']===$snapshot['latest_message_id']&&$current['revision']!==$snapshot['revision']);
        preparedReplyCheck('old nonexpired reference job context and save remain rejected', (ManagerPreparedReplyService::context($old)['error']??'')==='stale_context'&&(ManagerPreparedReplyService::complete($old,$snapshot['revision'],preparedReplyProposal())['error']??'')==='stale_context');
        // Ordinary collector starts AFTER the affected client; new enquiries must
        // not be required to revisit it before pending capacity is reconciled.
        $pdo->mutate("INSERT INTO manager_preparation_cursors VALUES('anytour','waiting',101,1),('anytour','due',101,1),('anytour','active',101,1)");
        $pdo->guard=false;
        foreach(range(201,225) as $id){
            $pdo->prepare("INSERT INTO conversations SELECT ?,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,external_chat_id,is_test FROM conversations WHERE id=101")->execute([$id]);
            $pdo->prepare("INSERT INTO messages SELECT ?,?,direction,sender_type,text,created_at,channel,metadata_json,sender_id,external_message_id FROM messages WHERE id=2")->execute([$id*10,$id]);
        }
        $pdo->guard=true;$before=preparedReplySourceState($pdo);
        $pass=ManagerPreparedReplyService::collectAndEnqueue([1]);
        $ids=array_column(ManagerPreparedReplyService::pending(20)['jobs']??[],'job_id');
        preparedReplyCheck('scheduled full revision reconciliation releases old inventory beforeTTL', ($pass['ok']??false)&&!in_array($old,$ids,true));
        $q=$pdo->query("SELECT job_id FROM manager_prepared_replies WHERE conversation_id=101 AND status='pending' AND expires_at>='".gmdate('Y-m-d H:i:s',time()+1)."'");$fresh=$q->fetchColumn();
        preparedReplyCheck('same client receives a different readable canonical job before its collector turn', is_string($fresh)&&$fresh!==$old&&(ManagerPreparedReplyService::context($fresh)['ok']??false));
        preparedReplyCheck('reconciliation is bounded and does not mutate business source', ($pass['reconciliation']['checked']??0)===1&&($pass['reconciliation']['retired']??0)===1&&($pass['reconciliation']['renewed']??0)===1&&preparedReplySourceState($pdo)===$before&&$pdo->sourceWriteAttempts===0);
    }finally{HotelDatabase::resetForTests();}
}

function preparedReplyReconciliationBoundaries(): void
{
    $pdo=preparedReplyFixture();preparedReplyQueue(preparedReplySnapshot());$pdo->guard=false;$old='';
    foreach(range(201,221) as $id){
        $pdo->prepare("INSERT INTO conversations SELECT ?,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,external_chat_id,is_test FROM conversations WHERE id=101")->execute([$id]);
        $pdo->prepare("INSERT INTO messages SELECT ?,?,direction,sender_type,text,created_at,channel,metadata_json,sender_id,external_message_id FROM messages WHERE id=2")->execute([$id*10,$id]);
        $snap=ManagerAiDraftContextService::snapshot($id,1);$queued=ManagerPreparedReplyService::enqueue([preparedReplyRow($snap,['conversation_id'=>$id])]);
        if($id===221)$old=$queued['job_ids'][0];
    }
    $pdo->guard=true;$pdo->mutate("UPDATE messages SET text='Новые существенные условия' WHERE conversation_id=221");$before=preparedReplySourceState($pdo);
    $first=ManagerPreparedReplyService::collectAndEnqueue([1]);
    preparedReplyCheck('valid prefix consumes only20 full validations in one scheduled pass',($first['reconciliation']['checked']??0)===20&&($first['reconciliation']['retired']??-1)===0);
    preparedReplyCheck('first bounded validation does not claim whole pending coverage', (ManagerPreparedReplyService::context($old)['error']??'')==='stale_context');
    $second=ManagerPreparedReplyService::collectAndEnqueue([1]);
    preparedReplyCheck('verification cursor reaches stale work behind20 current jobs beforeTTL', ($second['reconciliation']['checked']??0)===2&&($second['reconciliation']['retired']??0)===1&&($second['reconciliation']['renewed']??0)===1);
    $q=$pdo->prepare("SELECT job_id FROM manager_prepared_replies WHERE conversation_id=221 AND expires_at>? AND status='pending'");$q->execute([gmdate('Y-m-d H:i:s')]);$new=$q->fetchColumn();
    preparedReplyCheck('new full revision stays single readable work and old completion refused',is_string($new)&&$new!==$old&&(ManagerPreparedReplyService::context($new)['ok']??false)&&!(ManagerPreparedReplyService::context($old)['ok']??false));
    $third=ManagerPreparedReplyService::collectAndEnqueue([1]);
    preparedReplyCheck('verification wraps through valid clients without renewing them',($third['reconciliation']['checked']??0)===20&&($third['reconciliation']['retired']??-1)===0&&($third['reconciliation']['renewed']??-1)===0);
    preparedReplyCheck('rotating verification leaves every business table and readmarker unchanged', preparedReplySourceState($pdo)===$before&&$pdo->sourceWriteAttempts===0);

    $pdo=preparedReplyFixture();$job=preparedReplyQueue(preparedReplySnapshot());$pdo->mutate('DELETE FROM manager_projects WHERE manager_id=1');$before=preparedReplySourceState($pdo);
    $pass=ManagerPreparedReplyService::collectAndEnqueue([1]);
    $q=$pdo->prepare('SELECT expires_at FROM manager_prepared_replies WHERE job_id=?');$q->execute([$job]);
    preparedReplyCheck('revoked work releases capacity without enqueuing under another owner',strtotime($q->fetchColumn().' UTC')<=time()&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_replies')->fetchColumn()===1&&preparedReplySourceState($pdo)===$before);

    $pdo=preparedReplyFixture();$job=preparedReplyQueue(preparedReplySnapshot());$cache=$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();$pdo->failSnapshotOnce=true;
    $pass=ManagerPreparedReplyService::collectAndEnqueue([1]);
    preparedReplyCheck('snapshot uncertainty defers rather than expiring a current job',($pass['ok']??false)&&($pass['reconciliation']['deferred']??0)===1&&($pass['reconciliation']['retired']??-1)===0&&$cache===$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll());

    $pdo=preparedReplyFixture();$job=preparedReplyQueue(preparedReplySnapshot());$pdo->mutate("UPDATE messages SET text='Изменённые условия' WHERE id=2");
    $before=$pdo->query('SELECT * FROM manager_prepared_replies')->fetch();$pdo->beforeRetire=static function(PreparedReplyPdo $pdo):void{$pdo->mutate("UPDATE manager_prepared_replies SET status='ready',proposal_json='synthetic immutable completion'");};
    $pass=ManagerPreparedReplyService::collectAndEnqueue([1]);$q=$pdo->prepare('SELECT * FROM manager_prepared_replies WHERE job_id=?');$q->execute([$job]);$saved=$q->fetch();
    preparedReplyCheck('concurrent ready completion is never retired or overwritten',($pass['reconciliation']['retired']??-1)===0&&$saved['status']==='ready'&&$saved['expires_at']===$before['expires_at']&&$saved['proposal_json']==='synthetic immutable completion');

    $pdo=preparedReplyFixture();$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);$hold=preparedReplyProposal(['should_draft'=>false,'draft'=>null,'next_action'=>'no_contact','reason'=>'Ответ не требуется.']);
    preparedReplyCheck('boundary no_contact completion is valid',ManagerPreparedReplyService::complete($job,$snap['revision'],$hold)['ok']);
    $cache=$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();$pass=ManagerPreparedReplyService::collectAndEnqueue([1]);
    preparedReplyCheck('ready no_contact rows remain immutable and are not regenerated',($pass['reconciliation']['checked']??-1)===0&&$cache===$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll());
}

function preparedReplyRun(): void
{
    global $preparedReplyPassed;
    $startedWith = $preparedReplyPassed;
    require_once __DIR__.'/support/manager-supervision-regression.php';
    managerSupervisorRegression();
    preparedReplyReferenceRecovery();
    preparedReplyReconciliationBoundaries();

    foreach (['telegram'=>1,'max'=>2] as $channel=>$internalId) {
        foreach (['pending','ready'] as $state) {
            $pdo=preparedReplyFixture($channel);$snapshot=preparedReplySnapshot();$job=preparedReplyQueue($snapshot);
            $proposal=preparedReplyProposal();
            if ($state==='ready') ManagerPreparedReplyService::complete($job,$snapshot['revision'],$proposal);
            // A previously issued row remains present, but its now-internal
            // identity is rechecked independently of the frozen revision.
            $pdo->mutate("UPDATE conversations SET id=$internalId WHERE id=101; UPDATE messages SET conversation_id=$internalId WHERE conversation_id=101; UPDATE manager_conversation_reads SET conversation_id=$internalId WHERE conversation_id=101; UPDATE manager_prepared_replies SET conversation_id=$internalId WHERE job_id='$job'");
            $ownerSnapshot=ManagerAiDraftContextService::snapshot($internalId,1);
            $ownerRevision=$ownerSnapshot['revision'];
            $pdo->mutate("UPDATE manager_prepared_replies SET context_revision='$ownerRevision' WHERE job_id='$job'");
            $before=preparedReplySourceState($pdo);$writes=$pdo->cacheWrites;
            $queued=ManagerPreparedReplyService::enqueue([array_replace(preparedReplyRow($ownerSnapshot),['conversation_id'=>$internalId])]);
            preparedReplyCheck($channel.' internal owner '.$state.' cache is excluded without disabling manual context or hiding the dialogue',
                ($ownerSnapshot['ok']??false) && $queued['skipped']===1 && $queued['job_ids']===[]
                && ManagerPreparedReplyService::pending(20)['jobs']===[]
                && !ManagerPreparedReplyService::context($job)['ok']
                && !ManagerPreparedReplyService::complete($job,$ownerRevision,$proposal)['ok']
                && !ManagerPreparedReplyService::prepared($internalId,1,$ownerSnapshot)['prepared']
                && $pdo->cacheWrites===$writes && $pdo->sourceWriteAttempts===0 && preparedReplySourceState($pdo)===$before);
        }
    }

    foreach (['website','telegram','max'] as $channel) {
        $pdo=preparedReplyFixture($channel);$sourceBefore=preparedReplySourceState($pdo);
        $snapshot=preparedReplySnapshot();$job=preparedReplyQueue($snapshot);
        $context=ManagerPreparedReplyService::context($job);
        preparedReplyCheck($channel.' text-only worker context survives the photo capability',
            ($context['ok']??false)&&($context['image_inputs']??null)===[]&&$context['context']['messages']===ManagerAiDraftService::prepareInput($snapshot['context'],$snapshot['company_knowledge'],null,$snapshot['reference_context'])['context']['messages']
            &&$pdo->sourceWriteAttempts===0&&preparedReplySourceState($pdo)===$sourceBefore);
        $calls=0;
        $reply=ManagerAiDraftService::generate($snapshot['context'],static function($instructions,$payload,$images)use(&$calls):array {
            ++$calls;if($images!==[])throw new RuntimeException('unexpected image');return preparedReplyProposal();
        },$snapshot['company_knowledge'],null,$snapshot['reference_context'],
            static fn(array $normalized):?array=>$snapshot['photo_context']->read($normalized));
        preparedReplyCheck($channel.' text-only manual generation still invokes its provider once',
            ($reply['ok']??false)&&$calls===1&&$pdo->sourceWriteAttempts===0&&preparedReplySourceState($pdo)===$sourceBefore);
    }
    $pdo = preparedReplyFixture();
    $sourceBefore = preparedReplySourceState($pdo);
    $snapshot = preparedReplySnapshot();
    preparedReplyCheck('canonical fixture is an active assigned reply snapshot', ($snapshot['ok'] ?? false) === true && $snapshot['latest_message_id'] === 2);
    $job = preparedReplyQueue($snapshot);
    preparedReplyCheck('queue returns a bounded opaque identity', preg_match('/\A[a-f0-9]{64}\z/D', $job) === 1);
    $duplicate = ManagerPreparedReplyService::enqueue([preparedReplyRow($snapshot)]);
    preparedReplyCheck('unchanged context is deterministically deduplicated', ($duplicate['ok'] ?? false) === true && ($duplicate['inserted'] ?? -1) === 0 && ($duplicate['existing'] ?? 0) === 1 && ($duplicate['job_ids'] ?? []) === [$job] && (int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_replies')->fetchColumn() === 1);
    $pending = ManagerPreparedReplyService::pending(20);
    preparedReplyCheck('pending exposes only job metadata', ($pending['ok'] ?? false) === true && count($pending['jobs'] ?? []) === 1 && $pending['jobs'][0]['job_id'] === $job && !str_contains(json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'Подберите') && !str_contains(json_encode($pending, JSON_THROW_ON_ERROR), 'PRIVATE') && !array_key_exists('context', $pending['jobs'][0]) && !array_key_exists('proposal', $pending['jobs'][0]));
    $context = ManagerPreparedReplyService::context($job);
    $expectedContext = ManagerAiDraftService::prepareInput($snapshot['context'], $snapshot['company_knowledge'], null, $snapshot['reference_context'])['context'];
    preparedReplyCheck('job context comes from current canonical input, source references and approved knowledge', ($context['ok'] ?? false) === true && $context['context'] === $expectedContext && $context['revision'] === $snapshot['revision'] && $context['instructions'] === ManagerAiDraftService::instructions() && $context['company_knowledge']['facts'] === $snapshot['company_knowledge']->facts() && $context['company_knowledge']['revision'] === $snapshot['company_knowledge']->revision() && preg_match('/\A[a-f0-9]{64}\z/D', $context['instruction_revision']) === 1);
    preparedReplyCheck('external identifiers and selection capabilities are excluded from job context', !str_contains(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'PRIVATE') && !isset($context['selection_sources']));
    $beforeInvalid = $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
    foreach ([['evidence_message_ids'=>[999]],['unexpected'=>'value'],['draft'=>''],['history_incomplete'=>true]] as $changes) {
        $invalid = ManagerPreparedReplyService::complete($job, $snapshot['revision'], preparedReplyProposal($changes));
        preparedReplyCheck('ungrounded or malformed completion cannot be stored', ($invalid['ok'] ?? false) === false && $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll() === $beforeInvalid);
    }
    preparedReplyCheck('wrong expected revision cannot complete a job', (ManagerPreparedReplyService::complete($job, str_repeat('0', 64), preparedReplyProposal())['ok'] ?? false) === false && $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll() === $beforeInvalid);
    $proposal = preparedReplyProposal();
    $completed = ManagerPreparedReplyService::complete($job, $snapshot['revision'], $proposal);
    preparedReplyCheck('valid local completion becomes ready with a one-hour lifetime', ($completed['ok'] ?? false) === true && ($completed['idempotent'] ?? true) === false && strtotime($completed['expires_at']) - strtotime($completed['prepared_at']) === 3600);
    $readyRows = $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
    preparedReplyCheck('stored result contains only validated proposal and provenance', $readyRows[0]['status'] === 'ready' && json_decode($readyRows[0]['proposal_json'], true, 16, JSON_THROW_ON_ERROR) === $proposal && hash('sha256', $readyRows[0]['proposal_json']) === $readyRows[0]['proposal_sha256'] && !str_contains($readyRows[0]['proposal_json'], 'PRIVATE') && !str_contains($readyRows[0]['proposal_json'], 'Подберите тур'));
    $again = ManagerPreparedReplyService::complete($job, $snapshot['revision'], $proposal);
    preparedReplyCheck('identical retry is idempotent and does not extend freshness', ($again['ok'] ?? false) === true && ($again['idempotent'] ?? false) === true && $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll() === $readyRows);
    $conflict = ManagerPreparedReplyService::complete($job, $snapshot['revision'], preparedReplyProposal(['draft'=>'Другой текст ответа.']));
    preparedReplyCheck('conflicting retry does not replace a ready reply', ($conflict['ok'] ?? false) === false && $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll() === $readyRows);
    $writes = $pdo->cacheWrites;
    $ready = ManagerPreparedReplyService::prepared(101, 1, $snapshot);
    preparedReplyCheck('fresh owner can retrieve exact prepared reply and cutoff', ($ready['ok'] ?? false) === true && ($ready['prepared'] ?? false) === true && $ready['proposal'] === $proposal && $ready['revision'] === $snapshot['revision'] && $ready['latest_message_id'] === 2 && $ready['job_id'] === $job && $ready['preparation_source'] === 'chatgpt_assistant');
    preparedReplyCheck('read retrieval changes no cache or conversation state', $pdo->cacheWrites === $writes && preparedReplySourceState($pdo) === $sourceBefore && $pdo->sourceWriteAttempts === 0 && !$pdo->inTransaction());
    preparedReplyCheck('admin visibility cannot read another manager prepared reply', (ManagerPreparedReplyService::prepared(101, 2, $snapshot)['prepared'] ?? false) === false && (ManagerPreparedReplyService::prepared(101, 3, $snapshot)['prepared'] ?? false) === false);
    preparedReplyCheck('completed jobs are absent from pending work', (ManagerPreparedReplyService::pending(20)['jobs'] ?? null) === []);

    foreach (['edit','metadata_edit','new_customer','manager_reply','reassignment','source_revoke','project_revoke','project_inactive','inactive_manager','status_closed','max_suspended','test_conversation'] as $change) {
      foreach (['pending','ready'] as $stage) {
        $pdo = preparedReplyFixture($change === 'max_suspended' ? 'max' : 'telegram');
        if ($change === 'source_revoke') $pdo->mutate("INSERT INTO conversation_sources VALUES(10,'Restricted',1,20,'none',0,0); UPDATE conversations SET source_id=10; INSERT INTO manager_group_members VALUES(1,20)");
        $original = preparedReplySnapshot();
        $job = preparedReplyQueue($original);
        $proposal = preparedReplyProposal();
        if ($stage === 'ready') preparedReplyCheck($change.' fixture accepts original completion', (ManagerPreparedReplyService::complete($job, $original['revision'], $proposal)['ok'] ?? false) === true);
        else preparedReplyCheck($change.' pending fixture exposes current context', (ManagerPreparedReplyService::context($job)['ok'] ?? false) === true);
        $sql = match ($change) {
            'edit'=>"UPDATE messages SET text='Исправленный запрос: нужен только отель.' WHERE id=2",
            'metadata_edit'=>"UPDATE messages SET metadata_json='{\"edited_at\":\"2026-10-19 10:02:00\"}' WHERE id=2",
            'new_customer'=>"INSERT INTO messages VALUES(3,101,'inbound','customer','Теперь бюджет 150000.','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE')",
            'manager_reply'=>"INSERT INTO messages VALUES(3,101,'outbound','manager','Уточните даты.','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE')",
            'reassignment'=>'UPDATE conversations SET manager_id=2',
            'source_revoke'=>'DELETE FROM manager_group_members',
            'project_revoke'=>'DELETE FROM manager_projects WHERE manager_id=1',
            'project_inactive'=>'UPDATE projects SET is_active=0 WHERE id=1',
            'inactive_manager'=>'UPDATE managers SET is_active=0 WHERE id=1',
            'status_closed'=>"UPDATE conversations SET status='closed'",
            'max_suspended'=>"INSERT INTO conversation_events VALUES(1,101,'manager_message_failed','manager','{\"category\":\"suspended\"}','2026-10-19 10:02:00')",
            'test_conversation'=>'UPDATE conversations SET is_test=1',
        };
        $pdo->mutate($sql);
        $sourceAfter = preparedReplySourceState($pdo);
        $cacheBefore = $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
        $writes = $pdo->cacheWrites;
        preparedReplyCheck($change.' invalidates '.$stage.' context and completion even without client refresh', (ManagerPreparedReplyService::context($job)['ok'] ?? false) === false && (ManagerPreparedReplyService::complete($job, $original['revision'], $proposal)['ok'] ?? false) === false);
        $stale = ManagerPreparedReplyService::prepared(101, 1, $original);
        $current = preparedReplySnapshot();
        $fresh = ManagerPreparedReplyService::prepared(101, 1, $current);
        preparedReplyCheck($change.' prevents '.$stage.' stale cache retrieval with old or current snapshot', ($stale['prepared'] ?? false) === false && ($fresh['prepared'] ?? false) === false);
        preparedReplyCheck($change.' '.$stage.' refusal does not mutate source or cache', $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll() === $cacheBefore && preparedReplySourceState($pdo) === $sourceAfter && $pdo->sourceWriteAttempts === 0 && $pdo->cacheWrites === $writes && !$pdo->inTransaction());
      }
    }

    $pdo = preparedReplyFixture();
    $original = preparedReplySnapshot();
    $oldJob = preparedReplyQueue($original);
    $pdo->mutate("UPDATE messages SET text='Подберите тур в Египет на двоих, бюджет 180000 рублей.' WHERE id=2");
    $updated = preparedReplySnapshot();
    $sourceAfter = preparedReplySourceState($pdo);
    $newJob = preparedReplyQueue($updated);
    $oldRow = $pdo->prepare('SELECT expires_at FROM manager_prepared_replies WHERE job_id=?');
    $oldRow->execute([$oldJob]);
    preparedReplyCheck('a new canonical revision retires superseded pending work', $oldJob !== $newJob && strtotime($oldRow->fetchColumn()) <= time() && array_column(ManagerPreparedReplyService::pending(20)['jobs'] ?? [], 'job_id') === [$newJob]);
        preparedReplyCheck('retired work cannot be read or completed while the new revision is available', (ManagerPreparedReplyService::context($oldJob)['ok'] ?? false) === false && (ManagerPreparedReplyService::complete($oldJob, $original['revision'], preparedReplyProposal())['ok'] ?? false) === false && (ManagerPreparedReplyService::context($newJob)['context'] ?? null) === ManagerAiDraftService::prepareInput($updated['context'], $updated['company_knowledge'], null, $updated['reference_context'])['context'] && preparedReplySourceState($pdo) === $sourceAfter && $pdo->sourceWriteAttempts === 0);

    $pdo = preparedReplyFixture();
    $snapshot = preparedReplySnapshot();
    $job = preparedReplyQueue($snapshot);
    $pdo->mutate("UPDATE manager_prepared_replies SET expires_at='2000-01-01 00:00:00'");
    $writes = $pdo->cacheWrites;
    preparedReplyCheck('expired pending jobs cannot be analyzed or completed', (ManagerPreparedReplyService::pending(20)['jobs'] ?? null) === [] && (ManagerPreparedReplyService::context($job)['ok'] ?? false) === false && (ManagerPreparedReplyService::complete($job, $snapshot['revision'], preparedReplyProposal())['ok'] ?? false) === false && $pdo->cacheWrites === $writes);
    $pdo = preparedReplyFixture();
    $snapshot = preparedReplySnapshot();
    $job = preparedReplyQueue($snapshot);
    ManagerPreparedReplyService::complete($job, $snapshot['revision'], preparedReplyProposal());
    $pdo->mutate("UPDATE manager_prepared_replies SET expires_at='2000-01-01 00:00:00'");
    $writes = $pdo->cacheWrites;
    preparedReplyCheck('expired ready reply is unavailable and reads do not refresh its TTL', (ManagerPreparedReplyService::prepared(101, 1, $snapshot)['prepared'] ?? false) === false && $pdo->cacheWrites === $writes);
    $requeued = ManagerPreparedReplyService::enqueue([preparedReplyRow($snapshot)]);
    preparedReplyCheck('unchanged expired context does not silently schedule regeneration', ($requeued['ok'] ?? false) === true && ($requeued['inserted'] ?? -1) === 0 && (ManagerPreparedReplyService::pending(20)['jobs'] ?? null) === []);

    foreach (['hash_mismatch','broken_json','ungrounded_evidence','instruction_changed','future_prepared','invalid_ttl'] as $corruption) {
        $pdo = preparedReplyFixture();
        $snapshot = preparedReplySnapshot();
        $job = preparedReplyQueue($snapshot);
        ManagerPreparedReplyService::complete($job, $snapshot['revision'], preparedReplyProposal());
        $pdo->guard = false;
        if (in_array($corruption, ['hash_mismatch','broken_json','ungrounded_evidence'], true)) {
            $json = match ($corruption) {
                'hash_mismatch'=>json_encode(preparedReplyProposal(['draft'=>'Изменённый сохранённый текст.']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'broken_json'=>'{broken',
                'ungrounded_evidence'=>json_encode(preparedReplyProposal(['evidence_message_ids'=>[999]]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            };
            $q = $pdo->prepare('UPDATE manager_prepared_replies SET proposal_json=?,proposal_sha256=?');
            $q->execute([$json, $corruption === 'hash_mismatch' ? str_repeat('0',64) : hash('sha256', $json)]);
        } elseif ($corruption === 'instruction_changed') {
            $pdo->exec("UPDATE manager_prepared_replies SET instruction_revision='".str_repeat('0',64)."'");
        } elseif ($corruption === 'future_prepared') {
            $q = $pdo->prepare('UPDATE manager_prepared_replies SET prepared_at=?,expires_at=?');
            $q->execute([gmdate('Y-m-d H:i:s', time()+300),gmdate('Y-m-d H:i:s', time()+3900)]);
        } else {
            $pdo->prepare('UPDATE manager_prepared_replies SET expires_at=?')->execute([gmdate('Y-m-d H:i:s', time()+7200)]);
        }
        $pdo->guard = true;
        $writes = $pdo->cacheWrites;
        preparedReplyCheck($corruption.' is not exposed or repaired by cache retrieval', (ManagerPreparedReplyService::prepared(101, 1, $snapshot)['prepared'] ?? false) === false && $pdo->cacheWrites === $writes && $pdo->sourceWriteAttempts === 0);
    }

    $pdo = preparedReplyFixture();
    $snapshot = preparedReplySnapshot();
    foreach ([['manager_id'=>2],['project_key'=>'other'],['context_revision'=>str_repeat('f',64)],['latest_message_id'=>999],['conversation_id'=>0],['manager_id'=>0],['context_revision'=>'bad']] as $changes) {
        $result = ManagerPreparedReplyService::enqueue([preparedReplyRow($snapshot, $changes)]);
        preparedReplyCheck('untrusted queue metadata cannot create unauthorized work', ($result['ok'] ?? false) === false || ($result['inserted'] ?? 0) === 0);
    }
    preparedReplyCheck('invalid queue rows leave storage and read markers untouched', (int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_replies')->fetchColumn() === 0 && (int)$pdo->query('SELECT last_read_message_id FROM manager_conversation_reads')->fetchColumn() === 1 && $pdo->sourceWriteAttempts === 0);
    $writes = $pdo->cacheWrites;
    preparedReplyCheck('oversized or associative batches and invalid pending limits are refused', (ManagerPreparedReplyService::enqueue(array_fill(0,21,preparedReplyRow($snapshot)))['ok'] ?? false) === false && (ManagerPreparedReplyService::enqueue(['unexpected'=>preparedReplyRow($snapshot)])['ok'] ?? false) === false && (ManagerPreparedReplyService::pending(0)['ok'] ?? false) === false && (ManagerPreparedReplyService::pending(21)['ok'] ?? false) === false && $pdo->cacheWrites === $writes);
    foreach (['', 'not-a-job', str_repeat('0',64)] as $invalidJob) {
        preparedReplyCheck('unknown or malformed job cannot expose context or accept completion', (ManagerPreparedReplyService::context($invalidJob)['ok'] ?? false) === false && (ManagerPreparedReplyService::complete($invalidJob, $snapshot['revision'], preparedReplyProposal())['ok'] ?? false) === false);
    }
    $job = preparedReplyQueue($snapshot);
    $hold = preparedReplyProposal(['should_draft'=>false,'draft'=>null,'next_action'=>'check_offer','reason'=>'До ответа нужно проверить условия предложения.','checks'=>['Проверить актуальные условия предложения.']]);
    preparedReplyCheck('a human-check decision is retained without inventing customer text', (ManagerPreparedReplyService::complete($job, $snapshot['revision'], $hold)['ok'] ?? false) === true && (ManagerPreparedReplyService::prepared(101, 1, $snapshot)['proposal'] ?? null) === $hold);
    ProjectConfig::resetForTests(['id'=>'other', 'brand'=>['name'=>'Other project']]);
    $writes = $pdo->cacheWrites;
    preparedReplyCheck('a different runtime project cannot enumerate or complete another project work', (ManagerPreparedReplyService::pending(20)['jobs'] ?? null) === [] && (ManagerPreparedReplyService::context($job)['ok'] ?? false) === false && (ManagerPreparedReplyService::complete($job, $snapshot['revision'], $hold)['ok'] ?? false) === false && (ManagerPreparedReplyService::prepared(101, 1, $snapshot)['prepared'] ?? false) === false && $pdo->cacheWrites === $writes);
    preparedReplyCheck('all cache operations finish without source writes or transaction leakage', $pdo->sourceWriteAttempts === 0 && !$pdo->inTransaction());
    preparedReplySelectionRun();
    preparedReplyWaitingRun();
    preparedReplyCoverage();
    echo 'Manager prepared replies'.(PreparedReplyPdo::$mysqlFixture ? ' (MySQL)' : '').': '.($preparedReplyPassed - $startedWith)." checks passed\n";
}

function preparedReplySelectionFixture(): array
{
    $pdo = preparedReplyFixture();
    $pdo->mutate("UPDATE messages SET text='Варианты https://qui-quo.ru/AAAA-0001' WHERE id=1; UPDATE messages SET text='Какой вариант лучше для двоих на 7 ночей в пределах 200000 рублей?' WHERE id=2");
    $snapshot = preparedReplySnapshot();
    return [$pdo, $snapshot, preparedReplyQueue($snapshot)];
}

function preparedReplyWaitingFixture(): array
{
    $pdo = preparedReplyFixture();
    $pdo->mutate("UPDATE conversations SET manager_id=NULL,status='waiting_manager'");
    $snapshot = ManagerAiDraftContextService::preparationSnapshot(101,0);
    $queued = ManagerPreparedReplyService::enqueue([preparedReplyRow($snapshot,['manager_id'=>0])]);
    if (empty($snapshot['ok']) || empty($queued['job_ids'][0])) throw new RuntimeException('FAIL waiting fixture captures and queues real unassigned enquiry');
    return [$pdo,$snapshot,$queued['job_ids'][0]];
}

function preparedReplyWaitingRun(): void
{
    // Keep the fixed source fixture inside the collector's unchanged 72-hour window.
    $capturedAtUtc = '2026-10-19 10:03:00';
    [$pdo,$waiting,$job] = preparedReplyWaitingFixture();
    $sourceBefore = preparedReplySourceState($pdo);
    preparedReplyCheck('unassigned preparation does not grant manual reply rights to manager or admin', empty(ManagerAiDraftContextService::snapshot(101,1)['ok']) && empty(ManagerAiDraftContextService::snapshot(101,2)['ok']));
    $pending = ManagerPreparedReplyService::pending(20);
    $context = ManagerPreparedReplyService::context($job);
    preparedReplyCheck('worker can read current waiting enquiry without assigning an administrator', ($pending['jobs'][0]['manager_id'] ?? -1) === 0 && ($context['ok'] ?? false) && $context['preparation_scope'] === 'unassigned_waiting' && $context['revision'] === $waiting['revision'] && preparedReplySourceState($pdo) === $sourceBefore);
    $ordinary = ManagerDraftPilotCollector::collect($pdo,[1,2],20,null,null,$capturedAtUtc);
    $both = ManagerDraftPilotCollector::collect($pdo,[1,2],20,null,null,$capturedAtUtc,true);
    preparedReplyCheck('cron opt-in includes waiting inbound enquiries while old pilot scope is unchanged', ($ordinary['exported_count'] ?? -1) === 0 && ($both['exported_count'] ?? 0) === 1 && $both['conversations'][0]['assigned_manager_id'] === 0 && $both['conversations'][0]['revision'] === $waiting['revision'] && preparedReplySourceState($pdo) === $sourceBefore && $pdo->sourceWriteAttempts === 0);
    $proposal = preparedReplyProposal();
    preparedReplyCheck('unassigned private proposal saves without customer or ownership writes', (ManagerPreparedReplyService::complete($job,$waiting['revision'],$proposal)['ok'] ?? false) && preparedReplySourceState($pdo) === $sourceBefore && $pdo->sourceWriteAttempts === 0);
    preparedReplyCheck('waiting proposal does not bypass composer ownership', !(ManagerPreparedReplyService::prepared(101,1,$waiting)['prepared'] ?? false) && !(ManagerPreparedReplyService::prepared(101,2,$waiting)['prepared'] ?? false));
    $pdo->mutate("UPDATE conversations SET manager_id=1,status='manager'");
    $taken = preparedReplySnapshot();
    $ready = ManagerPreparedReplyService::prepared(101,1,$taken);
    preparedReplyCheck('real take retains identical evidence but creates an owner-specific insertion revision', $taken['preparation_revision'] === $waiting['revision'] && $taken['revision'] !== $waiting['revision'] && ($ready['proposal'] ?? null) === $proposal && $ready['revision'] === $taken['revision'] && $ready['latest_message_id'] === 2);
    preparedReplyCheck('take invalidates old worker completion and old client revision', !(ManagerPreparedReplyService::context($job)['ok'] ?? false) && !(ManagerPreparedReplyService::complete($job,$waiting['revision'],$proposal)['ok'] ?? false) && !(ManagerPreparedReplyService::prepared(101,1,$waiting)['prepared'] ?? false));
    preparedReplyCheck('another visible administrator cannot consume the new owner draft', !(ManagerPreparedReplyService::prepared(101,2,$taken)['prepared'] ?? false) && !(ManagerPreparedReplyService::prepared(101,3,$taken)['prepared'] ?? false));

    [$pdo,$waiting,$job] = preparedReplyWaitingFixture();
    $pdo->mutate("INSERT INTO conversations SELECT 2,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,'manager',lead_stage_key,1,started_at,last_message_at,closed_at,'OTHER PRIVATE',is_test FROM conversations WHERE id=101; INSERT INTO messages VALUES(3,2,'inbound','customer','Нужен тур на двоих','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE'); UPDATE managers SET is_working=0 WHERE id=1");
    $mixed = ManagerDraftPilotCollector::collect($pdo,[1,2],20,null,null,$capturedAtUtc,true);
    $owners = array_column($mixed['conversations'] ?? [],'assigned_manager_id'); sort($owners);
    preparedReplyCheck('one bounded cron batch includes waiting and assigned off-shift enquiries', ($mixed['candidate_count'] ?? 0) === 2 && ($mixed['exported_count'] ?? 0) === 2 && $owners === [0,1] && $pdo->sourceWriteAttempts === 0);
    foreach ([
        'callback'=>"UPDATE messages SET metadata_json='{\"type\":\"callback\"}' WHERE id=2",
        'bot_started'=>"UPDATE messages SET metadata_json='{\"type\":\"bot_started\"}' WHERE id=2",
        'no_customer_text'=>"DELETE FROM messages WHERE sender_type='customer'",
        'already_answered'=>"INSERT INTO messages VALUES(3,101,'outbound','manager','Уже ответили','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE')",
    ] as $case=>$sql) {
        [$pdo,$waiting,$job] = preparedReplyWaitingFixture(); $pdo->mutate($sql);
        $batch = ManagerDraftPilotCollector::collect($pdo,[1,2],20,null,null,$capturedAtUtc,true);
        preparedReplyCheck($case.' does not manufacture unassigned reply work', ($batch['candidate_count'] ?? -1) === 0 && ($batch['exported_count'] ?? -1) === 0 && $pdo->sourceWriteAttempts === 0);
    }

    foreach ([
        'source_text'=>"UPDATE messages SET text='Теперь нужен только отель' WHERE id=2",
        'source_metadata'=>"UPDATE messages SET metadata_json='{\"edited_at\":\"changed\"}' WHERE id=2",
        'new_customer'=>"INSERT INTO messages VALUES(3,101,'inbound','customer','Бюджет изменился','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE')",
        'manager_reply'=>"INSERT INTO messages VALUES(3,101,'outbound','manager','Отправленные варианты','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE')",
        'different_source'=>"UPDATE conversations SET source_id=999",
        'expired_cache'=>"UPDATE manager_prepared_replies SET expires_at='2000-01-01 00:00:00'",
        'revoked_owner'=>'DELETE FROM manager_projects WHERE manager_id=1',
    ] as $case=>$sql) {
        [$pdo,$waiting,$job] = preparedReplyWaitingFixture();
        ManagerPreparedReplyService::complete($job,$waiting['revision'],$proposal);
        $pdo->mutate("UPDATE conversations SET manager_id=1,status='manager'");
        $pdo->mutate($sql);
        $current = preparedReplySnapshot();
        preparedReplyCheck($case.' prevents stale waiting-draft carryover after take', !(ManagerPreparedReplyService::prepared(101,1,$current)['prepared'] ?? false) && $pdo->sourceWriteAttempts === 0 && !$pdo->inTransaction());
    }
    foreach ([
        'admin_revoked'=>'DELETE FROM manager_projects WHERE manager_id=2',
        'admin_inactive'=>'UPDATE managers SET is_active=0 WHERE id=2',
        'admin_demoted'=>"UPDATE managers SET role='manager' WHERE id=2",
        'project_inactive'=>'UPDATE projects SET is_active=0 WHERE id=1',
        'test_conversation'=>'UPDATE conversations SET is_test=1',
        'closed'=>"UPDATE conversations SET status='closed'",
        'self_service'=>"UPDATE conversations SET status='ai'",
    ] as $case=>$sql) {
        [$pdo,$waiting,$job] = preparedReplyWaitingFixture();
        $pdo->mutate($sql);
        $cacheBefore = $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
        preparedReplyCheck($case.' blocks waiting context and saving without repair', !(ManagerPreparedReplyService::context($job)['ok'] ?? false) && !(ManagerPreparedReplyService::complete($job,$waiting['revision'],$proposal)['ok'] ?? false) && $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll() === $cacheBefore && $pdo->sourceWriteAttempts === 0);
    }
}

function preparedReplySelectionRun(): void
{
    $html = '<div class="tour"><b class="name">Synthetic Hotel</b><div class="details"><span class="room">2 взрослых, стандарт</span><span class="nights">7 ночей</span><span class="price">190000 RUB</span></div><div class="details"><span class="room">2 взрослых, улучшенный</span><span class="price">210000 RUB</span></div></div>';
    [$pdo, $snapshot, $job] = preparedReplySelectionFixture();
    $sourceBefore = preparedReplySourceState($pdo);
    $cacheBefore = $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
    $reads = 0;
    $transport = static function(array $urls) use (&$reads, $pdo, $html): array {
        ++$reads;
        preparedReplyCheck('selection network read holds no database transaction', !$pdo->inTransaction());
        preparedReplyCheck('only canonical retained selection URL reaches transport', $urls === ['https://qui-quo.ru/AAAA-0001']);
        return [$urls[0]=>['status'=>200,'type'=>'text/html','html'=>$html]];
    };
    $context = ManagerPreparedReplyService::context($job, $transport);
    $input = $context['context'] ?? [];
    $page = $input['selection_snapshots'][0] ?? [];
    preparedReplyCheck('worker receives distinct observed card variants bound to source message', ($context['ok'] ?? false) && $reads === 1 && count($page['offers'] ?? []) === 2 && $page['source_messages'] === [['message_id'=>1,'sender_type'=>'manager','sent_at'=>'2026-10-19 10:00:00']] && $page['offers'][0]['hotel'] === 'Synthetic Hotel' && $page['offers'][0]['price_amount'] === '190000' && $page['offers'][1]['price_amount'] === '210000');
    preparedReplyCheck('worker card fields retain unknown price basis and current availability', $page['historical_send_snapshot'] === false && $page['current_price_verified'] === false && $page['offers'][0]['price_basis'] === null && !$input['selections_link_unread'] && !$input['messages'][0]['selections_link_unread']);
    $serialized = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    preparedReplyCheck('worker sees no raw URL HTML or contact data', !str_contains($serialized, 'qui-quo.ru') && !str_contains($serialized, 'AAAA-0001') && !str_contains($serialized, '<div') && !str_contains($serialized, 'PRIVATE') && !isset($input['selection_sources']));
    preparedReplyCheck('page observation leaves canonical revision and source/cache data unchanged', $context['revision'] === $snapshot['revision'] && preparedReplySourceState($pdo) === $sourceBefore && $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll() === $cacheBefore && $pdo->sourceWriteAttempts === 0);
    ManagerPreparedReplyService::context($job, $transport);
    preparedReplyCheck('explicit worker reread makes a new observation rather than reusing a page cache', $reads === 2);
    $proposal = preparedReplyProposal(['should_draft'=>false,'draft'=>null,'next_action'=>'check_offer','reason'=>'Сопоставить два номера и подтвердить цену.','checks'=>['Проверить единицу цены и наличие.'],'evidence_message_ids'=>[1,2]]);
    $saved = ManagerPreparedReplyService::complete($job, $snapshot['revision'], $proposal);
    $ready = ManagerPreparedReplyService::prepared(101, 1, $snapshot);
    preparedReplyCheck('card-informed eight-field proposal saves without persisting page contents', ($saved['ok'] ?? false) && ($ready['proposal'] ?? null) === $proposal && !str_contains(json_encode($pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll()), 'Synthetic Hotel') && $pdo->sourceWriteAttempts === 0);

    foreach (['failure','exception','unavailable','office','unsupported'] as $case) {
        [$pdo, $snapshot, $job] = preparedReplySelectionFixture();
        if ($case === 'office' || $case === 'unsupported') {
            $pdo->mutate($case === 'office' ? "UPDATE messages SET text='Где у вас офис?' WHERE id=2" : "UPDATE messages SET text='Варианты https://qui-quo.ru/AAAA-0001?private=1' WHERE id=1");
            $snapshot = preparedReplySnapshot(); $job = preparedReplyQueue($snapshot);
        }
        $reads = 0;
        $out = ManagerPreparedReplyService::context($job, static function(array $urls) use (&$reads, $case): array {
            ++$reads;
            if ($case === 'exception') throw new RuntimeException('PRIVATE_NETWORK_DETAIL');
            return $case === 'unavailable' ? [$urls[0]=>['status'=>404]] : [];
        });
        preparedReplyCheck($case.' preserves usable context and honest unread evidence', ($out['ok'] ?? false) && $out['context']['selections_link_unread'] && !str_contains(json_encode($out), 'PRIVATE_NETWORK_DETAIL') && $pdo->sourceWriteAttempts === 0);
        if ($case === 'office' || $case === 'unsupported') preparedReplyCheck($case.' never calls selection transport', $reads === 0);
    }

    foreach ([
        'message_edit'=>"UPDATE messages SET text='Варианты https://qui-quo.ru/BBBB-0002' WHERE id=1",
        'new_inbound'=>"INSERT INTO messages VALUES(3,101,'inbound','customer','Другие даты.','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE')",
        'manager_reassigned'=>'UPDATE conversations SET manager_id=2',
        'manager_revoked'=>'DELETE FROM manager_projects WHERE manager_id=1',
        'closed'=>"UPDATE conversations SET status='closed'",
        'test_conversation'=>'UPDATE conversations SET is_test=1',
        'expired'=>"UPDATE manager_prepared_replies SET expires_at='2000-01-01 00:00:00'",
        'completed'=>"UPDATE manager_prepared_replies SET status='ready'",
    ] as $case=>$sql) {
        [$pdo, $snapshot, $job] = preparedReplySelectionFixture();
        $out = ManagerPreparedReplyService::context($job, static function(array $urls) use ($pdo, $sql, $html): array {
            $pdo->mutate($sql);
            return [$urls[0]=>['status'=>200,'type'=>'text/html','html'=>$html]];
        });
        preparedReplyCheck($case.' during page read cannot expose stale card/transcript context', ($out['ok'] ?? true) === false && !isset($out['context']) && !str_contains(json_encode($out), 'Synthetic Hotel') && $pdo->sourceWriteAttempts === 0 && !$pdo->inTransaction());
    }
}

if(defined('MANAGER_PREPARED_FIXTURE_ONLY'))return;

try {
    preparedReplyRun();
    // The same behavior suite runs against MySQL only when explicitly enabled.
    // CI should provision a fresh maxbot_prepared_test[_suffix] database first.
    if (getenv('PREPARED_REPLY_MYSQL_DSN') !== false && getenv('PREPARED_REPLY_MYSQL_DSN') !== '') {
        PreparedReplyPdo::$mysqlFixture = true;
        preparedReplyRun();
    }
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof RuntimeException && str_starts_with($error->getMessage(),'FAIL ') ? $error->getMessage() : 'FAIL synthetic prepared-reply fixture')."\n");
    exit(1);
} finally {
    if (PreparedReplyPdo::$mysqlFixture) {
        try {
            $connection = (new ReflectionProperty(ConversationDb::class, 'pdo'))->getValue();
            if ($connection instanceof PreparedReplyPdo) {
                if ($connection->inTransaction()) $connection->rollBack();
                $connection->resetMysqlTables();
            }
        } catch (Throwable $ignored) { fwrite(STDERR, "FAIL MySQL fixture cleanup\n"); exit(1); }
    }
}
