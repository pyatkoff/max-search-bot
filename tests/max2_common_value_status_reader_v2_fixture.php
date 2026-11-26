<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/services/Max2CommonValueStatusReaderV2.php';

/** Only the caller's disposable test database; production code creates no tables. */
function max2CommonValueStatusSchema(PDO $p):void
{
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    foreach(['max2_common_lookup_intents_v2','max2_common_entry_frames_v2','max2_common_profile_work_v2',
        'max2_common_value_events_v2','max2_common_value_accounts_v2','max2_common_settings_v2','max2_common_ticks_v2']as$table)
        $p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec('CREATE TABLE IF NOT EXISTS customers(id BIGINT PRIMARY KEY,display_name VARCHAR(191));
        CREATE TABLE IF NOT EXISTS customer_channels(id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_user_id VARCHAR(40),external_chat_id VARCHAR(40));
        CREATE TABLE IF NOT EXISTS conversations(id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),is_test INT DEFAULT 0,external_chat_id VARCHAR(40));
        CREATE TABLE IF NOT EXISTS modeled_conversion_profile_versions(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),customer_key VARCHAR(40),conversation_id VARCHAR(40),journal_cursor BIGINT,observed_at_unix BIGINT,input_sha256 VARCHAR(64),profile_json TEXT)');
    if($mysql){
        foreach(['112_max2_common_value_v2.sql','113_max2_common_runtime_v2.sql']as$file)$p->exec(file_get_contents(dirname(__DIR__).'/migrations/'.$file));
        return;
    }
    $p->exec('CREATE TABLE max2_common_settings_v2(id INTEGER PRIMARY KEY,project_key TEXT,counter_id INTEGER,goal_id INTEGER,target TEXT,mode TEXT,producer_contract_sha256 TEXT,activation_sha256 TEXT,activation_source_sha TEXT,activation_request_sha256 TEXT,mode_request_sha256 TEXT,activated_at_unix INTEGER,initial_profile_max_id TEXT,profile_cursor TEXT,active_tick_key TEXT,lease_until_unix INTEGER,last_tick_at_unix INTEGER,last_tick_summary_json TEXT);
        CREATE TABLE max2_common_value_accounts_v2(id INTEGER PRIMARY KEY,version TEXT,counter_id INTEGER,goal_id INTEGER,target TEXT,project_key TEXT,platform TEXT,customer_key TEXT,conversation_id TEXT,inquiry_key TEXT,inquiry_journal_id TEXT,inquiry_native_message_id TEXT,inquiry_recorded_at_unix INTEGER,binding_key TEXT,binding_kind TEXT,tracker_record_id TEXT,immutable_tuple_sha256 TEXT,entry_id TEXT,publication_id TEXT,version_id TEXT,offer_id TEXT,client_id TEXT,identity_sha256 TEXT,initial_common_kopeks INTEGER,max2_model_version TEXT,binding_proof_sha256 TEXT,producer_contract_sha256 TEXT,proof_observed_at_unix INTEGER,point_recheck_sha256 TEXT,activation_sha256 TEXT,activated_at_unix INTEGER,created_at_unix INTEGER);
        CREATE TABLE max2_common_value_events_v2(id INTEGER PRIMARY KEY,account_id INTEGER,counter_id INTEGER,goal_id INTEGER,target TEXT,event_key TEXT,profile_id TEXT,profile_sha256 TEXT,model_version TEXT,total_kopeks INTEGER,delta_kopeks INTEGER,delivery_state TEXT,event_at_unix INTEGER,created_at_unix INTEGER,attempt_key TEXT,attempt_count INTEGER,csv_sha256 TEXT,attempt_started_at_unix INTEGER,upload_id TEXT,provider_status TEXT,source_quantity INTEGER,line_quantity INTEGER,accepted_at_unix INTEGER,processed_at_unix INTEGER,transport_receipt_sha256 TEXT,last_result_code TEXT);
        CREATE TABLE max2_common_ticks_v2(tick_key TEXT PRIMARY KEY,activation_sha256 TEXT,state TEXT,cursor_start TEXT,cursor_end TEXT,started_at_unix INTEGER,finished_at_unix INTEGER,summary_json TEXT);
        CREATE TABLE max2_common_profile_work_v2(activation_sha256 TEXT,profile_id TEXT,tick_key TEXT,outcome TEXT,reason TEXT,recorded_at_unix INTEGER,PRIMARY KEY(activation_sha256,profile_id))');
    $p->exec("INSERT INTO max2_common_settings_v2(id,project_key,counter_id,goal_id,target,mode,producer_contract_sha256,initial_profile_max_id,profile_cursor,lease_until_unix)
        VALUES(1,'anytour',98615635,687941321,'anytour_modeled_value_v2_max2','off','3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e','0','0',0)");
}
function max2CommonValueStatusInsert(PDO $p,string $table,array $row):void
{
    $p->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
}
function max2CommonValueStatusSeed(PDO $p,?int $now=null):array
{
    $now??=time();max2CommonValueStatusSchema($p);$epoch=hash('sha256','fixture-v2-activation');$tick=hash('sha256','fixture-v2-tick');
    $summary=array_fill_keys(['profiles_read','reserved','evaluated','held','unknown','lookup_list','lookup_recheck','goal_reads','send_attempts','provider_polls'],0);
    $summary['profiles_read']=3;$summary['held']=2;$summary['send_attempts']=1;
    $p->prepare('UPDATE max2_common_settings_v2 SET mode=?,activation_sha256=?,activation_source_sha=?,activation_request_sha256=?,mode_request_sha256=?,activated_at_unix=?,initial_profile_max_id=?,profile_cursor=?,last_tick_at_unix=?,last_tick_summary_json=? WHERE id=1')
        ->execute(['send',$epoch,str_repeat('a',40),str_repeat('b',64),str_repeat('c',64),$now-1000,'900000','920003',$now-10,json_encode($summary,JSON_THROW_ON_ERROR)]);
    max2CommonValueStatusInsert($p,'max2_common_ticks_v2',['tick_key'=>$tick,'activation_sha256'=>$epoch,'state'=>'completed','cursor_start'=>'900000','cursor_end'=>'920003','started_at_unix'=>$now-30,'finished_at_unix'=>$now-10,'summary_json'=>json_encode($summary,JSON_THROW_ON_ERROR)]);
    foreach([1=>'max',2=>'telegram']as$n=>$platform){
        $id=910000+$n;$peer=$platform==='max'?'-'.$id:(string)$id;
        $q=$p->prepare('SELECT id FROM customers WHERE id=?');$q->execute([$id]);if($q->fetchColumn()===false)max2CommonValueStatusInsert($p,'customers',['id'=>$id,'display_name'=>'PRIVATE_FIXTURE_CUSTOMER']);
        $q=$p->prepare('SELECT id FROM customer_channels WHERE id=?');$q->execute([$id]);if($q->fetchColumn()===false)
            max2CommonValueStatusInsert($p,'customer_channels',['id'=>$id,'customer_id'=>$id,'project_key'=>'anytour','channel'=>$platform,'external_user_id'=>(string)$id,'external_chat_id'=>$peer]);
        $q=$p->prepare('SELECT id FROM conversations WHERE id=?');$q->execute([$id]);if($q->fetchColumn()===false)
            max2CommonValueStatusInsert($p,'conversations',['id'=>$id,'customer_id'=>$id,'customer_channel_id'=>$id,'project_key'=>'anytour','channel'=>$platform,'is_test'=>0,'external_chat_id'=>$peer]);
        max2CommonValueStatusInsert($p,'max2_common_value_accounts_v2',['id'=>$n,'version'=>'max2_common_observed_inquiry_value_v2','counter_id'=>98615635,'goal_id'=>687941321,
            'target'=>'anytour_modeled_value_v2_max2','project_key'=>'anytour','platform'=>$platform,'customer_key'=>(string)$id,'conversation_id'=>(string)$id,
            'inquiry_key'=>hash('sha256','fixture-v2-inquiry-'.$n),'inquiry_journal_id'=>(string)(930000+$n),'inquiry_native_message_id'=>(string)(940000+$n),
            'inquiry_recorded_at_unix'=>$now-900,'binding_key'=>hash('sha256','fixture-v2-binding-'.$n),'binding_kind'=>$n===1?'UNIQUE_OBSERVED_MAX2':'PROVEN_NO_NEW_EARLY_RESERVE',
            'tracker_record_id'=>$n===1?'950001':null,'immutable_tuple_sha256'=>$n===1?str_repeat('d',64):null,'entry_id'=>null,'publication_id'=>null,'version_id'=>null,'offer_id'=>null,
            'client_id'=>'123456789.987654321','identity_sha256'=>str_repeat('e',64),'initial_common_kopeks'=>$n===1?2636:0,'max2_model_version'=>$n===1?'fixture_bootstrap':null,
            'binding_proof_sha256'=>str_repeat('f',64),'producer_contract_sha256'=>'3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e',
            'proof_observed_at_unix'=>$now-810,'point_recheck_sha256'=>null,'activation_sha256'=>$epoch,'activated_at_unix'=>$now-1000,'created_at_unix'=>$now-800]);
    }
    foreach([[1,1,'processed',10000,7364],[2,1,'reserved',20000,10000],[3,2,'failed',5000,5000],
        [4,2,'unknown',6000,1000],[5,2,'not_needed',6000,0],[6,2,'attempted',8000,2000],[7,2,'accepted',11000,3000]]as[$id,$account,$state,$total,$delta])
        max2CommonValueStatusEvent($p,$id,$account,$state,$total,$delta,$now);
    foreach([1=>['HOLD','INQUIRY_ANCHOR_UNPROVED'],2=>['UNKNOWN','LOOKUP_OUTCOME_UNKNOWN'],3=>['HOLD','IDENTITY_UNPROVED']]as$n=>[$outcome,$reason]){
        $id=920000+$n;$q=$p->prepare('SELECT id FROM modeled_conversion_profile_versions WHERE id=?');$q->execute([$id]);
        if($q->fetchColumn()===false)max2CommonValueStatusInsert($p,'modeled_conversion_profile_versions',['id'=>$id,'project_key'=>'anytour','platform'=>'max','customer_key'=>'910001','conversation_id'=>'910001',
            'journal_cursor'=>$id,'observed_at_unix'=>$now-50,'input_sha256'=>hash('sha256','profile-'.$id),'profile_json'=>json_encode(['fixture'=>'PRIVATE_PROFILE_MUST_NOT_BE_SELECTED'],JSON_THROW_ON_ERROR)]);
        max2CommonValueStatusInsert($p,'max2_common_profile_work_v2',['activation_sha256'=>$epoch,'profile_id'=>(string)$id,'tick_key'=>$tick,'outcome'=>$outcome,'reason'=>$reason,'recorded_at_unix'=>$now-20+$n]);
    }
    return['now'=>$now,'epoch'=>$epoch,'tick'=>$tick,'summary'=>$summary];
}
function max2CommonValueStatusEvent(PDO $p,int $id,int $account,string $state,int $total,int $delta,int $now):void
{
    $profile=(string)(960000+$id);$event=hash('sha256','max2/common/profile-delta/v2|'.hash('sha256','fixture-v2-inquiry-'.$account).'|'.$profile);
    $attempt=!in_array($state,['not_needed','reserved'],true);$accepted=in_array($state,['accepted','processed','failed'],true);
    $csv=$attempt?hash('sha256','fixture-csv-'.$id):null;$at=$now-400+$id;
    max2CommonValueStatusInsert($p,'max2_common_value_events_v2',['id'=>$id,'account_id'=>$account,'counter_id'=>98615635,'goal_id'=>687941321,'target'=>'anytour_modeled_value_v2_max2',
        'event_key'=>$event,'profile_id'=>$profile,'profile_sha256'=>hash('sha256','fixture-profile-'.$id),'model_version'=>'saved_v1_model_readonly_in_common_v2',
        'total_kopeks'=>$total,'delta_kopeks'=>$delta,'delivery_state'=>$state,'event_at_unix'=>$at,'created_at_unix'=>$at+1,
        'attempt_key'=>$attempt?hash('sha256','max2/common/attempt/v2|'.$event.'|'.$csv):null,'attempt_count'=>$attempt?1:0,'csv_sha256'=>$csv,'attempt_started_at_unix'=>$attempt?$at+2:null,
        'upload_id'=>$accepted?(string)(970000+$id):null,'provider_status'=>$state==='processed'?'PROCESSED':($state==='failed'?'LINKAGE_FAILURE':null),
        'source_quantity'=>$state==='processed'?1:null,'line_quantity'=>$state==='processed'?1:null,'accepted_at_unix'=>$accepted?$at+3:null,'processed_at_unix'=>$state==='processed'?$at+4:null,
        'transport_receipt_sha256'=>in_array($state,['accepted','processed','failed','unknown'],true)?hash('sha256','fixture-response-'.$id):null,'last_result_code'=>'FIXTURE']);
}
function max2CommonValueStatusSnapshot(PDO $p):array
{
    $out=[];foreach(['max2_common_settings_v2','max2_common_value_accounts_v2','max2_common_value_events_v2','max2_common_ticks_v2','max2_common_profile_work_v2','customers','customer_channels','conversations','modeled_conversion_profile_versions']as$t)
        $out[$t]=$p->query('SELECT * FROM '.$t.' ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);
    return$out;
}
function max2CommonValueStatusAssertions(PDO $p,callable $check):void
{
    $now=time();$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';max2CommonValueStatusSchema($p);
    $read=static fn():array=>(new Max2CommonValueStatusReaderV2($p,static function():void{},new ModeledConversionStatusBudget()))->read($now);
    $off=$read();$check('common V2 observed OFF is distinct from missing settings',($off['available']??false)&&($off['state']['mode']??null)==='off'
        &&($off['ledger']['reason']??null)==='not_activated'&&!isset($off['ledger']['late_reserved_kopeks']));
    $f=max2CommonValueStatusSeed($p,$now);$p->exec('DELETE FROM max2_common_value_events_v2');$empty=$read();
    $check('common V2 active empty ledger is a complete observed zero',($empty['available']??false)&&($empty['ledger']['available']??false)
        &&($empty['ledger']['coverage']['complete_current_scope']??false)&&($empty['ledger']['coverage']['selected_events']??null)===0&&($empty['ledger']['late_reserved_kopeks']??null)===0);
    $f=max2CommonValueStatusSeed($p,$now);
    $before=max2CommonValueStatusSnapshot($p);$r=$read();
    $check('common V2 reads the saved active goal without changing mode',($r['available']??false)&&($r['state']['mode']??null)==='send'&&($r['target']??null)==='anytour_modeled_value_v2_max2');
    $check('common V2 sums incremental reservations rather than repeated modeled totals',($r['ledger']['late_reserved_kopeks']??null)===28364&&($r['ledger']['selected_accounts']??null)===2);
    $check('common V2 counts initial reserve once per bound account',($r['ledger']['bound_initial_common_kopeks']??null)===2636&&($r['interpretation']['initial_reserve_is_only_bound_accounts_in_suffix']??false));
    $proof=$r['ledger']['cumulative_saved_proof']??[];
    $check('common V2 keeps intent API and exact processed observations separate',($proof['attempt_intents']??null)===['events'=>5,'delta_kopeks'=>18364]
        &&($proof['api_accepted']??null)===['events'=>3,'delta_kopeks'=>15364]&&($proof['processed']??null)===['events'=>1,'delta_kopeks'=>7364]);
    $check('common V2 failed after API and unknown reservations remain present',($r['ledger']['current_states']['failed']??null)===['events'=>1,'delta_kopeks'=>5000]
        &&($r['ledger']['current_states']['unknown']??null)===['events'=>1,'delta_kopeks'=>1000]);
    $check('common V2 complete coverage is explicitly bounded',($r['ledger']['coverage']['complete_current_scope']??false)&&($r['ledger']['coverage']['selected_events']??null)===7);
    $check('common V2 exposes saved HOLD reasons without reprocessing profiles',($r['profile_outcomes']['hold_reason_counts']??null)===['IDENTITY_UNPROVED'=>1,'LOOKUP_OUTCOME_UNKNOWN'=>1,'INQUIRY_ANCHOR_UNPROVED'=>1]
        &&($r['profile_outcomes']['latest']['reason']??null)==='IDENTITY_UNPROVED');
    $check('common V2 last tick action counts are not monetary success',($r['last_tick']['counts']??null)===$f['summary']&&($r['last_tick']['basis']??null)==='saved_completed_tick_action_counts_not_money');
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check('common V2 private projection omits native IDs bodies and original receipts',count($r['examples']??[])===3
        &&!str_contains($json,'PRIVATE_')&&!str_contains($json,'123456789.987654321')&&!str_contains($json,'970001')&&!str_contains($json,'910001')
        &&!str_contains($json,hash('sha256','fixture-v2-inquiry-1'))&&strlen($json)<5200);
    $processedEvent=hash('sha256','max2/common/profile-delta/v2|'.hash('sha256','fixture-v2-inquiry-1').'|960001');
    $check('common V2 retains an exact anonymous processed example ahead of newer waiting rows',($r['examples'][0]['reference']??null)===hash('sha256','max2/private/status/v2|'.$processedEvent)
        &&($r['examples'][0]['state']??null)==='processed'&&($r['examples'][0]['delta_kopeks']??null)===7364
        &&($r['examples'][0]['provider_status']??null)==='PROCESSED'&&is_string($r['examples'][0]['processed_at']??null));
    $check('common V2 observation leaves every fixture row unchanged',max2CommonValueStatusSnapshot($p)===$before&&!$p->inTransaction()
        &&($r['provider_requests']??null)===0&&($r['conversion_uploads']??null)===0&&($r['writes']??null)===0);

    $p->prepare('UPDATE max2_common_value_accounts_v2 SET activation_sha256=? WHERE id=1')->execute([str_repeat('0',64)]);
    $other=$read();$check('common V2 excludes another activation epoch',($other['ledger']['coverage']['selected_events']??null)===5&&($other['ledger']['bound_initial_common_kopeks']??null)===0);
    $p->prepare('UPDATE max2_common_value_accounts_v2 SET activation_sha256=? WHERE id=1')->execute([$f['epoch']]);
    $p->exec('UPDATE conversations SET is_test=1 WHERE id=910001');$other=$read();
    $check('common V2 excludes test dialogues from money and profile outcomes',($other['ledger']['coverage']['selected_events']??null)===5&&($other['profile_outcomes']['selected_profiles']??null)===0);
    $p->exec('UPDATE conversations SET is_test=0 WHERE id=910001');
    $p->exec("UPDATE customer_channels SET project_key='another' WHERE id=910001");$other=$read();
    $check('common V2 excludes broken current native project binding',($other['ledger']['coverage']['selected_events']??null)===5);
    $p->exec("UPDATE customer_channels SET project_key='anytour' WHERE id=910001");
    if(!$mysql){
        max2CommonValueStatusInsert($p,'conversations',['id'=>2,'customer_id'=>910001,'customer_channel_id'=>910001,'project_key'=>'anytour','channel'=>'max','is_test'=>0,'external_chat_id'=>'-910001']);
        $p->exec("UPDATE max2_common_value_accounts_v2 SET conversation_id='2' WHERE id=1");$other=$read();
        $check('common V2 preserves fixed owner demo exclusion',($other['ledger']['coverage']['selected_events']??null)===5);
        $p->exec("UPDATE max2_common_value_accounts_v2 SET conversation_id='910001' WHERE id=1");
        $p->exec('UPDATE max2_common_value_events_v2 SET delta_kopeks=-1 WHERE id=2');$bad=$read();
        $check('common V2 invalid money is unavailable rather than a negative total',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_saved_state');
        $p->exec('UPDATE max2_common_value_events_v2 SET delta_kopeks=10000 WHERE id=2');
        $p->exec('UPDATE max2_common_value_events_v2 SET source_quantity=0 WHERE id=1');$bad=$read();
        $check('common V2 does not trust a PROCESSED label without exact one row receipt',($bad['available']??true)===false&&($bad['reason']??null)==='saved_receipt_conflict');
        $p->exec('UPDATE max2_common_value_events_v2 SET source_quantity=1 WHERE id=1');
    }
    $p->exec("UPDATE max2_common_value_events_v2 SET transport_receipt_sha256='invalid' WHERE id=1");$bad=$read();
    $check('common V2 requires saved processing receipt evidence',($bad['available']??true)===false&&($bad['reason']??null)==='saved_receipt_conflict');
    $p->prepare('UPDATE max2_common_value_events_v2 SET transport_receipt_sha256=? WHERE id=1')->execute([hash('sha256','fixture-response-1')]);
    $p->prepare('UPDATE max2_common_value_events_v2 SET attempt_key=? WHERE id=6')->execute([str_repeat('0',64)]);$bad=$read();
    $check('common V2 binds the saved attempt to its exact event and CSV',($bad['available']??true)===false&&($bad['reason']??null)==='saved_receipt_conflict');
    $event=hash('sha256','max2/common/profile-delta/v2|'.hash('sha256','fixture-v2-inquiry-2').'|960006');
    $p->prepare('UPDATE max2_common_value_events_v2 SET attempt_key=? WHERE id=6')->execute([hash('sha256','max2/common/attempt/v2|'.$event.'|'.hash('sha256','fixture-csv-6'))]);
    $p->prepare('UPDATE max2_common_settings_v2 SET last_tick_summary_json=? WHERE id=1')->execute(['{"profiles_read":999}']);$bad=$read();
    $check('common V2 malformed tick is unknown without falsifying ledger counts',($bad['ledger']['coverage']['selected_events']??null)===7&&($bad['last_tick']['available']??true)===false);
    $p->prepare('UPDATE max2_common_settings_v2 SET last_tick_summary_json=? WHERE id=1')->execute([json_encode($f['summary'],JSON_THROW_ON_ERROR)]);
    $p->exec("UPDATE max2_common_settings_v2 SET producer_contract_sha256='invalid' WHERE id=1");$bad=$read();
    $check('common V2 invalid state provenance is not observed OFF',($bad['available']??true)===false&&!isset($bad['state']));
    $p->exec("UPDATE max2_common_settings_v2 SET producer_contract_sha256='3a563c1b7921915fd8b17c08f585cde88127904170a086c291a5677d49cf365e' WHERE id=1");
    $p->exec('UPDATE max2_common_settings_v2 SET activated_at_unix=1795262676 WHERE id=1');$bad=$read();
    $check('common V2 rejects the exact producer cutover just like canonical runtime state',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_saved_state');
    $p->prepare('UPDATE max2_common_settings_v2 SET activated_at_unix=? WHERE id=1')->execute([$now-1000]);
    $p->exec('UPDATE max2_common_settings_v2 SET activation_request_sha256=NULL WHERE id=1');$bad=$read();
    $check('common V2 requires canonical activation source and request provenance',($bad['available']??true)===false&&($bad['reason']??null)==='invalid_saved_state');
    $p->prepare('UPDATE max2_common_settings_v2 SET activation_request_sha256=? WHERE id=1')->execute([str_repeat('b',64)]);
    $p->beginTransaction();try{$bad=$read();$check('common V2 preserves a caller transaction and refuses its snapshot',($bad['reason']??null)==='outer_transaction'&&$p->inTransaction());}finally{$p->rollBack();}
    $calls=0;$denied=false;
    try{(new Max2CommonValueStatusReaderV2($p,static function()use(&$calls,$p):void{if(++$calls===2){if($p->inTransaction())throw new LogicException('authorization_inside_snapshot');throw new DomainException('revoked');}},new ModeledConversionStatusBudget()))->read($now);}
    catch(DomainException $e){$denied=$e->getMessage()==='revoked';}
    $check('common V2 reauthorizes after closing snapshot and propagates revocation',$denied&&$calls===2&&!$p->inTransaction());
    $clockCalls=0;$budget=new ModeledConversionStatusBudget(0.0,static function()use(&$clockCalls):float{return++$clockCalls<12?0.0:6.0;});$limited=false;
    try{(new Max2CommonValueStatusReaderV2($p,static function():void{},$budget))->read($now);}catch(ModeledConversionStatusLimit $e){$limited=$e->reason==='time_limit';}
    $check('common V2 shared budget exhaustion is not swallowed',$limited&&!$p->inTransaction());
    $p->exec($mysql?'RENAME TABLE max2_common_settings_v2 TO max2_fixture_missing_settings':'ALTER TABLE max2_common_settings_v2 RENAME TO max2_fixture_missing_settings');
    try{$bad=$read();$check('common V2 absent storage is unavailable rather than zero',($bad['available']??true)===false&&($bad['reason']??null)==='storage_unavailable'&&!isset($bad['ledger']));}
    finally{$p->exec($mysql?'RENAME TABLE max2_fixture_missing_settings TO max2_common_settings_v2':'ALTER TABLE max2_fixture_missing_settings RENAME TO max2_common_settings_v2');}
    for($id=8;$id<=35;$id++)max2CommonValueStatusEvent($p,$id,1,'reserved',20000+$id,1,$now);
    $dense=$read();$check('common V2 sentinel marks dense suffix incomplete without inventing full totals',($dense['ledger']['coverage']['selected_events']??null)===Max2CommonValueStatusReaderV2::EVENT_LIMIT
        &&($dense['ledger']['coverage']['complete_current_scope']??true)===false&&count($dense['examples']??[])===3&&($dense['interpretation']['amounts_are_selected_suffix_not_daily_totals']??false));
    // Restore a small valid fixture for the real authenticated composed read.
    $p->exec('DELETE FROM max2_common_value_events_v2 WHERE id>=8');
    $before=max2CommonValueStatusSnapshot($p);$read();$check('common V2 repeated final observation remains read-only',max2CommonValueStatusSnapshot($p)===$before);
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $p=new PDO('sqlite::memory:');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$n=0;
    max2CommonValueStatusAssertions($p,static function(string $name,bool $ok)use(&$n):void{if(!$ok)throw new RuntimeException($name);$n++;echo 'PASS '.$name.PHP_EOL;});
    echo 'MAX2 SAVED STATUS PASSED '.$n.PHP_EOL;
}
