<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerPreparedReplyService.php';

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
    public const TABLES = ['manager_prepared_replies','manager_conversation_reads','messages','conversation_events','manager_group_members','conversation_sources','conversations','manager_customer_names','customers','manager_projects','managers','projects'];
    public static bool $mysqlFixture = false;
    public bool $guard = false;
    public int $sourceWriteAttempts = 0;
    public int $cacheWrites = 0;
    public array $executed = [];

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
        $this->executed[] = $sql;
        if (preg_match('/\A\s*SELECT\b/i', $sql)) return;
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            && in_array($sql, ['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ','SET TRANSACTION READ ONLY'], true)) return;
        if (preg_match('/\A\s*(?:INSERT(?:\s+OR\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM)\s+manager_prepared_replies\b/i', $sql)) {
            ++$this->cacheWrites;
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
CREATE TABLE manager_prepared_replies(job_id CHAR(64) PRIMARY KEY,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,project_key TEXT NOT NULL,context_revision CHAR(64) NOT NULL,instruction_revision CHAR(64) NOT NULL,latest_message_id INTEGER NOT NULL,status TEXT NOT NULL,proposal_json TEXT,proposal_sha256 CHAR(64),created_at TEXT NOT NULL,prepared_at TEXT,expires_at TEXT);
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other project',1);
INSERT INTO managers VALUES(1,'owner','PRIVATE manager','manager','PRIVATE@example.test',1,1),(2,'admin','PRIVATE admin','admin','',1,1),(3,'outside','Outside project','admin','',1,1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2);
INSERT INTO customers VALUES(10,'PRIVATE customer','79995554433','PRIVATE@example.test');
INSERT INTO manager_customer_names VALUES(10,'PRIVATE working label',1);
INSERT INTO conversations VALUES(1,10,'anytour',0,'telegram','','','','manager','new',1,'2026-10-19 10:00:00','2026-10-19 10:01:00',NULL,'PRIVATE external chat',0);
INSERT INTO manager_conversation_reads VALUES(1,1,1,'2026-10-19 10:00:00');
INSERT INTO messages VALUES(1,1,'outbound','manager','Здравствуйте!','2026-10-19 10:00:00','telegram',NULL,'PRIVATE','PRIVATE');
INSERT INTO messages VALUES(2,1,'inbound','customer','Подберите тур в Турцию на двоих, бюджет 200000 рублей.','2026-10-19 10:01:00','telegram',NULL,'PRIVATE','PRIVATE');
";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        // The production cache migration has a BIGINT UNSIGNED conversation FK
        // and binary bounded proposal storage; exercise those actual constraints.
        $schema = str_replace('CREATE TABLE conversations(id INTEGER PRIMARY KEY', 'CREATE TABLE conversations(id BIGINT UNSIGNED PRIMARY KEY', $schema);
        $schema = preg_replace('/CREATE TABLE manager_prepared_replies\([^;]+;/s', '', $schema);
        $schema = preg_replace('/(CREATE TABLE [^;]+\));/s', '$1 ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;', $schema);
        if (!is_string($schema)) throw new RuntimeException('invalid_mysql_fixture_schema');
        $migration = file_get_contents(dirname(__DIR__).'/migrations/044_manager_prepared_replies.sql');
        if (!is_string($migration)) throw new RuntimeException('missing_mysql_fixture_migration');
        $pdo->exec($schema);
        $pdo->exec($migration);
    } else {
        $pdo->exec($schema);
    }
    if ($channel !== 'telegram') $pdo->prepare('UPDATE conversations SET channel=?')->execute([$channel]);
    $pdo->guard = true;
    return $pdo;
}

function preparedReplySourceState(PreparedReplyPdo $pdo): array
{
    $state = [];
    foreach (['projects','manager_projects','managers','customers','manager_customer_names','conversations','conversation_sources','manager_group_members','conversation_events','messages','manager_conversation_reads'] as $table) {
        $state[$table] = $pdo->query('SELECT * FROM '.$table)->fetchAll();
    }
    return $state;
}

function preparedReplySnapshot(int $actor = 1): array
{
    return ManagerAiDraftContextService::snapshot(1, $actor);
}

