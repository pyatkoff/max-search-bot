<?php
declare(strict_types=1);

// Native locking/atomicity proof in the existing disposable migrations schema.
// The fixture supplies every conversation, receipt and provider result locally.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/support/manager-promise-calculation-fixture.php';

function calculationNativeConnection(): PDO
{
    $dsn = (string) getenv('PREPARED_REPLY_MYSQL_DSN');
    if (preg_match('/\Amysql:host=(?:127\.0\.0\.1|localhost|mysql);(?:port=[0-9]{1,5};)?dbname=maxbot_prepared_test;charset=utf8mb4\z/D', $dsn) !== 1) {
        throw new RuntimeException('calculation_native_fixture_database_required');
    }
    $user = getenv('PREPARED_REPLY_MYSQL_USER');
    $password = getenv('PREPARED_REPLY_MYSQL_PASS');
    if (!is_string($user) || $user === '' || !is_string($password)) {
        throw new RuntimeException('calculation_native_explicit_credentials_required');
    }
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'maxbot_prepared_test') {
        throw new RuntimeException('calculation_native_wrong_database');
    }
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=10');
    return $pdo;
}

function calculationNativeAttach(PDO $pdo): void
{
    (new ReflectionProperty(ConversationDb::class, 'pdo'))->setValue(null, $pdo);
    foreach ([ProjectAccessService::class, ManagerAuthService::class, RoutingAccessService::class] as $class) {
        (new ReflectionProperty($class, 'schemaReady'))->setValue(null, false);
    }
    ProjectConfig::resetForTests(['id' => 'anytour', 'brand' => ['name' => 'AnyTour']]);
}

function calculationNativeCheck(bool $condition, string $label): void
{
    if (preg_match('/\A[A-Za-z0-9 _-]{1,180}\z/D', $label) !== 1) {
        throw new RuntimeException('calculation_native_invalid_assertion_label');
    }
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS native MySQL calculation ' . $label . "\n";
}

/** Only fixed diagnostic fields, never the SQL error message or bound data. */
function calculationNativeSqlFailure(Throwable $error): string
{
    if (!$error instanceof PDOException) return '';
    $state=(string)($error->errorInfo[0]??$error->getCode());
    $driver=(string)($error->errorInfo[1]??'');
    return (preg_match('/\A[A-Z0-9]{5}\z/D',$state)===1?' sqlstate '.$state:'')
        .(preg_match('/\A[0-9]{1,8}\z/D',$driver)===1?' driver '.$driver:'');
}

