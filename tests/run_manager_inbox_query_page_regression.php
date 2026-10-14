<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/ManagerConversationService.php';

/** Run the real service SQL; adapt only MySQL scalar syntax for SQLite. */
final class InboxQueryPdo extends PDO
{
    public PDO $database;
    public int $annotations=0;
    public string $lastListSql='';
    public array $lastListArgs=[];
    public function __construct()
    {
        $this->database=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->database->sqliteCreateFunction('NOW',static fn():string=>'2026-10-14 12:00:00');
        $this->database->sqliteCreateFunction('TIMESTAMPDIFF',static fn($unit,$from,$to)=>$from!==null?strtotime((string)$to)-strtotime((string)$from):null);
        $this->database->sqliteCreateFunction('GREATEST',function($value,$minimum){$this->annotations++;return max((int)$value,(int)$minimum);});
        $this->database->sqliteCreateFunction('JSON_UNQUOTE',static fn($value)=>$value);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        $isList=str_starts_with($query,'SELECT c.id,c.project_key');
        $sqliteSql=str_replace('TIMESTAMPDIFF(SECOND,',"TIMESTAMPDIFF('SECOND',",$query);
        return new InboxQueryStatement($this->database->prepare($sqliteSql),$this,$isList?$query:null);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{$statement=$this->prepare($query);$statement->execute();return$statement;}
}

final class InboxQueryStatement extends PDOStatement
{
    public function __construct(private PDOStatement $statement,private InboxQueryPdo $database,private ?string $listSql){}
    public function execute(?array $params=null):bool
    {
        if($this->listSql!==null){$this->database->lastListSql=$this->listSql;$this->database->lastListArgs=$params??[];}
        return$this->statement->execute($params);
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return$this->statement->fetch($mode,$orientation,$offset);}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return$this->statement->fetchAll($mode,...$args);}
    public function fetchColumn(int $column=0):mixed{return$this->statement->fetchColumn($column);}
}

