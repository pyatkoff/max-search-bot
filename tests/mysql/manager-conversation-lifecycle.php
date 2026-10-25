<?php
declare(strict_types=1);

// Native locking proof, never an application database or provider transport.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dsn=getenv('MANAGER_LIFECYCLE_TEST_MYSQL_DSN');
if(!is_string($dsn)||$dsn===''){echo "SKIP native lifecycle MySQL fixture is not configured\n";exit(0);}
if(!preg_match('/\Amysql:host=127\.0\.0\.1;port=([0-9]{1,5});dbname=manager_lifecycle_test;charset=utf8mb4\z/D',$dsn)){
    fwrite(STDERR,"Refusing non-loopback or non-fixture lifecycle database\n");exit(1);
}
$user=getenv('MANAGER_LIFECYCLE_TEST_MYSQL_USER');$password=getenv('MANAGER_LIFECYCLE_TEST_MYSQL_PASSWORD');
if(!is_string($user)||$user===''||!is_string($password)){fwrite(STDERR,"Explicit synthetic lifecycle credentials required\n");exit(1);}
require_once dirname(__DIR__).'/fixtures/manager_lifecycle_fixture.php';
$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='manager_lifecycle_test')throw new RuntimeException('Unexpected lifecycle schema');
lifecycleFixtureBind($pdo);
if(in_array($argv[1]??'',['--worker','--referral-worker'],true)){
    // Worker processes have no schema setup/DDL path and accept only fixture IDs.
    if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==10
        ||(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine='InnoDB'")->fetchColumn()!==10
        ||$pdo->query('SELECT fixture_marker FROM managers WHERE id=1')->fetchColumn()!=='manager-lifecycle-native-fixture')throw new RuntimeException('Refusing non-fixture worker');
    $actor=filter_var($argv[2]??'',FILTER_VALIDATE_INT);$target=filter_var($argv[3]??'',FILTER_VALIDATE_INT);
    $referral=($argv[1]??'')==='--referral-worker';
    if(!in_array($actor,[1,2],true)||!in_array($target,[11,15,31,32],true)||($referral&&($actor!==1||!in_array($target,[31,32],true))))throw new RuntimeException('Invalid synthetic worker command');
    echo "READY\n";fflush(STDOUT);if(fgets(STDIN)!=="start\n")throw new RuntimeException('Missing worker barrier');
    if($referral){
        $pdo->beginTransaction();$pdo->query('SELECT id FROM managers WHERE id=1 FOR UPDATE')->fetchColumn();
        foreach([21,$target] as $id)$pdo->query('SELECT id FROM conversations WHERE id='.(int)$id.' FOR UPDATE')->fetchColumn();
        $result=ManagerConversationService::assignSelectionReferralInTransaction($target,21,1);$pdo->commit();
        echo json_encode($result,JSON_THROW_ON_ERROR);exit(0);
    }
    echo json_encode(ManagerConversationService::takeResult($target,$actor),JSON_THROW_ON_ERROR);exit(0);
}
// No fixture DDL or writes may precede the exact-schema and empty-schema checks.
foreach(['tables'=>'table_schema','routines'=>'routine_schema','events'=>'event_schema'] as $table=>$column){
    if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.'.$table.' WHERE '.$column.'=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Refusing non-empty lifecycle schema');
}
lifecycleFixtureSchema($pdo,true);lifecycleFixtureSeed($pdo);
$passed=0;
function nativeLifecycleCheck(string $name,bool $ok): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function nativeLifecycleRace(array $commands): array
{
    $workers=[];$pipes=[];$results=[];
    try{
        foreach($commands as $index=>$command){
            [$actor,$target]=$command;$mode=($command[2]??'take')==='referral'?'--referral-worker':'--worker';
            $workers[$index]=proc_open([PHP_BINARY,__FILE__,$mode,(string)$actor,(string)$target],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes[$index]);
            if(!is_resource($workers[$index]))throw new RuntimeException('Cannot start isolated native lifecycle worker');
            stream_set_timeout($pipes[$index][1],15);stream_set_timeout($pipes[$index][2],15);
        }
        foreach($pipes as $streams)if(fgets($streams[1])!=="READY\n")throw new RuntimeException('Native lifecycle worker not ready');
        foreach($pipes as $streams){fwrite($streams[0],"start\n");fclose($streams[0]);}
        foreach($pipes as $index=>$streams){
            $body=stream_get_contents($streams[1]);$error=stream_get_contents($streams[2]);fclose($streams[1]);fclose($streams[2]);
            $exit=proc_close($workers[$index]);$workers[$index]=null;
            if($exit!==0||$error!=='')throw new RuntimeException('Native lifecycle worker failed');
            $results[]=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        }
    }finally{
        foreach($workers as $index=>$worker)if(is_resource($worker)){proc_terminate($worker);foreach($pipes[$index]??[] as $stream)if(is_resource($stream))fclose($stream);proc_close($worker);}
    }
    return $results;
}
$protected=lifecycleFixtureSnapshot($pdo,['managers','manager_projects','messages','manager_conversation_reads']);
$race=nativeLifecycleRace([[1,11],[2,11]]);
$success=array_values(array_filter($race,static fn($r)=>$r['ok']===true));$refused=array_values(array_filter($race,static fn($r)=>($r['error']??'')==='owned_elsewhere'));
nativeLifecycleCheck('two independent native connections race for one unassigned conversation',count($success)===1&&count($refused)===1);
$winner=(int)$pdo->query('SELECT manager_id FROM conversations WHERE id=11')->fetchColumn();
nativeLifecycleCheck('native winner owns one active assignment and one event',in_array($winner,[1,2],true)&&(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id=11 AND released_at IS NULL')->fetchColumn()===1&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=11 AND event_type='manager_taken'")->fetchColumn()===1&&(int)$pdo->query('SELECT manager_id FROM manager_assignments WHERE conversation_id=11')->fetchColumn()===$winner);
$race=nativeLifecycleRace([[1,15],[1,15]]);
nativeLifecycleCheck('same-manager native concurrent take remains idempotent',$race===[['ok'=>true],['ok'=>true]]&&(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id=15')->fetchColumn()===1&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=15 AND event_type='manager_taken'")->fetchColumn()===1);
nativeLifecycleCheck('native structured repeat remains successful without another assignment',ManagerConversationService::takeResult(11,$winner)===['ok'=>true]&&(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id=11')->fetchColumn()===1);
$before=lifecycleFixtureSnapshot($pdo);$state=ManagerConversationService::lifecycleState(11,$winner);
nativeLifecycleCheck('native explicit current-state check is write-free and private',$state===['id'=>11,'status'=>'manager','manager_id'=>$winner]&&lifecycleFixtureSnapshot($pdo)===$before&&MaxSearchApi::$calls===[]);
nativeLifecycleCheck('native lifecycle commands leave shifts permissions transcript and reads untouched',lifecycleFixtureSnapshot($pdo,['managers','manager_projects','messages','manager_conversation_reads'])===$protected);
$pdo->exec("INSERT INTO conversations VALUES(31,'anytour',0,'ai',NULL,'synthetic-31','2026-10-16 08:00:00','2026-10-16 08:01:00',NULL),(32,'anytour',0,'ai',NULL,'synthetic-32','2026-10-16 08:00:00','2026-10-16 08:01:00',NULL)");
$race=nativeLifecycleRace([[1,31,'referral'],[1,31,'referral']]);
$states=array_column($race,'state');sort($states);
nativeLifecycleCheck('native concurrent forwarded entries assign the selection manager exactly once',$states===['assigned','same_manager']&&(int)$pdo->query('SELECT manager_id FROM conversations WHERE id=31')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id=31')->fetchColumn()===1&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=31 AND event_type='manager_selection_referral'")->fetchColumn()===1);
$race=nativeLifecycleRace([[1,32,'referral'],[2,32,'take']]);$winner=(int)$pdo->query('SELECT manager_id FROM conversations WHERE id=32')->fetchColumn();
nativeLifecycleCheck('native referral and another manager take cannot overwrite each other',($winner===1&&($race[0]['state']??'')==='assigned'&&($race[1]['error']??'')==='owned_elsewhere')||($winner===2&&($race[0]['state']??'')==='existing_owner'&&!empty($race[1]['ok'])));
nativeLifecycleCheck('native referral take race retains one truthful assignment event and no protected mutations',(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments WHERE conversation_id=32')->fetchColumn()===1&&(int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=32 AND event_type IN ('manager_taken','manager_selection_referral')")->fetchColumn()===1&&lifecycleFixtureSnapshot($pdo,['managers','manager_projects','messages','manager_conversation_reads'])===$protected&&MaxSearchApi::$calls===[]);
echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
