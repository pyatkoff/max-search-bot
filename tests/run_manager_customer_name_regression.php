<?php

declare(strict_types=1);
require_once dirname(__DIR__).'/services/ManagerCustomerNameService.php';
require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';
$passed=0;
function cnCheck(string $name,bool $ok):void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
final class NameFixturePdo extends PDO
{
    public int $queries=0;
    public string $lastListSql='';
    public function __construct(string $dsn='sqlite::memory:') {parent::__construct($dsn,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$this->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));$this->sqliteCreateFunction('TIMESTAMPDIFF',static fn($unit,$from,$to)=>$from?strtotime((string)$to)-strtotime((string)$from):null);$this->sqliteCreateFunction('GREATEST',static fn($a,$b)=>max((int)$a,(int)$b));$this->sqliteCreateFunction('JSON_UNQUOTE',static fn($v)=>$v);}
    public function prepare(string $query,array $options=[]):PDOStatement|false {$this->queries++;if(str_starts_with($query,'SELECT c.id,c.project_key'))$this->lastListSql=$query;return parent::prepare(str_replace('TIMESTAMPDIFF(SECOND,',"TIMESTAMPDIFF('SECOND',",$query),$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {$this->queries++;return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
}
$pdo=new NameFixturePdo();(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)(new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$pdo->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,is_test INTEGER,external_chat_id TEXT);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,created_at TEXT,payload_json TEXT,actor_type TEXT);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,metadata_json TEXT,created_at TEXT);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER);
CREATE TABLE lead_stages(stage_key TEXT PRIMARY KEY,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER,is_terminal INTEGER,is_won INTEGER);
CREATE TABLE lead_tags(id INTEGER PRIMARY KEY,tag_key TEXT,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER);
CREATE TABLE conversation_lead_tags(conversation_id INTEGER,tag_id INTEGER);
CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY,conversation_id INTEGER,created_at TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE manager_customer_name_history(customer_id INTEGER,version INTEGER,conversation_id INTEGER,actor_manager_id INTEGER,operation_id TEXT,request_hash TEXT,before_name TEXT,after_name TEXT,result_json TEXT,created_at TEXT,PRIMARY KEY(customer_id,version),UNIQUE(actor_manager_id,operation_id));
CREATE TABLE admin_audit_log(id INTEGER PRIMARY KEY,actor_manager_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,project_key TEXT,before_json TEXT,after_json TEXT);
INSERT INTO projects VALUES(1,'anytour','Fixture',1),(2,'other','Other',1);
INSERT INTO managers VALUES(1,'one','Первый','manager','',1,1),(2,'two','Второй','manager','',1,1),(3,'admin','Администратор','admin','',1,0),(4,'outside','Чужой','admin','',1,1),(5,'inactive','Неактивный','manager','',0,1);
INSERT INTO manager_projects VALUES(1,1),(2,1),(3,1),(4,2);
INSERT INTO customers VALUES(10,'Анна исходная','',''),(20,'Анна исходная','',''),(80,'Скрытый клиент','','');");
$insert=$pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach([[100,10,'anytour',1],[101,10,'anytour',2],[200,20,'anytour',1],[800,80,'other',4]] as [$cid,$cust,$project,$assigned])$insert->execute([$cid,$cust,$project,0,'max','','','','manager','new',$assigned,'2026-10-14 01:00:00','2026-10-14 01:00:00',null,0,'synthetic-chat-'.$cid]);
function cnSave(int $actor,int $cid,$name,int $version,?string $op=null):array {static $seq=0;return ManagerCustomerNameService::save($actor,$cid,['working_name'=>$name,'version'=>$version,'operation_id'=>$op??('name-operation-'.str_pad((string)++$seq,8,'0',STR_PAD_LEFT))]);}
$get=ManagerCustomerNameService::get(1,100);cnCheck('initial original name and absent version preserved',$get['name']['original_display_name']==='Анна исходная'&&$get['name']['display_name']==='Анна исходная'&&$get['name']['version']===0&&$get['name']['working_name']===null&&$get['name']['can_edit']);
cnCheck('viewing colleague client does not grant edit',!ManagerCustomerNameService::get(2,100)['name']['can_edit']&&cnSave(2,100,'Запрещено',0)['error']==='forbidden');
cnCheck('out-of-project admin cannot view or write',ManagerCustomerNameService::get(4,100)['error']==='not_found'&&cnSave(4,100,'Запрещено',0)['error']==='not_found');
cnCheck('inactive manager cannot write',cnSave(5,100,'Запрещено',0)['error']==='not_found');
$op='stable-name-operation-0001';$saved=cnSave(1,100,'  Анна — семейная поездка  ',0,$op);
cnCheck('save retains original and updates common label',$saved['ok']&&$saved['name']['working_name']==='Анна — семейная поездка'&&$saved['name']['version']===1&&$saved['name']['updated_by_manager_name']==='Первый');
cnCheck('receipt retry returns same result and does not duplicate version',cnSave(1,100,'Анна — семейная поездка',0,$op)===$saved&&(int)$pdo->query('SELECT COUNT(*) FROM manager_customer_name_history')->fetchColumn()===1);
cnCheck('reusing operation key for changed command rejected',cnSave(1,100,'Иное',0,$op)['error']==='operation_mismatch');
cnCheck('same exact customer shares working name across conversations',ManagerCustomerNameService::get(2,101)['name']['working_name']==='Анна — семейная поездка');
// Defensive synthetic context mutation: retained receipts cannot escape their
// original customer even if a conversation association is repaired elsewhere.
$pdo->exec('UPDATE conversations SET customer_id=20 WHERE id=100');
cnCheck('write retry cannot reveal a receipt for a different resolved customer',cnSave(1,100,'Анна — семейная поездка',0,$op)['error']==='operation_mismatch');
$pdo->exec('UPDATE conversations SET customer_id=10 WHERE id=100');
cnCheck('matching original names do not merge customers',ManagerCustomerNameService::get(1,200)['name']['working_name']===null);
$stale=cnSave(2,101,'Старая правка',0);
cnCheck('parallel stale editor gets current value conflict',$stale['error']==='conflict'&&$stale['current']['version']===1&&$stale['current']['working_name']==='Анна — семейная поездка');
$second=cnSave(2,101,'Анна — Египет',1);
cnCheck('second assigned colleague edit increments common version',$second['ok']&&$second['name']['version']===2);
$history=ManagerCustomerNameService::get(1,100,['limit'=>1]);
cnCheck('history bounded and records author before and after',$history['history_has_more']&&count($history['history'])===1&&$history['history'][0]['actor_name']==='Второй'&&$history['history'][0]['before_name']==='Анна — семейная поездка'&&$history['history'][0]['after_name']==='Анна — Египет');
$previous=ManagerCustomerNameService::get(1,100,['before_version'=>2]);cnCheck('history pagination has no duplicate current version',count($previous['history'])===1&&$previous['history'][0]['version']===1);
cnCheck('receipt recovery is account and customer scoped',ManagerCustomerNameService::operation(1,100,$op)['found']&&!ManagerCustomerNameService::operation(2,101,$op)['found']&&!ManagerCustomerNameService::operation(1,200,$op)['found']&&ManagerCustomerNameService::operation(4,100,$op)['error']==='not_found');
$restore=cnSave(3,100,null,2);
cnCheck('admin restores original while keeping monotonic version',$restore['ok']&&$restore['name']['version']===3&&$restore['name']['working_name']===null&&$restore['name']['display_name']==='Анна исходная');
cnCheck('pre-restore stale form cannot write against reset version',cnSave(1,100,'Старая форма',0)['error']==='conflict');
$pdo->exec("UPDATE customers SET display_name='Анна обновлённая' WHERE id=10");
cnCheck('restored label follows current original name',ManagerCustomerNameService::get(1,100)['name']['display_name']==='Анна обновлённая');
$before=(int)$pdo->query('SELECT COUNT(*) FROM manager_customer_name_history')->fetchColumn();
$pdo->exec("CREATE TRIGGER reject_name_audit BEFORE INSERT ON admin_audit_log BEGIN SELECT RAISE(FAIL,'synthetic audit failure'); END");$failed=false;
try{cnSave(1,100,'Должно откатиться',3,'rollback-name-operation-0001');}catch(PDOException $e){$failed=true;}
cnCheck('audit failure atomically rolls back name, history and receipt',$failed&&ManagerCustomerNameService::get(1,100)['name']['version']===3&&(int)$pdo->query('SELECT COUNT(*) FROM manager_customer_name_history')->fetchColumn()===$before&&!ManagerCustomerNameService::operation(1,100,'rollback-name-operation-0001')['found']);
$pdo->exec('DROP TRIGGER reject_name_audit');
cnCheck('retry after rollback safely saves once',cnSave(1,100,'Анна — семья',3,'rollback-name-operation-0001')['ok']);
$cachedPermission=ManagerCustomerNameService::get(1,100)['name']['can_edit'];
$pdo->exec('UPDATE conversations SET manager_id=2 WHERE id=100');
cnCheck('assignment change revokes cached edit permission before save',$cachedPermission&&cnSave(1,100,'Нельзя после передачи',4)['error']==='forbidden');
$pdo->exec('UPDATE conversations SET manager_id=1 WHERE id=100');
$pdo->exec("UPDATE managers SET role='manager' WHERE id=3");
cnCheck('admin role revocation is rechecked on every write',cnSave(3,100,'Нельзя после снятия роли',4)['error']==='forbidden');
$pdo->exec("UPDATE managers SET role='admin' WHERE id=3");
$pdo->exec("INSERT INTO messages VALUES(1,100,'inbound','customer','Synthetic message',NULL,'2026-10-14 02:00:00')");
cnCheck('new incoming message does not erase working name',ManagerCustomerNameService::get(1,100)['name']['working_name']==='Анна — семья');
cnCheck('original identity and transcript unchanged by rename',$pdo->query('SELECT display_name FROM customers WHERE id=20')->fetchColumn()==='Анна исходная'&&$pdo->query('SELECT text FROM messages WHERE id=1')->fetchColumn()==='Synthetic message'&&(int)$pdo->query('SELECT customer_id FROM conversations WHERE id=100')->fetchColumn()===10);
$rows=ManagerConversationService::list(1,'all',200,'*');$named=array_values(array_filter($rows,static fn($r)=>(int)$r['id']===100))[0];
cnCheck('canonical list returns effective and original names with version',$named['display_name']==='Анна — семья'&&$named['original_display_name']==='Анна обновлённая'&&(int)$named['customer_name_version']===4&&(int)$named['customer_id']===10&&$named['can_edit_customer_name']);
$visible=ManagerConversationService::visibleConversation(100,1);
cnCheck('detail uses same identity projection',$visible['display_name']===$named['display_name']&&$visible['original_display_name']===$named['original_display_name']&&(int)$visible['customer_name_version']===4);
cnCheck('bounded Inbox search matches both names',count(ManagerLeadInboxService::filter([$named],'','Анна — семья'))===1&&count(ManagerLeadInboxService::filter([$named],'','Анна обновлённая'))===1);
$pdo->queries=0;ManagerConversationService::list(1,'all',200,'*');$smallCount=$pdo->queries;
$bulk=$pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');for($i=201;$i<399;$i++)$bulk->execute([$i,20,'anytour',0,'max','','','','manager','new',1,'2026-10-14 01:00:00','2026-10-14 01:00:00',null,0,'synthetic-'.$i]);
$pdo->queries=0;ManagerConversationService::list(1,'all',200,'*');$largeCount=$pdo->queries;
cnCheck('alias projection creates no per-row SQL as list grows',$smallCount===$largeCount);
$explain=$pdo->prepare('EXPLAIN QUERY PLAN '.$pdo->lastListSql);$explain->execute(['anytour','closed',1]);$plan=implode("\n",array_column($explain->fetchAll(PDO::FETCH_ASSOC),'detail'));
cnCheck('alias outer join uses its primary key instead of scanning labels',str_contains($plan,'SEARCH cn USING INTEGER PRIMARY KEY'));
// Compare the same final candidate query with only the new label projection
// removed. Both operate on the identical 200-row fixture; this is SQL evidence,
// not a historical production latency or a browser E2E measurement.
$baselineSql=str_replace(['cu.display_name AS original_display_name,cn.working_name,COALESCE(cn.version,0) AS customer_name_version,COALESCE(cn.working_name,cu.display_name) AS display_name,',' LEFT JOIN manager_customer_names cn ON cn.customer_id=c.customer_id'],['cu.display_name,',''],$pdo->lastListSql);
$baseline=$pdo->prepare($baselineSql);$baseline->execute(['anytour','closed',1]);$baselineIds=array_column($baseline->fetchAll(PDO::FETCH_ASSOC),'id');
$withLabels=$pdo->prepare($pdo->lastListSql);$withLabels->execute(['anytour','closed',1]);$withLabelIds=array_column($withLabels->fetchAll(PDO::FETCH_ASSOC),'id');
cnCheck('same candidate page is unchanged before and after indexed alias projection',$baselineIds===$withLabelIds&&count($withLabelIds)===200);
echo "MEASURED_SQL small={$smallCount} large={$largeCount} alias_rows=200; synthetic SQLite, not browser/production latency\n";
cnCheck('name validation respects Unicode length and rejects blank/control',ManagerCustomerNameService::normalize(str_repeat('я',120))['ok']&&ManagerCustomerNameService::normalize(str_repeat('я',121))['error']==='invalid_name'&&ManagerCustomerNameService::normalize("x\ny")['error']==='invalid_name'&&ManagerCustomerNameService::normalize(' ')['error']==='invalid_name');
// Actual HTTP endpoint and shared Manager authentication use an isolated fixture
// config/database/session directory. Nothing reads live config or sends a message.
$httpRoot=sys_get_temp_dir().'/manager-name-http-'.bin2hex(random_bytes(8));
mkdir($httpRoot,0700);mkdir($httpRoot.'/manager',0700);mkdir($httpRoot.'/sessions',0700);
$process=null;$httpDb=null;
try {
    $httpDb=new PDO('sqlite:'.$httpRoot.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $schema) $httpDb->exec($schema);
    foreach (['projects','manager_projects','managers','customers','conversations'] as $table) foreach ($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $q=$httpDb->prepare('INSERT INTO '.$table.' VALUES ('.implode(',',array_fill(0,count($row),'?')).')');$q->execute(array_values($row));
    }
    $sourceRoot=dirname(__DIR__);
    $config='<?php\nrequire_once '.var_export($sourceRoot.'/services/ManagerRequestContext.php',true).';\n'.
        '$fixturePdo=new PDO('.var_export('sqlite:'.$httpRoot.'/fixture.sqlite',true).',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);\n'.
        '(new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$fixturePdo);\n'.
        'ProjectConfig::resetForTests(["id"=>"anytour","brand"=>["name"=>"Fixture"]]);\nProjectAccessService::initializeReadOnly();\n';
    file_put_contents($httpRoot.'/config.php',str_replace('\\n',"\n",$config));
    copy($sourceRoot.'/manager/customer-name-api.php',$httpRoot.'/manager/customer-name-api.php');
    symlink($sourceRoot.'/manager/lib',$httpRoot.'/manager/lib');symlink($sourceRoot.'/services',$httpRoot.'/services');
    file_put_contents($httpRoot.'/manager/fixture-session.php','<?php require dirname(__DIR__)."/config.php";require __DIR__."/lib/ManagerHttp.php";ManagerHttp::startJson();$_SESSION["manager_id"]=(int)($_GET["id"]??1);$_SESSION["csrf"]="fixture-name-csrf";ManagerHttp::respond(["ok"=>true]);');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if (!$socket) throw new RuntimeException('Cannot allocate loopback fixture');
    $address=(string)stream_socket_get_name($socket,false);fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$httpRoot.'/sessions','-S',$address,'-t',$httpRoot],[0=>['pipe','r'],1=>['file',$httpRoot.'/server.log','a'],2=>['file',$httpRoot.'/server.log','a']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start loopback PHP');fclose($pipes[0]);
    $ready=false;for ($wait=0;$wait<200;$wait++) {$probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.05);if ($probe) {fclose($probe);$ready=true;break;}usleep(10000);}
    if (!$ready) throw new RuntimeException('Loopback PHP not ready');
    $request=static function(string $path,array $data=[],string $cookie='',string $method='POST') use ($address):array {
        $headers=['Content-Type: application/json'];if ($cookie!=='') $headers[]='Cookie: '.$cookie;
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$method==='POST'?json_encode($data,JSON_THROW_ON_ERROR):'','ignore_errors'=>true,'timeout'=>3]]);
        $body=file_get_contents('http://'.$address.$path,false,$context);$responseHeaders=$http_response_header??[];
        preg_match('/^HTTP\/[^ ]+ ([0-9]+)/',(string)($responseHeaders[0]??''),$status);$responseCookie='';
        foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie: (anytour_manager_panel=[^;]+)/i',$header,$match)) $responseCookie=$match[1];
        return ['status'=>(int)($status[1]??0),'body'=>json_decode((string)$body,true),'cookie'=>$responseCookie,'headers'=>$responseHeaders];
    };
    $path='/manager/customer-name-api.php';$read=['action'=>'get','conversation_id'=>100,'csrf'=>'fixture-name-csrf'];
    $anonymous=$request($path,$read);cnCheck('real name endpoint rejects anonymous with401',$anonymous['status']===401&&$anonymous['body']['error']==='unauthorized');
    $login=$request('/manager/fixture-session.php?id=1',[],'','GET');$cookie=$login['cookie'];
    $noCsrf=$request($path,['action'=>'get','conversation_id'=>100],$cookie);cnCheck('real name endpoint rejects missing CSRF with403',$noCsrf['status']===403&&$noCsrf['body']['error']==='csrf');
    $valid=$request($path,$read,$cookie);cnCheck('real authenticated name read succeeds',$valid['status']===200&&$valid['body']['name']['version']===0&&$valid['body']['name']['can_edit']);
    $command=['action'=>'save','conversation_id'=>100,'csrf'=>'fixture-name-csrf','working_name'=>'Synthetic HTTP name','version'=>0,'operation_id'=>'http-name-operation-00001'];
    $save=$request($path,$command,$cookie);cnCheck('real assigned manager save succeeds',$save['status']===200&&$save['body']['name']['version']===1&&$save['body']['name']['original_display_name']==='Анна обновлённая');
    $retry=$request($path,$command,$cookie);cnCheck('real HTTP lost response retry returns same receipt',$retry['body']===$save['body']&&(int)$httpDb->query('SELECT COUNT(*) FROM manager_customer_name_history')->fetchColumn()===1);
    $recovery=$request($path,['action'=>'operation','conversation_id'=>100,'csrf'=>'fixture-name-csrf','operation_id'=>$command['operation_id']],$cookie);
    cnCheck('real HTTP operation recovery returns committed receipt',$recovery['status']===200&&$recovery['body']['found']&&$recovery['body']['result']===$save['body']);
    $login=$request('/manager/fixture-session.php?id=2',[],$cookie,'GET');$cookie=$login['cookie']?:$cookie;
    $colleague=$request($path,$read,$cookie);cnCheck('real colleague can view name without write permission',$colleague['status']===200&&!$colleague['body']['name']['can_edit']);
    $forbidden=$request($path,array_replace($command,['version'=>1,'working_name'=>'Forbidden','operation_id'=>'http-forbidden-operation-01']),$cookie);
    cnCheck('real nonassigned manager write denied403',$forbidden['status']===403&&$forbidden['body']['error']==='forbidden');
    $login=$request('/manager/fixture-session.php?id=4',[],$cookie,'GET');$cookie=$login['cookie']?:$cookie;
    $hidden=$request($path,$read,$cookie);cnCheck('real foreign-project admin cannot read name404',$hidden['status']===404&&$hidden['body']['error']==='not_found');
    $login=$request('/manager/fixture-session.php?id=3',[],$cookie,'GET');$cookie=$login['cookie']?:$cookie;
    $restore=$request($path,array_replace($command,['version'=>1,'working_name'=>null,'operation_id'=>'http-restore-operation-001']),$cookie);
    cnCheck('real admin restores original with growing version',$restore['status']===200&&$restore['body']['name']['version']===2&&$restore['body']['name']['working_name']===null);
    $conflict=$request($path,array_replace($command,['version'=>1,'operation_id'=>'http-conflict-operation-01']),$cookie);
    cnCheck('real stale version gets409 with current state',$conflict['status']===409&&$conflict['body']['current']['version']===2);
    $get=$request($path,[],$cookie,'GET');cnCheck('real name endpoint rejects nonPOST405',$get['status']===405);
    $httpDb->exec('DROP TABLE manager_customer_names');
    $failure=$request($path,$read,$cookie);cnCheck('real missing storage reports503 instead of empty success',$failure['status']===503&&!$failure['body']['ok']&&$failure['body']['error']==='storage_unavailable');
} finally {
    if (is_resource($process)) {proc_terminate($process);proc_close($process);}
    $httpDb=null;
    $remove=static function(string $path) use (&$remove):void {if (is_link($path)||is_file($path)) {unlink($path);return;}if (is_dir($path)) {foreach (scandir($path)?:[] as $entry) if ($entry!=='.'&&$entry!=='..') $remove($path.'/'.$entry);rmdir($path);}};
    $remove($httpRoot);
}
echo "TOTAL {$passed} passed\n";
