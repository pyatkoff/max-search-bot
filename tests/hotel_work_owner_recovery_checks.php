<?php
declare(strict_types=1);

function hotelOwnerRecoveryFixture(): array
{
    [$pdo,$q,$clock]=hotelRecoveryFixture();
    $pending=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
    $q->markEventDelivered(1,str_repeat('a',32),'PILOT',$pending['event_id']);$clock->at+=180;
    $attention=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
    $q->markEventDelivered(1,str_repeat('a',32),'PILOT',$attention['event_id']);
    $s=hotelStatus($q);
    $args=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],
        'expected_pending_event_id'=>$pending['event_id'],'decision_sha256'=>str_repeat('d',64),'host_readback_sha256'=>str_repeat('e',64)];
    return [$pdo,$q,$clock,$args,$attention];
}

[$pdo,$q,$clock,$args,$attention]=hotelOwnerRecoveryFixture();
$before=$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
$resume=$pdo->query('SELECT * FROM hotel_work_connection_handoffs')->fetchAll(PDO::FETCH_ASSOC);
$automatic=$pdo->query('SELECT * FROM hotel_work_delivery_recoveries')->fetchAll(PDO::FETCH_ASSOC);
hotelAssert($q->pendingEvent(1,str_repeat('a',32),'PILOT')===null,'exhausted_delivery_stays_quiet_without_owner_operation');
hotelReject(fn()=>$q->recover_delivery(2,str_repeat('a',32),$args),DomainException::class,'owner_recovery_foreign_admin_denied');
hotelReject(fn()=>$q->recover_delivery(1,str_repeat('f',32),$args),DomainException::class,'owner_recovery_foreign_connection_denied');
foreach (['stage_id'=>'f','baseline_sha256'=>'f'] as $key=>$value) {
    $bad=$args;$bad[$key]=str_repeat($value,64);
    hotelReject(fn()=>$q->recover_delivery(1,str_repeat('a',32),$bad),HotelWorkQueueConflict::class,'owner_recovery_stale_pin_denied');
}
$bad=$args;$bad['expected_pending_event_id']='hotel_evt_'.str_repeat('f',64);
hotelReject(fn()=>$q->recover_delivery(1,str_repeat('a',32),$bad),HotelWorkQueueConflict::class,'owner_recovery_stale_event_denied','stale_pending_event');
$r=$q->recover_delivery(1,str_repeat('a',32),$args);
hotelAssert($r['recovery_status']==='owner_delivery_recovered_once'&&$r['dispatch_allowed']===false
    &&$r['state']==='ready'&&$r['current_run_id']===null,'owner_recovery_only_restores_delivery');
$row=$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
$changed=array_flip(['event_ack','delivered_at','claim_deadline','attention_at','attention_ack','updated_at']);
hotelAssert(array_diff_key($before,$changed)===array_diff_key($row,$changed),'owner_recovery_preserves_identity_stage_claim_business_progress');
hotelAssert($resume===$pdo->query('SELECT * FROM hotel_work_connection_handoffs')->fetchAll(PDO::FETCH_ASSOC)
    &&$automatic===$pdo->query('SELECT * FROM hotel_work_delivery_recoveries')->fetchAll(PDO::FETCH_ASSOC),'owner_recovery_preserves_both_original_audits');
$audit=$pdo->query('SELECT * FROM hotel_work_owner_delivery_recoveries')->fetch(PDO::FETCH_ASSOC);
hotelAssert($audit['pending_event_id']===$before['event_ack']&&$audit['attention_event_id']===$before['attention_ack']
    &&$audit['decision_sha256']===$args['decision_sha256']&&$audit['host_readback_sha256']===$args['host_readback_sha256'], 'owner_recovery_keeps_decision_host_attestation_and_old_ack');
$fresh=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
hotelAssert($fresh['name']==='hotel_work.pending'&&$fresh['event_id']!==$args['expected_pending_event_id']
    &&$fresh['event_id']===$r['expected_pending_event_id'],'owner_recovery_produces_new_pending_identity');
hotelReject(fn()=>$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$args['expected_pending_event_id']),HotelWorkQueueConflict::class,'owner_recovery_rejects_old_pending_ack','stale_event');
hotelReject(fn()=>$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$attention['event_id']),HotelWorkQueueConflict::class,'owner_recovery_rejects_old_attention_ack','stale_event');
$claim=['queue_id'=>'PILOT','stage_id'=>$args['stage_id'],'claim_key'=>str_repeat('b',64)];
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),$claim+['expected_pending_event_id'=>$args['expected_pending_event_id']]),HotelWorkQueueConflict::class,'owner_recovery_fences_old_claim','stale_pending_event');
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$fresh['event_id']);$clock->at+=180;
$a=$q->pendingEvent(1,str_repeat('a',32),'PILOT');$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$a['event_id']);$clock->at+=600;
hotelAssert($q->pendingEvent(1,str_repeat('a',32),'PILOT')===null,'owner_recovery_does_not_refill_automatic_budget');
$unchanged=hotelStatus($q);
hotelAssert($q->recover_delivery(1,str_repeat('a',32),$args)['recovery_status']==='already_recorded_do_not_replay'
    &&hotelStatus($q)===$unchanged,'owner_recovery_repeat_never_resets_new_ack');
