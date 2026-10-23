<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerTourSelectionService.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
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
$pdo->exec('CREATE TABLE manager_tour_selection_drafts(conversation_id INTEGER PRIMARY KEY,version INTEGER,draft_json TEXT,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE manager_tour_selection_operations(manager_id INTEGER,operation_id TEXT,conversation_id INTEGER,request_hash TEXT,saved_version INTEGER,created_at TEXT,PRIMARY KEY(manager_id,operation_id));');
$passed=0;
function tsCheck(string $name,bool $ok): void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function tsState(): array {global $pdo;$out=[];foreach (['managers','conversations','messages','manager_conversation_reads','conversation_events'] as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return $out;}
function tsSave(array $draft,int $version=0,int $actor=8,int $conversation=1,?string $operation=null): array {static $seq=0;return ManagerTourSelectionService::save($actor,$conversation,['version'=>$version,'draft'=>$draft,'operation_id'=>$operation??'selection-command-'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT)]);}
$before=tsState();$empty=ManagerTourSelectionDraft::empty();
tsCheck('new selection read performs no writes or read marking',ManagerTourSelectionService::get(8,1)['selection']['draft']===$empty&&tsState()===$before);
tsCheck('anonymous inactive and foreign actors cannot read',ManagerTourSelectionService::get(0,1)['error']==='unauthorized'&&ManagerTourSelectionService::get(10,1)['error']==='unauthorized'&&ManagerTourSelectionService::get(9,1)['error']==='not_found'&&ManagerTourSelectionService::get(7,4)['error']==='not_found');
tsCheck('visible colleague has read-only selection',!ManagerTourSelectionService::get(7,1)['selection']['can_edit']&&tsSave($empty,0,7)['error']==='forbidden');
$offer=['id'=>str_repeat('a',32),'selected'=>true,'hotel'=>'Synthetic Grand Hotel','departure'=>'Москва','room'=>'Стандартный','meal'=>'Всё включено','amount'=>'240000.00','currency'=>'RUB','date_from'=>'2027-06-05','nights'=>7,'adults'=>2,'child_ages'=>[0,4,4],'source_url'=>'https://example.invalid/tour?id=77','rationale'=>'Близко к пляжу','compromise'=>'Небольшой номер'];
$draft=['title'=>'Летний отдых','intro'=>'Выбрали варианты у моря','offers'=>[$offer]];
$normalized=ManagerTourSelectionDraft::normalize($draft);
tsCheck('complete draft preserves repeated child ages and infant',$normalized!==null&&$normalized['offers'][0]['child_ages']===[0,4,4]);
$first=tsSave($draft,0,8,1,'selection-create-stable-001');
tsCheck('assigned manager creates server draft version1',$first['ok']&&$first['saved_version']===1&&$first['selection']['draft']===$normalized);
$replay=tsSave($draft,0,8,1,'selection-create-stable-001');
tsCheck('same operation replays one version without duplicate row',$replay===$first&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_drafts')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM manager_tour_selection_operations')->fetchColumn()===1);
$other=$draft;$other['offers'][0]['amount']='250000';
tsCheck('key cannot be reused for different conditions',tsSave($other,0,8,1,'selection-create-stable-001')['error']==='operation_mismatch');
$stale=tsSave($other,0);
tsCheck('stale editor receives current conditions with conflict',$stale['error']==='conflict'&&$stale['selection']['version']===1&&$stale['selection']['draft']===$normalized);
$changed=$normalized;$changed['offers'][0]['selected']=false;$changed['offers'][]=array_replace($normalized['offers'][0],['id'=>str_repeat('b',32),'amount'=>'260000','room'=>'Семейный']);$changed['offers']=array_reverse($changed['offers']);
$second=tsSave($changed,1);
tsCheck('order exclusion and same hotel different room retained',$second['ok']&&$second['selection']['version']===2&&$second['selection']['draft']['offers']===$changed['offers']);
$receipt=ManagerTourSelectionService::operation(8,1,'selection-create-stable-001');
tsCheck('recovery distinguishes original saved version and current draft',$receipt['found']&&$receipt['saved_version']===1&&$receipt['selection']['version']===2&&$receipt['selection']['draft']===$changed);
tsCheck('absent operation is explicit and not fabricated success',!ManagerTourSelectionService::operation(8,1,'selection-not-issued-001')['found']);
tsCheck('cross-conversation operation binding rejects reuse',ManagerTourSelectionService::operation(8,2,'selection-create-stable-001')['error']==='operation_mismatch');
tsCheck('partial draft saves without inventing conditions',tsSave(['title'=>'Варианты','intro'=>'','offers'=>[['id'=>str_repeat('c',32),'selected'=>true]]],0,90,2)['ok']);
foreach ([['adults'=>0],['nights'=>61],['amount'=>'-10'],['amount'=>'1e6'],['amount'=>1],['currency'=>'AAA'],['child_ages'=>[18]],['child_ages'=>['4']],['date_from'=>'2027-02-30'],['selected'=>1],['id'=>str_repeat('a',31)],['price_verified'=>true],['manager_id'=>90],['source_url'=>'https://example.invalid/?access_token=secret'],['source_url'=>'https://login:password@example.invalid/'],['source_url'=>'https://127.0.0.1/'],['source_url'=>'javascript:alert(1)']] as $bad) {
    $invalid=$draft;$invalid['offers'][0]=array_replace($offer,$bad);
    tsCheck('invalid offer rejected '.implode(',',array_keys($bad)),ManagerTourSelectionDraft::normalize($invalid)===null);
}
$duplicates=$draft;$duplicates['offers'][]=$offer;tsCheck('duplicate offer IDs rejected',ManagerTourSelectionDraft::normalize($duplicates)===null);
$tooMany=$draft;$tooMany['offers']=array_map(static fn(int $i):array=>array_replace($offer,['id'=>str_pad(dechex($i),32,'0',STR_PAD_LEFT)]),range(1,21));tsCheck('bounded20 offers enforced',ManagerTourSelectionDraft::normalize($tooMany)===null);
$unsafe=$draft;$unsafe['offers'][0]['hotel']="Unsafe\0hotel";tsCheck('control characters rejected',ManagerTourSelectionDraft::normalize($unsafe)===null);
$literal=$draft;$literal['offers'][0]['hotel']='<script>literal</script>';tsCheck('import text kept literal for escaped rendering',ManagerTourSelectionDraft::normalize($literal)['offers'][0]['hotel']==='<script>literal</script>');
$pdo->exec('UPDATE conversations SET manager_id=7 WHERE id=1');
tsCheck('reassignment immediately prevents old manager write',tsSave($other,2)['error']==='forbidden');
tsCheck('new assignee reads and edits shared draft',tsSave($other,2,7)['ok']);
$pdo->exec("UPDATE conversations SET status='closed' WHERE id=1");
tsCheck('closed conversation cannot receive draft mutations',tsSave($other,3,90)['error']==='forbidden');
$pdo->exec("UPDATE conversations SET status='manager',manager_id=8 WHERE id=1");
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=8');
tsCheck('revoked project denies read and operation receipt',ManagerTourSelectionService::get(8,1)['error']==='not_found'&&ManagerTourSelectionService::operation(8,1,'selection-create-stable-001')['error']==='not_found');
$pdo->exec('INSERT INTO manager_projects VALUES(8,1)');
$pdo->exec('UPDATE managers SET is_active=0 WHERE id=8');tsCheck('deactivated manager cannot save',tsSave($other,3)['error']==='unauthorized');$pdo->exec('UPDATE managers SET is_active=1 WHERE id=8');
// Restore only synthetic assignment changes before proving no transcript effects.
$pdo->exec('UPDATE conversations SET manager_id=8 WHERE id=1');
tsCheck('draft workflow never changes customer transcript shifts or read markers',tsState()===$before);
// Actual HTTP endpoint and unmodified Manager auth/CSRF use isolated fixtures.
require_once __DIR__.'/support/manager-tour-collector.php';
$httpRoot=sys_get_temp_dir().'/manager-tour-selection-http-'.bin2hex(random_bytes(8));
mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/sessions',0700);$process=null;$httpDb=null;
try {
    $httpDb=new PDO('sqlite:'.$httpRoot.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $sql) $httpDb->exec($sql);
    collectorSchema($httpDb);
    foreach (['projects','manager_projects','managers','customers','manager_customer_names','conversations','conversation_sources','manager_group_members','messages','manager_conversation_reads','conversation_events'] as $table) {
        foreach ($pdo->query('SELECT * FROM '.$table)->fetchAll() as $row) {$q=$httpDb->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));}
    }
    $sourceRoot=dirname(__DIR__);
    file_put_contents($httpRoot.'/config.php',str_replace('\\n',"\n",'<?php\nrequire_once '.var_export($sourceRoot.'/services/ManagerRequestContext.php',true).';\n'.'$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n'.'(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);\n'.'ProjectConfig::resetForTests(["id"=>"notes-fixture","brand"=>["name"=>"Synthetic workspace"]]);\nProjectAccessService::initializeReadOnly();\n'));
    copy($sourceRoot.'/manager/tour-selections-api.php',$httpRoot.'/manager/tour-selections-api.php');copy($sourceRoot.'/manager/tour-selection-collector.php',$httpRoot.'/manager/tour-selection-collector.php');symlink($sourceRoot.'/manager/assets',$httpRoot.'/manager/assets');symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');symlink($sourceRoot.'/services',$httpRoot.'/services');
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??7);$_SESSION["csrf"]="selection-synthetic-csrf";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate notes loopback fixture');$address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);if (!is_resource($process)) throw new RuntimeException('Cannot start notes loopback fixture');fclose($pipes[0]);
    $ready=false;for ($i=0;$i<200;$i++) {$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if ($probe) {fclose($probe);$ready=true;break;}usleep(10000);}if (!$ready) throw new RuntimeException('Notes loopback unavailable');
    $request=static function(array $data=[],string $cookie='',string $method='POST',string $path='/manager/tour-selections-api.php') use($address):array {
        $headers=['Content-Type: application/json'];if ($cookie!=='') $headers[]='Cookie: '.$cookie;
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data,JSON_THROW_ON_ERROR):'','ignore_errors'=>true,'timeout'=>3]]);$body=file_get_contents('http://'.$address.$path,false,$context);$responseHeaders=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$responseCookie='';foreach ($responseHeaders as $h) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$h,$m)) $responseCookie=$m[1];
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$body,true),'cookie'=>$responseCookie,'raw'=>(string)$body,'headers'=>$responseHeaders];
    };

    $anonymous=$request(['action'=>'get','conversation_id'=>1]);tsCheck('actual selection endpoint rejects anonymous401',$anonymous['status']===401);
    $login=$request([],'','GET','/manager/fixture-session.php?id=8');$cookie=$login['cookie'];$base=['conversation_id'=>1,'csrf'=>'selection-synthetic-csrf'];
    tsCheck('actual selection endpoint requires CSRF403',$request(['action'=>'get','conversation_id'=>1],$cookie)['status']===403);
    tsCheck('actual selection endpoint requires POST405',$request([],$cookie,'GET')['status']===405);
    $write=['action'=>'save','version'=>0,'draft'=>$draft,'operation_id'=>'http-selection-create-stable-001']+$base;
    $created=$request($write,$cookie);tsCheck('actual selection HTTP create and stable replay',$created['status']===200&&$created['body']['selection']['version']===1&&$request($write,$cookie)['body']===$created['body']);
    $colleague=$request([],'','GET','/manager/fixture-session.php?id=7')['cookie'];
    tsCheck('actual visible colleague reads without acquiring edit rights',$request(['action'=>'get']+$base,$colleague)['status']===200&&$request(['action'=>'get']+$base,$colleague)['body']['selection']['can_edit']===false&&$request($write,$colleague)['status']===403);
    tsCheck('actual stale version returns409 and current draft',$request(['action'=>'save','version'=>0,'draft'=>$other,'operation_id'=>'http-selection-stale-001']+$base,$cookie)['status']===409);
    $recovered=$request(['action'=>'operation','operation_id'=>$write['operation_id']]+$base,$cookie);
    tsCheck('actual operation read recovers successful save',$recovered['status']===200&&$recovered['body']['found']&&$recovered['body']['saved_version']===1);
    tsCheck('actual forged verification flag rejected422',$request(['action'=>'save','version'=>1,'draft'=>['title'=>'Forge','intro'=>'','offers'=>[array_replace($offer,['price_verified'=>true])]],'operation_id'=>'http-selection-forge-001']+$base,$cookie)['status']===422);
    $device=str_repeat('a',32);$connection=str_repeat('b',32);
    $collector=$request(['action'=>'collector_get','device_id'=>$device]+$base,$cookie);
    tsCheck('actual collector reads only minimal current target',$collector['status']===200&&$collector['body']['target']['actor']===8&&!isset($collector['body']['selection']));
    $page=$request([],$cookie,'GET','/manager/tour-selection-collector.php?conversation_id=1');
    tsCheck('actual bridge uses canonical Manager session with private no-store HTML',$page['status']===200&&str_contains($page['raw'],'"actor":8')&&str_contains(implode('\n',$page['headers']),'private, no-store')&&str_contains(implode('\n',$login['headers']),'path=/manager/'));
    $connect=['action'=>'collector_connect','device_id'=>$device,'connection_id'=>$connection,'binding'=>$collector['body']['target']['binding'],'confirm_current_trip'=>true]+$base;
    tsCheck('actual collector connection needs current CSRF',$request(array_diff_key($connect,['csrf'=>true]),$cookie)['status']===403);
    tsCheck('actual session-bound collector connect returns one durable connection',$request($connect,$cookie)['body']['connection']['id']===$connection&&$request($connect,$cookie)['body']['connection']['id']===$connection);
    $import=['action'=>'collector_import','connection_id'=>$connection,'operation_id'=>'http-collector-intent-0001','observation'=>collectorObservation()]+$base;
    $status=array_replace($import,['action'=>'collector_operation']);
    tsCheck('actual import status is explicit before creation',$request($status,$cookie)['body']['found']===false);
    $added=$request($import,$cookie);
    tsCheck('actual protected HTTP import appends to canonical draft',$added['status']===200&&$added['body']['count']===2&&$added['body']['saved_version']===2);
    $confirmed=$request($status,$cookie);
    tsCheck('actual HTTP lost-response check retains exact intent and returns no draft',$confirmed['status']===200&&$confirmed['body']['found']===true&&!isset($confirmed['body']['selection'])&&$request($import,$cookie)['body']['saved_version']===2);
    tsCheck('actual status cannot reuse a receipt for another payload',$request($status+['as_variant'=>true],$cookie)['status']===409);
    $secondCookie=$request([],'','GET','/manager/fixture-session.php?id=8')['cookie'];
    tsCheck('actual new login session cannot inherit old collector metadata',$request($status,$secondCookie)['status']===409&&$request($import,$secondCookie)['status']===409);
    tsCheck('actual colleague cannot use author connection',$request($import,$colleague)['status']===403);
    $revoked=$request(['action'=>'collector_revoke']+$base,$cookie);
    tsCheck('actual revoke prevents both writes and receipt status',$revoked['status']===200&&$request($import,$cookie)['status']===409&&$request($status,$cookie)['status']===409);
    $httpDb->exec('UPDATE conversations SET manager_id=7 WHERE id=1');
    tsCheck('actual reassignment blocks former manager403',$request(['action'=>'save','version'=>1,'draft'=>$other,'operation_id'=>'http-selection-reassigned-001']+$base,$cookie)['status']===403);
    $httpDb->exec('DELETE FROM manager_projects WHERE manager_id=8');
    tsCheck('actual project revocation blocks read404',$request(['action'=>'get']+$base,$cookie)['status']===404);
    $httpDb->exec('INSERT INTO manager_projects VALUES(8,1)');$httpDb->exec('UPDATE managers SET is_active=0 WHERE id=8');
    tsCheck('actual session rechecks inactive account401',$request(['action'=>'get']+$base,$cookie)['status']===401);
    $httpDb->exec('UPDATE managers SET is_active=1 WHERE id=8');$httpDb->exec('DROP TABLE manager_tour_selection_drafts');
    tsCheck('actual unavailable storage returns503 not empty success',$request(['action'=>'get']+$base,$cookie)['status']===503);
} finally {
    if (is_resource($process)) {proc_terminate($process);proc_close($process);}$httpDb=null;
    $remove=static function(string $path) use(&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach (scandir($path)?:[] as $name) if ($name!=='.'&&$name!=='..') $remove($path.'/'.$name);rmdir($path);}};$remove($httpRoot);
}
$pdo->exec('DROP TABLE manager_tour_selection_drafts');$thrown=false;try{ManagerTourSelectionService::get(8,1);}catch(PDOException $e){$thrown=true;}tsCheck('missing schema is failure not an empty successful selection',$thrown);
echo "TOTAL {$passed} | PASS {$passed} | FAIL 0\n";
