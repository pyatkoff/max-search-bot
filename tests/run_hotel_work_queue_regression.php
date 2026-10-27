<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkQueue.php';
require_once __DIR__.'/fixtures/hotel_work_queue.php';

if (in_array($argv[1]??'',['--claim-handoff-child','--claim-resume-child'],true)) {
    [$unused,$mode,$barrier,$out,$file,$action]=array_pad($argv,6,'');
    $pdo=new HotelWorkTestPdo($file==='MYSQL'?null:$file,false);
    $at=$mode==='--claim-resume-child'?1793100180:1793100000;
    $holdClaim=$action==='claim-winner';$holdResume=$action==='resume-winner';
    $action=$holdClaim?'claim':($holdResume?'resume':$action);
    $hold=static function() use($out): void {
        file_put_contents($out.'.locked','locked');$until=microtime(true)+10;
        while (!is_file($out.'.release')&&microtime(true)<$until) usleep(1000);
        if (!is_file($out.'.release')) throw new RuntimeException('race_release_timeout');
    };
    $clockHold=(object)['armed'=>$holdClaim];
    $queue=new HotelWorkQueue($pdo,static function() use($at,$clockHold,$hold): int {
        if ($clockHold->armed) {$clockHold->armed=false;$hold();}
        return $at;
    });
    $status=$queue->status(1,str_repeat('a',32),'RACE');
    if ($mode==='--claim-resume-child') file_put_contents($out.'.event',$status['expected_pending_event_id']);
    file_put_contents($out.'.ready',$status['stage_id']);
    $until=microtime(true)+10;
    while (!is_file($barrier)&&microtime(true)<$until) usleep(1000);
    if (!is_file($barrier)) exit(3);
    file_put_contents($out.'.attempting','attempting');
    try {
        if ($action==='claim') {
            $claim=['queue_id'=>'RACE','stage_id'=>$status['stage_id'],'claim_key'=>str_repeat('b',64)];
            if ($mode==='--claim-resume-child') $claim['expected_pending_event_id']=$status['expected_pending_event_id'];
            $r=$queue->claim(1,str_repeat('a',32),$claim);
            $result=$r['claim_status'];
        } elseif ($action==='handoff') {
            $r=$queue->handoff_connection(1,str_repeat('f',32),['queue_id'=>'RACE','stage_id'=>$status['stage_id'],
                'baseline_sha256'=>$status['baseline_sha256'],'old_connection_id'=>str_repeat('a',32)],
                static fn(string $old,int $admin,string $proofMode): bool=>$old===str_repeat('a',32)&&$admin===1&&$proofMode==='ready');
            $result=$r['handoff_status'];
        } elseif ($action==='resume') {
            $r=$queue->handoff_connection(1,str_repeat('a',32),['queue_id'=>'RACE','stage_id'=>$status['stage_id'],
                'baseline_sha256'=>$status['baseline_sha256'],'old_connection_id'=>str_repeat('a',32)],
                static function(string $old,int $admin,string $proofMode) use($holdResume,$hold): bool {
                    if ($holdResume) $hold();
                    return $old===str_repeat('a',32)&&$admin===1&&$proofMode==='ready';
                });
            $result=$r['handoff_status'];
        } elseif ($action==='ack') {
            $queue->markEventDelivered(1,str_repeat('a',32),'RACE',$status['expected_pending_event_id']);
            $result='acknowledged';
        } else exit(4);
    } catch (HotelWorkQueueConflict $e) {$result='conflict:'.$e->reason;}
    catch (DomainException $e) {$result='access_denied';}
    file_put_contents($out,$result);exit(0);
}

// Two actual PHP processes contend on the same SQLite/MySQL admission row.
if (($argv[1]??'')==='--claim-child') {
    [$unused,$mode,$barrier,$out,$file,$key]=array_pad($argv,6,'');
    $pdo=new HotelWorkTestPdo($file==='MYSQL'?null:$file,false);
    $queue=new HotelWorkQueue($pdo,static fn(): int=>1793100000);
    $until=microtime(true)+10;
    while (!is_file($barrier)&&microtime(true)<$until) usleep(1000);
    if (!is_file($barrier)) exit(3);
    $status=$queue->status(1,str_repeat('a',32),'RACE');
    try { $r=$queue->claim(1,str_repeat('a',32),['queue_id'=>'RACE','stage_id'=>$status['stage_id'],'claim_key'=>$key]);$result=$r['claim_status']; }
    catch (HotelWorkQueueConflict $e) { $result='conflict'; }
    file_put_contents($out,$result);exit(0);
}

$checks=0;
function hotelAssert(bool $ok,string $name): void { global $checks;++$checks;if (!$ok) throw new RuntimeException('hotel_assertion_failed:'.$name); }
function hotelReject(callable $work,string $class,string $name,?string $reason=null): void
{
    try { $work(); } catch (Throwable $e) { hotelAssert($e instanceof $class&&($reason===null||$e->getMessage()===$reason),$name);return; }
    hotelAssert(false,$name);
}
function hotelFixture(?string $file=null): array
{
    $pdo=new HotelWorkTestPdo($file);$clock=(object)['at'=>1793100000];$q=new HotelWorkQueue($pdo,static fn(): int=>$clock->at);
    $q->create(1,str_repeat('a',32),['queue_id'=>'PILOT','baseline_sha256'=>str_repeat('0',64)]);return [$pdo,$q,$clock];
}
function hotelStatus(HotelWorkQueue $q): array { return $q->status(1,str_repeat('a',32),'PILOT'); }
function hotelStart(HotelWorkQueue $q,string $run='RUN'): array
{
    $s=hotelStatus($q);$args=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('b',64)];
    $q->claim(1,str_repeat('a',32),$args+['expected_pending_event_id'=>$s['expected_pending_event_id']]);
    $q->started(1,str_repeat('a',32),$args+['run_id'=>$run]);return $args;
}
function hotelReceipt(string $run='RUN',string $packet='1',?array $criteria=null): array
{
    return ['run_id'=>$run,'outcome'=>'accepted_readback','packet_sha256'=>str_repeat($packet,64),'readback_sha256'=>str_repeat('2',64),
        'ledger_sha256'=>str_repeat('3',64),'hotel_count'=>8,'record_count'=>24,'source_count'=>0,'read_update_count'=>22,'criteria'=>$criteria];
}
function hotelCriteria(): array { return ['readback_sha256'=>str_repeat('4',64),'case_count'=>10,'done_count'=>7,'partial_count'=>3]; }
function hotelResumeFixture(): array
{
    [$pdo,$q,$clock]=hotelFixture();$pending=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
    $q->markEventDelivered(1,str_repeat('a',32),'PILOT',$pending['event_id']);$clock->at+=180;
    $attention=$q->pendingEvent(1,str_repeat('a',32),'PILOT');$s=hotelStatus($q);
    $args=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],'old_connection_id'=>str_repeat('a',32)];
    return [$pdo,$q,$clock,$args,$pending,$attention];
}

