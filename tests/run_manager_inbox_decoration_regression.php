<?php
declare(strict_types=1);

// Isolated actual PDO fixtures only; no application configuration or sends.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/services/ManagerLeadInboxService.php';

final class InboxDecorationStatement extends PDOStatement
{
    public static array $rows=[];
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        $rows=parent::fetchAll($mode,...$args);
        $kind=str_contains($this->queryString,'FROM messages')?'summaries':(str_contains($this->queryString,'lead_tasks')?'tasks':'other');
        self::$rows[$kind]=(self::$rows[$kind]??0)+count($rows);
        return$rows;
    }
}

$passed=0;
function decorationCheck(string $name,bool $ok): void
{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    ++$passed;echo 'PASS '.$name."\n";
}

$dsn=getenv('MANAGER_INBOX_DECORATION_MYSQL_DSN');$native=is_string($dsn)&&$dsn!=='';
if($native&&!preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=[0-9]{1,5};)?dbname=manager_inbox_decoration_test;charset=utf8mb4\z/D',$dsn))throw new RuntimeException('isolated_native_fixture_required');
$pdo=new PDO($native?$dsn:'sqlite::memory:',$native?getenv('MANAGER_INBOX_DECORATION_MYSQL_USER'):null,$native?getenv('MANAGER_INBOX_DECORATION_MYSQL_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($native){
    if($pdo->query('SHOW TABLES')->fetchAll())throw new RuntimeException('empty_native_fixture_required');
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    $pdo->exec("SET time_zone='+00:00'");
}else$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[InboxDecorationStatement::class]);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
$pdo->exec("CREATE TABLE customers(id INTEGER PRIMARY KEY,phone VARCHAR(100),email VARCHAR(100));
 CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,lead_outcome VARCHAR(20),lead_close_reason VARCHAR(50),lead_outcome_note TEXT,lead_sale_amount VARCHAR(30),lead_sale_date VARCHAR(20));
 CREATE TABLE messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,sender_type VARCHAR(20),direction VARCHAR(20),text TEXT,metadata_json TEXT);
 CREATE INDEX decoration_messages ON messages(conversation_id,id);
 CREATE TABLE lead_tasks(id INTEGER PRIMARY KEY,conversation_id INTEGER,title VARCHAR(255),due_at_utc DATETIME NULL,is_pinned INTEGER,status VARCHAR(20));
 CREATE INDEX decoration_tasks ON lead_tasks(conversation_id,status,is_pinned,due_at_utc,id);
 CREATE TABLE lead_close_reasons(reason_key VARCHAR(50),display_name VARCHAR(100),is_active INTEGER,sort_order INTEGER)");
