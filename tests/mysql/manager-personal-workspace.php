<?php

declare(strict_types=1);

// Optional locally; mandatory in CI's explicitly configured ephemeral MySQL8.
// Never discover credentials or reuse an application database/configuration.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$dsn=getenv('PERSONAL_WORKSPACE_TEST_MYSQL_DSN');
if (!is_string($dsn)||$dsn==='') {echo "SKIP native personal workspace MySQL fixture is not configured\n";exit(0);}
if (!preg_match('/\Amysql:host=(127\.0\.0\.1|localhost|mysql);(?:port=([0-9]{1,5});)?dbname=manager_personal_workspace_test;charset=utf8mb4\z/D',$dsn)) {
    fwrite(STDERR,"Refusing non-ephemeral personal workspace MySQL configuration\n");exit(1);
}
$user=getenv('PERSONAL_WORKSPACE_TEST_MYSQL_USER');$password=getenv('PERSONAL_WORKSPACE_TEST_MYSQL_PASSWORD');
if (!is_string($user)||$user===''||!is_string($password)) {fwrite(STDERR,"Explicit native fixture user/password are required\n");exit(1);}
require_once dirname(__DIR__,2).'/services/ManagerPersonalWorkspaceService.php';
require_once dirname(__DIR__,2).'/services/MigrationRunner.php';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
try {$pdo=new PDO($dsn,$user,$password,$options);} catch (Throwable $error) {fwrite(STDERR,"Native personal workspace fixture connection failed\n");exit(1);}
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='manager_personal_workspace_test') {fwrite(STDERR,"Refusing unexpected native fixture schema\n");exit(1);}
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);

// Workers have no DDL path. Require the exact private fixture identity before
// performing commands against the tables deliberately created by this test.
if (($argv[1]??'')==='--worker') {
    if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==3
        ||(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('managers','manager_personal_workspace_states','manager_personal_workspace_operations') AND engine='InnoDB'")->fetchColumn()!==3
        ||$pdo->query('SELECT fixture_marker FROM managers WHERE id=1')->fetchColumn()!=='personal-workspace-native-fixture') {
        fwrite(STDERR,"Refusing non-fixture native worker schema\n");exit(1);
    }
    $command=json_decode((string)($argv[2]??''),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($command)||!is_int($command['actor']??null)||!in_array($command['actor'],[1,2],true)
        ||!in_array($command['action']??null,['label_save','settings'],true)||!is_array($command['input']??null)) {
        fwrite(STDERR,"Refusing invalid synthetic worker command\n");exit(1);
    }
    echo "READY\n";fflush(STDOUT);
    if (fgets(STDIN)!=="start\n") {fwrite(STDERR,"Missing native worker start barrier\n");exit(1);}
    $result=ManagerPersonalWorkspaceService::mutate($command['actor'],$command['action'],$command['input']);
    echo json_encode($result,JSON_THROW_ON_ERROR);exit(0);
}

// This check precedes EVERY DDL statement, including fixture setup/migration.
if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0
    ||(int)$pdo->query('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE()')->fetchColumn()!==0
    ||(int)$pdo->query('SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE()')->fetchColumn()!==0) {
    fwrite(STDERR,"Refusing non-empty native personal workspace fixture schema\n");exit(1);
}
$pdo->exec("CREATE TABLE managers (id BIGINT UNSIGNED NOT NULL PRIMARY KEY,is_active TINYINT NOT NULL,fixture_marker VARCHAR(64) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO managers VALUES (1,1,'personal-workspace-native-fixture'),(2,1,'personal-workspace-native-fixture'),(3,0,'personal-workspace-native-fixture')");
$migration=(string)file_get_contents(dirname(__DIR__,2).'/migrations/034_manager_personal_workspace.sql');
$runner=new MigrationRunner(dirname(__DIR__,2).'/migrations',$pdo);
$splitter=new ReflectionMethod(MigrationRunner::class,'splitStatements');
foreach ($splitter->invoke($runner,$migration) as $statement) $pdo->exec($statement);
$passed=0;
function nativeWorkspaceCheck(string $label,bool $ok): void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$label);$passed++;echo 'PASS '.$label."\n";}
function nativeWorkspaceMutate(int $actor,string $action,array $input=[]): array
{
    static $seq=0;$input+=['expected_version'=>ManagerPersonalWorkspaceService::snapshot($actor)['version'],'operation_id'=>'native-workspace-command-'.str_pad((string)++$seq,6,'0',STR_PAD_LEFT)];
    return ManagerPersonalWorkspaceService::mutate($actor,$action,$input);
}