[$pdo,$q,$clock]=hotelFixture();
$s=hotelStatus($q);
hotelAssert($s['country']==='EG'&&$s['stage_kind']==='research'&&$s['accepted_batches']===0,'default_plan');
hotelAssert($s['receipt_semantics']==='authenticated_attestation_not_independent_file_proof'&&!isset($s['claim_sha256']),'explicit_semantics_no_secret');
$same=$q->create(1,str_repeat('a',32),['queue_id'=>'PILOT','baseline_sha256'=>str_repeat('0',64)]);
hotelAssert($same['created']===false,'idempotent_create');
hotelReject(fn()=>$q->create(1,str_repeat('a',32),['queue_id'=>'PILOT','baseline_sha256'=>str_repeat('9',64)]),HotelWorkQueueConflict::class,'plan_conflict','plan_conflict');
hotelReject(fn()=>$q->status(2,str_repeat('a',32),'PILOT'),DomainException::class,'foreign_actor');
hotelReject(fn()=>$q->status(1,str_repeat('f',32),'PILOT'),DomainException::class,'foreign_connection');
hotelReject(fn()=>$q->status(1,str_repeat('a',32),'MISSING'),DomainException::class,'missing_not_disclosed');
hotelReject(fn()=>$q->create(1,str_repeat('a',32),['queue_id'=>'OTHER','baseline_sha256'=>str_repeat('0',64)]),HotelWorkQueueConflict::class,'shared_lane','hotel_lane_busy');
hotelAssert(count($q->eventCandidates())===1,'bounded_inventory');
hotelReject(fn()=>$q->eventCandidates(21),InvalidArgumentException::class,'inventory_bound');
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('b',64),'hotel_facts'=>[]]),InvalidArgumentException::class,'field_allowlist');
hotelReject(fn()=>$q->create(1,str_repeat('a',32),['queue_id'=>'BAD','baseline_sha256'=>str_repeat('0',64),'phase_counter'=>true]),InvalidArgumentException::class,'bool_counter');
hotelReject(fn()=>$q->create(1,str_repeat('a',32),['queue_id'=>'BAD','baseline_sha256'=>str_repeat('0',64),'mode'=>'continuous','batch_limit'=>3]),InvalidArgumentException::class,'continuous_no_hidden_limit');
$pdo->beginTransaction();
hotelReject(fn()=>hotelStatus($q),LogicException::class,'foreign_transaction_preserved');
hotelAssert($pdo->inTransaction(),'foreign_transaction_not_rolled_back');$pdo->rollBack();

$event=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
hotelAssert($event['name']==='hotel_work.pending'&&$event['data']['dispatch_allowed']===true&&strlen($event['event_id'])<=80,'pending_event');
hotelAssert(array_keys($event['data'])===['queue_id','stage_id','stage_kind','country','dispatch_allowed'],'event_exact_metadata');
hotelAssert($event===$q->pendingEvent(1,str_repeat('a',32),'PILOT'),'stable_event');
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$event['event_id']);$deadline=hotelStatus($q)['claim_deadline'];
$clock->at+=60;$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$event['event_id']);
hotelAssert(hotelStatus($q)['claim_deadline']===$deadline,'duplicate_ack_does_not_postpone_deadline');
hotelAssert($q->pendingEvent(1,str_repeat('a',32),'PILOT')===null,'unchanged_tick_silent');
$clock->at+=120;$attention=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
hotelAssert($attention['name']==='hotel_work.attention'&&$attention['data']['dispatch_allowed']===false,'deadline_attention_no_dispatch');
$q2=new HotelWorkQueue($pdo,static fn(): int=>$clock->at);
hotelAssert($attention===$q2->pendingEvent(1,str_repeat('a',32),'PILOT'),'attention_durable_across_instances');
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$attention['event_id']);$clock->at+=10000;
hotelAssert($q->pendingEvent(1,str_repeat('a',32),'PILOT')===null&&hotelStatus($q)['state']==='ready','attention_once_not_task_retry');
$args=hotelStart($q);
$again=$q->claim(1,str_repeat('a',32),$args);
hotelAssert($again['claim_status']==='already_claimed_do_not_replay','claim_repeat_no_replay');
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),array_replace($args,['claim_key'=>str_repeat('c',64)])),HotelWorkQueueConflict::class,'second_claim_denied');
hotelReject(fn()=>$q->started(1,str_repeat('a',32),$args+['run_id'=>'OTHER']),HotelWorkQueueConflict::class,'run_replacement','run_conflict');
hotelReject(fn()=>$q->release(1,str_repeat('a',32),['queue_id'=>'PILOT','stage_id'=>$args['stage_id']]),HotelWorkQueueConflict::class,'claimed_not_released','not_known_idle');
hotelAssert($q->pendingEvent(1,str_repeat('a',32),'PILOT')===null,'claimed_no_event');
$receipt=hotelReceipt();$q->complete(1,str_repeat('a',32),$args+['receipt'=>$receipt]);
hotelAssert(hotelStatus($q)['stage_kind']==='intake'&&hotelStatus($q)['accepted_batches']===0,'research_not_accepted_batch');
hotelAssert($q->complete(1,str_repeat('a',32),$args+['receipt'=>$receipt])['completion_status']==='already_recorded','completion_once');
hotelAssert($q->complete(1,str_repeat('a',32),$args+['receipt'=>array_reverse($receipt,true)])['completion_status']==='already_recorded','canonical_receipt_order');
hotelReject(fn()=>$q->complete(1,str_repeat('a',32),$args+['receipt'=>array_replace($receipt,['readback_sha256'=>str_repeat('9',64)])]),HotelWorkQueueConflict::class,'completion_conflict','completion_conflict');
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),$args),HotelWorkQueueConflict::class,'stale_stage','stale_stage');
hotelReject(fn()=>$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$event['event_id']),HotelWorkQueueConflict::class,'stale_event','stale_event');
$intake=hotelStart($q,'INTAKE');
hotelReject(fn()=>$q->complete(1,str_repeat('a',32),$intake+['receipt'=>hotelReceipt('INTAKE','9')]),HotelWorkQueueConflict::class,'intake_packet_binding','source_packet_mismatch');
hotelReject(fn()=>$q->complete(1,str_repeat('a',32),$intake+['receipt'=>hotelReceipt('INTAKE','1',hotelCriteria())]),HotelWorkQueueConflict::class,'extra_criteria_rejected','criteria_mismatch');
$q->complete(1,str_repeat('a',32),$intake+['receipt'=>array_replace(hotelReceipt('INTAKE'),['readback_sha256'=>str_repeat('8',64),'ledger_sha256'=>str_repeat('9',64)])]);
hotelAssert(hotelStatus($q)['accepted_batches']===1&&hotelStatus($q)['country']==='TH'&&hotelStatus($q)['phase_counter']===1,'intake_lineage_separate_readback');
$a=hotelStart($q,'TH_RESEARCH');$q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt('TH_RESEARCH','5')]);
hotelAssert(hotelStatus($q)['criteria_due']===true,'criteria_due_after_second_country');
$a=hotelStart($q,'TH_INTAKE');
hotelReject(fn()=>$q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt('TH_INTAKE','5')]),HotelWorkQueueConflict::class,'missing_due_criteria','criteria_mismatch');
$q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt('TH_INTAKE','5',hotelCriteria())]);
hotelAssert(hotelStatus($q)['accepted_batches']===2&&hotelStatus($q)['phase_counter']===0,'criteria_counter_reset');
$a=hotelStart($q,'VN_RESEARCH');$q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt('VN_RESEARCH','6')]);
$a=hotelStart($q,'VN_INTAKE');$q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt('VN_INTAKE','6')]);
hotelAssert(hotelStatus($q)['state']==='complete'&&hotelStatus($q)['accepted_batches']===3&&hotelStatus($q)['phase_counter']===1,'finite_three_complete');
hotelAssert($q->pendingEvent(1,str_repeat('a',32),'PILOT')===null&&$q->eventCandidates()===[],'complete_no_seventh_dispatch');
$created=$q->create(1,str_repeat('a',32),['queue_id'=>'NEXT','mode'=>'continuous','start_country'=>'TR','phase_counter'=>1,'baseline_sha256'=>str_repeat('7',64)]);
hotelAssert($created['country']==='TR'&&$created['mode']==='continuous','completed_releases_lane_for_explicit_continuation');
hotelAssert(count(array_diff(array_unique($pdo->writes),['hotel_work_queues','hotel_work_receipts','hotel_work_lane']))===0,'no_business_table_writes');

