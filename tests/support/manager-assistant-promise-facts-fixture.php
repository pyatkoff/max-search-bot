<?php
declare(strict_types=1);
if(!in_array(PHP_SAPI,['cli','cli-server'],true)){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/services/ManagerAssistantService.php';
require_once dirname(__DIR__,2).'/services/ManagerPromiseSelectionService.php';
if(PHP_SAPI==='cli')require_once __DIR__.'/manager-promise-selection-fixture.php';

final class AssistantPromiseFixtureStatement extends PDOStatement
{
    protected function __construct(private AssistantPromiseFixturePdo $owner){}
    public function execute(?array $params=null): bool
    {
        $this->owner->observe($this->queryString,$params??[]);
        $ok=parent::execute($params);
        $this->owner->afterRead($this->queryString,$params??[]);return $ok;
    }
}

/** Actual SQLite and canonical owners. Hooks only simulate changes between reads. */
final class AssistantPromiseFixturePdo extends PDO
{
    public bool $guard=false;
    public int $writeAttempts=0;
    public array $reads=[];
    public ?Closure $readHook=null;
    private bool $insideHook=false;

    public function __construct(string $path)
    {
        parent::__construct('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_STRINGIFY_FETCHES=>false]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[AssistantPromiseFixtureStatement::class,[$this]]);
        $this->exec('PRAGMA foreign_keys=ON');
        @$this->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>'2026-11-13 12:00:00');
        @$this->sqliteCreateFunction('NOW',static fn():string=>'2026-11-13 12:00:00');
        @$this->sqliteCreateFunction('JSON_UNQUOTE',static fn(mixed $value):mixed=>$value);
    }
    public function observe(string $sql,array $params=[]): void
    {
        if(preg_match('/\A\s*SELECT\b/i',$sql)){$this->reads[]=['sql'=>$sql,'params'=>$params];return;}
        if(!$this->guard)return;
        ++$this->writeAttempts;throw new RuntimeException('unexpected_promise_fact_write');
    }
    public function afterRead(string $sql,array $params=[]): void
    {
        if(!$this->readHook||$this->insideHook||!preg_match('/\A\s*SELECT\b/i',$sql))return;
        $this->insideHook=true;
        try{($this->readHook)($this,$sql,$params);}finally{$this->insideHook=false;}
    }
    public function exec(string $statement): int|false
    {$this->observe($statement);return parent::exec($statement);}
    // The canonical legacy reassign reader has a trailing MySQL lock clause.
    // SQLite exercises the real SQL mutations but does not model native locks.
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {return parent::prepare(preg_replace('/\s+FOR\s+UPDATE\s*;?\s*\z/i','',$query)??$query,$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->observe($query);$out=$fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
        $this->afterRead($query);return $out;
    }
    public function mutate(callable $write): mixed
    {
        $before=$this->guard;$this->guard=false;
        try{return $write($this);}finally{$this->guard=$before;}
    }
}

function assistantPromiseUse(AssistantPromiseFixturePdo $pdo): void
{
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)
        (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'Synthetic promise workspace']]);
}

