<?php
declare(strict_types=1);

// Execute the real detail, read-marker, media hydration and API detail branch on
// isolated SQLite. Only MySQL's atomic UPSERT syntax is translated for SQLite.
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $constant)if(!defined($constant))define($constant,'synthetic-history-fixture');
require_once dirname(__DIR__).'/services/ManagerConversationService.php';
require_once dirname(__DIR__).'/services/ManagerMessageMediaService.php';
require_once dirname(__DIR__).'/services/ManagerHandoffContextService.php';
require_once dirname(__DIR__).'/services/ManagerHandoffEventContextService.php';

final class HistoryFixtureStatement extends PDOStatement
{
    protected function __construct(private HistoryFixturePdo $owner) {}
    public function execute(?array $params=null): bool
    {
        $this->owner->executed[]=['sql'=>$this->queryString,'args'=>$params??[]];
        if(str_starts_with($this->queryString,'SELECT id,channel,direction,metadata_json')&&$this->owner->failHydration)throw new RuntimeException('synthetic_hydration_failure');
        if(str_starts_with($this->queryString,'INSERT INTO manager_conversation_reads')){
            $this->owner->readWrites++;
            if($this->owner->beforeReadWrite){$callback=$this->owner->beforeReadWrite;$this->owner->beforeReadWrite=null;$callback($this->owner);}
        }
        return parent::execute($params);
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        $rows=parent::fetchAll($mode,...$args);
        if(str_starts_with($this->queryString,'SELECT id,direction,sender_type,text,created_at FROM messages')&&$this->owner->afterPage){$callback=$this->owner->afterPage;$this->owner->afterPage=null;$callback($this->owner);}
        return$rows;
    }
}

final class HistoryFixturePdo extends PDO
{
    public array $executed=[];
    public int $readWrites=0;
    public bool $failHydration=false;
    public $afterPage=null,$beforeReadWrite=null;
    public function __construct()
    {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HistoryFixtureStatement::class,[$this]]);
        $this->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if(str_starts_with($query,'INSERT INTO manager_conversation_reads')){
            $expected='INSERT INTO manager_conversation_reads (manager_id,conversation_id,last_read_message_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id)),updated_at=NOW()';
            if($query!==$expected)throw new RuntimeException('read_marker_lost_atomic_monotonic_upsert');
            $query='INSERT INTO manager_conversation_reads (manager_id,conversation_id,last_read_message_id) VALUES (?,?,?) ON CONFLICT(manager_id,conversation_id) DO UPDATE SET last_read_message_id=MAX(manager_conversation_reads.last_read_message_id,excluded.last_read_message_id),updated_at=CURRENT_TIMESTAMP';
        }
        return parent::prepare($query,$options);
    }
}

final class HistoryApiResponse extends RuntimeException
{
    public function __construct(public array $body,public int $status){parent::__construct('synthetic_api_response');}
}
function out(array $body,int $status=200): void {throw new HistoryApiResponse($body,$status);}
class MaxSearchApi {public static function getAiSearchContext($chatId): array{return[];}}

$apiSource=(string)file_get_contents(dirname(__DIR__).'/manager/api.php');
$start=strpos($apiSource,"if(\$action==='detail'){");$end=strpos($apiSource,"if(\$action==='take'){",$start);
if($start===false||$end===false)throw new RuntimeException('canonical_detail_branch_missing');
$apiDetail=substr($apiSource,$start,$end-$start);
function apiDetail(array $data,int $managerId=1): array
{
    global$apiDetail;
    $action='detail';$m=['id'=>$managerId];
    try{eval($apiDetail);}catch(HistoryApiResponse $response){return['status'=>$response->status,'body'=>$response->body];}
    throw new RuntimeException('detail_branch_did_not_respond');
}

