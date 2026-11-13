<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/support/manager-promise-task-fixture.php';
require_once dirname(__DIR__,2).'/services/ManagerPromiseTaskService.php';

/** Native migration/locking proof, confined to the existing disposable CI database. */
function promiseNativeConnection(): PDO
{
    $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
    if(preg_match('/\Amysql:host=(?:127\.0\.0\.1|localhost|mysql);(?:port=[0-9]{1,5};)?dbname=maxbot_prepared_test;charset=utf8mb4\z/D',$dsn)!==1)
        throw new RuntimeException('unsafe_promise_fixture_database');
    $user=getenv('PREPARED_REPLY_MYSQL_USER');$password=getenv('PREPARED_REPLY_MYSQL_PASS');
    if(!is_string($user)||$user===''||!is_string($password))throw new RuntimeException('explicit_promise_fixture_credentials_required');
    $pdo=new PDO($dsn,$user,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='maxbot_prepared_test')throw new RuntimeException('unsafe_promise_fixture_database');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
    return $pdo;
}
function promiseNativeAttach(PDO $pdo): void
{
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    foreach([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class)
        (new ReflectionProperty($class,'schemaReady'))->setValue(null,false);
    ProjectConfig::resetForTests(['id'=>'anytour','brand'=>['name'=>'AnyTour']]);
}
function promiseNativeCheck(bool $condition,string $label): void
{
    if(!$condition)throw new RuntimeException($label);
    echo 'PASS native MySQL promise '.$label."\n";
}
function promiseNativeWorker(): never
{
    try{
        $pdo=promiseNativeConnection();
        if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()!==18
            ||$pdo->query('SELECT login FROM managers WHERE id=1')->fetchColumn()!=='promise-native-fixture')throw new RuntimeException('promise_worker_fixture_required');
        promiseNativeAttach($pdo);
        echo 'READY:'.$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()."\n";fflush(STDOUT);
        $line=fgets(STDIN,16384);$input=json_decode((string)$line,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($input)||!in_array($input['actor']??null,[1,2],true)||!is_array($input['command']??null))throw new RuntimeException('promise_worker_input');
        echo json_encode(ManagerPromiseTaskService::bind($input['actor'],1,$input['command']),JSON_THROW_ON_ERROR)."\n";exit(0);
    }catch(Throwable $error){fwrite(STDERR,"FAIL native promise worker\n");exit(1);}
}
if(array_slice($argv,1)===['--bind-worker'])promiseNativeWorker();
if(count($argv)!==1)throw new RuntimeException('promise_native_arguments');

