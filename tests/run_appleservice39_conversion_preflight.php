<?php
declare(strict_types=1);
define('AS39_CONVERSION_PREFLIGHT_LIBRARY_ONLY',true);
require_once dirname(__DIR__).'/tools/appleservice39_conversion_preflight.php';
require_once dirname(__DIR__).'/services/ProjectFunnelConversions.php';

$passed=0;
function as39PreflightCheck(string $name, bool $ok): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    $passed++; echo 'PASS '.$name.PHP_EOL;
}

function as39PreflightFixture(): PDO
{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE max_channel_attribution_settings(project_key TEXT PRIMARY KEY,mode TEXT,counter_id TEXT,message_cursor INTEGER,activated_at_unix INTEGER);
        INSERT INTO max_channel_attribution_settings VALUES('appleservice39','collect','42611469',0,NULL);
        CREATE TABLE max_channel_conversion_outbox(id INTEGER PRIMARY KEY,project_key TEXT,platform TEXT,conversation_id INTEGER,external_user_id TEXT,counter_id TEXT,target TEXT,transport_target TEXT,event_unix INTEGER,state TEXT,next_attempt_unix INTEGER,funnel_policy_sha256 TEXT,client_id TEXT,attempts INTEGER,claimed_at_unix INTEGER,funnel_context_json TEXT,upload_id TEXT,claim_token TEXT,upload_status TEXT);
        CREATE UNIQUE INDEX uq_max_channel_stage ON max_channel_conversion_outbox(project_key,conversation_id,target);
        CREATE TABLE conversations(id INTEGER PRIMARY KEY,project_key TEXT,channel TEXT,is_test INTEGER,customer_channel_id INTEGER,customer_id INTEGER,external_chat_id TEXT,metadata_json TEXT);
        CREATE TABLE customer_channels(id INTEGER PRIMARY KEY,customer_id INTEGER,project_key TEXT,channel TEXT,external_chat_id TEXT,external_user_id TEXT);
        CREATE TABLE conversation_events(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,event_type TEXT,payload_json TEXT);
        CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,direction TEXT,sender_type TEXT,metadata_json TEXT,text TEXT);
        CREATE TABLE advertising_visit_observations(id INTEGER PRIMARY KEY AUTOINCREMENT,project_key TEXT,source_key TEXT,platform TEXT,counter_id TEXT,visit_id TEXT,identity_status TEXT,external_user_id TEXT,post_ref TEXT,offer_ref TEXT,visit_at_unix INTEGER,observed_at_unix INTEGER,client_id TEXT);");
    return $pdo;
}

function as39PreflightSnapshot(PDO $pdo): string
{
    $tables=['max_channel_attribution_settings','max_channel_conversion_outbox','conversations','customer_channels','conversation_events','messages','advertising_visit_observations'];
    $rows=[];
    foreach ($tables as $table) $rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY rowid')->fetchAll();
    return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
}

function as39PreflightRun(PDO $pdo, array $settings, int $now, bool $override=false): array
{
    $before=as39PreflightSnapshot($pdo);
    try {
        $audit=new As39ConversionPreflight($pdo,microtime(true)+25);
        $report=$audit->read($settings,$override,$now);
        return $report;
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('PRAGMA query_only=OFF');
        if ($before!==as39PreflightSnapshot($pdo)) throw new RuntimeException('fixture_changed_by_preflight');
    }
}

function as39PreflightEvent(PDO $pdo, int $conversation, string $type, array $payload, int $at): void
{
    $pdo->prepare('INSERT INTO conversation_events(conversation_id,event_type,payload_json) VALUES(?,?,?)')->execute([
        $conversation,$type,json_encode(ConversationRecordClock::stamp($payload,$at),JSON_THROW_ON_ERROR)]);
}

