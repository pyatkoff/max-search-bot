<?php
declare(strict_types=1);
define('PRIVATE_CONVERSATION_CORPUS_TESTING',true);
require_once dirname(__DIR__).'/tools/export_private_conversation_corpus.php';

$passed=0; $failed=0;
function corpusAssert(bool $condition,string $name): void {
    global $passed,$failed;
    if ($condition) { $passed++; echo 'PASS '.$name."\n"; }
    else { $failed++; echo 'FAIL '.$name."\n"; }
}

final class CorpusTestStatement extends PDOStatement {
    private CorpusTestPdo $owner; private string $sql; private array $rows=[]; private int $offset=0;
    public function __construct(CorpusTestPdo $owner,string $sql) { $this->owner=$owner; $this->sql=$sql; }
    public function execute(?array $params=null): bool { $this->rows=$this->owner->select($this->sql,$params??[]); $this->offset=0; return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed { return $this->rows[$this->offset++]??false; }
    public function closeCursor(): bool { $this->rows=[]; return true; }
}

final class CorpusTestPdo extends PDO {
    public array $data; public array $commands=[]; public bool $active=false; public ?string $mismatch=null; public bool $badEngine=false;
    public function __construct(array $data) { $this->data=$data; }
    public function getAttribute(int $attribute): mixed { if ($attribute===PDO::ATTR_DRIVER_NAME) return 'mysql'; throw new PDOException('unsupported synthetic attribute read'); }
    public function setAttribute(int $attribute,mixed $value): bool { return true; }
    public function inTransaction(): bool { return $this->active; }
    public function exec(string $statement): int|false {
        $this->commands[]=$statement;
        if ($statement==='START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY') $this->active=true;
        elseif ($statement!=='SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') throw new RuntimeException('unexpected write');
        return 0;
    }
    public function rollBack(): bool { $this->commands[]='ROLLBACK'; $this->active=false; return true; }
    public function prepare(string $query,array $options=[]): PDOStatement|false { $this->commands[]=$query; return new CorpusTestStatement($this,$query); }
    public function select(string $sql,array $params): array {
        if (str_contains($sql,'information_schema.TABLES')) return array_map(fn($name)=>['TABLE_NAME'=>$name,'ENGINE'=>$this->badEngine?'MyISAM':'InnoDB'],$params);
        $kind=str_contains($sql,'FROM messages m')?'messages':(str_contains($sql,'FROM conversation_events e')?'events':(str_contains($sql,'FROM manager_assignments a')?'assignments':(str_contains($sql,'FROM managers g')?'managers':'conversations')));
        // Manager predicate contains nested FROMs; the outer table is authoritative.
        if (preg_match('/\bFROM managers g WHERE\b/',$sql)) $kind='managers';
        $rows=$this->data[$kind];
        if ($kind!=='managers') {
            $scope=$params[0]; $conversations=[];
            foreach ($this->data['conversations'] as $row) $conversations[$row['id']]=$row;
            $rows=array_values(array_filter($rows,static fn($row)=>($kind==='conversations'?$row['project_key']:($conversations[$row['conversation_id']]['project_key']??''))===$scope));
        }
        if (str_starts_with($sql,'SELECT COUNT(*)')) return [['n'=>count($rows)+($this->mismatch===$kind?1:0),'upper_id'=>$rows?max(array_column($rows,'id')):0]];
        $last=$params[count($params)-2]; $upper=$params[count($params)-1];
        $rows=array_values(array_filter($rows,static fn($row)=>$row['id']>$last && $row['id']<=$upper));
        usort($rows,static fn($a,$b)=>$a['id']<=>$b['id']);
        return array_slice($rows,0,200);
    }
}

function corpusConversation(int $id,string $channel='max',string $status='manager',bool $test=false,string $project='anytour'): array {
    return ['id'=>$id,'project_key'=>$project,'customer_id'=>$id+1000,'channel'=>$channel,'entry_channel'=>'max_anytour_msk',
        'attribution_region'=>'213','attribution_campaign'=>'714260445','status'=>$status,'is_test'=>$test?1:0,'test_source'=>$test?'synthetic':null,
        'manager_id'=>2,'started_at'=>'2026-10-01 10:00:00','last_message_at'=>'2026-10-17 10:00:00','closed_at'=>$status==='closed'?'2026-10-16 12:00:00':null,
        'source_id'=>1,'source_key'=>'max:anytour-main','lead_outcome'=>null,'lead_close_reason'=>null,'lead_sale_amount'=>null,'lead_sale_date'=>null,'lead_outcome_updated_at'=>null,'lead_outcome_manager_id'=>null];
}
function corpusMessage(int $id,int $conversation=1,string $sender='customer',?string $senderId=null): array {
    return ['id'=>$id,'conversation_id'=>$conversation,'direction'=>$sender==='customer'?'inbound':'outbound','sender_type'=>$sender,'sender_id'=>$senderId,
        'channel'=>$conversation===2?'telegram':'max','text'=>'Полный запрос на 9–11 ночей, до 350000 за всех. Дети 12,9,1. 15.11–20.12.2026.',
        'metadata_json'=>'{"type":"message"}','created_at'=>'2026-10-17 10:00:00'];
}
function corpusFixture(): array {
    $messages=[]; for ($i=1;$i<=405;$i++) $messages[]=corpusMessage($i,$i%2===0?2:1);
    $messages[0]['text']=str_repeat('Большое сообщение без обрезки. ',1000);
    $messages[0]['metadata_json']=json_encode(['type'=>'message','attachments'=>[['type'=>'image','url'=>'https://secret/?token=DO_NOT_EXPORT','file_id'=>'PRIVATE_FILE','name'=>'passport.jpg']],
        'username'=>'PRIVATE_LOGIN','token'=>'PRIVATE_TOKEN','linked_message'=>['type'=>'reply','text'=>'Сравните с первым вариантом','sender_name'=>'PRIVATE_PERSON']]);
    $messages[1]=corpusMessage(2,2,'manager','1');
    $messages[2]=corpusMessage(3,1,'manager','2');
    $messages[3]=corpusMessage(4,2,'manager',null);
    $messages[]=corpusMessage(500,99); $messages[count($messages)-1]['text']='FOREIGN_PROJECT_TEXT';
    return ['conversations'=>[corpusConversation(1),corpusConversation(2,'telegram','closed'),corpusConversation(3,'website','ai',true),corpusConversation(99,'max','ai',false,'other-project')],
        'messages'=>$messages,
        'events'=>[['id'=>1,'conversation_id'=>1,'event_type'=>'manager_reassigned','actor_type'=>'manager','actor_id'=>'2','created_at'=>'2026-10-17 10:00:00',
            'payload_json'=>'{"from_manager_id":1,"to_manager_id":2,"token":"PRIVATE_TOKEN","customer_phone":"PRIVATE_PHONE","campaign_id":"714260445"}']],
        'assignments'=>[['id'=>1,'conversation_id'=>1,'manager_id'=>1,'assignment_type'=>'manual','assigned_at'=>'2026-10-01 10:00:00','released_at'=>'2026-10-17 10:00:00'],
            ['id'=>2,'conversation_id'=>1,'manager_id'=>2,'assignment_type'=>'admin_reassign','assigned_at'=>'2026-10-17 10:00:00','released_at'=>null]],
        'managers'=>[['id'=>1,'role'=>'manager','is_active'=>1,'display_name'=>'Первый менеджер'],['id'=>2,'role'=>'admin','is_active'=>1,'display_name'=>'Второй менеджер']]];
}
function corpusRun(CorpusTestPdo $pdo): array {
    $lines=[]; $collector=new PrivateConversationCorpus($pdo,'anytour',static function($line)use(&$lines){$lines[]=$line;});
    $ok=$collector->run(str_repeat('a',40));
    return [$ok,array_map(static fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),$lines),implode('',$lines)];
}