[$pdo,$q,$clock]=hotelFixture();$args=['queue_id'=>'PILOT','stage_id'=>hotelStatus($q)['stage_id'],'claim_key'=>str_repeat('b',64)];$q->claim(1,str_repeat('a',32),$args);
$unknown=array_fill_keys(array_keys(hotelReceipt()),null);$unknown['outcome']='unknown';
$q->complete(1,str_repeat('a',32),$args+['receipt'=>$unknown]);$clock->at+=1000000;
$unknownAttention=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
hotelAssert(hotelStatus($q)['state']==='unknown'&&$unknownAttention['name']==='hotel_work.attention'&&$unknownAttention['data']['dispatch_allowed']===false,'unknown_attention_never_age_retry');
hotelAssert($q->complete(1,str_repeat('a',32),$args+['receipt'=>$unknown])['completion_status']==='already_recorded','unknown_receipt_idempotent');
hotelReject(fn()=>$q->create(2,str_repeat('f',32),['queue_id'=>'OTHER','baseline_sha256'=>str_repeat('0',64)]),HotelWorkQueueConflict::class,'unknown_global_lane_hold','hotel_lane_busy');
hotelReject(fn()=>$q->release(1,str_repeat('a',32),['queue_id'=>'PILOT','stage_id'=>$args['stage_id']]),HotelWorkQueueConflict::class,'unknown_not_released','not_known_idle');

[$pdo,$q,$clock]=hotelFixture();$args=hotelStart($q);
foreach ([['hotel_count'=>true],['record_count'=>0],['source_count'=>-1],['read_update_count'=>201],['packet_sha256'=>'bad'],['transcript'=>'private']] as $bad) {
    hotelReject(fn()=>$q->complete(1,str_repeat('a',32),$args+['receipt'=>array_replace(hotelReceipt(),$bad)]),InvalidArgumentException::class,'strict_receipt_bounds');
}
hotelAssert(hotelStatus($q)['state']==='claimed','invalid_receipt_no_transition');
[$pdo,$q,$clock]=hotelFixture();$q->release(1,str_repeat('a',32),['queue_id'=>'PILOT','stage_id'=>hotelStatus($q)['stage_id']]);
hotelAssert(hotelStatus($q)['state']==='released'&&$q->eventCandidates()===[],'explicit_known_idle_release');
$q->create(2,str_repeat('f',32),['queue_id'=>'OTHER','baseline_sha256'=>str_repeat('0',64)]);
hotelAssert($q->status(2,str_repeat('f',32),'OTHER')['state']==='ready','known_release_allows_new_owner');
$q->release(1,str_repeat('a',32),['queue_id'=>'PILOT','stage_id'=>hotelStatus($q)['stage_id']]);
hotelAssert($q->eventCandidates()[0]['queue_id']==='OTHER','release_retry_cannot_release_new_owner');

// Explicit continuous mode preserves the pre-existing criteria counter and rotates beyond three batches.
$pdo=new HotelWorkTestPdo();$clock=(object)['at'=>1793100000];$q=new HotelWorkQueue($pdo,static fn(): int=>$clock->at);
$q->create(1,str_repeat('a',32),['queue_id'=>'PILOT','mode'=>'continuous','start_country'=>'TR','phase_counter'=>1,'baseline_sha256'=>str_repeat('7',64)]);
$countries=['TR','EG','TH','VN','TR'];
foreach ($countries as $index=>$expected) {
    hotelAssert(hotelStatus($q)['country']===$expected&&hotelStatus($q)['stage_kind']==='research','continuous_country_rotation');
    $packet=(string)($index+5);$run='CONTINUOUS_'.$index.'_RESEARCH';$a=hotelStart($q,$run);
    $q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt($run,$packet)]);
    $due=hotelStatus($q)['criteria_due'];hotelAssert($due===($index%2===0),'continuous_inherited_criteria_counter');
    $run='CONTINUOUS_'.$index.'_INTAKE';$a=hotelStart($q,$run);
    $q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt($run,$packet,$due?hotelCriteria():null)]);
}
hotelAssert(hotelStatus($q)['accepted_batches']===5&&hotelStatus($q)['state']==='ready'&&hotelStatus($q)['country']==='EG','continuous_not_capped_at_three');