/** Hold the canonical conversation row and prove that both connections wait. */
function calculationNativeRace(PDO $observer, string $fixtureToken, array $commands, ?callable $beforeRelease = null): array
{
    $children = [];
    $results = [];
    try {
        $observer->beginTransaction();
        $observer->query('SELECT id FROM conversations WHERE id=1 FOR UPDATE')->fetchColumn();
        foreach ($commands as $command) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $fixtureToken],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('calculation_native_worker_start');
            $children[] = ['process' => $process, 'pipes' => $pipes];
            $index = array_key_last($children);
            stream_set_timeout($pipes[1], 15);
            $ready = fgets($pipes[1]);
            if (preg_match('/\AREADY:([0-9]+)\n\z/D', (string) $ready, $match) !== 1) {
                throw new RuntimeException('calculation_native_worker_not_ready');
            }
            $children[$index]['connection'] = (int) $match[1];
            fwrite($pipes[0], json_encode($command, JSON_THROW_ON_ERROR) . "\n");
            fclose($pipes[0]);
        }
        $waiting = [];
        $until = microtime(true) + 7;
        do {
            // InnoDB caches its transaction observation for approximately 100ms.
            usleep(150000);
            foreach ($children as $child) {
                $q = $observer->prepare("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id=? AND trx_state='LOCK WAIT'");
                $q->execute([$child['connection']]);
                if ((int) $q->fetchColumn() === 1) $waiting[$child['connection']] = true;
            }
        } while (count($waiting) !== count($children) && microtime(true) < $until);
        if (count($waiting) !== count($children)) {
            throw new RuntimeException('calculation_native_concurrency_not_observed');
        }
        if ($beforeRelease !== null) $beforeRelease($observer);
        $observer->commit();
        foreach ($children as $index => $child) {
            $line = fgets($child['pipes'][1]);
            $errors = stream_get_contents($child['pipes'][2]);
            fclose($child['pipes'][1]);
            fclose($child['pipes'][2]);
            $status = proc_close($child['process']);
            $children[$index] = null;
            if ($status !== 0 || $errors !== '') {
                if (preg_match('/\AFAIL native calculation worker phase (?:preflight|send|finalize|recover)(?: sqlstate [A-Z0-9]{5})?(?: driver [0-9]{1,8})?\n\z/D',$errors)===1) fwrite(STDERR,$errors);
                throw new RuntimeException('calculation_native_worker_failed');
            }
            $result = json_decode((string) $line, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result)) throw new RuntimeException('calculation_native_worker_result');
            $results[] = $result;
        }
        return $results;
    } finally {
        if ($observer->inTransaction()) $observer->rollBack();
        foreach ($children as $child) {
            if ($child === null) continue;
            proc_terminate($child['process']);
            foreach ($child['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($child['process']);
        }
    }
}

/** These names are owned only after the complete empty-schema preflight. */
function calculationNativeTables(): array
{
    return ['manager_promise_calculation_deliveries','manager_promise_calculation_operations','manager_promise_calculation_intents',
        'manager_promise_selection_operations','manager_promise_selection_intents','manager_task_promise_operations','manager_task_promises',
        'manager_request_profiles','manager_send_attempts','manager_assignments','lead_tasks','manager_conversation_reads','messages',
        'conversation_events','manager_group_members','manager_groups','conversation_sources','conversations','customer_channels','manager_customer_names',
        'customers','manager_projects','managers','projects','manager_group_territory_regions','manager_group_territories','manager_regional_access'];
}

function calculationNativeSchema(PDO $pdo, string $token): void
{
    regionalAccessFixtureSchema($pdo);
    $definitions = [
        'projects' => 'id BIGINT UNSIGNED PRIMARY KEY,project_key VARCHAR(64),display_name TEXT,is_active INT',
        'manager_projects' => 'manager_id BIGINT UNSIGNED,project_id BIGINT UNSIGNED',
        'managers' => 'id BIGINT UNSIGNED PRIMARY KEY,login VARCHAR(100),display_name TEXT,role VARCHAR(32),email TEXT,is_active INT,is_working INT',
        'customers' => 'id BIGINT UNSIGNED PRIMARY KEY,display_name TEXT,phone TEXT,email TEXT',
        'manager_customer_names' => 'customer_id BIGINT UNSIGNED PRIMARY KEY,working_name TEXT,version INT,updated_by_manager_id BIGINT UNSIGNED,updated_at DATETIME',
        'customer_channels' => 'id BIGINT UNSIGNED PRIMARY KEY,customer_id BIGINT UNSIGNED,project_key VARCHAR(64),channel VARCHAR(32),external_user_id VARCHAR(255),external_chat_id VARCHAR(255)',
        'conversations' => 'id BIGINT UNSIGNED PRIMARY KEY,customer_id BIGINT UNSIGNED,customer_channel_id BIGINT UNSIGNED,project_key VARCHAR(64),source_id BIGINT UNSIGNED,channel VARCHAR(32),entry_channel TEXT,attribution_region TEXT,attribution_campaign TEXT,status VARCHAR(32),lead_stage_key VARCHAR(32),manager_id BIGINT UNSIGNED,started_at DATETIME,last_message_at DATETIME,closed_at DATETIME,external_chat_id VARCHAR(255)',
        'conversation_sources' => 'id BIGINT UNSIGNED PRIMARY KEY,display_name TEXT,primary_group_id BIGINT UNSIGNED,fallback_mode VARCHAR(32),fallback_group_id BIGINT UNSIGNED,fallback_after_minutes INT,is_active INT',
        'manager_group_members' => 'manager_id BIGINT UNSIGNED,group_id BIGINT UNSIGNED',
        'manager_groups' => 'id BIGINT UNSIGNED PRIMARY KEY,project_id BIGINT UNSIGNED,display_name VARCHAR(255),is_active INT NOT NULL DEFAULT 1,INDEX calculation_groups(project_id,id)',
        'messages' => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,conversation_id BIGINT UNSIGNED,direction VARCHAR(32),sender_type VARCHAR(32),sender_id VARCHAR(255),channel VARCHAR(32),external_message_id VARCHAR(255),text MEDIUMTEXT,metadata_json MEDIUMTEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,INDEX calculation_messages(conversation_id,id)',
        'manager_conversation_reads' => 'manager_id BIGINT UNSIGNED,conversation_id BIGINT UNSIGNED,last_read_message_id BIGINT UNSIGNED,updated_at DATETIME,PRIMARY KEY(manager_id,conversation_id)',
        'conversation_events' => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,conversation_id BIGINT UNSIGNED,event_type VARCHAR(64),actor_type VARCHAR(32),actor_id VARCHAR(255),payload_json MEDIUMTEXT,created_at DATETIME',
        'manager_assignments' => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,conversation_id BIGINT UNSIGNED,manager_id BIGINT UNSIGNED,assignment_type VARCHAR(32),assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,released_at DATETIME',
        'lead_tasks' => "id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,conversation_id BIGINT UNSIGNED NOT NULL,title TEXT NOT NULL,due_at_utc DATETIME,status VARCHAR(32) NOT NULL DEFAULT 'open',is_pinned INT NOT NULL DEFAULT 0,assigned_manager_id BIGINT UNSIGNED,created_by_manager_id BIGINT UNSIGNED NOT NULL,completed_at_utc DATETIME,reminder_attempted_at_utc DATETIME,reminder_notified_at_utc DATETIME,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL",
    ];
    foreach ($definitions as $table => $definition) {
        $pdo->exec('CREATE TABLE '.$table.'('.$definition.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
    foreach (['059_manager_send_attempts.sql','080_manager_request_profiles.sql','100_manager_media_send_attempts.sql',
        '108_manager_task_promises.sql','109_manager_promise_selections.sql'] as $migration) {
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2).'/migrations/'.$migration));
    }
    promiseCalculationSchema($pdo, true);
    $pdo->exec('CREATE TABLE calculation_native_fixture(id INT PRIMARY KEY,token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO calculation_native_fixture VALUES(1,?)')->execute([$token]);
}

