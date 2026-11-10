<?php
declare(strict_types=1);
// Empty, explicitly named loopback fixture only. No provider or application database.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
if($dsn===''){echo "SKIP native reply queue MySQL fixture is not configured\n";exit(0);}
if(!preg_match('/\Amysql:host=127\.0\.0\.1;(?:port=[0-9]{1,5};)?dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?);charset=utf8mb4\z/D',$dsn,$match))throw new RuntimeException('Refusing non-loopback reply queue fixture database');
$user=(string)getenv('PREPARED_REPLY_MYSQL_USER');$pass=(string)getenv('PREPARED_REPLY_MYSQL_PASS');
if($user===''||$pass==='')throw new RuntimeException('Explicit synthetic MySQL credentials required');
require_once dirname(__DIR__).'/fixtures/manager_reply_queue_fixture.php';
final class ReplyQueueNativePdo extends PDO
{
    public $beforeDecision=null;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if($this->beforeDecision&&str_starts_with($query,'INSERT INTO conversation_events')){$hook=$this->beforeDecision;$this->beforeDecision=null;$hook();}
        return parent::prepare($query,$options);
    }
}
$pdo=new ReplyQueueNativePdo($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);sendAttemptBind($pdo);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$match[1])throw new RuntimeException('Unexpected fixture database');
$mode=$argv[1]??'';
if(in_array($mode,['--resolve','--revoke'],true)){
    if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==14||$pdo->query('SELECT login FROM managers WHERE id=7')->fetchColumn()!=='synthetic-owner')throw new RuntimeException('Refusing non-fixture worker');
    if($mode==='--resolve'){
        $actor=(int)($argv[2]??0);$op=(string)($argv[3]??'');if(!in_array($actor,[7,9],true)||!preg_match('/\Anative-race-[a-z0-9-]+\z/D',$op))throw new RuntimeException('Invalid synthetic worker');
        $view=ManagerReplyQueueService::snapshot($actor,101)['reply_queue'];$input=replyQueueInput($view,$op);
    }else{if(!in_array($argv[2]??'',['project','group'],true))throw new RuntimeException('Invalid synthetic revoke worker');}
    echo "READY\n";fflush(STDOUT);if(fgets(STDIN)!=="start\n")throw new RuntimeException('Missing synthetic worker barrier');
    if($mode==='--resolve')echo json_encode(ManagerReplyQueueService::mutate($actor,101,'resolve',$input),JSON_THROW_ON_ERROR);
    else{$pdo->exec(($argv[2]??'')==='project'?'DELETE FROM manager_projects WHERE manager_id=7 AND project_id=1':'DELETE FROM manager_group_members WHERE manager_id=7 AND group_id=90');echo "DELETED\n";}
    exit;
}
foreach(['tables'=>'table_schema','routines'=>'routine_schema','events'=>'event_schema'] as $table=>$column)if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$column.'=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Refusing non-empty synthetic schema');
$owned=false;$passed=0;
function nrqCheck(string $name,bool $ok): void {global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function nrqWorker(array $args): array
{
    $process=proc_open([PHP_BINARY,__FILE__,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot start fixture worker');
    stream_set_timeout($pipes[1],15);stream_set_timeout($pipes[2],15);if(fgets($pipes[1])!=="READY\n")throw new RuntimeException('Fixture worker not ready');
    return [$process,$pipes];
}
function nrqStart(array $worker): void {fwrite($worker[1][0],"start\n");fclose($worker[1][0]);}
function nrqFinish(array $worker): string
{
    $body=stream_get_contents($worker[1][1]);$error=stream_get_contents($worker[1][2]);fclose($worker[1][1]);fclose($worker[1][2]);$exit=proc_close($worker[0]);
    if($exit!==0||$error!=='')throw new RuntimeException('Synthetic worker failure '.$error);return$body;
}
try{
    replyQueueFixture($pdo,true);$owned=true;$now=time();replyQueueMessage($pdo,1,101,'inbound','customer',$now-90);replyQueueRequest($pdo,101,$now-60);
    $a=nrqWorker(['--resolve','7','native-race-owner-one']);$b=nrqWorker(['--resolve','9','native-race-admin-two']);nrqStart($a);nrqStart($b);
    $results=[json_decode(nrqFinish($a),true,32,JSON_THROW_ON_ERROR),json_decode(nrqFinish($b),true,32,JSON_THROW_ON_ERROR)];
    nrqCheck('two actual connections have one expected-version decision winner',count(array_filter($results,static fn($r)=>($r['ok']??false)===true))===1&&count(array_filter($results,static fn($r)=>($r['error']??'')==='conflict'))===1);
    nrqCheck('concurrent winner has one durable decision and one immutable receipt',(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='manager_reply_queue'")->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM manager_reply_queue_operations')->fetchColumn()===1);

    foreach(['project','group'] as $kind){
        if($kind==='group'){$pdo->exec('UPDATE conversation_sources SET primary_group_id=90 WHERE id=1');$pdo->exec('INSERT INTO manager_group_members VALUES(7,90)');}
        replyQueueMessage($pdo,$kind==='project'?2:3,101,'inbound','customer',$now-20);$view=ManagerReplyQueueService::snapshot(7,101)['reply_queue'];$input=replyQueueInput($view,'native-lock-'.$kind.'-receipt');$worker=null;
        $pdo->beforeDecision=static function()use(&$worker,$kind): void {
            $worker=nrqWorker(['--revoke',$kind]);nrqStart($worker);
            $read=[$worker[1][1]];$write=[];$except=[];$ready=stream_select($read,$write,$except,0,250000);
            nrqCheck($kind.' revocation cannot commit while canonical visibility inputs are locked',$ready===0);
        };
        $saved=ManagerReplyQueueService::mutate(7,101,'resolve',$input);
        nrqCheck($kind.' decision commits while its original authorization still holds',!empty($saved['ok'])&&is_array($worker));
        nrqCheck($kind.' revocation completes after decision commit',nrqFinish($worker)==="DELETED\n");
        nrqCheck($kind.' fresh receipt read respects the now-revoked ordinary visibility',ManagerReplyQueueService::operation(7,101,$input['operation_id'])['error']==='forbidden');
        if($kind==='project')$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');else{$pdo->exec('INSERT INTO manager_group_members VALUES(7,90)');$pdo->exec('UPDATE conversation_sources SET primary_group_id=0 WHERE id=1');}
    }
    $before=$pdo->query('SELECT * FROM manager_reply_queue_operations ORDER BY manager_id,operation_id')->fetchAll();$pdo->exec((string)file_get_contents(dirname(__DIR__,2).'/migrations/098_manager_reply_queue.sql'));
    nrqCheck('actual forward migration is idempotent without altering decision receipts',$before===$pdo->query('SELECT * FROM manager_reply_queue_operations ORDER BY manager_id,operation_id')->fetchAll());
    nrqCheck('native receipt key and InnoDB engine are installed',(int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='manager_reply_queue_operations' AND index_name='PRIMARY'")->fetchColumn()===2&&$pdo->query("SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='manager_reply_queue_operations'")->fetchColumn()==='InnoDB');

    // One bounded 200-conversation page, including retained outgoing histories.
    $insert=$pdo->prepare("INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id) VALUES(?,1,'anytour',1,'telegram','manager',7)");
    $oldEvent=$pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json,created_at) VALUES (?,'manager_message','manager','{}',?)");
    for($i=0;$i<200;$i++){
        $cid=2000+$i;$insert->execute([$cid]);$base=1000+$i*30;
        // Keep both histories representative. An optimizer may legitimately
        // scan a tiny events table; that would not prove a queue-plan defect.
        replyQueueRequest($pdo,$cid,$now-900);
        for($j=0;$j<10;$j++){replyQueueMessage($pdo,$base+$j,$cid,'outbound','manager',$now-600+$j,[],'Old synthetic reply');$oldEvent->execute([$cid,gmdate('Y-m-d H:i:s',$now-600+$j)]);}
        replyQueueMessage($pdo,$base+20,$cid,'inbound','customer',$now-30);
    }
    $sql='SELECT c.id,CASE WHEN '.ManagerReplyQueueService::neededSql('c').' THEN 1 ELSE 0 END AS needed,'.ManagerReplyQueueService::waitStartedSql('c').' AS since_unix FROM conversations c WHERE c.id BETWEEN 2000 AND 2199 ORDER BY c.id LIMIT 200';
    $plan=$pdo->query('EXPLAIN '.$sql)->fetchAll();$begin=microtime(true);$rows=$pdo->query($sql)->fetchAll();$elapsed=round((microtime(true)-$begin)*1000,3);
    nrqCheck('native bounded projection returns every current wait from retained histories',count($rows)===200&&array_sum(array_column($rows,'needed'))===200&&count(array_filter($rows,static fn($r)=>(int)$r['since_unix']!==$now-30))===0);
    $indexes=[];foreach($plan as $step)if(in_array($step['table']??'',['ro','rq','ri','rr','re','s'],true))$indexes[]=[$step['table'],$step['type'],$step['key']];
    nrqCheck('native correlated queue plan retains indexed conversation lookups',$indexes!==[]&&count(array_filter($indexes,static fn($r)=>$r[1]==='ALL'))===0);
    echo 'MEASURE synthetic current-wait page200 messages2200+ events2200+ latency_ms='.$elapsed.' plan='.json_encode($indexes,JSON_THROW_ON_ERROR)."; not production latency\n";
    echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
}finally{
    if($owned)foreach(['manager_reply_queue_operations','manager_send_attempts','provider_calls','manager_conversation_reads','conversation_events','messages','conversations','manager_customer_names','customers','manager_group_members','conversation_sources','manager_projects','managers','projects'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
}