// A fresh scoped consent may adopt only exact known state; no client revocation assertion is accepted.
[$pdo,$q,$clock]=hotelFixture();$s=hotelStatus($q);$oldEvent=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$oldEvent['event_id']);
$handoff=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],'old_connection_id'=>str_repeat('a',32)];
foreach ([static fn(): bool=>false,static fn(): bool=>throw new RuntimeException('synthetic_proof_unavailable')] as $proof) {
    hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),$handoff,$proof),HotelWorkQueueConflict::class,'handoff_requires_known_hotel_owner_proof','old_connection_not_known_hotel_owner');
}
hotelReject(fn()=>$q->handoff_connection(2,str_repeat('f',32),$handoff,static fn(): bool=>true),DomainException::class,'handoff_same_admin_only');
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),array_replace($handoff,['baseline_sha256'=>str_repeat('9',64)]),static fn(): bool=>true),HotelWorkQueueConflict::class,'handoff_exact_baseline','baseline_mismatch');
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),array_replace($handoff,['stage_id'=>str_repeat('9',64)]),static fn(): bool=>true),HotelWorkQueueConflict::class,'handoff_exact_stage','stale_stage');
hotelAssert((int)$pdo->query('SELECT COUNT(*) FROM hotel_work_connection_handoffs')->fetchColumn()===0,'denied_handoffs_write_no_audit');
$proofModes=[];
$after=$q->handoff_connection(1,str_repeat('f',32),$handoff,static function(string $old,int $admin,string $mode) use (&$proofModes): bool {
    $proofModes[]=$mode;return $old===str_repeat('a',32)&&$admin===1&&$mode==='ready';
});
hotelAssert($proofModes===['ready'],'ready_handoff_selects_known_owner_policy_not_retirement');
hotelAssert($after['handoff_status']==='connection_handed_off'&&$after['stage_id']===$s['stage_id']&&$after['state']==='ready','ready_handoff_preserves_stage');
hotelAssert($after['oauth_connection_id']===str_repeat('f',32),'owned_projection_has_nonsecret_connection_binding');
$reverse=array_replace($handoff,['old_connection_id'=>str_repeat('f',32)]);
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$reverse,static fn(string $old,int $admin,string $mode): bool=>true),HotelWorkQueueConflict::class,'old_live_family_cannot_handoff_back_with_exact_pins','binding_relinquished');
hotelAssert((int)$pdo->query('SELECT COUNT(*) FROM hotel_work_connection_handoffs')->fetchColumn()===1&&$q->status(1,str_repeat('f',32),'PILOT')['state']==='ready','denied_reactivation_changes_no_audit_or_state');
hotelReject(fn()=>hotelStatus($q),DomainException::class,'retired_connection_cannot_read');
$staleArgs=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('b',64)];
hotelReject(fn()=>$q->create(1,str_repeat('a',32),['queue_id'=>'PILOT','baseline_sha256'=>$s['baseline_sha256']]),DomainException::class,'old_family_cannot_recreate_bound_queue');
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),$staleArgs),DomainException::class,'old_family_cannot_claim');
hotelReject(fn()=>$q->started(1,str_repeat('a',32),$staleArgs+['run_id'=>'STALE']),DomainException::class,'old_family_cannot_bind_run');
hotelReject(fn()=>$q->complete(1,str_repeat('a',32),$staleArgs+['receipt'=>hotelReceipt('STALE')]),DomainException::class,'old_family_cannot_complete');
hotelReject(fn()=>$q->release(1,str_repeat('a',32),['queue_id'=>'PILOT','stage_id'=>$s['stage_id']]),DomainException::class,'old_family_cannot_release');
hotelReject(fn()=>$q->resolve_existing_run(1,str_repeat('a',32),$staleArgs+['unknown_receipt_sha256'=>str_repeat('0',64),'expected_run_id'=>'STALE','expected_packet_sha256'=>str_repeat('1',64),'receipt'=>hotelReceipt('STALE')]),DomainException::class,'old_family_cannot_resolve');
hotelReject(fn()=>$q->pendingEvent(1,str_repeat('a',32),'PILOT'),DomainException::class,'old_family_cannot_read_event');
hotelReject(fn()=>$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$oldEvent['event_id']),DomainException::class,'old_family_cannot_ack');
$newEvent=$q->pendingEvent(1,str_repeat('f',32),'PILOT');
hotelAssert($newEvent['event_id']!==$oldEvent['event_id']&&$newEvent['data']['stage_id']===$oldEvent['data']['stage_id'],'handoff_new_delivery_identity_same_work');
hotelAssert($q->handoff_connection(1,str_repeat('f',32),$handoff,static fn(): bool=>false)['handoff_status']==='already_recorded','handoff_retry_idempotent');

// A claimed/UNKNOWN existing run needs claim-secret and run proof; it never returns ready or dispatch.
foreach (['claimed','unknown'] as $adoptState) {
    [$pdo,$q,$clock]=hotelFixture();$a=hotelStart($q,'BOUND_RUN');$before=hotelStatus($q);
    if ($adoptState==='unknown') {
        $u=array_fill_keys(array_keys(hotelReceipt()),null);$u['outcome']='unknown';$u['run_id']='BOUND_RUN';
        $q->complete(1,str_repeat('a',32),$a+['receipt'=>$u]);$before=hotelStatus($q);
    }
    $h=['queue_id'=>'PILOT','stage_id'=>$before['stage_id'],'baseline_sha256'=>$before['baseline_sha256'],'old_connection_id'=>str_repeat('a',32)];
    hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),$h,static fn(): bool=>true),HotelWorkQueueConflict::class,'claimed_not_ready_handoff','not_known_idle');
    $h+=['handoff_mode'=>'existing_run','claim_key'=>str_repeat('b',64),'expected_run_id'=>'BOUND_RUN'];
    $modes=[];
    hotelReject(static function() use ($q,$h,&$modes): void {
        $q->handoff_connection(1,str_repeat('f',32),$h,static function(string $old,int $admin,string $mode) use (&$modes): bool {
            $modes[]=$mode;return $mode==='ready';
        });
    },HotelWorkQueueConflict::class,'active_known_old_grant_cannot_adopt_existing_run','old_connection_not_definitively_retired');
    hotelAssert($modes===['existing_run'],'existing_run_selects_retirement_proof_only');
    hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),array_replace($h,['claim_key'=>str_repeat('c',64)]),static fn(): bool=>true),HotelWorkQueueConflict::class,'adoption_requires_exact_claim','existing_run_proof_mismatch');
    hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),array_replace($h,['expected_run_id'=>'FOREIGN_RUN']),static fn(): bool=>true),HotelWorkQueueConflict::class,'adoption_requires_exact_run','existing_run_proof_mismatch');
    $adopted=$q->handoff_connection(1,str_repeat('f',32),$h,static fn(): bool=>true);
    hotelAssert($adopted['state']===$adoptState&&$adopted['current_run_id']==='BOUND_RUN'&&$adopted['stage_id']===$before['stage_id'],'adoption_preserves_running_or_unknown_work');
    $attention=$q->pendingEvent(1,str_repeat('f',32),'PILOT');
    hotelAssert($attention['name']==='hotel_work.attention'&&$attention['data']['dispatch_allowed']===false,'adoption_attention_only');
    hotelReject(fn()=>$q->claim(1,str_repeat('f',32),array_replace($a,['claim_key'=>str_repeat('c',64)])),HotelWorkQueueConflict::class,'adoption_no_new_claim');
    if ($adoptState==='unknown') {
        $resolve=$a+['unknown_receipt_sha256'=>$adopted['unknown_receipt_sha256'],'expected_run_id'=>'BOUND_RUN','expected_packet_sha256'=>str_repeat('1',64),'receipt'=>hotelReceipt('BOUND_RUN')];
        $resolved=$q->resolve_existing_run(1,str_repeat('f',32),$resolve);
        hotelAssert($resolved['stage_kind']==='intake'&&$resolved['stage_index']===1,'fresh_consent_resolves_same_existing_run');
    }
}