$pdo=new CorpusTestPdo(corpusFixture()); [$ok,$records,$raw]=corpusRun($pdo);
$header=$records[0]; $footer=$records[count($records)-1];
$byType=[]; foreach ($records as $record) $byType[$record['type']][]=$record;
corpusAssert($ok && $footer['complete']===true,'complete corpus succeeds');
corpusAssert($header['schema']==='private_conversation_corpus_v1' && $header['project_scope']===['anytour'],'private version and project scope');
corpusAssert($footer['expected_counts']===$footer['exported_counts'] && $header['expected_counts']===$footer['exported_counts'],'manifest reconciles every table');
corpusAssert(count($byType['message'])===405,'all message pages exported beyond 200 and 400');
corpusAssert(count($byType['conversation'])===3 && !str_contains($raw,'FOREIGN_PROJECT_TEXT'),'foreign project excluded');
corpusAssert(array_column($byType['conversation'],'channel')===['max','telegram','website'],'all recorded channels preserved');
corpusAssert($byType['conversation'][1]['status']==='closed' && $byType['conversation'][2]['is_test']===true,'closed and explicit test conversations included');
corpusAssert($byType['message'][0]['text']===str_repeat('Большое сообщение без обрезки. ',1000),'long text preserved without 280 character truncation');
corpusAssert(!str_contains($raw,'PRIVATE_') && !str_contains($raw,'passport.jpg') && !str_contains($raw,'secret/?token'),'arbitrary metadata and contact profiles omitted');
corpusAssert($byType['message'][0]['metadata']['linked_message']['text']==='Сравните с первым вариантом','reply text retained');
corpusAssert($byType['message'][1]['manager_ref']===$byType['manager'][0]['id'] && $byType['message'][2]['manager_ref']===$byType['manager'][1]['id'],'message author follows recorded sender after reassignment');
corpusAssert($byType['message'][1]['manager_ref']!==$byType['conversation'][1]['manager_ref'],'historical author never replaced with current owner');
corpusAssert($byType['message'][1]['source_locator']['manager_id']===1 && $byType['message'][1]['source_locator']['conversation_id']===2 && $byType['manager'][0]['display_name']==='Первый менеджер','private internal locators retain real panel and manager attribution');
corpusAssert($byType['message'][3]['manager_ref']===null && $byType['message'][3]['sender_attribution']==='missing','missing manager sender is explicit');
corpusAssert($byType['assignment'][0]['manager_ref']===$byType['manager'][0]['id'] && $byType['event'][0]['metadata']['from_manager_ref']===$byType['manager'][0]['id'],'assignment and event aliases correlate');
corpusAssert($footer['statistics']['conversations_by_test']===['business'=>2,'test'=>1] && $footer['statistics']['attachments_by_type']['image']===1,'test and media counters present');
corpusAssert($pdo->commands[0]==='SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' && $pdo->commands[1]==='START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' && end($pdo->commands)==='ROLLBACK','consistent read only transaction encloses entire export');
corpusAssert(!array_filter($pdo->commands,static fn($sql)=>preg_match('/^(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE|TRUNCATE)\b/i',$sql)),'no business or schema writes');
$trip='15.11–20.12.2026, до 350000 за всех, дети 12,9,1, 9–11 ночей';
corpusAssert(PrivateConversationCorpus::privateText($trip)===$trip,'dates budgets ages and nights are not redacted');
$private=PrivateConversationCorpus::privateText('Телефон +7 (999) 123-45-67, 89991234567, 79991234567; user@example.ru; Bearer secretToken123; 123456789:ABCDEFGHIJKLMNOPQRSTUVWX');
corpusAssert(!str_contains($private,'999') && !str_contains($private,'example.ru') && !str_contains($private,'secretToken123') && !str_contains($private,'ABCDEFGHIJKLMNOP'),'recognizable contacts and credentials masked');
$pdo2=new CorpusTestPdo(corpusFixture()); [$ok2,$records2]=corpusRun($pdo2);
corpusAssert($byType['conversation'][0]['id']!==$records2[1]['id'],'pseudonyms change between captures');
$mismatch=new CorpusTestPdo(corpusFixture()); $mismatch->mismatch='messages'; [$bad,$badRecords]=corpusRun($mismatch);
corpusAssert(!$bad && end($badRecords)['complete']===false && end($badRecords)['error']==='count_mismatch' && !$mismatch->active,'missing rows fail closed and roll back');
$engine=new CorpusTestPdo(corpusFixture()); $engine->badEngine=true; [$badEngine,$engineRecords]=corpusRun($engine);
corpusAssert(!$badEngine && !$engineRecords && !$engine->active,'nontransactional tables refuse capture before header');
$oversized=new CorpusTestPdo(corpusFixture()); $oversized->data['messages'][0]['oversized_record']=1; [$badSize,$sizeRecords]=corpusRun($oversized);
corpusAssert(!$badSize && end($sizeRecords)['complete']===false && end($sizeRecords)['error']==='source_record_byte_limit' && end($sizeRecords)['exported_counts']['messages']===0,'oversized source record fails without text truncation or counting an omitted message');

