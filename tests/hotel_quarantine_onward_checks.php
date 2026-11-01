<?php
declare(strict_types=1);
// Continues the synthetic, independently accepted original intake above.
$service=new HotelCollectionCanonicalService($p,901001,$f['conn'],$f['clock']);
$acceptedRow=$p->query('SELECT * FROM hotel_quarantined_intakes')->fetch(PDO::FETCH_ASSOC);
$inventory=['schema_version'=>1,'entries'=>[['reserve_id'=>'retained-protected','country'=>'EG','hotel_keys'=>['protected-hotel'],'protected'=>true]]];
$onward=['request_id'=>'adopt-original-intake','acceptance_sha256'=>hash('sha256',$acceptedRow['acceptance_bytes']),'working_sha256'=>$acceptedRow['accepted_working_sha256'],
    'inventory'=>$inventory,'reserve_attestation'=>'Synthetic retained protected inventory','producer_task_id'=>str_repeat('a',32),'scope_id'=>'next-eg-scope','source_packet_id'=>'next-eg-packet','hotel_keys'=>['hotel-b']];
$aa=['COLLECTION-SERVICE',$stage,$control,$onward['request_id'],$onward['acceptance_sha256'],$onward['working_sha256'],$inventory,$onward['reserve_attestation']];
$q42=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);
$allReceipts=$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC);
$wrong=$aa;$wrong[4]=str_repeat('f',64);hccReject(fn()=>$service->adoptQuarantined(...$wrong),'onward_wrong_acceptance_pin');
$wrong=$aa;$wrong[2]=str_repeat('f',64);hccReject(fn()=>$service->adoptQuarantined(...$wrong),'onward_wrong_control');
$wrong=$aa;$wrong[6]=['schema_version'=>1,'entries'=>[]];hccReject(fn()=>$service->adoptQuarantined(...$wrong),'onward_cannot_drop_protected_inventory');
foreach(['adoption_data_before_queue','adoption_queue_before_event']as$point){
    $broken=new HotelCollectionCanonicalService($p,901001,$f['conn'],$f['clock'],static function(string$at)use($point):void{if($at===$point)throw new RuntimeException('onward_fault');});
    hccReject(fn()=>$broken->adoptQuarantined(...$aa),'onward_rolls_back_'.$point,'onward_fault');
    hccCheck($q42===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC)&&(int)$p->query('SELECT COUNT(*) FROM hotel_working_imports')->fetchColumn()===0&&(int)$p->query('SELECT COUNT(*) FROM hotel_quarantine_adoptions')->fetchColumn()===0&&(int)$p->query('SELECT COUNT(*) FROM hotel_queue_write_permits')->fetchColumn()===0,'onward_atomic_rollback_'.$point);
}
$onwardPlan=$plan+['continuation'=>$onward];$off=$onwardPlan;$off['replacement_worker_setup']['dispatch_allowed']=false;
hccCheck($runtime->tick([$off])===0&&$q42===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC),'onward_false_flag_cannot_adopt_or_dispatch');
// An old raw writer really waits on the adoption, then is denied after the
// marker has changed to journal_v1. This exercises the actual MySQL migration.
$raceDir=sys_get_temp_dir().'/quarantined-onward-writer-'.bin2hex(random_bytes(6));mkdir($raceDir,0700);$out=$raceDir.'/result';$process=null;
$adoptionRace=static function(string$at)use($p,$out,$raceDir,&$process):void{
    if($at!=='adoption_queue_before_event')return;
    $db=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'MYSQL':$p->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'];
    $process=proc_open([PHP_BINARY,__DIR__.'/hotel_quarantined_intake_checks.php','--quarantine-old-writer-child',$db,$out],[0=>['pipe','r'],1=>['file',$raceDir.'/stdout','w'],2=>['file',$raceDir.'/stderr','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('onward_race_spawn');fclose($pipes[0]);
    $until=microtime(true)+10;while(!is_file($out.'.started')&&microtime(true)<$until)usleep(1000);if(!is_file($out.'.started'))throw new RuntimeException('onward_race_start');
    usleep(100000);hccCheck(!is_file($out.'.done'),'old_SQL_waits_during_onward_transaction');
};
try{
    $adopting=new HotelQuarantinedIntakeRuntime($p,$f['clock'],$factory,$adoptionRace);
    hccCheck($adopting->tick([$onwardPlan])===1,'same_cron_adopts_and_prepares_next_stage');
    $until=microtime(true)+15;while(!is_file($out.'.done')&&microtime(true)<$until)usleep(1000);
    hccCheck(is_file($out.'.done')&&json_decode(file_get_contents($out.'.done'),true)['denied']===true,'old_SQL_denied_after_journal_protocol_adoption');
}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}foreach(glob($raceDir.'/*')?:[]as$file)unlink($file);rmdir($raceDir);}
$adoption=$service->adoptQuarantined(...$aa);
hccCheck($adoption===$service->adoptQuarantined(...$aa),'onward_lost_ACK_replays_without_credit');
$q=$p->query('SELECT * FROM hotel_work_queues')->fetch(PDO::FETCH_ASSOC);
hccCheck($q['queue_id']==='COLLECTION-SERVICE'&&(int)$q['stage_index']===42&&(int)$q['accepted_batches']===20&&$q['baseline_sha256']===$q42[0]['baseline_sha256']&&$q['write_protocol']==='journal_v1'&&(int)$q['persistent_fence']===1,'onward_preserves_queue_origin_count_and_sticky_fence');
$import=$p->query('SELECT * FROM hotel_working_imports')->fetch(PDO::FETCH_ASSOC);
hccCheck($import['body_bytes']===$acceptedRow['accepted_working_bytes']&&$import['data_sha256']===$onward['working_sha256'],'onward_exact_accepted_WORKING_import');
hccCheck($allReceipts===$p->query('SELECT * FROM hotel_work_receipts ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC),'onward_never_replays_accepted_research_or_intake');
hccCheck((int)$p->query('SELECT COUNT(*) FROM hotel_queue_write_permits')->fetchColumn()===0,'onward_no_persistent_SQL_permit');
foreach(["UPDATE hotel_work_queues SET accepted_batches=accepted_batches+1","UPDATE hotel_work_queues SET persistent_fence=0","UPDATE hotel_work_queues SET write_protocol='legacy'","DELETE FROM hotel_work_queues","UPDATE hotel_work_receipts SET receipt_json='{}'","DELETE FROM hotel_work_receipts"]as$sql)hccReject(fn()=>$p->exec($sql),'onward_old_write_denied_'.$sql);
hccCheck($runtime->tick([$onwardPlan])===0&&count($events)===1,'onward_waits_for_fresh_next_pending_no_early_TH');
$next=$f['queue']->pendingEvent(901001,$f['conn'],'COLLECTION-SERVICE');
hccCheck($next['event_id']===$adoption['event_id']&&(int)$p->query("SELECT COUNT(*) FROM hotel_collection_outbox WHERE stage_id='".$adoption['next_stage_id']."'")->fetchColumn()===1,'onward_reuses_one_existing_held_event');
[, $psub,$pproof]=hnaSubscribe($f,$webhook,'producer',str_repeat('a',32));$native->bind('COLLECTION-SERVICE','producer',str_repeat('a',32),$psub['id'],$pproof);
$f['queue']->markEventDelivered(901001,$f['conn'],'COLLECTION-SERVICE',$next['event_id']);
hccCheck($runtime->tick([$onwardPlan])===1&&$runtime->tick([$onwardPlan])===0&&count($events)===2&&$events[1]['name']==='hotel_work.research_ready','onward_one_existing_TH_dispatch_after_fresh_cron');
$intentId=hash('sha256',HotelNativeRecoveryContext::json([$onward['request_id'],'onward-intent']));
$journal=new HotelWorkOperationJournalPrototype($p,901001,$f['conn']);
$intent=$journal->intent('COLLECTION-SERVICE',$adoption['next_stage_id'],1,$control,$intentId,$adoption['event_id'],str_repeat('a',32));
$worker=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$adoption['next_stage_id'],'generation'=>1,'operation_id'=>$intent['operation_id'],'worker_capability'=>$intent['worker_capability'],'instance_id'=>'next-native-instance'];
$service->enterProducer($worker,'next-native-entry');
$assignment=$service->workerRead($worker);$packet=json_decode(hccPacket($assignment,'after-original-intake'),true);
foreach(['facts','sources']as$g)foreach($packet[$g]as&$row)$row['hotel_key']='hotel-b';unset($row);
$bad=$packet;$bad['facts'][0]['id']='new-fact';hccReject(fn()=>$service->workerSubmit($worker+['request_id'=>'replay-original-id','packet_json'=>hccJson($bad)]),'onward_cannot_recredit_original_TR40_fact');
$bad=$packet;$bad['facts'][0]['source_ids']=['old-source'];hccReject(fn()=>$service->workerSubmit($worker+['request_id'=>'guess-legacy-source','packet_json'=>hccJson($bad)]),'onward_unmapped_legacy_source_not_invented');
$candidate=$service->workerSubmit($worker+['request_id'=>'next-candidate','packet_json'=>hccJson($packet)]);
$review=$service->sealResearch('COLLECTION-SERVICE',$adoption['next_stage_id'],1,$control,$intent['operation_id'],'next-producer-run',str_repeat('b',32));
$reviewArgs=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$review['intake_stage_id'],'generation'=>1,'review_capability'=>$review['review_capability'],'instance_id'=>'next-independent-CENTRAL'];
$ctx=$service->reviewContext($reviewArgs);hccCheck($ctx['criteria_due']===true,'next_intake_keeps_criteria_cadence');
$rv=$service->submitReview($reviewArgs,['candidate_sha256'=>$candidate['raw_sha256'],'decision'=>'accept','criteria'=>['readback_sha256'=>hash('sha256','synthetic next criteria'),'case_count'=>1,'done_count'=>1,'partial_count'=>0],'review_run'=>'next-independent-review-run']);
$nextAccepted=$service->finalizeIntake('COLLECTION-SERVICE',$review['intake_stage_id'],1,$control,'next-finalize',$rv['verdict_sha256'],$assignment['base_revision'],$assignment['base_snapshot_sha256']);
hccCheck($nextAccepted['accepted_batches']===21&&(int)$p->query('SELECT stage_index FROM hotel_work_queues')->fetchColumn()===44,'onward_next_server_cycle_accepts_without_reset');
hccCheck($service->adoptQuarantined(...$aa)===$adoption&&$runtime->tick([$onwardPlan])===0,'old_recovery_plan_stays_inert_after_next_cycle');
hccCheck($allReceipts===$p->query('SELECT * FROM hotel_work_receipts WHERE stage_index<=41 ORDER BY stage_index')->fetchAll(PDO::FETCH_ASSOC),'all_original_receipts_unchanged_through_next_cycle');
hccReject(fn()=>$p->exec('UPDATE hotel_work_queues SET accepted_batches=accepted_batches+1'),'old_SQL_still_denied_after_next_acceptance');
hccCheck((int)$p->query('SELECT COUNT(*) FROM hotel_queue_write_permits')->fetchColumn()===0,'no_SQL_permits_survive_next_cycle');
// The actual legacy WORKING has an object of question groups, not a list.
$objectWorking=json_decode($f['working']);$objectWorking->scoped_question_results=(object)['historical-group'=>[(object)['unknown'=>'retained']]];
$objectBytes=HotelQuarantinedPacket::json($objectWorking);$objectPacket=json_decode($f['packetBytes'],true);$objectPacket['file_pins']['WORKING']['sha256']=hash('sha256',$objectBytes);
$merged=json_decode(HotelQuarantinedPacket::merge($objectBytes,hccJson($objectPacket),['operation_id'=>'synthetic-acceptance']));
hccCheck($merged->scoped_question_results instanceof stdClass&&$merged->scoped_question_results->{'historical-group'}[0]->unknown==='retained'&&count($merged->scoped_question_results->{$f['packet']['operation_id']})===2,'actual_WORKING_question_map_is_preserved');
echo "HOTEL QUARANTINE ONWARD CHECKS PASS\n";

