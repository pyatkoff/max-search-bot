<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerClientEventService.php';
final class ClientEventFixturePdo extends PDO
{
    public int $queries=0; public $afterRead=null;
    public function __construct(string $dsn='sqlite::memory:') {parent::__construct($dsn,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ClientEventFixtureStatement::class,[$this]]);}
    public function prepare(string $query,array $options=[]): PDOStatement|false {$this->queries++;return parent::prepare($query,$options);}
}
final class ClientEventFixtureStatement extends PDOStatement
{
    protected function __construct(private ClientEventFixturePdo $fixture) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        $rows=parent::fetchAll($mode,...$args);
        if (str_starts_with($this->queryString,'SELECT t.id,t.created_at')&&$this->fixture->afterRead!==null) {$callback=$this->fixture->afterRead;$this->fixture->afterRead=null;$callback();}
        return $rows;
    }
}
$pdo=new ClientEventFixturePdo();
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
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT);
INSERT INTO projects VALUES(1,'notes-fixture','Synthetic workspace',1),(2,'other','Synthetic other',1);
INSERT INTO manager_projects VALUES(7,1),(8,1),(90,1),(9,2);
INSERT INTO managers VALUES(7,'author','Synthetic author','manager','',1,1),(8,'colleague','Synthetic colleague','manager','',1,1),(90,'admin','Synthetic admin','admin','',1,1),(9,'foreign','Synthetic foreign','manager','',1,1),(10,'inactive','Inactive','manager','',0,1);
INSERT INTO customers VALUES(1,'Synthetic client','123','');
INSERT INTO conversation_sources VALUES(1,'Allowed',0,'none',0,0,1),(2,'Restricted',101,'none',0,0,1);
INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id,started_at) VALUES(1,1,'notes-fixture',1,'max','manager',8,'2026-10-14 00:00:00'),(2,1,'notes-fixture',1,'max','waiting_manager',NULL,'2026-10-14 00:00:00'),(3,1,'other',1,'max','manager',9,'2026-10-14 00:00:00'),(4,1,'notes-fixture',2,'max','manager',7,'2026-10-14 00:00:00');
INSERT INTO messages VALUES(11,1,'Original customer transcript');
INSERT INTO manager_conversation_reads VALUES(7,1,10);
INSERT INTO conversation_events VALUES(1,1,'waiting_manager','customer',NULL,'{\"secret\":\"DO NOT EXPOSE\"}','2026-10-14 09:00:00');");
$pdo->exec("CREATE TABLE lead_stages(stage_key TEXT PRIMARY KEY,display_name TEXT);
CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY,conversation_id INTEGER,from_stage_key TEXT,to_stage_key TEXT,changed_by_manager_id INTEGER,created_at TEXT);
CREATE TABLE manager_customer_name_history(customer_id INTEGER,version INTEGER,conversation_id INTEGER,actor_manager_id INTEGER,before_name TEXT,after_name TEXT,created_at TEXT,PRIMARY KEY(customer_id,version));
CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY,conversation_id INTEGER,title TEXT,due_at_utc TEXT,status TEXT,created_by_manager_id INTEGER,created_at TEXT,updated_at TEXT);
INSERT INTO lead_stages VALUES('new','Новый'),('selection','Подбор');
INSERT INTO lead_stage_history VALUES(1,1,'new','selection',8,'2026-10-14 10:00:00');
INSERT INTO manager_customer_name_history VALUES(1,1,1,8,NULL,'Рабочее имя','2026-10-14 10:00:00'),(1,2,3,9,'Рабочее имя','Foreign forbidden name','2026-10-14 11:00:00');
INSERT INTO manager_internal_notes VALUES(1,1,8,'Текущая заметка <script>literal</script>',3,'2026-10-14 10:00:00','2026-10-14 12:00:00',NULL),(2,1,7,'DELETED TEXT MUST NOT LEAK',2,'2026-10-14 10:00:00','2026-10-14 12:00:00','2026-10-14 12:00:00');
INSERT INTO lead_tasks VALUES(1,1,'Текущая задача','2026-10-20 09:00:00','done',8,'2026-10-14 10:00:00','2026-10-14 12:00:00');
INSERT INTO conversation_events VALUES(2,1,'manager_taken','manager','8',NULL,'2026-10-14 10:00:00'),(3,1,'prepared_private','manager','8','PRIVATE_SECRET','2026-10-14 11:00:00'),(4,3,'manager_taken','manager','9',NULL,'2026-10-14 12:00:00');");
$passed=0;
function ceCheck(string $label,bool $ok): void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
function ceList(array $input=[],int $actor=7,int $conversation=1): array {return ManagerClientEventService::list($actor,$conversation,$input);}
function ceSnapshot(): array {global $pdo;$out=[];foreach (['conversations','messages','manager_conversation_reads','conversation_events','manager_internal_notes','lead_tasks','lead_stage_history','manager_customer_name_history','managers'] as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return $out;}
$before=ceSnapshot();$all=ceList();$json=json_encode($all,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
ceCheck('five existing sources combine without transcript or business writes',$all['ok']&&count($all['items'])===7&&ceSnapshot()===$before);
ceCheck('unknown events raw payloads foreign dialogue and deleted text never escape',!str_contains($json,'DO NOT EXPOSE')&&!str_contains($json,'PRIVATE_SECRET')&&!str_contains($json,'Foreign forbidden')&&!str_contains($json,'DELETED TEXT'));
ceCheck('ordinary employee output excludes colleague identities and IDs',!str_contains($json,'Synthetic colleague')&&!str_contains($json,'colleague')&&!str_contains($json,'actor_id')&&$all['items'][0]['actor']==='Менеджер');
$admin=ceList([],90);ceCheck('admin actor names are batch-projected only for existing source actors',$admin['items'][0]['actor']==='Synthetic colleague');
$byKind=[];foreach ($all['items'] as $item) $byKind[$item['kind']][]=$item;
ceCheck('actual stage and working-name history expose explicit before and after',$byKind['stage'][0]['details'][0]['value']==='Новый → Подбор'&&$byKind['name'][0]['details'][1]['value']==='Рабочее имя');
ceCheck('mutable notes tasks are current records not invented edit events',$byKind['note'][0]['current_record']&&$byKind['note'][0]['details'][0]['value']==='Заметка удалена'&&$byKind['note'][1]['updated_at']==='2026-10-14 12:00:00'&&$byKind['task'][0]['details'][1]['value']==='Выполнена'&&$byKind['task'][0]['due_at']==='2026-10-20 09:00:00');
ceCheck('active actor ordinary visibility project and source grants protect all reads',ceList([],0)['error']==='unauthorized'&&ceList([],10)['error']==='unauthorized'&&ceList([],7,3)['error']==='forbidden'&&ceList([],7,4)['error']==='forbidden');
$page=ceList(['page_size'=>2]);$keys=[];$pages=0;
do {foreach ($page['items'] as $item)$keys[]=$item['key'];$pages++;$cursor=$page['history']['cursor'];if (!$page['history']['has_more'])break;$page=ceList(['page_size'=>2,'cursor'=>$cursor]);}while ($pages<10);
ceCheck('timestamp ties across all tables page without duplicates or omissions',$pages===4&&$keys===array_column($all['items'],'key')&&count($keys)===count(array_unique($keys)));
$first=ceList(['page_size'=>2]);$stableCursor=$first['history']['cursor'];
$pdo->exec("INSERT INTO conversation_events VALUES(100,1,'conversation_closed','manager','8',NULL,'2026-10-14 09:30:00')");
$continued=ceList(['page_size'=>50,'cursor'=>$stableCursor]);
ceCheck('first-page high-water excludes later backdated insert from old snapshot',!in_array('event:100',array_column($continued['items'],'key'),true)&&in_array('event:100',array_column(ceList()['items'],'key'),true));
ceCheck('cursor is scoped to exact conversation and account',ceList(['cursor'=>$stableCursor],8)['error']==='invalid_cursor'&&ceList(['cursor'=>$stableCursor],7,2)['error']==='invalid_cursor');
foreach (['***',str_repeat('a',1025),null,[],rtrim(strtr(base64_encode('{"v":1}'),'+/','-_'),'=')] as $bad) ceCheck('malformed cursor fails closed',ceList(['cursor'=>$bad])['error']==='invalid_cursor');
ceCheck('page limits retain numeric typing',ceList(['page_size'=>0])['error']==='invalid_page'&&ceList(['page_size'=>51])['error']==='invalid_page'&&ceList(['page_size'=>'30'])['error']==='invalid_page');
$pdo->afterRead=static function() use($pdo):void {$pdo->exec('DELETE FROM manager_projects WHERE manager_id=7');};
ceCheck('project revoked during source load returns no already-collected private items',ceList()['error']==='forbidden');$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
$pdo->afterRead=static function() use($pdo):void {$pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');};
ceCheck('account deactivated during source load returns unauthorized',ceList()['error']==='unauthorized');$pdo->exec('UPDATE managers SET is_active=1 WHERE id=7');
$pdo->afterRead=static function() use($pdo):void {$pdo->exec("UPDATE managers SET role='manager' WHERE id=90");};
$demoted=ceList([],90);ceCheck('role demotion during source load cannot retain admin actor names',$demoted['ok']&&!str_contains(json_encode($demoted),'Synthetic colleague'));$pdo->exec("UPDATE managers SET role='admin' WHERE id=90");
$pdo->queries=0;ceList(['page_size'=>1]);$small=$pdo->queries;$pdo->queries=0;ceList(['page_size'=>50]);$large=$pdo->queries;
ceCheck('page-size growth adds no per-row queries',$small===$large);
echo "EVIDENCE bounded five-source reads queries1={$small} queries50={$large}; no production timing claimed\n";

// Real canonical HTTP/auth/CSRF boundary with an isolated synthetic SQLite store.
$httpRoot=sys_get_temp_dir().'/manager-client-events-http-'.bin2hex(random_bytes(8));
mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/sessions',0700);$process=null;$httpDb=null;
try {
    $httpDb=new PDO('sqlite:'.$httpRoot.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $sql) $httpDb->exec($sql);
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        foreach ($pdo->query('SELECT * FROM '.$table)->fetchAll() as $row) {$q=$httpDb->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));}
    }
    $sourceRoot=dirname(__DIR__);
    file_put_contents($httpRoot.'/config.php',str_replace('\\n',"\n",'<?php\nrequire_once '.var_export($sourceRoot.'/services/ManagerRequestContext.php',true).';\n'.'$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n'.'(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);\n'.'ProjectConfig::resetForTests(["id"=>"notes-fixture","brand"=>["name"=>"Synthetic workspace"]]);\nProjectAccessService::initializeReadOnly();\n'));
    copy($sourceRoot.'/manager/client-events-api.php',$httpRoot.'/manager/client-events-api.php');symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');symlink($sourceRoot.'/services',$httpRoot.'/services');
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??7);$_SESSION["csrf"]="events-synthetic-csrf";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate notes loopback fixture');$address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);if (!is_resource($process)) throw new RuntimeException('Cannot start notes loopback fixture');fclose($pipes[0]);
    $ready=false;for ($i=0;$i<200;$i++) {$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if ($probe) {fclose($probe);$ready=true;break;}usleep(10000);}if (!$ready) throw new RuntimeException('Notes loopback unavailable');
    $request=static function(array $data=[],string $cookie='',string $method='POST',string $path='/manager/client-events-api.php') use($address):array {
        $headers=['Content-Type: application/json'];if ($cookie!=='') $headers[]='Cookie: '.$cookie;
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data,JSON_THROW_ON_ERROR):'','ignore_errors'=>true,'timeout'=>3]]);$body=file_get_contents('http://'.$address.$path,false,$context);$responseHeaders=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$responseCookie='';foreach ($responseHeaders as $h) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$h,$m)) $responseCookie=$m[1];
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$body,true),'cookie'=>$responseCookie];
    };
    $anonymous=$request(['action'=>'list','conversation_id'=>1]);ceCheck('actual client events HTTP rejects anonymous401',$anonymous['status']===401);
    $cookie=$request([],'','GET','/manager/fixture-session.php?id=7')['cookie'];$base=['conversation_id'=>1,'csrf'=>'events-synthetic-csrf'];
    ceCheck('actual client events require CSRF403 and POST405',$request(['action'=>'list','conversation_id'=>1],$cookie)['status']===403&&$request([],$cookie,'GET')['status']===405);
    $result=$request(['action'=>'list','manager_id'=>90]+$base,$cookie);
    ceCheck('actual endpoint ignores injected actor and remains read-only',$result['status']===200&&$result['body']['ok']&&!str_contains(json_encode($result['body']),'Synthetic colleague'));
    ceCheck('actual endpoint validates conversation ID and page cursor',$request(['action'=>'list','conversation_id'=>'1','csrf'=>'events-synthetic-csrf'],$cookie)['status']===422&&$request(['action'=>'list','cursor'=>'broken']+$base,$cookie)['status']===422);
    ceCheck('actual endpoint rejects writes and inaccessible conversation',$request(['action'=>'create']+$base,$cookie)['status']===400&&$request(['action'=>'list','conversation_id'=>3,'csrf'=>'events-synthetic-csrf'],$cookie)['status']===403);
    $httpDb->exec('DELETE FROM manager_projects WHERE manager_id=7');ceCheck('actual source revocation blocks old session read403',$request(['action'=>'list']+$base,$cookie)['status']===403);$httpDb->exec('INSERT INTO manager_projects VALUES(7,1)');
    $httpDb->exec('UPDATE managers SET is_active=0 WHERE id=7');ceCheck('actual inactive account rejects session401',$request(['action'=>'list']+$base,$cookie)['status']===401);$httpDb->exec('UPDATE managers SET is_active=1 WHERE id=7');
    $httpDb->exec('DROP TABLE lead_tasks');ceCheck('source storage failure is503 rather than empty timeline',$request(['action'=>'list']+$base,$cookie)['status']===503);
} finally {
    if (is_resource($process)) {proc_terminate($process);proc_close($process);}$httpDb=null;
    $remove=static function(string $path) use(&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach (scandir($path)?:[] as $name) if ($name!=='.'&&$name!=='..') $remove($path.'/'.$name);rmdir($path);}};$remove($httpRoot);
}

echo "TOTAL {$passed} | PASS {$passed} | FAIL 0\n";
