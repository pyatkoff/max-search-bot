<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerInternalNoteService.php';
final class InternalNoteFixturePdo extends PDO
{
    public int $queries=0;
    public function __construct(string $dsn='sqlite::memory:') {parent::__construct($dsn,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
    public function prepare(string $query,array $options=[]): PDOStatement|false {$this->queries++;return parent::prepare($query,$options);}
}
$pdo=new InternalNoteFixturePdo();
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$pdo->exec("CREATE TABLE manager_internal_notes(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,author_manager_id INTEGER,body TEXT,version INTEGER,created_at TEXT,updated_at TEXT,deleted_at TEXT);
CREATE TABLE manager_internal_note_operations(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,request_hash TEXT,result_json TEXT,created_at TEXT,PRIMARY KEY(manager_id,operation_id));
CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,external_chat_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,text TEXT);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT);
INSERT INTO projects VALUES(1,'notes-fixture','Synthetic workspace',1),(2,'other','Synthetic other',1);
INSERT INTO manager_projects VALUES(7,1),(8,1),(90,1),(9,2);
INSERT INTO managers VALUES(7,'author','Synthetic author','manager','',1,1),(8,'colleague','Synthetic colleague','manager','',1,1),(90,'admin','Synthetic admin','admin','',1,1),(9,'foreign','Synthetic foreign','manager','',1,1),(10,'inactive','Inactive','manager','',0,1);
INSERT INTO customers VALUES(1,'Synthetic client','123','');
INSERT INTO conversation_sources VALUES(1,'Allowed',0,'none',0,0,1),(2,'Restricted',101,'none',0,0,1);
INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id,started_at) VALUES(1,1,'notes-fixture',1,'max','manager',8,'2026-10-14 00:00:00'),(2,1,'notes-fixture',1,'max','waiting_manager',NULL,'2026-10-14 00:00:00'),(3,1,'other',1,'max','manager',9,'2026-10-14 00:00:00'),(4,1,'notes-fixture',2,'max','manager',7,'2026-10-14 00:00:00');
INSERT INTO messages VALUES(11,1,'Original customer transcript');
INSERT INTO manager_conversation_reads VALUES(7,1,10);
INSERT INTO conversation_events VALUES(1,1,'original_event');");
$passed=0;
function inCheck(string $label,bool $ok): void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
function inMut(string $action,array $input=[],int $actor=7,int $conversation=1): array {static $seq=0;$input+=['operation_id'=>'synthetic-note-command-'.str_pad((string)++$seq,6,'0',STR_PAD_LEFT)];return ManagerInternalNoteService::mutate($actor,$conversation,$action,$input);}
function inBusiness(): array {global $pdo;$result=[];foreach (['managers','conversations','messages','manager_conversation_reads','conversation_events'] as $table) $result[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return $result;}
$before=inBusiness();
inCheck('empty staff-only list is protected and read-only',ManagerInternalNoteService::list(7,1)['notes']===[]&&inBusiness()===$before);
inCheck('fresh actor and conversation ACL protect reads',ManagerInternalNoteService::list(0,1)['error']==='unauthorized'&&ManagerInternalNoteService::list(10,1)['error']==='unauthorized'&&ManagerInternalNoteService::list(7,3)['error']==='forbidden'&&ManagerInternalNoteService::list(7,4)['error']==='forbidden');
$stable=['body'=>" Staff only\r\n<b>literal</b> ",'operation_id'=>'stable-internal-note-create-001'];$created=inMut('create',$stable);$id=$created['id'];
inCheck('visible colleague can create without assignment and text remains literal',$created['ok']&&$created['saved_version']===1&&$created['note']['body']==="Staff only\n<b>literal</b>"&&$created['note']['author_id']===7&&$created['note']['can_edit']&&$created['note']['version']===1);
inCheck('create replay allocates exactly one note',inMut('create',$stable)===$created&&(int)$pdo->query('SELECT COUNT(*) FROM manager_internal_notes')->fetchColumn()===1);
inCheck('other visible colleague reads shared notes without edit grant',ManagerInternalNoteService::list(8,1)['notes'][0]['id']===$id&&!ManagerInternalNoteService::list(8,1)['notes'][0]['can_edit']);
inCheck('ordinary colleague cannot edit or delete other author note',inMut('update',['id'=>$id,'version'=>1,'body'=>'Hack'],8)['error']==='forbidden'&&inMut('delete',['id'=>$id,'version'=>1],8)['error']==='forbidden');
$edit=inMut('update',['id'=>$id,'version'=>1,'body'=>'Updated staff note']);
inCheck('author update increments version and retains original author/time',$edit['note']['version']===2&&$edit['note']['body']==='Updated staff note'&&$edit['note']['author_id']===7&&$edit['note']['created_at']===$created['note']['created_at']);
$recovery=ManagerInternalNoteService::operation(7,1,$stable['operation_id']);
inCheck('old positive receipt and create replay return current note never obsolete body',$recovery['found']&&$recovery['result']['note']===$edit['note']&&inMut('create',$stable)['note']===$edit['note']);
inCheck('operation command mismatch and wrong conversation cannot reuse receipt',inMut('create',array_replace($stable,['body'=>'Changed']))['error']==='operation_mismatch'&&ManagerInternalNoteService::operation(7,2,$stable['operation_id'])['error']==='operation_mismatch');
inCheck('receipt read is account-scoped and unknown is not success',!ManagerInternalNoteService::operation(8,1,$stable['operation_id'])['found']&&!ManagerInternalNoteService::operation(7,1,'missing-operation-key-0001')['found']);
$conflict=inMut('update',['id'=>$id,'version'=>1,'body'=>'Stale']);
inCheck('stale edit returns confirmed current note without overwrite',$conflict['error']==='conflict'&&$conflict['note']===$edit['note']);
$admin=inMut('update',['id'=>$id,'version'=>2,'body'=>'Admin edit'],90);
inCheck('fresh admin may edit while authorship stays unchanged',$admin['ok']&&$admin['note']['version']===3&&$admin['note']['author_id']===7&&$admin['note']['can_edit']);
$advancedReceipt=ManagerInternalNoteService::operation(7,1,$stable['operation_id']);
inCheck('old create receipt preserves committed version independently of newer admin note',$advancedReceipt['result']['saved_version']===1&&$advancedReceipt['result']['note']['version']===3&&$advancedReceipt['result']['note']['body']==='Admin edit'&&inMut('create',$stable)['saved_version']===1);
$noop=inMut('update',['id'=>$id,'version'=>3,'body'=>'Admin edit']);
inCheck('no-op edit receipt records existing committed version without increment',$noop['saved_version']===3&&$noop['note']['version']===3&&$edit['saved_version']===2&&$admin['saved_version']===3);
inCheck('cross-conversation note ID cannot read or mutate original note',inMut('update',['id'=>$id,'version'=>3,'body'=>'Cross conversation'],7,2)['error']==='not_found');
inCheck('Unicode body bounds include4000 and reject4001 and malformed UTF8',ManagerInternalNoteService::normalize(str_repeat('я',4000))['ok']&&ManagerInternalNoteService::normalize(str_repeat('я',4001))['error']==='invalid_body'&&ManagerInternalNoteService::normalize("\xff")['error']==='invalid_body');
foreach ([null,[]," \n ","a\0b"] as $body) inCheck('invalid note text fails without write',inMut('create',['body'=>$body])['error']==='invalid_body');
inCheck('invalid version operation ID and page boundaries fail closed',inMut('update',['id'=>$id,'version'=>'3','body'=>'x'])['error']==='invalid_version'&&inMut('create',['body'=>'x','operation_id'=>'short'])['error']==='invalid_operation_id'&&ManagerInternalNoteService::list(7,1,['before_id'=>-1])['error']==='invalid_page'&&ManagerInternalNoteService::list(7,1,['page_size'=>51])['error']==='invalid_page');
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');
inCheck('revoked project denies notes read write and operation readback',ManagerInternalNoteService::list(7,1)['error']==='forbidden'&&inMut('create',['body'=>'Revoked'])['error']==='forbidden'&&ManagerInternalNoteService::operation(7,1,$stable['operation_id'])['error']==='forbidden');
$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
$pdo->exec("UPDATE managers SET role='manager' WHERE id=90");
inCheck('fresh role demotion removes admin edit authority',inMut('update',['id'=>$id,'version'=>3,'body'=>'No longer admin'],90)['error']==='forbidden');
$pdo->exec("UPDATE managers SET role='admin' WHERE id=90");
$pdo->exec("CREATE TRIGGER reject_note_receipt BEFORE UPDATE ON manager_internal_note_operations WHEN NEW.operation_id='note-receipt-rollback-0001' BEGIN SELECT RAISE(FAIL,'synthetic receipt failure'); END");
$count=(int)$pdo->query('SELECT COUNT(*) FROM manager_internal_notes')->fetchColumn();$rollback=['body'=>'Rollback body','operation_id'=>'note-receipt-rollback-0001'];$failed=false;
try {inMut('create',$rollback);} catch (PDOException $e) {$failed=true;}
inCheck('failed receipt rolls back note and UNKNOWN remains unknown',$failed&&(int)$pdo->query('SELECT COUNT(*) FROM manager_internal_notes')->fetchColumn()===$count&&!ManagerInternalNoteService::operation(7,1,$rollback['operation_id'])['found']);
$pdo->exec('DROP TRIGGER reject_note_receipt');
$retry=inMut('create',$rollback);
inCheck('same command after confirmed rollback creates exactly once',$retry['ok']&&inMut('create',$rollback)['id']===$retry['id']);
$deleted=inMut('delete',['id'=>$id,'version'=>3]);
inCheck('soft deletion clears private text and preserves versioned tombstone',$deleted['ok']&&$deleted['saved_version']===4&&$deleted['note']['deleted']&&$deleted['note']['body']===''&&$deleted['note']['version']===4&&!$deleted['note']['can_edit']);
inCheck('old receipts project deleted tombstone never resurrect deleted text',ManagerInternalNoteService::operation(7,1,$stable['operation_id'])['result']['note']===$deleted['note']&&inMut('create',$stable)['note']===$deleted['note']);
inCheck('deleted note cannot silently be edited again',inMut('update',['id'=>$id,'version'=>4,'body'=>'Resurrect'])['error']==='deleted');
$bulk=$pdo->prepare("INSERT INTO manager_internal_notes(conversation_id,author_manager_id,body,version,created_at,updated_at) VALUES(1,7,?,1,'2026-10-14 00:00:00','2026-10-14 00:00:00')");
for ($i=0;$i<121;$i++) $bulk->execute(['Synthetic page note '.$i]);
$latest=ManagerInternalNoteService::list(7,1);$older=ManagerInternalNoteService::list(7,1,['before_id'=>$latest['history']['before_id']]);$oldest=ManagerInternalNoteService::list(7,1,['before_id'=>$older['history']['before_id']]);
$ids=array_merge(array_column($oldest['notes'],'id'),array_column($older['notes'],'id'),array_column($latest['notes'],'id'));
inCheck('latest50 and explicit older cursor cover123 notes without gaps duplicates',count($latest['notes'])===50&&$latest['history']['has_more']&&count($older['notes'])===50&&count($oldest['notes'])===23&&!$oldest['history']['has_more']&&count(array_unique($ids))===123&&$ids===array_map('intval',$pdo->query('SELECT id FROM manager_internal_notes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
$pdo->queries=0;ManagerInternalNoteService::list(7,1,['page_size'=>1]);$one=$pdo->queries;
$pdo->queries=0;ManagerInternalNoteService::list(7,1);$fifty=$pdo->queries;
inCheck('bounded note read has no author-per-row N+1',$one===$fifty);
echo "EVIDENCE synthetic note_list queries1={$one} queries50={$fifty}; no production timing claimed\n";
inCheck('full notes workflow preserves transcript read markers assignment events shifts',inBusiness()===$before);

// Actual HTTP endpoint and unmodified Manager auth/CSRF use isolated fixtures.
$httpRoot=sys_get_temp_dir().'/manager-internal-note-http-'.bin2hex(random_bytes(8));
mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/sessions',0700);$process=null;$httpDb=null;
try {
    $httpDb=new PDO('sqlite:'.$httpRoot.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $sql) $httpDb->exec($sql);
    foreach (['projects','manager_projects','managers','customers','manager_customer_names','conversations','conversation_sources','manager_group_members','messages','manager_conversation_reads','conversation_events'] as $table) {
        foreach ($pdo->query('SELECT * FROM '.$table)->fetchAll() as $row) {$q=$httpDb->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));}
    }
    $sourceRoot=dirname(__DIR__);
    file_put_contents($httpRoot.'/config.php',str_replace('\\n',"\n",'<?php\nrequire_once '.var_export($sourceRoot.'/services/ManagerRequestContext.php',true).';\n'.'$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n'.'(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);\n'.'ProjectConfig::resetForTests(["id"=>"notes-fixture","brand"=>["name"=>"Synthetic workspace"]]);\nProjectAccessService::initializeReadOnly();\n'));
    copy($sourceRoot.'/manager/internal-notes-api.php',$httpRoot.'/manager/internal-notes-api.php');symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');symlink($sourceRoot.'/services',$httpRoot.'/services');
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??7);$_SESSION["csrf"]="notes-synthetic-csrf";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate notes loopback fixture');$address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);if (!is_resource($process)) throw new RuntimeException('Cannot start notes loopback fixture');fclose($pipes[0]);
    $ready=false;for ($i=0;$i<200;$i++) {$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if ($probe) {fclose($probe);$ready=true;break;}usleep(10000);}if (!$ready) throw new RuntimeException('Notes loopback unavailable');
    $request=static function(array $data=[],string $cookie='',string $method='POST',string $path='/manager/internal-notes-api.php') use($address):array {
        $headers=['Content-Type: application/json'];if ($cookie!=='') $headers[]='Cookie: '.$cookie;
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data,JSON_THROW_ON_ERROR):'','ignore_errors'=>true,'timeout'=>3]]);$body=file_get_contents('http://'.$address.$path,false,$context);$responseHeaders=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$responseCookie='';foreach ($responseHeaders as $h) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$h,$m)) $responseCookie=$m[1];
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$body,true),'cookie'=>$responseCookie];
    };
    $anonymous=$request(['action'=>'list','conversation_id'=>1]);inCheck('actual notes HTTP rejects anonymous401',$anonymous['status']===401);
    $login=$request([],'','GET','/manager/fixture-session.php?id=7');$cookie=$login['cookie'];$csrf=['csrf'=>'notes-synthetic-csrf'];$base=['conversation_id'=>1]+$csrf;
    inCheck('actual notes list requires CSRF403',$request(['action'=>'list','conversation_id'=>1],$cookie)['status']===403);
    inCheck('actual notes endpoint requires POST405',$request([],$cookie,'GET')['status']===405);
    $create=['action'=>'create','body'=>'HTTP staff note','operation_id'=>'http-note-create-stable-001']+$base;
    $httpCreate=$request($create,$cookie);$httpId=$httpCreate['body']['id'];
    inCheck('actual notes HTTP save and same-key replay allocate once',$httpCreate['status']===200&&$request($create,$cookie)['body']===$httpCreate['body']);
    $colleague=$request([],'','GET','/manager/fixture-session.php?id=8')['cookie'];
    $shared=$request(['action'=>'list']+$base,$colleague);$forbidden=$request(['action'=>'update','id'=>$httpId,'version'=>1,'body'=>'Hack','operation_id'=>'http-note-forbidden-001']+$base,$colleague);
    inCheck('actual colleague reads but cannot edit other author403',$shared['status']===200&&!$shared['body']['notes'][0]['can_edit']&&$forbidden['status']===403);
    $updated=$request(['action'=>'update','id'=>$httpId,'version'=>1,'body'=>'HTTP updated','operation_id'=>'http-note-update-stable-001','manager_id'=>8,'author_id'=>8]+$base,$cookie);
    inCheck('actual notes actor injection is ignored and original author remains',$updated['status']===200&&$updated['body']['note']['author_id']===7&&$updated['body']['note']['version']===2);
    $httpReceipt=$request(['action'=>'operation','operation_id'=>$create['operation_id']]+$base,$cookie);
    inCheck('actual receipt recovery returns current note body',$httpReceipt['status']===200&&$httpReceipt['body']['found']&&$httpReceipt['body']['result']['note']===$updated['body']['note']);
    inCheck('actual old receipt preserves original saved version and current advanced version',$httpReceipt['body']['result']['saved_version']===1&&$httpReceipt['body']['result']['note']['version']===2&&$updated['body']['saved_version']===2);
    $httpConflict=$request(['action'=>'update','id'=>$httpId,'version'=>1,'body'=>'Stale','operation_id'=>'http-note-version-conflict-001']+$base,$cookie);
    inCheck('actual stale edit409 returns confirmed currentnote',$httpConflict['status']===409&&$httpConflict['body']['note']===$updated['body']['note']);
    inCheck('actual malformed note422',$request(['action'=>'create','body'=>[],'operation_id'=>'http-note-invalid-body-001']+$base,$cookie)['status']===422);
    $adminCookie=$request([],'','GET','/manager/fixture-session.php?id=90')['cookie'];$adminDeleted=$request(['action'=>'delete','id'=>$httpId,'version'=>2,'operation_id'=>'http-note-admin-delete-001']+$base,$adminCookie);
    inCheck('actual admin can softdelete without sending customer message',$adminDeleted['status']===200&&$adminDeleted['body']['note']['deleted']&&$adminDeleted['body']['note']['body']==='');
    $deletedReceipt=$request(['action'=>'operation','operation_id'=>$create['operation_id']]+$base,$cookie);
    inCheck('actual old receipt after admin change retains command provenance and current tombstone',$deletedReceipt['body']['result']['saved_version']===1&&$deletedReceipt['body']['result']['note']['version']===3&&$deletedReceipt['body']['result']['note']['deleted']&&$adminDeleted['body']['saved_version']===3);
    $httpDb->exec('DELETE FROM manager_projects WHERE manager_id=7');
    inCheck('actual revoked project blocks read and receipt403',$request(['action'=>'list']+$base,$cookie)['status']===403&&$request(['action'=>'operation','operation_id'=>$create['operation_id']]+$base,$cookie)['status']===403);
    $httpDb->exec('INSERT INTO manager_projects VALUES(7,1)');$httpDb->exec('UPDATE managers SET is_active=0 WHERE id=7');
    inCheck('actual existing session rechecks inactive account401',$request(['action'=>'list']+$base,$cookie)['status']===401);
    $httpDb->exec('UPDATE managers SET is_active=1 WHERE id=7');$httpDb->exec('DROP TABLE manager_internal_notes');
    inCheck('actual missing notes schema503 never empty success',$request(['action'=>'list']+$base,$cookie)['status']===503);
} finally {
    if (is_resource($process)) {proc_terminate($process);proc_close($process);}$httpDb=null;
    $remove=static function(string $path) use(&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach (scandir($path)?:[] as $name) if ($name!=='.'&&$name!=='..') $remove($path.'/'.$name);rmdir($path);}};$remove($httpRoot);
}
echo "TOTAL {$passed} | PASS {$passed} | FAIL 0\n";
