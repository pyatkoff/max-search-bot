<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dsn=getenv('PREPARED_REPLY_MYSQL_DSN');
if(!is_string($dsn)||$dsn===''){
    if(in_array('--required-native',$argv,true)){fwrite(STDERR,"Explicit ephemeral regional MySQL fixture required\n");exit(1);}
    echo "SKIP native regional routing fixture is not configured\n";exit(0);
}
if(!preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=([0-9]{1,5});)?dbname=maxbot_prepared_test;charset=utf8mb4\z/D',$dsn)){
    fwrite(STDERR,"Refusing non-ephemeral regional routing MySQL configuration\n");exit(1);
}
$user=getenv('PREPARED_REPLY_MYSQL_USER');$password=getenv('PREPARED_REPLY_MYSQL_PASS');
if(!is_string($user)||$user===''||!is_string($password)){fwrite(STDERR,"Explicit regional fixture credentials required\n");exit(1);}
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
try{$pdo=new PDO($dsn,$user,$password,$options);}catch(Throwable$ignored){fwrite(STDERR,"Regional native fixture unavailable\n");exit(1);}
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_prepared_test'){fwrite(STDERR,"Refusing unexpected regional fixture schema\n");exit(1);}
require_once dirname(__DIR__).'/fixtures/manager_regional_routing_fixture.php';
regionalRoutingBind($pdo);
if(($argv[1]??'')==='--worker-revoke'){
    if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==18
        ||$pdo->query('SELECT login FROM managers WHERE id=90')->fetchColumn()!=='regional-native-admin'){
        fwrite(STDERR,"Refusing nonfixture regional worker\n");exit(1);
    }
    echo 'READY:'.$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";fflush(STDOUT);
    if(fgets(STDIN)!=="start\n")exit(1);
    echo "MUTATING\n";fflush(STDOUT);
    $result=RoutingAdminService::saveGroupResult(90,'anytour',10,'kras','Synthetic Krasnoyarsk group',[7],[]);
    echo json_encode($result,JSON_THROW_ON_ERROR);exit(empty($result['ok'])?1:0);
}
// Destructive setup is allowed ONLY after proving this dedicated fresh job owns
// an empty loopback schema. No application config or production DSN is loaded.
foreach(['tables'=>'table_schema','routines'=>'routine_schema','events'=>'event_schema']as$kind=>$column){
    if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.'.$kind.' WHERE '.$column.'=DATABASE()')->fetchColumn()!==0){
        fwrite(STDERR,"Refusing nonempty regional MySQL fixture\n");exit(1);
    }
}
regionalRoutingSchema($pdo,true);$scopes=regionalRoutingInstallScopes($pdo);
$pdo->exec('CREATE TABLE regional_guarded_writes(id INTEGER PRIMARY KEY) ENGINE=InnoDB');
regionalRoutingConversation($pdo,101,'62');regionalRoutingConversation($pdo,102,'51');
regionalRoutingConversation($pdo,103,'62',['manager_id'=>0]);
$checks=0;
function regionalNativeCheck(string$label,bool$ok):void
{
    global$checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label."\n";
}
function regionalNativeVisible(PDO$pdo,int$id,bool$currentRead=false):bool
{
    return RoutingAccessService::canSeeConversation(7,regionalRoutingStored($pdo,$id),$currentRead);
}
regionalNativeCheck('Actual migration099 creates exactly three indexed InnoDB ACL tables',
    (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('manager_regional_access','manager_group_territories','manager_group_territory_regions') AND engine='InnoDB'")->fetchColumn()===3
    &&(int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='manager_group_territory_regions' AND index_name='idx_territory_region_access'")->fetchColumn()===3);
regionalNativeCheck('Native regional ACL grants only the configured territory',regionalNativeVisible($pdo,101)&&!regionalNativeVisible($pdo,102));
$zeroArgs=[];$zeroPredicate=RegionalRoutingScopeService::candidatePredicate(7,'c',$zeroArgs);
$zeroQuery=$pdo->prepare('SELECT id FROM conversations c WHERE c.id=103 AND '.$zeroPredicate);$zeroQuery->execute($zeroArgs);
regionalNativeCheck('Native JSON candidate SQL preserves singleton zero-owner semantics',(int)$zeroQuery->fetchColumn()===103&&regionalNativeVisible($pdo,103));
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=7 AND project_id=1');
regionalNativeCheck('Native project grant revocation cannot erase the durable restriction',
    (int)$pdo->query('SELECT regional_scope_required FROM manager_regional_access WHERE manager_id=7 AND project_id=1')->fetchColumn()===1&&!regionalNativeVisible($pdo,101));
$pdo->exec('INSERT INTO manager_projects VALUES(7,1)');
regionalNativeCheck('Regranting native project access remains region-restricted',regionalNativeVisible($pdo,101)&&!regionalNativeVisible($pdo,102));

// A prior RR snapshot must not resurrect a scope revoked by an independent
// connection before the canonical current-read visibility fence is acquired.
$reader=new PDO($dsn,$user,$password,$options);$writer=new PDO($dsn,$user,$password,$options);
$reader->exec('SET TRANSACTION READ ONLY');$reader->beginTransaction();regionalRoutingBind($reader);
try{regionalNativeCheck('An ordinary ACL read remains valid inside an actual READ ONLY transaction',regionalNativeVisible($reader,101));}
finally{$reader->rollBack();regionalRoutingBind($pdo);}
$reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$reader->beginTransaction();
$reader->query('SELECT COUNT(*) FROM manager_group_territories')->fetchColumn();
$writer->exec('DELETE FROM manager_group_territories WHERE group_id=10');
regionalRoutingBind($reader);$row=regionalRoutingStored($reader,101);
$locked=RoutingAccessService::lockConversationVisibilityInputs(7,$row);
regionalNativeCheck('Current-read fence denies a scope revoked after the reader RR snapshot',!$locked||!regionalNativeVisible($reader,101,true));
$reader->rollBack();regionalRoutingBind($pdo);
$pdo->beginTransaction();RegionalRoutingScopeService::replaceGroupScopes($pdo,10,[$scopes['62']]);$pdo->commit();

// Exercise the actual admin mutation in another PHP process. Its project/scope
// revocation must wait until an already-authorized protected write commits.
$reader->beginTransaction();regionalRoutingBind($reader);$row=regionalRoutingStored($reader,101);
regionalNativeCheck('Reader acquires the canonical current regional visibility inputs',RoutingAccessService::lockConversationVisibilityInputs(7,$row)&&regionalNativeVisible($reader,101,true));
$worker=proc_open([PHP_BINARY,__FILE__,'--worker-revoke'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($worker))throw new RuntimeException('Native regional worker unavailable');
try{
    stream_set_timeout($pipes[1],15);$ready=fgets($pipes[1]);
    if(!is_string($ready)||!preg_match('/\AREADY:([1-9][0-9]*)\n\z/D',$ready,$match))throw new RuntimeException('Native regional worker not ready');
    $connection=(int)$match[1];fwrite($pipes[0],"start\n");fclose($pipes[0]);
    if(fgets($pipes[1])!=="MUTATING\n")throw new RuntimeException('Native regional worker did not enter the canonical mutation');
    $observer=new PDO($dsn,$user,$password,$options);$waiting=false;$deadline=microtime(true)+5;
    do{
        $q=$observer->prepare("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=? AND trx_state='LOCK WAIT'");$q->execute([$connection]);
        if((int)$q->fetchColumn()===1){$waiting=true;break;}
        // INNODB_TRX refresh requires >100ms without another cache read. A
        // 10ms poll pins its initial snapshot and can never observe a new wait.
        // MySQL 8.0 storage/innobase/trx/trx0i_s.cc: can_cache_be_updated().
        usleep(200000);
    }while(microtime(true)<$deadline);
    if(!$waiting){
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $q=$observer->prepare('SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?');$q->execute([$connection]);
        fwrite(STDERR,json_encode(['regional_worker_wait_observation'=>'missing','process'=>$q->fetch(),
            'worker_output'=>stream_get_contents($pipes[1],2000),'worker_error'=>stream_get_contents($pipes[2],2000)],JSON_THROW_ON_ERROR)."\n");
    }
    regionalNativeCheck('Concurrent canonical scope revocation waits for the protected regional transaction',$waiting);
    $reader->exec('INSERT INTO regional_guarded_writes VALUES(1)');$reader->commit();
    $body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($worker);$worker=null;$result=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    regionalNativeCheck('The original revoke completes exactly once after the prior guarded commit',$exit===0&&$error===''&&!empty($result['ok']));
}finally{
    if($reader->inTransaction())$reader->rollBack();
    if(is_resource($worker)){proc_terminate($worker);foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($worker);}
    regionalRoutingBind($pdo);
}
regionalNativeCheck('Committed revocation is immediate and cannot undo the prior guarded write',
    !regionalNativeVisible($pdo,101)&&(int)$pdo->query('SELECT COUNT(*) FROM regional_guarded_writes')->fetchColumn()===1
    &&(int)$pdo->query('SELECT regional_scope_required FROM manager_regional_access WHERE manager_id=7 AND project_id=1')->fetchColumn()===1);
$before=$pdo->query('SELECT status,manager_id FROM conversations WHERE id=101')->fetch();
$take=ManagerConversationService::takeResult(101,7);
regionalNativeCheck('A revoked territory cannot claim a conversation or write an assignment',empty($take['ok'])
    &&$pdo->query('SELECT status,manager_id FROM conversations WHERE id=101')->fetch()===$before
    &&(int)$pdo->query('SELECT COUNT(*) FROM manager_assignments')->fetchColumn()===0);

$restored=RoutingAdminService::saveGroupResult(90,'anytour',10,'kras','Synthetic Krasnoyarsk group',[7],['62']);
regionalNativeCheck('A reviewed native scope save rebuilds its actual header and all descendants',!empty($restored['ok'])&&regionalNativeVisible($pdo,101)
    &&(int)$pdo->query("SELECT COUNT(*) FROM manager_group_territory_regions WHERE group_id=10 AND territory_region_id='62'")->fetchColumn()===$scopes['62']['descendant_count']);

// MySQL's JSON scalar types and the native prepared candidate SQL must reject a
// full earlier page of malformed first receipts, not hide the valid row below.
$pdo->exec('DELETE FROM conversations;DELETE FROM conversation_events');
for($id=1;$id<=210;$id++)regionalRoutingConversation($pdo,1000+$id,'51',['started_at'=>'2000-01-01 00:00:00']);
for($id=1;$id<=210;$id++){
    $bad=regionalRoutingReceipt('62');$bad['version']='1';
    regionalRoutingConversation($pdo,2000+$id,'62',['started_at'=>'2000-01-02 00:00:00','metadata_json'=>json_encode(['first_entry_region'=>$bad])]);
}
regionalRoutingConversation($pdo,9000,'62');
$page=ManagerConversationService::notificationWaitingRows(7,'anytour');
regionalNativeCheck('Native JSON receipt and territory predicates protect the pre-LIMIT candidate page',array_map('intval',array_column($page,'id'))===[9000]);
echo 'NATIVE REGIONAL ROUTING MYSQL OK ('.$checks." checks)\n";
