<?php
declare(strict_types=1);

// A fixed disposable localhost CI schema only. Never load application config,
// provider credentials, production sessions or a configurable database address.
if (PHP_SAPI!=='cli'||getenv('CI')!=='true'||getenv('SOUND_MYSQL_TEST')!=='1') {
    fwrite(STDERR,"Explicit isolated native CI service required\n");exit(1);
}
require_once dirname(__DIR__,2).'/services/ManagerPushConnectionService.php';
require_once dirname(__DIR__,2).'/services/ManagerPushHealth.php';
require_once dirname(__DIR__,2).'/services/MigrationRunner.php';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO('mysql:host=127.0.0.1;dbname=sound_test;charset=utf8mb4','root','sound-local-test-only',$options);
if ($server->query('SELECT DATABASE()')->fetchColumn()!=='sound_test') throw new RuntimeException('Unexpected CI service');
// No IF NOT EXISTS: accidental reuse must fail before touching a prior schema.
$server->exec('CREATE DATABASE manager_push_connections_test CHARACTER SET utf8mb4');
$pdo=new PDO('mysql:host=127.0.0.1;dbname=manager_push_connections_test;charset=utf8mb4','root','sound-local-test-only',$options);
if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0) throw new RuntimeException('Nonempty push fixture');
$pdo->exec("SET time_zone='+00:00'");
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
$pdo->exec('CREATE TABLE managers (id BIGINT UNSIGNED PRIMARY KEY,login VARCHAR(80),display_name VARCHAR(80),is_active TINYINT,is_working TINYINT) ENGINE=InnoDB');
$pdo->exec("INSERT INTO managers VALUES (7,'seven','Seven',1,1),(8,'eight','Eight',1,1)");
$runner=new MigrationRunner(dirname(__DIR__,2).'/migrations',$pdo);
$splitter=new ReflectionMethod(MigrationRunner::class,'splitStatements');
$apply=static function(string $file) use ($runner,$splitter,$pdo): void {
    foreach ($splitter->invoke($runner,(string)file_get_contents(dirname(__DIR__,2).'/migrations/'.$file)) as $sql) $pdo->exec($sql);
};
$apply('018_manager_push_subscriptions.sql');
$subscription=static fn(string $key,int $manager=7): array=>['endpoint'=>'https://push-fixture.invalid/'.$key,'keys'=>['p256dh'=>'synthetic-key-'.($key==='b'?7:$manager).'-'.$key,'auth'=>'synthetic-auth-'.($key==='b'?7:$manager).'-'.$key]];
$insert=$pdo->prepare('INSERT INTO manager_push_subscriptions (manager_id,endpoint,endpoint_hash,p256dh,auth_secret,user_agent,created_at,updated_at,last_success_at) VALUES (?,?,?,?,?,?,?,?,?)');
foreach ([[7,'a'],[7,'b'],[7,'c'],[8,'b']] as [$manager,$key]) {
    $sub=$subscription($key,$manager);$insert->execute([$manager,$sub['endpoint'],hash('sha256',$sub['endpoint']),$sub['keys']['p256dh'],$sub['keys']['auth'],'Firefox/141.0 Windows','2026-10-15 10:00:00','2026-10-15 11:00:00','2026-10-15 12:00:00']);
}
$before=$pdo->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll();$passed=0;
function nativePushCheck(string $name,bool $ok): void {global $passed;if (!$ok) throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
$apply('039_manager_push_connections.sql');
$rows=$pdo->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll();
nativePushCheck('actual forward migration039 runs on native MySQL8',count($rows)===4&&(int)$pdo->query('SELECT COUNT(*) FROM manager_push_installations')->fetchColumn()===4);
foreach ($rows as $n=>$row) {
    $old=$before[$n];unset($old['updated_at']);$kept=array_intersect_key($row,$old);
    nativePushCheck('migration preserves legacy identity keys and delivery history '.$n,$kept===$old&&(int)$row['installation_id']===(int)$row['id']&&(int)$row['intent_revision']===1);
}
nativePushCheck('legacy activity uses the available pre-migration timestamp',$pdo->query('SELECT DISTINCT last_seen_at FROM manager_push_installations')->fetchAll(PDO::FETCH_COLUMN)===['2026-10-15 11:00:00']);
$snapshot=static fn(): array=>[$pdo->query('SELECT * FROM manager_push_subscriptions ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM manager_push_installations ORDER BY id')->fetchAll()];
$once=$snapshot();$apply('039_manager_push_connections.sql');nativePushCheck('a partial/repeated migration is safe and byte preserving',$snapshot()===$once);
$index=$pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manager_push_subscriptions' AND INDEX_NAME='idx_push_installation_activity' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
nativePushCheck('actual activity index supports bounded installation reads',$index===['installation_id','is_active','updated_at','id']);
$nonce=0;$command=static function(string $action,array $input,int $manager=7) use (&$nonce): array {return ManagerPushConnectionService::mutate($manager,$action,['operation_id'=>str_pad(dechex(++$nonce),64,'0',STR_PAD_LEFT)]+$input,'Firefox/141.0 Windows');};
$token=str_repeat('b',64);$shared=$pdo->query('SELECT * FROM manager_push_subscriptions WHERE manager_id=8')->fetchAll();$managers=$pdo->query('SELECT * FROM managers ORDER BY id')->fetchAll();
$intent=$command('enable_intent',['installation_token'=>$token,'expected_revision'=>1,'subscription'=>$subscription('b')]);
$saved=$command('subscribe',['installation_token'=>$token,'expected_revision'=>2,'subscription'=>$subscription('b')]);
nativePushCheck('native intent and subscribe keep A B C active',$intent['ok']&&$saved['ok']&&count(ManagerPushConnectionService::activeSubscriptions($pdo,[7]))===3);
$captured=array_values(array_filter(ManagerPushConnectionService::activeSubscriptions($pdo,[7]),static fn(array $row): bool=>(int)$row['installation_id']===2))[0];
$command('enable_intent',['installation_token'=>$token,'expected_revision'=>2]);
$revoked=$command('revoke',['connection_id'=>2,'expected_revision'=>3]);
$late=$command('subscribe',['installation_token'=>$token,'expected_revision'=>3,'subscription'=>$subscription('e2')]);
nativePushCheck('native remote revoke rejects a pending new endpoint',$revoked['ok']&&!$late['ok']&&$late['error']==='subscription_revoked');
nativePushCheck('actual subsequent fan-out selects only A C',array_column(ManagerPushConnectionService::activeSubscriptions($pdo,[7]),'endpoint')===[$subscription('a')['endpoint'],$subscription('c')['endpoint']]);
nativePushCheck('native health counts only remaining active paths',ManagerPushHealth::statusForManager($pdo,7)['subscription_count']===2&&ManagerPushHealth::statusForSubscription($pdo,7,$subscription('b')['endpoint'])['notification_path_reason']==='subscription_revoked');
$off=$snapshot();$apply('039_manager_push_connections.sql');nativePushCheck('migration retry cannot reset a later real revocation',$snapshot()===$off);
$receipt=$revoked['operation'];$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
$list=ManagerPushConnectionService::connections(7,$token);$reconciled=ManagerPushConnectionService::operation(7,$receipt['id']);$foreignReceipt=ManagerPushConnectionService::operation(8,$receipt['id']);$pdo->rollBack();
nativePushCheck('own list and immutable receipt work in native READ ONLY',count($list['connections'])===3&&$reconciled['operation']===$receipt&&!$reconciled['current_connection']['enabled']&&!$foreignReceipt['found']);
$repeat=$command('revoke',['connection_id'=>2,'expected_revision'=>3]);nativePushCheck('native idempotent revoke does not increment an already disabled intent',$repeat['ok']&&$repeat['revision']===4);
$renewed=$command('enable_intent',['installation_token'=>$token,'expected_revision'=>4]);$new=$command('subscribe',['installation_token'=>$token,'expected_revision'=>5,'subscription'=>$subscription('e2')]);
nativePushCheck('explicit renewed intent restores only the same installation',$renewed['ok']&&$new['ok']&&$new['current_connection']['id']===2&&count(ManagerPushConnectionService::activeSubscriptions($pdo,[7]))===3);
$after=$snapshot();foreach ([201,500,404,410] as $code) ManagerPushConnectionService::deliveryResult($pdo,$captured,$code,'synthetic late result');
nativePushCheck('late native SQL provider results cannot touch the renewed row',$snapshot()===$after);
nativePushCheck('shared endpoint second account stays byte identical',$pdo->query('SELECT * FROM manager_push_subscriptions WHERE manager_id=8')->fetchAll()===$shared);
$pdo->exec("CREATE TRIGGER fixture_fail_push_receipt BEFORE INSERT ON manager_push_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic receipt failure'");
$prior=$snapshot();$failed=false;try {$command('revoke',['connection_id'=>1,'expected_revision'=>1]);}catch(PDOException $e){$failed=true;}
$pdo->exec('DROP TRIGGER fixture_fail_push_receipt');nativePushCheck('native receipt failure atomically rolls back the revoke',$failed&&$snapshot()===$prior);
$locker=new PDO('mysql:host=127.0.0.1;dbname=manager_push_connections_test;charset=utf8mb4','root','sound-local-test-only',$options);
$locker->beginTransaction();$locker->query('SELECT id FROM managers WHERE id=7 FOR UPDATE')->fetch();
$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');$blocked=false;$prior=$snapshot();
try {$command('revoke',['connection_id'=>1,'expected_revision'=>1]);}catch(PDOException $e){$blocked=(int)($e->errorInfo[1]??0)===1205;}
$pdo->beginTransaction();$other=(int)$pdo->query('SELECT id FROM managers WHERE id=8 FOR UPDATE')->fetchColumn();$pdo->rollBack();$locker->rollBack();
nativePushCheck('the real manager lock fences concurrent commands without locking another account',$blocked&&$other===8&&$snapshot()===$prior);
nativePushCheck('native lifecycle never changes shifts or manager identity',$pdo->query('SELECT * FROM managers ORDER BY id')->fetchAll()===$managers);
echo "NATIVE MANAGER PUSH MYSQL OK ({$passed} checks; no provider requests)\n";