function preparedReplyRow(array $snapshot, array $changes = []): array
{
    return array_replace(['conversation_id'=>1,'manager_id'=>1,'project_key'=>'anytour',
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

function preparedReplyRun(): void
{
    global $preparedReplyPassed;
    $startedWith = $preparedReplyPassed;
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
    preparedReplyCheck('job context comes from current canonical input and approved knowledge', ($context['ok'] ?? false) === true && $context['context'] === $snapshot['context'] && $context['revision'] === $snapshot['revision'] && $context['instructions'] === ManagerAiDraftService::instructions() && $context['company_knowledge']['facts'] === $snapshot['company_knowledge']->facts() && $context['company_knowledge']['revision'] === $snapshot['company_knowledge']->revision() && preg_match('/\A[a-f0-9]{64}\z/D', $context['instruction_revision']) === 1);
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
    $ready = ManagerPreparedReplyService::prepared(1, 1, $snapshot);
    preparedReplyCheck('fresh owner can retrieve exact prepared reply and cutoff', ($ready['ok'] ?? false) === true && ($ready['prepared'] ?? false) === true && $ready['proposal'] === $proposal && $ready['revision'] === $snapshot['revision'] && $ready['latest_message_id'] === 2 && $ready['job_id'] === $job && $ready['preparation_source'] === 'chatgpt_assistant');
    preparedReplyCheck('read retrieval changes no cache or conversation state', $pdo->cacheWrites === $writes && preparedReplySourceState($pdo) === $sourceBefore && $pdo->sourceWriteAttempts === 0 && !$pdo->inTransaction());
    preparedReplyCheck('admin visibility cannot read another manager prepared reply', (ManagerPreparedReplyService::prepared(1, 2, $snapshot)['prepared'] ?? false) === false && (ManagerPreparedReplyService::prepared(1, 3, $snapshot)['prepared'] ?? false) === false);
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
            'new_customer'=>"INSERT INTO messages VALUES(3,1,'inbound','customer','Теперь бюджет 150000.','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE')",
            'manager_reply'=>"INSERT INTO messages VALUES(3,1,'outbound','manager','Уточните даты.','2026-10-19 10:02:00','telegram',NULL,'PRIVATE','PRIVATE')",
            'reassignment'=>'UPDATE conversations SET manager_id=2',
            'source_revoke'=>'DELETE FROM manager_group_members',
            'project_revoke'=>'DELETE FROM manager_projects WHERE manager_id=1',
            'project_inactive'=>'UPDATE projects SET is_active=0 WHERE id=1',
            'inactive_manager'=>'UPDATE managers SET is_active=0 WHERE id=1',
            'status_closed'=>"UPDATE conversations SET status='closed'",
            'max_suspended'=>"INSERT INTO conversation_events VALUES(1,1,'manager_message_failed','manager','{\"category\":\"suspended\"}','2026-10-19 10:02:00')",
            'test_conversation'=>'UPDATE conversations SET is_test=1',
        };
        $pdo->mutate($sql);
        $sourceAfter = preparedReplySourceState($pdo);
        $cacheBefore = $pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();
        $writes = $pdo->cacheWrites;
        preparedReplyCheck($change.' invalidates '.$stage.' context and completion even without client refresh', (ManagerPreparedReplyService::context($job)['ok'] ?? false) === false && (ManagerPreparedReplyService::complete($job, $original['revision'], $proposal)['ok'] ?? false) === false);
        $stale = ManagerPreparedReplyService::prepared(1, 1, $original);
        $current = preparedReplySnapshot();
        $fresh = ManagerPreparedReplyService::prepared(1, 1, $current);
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
    preparedReplyCheck('retired work cannot be read or completed while the new revision is available', (ManagerPreparedReplyService::context($oldJob)['ok'] ?? false) === false && (ManagerPreparedReplyService::complete($oldJob, $original['revision'], preparedReplyProposal())['ok'] ?? false) === false && (ManagerPreparedReplyService::context($newJob)['context'] ?? null) === $updated['context'] && preparedReplySourceState($pdo) === $sourceAfter && $pdo->sourceWriteAttempts === 0);

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
    preparedReplyCheck('expired ready reply is unavailable and reads do not refresh its TTL', (ManagerPreparedReplyService::prepared(1, 1, $snapshot)['prepared'] ?? false) === false && $pdo->cacheWrites === $writes);
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
        preparedReplyCheck($corruption.' is not exposed or repaired by cache retrieval', (ManagerPreparedReplyService::prepared(1, 1, $snapshot)['prepared'] ?? false) === false && $pdo->cacheWrites === $writes && $pdo->sourceWriteAttempts === 0);
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
    preparedReplyCheck('a human-check decision is retained without inventing customer text', (ManagerPreparedReplyService::complete($job, $snapshot['revision'], $hold)['ok'] ?? false) === true && (ManagerPreparedReplyService::prepared(1, 1, $snapshot)['proposal'] ?? null) === $hold);
    ProjectConfig::resetForTests(['id'=>'other', 'brand'=>['name'=>'Other project']]);
    $writes = $pdo->cacheWrites;
    preparedReplyCheck('a different runtime project cannot enumerate or complete another project work', (ManagerPreparedReplyService::pending(20)['jobs'] ?? null) === [] && (ManagerPreparedReplyService::context($job)['ok'] ?? false) === false && (ManagerPreparedReplyService::complete($job, $snapshot['revision'], $hold)['ok'] ?? false) === false && (ManagerPreparedReplyService::prepared(1, 1, $snapshot)['prepared'] ?? false) === false && $pdo->cacheWrites === $writes);
    preparedReplyCheck('all cache operations finish without source writes or transaction leakage', $pdo->sourceWriteAttempts === 0 && !$pdo->inTransaction());
    echo 'Manager prepared replies'.(PreparedReplyPdo::$mysqlFixture ? ' (MySQL)' : '').': '.($preparedReplyPassed - $startedWith)." checks passed\n";
}

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
