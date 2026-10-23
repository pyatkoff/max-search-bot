<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerAssistantService.php';

final class AssistantFixtureStatement extends PDOStatement
{
    protected function __construct(private AssistantFixturePdo $owner) {}
    public function execute(?array $params = null): bool
    {
        $this->owner->observe($this->queryString);
        $result=parent::execute($params);
        if ($this->owner->afterDecisionWrite!==null && preg_match('/\A(?:INSERT INTO|UPDATE) manager_assistant_decisions\b/i',$this->queryString)) {
            $hook=$this->owner->afterDecisionWrite; $this->owner->afterDecisionWrite=null; $hook($this->owner);
        }
        if($this->owner->afterTaskRead!==null&&str_contains($this->queryString,'FROM lead_tasks t LEFT JOIN managers')){
            $hook=$this->owner->afterTaskRead;$this->owner->afterTaskRead=null;$hook($this->owner);
        }
        return $result;
    }
}

/** Real owners against synthetic state; only private assistant/cache writes allowed. */
final class AssistantFixturePdo extends PDO
{
    public bool $guard = false;
    public int $businessWriteAttempts = 0;
    public int $privateWrites = 0;
    public int $operationalReads = 0;
    public ?Closure $afterDecisionWrite = null;
    public ?Closure $afterTaskRead = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[AssistantFixtureStatement::class,[$this]]);
        // Keep PHP 8.2 CI compatibility; PDO's alias is deprecated only in 8.5.
        @$this->sqliteCreateFunction('JSON_UNQUOTE',static fn($v)=>$v);
        @$this->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>gmdate('Y-m-d H:i:s'));
    }
    public function observe(string $sql): void
    {
        if(str_contains($sql,' AS awaiting_manager_reply,')&&str_contains($sql,'FROM conversations c WHERE c.manager_id=?'))++$this->operationalReads;
        if (!$this->guard || preg_match('/\A\s*SELECT\b/i',$sql)) return;
        if (preg_match('/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:manager_prepared_replies|manager_assistant_decisions)\b/i',$sql)) { ++$this->privateWrites; return; }
        ++$this->businessWriteAttempts;
        throw new RuntimeException('unexpected_business_write');
    }
    public function exec(string $statement): int|false { $this->observe($statement); return parent::exec($statement); }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->observe($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    public function mutate(string $sql): void
    {
        $before=$this->guard; $this->guard=false;
        try { parent::exec($sql); } finally { $this->guard=$before; }
    }
}

function assistantCheck(string $name,bool $ok): void
{
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name."\n";
}

function assistantFixture(): AssistantFixturePdo
{
    $pdo=new AssistantFixturePdo();
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic project']]);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
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
CREATE TABLE manager_assistant_decisions(manager_id INTEGER,conversation_id INTEGER,project_key TEXT,recommendation_revision TEXT,context_revision TEXT,instruction_revision TEXT,decision TEXT,created_at TEXT,updated_at TEXT,PRIMARY KEY(manager_id,conversation_id,recommendation_revision));
CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY,conversation_id INTEGER,title TEXT,due_at_utc TEXT,status TEXT,is_pinned INTEGER,assigned_manager_id INTEGER,created_by_manager_id INTEGER,completed_at_utc TEXT,reminder_attempted_at_utc TEXT,reminder_notified_at_utc TEXT,created_at TEXT,updated_at TEXT);
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other project',1);
INSERT INTO managers VALUES(1,'owner','Synthetic manager','manager','',1,1),(2,'admin','Synthetic admin','admin','',1,1),(3,'outside','Other project admin','admin','',1,1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,2);
INSERT INTO customers VALUES(10,'Synthetic customer','PRIVATE PHONE','PRIVATE EMAIL');
INSERT INTO manager_customer_names VALUES(10,'Synthetic working label',1);
INSERT INTO conversations VALUES(1,10,'anytour',0,'website','','','','manager','new',1,'2026-10-19 10:00:00','2026-10-19 10:01:00',NULL,'PRIVATE CHAT',0);
INSERT INTO manager_conversation_reads VALUES(1,1,1,'2026-10-19 10:00:00');
INSERT INTO messages VALUES(1,1,'outbound','manager','Здравствуйте!','2026-10-19 10:00:00','website',NULL,'PRIVATE','PRIVATE');
INSERT INTO messages VALUES(2,1,'inbound','customer','Подберите тур в Турцию на двоих, бюджет 200000 рублей.','2026-10-19 10:01:00','website',NULL,'PRIVATE','PRIVATE');
INSERT INTO lead_tasks VALUES(1,1,'Проверить варианты','2000-01-01 10:00:00','open',0,1,1,NULL,NULL,NULL,'2026-10-19 10:00:00','2026-10-19 10:00:00');
INSERT INTO lead_tasks VALUES(2,1,'Проверить перелёт',NULL,'open',1,1,1,NULL,NULL,NULL,'2026-10-19 10:00:00','2026-10-19 10:00:00');
INSERT INTO lead_tasks VALUES(3,1,'Уже сделано',NULL,'done',0,1,1,'2026-10-19 10:00:00',NULL,NULL,'2026-10-19 10:00:00','2026-10-19 10:00:00');
");
    $pdo->guard=true;
    return $pdo;
}