/** Real profile/promise services seed the same synthetic author and task as the shared fixture. */
function calculationNativeSeed(PDO $pdo): void
{
    foreach (calculationNativeTables() as $table) $pdo->exec('DELETE FROM '.$table);
    calculationNativeAttach($pdo);
    $pdo->exec("INSERT INTO projects VALUES(1,'anytour','AnyTour',1);
INSERT INTO manager_projects VALUES(7,1),(8,1);
INSERT INTO managers VALUES(7,'calculation-native-fixture','Synthetic author','manager','',1,1),(8,'colleague','Synthetic colleague','manager','',1,1);
INSERT INTO customers VALUES(1,'Synthetic client','','');
INSERT INTO customer_channels VALUES(1,1,'anytour','telegram','101','101');
INSERT INTO conversation_sources VALUES(1,'Allowed',0,'none',0,0,1);
INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,source_id,channel,status,manager_id,started_at,external_chat_id) VALUES(1,1,1,'anytour',1,'telegram','manager',7,'2026-11-13 10:00:00','101');
INSERT INTO manager_conversation_reads(manager_id,conversation_id,last_read_message_id) VALUES(7,1,0);
INSERT INTO manager_assignments(conversation_id,manager_id,assignment_type) VALUES(1,7,'synthetic');
INSERT INTO lead_tasks(id,conversation_id,title,due_at_utc,status,is_pinned,assigned_manager_id,created_by_manager_id,reminder_attempted_at_utc,reminder_notified_at_utc,created_at,updated_at) VALUES
(101,1,'Отправить расчёт по поездке','2027-06-01 12:00:00','open',0,7,7,'2026-11-13 11:00:00','2026-11-13 11:00:01','2026-11-13 10:00:00','2026-11-13 10:00:00'),
(102,1,'Другая ручная задача',NULL,'open',1,8,7,NULL,NULL,'2026-11-13 10:00:00','2026-11-13 10:00:00');");
    promiseSelectionMessage($pdo,10,'manager','Москва, 5 июня 2027, 7 ночей.');
    promiseSelectionMessage($pdo,11,'customer','Двое взрослых. Даты подходят.');
    promiseSelectionMessage($pdo,12,'manager','Пришлю расчёт по согласованной поездке.');
    ProjectAccessService::initializeReadOnly();
    $view = ManagerRequestProfileService::read(7,1);
    if (empty($view['ok'])) throw new RuntimeException('calculation_native_profile_read');
    $sources = array_column($view['sources'],null,'id');
    $saved = ManagerRequestProfileService::save(7,1,['version'=>$view['profile']['version'],'context_sha256'=>$view['context_sha256'],
        'operation_id'=>str_repeat('b',32),'boundary_message_id'=>10,'new_trip'=>false,'label'=>'Синтетическая поездка','fields'=>[
            'dates'=>['value'=>'5 июня 2027, 7 ночей','kind'=>'manager_interpretation','importance'=>'hard','note'=>'','source_id'=>10,'quote'=>$sources[10]['text']],
            'party'=>['value'=>['adults'=>2,'children'=>0,'child_ages'=>[]],'kind'=>'manager_interpretation','importance'=>'hard','note'=>'','source_id'=>11,'quote'=>$sources[11]['text']],
        ]]);
    if (empty($saved['ok'])) throw new RuntimeException('calculation_native_profile_save');
    $context = ManagerPromiseTaskService::context(7,1,101,12,'send_calculation');
    if (empty($context['ok'])) throw new RuntimeException('calculation_native_binding_context');
    $bound = ManagerPromiseTaskService::bind(7,1,['operation_id'=>str_repeat('c',32),'task_id'=>101,'kind'=>'send_calculation',
        'trip_id'=>$context['profile']['trip_id'],'profile_version'=>$context['profile']['version'],'profile_sha256'=>$context['profile']['sha256'],
        'profile_context_sha256'=>$context['profile']['context_sha256'],'source_message_id'=>12,'source_message_revision'=>$context['source']['revision'],'deadline'=>null]);
    if (empty($bound['ok'])) throw new RuntimeException('calculation_native_binding');
}