$bad=$args;$bad['decision_sha256']=str_repeat('f',64);
hotelReject(fn()=>$q->recover_delivery(1,str_repeat('a',32),$bad),HotelWorkQueueConflict::class,'owner_recovery_second_decision_denied','owner_delivery_recovery_already_used');

[$pdo,$q,$clock,$args]=hotelOwnerRecoveryFixture();$q->recover_delivery(1,str_repeat('a',32),$args);
$fresh=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
$claim=['queue_id'=>'PILOT','stage_id'=>$args['stage_id'],'claim_key'=>str_repeat('b',64),'expected_pending_event_id'=>$fresh['event_id']];
hotelAssert($q->claim(1,str_repeat('a',32),$claim)['claim_status']==='claimed'
    &&$q->claim(1,str_repeat('a',32),$claim)['claim_status']==='already_claimed_do_not_replay','fresh_owner_recovery_admits_one_claim_only');
$q->started(1,str_repeat('a',32),array_diff_key($claim,['expected_pending_event_id'=>true])+['run_id'=>'OWNER_RECOVERED']);
$row=hotelStatus($q);$q->recover_delivery(1,str_repeat('a',32),$args);
hotelAssert(hotelStatus($q)===$row,'recorded_owner_recovery_repeat_does_not_reset_active_run');

foreach (['claimed','unknown','receipt','other_claim','unacknowledged'] as $guard) {
    [$pdo,$q,$clock,$args]=hotelOwnerRecoveryFixture();
    if ($guard==='claimed'||$guard==='unknown') {
        $a=hotelStart($q,'BLOCKED');
        if ($guard==='unknown') {$r=hotelReceipt('BLOCKED');$r['outcome']='unknown';$q->complete(1,str_repeat('a',32),$a+['receipt'=>$r]);}
    } elseif ($guard==='receipt') {
        $pdo->prepare('INSERT INTO hotel_work_receipts(queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES (?,?,?,?,?,?)')->execute(['PILOT',0,$args['stage_id'],str_repeat('a',64),'{}',gmdate('Y-m-d H:i:s',$clock->at)]);
    } elseif ($guard==='unacknowledged') $pdo->exec("UPDATE hotel_work_queues SET attention_ack=NULL WHERE queue_id='PILOT'");
    else $pdo->exec("INSERT INTO hotel_work_queues SELECT 'OTHER',project_key,admin_id,oauth_connection_id,mode,batch_limit,start_country,initial_phase_counter,phase_counter,baseline_sha256,plan_sha256,stage_index,'claimed','held',current_run_id,accepted_batches,event_ack,delivered_at,claim_deadline,attention_at,attention_ack,watchdog_due_at,watchdog_epoch,last_watch_ack,created_at,updated_at FROM hotel_work_queues WHERE queue_id='PILOT'");
    hotelReject(fn()=>$q->recover_delivery(1,str_repeat('a',32),$args),HotelWorkQueueConflict::class,'owner_recovery_blocks_'.$guard);
    hotelAssert((int)$pdo->query('SELECT COUNT(*) FROM hotel_work_owner_delivery_recoveries')->fetchColumn()===0,'rejected_owner_recovery_creates_no_audit');
}

foreach (['decision_sha256','automatic_recovery_sha256','pending_event_id','created_at'] as $field) {
    [$pdo,$q,$clock,$args]=hotelOwnerRecoveryFixture();$q->recover_delivery(1,str_repeat('a',32),$args);
    $value=$field==='created_at'?'2000-01-01 00:00:00':($field==='pending_event_id'?'hotel_evt_':'').str_repeat('f',64);
    $pdo->prepare('UPDATE hotel_work_owner_delivery_recoveries SET '.$field.'=?')->execute([$value]);
    hotelReject(fn()=>hotelStatus($q),RuntimeException::class,'owner_recovery_corrupt_audit_blocks_status_'.$field);
    hotelReject(fn()=>$q->pendingEvent(1,str_repeat('a',32),'PILOT'),RuntimeException::class,'owner_recovery_corrupt_audit_blocks_delivery_'.$field);
}