// UNKNOWN without a bound run cannot be adopted or assigned a guessed run.
[$pdo,$q,$clock]=hotelFixture();$a=['queue_id'=>'PILOT','stage_id'=>hotelStatus($q)['stage_id'],'claim_key'=>str_repeat('b',64)];$q->claim(1,str_repeat('a',32),$a);
$u=array_fill_keys(array_keys(hotelReceipt()),null);$u['outcome']='unknown';$q->complete(1,str_repeat('a',32),$a+['receipt'=>$u]);$s=hotelStatus($q);
$h=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],'old_connection_id'=>str_repeat('a',32),'handoff_mode'=>'existing_run','claim_key'=>str_repeat('b',64),'expected_run_id'=>'GUESSED'];
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),$h,static fn(): bool=>true),HotelWorkQueueConflict::class,'unbound_unknown_cannot_be_adopted','existing_run_proof_mismatch');

// Reconcile exactly the existing run, retaining the original UNKNOWN bytes and one append-only resolution.
[$pdo,$q,$clock]=hotelFixture();$a=hotelStart($q,'ORIGINAL_RUN');
$u=hotelReceipt('ORIGINAL_RUN');$u['outcome']='unknown';$u['readback_sha256']=null;$u['ledger_sha256']=null;
$first=$q->complete(1,str_repeat('a',32),$a+['receipt'=>$u]);
$original=$pdo->query('SELECT receipt_json FROM hotel_work_receipts')->fetchColumn();
hotelAssert($first['receipt_sha256']===$first['unknown_receipt_sha256'],'unknown_hash_returned_not_guessed');
$resolve=$a+['unknown_receipt_sha256'=>$first['receipt_sha256'],'expected_run_id'=>'ORIGINAL_RUN','expected_packet_sha256'=>str_repeat('1',64),'receipt'=>hotelReceipt('ORIGINAL_RUN')];
foreach ([['unknown_receipt_sha256'=>str_repeat('9',64)],['claim_key'=>str_repeat('c',64)]] as $bad) {
    hotelReject(fn()=>$q->resolve_existing_run(1,str_repeat('a',32),array_replace($resolve,$bad)),HotelWorkQueueConflict::class,'resolution_exact_prior_proof');
}
$wrong=$resolve;$wrong['expected_packet_sha256']=str_repeat('9',64);$wrong['receipt']=hotelReceipt('ORIGINAL_RUN','9');
hotelReject(fn()=>$q->resolve_existing_run(1,str_repeat('a',32),$wrong),HotelWorkQueueConflict::class,'resolution_preserves_bound_packet','source_packet_mismatch');
$resolved=$q->resolve_existing_run(1,str_repeat('a',32),$resolve);
hotelAssert($resolved['stage_kind']==='intake'&&$resolved['accepted_batches']===0&&$resolved['watchdog_due_at']===null,'resolved_research_advances_once_stops_watchdog');
hotelAssert($pdo->query('SELECT receipt_json FROM hotel_work_receipts')->fetchColumn()===$original,'original_unknown_immutable');
hotelAssert((int)$pdo->query('SELECT COUNT(*) FROM hotel_work_resolutions')->fetchColumn()===1,'one_append_only_resolution');
hotelAssert($q->resolve_existing_run(1,str_repeat('a',32),$resolve)['resolution_status']==='already_resolved','resolution_repeat_idempotent');
$changed=$resolve;$changed['receipt']['readback_sha256']=str_repeat('8',64);
hotelReject(fn()=>$q->resolve_existing_run(1,str_repeat('a',32),$changed),HotelWorkQueueConflict::class,'conflicting_resolution_rejected','resolution_conflict');
$a=hotelStart($q,'RESOLVED_INTAKE');$q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt('RESOLVED_INTAKE')]);
hotelAssert(hotelStatus($q)['accepted_batches']===1&&hotelStatus($q)['country']==='TH','intake_consumes_exact_resolved_research_packet');

// A claimed child remains discoverable after its parent's turn ends, without a second dispatch.
[$pdo,$q,$clock]=hotelFixture();$a=hotelStart($q,'LONG_CHILD');$due=hotelStatus($q)['watchdog_due_at'];
hotelAssert(count($q->eventCandidates())===1&&$q->pendingEvent(1,str_repeat('a',32),'PILOT')===null,'claimed_watch_registered_not_due');
$clock->at+=300;$watch=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
hotelAssert($watch['name']==='hotel_work.attention'&&$watch['data']['dispatch_allowed']===false&&hotelStatus($q)['state']==='claimed','claimed_watch_no_redispatch');
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$watch['event_id']);$nextDue=hotelStatus($q)['watchdog_due_at'];$clock->at+=60;
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$watch['event_id']);
hotelAssert(hotelStatus($q)['watchdog_due_at']===$nextDue,'watch_ack_retry_does_not_postpone');
$clock->at+=240;$next=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
hotelAssert($next['event_id']!==$watch['event_id']&&$next['data']['dispatch_allowed']===false,'one_new_watch_generation_per_interval');
$q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt('LONG_CHILD')]);
hotelAssert($q->pendingEvent(1,str_repeat('a',32),'PILOT')['name']==='hotel_work.pending'&&hotelStatus($q)['watchdog_due_at']===null,'existing_child_receipt_closes_watch');