function as39PreflightSource(PDO $pdo, array $settings, int $now): array
{
    $user='7999000111222'; $client='17999999000012345678';
    $anchor=['project_key'=>'appleservice39','bot_key'=>'id390521129062_bot','attribution_state'=>'matched',
        'advertising_scope'=>ProjectFunnelEntry::scope($settings),'external_user_id'=>$user,
        'post_id'=>'service-display','offer_id'=>$settings['posts']['service-display']['offer_id'],
        'observations'=>[['visit_id'=>'PRIVATE_VISIT_CANARY']],'private_note'=>'PRIVATE_METADATA_CANARY'];
    $pdo->prepare('INSERT INTO customer_channels VALUES(1,1,?,?,?,?)')->execute(['appleservice39','max','PRIVATE_CHAT_CANARY',$user]);
    $pdo->prepare('INSERT INTO conversations VALUES(1,?,?,0,1,1,?,?)')->execute(['appleservice39','max','PRIVATE_CHAT_CANARY',json_encode(['max_bot_key'=>'id390521129062_bot','private_note'=>'PRIVATE_METADATA_CANARY'])]);
    as39PreflightEvent($pdo,1,'project_funnel_entry',$anchor,$now-60);
    as39PreflightEvent($pdo,1,'waiting_manager',['private_note'=>'PRIVATE_METADATA_CANARY'],$now-40);
    as39PreflightEvent($pdo,1,'project_funnel_stage',['project_key'=>'appleservice39','bot_key'=>'id390521129062_bot','stage'=>'working'],$now-10);
    $message=$pdo->prepare('INSERT INTO messages(conversation_id,direction,sender_type,metadata_json,text) VALUES(1,?,?,?,?)');
    foreach ([['inbound','customer',-50],['outbound','manager',-30],['inbound','customer',-20]] as [$direction,$sender,$delta]) {
        $message->execute([$direction,$sender,json_encode(ConversationRecordClock::stamp(['type'=>'message','private_note'=>'PRIVATE_METADATA_CANARY'],$now+$delta)),'PRIVATE_TRANSCRIPT_CANARY']);
    }
    $visit=$pdo->prepare('INSERT INTO advertising_visit_observations(project_key,source_key,platform,counter_id,visit_id,identity_status,external_user_id,post_ref,offer_ref,visit_at_unix,observed_at_unix,client_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $visit->execute(['appleservice39','max-miniapp','max','42611469','PRIVATE_VISIT_CANARY','verified',$user,'service-display',$anchor['offer_id'],$now-70,$now-65,$client]);
    return ['anchor'=>$anchor,'user'=>$user,'client'=>$client];
}

function as39PreflightQueue(PDO $pdo, array $settings, array $source, int $now, int $id, array $extra=[]): void
{
    $p=$settings; $p['metrika']['mode']='collect'; $policy=ProjectFunnelConversions::policy($p);
    $row=array_replace(['id'=>$id,'project_key'=>'appleservice39','platform'=>'max','conversation_id'=>10000+$id,
        'external_user_id'=>$source['user'],'counter_id'=>'42611469','target'=>'funnel_predictive_value','transport_target'=>'predict',
        'event_unix'=>$now-10,'state'=>'ready','next_attempt_unix'=>0,'funnel_policy_sha256'=>$policy['sha256'],
        'client_id'=>$source['client'],'attempts'=>0,'claimed_at_unix'=>null,
        'funnel_context_json'=>json_encode($source['anchor'],JSON_THROW_ON_ERROR),'upload_id'=>null,'claim_token'=>null,'upload_status'=>null],$extra);
    $pdo->prepare('INSERT INTO max_channel_conversion_outbox('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
}

$base=json_decode((string)file_get_contents(dirname(__DIR__).'/resources/project-funnel/appleservice39.json'),true,32,JSON_THROW_ON_ERROR);
$base=ProjectFunnelSettings::validate($base,'appleservice39','id390521129062_bot');
$now=1800000000; $pdo=as39PreflightFixture();
$empty=as39PreflightRun($pdo,$base,$now);
as39PreflightCheck('missing calibration and genuinely empty inventories remain explicit',
    $empty['settings']['prediction']['status']==='missing' && $empty['outbox']['examined']===0
    && $empty['visits']['examined']===0 && $empty['conversations']['examined']===0 && $empty['dedup_index']==='present');

$source=as39PreflightSource($pdo,$base,$now);
$observed=as39PreflightRun($pdo,$base,$now);
as39PreflightCheck('real fixture clocks identify observed stages and exact saved visit without a model',
    $observed['conversations']['eligible_anchor_cases']===1 && $observed['conversations']['observed_stage_cases']['customer_activity']===1
    && $observed['conversations']['observed_stage_cases']['customer_reply']===1 && $observed['conversations']['observed_stage_cases']['manager_request']===1
    && $observed['conversations']['observed_stage_cases']['working']===1 && $observed['conversations']['identity_qualified_cases']===1
    && !$observed['conversations']['estimate_possible'] && $observed['outbox']['examined']===0);

$current=$base;
$current['prediction']=['version'=>1,'model_id'=>'fixture-only-repair-calibration','evidence_sha256'=>str_repeat('d',64),
    'validated_at_unix'=>$now-1000,'valid_until_unix'=>$now+1000,'base_value_rub'=>'4321.98',
    'stage_probability'=>['manager_request'=>0.1,'working'=>0.25]];
$calibrated=as39PreflightRun($pdo,$current,$now,true);
as39PreflightCheck('current fixture calibration is reported only as availability evidence',
    $calibrated['settings']['source']==='connection_override' && $calibrated['settings']['prediction']['current']
    && $calibrated['settings']['prediction']['evidence_present'] && $calibrated['conversations']['calibrated_estimate_cases']===1
    && $calibrated['conversations']['identity_qualified_estimate_cases']===1);
$expired=$current; $expired['prediction']['valid_until_unix']=$now-1;
$future=$current; $future['prediction']['validated_at_unix']=$now+1;
as39PreflightCheck('expired and future fixture calibrations cannot produce estimates',
    as39PreflightRun($pdo,$expired,$now)['settings']['prediction']['status']==='expired'
    && !as39PreflightRun($pdo,$expired,$now)['conversations']['estimate_possible']
    && as39PreflightRun($pdo,$future,$now)['settings']['prediction']['status']==='not_yet_valid');
foreach (['off','collect','send'] as $mode) {
    $s=$base; $s['metrika']['mode']=$mode; $report=as39PreflightRun($pdo,$s,$now);
    as39PreflightCheck('effective '.$mode.' is observed without changing policy or storage',
        $report['settings']['mode']===$mode && $report['settings']['collect_send_policy_invariant']);
}
foreach (['goal','source'] as $change) {
    $bad=$base;
    if ($change==='goal') $bad['metrika']['goal_ids']['predictive_value']='PRIVATE_GOAL_CANARY';
    else $bad['advertising_source_key']='PRIVATE_SOURCE_CANARY';
    $reason=null; try { as39PreflightRun($pdo,$bad,$now); } catch (RuntimeException $error) { $reason=$error->getMessage(); }
    as39PreflightCheck('changed '.$change.' fails closed before inventory',$reason==='policy_changed');
}

as39PreflightQueue($pdo,$current,$source,$now,1);
as39PreflightQueue($pdo,$current,$source,$now,2,['state'=>'pending','external_user_id'=>'7999000111999']);
as39PreflightQueue($pdo,$current,$source,$now,3,['state'=>'unknown','attempts'=>1,'claim_token'=>'PRIVATE_CLAIM_CANARY','upload_id'=>'PRIVATE_UPLOAD_CANARY']);
as39PreflightQueue($pdo,$current,$source,$now,4,['state'=>'accepted','attempts'=>1,'upload_id'=>'PRIVATE_UPLOAD_CANARY','upload_status'=>'UPLOADED']);
as39PreflightQueue($pdo,$current,$source,$now,5,['event_unix'=>$now-22*86400]);
as39PreflightQueue($pdo,$current,$source,$now,6,['counter_id'=>'PRIVATE_COUNTER_CANARY','platform'=>'telegram']);
as39PreflightQueue($pdo,$current,$source,$now,7,['funnel_policy_sha256'=>str_repeat('e',64),'target'=>'PRIVATE_TARGET_CANARY']);
as39PreflightQueue($pdo,$current,$source,$now,8,['client_id'=>'17999999000099999999']);
$inventory=as39PreflightRun($pdo,$current,$now);
as39PreflightCheck('bounded queue distinguishes foreign outdated and eligible rows',
    $inventory['outbox']['examined']===8 && !$inventory['outbox']['truncated']
    && $inventory['outbox']['foreign_scope_rows']===1 && $inventory['outbox']['policy_mismatch_rows']===1
    && $inventory['outbox']['event_age']['older_than_21_days']===1 && $inventory['outbox']['due_pending_ready_rows']===3
    && $inventory['outbox']['identity_checked_rows']===3 && $inventory['outbox']['identity_qualified_rows']===2
    && $inventory['outbox']['ready_client_matches_rows']===1 && $inventory['outbox']['ready_client_mismatch_rows']===1);
as39PreflightCheck('unknown and accepted history is observed without resend or mutation',
    $inventory['outbox']['unknown_read_reconciliation_only']===1 && $inventory['outbox']['accepted_poll_only']===1
    && $inventory['outbox']['attempted_rows']===2 && $inventory['conversion_requests']===0
    && $pdo->query("SELECT state FROM max_channel_conversion_outbox WHERE id=3")->fetchColumn()==='unknown');
$json=json_encode($inventory,JSON_THROW_ON_ERROR);
foreach (['PRIVATE_',$source['user'],$source['client'],'17999999000099999999','4321.98',str_repeat('d',64),'fixture-only-repair-calibration'] as $canary) {
    if (str_contains($json,$canary)) throw new RuntimeException('report_contains_private_fixture_metadata');
}
as39PreflightCheck('report excludes raw identities contexts transcript receipts and financial values',
    $inventory['database_writes']===0 && $inventory['provider_reads']===0 && $inventory['provider_mutations']===0 && $inventory['customer_messages']===0);

$pdo->prepare("INSERT INTO conversations VALUES(2,'appleservice39','max',0,NULL,2,'PRIVATE_ORPHAN_CHAT','{}')")->execute();
$pdo->prepare("INSERT INTO conversations VALUES(3,'appleservice39','max',1,NULL,3,'PRIVATE_TEST_CHAT','{}')")->execute();
$pdo->prepare("INSERT INTO conversations VALUES(4,'anytour','max',0,NULL,4,'PRIVATE_OTHER_CHAT','{}')")->execute();
$pdo->prepare("INSERT INTO advertising_visit_observations(project_key,source_key,platform,counter_id,visit_at_unix,identity_status,client_id) VALUES('appleservice39','max-miniapp','max','PRIVATE_OTHER_COUNTER',?,'verified','PRIVATE_OTHER_CLIENT')")->execute([$now-50]);
$sources=as39PreflightRun($pdo,$current,$now);
as39PreflightCheck('source inventory separates missing entry and counter mismatch without other projects or tests',
    $sources['conversations']['examined']===2 && $sources['conversations']['without_entry']===1
    && $sources['conversations']['with_entry']===1 && $sources['visits']['counter_mismatch_rows']===1
    && $sources['visits']['verified_client_fresh_exact_rows']===1);

$pdo->prepare("UPDATE messages SET metadata_json=? WHERE id=1")->execute([json_encode(['type'=>'message'])]);
$pdo->prepare("UPDATE conversation_events SET payload_json=? WHERE event_type='project_funnel_stage'")->execute([json_encode(['stage'=>'working','project_key'=>'appleservice39','bot_key'=>'id390521129062_bot'])]);
$clocks=as39PreflightRun($pdo,$current,$now);
as39PreflightCheck('bare historical metadata never invents stage time',$clocks['conversations']['observed_stage_cases']['working']===0);
$pdo->prepare('UPDATE messages SET metadata_json=? WHERE id=1')->execute([str_repeat('x',16385)]);
$oversized=as39PreflightRun($pdo,$current,$now);
as39PreflightCheck('oversized history fails closed and declares incomplete coverage',
    $oversized['conversations']['metadata_oversize_cases']===1 && $oversized['conversations']['history_incomplete_cases']===1
    && !$oversized['conversations']['estimate_possible']);

$limited=as39PreflightFixture(); $limitedSource=as39PreflightSource($limited,$current,$now);
for ($id=1;$id<=1002;$id++) as39PreflightQueue($limited,$current,$limitedSource,$now,$id);
$visit=$limited->prepare("INSERT INTO advertising_visit_observations(project_key,source_key,platform,counter_id,identity_status,visit_at_unix,client_id) VALUES('appleservice39','max-miniapp','max','42611469','unverified',?,'')");
for ($i=0;$i<1001;$i++) $visit->execute([$now-50]);
for ($id=2;$id<=102;$id++) {
    $limited->prepare("INSERT INTO customer_channels VALUES(?,?,'appleservice39','max',?,?)")->execute([$id,$id,'chat-'.$id,(string)(8000000+$id)]);
    $limited->prepare("INSERT INTO conversations VALUES(?,'appleservice39','max',0,?,?,?,?)")->execute([$id,$id,$id,'chat-'.$id,json_encode(['max_bot_key'=>'id390521129062_bot'])]);
    $anchor=$limitedSource['anchor']; $anchor['external_user_id']=(string)(8000000+$id);
    as39PreflightEvent($limited,$id,'project_funnel_entry',$anchor,$now-60);
}
$limits=as39PreflightRun($limited,$current,$now);
as39PreflightCheck('page sentinels report samples without pretending to be totals',
    $limits['outbox']['examined']===1000 && $limits['outbox']['truncated'] && $limits['outbox']['coverage']==='bounded_sample'
    && $limits['visits']['examined']===1000 && $limits['visits']['truncated']
    && $limits['conversations']['examined']===100 && $limits['conversations']['truncated']
    && $limits['conversations']['detail_examined']===25 && $limits['conversations']['detail_truncated']);
as39PreflightCheck('canonical identity enrichment never exceeds one hundred reads',
    $limits['identity_checks']['performed']===100 && $limits['outbox']['identity_checked_rows']===100
    && $limits['outbox']['identity_checks_truncated']);

$guarded=as39PreflightFixture(); $writeBlocked=false;
try {
    $audit=new As39ConversionPreflight($guarded,microtime(true)+25);
    try { $guarded->exec("DELETE FROM max_channel_attribution_settings"); } catch (PDOException $error) { $writeBlocked=true; }
} finally {
    if ($guarded->inTransaction()) $guarded->rollBack();
    $guarded->exec('PRAGMA query_only=OFF');
}
as39PreflightCheck('fixture transaction enforces read-only access',$writeBlocked && $guarded->query('SELECT COUNT(*) FROM max_channel_attribution_settings')->fetchColumn()===1);
$reason=null;
try { new As39ConversionPreflight($guarded,microtime(true)-1); } catch (RuntimeException $error) { $reason=$error->getMessage(); }
$guarded->exec('PRAGMA query_only=OFF');
as39PreflightCheck('expired global deadline fails closed',$reason==='read_deadline');
ob_start(); $exit=as39ConversionPreflightMain(['preflight','PRIVATE_INVALID_ARGUMENT']); $failure=json_decode((string)ob_get_clean(),true);
as39PreflightCheck('invalid operation emits only a fixed redacted error',
    $exit===1 && $failure['reason']==='invalid_operation' && $failure['provider_reads']===0
    && !str_contains(json_encode($failure),'PRIVATE_'));
echo 'APPLESERVICE39 CONVERSION PREFLIGHT SYNTHETIC REGRESSION PASSED '.$passed.PHP_EOL;
