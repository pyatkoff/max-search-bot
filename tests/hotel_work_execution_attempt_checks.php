<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkQueue.php';
require_once __DIR__.'/fixtures/hotel_work_queue.php';

// This suite is both standalone (focused SQLite) and included by the existing
// queue/MySQL entrypoint. All records, identities and packets are synthetic.
$executionAttemptChecks=0;
function executionCheck(bool $ok,string $name): void {
    global $executionAttemptChecks;++$executionAttemptChecks;
    if (!$ok) throw new RuntimeException('execution_attempt_assertion_failed:'.$name);
}
function executionReject(callable $work,string $name,?string $reason=null): void {
    try {$work();} catch (Throwable $error) {
        executionCheck(($error instanceof HotelWorkQueueConflict||$error instanceof DomainException||$error instanceof InvalidArgumentException)
            &&($reason===null||$error->getMessage()===$reason),$name);return;
    }
    executionCheck(false,$name);
}
function executionTables(): array {return ['hotel_work_queues','hotel_work_lane','hotel_work_receipts','hotel_work_resolutions',
    'hotel_work_connection_handoffs','hotel_work_delivery_recoveries','hotel_work_research_skips','hotel_work_consumer_delivery_repairs',
    'hotel_work_execution_attempts','hotel_work_execution_attempt_entries','hotel_work_execution_attempt_associations'];}
function executionSnapshot(PDO $pdo): array {
    $out=[];foreach(executionTables() as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);return $out;
}
function executionCanonical(array $value): string {
    $sort=static function(array $array)use(&$sort):array {ksort($array,SORT_STRING);foreach($array as &$item) if(is_array($item)) $item=$sort($item);return $array;};
    return json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function executionReceipt(string $run='ACTUAL_PRODUCER',string $packet='7',?array $criteria=null): array {
    return ['run_id'=>$run,'outcome'=>'accepted_readback','packet_sha256'=>str_repeat($packet,64),
        'readback_sha256'=>str_repeat('8',64),'ledger_sha256'=>str_repeat('9',64),'hotel_count'=>8,'record_count'=>24,
        'source_count'=>2,'read_update_count'=>22,'criteria'=>$criteria];
}
function executionFixture(?string $file=null): array {
    $pdo=new HotelWorkTestPdo($file);$clock=(object)['at'=>1793400000];$queue=new HotelWorkQueue($pdo,static fn():int=>$clock->at);
    $queue->create(1,str_repeat('a',32),['queue_id'=>'ATTEMPT','mode'=>'continuous','start_country'=>'TH',
        'phase_counter'=>1,'baseline_sha256'=>str_repeat('0',64)]);
    $pending=$queue->pendingEvent(1,str_repeat('a',32),'ATTEMPT');$queue->markEventDelivered(1,str_repeat('a',32),'ATTEMPT',$pending['event_id']);
    $s=$queue->status(1,str_repeat('a',32),'ATTEMPT');$claim=['queue_id'=>'ATTEMPT','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('b',64)];
    $queue->claim(1,str_repeat('a',32),$claim+['expected_pending_event_id'=>$pending['event_id']]);
    $queue->started(1,str_repeat('a',32),$claim+['run_id'=>'CANONICAL_HOLD2']);
    $identity=['queue_id'=>'ATTEMPT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],
        'expected_caller_connection_id'=>str_repeat('a',32),'expected_current_run_id'=>'CANONICAL_HOLD2'];
    return [$pdo,$queue,$clock,$identity,$claim];
}
function executionPrepareArgs(HotelWorkQueue $queue,array $identity): array {
    $pre=$queue->execution_attempt_preflight(1,str_repeat('a',32),$identity);
    return $identity+['claim_key'=>str_repeat('b',64),'expected_state_sha256'=>$pre['state_sha256'],
        'operation_id'=>'TH28-CENTRAL29-SYNTHETIC','attempt_token'=>str_repeat('1',64),'scope_sha256'=>str_repeat('2',64),
        'owner_decision_sha256'=>str_repeat('3',64),'prompt_readback_sha256'=>str_repeat('4',64),
        'assignment_readback_sha256'=>str_repeat('5',64),'fencing_readback_sha256'=>str_repeat('6',64),
        'fencing_status'=>'old_guards_fail_closed','prior_attempts'=>[
            ['ordinal'=>1,'status'=>'unknown','run_id'=>null,'receipt_sha256'=>str_repeat('c',64)],
            ['ordinal'=>2,'status'=>'terminal_hold_no_work','run_id'=>'CANONICAL_HOLD2','receipt_sha256'=>str_repeat('d',64)]],
        'dispatch_ceiling'=>3];
}
function executionEnterArgs(array $args,string $instance='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'): array {
    return array_intersect_key($args,array_flip(['queue_id','stage_id','attempt_token','operation_id','scope_sha256',
        'prompt_readback_sha256','assignment_readback_sha256']))+['instance_id'=>$instance];
}
function executionBindArgs(HotelWorkQueue $queue,array $identity,array $args,array $entry): array {
    $pre=$queue->execution_attempt_preflight(1,str_repeat('a',32),$identity);
    return $identity+['claim_key'=>str_repeat('b',64),'expected_state_sha256'=>$pre['state_sha256'],
        'attempt_token'=>$args['attempt_token'],'instance_id'=>str_repeat('e',64),'entry_sha256'=>$entry['entry_sha256'],
        'producer_run_id'=>'ACTUAL_PRODUCER','packet_sha256'=>str_repeat('7',64),'native_result_readback_sha256'=>str_repeat('f',64)];
}
function executionPrepared(?string $file=null): array {
    [$pdo,$queue,$clock,$identity,$claim]=executionFixture($file);$args=executionPrepareArgs($queue,$identity);
    $permit=$queue->prepare_execution_attempt(1,str_repeat('a',32),$args);return [$pdo,$queue,$clock,$identity,$claim,$args,$permit];
}
function executionEntered(): array {
    [$pdo,$queue,$clock,$identity,$claim,$args,$permit]=executionPrepared();
    $entry=$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args));
    return [$pdo,$queue,$clock,$identity,$claim,$args,$permit,$entry];
}
function executionBound(): array {
    [$pdo,$queue,$clock,$identity,$claim,$args,$permit,$entry]=executionEntered();$bind=executionBindArgs($queue,$identity,$args,$entry);
    $associated=$queue->bind_execution_attempt(1,str_repeat('a',32),$bind);
    return [$pdo,$queue,$clock,$identity,$claim,$args,$permit,$entry,$bind,$associated];
}
function executionSameSnapshotReject(PDO $pdo,callable $work,string $name): void {
    $before=executionSnapshot($pdo);executionReject($work,$name);executionCheck($before===executionSnapshot($pdo),$name.'_no_mutation');
}
function executionWait(string $path): void {
    $until=microtime(true)+12;while(!is_file($path)&&microtime(true)<$until) usleep(1000);
    if(!is_file($path)) throw new RuntimeException('execution_race_barrier_timeout:'.basename($path));
}