// Explicit same-family resume is one delivery generation per stage, never a claim or hotel run.
[$pdo,$q,$clock]=hotelFixture();$s=hotelStatus($q);$original=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
$legacy='hotel_evt_'.hash('sha256',json_encode([$s['stage_id'],str_repeat('a',32),'ready'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
hotelAssert($original['event_id']===$legacy&&$s['expected_pending_event_id']===$legacy,'unresumed_delivery_ids_remain_compatible');
$self=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],'old_connection_id'=>str_repeat('a',32)];
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>true),HotelWorkQueueConflict::class,'fresh_ready_is_not_resumable','delivery_resume_not_attention');
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$original['event_id']);
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>true),HotelWorkQueueConflict::class,'fresh_ack_is_not_resumable','delivery_resume_not_attention');

[$pdo,$q,$clock,$self,$oldPending,$oldAttention]=hotelResumeFixture();$before=hotelStatus($q);
hotelReject(fn()=>$q->handoff_connection(2,str_repeat('a',32),$self,static fn(): bool=>true),DomainException::class,'foreign_admin_cannot_resume');
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),array_replace($self,['old_connection_id'=>str_repeat('f',32)]),static fn(): bool=>true),DomainException::class,'foreign_family_cannot_resume');
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),array_replace($self,['stage_id'=>str_repeat('9',64)]),static fn(): bool=>true),HotelWorkQueueConflict::class,'resume_exact_stage','stale_stage');
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),array_replace($self,['baseline_sha256'=>str_repeat('9',64)]),static fn(): bool=>true),HotelWorkQueueConflict::class,'resume_exact_baseline','baseline_mismatch');
$unmodified=$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>false),HotelWorkQueueConflict::class,'self_resume_requires_current_server_authorization','old_connection_not_known_hotel_owner');
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$self,static function(): bool {throw new RuntimeException('proof_unavailable');}),HotelWorkQueueConflict::class,'self_resume_proof_unknown_fails_closed','old_connection_not_known_hotel_owner');
hotelAssert($unmodified===$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC)&&(int)$pdo->query('SELECT COUNT(*) FROM hotel_work_connection_handoffs')->fetchColumn()===0,'failed_self_proof_changes_no_state_or_audit');
$resumed=$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>true);
$fresh=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
hotelAssert($resumed['handoff_status']==='delivery_resumed_once'&&$fresh['event_id']!==$oldPending['event_id'],'resume_creates_one_new_delivery_id');
hotelAssert($resumed['stage_id']===$before['stage_id']&&$resumed['oauth_connection_id']===$before['oauth_connection_id']&&$resumed['accepted_batches']===$before['accepted_batches']&&$resumed['state']==='ready'&&$resumed['current_run_id']===null,'resume_changes_no_stage_identity_business_or_run');
hotelAssert($resumed['expected_pending_event_id']===$fresh['event_id']&&$fresh['data']===$oldPending['data'],'fresh_expected_id_same_bounded_payload');
hotelAssert((int)$pdo->query("SELECT COUNT(*) FROM hotel_work_connection_handoffs WHERE old_connection_id=new_connection_id")->fetchColumn()===1,'one_durable_self_audit');
hotelReject(fn()=>$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$oldPending['event_id']),HotelWorkQueueConflict::class,'old_pending_ack_stale_after_resume','stale_event');
hotelReject(fn()=>$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$oldAttention['event_id']),HotelWorkQueueConflict::class,'old_attention_ack_stale_after_resume','stale_event');
$claim=['queue_id'=>'PILOT','stage_id'=>$resumed['stage_id'],'claim_key'=>str_repeat('b',64)];
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),$claim),HotelWorkQueueConflict::class,'resumed_stage_requires_event_admission','pending_event_required');
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),$claim+['expected_pending_event_id'=>$oldPending['event_id']]),HotelWorkQueueConflict::class,'old_pending_cannot_claim_resumed_stage','stale_pending_event');
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),$claim+['expected_pending_event_id'=>false]),InvalidArgumentException::class,'event_admission_rejects_bool','invalid_hotel_work_pending_event');
hotelReject(fn()=>$q->claim(1,str_repeat('a',32),$claim+['expected_pending_event_id'=>$oldAttention['event_id']]),InvalidArgumentException::class,'attention_event_cannot_admit_claim','invalid_hotel_work_pending_event');
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$fresh['event_id']);
$clock->at+=180;$freshAttention=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
hotelAssert($freshAttention['event_id']!==$oldAttention['event_id']&&$freshAttention['data']===$oldAttention['data'],'resumed_attention_has_new_id_same_bounded_payload');
$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$freshAttention['event_id']);
$acked=$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>false);
hotelAssert($acked===$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC)&&$q->pendingEvent(1,str_repeat('a',32),'PILOT')===null,'exact_resume_retry_never_clears_new_ack');
$q->claim(1,str_repeat('a',32),$claim+['expected_pending_event_id'=>$fresh['event_id']]);
$claimed=$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>false);
hotelAssert($claimed===$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC),'exact_resume_retry_never_resets_claim');
$u=array_fill_keys(array_keys(hotelReceipt()),null);$u['outcome']='unknown';$q->complete(1,str_repeat('a',32),$claim+['receipt'=>$u]);
$unknown=$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>false);
hotelAssert($unknown===$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC),'exact_resume_retry_never_resets_unknown');

[$pdo,$q,$clock,$self]=hotelResumeFixture();
$pdo->prepare('INSERT INTO hotel_work_receipts (queue_id,stage_index,stage_id,receipt_sha256,receipt_json,created_at) VALUES (?,?,?,?,?,?)')->execute(['PILOT',0,$self['stage_id'],str_repeat('8',64),'{}',gmdate('Y-m-d H:i:s',$clock->at)]);
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>true),HotelWorkQueueConflict::class,'existing_stage_receipt_prevents_resume','stage_receipt_exists');
hotelAssert((int)$pdo->query('SELECT COUNT(*) FROM hotel_work_connection_handoffs')->fetchColumn()===0,'receipt_guard_writes_no_self_audit');

foreach (['claimed','unknown'] as $notReady) {
    [$pdo,$q,$clock]=hotelFixture();$a=hotelStart($q,'NOT_RESUMABLE');
    if ($notReady==='unknown') {$u=hotelReceipt('NOT_RESUMABLE');$u['outcome']='unknown';$q->complete(1,str_repeat('a',32),$a+['receipt'=>$u]);}
    $s=hotelStatus($q);$h=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],'old_connection_id'=>str_repeat('a',32)];
    hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$h,static fn(): bool=>true),HotelWorkQueueConflict::class,'unresumed_claimed_or_unknown_cannot_resume','not_known_idle');
    hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$h+['handoff_mode'=>'existing_run','claim_key'=>str_repeat('b',64),'expected_run_id'=>'NOT_RESUMABLE'],static fn(): bool=>true),InvalidArgumentException::class,'existing_run_self_remains_rejected','same_hotel_work_connection');
}

