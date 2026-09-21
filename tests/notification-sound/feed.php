<?php

declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ManagerIncomingNotificationService.php';

$count=0;
function check(string $name, $actual, $expected): void {
    global $count;
    if ($actual !== $expected) throw new RuntimeException($name.': '.json_encode(['actual'=>$actual,'expected'=>$expected]));
    $count++; echo 'PASS '.$name."\n";
}
$mysql=getenv('SOUND_MYSQL_TEST')==='1';
if($mysql){
    if(getenv('CI')!=='true')throw new RuntimeException('isolated_ci_database_only');
    // Fixed disposable CI service, never an application/config-derived database.
    define('CONVERSATION_DB_HOST','127.0.0.1');define('CONVERSATION_DB_NAME','sound_test');
    define('CONVERSATION_DB_USER','root');define('CONVERSATION_DB_PASS','sound-local-test-only');
    $pdo=ConversationDb::connection();
}else{$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
$pdo->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY,project_key VARCHAR(80),source_id INTEGER,status VARCHAR(30),manager_id INTEGER,is_test INTEGER,started_at VARCHAR(30),last_message_at VARCHAR(30))');
$pdo->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY'.($mysql?' AUTO_INCREMENT':'').',conversation_id INTEGER,direction VARCHAR(20),sender_type VARCHAR(20),metadata_json TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE manager_reads (conversation_id INTEGER PRIMARY KEY,last_read_message_id INTEGER)');
$pdo->exec('INSERT INTO manager_reads VALUES (1,0)');
$q=$pdo->prepare('INSERT INTO conversations VALUES (?,?,?,?,?,?,?,?)');
foreach([[1,'allowed',10,'manager',7,0],[2,'allowed',99,'manager',7,0],[3,'other-project',10,'manager',7,0],[4,'allowed',10,'manager',8,0],[5,'allowed',10,'waiting_manager',null,0],[6,'allowed',10,'manager',7,1],[7,'allowed',10,'closed',7,0]] as $row)$q->execute(array_merge($row,['2026-09-21 10:00:00','2026-09-21 10:00:00']));
$q=$pdo->prepare('INSERT INTO messages (id,conversation_id,direction,sender_type,metadata_json) VALUES (?,?,?,?,?)');
foreach([
 [1,1,'inbound','customer','{"type":"message"}'],[2,1,'inbound','customer','{"type":"message","attachments":[{"type":"image","token":"private-fixture-token"}]}'],
 [3,2,'inbound','customer','{"type":"message"}'],[4,3,'inbound','customer','{"type":"message"}'],[5,4,'inbound','customer','{"type":"message"}'],
 [6,1,'inbound','customer','{"type":"callback"}'],[7,1,'inbound','customer','{"type":"bot_started"}'],[8,1,'outbound','manager','{}'],[9,1,'outbound','ai','{}'],
 [10,5,'inbound','customer','{"type":"message"}'],[11,6,'inbound','customer','{"type":"message"}'],[12,7,'inbound','customer','{"type":"message"}'],
 [13,1,'inbound','customer','invalid JSON'],[14,1,'inbound','customer','{"type":"contact"}'],[15,1,'inbound','customer','{"type":"start"}'],[16,1,'inbound','customer',null],
] as $row)$q->execute($row);
$checked=[];$allowed=static function(array $row)use(&$checked):bool{$checked[]=$row;return(int)$row['source_id']!==99;};
$poll=static fn(?int $cursor,array $keys=['allowed'])=>ManagerIncomingNotificationService::collect($pdo,7,$keys,$cursor,$allowed);
$before=$pdo->query('SELECT * FROM manager_reads')->fetchAll(PDO::FETCH_ASSOC);
$result=$poll(null);
check('baseline returns the current window for silent client marking',array_column($result['events'],'message_id'),[1,2,10,14,16]);
check('high-water is no longer used to exclude recently committed lower IDs',$poll(999)['events'],$result['events']);
check('response exposes only exact event IDs',array_keys($result['events'][0]),['conversation_id','message_id']);
check('attachment token cannot escape',strpos(json_encode($result),'private-fixture-token')===false,true);
check('projects/owners/test/closed rows never reach access callback',array_values(array_unique(array_column($checked,'id'))),[1,2,5]);
check('access callback receives conversation ID',$checked[0]['id'],1);
check('same cursor reconciles the window instead of assuming commit order',$poll(16)['events'],$result['events']);
check('no project access reveals no event IDs or global high-water',$poll(0,[]),['manager_id'=>7,'cursor'=>0,'events'=>[],'has_more'=>false]);
check('poll never marks conversations read',$pdo->query('SELECT * FROM manager_reads')->fetchAll(PDO::FETCH_ASSOC),$before);
$pdo->exec("UPDATE conversations SET manager_id=8 WHERE id=1");
check('reassignment immediately removes own events',array_column($poll(0)['events'],'message_id'),[10]);
$pdo->exec('UPDATE conversations SET manager_id=7 WHERE id=1');
$pdo->exec("UPDATE messages SET created_at='2000-01-01 00:00:00' WHERE id=1");
check('old history is outside reconciliation window',in_array(1,array_column($poll(0)['events'],'message_id'),true),false);
$pdo->exec('DELETE FROM messages');
for($i=1;$i<=500;$i++)$q->execute([$i,1,'inbound','customer','{"type":"message"}']);
check('full bounded window is returned',count($poll(0)['events']),500);
$q->execute([501,1,'inbound','customer','{}']);$overflow=false;
try{$poll(0);}catch(RuntimeException $e){$overflow=$e->getMessage()==='notification_window_overflow';}
check('overflow is an explicit error not a lost tail',$overflow,true);
$pdo->exec('DELETE FROM messages');
foreach([-1,9007199254740992] as $bad){$rejected=false;try{$poll($bad);}catch(InvalidArgumentException $e){$rejected=true;}check('invalid cursor rejected '.$bad,$rejected,true);}
if($mysql){
    $other=new PDO('mysql:host=127.0.0.1;dbname=sound_test;charset=utf8mb4','root','sound-local-test-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->beginTransaction();
    $other->exec("INSERT INTO messages (conversation_id,direction,sender_type,metadata_json) VALUES (1,'inbound','customer','{}')");$lower=(int)$other->lastInsertId();
    $pdo->exec("INSERT INTO messages (conversation_id,direction,sender_type,metadata_json) VALUES (1,'inbound','customer','{}')");$higher=(int)$pdo->lastInsertId();
    check('real allocation order differs from visibility',$lower<$higher,true);
    $first=$poll(0);check('uncommitted lower ID is initially invisible',array_column($first['events'],'message_id'),[$higher]);
    $other->commit();check('late lower-ID commit remains in the next window',array_column($poll($higher)['events'],'message_id'),[$lower,$higher]);
    $pdo->exec('CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key VARCHAR(80),display_name VARCHAR(80),is_active INTEGER)');
    $pdo->exec("INSERT INTO projects VALUES(1,'allowed','fixture',1)");
    $pdo->exec('CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER)');$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
    $pdo->exec('CREATE TABLE managers(id INTEGER PRIMARY KEY,login VARCHAR(80),display_name VARCHAR(80),role VARCHAR(30),email VARCHAR(80),is_active INTEGER,is_working INTEGER)');
    $pdo->exec("INSERT INTO managers VALUES(7,'fixture','fixture','manager',NULL,1,1)");
    $pdo->exec('CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,primary_group_id INTEGER,fallback_mode VARCHAR(30),fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER)');
    ProjectConfig::resetForTests(['id'=>'allowed','brand'=>['name'=>'fixture']]);
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();ProjectAccessService::initializeReadOnly();
    check('actual active-manager auth works inside native READ ONLY',ManagerAuthService::byId(7)['id'],7);
    check('actual authorized feed works inside native READ ONLY',count(ManagerIncomingNotificationService::poll(7,0)['events']),2);
    $rejected=false;try{$pdo->exec("UPDATE managers SET is_working=0 WHERE id=7");}catch(PDOException $e){$rejected=true;}
    check('database itself rejects accidental writes',$rejected,true);$pdo->rollBack();
    check('working status unchanged',(int)$pdo->query('SELECT is_working FROM managers WHERE id=7')->fetchColumn(),1);
    $pdo->exec('DELETE FROM manager_projects');check('revoked project loses all events',ManagerIncomingNotificationService::poll(7,0)['events'],[]);
    $pdo->exec('INSERT INTO manager_projects VALUES(7,1)');$pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');
    check('disabled manager fails canonical authentication',ManagerAuthService::byId(7),null);$pdo->exec('UPDATE managers SET is_active=1 WHERE id=7');
}
$endpoint=file_get_contents(dirname(__DIR__,2).'/manager/notification-events.php');
check('endpoint enforces auth and CSRF',str_contains($endpoint,'ManagerHttp::requireManager()')&&str_contains($endpoint,'ManagerHttp::requireCsrf($data)'),true);
check('no detail or mark-read side effect',!str_contains($endpoint,'::detail(')&&!str_contains(file_get_contents(dirname(__DIR__,2).'/services/ManagerIncomingNotificationService.php'),'markRead'),true);
echo "TOTAL $count PASSED\n";