if(($argv[1]??'')==='--execution-attempt-child') {
    [$unused,$mode,$file,$input,$action,$out]=array_pad($argv,6,'');$payload=json_decode((string)file_get_contents($input),true,32,JSON_THROW_ON_ERROR);
    $pdo=new HotelWorkTestPdo($file==='MYSQL'?null:$file,false);$hold=(object)['armed'=>false];
    $queue=new HotelWorkQueue($pdo,static function()use($hold,$out):int {
        if($hold->armed){$hold->armed=false;file_put_contents($out.'.locked','locked');executionWait($out.'.release');}return 1793400000;
    });
    file_put_contents($out.'.ready','ready');executionWait($out.'.go');file_put_contents($out.'.attempting','attempting');
    $hold->armed=str_ends_with($action,'-winner');$action=str_replace('-winner','',$action);
    try {
        $args=$payload[$action];
        if($action==='prepare') $result=$queue->prepare_execution_attempt(1,str_repeat('a',32),$args);
        elseif($action==='enter') $result=$queue->enter_execution_attempt(1,str_repeat('a',32),$args);
        elseif($action==='bind') $result=$queue->bind_execution_attempt(1,str_repeat('a',32),$args);
        elseif($action==='complete') $result=$queue->complete(1,str_repeat('a',32),$args);
        elseif($action==='skip') $result=$queue->skip_research(1,str_repeat('a',32),$args,static fn():bool=>true);
        elseif($action==='handoff') $result=$queue->handoff_connection(1,str_repeat('f',32),$args,static fn():bool=>true);
        else throw new LogicException('unknown_execution_race_action');
        // Cooperating invocations do no synthetic producer effect until the
        // first consume response authorizes this invocation. Same-I replay
        // and a distinct losing I must never reach this marker.
        if($action==='enter'&&($result['work_authorized']??false)===true) file_put_contents($out.'.work','producer-effect');
        file_put_contents($out,json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR));
    }catch(HotelWorkQueueConflict|DomainException|InvalidArgumentException $error){file_put_contents($out,json_encode(['ok'=>false,'reason'=>$error->getMessage()],JSON_THROW_ON_ERROR));}
    exit(0);
}

