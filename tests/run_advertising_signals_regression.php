<?php
declare(strict_types=1);
require_once __DIR__.'/run_advertising_visits_regression.php';
require_once dirname(__DIR__).'/services/AdvertisingSignalRepository.php';
require_once dirname(__DIR__).'/services/MetrikaConversionGoalService.php';

function advertisingSignalSchema(PDO $pdo): void
{
    advertisingVisitFixtureSchema($pdo);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        $migration=file_get_contents(dirname(__DIR__).'/migrations/064_advertising_signals.sql');
        $pdo->exec($migration);$pdo->exec($migration);
        $pdo->exec('CREATE TABLE customer_channels (id BIGINT PRIMARY KEY,project_key VARCHAR(40),channel VARCHAR(16),external_user_id VARCHAR(80),external_chat_id VARCHAR(80))');
        $pdo->exec('CREATE TABLE conversations (id BIGINT PRIMARY KEY,customer_channel_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_chat_id VARCHAR(80),status VARCHAR(32),is_test INT DEFAULT 0)');
        $pdo->exec('CREATE TABLE messages (id BIGINT PRIMARY KEY,conversation_id BIGINT,channel VARCHAR(16),direction VARCHAR(16),sender_type VARCHAR(16),external_message_id VARCHAR(128),metadata_json TEXT,created_at DATETIME)');
        $pdo->exec('CREATE TABLE conversation_events (id BIGINT PRIMARY KEY,conversation_id BIGINT,event_type VARCHAR(64),actor_type VARCHAR(16),created_at DATETIME,payload_json TEXT)');
    } else {
        $pdo->exec('CREATE TABLE advertising_signals (id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,conversation_id TEXT,external_user_id TEXT,signal_key TEXT,source_type TEXT,source_id TEXT,handoff_event_id TEXT,manager_event_id TEXT,manager_message_id TEXT,occurred_at_unix INTEGER,captured_at_unix INTEGER,UNIQUE(project_key,source_key,platform,counter_id,conversation_id,signal_key))');
        $pdo->exec('CREATE TABLE customer_channels (id INTEGER PRIMARY KEY,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT)');
        $pdo->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY,customer_channel_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,status TEXT,is_test INTEGER DEFAULT 0)');
        $pdo->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY,conversation_id INTEGER,channel TEXT,direction TEXT,sender_type TEXT,external_message_id TEXT,metadata_json TEXT,created_at TEXT)');
        $pdo->exec('CREATE TABLE conversation_events (id INTEGER PRIMARY KEY,conversation_id INTEGER,event_type TEXT,actor_type TEXT,created_at TEXT,payload_json TEXT)');
    }
}