$pdo->exec("INSERT INTO lead_close_reasons VALUES('other','Other',1,0)");
$customer=$pdo->prepare('INSERT INTO customers VALUES(?,?,?)');
$conversation=$pdo->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?)');
for($id=1;$id<=202;++$id){$customer->execute([$id,'phone-'.$id,'email-'.$id]);$conversation->execute([$id,$id,$id===3?'won':'open',null,null,null,null]);}
$insertMessage=$pdo->prepare('INSERT INTO messages VALUES(?,?,?,?,?,?)');$mid=0;
$summary=static fn(string $text)=>json_encode(['trip_summary'=>['version'=>1,'text'=>$text]],JSON_UNESCAPED_UNICODE);
// Long, interleaved histories have one current recognized snapshot per lead.
for($revision=0;$revision<40;++$revision)for($id=1;$id<=200;++$id)$insertMessage->execute([++$mid,$id,'ai','outbound','Snapshot',$summary('trip-'.$id.'-'.$revision)]);
$insertMessage->execute([++$mid,1,'ai','outbound','Empty is current',$summary('')]);
$insertMessage->execute([++$mid,2,'ai','outbound','✅ Готово! Проверьте параметры New legacy Что удобнее дальше?',null]);
for($i=0;$i<600;++$i)$insertMessage->execute([++$mid,2,'ai','outbound','Unsupported',json_encode(['trip_summary'=>['version'=>2,'text'=>'future']])]);
$insertMessage->execute([++$mid,2,'ai','outbound','Malformed','{"trip_summary":']);
$insertMessage->execute([++$mid,1,'customer','inbound','Готово! Проверьте параметры',$summary('customer-spoof')]);
$insertMessage->execute([++$mid,1,'manager','outbound','Готово! Проверьте параметры',$summary('manager-spoof')]);
$insertMessage->execute([++$mid,201,'ai','outbound','Foreign',$summary('foreign')]);
$insertMessage->execute([++$mid,202,'ai','outbound','Unsupported',json_encode(['trip_summary'=>['version'=>2,'text'=>'future']])]);
$insertTask=$pdo->prepare('INSERT INTO lead_tasks VALUES(?,?,?,?,?,?)');$tid=0;
for($id=1;$id<=200;++$id){
    for($i=0;$i<120;++$i)$insertTask->execute([++$tid,$id,'task-'.$id.'-'.$i,'2099-01-01 12:00:00',0,'open']);
    $insertTask->execute([++$tid,$id,'pinned-'.$id,'2099-02-01 12:00:00',1,'open']);
    $insertTask->execute([++$tid,$id,'urgent-'.$id,'2000-01-01 12:00:00',0,'open']);
    $insertTask->execute([++$tid,$id,'done-'.$id,'1990-01-01 12:00:00',1,'done']);
}
// Pinned unscheduled task remains the visible row; other deadlines stay urgent.
$insertTask->execute([++$tid,4,'pinned-without-date',null,1,'open']);
// Equal pinned deadlines choose the larger ID, including wholly unscheduled leads.
$insertTask->execute([++$tid,202,'no-date-older',null,1,'open']);
$lastTask=++$tid;$insertTask->execute([$lastTask,202,'no-date-newer',null,1,'open']);
$input=array_map(static fn(int $id)=>['id'=>$id,'display_name'=>'lead-'.$id,'project_key'=>'test','channel'=>'max','source_id'=>1],array_merge(range(1,200),[202]));
$beforeMessages=$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll();
$beforeTasks=$pdo->query('SELECT * FROM lead_tasks ORDER BY id')->fetchAll();
InboxDecorationStatement::$rows=[];$started=hrtime(true);$actual=ManagerLeadInboxService::decorate($input);$milliseconds=(hrtime(true)-$started)/1e6;$work=InboxDecorationStatement::$rows;
$byId=array_column($actual,null,'id');
decorationCheck('input order and cardinality are unchanged',array_column($actual,'id')===array_column($input,'id'));
decorationCheck('valid empty snapshot masks all older and spoofed messages',$byId[1]['trip_summary']==='');
decorationCheck('malformed and600unsupported candidates fall back across multiple pages',$byId[2]['trip_summary']==='New legacy');
decorationCheck('all other current summaries stay exact',$byId[200]['trip_summary']==='trip-200-39'&&$byId[3]['trip_summary']==='trip-3-39');
decorationCheck('unsupported-only history returns an empty summary',$byId[202]['trip_summary']==='');
decorationCheck('foreign summary never appears in authorized input',!isset($byId[201])&&!str_contains(json_encode($actual),'foreign'));
decorationCheck('open count includes all tasks and excludes completed ones',$byId[1]['open_task_count']===122&&$byId[4]['open_task_count']===123);
decorationCheck('pinned future task is visible while unpinned overdue task controls urgency',$byId[1]['next_task_title']==='pinned-1'&&$byId[1]['next_task_due_state']==='upcoming'&&$byId[1]['operational_task_due_state']==='overdue'&&$byId[1]['operational_task_due_at_utc']==='2000-01-01 12:00:00');
decorationCheck('scheduled pinned task precedes a newer unscheduled pinned task',$byId[4]['next_task_title']==='pinned-4');
decorationCheck('unscheduled pinned ties retain the newest ID and unscheduled urgency',$byId[202]['next_task_id']==$lastTask&&$byId[202]['next_task_title']==='no-date-newer'&&$byId[202]['operational_task_due_state']==='unscheduled');
decorationCheck('closed outcome demotes urgency but preserves task facts',$byId[3]['operational_task_due_state']==='closed'&&$byId[3]['operational_task_rank']===4&&$byId[3]['open_task_count']===122);
decorationCheck('existing contact and origin fields remain available',$byId[1]['contact_phone']==='phone-1'&&$byId[1]['contact_email']==='email-1'&&$byId[1]['origin_label']==='MAX · test');
decorationCheck('filters retain hidden overdue work and closed cleanup semantics',count(ManagerLeadInboxService::filter($actual,'','','overdue'))===199&&count(ManagerLeadInboxService::filter($actual,'','','pinned'))===201&&ManagerLeadInboxService::filter($actual,'','','none')===[]);
decorationCheck('decoration does not change messages or tasks',$beforeMessages===$pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll()&&$beforeTasks===$pdo->query('SELECT * FROM lead_tasks ORDER BY id')->fetchAll());
decorationCheck('only one task row per lead crosses PDO',($work['tasks']??PHP_INT_MAX)===201);
decorationCheck('historical summary transfer is bounded to current candidates and unresolved fallback',($work['summaries']??PHP_INT_MAX)<1200);

// Native CI and local paired runs load the exact pinned pre-change source.
$baselinePath=getenv('MANAGER_INBOX_DECORATION_BASELINE_FILE');
if(is_string($baselinePath)&&$baselinePath!==''){
    $source=(string)file_get_contents($baselinePath);
    if(hash('sha256',$source)!=='e1889687ac49eb3de917fd7b57eabd2184ad58761c6808491fcc8611ccc4451e')throw new RuntimeException('baseline_identity_mismatch');
    $source=preg_replace('/^require_once .*;\s*$/m','',$source);
    $source=str_replace('class ManagerLeadInboxService','class ManagerLeadInboxBeforeReadReduction',$source,$replacements);
    if($replacements!==1)throw new RuntimeException('baseline_class_mismatch');
    eval(substr($source,5));
    InboxDecorationStatement::$rows=[];$started=hrtime(true);$expected=ManagerLeadInboxBeforeReadReduction::decorate($input);$baselineMs=(hrtime(true)-$started)/1e6;$baselineWork=InboxDecorationStatement::$rows;
    decorationCheck('all decorated fields equal the exact installed baseline',$actual===$expected);
    decorationCheck('summary transfer falls by at least80percent on this exact fixture',$work['summaries']<$baselineWork['summaries']/5);
    decorationCheck('task transfer falls by at least99percent on this exact fixture',$work['tasks']<$baselineWork['tasks']/100);
    echo 'PAIRED_FIXTURE '.json_encode(['driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'leads'=>count($input),'baseline_rows'=>$baselineWork,'candidate_rows'=>$work,'baseline_ms'=>round($baselineMs,3),'candidate_ms'=>round($milliseconds,3),'sample_count'=>1,'production_speedup'=>null])."\n";
}elseif($native)throw new RuntimeException('native_paired_baseline_required');

echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
