<?php
declare(strict_types=1);
// Dedicated empty loopback schema only. No real adapters are loaded/called.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dsn=getenv('MANAGER_SEND_TEST_MYSQL_DSN');
if(!is_string($dsn)||$dsn===''){echo "SKIP native send MySQL fixture is not configured\n";exit(0);}
if(!preg_match('/\Amysql:host=127\.0\.0\.1;port=([0-9]{1,5});dbname=manager_send_attempt_test;charset=utf8mb4\z/D',$dsn))throw new RuntimeException('Refusing non-fixture send database');
$user=getenv('MANAGER_SEND_TEST_MYSQL_USER');$password=getenv('MANAGER_SEND_TEST_MYSQL_PASSWORD');if(!is_string($user)||$user===''||!is_string($password))throw new RuntimeException('Explicit synthetic send credentials required');
require_once dirname(__DIR__).'/fixtures/manager_send_attempt_fixture.php';
$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);sendAttemptBind($pdo);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='manager_send_attempt_test')throw new RuntimeException('Unexpected send schema');
if(($argv[1]??'')==='--worker'){
    if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==12||$pdo->query('SELECT login FROM managers WHERE id=7')->fetchColumn()!=='synthetic-owner')throw new RuntimeException('Refusing non-fixture worker');
    $id=$argv[2]??'';$command=$argv[3]??'';if(!in_array($id,[str_repeat('a',32),str_repeat('b',32)],true)||!in_array($command,['send','cancel'],true))throw new RuntimeException('Invalid worker command');
    echo "READY\n";fflush(STDOUT);if(fgets(STDIN)!=="start\n")throw new RuntimeException('Missing barrier');
    if($command==='cancel')$result=ManagerSendAttemptService::cancel(7,101,$id);
    else $result=ManagerSendAttemptService::execute(7,101,$id,'Synthetic native text',null,static function()use($pdo,$id){if($pdo->inTransaction())throw new RuntimeException('Lock across provider');$pdo->prepare('INSERT INTO provider_calls VALUES(?)')->execute([$id]);usleep(200000);return['ok'=>true];});
    echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
foreach(['tables'=>'table_schema','routines'=>'routine_schema','events'=>'event_schema'] as $table=>$column)if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$column.'=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Refusing non-empty schema');
sendAttemptSchema($pdo,true);$protected=sendAttemptProtected($pdo);$passed=0;
function nativeSendCheck(string $name,bool $ok): void {global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function nativeSendRace(string $id,array $commands): array
{
    $workers=[];$pipes=[];$results=[];
    try{
        foreach($commands as $i=>$command){$workers[$i]=proc_open([PHP_BINARY,__FILE__,'--worker',$id,$command],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes[$i]);if(!is_resource($workers[$i]))throw new RuntimeException('Cannot start worker');stream_set_timeout($pipes[$i][1],15);}
        foreach($pipes as $stream)if(fgets($stream[1])!=="READY\n")throw new RuntimeException('Worker not ready');
        foreach($pipes as $stream){fwrite($stream[0],"start\n");fclose($stream[0]);}
        foreach($pipes as $i=>$stream){$body=stream_get_contents($stream[1]);$error=stream_get_contents($stream[2]);fclose($stream[1]);fclose($stream[2]);$exit=proc_close($workers[$i]);$workers[$i]=null;if($exit!==0||$error!=='')throw new RuntimeException('Synthetic native worker failure '.$error);$results[]=json_decode($body,true,16,JSON_THROW_ON_ERROR);}
    }finally{foreach($workers as $i=>$worker)if(is_resource($worker)){proc_terminate($worker);foreach($pipes[$i]??[] as $stream)if(is_resource($stream))fclose($stream);proc_close($worker);}}
    return $results;
}
$race=nativeSendRace(str_repeat('a',32),['send','send']);$states=array_column(array_column($race,'attempt'),'state');
nativeSendCheck('native unique reservation allows only one callback',count(array_filter($states,static fn($s)=>$s==='confirmed'))>=1&&(int)$pdo->query('SELECT COUNT(*) FROM provider_calls')->fetchColumn()===1);
$receipt=ManagerSendAttemptService::receipt(7,101,str_repeat('a',32));nativeSendCheck('independent connection reconciles committed success',$receipt===['ok'=>true,'attempt'=>['state'=>'confirmed']]);
$race=nativeSendRace(str_repeat('b',32),['send','cancel']);$state=ManagerSendAttemptService::receipt(7,101,str_repeat('b',32))['attempt']['state'];$count=(int)$pdo->query("SELECT COUNT(*) FROM provider_calls WHERE operation_id='".str_repeat('b',32)."'")->fetchColumn();
nativeSendCheck('native cancellation versus execution has exactly one durable winner',($state==='confirmed'&&$count===1)||($state==='cancelled'&&$count===0));
$late=ManagerSendAttemptService::execute(7,101,str_repeat('b',32),'Synthetic native text',null,static function(){throw new RuntimeException('Late transport must never run');});nativeSendCheck('late original replays winner without external work',$late['attempt']['state']===$state);
$rows=$pdo->query('SELECT * FROM manager_send_attempts ORDER BY operation_id')->fetchAll();ManagerSendAttemptService::receipt(7,101,str_repeat('a',32));nativeSendCheck('native receipt lookup is write free',$rows===$pdo->query('SELECT * FROM manager_send_attempts ORDER BY operation_id')->fetchAll());
nativeSendCheck('native workflow leaves auth assignment read state transcript unchanged',sendAttemptProtected($pdo)===$protected);
nativeSendCheck('actual forward migration is idempotent and creates indexed InnoDB receipt table',($pdo->exec((string)file_get_contents(dirname(__DIR__,2).'/migrations/059_manager_send_attempts.sql'))!==false)&&$pdo->query("SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='manager_send_attempts'")->fetchColumn()==='InnoDB'&&(int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='manager_send_attempts' AND index_name='PRIMARY'")->fetchColumn()===2);
echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