$pdo=new InboxQueryPdo();$db=$pdo->database;
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)(new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$db->exec("CREATE TABLE projects(id INTEGER PRIMARY KEY,project_key TEXT COLLATE NOCASE UNIQUE,display_name TEXT,is_active INTEGER);
CREATE TABLE manager_projects(manager_id INTEGER,project_id INTEGER);
CREATE TABLE managers(id INTEGER PRIMARY KEY,login TEXT,display_name TEXT,role TEXT,email TEXT,is_active INTEGER,is_working INTEGER);
CREATE TABLE customers(id INTEGER PRIMARY KEY,display_name TEXT);
CREATE TABLE manager_customer_names(customer_id INTEGER PRIMARY KEY,working_name TEXT,version INTEGER,updated_by_manager_id INTEGER,updated_at TEXT);
CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT COLLATE NOCASE,source_id INTEGER,channel TEXT,entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status TEXT,lead_stage_key TEXT,manager_id INTEGER,started_at TEXT,last_message_at TEXT,closed_at TEXT,is_test INTEGER);
CREATE TABLE conversation_sources(id INTEGER PRIMARY KEY,display_name TEXT,primary_group_id INTEGER,fallback_mode TEXT,fallback_group_id INTEGER,fallback_after_minutes INTEGER,is_active INTEGER);
CREATE TABLE manager_group_members(manager_id INTEGER,group_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,created_at TEXT,payload_json TEXT,actor_type TEXT);
CREATE INDEX event_conversation ON conversation_events(conversation_id,id);
CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,direction TEXT,sender_type TEXT,text TEXT,metadata_json TEXT,created_at TEXT);
CREATE INDEX message_conversation ON messages(conversation_id,id);
CREATE TABLE manager_conversation_reads(manager_id INTEGER,conversation_id INTEGER,last_read_message_id INTEGER,PRIMARY KEY(manager_id,conversation_id));
CREATE TABLE lead_stages(stage_key TEXT PRIMARY KEY,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER,is_terminal INTEGER,is_won INTEGER);
CREATE TABLE lead_tags(id INTEGER PRIMARY KEY,tag_key TEXT,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER);
CREATE TABLE conversation_lead_tags(conversation_id INTEGER,tag_id INTEGER,PRIMARY KEY(conversation_id,tag_id));
CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY,conversation_id INTEGER,created_at TEXT);
INSERT INTO projects VALUES(1,'anytour','Fixture',1),(2,'other','Other',1);
INSERT INTO manager_projects VALUES(7,1);
INSERT INTO managers VALUES(7,'fixture','Fixture','admin','',1,1),(8,'other','Other','manager','',1,1);
INSERT INTO customers VALUES(1,'Fixture customer');
INSERT INTO lead_stages VALUES('new','New','blue',1,1,0,0),('quote','Quote','blue',2,1,0,0);
INSERT INTO lead_tags VALUES(1,'tag1','Tag 1','blue',1,1);
INSERT INTO conversation_sources VALUES(1,'Allowed',0,'none',0,0,1),(2,'Restricted',101,'none',0,0,1);");
$insert=$db->prepare('INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,lead_stage_key,manager_id,started_at,last_message_at,is_test) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
$message=$db->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?,?)');
$event=$db->prepare("INSERT INTO conversation_events(id,conversation_id,event_type,created_at) VALUES(?,?,'waiting_manager',?)");
$read=$db->prepare('INSERT INTO manager_conversation_reads VALUES(7,?,?)');
$tag=$db->prepare('INSERT INTO conversation_lead_tags VALUES(?,1)');
$fixtures=[];$base=strtotime('2026-10-13 00:00:00');
$db->beginTransaction();
for($id=1;$id<=5000;$id++){
    $at=gmdate('Y-m-d H:i:s',$base+$id);
    $row=['id'=>$id,'customer_id'=>1,'project_key'=>$id%17===0?'other':'anytour','source_id'=>1,'channel'=>'max','status'=>$id<=2500?['ai','manager','waiting_manager'][$id%3]:'closed','lead_stage_key'=>$id%2===0?'quote':'new','manager_id'=>$id%3===0?8:($id%3===1?7:null),'started_at'=>$at,'last_message_at'=>$id%13===0?null:$at,'is_test'=>$id%19===0?1:0];
    // Put customer-less candidates near the head, where capping before the JOIN loses rows.
    if($id===2499||$id===4999)$row['customer_id']=99999;
    $fixtures[$id]=$row;
    $insert->execute(array_values($row));
    $message->execute([$id*2,$id,'inbound','customer','older-'.$id,'{"type":"message"}',$at]);
    $message->execute([$id*2+1,$id,'inbound','customer','latest-'.$id,'{"type":"message"}',$at]);
    if($id%7===0)$event->execute([$id,$id,$at]);
    if($id%5===0)$read->execute([$id,$id*2]);
    if($id%4===0)$tag->execute([$id]);
}
$db->commit();
$passed=0;$failed=0;
function queryPageCheck(string $name,bool $ok):void{global$passed,$failed;if($ok){$passed++;echo "PASS {$name}\n";}else{$failed++;echo "FAIL {$name}\n";}}
function queryPageExpected(array $fixtures,string $queue,int $limit=200,string $managerFilter='',string $stage='',int $tag=0,bool $admin=true,bool $restricted=false):array
{
    $rows=array_values(array_filter($fixtures,static function(array $row)use($queue,$managerFilter,$stage,$tag,$admin):bool{
        if($row['is_test']||$row['customer_id']!==1||strcasecmp($row['project_key'],'anytour')!==0)return false;
        if(($queue==='closed')!==($row['status']==='closed'))return false;
        if(!$admin&&$queue!=='closed'&&$row['manager_id']!==null&&$row['manager_id']!==7)return false;
        if($managerFilter==='unassigned'&&$row['manager_id']!==null)return false;
        if(($managerFilter==='mine'||$managerFilter==='7')&&$row['manager_id']!==7)return false;
        if($managerFilter==='8'&&$row['manager_id']!==8)return false;
        if($stage!==''&&$row['lead_stage_key']!==$stage)return false;
        if($tag>0&&$row['id']%4!==0)return false;
        return true;
    }));
    usort($rows,static fn(array $a,array $b):int=>strcmp($b['last_message_at']??$b['started_at'],$a['last_message_at']??$a['started_at']));
    $rows=array_slice($rows,0,200);
    // Preserve legacy routing visibility after selecting the page, without backfilling.
    if($restricted)$rows=array_values(array_filter($rows,static fn(array $row):bool=>$row['source_id']!==2));
    return array_column(array_slice($rows,0,$limit),'id');
}