function calculationNativeArm(PDO $pdo, array $composition): string
{
    $result = promiseCalculationArm($pdo,$composition);
    if (empty($result['ok']) || ($result['intent']['state']??null)!=='armed') throw new RuntimeException('calculation_native_arm');
    return $result['intent']['intent_id'];
}

/** The callback is the only simulated provider. Never loads transport credentials. */
function calculationNativeSend(PDO $pdo, string $intent, string $operation, string $text, string $mid, bool $defer=false, ?array $file=null, int $part=0, bool $unknown=false): array
{
    $calls=0; $inTransaction=false;
    $send=static function(string $expected, ?callable $accepted, ?array $identity) use ($pdo,$operation,$text,$mid,$defer,$file,$unknown,&$calls,&$inTransaction): array {
        ++$calls; $inTransaction=$pdo->inTransaction();
        if ($inTransaction || $accepted===null) throw new RuntimeException('calculation_native_provider_fence');
        if ($unknown) return ['ok'=>false];
        $attachment=$file===null?null:['type'=>'file','name'=>$file['name'],'mime_type'=>'text/plain'];
        $proof=promiseCalculationNative($pdo,$text,$mid,$attachment);
        if ($identity!==$proof['identity']) throw new RuntimeException('calculation_native_dispatch_identity');
        if ($defer) {
            ManagerPromiseCalculationService::acceptNative(7,1,$operation,$proof);
            throw new RuntimeException('synthetic_stop_after_durable_native_proof');
        }
        $accepted($proof);
        return ['ok'=>true];
    };
    $reference=['intent_id'=>$intent,'part_index'=>$part];
    $result=$file===null
        ?ManagerSendAttemptService::execute(7,1,$operation,$text,null,$send,$reference)
        :ManagerSendAttemptService::executeMedia(7,1,$operation,$file['path'],$file['name'],'text/plain',$text,$send,$reference);
    return ['result'=>$result,'provider_calls'=>$calls,'provider_in_transaction'=>$inTransaction];
}

