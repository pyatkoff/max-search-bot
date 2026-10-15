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
        ||!in_array($command['action']??null,['label_save','settings','group_create'],true)||!is_array($command['input']??null)) {
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
/** Worker JSON turns members:{} into an associative array. Compare the same
 * decoded transport representation, retaining strict scalar values and order. */
function nativeWorkspaceEqual($left,$right): bool
{
    return json_decode(json_encode($left,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR)
        ===json_decode(json_encode($right,JSON_THROW_ON_ERROR),true,32,JSON_THROW_ON_ERROR);
}
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
nativeWorkspaceCheck('native same-key replay allocates exactly once',nativeWorkspaceEqual($replay,$tab)&&(int)$pdo->query('SELECT COUNT(*) FROM manager_personal_workspace_operations WHERE manager_id=1')->fetchColumn()===2&&count($replay['state']['tabs'])===1);
$settings=nativeWorkspaceMutate(1,'settings',['settings'=>['start_tab'=>'tab:'.$tab['id'],'density'=>'compact','font'=>'large']]);
$oldReceipt=ManagerPersonalWorkspaceService::operation(1,$create['operation_id']);$oldReplay=nativeWorkspaceMutate(1,'tab_save',$create);
nativeWorkspaceCheck('native old receipt and retry include current state never old preferences',$settings['version']===3&&$oldReceipt['found']&&$oldReceipt['result']['version']===3&&nativeWorkspaceEqual($oldReceipt['result']['state'],$settings['state'])&&nativeWorkspaceEqual($oldReplay['state'],$settings['state']));
$mismatch=$create;$mismatch['tab']['name']='Другое поручение';
nativeWorkspaceCheck('native operation-key command mismatch is rejected',nativeWorkspaceMutate(1,'tab_save',$mismatch)['error']==='operation_mismatch');
$conflict=nativeWorkspaceMutate(1,'settings',['settings'=>['font'=>'normal'],'expected_version'=>1]);
nativeWorkspaceCheck('native stale version returns current state without lost update',$conflict['error']==='conflict'&&$conflict['version']===3&&nativeWorkspaceEqual($conflict['state'],$settings['state']));
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
$sameSuccess=count($success)===1&&nativeWorkspaceEqual($success[0]['state'],$confirmed['state']);
$sameConflict=count($conflicts)===1&&nativeWorkspaceEqual($conflicts[0]['state'],$confirmed['state']);
echo 'EVIDENCE native version race '.json_encode(['successes'=>count($success),'conflicts'=>count($conflicts),'before_version'=>$version,'stored_version'=>$confirmed['version'],'success_state_matches'=>$sameSuccess,'conflict_state_matches'=>$sameConflict],JSON_THROW_ON_ERROR)."\n";
nativeWorkspaceCheck('two native independent writers with same version serialize one commit one conflict',count($success)===1&&count($conflicts)===1&&$confirmed['version']===$version+1&&$success[0]['version']===$confirmed['version']&&$conflicts[0]['version']===$confirmed['version']&&$sameSuccess&&$sameConflict);
nativeWorkspaceCheck('native version race commits outcome receipts for both commands',(int)$pdo->query("SELECT COUNT(*) FROM manager_personal_workspace_operations WHERE manager_id=1 AND operation_id IN ('native-version-race-first-01','native-version-race-other-01') AND result_json IS NOT NULL")->fetchColumn()===2);
$sameCommand=['actor'=>2,'action'=>'label_save','input'=>['id'=>0,'name'=>'Общая повторная команда','expected_version'=>$other['version'],'operation_id'=>'native-parallel-same-command-01']];
$duplicates=nativeWorkspaceRace([$sameCommand,$sameCommand]);$afterDuplicate=ManagerPersonalWorkspaceService::snapshot(2);
nativeWorkspaceCheck('two native same-key writers return one allocation and one committed receipt',$duplicates[0]['ok']&&$duplicates[1]['ok']&&$duplicates[0]['id']===$duplicates[1]['id']&&nativeWorkspaceEqual($duplicates[0]['state'],$duplicates[1]['state'])&&nativeWorkspaceEqual($duplicates[0]['state'],$afterDuplicate['state'])&&$duplicates[0]['version']===$afterDuplicate['version']&&$duplicates[1]['version']===$afterDuplicate['version']&&$afterDuplicate['version']===$other['version']+1&&count($afterDuplicate['state']['labels'])===2&&(int)$pdo->query("SELECT COUNT(*) FROM manager_personal_workspace_operations WHERE manager_id=2 AND operation_id='native-parallel-same-command-01'")->fetchColumn()===1);

$groupCommand=['actor'=>2,'action'=>'group_create','input'=>['name'=>'Подбор','expected_version'=>$afterDuplicate['version'],'operation_id'=>'native-parallel-same-group-01']];
$groups=nativeWorkspaceRace([$groupCommand,$groupCommand]);$groupState=ManagerPersonalWorkspaceService::snapshot(2);
$nativeGroupId=$groups[0]['id'];$nativeGroupLabel=$groups[0]['label_id'];
nativeWorkspaceCheck('two native same-key group creates commit one bound pair in one revision',$groups[0]['ok']&&$groups[1]['ok']&&$groups[0]['id']===$groups[1]['id']&&$groups[0]['label_id']===$groups[1]['label_id']&&nativeWorkspaceEqual($groups[0]['state'],$groups[1]['state'])&&$groupState['version']===$afterDuplicate['version']+1&&count($groupState['state']['tabs'])===1&&count($groupState['state']['labels'])===3&&$groupState['state']['tabs'][0]['kind']==='group'&&$groupState['state']['tabs'][0]['group_label_id']===$nativeGroupLabel&&$groupState['state']['tabs'][0]['queues']===['all','closed']&&(int)$pdo->query("SELECT COUNT(*) FROM manager_personal_workspace_operations WHERE manager_id=2 AND operation_id='native-parallel-same-group-01'")->fetchColumn()===1);
$groupBeforeRace=ManagerPersonalWorkspaceService::snapshot(1);
$groupRace=nativeWorkspaceRace([
    ['actor'=>1,'action'=>'group_create','input'=>['name'=>'Первая группа','expected_version'=>$groupBeforeRace['version'],'operation_id'=>'native-different-group-first-01']],
    ['actor'=>1,'action'=>'group_create','input'=>['name'=>'Вторая группа','expected_version'=>$groupBeforeRace['version'],'operation_id'=>'native-different-group-other-01']],
]);
$groupCommitted=ManagerPersonalWorkspaceService::snapshot(1);
nativeWorkspaceCheck('two native distinct group writers serialize one pair and one conflict',count(array_filter($groupRace,static fn(array $row):bool=>!empty($row['ok'])))===1&&count(array_filter($groupRace,static fn(array $row):bool=>($row['error']??'')==='conflict'))===1&&$groupCommitted['version']===$groupBeforeRace['version']+1&&count($groupCommitted['state']['tabs'])===count($groupBeforeRace['state']['tabs'])+1&&count($groupCommitted['state']['labels'])===count($groupBeforeRace['state']['labels'])+1);

$pdo->exec("CREATE TRIGGER personal_workspace_reject_receipt BEFORE UPDATE ON manager_personal_workspace_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic receipt failure'");
$beforeRollback=ManagerPersonalWorkspaceService::snapshot(2);$rolledBack=false;$rollback=['name'=>'Откат','expected_version'=>$beforeRollback['version'],'operation_id'=>'native-rollback-operation-01'];
try {nativeWorkspaceMutate(2,'label_save',$rollback);} catch (PDOException $error) {$rolledBack=true;}
nativeWorkspaceCheck('native receipt failure rolls back allocation revision and UNKNOWN receipt',$rolledBack&&nativeWorkspaceEqual(ManagerPersonalWorkspaceService::snapshot(2),$beforeRollback)&&!ManagerPersonalWorkspaceService::operation(2,$rollback['operation_id'])['found']);
$rolledBack=false;$groupRollback=['name'=>'Откат группы','expected_version'=>$beforeRollback['version'],'operation_id'=>'native-group-rollback-operation-01'];
try {nativeWorkspaceMutate(2,'group_create',$groupRollback);} catch (PDOException $error) {$rolledBack=true;}
nativeWorkspaceCheck('native failed group receipt rolls back both allocations and revision',$rolledBack&&nativeWorkspaceEqual(ManagerPersonalWorkspaceService::snapshot(2),$beforeRollback)&&!ManagerPersonalWorkspaceService::operation(2,$groupRollback['operation_id'])['found']);
$pdo->exec('DROP TRIGGER personal_workspace_reject_receipt');
$retry=nativeWorkspaceMutate(2,'label_save',$rollback);
nativeWorkspaceCheck('native same command after confirmed rollback commits once',$retry['ok']&&nativeWorkspaceMutate(2,'label_save',$rollback)['id']===$retry['id']);
nativeWorkspaceCheck('native workspace preferences never write manager business fields',(int)$pdo->query("SELECT COUNT(*) FROM managers WHERE fixture_marker='personal-workspace-native-fixture'")->fetchColumn()===3&&(int)$pdo->query('SELECT SUM(is_active) FROM managers')->fetchColumn()===2);

// Native prepared-query coverage after the isolated concurrency workers finish.
// Every table below belongs to the same explicitly empty ephemeral schema gate.
$pdo->exec("ALTER TABLE managers ADD login VARCHAR(64) NOT NULL DEFAULT 'fixture',ADD display_name VARCHAR(64) NOT NULL DEFAULT 'Synthetic manager',ADD role VARCHAR(16) NOT NULL DEFAULT 'manager',ADD email VARCHAR(64) NOT NULL DEFAULT '',ADD is_working TINYINT NOT NULL DEFAULT 1");
foreach ([
    'projects(id INT PRIMARY KEY,project_key VARCHAR(64),display_name VARCHAR(64),is_active TINYINT)',
    'manager_projects(manager_id INT,project_id INT)',
    'customers(id INT PRIMARY KEY,display_name VARCHAR(64),phone VARCHAR(32),email VARCHAR(64))',
    'manager_customer_names(customer_id INT PRIMARY KEY,working_name VARCHAR(64),version INT)',
    'conversations(id INT PRIMARY KEY,customer_id INT,project_key VARCHAR(64),source_id INT,channel VARCHAR(16),entry_channel VARCHAR(64),attribution_region VARCHAR(32),attribution_campaign VARCHAR(32),status VARCHAR(32),lead_stage_key VARCHAR(32),manager_id INT,started_at DATETIME,last_message_at DATETIME,closed_at DATETIME,is_test TINYINT,external_chat_id VARCHAR(64),lead_outcome VARCHAR(16) DEFAULT \'open\',lead_close_reason VARCHAR(32),lead_outcome_note TEXT,lead_sale_amount DECIMAL(12,2),lead_sale_date DATE)',
    'conversation_sources(id INT PRIMARY KEY,project_id INT,display_name VARCHAR(64),primary_group_id INT,fallback_mode VARCHAR(16),fallback_group_id INT,fallback_after_minutes INT,is_active TINYINT)',
    'manager_group_members(manager_id INT,group_id INT)',
    'conversation_events(id INT PRIMARY KEY,conversation_id INT,event_type VARCHAR(64),created_at DATETIME,payload_json TEXT,actor_type VARCHAR(16),KEY(conversation_id,id))',
    'messages(id INT PRIMARY KEY,conversation_id INT,direction VARCHAR(16),sender_type VARCHAR(16),text TEXT,metadata_json TEXT,created_at DATETIME,KEY(conversation_id,id))',
    'manager_conversation_reads(manager_id INT,conversation_id INT,last_read_message_id INT,PRIMARY KEY(manager_id,conversation_id))',
    'lead_stages(stage_key VARCHAR(32) PRIMARY KEY,display_name VARCHAR(64),color VARCHAR(16),sort_order INT,is_active TINYINT,is_terminal TINYINT,is_won TINYINT)',
    'lead_tags(id INT PRIMARY KEY,tag_key VARCHAR(32),display_name VARCHAR(64),color VARCHAR(16),sort_order INT,is_active TINYINT)',
    'conversation_lead_tags(conversation_id INT,tag_id INT,PRIMARY KEY(conversation_id,tag_id))',
    'lead_stage_history(id INT PRIMARY KEY,conversation_id INT,created_at DATETIME)',
    'lead_tasks(id INT PRIMARY KEY,conversation_id INT,title VARCHAR(128),due_at_utc DATETIME,is_pinned TINYINT,status VARCHAR(16))',
    'lead_close_reasons(reason_key VARCHAR(32),display_name VARCHAR(64),sort_order INT,is_active TINYINT)',
] as $table) $pdo->exec('CREATE TABLE '.$table.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
foreach ([ProjectAccessService::class,ManagerAuthService::class,RoutingAccessService::class] as $class) (new ReflectionProperty($class,'schemaReady'))->setValue(null,true);
$pdo->exec("INSERT INTO projects VALUES(1,'native-personal','Synthetic personal',1),(2,'native-denied','Synthetic denied',1);
INSERT INTO manager_projects VALUES(1,1),(2,1);
INSERT INTO customers VALUES(1,'Synthetic client','','');
INSERT INTO conversation_sources VALUES(1,1,'First',0,'none',0,0,1),(2,1,'Second',0,'none',0,0,1),(3,2,'Denied',0,'none',0,0,1),(4,1,'Inactive',0,'none',0,0,0)");
$insert=$pdo->prepare("INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,lead_stage_key,manager_id,started_at,last_message_at,is_test) VALUES(?,1,'native-personal',?,?,?,'new',?,'2026-10-10 00:00:00',?,0)");
$insert->execute([1,1,'max','manager',1,'2026-10-10 00:00:01']);
$insert->execute([2,2,'telegram','closed',1,'2026-10-10 00:00:02']);
$insert->execute([3,2,'telegram','waiting_manager',null,'2026-10-10 00:00:03']);
$insert->execute([4,2,'website','closed',1,'2026-10-10 00:00:04']);
$insert->execute([5,1,'max','manager',2,'2026-10-10 00:00:05']);
for ($id=10;$id<240;$id++) $insert->execute([$id,1,'website','ai',null,'2026-10-14 00:00:00']);
$nativeFirst=nativeWorkspaceMutate(1,'label_save',['name'=>'Первая выбранная']);$nativeFirstId=$nativeFirst['id'];
$nativeSecond=nativeWorkspaceMutate(1,'label_save',['name'=>'Вторая выбранная']);$nativeSecondId=$nativeSecond['id'];
foreach ([[1,$nativeFirstId],[2,$nativeSecondId],[4,$nativeSecondId]] as [$conversationId,$selectedLabel]) nativeWorkspaceMutate(1,'label_membership',['conversation_id'=>$conversationId,'label_id'=>$selectedLabel,'enabled'=>true]);
$multiConditions=['name'=>'Несколько условий MySQL','queues'=>['mine','closed'],'channels'=>['max','telegram'],'source_ids'=>[1,2],'label_ids'=>[$nativeFirstId,$nativeSecondId]];
$nativeMulti=nativeWorkspaceMutate(1,'tab_save',['tab'=>$multiConditions]);$nativeMultiId=$nativeMulti['id'];
$selection=['selection'=>'tab:'.$nativeMultiId];$nativeRows=ManagerPersonalWorkspaceService::list(1,$selection);
nativeWorkspaceCheck('native prepared SQL applies queue channel source and label unions before200 newer unmatched rows',array_map('intval',array_column($nativeRows['conversations'],'id'))===[2,1]&&!$nativeRows['limited']);
$nativeSnapshot=ManagerPersonalWorkspaceService::snapshot(1);$nativeSaved=array_values(array_filter($nativeSnapshot['state']['tabs'],static fn(array $item):bool=>$item['id']===$nativeMultiId))[0];
nativeWorkspaceCheck('native JSON preserves plural selectors and stable legacy tab',$nativeSaved['queues']===['mine','closed']&&$nativeSaved['channels']===['max','telegram']&&$nativeSaved['source_ids']===[1,2]&&$nativeSaved['label_ids']===[$nativeFirstId,$nativeSecondId]&&nativeWorkspaceEqual(array_values(array_filter($nativeSnapshot['state']['tabs'],static fn(array $item):bool=>$item['id']===$tab2['id']))[0],array_values(array_filter($tab2['state']['tabs'],static fn(array $item):bool=>$item['id']===$tab2['id']))[0]));
nativeWorkspaceCheck('native external selectors intersect the saved union',array_map('intval',array_column(ManagerPersonalWorkspaceService::list(1,$selection+['channel'=>'telegram','source_id'=>2])['conversations'],'id'))===[2]&&ManagerPersonalWorkspaceService::list(1,$selection+['source_id'=>-1])['conversations']===[]&&ManagerPersonalWorkspaceService::list(1,$selection+['channel'=>'website'])['conversations']===[]);
nativeWorkspaceMutate(1,'pin',['conversation_id'=>1,'enabled'=>true]);
nativeWorkspaceMutate(1,'tab_save',['id'=>$nativeMultiId,'tab'=>$multiConditions+['pinned_only'=>true]]);
nativeWorkspaceCheck('native pinned condition intersects labels rather than adding another union',array_map('intval',array_column(ManagerPersonalWorkspaceService::list(1,$selection)['conversations'],'id'))===[1]);
nativeWorkspaceMutate(1,'tab_save',['id'=>$nativeMultiId,'tab'=>$multiConditions]);
nativeWorkspaceMutate(1,'label_delete',['id'=>$nativeSecondId]);
nativeWorkspaceCheck('native deleted label contributes empty branch with remaining label intact',array_map('intval',array_column(ManagerPersonalWorkspaceService::list(1,$selection)['conversations'],'id'))===[1]);
nativeWorkspaceMutate(1,'label_delete',['id'=>$nativeFirstId]);
nativeWorkspaceCheck('native all removed labels never broaden the saved collection',ManagerPersonalWorkspaceService::list(1,$selection)['conversations']===[]);
$multiConditions['label_ids']=[];$multiConditions['channels']=[];$multiConditions['source_ids']=[];$multiConditions['queues']=['waiting','closed'];
nativeWorkspaceMutate(1,'tab_save',['id'=>$nativeMultiId,'tab'=>$multiConditions]);
nativeWorkspaceCheck('native queue union selects closed and waiting before unmatched noise cap',array_map('intval',array_column(ManagerPersonalWorkspaceService::list(1,$selection)['conversations'],'id'))===[4,3,2]);
nativeWorkspaceCheck('native all selected sources require current active access',nativeWorkspaceMutate(1,'tab_save',['id'=>$nativeMultiId,'tab'=>array_replace($multiConditions,['source_ids'=>[1,3]])])['error']==='forbidden'&&nativeWorkspaceMutate(1,'tab_save',['id'=>$nativeMultiId,'tab'=>array_replace($multiConditions,['source_ids'=>[1,4]])])['error']==='forbidden');
$nativeOverlap=ManagerConversationService::list(1,'all',200,'*','','',0,['queues'=>['mine','all']]);
nativeWorkspaceCheck('native overlapping queues are one bounded unique page',count($nativeOverlap)===200&&count(array_unique(array_column($nativeOverlap,'id'),SORT_REGULAR))===200&&!in_array(5,array_map('intval',array_column($nativeOverlap,'id')),true));
$pdo->exec("UPDATE managers SET role='admin' WHERE id=2");
nativeWorkspaceCheck('native saved Mine intersects admin manager filter without changing ordinary Mine',array_map('intval',array_column(ManagerConversationService::list(2,'all',200,'*','','',0,['queues'=>['mine']]),'id'))===[5]&&ManagerConversationService::list(2,'all',200,'*','1','',0,['queues'=>['mine']])===[]&&array_map('intval',array_column(ManagerConversationService::list(2,'mine',200,'*','1'),'id'))===[5]);

foreach ([1,2,3] as $conversationId) nativeWorkspaceMutate(2,'group_membership',['id'=>$nativeGroupId,'conversation_id'=>$conversationId,'enabled'=>true]);
$groupRows=ManagerPersonalWorkspaceService::list(2,['selection'=>'tab:'.$nativeGroupId]);
nativeWorkspaceCheck('native group selected IDs include active closed and waiting before unrelated cap',array_map('intval',array_column($groupRows['conversations'],'id'))===[3,2,1]&&!$groupRows['limited']&&$groupRows['bounded_count']===3);
$groupVersion=ManagerPersonalWorkspaceService::snapshot(2)['version'];
$pdo->exec("INSERT INTO messages VALUES(1001,1,'outbound','manager','Synthetic reply','{}','2026-10-15 12:00:00'); INSERT INTO manager_conversation_reads VALUES(2,1,1001)");
nativeWorkspaceCheck('native reply and read do not change sticky group collection',array_map('intval',array_column(ManagerPersonalWorkspaceService::list(2,['selection'=>'tab:'.$nativeGroupId])['conversations'],'id'))===[3,2,1]&&ManagerPersonalWorkspaceService::snapshot(2)['version']===$groupVersion);
nativeWorkspaceCheck('native generic group editors cannot break marker binding',nativeWorkspaceMutate(2,'tab_save',['id'=>$nativeGroupId,'tab'=>['name'=>'Broken']])['error']==='group_action_required'&&nativeWorkspaceMutate(2,'label_delete',['id'=>$nativeGroupLabel])['error']==='group_action_required');
$renamed=nativeWorkspaceMutate(2,'group_rename',['id'=>$nativeGroupId,'name'=>'В работе']);
$groupReceipt=ManagerPersonalWorkspaceService::operation(2,$groupCommand['input']['operation_id']);
nativeWorkspaceCheck('native group rename and old receipt preserve current pair and memberships',$renamed['ok']&&$renamed['state']['tabs'][0]['name']==='В работе'&&$groupReceipt['found']&&$groupReceipt['result']['id']===$nativeGroupId&&$groupReceipt['result']['label_id']===$nativeGroupLabel&&nativeWorkspaceEqual($groupReceipt['result']['state'],$renamed['state']));
$pdo->exec('DELETE FROM manager_projects WHERE manager_id=2');
nativeWorkspaceCheck('native revoked access hides group rows and blocks membership removal',ManagerPersonalWorkspaceService::list(2,['selection'=>'tab:'.$nativeGroupId])['conversations']===[]&&nativeWorkspaceMutate(2,'group_membership',['id'=>$nativeGroupId,'conversation_id'=>1,'enabled'=>false])['error']==='forbidden');
$pdo->exec('INSERT INTO manager_projects VALUES(2,1)');
nativeWorkspaceMutate(2,'settings',['settings'=>['start_tab'=>'tab:'.$nativeGroupId]]);
$deleted=nativeWorkspaceMutate(2,'group_delete',['id'=>$nativeGroupId]);
nativeWorkspaceCheck('native group deletion clears its own pair and membership with safe startup',$deleted['ok']&&$deleted['state']['tabs']===[]&&!in_array($nativeGroupLabel,array_column($deleted['state']['labels'],'id'),true)&&!isset(((array)$deleted['state']['members'])[$nativeGroupLabel])&&$deleted['state']['settings']['start_tab']==='waiting'&&ManagerPersonalWorkspaceService::list(2,['selection'=>'tab:'.$nativeGroupId])['error']==='not_found');
echo 'NATIVE PERSONAL WORKSPACE MYSQL OK ('.$passed." checks)\n";