function fixture(int $count,bool $humanReply=true): HistoryFixturePdo
{
    $pdo=new HistoryFixturePdo();(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)(new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
    $pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,payload_json TEXT,created_at TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,created_at TEXT,channel TEXT,metadata_json TEXT);
CREATE INDEX idx_messages_conversation ON messages(conversation_id,id);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(manager_id,conversation_id));
INSERT INTO projects VALUES(1,'anytour','Synthetic project',1),(2,'other','Other project',1);
INSERT INTO managers VALUES(1,'admin','Synthetic admin','admin','',1,1),(2,'other','Outside project','admin','',1,1);
INSERT INTO manager_projects VALUES(1,1),(2,2);
INSERT INTO customers VALUES(10,'Synthetic customer','','');
INSERT INTO conversations VALUES(1,10,'anytour',0,'telegram','','','','manager','new',1,'2026-10-14 00:00:00','2026-10-14 00:00:00',NULL,'synthetic-chat');");
    $insert=$pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?)');
    for($id=1;$id<=$count;$id++)$insert->execute([$id,1,$id===1&&$humanReply?'outbound':'inbound',$id===1&&$humanReply?'manager':'customer',$id===1&&$humanReply?'Ответ &amp; привет':'Сообщение '.$id,'2026-10-14 00:00:00','telegram',null]);
    $pdo->executed=[];$pdo->readWrites=0;return$pdo;
}
function cursor(HistoryFixturePdo $pdo,int $managerId=1): int{return(int)$pdo->query('SELECT COALESCE(MAX(last_read_message_id),0) FROM manager_conversation_reads WHERE manager_id='.$managerId.' AND conversation_id=1')->fetchColumn();}
function ids(array $detail): array{return array_map('intval',array_column($detail['messages'],'id'));}
$passed=0;
function check(string $name,bool $ok): void{global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo'PASS '.$name."\n";}

