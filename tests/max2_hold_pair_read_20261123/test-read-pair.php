<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

// Synthetic local/CI SQLite fixtures only. No live config or business worker.
require_once dirname(__DIR__,2).'/tools/max2_hold_pair_read_20261123/diagnose.php';
require_once dirname(__DIR__,2).'/tools/max2_hold_pair_read_20261123/read-pair.php';
if (count($argv)!==2 || !is_dir($argv[1])) throw new RuntimeException('FIXTURE_APP_REQUIRED');
$app = $argv[1];
require_once $app.'/services/InternalOwnerDialogueScope.php';

$checks=0;
function need(bool $ok,string $name): void
{ global $checks; if (!$ok) throw new RuntimeException('FIXTURE_FAILED_'.$name); $checks++; }
function epoch(): string { return Max2OriginalHoldPairReader::EPOCH; }
function summary(): string
{ return json_encode(['profiles_read'=>1,'reserved'=>0,'evaluated'=>0,'held'=>1,'unknown'=>0,'lookup_list'=>0,
    'lookup_recheck'=>0,'goal_reads'=>0,'send_attempts'=>0,'provider_polls'=>0],JSON_THROW_ON_ERROR); }
function projection(string $gate='NO_ELIGIBLE_SIGNAL'): array
{
    $category=Max2HoldCandidateDiagnosis::GATES[$gate];
    $p=['canonical_result'=>$gate==='CANDIDATE_AVAILABLE'?'candidate':'null','first_gate'=>$gate,'category'=>$category,
        'checks'=>['access_allowed'=>true,'evidence_admitted'=>0]];
    return ['version'=>Max2HoldCandidateDiagnosis::VERSION,'temporal_result'=>'invariant_over_saved_interval',
        'original_null_reproduced'=>$gate!=='CANDIDATE_AVAILABLE','first_failed_gate'=>$gate,'category'=>$category,
        'at_tick_start'=>$p,'at_hold_recorded'=>$p,'canonical_parity_verified'=>true,'source_hashes_verified'=>true,
        'input_unchanged'=>true,'model_calls'=>0,'provider_requests'=>0,'writes'=>0];
}
function schema(): PDO
{
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach ([
        'CREATE TABLE max2_common_settings_v2(id INTEGER PRIMARY KEY,project_key TEXT,counter_id INTEGER,goal_id INTEGER,target TEXT,producer_contract_sha256 TEXT,activation_sha256 TEXT,activated_at_unix INTEGER,initial_profile_max_id TEXT,profile_cursor TEXT)',
        'CREATE TABLE max2_common_ticks_v2(tick_key TEXT PRIMARY KEY,activation_sha256 TEXT,state TEXT,cursor_start TEXT,cursor_end TEXT,started_at_unix INTEGER,finished_at_unix INTEGER,summary_json TEXT)',
        'CREATE TABLE max2_common_profile_work_v2(activation_sha256 TEXT,profile_id TEXT,tick_key TEXT,outcome TEXT,reason TEXT,recorded_at_unix INTEGER,PRIMARY KEY(activation_sha256,profile_id))',
        'CREATE TABLE modeled_conversion_profile_versions(id INTEGER PRIMARY KEY,project_key TEXT,platform TEXT,customer_key TEXT,conversation_id INTEGER,observed_at_unix INTEGER,input_sha256 TEXT,profile_json TEXT)',
        'CREATE TABLE customers(id INTEGER PRIMARY KEY)',
        'CREATE TABLE conversations(id INTEGER PRIMARY KEY,customer_id INTEGER,customer_channel_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,is_test INTEGER)',
        'CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_user_id TEXT,external_chat_id TEXT)',
    ] as $sql) $p->exec($sql);
    $p->prepare('INSERT INTO max2_common_settings_v2 VALUES(1,?,?,?,?,?,?,?,?,?)')->execute([
        'anytour',98615635,687941321,'anytour_modeled_value_v2_max2',
        '3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e',epoch(),
        Max2OriginalHoldPairReader::ACTIVATED_AT,Max2OriginalHoldPairReader::INITIAL_PROFILE_FLOOR,'20000']);
    return $p;
}
function add(PDO $p,int $id,int $finished,?string $epoch=null,?string $tick=null,int $conversation=0): void
{
    $epoch??=epoch();$tick??=hash('sha256','synthetic_tick_'.$id);$conversation=$conversation?:$id+20000;
    $customer=$id+10000;$channel=$id+30000;$actor='998877665544'.(string)$id;$chat='-'.$actor;
    $subject=['project_key'=>'anytour','platform'=>'max','customer_key'=>(string)$customer,'conversation_id'=>(string)$conversation];
    $wrapper=['access_status'=>'allowed','subject'=>$subject,
        'processing'=>['source_sha256'=>Max2OriginalHoldPairReader::PROFILE_PRODUCER_SHA256,'assessment_kind'=>'live'],
        'private_fixture_payload'=>'NEVER_EXPORT_PRIVATE_CONTENT'];
    $p->prepare('INSERT INTO customers VALUES(?)')->execute([$customer]);
    $p->prepare('INSERT INTO customer_channels VALUES(?,?,?,?,?,?)')->execute([$channel,$customer,'anytour','max',$actor,$chat]);
    $p->prepare('INSERT INTO conversations VALUES(?,?,?,?,?,?,?)')->execute([$conversation,$customer,$channel,'anytour','max',$chat,0]);
    $p->prepare('INSERT INTO modeled_conversion_profile_versions VALUES(?,?,?,?,?,?,?,?)')->execute([
        $id,'anytour','max',(string)$customer,$conversation,$finished-10,str_repeat('b',64),json_encode($wrapper,JSON_THROW_ON_ERROR)]);
    $t=$p->prepare('SELECT COUNT(*) FROM max2_common_ticks_v2 WHERE tick_key=?');$t->execute([$tick]);
    if ((int)$t->fetchColumn()===0) $p->prepare('INSERT INTO max2_common_ticks_v2 VALUES(?,?,?,?,?,?,?,?)')->execute([
        $tick,$epoch,'completed',(string)($id-1),(string)$id,$finished-4,$finished,summary()]);
    $p->prepare('INSERT INTO max2_common_profile_work_v2 VALUES(?,?,?,?,?,?)')->execute([
        $epoch,(string)$id,$tick,'HOLD','SAVED_VALUE_UNAVAILABLE',$finished-1]);
}
function db(): PDO
{ $p=schema();add($p,17654,Max2OriginalHoldPairReader::FINISHED_AT[0]);add($p,17655,Max2OriginalHoldPairReader::FINISHED_AT[1]);return $p; }
function image(PDO $p): string
{
    $out=[];
    foreach ($p->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $name)
        $out[$name]=$p->query('SELECT * FROM '.$name.' ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
}
function call(PDO $p,?callable $diagnose=null,?callable $clock=null,?callable $auth=null): array
{
    $before=image($p);$r=(new Max2OriginalHoldPairReader($p,$auth??static function():void{},
        $diagnose??static fn(array $w,array $s,int $floor,int $start,int $end):array=>projection(),$clock))->read();
    need(image($p)===$before,'DATABASE_IMAGE_UNCHANGED');
    need(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'NEVER_EXPORT_PRIVATE_CONTENT'),'PRIVATE_TEXT_ABSENT');
    need(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'998877665544'),'NATIVE_ACTOR_ABSENT');
    need(!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'customer_key'),'NATIVE_SUBJECT_ABSENT');
    return $r;
}
function unavailable(array $r,string $reason): void
{ need(($r['status']??null)==='UNAVAILABLE'&&($r['reason']??null)===$reason,'REASON_'.$reason);need(!isset($r['cases']),'NO_PARTIAL_CASES'); }
function changeProfile(PDO $p,callable $change,int $id=17654): void
{
    $q=$p->prepare('SELECT profile_json FROM modeled_conversion_profile_versions WHERE id=?');$q->execute([$id]);
    $w=json_decode($q->fetchColumn(),true,64,JSON_THROW_ON_ERROR);$w=$change($w);
    $p->prepare('UPDATE modeled_conversion_profile_versions SET profile_json=? WHERE id=?')->execute([json_encode($w,JSON_THROW_ON_ERROR),$id]);
}
function latest(PDO $p,?callable $change=null,int $original=17654,int $id=20001): void
{
    $q=$p->prepare('SELECT * FROM modeled_conversion_profile_versions WHERE id=?');$q->execute([$original]);$r=$q->fetch(PDO::FETCH_ASSOC);
    $w=json_decode($r['profile_json'],true,64,JSON_THROW_ON_ERROR);$w['private_fixture_payload']='NEWER_PROFILE_MUST_NOT_REPLACE_ORIGINAL';
    if ($change!==null) $w=$change($w);
    $p->prepare('INSERT INTO modeled_conversion_profile_versions VALUES(?,?,?,?,?,?,?,?)')->execute([
        $id,$r['project_key'],$r['platform'],$r['customer_key'],$r['conversation_id'],(int)$r['observed_at_unix']+1000,
        str_repeat('d',64),json_encode($w,JSON_THROW_ON_ERROR)]);
}