function executionAttemptCoreChecks(): void {
    [$pdo,$queue,$clock,$identity,$claim]=executionFixture();$before=executionSnapshot($pdo);$writes=$pdo->writes;
    $pre=$queue->execution_attempt_preflight(1,str_repeat('a',32),$identity);
    executionCheck($pre['eligible_to_prepare']===true&&$pre['eligible_to_bind']===false&&$before===executionSnapshot($pdo)&&$writes===$pdo->writes,'preflight_read_only_exact_claimed_research');
    executionSameSnapshotReject($pdo,fn()=>$queue->started(1,str_repeat('a',32),$claim+['run_id'=>'ACTUAL_PRODUCER']),'legacy_started_cannot_replace_canonical');
    executionSameSnapshotReject($pdo,fn()=>$queue->complete(1,str_repeat('a',32),$claim+['receipt'=>executionReceipt()]),'legacy_complete_cannot_label_new_producer');
    $args=executionPrepareArgs($queue,$identity);$permit=$queue->prepare_execution_attempt(1,str_repeat('a',32),$args);$prepared=executionSnapshot($pdo);
    executionCheck($permit['attempt_status']==='execution_attempt_prepared'&&$permit['operation_dispatches_work']===false&&$permit['work_authorized']===false,'prepare_does_not_dispatch_or_admit');
    foreach(array_slice(executionTables(),0,8) as $table) executionCheck($before[$table]===$prepared[$table],'prepare_preserves_'.$table);
    executionCheck(count($prepared['hotel_work_execution_attempts'])===1&&$prepared['hotel_work_execution_attempt_entries']===[]&&$prepared['hotel_work_execution_attempt_associations']===[],'prepare_appends_one_permit_only');
    $audit=json_decode($prepared['hotel_work_execution_attempts'][0]['audit_json'],true,32,JSON_THROW_ON_ERROR);
    executionCheck($audit['request']['prior_attempts']==$args['prior_attempts']&&$audit['canonical_run_id']==='CANONICAL_HOLD2',
        'immutable_history_retains_first_unknown_and_second_hold_without_invented_terminal_origin');
    $public=$queue->status(1,str_repeat('a',32),'ATTEMPT');$publicJson=json_encode($public,JSON_THROW_ON_ERROR);
    executionCheck($public['execution_attempt']['canonical_run_id']==='CANONICAL_HOLD2'
        &&!str_contains($publicJson,$args['attempt_token'])&&!str_contains($publicJson,$args['claim_key'])
        &&!isset($public['execution_attempt']['host_verified']),'bounded_status_omits_literal_token_claim_secret_and_host_truth');
    $again=$queue->prepare_execution_attempt(1,str_repeat('a',32),$args);
    executionCheck($again['attempt_status']==='already_prepared'&&$again['permit_sha256']===$permit['permit_sha256']&&$again['work_authorized']===false&&$prepared===executionSnapshot($pdo),'exact_prepare_repeat_no_refund_no_mutation');
    $entered=$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args));$entrySnapshot=executionSnapshot($pdo);
    executionCheck($entered['entry_status']==='entered_once'&&$entered['work_authorized']===true,'first_fresh_instance_authorized_without_native_id');
    $againEntry=$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args));
    executionCheck($againEntry['entry_status']==='already_entered_do_not_replay'&&$againEntry['work_authorized']===false
        &&$againEntry['entry_sha256']===$entered['entry_sha256']&&$entrySnapshot===executionSnapshot($pdo),'same_instance_repeat_never_new_work_authority');
    executionSameSnapshotReject($pdo,fn()=>$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args,str_repeat('f',64))),'distinct_instance_loses_without_native_id');
    executionSameSnapshotReject($pdo,fn()=>$queue->complete(1,str_repeat('a',32),$claim+['receipt'=>executionReceipt('CANONICAL_HOLD2')]),'permit_blocks_new_legacy_completion');
    executionReject(fn()=>$queue->resolve_existing_run(1,str_repeat('a',32),$claim+[
        'unknown_receipt_sha256'=>str_repeat('c',64),'expected_run_id'=>'CANONICAL_HOLD2',
        'expected_packet_sha256'=>str_repeat('7',64),'receipt'=>executionReceipt('CANONICAL_HOLD2')]),
        'permit_blocks_legacy_resolution_without_claiming_host_truth','execution_attempt_required');
    executionCheck($entrySnapshot===executionSnapshot($pdo),'blocked_legacy_resolution_preserves_original_entry_and_claim');
    $bind=executionBindArgs($queue,$identity,$args,$entered);$associated=$queue->bind_execution_attempt(1,str_repeat('a',32),$bind);$bound=executionSnapshot($pdo);
    executionCheck($associated['association_status']==='producer_association_recorded'&&$associated['work_authorized']===false,'late_actual_producer_association_is_metadata_only');
    executionCheck($before['hotel_work_queues']===$bound['hotel_work_queues']&&$bound['hotel_work_queues'][0]['current_run_id']==='CANONICAL_HOLD2','producer_binding_preserves_original_canonical_claim_ack_history');
    $againBind=$queue->bind_execution_attempt(1,str_repeat('a',32),$bind);
    executionCheck($againBind['association_status']==='already_recorded'&&$againBind['producer_association_sha256']===$associated['producer_association_sha256']&&$bound===executionSnapshot($pdo),'exact_bind_repeat_is_no_op');
    $completion=$claim+['producer_association_sha256'=>$associated['producer_association_sha256'],'receipt'=>executionReceipt()];
    $done=$queue->complete(1,str_repeat('a',32),$completion);$advanced=executionSnapshot($pdo);
    executionCheck($done['stage_kind']==='intake'&&$done['stage_index']===1&&$done['accepted_batches']===0,'accepted_actual_producer_advances_once_to_its_intake');
    $saved=json_decode($advanced['hotel_work_receipts'][0]['receipt_json'],true,16,JSON_THROW_ON_ERROR);
    executionCheck($saved['run_id']==='ACTUAL_PRODUCER'&&$saved['packet_sha256']===str_repeat('7',64),'normal_receipt_names_actual_producer_never_canonical_hold');
    foreach(['hotel_work_execution_attempts','hotel_work_execution_attempt_entries','hotel_work_execution_attempt_associations'] as $table) executionCheck($bound[$table]===$advanced[$table],'completion_preserves_immutable_'.$table);
    executionCheck($queue->complete(1,str_repeat('a',32),$completion)['completion_status']==='already_recorded'&&$advanced===executionSnapshot($pdo),'actual_producer_completion_repeat_after_advance');
    executionCheck($queue->prepare_execution_attempt(1,str_repeat('a',32),$args)['attempt_status']==='already_prepared'
        &&$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args))['work_authorized']===false
        &&$queue->bind_execution_attempt(1,str_repeat('a',32),$bind)['association_status']==='already_recorded'
        &&$advanced===executionSnapshot($pdo),'historical_permit_entry_binding_repeats_after_stage_advance_not_authority');
    $next=$queue->pendingEvent(1,str_repeat('a',32),'ATTEMPT');
    executionCheck($next['name']==='hotel_work.pending'&&$next['data']['stage_kind']==='intake'&&$next['data']['dispatch_allowed']===true,'only_normal_next_stage_event_after_accepted_producer');
    $queue->markEventDelivered(1,str_repeat('a',32),'ATTEMPT',$next['event_id']);
    $intake=['queue_id'=>'ATTEMPT','stage_id'=>$done['stage_id'],'claim_key'=>str_repeat('c',64)];
    $queue->claim(1,str_repeat('a',32),$intake+['expected_pending_event_id'=>$next['event_id']]);$queue->started(1,str_repeat('a',32),$intake+['run_id'=>'CENTRAL_ACTUAL']);
    $criteria=['readback_sha256'=>str_repeat('a',64),'case_count'=>10,'done_count'=>7,'partial_count'=>3];
    executionSameSnapshotReject($pdo,fn()=>$queue->complete(1,str_repeat('a',32),$intake+['receipt'=>executionReceipt('CENTRAL_ACTUAL','6',$criteria)]),'central_rejects_foreign_packet');
    executionSameSnapshotReject($pdo,fn()=>$queue->complete(1,str_repeat('a',32),$intake+['receipt'=>executionReceipt('CENTRAL_ACTUAL')]),'central_preserves_due_criteria');
    $intakeDone=$queue->complete(1,str_repeat('a',32),$intake+['receipt'=>executionReceipt('CENTRAL_ACTUAL','7',$criteria)]);
    executionCheck($intakeDone['accepted_batches']===1&&$intakeDone['stage_index']===2&&$intakeDone['phase_counter']===0,'central_accepts_same_actual_packet_with_due_criteria');
    $final=executionSnapshot($pdo);$queue->prepare_execution_attempt(1,str_repeat('a',32),$args);
    executionCheck($final===executionSnapshot($pdo),'post_central_prepare_repeat_cannot_buy_another_unit');
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {$pdo->reapplyExecutionAttemptMigration();executionCheck($final===executionSnapshot($pdo),'forward_078_reapply_preserves_all_permits_entries_bindings_receipts_and_history');}
    // A distinct synthetic later stage has separate explicit authority, but
    // cannot reuse the old token, invocation identity or actual producer ID.
    $s=$queue->status(1,str_repeat('a',32),'ATTEMPT');$laterClaim=['queue_id'=>'ATTEMPT','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('b',64)];
    $queue->claim(1,str_repeat('a',32),$laterClaim+['expected_pending_event_id'=>$s['expected_pending_event_id']]);
    $queue->started(1,str_repeat('a',32),$laterClaim+['run_id'=>'CANONICAL_LATER_HOLD']);
    $laterIdentity=array_replace($identity,['stage_id'=>$s['stage_id'],'expected_current_run_id'=>'CANONICAL_LATER_HOLD']);
    $later=executionPrepareArgs($queue,$laterIdentity);$later['prior_attempts'][1]['run_id']='CANONICAL_LATER_HOLD';
    executionSameSnapshotReject($pdo,fn()=>$queue->prepare_execution_attempt(1,str_repeat('a',32),$later),'later_stage_cannot_reuse_spent_token');
    $later['attempt_token']=str_repeat('f',64);$queue->prepare_execution_attempt(1,str_repeat('a',32),$later);
    executionSameSnapshotReject($pdo,fn()=>$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($later)),'later_stage_cannot_adopt_previous_instance');
    $laterEntry=$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($later,str_repeat('f',64)));
    $laterBind=executionBindArgs($queue,$laterIdentity,$later,$laterEntry);$laterBind['instance_id']=str_repeat('f',64);
    executionSameSnapshotReject($pdo,fn()=>$queue->bind_execution_attempt(1,str_repeat('a',32),$laterBind),'actual_producer_cannot_be_associated_with_another_stage');
}