function calculationNativeWorker(string $token): never
{
    $phase='preflight';
    try {
        if (preg_match('/\A[a-f0-9]{32}\z/D',$token)!==1) throw new RuntimeException('calculation_native_worker_token');
        $pdo=calculationNativeConnection();
        $q=$pdo->prepare('SELECT token FROM calculation_native_fixture WHERE id=1'); $q->execute([]);
        if (!hash_equals($token,(string)$q->fetchColumn()) || $pdo->query('SELECT login FROM managers WHERE id=7')->fetchColumn()!=='calculation-native-fixture') {
            throw new RuntimeException('calculation_native_worker_fixture');
        }
        calculationNativeAttach($pdo);
        ProjectAccessService::initializeReadOnly();
        echo 'READY:'.$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()."\n"; fflush(STDOUT);
        $input=json_decode((string)fgets(STDIN,4096),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($input) || !in_array($input['action']??null,['send','finalize','recover'],true)
            || preg_match('/\A[a-f0-9]{32}\z/D',(string)($input['intent']??''))!==1
            || preg_match('/\A[a-f0-9]{32}\z/D',(string)($input['operation']??''))!==1) throw new RuntimeException('calculation_native_worker_command');
        $phase=$input['action'];
        if ($input['action']==='send') {
            if (!is_string($input['text']??null) || strlen($input['text'])>512) throw new RuntimeException('calculation_native_worker_text');
            $result=calculationNativeSend($pdo,$input['intent'],$input['operation'],$input['text'],'7001');
        } else {
            if ($input['action']==='finalize') ManagerPromiseCalculationService::finalizeNative(7,1,$input['operation']);
            $result=['result'=>$input['action']==='recover'?ManagerPromiseCalculationService::recover(7,1,$input['intent']):ManagerPromiseCalculationService::status(7,1,$input['intent']),
                'provider_calls'=>0,'provider_in_transaction'=>false];
        }
        echo json_encode($result,JSON_THROW_ON_ERROR)."\n"; exit(0);
    } catch (Throwable $error) { fwrite(STDERR,'FAIL native calculation worker phase '.$phase.calculationNativeSqlFailure($error)."\n"); exit(1); }
}

function calculationNativeSnapshot(PDO $pdo): array
{
    $out=[];
    foreach (['manager_promise_calculation_intents'=>'intent_id','manager_promise_calculation_operations'=>'manager_id,operation_id',
        'manager_promise_calculation_deliveries'=>'manager_id,operation_id','manager_send_attempts'=>'manager_id,operation_id',
        'messages'=>'id','conversation_events'=>'id','lead_tasks'=>'id','manager_conversation_reads'=>'manager_id,conversation_id'] as $table=>$order) {
        $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$order)->fetchAll();
    }
    return $out;
}

function calculationNativeState(PDO $pdo, string $intent): array
{
    $result=ManagerPromiseCalculationService::status(7,1,$intent);
    if (empty($result['ok'])) throw new RuntimeException('calculation_native_status');
    return $result['intent'];
}