$p=db();$seen=[];
$r=call($p,static function(array $w,array $s,int $floor,int $start,int $end)use(&$seen):array{
    $seen[]=[$floor,$start,$end];return projection();
});
need($r['status']==='OBSERVED'&&$r['selected_profiles']===2&&$r['queries']===8,'EXACT_TWO_OBSERVED');
need(array_column($r['cases'],'case')===['A','B']&&$r['snapshot_rolled_back']===true,'ORDER_AND_ROLLBACK');
need($seen===[[1795387176,1795400579,1795400582],[1795387176,1795400881,1795400884]],'HISTORICAL_CLOCK_BRACKET_EXACT');
need((int)$p->query('PRAGMA query_only')->fetchColumn()===0&&!$p->inTransaction(),'CONNECTION_STATE_RESTORED');
need($r['current_access_rechecked_after_snapshot']===true&&$r['installed_source_hashes_verified']===true,'CURRENT_ACCESS_AND_SOURCE_FENCES');

$p=db();latest($p);$markers=[];
$r=call($p,static function(array $w)use(&$markers):array{$markers[]=$w['private_fixture_payload'];return projection();});
need($r['status']==='OBSERVED'&&$markers===['NEVER_EXPORT_PRIVATE_CONTENT','NEVER_EXPORT_PRIVATE_CONTENT'],'LATEST_SCALAR_NEVER_REPLACES_ORIGINAL');
need(!str_contains(json_encode($r),'NEWER_PROFILE_MUST_NOT_REPLACE_ORIGINAL'),'LATEST_PRIVATE_TEXT_ABSENT');
$p=db();latest($p,static function(array $w):array{$w['access_status']='revoked';return$w;},17655);
$analyzed=0;$r=call($p,static function()use(&$analyzed):array{$analyzed++;return projection();});
unavailable($r,'CURRENT_ACCESS_DENIED');need($analyzed===0,'BOTH_ACCESS_FENCES_PRECEDE_HISTORICAL_JSON');
$p=db();latest($p,static function(array $w):array{$w['access_status']='blocked';return$w;});
unavailable(call($p),'CURRENT_ACCESS_DENIED');
$p=db();latest($p,static function(array $w):array{unset($w['access_status']);return$w;});
unavailable(call($p),'CURRENT_ACCESS_UNAVAILABLE');
$p=db();latest($p);$p->prepare('UPDATE modeled_conversion_profile_versions SET profile_json=? WHERE id=20001')->execute([str_repeat('x',131073)]);
unavailable(call($p),'CURRENT_ACCESS_UNAVAILABLE');
$p=db();$p->sqliteCreateFunction('JSON_EXTRACT',static function(string $raw,string $path)use($p):?string {
    $w=json_decode($raw,true);return $p->inTransaction()?($w['access_status']??null):'revoked';
},2);
$r=call($p);unavailable($r,'CURRENT_ACCESS_DENIED');need(!$p->inTransaction(),'POST_SNAPSHOT_REVOCATION_REDACTS_WHOLE_PAIR');