function executionAttemptInvalidChecks(): void {
    [$pdo,$queue,$clock,$identity]=executionFixture();
    executionSameSnapshotReject($pdo,fn()=>$queue->execution_attempt_preflight(1,str_repeat('a',32),array_replace($identity,['expected_caller_connection_id'=>7])),
        'preflight_nonstring_caller_is_controlled_rejection_not_typeerror');
    foreach(['owner','claim','stage','baseline','caller','run','state','scope','ceiling','history_count','history_unknown','history_hold_run','fencing','extra'] as $case) {
        [$pdo,$queue,$clock,$identity]=executionFixture();$args=executionPrepareArgs($queue,$identity);$admin=1;$connection=str_repeat('a',32);
        if($case==='owner') $admin=2;
        elseif($case==='claim') $args['claim_key']=str_repeat('f',64);
        elseif($case==='stage') $args['stage_id']=str_repeat('f',64);
        elseif($case==='baseline') $args['baseline_sha256']=str_repeat('f',64);
        elseif($case==='caller') $args['expected_caller_connection_id']=str_repeat('f',32);
        elseif($case==='run') $args['expected_current_run_id']='OTHER_RUN';
        elseif($case==='state') $args['expected_state_sha256']=str_repeat('f',64);
        elseif($case==='scope') $args['scope_sha256']='not-a-digest';
        elseif($case==='ceiling') $args['dispatch_ceiling']=4;
        elseif($case==='history_count') array_pop($args['prior_attempts']);
        elseif($case==='history_unknown') $args['prior_attempts'][0]['status']='canceled';
        elseif($case==='history_hold_run') $args['prior_attempts'][1]['run_id']='UNRELATED';
        elseif($case==='fencing') $args['fencing_status']='host_verified_safe';
        else $args['host_verified']=true;
        executionSameSnapshotReject($pdo,fn()=>$queue->prepare_execution_attempt($admin,$connection,$args),'prepare_rejects_'.$case);
    }
    foreach(['attempt_token','scope_sha256','operation_id','prompt_readback_sha256','assignment_readback_sha256'] as $field) {
        [$pdo,$queue,$clock,$identity,$claim,$args]=executionPrepared();$entry=executionEnterArgs($args);$entry[$field]=$field==='operation_id'?'OTHER_OPERATION':str_repeat('f',64);
        executionSameSnapshotReject($pdo,fn()=>$queue->enter_execution_attempt(1,str_repeat('a',32),$entry),'entry_rejects_changed_'.$field);
        $changed=$args;$changed[$field]=$entry[$field];executionSameSnapshotReject($pdo,fn()=>$queue->prepare_execution_attempt(1,str_repeat('a',32),$changed),'changed_permit_repeat_'.$field);
    }
    [$pdo,$queue,$clock,$identity,$claim,$args]=executionPrepared();
    $notEntered=executionBindArgs($queue,$identity,$args,['entry_sha256'=>str_repeat('e',64)]);
    executionSameSnapshotReject($pdo,fn()=>$queue->bind_execution_attempt(1,str_repeat('a',32),$notEntered),'producer_binding_requires_actual_entry');
    executionSameSnapshotReject($pdo,fn()=>$queue->enter_execution_attempt(2,str_repeat('a',32),executionEnterArgs($args)),'entry_rejects_foreign_admin');
    executionSameSnapshotReject($pdo,fn()=>$queue->enter_execution_attempt(1,str_repeat('f',32),executionEnterArgs($args)),'entry_rejects_foreign_connection');
    executionSameSnapshotReject($pdo,fn()=>$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args,'invalid-instance')),'entry_rejects_malformed_instance');
    [$pdo,$queue,$clock,$identity,$claim,$args]=executionPrepared();$clock->at+=86400;
    executionSameSnapshotReject($pdo,fn()=>$queue->prepare_execution_attempt(1,str_repeat('a',32),array_replace($args,['attempt_token'=>str_repeat('f',64)])),'unknown_or_elapsed_time_never_refunds_prepared_unit');
    executionCheck($queue->prepare_execution_attempt(1,str_repeat('a',32),$args)['work_authorized']===false,'lost_prepare_ack_reconciles_same_token_without_dispatch');
    $entry=$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args));$consumed=executionSnapshot($pdo);$clock->at+=86400;
    executionCheck($queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args))['work_authorized']===false&&$consumed===executionSnapshot($pdo),'lost_entry_ack_or_hold_never_reauthorizes_same_instance');
    foreach(['instance_id','entry_sha256','packet_sha256','producer_run_id','state','claim','native_result'] as $case) {
        [$pdo,$queue,$clock,$identity,$claim,$args,$permit,$entry]=executionEntered();$bind=executionBindArgs($queue,$identity,$args,$entry);
        if($case==='instance_id'||$case==='entry_sha256') $bind[$case]=str_repeat('f',64);
        elseif($case==='packet_sha256') $bind[$case]='invalid';
        elseif($case==='producer_run_id') $bind[$case]='CANONICAL_HOLD2';
        elseif($case==='state') $bind['expected_state_sha256']=str_repeat('f',64);
        elseif($case==='claim') $bind['claim_key']=str_repeat('f',64);
        else $bind['native_result_readback_sha256']='invalid';
        executionSameSnapshotReject($pdo,fn()=>$queue->bind_execution_attempt(1,str_repeat('a',32),$bind),'binding_rejects_'.$case);
    }
    foreach(['run','packet','association','unknown','legacy'] as $case) {
        [$pdo,$queue,$clock,$identity,$claim,$args,$permit,$entry,$bind,$association]=executionBound();
        $completion=$claim+['producer_association_sha256'=>$association['producer_association_sha256'],'receipt'=>executionReceipt()];
        if($case==='run') $completion['receipt']['run_id']='OTHER_PRODUCER4';
        elseif($case==='packet') $completion['receipt']['packet_sha256']=str_repeat('6',64);
        elseif($case==='association') $completion['producer_association_sha256']=str_repeat('f',64);
        elseif($case==='unknown') $completion['receipt']['outcome']='unknown';
        else {unset($completion['producer_association_sha256']);$completion['receipt']['run_id']='CANONICAL_HOLD2';}
        executionSameSnapshotReject($pdo,fn()=>$queue->complete(1,str_repeat('a',32),$completion),'completion_rejects_'.$case.'_without_relabel_or_refund');
    }
    [$pdo,$queue,$clock,$identity,$claim,$args,$permit,$entry,$bind]=executionBound();
    executionSameSnapshotReject($pdo,fn()=>$queue->bind_execution_attempt(1,str_repeat('a',32),array_replace($bind,['producer_run_id'=>'OTHER_PRODUCER4'])),'binding_conflict_cannot_add_fourth_producer');
}