foreach(['all','closed'] as $queue){
    $pdo->annotations=0;$rows=ManagerConversationService::list(7,$queue,200,'*');$calls=$pdo->annotations;
    queryPageCheck("{$queue} returns the exact newest customer-backed non-test accessible-project page",array_column($rows,'id')===queryPageExpected($fixtures,$queue));
    queryPageCheck("{$queue} annotates no more than the selected 200 candidates",$calls<=200);
    echo "EVIDENCE queue={$queue} eligible_candidates>200 projected_rows=".count($rows)." annotation_calls={$calls}\n";
    foreach($rows as $row){$id=(int)$row['id'];if(!str_ends_with((string)$row['last_text'],'latest-'.$id)||$row['last_direction']!=='inbound'||$row['last_sender_type']!=='customer'||((int)$row['unread_count']!==($id%5===0?1:2)))throw new RuntimeException('message/read projection changed');}
    queryPageCheck("{$queue} preserves latest messages and unread projection",true);
    $requestRows=array_filter($rows,static fn(array $row):bool=>(int)$row['id']%7===0);
    queryPageCheck("{$queue} preserves manager request timestamps",(bool)$requestRows&&!array_filter($requestRows,static fn(array $row):bool=>$row['manager_request_at']!==($fixtures[(int)$row['id']]['last_message_at']??$fixtures[(int)$row['id']]['started_at'])));
}

foreach([['all',37,'','',0],['closed',100,'unassigned','',0],['all',200,'mine','',0],['closed',200,'8','',0],['all',200,'7','quote',1],['closed',200,'','new',1]] as [$queue,$limit,$managerFilter,$stage,$tagId]){
    $rows=ManagerConversationService::list(7,$queue,$limit,'anytour',$managerFilter,$stage,$tagId);
    queryPageCheck("{$queue} preserves limit/owner/stage/tag filters ({$limit},{$managerFilter},{$stage},{$tagId})",array_column($rows,'id')===queryPageExpected($fixtures,$queue,$limit,$managerFilter,$stage,$tagId));
}

$db->exec("UPDATE managers SET role='manager' WHERE id=7");
$db->exec('UPDATE conversations SET source_id=2 WHERE id>2250 AND id%2=0');
foreach($fixtures as &$row){if($row['id']>2250&&$row['id']%2===0)$row['source_id']=2;}unset($row);
$rows=ManagerConversationService::list(7,'all',200,'*');$expected=queryPageExpected($fixtures,'all',200,'','',0,false,true);
queryPageCheck('ordinary ownership and source visibility remain post-page without backfill',array_column($rows,'id')===$expected&&count($rows)<200);
$rows=ManagerConversationService::list(7,'closed',200,'*');
queryPageCheck('ordinary archive ownership/source visibility preserves the capped candidate window',array_column($rows,'id')===queryPageExpected($fixtures,'closed',200,'','',0,false,true));

// Semantic queues still need their original request/reply ranking before the cap.
$db->exec("UPDATE managers SET role='admin' WHERE id=7");
foreach(['waiting','attention','requested','mine'] as $queue){
    $rows=ManagerConversationService::list(7,$queue,200,'*');
    queryPageCheck("{$queue} includes real fixture leads",count($rows)>0);
    queryPageCheck("{$queue} retains its original semantic query path",!str_contains($pdo->lastListSql,'inbox_page'));
}

