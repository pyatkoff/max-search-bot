<?php
declare(strict_types=1);

/** Synthetic SQLite/native MySQL execution of the actual bounded aggregate reader. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('PURCHASE_STUDY_AUDIT_LIBRARY',true);
require_once dirname(__DIR__).'/services/InternalOwnerDialogueScope.php';
require_once dirname(__DIR__).'/tools/purchase_study_metadata.php';
$passed=0;$failed=0;
final class StudyMetadataProbePdo extends PDO
{
    public bool $probeReadOnly=false;
    public bool $probeExecuted=false;
    public bool $probeRejected=false;
    public bool $delayProfileQuery=false;
    public bool $delayed=false;
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if($this->probeReadOnly&&$this->inTransaction()&&!$this->probeExecuted){
            $this->probeExecuted=true;
            try{$this->exec("INSERT INTO messages(id,text) VALUES (987654,'fixture-write-probe')");}
            catch(PDOException $e){$this->probeRejected=true;throw$e;}
            throw new RuntimeException('fixture_readonly_was_not_enforced');
        }
        if($this->delayProfileQuery&&!$this->delayed&&str_contains($query,'SELECT p.id')){
            $this->delayed=true;usleep(1100000);
        }
        return parent::prepare($query,$options);
    }
}
function studyMetadataCheck(string $name,bool $ok):void
{
    global $passed,$failed;echo($ok?'PASS  ':'FAIL  ').$name."\n";if($ok)$passed++;else$failed++;
}
function studyMetadataDb():StudyMetadataProbePdo
{
    $dsn=getenv('PURCHASE_AUDIT_TEST_DSN')?:'sqlite::memory:';
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    if($dsn!=='sqlite::memory:'){
        if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('isolated_fixture_dsn_required');
        $attributes=[];
        foreach(explode(';',substr($dsn,6))as$part){
            if($part==='')continue;$pair=explode('=',$part,2);$name=strtolower(trim($pair[0]));
            if(count($pair)!==2||isset($attributes[$name]))throw new RuntimeException('isolated_fixture_dsn_required');
            $attributes[$name]=$pair[1];
        }
        if(($attributes['dbname']??'')!=='purchase_audit_fixture'||!in_array($attributes['host']??'',['127.0.0.1','localhost','mysql'],true))throw new RuntimeException('isolated_fixture_dsn_required');
        $options[PDO::ATTR_EMULATE_PREPARES]=false;
    }
    $p=new StudyMetadataProbePdo($dsn,getenv('PURCHASE_AUDIT_TEST_USER')?:null,getenv('PURCHASE_AUDIT_TEST_PASSWORD')?:null,
        $options);
    foreach(['modeled_conversion_profile_versions','modeled_conversion_stream_versions','modeled_conversion_profiles_state','modeled_conversion_stream_state','messages','conversation_events','conversations','customer_channels']as$table)$p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec('CREATE TABLE customer_channels(id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),display_name TEXT)');
    $p->exec("CREATE TABLE conversations(id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),is_test INTEGER DEFAULT 0,lead_outcome VARCHAR(32),private_note TEXT)");
    $p->exec('CREATE TABLE modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id VARCHAR(40),observed_at_unix BIGINT,profile_json MEDIUMTEXT)');
    $p->exec('CREATE TABLE modeled_conversion_stream_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),source_kind VARCHAR(16),observed_at_unix BIGINT,snapshot_json MEDIUMTEXT)');
    $p->exec('CREATE TABLE modeled_conversion_profiles_state(project_key VARCHAR(40) PRIMARY KEY,enabled INTEGER)');
    $p->exec('CREATE TABLE modeled_conversion_stream_state(project_key VARCHAR(40) PRIMARY KEY,enabled INTEGER)');
    $p->exec('CREATE TABLE messages(id BIGINT PRIMARY KEY,text MEDIUMTEXT)');
    $p->exec('CREATE TABLE conversation_events(id BIGINT PRIMARY KEY,payload_json MEDIUMTEXT)');
    $p->exec("INSERT INTO modeled_conversion_profiles_state VALUES ('anytour',1)");
    $p->exec("INSERT INTO modeled_conversion_stream_state VALUES ('anytour',1)");
    $p->exec("INSERT INTO messages VALUES (1,'fixture-native-message-never-read')");
    $p->exec("INSERT INTO conversation_events VALUES (1,'fixture-native-event-never-read')");
    return$p;
}
function studyMetadataScope(PDO $p,int $id,int $customer,string $channel='max',string $project='anytour',int $test=0,string $outcome='open'):void
{
    $p->prepare('INSERT INTO customer_channels VALUES (?,?,?,?,?)')->execute([$id,$customer,$project,$channel,'fixture-hidden-display-name']);
    $p->prepare('INSERT INTO conversations VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$customer,$id,$project,$channel,$test,$outcome,'fixture-hidden-native-note']);
}
function studyMetadataProfile(PDO $p,int $id,int $conversation,int $customer,int $at,array $override=[],string $platform='max',string $project='anytour',?string $raw=null):void
{
    $value=['access_status'=>'allowed','profile'=>['extraction_version'=>'ru_trip_prefix_rules_v1_18','trip_status'=>'unknown','prefix_complete'=>false,'private_field'=>'fixture-private-profile-text']];
    $value=array_replace_recursive($value,$override);
    $p->prepare('INSERT INTO modeled_conversion_profile_versions VALUES (?,?,?,?,?,?,?)')->execute([$id,$project,$platform,(string)$customer,(string)$conversation,$at,$raw??json_encode($value,JSON_THROW_ON_ERROR)]);
}
function studyMetadataEvent(PDO $p,int $id,int $conversation,int $customer,int $recorded,int $observed,string $type,array $payload=[],array $override=[],string $platform='max'):void
{
    $value=['project_key'=>'anytour','platform'=>$platform,'customer_key'=>(string)$customer,'conversation_id'=>(string)$conversation,
        'source_status'=>'present','payload_status'=>'copied','source_timestamp_basis'=>'canonical_recording_unix_v1','source_created_at_unix'=>$recorded,
        'payload'=>['event_type'=>$type,'payload_json'=>json_encode($payload,JSON_THROW_ON_ERROR),'private_text'=>'fixture-private-journal-text']];
    $value=array_replace_recursive($value,$override);
    $p->prepare('INSERT INTO modeled_conversion_stream_versions VALUES (?,?,?,?,?)')->execute([$id,'anytour','event',$observed,json_encode($value,JSON_THROW_ON_ERROR)]);
}
function studyMetadataOutcome(int $recorded,array $override=[]):array
{
    return array_replace_recursive(['version'=>1,'producer'=>'sales_pipeline_manager_assertion','actor_role'=>'manager','outcome'=>'won','profile_id'=>null,
        '_recorded_clock'=>['version'=>1,'basis'=>'server_recording_unix','unix'=>$recorded],'note'=>'fixture-private-outcome-text'],$override);
}
function studyMetadataContents(PDO $p):array
{
    $out=[];foreach(['messages','conversation_events','conversations','customer_channels','modeled_conversion_profile_versions','modeled_conversion_stream_versions','modeled_conversion_profiles_state','modeled_conversion_stream_state']as$table)$out[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll();return$out;
}
function studyMetadataMutationCounters(PDO $p):array
{
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return['total_changes'=>(int)$p->query('SELECT total_changes()')->fetchColumn()];
    $out=[];foreach($p->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete','Com_replace')")->fetchAll(PDO::FETCH_ASSOC)as$row)$out[$row['Variable_name']]=(int)$row['Value'];ksort($out);return$out;
}

try {
    $driver=(getenv('PURCHASE_AUDIT_TEST_DSN')?:'sqlite::memory:')==='sqlite::memory:'?'sqlite':'mysql';
    if(!in_array($driver,PDO::getAvailableDrivers(),true))throw new RuntimeException('fixture_pdo_driver_required');
    $through=time();$after=$through-3600;$p=studyMetadataDb();
    foreach([[10,700001,'max','anytour',0,'won'],[11,700001,'telegram','anytour',0,'open'],[12,700002,'telegram','anytour',0,'lost'],
        [13,700003,'max','anytour',0,'open'],[14,700004,'max','anytour',0,'open'],[15,700005,'max','anytour',0,'open'],
        [16,700006,'max','anytour',1,'won'],[1,700007,'telegram','anytour',0,'won'],[2,700008,'max','anytour',0,'won'],
        [18,700009,'max','anex',0,'won'],[19,700010,'max','anytour',0,'won'],[20,700011,'max','anytour',0,'won'],
        [21,700012,'max','anytour',0,'won'],[22,700013,'max','anytour',0,'won'],[23,700014,'website','anytour',0,'won'],
        [24,700015,'max','anex',0,'won'],[25,700016,'max','anytour',0,'open'],[26,700017,'max','anytour',0,'open'],[17,700018,'max','anytour',0,'open']]as$args)studyMetadataScope($p,...$args);
    studyMetadataProfile($p,1,10,700001,$after+10);
    studyMetadataProfile($p,2,10,700001,$after+20);
    studyMetadataProfile($p,3,11,700001,$after+30,['profile'=>['extraction_version'=>'ru_trip_prefix_rules_v1_17']],'telegram');
    studyMetadataProfile($p,4,12,700002,$after+40,[],'telegram');
    studyMetadataProfile($p,5,13,700003,$after+50,['access_status'=>'denied']);
    studyMetadataProfile($p,6,14,700004,$after+60,[],'max','anytour','{bad json');
    studyMetadataProfile($p,7,15,700005,$after+70,['profile'=>['trip_status'=>'accepted','prefix_complete'=>true]]);
    studyMetadataProfile($p,8,16,700006,$after+80);
    studyMetadataProfile($p,9,1,700007,$after+90,[],'telegram');
    studyMetadataProfile($p,10,2,700008,$after+100);
    studyMetadataProfile($p,11,18,700009,$after+110);
    studyMetadataProfile($p,12,19,700010,$after+120);
    studyMetadataProfile($p,13,20,700011,$after+130);
    studyMetadataProfile($p,14,21,700012,$after+140);
    studyMetadataProfile($p,15,22,999999,$after+150);
    studyMetadataProfile($p,16,23,700014,$after+160,[],'website');
    studyMetadataProfile($p,17,24,700015,$after+170,[],'max','anex');
    studyMetadataProfile($p,18,25,700016,$after);
    studyMetadataProfile($p,19,26,700017,$through+1);
    studyMetadataProfile($p,20,17,700018,$through);
    $p->exec("UPDATE customer_channels SET customer_id=999999 WHERE id=19");
    $p->exec("UPDATE customer_channels SET project_key='mismatch' WHERE id=20");
    $p->exec("UPDATE customer_channels SET channel='mismatch' WHERE id=21");
    $at=$after+200;$observed=$after+210;
    studyMetadataEvent($p,1,10,700001,$at,$observed,'sales_pipeline_outcome_observed',studyMetadataOutcome($at));
    studyMetadataEvent($p,2,12,700002,$at,$observed,'sales_pipeline_outcome_observed',studyMetadataOutcome($at,['actor_role'=>'system','profile_id'=>'17']),[],'telegram');
    studyMetadataEvent($p,3,10,700001,$at,$observed,'metrika_max_sale');
    studyMetadataEvent($p,4,10,700001,$at,$observed,'channel_subscription_observed');
    studyMetadataEvent($p,5,10,700001,$at,$observed,'channel_retention_30m_observed');
    studyMetadataEvent($p,6,15,700005,$at,$observed,'fixture_uninterpreted');
    studyMetadataEvent($p,7,17,700018,$at,$observed,'sales_pipeline_outcome_observed',studyMetadataOutcome($at),['source_status'=>'deleted']);
    studyMetadataEvent($p,8,11,700001,$at,$observed,'metrika_max_sale',[],[],'telegram');
    studyMetadataEvent($p,9,10,700001,$at,$observed,'metrika_max_sale',[],['project_key'=>'anex']);
    studyMetadataEvent($p,10,10,700001,$at,$after,'metrika_max_sale');
    studyMetadataEvent($p,11,10,700001,$at,$through+1,'metrika_max_sale');
    $before=studyMetadataContents($p);$changes=studyMetadataMutationCounters($p);
    if($driver==='sqlite')$p->exec('PRAGMA query_only=ON');$out=(new PurchaseStudyMetadataAudit($p))->collect($after,$through);
    studyMetadataCheck('window is exclusive at the lower and inclusive at the upper bound',$out['profile_versions_examined']===16&&$out['window_after_exclusive_utc']===gmdate('Y-m-d\TH:i:s\Z',$after)&&$out['window_through_inclusive_utc']===gmdate('Y-m-d\TH:i:s\Z',$through));
    studyMetadataCheck('native scope and access exclude owner test foreign and mismatched subjects',$out['allowed_profile_versions']===6&&$out['denied_or_unchecked_profile_versions']===9&&$out['invalid_profile_json']===1);
    studyMetadataCheck('profile metadata preserves version trip and prefix denominators',$out['v1_18_profile_versions']===5&&$out['unknown_trip_profile_versions']===5&&$out['incomplete_prefix_versions']===5);
    studyMetadataCheck('canonical people deduplicate across versions and platforms before selecting scopes',$out['distinct_allowed_people_in_page']===4&&$out['selected_scopes']===4&&$out['checked_earliest_scope']==='earliest_in_checked_page_only');
    studyMetadataCheck('current won and lost metadata do not become purchase labels',$out['current_operational_terminal_scopes']===2&&$out['purchase_label_readiness']===false&&$out['independent_control_people']===0&&$out['historical_labels_reconstructed']===0);
    studyMetadataCheck('only the exact selected scopes and project enter the event page',$out['event_metadata_revisions_examined']===7&&$out['present_event_revisions']===6&&$out['ordinary_sale_marker_revisions']===1);
    studyMetadataCheck('event counters are declared metadata and never independently verified outcomes',$out['declared_outcome_event_revisions']===2&&$out['declared_manager_role_revisions']===1&&$out['declared_profile_reference_revisions']===1&&$out['event_contracts_independently_verified']===false);
    studyMetadataCheck('membership and unrecognized event counts remain separate',$out['subscription_receipt_revisions']===1&&$out['retention_receipt_revisions']===1&&$out['uninterpreted_event_revisions']===1&&$out['invalid_event_clock_revisions']===0);
    studyMetadataCheck('state and row limits are reported with a bounded-page denominator',$out['intake_enabled']===true&&$out['profiles_enabled']===true&&$out['metadata_rows_returned']===27&&$out['profile_page_cap_reached']===false&&$out['event_metadata_cap_reached']===false&&$out['population_coverage']==='bounded_recent_page_not_global_population');
    studyMetadataCheck('successful reader is read-only and closes its own transaction',!$p->inTransaction()&&studyMetadataContents($p)===$before&&studyMetadataMutationCounters($p)===$changes&&$out['database_writes']===0&&$out['native_message_or_event_rows_read']===0);
    $json=json_encode($out,JSON_THROW_ON_ERROR);$flat=count(array_filter($out,'is_array'))===0;
    studyMetadataCheck('protocol is flat bounded aggregate output with no individual identifiers or source strings',$flat&&strlen($json)<65536&&$out['individual_values_returned']===0&&!preg_match('/fixture-|700001|700002|customer_key|conversation_id|payload_json|profile_json/',$json));

    // JSON validity is separate from strict scalar typing and label validity.
    $p=studyMetadataDb();studyMetadataScope($p,10,800001);
    studyMetadataProfile($p,1,10,800001,$after+10,['access_status'=>['allowed']]);
    studyMetadataProfile($p,2,10,800001,$after+20,['profile'=>['prefix_complete'=>'false']]);
    studyMetadataProfile($p,3,10,800001,$after+30,[],'max','anytour','"fixture-valid-scalar"');
    $out=(new PurchaseStudyMetadataAudit($p))->collect($after,$through);
    studyMetadataCheck('JSON arrays cannot masquerade as allowed access and string false is not a boolean',$out['allowed_profile_versions']===1&&$out['denied_or_unchecked_profile_versions']===2&&$out['incomplete_prefix_versions']===0&&$out['invalid_profile_json']===0);
    $p=studyMetadataDb();studyMetadataScope($p,10,800001);studyMetadataProfile($p,1,10,800001,$after+10);
    studyMetadataEvent($p,1,10,800001,$at,$observed,'sales_pipeline_outcome_observed',studyMetadataOutcome($at),['payload'=>['payload_json'=>'{bad nested json']]);
    studyMetadataEvent($p,2,10,800001,$at,$observed,'sales_pipeline_outcome_observed',studyMetadataOutcome($at,['version'=>'1']));
    studyMetadataEvent($p,3,10,800001,$at,$observed,'sales_pipeline_outcome_observed',studyMetadataOutcome($at),['source_created_at_unix'=>(string)$at]);
    studyMetadataEvent($p,4,10,800001,$at,$observed,'sales_pipeline_outcome_observed',studyMetadataOutcome($at,['actor_role'=>['manager'],'profile_id'=>[17]]));
    studyMetadataEvent($p,5,10,800001,$at,$observed,'sales_pipeline_outcome_observed',studyMetadataOutcome($at,['_recorded_clock'=>['unix'=>$at+1]]));
    $out=(new PurchaseStudyMetadataAudit($p))->collect($after,$through);
    studyMetadataCheck('malformed nested JSON and numeric-string versions remain uninterpreted safely',$out['event_metadata_revisions_examined']===5&&$out['uninterpreted_event_revisions']===2);
    studyMetadataCheck('non-integer or inconsistent clocks never become manager outcome evidence',$out['invalid_event_clock_revisions']===2&&$out['declared_manager_role_revisions']===0&&$out['declared_profile_reference_revisions']===0&&$out['purchase_label_readiness']===false);

    // Saturated profile/event pages are explicitly partial, with four scopes only.
    $p=studyMetadataDb();
    for($i=1;$i<=70;$i++){
        studyMetadataScope($p,1000+$i,900000+$i,'max','anytour',0,$i>=7&&$i<=10?'won':'open');
        studyMetadataProfile($p,$i,1000+$i,900000+$i,$after+$i);
    }
    for($i=1;$i<=205;$i++)studyMetadataEvent($p,$i,1007,900007,$at,$observed,'metrika_max_sale');
    $out=(new PurchaseStudyMetadataAudit($p))->collect($after,$through);
    studyMetadataCheck('recent profile page never exceeds 64 and selects only four earliest checked people',$out['profile_versions_examined']===64&&$out['profile_page_cap_reached']===true&&$out['distinct_allowed_people_in_page']===64&&$out['selected_scopes']===4&&$out['current_operational_terminal_scopes']===4);
    studyMetadataCheck('event page never exceeds 200 and total returned metadata stays at 268',$out['event_metadata_revisions_examined']===200&&$out['event_metadata_cap_reached']===true&&$out['metadata_rows_returned']===268&&$out['population_coverage']==='bounded_recent_page_not_global_population'&&$out['purchase_label_readiness']===false);

    // Empty state is explicit; outer transactions and failure paths are untouched.
    $p=studyMetadataDb();$p->exec('DELETE FROM modeled_conversion_stream_state');$p->exec('DELETE FROM modeled_conversion_profiles_state');
    $out=(new PurchaseStudyMetadataAudit($p))->collect($after,$through);
    studyMetadataCheck('empty profiles and uninitialized producer states do not fabricate a cohort',$out['profile_versions_examined']===0&&$out['selected_scopes']===0&&$out['intake_enabled']===false&&$out['profiles_enabled']===false&&$out['event_metadata_revisions_examined']===0);
    foreach([[$after,$after-1],[1,$through],[$after,time()+60]]as[$lower,$upper]){
        try{(new PurchaseStudyMetadataAudit($p))->collect($lower,$upper);$rejected=false;}catch(RuntimeException $e){$rejected=$e->getMessage()==='audit_window';}
        studyMetadataCheck('invalid or future window fails closed before opening a transaction',$rejected&&!$p->inTransaction());
    }
    $p->beginTransaction();
    try{(new PurchaseStudyMetadataAudit($p))->collect($after,$through);$rejected=false;}catch(RuntimeException $e){$rejected=$e->getMessage()==='audit_window';}
    studyMetadataCheck('caller transaction is rejected and remains owned by the caller',$rejected&&$p->inTransaction());$p->rollBack();
    try{(new PurchaseStudyMetadataAudit($p,hrtime(true)-6000000000))->collect($after,$through);$expired=false;}catch(RuntimeException $e){$expired=$e->getMessage()==='audit_deadline';}
    studyMetadataCheck('expired budget performs no read and leaves no transaction',$expired&&!$p->inTransaction());
    $p=studyMetadataDb();studyMetadataScope($p,10,800001);studyMetadataProfile($p,1,10,800001,$after+10);$p->exec('DROP TABLE customer_channels');
    try{(new PurchaseStudyMetadataAudit($p))->collect($after,$through);$rejected=false;}catch(PDOException $e){$rejected=true;}
    studyMetadataCheck('SQL failure rolls back the reader transaction without modifying native data',$rejected&&!$p->inTransaction()&&$p->query('SELECT text FROM messages WHERE id=1')->fetchColumn()==='fixture-native-message-never-read');
    $p=studyMetadataDb();studyMetadataScope($p,10,800001);studyMetadataProfile($p,1,10,800001,$after+10);$p->delayProfileQuery=true;
    try{(new PurchaseStudyMetadataAudit($p,hrtime(true)-4000000000))->collect($after,$through);$expired=false;}catch(RuntimeException $e){$expired=$e->getMessage()==='audit_deadline';}
    studyMetadataCheck('deadline crossing during query preparation rolls back and emits no partial protocol',$expired&&$p->delayed&&!$p->inTransaction());
    if($driver==='mysql'){
        $p=studyMetadataDb();studyMetadataScope($p,10,800001);studyMetadataProfile($p,1,10,800001,$after+10);$p->probeReadOnly=true;
        try{(new PurchaseStudyMetadataAudit($p))->collect($after,$through);$blocked=false;}catch(PDOException $e){$blocked=true;}
        studyMetadataCheck('native MySQL read-only transaction rejects an attempted insert and rolls back',$blocked&&$p->probeExecuted&&$p->probeRejected&&!$p->inTransaction()&&(int)$p->query('SELECT COUNT(*) FROM messages WHERE id=987654')->fetchColumn()===0);
    }
} catch(Throwable $e) {
    studyMetadataCheck('metadata fixture executes its required assertions',false);
    fwrite(STDERR,'study_metadata_fixture_failed: '.get_class($e).': '.$e->getMessage()."\n");
}
echo "\nTOTAL ".($passed+$failed)." | PASS {$passed} | FAIL {$failed}\n";
exit($failed?1:0);