/** Reuses the accepted2.4 fixture unchanged, then adds only Assistant read dependencies. */
function assistantPromiseFixture(?string $path=null,string $channel='telegram'): AssistantPromiseFixturePdo
{
    if(PHP_SAPI!=='cli')throw new LogicException('fixture_seed_requires_cli');
    if($path===null){
        $path=sys_get_temp_dir().'/assistant-promise-facts-'.bin2hex(random_bytes(8)).'.sqlite';
        $GLOBALS['assistantPromiseOwnedPaths'][]=$path;
    }
    promiseSelectionFixture($path,$channel);$pdo=new AssistantPromiseFixturePdo($path);assistantPromiseUse($pdo);
    $pdo->exec("ALTER TABLE conversations ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0;
CREATE TABLE manager_prepared_replies(job_id CHAR(64) PRIMARY KEY,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,project_key TEXT NOT NULL,context_revision CHAR(64) NOT NULL,instruction_revision CHAR(64) NOT NULL,latest_message_id INTEGER NOT NULL,status TEXT NOT NULL,proposal_json TEXT,proposal_sha256 CHAR(64),created_at TEXT NOT NULL,prepared_at TEXT,expires_at TEXT,customer_input_revision CHAR(64),purpose TEXT NOT NULL DEFAULT 'background',requested_by_manager_id INTEGER,viewer_access_revision CHAR(64));
CREATE TABLE manager_assistant_decisions(manager_id INTEGER,conversation_id INTEGER,project_key TEXT,recommendation_revision TEXT,context_revision TEXT,instruction_revision TEXT,decision TEXT,created_at TEXT,updated_at TEXT,PRIMARY KEY(manager_id,conversation_id,recommendation_revision));
INSERT INTO projects VALUES(2,'other','Other synthetic project',1);
INSERT INTO managers VALUES(91,'foreign-admin','Other project administrator','admin','',1,1),(92,'inactive','Inactive manager','manager','',0,1);
INSERT INTO manager_projects VALUES(91,2),(92,1);");
    $pdo->reads=[];$pdo->guard=true;return $pdo;
}

function assistantPromiseCleanup(): void
{
    foreach($GLOBALS['assistantPromiseOwnedPaths']??[] as $path){
        foreach([$path,$path.'-journal',$path.'-wal',$path.'-shm'] as $file)if(is_file($file))unlink($file);
    }
    $GLOBALS['assistantPromiseOwnedPaths']=[];
}

function assistantPromiseSnapshot(PDO $pdo): array
{
    $out=[];
    foreach(['conversations','lead_tasks','manager_task_promises','manager_task_promise_operations','manager_request_profiles','messages','manager_conversation_reads','manager_tour_selection_drafts','manager_tour_selection_operations','manager_tour_selection_publications','manager_tour_selection_cards','manager_tour_selection_deliveries','conversation_events','manager_promise_selection_intents','manager_promise_selection_operations','manager_prepared_replies','manager_assistant_decisions'] as $table)
        $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}

function assistantPromiseDetail(int $actor=7,int $cid=1): array
{
    $result=ManagerAssistantService::detail($actor,$cid);
    if(empty($result['ok']))throw new RuntimeException('assistant_detail: '.json_encode($result,JSON_UNESCAPED_UNICODE));return $result['item'];
}

function assistantPromiseTaskRow(array $item,int $id=101): ?array
{
    foreach($item['tasks']??[] as $task)if(($task['id']??null)===$id)return $task;return null;
}

function assistantPromiseAttach(AssistantPromiseFixturePdo $pdo): array
{
    return $pdo->mutate(static function(PDO $db):array{
        $result=promiseSelectionAttach($db);if(empty($result['ok']))throw new RuntimeException('intent_attach: '.json_encode($result));return $result['intent'];
    });
}

function assistantPromiseSend(AssistantPromiseFixturePdo $pdo,int $card,array &$transport,?string $publication=null): array
{
    return $pdo->mutate(static function(PDO $db)use($card,&$transport,$publication):array{return promiseSelectionSend($db,$card,$transport,$publication);});
}

function assistantPromiseProfile(AssistantPromiseFixturePdo $pdo,bool $newTrip): array
{
    return $pdo->mutate(static function()use($newTrip):array{
        $current=ManagerRequestProfileService::read(7,1);if(empty($current['ok']))throw new RuntimeException('profile_read_failed');
        $result=ManagerRequestProfileService::save(7,1,['version'=>$current['profile']['version'],'context_sha256'=>$current['context_sha256'],
            'operation_id'=>bin2hex(random_bytes(16)),'boundary_message_id'=>$current['profile']['boundary_message_id'],
            'new_trip'=>$newTrip,'label'=>$newTrip?'Другая подтверждённая поездка':'Уточнённые условия этой поездки','fields'=>[]]);
        if(empty($result['ok']))throw new RuntimeException('profile_save: '.json_encode($result));return $result;
    });
}

function assistantPromisePublication(AssistantPromiseFixturePdo $pdo,bool $shared): string
{
    return $pdo->mutate(static function()use($shared):string{
        $current=ManagerTourSelectionService::get(7,1)['selection'];$draft=$current['draft'];$draft['title']='Другая публикация для проверки связи';
        if(!$shared)foreach($draft['offers'] as $i=>&$offer){$offer['hotel']='Другой синтетический отель '.($i+1);$offer['amount']=(string)(275000+$i*1000);}unset($offer);
        $saved=deliverySave($draft,$current['version']);if(empty($saved['ok']))throw new RuntimeException('publication_save: '.json_encode($saved));
        $prepared=ManagerTourSelectionDeliveryService::prepare(7,1,$saved['saved_version']);
        if(empty($prepared['ok']))throw new RuntimeException('publication_prepare: '.json_encode($prepared));return $prepared['publication']['id'];
    });
}

/** Real explicit binding with a second synthetic source, for typed promise deadlines. */
function assistantPromiseBind(AssistantPromiseFixturePdo $pdo,?array $deadline,string $kind='send_calculation',int $taskId=104): array
{
    return $pdo->mutate(static function(PDO $db)use($deadline,$kind,$taskId):array{
        if($taskId===104)$db->exec("INSERT INTO lead_tasks(id,conversation_id,title,due_at_utc,status,is_pinned,assigned_manager_id,created_by_manager_id,created_at,updated_at) VALUES(104,1,'Ручное название расчёта','2027-07-15 09:30:00','open',0,7,7,'2026-11-13 10:00:00','2026-11-13 10:00:00')");
        promiseSelectionMessage($db,20,'manager','Пришлю отдельный расчёт по согласованным условиям.');
        $context=ManagerPromiseTaskService::context(7,1,$taskId,20,$kind);if(empty($context['ok']))throw new RuntimeException('new_binding_context: '.json_encode($context));
        $result=ManagerPromiseTaskService::bind(7,1,['operation_id'=>bin2hex(random_bytes(16)),'task_id'=>$taskId,'kind'=>$kind,
            'trip_id'=>$context['profile']['trip_id'],'profile_version'=>$context['profile']['version'],'profile_sha256'=>$context['profile']['sha256'],
            'profile_context_sha256'=>$context['profile']['context_sha256'],'source_message_id'=>20,'source_message_revision'=>$context['source']['revision'],'deadline'=>$deadline]);
        if(empty($result['ok']))throw new RuntimeException('new_binding: '.json_encode($result));return $result;
    });
}

/** Native HTTP-only bootstrap uses the same actual PDO guard, without seeding or transport. */
function assistantPromiseHttpConnect(string $database,?string $raceFile=null,?string $raceReceipt=null): AssistantPromiseFixturePdo
{
    $pdo=new AssistantPromiseFixturePdo($database);assistantPromiseUse($pdo);$pdo->guard=true;
    if($raceFile!==null)$pdo->readHook=static function(AssistantPromiseFixturePdo $db,string $sql)use($raceFile,$raceReceipt):void{
        if(!str_contains($sql,'FROM lead_tasks t LEFT JOIN managers')||!is_file($raceFile))return;
        $mode=trim((string)file_get_contents($raceFile));unlink($raceFile);$db->readHook=null;
        if(!in_array($mode,['logout','actor','csrf'],true))throw new RuntimeException('invalid_fixture_session_race');
        if(session_status()===PHP_SESSION_ACTIVE)throw new RuntimeException('assistant_read_unexpected_session_lock');
        if(!session_start())throw new RuntimeException('fixture_session_reopen_failed');
        $before=(int)($_SESSION['manager_id']??0);
        if($mode==='logout')$_SESSION=[];
        elseif($mode==='actor')$_SESSION['manager_id']=8;
        else $_SESSION['csrf']='rotated-during-assistant-read';
        session_write_close();
        if($raceReceipt!==null)file_put_contents($raceReceipt,json_encode(['mode'=>$mode,'before_actor'=>$before,'changed'=>true],JSON_THROW_ON_ERROR));
    };
    return $pdo;
}