// The working New queue is personal even for an administrator. Administrative
// All/attention views remain available; this selection must never reassign leads.
require_once dirname(__DIR__).'/services/ManagerQueueProjectionService.php';
$db->exec('DELETE FROM conversations; DELETE FROM messages; DELETE FROM conversation_events; DELETE FROM manager_conversation_reads; DELETE FROM conversation_lead_tags; DELETE FROM lead_stage_history');
foreach([[101,null,'waiting_manager',1],[102,7,'manager',2],[103,8,'manager',3],[104,7,'manager',1]] as [$id,$owner,$status,$unread]){
    $at='2026-10-14 10:00:'.str_pad((string)($id-100),2,'0',STR_PAD_LEFT);
    $insert->execute([$id,1,'anytour',1,'max',$status,'new',$owner,$at,$at,0]);
    $event->execute([$id,$id,$at]);
    for($i=0;$i<$unread;$i++)$message->execute([$id*10+$i,$id,'inbound','customer','queue fixture','{"type":"message"}',$at]);
}
$message->execute([1049,104,'outbound','manager','answered','{}','2026-10-14 10:01:00']);
$queueSnapshot=static fn():array=>[
    $db->query('SELECT * FROM conversations ORDER BY id')->fetchAll(),
    $db->query('SELECT * FROM messages ORDER BY id')->fetchAll(),
    $db->query('SELECT * FROM conversation_events ORDER BY id')->fetchAll(),
    $db->query('SELECT * FROM manager_conversation_reads ORDER BY manager_id,conversation_id')->fetchAll(),
];
$beforeQueueReads=$queueSnapshot();
foreach(['admin','manager'] as $role){
    $db->prepare('UPDATE managers SET role=? WHERE id=7')->execute([$role]);
    $new=ManagerConversationService::list(7,'waiting',200,'anytour');
    queryPageCheck("{$role} New contains only unassigned and own unanswered leads",array_column($new,'id')===[101,102]);
    $counts=ManagerQueueProjectionService::counts(7,'anytour');
    queryPageCheck("{$role} New count matches its personal list",$counts['waiting']['count']===count($new)&&$counts['waiting']['count']===2);
    queryPageCheck("{$role} New unread counts only unassigned customer messages",$counts['waiting']['unread']===1);
    queryPageCheck("{$role} Mine remains own assignments including answered leads",$counts['mine']===['count'=>2,'unread'=>3]);
    queryPageCheck("{$role} notification badge excludes another manager's New lead",$counts['notification_unread']===4);
    queryPageCheck("{$role} queue reads preserve ownership, transcript, events and read state",$queueSnapshot()===$beforeQueueReads);
    $all=ManagerConversationService::list(7,'all',200,'anytour');
    queryPageCheck("{$role} All keeps its existing role visibility",array_column($all,'id')===($role==='admin'?[104,103,102,101]:[104,102,101]));
    $attention=ManagerConversationService::list(7,'attention',200,'anytour');
    queryPageCheck("{$role} attention keeps its existing role visibility",array_column($attention,'id')===($role==='admin'?[101,102,103]:[101,102]));
}
$db->exec("UPDATE managers SET role='admin' WHERE id=7");
queryPageCheck('admin All still filters another manager without altering assignments',array_column(ManagerConversationService::list(7,'all',200,'anytour','8'),'id')===[103]);
queryPageCheck('admin attention still permits another-manager oversight',array_column(ManagerConversationService::list(7,'attention',200,'anytour','8'),'id')===[103]);
queryPageCheck('admin manager filter cannot make another assignment a personal New lead',ManagerConversationService::list(7,'waiting',200,'anytour','8')===[]);
queryPageCheck('admin requested history remains a separate shared projection',ManagerQueueProjectionService::counts(7,'anytour')['requested']===['count'=>4,'unread'=>7]);
queryPageCheck('all queue projections remain read-only',$queueSnapshot()===$beforeQueueReads);

echo 'TOTAL '.($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