/** Start genuinely independent PDO/PHP processes and release their shared barrier. */
function nativeWorkspaceRace(array $commands): array
{
    $processes=[];$pipes=[];$results=[];
    try {
        foreach ($commands as $index=>$command) {
            $processes[$index]=proc_open([PHP_BINARY,__FILE__,'--worker',json_encode($command,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes[$index]);
            if (!is_resource($processes[$index])) throw new RuntimeException('Cannot create native isolated PDO worker');
            stream_set_timeout($pipes[$index][1],15);stream_set_timeout($pipes[$index][2],15);
        }
        foreach ($pipes as $workerPipes) if (fgets($workerPipes[1])!=="READY\n") throw new RuntimeException('Native isolated PDO worker not ready');
        foreach ($pipes as $workerPipes) {fwrite($workerPipes[0],"start\n");fclose($workerPipes[0]);}
        foreach ($pipes as $index=>$workerPipes) {
            $body=stream_get_contents($workerPipes[1]);$error=stream_get_contents($workerPipes[2]);fclose($workerPipes[1]);fclose($workerPipes[2]);
            $exit=proc_close($processes[$index]);$processes[$index]=null;
            if ($exit!==0||$error!=='') throw new RuntimeException('Native isolated PDO worker failed');
            $results[]=json_decode((string)$body,true,32,JSON_THROW_ON_ERROR);
        }
    } finally {
        foreach ($processes as $index=>$worker) if (is_resource($worker)) {
            proc_terminate($worker);
            foreach ($pipes[$index]??[] as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($worker);
        }
    }
    return $results;
}

nativeWorkspaceCheck('actual migration034 creates two private InnoDB tables',(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('manager_personal_workspace_states','manager_personal_workspace_operations') AND engine='InnoDB'")->fetchColumn()===2);
nativeWorkspaceCheck('native receipt primary key is binary case-sensitive',$pdo->query("SELECT collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='manager_personal_workspace_operations' AND column_name='operation_id'")->fetchColumn()==='ascii_bin');
$empty=ManagerPersonalWorkspaceService::snapshot(1);
nativeWorkspaceCheck('native empty read keeps default version0 and performs no account initialization',$empty['ok']&&$empty['version']===0&&$empty['state']['tabs']===[]&&(int)$pdo->query('SELECT COUNT(*) FROM manager_personal_workspace_states')->fetchColumn()===0);
nativeWorkspaceCheck('native fresh disabled actor is rejected',ManagerPersonalWorkspaceService::snapshot(3)['error']==='unauthorized');
$label=nativeWorkspaceMutate(1,'label_save',['name'=>'Метка клиента']);$labelId=$label['id'];
$create=['id'=>0,'tab'=>['name'=>'Личная вкладка','queue'=>'mine','label_id'=>$labelId],'expected_version'=>$label['version'],'operation_id'=>'native-tab-create-stable-01'];
$tab=nativeWorkspaceMutate(1,'tab_save',$create);
nativeWorkspaceCheck('native tab and label writes allocate positive IDs and advance monotonic revision',$label['ok']&&$tab['ok']&&$labelId===1&&$tab['id']===1&&$tab['version']===2&&$tab['state']['tabs'][0]['label_id']===$labelId);
$replay=nativeWorkspaceMutate(1,'tab_save',$create);
nativeWorkspaceCheck('native same-key replay allocates exactly once',$replay==$tab&&(int)$pdo->query('SELECT COUNT(*) FROM manager_personal_workspace_operations WHERE manager_id=1')->fetchColumn()===2&&count($replay['state']['tabs'])===1);
$settings=nativeWorkspaceMutate(1,'settings',['settings'=>['start_tab'=>'tab:'.$tab['id'],'density'=>'compact','font'=>'large']]);
$oldReceipt=ManagerPersonalWorkspaceService::operation(1,$create['operation_id']);$oldReplay=nativeWorkspaceMutate(1,'tab_save',$create);
nativeWorkspaceCheck('native old receipt and retry include current state never old preferences',$settings['version']===3&&$oldReceipt['found']&&$oldReceipt['result']['version']===3&&$oldReceipt['result']['state']==$settings['state']&&$oldReplay['state']==$settings['state']);
$mismatch=$create;$mismatch['tab']['name']='Другое поручение';
nativeWorkspaceCheck('native operation-key command mismatch is rejected',nativeWorkspaceMutate(1,'tab_save',$mismatch)['error']==='operation_mismatch');
$conflict=nativeWorkspaceMutate(1,'settings',['settings'=>['font'=>'normal'],'expected_version'=>1]);
nativeWorkspaceCheck('native stale version returns current state without lost update',$conflict['error']==='conflict'&&$conflict['version']===3&&$conflict['state']==$settings['state']);
$other=nativeWorkspaceMutate(2,'label_save',['name'=>'Отдельная метка']);
nativeWorkspaceCheck('native preferences and receipts are isolated by manager primary key',$other['version']===1&&$other['state']['tabs']===[]&&!ManagerPersonalWorkspaceService::operation(2,$create['operation_id'])['found']&&ManagerPersonalWorkspaceService::snapshot(1)['version']===3);
$tab2=nativeWorkspaceMutate(1,'tab_save',['tab'=>['name'=>'Вторая вкладка','queue'=>'all']]);
$order=nativeWorkspaceMutate(1,'tab_reorder',['ids'=>[$tab2['id'],$tab['id']]]);
nativeWorkspaceCheck('native full reorder preserves account collection membership',array_column($order['state']['tabs'],'id')===[$tab2['id'],$tab['id']]);
$deleted=nativeWorkspaceMutate(1,'label_delete',['id'=>$labelId]);
nativeWorkspaceCheck('native deleted label leaves tombstone filter rather than broadening collection',$deleted['state']['tabs'][1]['label_id']===$labelId&&$deleted['state']['labels']===[]);
nativeWorkspaceMutate(1,'tab_delete',['id'=>$tab['id']]);$fresh=nativeWorkspaceMutate(1,'tab_save',['tab'=>['name'=>'После удаления']]);
nativeWorkspaceCheck('native deletion resets startup safely and never reuses allocated ID',$fresh['state']['settings']['start_tab']==='waiting'&&$fresh['id']>$tab2['id']);

$version=ManagerPersonalWorkspaceService::snapshot(1)['version'];
$race=nativeWorkspaceRace([
    ['actor'=>1,'action'=>'settings','input'=>['settings'=>['font'=>'normal'],'expected_version'=>$version,'operation_id'=>'native-version-race-first-01']],
    ['actor'=>1,'action'=>'settings','input'=>['settings'=>['density'=>'comfortable'],'expected_version'=>$version,'operation_id'=>'native-version-race-other-01']],
]);
$success=array_values(array_filter($race,static fn(array $result):bool=>!empty($result['ok'])));
$conflicts=array_values(array_filter($race,static fn(array $result):bool=>($result['error']??'')==='conflict'));
$confirmed=ManagerPersonalWorkspaceService::snapshot(1);
nativeWorkspaceCheck('two native independent writers with same version serialize one commit one conflict',count($success)===1&&count($conflicts)===1&&$confirmed['version']===$version+1&&$success[0]['state']==$confirmed['state']&&$conflicts[0]['state']==$confirmed['state']);
nativeWorkspaceCheck('native version race commits outcome receipts for both commands',(int)$pdo->query("SELECT COUNT(*) FROM manager_personal_workspace_operations WHERE manager_id=1 AND operation_id IN ('native-version-race-first-01','native-version-race-other-01') AND result_json IS NOT NULL")->fetchColumn()===2);
$sameCommand=['actor'=>2,'action'=>'label_save','input'=>['id'=>0,'name'=>'Общая повторная команда','expected_version'=>$other['version'],'operation_id'=>'native-parallel-same-command-01']];
$duplicates=nativeWorkspaceRace([$sameCommand,$sameCommand]);$afterDuplicate=ManagerPersonalWorkspaceService::snapshot(2);
nativeWorkspaceCheck('two native same-key writers return one allocation and one committed receipt',$duplicates[0]['ok']&&$duplicates[1]['ok']&&$duplicates[0]['id']===$duplicates[1]['id']&&$duplicates[0]['state']==$duplicates[1]['state']&&$afterDuplicate['version']===$other['version']+1&&count($afterDuplicate['state']['labels'])===2&&(int)$pdo->query("SELECT COUNT(*) FROM manager_personal_workspace_operations WHERE manager_id=2 AND operation_id='native-parallel-same-command-01'")->fetchColumn()===1);

$pdo->exec("CREATE TRIGGER personal_workspace_reject_receipt BEFORE UPDATE ON manager_personal_workspace_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic receipt failure'");
$beforeRollback=ManagerPersonalWorkspaceService::snapshot(2);$rolledBack=false;$rollback=['name'=>'Откат','expected_version'=>$beforeRollback['version'],'operation_id'=>'native-rollback-operation-01'];
try {nativeWorkspaceMutate(2,'label_save',$rollback);} catch (PDOException $error) {$rolledBack=true;}
nativeWorkspaceCheck('native receipt failure rolls back allocation revision and UNKNOWN receipt',$rolledBack&&ManagerPersonalWorkspaceService::snapshot(2)==$beforeRollback&&!ManagerPersonalWorkspaceService::operation(2,$rollback['operation_id'])['found']);
$pdo->exec('DROP TRIGGER personal_workspace_reject_receipt');
$retry=nativeWorkspaceMutate(2,'label_save',$rollback);
nativeWorkspaceCheck('native same command after confirmed rollback commits once',$retry['ok']&&nativeWorkspaceMutate(2,'label_save',$rollback)['id']===$retry['id']);
nativeWorkspaceCheck('native workspace preferences never write manager business fields',(int)$pdo->query("SELECT COUNT(*) FROM managers WHERE fixture_marker='personal-workspace-native-fixture'")->fetchColumn()===3&&(int)$pdo->query('SELECT SUM(is_active) FROM managers')->fetchColumn()===2);
echo 'NATIVE PERSONAL WORKSPACE MYSQL OK ('.$passed." checks)\n";