/** Hold the actual conversation lock until both independent connections wait. */
function promiseNativeRace(PDO $observer,array $commands): array
{
    $children=[];$results=[];
    try{
        $observer->beginTransaction();$observer->query('SELECT id FROM conversations WHERE id=1 FOR UPDATE')->fetchColumn();
        foreach($commands as $command){
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--bind-worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('promise_worker_start');
            $children[]=['process'=>$process,'pipes'=>$pipes];$index=array_key_last($children);
            stream_set_timeout($pipes[1],15);$ready=fgets($pipes[1]);
            if(preg_match('/\AREADY:([0-9]+)\n\z/D',(string)$ready,$match)!==1)throw new RuntimeException('promise_worker_not_ready');
            $children[$index]['connection']=(int)$match[1];
            fwrite($pipes[0],json_encode($command,JSON_THROW_ON_ERROR)."\n");fclose($pipes[0]);
        }
        $waiting=[];$until=microtime(true)+7;
        do{
            usleep(150000); // InnoDB's transaction observation cache needs >100ms between reads.
            foreach($children as $child){
                $q=$observer->prepare("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=? AND trx_state='LOCK WAIT'");
                $q->execute([$child['connection']]);if((int)$q->fetchColumn()===1)$waiting[$child['connection']]=true;
            }
        }while(count($waiting)!==count($children)&&microtime(true)<$until);
        if(count($waiting)!==count($children))throw new RuntimeException('promise_concurrency_not_observed');
        $observer->commit();
        foreach($children as $index=>$child){
            $line=fgets($child['pipes'][1]);$errors=stream_get_contents($child['pipes'][2]);
            fclose($child['pipes'][1]);fclose($child['pipes'][2]);$status=proc_close($child['process']);$children[$index]=null;
            if($status!==0||$errors!=='')throw new RuntimeException('promise_worker_failed');
            $result=json_decode((string)$line,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($result))throw new RuntimeException('promise_worker_result');$results[]=$result;
        }
        return $results;
    }finally{
        if($observer->inTransaction())$observer->rollBack();
        foreach($children as $child)if($child!==null){
            proc_terminate($child['process']);foreach($child['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($child['process']);
        }
    }
}

$pdo=null;$owned=false;$failed=false;
$tables=['manager_task_promise_operations','manager_task_promises','manager_request_profiles','lead_tasks','manager_conversation_reads','messages','conversation_events','manager_group_members','conversation_sources','conversations','manager_customer_names','customers','manager_projects','managers','projects','manager_group_territory_regions','manager_group_territories','manager_regional_access'];
try{
    $pdo=promiseNativeConnection();
    foreach(['TABLES','ROUTINES','EVENTS'] as $kind){
        $column=$kind==='TABLES'?'TABLE_SCHEMA':($kind==='ROUTINES'?'ROUTINE_SCHEMA':'EVENT_SCHEMA');
        if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.$kind WHERE $column=DATABASE()")->fetchColumn()!==0)
            throw new RuntimeException('promise_fixture_requires_empty_database');
    }
    $owned=true;regionalAccessFixtureSchema($pdo);promiseNativeAttach($pdo);
    $definitions=[
        'projects'=>"id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(64),display_name TEXT,is_active INT",
        'manager_projects'=>'manager_id BIGINT UNSIGNED,project_id BIGINT UNSIGNED',
        'managers'=>'id BIGINT UNSIGNED PRIMARY KEY,login VARCHAR(100),display_name TEXT,role VARCHAR(32),email TEXT,is_active INT,is_working INT',
        'customers'=>'id BIGINT UNSIGNED PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT',
        'manager_customer_names'=>'customer_id BIGINT UNSIGNED PRIMARY KEY,working_name TEXT,version INT',
        'conversations'=>'id BIGINT UNSIGNED PRIMARY KEY,customer_id BIGINT UNSIGNED,project_key VARCHAR(64),source_id BIGINT UNSIGNED,channel VARCHAR(32),entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status VARCHAR(32),lead_stage_key VARCHAR(32),manager_id BIGINT UNSIGNED,started_at DATETIME,last_message_at DATETIME,closed_at DATETIME,external_chat_id VARCHAR(255)',
        'conversation_sources'=>'id BIGINT UNSIGNED PRIMARY KEY,display_name TEXT,is_active INT,primary_group_id BIGINT UNSIGNED,fallback_mode VARCHAR(32),fallback_group_id BIGINT UNSIGNED,fallback_after_minutes INT',
        'manager_group_members'=>'manager_id BIGINT UNSIGNED,group_id BIGINT UNSIGNED',
        'conversation_events'=>'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,conversation_id BIGINT UNSIGNED,event_type VARCHAR(64),actor_type VARCHAR(32),payload_json MEDIUMTEXT,created_at DATETIME',
        'messages'=>'id BIGINT UNSIGNED PRIMARY KEY,conversation_id BIGINT UNSIGNED,direction VARCHAR(32),sender_type VARCHAR(32),text MEDIUMTEXT,created_at DATETIME,channel VARCHAR(32),metadata_json MEDIUMTEXT,sender_id VARCHAR(255),external_message_id VARCHAR(255),INDEX profile_messages(conversation_id,id)',
        'manager_conversation_reads'=>'manager_id BIGINT UNSIGNED,conversation_id BIGINT UNSIGNED,last_read_message_id BIGINT UNSIGNED,updated_at DATETIME,PRIMARY KEY(manager_id,conversation_id)',
        'lead_tasks'=>"id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,conversation_id BIGINT UNSIGNED NOT NULL,title TEXT NOT NULL,due_at_utc DATETIME,status VARCHAR(32) NOT NULL DEFAULT 'open',is_pinned INT NOT NULL DEFAULT 0,assigned_manager_id BIGINT UNSIGNED,created_by_manager_id BIGINT UNSIGNED NOT NULL,completed_at_utc DATETIME,reminder_attempted_at_utc DATETIME,reminder_notified_at_utc DATETIME,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL"
    ];
    foreach($definitions as $table=>$definition)$pdo->exec('CREATE TABLE '.$table.'('.$definition.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    foreach(['080_manager_request_profiles.sql','108_manager_task_promises.sql'] as $migration)$pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/'.$migration));
    requestProfileSeed($pdo);$pdo->exec("UPDATE managers SET login='promise-native-fixture' WHERE id=1");promiseTaskSeed($pdo);
    $scope='synthetic-🧳-е́';$pdo->prepare('UPDATE conversations SET external_chat_id=? WHERE id=1')->execute([$scope]);
    $before=promiseTaskCanonicalSnapshot($pdo);
    $context=ManagerPromiseTaskService::context(1,1,101,12,'send_calculation');
    promiseNativeCheck(!empty($context['ok'])&&$context['can_bind']===true,'actual migrated schema provides authorized context');
    $day=['kind'=>'date','value'=>'2028-02-29','zone'=>'Europe/Moscow'];$first=promiseTaskCommand($context,['deadline'=>$day]);
    $second=promiseTaskCommand(ManagerPromiseTaskService::context(2,1,101,12,'send_calculation'),['deadline'=>$day]);
    $race=promiseNativeRace($pdo,[['actor'=>1,'command'=>$first],['actor'=>2,'command'=>$second]]);
    promiseNativeCheck(!empty($race[0]['ok'])&&!empty($race[1]['ok'])&&$race[0]['promise_id']===$race[1]['promise_id']
        &&promiseTaskCounts($pdo)===[1,2],'two actors concurrently reuse one immutable promise and record both receipts');
    $row=$pdo->query('SELECT *,HEX(deadline_json) AS deadline_hex FROM manager_task_promises')->fetch();
    promiseNativeCheck(json_decode($row['scope_json'],true,16,JSON_THROW_ON_ERROR)['external_chat_id']===$scope
        &&json_decode($row['deadline_json'],true,8,JSON_THROW_ON_ERROR)===$day
        &&strtolower($row['deadline_hex'])===bin2hex($row['deadline_json']),'utf8mb4 identity and binary calendar precision round-trip exactly');
    $duplicate=promiseTaskCommand(ManagerPromiseTaskService::context(1,1,102,13,'send_selection'),['kind'=>'send_selection']);
    $race=promiseNativeRace($pdo,[['actor'=>1,'command'=>$duplicate],['actor'=>1,'command'=>$duplicate]]);
    promiseNativeCheck(!empty($race[0]['ok'])&&!empty($race[1]['ok'])&&($race[0]['replayed'] xor $race[1]['replayed'])
        &&promiseTaskCounts($pdo)===[2,3],'simultaneous identical operation commits once and replays once');
    promiseNativeCheck(promiseTaskCanonicalSnapshot($pdo)===$before,'all concurrent binds leave tasks transcript profile events and read markers byte-exact');

    foreach([14,15] as $id)requestProfileMessage($pdo,$id,'manager','Дополнительное синтетическое обещание '.$id);
    promiseTaskManualEdit($pdo,"INSERT INTO lead_tasks(id,conversation_id,title,status,created_by_manager_id,created_at,updated_at) VALUES(103,1,'Первый ручной вариант','open',1,'2026-10-30 10:01:00','2026-10-30 10:01:00'),(104,1,'Второй ручной вариант','open',1,'2026-10-30 10:01:00','2026-10-30 10:01:00'),(105,1,'Для проверки атомарности','open',1,'2026-10-30 10:01:00','2026-10-30 10:01:00')");
    $before=promiseTaskCanonicalSnapshot($pdo);
    $a=promiseTaskCommand(ManagerPromiseTaskService::context(1,1,103,14,'send_calculation'));
    $b=promiseTaskCommand(ManagerPromiseTaskService::context(2,1,104,14,'send_calculation'));
    $race=promiseNativeRace($pdo,[['actor'=>1,'command'=>$a],['actor'=>2,'command'=>$b]]);
    $success=array_values(array_filter($race,static fn(array $r):bool=>!empty($r['ok'])));
    $errors=array_values(array_filter($race,static fn(array $r):bool=>($r['error']??null)==='binding_conflict'));
    promiseNativeCheck(count($success)===1&&count($errors)===1&&promiseTaskCounts($pdo)===[3,4]
        &&promiseTaskCanonicalSnapshot($pdo)===$before,'conflicting concurrent task targets retain one binding and one receipt');
    $atomic=promiseTaskCommand(ManagerPromiseTaskService::context(1,1,105,15,'send_calculation'));
    $pdo->exec("CREATE TRIGGER promise_native_fail_receipt BEFORE INSERT ON manager_task_promise_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_receipt_failure'");
    $result=ManagerPromiseTaskService::bind(1,1,$atomic);
    $lookup=ManagerPromiseTaskService::operation(1,1,$atomic['operation_id']);
    promiseNativeCheck(($result['error']??null)==='storage_unavailable'&&($lookup['found']??null)===false
        &&promiseTaskCounts($pdo)===[3,4]&&promiseTaskCanonicalSnapshot($pdo)===$before,'receipt failure atomically rolls back binding and leaves an honest absent operation');
    $pdo->exec('DROP TRIGGER promise_native_fail_receipt');$result=ManagerPromiseTaskService::bind(1,1,$atomic);
    promiseNativeCheck(!empty($result['ok'])&&$result['replayed']===false&&promiseTaskCounts($pdo)===[4,5],'explicit retry of known uncommitted operation succeeds once');
    $history=[];foreach(['manager_task_promises'=>'promise_id','manager_task_promise_operations'=>'manager_id,operation_id'] as $table=>$order)$history[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll();
    $pdo->exec(file_get_contents(dirname(__DIR__,2).'/migrations/108_manager_task_promises.sql'));
    foreach(['manager_task_promises'=>'promise_id','manager_task_promise_operations'=>'manager_id,operation_id'] as $table=>$order)
        promiseNativeCheck($pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll()===$history[$table],'migration retry preserves '.$table.' byte-exact');
    promiseTaskManualEdit($pdo,'DELETE FROM lead_tasks WHERE id=105');
    $orphan=ManagerPromiseTaskService::operation(1,1,$atomic['operation_id']);$replay=ManagerPromiseTaskService::bind(1,1,$atomic);
    promiseNativeCheck(($orphan['error']??null)==='storage_unavailable'&&($replay['error']??null)==='storage_unavailable'
        &&promiseTaskCounts($pdo)===[4,5]&&(int)$pdo->query('SELECT COUNT(*) FROM lead_tasks WHERE id=105')->fetchColumn()===0,'orphan receipt is retained and never recreates its deleted task');
    $view=ManagerRequestProfileService::read(1,2);
    $saved=ManagerRequestProfileService::save(1,2,['version'=>$view['profile']['version'],'context_sha256'=>$view['context_sha256'],
        'operation_id'=>bin2hex(random_bytes(16)),'boundary_message_id'=>20,'new_trip'=>false,'label'=>'Контрольная поездка','fields'=>[]]);
    promiseNativeCheck(!empty($saved['ok']),'independent control conversation has its own canonical trip');
    $control=promiseTaskCommand(ManagerPromiseTaskService::context(1,2,201,20,'send_calculation'));
    promiseNativeCheck(!empty(ManagerPromiseTaskService::bind(1,2,$control)['ok']),'independent control binding exists');
    $pdo->exec('DELETE FROM conversations WHERE id=1');$controlRead=ManagerPromiseTaskService::operation(1,2,$control['operation_id']);
    promiseNativeCheck(promiseTaskCounts($pdo)===[1,1]&&!empty($controlRead['found'])&&$controlRead['task_id']===201,
        'actual foreign keys cascade only deleted conversation bindings and receipts');
}catch(Throwable $error){
    $failed=true;
    $state=$error instanceof PDOException?(string)($error->errorInfo[0]??$error->getCode()):'';
    $driver=$error instanceof PDOException?(string)($error->errorInfo[1]??''):'';
    $label=$error instanceof RuntimeException&&!($error instanceof PDOException)?$error->getMessage():get_class($error);
    $label=preg_match('/\A[A-Za-z0-9 _-]{1,160}\z/D',$label)===1?$label:'fixture_assertion';
    fwrite(STDERR,'FAIL native MySQL promise '.$label.' sqlstate='.(preg_match('/\A[A-Z0-9]{5}\z/D',$state)?$state:'unknown').' driver='.(ctype_digit($driver)?$driver:'unknown')."\n");
}finally{
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    if($owned&&$pdo instanceof PDO)foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
}
if($failed)exit(1);