function executionLateUnknownProducerChecks(): void {
    [$pdo,$queue,$clock,$identity,$claim]=executionFixture();$args=executionPrepareArgs($queue,$identity);
    $args['prior_attempts'][0]['run_id']='KNOWN_PREVIOUS_UNKNOWN_RUN';
    $queue->prepare_execution_attempt(1,str_repeat('a',32),$args);
    $entry=$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args));
    $bind=executionBindArgs($queue,$identity,$args,$entry);$bind['producer_run_id']='KNOWN_PREVIOUS_UNKNOWN_RUN';
    executionSameSnapshotReject($pdo,fn()=>$queue->bind_execution_attempt(2,str_repeat('a',32),$bind),'binding_rejects_foreign_admin');
    executionSameSnapshotReject($pdo,fn()=>$queue->bind_execution_attempt(1,str_repeat('f',32),$bind),'binding_rejects_foreign_connection');
    $associated=$queue->bind_execution_attempt(1,str_repeat('a',32),$bind);
    $queue->complete(1,str_repeat('a',32),$claim+['producer_association_sha256'=>$associated['producer_association_sha256'],
        'receipt'=>executionReceipt('KNOWN_PREVIOUS_UNKNOWN_RUN')]);
    $audit=json_decode($pdo->query('SELECT audit_json FROM hotel_work_execution_attempts')->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $receipt=json_decode($pdo->query('SELECT receipt_json FROM hotel_work_receipts')->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
    executionCheck($audit['request']['prior_attempts'][0]['status']==='unknown'
        &&$receipt['run_id']==='KNOWN_PREVIOUS_UNKNOWN_RUN','late_previous_unknown_run_can_be_true_token_producer_without_resolving_request_origin');
}

function executionAttemptStoredStateChecks(): void {
    // An actual intervening watchdog ACK changes the server pin even within the
    // same retained claim. A fake wrong hash alone would not cover this race.
    [$pdo,$queue,$clock,$identity]=executionFixture();$args=executionPrepareArgs($queue,$identity);
    $clock->at+=HotelWorkQueue::WATCHDOG_SECONDS;$attention=$queue->pendingEvent(1,str_repeat('a',32),'ATTEMPT');
    $queue->markEventDelivered(1,str_repeat('a',32),'ATTEMPT',$attention['event_id']);
    executionSameSnapshotReject($pdo,fn()=>$queue->prepare_execution_attempt(1,str_repeat('a',32),$args),'actual_server_watchdog_change_fences_stale_prepare_pin');
    [$pdo,$queue,$clock,$identity,$claim,$args]=executionPrepared();
    $oldPin=$queue->execution_attempt_preflight(1,str_repeat('a',32),$identity)['state_sha256'];
    $entry=$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args));$bind=executionBindArgs($queue,$identity,$args,$entry);
    executionSameSnapshotReject($pdo,fn()=>$queue->bind_execution_attempt(1,str_repeat('a',32),array_replace($bind,['expected_state_sha256'=>$oldPin])),'same_second_entry_change_fences_stale_bind_pin');
    foreach(['permit_audit','permit_token','entry_audit','association_audit','orphan_entry','orphan_association'] as $case) {
        if(in_array($case,['association_audit','orphan_association'],true)) [$pdo,$queue,$clock,$identity,$claim,$args]=executionBound();
        elseif(in_array($case,['entry_audit','orphan_entry'],true)) [$pdo,$queue,$clock,$identity,$claim,$args]=executionEntered();
        else [$pdo,$queue,$clock,$identity,$claim,$args]=executionPrepared();
        if($case==='permit_audit') $pdo->exec("UPDATE hotel_work_execution_attempts SET audit_json='{}'");
        elseif($case==='permit_token') $pdo->exec("UPDATE hotel_work_execution_attempts SET attempt_token='".str_repeat('f',64)."'");
        elseif($case==='entry_audit') $pdo->exec("UPDATE hotel_work_execution_attempt_entries SET audit_json='{}'");
        elseif($case==='association_audit') $pdo->exec("UPDATE hotel_work_execution_attempt_associations SET audit_json='{}'");
        elseif($case==='orphan_entry') $pdo->exec('DELETE FROM hotel_work_execution_attempts');
        else $pdo->exec('DELETE FROM hotel_work_execution_attempt_entries');
        $corrupt=executionSnapshot($pdo);$pre=$queue->execution_attempt_preflight(1,str_repeat('a',32),$identity);
        executionCheck($pre['eligible_to_prepare']===false&&$pre['eligible_to_bind']===false&&$pre['reason']==='execution_attempt_audit_invalid',
            $case.'_preflight_fails_closed_without_pins_or_host_truth');
        executionSameSnapshotReject($pdo,fn()=>$queue->prepare_execution_attempt(1,str_repeat('a',32),$args),$case.'_cannot_reissue_permit');
        executionCheck($corrupt===executionSnapshot($pdo),$case.'_is_preserved_without_autoheal');
    }
    // Recompute every affected digest, so this checks the stored-state shape
    // rather than merely detecting stale hashes. A null saved updated_at is
    // invalid even when all attacker-supplied metadata is self-consistent.
    [$pdo,$queue,$clock,$identity,$claim,$args]=executionPrepared();
    $row=$pdo->query('SELECT * FROM hotel_work_execution_attempts')->fetch(PDO::FETCH_ASSOC);
    $audit=json_decode($row['audit_json'],true,32,JSON_THROW_ON_ERROR);$audit['original_snapshot']['state']['delivery']['updated_at']=null;
    $audit['request']['expected_state_sha256']=hash('sha256',executionCanonical($audit['original_snapshot']));
    $audit['request_sha256']=hash('sha256',executionCanonical(['namespace'=>'hotel_execution_permit_v1','admin_id'=>1,
        'connection_id'=>str_repeat('a',32),'claim_sha256'=>$audit['claim_sha256'],'arguments'=>$audit['request']]));
    $auditJson=executionCanonical($audit);$permitHash=hash('sha256',$auditJson);
    $pdo->prepare('UPDATE hotel_work_execution_attempts SET permit_sha256=?,request_sha256=?,audit_json=? WHERE queue_id=?')
        ->execute([$permitHash,$audit['request_sha256'],$auditJson,'ATTEMPT']);
    $malformed=executionSnapshot($pdo);$pre=$queue->execution_attempt_preflight(1,str_repeat('a',32),$identity);
    executionCheck($pre['eligible_to_prepare']===false&&$pre['eligible_to_bind']===false&&$pre['reason']==='execution_attempt_audit_invalid',
        'self_consistent_hashes_cannot_legitimize_null_original_snapshot_timestamp');
    executionCheck($malformed===executionSnapshot($pdo),'malformed_rehashed_audit_preserved_for_reconciliation');
    [$pdo,$queue,$clock,$identity]=executionFixture();$args=executionPrepareArgs($queue,$identity);
    $other=$pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='ATTEMPT'")->fetch(PDO::FETCH_ASSOC);$other['queue_id']='OTHER_CLAIM';$other['current_run_id']='OTHER_ACTIVE_RUN';
    $pdo->prepare('INSERT INTO hotel_work_queues ('.implode(',',array_keys($other)).') VALUES ('.implode(',',array_fill(0,count($other),'?')).')')->execute(array_values($other));
    executionSameSnapshotReject($pdo,fn()=>$queue->prepare_execution_attempt(1,str_repeat('a',32),$args),'other_persisted_claim_prevents_extra_admission');
}

