<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelQuarantinedIntake.php';

// A genuinely concurrent old client: it does not load the new PHP write guard.
if(($argv[1]??'')==='--quarantine-old-writer-child'){
    $mysql=($argv[2]??'')==='MYSQL';$out=$argv[3]??'';
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
    if($mysql){$dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');if(preg_match('/(?:\Amysql:|;)dbname=maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?(?:;|\z)/D',$dsn)!==1)throw new RuntimeException('unsafe_child_database');$pdo=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,$options);}
    else{if(!str_starts_with((string)realpath($argv[2]),realpath(sys_get_temp_dir()).'/hotel-collection-service-'))throw new RuntimeException('unsafe_child_file');$pdo=new PDO('sqlite:'.$argv[2],null,null,$options);$pdo->exec('PRAGMA busy_timeout=15000');$pdo->sqliteCreateFunction('hotel_intake_session',static fn():string=>'old-child-session',0);}
    file_put_contents($out.'.started','started');
    try{$pdo->exec("UPDATE hotel_work_queues SET accepted_batches=accepted_batches+1 WHERE queue_id='COLLECTION-SERVICE'");$r=['denied'=>false];}catch(PDOException$e){$r=['denied'=>true];}
    file_put_contents($out.'.done',json_encode($r));exit;
}
function hqiSchema(PDO$p):void
{
    foreach(['hotel_work_events','hotel_native_bindings','hotel_native_deliveries','hotel_quarantined_intakes','hotel_quarantined_read_coverage','hotel_quarantine_intake_permits','hotel_legacy_effect_quarantine','hotel_legacy_quarantine_gate']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        foreach(['071_hotel_work_events.sql','086_hotel_native_automation_adapter.sql','087_hotel_legacy_effect_quarantine.sql','088_hotel_quarantined_intake.sql']as$f)foreach(explode(';',(string)file_get_contents(dirname(__DIR__).'/migrations/'.$f))as$s)if(trim($s)!=='')$p->exec($s);
        return;
    }
    foreach(['071_hotel_work_events.sql','086_hotel_native_automation_adapter.sql','087_hotel_legacy_effect_quarantine.sql','088_hotel_quarantined_intake.sql']as$f){
        $s=(string)file_get_contents(dirname(__DIR__).'/migrations/'.$f);if(preg_match('/(?:CREATE|DROP) TRIGGER/',$s,$m,PREG_OFFSET_CAPTURE))$s=substr($s,0,$m[0][1]);
        $s=preg_replace('/ CHARACTER SET ascii COLLATE ascii_bin/','',$s);$s=str_replace([' ENGINE=InnoDB','INSERT IGNORE','LONGBLOB'],['','INSERT OR IGNORE','BLOB'],$s);
        $s=preg_replace('/UNIQUE KEY [a-zA-Z0-9_]+\s*\(/','UNIQUE(',$s);$s=preg_replace('/,\s*KEY [a-zA-Z0-9_]+\s*\([^)]*\)/','',$s);$s=preg_replace('/ DEFAULT CHARSET=[a-zA-Z0-9_]+/','',$s);$p->exec($s);
    }
    $p->sqliteCreateFunction('sha2',static fn(string$s,int$bits):string=>hash('sha256',$s),2);
    $fields=['queue_id','project_key','admin_id','oauth_connection_id','mode','batch_limit','start_country','initial_phase_counter','phase_counter','baseline_sha256','plan_sha256','stage_index','state','claim_sha256','current_run_id','accepted_batches','write_protocol','data_snapshot_sha256'];
    $equal=static fn(array$f):string=>implode(' AND ',array_map(static fn(string$k):string=>'NEW.'.$k.' IS OLD.'.$k,$f));
    $keep=array_diff($fields,['stage_index','state','phase_counter','accepted_batches','claim_sha256','current_run_id']);
    $permit="EXISTS(SELECT 1 FROM hotel_quarantine_intake_permits p JOIN hotel_work_receipts r ON r.queue_id=p.queue_id AND r.stage_id=p.stage_id AND r.receipt_sha256=p.receipt_sha256 WHERE p.queue_id=OLD.queue_id AND p.session_id=hotel_intake_session() AND p.stage_index=OLD.stage_index AND p.claim_sha256=OLD.claim_sha256 AND OLD.state='claimed' AND OLD.current_run_id IS NULL AND OLD.mode='continuous' AND NEW.stage_index=OLD.stage_index+1 AND NEW.state='ready' AND NEW.claim_sha256 IS NULL AND NEW.current_run_id IS NULL AND NEW.accepted_batches=p.target_accepted AND p.target_accepted=OLD.accepted_batches+1 AND NEW.phase_counter=p.target_phase AND p.target_phase=1-OLD.phase_counter)";
    $p->exec("CREATE TRIGGER hotel_quarantine_queue_update BEFORE UPDATE ON hotel_work_queues WHEN OLD.write_protocol='quarantine_v1' AND NOT((".$equal($fields).') OR (('.$equal($keep).') AND '.$permit.")) BEGIN SELECT RAISE(ABORT,'database_quarantine'); END");
    $p->exec("CREATE TRIGGER hotel_quarantine_queue_delete BEFORE DELETE ON hotel_work_queues WHEN OLD.write_protocol='quarantine_v1' BEGIN SELECT RAISE(ABORT,'database_quarantine'); END");
    $rp="EXISTS(SELECT 1 FROM hotel_quarantine_intake_permits p WHERE p.queue_id=NEW.queue_id AND p.session_id=hotel_intake_session() AND p.stage_id=NEW.stage_id AND p.stage_index=NEW.stage_index AND p.receipt_sha256=NEW.receipt_sha256 AND p.receipt_sha256=sha2(NEW.receipt_json,256))";
    $p->exec("CREATE TRIGGER hotel_quarantine_receipt_insert BEFORE INSERT ON hotel_work_receipts WHEN EXISTS(SELECT 1 FROM hotel_work_queues WHERE queue_id=NEW.queue_id AND write_protocol='quarantine_v1') AND NOT(".$rp.") BEGIN SELECT RAISE(ABORT,'database_quarantine'); END");
    foreach(['update'=>'OLD.queue_id,NEW.queue_id','delete'=>'OLD.queue_id']as$op=>$keys)$p->exec('CREATE TRIGGER hotel_quarantine_receipt_'.$op.' BEFORE '.strtoupper($op)." ON hotel_work_receipts WHEN EXISTS(SELECT 1 FROM hotel_work_queues WHERE queue_id IN(".$keys.") AND write_protocol='quarantine_v1') BEGIN SELECT RAISE(ABORT,'database_quarantine'); END");
}
function hqiFixture():array
{
    $f=hccFixture();$p=$f['p'];hccNewSchema($p);hotelJournalFixtureSchema($p);hotelCollectionCanonicalFixtureSchema($p);hqiSchema($p);
    $c=new HotelNativeRecoveryContext($p,901001,$f['conn'],$f['clock']);$intake=new HotelQuarantinedIntake($c);
    $p->exec("UPDATE hotel_work_queues SET write_protocol='legacy',start_country='TR',stage_index=41,state='ready',phase_counter=0,accepted_batches=19");
    $stage=$f['queue']->status(901001,$f['conn'],'COLLECTION-SERVICE')['stage_id'];$claim=str_repeat('d',64);
    $f['queue']->claim(901001,$f['conn'],['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$stage,'claim_key'=>$claim]);
    $record=['id'=>'old-fact','hotel_key'=>'hotel-a','text'=>'Old original','source_ids'=>['old-source']];
    $working=hccJson(['knowledge'=>['claims'=>(object)['old-fact'=>[['record'=>$record,'origins'=>['old-one']],['record'=>$record+['variant'=>'two'],'origins'=>['old-two']]]],
        'sources_including_unclaimed'=>(object)['old-source'=>[['record'=>['id'=>'old-source','hotel_key'=>'hotel-a'],'origins'=>['old-source-origin']]]],'source_read_updates'=>[]],
        'hotel_index'=>(object)['hotel-a'=>['claim_ids'=>['old-fact'],'source_ids'=>['old-source']],'hotel-b'=>['claim_ids'=>[],'source_ids'=>[]]],
        'working_acceptances'=>[['operation_id'=>'previous-accepted']],'country_import_ledger'=>['imports'=>[['operation_id'=>'previous-accepted']],'revision'=>99],
        'coordination'=>['active_claims'=>[],'legacy_reserved_scopes'=>[['hotel_key'=>'protected-hotel']]],'criteria_updates'=>[['historical'=>'keep']],
        'scoped_question_results'=>[],'unknown_history'=>(object)['empty'=>(object)[]]]);
    $op='ORIGINAL-TR-RESEARCH40';$producer='original-producer-run40';$base='runs/ORIGINAL-TR-RESEARCH40/';
    $evidence=hccJson(['quote'=>'Synthetic exact original evidence','limits'=>'Unknown fifth bed']);$eh=hash('sha256',$evidence);
    $source=['id'=>'new-source','hotel_key'=>'hotel-a','country'=>'TR','operation_id'=>$op,'url'=>'https://example.com/original','read_scope'=>'Saved original paragraph','saved_evidence_ref'=>$base.'evidence.json','saved_evidence_sha256'=>$eh,'quote'=>'Synthetic exact original evidence','unknown_field'=>['preserved'=>true]];
    $new=['id'=>'new-fact','hotel_key'=>'hotel-a','country'=>'TR','operation_id'=>$op,'source_ids'=>['new-source'],'property_fact_credit'=>true,'saved_evidence_ref'=>$base.'evidence.json','saved_evidence_sha256'=>$eh,'text'=>'Synthetic original saved fact','scope'=>['category'=>'Family'],'limitation'=>'Unknown fifth bed'];
    $counts=['hotel_count'=>2,'record_count'=>1,'source_count'=>1,'read_update_count'=>0];
    $delta=['operation_id'=>$op,'country'=>'TR','claims'=>[$new],'sources'=>[$source],'source_read_updates'=>[],
        'question_results'=>[['hotel_key'=>'hotel-a','status'=>'PARTIAL','unknowns'=>'Unknown fifth bed'],['hotel_key'=>'hotel-b','status'=>'HOLD','unknowns'=>'Failed original read']],
        'failed_reads'=>[['hotel_key'=>'hotel-b','outcome'=>'not_found']],'source_observations'=>[['hotel_key'=>'hotel-b','observation'=>'Original HOLD']],'initial_source_reads'=>[],'counts'=>$counts];
    $members=[$base.'delta.json'=>hccJson($delta),$base.'ledger.json'=>hccJson(['record_ids'=>['new-fact'],'source_ids'=>['new-source']]),$base.'source-read.json'=>hccJson(['original'=>'read']),
        $base.'release.json'=>hccJson(['operation_id'=>$op,'actual_run_id'=>$producer,'state'=>'RELEASED_AFTER_CONTENT_READBACK']),$base.'admission.json'=>hccJson(['original'=>'admission']),$base.'evidence.json'=>$evidence];
    $packet=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>hash('sha256',hccJson(['COLLECTION-SERVICE',$p->query('SELECT plan_sha256 FROM hotel_work_queues')->fetchColumn(),40])),
        'task_id'=>str_repeat('a',32),'country'=>'TR','operation_id'=>$op,'actual_run_id'=>$producer,'state'=>'COUNTRY_SAVED_CENTRAL_PENDING','file_pins'=>['WORKING'=>['sha256'=>hash('sha256',$working)]],
        'scope'=>[['hotel_key'=>'hotel-a'],['hotel_key'=>'hotel-b']],'source_evidence'=>[$base.'evidence.json'=>$eh],
        'admission_readback'=>['member'=>$base.'admission.json','sha256'=>hash('sha256',$members[$base.'admission.json'])],
        'raw_delta'=>$delta,'record_ids'=>['new-fact'],'new_source_ids'=>['new-source'],'counts'=>$counts,'extra_provenance'=>str_repeat('saved evidence ',2000)];
    foreach(['delta'=>'delta.json','ledger'=>'ledger.json','source_read'=>'source-read.json','release'=>'release.json']as$k=>$n){$packet[$k.'_ref']=$base.$n;$packet[$k.'_sha256']=hash('sha256',$members[$base.$n]);}
    $packetBytes=hccJson($packet);$packetSha=hash('sha256',$packetBytes);
    foreach([[39,str_repeat('c',64),hccJson(['outcome'=>'accepted_readback','previous'=>'keep'])],[40,$packet['stage_id'],hccJson(['outcome'=>'accepted_readback','run_id'=>$producer,'packet_sha256'=>$packetSha])]]as[$index,$id,$bytes])
        $p->prepare('INSERT INTO hotel_work_receipts(queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES(?,?,?,?,?,?)')->execute(['COLLECTION-SERVICE',$index,$id,hash('sha256',$bytes),$bytes,'2026-11-01 00:00:00']);
    $barrier=new HotelLegacyEffectQuarantine($c);$pre=$barrier->preflight('COLLECTION-SERVICE');
    $barrier->apply('COLLECTION-SERVICE',$pre['expected_queue_sha256'],'quarantine-original','00000000-0000-0000-0000-000000000041',$working,hash('sha256',$working),$packetSha,hash('sha256','fixture approved provenance'));
    return array_merge($f,compact('c','intake','stage','claim','working','packet','packetBytes','packetSha','members','producer'));
}
$f=hqiFixture();$p=$f['p'];$intake=$f['intake'];$stage=$f['stage'];$control=str_repeat('7',64);
$before=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);$history=$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC);
$args=['COLLECTION-SERVICE',$stage,$control,'stage-original',str_repeat('b',32),$f['packetBytes'],$f['members'],['protected-hotel']];
$badMembers=$f['members'];$name=array_key_first($badMembers);$badMembers[$name].='changed';
hccReject(fn()=>HotelQuarantinedPacket::inspect($f['working'],$f['packetBytes'],$badMembers,$before[0],['protected-hotel']),'quarantined_modified_evidence_member');
hccReject(fn()=>HotelQuarantinedPacket::inspect($f['working'],$f['packetBytes'],$f['members'],$before[0],['hotel-a']),'quarantined_protected_scope_denied');
$staged=$intake->stage(...$args);
hccCheck($staged===$intake->stage(...$args),'quarantined_stage_exact_replay');
hccCheck($before===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC)&&$history===$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC),'quarantined_stage_preserves_claim_and_research_receipts');
$bad=$args;$bad[5]=$f['packetBytes'].' ';hccReject(fn()=>$intake->stage(...$bad),'quarantined_changed_packet_not_replayed');
$ra=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$stage,'generation'=>1,'review_capability'=>$staged['review_capability'],'instance_id'=>'fresh-review-instance'];
$api=new HotelWorkMcpAdapter($p,$f['queue'],$f['clock']);hccReject(fn()=>$api->call('hotel_work_review_context',$ra,901001,$f['conn']),'quarantined_no_review_before_dispatch');
$events=[];$webhook=new ManagerPreparationWebhook(static function(string$u,array$h,string$b)use(&$events):array{$v=json_decode($b,true);if(($v['type']??null)==='verification')return['status'=>200,'body'=>json_encode(['challenge'=>$v['challenge']])];$events[]=$v;return['status'=>202,'body'=>''];},static fn(string$h):array=>['93.184.216.34'],$f['clock']);
[$eventService,$sub,$proof]=hnaSubscribe($f,$webhook,'reviewer',str_repeat('b',32));
$native=new HotelNativeAutomationAdapter($p,901001,$f['conn'],$webhook,$f['clock']);$native->bind('COLLECTION-SERVICE','reviewer',str_repeat('b',32),$sub['id'],$proof);
$pending=$f['queue']->pendingEvent(901001,$f['conn'],'COLLECTION-SERVICE');hccCheck($pending['event_id']===$staged['event_id'],'quarantined_same_claim_fresh_cron_outbox');
$f['queue']->markEventDelivered(901001,$f['conn'],'COLLECTION-SERVICE',$pending['event_id']);
$coordinator=new HotelCollectionCoordinator($p,901001,$f['conn'],$native,$f['clock']);$route=['operation_id'=>$staged['operation_id'],'task_id'=>str_repeat('b',32),'request_id'=>'original-review-dispatch','worker_capability'=>$staged['review_capability']];
$coordinator->dispatch('COLLECTION-SERVICE',$stage,1,$control,$pending['event_id'],$route);
hccCheck(count($events)===1&&$events[0]['name']==='hotel_work.review_ready'&&$events[0]['data']['role']==='reviewer','quarantined_only_CENTRAL_event_no_producer');
$view=$api->call('hotel_work_review_context',$ra,901001,$f['conn']);$duplicate=$ra;$duplicate['instance_id']='another-native-instance';hccReject(fn()=>$api->call('hotel_work_review_context',$duplicate,901001,$f['conn']),'quarantined_duplicate_instance_denied');
$wrong=$ra;$wrong['review_capability']=$f['control'];hccReject(fn()=>$api->call('hotel_work_review_context',$wrong,901001,$f['conn']),'quarantined_control_not_review_capability');
hccReject(fn()=>$api->call('hotel_work_review_context',$ra,901002,str_repeat('b',32)),'quarantined_wrong_owner_cannot_review');
$verdict=['candidate_sha256'=>$f['packetSha'],'decision'=>'accept','criteria'=>null,'review_run'=>'independent-review-run41'];
hccReject(fn()=>$api->call('hotel_work_review_submit',$ra+['verdict'=>$verdict],901001,$f['conn']),'quarantined_unread_packet_cannot_accept','quarantined_intake_required_review_bytes_unread');
hccReject(fn()=>$api->call('hotel_work_quarantine_read',$ra+['kind'=>'packet','member'=>'','pointer'=>'','offset'=>1],901001,$f['conn']),'quarantined_skip_byte_cursor_denied');
foreach($view['required_reads']as$read){$offset=0;do{$readback=$api->call('hotel_work_quarantine_read',$ra+$read+['offset'=>$offset],901001,$f['conn']);$offset=$readback['next_offset'];}while($offset!==null);}
$v=$api->call('hotel_work_review_submit',$ra+['verdict'=>$verdict],901001,$f['conn']);
hccCheck($v===$api->call('hotel_work_review_submit',$ra+['verdict'=>$verdict],901001,$f['conn']),'quarantined_immutable_verdict_replay');
$fa=['COLLECTION-SERVICE',$stage,$control,'accept-original',hash('sha256',$f['working']),$f['packetSha'],$v['verdict_sha256']];
$fault=new HotelQuarantinedIntake($f['c'],static function(string$at):void{if($at==='quarantine_data_before_queue')throw new RuntimeException('intake_fault');});
hccReject(fn()=>$fault->finalize(...$fa),'quarantined_accept_rollback','intake_fault');
hccCheck($before===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC)&&$history===$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC)&&$p->query('SELECT acceptance_bytes FROM hotel_quarantined_intakes')->fetchColumn()===null,'quarantined_data_receipt_queue_rollback_together');
$bad=$fa;$bad[5]=str_repeat('f',64);hccReject(fn()=>$intake->finalize(...$bad),'quarantined_accept_wrong_packet_CAS');
$lateFault=new HotelQuarantinedIntake($f['c'],static function(string$at):void{if($at==='quarantine_permit_before_queue')throw new RuntimeException('late_intake_fault');});
hccReject(fn()=>$lateFault->finalize(...$fa),'quarantined_accept_late_rollback','late_intake_fault');
hccCheck((int)$p->query('SELECT COUNT(*) FROM hotel_quarantine_intake_permits')->fetchColumn()===0&&$history===$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC),'quarantined_permit_and_receipt_rollback_before_queue');