function advertisingSignalAssertions(PDO $pdo, callable $check): void
{
    $now=time();$captured=$now-150;$early=gmdate('Y-m-d H:i:s',$now-300);$same=gmdate('Y-m-d H:i:s',$now-200);
    $scope=['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635'];
    $user='184467440737095516151';$repo=new AdvertisingSignalRepository($pdo,$scope);
    $reject=static function(callable $call,string $error,string $name) use($check):void {try{$call();$check($name,false);}catch(Throwable $e){$check($name,$e->getMessage()===$error);}};
    foreach ([1,2,3,4,5,6,7,8,9,10] as $id) {
        $project=$id===2?'anex':'anytour';$channel=$id===3?'max':'telegram';$chat=$id===4?'-100':($id===5?'999':$user);
        $pdo->prepare('INSERT INTO customer_channels VALUES (?,?,?,?,?)')->execute([$id,$project,$channel,$user,$chat]);
        $pdo->prepare('INSERT INTO conversations VALUES (?,?,?,?,?,?,?)')->execute([$id,$id,$project,$channel,$chat,$id===6?'closed':($id===8?'waiting_manager':'manager'),$id===7?1:0]);
        $pdo->prepare('INSERT INTO messages VALUES (?,?,?,?,?,?,?,?)')->execute([$id*10+1,$id,$channel,'inbound','customer','initial-'.$id,'{"type":"message","username":"do-not-copy"}',$early]);
        $pdo->prepare('INSERT INTO messages VALUES (?,?,?,?,?,?,?,?)')->execute([$id*10+2,$id,$channel,'outbound','manager','manager-'.$id,'{}',$same]);
        $pdo->prepare('INSERT INTO messages VALUES (?,?,?,?,?,?,?,?)')->execute([$id*10+3,$id,$channel,'inbound','customer','reply-'.$id,'{"type":"message"}',$same]);
        $pdo->prepare('INSERT INTO messages VALUES (?,?,?,?,?,?,?,?)')->execute([$id*10+4,$id,$channel,'inbound','customer','callback-'.$id,'{"type":"callback"}',$same]);
        $pdo->prepare('INSERT INTO conversation_events VALUES (?,?,?,?,?,?)')->execute([$id*10+1,$id,'waiting_manager','customer',$same,null]);
        $pdo->prepare('INSERT INTO conversation_events VALUES (?,?,?,?,?,?)')->execute([$id*10+2,$id,'manager_message','manager',$same,null]);
    }
    // One private chat can have only one active conversation in a well-formed fixture.
    foreach ([2,3,4,5,6,7,8,9,10] as $id) {
        $distinct=(string)(8000000000+$id);
        if ($id!==4 && $id!==5) {
            $pdo->prepare('UPDATE conversations SET external_chat_id=? WHERE id=?')->execute([$distinct,$id]);
            $pdo->prepare('UPDATE customer_channels SET external_user_id=?,external_chat_id=? WHERE id=?')->execute([$distinct,$distinct,$id]);
        }
    }
    $check('missing native identity cannot invent a message', $repo->incoming($user,'','message',$captured)===0);
    $check('unrecorded native message cannot invent activity', $repo->incoming($user,'not-recorded','message',$captured)===0);
    $check('incoming kind must match the exact source record', $repo->incoming($user,'callback-1','message',$captured)===0);
    $check('pre-manager inbound records activity only', $repo->incoming($user,'initial-1','message',$captured)===1);
    $check('real waiting-manager evidence records its own signal', $repo->request(1,$captured)===1);
    $check('repeated handoff observation is idempotent', $repo->request(1,$now)===0);
    $check('callback after a manager is not a customer reply', $repo->incoming($user,'callback-1','callback',$captured)===0 && $repo->counts()['customer_reply']===0);
    $check('same-second ordered reply is captured', $repo->incoming($user,'reply-1','message',$captured)===1);
    $check('native replay adds no repeated signals', $repo->incoming($user,'reply-1','message',$now)===0);
    $rows=$pdo->query('SELECT * FROM advertising_signals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('three signals survive without any ClientID', count($rows)===3 && $repo->counts()===['customer_activity'=>1,'manager_request'=>1,'customer_reply'=>1]);
    $check('source identities stay exact decimal strings', $rows[0]['external_user_id']===$user && $rows[0]['source_id']==='11');
    $check('original recorded event time differs from capture time', (int)$rows[0]['occurred_at_unix']===$now-300 && (int)$rows[0]['captured_at_unix']===$captured);
    $reply=array_values(array_filter($rows,static fn($r)=>$r['signal_key']==='customer_reply'))[0];
    $check('reply retains actual handoff and manager evidence references', $reply['source_id']==='13' && $reply['handoff_event_id']==='11' && $reply['manager_event_id']==='12' && $reply['manager_message_id']==='12');
    $check('signal rows do not copy native payloads or profile text', !str_contains(json_encode($rows),'do-not-copy') && !str_contains(json_encode($rows),'username'));
    foreach ([2,3,4,5,6,7] as $id) {
        $chat=(string)$pdo->query('SELECT external_chat_id FROM conversations WHERE id='.$id)->fetchColumn();
        $check('request rejects foreign nonprivate closed or test conversation '.$id,$repo->request($id,$now)===0);
        $check('incoming rejects foreign nonprivate closed or test conversation '.$id,$repo->incoming($chat,'reply-'.$id,'message',$now)===0);
    }
    $pdo->exec("DELETE FROM conversation_events WHERE conversation_id=9 AND event_type='waiting_manager'");
    $check('request needs an actual persisted transition', $repo->request(9,$now)===0);
    $check('manager outbound alone cannot manufacture a request or reply', $repo->incoming('8000000009','reply-9','message',$now)===1 && $repo->counts()['customer_reply']===1);
    $pdo->exec("UPDATE conversation_events SET id=9999 WHERE conversation_id=10 AND event_type='waiting_manager'");
    $check('manager evidence from before a newer handoff cannot establish a reply', $repo->incoming('8000000010','reply-10','message',$now)===1 && $repo->counts()['customer_reply']===1);

    $preview=$repo->preview('0',3,$now);
    $check('no source upload leaves all three identities explicitly missing', array_column($preview,'identity_status')===['missing','missing','missing']);
    $check('keyset preview is bounded and resumes after the last row', count($repo->preview($preview[0]['id'],1,$now))===1 && $repo->preview($preview[0]['id'],1,$now)[0]['id']===$preview[1]['id']);
    $reject(static fn()=>$repo->preview('0',101,$now),'advertising_signal_page','unbounded preview rejected');
    $visits=new AdvertisingVisitImportService(new AdvertisingVisitRepository($pdo),$scope);
    $entry=advertisingVisitRow('1',['visit_id'=>'signal-entry','visit_at_unix'=>$now-600,'observed_at_unix'=>$now-500,'external_user_id'=>$user,'identity_status'=>'unverified']);
    $page=static function(array $rows,string $after,array $scope):array{return ['schema_version'=>1]+$scope+['after_cursor'=>$after,'next_cursor'=>$rows[count($rows)-1]['source_cursor'],'observations'=>$rows];};
    $visits->import($page([$entry],'0',$scope),$now-500);
    $check('unverified history cannot supply a ClientID', $repo->preview('0',1,$now)[0]['identity_status']==='missing');
    $entry['source_cursor']='2';$entry['identity_status']='verified';$entry['observed_at_unix']=$now-100;
    $visits->import($page([$entry],'1',$scope),$now-50);
    $check('future observation is unavailable at an earlier assessment', $repo->preview('0',1,$now-125)[0]['identity_status']==='missing');
    $check('received-at cutoff prevents late import from leaking into earlier assessment', $repo->preview('0',1,$now-75)[0]['identity_status']==='missing');
    $matched=$repo->preview('0',3,$now);
    $check('late verified identity can assess earlier retained events', array_column($matched,'identity_status')===['unique','unique','unique'] && $matched[0]['client_id']===$entry['client_id']);
    $check('assessment never rewrites signal occurrence or capture time', $pdo->query('SELECT * FROM advertising_signals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)[0]===$rows[0]);
    foreach ([['project_key'=>'anex'],['source_key'=>'other'],['platform'=>'max'],['counter_id'=>'98615636']] as $changes) {
        $foreign=array_replace($scope,$changes);$r=$entry;$r['source_cursor']='1';$r['client_id']='999';$r['observed_at_unix']=$now-400;
        (new AdvertisingVisitImportService(new AdvertisingVisitRepository($pdo),$foreign))->import($page([$r],'0',$foreign),$now-300);
        $check('identity history is isolated by '.key($changes),$repo->preview('0',1,$now)[0]['identity_status']==='unique');
    }
    $future=advertisingVisitRow('3',['visit_id'=>'future-entry','visit_at_unix'=>$now-10,'observed_at_unix'=>$now-10,'external_user_id'=>$user,'client_id'=>'777']);
    $visits->import($page([$future],'2',$scope),$now);
    $check('a later advertising entry cannot explain an earlier event', $repo->preview('0',1,$now)[0]['identity_status']==='unique');
    $sameClient=$entry;$sameClient['source_cursor']='4';$sameClient['visit_id']='another-entry';$sameClient['observed_at_unix']=$now-25;
    $visits->import($page([$sameClient],'3',$scope),$now);
    $check('repeated eligible entries with one ClientID are not ambiguous', $repo->preview('0',1,$now)[0]['identity_status']==='unique');
    $other=$sameClient;$other['source_cursor']='5';$other['visit_id']='different-browser';$other['client_id']='555';
    $visits->import($page([$other],'4',$scope),$now);
    $assessed=$repo->preview('0',1,$now)[0];
    $check('multiple eligible ClientIDs stay ambiguous without an arbitrary winner', $assessed['identity_status']==='ambiguous' && $assessed['client_id']==='');
    $check('a later conflicting identity revokes a provisional unique assessment', $matched[0]['identity_status']==='unique' && $repo->preview('0',1,$now)[0]['identity_status']==='ambiguous');

    ProjectConfig::resetForTests(['id'=>'anytour']);
    foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $key) if(!defined($key))define($key,'fixture');
    (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
    $native=['platform'=>'telegram','type'=>'message','user'=>['chat_id'=>'8000000008'],'message_id'=>'initial-8'];
    $before=$repo->counts();MetrikaConversionGoalService::observeIncoming($native);
    $check('canonical incoming owner captures a signal without a legacy queue or ClientID', $repo->counts()['customer_activity']===$before['customer_activity']+1);
    $queueCalls=0;$legacy=MetrikaConversionGoalService::managerRequest(8,static function()use(&$queueCalls):bool{$queueCalls++;return false;});
    $check('canonical manager request persists evidence despite unavailable legacy queue', !$legacy && $queueCalls===0 && $repo->counts()['manager_request']===2);
    $before=$repo->counts();ProjectConfig::resetForTests(['id'=>'anex']);MetrikaConversionGoalService::observeIncoming($native);
    $check('runtime observer does not apply AnyTour counter to another brand', $repo->counts()===$before);
    ProjectConfig::resetForTests(['id'=>'anytour']);
    $pdo->beginTransaction();$inserted=$repo->request(10,$now);
    $check('signal insert participates in the existing transaction without completing it', $inserted===1 && $pdo->inTransaction() && $repo->counts()['manager_request']===$before['manager_request']+1);
    $pdo->rollBack();
    $check('observer never commits an outer business transaction', $repo->counts()===$before && !$pdo->inTransaction());
    $pdo->exec('DROP TABLE advertising_signals');
    MetrikaConversionGoalService::observeIncoming($native);
    $check('missing observation storage cannot throw into incoming handling', true);
    $check('storage failure leaves canonical conversation state unchanged', $pdo->query('SELECT status FROM conversations WHERE id=8')->fetchColumn()==='waiting_manager');
}

function advertisingSignalCliAssertions(callable $check): void
{
    $root=sys_get_temp_dir().'/advertising-signal-cli-'.bin2hex(random_bytes(6));mkdir($root.'/services',0700,true);mkdir($root.'/tools',0700);
    $copies=['tools/advertising_signals.php','services/AdvertisingSignalRepository.php','services/AdvertisingVisitContract.php','services/ConversationDb.php'];
    foreach($copies as $copy)copy(dirname(__DIR__).'/'.$copy,$root.'/'.$copy);
    file_put_contents($root.'/services/ProjectConfig.php','<?php class ProjectConfig { public static function projectId(): string { return "anytour"; } }');
    $db=$root.'/fixture.sqlite';
    $pdo=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);advertisingSignalSchema($pdo);
    $scope=['project_key'=>'anytour','source_key'=>'tgmini','platform'=>'telegram','counter_id'=>'98615635'];
    $now=time();$repo=new AdvertisingVisitImportService(new AdvertisingVisitRepository($pdo),$scope);
    $row=advertisingVisitRow('1',['visit_at_unix'=>$now-600,'observed_at_unix'=>$now-500]);
    $repo->import(['schema_version'=>1]+$scope+['after_cursor'=>'0','next_cursor'=>'1','observations'=>[$row]],$now-400);
    $pdo->prepare('INSERT INTO advertising_signals (project_key,source_key,platform,counter_id,conversation_id,external_user_id,signal_key,source_type,source_id,handoff_event_id,manager_event_id,manager_message_id,occurred_at_unix,captured_at_unix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...array_values($scope),'123',$row['external_user_id'],'customer_activity','message','111','','','',$now-300,$now-200]);
    file_put_contents($root.'/config.php','<?php require_once __DIR__."/services/ConversationDb.php"; $p=new PDO('.var_export('sqlite:'.$db,true).'); (new ReflectionProperty(ConversationDb::class,"pdo"))->setValue(null,$p);');
    $call=static function(string $command,array $extra=[])use($root,$scope):array {
        $args=[PHP_BINARY,$root.'/tools/advertising_signals.php',$command];foreach($scope as $key=>$value)$args[]='--'.$key.'='.$value;
        $p=proc_open([...$args,...$extra],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('cli_fixture_failed');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];
    };
    try {
        [$code,$out,$err]=$call('status');$value=json_decode($out,true);
        $check('actual CLI status returns only signal counts without writing', $code===0 && $err==='' && $value===['status'=>'observed','database_writes'=>false,'conversion_uploads'=>0,'signals'=>['customer_activity'=>1,'manager_request'=>0,'customer_reply'=>0]]);
        [$code,$out,$err]=$call('preview',['--limit=1']);$value=json_decode($out,true);
        $check('actual CLI previews provisional identity counts', $code===0 && $err==='' && $value['signals_read']===1 && $value['identity']===['missing'=>0,'unique'=>1,'ambiguous'=>0] && $value['database_writes']===false && $value['conversion_uploads']===0);
        $check('CLI output never reveals subject ClientID or private paths', !str_contains($out,$row['client_id']) && !str_contains($out,$row['external_user_id']) && !str_contains($out,$root));
        $check('CLI preview leaves observation data unchanged', (int)$pdo->query('SELECT COUNT(*) FROM advertising_signals')->fetchColumn()===1 && (int)$pdo->query('SELECT COUNT(*) FROM advertising_visit_observations')->fetchColumn()===1);
        [$code,$out,$err]=$call('preview',['--limit=101']);
        $check('CLI rejects unbounded requests before runtime reads', $code===1 && $out==='' && json_decode($err,true)===['status'=>'failed','error'=>'advertising_signal_argument']);
        unlink($root.'/config.php');[$code,$out,$err]=$call('status');
        $check('missing runtime config produces a fixed enum without a PHP path warning', $code===1 && $out==='' && json_decode($err,true)===['status'=>'failed','error'=>'advertising_signal_runtime_unavailable'] && !str_contains($err,$root));
    } finally {
        $pdo=null;foreach($copies as $copy)@unlink($root.'/'.$copy);@unlink($root.'/services/ProjectConfig.php');@unlink($root.'/config.php');@unlink($db);@rmdir($root.'/tools');@rmdir($root.'/services');@rmdir($root);
    }
}

/** Real dispatcher/goal owner/observer, with only unrelated routing and transport boundaries stubbed. */
function advertisingSignalDispatcherAssertions(callable $check): void
{
    $root=sys_get_temp_dir().'/advertising-signal-dispatch-'.bin2hex(random_bytes(6));mkdir($root,0700);
    $copies=['IncomingUpdateDispatcher.php','MetrikaConversionGoalService.php','AdvertisingSignalService.php','AdvertisingSignalRepository.php','AdvertisingVisitContract.php','ConversationDb.php'];
    foreach($copies as $name)copy(dirname(__DIR__).'/services/'.$name,$root.'/'.$name);
    $baseline=(string)getenv('ADVERTISING_SIGNAL_BASELINE_DISPATCHER');
    if($baseline!=='')copy($baseline,$root.'/IncomingUpdateDispatcher.php');
    $stubs=[
        'ProjectConfig.php'=>'class ProjectConfig {static function projectId(){return "anytour";}}',
        'DialogueApplication.php'=>'class DialogueApplication {}',
        'DiagnosticLogger.php'=>'class DiagnosticLogger {static function log(...$args){}}',
        'ConversationRecorder.php'=>'class ConversationRecorder {static function inbound($v){return true;}}',
        'ConversationAttributionService.php'=>'class ConversationAttributionService {static function syncByChat(...$args){}}',
        'ConversationControlService.php'=>'class ConversationControlService {}',
        'ManagerPushService.php'=>'class ManagerPushService {}',
        'SourceHandlingService.php'=>'class SourceHandlingService {static function handle($v){return true;}}',
        'MaxChannelConversionOutbox.php'=>'class MaxChannelConversionOutbox {}',
    ];
    foreach($stubs as $name=>$code)file_put_contents($root.'/'.$name,'<?php '.$code);
    $db=$root.'/fixture.sqlite';$pdo=new PDO('sqlite:'.$db,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);advertisingSignalSchema($pdo);
    $pdo->exec("INSERT INTO customer_channels VALUES(1,'anytour','telegram','987654321','987654321'); INSERT INTO conversations VALUES(1,1,'anytour','telegram','987654321','ai',0)");
    $pdo->prepare('INSERT INTO messages VALUES(1,1,?,?,?,?,?,?)')->execute(['telegram','inbound','customer','native-1','{"type":"message"}',gmdate('Y-m-d H:i:s',time()-5)]);
    $runner=<<<'PHP'
<?php
foreach(['CONVERSATION_DB_HOST','CONVERSATION_DB_NAME','CONVERSATION_DB_USER','CONVERSATION_DB_PASS'] as $name)define($name,'fixture');
require __DIR__.'/IncomingUpdateDispatcher.php';
$pdo=new PDO('sqlite:'.__DIR__.'/fixture.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
class MaxSearchApi {static int $queued=0;static function queueMetrikaGoal(...$args){self::$queued++;return false;}}
$handled=(new IncomingUpdateDispatcher())->dispatch(['platform'=>'telegram','type'=>'message','message_id'=>'native-1','user'=>['chat_id'=>'987654321']]);
echo json_encode(['handled'=>$handled,'signals'=>(int)$pdo->query('SELECT COUNT(*) FROM advertising_signals')->fetchColumn(),'legacy_attempts'=>MaxSearchApi::$queued,'legacy_markers'=>(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()]);
PHP;
    file_put_contents($root.'/run.php',$runner);
    try {
        $p=proc_open([PHP_BINARY,$root.'/run.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('dispatcher_fixture_failed');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);$value=json_decode($out,true);
        $check('actual dispatcher captures before an unsuccessful legacy queue and preserves routing', $code===0 && $error==='' && $value===['handled'=>true,'signals'=>1,'legacy_attempts'=>1,'legacy_markers'=>0]);
    } finally {
        $pdo=null;foreach(array_merge($copies,array_keys($stubs),['run.php','fixture.sqlite']) as $name)@unlink($root.'/'.$name);@rmdir($root);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);advertisingSignalSchema($pdo);$passed=0;
    advertisingSignalAssertions($pdo,static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;});
    advertisingSignalCliAssertions(static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;});
    advertisingSignalDispatcherAssertions(static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;});
    echo 'ADVERTISING SIGNALS REGRESSION PASSED '.$passed.PHP_EOL;
}
