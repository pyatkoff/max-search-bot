<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/regional_access_schema.php';


// Execute the real detail, read-marker, media hydration and API detail branch on
// isolated SQLite. MySQL's atomic UPSERT and candidate time-window expression
// are translated to their SQLite equivalents; application services are real.
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $constant)if(!defined($constant))define($constant,'synthetic-history-fixture');
require_once dirname(__DIR__).'/services/ManagerConversationService.php';
require_once dirname(__DIR__).'/services/ManagerMessageMediaService.php';
require_once dirname(__DIR__).'/services/ManagerHandoffContextService.php';
require_once dirname(__DIR__).'/services/ManagerHandoffEventContextService.php';
require_once dirname(__DIR__).'/services/ManagerMessageEditService.php';
require_once dirname(__DIR__).'/services/ManagerOutboundService.php';

final class HistoryFixtureStatement extends PDOStatement
{
    protected function __construct(private HistoryFixturePdo $owner) {}
    public function execute(?array $params=null): bool
    {
        $this->owner->executed[]=['sql'=>$this->queryString,'args'=>$params??[]];
        if(str_starts_with($this->queryString,'SELECT EXISTS(SELECT 1 FROM messages')&&$this->owner->beforeReplyCheck){$callback=$this->owner->beforeReplyCheck;$this->owner->beforeReplyCheck=null;$callback($this->owner);}
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
        if((str_starts_with($this->queryString,'SELECT id,direction,sender_type,text,created_at FROM messages')||str_starts_with($this->queryString,'SELECT id,direction,sender_type,text,created_at,metadata_json FROM messages')||str_starts_with($this->queryString,'SELECT id,sender_type,text,created_at FROM messages'))&&$this->owner->afterPage){$callback=$this->owner->afterPage;$this->owner->afterPage=null;$callback($this->owner);}
        return$rows;
    }
}