function calculationNativeCompleted(PDO $pdo, string $intent): bool
{
    $state=calculationNativeState($pdo,$intent); $task=promiseSelectionTask($pdo);
    $proof=json_decode((string)$pdo->query('SELECT evidence_json FROM manager_promise_calculation_intents')->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    return $state['state']==='completed' && $state['confirmed']===$state['total'] && $task['status']==='done'
        && (int)$task['business_revision']===2 && ($proof['task_outcome']??null)==='applied' && ($proof['task_revision']??null)===2
        && count($proof['parts']??[])===$state['total'];
}

if (count($argv)===3 && $argv[1]==='--worker') calculationNativeWorker($argv[2]);
if (count($argv)!==1) throw new RuntimeException('calculation_native_arguments');

$pdo=null; $owned=false; $failed=false; $files=[]; $phase='preflight';
try {
    $pdo=calculationNativeConnection();
    foreach (['TABLES'=>'TABLE_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','EVENTS'=>'EVENT_SCHEMA','TRIGGERS'=>'TRIGGER_SCHEMA'] as $kind=>$column) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.$kind WHERE $column=DATABASE()")->fetchColumn()!==0) {
            throw new RuntimeException('calculation_native_requires_empty_database');
        }
    }
    $owned=true; $phase='schema'; $token=bin2hex(random_bytes(16)); calculationNativeSchema($pdo,$token);
    $phase='seed'; calculationNativeSeed($pdo);
    $engines=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('manager_promise_calculation_intents','manager_promise_calculation_operations','manager_promise_calculation_deliveries') AND ENGINE='InnoDB'")->fetchColumn();
    $nativeKey=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_promise_calculation_deliveries' AND INDEX_NAME='manager_calculation_native_identity' AND NON_UNIQUE=0 AND COLUMN_NAME='native_identity_sha256'")->fetchColumn();
    calculationNativeCheck((int)$engines===3 && (int)$nativeKey===1,'actual migration creates transactional tables and unique native receipt identity');

    $phase='duplicate_send'; $text='Синтетический расчёт 🧳: е́ < 250000 ₽'; $composition=['text'=>$text,'files'=>[]];
    $intent=calculationNativeArm($pdo,$composition); $operation=str_repeat('1',32);
    $other=promiseSelectionTask($pdo,102);
    $command=['action'=>'send','intent'=>$intent,'operation'=>$operation,'text'=>$text];
    $race=calculationNativeRace($pdo,$token,[$command,$command]);
    calculationNativeCheck(array_sum(array_column($race,'provider_calls'))===1 && !in_array(true,array_column($race,'provider_in_transaction'),true)
        && calculationNativeCompleted($pdo,$intent) && (int)$pdo->query('SELECT COUNT(*) FROM manager_send_attempts')->fetchColumn()===1
        && (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE external_message_id='7001'")->fetchColumn()===1
        && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='manager_message'")->fetchColumn()===1
        && promiseSelectionTask($pdo,102)===$other,'contending duplicate send commits one provider call mirror receipt and task completion');
    $row=$pdo->query('SELECT composition_json,HEX(composition_json) AS bytes FROM manager_promise_calculation_intents')->fetch();
    calculationNativeCheck(json_decode($row['composition_json'],true,8,JSON_THROW_ON_ERROR)===$composition
        && strtolower($row['bytes'])===bin2hex($row['composition_json']),'utf8mb4 composition preserves exact Unicode and escaped text bytes');
    $before=calculationNativeSnapshot($pdo); $again=calculationNativeSend($pdo,$intent,$operation,$text,'7001');
    calculationNativeCheck($again['provider_calls']===0 && calculationNativeSnapshot($pdo)===$before,'completed operation replay has zero provider calls and zero canonical writes');
    promiseCalculationSchema($pdo,true);
    calculationNativeCheck(calculationNativeSnapshot($pdo)===$before,'migration retry preserves completed proof transcript receipts and tasks byte exact');

    $phase='duplicate_recovery'; calculationNativeSeed($pdo); $intent=calculationNativeArm($pdo,$composition); $operation=str_repeat('2',32);
    $sent=calculationNativeSend($pdo,$intent,$operation,$text,'7002',true);
    $command=['action'=>'recover','intent'=>$intent,'operation'=>$operation];
    $race=calculationNativeRace($pdo,$token,[$command,$command]);
    calculationNativeCheck($sent['provider_calls']===1 && ($sent['result']['attempt']['state']??null)==='unknown'
        && array_sum(array_column($race,'provider_calls'))===0 && !empty($race[0]['result']['ok']) && !empty($race[1]['result']['ok'])
        && calculationNativeCompleted($pdo,$intent) && (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE external_message_id='7002'")->fetchColumn()===1
        && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='manager_message'")->fetchColumn()===1,
        'concurrent local recoveries finalize one durable native proof exactly once without resend');
    $phase='manual_reopen'; LeadTaskService::setCompleted(1,101,false); $before=calculationNativeSnapshot($pdo);
    ManagerPromiseCalculationService::recover(7,1,$intent); ManagerPromiseCalculationService::status(7,1,$intent);
    $replay=calculationNativeSend($pdo,$intent,$operation,$text,'7002');
    calculationNativeCheck(promiseSelectionTask($pdo)['status']==='open' && (int)promiseSelectionTask($pdo)['business_revision']===3
        && $replay['provider_calls']===0 && calculationNativeSnapshot($pdo)===$before,'manual reopen after completion survives late status recovery and old send replay');

    $phase='manual_aba'; calculationNativeSeed($pdo); $intent=calculationNativeArm($pdo,$composition); $operation=str_repeat('3',32);
    calculationNativeSend($pdo,$intent,$operation,$text,'7003',true);
    $command=['action'=>'finalize','intent'=>$intent,'operation'=>$operation];
    calculationNativeRace($pdo,$token,[$command,$command],static function(PDO $locked): void {
        $task=promiseSelectionTask($locked); $due=str_replace(' ','T',$task['due_at_utc']).'Z';
        if (empty(LeadTaskService::update(1,101,'Временная ручная правка',$due)['ok'])
            || empty(LeadTaskService::update(1,101,$task['title'],$due)['ok'])) throw new RuntimeException('calculation_native_manual_aba');
    });
    $state=calculationNativeState($pdo,$intent); $task=promiseSelectionTask($pdo);
    calculationNativeCheck($state['state']==='blocked' && $state['reason']==='task_changed' && $state['confirmed']===1
        && $task['status']==='open' && (int)$task['business_revision']===3 && $task['title']==='Отправить расчёт по поездке',
        'manual ABA committed ahead of waiting finalizers preserves revision and leaves task open');

    $phase='terminal_rollback'; calculationNativeSeed($pdo); $intent=calculationNativeArm($pdo,$composition); $operation=str_repeat('4',32);
    $pdo->exec("CREATE TRIGGER calculation_native_terminal_failure BEFORE UPDATE ON manager_promise_calculation_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_terminal_failure'");
    $sent=calculationNativeSend($pdo,$intent,$operation,$text,'7004');
    $delivery=$pdo->query('SELECT native_json,message_id FROM manager_promise_calculation_deliveries')->fetch(); $state=calculationNativeState($pdo,$intent);
    calculationNativeCheck($sent['provider_calls']===1 && !$sent['provider_in_transaction'] && ($sent['result']['attempt']['state']??null)==='unknown'
        && $delivery['native_json']!==null && $delivery['message_id']===null && $state['state']==='armed' && $state['can_recover']
        && promiseSelectionTask($pdo)['status']==='open' && (int)promiseSelectionTask($pdo)['business_revision']===1
        && (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE external_message_id='7004'")->fetchColumn()===0
        && (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='manager_message'")->fetchColumn()===0,
        'terminal evidence failure rolls back mirror confirmed receipt and task but retains native proof');
    $before=calculationNativeSnapshot($pdo);
    ManagerPromiseCalculationService::status(7,1,$intent); ManagerSendAttemptService::receipt(7,1,$operation);
    calculationNativeCheck(calculationNativeSnapshot($pdo)===$before,'read only receipt and status never repair or resend a failed local commit');
    $pdo->exec('DROP TRIGGER calculation_native_terminal_failure'); $recovered=ManagerPromiseCalculationService::recover(7,1,$intent);
    $again=calculationNativeSend($pdo,$intent,$operation,$text,'7004');
    calculationNativeCheck(!empty($recovered['ok']) && calculationNativeCompleted($pdo,$intent) && $again['provider_calls']===0
        && (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE external_message_id='7004'")->fetchColumn()===1,
        'explicit recovery atomically repairs durable native result with no second provider call');

    $phase='cancel_before_finalize'; calculationNativeSeed($pdo); $intent=calculationNativeArm($pdo,$composition); $operation=str_repeat('5',32);
    calculationNativeSend($pdo,$intent,$operation,$text,'7005',true);
    $cancelled=ManagerPromiseCalculationService::cancel(7,1,['operation_id'=>str_repeat('d',32),'intent_id'=>$intent]);
    $command=['action'=>'finalize','intent'=>$intent,'operation'=>$operation]; calculationNativeRace($pdo,$token,[$command,$command]);
    $state=calculationNativeState($pdo,$intent);
    calculationNativeCheck(!empty($cancelled['ok']) && $state['state']==='cancelled' && $state['confirmed']===1
        && promiseSelectionTask($pdo)['status']==='open' && (int)promiseSelectionTask($pdo)['business_revision']===1,
        'cancellation before local finalization keeps truthful delivery and leaves task open');

    $phase='partial_unknown'; foreach (['a','b'] as $suffix) {
        $path=tempnam(sys_get_temp_dir(),'calculation-native-');
        if ($path===false) throw new RuntimeException('calculation_native_tempfile'); $files[]=['path'=>$path,'name'=>'fixture-'.$suffix.'.txt'];
        if (file_put_contents($path,'Synthetic calculation attachment '.$suffix)===false) throw new RuntimeException('calculation_native_file_write');
    }
    $media=['text'=>'Синтетический расчёт с двумя файлами','files'=>array_map(static fn(array $f): array=>[
        'sha256'=>hash_file('sha256',$f['path']),'size'=>filesize($f['path']),'name'=>$f['name']],$files)];
    calculationNativeSeed($pdo); $intent=calculationNativeArm($pdo,$media);
    $first=calculationNativeSend($pdo,$intent,str_repeat('6',32),$media['text'],'7006',false,$files[0]);
    $second=calculationNativeSend($pdo,$intent,str_repeat('7',32),'','7007',false,$files[1],1,true);
    $state=calculationNativeState($pdo,$intent); $before=calculationNativeSnapshot($pdo);
    ManagerPromiseCalculationService::recover(7,1,$intent);
    $replay=calculationNativeSend($pdo,$intent,str_repeat('7',32),'','7007',false,$files[1],1,true);
    calculationNativeCheck(($first['result']['attempt']['state']??null)==='confirmed' && ($second['result']['attempt']['state']??null)==='unknown'
        && $state['state']==='armed' && $state['confirmed']===1 && $state['total']===2 && !$state['can_recover']
        && promiseSelectionTask($pdo)['status']==='open' && $replay['provider_calls']===0 && calculationNativeSnapshot($pdo)===$before,
        'one confirmed file plus unresolved final file cannot complete or authorize an automatic retry');

    $phase='context_before_final_part'; calculationNativeSeed($pdo); $intent=calculationNativeArm($pdo,$media);
    calculationNativeSend($pdo,$intent,str_repeat('8',32),$media['text'],'7008',false,$files[0]);
    $operation=str_repeat('9',32); calculationNativeSend($pdo,$intent,$operation,'','7009',true,$files[1],1);
    $command=['action'=>'finalize','intent'=>$intent,'operation'=>$operation];
    calculationNativeRace($pdo,$token,[$command,$command],static function(PDO $locked): void {
        promiseSelectionMessage($locked,30,'customer','Синтетическое изменение условий после отправки.');
    });
    $state=calculationNativeState($pdo,$intent);
    calculationNativeCheck($state['state']==='blocked' && $state['reason']==='context_changed' && $state['confirmed']===2
        && promiseSelectionTask($pdo)['status']==='open' && (int)promiseSelectionTask($pdo)['business_revision']===1
        && (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE external_message_id IN ('7008','7009')")->fetchColumn()===2,
        'new customer context wins against waiting final part and preserves both delivered files without closure');

    $phase='cascade'; $pdo->exec('DELETE FROM conversations WHERE id=1');
    calculationNativeCheck((int)$pdo->query('SELECT COUNT(*) FROM manager_promise_calculation_intents')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM manager_promise_calculation_operations')->fetchColumn()===0
        && (int)$pdo->query('SELECT COUNT(*) FROM manager_promise_calculation_deliveries')->fetchColumn()===0,
        'native conversation cascade removes only its calculation intent operations and proof rows');
} catch (Throwable $error) {
    $failed=true;
    // No SQL, credentials, transcript or provider payload in CI output.
    $label=$error instanceof RuntimeException && preg_match('/\A[A-Za-z0-9 _-]{1,180}\z/D',$error->getMessage())===1
        ?$error->getMessage():'calculation_native_fixture_failed';
    fwrite(STDERR,'FAIL native MySQL calculation phase '.$phase.' '.$label.calculationNativeSqlFailure($error)."\n");
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($owned && $pdo instanceof PDO) {
        $pdo->exec('DROP TRIGGER IF EXISTS calculation_native_terminal_failure');
        foreach (calculationNativeTables() as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
        $pdo->exec('DROP TABLE IF EXISTS calculation_native_fixture');
    }
    foreach ($files as $file) if (is_file($file['path'])) unlink($file['path']);
}
if ($failed) exit(1);
echo "OK native MySQL calculation migration locking and atomicity\n";