foreach([500,501,750,1001] as $count){
    $pdo=fixture($count);$detail=ManagerConversationService::detail(1,1);
    check($count.' latest returns ascending last 200',ids($detail)===range($count-199,$count));
    check($count.' page metadata and full-conversation human reply',$detail['history']===['has_more'=>true,'before_id'=>$count-199,'page_size'=>200,'mode'=>'latest']&&$detail['has_manager_reply']===true);
    check($count.' direct latest marks only captured page maximum',cursor($pdo)===$count&&$pdo->readWrites===1);
    $all=ids($detail);$page=$detail;
    while($page['history']['has_more']){$page=ManagerConversationService::detail(1,1,$page['history']['before_id']);$all=array_merge(ids($page),$all);check($count.' older page ascending and globally replied',ids($page)===array_values(array_unique(ids($page)))&&ids($page)===array_values(array_filter(range(1,$count),static fn($id)=>in_array($id,ids($page),true)))&&$page['has_manager_reply']);}
    check($count.' every historical message reachable once',$all===range(1,$count));
    check($count.' older pages never regress or rewrite read cursor',cursor($pdo)===$count&&$pdo->readWrites===1);
    check($count.' stored manager entities decoded on older page',$page['messages'][0]['text']==='Ответ & привет');
}
$pdo=fixture(200);$page=ManagerConversationService::detail(1,1);check('exactly 200 messages has no phantom older page',!$page['history']['has_more']&&ids($page)===range(1,200));
$pdo=fixture(0,false);$page=ManagerConversationService::detail(1,1);check('confirmed empty latest has explicit metadata and no read write',$page['messages']===[]&&$page['history']===['has_more'=>false,'before_id'=>0,'page_size'=>200,'mode'=>'latest']&&!$page['has_manager_reply']&&$pdo->readWrites===0);
$pdo=fixture(501);$pdo->afterPage=static fn($db)=>$db->exec("INSERT INTO messages VALUES(502,1,'inbound','customer','Arrived after captured page','2026-10-14 00:00:01','telegram',NULL)");$page=ManagerConversationService::detail(1,1);check('concurrent arrival after page capture remains unread',end($page['messages'])['id']===501&&cursor($pdo)===501);
$pdo=fixture(501);$pdo->beforeReadWrite=static fn($db)=>$db->exec('INSERT INTO manager_conversation_reads(manager_id,conversation_id,last_read_message_id) VALUES(1,1,900)');ManagerConversationService::detail(1,1);check('concurrent newer cursor cannot be overwritten by older captured page',cursor($pdo)===900);
ManagerReadService::markRead(1,1,100);check('atomic marker remains monotonic for late same-account completion',cursor($pdo)===900);
$pdo=fixture(750);$page=ManagerConversationService::detail(1,1,501);check('first older-only view does not clear unseen newer history',ids($page)===range(301,500)&&cursor($pdo)===0&&$pdo->readWrites===0);
$pdo=fixture(750);$page=ManagerConversationService::detail(1,1,0,200,false);check('API-deferred detail performs no read write before hydration',count($page['messages'])===200&&$pdo->readWrites===0);
$pdo=fixture(501);$denied=ManagerConversationService::detail(1,2);$messageQueries=array_filter($pdo->executed,static fn($query)=>str_contains($query['sql'],'FROM messages'));check('denied service access neither reads messages nor writes read state',$denied===null&&$messageQueries===[]&&$pdo->readWrites===0);
$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1]);check('actual API returns latest page and suppresses false first-reply summary outside tail',$response['status']===200&&$response['body']['has_manager_reply']&&ids($response['body'])===range(302,501)&&cursor($pdo)===501);
$pageQueries=array_filter($pdo->executed,static fn($query)=>str_starts_with($query['sql'],'SELECT id,direction,sender_type,text,created_at FROM messages'));$hydrateQueries=array_filter($pdo->executed,static fn($query)=>str_starts_with($query['sql'],'SELECT id,channel,direction,metadata_json'));check('API fetches one bounded page and one bounded hydration query',count($pageQueries)===1&&count($hydrateQueries)===1&&count(array_values($hydrateQueries)[0]['args'])===200);
$pdo=fixture(501);$pdo->afterPage=static fn($db)=>$db->exec("INSERT INTO messages VALUES(502,1,'inbound','customer','Arrived during HTTP request','2026-10-14 00:00:01','telegram',NULL)");$response=apiDetail(['conversation_id'=>1]);check('actual API cannot mark newly arrived unseen message',ids($response['body'])===range(302,501)&&cursor($pdo)===501);
$pdo=fixture(501);$pdo->failHydration=true;$response=apiDetail(['conversation_id'=>1]);check('hydration failure is explicit and writes no read marker',$response['status']===500&&$response['body']===['ok'=>false,'error'=>'conversation_load_failed']&&cursor($pdo)===0&&$pdo->readWrites===0);
$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1],2);check('denied API returns 404 without read write',$response['status']===404&&$response['body']['error']==='not_found'&&$pdo->readWrites===0);
$pdo=fixture(501,false);$response=apiDetail(['conversation_id'=>1,'before_id'=>302]);check('older API never appends pseudo-summary or marks unseen newer history',$response['status']===200&&ids($response['body'])===range(102,301)&&!$response['body']['has_manager_reply']&&$response['body']['history']['mode']==='older'&&cursor($pdo)===0);
foreach([-1,'-1','not-an-id',1.5,true,'01','999999999999999999999'] as $invalid){$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1,'before_id'=>$invalid]);check('invalid cursor '.json_encode($invalid).' returns explicit 422 before page/read',$response['status']===422&&$response['body']['error']==='invalid_history_cursor'&&$pdo->executed===[]&&$pdo->readWrites===0);}
$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1,'before_id'=>'302']);check('canonical decimal-string cursor accepted',ids($response['body'])===range(102,301));
$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1,'before_id'=>1]);check('older exhaustion is confirmed empty rather than an error',$response['body']['messages']===[]&&!$response['body']['history']['has_more']&&$response['body']['history']['before_id']===0&&$pdo->readWrites===0);
$plan=$pdo->query('EXPLAIN QUERY PLAN SELECT id,direction,sender_type,text,created_at FROM messages WHERE conversation_id=1 AND id<302 ORDER BY id DESC LIMIT 201')->fetchAll();check('history page uses existing conversation/id index',str_contains(json_encode($plan),'idx_messages_conversation'));
echo"TOTAL $passed | FAIL 0\n";
