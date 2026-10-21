<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerDraftPilotCollector.php';

/** Execute production SQL/services on isolated SQLite, rejecting every capture write. */
final class DraftPilotCollectorStatement extends PDOStatement
{
    protected function __construct(private DraftPilotCollectorPdo $owner) {}

    public function execute(?array $params = null): bool
    {
        $this->owner->observe($this->queryString, 'execute');
        return parent::execute($params);
    }
}

final class DraftPilotCollectorPdo extends PDO
{
    public bool $guard = false;
    public array $observed = [];
    public int $writeAttempts = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $fixtureRaceWrites = 0;
    public string $failQuery = '';
    public ?string $driverOverride = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DraftPilotCollectorStatement::class, [$this]]);
        // SQLite JSON_EXTRACT already unquotes strings, unlike MySQL.
        $this->sqliteCreateFunction('JSON_UNQUOTE', static fn($value) => $value, 1);
    }

    public function observe(string $sql, string $method): void
    {
        if (!$this->guard) return;
        $this->observed[] = ['method'=>$method, 'sql'=>$sql, 'transaction'=>$this->inTransaction()];
        if (preg_match('/\A\s*SELECT\b/i', $sql) !== 1) {
            ++$this->writeAttempts;
            throw new RuntimeException('fixture_rejected_write');
        }
        if ($this->failQuery !== '' && str_contains($sql, $this->failQuery)) {
            throw new RuntimeException('PRIVATE synthetic database detail https://private.test/failure');
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->observe($query, 'prepare');
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->observe($query, 'query');
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->observe($statement, 'exec');
        return parent::exec($statement);
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME && $this->driverOverride !== null) return $this->driverOverride;
        return parent::getAttribute($attribute);
    }

    public function commit(): bool
    {
        $result = parent::commit();
        ++$this->commits;
        return $result;
    }

    public function rollBack(): bool
    {
        $result = parent::rollBack();
        ++$this->rollbacks;
        return $result;
    }

    /** Simulate a separately committed writer between the two capture snapshots. */
    public function fixtureRace(string $sql): void
    {
        if ($this->inTransaction()) throw new RuntimeException('FAIL race fixture ran inside collector transaction');
        ++$this->fixtureRaceWrites;
        parent::exec($sql);
    }
}

const DRAFT_PILOT_CAPTURED = '2026-10-19 12:00:00';
const DRAFT_PILOT_SINCE = '2026-10-16 12:00:00';

$passed = 0;
function draftPilotCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    ++$passed;
    echo 'PASS '.$name."\n";
}

function draftPilotFixture(bool $pending = true): DraftPilotCollectorPdo
{
    $pdo = new DraftPilotCollectorPdo();
    (new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $pdo);
    foreach ([ProjectAccessService::class, ManagerAuthService::class, RoutingAccessService::class] as $class) {
        (new ReflectionProperty($class, 'schemaReady'))->setValue(null, false);
    }
    ProjectConfig::resetForTests(['id'=>'anytour', 'brand'=>['name'=>'Synthetic project']]);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT,is_test INTEGER);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,created_at TEXT,channel TEXT,metadata_json TEXT,sender_id TEXT,external_message_id TEXT);
CREATE INDEX idx_draft_pilot_messages ON messages(conversation_id,id);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER,updated_at TEXT,PRIMARY KEY(manager_id,conversation_id));
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other project',1);
INSERT INTO managers VALUES(1,'owner','PRIVATE manager','manager','PRIVATE@example.test',1,1),(2,'admin','Other owner','admin','',1,1),(3,'other','Outside project','manager','',1,1),(4,'inactive','Inactive owner','manager','',0,1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2),(4,1);
INSERT INTO customers VALUES(10,'PRIVATE customer','79995554433','PRIVATE@example.test');
INSERT INTO manager_customer_names VALUES(10,'PRIVATE working label',1);
INSERT INTO manager_conversation_reads VALUES(1,1,999,'2026-10-19 11:00:00');");
    if ($pending) draftPilotPending($pdo, 1);
    return $pdo;
}

