<?php
declare(strict_types=1);

/** Execute the actual mutation and observer against an isolated synthetic DB. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$passed=0;$failed=0;
function outcomeReceiptCheck(string $name,bool $ok):void
{
    global $passed,$failed;
    echo ($ok?'PASS  ':'FAIL  ').$name."\n";
    if($ok)$passed++;else$failed++;
}
function outcomeReceiptCount(PDO $p):int{return(int)$p->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn();}
function outcomeReceiptRows(PDO $p):array{return$p->query('SELECT * FROM conversation_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);}
function outcomeReceiptAdd(PDO $p,int $id,string $project='anytour',string $channel='max',int $test=0):void
{
    $p->prepare('INSERT INTO customer_channels(id,customer_id,project_key,channel) VALUES (?,?,?,?)')->execute([$id,$id,$project,$channel]);
    $p->prepare('INSERT INTO conversations(id,customer_id,customer_channel_id,project_key,channel,is_test) VALUES (?,?,?,?,?,?)')->execute([$id,$id,$id,$project,$channel,$test]);
}

$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/modeled-outcome-fixture-'.bin2hex(random_bytes(10));
mkdir($dir,0700);mkdir($dir.'/services',0700);
try {
    foreach(['SalesPipelineService.php','ModeledConversionOutcomeReceipt.php','ConversationRecordClock.php','InternalOwnerDialogueScope.php']as$file){
        if(!copy($root.'/services/'.$file,$dir.'/services/'.$file))throw new RuntimeException('fixture_copy_failed');
    }
    file_put_contents($dir.'/services/ConversationDb.php',<<<'PHP'
<?php
class ConversationDb { public static PDO $pdo;public static function connection():PDO{return self::$pdo;} }
PHP);
    file_put_contents($dir.'/services/ProjectConfig.php',<<<'PHP'
<?php
class ProjectConfig {public static string $project='anytour';public static function projectId():string{return self::$project;}}
PHP);
    file_put_contents($dir.'/services/DiagnosticLogger.php',<<<'PHP'
<?php
class DiagnosticLogger {public static array $entries=[];public static bool $throws=false;public static function log(...$args){self::$entries[]=$args;if(self::$throws)throw new RuntimeException('fixture_logger_failed');}}
PHP);
    file_put_contents($dir.'/services/MetrikaConversionGoalService.php',<<<'PHP'
<?php
class MetrikaConversionGoalService {
    public static array $calls=[];public static bool $throws=false;
    public static function saleOutcome(int $id,string $outcome):bool {
        try{$receipts=(int)ConversationDb::connection()->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn();}catch(Throwable $ignored){$receipts=null;}
        self::$calls[]=['id'=>$id,'outcome'=>$outcome,'receipts_at_goal'=>$receipts];
        if(self::$throws)throw new RuntimeException('fixture_goal_failed');
        return true;
    }
}
PHP);
    require $dir.'/services/SalesPipelineService.php';
    if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite_required');
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
    ConversationDb::$pdo=$p;
    $p->exec('CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT)');
    $p->exec("CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,channel TEXT,is_test INTEGER DEFAULT 0,lead_outcome TEXT DEFAULT 'open',lead_close_reason TEXT,lead_outcome_note TEXT,lead_sale_amount TEXT,lead_sale_date TEXT,lead_outcome_updated_at TEXT,lead_outcome_manager_id INTEGER)");
    $p->exec('CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $p->exec('CREATE TABLE lead_close_reasons(reason_key TEXT PRIMARY KEY,is_active INTEGER)');
    $p->exec("INSERT INTO lead_close_reasons VALUES ('not_travelling',1),('legacy_reason',0)");
    outcomeReceiptAdd($p,10);outcomeReceiptAdd($p,11,'anytour','telegram');
    $before=time();
    $ok=SalesPipelineService::setOutcome(10,'won',null,'fixture-private-note',7,'123456,7','2026-11-04');
    $rows=outcomeReceiptRows($p);$first=$rows[0];$payload=json_decode($first['payload_json'],true,32,JSON_THROW_ON_ERROR);$after=time();
    outcomeReceiptCheck('successful authoritative update appends exactly one typed receipt',$ok&&count($rows)===1&&$first['event_type']===ModeledConversionOutcomeReceipt::EVENT_TYPE);
    outcomeReceiptCheck('ordinary sale goal retains its original place before the passive observer',MetrikaConversionGoalService::$calls===[['id'=>10,'outcome'=>'won','receipts_at_goal'=>0]]);
    outcomeReceiptCheck('applied normalized amount and date remain operational assertions',$payload['outcome']==='won'&&$payload['sale_amount']==='123456.70'&&$payload['sale_date']==='2026-11-04'&&$payload['sale_date_precision']==='date_only'&&$payload['sale_amount_basis']==='sale_revenue_assertion_not_commission');
    outcomeReceiptCheck('receipt does not invent an inquiry episode profile or previous outcome',$payload['inquiry_binding']===null&&$payload['episode_key']===null&&$payload['profile_id']===null&&$payload['previous_outcome']===null);
    outcomeReceiptCheck('server-derived canonical identity and actor role are exact',$payload['scope']===['project_key'=>'anytour','platform'=>'max','customer_key'=>'10','conversation_id'=>'10']&&$payload['actor_role']==='manager'&&$first['actor_type']==='manager'&&$first['actor_id']==='7');
    outcomeReceiptCheck('actual recording clock is separate from the asserted date',$payload['_recorded_clock']===['version'=>1,'basis'=>'server_recording_unix','unix'=>$payload['_recorded_clock']['unix']]&&$payload['_recorded_clock']['unix']>=$before&&$payload['_recorded_clock']['unix']<=$after);
    outcomeReceiptCheck('operation identity is a receipt-only fresh identifier',preg_match('/\A[0-9a-f]{32}\z/D',$payload['operation_id'])===1);
    outcomeReceiptCheck('private notes stay in authoritative CRM and never enter the receipt',$p->query('SELECT lead_outcome_note FROM conversations WHERE id=10')->fetchColumn()==='fixture-private-note'&&!str_contains($first['payload_json'],'fixture-private-note')&&!array_key_exists('note',$payload));
    outcomeReceiptCheck('source is explicitly manager operational evidence rather than payment',$payload['version']===1&&$payload['origin']==='existing_sales_pipeline'&&$payload['producer']==='sales_pipeline_manager_assertion'&&$payload['evidence_basis']==='manager_operational_assertion');
    $original=$first['payload_json'];
    $lost=SalesPipelineService::setOutcome(10,'lost','not_travelling','fixture-correction-note',7);
    $open=SalesPipelineService::setOutcome(10,'open',null,null,7);
    $rows=outcomeReceiptRows($p);$all=array_map(static fn(array $r):array=>json_decode($r['payload_json'],true,32,JSON_THROW_ON_ERROR),$rows);
    outcomeReceiptCheck('correction and reopening append without overwriting the earlier assertion',$lost&&$open&&count($rows)===3&&$rows[0]['payload_json']===$original&&array_column($all,'outcome')===['won','lost','open']);
    outcomeReceiptCheck('lost reason and cleared sale values reflect the actual applied values',$all[1]['reason_key']==='not_travelling'&&$all[1]['sale_amount']===null&&$all[1]['sale_date']===null&&$all[1]['sale_date_precision']==='unknown'&&$all[2]['reason_key']===null);
    outcomeReceiptCheck('corrections do not fabricate historical operation linkage',count(array_unique(array_column($all,'operation_id')))===3&&count(array_filter($all,static fn(array $r):bool=>$r['previous_outcome']!==null))===0);
    outcomeReceiptCheck('lost and open do not introduce sale goals',count(MetrikaConversionGoalService::$calls)===1);

    $count=outcomeReceiptCount($p);$goals=count(MetrikaConversionGoalService::$calls);
    foreach([['invalid',null,null,null],['won',null,'not-numeric',null],['won',null,'-1',null],['won',null,'10000000000',null],['won',null,null,'2026-02-30'],['lost','unknown_reason',null,null]]as[$outcome,$reason,$amount,$date]){
        outcomeReceiptCheck('invalid authoritative input does not create receipt '.($failed+$passed+1),!SalesPipelineService::setOutcome(11,$outcome,$reason,'fixture',7,$amount,$date)&&outcomeReceiptCount($p)===$count&&count(MetrikaConversionGoalService::$calls)===$goals);
    }
    outcomeReceiptCheck('missing conversation is a failed/no-op update without observer or goal',!SalesPipelineService::setOutcome(999,'won',null,null,7)&&outcomeReceiptCount($p)===$count&&count(MetrikaConversionGoalService::$calls)===$goals);
    outcomeReceiptAdd($p,60);
    $p->exec("CREATE TRIGGER fixture_ignore BEFORE UPDATE ON conversations WHEN NEW.id=60 BEGIN SELECT RAISE(IGNORE); END");
    outcomeReceiptCheck('zero-row authoritative update remains false and creates no receipt',!SalesPipelineService::setOutcome(60,'won',null,null,7)&&outcomeReceiptCount($p)===$count);
    $p->exec('DROP TRIGGER fixture_ignore');

    foreach([[1,'anytour','telegram',0],[2,'anytour','max',0],[30,'anytour','max',1],[31,'anex','max',0],[32,'anytour','website',0]]as[$id,$project,$channel,$test]){
        outcomeReceiptAdd($p,$id,$project,$channel,$test);
        $before=outcomeReceiptCount($p);
        outcomeReceiptCheck('owner test foreign and unsupported scope preserve mutation but exclude receipt '.$id,SalesPipelineService::setOutcome($id,'won',null,null,7)&&outcomeReceiptCount($p)===$before);
    }
    foreach(['customer_id','project_key','channel']as$column){
        $id=['customer_id'=>33,'project_key'=>34,'channel'=>35][$column];outcomeReceiptAdd($p,$id);
        $p->exec('UPDATE customer_channels SET '.$column.'='.($column==='customer_id'?'999':"'mismatch'").' WHERE id='.$id);
        $before=outcomeReceiptCount($p);
        outcomeReceiptCheck('mismatched canonical channel join excludes receipt '.$column,SalesPipelineService::setOutcome($id,'won',null,null,7)&&outcomeReceiptCount($p)===$before);
    }
    ProjectConfig::$project='anex';$before=outcomeReceiptCount($p);
    outcomeReceiptCheck('foreign runtime config cannot observe an AnyTour row',SalesPipelineService::setOutcome(11,'open',null,null,7)&&outcomeReceiptCount($p)===$before);
    ProjectConfig::$project='anytour';
    $p->exec("UPDATE conversations SET lead_close_reason='legacy_reason' WHERE id=11");
    outcomeReceiptCheck('existing inactive-reason compatibility remains accepted',SalesPipelineService::setOutcome(11,'lost','legacy_reason',null,7)&&outcomeReceiptCount($p)===$before+1);
    $before=outcomeReceiptCount($p);
    outcomeReceiptCheck('system caller remains explicitly distinct from an identified manager',SalesPipelineService::setOutcome(11,'open',null,null,0)&&json_decode(outcomeReceiptRows($p)[$before]['payload_json'],true)['actor_role']==='system');

    $before=outcomeReceiptCount($p);$old=$p->query('SELECT lead_outcome FROM conversations WHERE id=11')->fetchColumn();
    $p->beginTransaction();$ok=SalesPipelineService::setOutcome(11,'won',null,null,7,'99.00','2026-11-05');
    $inside=$p->inTransaction()&&outcomeReceiptCount($p)===$before+1;$p->rollBack();
    outcomeReceiptCheck('outer transaction owns both mutation and receipt and rollback is preserved',$ok&&$inside&&!$p->inTransaction()&&outcomeReceiptCount($p)===$before&&$p->query('SELECT lead_outcome FROM conversations WHERE id=11')->fetchColumn()===$old);

    $p->exec("CREATE TRIGGER fixture_observer_failure BEFORE INSERT ON conversation_events BEGIN SELECT RAISE(ABORT,'fixture-private-secret'); END");
    $before=outcomeReceiptCount($p);$goals=count(MetrikaConversionGoalService::$calls);$logs=count(DiagnosticLogger::$entries);
    $ok=SalesPipelineService::setOutcome(11,'won',null,'fixture-failure-note',7,'77.00',null);
    outcomeReceiptCheck('observer storage failure does not change business success or the ordinary goal',$ok&&$p->query('SELECT lead_outcome FROM conversations WHERE id=11')->fetchColumn()==='won'&&outcomeReceiptCount($p)===$before&&count(MetrikaConversionGoalService::$calls)===$goals+1);
    $entry=DiagnosticLogger::$entries[$logs]??null;
    outcomeReceiptCheck('observer failure has only a fixed aggregate privacy-safe diagnostic',$entry===['modeled_conversion_outcome','receipt_write_failed',['coverage'=>'unknown'],null,'warning']&&!str_contains(json_encode($entry),'fixture-private-secret'));
    DiagnosticLogger::$throws=true;
    outcomeReceiptCheck('telemetry failure cannot propagate through a successful update',SalesPipelineService::setOutcome(11,'open',null,null,7)&&outcomeReceiptCount($p)===$before);
    DiagnosticLogger::$throws=false;
    $p->beginTransaction();$ok=SalesPipelineService::setOutcome(11,'won',null,null,7);$stillOwned=$p->inTransaction();$p->rollBack();
    outcomeReceiptCheck('observer failure neither commits nor rolls back an existing caller transaction',$ok&&$stillOwned&&!$p->inTransaction()&&outcomeReceiptCount($p)===$before);
    $p->exec('DROP TRIGGER fixture_observer_failure');

    MetrikaConversionGoalService::$throws=true;$before=outcomeReceiptCount($p);
    try{SalesPipelineService::setOutcome(11,'won',null,null,7);$threw=false;}catch(RuntimeException $e){$threw=$e->getMessage()==='fixture_goal_failed';}
    MetrikaConversionGoalService::$throws=false;
    outcomeReceiptCheck('an existing ordinary-goal exception is not newly swallowed or reordered',$threw&&outcomeReceiptCount($p)===$before);
    $before=outcomeReceiptCount($p);
    outcomeReceiptCheck('direct malformed applied-value contract remains a nonfatal coverage gap',!ModeledConversionOutcomeReceipt::observe($p,11,'won',null,7,'7.0',null)&&outcomeReceiptCount($p)===$before);
    $logs=count(DiagnosticLogger::$entries);$p->exec('DROP TABLE customer_channels');
    outcomeReceiptCheck('canonical scope read failure is also isolated from the successful mutation',SalesPipelineService::setOutcome(11,'open',null,null,7)&&outcomeReceiptCount($p)===$before&&count(DiagnosticLogger::$entries)===$logs+1);
} catch(Throwable $e) {
    outcomeReceiptCheck('fixture executes all required integration assertions',false);
    fwrite(STDERR,'outcome_fixture_failed: '.get_class($e).': '.$e->getMessage()."\n");
} finally {
    foreach(glob($dir.'/services/*')?:[]as$file)unlink($file);
    if(is_dir($dir.'/services'))rmdir($dir.'/services');
    if(is_dir($dir))rmdir($dir);
}
echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