// Optional native MySQL execution. CI supplies a dedicated empty throwaway DB.
// Never point this test at production: the explicit db-name guard is mandatory.
$nativeDsn=getenv('PRIVATE_CORPUS_MYSQL_DSN')?:'';
if ($nativeDsn!=='') {
    try {
        if (!preg_match('/(?:^|;)dbname=(private_corpus_test_[A-Za-z0-9_]+)(?:;|$)/D',$nativeDsn)) throw new RuntimeException('unsafe native fixture database');
        $user=getenv('PRIVATE_CORPUS_MYSQL_USER')?:'root'; $pass=getenv('PRIVATE_CORPUS_MYSQL_PASSWORD')?:'';
        $native=new PDO($nativeDsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        if (!preg_match('/^private_corpus_test_[A-Za-z0-9_]+$/D',(string)$native->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('unsafe actual fixture database');
        // Test-only diagnostic: identify unsupported attribute reads without
        // exposing exception messages, connection configuration or credentials.
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            try { $native->getAttribute(constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')); }
            catch (PDOException $e) {
                $state=preg_match('/^[A-Z0-9]{5}$/D',(string)$e->getCode())?(string)$e->getCode():'unknown';
                echo 'INFO native buffered-query attribute read unsupported; SQLSTATE='.$state."\n";
            }
        }
        $writer=new PDO($nativeDsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $schema=[
            'CREATE TABLE projects(id BIGINT PRIMARY KEY,project_key VARCHAR(64)) ENGINE=InnoDB',
            'CREATE TABLE conversation_sources(id BIGINT PRIMARY KEY,project_id BIGINT,source_key VARCHAR(96)) ENGINE=InnoDB',
            'CREATE TABLE managers(id BIGINT PRIMARY KEY,role VARCHAR(32),is_active INT,display_name VARCHAR(191)) ENGINE=InnoDB',
            'CREATE TABLE conversations(id BIGINT PRIMARY KEY,project_key VARCHAR(64),customer_id BIGINT,channel VARCHAR(32),entry_channel VARCHAR(64),attribution_region VARCHAR(64),attribution_campaign VARCHAR(64),status VARCHAR(32),is_test INT,test_source VARCHAR(64),manager_id BIGINT,started_at DATETIME,last_message_at DATETIME,closed_at DATETIME,source_id BIGINT,lead_outcome VARCHAR(32),lead_close_reason VARCHAR(64),lead_sale_amount DECIMAL(12,2),lead_sale_date DATE,lead_outcome_updated_at DATETIME,lead_outcome_manager_id BIGINT) ENGINE=InnoDB',
            'CREATE TABLE messages(id BIGINT PRIMARY KEY,conversation_id BIGINT,direction VARCHAR(16),sender_type VARCHAR(16),sender_id VARCHAR(191),channel VARCHAR(32),text MEDIUMTEXT,metadata_json LONGTEXT,created_at DATETIME,KEY(conversation_id,id)) ENGINE=InnoDB',
            'CREATE TABLE conversation_events(id BIGINT PRIMARY KEY,conversation_id BIGINT,event_type VARCHAR(64),actor_type VARCHAR(32),actor_id VARCHAR(191),payload_json LONGTEXT,created_at DATETIME) ENGINE=InnoDB',
            'CREATE TABLE manager_assignments(id BIGINT PRIMARY KEY,conversation_id BIGINT,manager_id BIGINT,assignment_type VARCHAR(32),assigned_at DATETIME,released_at DATETIME) ENGINE=InnoDB',
        ];
        foreach ($schema as $sql) $native->exec($sql);
        $native->exec("INSERT INTO projects VALUES(1,'anytour')"); $native->exec("INSERT INTO conversation_sources VALUES(1,1,'max:anytour-main')");
        $fixture=corpusFixture();
        foreach ($fixture as $kind=>$rows) {
            $table=['events'=>'conversation_events','assignments'=>'manager_assignments'][$kind]??$kind;
            foreach ($rows as $row) {
                if ($kind==='conversations') unset($row['source_key']);
                $columns=array_keys($row); $query=$native->prepare('INSERT INTO '.$table.'('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
                $query->execute(array_values($row));
            }
        }
        $lines=[]; $denied=false; $inserted=false;
        $collector=new PrivateConversationCorpus($native,'anytour',static function($line)use(&$lines,&$denied,&$inserted,$native,$writer){
            $lines[]=$line;
            if (!$inserted) {
                try { $native->exec("UPDATE conversations SET status='MUST_NOT_WRITE' WHERE id=1"); }
                catch (PDOException $e) { $denied=(int)($e->errorInfo[1]??0)===1792 || (string)$e->getCode()==='25006'; }
                $writer->exec("INSERT INTO conversations(id,project_key,customer_id,channel,status,is_test) VALUES(1000,'anytour',9000,'telegram','ai',0)");
                $inserted=true;
            }
        });
        $nativeOk=$collector->run(str_repeat('b',40));
        $nativeRecords=array_map(static fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),$lines);
        $nativeFooter=end($nativeRecords);
        corpusAssert($nativeOk && $denied,'native server rejects business writes inside readonly capture');
        corpusAssert($nativeFooter['complete']===true && $nativeFooter['exported_counts']['conversations']===3 && $nativeFooter['exported_counts']['messages']===405,'native stable snapshot excludes concurrent insert and reconciles all pages');
        corpusAssert((int)$writer->query("SELECT COUNT(*) FROM conversations WHERE status='MUST_NOT_WRITE'")->fetchColumn()===0,'native readonly capture leaves business rows intact');
    } catch (Throwable $e) {
        corpusAssert(false,'native MySQL regression setup or capture failed');
    }
}
echo 'TOTAL '.($passed+$failed).' | PASS '.$passed.' | FAIL '.$failed."\n";
exit($failed?1:0);