function assistantPrepare(): array
{
    $snapshot=ManagerAiDraftContextService::snapshot(1,1);
    if (empty($snapshot['ok'])) throw new RuntimeException('FAIL synthetic snapshot');
    $queued=ManagerPreparedReplyService::enqueue([['conversation_id'=>1,'manager_id'=>1,'project_key'=>'anytour','context_revision'=>$snapshot['revision'],'latest_message_id'=>$snapshot['latest_message_id']]]);
    if (empty($queued['job_ids'][0])) throw new RuntimeException('FAIL synthetic enqueue');
    $proposal=['ok'=>true,'should_draft'=>true,'draft'=>'Подскажите, пожалуйста, город вылета и даты поездки?',
        'next_action'=>'clarify','reason'=>'Для подбора нужны город вылета и даты. Состав на двоих уже указан.',
        'checks'=>['Проверить наличие и стоимость подходящих вариантов.'],'evidence_message_ids'=>[2],'history_incomplete'=>false];
    $saved=ManagerPreparedReplyService::complete($queued['job_ids'][0],$snapshot['revision'],$proposal);
    if (empty($saved['ok'])) throw new RuntimeException('FAIL synthetic proposal');
    return [$snapshot,$proposal];
}

function assistantBusinessState(AssistantFixturePdo $pdo): array
{
    $out=[];
    foreach (['projects','manager_projects','managers','customers','manager_customer_names','conversations','conversation_sources','manager_group_members','conversation_events','messages','manager_conversation_reads','lead_tasks'] as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll();
    return $out;
}

try {
    $pdo=assistantFixture(); [, $proposal]=assistantPrepare();
    $before=assistantBusinessState($pdo); $writes=$pdo->privateWrites;
    $detail=ManagerAssistantService::detail(1,1); $item=$detail['item']??[]; $recommendation=$item['recommendation']??[];
    $list=ManagerAssistantService::listing(1);
    assistantCheck('feed and dialog read exactly the same saved recommendation',!empty($detail['ok']) && ($list['items'][0]??null)===$item && $recommendation['reason']===$proposal['reason'] && $recommendation['checks']===$proposal['checks'] && $recommendation['evidence_message_ids']===[2] && $recommendation['decision']===null);
    assistantCheck('projection contains no draft body private contacts or internal context hashes',array_intersect_key($recommendation,array_flip(['draft','context_revision','instruction_revision']))===[] && !str_contains(json_encode($detail),'PRIVATE') && $item['analysis_state']==='current' && $recommendation['should_draft']);
    assistantCheck('existing open tasks retain canonical ordering and deadline semantics',array_column($item['tasks'],'id')===[2,1] && $item['tasks'][0]['due_state']==='unscheduled' && $item['tasks'][1]['due_state']==='overdue' && !$item['tasks_truncated']);
    assistantCheck('reads neither mark messages read nor change tasks routing shifts or private state',assistantBusinessState($pdo)===$before && $pdo->businessWriteAttempts===0 && $pdo->privateWrites===$writes);

    $revision=$recommendation['revision'];
    $saved=ManagerAssistantService::decide(1,1,$revision,'done');
    $duplicate=ManagerAssistantService::decide(1,1,$revision,'done');
    assistantCheck('decision is durable and duplicate completion is idempotent',!empty($saved['ok']) && !$saved['idempotent'] && !empty($duplicate['idempotent']) && ManagerAssistantService::detail(1,1)['item']['recommendation']['decision']==='done' && (int)$pdo->query('SELECT COUNT(*) FROM manager_assistant_decisions')->fetchColumn()===1);
    assistantCheck('conflicting duplicate request cannot overwrite a later decision',(ManagerAssistantService::decide(1,1,$revision,'irrelevant')['error']??'')==='decision_conflict');
    $undo=ManagerAssistantService::decide(1,1,$revision,'open','done');
    $delayed=ManagerAssistantService::decide(1,1,$revision,'done');
    $redo=ManagerAssistantService::decide(1,1,$revision,'irrelevant','open');
    assistantCheck('undo is durable and delayed initial retries cannot resurrect completion',!empty($undo['ok']) && ($delayed['error']??'')==='decision_conflict' && !empty($redo['ok']) && ManagerAssistantService::detail(1,1)['item']['recommendation']['decision']==='irrelevant');
    assistantCheck('unknown decisions and malformed revision are rejected',(ManagerAssistantService::decide(1,1,$revision,'send')['error']??'')==='invalid_request' && (ManagerAssistantService::decide(1,1,'not-a-revision','done')['error']??'')==='invalid_request');
    assistantCheck('decisions change only private metadata',assistantBusinessState($pdo)===$before && $pdo->businessWriteAttempts===0);

    for ($id=4;$id<=55;++$id) $pdo->mutate("INSERT INTO lead_tasks SELECT ".$id.",conversation_id,title,due_at_utc,status,is_pinned,assigned_manager_id,created_by_manager_id,completed_at_utc,reminder_attempted_at_utc,reminder_notified_at_utc,created_at,updated_at FROM lead_tasks WHERE id=1");
    $manyTasks=ManagerAssistantService::detail(1,1)['item'];
    assistantCheck('large existing task sets expose a bounded projection and truthful truncation',count($manyTasks['tasks'])===50 && $manyTasks['tasks_truncated'] && $manyTasks['tasks'][0]['id']===2);

    foreach ([
        'edited transcript'=>"UPDATE messages SET text='Нужны другие даты' WHERE id=2",
        'new message'=>"INSERT INTO messages VALUES(3,1,'inbound','customer','Поменялся бюджет','2026-10-19 10:02:00','website',NULL,'PRIVATE','PRIVATE')",
        'expired proposal'=>"UPDATE manager_prepared_replies SET expires_at='2000-01-01 00:00:00'",
        'old instructions'=>"UPDATE manager_prepared_replies SET instruction_revision='".str_repeat('0',64)."'",
        'corrupt proposal'=>"UPDATE manager_prepared_replies SET proposal_sha256='".str_repeat('0',64)."'",
    ] as $name=>$sql) {
        $pdo=assistantFixture(); assistantPrepare(); $old=ManagerAssistantService::detail(1,1)['item']['recommendation']['revision'];
        $pdo->mutate($sql); $fresh=ManagerAssistantService::detail(1,1);
        assistantCheck($name.' hides old analysis retains tasks and rejects stale decision',!empty($fresh['ok']) && $fresh['item']['analysis_state']==='unavailable' && $fresh['item']['recommendation']===null && count($fresh['item']['tasks'])===2 && (ManagerAssistantService::decide(1,1,$old,'done')['error']??'')==='stale_context' && (int)$pdo->query('SELECT COUNT(*) FROM manager_assistant_decisions')->fetchColumn()===0);
    }

    $pdo=assistantFixture(); assistantPrepare(); $old=ManagerAssistantService::detail(1,1)['item']['recommendation']['revision'];
    ManagerAssistantService::decide(1,1,$old,'done');
    $pdo->mutate("UPDATE messages SET text='Подберите тур в Турцию на двоих, бюджет 250000 рублей.' WHERE id=2"); assistantPrepare();
    $fresh=ManagerAssistantService::detail(1,1)['item']['recommendation'];
    assistantCheck('changed recommendation never inherits an old completed decision',$fresh['revision']!==$old && $fresh['decision']===null && (ManagerAssistantService::decide(1,1,$old,'done')['error']??'')==='stale_context');

    $pdo=assistantFixture(); assistantPrepare(); $old=ManagerAssistantService::detail(1,1)['item']['recommendation']['revision'];
    $pdo->afterDecisionWrite=static function(AssistantFixturePdo $db):void { $db->mutate("UPDATE messages SET text='Подберите тур на двоих с другим бюджетом' WHERE id=2"); };
    $raced=ManagerAssistantService::decide(1,1,$old,'done');
    assistantPrepare(); $fresh=ManagerAssistantService::detail(1,1)['item']['recommendation'];
    assistantCheck('post-commit canonical recheck reports concurrent source change as stale without hiding a new proposal',($raced['error']??'')==='stale_context' && $fresh['revision']!==$old && $fresh['decision']===null && (int)$pdo->query('SELECT COUNT(*) FROM manager_assistant_decisions')->fetchColumn()===1 && $pdo->businessWriteAttempts===0);

    foreach ([
        'source revoked'=>"INSERT INTO conversation_sources VALUES(9,'Restricted',1,9,'none',NULL,0); UPDATE conversations SET source_id=9",
        'project revoked'=>"DELETE FROM manager_projects WHERE manager_id=1",
        'reassigned'=>"UPDATE conversations SET manager_id=2",
        'closed'=>"UPDATE conversations SET status='closed'",
        'test dialogue'=>"UPDATE conversations SET is_test=1",
        'other project'=>"UPDATE conversations SET project_key='other'",
    ] as $name=>$sql) {
        $pdo=assistantFixture(); assistantPrepare(); $old=ManagerAssistantService::detail(1,1)['item']['recommendation']['revision']; $pdo->mutate($sql);
        $out=ManagerAssistantService::listing(1);
        assistantCheck($name.' cannot disclose details tasks analysis or accept decisions',!empty($out['ok']) && $out['items']===[] && (ManagerAssistantService::detail(1,1)['error']??'')==='not_found' && (ManagerAssistantService::decide(1,1,$old,'done')['error']??'')==='not_found');
    }
    $pdo=assistantFixture();
    assistantCheck('admin view still cannot read another manager client',(ManagerAssistantService::detail(2,1)['error']??'')==='not_found' && ManagerAssistantService::listing(2)['items']===[]);
    $pdo->mutate('UPDATE managers SET is_active=0 WHERE id=1');
    assistantCheck('inactive manager cannot read feed or dialog',empty(ManagerAssistantService::listing(1)['ok']) && empty(ManagerAssistantService::detail(1,1)['ok']));

    // Reproduce the real priority boundary: old unanswered and due clients must
    // precede twenty newer answered clients even without a prepared proposal.
    $pdo=assistantFixture();
    $pdo->mutate("DELETE FROM lead_tasks; INSERT INTO conversations SELECT 2,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,external_chat_id,is_test FROM conversations WHERE id=1");
    for($id=3;$id<=22;++$id)$pdo->mutate("INSERT INTO conversations SELECT $id,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,external_chat_id,is_test FROM conversations WHERE id=1");
    $pdo->mutate("INSERT INTO lead_tasks VALUES(1,2,'Arrived own reminder','2000-01-01 10:00:00','open',0,1,1,NULL,NULL,NULL,'2026-10-19 10:00:00','2026-10-19 10:00:00')");
    $before=assistantBusinessState($pdo);$private=$pdo->privateWrites;
    $priority=ManagerAssistantService::listing(1);
    assistantCheck('old unanswered and arrived reminder precede the first page boundary',array_slice(array_column($priority['items']??[],'conversation_id'),0,2)===[1,2]);
    assistantCheck('operational facts do not depend on a fresh AI proposal',($priority['items'][0]['operational']['awaiting_manager_reply']??false)===true&&($priority['items'][1]['operational']['due_task']??false)===true&&$priority['items'][0]['recommendation']===null);
    assistantCheck('priority reads preserve all business and private state',assistantBusinessState($pdo)===$before&&$private===$pdo->privateWrites&&$pdo->businessWriteAttempts===0);

    $pdo=assistantFixture();
    for ($id=2;$id<=235;++$id) $pdo->mutate("INSERT INTO conversations SELECT ".$id.",customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,external_chat_id,is_test FROM conversations WHERE id=1");
    $before=assistantBusinessState($pdo); $ids=[]; $cursor=null; $pages=0;
    do {
        $page=ManagerAssistantService::listing(1,$cursor,20);
        if (empty($page['ok']) || ++$pages>20) throw new RuntimeException('FAIL pagination unavailable');
        array_push($ids,...array_column($page['items'],'conversation_id')); $cursor=$page['next_cursor'];
    } while ($page['has_more']);
    assistantCheck('keyset pagination reaches all 235 own active clients without the old 200 cap',$ids===array_merge([1],range(235,2)) && $pages===12 && $cursor===null);
    assistantCheck('unanalysed clients are honest unavailable rows rather than fabricated work',$page['items'][0]['analysis_state']==='unavailable' && $page['items'][0]['recommendation']===null && $page['coverage']===['scope'=>'own_active','analysis'=>'current_prepared_only'] && assistantBusinessState($pdo)===$before && $pdo->businessWriteAttempts===0);
    $pdo->mutate("INSERT INTO conversation_sources VALUES(9,'Restricted',1,9,'none',NULL,0); UPDATE conversations SET source_id=9 WHERE id>=216");
    $hidden=ManagerAssistantService::listing(1,null,20); $next=ManagerAssistantService::listing(1,$hidden['next_cursor'],20);
    assistantCheck('hidden clients do not consume the priority page or leak through continuation',array_column($hidden['items'],'conversation_id')===array_merge([1],range(215,197)) && $hidden['has_more'] && is_string($hidden['next_cursor']) && array_column($next['items'],'conversation_id')===range(196,177));
    foreach(['owner'=>"UPDATE conversations SET manager_id=2 WHERE id=1",'inactive actor'=>"UPDATE managers SET is_active=0 WHERE id=1",'revoked source'=>"INSERT INTO conversation_sources VALUES(9,'Restricted',1,9,'none',NULL,0);UPDATE conversations SET source_id=9 WHERE id=1"]as$name=>$sql){
        $pdo=assistantFixture();$pdo->afterTaskRead=static function(AssistantFixturePdo $db)use($sql):void{$db->mutate($sql);};$raced=ManagerAssistantService::listing(1);
        assistantCheck('late '.$name.' change cannot disclose an already hydrated page',empty($raced['ok'])&&!isset($raced['items'])&&$pdo->businessWriteAttempts===0);
    }

    // Continuation is bound to current full authorized operational ordering.
    foreach([
        'reply changes priority'=>"INSERT INTO messages VALUES(3,1,'outbound','manager','Answer','2026-10-19 10:02:00','website',NULL,'1','receipt')",
        'owner changes'=>"UPDATE conversations SET manager_id=2 WHERE id=20",
        'source access revoked'=>"INSERT INTO conversation_sources VALUES(9,'Restricted',1,9,'none',NULL,0); UPDATE conversations SET source_id=9 WHERE id=20",
        'task becomes arrived'=>"INSERT INTO lead_tasks VALUES(99,20,'Now due','2000-01-01 10:00:00','open',0,1,1,NULL,NULL,NULL,'2026-10-19 10:00:00','2026-10-19 10:00:00')",
        'client closed'=>"UPDATE conversations SET status='closed' WHERE id=20"
    ]as$name=>$sql){
        $pdo=assistantFixture();for($id=2;$id<=225;++$id)$pdo->mutate("INSERT INTO conversations SELECT $id,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,external_chat_id,is_test FROM conversations WHERE id=1");
        $first=ManagerAssistantService::listing(1);$old=$first['next_cursor'];$pdo->mutate($sql);
        $stale=ManagerAssistantService::listing(1,$old);$fresh=ManagerAssistantService::listing(1);
        assistantCheck($name.' rejects an old cursor without returning a mixed page',($stale['error']??'')==='list_changed'&&!isset($stale['items'])&&!empty($fresh['ok'])&&$fresh['next_cursor']!==$old);
    }
    $pdo=assistantFixture();for($id=2;$id<=25;++$id)$pdo->mutate("INSERT INTO conversations SELECT $id,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,external_chat_id,is_test FROM conversations WHERE id=1");
    $reads=$pdo->operationalReads;$first=ManagerAssistantService::listing(1);
    assistantCheck('only two full-scope metadata statements regardless of page hydration',$pdo->operationalReads-$reads===2);
    $pdo->mutate("INSERT INTO messages VALUES(3,1,'inbound','customer','Another condition','2026-10-19 10:02:00','website',NULL,'PRIVATE','PRIVATE')");
    assistantCheck('same-priority traffic cannot starve continuation',!empty(ManagerAssistantService::listing(1,$first['next_cursor'])['ok']));
    assistantCheck('another actor cannot replay the ordering receipt',(ManagerAssistantService::listing(2,$first['next_cursor'])['error']??'')==='list_changed');

    foreach(['callback','start','bot_started']as$type){
        $pdo=assistantFixture();$pdo->mutate("UPDATE messages SET metadata_json='{\"type\":\"$type\"}' WHERE id=2; DELETE FROM lead_tasks");
        assistantCheck($type.' is not an unanswered human message',ManagerAssistantService::detail(1,1)['item']['operational']['awaiting_manager_reply']===false);
    }
    foreach(['future'=>"UPDATE lead_tasks SET due_at_utc='2999-01-01 00:00:00'",'completed'=>"UPDATE lead_tasks SET status='done'",'foreign'=>"UPDATE lead_tasks SET assigned_manager_id=2"]as$name=>$sql){
        $pdo=assistantFixture();$pdo->mutate($sql);$item=ManagerAssistantService::detail(1,1)['item'];
        assistantCheck($name.' tasks do not become arrived work',$item['operational']['due_task']===false&&($name!=='foreign'||$item['tasks']===[]));
    }
    $pdo=assistantFixture();$pdo->mutate('UPDATE lead_tasks SET assigned_manager_id=NULL');
    assistantCheck('existing unassigned arrived task remains actionable',ManagerAssistantService::detail(1,1)['item']['operational']['due_task']===true);

    assistantCheck('invalid page parameters are rejected',(ManagerAssistantService::listing(1,null,21)['error']??'')==='invalid_request' && (ManagerAssistantService::listing(1,'bad')['error']??'')==='invalid_request');

    $endpoint=file_get_contents(dirname(__DIR__).'/manager/assistant-api.php');
    assistantCheck('assistant endpoint requires canonical auth CSRF and POST with closed actions',str_contains($endpoint,"REQUEST_METHOD") && str_contains($endpoint,'requireManager()') && str_contains($endpoint,'requireCsrf($data)') && str_contains($endpoint,"['detail','decision']") && str_contains($endpoint,'unknown_action') && strpos($endpoint,'initializeReadOnly()')<strpos($endpoint,'requireManager()'));
} catch (Throwable $error) {
    fwrite(STDERR,($error instanceof RuntimeException && str_starts_with($error->getMessage(),'FAIL ')?$error->getMessage():'FAIL synthetic manager assistant fixture')."\n");
    exit(1);
}