$p=db();add($p,17656,1795401185);add($p,17657,1795400583,str_repeat('c',64));
$r=call($p);need($r['status']==='OBSERVED'&&$r['selected_profiles']===2,'UNRELATED_TICKS_AND_EPOCH_EXCLUDED');

$p=db();add($p,17656,1795400583,epoch(),hash('sha256','synthetic_tick_17654'));
unavailable(call($p),'PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED');
$p=db();$p->exec("DELETE FROM max2_common_profile_work_v2 WHERE profile_id='17655'");
unavailable(call($p),'PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED');
$p=db();$p->exec("UPDATE max2_common_profile_work_v2 SET profile_id='017654' WHERE profile_id='17654'");
unavailable(call($p),'PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED');
$p=db();$p->exec('UPDATE max2_common_settings_v2 SET goal_id=678187097');
unavailable(call($p),'SETTINGS_SCOPE_UNPROVED');
$p=db();$p->exec('UPDATE max2_common_settings_v2 SET activated_at_unix=1795387175');
unavailable(call($p),'SETTINGS_SCOPE_UNPROVED');
$p=db();$p->prepare('UPDATE max2_common_settings_v2 SET activation_sha256=?')->execute([str_repeat('a',64)]);
unavailable(call($p),'SETTINGS_SCOPE_UNPROVED');
$p=db();$p->exec("UPDATE max2_common_settings_v2 SET initial_profile_max_id='17652'");
unavailable(call($p),'SETTINGS_SCOPE_UNPROVED');
$p=db();$p->exec("UPDATE max2_common_profile_work_v2 SET reason='ANOTHER_REASON' WHERE profile_id='17654'");
unavailable(call($p),'ORIGINAL_WORK_UNPROVED');
$p=db();$s=json_decode(summary(),true);$s['held']=2;
$p->prepare('UPDATE max2_common_ticks_v2 SET summary_json=? WHERE finished_at_unix=?')->execute([json_encode($s),1795400583]);
unavailable(call($p),'ORIGINAL_TICK_UNPROVED');
$p=db();$p->exec("UPDATE max2_common_ticks_v2 SET cursor_end='1000' WHERE finished_at_unix=1795400583");
unavailable(call($p),'ORIGINAL_WORK_UNPROVED');
$p=db();$p->exec("UPDATE modeled_conversion_profile_versions SET profile_json='{bad' WHERE id=17654");
unavailable(call($p),'CURRENT_ACCESS_UNAVAILABLE');
$p=db();latest($p);$p->exec("UPDATE modeled_conversion_profile_versions SET profile_json='{bad' WHERE id=17654");
unavailable(call($p),'PROFILE_JSON_INVALID');
$p=db();$p->prepare('UPDATE modeled_conversion_profile_versions SET profile_json=? WHERE id=17654')->execute([str_repeat('x',131073)]);
unavailable(call($p),'PROFILE_SCOPE_OR_BOUND');
$p=db();changeProfile($p,static function(array $w):array{$w['processing']['source_sha256']=str_repeat('0',64);return$w;});
unavailable(call($p),'PROFILE_NOT_CURRENT_ADMITTED_SOURCE');
$p=db();changeProfile($p,static function(array $w):array{$w['processing']['assessment_kind']='archive';return$w;});
unavailable(call($p),'PROFILE_NOT_CURRENT_ADMITTED_SOURCE');
$p=db();changeProfile($p,static function(array $w):array{$w['subject']['customer_key']='foreign';return$w;});
unavailable(call($p),'PROFILE_NOT_CURRENT_ADMITTED_SOURCE');
$p=db();$p->exec('UPDATE modeled_conversion_profile_versions SET observed_at_unix=1795387175 WHERE id=17654');
unavailable(call($p),'PROFILE_SCOPE_OR_BOUND');
$p=db();$p->exec('UPDATE modeled_conversion_profile_versions SET observed_at_unix=1795400583 WHERE id=17654');
unavailable(call($p),'PROFILE_SCOPE_OR_BOUND');
$p=db();$p->exec("UPDATE customer_channels SET project_key='foreign' WHERE id=47654");
unavailable(call($p),'PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED');
$p=db();$p->exec('UPDATE conversations SET is_test=1 WHERE id=37654');
unavailable(call($p),'PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED');
$p=schema();add($p,17654,1795400583,null,null,2);add($p,17655,1795400885);
unavailable(call($p),'PAIR_NOT_EXACT_OR_NOT_CURRENTLY_ADMITTED');
$p=db();$p->exec("UPDATE customer_channels SET external_chat_id='changed' WHERE id=47654");
unavailable(call($p),'SUBJECT_NOT_ADMITTED');
$p=db();$p->prepare('UPDATE customer_channels SET external_user_id=? WHERE id=47654')->execute([str_repeat('9',280000)]);
unavailable(call($p),'READ_BYTE_BOUND');
$p=db();$r=call($p,static function():array{$out=projection();$out['customer_key']='NEVER_EXPORT_PRIVATE_CONTENT';return$out;});
unavailable($r,'DIAGNOSIS_PROJECTION_UNSAFE');
$p=db();$r=call($p,static function()use($p):array{$p->exec('DELETE FROM customers');return projection();});
unavailable($r,'DIAGNOSIS_UNAVAILABLE');need(!$p->inTransaction(),'FAILED_DIAGNOSIS_ROLLED_BACK');
$p=db();$clockCalls=0;
$r=call($p,null,static function()use(&$clockCalls):int{return$clockCalls++*1000000000;});
unavailable($r,'READ_TIME_BOUND');need(!$p->inTransaction(),'BUDGET_FAILURE_ROLLED_BACK');
$p=db();$authCalls=0;
$r=call($p,null,null,static function()use(&$authCalls):void{if(++$authCalls>=2)throw new RuntimeException('private token forbidden');});
unavailable($r,'ACCESS_UNAVAILABLE');need(!str_contains(json_encode($r),'private token'),'AUTH_EXCEPTION_REDACTED');
$p=db();$p->beginTransaction();$r=call($p);unavailable($r,'OUTER_TRANSACTION');need($p->inTransaction(),'CALLER_TRANSACTION_RETAINED');$p->rollBack();
$p=db();$r=call($p,static fn():array=>projection('CANDIDATE_AVAILABLE'));
need($r['status']==='OBSERVED'&&$r['cases'][0]['diagnosis']['original_null_reproduced']===false,'NONREPRODUCTION_IS_EXPLICIT');
$p=db();$r=call($p,static function():array{
    $x=projection();$x['at_tick_start']=['canonical_result'=>'null','first_gate'=>'FREEZE_AFTER_EVALUATION',
        'category'=>'time_inconsistent','checks'=>['freeze_not_after_evaluation'=>false]];
    $x['temporal_result']='original_evaluation_time_unresolved';$x['original_null_reproduced']=false;
    $x['first_failed_gate']='EVALUATION_TIME_BOUNDARY';$x['category']='time_boundary_unresolved';return$x;
});
need($r['status']==='OBSERVED'&&$r['cases'][0]['diagnosis']['temporal_result']==='original_evaluation_time_unresolved','CLOCK_UNCERTAINTY_RETAINED');

echo json_encode(['status'=>'PASS','checks'=>$checks,'database'=>'synthetic_sqlite','live_connections'=>0,
    'provider_calls'=>0,'worker_calls'=>0,'business_writes'=>0],JSON_THROW_ON_ERROR)."\n";