final class HistoryFixturePdo extends PDO
{
    public array $executed=[];
    public int $readWrites=0;
    public bool $failHydration=false;
    public $afterPage=null,$beforeReadWrite=null,$beforeReplyCheck=null;
    public function __construct()
    {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HistoryFixtureStatement::class,[$this]]);
        $this->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $query=str_replace(' FOR UPDATE','',$query);
        $query=str_replace('UTC_TIMESTAMP()-INTERVAL 5 MINUTE',"datetime('now','-5 minutes')",$query);
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
    $pdo=new HistoryFixturePdo();regionalAccessFixtureSchema($pdo);(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
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

// Child mode pauses a real transcript read with a native files-backed session.
// No application bootstrap/configuration, network or production connection.
if(PHP_SAPI==='cli'&&($argv[1]??'')==='--session-read-probe'){
    $directory=realpath((string)($argv[2]??''));$mode=$argv[3]??'';
    if(!$directory||dirname($directory)!==realpath(sys_get_temp_dir())||!preg_match('/^manager-detail-session-[a-f0-9]{16}$/D',basename($directory))||!in_array($mode,['latest','older','materials'],true))exit(2);
    session_save_path($directory);session_name('manager_detail_fixture');session_id('transcript-fixture');
    if(!session_start())throw new RuntimeException('native_session_start_failed');
    $seed=['manager_id'=>1,'csrf'=>'synthetic-session-token'];$_SESSION=$seed;session_write_close();
    $seedHash=hash_file('sha256',$directory.'/sess_transcript-fixture');
    if(!session_start())throw new RuntimeException('native_session_reopen_failed');
    $pdo=fixture(501);
    $pdo->afterPage=static function()use($directory,$seedHash):void{
        file_put_contents($directory.'/ready.tmp',json_encode(['seed_hash'=>$seedHash],JSON_THROW_ON_ERROR));rename($directory.'/ready.tmp',$directory.'/ready');
        $until=microtime(true)+10;
        while(!is_file($directory.'/continue')){if(microtime(true)>$until)throw new RuntimeException('session_probe_barrier_timeout');usleep(10000);clearstatcache();}
    };
    $response=$mode==='materials'?apiSearch(['conversation_id'=>1],1,'message_materials'):apiDetail(['conversation_id'=>1]+($mode==='older'?['before_id'=>302]:[]));$page=$mode==='materials'?[]:ids($response['body']);
    file_put_contents($directory.'/result',json_encode(['status'=>$response['status'],'ok'=>$response['body']['ok'],'count'=>count($page),'first'=>reset($page),'last'=>end($page),'read_writes'=>$pdo->readWrites,'cursor'=>cursor($pdo),'scanned'=>$response['body']['scanned']??null,'session_closed'=>session_status()===PHP_SESSION_NONE,'identity_preserved'=>$_SESSION===$seed],JSON_THROW_ON_ERROR));
    exit;
}

/** A second PHP process probes the actual native lock while SQL is paused. */
function historySessionProbe(string $mode):array
{
    $directory=sys_get_temp_dir().'/manager-detail-session-'.bin2hex(random_bytes(8));
    if(!mkdir($directory,0700))throw new RuntimeException('session_probe_directory_failed');
    $process=null;$pipes=[];
    try{
        $process=proc_open([PHP_BINARY,'-d','session.use_cookies=0','-d','session.cache_limiter=','-d','session.save_handler=files',__FILE__,'--session-read-probe',$directory,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('session_probe_process_failed');
        fclose($pipes[0]);unset($pipes[0]);$until=microtime(true)+10;
        while(!is_file($directory.'/ready')){if(microtime(true)>$until||!proc_get_status($process)['running'])throw new RuntimeException('session_probe_not_ready');usleep(10000);clearstatcache();}
        $ready=json_decode((string)file_get_contents($directory.'/ready'),true,4,JSON_THROW_ON_ERROR);
        $lock=fopen($directory.'/sess_transcript-fixture','r+');if(!$lock)throw new RuntimeException('session_probe_file_missing');
        $available=flock($lock,LOCK_EX|LOCK_NB);if($available)flock($lock,LOCK_UN);fclose($lock);
        $paused=proc_get_status($process)['running'];$unchanged=hash_file('sha256',$directory.'/sess_transcript-fixture')===$ready['seed_hash'];
        file_put_contents($directory.'/continue','continue');
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
        $exit=proc_close($process);$process=null;
        if($exit!==0||$stderr!==''||$stdout!==''||!is_file($directory.'/result'))throw new RuntimeException('session_probe_child_failed');
        return ['lock_available'=>$available,'sql_still_paused'=>$paused,'session_file_unchanged'=>$unchanged,'response'=>json_decode((string)file_get_contents($directory.'/result'),true,8,JSON_THROW_ON_ERROR)];
    }finally{
        file_put_contents($directory.'/continue','continue');
        if(is_resource($process)){proc_terminate($process);proc_close($process);}
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
        foreach(glob($directory.'/*')?:[] as $file)unlink($file);rmdir($directory);
    }
}

$passed=0;
function check(string $name,bool $ok): void{global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo'PASS '.$name."\n";}

foreach([500,501,750,1001] as $count){
    $pdo=fixture($count);$detail=ManagerConversationService::detail(1,1);
    check($count.' latest returns ascending last 200',ids($detail)===range($count-199,$count));
    check($count.' page metadata and full-conversation human reply',$detail['history']===['has_more'=>true,'before_id'=>$count-199,'page_size'=>200,'mode'=>'latest']&&$detail['has_manager_reply']===true);
    check($count.' direct latest never acknowledges display',cursor($pdo)===0&&$pdo->readWrites===0);
    $all=ids($detail);$page=$detail;
    while($page['history']['has_more']){$page=ManagerConversationService::detail(1,1,$page['history']['before_id']);$all=array_merge(ids($page),$all);check($count.' older page ascending and globally replied',ids($page)===array_values(array_unique(ids($page)))&&ids($page)===array_values(array_filter(range(1,$count),static fn($id)=>in_array($id,ids($page),true)))&&$page['has_manager_reply']);}
    check($count.' every historical message reachable once',$all===range(1,$count));
    check($count.' older pages never regress or rewrite read cursor',cursor($pdo)===0&&$pdo->readWrites===0);
    check($count.' stored manager entities decoded on older page',$page['messages'][0]['text']==='Ответ & привет');
}
$pdo=fixture(200);$page=ManagerConversationService::detail(1,1);check('exactly 200 messages has no phantom older page',!$page['history']['has_more']&&ids($page)===range(1,200));
$pdo=fixture(0,false);$page=ManagerConversationService::detail(1,1);check('confirmed empty latest has explicit metadata and no read write',$page['messages']===[]&&$page['history']===['has_more'=>false,'before_id'=>0,'page_size'=>200,'mode'=>'latest']&&!$page['has_manager_reply']&&$pdo->readWrites===0);
$pdo=fixture(501);$pdo->afterPage=static fn($db)=>$db->exec("INSERT INTO messages VALUES(502,1,'inbound','customer','Arrived after captured page','2026-10-14 00:00:01','telegram',NULL)");$page=ManagerConversationService::detail(1,1);check('concurrent arrival after page capture remains unread',end($page['messages'])['id']===501&&cursor($pdo)===0);
$pdo=fixture(501);$pdo->exec('INSERT INTO manager_conversation_reads(manager_id,conversation_id,last_read_message_id) VALUES(1,1,900)');ManagerConversationService::detail(1,1);check('concurrent newer cursor cannot be overwritten by older captured page',cursor($pdo)===900);
ManagerReadService::markRead(1,1,100);check('atomic marker remains monotonic for late same-account completion',cursor($pdo)===900);
$pdo=fixture(750);$page=ManagerConversationService::detail(1,1,501);check('first older-only view does not clear unseen newer history',ids($page)===range(301,500)&&cursor($pdo)===0&&$pdo->readWrites===0);
$pdo=fixture(750);$page=ManagerConversationService::detail(1,1,0,200,false);check('API-deferred detail performs no read write before hydration',count($page['messages'])===200&&$pdo->readWrites===0);
$pdo=fixture(501);$denied=ManagerConversationService::detail(1,2);$messageQueries=array_filter($pdo->executed,static fn($query)=>str_contains($query['sql'],'FROM messages'));check('denied service access neither reads messages nor writes read state',$denied===null&&$messageQueries===[]&&$pdo->readWrites===0);
$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1]);check('actual API returns latest page and suppresses false first-reply summary outside tail',$response['status']===200&&$response['body']['has_manager_reply']&&ids($response['body'])===range(302,501)&&cursor($pdo)===0);
check('latest replied detail explicitly clears panel-only context',array_key_exists('handoff_context',$response['body'])&&$response['body']['handoff_context']===null);
$pageQueries=array_filter($pdo->executed,static fn($query)=>str_starts_with($query['sql'],'SELECT id,direction,sender_type,text,created_at FROM messages'));$hydrateQueries=array_filter($pdo->executed,static fn($query)=>str_starts_with($query['sql'],'SELECT id,channel,direction,metadata_json'));check('API fetches one bounded page and one bounded hydration query',count($pageQueries)===1&&count($hydrateQueries)===1&&count(array_values($hydrateQueries)[0]['args'])===200);

// The same server receipt must display the same instant despite mixed DB wall clocks.
$pdo=fixture(6);
$recordedUnix=(new DateTimeImmutable('2026-11-04T07:26:00Z'))->getTimestamp();
$clock=['version'=>1,'basis'=>'server_recording_unix','unix'=>$recordedUnix];
$clockCases=[
    1=>['2026-11-04 07:26:00',json_encode(['_recorded_clock'=>$clock,'private_marker'=>'private-clock-marker'],JSON_THROW_ON_ERROR)],
    2=>['2026-11-04 10:26:00',json_encode(['_recorded_clock'=>$clock],JSON_THROW_ON_ERROR)],
    3=>['2026-11-04 10:26:00',json_encode(['_recorded_clock'=>array_replace($clock,['unix'=>(string)$recordedUnix])],JSON_THROW_ON_ERROR)],
    4=>['2026-11-04 10:26:00',json_encode(['_recorded_clock'=>array_replace($clock,['basis'=>'provider_timestamp'])],JSON_THROW_ON_ERROR)],
    5=>['2026-11-04 10:26:00',null],
    6=>['2026-11-04 10:26:00','{malformed'],
];
$update=$pdo->prepare('UPDATE messages SET created_at=?,metadata_json=? WHERE id=?');
foreach($clockCases as $id=>[$wall,$metadata])$update->execute([$wall,$metadata,$id]);
$pdo->exec("UPDATE messages SET direction='outbound',sender_type='ai' WHERE id=2");
$storedClockRows=$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();$pdo->executed=[];
$response=apiDetail(['conversation_id'=>1]);$clockMessages=array_column($response['body']['messages'],null,'id');
check('plain bot message uses its server recording instant with explicit UTC',$response['status']===200&&$clockMessages[2]['created_at']==='2026-11-04T07:26:00Z');
check('equal receipts override different database wall clocks equally',$clockMessages[1]['created_at']===$clockMessages[2]['created_at']);
foreach([3,4,5,6] as $id)check('unknown or invalid recording clock is not guessed #'.$id,$clockMessages[$id]['created_at']===$clockCases[$id][0]);
check('recording projection retains page order and does not acknowledge display',ids($response['body'])===range(1,6)&&cursor($pdo)===0&&$pdo->readWrites===0);
check('recording projection never rewrites stored messages',$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll()===$storedClockRows);
check('recording projection exposes no raw metadata',!str_contains(json_encode($clockMessages,JSON_THROW_ON_ERROR),'private-clock-marker')&&!str_contains(json_encode($clockMessages,JSON_THROW_ON_ERROR),'_recorded_clock'));
$clockHydrationQueries=array_filter($pdo->executed,static fn($query)=>str_starts_with($query['sql'],'SELECT id,channel,direction,metadata_json'));
check('recording projection reuses the one bounded hydration query',count($clockHydrationQueries)===1&&count(array_values($clockHydrationQueries)[0]['args'])===6);

$pdo=fixture(501);$pdo->afterPage=static fn($db)=>$db->exec("INSERT INTO messages VALUES(502,1,'inbound','customer','Arrived during HTTP request','2026-10-14 00:00:01','telegram',NULL)");$response=apiDetail(['conversation_id'=>1]);check('actual API cannot mark newly arrived unseen message',ids($response['body'])===range(302,501)&&cursor($pdo)===0);
$pdo=fixture(501);$pdo->failHydration=true;$response=apiDetail(['conversation_id'=>1]);check('hydration failure is explicit and writes no read marker',$response['status']===500&&$response['body']===['ok'=>false,'error'=>'conversation_load_failed']&&cursor($pdo)===0&&$pdo->readWrites===0);
$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1],2);check('denied API returns 404 without read write',$response['status']===404&&$response['body']['error']==='not_found'&&$pdo->readWrites===0);
$pdo=fixture(501,false);$response=apiDetail(['conversation_id'=>1,'before_id'=>302]);check('older API never appends pseudo-summary or marks unseen newer history',$response['status']===200&&ids($response['body'])===range(102,301)&&!$response['body']['has_manager_reply']&&$response['body']['history']['mode']==='older'&&cursor($pdo)===0);
check('older page cannot overwrite latest handoff context',!array_key_exists('handoff_context',$response['body']));
$pdo=fixture(3,false);$response=apiDetail(['conversation_id'=>1]);
check('latest handoff context is separate from unchanged stored message ids',$response['status']===200&&ids($response['body'])===[1,2,3]&&is_array($response['body']['handoff_context'])&&str_contains($response['body']['handoff_context']['summary'],'Сообщение 3')&&$response['body']['handoff_context']['guidance']===ManagerHandoffContextService::firstReplyGuidance()&&$response['body']['handoff_context']['attachments']===[]&&cursor($pdo)===0&&$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===3);
foreach([-1,'-1','not-an-id',1.5,true,'01','999999999999999999999'] as $invalid){$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1,'before_id'=>$invalid]);check('invalid cursor '.json_encode($invalid).' returns explicit 422 before page/read',$response['status']===422&&$response['body']['error']==='invalid_history_cursor'&&$pdo->executed===[]&&$pdo->readWrites===0);}
$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1,'before_id'=>'302']);check('canonical decimal-string cursor accepted',ids($response['body'])===range(102,301));
$pdo=fixture(501);$response=apiDetail(['conversation_id'=>1,'before_id'=>1]);check('older exhaustion is confirmed empty rather than an error',$response['body']['messages']===[]&&!$response['body']['history']['has_more']&&$response['body']['history']['before_id']===0&&$pdo->readWrites===0);
$plan=$pdo->query('EXPLAIN QUERY PLAN SELECT id,direction,sender_type,text,created_at FROM messages WHERE conversation_id=1 AND id<302 ORDER BY id DESC LIMIT 201')->fetchAll();check('history page uses existing conversation/id index',str_contains(json_encode($plan),'idx_messages_conversation'));