[$pdo,$q,$clock,$self]=hotelResumeFixture();$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>true);
$q->handoff_connection(1,str_repeat('f',32),$self,static fn(): bool=>true);
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>true),DomainException::class,'old_self_retry_requires_current_owner');
$event=$q->pendingEvent(1,str_repeat('f',32),'PILOT');$q->markEventDelivered(1,str_repeat('f',32),'PILOT',$event['event_id']);$clock->at+=180;$q->pendingEvent(1,str_repeat('f',32),'PILOT');
$otherSelf=array_replace($self,['old_connection_id'=>str_repeat('f',32)]);
$rowBefore=$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('f',32),$otherSelf,static fn(): bool=>true),HotelWorkQueueConflict::class,'resume_once_per_stage_across_families','delivery_resume_already_used');
hotelAssert($rowBefore===$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC)&&(int)$pdo->query('SELECT COUNT(*) FROM hotel_work_connection_handoffs')->fetchColumn()===2,'cross_family_retry_changes_no_ack_or_audit');
hotelReject(fn()=>$q->handoff_connection(1,str_repeat('a',32),$otherSelf,static fn(): bool=>true),HotelWorkQueueConflict::class,'real_relinquished_binding_stays_fenced','binding_relinquished');

[$pdo,$q,$clock,$self]=hotelResumeFixture();$q->handoff_connection(1,str_repeat('a',32),$self,static fn(): bool=>true);
$a=hotelStart($q,'RESUMED_RESEARCH');$q->complete(1,str_repeat('a',32),$a+['receipt'=>hotelReceipt('RESUMED_RESEARCH')]);
$next=$q->pendingEvent(1,str_repeat('a',32),'PILOT');$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$next['event_id']);$clock->at+=180;$q->pendingEvent(1,str_repeat('a',32),'PILOT');
$s=hotelStatus($q);$nextSelf=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],'old_connection_id'=>str_repeat('a',32)];
hotelAssert($q->handoff_connection(1,str_repeat('a',32),$nextSelf,static fn(): bool=>true)['handoff_status']==='delivery_resumed_once','prior_stage_self_audit_is_not_relinquishment');

// Native concurrent claims: subprocesses start on a common barrier, not a mocked row count.
$dir=sys_get_temp_dir().'/hotel-work-race-'.bin2hex(random_bytes(8));if (!mkdir($dir,0700)) throw new RuntimeException('race_directory_unavailable');
$file=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/queue.sqlite';
$racePdo=new HotelWorkTestPdo($file==='MYSQL'?null:$file);$raceQ=new HotelWorkQueue($racePdo,static fn(): int=>1793100000);
$raceQ->create(1,str_repeat('a',32),['queue_id'=>'RACE','baseline_sha256'=>str_repeat('0',64)]);
$processes=[];
foreach (['b','c'] as $i=>$char) {
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--claim-child',$dir.'/go',$dir.'/out'.$i,$file,str_repeat($char,64)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('race_process_unavailable');fclose($pipes[0]);$processes[]=[$process,$pipes];
}
file_put_contents($dir.'/go','go');
foreach ($processes as [$process,$pipes]) { $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);hotelAssert(proc_close($process)===0&&$stdout===''&&$stderr==='','native_race_child_clean'); }
$results=[file_get_contents($dir.'/out0'),file_get_contents($dir.'/out1')];sort($results);
hotelAssert($results===['claimed','conflict'],'two_real_processes_one_claim_winner');
$racePdo=null;$raceQ=null;foreach (glob($dir.'/*')?:[] as $path) unlink($path);rmdir($dir);