function executionAttemptRaceChecks(): void {
    foreach(['entry_1_first','entry_2_first','same_instance','binding_1_first','binding_2_first','actual_complete_repeat','actual_complete_conflict',
        'prepare_first','complete_first','permit_before_skip','skip_before_permit','permit_before_handoff','handoff_before_permit'] as $scenario) {
        $dir=sys_get_temp_dir().'/hotel-execution-race-'.bin2hex(random_bytes(8));if(!mkdir($dir,0700)) throw new RuntimeException('execution_race_directory_unavailable');
        $file=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/queue.sqlite';$processes=[];$pdo=null;$queue=null;
        try {
            [$pdo,$queue,$clock,$identity,$claim]=executionFixture($file==='MYSQL'?null:$file);$args=executionPrepareArgs($queue,$identity);
            $payload=['prepare'=>$args,'complete'=>$claim+['receipt'=>executionReceipt('CANONICAL_HOLD2')],
                'skip'=>['queue_id'=>'ATTEMPT','stage_id'=>$claim['stage_id'],'baseline_sha256'=>$identity['baseline_sha256'],
                    'old_connection_id'=>str_repeat('a',32),'expected_caller_connection_id'=>str_repeat('a',32),'expected_run_id'=>'CANONICAL_HOLD2',
                    'expected_unknown_receipt_sha256'=>null,'decision_sha256'=>str_repeat('3',64),
                    'external_effects'=>['status'=>'isolated','readback_sha256'=>str_repeat('4',64)]],
                'handoff'=>['queue_id'=>'ATTEMPT','stage_id'=>$claim['stage_id'],'baseline_sha256'=>$identity['baseline_sha256'],
                    'old_connection_id'=>str_repeat('a',32),'handoff_mode'=>'existing_run','claim_key'=>str_repeat('b',64),'expected_run_id'=>'CANONICAL_HOLD2']];
            $entries=str_starts_with($scenario,'entry_')||$scenario==='same_instance';
            $bindings=str_starts_with($scenario,'binding_');$completions=str_starts_with($scenario,'actual_complete_');
            if($entries) {$queue->prepare_execution_attempt(1,str_repeat('a',32),$args);$payload['enter']=executionEnterArgs($args);$actions=['enter-winner','enter'];}
            elseif($bindings||$completions) {
                $queue->prepare_execution_attempt(1,str_repeat('a',32),$args);$entry=$queue->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($args));
                $payload['bind']=executionBindArgs($queue,$identity,$args,$entry);
                if($bindings) $actions=['bind-winner','bind'];
                else {$association=$queue->bind_execution_attempt(1,str_repeat('a',32),$payload['bind']);
                    $payload['complete']=$claim+['producer_association_sha256'=>$association['producer_association_sha256'],'receipt'=>executionReceipt()];$actions=['complete-winner','complete'];}
            }
            else $actions=match($scenario){'prepare_first'=>['prepare-winner','complete'],'complete_first'=>['complete-winner','prepare'],
                'permit_before_skip'=>['prepare-winner','skip'],'skip_before_permit'=>['skip-winner','prepare'],
                'permit_before_handoff'=>['prepare-winner','handoff'],default=>['handoff-winner','prepare']};
            $before=executionSnapshot($pdo);
            foreach($actions as $i=>$action) {
                $child=$payload;
                if($entries) {$instance=$i===0?($scenario==='entry_2_first'?'f':'e'):($scenario==='entry_2_first'?'e':'f');if($scenario==='same_instance') $instance='e';$child['enter']=executionEnterArgs($args,str_repeat($instance,64));}
                if($bindings) $child['bind']['producer_run_id']=($i===0)===($scenario==='binding_1_first')?'ACTUAL_PRODUCER':'OTHER_PRODUCER4';
                if($completions&&$i===1&&$scenario==='actual_complete_conflict') $child['complete']['receipt']['readback_sha256']=str_repeat('6',64);
                file_put_contents($dir.'/args'.$i.'.json',json_encode($child,JSON_THROW_ON_ERROR));
                $command=[PHP_BINARY];$ini=php_ini_loaded_file();if(is_string($ini)&&$ini!=='') array_push($command,'-c',$ini);
                array_push($command,__FILE__,'--execution-attempt-child',$file,$dir.'/args'.$i.'.json',$action,$dir.'/out'.$i);
                $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if(!is_resource($process)) throw new RuntimeException('execution_race_process_unavailable');fclose($pipes[0]);$processes[]=[$process,$pipes];
            }
            executionWait($dir.'/out0.ready');executionWait($dir.'/out1.ready');file_put_contents($dir.'/out0.go','go');executionWait($dir.'/out0.locked');
            file_put_contents($dir.'/out1.go','go');executionWait($dir.'/out1.attempting');usleep(100000);
            executionCheck(!is_file($dir.'/out1')&&proc_get_status($processes[1][0])['running'],$scenario.'_contender_waits_on_actual_sql_lock');
            file_put_contents($dir.'/out0.release','release');executionWait($dir.'/out0');executionWait($dir.'/out1');
            foreach($processes as [$process,$pipes]) {$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);executionCheck(proc_close($process)===0&&$stdout===''&&$stderr==='',$scenario.'_children_exit_cleanly');}
            $first=json_decode((string)file_get_contents($dir.'/out0'),true,32,JSON_THROW_ON_ERROR);$second=json_decode((string)file_get_contents($dir.'/out1'),true,32,JSON_THROW_ON_ERROR);$after=executionSnapshot($pdo);
            executionCheck($first['ok']===true,$scenario.'_forced_winner_succeeds');
            if($entries) {
                executionCheck($first['result']['work_authorized']===true&&count($after['hotel_work_execution_attempt_entries'])===1,$scenario.'_one_instance_gets_only_first_work_authority');
                executionCheck($scenario==='same_instance'?($second['ok']===true&&$second['result']['work_authorized']===false):$second['ok']===false,$scenario.'_other_call_never_work_authorized');
                executionCheck(is_file($dir.'/out0.work')&&!is_file($dir.'/out1.work'),$scenario.'_nonwinner_holds_before_producer_effect');
                executionCheck($before['hotel_work_queues']===$after['hotel_work_queues']&&$after['hotel_work_receipts']===[],$scenario.'_no_native_binding_or_business_receipt_from_entry');
            }elseif($bindings) {
                executionCheck($second['ok']===false&&count($after['hotel_work_execution_attempt_associations'])===1
                    &&$before['hotel_work_queues']===$after['hotel_work_queues']&&$after['hotel_work_receipts']===[],$scenario.'_single_true_producer_association_no_canonical_rebind');
            }elseif($completions) {
                executionCheck(count($after['hotel_work_receipts'])===1&&(int)$after['hotel_work_queues'][0]['stage_index']===1
                    &&(int)$after['hotel_work_queues'][0]['accepted_batches']===0,$scenario.'_one_accepted_source_one_stage_advance');
                executionCheck($scenario==='actual_complete_repeat'?($second['ok']===true&&$second['result']['completion_status']==='already_recorded'):$second['ok']===false,
                    $scenario.'_repeat_or_conflict_never_second_acceptance');
            }elseif(in_array($scenario,['prepare_first','permit_before_skip','permit_before_handoff'],true)) {
                executionCheck($second['ok']===false&&count($after['hotel_work_execution_attempts'])===1&&$before['hotel_work_queues']===$after['hotel_work_queues'],$scenario.'_permit_fences_old_mutation');
                executionCheck($after['hotel_work_receipts']===[]&&$after['hotel_work_research_skips']===[]&&$after['hotel_work_connection_handoffs']===[],$scenario.'_no_competing_accepted_lineage_or_transfer');
            }else executionCheck($second['ok']===false&&$after['hotel_work_execution_attempts']===[],$scenario.'_old_mutation_winner_prevents_stale_permit');
        }finally {
            foreach($processes as [$process,$pipes]) {foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe);if(is_resource($process)){proc_terminate($process,9);proc_close($process);}}
            $queue=null;$pdo=null;gc_collect_cycles();foreach(glob($dir.'/*')?:[] as $path) unlink($path);rmdir($dir);
        }
    }
}

executionAttemptCoreChecks();executionAttemptInvalidChecks();executionLateUnknownProducerChecks();executionAttemptStoredStateChecks();executionAttemptRaceChecks();
echo 'HOTEL WORK EXECUTION ATTEMPT: '.$executionAttemptChecks." PASS\n";