function draftPilotConversation(DraftPilotCollectorPdo $pdo, int $id, array $overrides = []): void
{
    $row = array_replace([
        'id'=>$id, 'customer_id'=>10, 'project_key'=>'anytour', 'source_id'=>0,
        'channel'=>$id===1?'website':'telegram', 'entry_channel'=>'', 'attribution_region'=>'', 'attribution_campaign'=>'',
        'status'=>'manager', 'lead_stage_key'=>'new', 'manager_id'=>1,
        'started_at'=>'2026-10-19 09:00:00', 'last_message_at'=>'2026-10-19 10:00:00',
        'closed_at'=>null, 'external_chat_id'=>'PRIVATE external chat', 'is_test'=>0,
    ], $overrides);
    $pdo->prepare('INSERT INTO conversations VALUES('.implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
}

function draftPilotMessage(DraftPilotCollectorPdo $pdo, int $id, int $cid, array $overrides = []): void
{
    $row = array_replace([
        'id'=>$id, 'conversation_id'=>$cid, 'direction'=>'inbound', 'sender_type'=>'customer',
        'text'=>'Подскажите варианты на октябрь', 'created_at'=>'2026-10-19 10:00:00', 'channel'=>$cid===1?'website':'telegram',
        'metadata_json'=>null, 'sender_id'=>'PRIVATE sender', 'external_message_id'=>'PRIVATE message',
    ], $overrides);
    $pdo->prepare('INSERT INTO messages VALUES('.implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
}

function draftPilotPending(DraftPilotCollectorPdo $pdo, int $id, array $conversation = [], array $message = []): void
{
    draftPilotConversation($pdo, $id, $conversation);
    draftPilotMessage($pdo, $id * 100 + 1, $id, array_replace(['channel'=>$conversation['channel'] ?? ($id===1?'website':'telegram')],$message));
}

function draftPilotBusinessState(DraftPilotCollectorPdo $pdo): array
{
    $state = [];
    foreach (['projects','manager_projects','managers','customers','manager_customer_names','conversations',
        'conversation_sources','manager_group_members','conversation_events','messages','manager_conversation_reads'] as $table) {
        $state[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
    }
    return $state;
}

function draftPilotCapture(DraftPilotCollectorPdo $pdo, array $managers = [1], int $limit = 20,
    ?string $since = DRAFT_PILOT_SINCE, ?callable $snapshot = null, ?string $captured = DRAFT_PILOT_CAPTURED): array
{
    $before = draftPilotBusinessState($pdo);
    $pdo->observed = [];
    $pdo->guard = true;
    try {
        $result = ManagerDraftPilotCollector::collect($pdo, $managers, $limit, $since, $snapshot, $captured);
    } finally {
        $pdo->guard = false;
    }
    draftPilotCheck('capture releases its own transactions and never attempts a write', !$pdo->inTransaction() && $pdo->writeAttempts === 0);
    $reads = array_filter($pdo->observed, static fn(array $entry): bool => $entry['method'] !== 'prepare');
    draftPilotCheck('all executed capture reads belong to short transactions',
        count(array_filter($reads, static fn(array $entry): bool => !$entry['transaction'])) === 0);
    $after = draftPilotBusinessState($pdo);
    draftPilotCheck('read cursors, manager shifts and project metadata stay untouched',
        $before['manager_conversation_reads'] === $after['manager_conversation_reads']
        && $before['managers'] === $after['managers'] && $before['projects'] === $after['projects']);
    if ($pdo->fixtureRaceWrites === 0) draftPilotCheck('capture preserves every business table', $before === $after);
    return $result;
}

function draftPilotIds(array $result): array
{
    return array_column($result['conversations'], 'conversation_id');
}

function draftPilotStub(int $cid, ?int $latest = null): array
{
    $latest ??= $cid * 100 + 1;
    return [
        'ok'=>true, 'revision'=>str_repeat('a',64), 'latest_message_id'=>$latest,
        'history_incomplete'=>false, 'company_knowledge'=>ManagerCompanyKnowledge::forProject('anytour'),
        'selection_sources'=>[['url'=>'https://private.test/selection?token=PRIVATE']],
        'context'=>['conversation_id'=>$cid, 'as_of_message_id'=>$latest,
            'messages'=>[['id'=>$latest, 'sender_type'=>'customer', 'text'=>'Вопрос клиента', 'event_type'=>'message',
                'created_at'=>'2026-10-19 10:00:00', 'media_unread'=>false, 'links_redacted'=>false, 'selections_link_unread'=>false]],
            'history_incomplete'=>false, 'media_unread'=>false, 'selections_link_unread'=>false],
    ];
}

function draftPilotIsSkipped(array $result, string $reason): bool
{
    return ($result['ok'] ?? false) === true && $result['candidate_count'] === 1
        && $result['exported_count'] === 0 && $result['skipped_count'] === 1
        && $result['conversations'] === [] && ($result['skipped_by_reason'][$reason] ?? 0) === 1;
}

try {
    $pdo = draftPilotFixture();
    $result = draftPilotCapture($pdo);
    draftPilotCheck('canonical snapshot exports the actual assigned owner and customer message',
        $result['ok'] && $result['schema'] === 'manager_draft_pilot_v1' && draftPilotIds($result) === [1]
        && $result['authorized_manager_ids'] === [1] && $result['candidate_count'] === 1
        && $result['exported_count'] === 1 && $result['skipped_count'] === 0
        && $result['captured_at_utc'] === DRAFT_PILOT_CAPTURED && $result['since_utc'] === DRAFT_PILOT_SINCE
        && $result['conversations'][0]['assigned_manager_id'] === 1
        && $result['conversations'][0]['last_customer_message_id'] === 101
        && $result['conversations'][0]['last_customer_message_at'] === '2026-10-19 10:00:00'
        && $result['conversations'][0]['latest_message_id'] === 101 && $pdo->commits === 3);
    draftPilotCheck('export retains explicit evidence limits and normalized company facts',
        $result['unknowns'] === ['outside_selected_page'=>'not_reviewed','media_and_link_contents'=>'not_read','state_after_capture'=>'unknown']
        && $result['conversations'][0]['context']['as_of_message_id'] === 101
        && $result['conversations'][0]['company_knowledge'] === [
            'revision'=>ManagerCompanyKnowledge::forProject('anytour')->revision(),
            'facts'=>ManagerCompanyKnowledge::forProject('anytour')->facts(),
        ]);

    $pdo = draftPilotFixture();
    draftPilotMessage($pdo, 100, 1, ['direction'=>'outbound','sender_type'=>'manager']);
    foreach (['callback','start','bot_started'] as $offset=>$type) {
        $cid = $offset + 2;
        draftPilotPending($pdo, $cid);
        draftPilotMessage($pdo, $cid*100+2, $cid, ['direction'=>'outbound','sender_type'=>'manager']);
        draftPilotMessage($pdo, $cid*100+3, $cid, ['metadata_json'=>json_encode(['type'=>$type])]);
    }
    draftPilotPending($pdo, 5, [], ['text'=>'','metadata_json'=>'{"attachments":[{"type":"photo","url":"https://private.test/image"}]}']);
    draftPilotPending($pdo, 6, [], ['metadata_json'=>'malformed historical metadata']);
    draftPilotPending($pdo, 7, [], ['metadata_json'=>null]);
    draftPilotPending($pdo, 8);
    draftPilotMessage($pdo, 802, 8, ['direction'=>'outbound','sender_type'=>'ai']);
    draftPilotMessage($pdo, 803, 8, ['direction'=>'outbound','sender_type'=>'system']);
    draftPilotPending($pdo, 9);
    draftPilotMessage($pdo, 902, 9, ['direction'=>'outbound','sender_type'=>'manager']);
    draftPilotPending($pdo, 10, [], ['created_at'=>'2026-10-16 11:59:59']);
    draftPilotMessage($pdo, 1002, 10, ['metadata_json'=>'{"type":"callback"}']);
    draftPilotPending($pdo, 11, [], ['created_at'=>'2026-10-19 12:00:01']);
    draftPilotConversation($pdo, 12);
    draftPilotMessage($pdo, 1201, 12, ['sender_type'=>'manager']);
    draftPilotPending($pdo, 13);
    draftPilotMessage($pdo, 1302, 13, ['direction'=>'outbound','sender_type'=>'manager']);
    $pdo->exec("INSERT INTO conversation_events VALUES(1,13,'waiting_manager','system','{}','2026-10-19 11:00:00')");
    $result = draftPilotCapture($pdo);
    draftPilotCheck('real customer continuations survive read markers, null metadata, malformed metadata and empty media captions',
        draftPilotIds($result) === [8,7,6,5,1] && $result['candidate_count'] === 5 && $result['exported_count'] === 5);
    draftPilotCheck('AI and system output do not answer for manager and retain latest snapshot ID',
        $result['conversations'][0]['last_customer_message_id'] === 801 && $result['conversations'][0]['latest_message_id'] === 803);
    $byId = array_column($result['conversations'], null, 'conversation_id');
    draftPilotCheck('malformed metadata and unread media preserve canonical limitation flags',
        $byId[6]['history_incomplete'] && $byId[6]['context']['history_incomplete']
        && $byId[5]['context']['media_unread'] && !$byId[5]['history_incomplete']);
    draftPilotCheck('navigation, starts, answered messages, stale or future customer messages and handoff-only pending are excluded',
        array_intersect(draftPilotIds($result), [2,3,4,9,10,11,12,13]) === []);

    $pdo = draftPilotFixture();
    $pdo->exec('UPDATE managers SET is_working=0 WHERE id=1');
    draftPilotPending($pdo, 2, ['manager_id'=>2]);
    draftPilotPending($pdo, 3, ['manager_id'=>4]);
    draftPilotPending($pdo, 4, ['is_test'=>1]);
    draftPilotPending($pdo, 5, ['status'=>'closed']);
    draftPilotPending($pdo, 6, ['status'=>'waiting_manager']);
    draftPilotPending($pdo, 7, ['customer_id'=>404]);
    draftPilotPending($pdo, 8, ['manager_id'=>0]);
    draftPilotPending($pdo, 9, ['manager_id'=>null]);
    draftPilotPending($pdo, 10, [], ['created_at'=>'2026-10-16 11:59:59']);
    draftPilotPending($pdo, 11, [], ['created_at'=>DRAFT_PILOT_SINCE]);
    draftPilotPending($pdo, 12, [], ['created_at'=>DRAFT_PILOT_CAPTURED]);
    draftPilotPending($pdo, 13, [], ['created_at'=>'2026-10-19 12:00:01']);
    $called = [];
    $result = draftPilotCapture($pdo, [1,4,1], 20, DRAFT_PILOT_SINCE,
        static function(int $cid, int $actor) use ($pdo, &$called): array {
            draftPilotCheck('snapshot callback starts after selection commits', !$pdo->inTransaction());
            $called[] = [$cid,$actor];
            return draftPilotStub($cid);
        });
    draftPilotCheck('cohort, active account, customer existence, current manager status, test and inclusive recency gates apply before capture',
        draftPilotIds($result) === [12,11,1] && $called === [[12,1],[11,1],[1,1]]
        && $result['authorized_manager_ids'] === [1,4] && $result['candidate_count'] === 3);
    draftPilotCheck('off-shift assigned owners remain eligible without a shift mutation',
        (int)$pdo->query('SELECT is_working FROM managers WHERE id=1')->fetchColumn() === 0 && in_array(1,draftPilotIds($result),true));

    $pdo = draftPilotFixture(false);
    for ($cid=1; $cid<=25; ++$cid) draftPilotPending($pdo, $cid);
    $result = draftPilotCapture($pdo, [1], 999, DRAFT_PILOT_SINCE, static fn(int $cid,int $actor): array => draftPilotStub($cid));
    draftPilotCheck('a requested page above twenty is clamped and ties use conversation ID descending',
        $result['limit'] === 20 && $result['candidate_count'] === 20 && $result['exported_count'] === 20
        && draftPilotIds($result) === range(25,6));
    $result = draftPilotCapture($pdo, [1], 2, DRAFT_PILOT_SINCE,
        static fn(int $cid,int $actor): array => $cid === 25 ? ['ok'=>false,'error'=>'not_found'] : draftPilotStub($cid));
    draftPilotCheck('skipped candidates are counted without backfilling beyond the selected page',
        $result['candidate_count'] === 2 && $result['exported_count'] === 1 && $result['skipped_count'] === 1
        && draftPilotIds($result) === [24] && ($result['skipped_by_reason']['not_found'] ?? 0) === 1);

    $pdo = draftPilotFixture();
    draftPilotMessage($pdo, 100, 1, ['direction'=>'outbound','sender_type'=>'manager',
        'text'=>'Варианты https://qui-quo.ru/AB12-CD34?secret=PRIVATE']);
    $pdo->exec("UPDATE messages SET text='200000 RUB, телефон +7 (999) 555-44-33; https://private.test/path',metadata_json='{".'"attachments":[{"type":"image","url":"https://private.test/file","token":"PRIVATE"}]' ."}' WHERE id=101");
    $result = draftPilotCapture($pdo);
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    draftPilotCheck('canonical export omits literal selection URLs, raw metadata and contact identities',
        !str_contains($json,'PRIVATE') && !str_contains($json,'https://') && !str_contains($json,'qui-quo.ru')
        && !str_contains($json,'999) 555') && !str_contains($json,'metadata_json')
        && !str_contains($json,'selection_sources') && str_contains($json,'200000 RUB')
        && $result['conversations'][0]['context']['media_unread']
        && $result['conversations'][0]['context']['selections_link_unread']);
    $result = draftPilotCapture($pdo, [1], 20, DRAFT_PILOT_SINCE,
        static function(int $cid,int $actor): array {
            $snapshot = draftPilotStub($cid);
            $snapshot['context']['private_url'] = 'https://private.test/context?token=PRIVATE';
            $snapshot['unexpected_private'] = 'PRIVATE';
            return $snapshot;
        });
    $json = json_encode($result, JSON_THROW_ON_ERROR);
    draftPilotCheck('export allowlist drops extra callback context and selection capability fields',
        $result['exported_count'] === 1 && !str_contains($json,'PRIVATE') && !str_contains($json,'private.test')
        && !str_contains($json,'private_url') && !str_contains($json,'selection_sources'));

    foreach (['source','project','suspended'] as $refusal) {
        $pdo = draftPilotFixture();
        if ($refusal === 'source') {
            $pdo->exec("INSERT INTO conversation_sources VALUES(10,'Restricted',1,20,'none',0,0); UPDATE conversations SET source_id=10");
        } elseif ($refusal === 'project') {
            $pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');
        } else {
            $pdo->exec("UPDATE conversations SET channel='max'; INSERT INTO conversation_events VALUES(1,1,'manager_message_failed','manager','{\"category\":\"suspended\",\"message\":\"PRIVATE failure\"}','2026-10-19 11:00:00')");
        }
        $result = draftPilotCapture($pdo);
        draftPilotCheck('canonical snapshot refuses current '.$refusal.' access or delivery restriction',
            draftPilotIsSkipped($result, $refusal === 'suspended' ? 'suspended' : 'not_found'));
    }
    $pdo = draftPilotFixture();
    $pdo->exec("UPDATE conversations SET channel='max'; INSERT INTO conversation_events VALUES(1,1,'manager_message_failed','manager','{\"category\":\"suspended\"}','2026-10-19 11:00:00'),(2,1,'bot_started','system','{}','2026-10-19 11:00:00')");
    $result = draftPilotCapture($pdo);
    draftPilotCheck('newer canonical MAX restart restores context without changing failure history', $result['exported_count'] === 1);

    foreach ([
        'answered'=>"INSERT INTO messages VALUES(102,1,'outbound','manager','Ответ','2026-10-19 11:00:00','telegram',NULL,'PRIVATE','PRIVATE')",
        'new_message'=>"INSERT INTO messages VALUES(102,1,'inbound','customer','Ещё вопрос','2026-10-19 11:00:00','telegram',NULL,'PRIVATE','PRIVATE')",
        'new_callback'=>"INSERT INTO messages VALUES(102,1,'inbound','customer','show_tours','2026-10-19 11:00:00','telegram','{\"type\":\"callback\"}','PRIVATE','PRIVATE')",
        'reassigned'=>'UPDATE conversations SET manager_id=2 WHERE id=1',
        'closed'=>"UPDATE conversations SET status='closed' WHERE id=1",
        'test'=>'UPDATE conversations SET is_test=1 WHERE id=1',
        'customer_removed'=>'DELETE FROM customers WHERE id=10',
        'customer_expired'=>"UPDATE messages SET created_at='2026-10-16 11:59:59' WHERE id=101",
    ] as $race=>$sql) {
        $pdo = draftPilotFixture();
        $result = draftPilotCapture($pdo, [1], 20, DRAFT_PILOT_SINCE,
            static function(int $cid,int $actor) use ($pdo,$sql): array {
                draftPilotCheck('race snapshot runs after candidate transaction ends', !$pdo->inTransaction());
                $snapshot = ManagerAiDraftContextService::snapshot($cid,$actor);
                $pdo->fixtureRace($sql);
                return $snapshot;
            });
        draftPilotCheck('post-snapshot recheck skips '.$race.' race', draftPilotIsSkipped($result,'changed_since_selection'));
    }

    foreach (['unauthorized','not_found','reply_not_allowed','suspended','context_unavailable','PRIVATE injected reason'] as $error) {
        $pdo = draftPilotFixture();
        $result = draftPilotCapture($pdo, [1], 20, DRAFT_PILOT_SINCE,
            static fn(int $cid,int $actor): array => ['ok'=>false,'error'=>$error,'detail'=>'PRIVATE https://private.test/error']);
        draftPilotCheck('snapshot failure is safely counted: '.($error === 'PRIVATE injected reason' ? 'unknown reason' : $error),
            draftPilotIsSkipped($result, $error === 'PRIVATE injected reason' ? 'context_unavailable' : $error)
            && !str_contains(json_encode($result),'PRIVATE') && !str_contains(json_encode($result),'private.test'));
    }
    $pdo = draftPilotFixture();
    $result = draftPilotCapture($pdo, [1], 20, DRAFT_PILOT_SINCE,
        static function(int $cid,int $actor): array { throw new RuntimeException('PRIVATE https://private.test/exception'); });
    draftPilotCheck('throwing snapshot is a safe per-conversation failure', draftPilotIsSkipped($result,'context_unavailable')
        && !str_contains(json_encode($result),'PRIVATE'));

    foreach ([
        'wrong_conversation'=>static function(array &$s): void { $s['context']['conversation_id'] = 2; },
        'invalid_revision'=>static function(array &$s): void { $s['revision'] = 'PRIVATE'; },
        'mismatched_latest'=>static function(array &$s): void { $s['context']['as_of_message_id'] = 999; },
        'negative_message_id'=>static function(array &$s): void { $s['context']['messages'][0]['id'] = -1; },
        'unordered_messages'=>static function(array &$s): void { $s['context']['messages'][] = $s['context']['messages'][0]; },
        'oversized_history'=>static function(array &$s): void { $s['context']['messages'] = array_fill(0,121,$s['context']['messages'][0]); },
        'oversized_text'=>static function(array &$s): void { $s['context']['messages'][0]['text'] = str_repeat('я',40001); },
        'invalid_flag'=>static function(array &$s): void { $s['context']['media_unread'] = 'false'; },
        'untrusted_knowledge'=>static function(array &$s): void { $s['company_knowledge'] = ['revision'=>str_repeat('a',64),'facts'=>[]]; },
    ] as $invalid=>$mutate) {
        $pdo = draftPilotFixture();
        $result = draftPilotCapture($pdo, [1], 20, DRAFT_PILOT_SINCE,
            static function(int $cid,int $actor) use ($mutate): array {
                $snapshot = draftPilotStub($cid);
                $mutate($snapshot);
                return $snapshot;
            });
        draftPilotCheck('invalid snapshot shape is refused: '.$invalid, draftPilotIsSkipped($result,'context_unavailable'));
    }

    foreach ([[], [0], [-1], ['1'], [true], [1.0], ['manager'=>1], range(1,2001)] as $cohort) {
        $pdo = draftPilotFixture();
        $result = draftPilotCapture($pdo, $cohort);
        draftPilotCheck('invalid cohort never defaults to all managers', $result === ['ok'=>false,'error'=>'invalid_cohort'] && $pdo->observed === []);
    }
    foreach ([0,-1] as $limit) {
        $pdo = draftPilotFixture();
        draftPilotCheck('nonpositive page limit is refused', draftPilotCapture($pdo,[1],$limit) === ['ok'=>false,'error'=>'invalid_limit']);
    }
    foreach ([
        ['2026-10-16 11:59:59',DRAFT_PILOT_CAPTURED],
        ['2026-10-19 12:00:01',DRAFT_PILOT_CAPTURED],
        ['2026-02-30 00:00:00',DRAFT_PILOT_CAPTURED],
        ['2026-10-16T12:00:00Z',DRAFT_PILOT_CAPTURED],
        ['2026-10-16 12:00:00 ',DRAFT_PILOT_CAPTURED],
        [DRAFT_PILOT_SINCE,'2026-10-19T12:00:00Z'],
        [DRAFT_PILOT_SINCE,'2026-10-32 12:00:00'],
    ] as [$since,$captured]) {
        $pdo = draftPilotFixture();
        draftPilotCheck('invalid or over-wide UTC window is refused before reading',
            draftPilotCapture($pdo,[1],20,$since,null,$captured) === ['ok'=>false,'error'=>'invalid_window'] && $pdo->observed === []);
    }
    $pdo = draftPilotFixture(false);
    $result = draftPilotCapture($pdo,[1],20,null,null,DRAFT_PILOT_CAPTURED);
    draftPilotCheck('omitted lower bound is exactly the captured UTC time minus 72 hours',
        $result['ok'] && $result['since_utc'] === DRAFT_PILOT_SINCE && $result['conversations'] === []);
    $pdo = draftPilotFixture(false);
    $beforeNow = gmdate('Y-m-d H:i:s');
    $result = draftPilotCapture($pdo,[1],20,null,null,null);
    $afterNow = gmdate('Y-m-d H:i:s');
    draftPilotCheck('omitted capture time uses now in UTC and a fixed 72-hour window',
        $result['ok'] && $result['captured_at_utc'] >= $beforeNow && $result['captured_at_utc'] <= $afterNow
        && strtotime($result['captured_at_utc'].' UTC') - strtotime($result['since_utc'].' UTC') === 72*3600);
    $pdo = draftPilotFixture();
    $pdo->driverOverride = 'unsupported';
    draftPilotCheck('unsupported PDO driver is refused safely',
        draftPilotCapture($pdo) === ['ok'=>false,'error'=>'unsupported_driver']);
    $pdo = draftPilotFixture();
    $pdo->failQuery = 'FROM conversations';
    $result = draftPilotCapture($pdo);
    draftPilotCheck('candidate read failure releases its transaction without private details',
        $result === ['ok'=>false,'error'=>'context_unavailable'] && $pdo->rollbacks === 1);
    $pdo = draftPilotFixture();
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,new DraftPilotCollectorPdo());
    $result = draftPilotCapture($pdo);
    draftPilotCheck('default snapshot cannot read from a different global connection',
        $result === ['ok'=>false,'error'=>'context_unavailable']);

    $pdo = draftPilotFixture();
    $pdo->beginTransaction();
    $result = ManagerDraftPilotCollector::collect($pdo,[1],20,DRAFT_PILOT_SINCE,null,DRAFT_PILOT_CAPTURED);
    draftPilotCheck('caller-owned transaction is refused without commit or rollback ownership theft',
        $result === ['ok'=>false,'error'=>'context_unavailable'] && $pdo->inTransaction()
        && $pdo->commits === 0 && $pdo->rollbacks === 0);
    $pdo->rollBack();

    $pdo = draftPilotFixture();
    $pdo->exec("UPDATE conversations SET channel='telegram',last_message_at='2026-10-19 11:59:00' WHERE id=1; UPDATE messages SET channel='telegram' WHERE conversation_id=1");
    draftPilotPending($pdo,2,['channel'=>'max','last_message_at'=>'2026-10-19 11:58:00']);
    draftPilotPending($pdo,3,['channel'=>'telegram','last_message_at'=>'2026-10-19 11:00:00'],['text'=>'Alex asks about a holiday gift card']);
    $state = draftPilotBusinessState($pdo);
    $result = draftPilotCapture($pdo,[1],1);
    draftPilotCheck('fixed internal owner scopes cannot crowd a real tourist out of the bounded candidate page',
        $result['candidate_count']===1 && draftPilotIds($result)===[3] && draftPilotBusinessState($pdo)===$state);
    $pdo->exec("UPDATE conversations SET channel='website' WHERE id=1; UPDATE conversations SET channel='telegram' WHERE id=2; UPDATE messages SET channel='website' WHERE conversation_id=1; UPDATE messages SET channel='telegram' WHERE conversation_id=2");
    $result = draftPilotCapture($pdo);
    draftPilotCheck('same numeric conversations in different channels and normal cards wording remain eligible',
        draftPilotIds($result)===[1,2,3]);
    $predicate=InternalOwnerDialogueScope::conversationSql('c');
    $pdo->exec("UPDATE conversations SET channel='telegram',project_key='other' WHERE id=1");
    draftPilotCheck('fixed owner classification is project bound rather than a global numeric ID ban',
        (int)$pdo->query('SELECT COUNT(*) FROM conversations c WHERE c.id=1 AND NOT '.$predicate)->fetchColumn()===1);

    echo 'Manager draft pilot collector: '.$passed." checks passed\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error instanceof RuntimeException && str_starts_with($error->getMessage(),'FAIL ')
        ? $error->getMessage()."\n" : "FAIL synthetic pilot collector fixture\n");
    exit(1);
}