$raceDir=sys_get_temp_dir().'/quarantined-old-writer-'.bin2hex(random_bytes(6));mkdir($raceDir,0700);$out=$raceDir.'/result';$process=null;
$race=new HotelQuarantinedIntake($f['c'],static function(string$at)use($p,$out,&$process,$raceDir):void{
    if($at!=='quarantine_permit_before_queue')return;
    $db=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'MYSQL':$p->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'];
    $process=proc_open([PHP_BINARY,__FILE__,'--quarantine-old-writer-child',$db,$out],[0=>['pipe','r'],1=>['file',$raceDir.'/stdout','w'],2=>['file',$raceDir.'/stderr','w']],$pipes);if(!is_resource($process))throw new RuntimeException('race_spawn');fclose($pipes[0]);
    $until=microtime(true)+10;while(!is_file($out.'.started')&&microtime(true)<$until)usleep(1000);if(!is_file($out.'.started'))throw new RuntimeException('race_start');usleep(100000);hccCheck(!is_file($out.'.done'),'quarantined_old_writer_waits_for_same_queue_transaction');
});
try{
    $accepted=$race->finalize(...$fa);$until=microtime(true)+15;while(!is_file($out.'.done')&&microtime(true)<$until)usleep(1000);
    hccCheck(is_file($out.'.done')&&json_decode(file_get_contents($out.'.done'),true)['denied']===true,'quarantined_concurrent_old_SQL_denied_after_commit');
}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}foreach(glob($raceDir.'/*')?:[]as$file)unlink($file);rmdir($raceDir);}
hccCheck($accepted['business_accepted']&&$accepted['accepted_batches']===20&&!$accepted['next_dispatch_allowed']&&!$accepted['native_run_verified'],'quarantined_original_intake_accepted_exactly_once');
hccCheck($intake->finalize(...$fa)===$accepted,'quarantined_accept_lost_ACK_no_double_credit');
$q=$p->query('SELECT * FROM hotel_work_queues')->fetch(PDO::FETCH_ASSOC);hccCheck((int)$q['stage_index']===42&&$q['write_protocol']==='quarantine_v1'&&(int)$q['accepted_batches']===20,'quarantined_same_queue_next_stage_stays_fenced');
$status=$f['queue']->status(901001,$f['conn'],'COLLECTION-SERVICE');hccCheck($status['delivery_state']==='quarantined'&&$status['expected_pending_event_id']===null&&!$status['legacy_claim_allowed']&&!$status['automatic_next_dispatch'],'quarantined_status_does_not_advertise_legacy_dispatch');
hccCheck((int)$p->query('SELECT COUNT(*) FROM hotel_quarantine_intake_permits')->fetchColumn()===0,'quarantined_no_committed_session_permit');
$saved=$p->query('SELECT accepted_working_bytes FROM hotel_quarantined_intakes')->fetchColumn();$old=json_decode($f['working'],true);$new=json_decode($saved,true);
hccCheck($new['knowledge']['claims']['old-fact']===$old['knowledge']['claims']['old-fact']&&$new['criteria_updates']===$old['criteria_updates']&&$new['coordination']===$old['coordination'],'quarantined_old_variants_criteria_reserves_unchanged');
hccCheck(json_decode($saved)->unknown_history->empty instanceof stdClass,'quarantined_preserves_empty_objects_not_arrays');
hccCheck($new['knowledge']['claims']['new-fact'][0]['record']===$f['packet']['raw_delta']['claims'][0]&&$new['knowledge']['sources_including_unclaimed']['new-source'][0]['record']===$f['packet']['raw_delta']['sources'][0],'quarantined_raw_new_records_lossless');
hccCheck(str_ends_with($new['knowledge']['claims']['new-fact'][0]['origins'][0],'#/raw_delta/claims/0')&&str_ends_with($new['knowledge']['sources_including_unclaimed']['new-source'][0]['origins'][0],'#/raw_delta/sources/0'),'quarantined_origin_pointers_resolve_original_arrays');
hccCheck(count($new['working_acceptances'])===2&&count($new['country_import_ledger']['imports'])===2&&count($new['scoped_question_results'])===2,'quarantined_one_import_receipt_and_original_HOLD_partial');
$post=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);$api->call('hotel_work_review_submit',$ra+['verdict'=>$verdict],901001,$f['conn']);hccCheck($post===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC),'quarantined_postaccept_verdict_reconciliation_read_only');
hccReject(fn()=>$p->exec('UPDATE hotel_work_queues SET accepted_batches=accepted_batches+1'),'quarantined_old_raw_SQL_still_blocked');
hccReject(fn()=>$p->exec("UPDATE hotel_work_queues SET write_protocol='legacy'"),'quarantined_protocol_never_downgraded');
hccCheck($f['queue']->pendingEvent(901001,$f['conn'],'COLLECTION-SERVICE')===null,'quarantined_no_automatic_next_research');
hccCheck($history===$p->query('SELECT * FROM hotel_work_receipts WHERE stage_index<41 ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC),'quarantined_prior_accepted_receipts_unchanged_after_acceptance');
hccReject(fn()=>$coordinator->dispatch('COLLECTION-SERVICE',$stage,1,$control,$pending['event_id'],$route),'quarantined_completed_intake_cannot_redispatch');
hccCheck(count($events)===1&&$p->query('SELECT original_run_id FROM hotel_legacy_effect_quarantine')->fetchColumn()==='00000000-0000-0000-0000-000000000041','quarantined_original_handler_UNKNOWN_retained');
echo "HOTEL QUARANTINED INTAKE CHECKS PASS\n";
