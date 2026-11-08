<?php
declare(strict_types=1);

/** Actual outcome owners/helpers; synthetic SQLite only, no app bootstrap or transport. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$outcomeTripPassed = 0;
$outcomeTripFailed = 0;
function outcomeTripCheck(string $name, bool $ok): void
{
    global $outcomeTripPassed, $outcomeTripFailed;
    echo ($ok ? 'PASS  ' : 'FAIL  ').$name.PHP_EOL;
    $ok ? ++$outcomeTripPassed : ++$outcomeTripFailed;
}

function outcomeTripProfile(PDO $pdo, int $version, string $trip, string $label, int $cid = 11): void
{
    $profile = json_encode(['schema_version'=>1, 'label'=>$label, 'fields'=>[]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO manager_request_profiles (conversation_id,version,trip_id,boundary_message_id,manager_id,operation_id,request_sha256,context_sha256,profile_json,profile_sha256,changed_fields_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$cid,$version,$trip,100+$version,1,bin2hex(random_bytes(16)),hash('sha256','request-'.$version),hash('sha256','context-'.$version),$profile,hash('sha256',$profile),'[]','2026-11-07 00:00:00']);
}

function outcomeTripFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'));
    ConversationDb::$pdo = $pdo;
    MetrikaConversionGoalService::$calls = [];
    ModeledConversionOutcomeReceipt::$calls = [];
    $pdo->exec(<<<'SQL'
PRAGMA foreign_keys=ON;
CREATE TABLE managers(id INTEGER PRIMARY KEY,is_active INTEGER,role TEXT,display_name TEXT);
CREATE TABLE conversations(
 id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,source_id INTEGER,
 channel TEXT,external_chat_id TEXT,status TEXT,manager_id INTEGER,is_test INTEGER DEFAULT 0,
 lead_stage_key TEXT,lead_outcome TEXT,lead_close_reason TEXT,lead_outcome_note TEXT,
 lead_sale_amount TEXT,lead_sale_date TEXT,lead_outcome_updated_at TEXT,lead_outcome_manager_id INTEGER
);
CREATE TABLE lead_stages(stage_key TEXT PRIMARY KEY,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER,is_terminal INTEGER,is_won INTEGER);
CREATE TABLE lead_close_reasons(reason_key TEXT PRIMARY KEY,display_name TEXT,is_active INTEGER);
CREATE TABLE lead_stage_history(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,from_stage_key TEXT,to_stage_key TEXT,changed_by_manager_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE lead_tags(id INTEGER PRIMARY KEY,tag_key TEXT,display_name TEXT,color TEXT,sort_order INTEGER,is_active INTEGER);
CREATE TABLE conversation_lead_tags(conversation_id INTEGER,tag_id INTEGER);
CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,actor_type TEXT,actor_id TEXT,payload_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE manager_request_profiles(
 conversation_id INTEGER NOT NULL,version INTEGER NOT NULL,trip_id TEXT NOT NULL,boundary_message_id INTEGER NOT NULL,
 manager_id INTEGER NOT NULL,operation_id TEXT NOT NULL,request_sha256 TEXT NOT NULL,context_sha256 TEXT NOT NULL,
 profile_json TEXT NOT NULL,profile_sha256 TEXT NOT NULL,changed_fields_json TEXT NOT NULL,created_at TEXT NOT NULL,
 PRIMARY KEY(conversation_id,version),UNIQUE(manager_id,operation_id),
 FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE TABLE lead_outcome_trip_bindings(
 id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER NOT NULL,manager_id INTEGER NOT NULL,
 operation_id TEXT NOT NULL,request_sha256 TEXT NOT NULL,trip_id TEXT,profile_version INTEGER,profile_sha256 TEXT,
 outcome_json BLOB NOT NULL,outcome_sha256 TEXT NOT NULL,scope_json BLOB NOT NULL,created_at TEXT NOT NULL,
 UNIQUE(manager_id,operation_id),
 FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE ON UPDATE CASCADE,
 FOREIGN KEY(conversation_id,profile_version) REFERENCES manager_request_profiles(conversation_id,version) ON DELETE CASCADE ON UPDATE CASCADE
);
CREATE INDEX outcome_trip_conversation ON lead_outcome_trip_bindings(conversation_id,id);
CREATE INDEX outcome_trip_profile ON lead_outcome_trip_bindings(conversation_id,profile_version);
INSERT INTO managers VALUES(1,1,'manager','Synthetic manager'),(2,1,'admin','Synthetic owner'),(3,0,'manager','Inactive');
INSERT INTO conversations VALUES(11,101,201,'anytour',7,'max','synthetic-101','manager',1,0,'working','open',NULL,NULL,NULL,NULL,'2026-11-07 00:00:00',1);
INSERT INTO conversations VALUES(12,102,202,'anytour',7,'max','synthetic-102','manager',1,0,'working','open',NULL,NULL,NULL,NULL,'2026-11-07 00:00:00',1);
INSERT INTO lead_stages VALUES('working','In progress','',1,1,0,0),('won','Sold','',2,1,1,1),('lost','Lost','',3,1,1,0),('retired','Retired','',4,0,1,1);
INSERT INTO lead_close_reasons VALUES('not_travelling','Not travelling',1),('legacy','Legacy reason',0);
SQL);
    outcomeTripProfile($pdo,1,str_repeat('a',32),'Trip one: initial request');
    outcomeTripProfile($pdo,2,str_repeat('a',32),'Trip one: clarified party');
    outcomeTripProfile($pdo,3,str_repeat('b',32),'Trip two: separate journey');
    outcomeTripProfile($pdo,1,str_repeat('c',32),'Different conversation',12);
    return $pdo;
}

function outcomeTripRows(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM lead_outcome_trip_bindings ORDER BY id')->fetchAll();
}
function outcomeTripBusiness(PDO $pdo): array
{
    return [
        'conversation'=>$pdo->query('SELECT * FROM conversations WHERE id=11')->fetch(),
        'history'=>$pdo->query('SELECT * FROM lead_stage_history ORDER BY id')->fetchAll(),
        'bindings'=>outcomeTripRows($pdo),
        'goals'=>MetrikaConversionGoalService::$calls,
        'receipts'=>ModeledConversionOutcomeReceipt::$calls,
    ];
}
function outcomeTripCommand(?string $trip, ?int $version, array $changes = []): array
{
    $view = SalesPipelineService::outcomeTripForConversation(11);
    return array_replace([
        'outcome'=>'won','close_reason'=>'','note'=>'Synthetic operational assertion',
        'sale_amount'=>'100000.00','sale_date'=>'2026-11-07',
        'stage_key'=>'won','expected_stage_key'=>'working',
        'outcome_trip'=>['operation_id'=>bin2hex(random_bytes(16)),'expected_revision'=>$view['revision'],
            'trip_id'=>$trip,'profile_version'=>$version],
    ],$changes);
}
function outcomeTripBindingIs(array $view, ?string $trip, ?int $version): bool
{
    if ($trip === null) return ($view['binding'] ?? null) === null;
    $binding = $view['binding'] ?? null;
    return is_array($binding) && ($binding['trip_id'] ?? null) === $trip
        && ($binding['version'] ?? null) === $version;
}

$root = dirname(__DIR__);
$dir = sys_get_temp_dir().'/outcome-trip-fixture-'.bin2hex(random_bytes(10));
mkdir($dir,0700);
mkdir($dir.'/services',0700);

try {
    $stubs = [
        'ConversationDb.php'=><<<'PHP'
<?php
class ConversationDb {public static PDO $pdo;public static function connection():PDO{return self::$pdo;}}
PHP,
        'MetrikaConversionGoalService.php'=><<<'PHP'
<?php
class MetrikaConversionGoalService {
 public static array $calls=[];
 public static function salesStage(int $cid,string $stage):bool {self::$calls[]=['stage',$cid,$stage,ConversationDb::connection()->inTransaction()];return true;}
 public static function saleOutcome(int $cid,string $outcome):bool {self::$calls[]=['sale',$cid,$outcome,ConversationDb::connection()->inTransaction()];return true;}
}
PHP,
        'ModeledConversionOutcomeReceipt.php'=><<<'PHP'
<?php
class ModeledConversionOutcomeReceipt {
 public static array $calls=[];
 public static function observe(PDO $pdo,int $cid,string $outcome,?string $reason,int $actor,?string $amount,?string $date):bool {
  self::$calls[]=[$cid,$outcome,$reason,$actor,$amount,$date,$pdo->inTransaction()];return true;
 }
}
PHP,
        'ManagerConversationService.php'=><<<'PHP'
<?php
class ManagerConversationService {
 public static function visibleConversation(int $cid,int $actor,bool $unused=false):?array {
  $q=ConversationDb::connection()->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$cid]);return $q->fetch()?:null;
 }
}
class ProjectAccessService {public static function initializeReadOnly():void {}}
class ManagerAuthService {public static function byId(int $id):?array {$q=ConversationDb::connection()->prepare('SELECT * FROM managers WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}}
class ManagerConversationAccessPolicy {public static function canEditVisibleConversation(array $c,array $m):bool{return !empty($m['is_active'])&&($m['role']==='admin'||(int)$c['manager_id']===(int)$m['id']);}}
PHP,
        'ProjectConfig.php'=><<<'PHP'
<?php
class ProjectConfig {public static function projectId():string{return 'anytour';}}
PHP,
        'DiagnosticLogger.php'=><<<'PHP'
<?php
class DiagnosticLogger {public static function log(...$unused):void {}}
PHP,
        // No hotel focus is present in these profiles. Any accidental use fails loudly.
        'ManagerHotelReferenceContext.php'=><<<'PHP'
<?php
class ManagerHotelReferenceContext {public static function __callStatic($name,$args){throw new LogicException('unexpected_hotel_context_in_trip_binding');}}
PHP,
    ];
    foreach ($stubs as $name=>$body) file_put_contents($dir.'/services/'.$name,$body);
    $copied = array_fill_keys(array_keys($stubs),true);
    $copy = function(string $name) use (&$copy,&$copied,$root,$dir): void {
        if (isset($copied[$name])) return;
        if (basename($name)!==$name || !is_file($root.'/services/'.$name)) throw new RuntimeException('fixture_service_unavailable: '.$name);
        $copied[$name]=true;
        $source=(string)file_get_contents($root.'/services/'.$name);
        if (!copy($root.'/services/'.$name,$dir.'/services/'.$name)) throw new RuntimeException('fixture_copy_failed');
        preg_match_all('~require(?:_once)?\s+__DIR__\s*\.\s*[\'"]/?([^\'"]+)[\'"]~',$source,$matches);
        foreach ($matches[1] as $dependency) $copy($dependency);
    };
    foreach (['SalesPipelineService.php','SalesOutcomeTripBinding.php','ManagerRequestProfileService.php','ManagerRequestProfile.php'] as $name) $copy($name);
    require $dir.'/services/SalesPipelineService.php';
    if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) throw new RuntimeException('pdo_sqlite_required');

    $allow = true;
    $authorizationStates = [];
    $authorize = static function(PDO $pdo,array $conversation,array $manager) use (&$allow,&$authorizationStates): bool {
        $authorizationStates[] = $pdo->inTransaction();
        return $allow && $conversation['project_key']==='anytour' && !empty($manager['is_active']);
    };
    $p = outcomeTripFixture();
    $initial = SalesPipelineService::outcomeTripForConversation(11);
    outcomeTripCheck('initial projection is available and has a CAS revision',($initial['available']??false)===true
        && preg_match('/\A[a-f0-9]{64}\z/D',(string)($initial['revision']??''))===1
        && ($initial['binding']??null)===null && is_array($initial['choices']??null));

    $tripOne = str_repeat('a',32);
    $tripTwo = str_repeat('b',32);
    $command = outcomeTripCommand($tripOne,2);
    $result = SalesPipelineService::saveOutcomeCommand(11,1,$command,$authorize);
    $saved = outcomeTripRows($p);
    outcomeTripCheck('write authorization is repeated under the owner transaction',$authorizationStates!==[]
        && array_filter($authorizationStates,static fn(bool $active):bool=>!$active)===[]);
    outcomeTripCheck('trip one remains selectable after trip two and keeps exact historical profile',($result['ok']??false)===true
        && outcomeTripBindingIs(SalesPipelineService::outcomeTripForConversation(11),$tripOne,2)
        && count($saved)===1 && (int)$saved[0]['profile_version']===2 && $saved[0]['trip_id']===$tripOne);
    outcomeTripCheck('outcome and compatible stage commit with the binding',$p->query('SELECT lead_outcome FROM conversations WHERE id=11')->fetchColumn()==='won'
        && $p->query('SELECT lead_stage_key FROM conversations WHERE id=11')->fetchColumn()==='won'
        && (int)$p->query('SELECT COUNT(*) FROM lead_stage_history')->fetchColumn()===1);
    outcomeTripCheck('ordinary analytics run once after the successful commit',count(MetrikaConversionGoalService::$calls)===2
        && count(ModeledConversionOutcomeReceipt::$calls)===1
        && array_filter(MetrikaConversionGoalService::$calls,static fn(array $r):bool=>$r[3]!==false)===[]
        && ModeledConversionOutcomeReceipt::$calls[0][6]===false);

    $before=outcomeTripBusiness($p);
    $retry=SalesPipelineService::saveOutcomeCommand(11,1,$command,$authorize);
    outcomeTripCheck('exact lost-ack retry neither duplicates binding nor analytics',($retry['ok']??false)===true
        && ($retry['replayed']??false)===true && outcomeTripBusiness($p)===$before);
    $reordered=$command;$reordered['outcome_trip']=array_reverse($command['outcome_trip'],true);
    $retry=SalesPipelineService::saveOutcomeCommand(11,1,$reordered,$authorize);
    outcomeTripCheck('nested binding key order does not change an exact operation retry',($retry['ok']??false)===true
        && ($retry['replayed']??false)===true && outcomeTripBusiness($p)===$before);
    $mismatch=$command;$mismatch['note']='Different operation body';
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$mismatch,$authorize);
    outcomeTripCheck('same operation ID cannot authorize different normalized content',($reply['ok']??true)===false && outcomeTripBusiness($p)===$before);
    $operation=SalesPipelineService::outcomeOperation(11,1,$command['outcome_trip']['operation_id'],$authorize);
    outcomeTripCheck('operation lookup resolves exact saved trip and profile',($operation['ok']??false)===true && ($operation['found']??false)===true
        && ($operation['operation']['trip_id']??null)===$tripOne && ($operation['operation']['profile_version']??null)===2);
    $foreignOperation=SalesPipelineService::outcomeOperation(12,1,$command['outcome_trip']['operation_id'],$authorize);
    outcomeTripCheck('operation lookup cannot cross the conversation',($foreignOperation['ok']??true)===false || ($foreignOperation['found']??true)===false);

    $onlyBinding=outcomeTripCommand($tripTwo,3,['expected_stage_key'=>'won']);
    $goals=MetrikaConversionGoalService::$calls;$receipts=ModeledConversionOutcomeReceipt::$calls;
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$onlyBinding,$authorize);
    outcomeTripCheck('binding-only correction changes the pinned trip without another sale assertion',($reply['ok']??false)===true
        && outcomeTripBindingIs(SalesPipelineService::outcomeTripForConversation(11),$tripTwo,3)
        && MetrikaConversionGoalService::$calls===$goals && ModeledConversionOutcomeReceipt::$calls===$receipts);

    $stale=outcomeTripCommand($tripOne,2,['expected_stage_key'=>'won']);
    outcomeTripProfile($p,4,$tripTwo,'Trip two: subsequent clarification');
    $before=outcomeTripBusiness($p);
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$stale,$authorize);
    outcomeTripCheck('a newly saved request profile invalidates the old editor revision',($reply['ok']??true)===false && outcomeTripBusiness($p)===$before);
    $retry=SalesPipelineService::saveOutcomeCommand(11,1,$command,$authorize);
    outcomeTripCheck('exact earlier operation still reconciles after later profile and binding changes',($retry['ok']??false)===true
        && ($retry['replayed']??false)===true && outcomeTripBusiness($p)===$before);

    foreach (['customer_id'=>999,'manager_id'=>2,'source_id'=>8] as $column=>$value) {
        $stale=outcomeTripCommand($tripOne,2,['expected_stage_key'=>'won']);
        $old=$p->query('SELECT '.$column.' FROM conversations WHERE id=11')->fetchColumn();
        $p->exec('UPDATE conversations SET '.$column.'='.(int)$value.' WHERE id=11');
        $before=outcomeTripBusiness($p);
        $reply=SalesPipelineService::saveOutcomeCommand(11,1,$stale,$authorize);
        outcomeTripCheck('canonical scope change invalidates the editor: '.$column,($reply['ok']??true)===false && outcomeTripBusiness($p)===$before);
        $p->exec('UPDATE conversations SET '.$column.'='.(int)$old.' WHERE id=11');
    }
    $stale=outcomeTripCommand($tripOne,2,['expected_stage_key'=>'won']);
    $p->exec("UPDATE conversations SET lead_outcome_note='Concurrent operational correction' WHERE id=11");
    $before=outcomeTripBusiness($p);
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$stale,$authorize);
    outcomeTripCheck('concurrent outcome change invalidates the editor without overwriting it',($reply['ok']??true)===false && outcomeTripBusiness($p)===$before);

    $p=outcomeTripFixture();$oldCommand=outcomeTripCommand($tripOne,2);
    $first=SalesPipelineService::saveOutcomeCommand(11,1,$oldCommand,$authorize);
    $laterCommand=outcomeTripCommand($tripTwo,3,[
        'outcome'=>'lost','close_reason'=>'not_travelling','note'=>'Later operational correction',
        'sale_amount'=>null,'sale_date'=>null,'stage_key'=>'lost','expected_stage_key'=>'won',
    ]);
    $later=SalesPipelineService::saveOutcomeCommand(11,1,$laterCommand,$authorize);
    $before=outcomeTripBusiness($p);
    outcomeTripCheck('a later business outcome and different trip actually commit',($first['ok']??false)===true && ($later['ok']??false)===true
        && $before['conversation']['lead_outcome']==='lost' && $before['conversation']['lead_stage_key']==='lost'
        && outcomeTripBindingIs(SalesPipelineService::outcomeTripForConversation(11),$tripTwo,3)
        && count($before['history'])===2 && count($before['bindings'])===2 && count($before['receipts'])===2);
    $retry=SalesPipelineService::saveOutcomeCommand(11,1,$oldCommand,$authorize);
    outcomeTripCheck('replaying an older operation cannot revert a later committed business outcome',($retry['ok']??false)===true
        && ($retry['replayed']??false)===true && outcomeTripBusiness($p)===$before
        && outcomeTripBindingIs(SalesPipelineService::outcomeTripForConversation(11),$tripTwo,3));

    $p=outcomeTripFixture();$zeroNote=outcomeTripCommand($tripOne,2,['note'=>'0']);
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$zeroNote,$authorize);
    $publicOutcome=SalesPipelineService::outcomeForConversation(11);
    outcomeTripCheck('literal zero note remains an exact valid binding and survives public CRM readback',($reply['ok']??false)===true
        && $p->query('SELECT lead_outcome_note FROM conversations WHERE id=11')->fetchColumn()==='0'
        && ($publicOutcome['note']??null)==='0'
        && outcomeTripBindingIs(SalesPipelineService::outcomeTripForConversation(11),$tripOne,2));

    $p=outcomeTripFixture();$initialWon=outcomeTripCommand($tripOne,2);
    $first=SalesPipelineService::saveOutcomeCommand(11,1,$initialWon,$authorize);
    $p->exec("INSERT INTO lead_stages VALUES('won_confirmed','Sale confirmed','',5,1,1,1)");
    $goals=MetrikaConversionGoalService::$calls;$receipts=ModeledConversionOutcomeReceipt::$calls;
    $stageOnly=outcomeTripCommand($tripOne,2,['stage_key'=>'won_confirmed','expected_stage_key'=>'won']);
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$stageOnly,$authorize);
    $stageGoals=array_slice(MetrikaConversionGoalService::$calls,count($goals));
    $stageReceipts=array_slice(ModeledConversionOutcomeReceipt::$calls,count($receipts));
    outcomeTripCheck('stage-only won transition preserves the existing stage sale and receipt observers once',($first['ok']??false)===true
        && ($reply['ok']??false)===true && ($reply['replayed']??true)===false
        && $stageGoals===[['stage',11,'won_confirmed',false],['sale',11,'won',false]]
        && count($stageReceipts)===1 && $stageReceipts[0]===[11,'won',null,1,'100000.00','2026-11-07',false]
        && $p->query('SELECT lead_stage_key FROM conversations WHERE id=11')->fetchColumn()==='won_confirmed'
        && (int)$p->query('SELECT COUNT(*) FROM lead_stage_history')->fetchColumn()===2
        && outcomeTripBindingIs(SalesPipelineService::outcomeTripForConversation(11),$tripOne,2));

    $p=outcomeTripFixture();
    foreach ([['trip_id'=>str_repeat('c',32),'profile_version'=>1],['trip_id'=>$tripOne,'profile_version'=>3],['trip_id'=>str_repeat('f',32),'profile_version'=>99]] as $wrong) {
        $bad=outcomeTripCommand($wrong['trip_id'],$wrong['profile_version']);$before=outcomeTripBusiness($p);
        $reply=SalesPipelineService::saveOutcomeCommand(11,1,$bad,$authorize);
        outcomeTripCheck('foreign fabricated or mismatched profile pin is rejected',($reply['ok']??true)===false && outcomeTripBusiness($p)===$before);
    }
    $valid=outcomeTripCommand($tripOne,2);$before=outcomeTripBusiness($p);$allow=false;
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$valid,$authorize);$allow=true;
    outcomeTripCheck('revoked write authorization leaves outcome stage and binding untouched',($reply['ok']??true)===false && outcomeTripBusiness($p)===$before);
    $reply=SalesPipelineService::saveOutcomeCommand(11,3,$valid,$authorize);
    outcomeTripCheck('inactive actor cannot apply a binding',($reply['ok']??true)===false && outcomeTripBusiness($p)===$before);
    $authorizationCalls=0;$authorizationSnapshots=[];
    $denySecond=static function(PDO $pdo,array $conversation,array $manager) use (&$authorizationCalls,&$authorizationSnapshots): bool {
        ++$authorizationCalls;
        $authorizationSnapshots[]=[$pdo->inTransaction(),$conversation['lead_outcome']??null,$conversation['lead_stage_key']??null];
        return $authorizationCalls===1 && $pdo->inTransaction() && (int)$conversation['id']===11
            && (int)$manager['id']===1 && !empty($manager['is_active']);
    };
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$valid,$denySecond);
    outcomeTripCheck('second authorization denial rolls back changed outcome and stage before binding or analytics',($reply['ok']??true)===false
        && ($reply['error']??null)==='forbidden' && $authorizationCalls===2
        && $authorizationSnapshots===[[true,'open','working'],[true,'won','won']]
        && !$p->inTransaction() && outcomeTripBusiness($p)===$before);

    $p->exec("CREATE TRIGGER outcome_binding_insert_failure BEFORE INSERT ON lead_outcome_trip_bindings BEGIN SELECT RAISE(ABORT,'synthetic insert failure'); END");
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$valid,$authorize);
    outcomeTripCheck('binding insert failure rolls back outcome and stage before analytics',($reply['ok']??true)===false
        && ! $p->inTransaction() && outcomeTripBusiness($p)===$before);
    $p->exec('DROP TRIGGER outcome_binding_insert_failure');
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$valid,$authorize);
    outcomeTripCheck('same command remains usable after rollback',($reply['ok']??false)===true && count(outcomeTripRows($p))===1);

    $nested=outcomeTripCommand($tripOne,2,['expected_stage_key'=>'won']);
    $before=outcomeTripBusiness($p);
    $p->beginTransaction();
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$nested,$authorize);
    $callerTransaction=$p->inTransaction();$p->rollBack();
    outcomeTripCheck('command rejects nesting without ending caller transaction',($reply['ok']??true)===false && $callerTransaction && outcomeTripBusiness($p)===$before);

    $clear=outcomeTripCommand(null,null,['expected_stage_key'=>'won']);
    $goals=MetrikaConversionGoalService::$calls;$receipts=ModeledConversionOutcomeReceipt::$calls;
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$clear,$authorize);
    outcomeTripCheck('explicit unknown clears only the binding and emits no sale analytics',($reply['ok']??false)===true
        && outcomeTripBindingIs(SalesPipelineService::outcomeTripForConversation(11),null,null)
        && MetrikaConversionGoalService::$calls===$goals && ModeledConversionOutcomeReceipt::$calls===$receipts);
    $bindAgain=outcomeTripCommand($tripOne,2,['expected_stage_key'=>'won']);
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$bindAgain,$authorize);
    outcomeTripCheck('fixture can bind again after explicit unknown',($reply['ok']??false)===true);
    $legacy=SalesPipelineService::setOutcome(11,'lost','not_travelling','Legacy outcome update',1);
    outcomeTripCheck('legacy outcome change cannot retain a stale trip binding',$legacy
        && outcomeTripBindingIs(SalesPipelineService::outcomeTripForConversation(11),null,null));

    $p=outcomeTripFixture();$pending=outcomeTripCommand($tripOne,2);
    $p->exec('DROP TABLE lead_outcome_trip_bindings');
    $view=SalesPipelineService::outcomeTripForConversation(11);
    $before=$p->query('SELECT * FROM conversations WHERE id=11')->fetch();
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$pending,$authorize);
    outcomeTripCheck('missing binding migration is unavailable and never silently accepts an explicit pin',($view['available']??true)===false
        && ($reply['ok']??true)===false && $p->query('SELECT * FROM conversations WHERE id=11')->fetch()===$before
        && MetrikaConversionGoalService::$calls===[] && ModeledConversionOutcomeReceipt::$calls===[]);
    outcomeTripCheck('legacy outcome remains usable without optional binding storage',SalesPipelineService::setOutcome(11,'lost','not_travelling','Legacy without migration',1)
        && $p->query('SELECT lead_outcome FROM conversations WHERE id=11')->fetchColumn()==='lost');
    $legacyScope=[];
    $typedLegacyAuthorization=static function(PDO $pdo,array $conversation,array $manager) use (&$legacyScope): bool {
        $legacyScope[]=[$conversation['id']??null,$conversation['project_key']??null,$conversation['lead_outcome']??null,$manager['id']??null,$manager['is_active']??null];
        return (int)($conversation['id']??0)===11 && ($conversation['project_key']??null)==='anytour'
            && ($conversation['lead_outcome']??null)==='lost' && (int)($manager['id']??0)===1 && !empty($manager['is_active']);
    };
    $legacyCommand=['outcome'=>'won','close_reason'=>'','note'=>'Legacy typed authorization',
        'sale_amount'=>'10.00','sale_date'=>'2026-11-07','stage_key'=>'won','expected_stage_key'=>'working'];
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$legacyCommand,$typedLegacyAuthorization);
    outcomeTripCheck('missing storage fallback supplies fresh conversation and manager to typed authorization',($reply['ok']??false)===true
        && $legacyScope===[[11,'anytour','lost',1,1]]
        && $p->query('SELECT lead_outcome FROM conversations WHERE id=11')->fetchColumn()==='won'
        && $p->query('SELECT lead_stage_key FROM conversations WHERE id=11')->fetchColumn()==='won');

    $p=outcomeTripFixture();
    $p->exec("UPDATE conversations SET project_key='other_project' WHERE id=11");
    $unsupported=SalesPipelineService::outcomeTripForConversation(11);
    $legacy=SalesPipelineService::setOutcome(11,'lost','not_travelling','Other project legacy outcome',1);
    outcomeTripCheck('unsupported project is distinguished while legacy outcome still works with binding storage',($unsupported['available']??true)===false
        && ($unsupported['reason']??null)==='unsupported_project' && $legacy
        && $p->query('SELECT lead_outcome FROM conversations WHERE id=11')->fetchColumn()==='lost'
        && $p->query('SELECT project_key FROM conversations WHERE id=11')->fetchColumn()==='other_project'
        && (SalesPipelineService::outcomeTripForConversation(11)['reason']??null)==='unsupported_project');

    $p=outcomeTripFixture();$pending=outcomeTripCommand($tripOne,2);
    $p->exec("UPDATE manager_request_profiles SET profile_sha256='invalid' WHERE conversation_id=11 AND version=2");
    $before=outcomeTripBusiness($p);
    $reply=SalesPipelineService::saveOutcomeCommand(11,1,$pending,$authorize);
    outcomeTripCheck('corrupt selected profile cannot become an authoritative outcome binding',($reply['ok']??true)===false && outcomeTripBusiness($p)===$before);
} catch (Throwable $e) {
    ++$outcomeTripFailed;
    fwrite(STDERR,'FAIL  outcome trip fixture: '.$e->getMessage().PHP_EOL);
} finally {
    $remove = static function(string $path) use (&$remove): void {
        if (is_dir($path)) { foreach (scandir($path) ?: [] as $entry) if ($entry!=='.' && $entry!=='..') $remove($path.'/'.$entry); @rmdir($path); }
        elseif (is_file($path)) @unlink($path);
    };
    $remove($dir);
}
echo 'Outcome trip binding: '.$outcomeTripPassed.' passed, '.$outcomeTripFailed.' failed'.PHP_EOL;
exit($outcomeTripFailed ? 1 : 0);