// Both processes pin the same READY snapshot before contending for the real lane lock.
$dir=sys_get_temp_dir().'/hotel-work-handoff-race-'.bin2hex(random_bytes(8));if (!mkdir($dir,0700)) throw new RuntimeException('race_directory_unavailable');
$file=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/queue.sqlite';
$racePdo=new HotelWorkTestPdo($file==='MYSQL'?null:$file);$raceQ=new HotelWorkQueue($racePdo,static fn(): int=>1793100000);
$raceQ->create(1,str_repeat('a',32),['queue_id'=>'RACE','baseline_sha256'=>str_repeat('0',64)]);
$processes=[];
foreach (['claim','handoff'] as $i=>$action) {
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--claim-handoff-child',$dir.'/go',$dir.'/out'.$i,$file,$action],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('race_process_unavailable');fclose($pipes[0]);$processes[]=[$process,$pipes];
}
$until=microtime(true)+10;
while ((!is_file($dir.'/out0.ready')||!is_file($dir.'/out1.ready'))&&microtime(true)<$until) usleep(1000);
hotelAssert(is_file($dir.'/out0.ready')&&is_file($dir.'/out1.ready'),'claim_handoff_children_ready');
hotelAssert(file_get_contents($dir.'/out0.ready')===file_get_contents($dir.'/out1.ready'),'claim_handoff_same_pinned_stage');
file_put_contents($dir.'/go','go');
foreach ($processes as [$process,$pipes]) {$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);hotelAssert(proc_close($process)===0&&$stdout===''&&$stderr==='','claim_handoff_child_clean');}
$claimResult=file_get_contents($dir.'/out0');$handoffResult=file_get_contents($dir.'/out1');
$row=$racePdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='RACE'")->fetch(PDO::FETCH_ASSOC);
if ($row['oauth_connection_id']===str_repeat('f',32)) {
    hotelAssert($handoffResult==='connection_handed_off'&&$claimResult==='access_denied'&&$row['state']==='ready'&&$row['claim_sha256']===null&&$row['current_run_id']===null,'handoff_wins_without_old_claim');
    hotelReject(fn()=>$raceQ->claim(1,str_repeat('a',32),['queue_id'=>'RACE','stage_id'=>file_get_contents($dir.'/out0.ready'),'claim_key'=>str_repeat('b',64)]),DomainException::class,'race_old_family_stays_fenced');
} else {
    hotelAssert($row['oauth_connection_id']===str_repeat('a',32)&&$row['state']==='claimed'&&$claimResult==='claimed'&&$handoffResult==='conflict:not_known_idle','old_claim_wins_without_handoff');
}
hotelAssert((int)$row['stage_index']===0&&(int)$row['accepted_batches']===0,'claim_handoff_race_never_advances_or_dispatches_business');
$racePdo=null;$raceQ=null;foreach (glob($dir.'/*')?:[] as $path) unlink($path);rmdir($dir);
// Force both real claim/resume lock orderings; keep an additional old-ACK race.
foreach (['claim_first','resume_first','ack_race'] as $scenario) {
    $dir=sys_get_temp_dir().'/hotel-work-resume-race-'.bin2hex(random_bytes(8));if (!mkdir($dir,0700)) throw new RuntimeException('race_directory_unavailable');
    $processes=[];$racePdo=null;$raceQ=null;
    try {
        $file=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/queue.sqlite';$raceClock=(object)['at'=>1793100000];
        $racePdo=new HotelWorkTestPdo($file==='MYSQL'?null:$file);$raceQ=new HotelWorkQueue($racePdo,static fn(): int=>$raceClock->at);
        $raceQ->create(1,str_repeat('a',32),['queue_id'=>'RACE','baseline_sha256'=>str_repeat('0',64)]);
        $old=$raceQ->pendingEvent(1,str_repeat('a',32),'RACE');$raceQ->markEventDelivered(1,str_repeat('a',32),'RACE',$old['event_id']);
        $raceClock->at+=180;$raceQ->pendingEvent(1,str_repeat('a',32),'RACE');
        $actions=$scenario==='claim_first'?['claim-winner','resume']:($scenario==='resume_first'?['claim','resume-winner']:['ack','resume']);
        foreach ($actions as $i=>$action) {
            $go=$scenario==='ack_race'?$dir.'/go':$dir.'/go'.$i;
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--claim-resume-child',$go,$dir.'/out'.$i,$file,$action],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('race_process_unavailable');fclose($pipes[0]);$processes[]=[$process,$pipes];
        }
        $until=microtime(true)+10;
        while ((!is_file($dir.'/out0.ready')||!is_file($dir.'/out1.ready'))&&microtime(true)<$until) usleep(1000);
        hotelAssert(is_file($dir.'/out0.ready')&&is_file($dir.'/out1.ready'),'resume_race_children_ready');
        hotelAssert(file_get_contents($dir.'/out0.ready')===file_get_contents($dir.'/out1.ready')&&file_get_contents($dir.'/out0.event')===$old['event_id']&&file_get_contents($dir.'/out1.event')===$old['event_id'],'resume_race_same_stage_and_old_event_pins');
        if ($scenario==='ack_race') file_put_contents($dir.'/go','go');
        else {
            $winner=$scenario==='claim_first'?0:1;$contender=1-$winner;
            file_put_contents($dir.'/go'.$winner,'go');$until=microtime(true)+10;
            while (!is_file($dir.'/out'.$winner.'.locked')&&microtime(true)<$until) usleep(1000);
            hotelAssert(is_file($dir.'/out'.$winner.'.locked'),'forced_winner_holds_actual_lane_and_queue_locks');
            file_put_contents($dir.'/go'.$contender,'go');$until=microtime(true)+5;
            while (!is_file($dir.'/out'.$contender.'.attempting')&&microtime(true)<$until) usleep(1000);
            hotelAssert(is_file($dir.'/out'.$contender.'.attempting'),'forced_contender_attempts_while_winner_locked');
            usleep(100000);
            hotelAssert(!is_file($dir.'/out'.$winner)&&!is_file($dir.'/out'.$contender)&&proc_get_status($processes[$contender][0])['running'],'forced_contender_cannot_finish_before_lock_release');
            file_put_contents($dir.'/out'.$winner.'.release','release');
        }
        $until=microtime(true)+10;
        while ((!is_file($dir.'/out0')||!is_file($dir.'/out1'))&&microtime(true)<$until) usleep(1000);
        hotelAssert(is_file($dir.'/out0')&&is_file($dir.'/out1'),'resume_race_children_finish_within_deadline');
        foreach ($processes as [$process,$pipes]) {$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);hotelAssert(proc_close($process)===0&&$stdout===''&&$stderr==='','resume_race_child_clean');}
        $competingResult=file_get_contents($dir.'/out0');$resumeResult=file_get_contents($dir.'/out1');
        $row=$racePdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='RACE'")->fetch(PDO::FETCH_ASSOC);
        $selfCount=(int)$racePdo->query('SELECT COUNT(*) FROM hotel_work_connection_handoffs WHERE old_connection_id=new_connection_id')->fetchColumn();
        if ($scenario==='ack_race') {
            hotelAssert($resumeResult==='delivery_resumed_once'&&in_array($competingResult,['acknowledged','conflict:stale_event'],true)&&$row['state']==='ready'&&$row['event_ack']===null&&$selfCount===1,'old_ack_race_cannot_ack_new_delivery');
            $fresh=$raceQ->pendingEvent(1,str_repeat('a',32),'RACE');
            hotelAssert($fresh!==null&&$fresh['event_id']!==$old['event_id']&&$row['claim_sha256']===null&&$row['current_run_id']===null,'old_ack_race_preserves_fresh_pending_without_dispatch');
        } elseif ($scenario==='resume_first') {
            hotelAssert($row['state']==='ready'&&$resumeResult==='delivery_resumed_once'&&$competingResult==='conflict:stale_pending_event'&&$selfCount===1&&$row['claim_sha256']===null&&$row['current_run_id']===null,'forced_resume_first_atomically_fences_old_admission');
            $fresh=$raceQ->pendingEvent(1,str_repeat('a',32),'RACE');
            $claim=['queue_id'=>'RACE','stage_id'=>file_get_contents($dir.'/out0.ready'),'claim_key'=>str_repeat('b',64),'expected_pending_event_id'=>$fresh['event_id']];
            $first=$raceQ->claim(1,str_repeat('a',32),$claim);$again=$raceQ->claim(1,str_repeat('a',32),$claim);
            hotelAssert($first['claim_status']==='claimed'&&$again['claim_status']==='already_claimed_do_not_replay'&&$raceQ->status(1,str_repeat('a',32),'RACE')['state']==='claimed','fresh_resumed_event_admits_exactly_one_claim');
        } else {
            hotelAssert($row['state']==='claimed'&&$competingResult==='claimed'&&$resumeResult==='conflict:not_known_idle'&&$selfCount===0&&$row['current_run_id']===null,'forced_claim_first_denies_resume_without_audit');
        }
        hotelAssert($row['oauth_connection_id']===str_repeat('a',32)&&(int)$row['stage_index']===0&&(int)$row['accepted_batches']===0,'resume_race_changes_no_identity_stage_or_business_data');
    } finally {
        foreach ($processes as [$process,$pipes]) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) {proc_terminate($process,9);proc_close($process);}
        }
        $racePdo=null;$raceQ=null;foreach (glob($dir.'/*')?:[] as $path) unlink($path);rmdir($dir);
    }
}
echo 'HOTEL WORK QUEUE: '.$checks." PASS\n";