// A previously read reply is editable, while later customer messages have not
// been returned in detail. Candidate lookup, edits and cached-media permission
// checks must preserve that distinction and must not fetch a transcript page.
function authorizationOnlyFixture(): HistoryFixturePdo
{
    $pdo=fixture(501);
    $pdo->exec("ALTER TABLE messages ADD COLUMN sender_id TEXT;
ALTER TABLE messages ADD COLUMN external_message_id TEXT;
ALTER TABLE conversation_events ADD COLUMN actor_id TEXT;
UPDATE messages SET sender_id='1',channel='website',created_at=CURRENT_TIMESTAMP WHERE id=1;
UPDATE conversations SET channel='website' WHERE id=1;
INSERT INTO manager_conversation_reads(manager_id,conversation_id,last_read_message_id) VALUES(1,1,1);");
    $pdo->executed=[];return$pdo;
}
function transcriptPageQueries(HistoryFixturePdo $pdo): array
{
    return array_values(array_filter($pdo->executed,static fn($query)=>str_starts_with($query['sql'],'SELECT id,direction,sender_type,text,created_at FROM messages')));
}
function cachedMediaDenied(int $conversationId,int $managerId): bool
{
    // Execute the endpoint's actual legacy-cache authorization predicate,
    // independently of binary output, headers and its process-terminating exit.
    $source=(string)file_get_contents(dirname(__DIR__).'/manager/media-file.php');
    if(!preg_match('/if\((\$conversationId<=0\|\|[^\n]+)\)\{http_response_code\(403\);exit;\}/',$source,$match))throw new RuntimeException('cached_media_authorization_predicate_missing');
    return eval('return '.$match[1].';');
}
$pdo=authorizationOnlyFixture();$candidates=ManagerMessageEditService::editableForConversation(1,1);
check('real edit-candidate lookup still offers the owned recent reply',count($candidates)===1&&$candidates[0]['message_id']===1);
check('edit-candidate lookup leaves later unseen customer messages unread',cursor($pdo)===1&&$pdo->readWrites===0&&transcriptPageQueries($pdo)===[]);
$pdo=authorizationOnlyFixture();$candidates=ManagerMessageEditService::editableForConversation(1,2);
check('denied edit-candidate lookup returns no message data or read write',$candidates===[]&&$pdo->readWrites===0&&array_filter($pdo->executed,static fn($query)=>str_contains($query['sql'],'FROM messages'))===[]);
$pdo=authorizationOnlyFixture();$edited=ManagerMessageEditService::edit(1,1,'Исправленный ответ');
check('real website edit retains owned edit and audit behavior',!empty($edited['ok'])&&$pdo->query('SELECT text FROM messages WHERE id=1')->fetchColumn()==='Исправленный ответ'&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='manager_message_edited' AND actor_id='1'")->fetchColumn()===1);
check('actual message edit does not mark unrelated unseen messages read',cursor($pdo)===1&&$pdo->readWrites===0&&transcriptPageQueries($pdo)===[]);
$pdo=authorizationOnlyFixture();$edited=ManagerMessageEditService::edit(1,2,'Чужая правка');
check('other account cannot edit message or change either read marker',empty($edited['ok'])&&$edited['error']==='not_owner'&&cursor($pdo)===1&&cursor($pdo,2)===0&&$pdo->readWrites===0&&$pdo->query('SELECT text FROM messages WHERE id=1')->fetchColumn()==='Ответ &amp; привет');
$pdo=authorizationOnlyFixture();$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');$edited=ManagerMessageEditService::edit(1,1,'Недоступная правка');
check('lost project access still blocks an otherwise owned edit',empty($edited['ok'])&&$edited['error']==='conversation_not_found'&&cursor($pdo)===1&&$pdo->readWrites===0&&$pdo->query('SELECT text FROM messages WHERE id=1')->fetchColumn()==='Ответ &amp; привет');
$pdo=authorizationOnlyFixture();$denied=cachedMediaDenied(1,1);
check('actual cached-media predicate grants access without clearing unread',$denied===false&&cursor($pdo)===1&&$pdo->readWrites===0&&transcriptPageQueries($pdo)===[]);
$pdo=authorizationOnlyFixture();$denied=cachedMediaDenied(1,2);
check('cached-media predicate denies another project without reading messages',$denied===true&&cursor($pdo)===1&&cursor($pdo,2)===0&&$pdo->readWrites===0&&array_filter($pdo->executed,static fn($query)=>str_contains($query['sql'],'FROM messages'))===[]);
check('cached-media predicate rejects an invalid conversation',cachedMediaDenied(0,1)===true&&$pdo->readWrites===0);

// Exercise real send guards only on unsupported synthetic channels or rejected
// ownership/access. No adapter can be reached and no provider/customer is used.
$file=tmpfile();if($file===false)throw new RuntimeException('synthetic_media_file_failed');
try{
    $filePath=stream_get_meta_data($file)['uri'];
    foreach(['text','media'] as $kind){
        $send=static fn(int $managerId)=>$kind==='text'
            ? ManagerOutboundService::send(1,$managerId,'Synthetic rejected text')
            : ManagerOutboundService::sendMedia(1,$managerId,$filePath,'synthetic.txt','text/plain');
        $pdo=authorizationOnlyFixture();if($kind==='text')$pdo->exec("UPDATE conversations SET channel='other' WHERE id=1");
        $sent=$send(1);
        check('unsupported '.$kind.' send preserves unseen customer messages',$sent===false&&cursor($pdo)===1&&$pdo->readWrites===0&&transcriptPageQueries($pdo)===[]);
        if($kind==='media')check('unsupported media send retains explicit channel failure',ManagerOutboundService::lastFailure()['category']==='unsupported');
        $pdo=authorizationOnlyFixture();$sent=$send(2);
        check('denied '.$kind.' send preserves read markers and accesses no transcript',$sent===false&&cursor($pdo)===1&&cursor($pdo,2)===0&&$pdo->readWrites===0&&transcriptPageQueries($pdo)===[]);
        $pdo=authorizationOnlyFixture();$pdo->exec('UPDATE conversations SET manager_id=2 WHERE id=1');$sent=$send(1);
        check('nonowner '.$kind.' send is rejected without clearing unread',$sent===false&&cursor($pdo)===1&&$pdo->readWrites===0&&transcriptPageQueries($pdo)===[]);
        $pdo=authorizationOnlyFixture();$pdo->exec("UPDATE conversations SET status='closed' WHERE id=1");$sent=$send(1);
        check('closed-conversation '.$kind.' send is rejected without clearing unread',$sent===false&&cursor($pdo)===1&&$pdo->readWrites===0&&transcriptPageQueries($pdo)===[]);
    }
}finally{fclose($file);}
echo"TOTAL $passed | FAIL 0\n";

// Execute the canonical explicit acknowledgement branch with fresh real access.
function apiAck(array $data,int $managerId=1): array
{
    global $apiDetail;$action='ack_read';$m=['id'=>$managerId];
    try{eval($apiDetail);}catch(HistoryApiResponse $response){return ['status'=>$response->status,'body'=>$response->body];}
    throw new RuntimeException('ack_branch_did_not_respond');
}
function shown(array $state,array $ids,int $managerId=1): array{return apiAck(['conversation_id'=>1,'message_ids'=>$ids,'read_context'=>$state['context']],$managerId);}
$pdo=fixture(6);$state=apiDetail(['conversation_id'=>1])['body']['read_state'];
check('returned transcript exposes first unread without changing marker',$state['first_unread_id']===2&&$state['unread_count']===5&&cursor($pdo)===0);
$r=shown($state,[2,3]);check('display acknowledgement advances only shown customer prefix',$r['status']===200&&cursor($pdo)===3&&$r['body']['read_state']['unread_count']===3);
$r=shown($state,[5,6]);check('offscreen unread row cannot be skipped by later visible rows',cursor($pdo)===3&&$r['body']['read_state']['first_unread_id']===4);
$r=shown($state,[4,6]);check('partial acknowledgement stops at first unseen conversation row',cursor($pdo)===4);
shown($state,[2,3]);check('late duplicate read acknowledgement is monotonic',cursor($pdo)===4);
shown($state,[5,6]);check('shown remaining prefix clears only its actual unread rows',cursor($pdo)===6);
$pdo->exec("INSERT INTO messages VALUES(900,1,'inbound','customer','Global ID gap','2026-10-14 00:00:01','telegram',NULL)");
shown($state,[900]);check('global ID gaps do not create phantom unread gaps',cursor($pdo)===900);
// The unread prefix contains human messages, not recorded button/start actions.
$pdo=fixture(8);
foreach([2=>'callback',3=>'start',4=>'bot_started'] as $id=>$type){$q=$pdo->prepare('UPDATE messages SET metadata_json=? WHERE id=?');$q->execute([json_encode(['type'=>$type]),$id]);}
$pdo->exec("UPDATE messages SET text='show_tours',metadata_json='{\"type\":\"message\"}' WHERE id=7;UPDATE messages SET metadata_json='{broken' WHERE id=8");
$detail=apiDetail(['conversation_id'=>1])['body'];$state=$detail['read_state'];
check('buttons and starts remain in history but do not inflate unread',count($detail['messages'])===8&&$state['unread_count']===4&&$state['first_unread_id']===5);
$r=shown($state,[6]);check('excluded actions do not allow skipping an unseen human message',cursor($pdo)===0&&$r['body']['read_state']['unread_count']===4);
$r=shown($state,[5,6]);check('excluded action rows never block the actually shown human prefix',cursor($pdo)===6&&$r['body']['read_state']['unread_count']===2);
$r=shown($state,[7,8]);check('typed callback names and malformed metadata remain real unread messages',cursor($pdo)===8&&$r['body']['read_state']['unread_count']===0);
$pdo->exec("INSERT INTO messages VALUES(9,1,'inbound','customer','New action','2026-10-14 00:00:01','telegram','{\"type\":\"callback\"}')");
check('a newly recorded action does not revive the personal unread badge',apiDetail(['conversation_id'=>1])['body']['read_state']['unread_count']===0);
$pdo->exec("INSERT INTO messages VALUES(10,1,'inbound','customer','','2026-10-14 00:00:01','telegram','{\"attachments\":[{\"type\":\"photo\"}]}')");
$r=shown($state,[9]);check('an action-only acknowledgement cannot clear a newer unseen attachment',cursor($pdo)===8&&$r['body']['read_state']['unread_count']===1&&$r['body']['read_state']['first_unread_id']===10);
$r=shown($state,[10]);check('a visible customer attachment advances across excluded actions',cursor($pdo)===10&&$r['body']['read_state']['unread_count']===0);
$pdo=fixture(501);$state=apiDetail(['conversation_id'=>1])['body']['read_state'];shown($state,range(302,501));
check('bounded latest tail cannot jump an unknown unread prefix',cursor($pdo)===0);
foreach([range(2,201),range(202,401),range(402,501)] as $ids)shown($state,$ids);
check('sequential shown pages restore a complete unread prefix',cursor($pdo)===501);
$pdo=fixture(5);$state=apiDetail(['conversation_id'=>1])['body']['read_state'];$pdo->exec('UPDATE conversations SET manager_id=2 WHERE id=1');$r=shown($state,[2,3,4,5]);
check('assignment change rejects captured read context',$r['status']===409&&cursor($pdo)===0&&$pdo->readWrites===0);
$foreign=apiDetail(['conversation_id'=>1]);check('owner foreign read exposes no personal read acknowledgement',!$foreign['body']['read_state']['acknowledge']);
$pdo=fixture(5);$state=apiDetail(['conversation_id'=>1])['body']['read_state'];$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');$r=shown($state,[2,3,4,5]);
check('fresh project revocation refuses read write',$r['status']===409&&cursor($pdo)===0);
$pdo=fixture(5);$state=apiDetail(['conversation_id'=>1])['body']['read_state'];$pdo->exec('UPDATE managers SET is_active=0 WHERE id=1');$r=shown($state,[2]);
check('inactive account refuses read write',$r['status']===409&&cursor($pdo)===0);
foreach([[],[0],['2'],[true],range(1,201)] as $ids){$pdo=fixture(5);$state=apiDetail(['conversation_id'=>1])['body']['read_state'];$r=shown($state,$ids);check('invalid read batch '.json_encode(array_slice($ids,0,3)).' writes nothing',$r['status']===422&&$pdo->readWrites===0);}
$pdo=fixture(5);$state=apiDetail(['conversation_id'=>1])['body']['read_state'];$pdo->exec("INSERT INTO messages VALUES(6,1,'inbound','customer','Arrived after display','2026-10-14 00:00:01','telegram',NULL)");$r=shown($state,[2,3,4,5]);
check('arrival after display stays unread in authoritative count',cursor($pdo)===5&&$r['body']['read_state']['unread_count']===1);

// Explicit initial-unread reads must seek an old prefix without draining history.
$pdo=fixture(10001);$opened=apiDetail(['conversation_id'=>1,'open_unread'=>true]);
check('initial open includes first unread beyond latest 200 with preceding context',in_array(1,ids($opened['body']),true)&&in_array(2,ids($opened['body']),true)&&($opened['body']['history']['initial_unread_id']??0)===2);
check('initial unread includes current tail within one bounded response',in_array(10001,ids($opened['body']),true)&&count(ids($opened['body']))<=200&&cursor($pdo)===0&&$pdo->readWrites===0);

$pdo=fixture(10001);$pdo->exec('INSERT INTO manager_conversation_reads VALUES(1,1,200,CURRENT_TIMESTAMP)');$opened=apiDetail(['conversation_id'=>1,'open_unread'=>true])['body'];
check('old unread seek includes twenty actual preceding rows and recent tail',array_slice(ids($opened),0,21)===range(181,201)&&end($opened['messages'])['id']==10001&&count(ids($opened))===200&&$opened['history']['initial_unread_id']===201);
check('initial interval supplies explicit actual frontier and tail cursor',$opened['history']['gap_frontier']===300&&$opened['history']['gap_before_id']===9922&&$opened['history']['has_more']);
check('old unread opening uses three bounded transcript queries not sequential pages',count(transcriptPageQueries($pdo))===3&&$pdo->readWrites===0&&cursor($pdo)===200);
$pdo=fixture(10001);$pdo->exec('INSERT INTO manager_conversation_reads VALUES(1,1,9801,CURRENT_TIMESTAMP)');$opened=apiDetail(['conversation_id'=>1,'open_unread'=>true])['body'];
check('first unread at previous latest boundary receives preceding context',array_slice(ids($opened),0,21)===range(9782,9802)&&$opened['history']['initial_unread_id']===9802);
$pdo=fixture(600);$pdo->exec('UPDATE messages SET id=-id');$pdo->exec('UPDATE messages SET id=-id*10');$pdo->exec('INSERT INTO manager_conversation_reads VALUES(1,1,2000,CURRENT_TIMESTAMP)');$opened=apiDetail(['conversation_id'=>1,'open_unread'=>true])['body'];
check('unread context is selected by real rows across sparse global IDs',array_slice(ids($opened),0,21)===range(1810,2010,10)&&$opened['history']['initial_unread_id']===2010&&$opened['history']['gap_frontier']===3000);
$pdo=fixture(500);$pdo->exec('INSERT INTO manager_conversation_reads VALUES(1,1,450,CURRENT_TIMESTAMP)');$opened=apiDetail(['conversation_id'=>1,'open_unread'=>true])['body'];
check('unread already inside latest page adds no context query or gap',ids($opened)===range(301,500)&&$opened['history']['initial_unread_id']===451&&!isset($opened['history']['gap_frontier'])&&count(transcriptPageQueries($pdo))===1);
$pdo=fixture(500);$pdo->exec('INSERT INTO manager_conversation_reads VALUES(1,1,500,CURRENT_TIMESTAMP)');$opened=apiDetail(['conversation_id'=>1,'open_unread'=>true])['body'];
check('fully read conversation keeps ordinary latest opening',ids($opened)===range(301,500)&&!isset($opened['history']['initial_unread_id'])&&$pdo->readWrites===0);
$pdo=fixture(500);$pdo->exec('UPDATE conversations SET manager_id=2 WHERE id=1');$opened=apiDetail(['conversation_id'=>1,'open_unread'=>true])['body'];
check('foreign owner read does not seek or change personal unread',ids($opened)===range(301,500)&&!isset($opened['history']['initial_unread_id'])&&!$opened['read_state']['acknowledge']&&$pdo->readWrites===0);
$pdo=fixture(500);$pdo->exec('DELETE FROM manager_projects WHERE manager_id=1');$opened=apiDetail(['conversation_id'=>1,'open_unread'=>true]);
check('initial unread seek retains fresh project access fence',$opened['status']===404&&transcriptPageQueries($pdo)===[]&&$pdo->readWrites===0);
foreach([1,'true',null,[],0] as $flag){$pdo=fixture(5);$r=apiDetail(['conversation_id'=>1,'open_unread'=>$flag]);check('invalid initial unread flag '.json_encode($flag),$r['status']===422&&$pdo->executed===[]);}
$pdo=fixture(500);$r=apiDetail(['conversation_id'=>1,'open_unread'=>true,'before_id'=>400]);check('older page cannot double as an unread opening',$r['status']===422&&$pdo->executed===[]);
$pdo=fixture(500);$pdo->exec("UPDATE conversations SET status='closed' WHERE id=1");$pdo->beforeReplyCheck=static function(HistoryFixturePdo $db):void{$db->exec("UPDATE managers SET role='manager' WHERE id=1");};$r=apiDetail(['conversation_id'=>1]);
check('owner presentation rechecks current role after transcript and metadata reads',empty($r['body']['owner_read'])&&$pdo->readWrites===0);
// Exact quoted originals remain a lazy read-only view, not a full-history drain.
function targetFixture(int $count=1001): HistoryFixturePdo
{
    $pdo=fixture($count);$pdo->exec('ALTER TABLE messages ADD COLUMN external_message_id TEXT COLLATE NOCASE');
    $pdo->prepare('UPDATE messages SET external_message_id=? WHERE id=?')->execute(['mid.synthetic-original',50]);
    $pdo->prepare('UPDATE messages SET metadata_json=? WHERE id=?')->execute([json_encode(['linked_message'=>['type'=>'reply','mid'=>'mid.synthetic-original']]),$count]);
    $pdo->executed=[];return$pdo;
}
function apiTarget(array $data,int $managerId=1): array
{
    global$apiDetail;$action='message_target';$m=['id'=>$managerId];
    try{eval($apiDetail);}catch(HistoryApiResponse $response){return['status'=>$response->status,'body'=>$response->body];}
    throw new RuntimeException('message_target_branch_did_not_respond');
}
$pdo=targetFixture();$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);
check('exact quote resolves old original with bounded ascending context',$r['status']===200&&$r['body']['available']&&$r['body']['target_message_id']===50&&ids($r['body'])===range(30,70)&&$r['body']['context']===['has_before'=>true,'has_after'=>true]);
check('quote lookup and bounded hydration never acknowledge display',$pdo->readWrites===0&&cursor($pdo)===0&&!isset($r['body']['read_state'])&&count(transcriptPageQueries($pdo))===2);
$queries=array_filter($pdo->executed,static fn($q)=>str_starts_with($q['sql'],'SELECT id,channel,direction,metadata_json'));check('target hydrates only the bounded context',count($queries)===1&&count(array_values($queries)[0]['args'])===41);
$pdo=targetFixture();$pdo->exec("UPDATE messages SET sender_type='manager',text='Оригинал &amp; детали' WHERE id=50");$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('context uses canonical stored manager-text decoding',$r['body']['messages'][20]['text']==='Оригинал & детали');
$pdo=targetFixture();$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001],2);check('denied quote lookup reads no messages',$r['status']===404&&array_filter($pdo->executed,static fn($q)=>str_contains($q['sql'],'FROM messages'))===[]&&$pdo->readWrites===0);
$pdo=targetFixture();$pdo->exec("UPDATE messages SET conversation_id=2 WHERE id=50");$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('quote original cannot cross conversation boundary',$r['status']===200&&!$r['body']['available']&&!isset($r['body']['messages']));
$pdo=targetFixture();$pdo->exec("UPDATE messages SET channel='max' WHERE id=50");$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('provider ids cannot cross channel boundary',!$r['body']['available']);
$pdo=targetFixture();$pdo->exec("UPDATE messages SET external_message_id='MID.SYNTHETIC-ORIGINAL' WHERE id=50");$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('case-insensitive database collation cannot select another provider id',!$r['body']['available']);
$pdo=targetFixture();$pdo->exec("UPDATE messages SET external_message_id='mid.synthetic-original' WHERE id=51");$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('ambiguous legacy provider identity is unavailable',!$r['body']['available']&&!isset($r['body']['target_message_id']));
$pdo=targetFixture();$pdo->exec("UPDATE messages SET metadata_json='invalid' WHERE id=1001");$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('missing or malformed stored quote is explicit unavailable',!$r['body']['available']);
$pdo=targetFixture();$pdo->afterPage=static fn($db)=>$db->exec('DELETE FROM manager_projects WHERE manager_id=1');$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('permission loss during context load rejects captured original',$r['status']===404&&$pdo->readWrites===0);
$pdo=targetFixture();$pdo->failHydration=true;$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('quote hydration failure is recoverable without a read write',$r['status']===500&&$r['body']['error']==='message_target_failed'&&$pdo->readWrites===0);
foreach([-1,0,'01','bad',true,1.2,'999999999999999999999'] as $invalid){$pdo=targetFixture();$r=apiTarget(['conversation_id'=>1,'source_message_id'=>$invalid]);check('invalid quote source rejected before lookup '.json_encode($invalid),$r['status']===422&&$pdo->executed===[]);}
// Item18 compares captured text with that same bounded saved original. It
// never upgrades a difference/missing row into a provider edit/delete event.
function quoteFixture(array $link,string $original='Сохранённые условия'): HistoryFixturePdo
{
    $pdo=targetFixture();$pdo->prepare('UPDATE messages SET text=? WHERE id=50')->execute([$original]);
    $pdo->prepare('UPDATE messages SET metadata_json=? WHERE id=1001')->execute([json_encode(['linked_message'=>array_replace(['type'=>'reply','mid'=>'mid.synthetic-original'],$link)],JSON_THROW_ON_ERROR)]);
    $pdo->executed=[];return$pdo;
}
foreach(['reply','forward'] as $type){
    $pdo=quoteFixture(['type'=>$type,'text'=>'Сохранённые условия','sender_name'=>'<img src=x>']);$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);
    check('captured '.$type.' matches only the saved text',$r['body']['quote_comparison']===['state'=>'text_matches','snapshot'=>['type'=>$type,'text'=>'Сохранённые условия','sender_name'=>'<img src=x>','may_be_truncated'=>false]]);
    check('comparison remains bounded and read-only '.$type,count($r['body']['messages'])===41&&$pdo->readWrites===0&&cursor($pdo)===0&&count(transcriptPageQueries($pdo))===2);
}
$pdo=quoteFixture(['text'=>'Прежние даты','mid'=>'mid.synthetic-original','secret'=>'private-quote-token'],'Новые даты');$before=$pdo->query('SELECT id,text,metadata_json FROM messages ORDER BY id')->fetchAll();$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);
check('different quoted text and original are both retained',$r['body']['quote_comparison']['state']==='text_differs'&&$r['body']['quote_comparison']['snapshot']['text']==='Прежние даты'&&$r['body']['messages'][20]['text']==='Новые даты');
check('comparison changes no stored quotation message or unread state',$pdo->query('SELECT id,text,metadata_json FROM messages ORDER BY id')->fetchAll()===$before&&$pdo->readWrites===0);
check('comparison exports no raw metadata or provider identity',!str_contains(json_encode($r['body']['quote_comparison']),'private-quote-token')&&!str_contains(json_encode($r['body']['quote_comparison']),'mid.synthetic-original'));
$pdo=quoteFixture(['text'=>'Оригинал & детали'],'Оригинал &amp; детали');$pdo->exec("UPDATE messages SET sender_type='manager' WHERE id=50");$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);
check('comparison uses the canonical decoded manager original',$r['body']['quote_comparison']['state']==='text_matches');
foreach([499,500,501] as $length){
    $quote=str_repeat('🌊',$length);$pdo=quoteFixture(['text'=>$quote,'sender_name'=>str_repeat('Я',200)],$quote.'дальше');$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);$comparison=$r['body']['quote_comparison'];
    check('Unicode quotation cap is honest at '.$length,$comparison['state']===($length>=500?'excerpt_matches':'text_differs')&&mb_strlen($comparison['snapshot']['text'])===min($length,500)&&mb_strlen($comparison['snapshot']['sender_name'])===160&&$comparison['snapshot']['may_be_truncated']===($length>=500));
}
$pdo=quoteFixture(['text'=>'Прежний текст']);$pdo->exec('DELETE FROM messages WHERE id=50');$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);
check('missing original preserves captured quote without claiming deletion',!$r['body']['available']&&$r['body']['quote_comparison']['state']==='unavailable'&&$r['body']['quote_comparison']['snapshot']['text']==='Прежний текст'&&!isset($r['body']['messages']));
$pdo=quoteFixture(['text'=>'Короткая цитата']);$pdo->exec("UPDATE messages SET external_message_id='mid.synthetic-original' WHERE id=51");$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);
check('ambiguous provider original remains unavailable with a historical quotation',!$r['body']['available']&&$r['body']['quote_comparison']['state']==='unavailable');
foreach([[],['text'=>''],['text'=>['private-invalid']]] as $link){$pdo=quoteFixture($link);$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);check('missing textual quote cannot certify equal or edited text '.json_encode($link),$r['body']['quote_comparison']['state']==='not_comparable'&&$r['body']['quote_comparison']['snapshot']['text']==='');}
$pdo=quoteFixture(['text'=>'Историческая цитата']);$pdo->afterPage=static fn($db)=>$db->exec('DELETE FROM manager_projects WHERE manager_id=1');$r=apiTarget(['conversation_id'=>1,'source_message_id'=>1001]);
check('comparison cannot escape post-read access revocation',$r['status']===404&&!isset($r['body']['quote_comparison'])&&$pdo->readWrites===0);
function apiSearch(array $data,int $managerId=1,string $action='message_search'): array
{
    global $apiDetail;$m=['id'=>$managerId];
    try{eval($apiDetail);}catch(HistoryApiResponse $response){return ['status'=>$response->status,'body'=>$response->body];}
    throw new RuntimeException('search_branch_did_not_respond');
}
$pdo=fixture(2050);$pdo->exec("UPDATE messages SET text='Старые условия у моря <img src=x> 100%_\\, & детали' WHERE id=50");
$r=apiSearch(['conversation_id'=>1,'query'=>'У МОРЯ']);
check('full search inspects a bounded indexed window without returning the transcript',$r['status']===200&&$r['body']['matches']===[]&&$r['body']['has_more']&&$r['body']['scanned']===1000&&$r['body']['before_id']===1051&&$r['body']['snapshot_id']===2050);
$r=apiSearch(['conversation_id'=>1,'query'=>'У МОРЯ','before_id'=>1051,'snapshot_id'=>2050]);
check('empty intermediate search retains its actual continuation',$r['body']['matches']===[]&&$r['body']['has_more']&&$r['body']['before_id']===51);
$r=apiSearch(['conversation_id'=>1,'query'=>'У МОРЯ','before_id'=>51,'snapshot_id'=>2050]);
check('continuation finds an old Unicode case-insensitive match beyond latest 200',array_column($r['body']['matches'],'message_id')===[50]);
check('only exhausted continuation declares search complete',!$r['body']['has_more']&&$r['body']['scanned']===50);
check('all search reads preserve the unread marker and do not hydrate media',$pdo->readWrites===0&&cursor($pdo)===0&&array_filter($pdo->executed,static fn($q)=>str_starts_with($q['sql'],'SELECT id,channel,direction,metadata_json'))===[]);
$r=apiSearch(['conversation_id'=>1,'query'=>'100%_\\','before_id'=>100,'snapshot_id'=>2050]);
check('search wildcards backslashes and HTML remain literal text',array_column($r['body']['matches'],'message_id')===[50]&&str_contains($r['body']['matches'][0]['snippet'],'<img src=x>'));
$pdo=fixture(4);$pdo->prepare('UPDATE messages SET text=? WHERE id=1')->execute(['До &amp; море &#x1F30A; &quot;цитата&quot; после']);$r=apiSearch(['conversation_id'=>1,'query'=>'& море 🌊 "цитата"']);
check('full search uses displayed manager text including numeric historic entities',array_column($r['body']['matches'],'message_id')===[1]&&str_contains($r['body']['matches'][0]['snippet'],'& море 🌊 "цитата"'));
$pdo=fixture(61);$r=apiSearch(['conversation_id'=>1,'query'=>'сообщение']);$first=$r['body'];
check('dense matches return at most 25 short projections',array_column($first['matches'],'message_id')===range(61,37)&&$first['scanned']===25&&$first['before_id']===37&&$first['has_more']&&!isset($first['messages'],$first['read_state']));
$pdo->exec("INSERT INTO messages VALUES(99,1,'inbound','customer','Новое сообщение','2026-11-11 00:00:00','telegram',NULL)");
$r=apiSearch(['conversation_id'=>1,'query'=>'сообщение','snapshot_id'=>$first['snapshot_id'],'before_id'=>$first['before_id']]);
check('keyset continuation neither duplicates hits nor includes later arrivals',array_column($r['body']['matches'],'message_id')===range(36,12)&&$r['body']['snapshot_id']===61);
$pdo=fixture(4);$pdo->exec("UPDATE messages SET conversation_id=2 WHERE id=2");$r=apiSearch(['conversation_id'=>1,'query'=>'Сообщение 2']);
check('full search never returns another conversations matching text',$r['status']===200&&$r['body']['matches']===[]&&!$r['body']['has_more']);
$pdo=fixture(4);$r=apiSearch(['conversation_id'=>1,'query'=>'сообщение'],2);
check('denied full search reads no messages',$r['status']===404&&array_filter($pdo->executed,static fn($q)=>str_contains($q['sql'],'FROM messages'))===[]);
$pdo=fixture(4);$pdo->afterPage=static fn($db)=>$db->exec('DELETE FROM manager_projects WHERE manager_id=1');$r=apiSearch(['conversation_id'=>1,'query'=>'сообщение']);
check('permission loss during search discards all captured snippets',$r['status']===404&&$pdo->readWrites===0);
$pdo=fixture(4);$pdo->afterPage=static fn($db)=>$db->exec('UPDATE conversations SET manager_id=2 WHERE id=1');$r=apiSearch(['conversation_id'=>1,'query'=>'сообщение']);
check('assignment change during search refuses obsolete context even for admin',$r['status']===404);
$pdo=fixture(4);$pdo->afterPage=static fn($db)=>$db->exec('UPDATE managers SET is_active=0 WHERE id=1');$r=apiSearch(['conversation_id'=>1,'query'=>'сообщение']);
check('account deactivation during search exposes no captured snippets',$r['status']===404&&!isset($r['body']['matches']));
foreach([['query'=>''],['query'=>'   '],['query'=>str_repeat('я',201)],['query'=>true],['query'=>"\xFF"],['query'=>'текст','before_id'=>-1],['query'=>'текст','snapshot_id'=>'01'],['query'=>'текст','before_id'=>10],['query'=>'текст','before_id'=>11,'snapshot_id'=>10],['conversation_id'=>true,'query'=>'текст']] as $invalid){$pdo=fixture(4);$r=apiSearch($invalid+['conversation_id'=>1]);check('invalid search request rejected before SQL '.json_encode($invalid,JSON_INVALID_UTF8_SUBSTITUTE),$r['status']===422&&$pdo->executed===[]);}
$pdo=fixture(1001);$r=apiSearch(['conversation_id'=>1,'target_message_id'=>50],1,'message_context');
check('search target shares the exact bounded quote context renderer projection',$r['status']===200&&$r['body']['target_message_id']===50&&ids($r['body'])===range(30,70)&&$pdo->readWrites===0&&cursor($pdo)===0);
$pdo=fixture(4);$r=apiSearch(['conversation_id'=>1,'target_message_id'=>99],1,'message_context');
check('deleted or foreign search target does not expose adjacent messages',!$r['body']['available']&&!isset($r['body']['messages']));
$pdo=fixture(4);$pdo->failHydration=true;$r=apiSearch(['conversation_id'=>1,'target_message_id'=>2],1,'message_context');
check('search context hydration failure remains recoverable and read-only',$r['status']===500&&$r['body']['error']==='message_search_failed'&&$pdo->readWrites===0);
require __DIR__.'/manager_conversation_materials_checks.php';
if(PHP_SAPI==='cli')foreach(['latest','older','materials'] as $mode){
    $probe=historySessionProbe($mode);$response=$probe['response'];
    echo 'SESSION_PROBE '.json_encode(['mode'=>$mode,'lock_available_during_sql'=>$probe['lock_available'],'original_request_still_paused'=>$probe['sql_still_paused']],JSON_THROW_ON_ERROR)."\n";
    check($mode.' transcript releases the native session while its read is paused',$probe['lock_available']&&$probe['sql_still_paused']);
    check($mode.' read preserves session identity CSRF and stored bytes',$probe['session_file_unchanged']&&$response['identity_preserved']&&$response['session_closed']);
    check($mode.' concurrent read preserves exact page and performs no acknowledgement',$response['status']===200&&$response['ok']&&($mode==='materials'?($response['count']===0&&$response['scanned']===250):($response['count']===200&&$response['first']===($mode==='older'?102:302)&&$response['last']===($mode==='older'?301:501)))&&$response['read_writes']===0&&$response['cursor']===0);
}
echo "FINAL TOTAL $passed | FAIL 0\n";
