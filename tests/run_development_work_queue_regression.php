<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/DevelopmentWorkQueue.php';
require_once __DIR__.'/fixtures/development_work_queue.php';

function devSpec(string $id,array $deps=[],int $priority=10): array
{
    return ['task_id'=>$id,'owner_task_id'=>'owner-'.$id,'reviewer_task_id'=>'reviewer-'.$id,
        'input_sha256'=>hash('sha256','input-'.$id),'acceptance_contract_sha256'=>hash('sha256','acceptance-'.$id),
        'deps'=>$deps,'priority'=>$priority];
}
function devReceipt(string $run='RUN-A',string $outcome='accepted_readback'): array
{
    return ['run_id'=>$run,'outcome'=>$outcome,'result_sha256'=>str_repeat('1',64),
        'readback_sha256'=>str_repeat('2',64),'acceptance_sha256'=>$outcome==='terminal_blocked'?null:str_repeat('3',64),
        'reviewer_run_id'=>$outcome==='terminal_blocked'?null:'REVIEW-'.$run,'owned_claims_released'=>true];
}
function devRetireArgs(DevelopmentWorkQueue $q): array
{
    $s=devStatus($q);$event=$q->pendingEvent(1,str_repeat('a',32),'DEV');
    return ['queue_id'=>'DEV','task_id'=>$s['task_id'],'spec_sha256'=>$s['spec_sha256'],
        'expected_plan_sha256'=>$s['plan_sha256'],'expected_pending_event_id'=>$event['event_id'],
        'decision'=>['decision_sha256'=>str_repeat('4',64),'readback_sha256'=>str_repeat('5',64),
            'reviewer_run_id'=>'REVIEW-RETIRE-'.$s['task_id']]];
}
if (($argv[1]??'')==='--development-retire-race-child') {
    [$unused,$mode,$barrier,$out,$file,$action]=array_pad($argv,6,'');
    $pdo=new DevelopmentWorkTestPdo($file==='MYSQL'?null:$file,false);$hold=(object)['armed'=>false];
    $q=new DevelopmentWorkQueue($pdo,static function() use($out,$hold): int {
        if ($hold->armed) {
            $hold->armed=false;file_put_contents($out.'.locked','locked');$until=microtime(true)+10;
            while (!is_file($out.'.release')&&microtime(true)<$until) usleep(1000);
            if (!is_file($out.'.release')) throw new RuntimeException('retirement_race_release_timeout');
        }
        return 1793100000;
    });
    $s=$q->status(1,str_repeat('a',32),'DEV');
    if (file_put_contents($out.'.event',$s['expected_pending_event_id'])!==strlen($s['expected_pending_event_id']))
        throw new RuntimeException('retirement_race_metadata_write_failed');
    if (file_put_contents($out.'.ready.tmp',$s['task_id'])!==strlen($s['task_id'])
        ||!rename($out.'.ready.tmp',$out.'.ready')) throw new RuntimeException('retirement_race_ready_publish_failed');
    $until=microtime(true)+10;
    while (!is_file($barrier)&&microtime(true)<$until) usleep(1000);
    if (!is_file($barrier)) exit(3);
    file_put_contents($out.'.attempting','attempting');$hold->armed=str_ends_with($action,'-winner');
    $action=str_replace('-winner','',$action);
    try {
        if ($action==='block') {
            $q->retire_unclaimed(1,str_repeat('a',32),['queue_id'=>'DEV','task_id'=>$s['task_id'],'spec_sha256'=>$s['spec_sha256'],
                'expected_plan_sha256'=>$s['plan_sha256'],'expected_pending_event_id'=>$s['expected_pending_event_id'],
                'decision'=>['decision_sha256'=>str_repeat('4',64),'readback_sha256'=>str_repeat('5',64),
                    'reviewer_run_id'=>'REVIEW-RETIRE-'.$s['task_id']]]);
            $result='blocked';
        } elseif ($action==='claim') {
            $result=$q->claim(1,str_repeat('a',32),['queue_id'=>'DEV','task_id'=>$s['task_id'],'spec_sha256'=>$s['spec_sha256'],
                'claim_key'=>str_repeat('b',64),'expected_pending_event_id'=>$s['expected_pending_event_id']])['claim_status'];
        } elseif ($action==='ack') {
            $q->markEventDelivered(1,str_repeat('a',32),'DEV',$s['expected_pending_event_id']);$result='acknowledged';
        } else exit(4);
    } catch (DevelopmentWorkQueueConflict $error) {$result='conflict';}
    catch (DomainException $error) {$result='access_denied';}
    file_put_contents($out,$result);exit(0);
}

if (($argv[1]??'')==='--development-race-child') {
    [$unused,$mode,$barrier,$out,$file,$action]=array_pad($argv,6,'');
    $pdo=new DevelopmentWorkTestPdo($file==='MYSQL'?null:$file,false);$hold=(object)['armed'=>false];
    $q=new DevelopmentWorkQueue($pdo,static function() use($out,$hold): int {
        if ($hold->armed) {
            $hold->armed=false;file_put_contents($out.'.locked','locked');$until=microtime(true)+10;
            while (!is_file($out.'.release')&&microtime(true)<$until) usleep(1000);
            if (!is_file($out.'.release')) throw new RuntimeException('development_race_release_timeout');
        }
        return 1793100000;
    });
    $s=$q->status(1,str_repeat('a',32),'DEV');
    if (file_put_contents($out.'.spec',$s['spec_sha256'])!==strlen($s['spec_sha256']))
        throw new RuntimeException('development_race_metadata_write_failed');
    $event=$s['expected_pending_event_id']??file_get_contents($barrier.'.event');
    if (!is_string($event)||file_put_contents($out.'.event',$event)!==strlen($event))
        throw new RuntimeException('development_race_metadata_write_failed');
    if (file_put_contents($out.'.ready.tmp',$s['task_id'])!==strlen($s['task_id'])
        ||!rename($out.'.ready.tmp',$out.'.ready')) throw new RuntimeException('development_race_ready_publish_failed');
    $until=microtime(true)+10;
    while (!is_file($barrier)&&microtime(true)<$until) usleep(1000);
    if (!is_file($barrier)) exit(3);
    file_put_contents($out.'.attempting','attempting');$hold->armed=str_ends_with($action,'-winner');
    $action=str_replace('-winner','',$action);
    try {
        $args=['queue_id'=>'DEV','task_id'=>$s['task_id'],'spec_sha256'=>$s['spec_sha256'],'claim_key'=>str_repeat($action==='claim-c'?'c':'b',64)];
        if (in_array($action,['claim-b','claim-c'],true)) {
            $result=$q->claim(1,str_repeat('a',32),$args+['expected_pending_event_id'=>$event])['claim_status'];
        } elseif ($action==='complete') {
            $q->complete(1,str_repeat('a',32),$args+['receipt'=>devReceipt()]);$result='completed';
        } elseif ($action==='ack') {
            $q->markEventDelivered(1,str_repeat('a',32),'DEV',$event);$result='acknowledged';
        } else exit(4);
    } catch (DevelopmentWorkQueueConflict $error) {$result='conflict';}
    catch (DomainException $error) {$result='access_denied';}
    file_put_contents($out,$result);exit(0);
}

$checks=0;
function devAssert(bool $ok,string $name): void { global $checks;++$checks;if (!$ok) throw new RuntimeException('development_assertion_failed:'.$name); }
function devReject(callable $operation,array|string $classes,string $name): void
{
    try {$operation();}
    catch (Throwable $error) {
        $ok=false;foreach ((array)$classes as $class) if ($error instanceof $class) $ok=true;
        devAssert($ok,$name);return;
    }
    devAssert(false,$name);
}
function devFixture(?array $tasks=null): array
{
    $pdo=new DevelopmentWorkTestPdo();$clock=(object)['at'=>1793100000];$q=new DevelopmentWorkQueue($pdo,static fn():int=>$clock->at);
    $tasks??=[devSpec('A'),devSpec('B',['A'],90),devSpec('C',[],5)];
    $args=['queue_id'=>'DEV','plan_sha256'=>str_repeat('0',64),'tasks'=>$tasks];
    $q->create(1,str_repeat('a',32),$args);return [$pdo,$q,$clock,$args];
}
function devStatus(DevelopmentWorkQueue $q): array { return $q->status(1,str_repeat('a',32),'DEV'); }
function devClaim(DevelopmentWorkQueue $q,string $key='b'): array
{
    $s=devStatus($q);$event=$q->pendingEvent(1,str_repeat('a',32),'DEV');
    $args=['queue_id'=>'DEV','task_id'=>$s['task_id'],'spec_sha256'=>$s['spec_sha256'],'claim_key'=>str_repeat($key,64)];
    $q->claim(1,str_repeat('a',32),$args+['expected_pending_event_id'=>$event['event_id']]);return $args;
}
function devStart(DevelopmentWorkQueue $q,string $run='RUN-A'): array
{
    $args=devClaim($q);$q->started(1,str_repeat('a',32),$args+['run_id'=>$run]);return $args;
}

[$pdo,$q,$clock,$create]=devFixture();$s=devStatus($q);
devAssert(DevelopmentWorkTestSchema::$lastProof['applies']===2&&DevelopmentWorkTestSchema::$lastProof['statements']>=6,
    'actual_forward_migration_applied_and_reapplied_in_native_fixture');
devAssert($s['task_id']==='A'&&$s['state']==='ready'&&$s['task_count']===3&&$s['accepted_count']===0
    &&$s['owner_task_id']==='owner-A'&&$s['reviewer_task_id']==='reviewer-A','highest_priority_dependency_satisfied_task_selected');
devAssert(!array_key_exists('claim_sha256',$s)&&!str_contains(json_encode($s,JSON_THROW_ON_ERROR),str_repeat('b',64)),
    'status_never_exposes_claim_key_or_digest');
$before=$pdo->query('SELECT * FROM development_work_queues')->fetchAll(PDO::FETCH_ASSOC);
$beforeItems=$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC);$writes=$pdo->writes;
devStatus($q);devStatus($q);
devAssert($writes===$pdo->writes&&$before===$pdo->query('SELECT * FROM development_work_queues')->fetchAll(PDO::FETCH_ASSOC),
    'status_is_read_only_with_no_implicit_schema_or_admission_write');
DevelopmentWorkTestSchema::apply($pdo);
devAssert($before===$pdo->query('SELECT * FROM development_work_queues')->fetchAll(PDO::FETCH_ASSOC)
    &&$beforeItems===$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC),
    'forward_migration_reapply_preserves_existing_plan_items_and_lane');
devAssert($q->create(1,str_repeat('a',32),$create)['create_status']==='already_recorded','identical_create_is_idempotent');
devReject(fn()=>$q->create(1,str_repeat('a',32),array_replace($create,['plan_sha256'=>str_repeat('9',64)])),DevelopmentWorkQueueConflict::class,
    'same_queue_cannot_be_redefined_by_create');
foreach ([[2,str_repeat('a',32)],[1,str_repeat('f',32)]] as [$admin,$connection]) {
    devReject(fn()=>$q->status($admin,$connection,'DEV'),DomainException::class,'foreign_owner_or_binding_cannot_read_development_queue');
}
devReject(fn()=>$q->create(1,str_repeat('a',32),['queue_id'=>'OTHER','plan_sha256'=>str_repeat('0',64),'tasks'=>[devSpec('OTHER')]]),
    DevelopmentWorkQueueConflict::class,'one_development_lane_has_one_active_queue');
$invalidSpecs=[
    [devSpec('A'),devSpec('A')],[devSpec('A',['A'])],[devSpec('A',['MISSING'])],[devSpec('B',['A']),devSpec('A')],
    [array_replace(devSpec('A'),['priority'=>101])],[array_replace(devSpec('A'),['priority'=>true])],
    [array_replace(devSpec('A'),['input_sha256'=>'not-a-sha'])],[array_replace(devSpec('A'),['reviewer_task_id'=>'owner-A'])],
    [devSpec('A')+['dispatch_allowed'=>true]],[array_replace(devSpec('A'),['deps'=>['B','B']])]
];
foreach ($invalidSpecs as $tasks) {
    $testPdo=new DevelopmentWorkTestPdo();$bad=new DevelopmentWorkQueue($testPdo,static fn():int=>1793100000);
    devReject(fn()=>$bad->create(1,str_repeat('a',32),['queue_id'=>'BAD','plan_sha256'=>str_repeat('0',64),'tasks'=>$tasks]),
        InvalidArgumentException::class,'malformed_or_non_prior_dependency_plan_is_rejected');
    devAssert((int)$testPdo->query('SELECT COUNT(*) FROM development_work_queues')->fetchColumn()===0,
        'invalid_plan_never_partially_writes_queue_or_lane');
}

[$pdo,$q,$clock]=devFixture();$s=devStatus($q);$event=$q->pendingEvent(1,str_repeat('a',32),'DEV');
devAssert($event['name']==='development_work.pending'&&$event['data']===['queue_id'=>'DEV','task_id'=>'A',
    'spec_sha256'=>$s['spec_sha256'],'owner_task_id'=>'owner-A','dispatch_allowed'=>true],'development_event_exact_metadata_not_hotel_packet');
$args=['queue_id'=>'DEV','task_id'=>'A','spec_sha256'=>$s['spec_sha256'],'claim_key'=>str_repeat('b',64)];
devReject(fn()=>$q->claim(1,str_repeat('a',32),$args),[InvalidArgumentException::class,DevelopmentWorkQueueConflict::class],
    'every_development_claim_requires_received_event_identity');
foreach (['hotel_evt_'.str_repeat('1',64),'dev_attention_'.str_repeat('1',64),'dev_evt_'.str_repeat('1',64)] as $wrong) {
    devReject(fn()=>$q->claim(1,str_repeat('a',32),$args+['expected_pending_event_id'=>$wrong]),
        [InvalidArgumentException::class,DevelopmentWorkQueueConflict::class],'foreign_attention_or_stale_event_never_admits_development_work');
}
$q->markEventDelivered(1,str_repeat('a',32),'DEV',$event['event_id']);$clock->at+=179;
devAssert($q->pendingEvent(1,str_repeat('a',32),'DEV')===null&&devStatus($q)['accepted_count']===0,
    'transport_ack_is_not_worker_execution_or_acceptance');
$clock->at++;$attention=$q->pendingEvent(1,str_repeat('a',32),'DEV');
devAssert($attention['name']==='development_work.attention'&&$attention['data']['dispatch_allowed']===false,
    'unclaimed_attention_never_dispatches');
$q->markEventDelivered(1,str_repeat('a',32),'DEV',$attention['event_id']);$clock->at+=3600;
devAssert($q->pendingEvent(1,str_repeat('a',32),'DEV')===null,'acknowledged_attention_does_not_loop_model_wakeups');

[$pdo,$q,$clock]=devFixture();$s=devStatus($q);$event=$q->pendingEvent(1,str_repeat('a',32),'DEV');
$args=['queue_id'=>'DEV','task_id'=>'A','spec_sha256'=>$s['spec_sha256'],'claim_key'=>str_repeat('b',64)];
$received=$args+['expected_pending_event_id'=>$event['event_id']];
devAssert($q->claim(1,str_repeat('a',32),$received)['claim_status']==='claimed','exact_fresh_event_admits_one_claim');
devAssert($q->claim(1,str_repeat('a',32),$received)['claim_status']==='already_claimed_do_not_replay','matching_claim_repeat_cannot_authorize_replay');
devReject(fn()=>$q->claim(1,str_repeat('a',32),$args),[InvalidArgumentException::class,DevelopmentWorkQueueConflict::class],
    'missing_event_proof_is_checked_before_claim_idempotence');
devReject(fn()=>$q->claim(1,str_repeat('a',32),array_replace($received,['expected_pending_event_id'=>'dev_evt_'.str_repeat('f',64)])),
    DevelopmentWorkQueueConflict::class,'stale_event_proof_is_checked_before_claim_idempotence');
devReject(fn()=>$q->claim(1,str_repeat('a',32),array_replace($received,['claim_key'=>str_repeat('c',64)])),
    DevelopmentWorkQueueConflict::class,'different_claim_key_cannot_take_over_existing_work');
devReject(fn()=>$q->complete(1,str_repeat('a',32),$args+['receipt'=>devReceipt()]),DevelopmentWorkQueueConflict::class,
    'accepted_receipt_cannot_invent_a_run_before_started');
$q->started(1,str_repeat('a',32),$args+['run_id'=>'RUN-A']);$bound=devStatus($q);
$q->started(1,str_repeat('a',32),$args+['run_id'=>'RUN-A']);
devAssert(devStatus($q)===$bound,'repeated_started_keeps_exact_original_run');
devReject(fn()=>$q->started(1,str_repeat('a',32),$args+['run_id'=>'REPLACEMENT']),DevelopmentWorkQueueConflict::class,'started_cannot_replace_bound_run');
foreach ([
    ['run_id'=>'DIFFERENT_RUN'],['result_sha256'=>null],['readback_sha256'=>null],['acceptance_sha256'=>null],
    ['reviewer_run_id'=>null],['reviewer_run_id'=>'RUN-A'],['owned_claims_released'=>false]
] as $bad) {
    devReject(fn()=>$q->complete(1,str_repeat('a',32),$args+['receipt'=>array_replace(devReceipt(),$bad)]),
        [InvalidArgumentException::class,DevelopmentWorkQueueConflict::class],'acceptance_requires_exact_run_all_evidence_independent_review_and_released_claims');
    devAssert(devStatus($q)['task_id']==='A'&&devStatus($q)['accepted_count']===0,
        'invalid_acceptance_never_satisfies_dependencies_or_advances');
}
$accepted=$q->complete(1,str_repeat('a',32),$args+['receipt'=>devReceipt()]);$next=devStatus($q);
devAssert($next['task_id']==='B'&&$next['accepted_count']===1&&$next['state']==='ready',
    'only_accepted_predecessor_unblocks_higher_priority_dependent');
$storedReceipt=$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC);
$readback=json_decode($storedReceipt['receipt_json'],true,16,JSON_THROW_ON_ERROR);$expected=devReceipt();ksort($readback);ksort($expected);
devAssert(hash('sha256',$storedReceipt['receipt_json'])===$storedReceipt['receipt_sha256']&&$readback===$expected,
    'actual_saved_receipt_readback_matches_the_known_original_evidence');
$q->complete(1,str_repeat('a',32),$args+['receipt'=>devReceipt()]);
devAssert(devStatus($q)===$next&&(int)$pdo->query('SELECT COUNT(*) FROM development_work_receipts')->fetchColumn()===1,
    'lost_completion_response_reconciles_without_second_acceptance_or_dispatch');
devReject(fn()=>$q->complete(1,str_repeat('a',32),$args+['receipt'=>array_replace(devReceipt(),['readback_sha256'=>str_repeat('f',64)])]),
    DevelopmentWorkQueueConflict::class,'conflicting_repeat_never_replaces_accepted_receipt');
devReject(fn()=>$q->markEventDelivered(1,str_repeat('a',32),'DEV',$event['event_id']),DevelopmentWorkQueueConflict::class,
    'old_ack_cannot_acknowledge_next_task_generation');
devReject(fn()=>$q->claim(1,str_repeat('a',32),$received),DevelopmentWorkQueueConflict::class,'late_old_task_claim_cannot_reach_next_generation');
$a=devStart($q,'RUN-B');$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt('RUN-B')]);
devAssert(devStatus($q)['task_id']==='C','remaining_independent_task_selected_after_dependent_acceptance');
$a=devStart($q,'RUN-C');$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt('RUN-C')]);
devAssert(devStatus($q)['state']==='complete'&&devStatus($q)['accepted_count']===3
    &&$pdo->query("SELECT queue_id FROM development_work_lane WHERE lane_key='DEVELOPMENT'")->fetchColumn()===null,
    'all_accepted_plan_finishes_and_releases_only_development_lane');

// A blocked predecessor satisfies no dependency; another independent task can proceed.
[$pdo,$q,$clock]=devFixture();$a=devStart($q);
$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt('RUN-A','terminal_blocked')]);
devAssert(devStatus($q)['task_id']==='C'&&devStatus($q)['blocked_count']===1&&devStatus($q)['accepted_count']===0,
    'terminal_blocked_does_not_satisfy_dependency_but_allows_independent_task');
$a=devStart($q,'RUN-C');$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt('RUN-C')]);
devAssert(devStatus($q)['state']==='blocked'&&devStatus($q)['task_id']===null
    &&devStatus($q)['accepted_count']===1&&$q->pendingEvent(1,str_repeat('a',32),'DEV')===null,
    'unsatisfied_dependency_remains_blocked_not_waived_or_replayed');
$append=['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('0',64),'plan_sha256'=>str_repeat('9',64),'tasks'=>[devSpec('D')]];
$q->append(1,str_repeat('a',32),$append);
devAssert(devStatus($q)['task_id']==='D'&&devStatus($q)['accepted_count']===1&&devStatus($q)['blocked_count']===1,
    'explicit_append_can_add_independent_work_without_rewriting_blocked_history');

[$pdo,$q,$clock,$create]=devFixture();$s=devStatus($q);$event=$q->pendingEvent(1,str_repeat('a',32),'DEV');
$initialItems=$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC);
$append=['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('0',64),'plan_sha256'=>str_repeat('9',64),
    'tasks'=>[devSpec('D',['B'],100),devSpec('E',[],100)]];
$q->append(1,str_repeat('a',32),$append);$afterAppend=devStatus($q);
devAssert($afterAppend['task_count']===5&&$afterAppend['task_id']===$s['task_id']&&$afterAppend['spec_sha256']===$s['spec_sha256']
    &&$afterAppend['expected_pending_event_id']===$event['event_id'],'append_preserves_current_task_spec_and_received_event_identity');
devAssert($initialItems===$pdo->query('SELECT * FROM development_work_items WHERE ordinal<3 ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC),
    'append_never_rewrites_existing_immutable_specs_or_states');
devAssert($q->append(1,str_repeat('a',32),$append)['append_status']==='already_recorded'&&devStatus($q)===$afterAppend,
    'same_append_intent_is_idempotent_after_plan_cas_commit');
devReject(fn()=>$q->append(1,str_repeat('a',32),array_replace($append,['plan_sha256'=>str_repeat('8',64),'tasks'=>[devSpec('F')]])),
    DevelopmentWorkQueueConflict::class,'stale_plan_cas_never_inserts_new_tasks');
devReject(fn()=>$q->append(1,str_repeat('a',32),array_replace($append,['expected_plan_sha256'=>str_repeat('9',64),'plan_sha256'=>str_repeat('8',64),
    'tasks'=>[array_replace(devSpec('A'),['input_sha256'=>str_repeat('f',64)])]])),
    [InvalidArgumentException::class,DevelopmentWorkQueueConflict::class],'append_cannot_redefine_existing_task_identity');
devAssert($q->create(1,str_repeat('a',32),$create)['create_status']==='already_recorded'&&devStatus($q)===$afterAppend,
    'late_original_create_does_not_roll_back_appended_plan');
$a=devStart($q);$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt()]);
devAssert(devStatus($q)['task_id']==='E','new_priority_is_considered_after_current_task_acceptance_only');

// Append idempotence is an exact current-revision intent, not a subset of old items.
[$pdo,$q,$clock]=devFixture([devSpec('A')]);
$append=['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('0',64),'plan_sha256'=>str_repeat('9',64),
    'tasks'=>[devSpec('D'),devSpec('E')]];
$q->append(1,str_repeat('a',32),$append);
$audit=$pdo->query('SELECT * FROM development_work_appends')->fetch(PDO::FETCH_ASSOC);
$last=devStatus($q)['last_append'];ksort($audit);ksort($last);
devAssert($audit===$last&&$last['expected_plan_sha256']===str_repeat('0',64)
    &&$last['plan_sha256']===str_repeat('9',64)&&(int)$last['task_count']===2,
    'current_append_readback_matches_exact_saved_batch_audit');
$beforeAppendQueue=$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC);
$beforeAppendItems=$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC);
$beforeAppendAudit=$pdo->query('SELECT * FROM development_work_appends ORDER BY plan_sha256')->fetchAll(PDO::FETCH_ASSOC);
foreach ([
    array_replace($append,['expected_plan_sha256'=>str_repeat('8',64)]),
    array_replace($append,['tasks'=>[devSpec('D')]]),
    array_replace($append,['tasks'=>[array_replace(devSpec('D'),['priority'=>11]),devSpec('E')]])
] as $changedIntent) {
    devReject(fn()=>$q->append(1,str_repeat('a',32),$changedIntent),DevelopmentWorkQueueConflict::class,
        'append_repeat_requires_exact_expected_revision_full_batch_and_task_definitions');
}
devAssert($beforeAppendQueue===$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC)
    &&$beforeAppendItems===$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC)
    &&$beforeAppendAudit===$pdo->query('SELECT * FROM development_work_appends ORDER BY plan_sha256')->fetchAll(PDO::FETCH_ASSOC),
    'mismatched_append_retries_change_no_plan_items_or_immutable_audit');
devAssert($q->append(1,str_repeat('a',32),$append)['append_status']==='already_recorded'
    &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_appends')->fetchColumn()===1,
    'exact_current_append_retry_reads_one_existing_audit_without_another_insert');
$q->append(1,str_repeat('a',32),['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('9',64),
    'plan_sha256'=>str_repeat('8',64),'tasks'=>[devSpec('F')]]);
devAssert((devStatus($q)['last_append']['plan_sha256']??null)===str_repeat('8',64),
    'latest_append_follows_current_revision_when_wall_clock_timestamps_are_equal');
$beforeAppendQueue=$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC);
$beforeAppendItems=$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC);
$beforeAppendAudit=$pdo->query('SELECT * FROM development_work_appends ORDER BY plan_sha256')->fetchAll(PDO::FETCH_ASSOC);
foreach ([$append,
    ['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('8',64),'plan_sha256'=>str_repeat('9',64),'tasks'=>[devSpec('G')]],
    ['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('8',64),'plan_sha256'=>str_repeat('0',64),'tasks'=>[devSpec('G')]]
] as $reusedRevision) {
    devReject(fn()=>$q->append(1,str_repeat('a',32),$reusedRevision),DevelopmentWorkQueueConflict::class,
        'historical_append_or_initial_revision_cannot_be_reused_for_current_work');
}
devAssert($beforeAppendQueue===$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC)
    &&$beforeAppendItems===$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC)
    &&$beforeAppendAudit===$pdo->query('SELECT * FROM development_work_appends ORDER BY plan_sha256')->fetchAll(PDO::FETCH_ASSOC),
    'historical_revision_rejection_preserves_current_plan_and_all_audit_history');
$insertAttempts=0;
$pdo->beforeExecute=static function(string $sql,?array $params) use (&$insertAttempts): void {
    if (preg_match('/\A\s*INSERT\s+INTO\s+development_work_items\b/i',$sql)&&++$insertAttempts===2) {
        throw new RuntimeException('synthetic_second_item_insert_failure');
    }
};
try {
    devReject(fn()=>$q->append(1,str_repeat('a',32),['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('8',64),
        'plan_sha256'=>str_repeat('7',64),'tasks'=>[devSpec('G'),devSpec('H')]]),RuntimeException::class,
        'mid_batch_insert_failure_is_not_reported_as_an_appended_plan');
} finally {$pdo->beforeExecute=null;}
devAssert($insertAttempts===2&&$beforeAppendQueue===$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC)
    &&$beforeAppendItems===$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC)
    &&$beforeAppendAudit===$pdo->query('SELECT * FROM development_work_appends ORDER BY plan_sha256')->fetchAll(PDO::FETCH_ASSOC),
    'append_transaction_rolls_back_first_item_revision_and_audit_when_second_insert_fails');
DevelopmentWorkTestSchema::apply($pdo);$writes=$pdo->writes;$last=devStatus($q)['last_append'];devStatus($q);
devAssert($beforeAppendAudit===$pdo->query('SELECT * FROM development_work_appends ORDER BY plan_sha256')->fetchAll(PDO::FETCH_ASSOC)
    &&$last['plan_sha256']===str_repeat('8',64)&&$writes===$pdo->writes,
    'append_audit_survives_migration_reapply_and_read_only_unknown_response_reconciliation');

// UNKNOWN is held, including across time and an attempted plan update. Resolve only its original run.
[$pdo,$q,$clock]=devFixture();$a=devStart($q,'ORIGINAL');
$unknown=['run_id'=>'ORIGINAL','outcome'=>'unknown','result_sha256'=>str_repeat('1',64),'readback_sha256'=>null,
    'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>false];
$q->complete(1,str_repeat('a',32),$a+['receipt'=>$unknown]);$held=devStatus($q);
$original=$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC);
devAssert($held['state']==='unknown'&&$held['current_run_id']==='ORIGINAL'&&$held['unknown_receipt_sha256']===$original['receipt_sha256'],
    'unknown_exposes_exact_original_receipt_identity_without_authorizing_retry');
$clock->at+=3600;$attention=$q->pendingEvent(1,str_repeat('a',32),'DEV');
devAssert($attention['name']==='development_work.attention'&&$attention['data']['dispatch_allowed']===false
    &&devStatus($q)['task_id']==='A'&&devStatus($q)['accepted_count']===0,'unknown_age_only_watches_original_run_without_advancing');
$q->markEventDelivered(1,str_repeat('a',32),'DEV',$attention['event_id']);
try {$q->append(1,str_repeat('a',32),['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('0',64),
    'plan_sha256'=>str_repeat('9',64),'tasks'=>[devSpec('D')]]);} catch (DevelopmentWorkQueueConflict $expectedConflict) {}
devAssert(devStatus($q)['state']==='unknown'&&devStatus($q)['task_id']==='A'&&devStatus($q)['current_run_id']==='ORIGINAL',
    'plan_append_never_releases_or_restarts_unknown_work');
$resolve=$a+['unknown_receipt_sha256'=>$original['receipt_sha256'],'expected_run_id'=>'ORIGINAL','receipt'=>devReceipt('ORIGINAL')];
foreach ([
    ['unknown_receipt_sha256'=>str_repeat('f',64)],['expected_run_id'=>'REPLACEMENT'],['claim_key'=>str_repeat('c',64)],
    ['receipt'=>array_replace(devReceipt('ORIGINAL'),['result_sha256'=>str_repeat('f',64)])]
] as $bad) {
    devReject(fn()=>$q->resolve_existing_run(1,str_repeat('a',32),array_replace($resolve,$bad)),
        [InvalidArgumentException::class,DevelopmentWorkQueueConflict::class],'resolution_requires_original_unknown_hash_key_run_and_result');
}
$q->resolve_existing_run(1,str_repeat('a',32),$resolve);$resolved=devStatus($q);
devAssert($resolved['state']==='ready'&&$resolved['task_id']==='B'&&$resolved['accepted_count']===1
    &&$original===$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC)
    &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_resolutions')->fetchColumn()===1,
    'verified_original_run_resolution_advances_once_preserving_original_unknown_receipt');
$q->resolve_existing_run(1,str_repeat('a',32),$resolve);
devAssert(devStatus($q)===$resolved,'identical_resolution_repeat_never_advances_or_dispatches_again');

[$pdo,$q,$clock]=devFixture();$a=devClaim($q);
$unknown=['run_id'=>null,'outcome'=>'unknown','result_sha256'=>null,'readback_sha256'=>null,
    'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>false];
$q->complete(1,str_repeat('a',32),$a+['receipt'=>$unknown]);$s=devStatus($q);
devReject(fn()=>$q->resolve_existing_run(1,str_repeat('a',32),$a+['unknown_receipt_sha256'=>$s['unknown_receipt_sha256'],
    'expected_run_id'=>'GUESSED','receipt'=>devReceipt('GUESSED')]),DevelopmentWorkQueueConflict::class,
    'unknown_without_a_bound_run_cannot_adopt_an_invented_run');

// An exact original UNKNOWN run may prove a terminal block without accepting its dependencies.
[$pdo,$q,$clock]=devFixture();$args=devStart($q,'ORIGINAL-BLOCKED');
$unknown=['run_id'=>'ORIGINAL-BLOCKED','outcome'=>'unknown','result_sha256'=>str_repeat('1',64),
    'readback_sha256'=>null,'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>false];
$q->complete(1,str_repeat('a',32),$args+['receipt'=>$unknown]);$held=devStatus($q);
$originalUnknown=$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC);
$blockedReceipt=devReceipt('ORIGINAL-BLOCKED','terminal_blocked');
$resolve=$args+['unknown_receipt_sha256'=>$held['unknown_receipt_sha256'],'expected_run_id'=>'ORIGINAL-BLOCKED','receipt'=>$blockedReceipt];
foreach ([
    ['unknown_receipt_sha256'=>str_repeat('f',64)],['expected_run_id'=>'REPLACEMENT'],['claim_key'=>str_repeat('c',64)],
    ['spec_sha256'=>str_repeat('f',64)],['receipt'=>array_replace($blockedReceipt,['run_id'=>'DIFFERENT-RUN'])],
    ['receipt'=>array_replace($blockedReceipt,['result_sha256'=>str_repeat('f',64)])],
    ['receipt'=>array_replace($blockedReceipt,['readback_sha256'=>null])],
    ['receipt'=>array_replace($blockedReceipt,['reviewer_run_id'=>'UNUSED-REVIEW'])],
    ['receipt'=>array_replace($blockedReceipt,['acceptance_sha256'=>str_repeat('3',64)])],
    ['receipt'=>array_replace($blockedReceipt,['owned_claims_released'=>false])],
    ['receipt'=>array_replace($blockedReceipt,['outcome'=>'unknown'])]
] as $bad) {
    devReject(fn()=>$q->resolve_existing_run(1,str_repeat('a',32),array_replace($resolve,$bad)),
        [InvalidArgumentException::class,DevelopmentWorkQueueConflict::class],
        'blocked_resolution_requires_exact_retained_run_spec_hash_key_result_and_released_claims');
    devAssert(devStatus($q)['state']==='unknown'&&devStatus($q)['task_id']==='A'
        &&devStatus($q)['accepted_count']===0&&devStatus($q)['blocked_count']===0
        &&$originalUnknown===$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC)
        &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_resolutions')->fetchColumn()===0,
        'invalid_blocked_resolution_never_rewrites_unknown_or_satisfies_a_dependency');
}
$q->resolve_existing_run(1,str_repeat('a',32),$resolve);$afterBlocked=devStatus($q);
$storedBlocked=$pdo->query("SELECT * FROM development_work_resolutions WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC);
$readback=json_decode($storedBlocked['receipt_json'],true,16,JSON_THROW_ON_ERROR);$expected=$blockedReceipt;ksort($readback);ksort($expected);
devAssert($afterBlocked['state']==='ready'&&$afterBlocked['task_id']==='C'
    &&$afterBlocked['accepted_count']===0&&$afterBlocked['blocked_count']===1
    &&($afterBlocked['last_resolution']['task_id']??null)==='A'
    &&$originalUnknown===$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC)
    &&$readback===$expected&&hash('sha256',$storedBlocked['receipt_json'])===$storedBlocked['receipt_sha256'],
    'verified_blocked_original_run_preserves_unknown_and_selects_only_independent_work');
$q->resolve_existing_run(1,str_repeat('a',32),$resolve);
devAssert(devStatus($q)===$afterBlocked
    &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_resolutions')->fetchColumn()===1,
    'identical_blocked_resolution_never_double_advances_or_counts_as_acceptance');
$independent=devStart($q,'INDEPENDENT-C');$q->complete(1,str_repeat('a',32),$independent+['receipt'=>devReceipt('INDEPENDENT-C')]);
$dependentAfterBlocked=$pdo->query("SELECT state,claim_sha256,current_run_id FROM development_work_items WHERE task_id='B'")->fetch(PDO::FETCH_ASSOC);
devAssert(devStatus($q)['state']==='blocked'&&devStatus($q)['task_id']===null
    &&devStatus($q)['accepted_count']===1&&devStatus($q)['blocked_count']===1
    &&$q->pendingEvent(1,str_repeat('a',32),'DEV')===null
    &&in_array($dependentAfterBlocked['state'],['pending','blocked'],true)&&$dependentAfterBlocked['claim_sha256']===null
    &&$dependentAfterBlocked['current_run_id']===null
    &&(int)$pdo->query("SELECT COUNT(*) FROM development_work_receipts WHERE task_id='B'")->fetchColumn()===0,
    'terminal_blocked_resolution_does_not_unblock_its_dependent_after_other_work_is_accepted');
// Even a forged accepted item label cannot turn a stored blocked resolution into acceptance.
$pdo->exec("UPDATE development_work_items SET state='accepted' WHERE task_id='A'");
$beforeBlockedPlan=$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC);
devReject(fn()=>$q->append(1,str_repeat('a',32),['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('0',64),
    'plan_sha256'=>str_repeat('9',64),'tasks'=>[devSpec('D',['A'])]]),RuntimeException::class,
    'blocked_resolution_cannot_satisfy_accepted_dependency_despite_forged_item_label');
devAssert($beforeBlockedPlan===$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC)
    &&(int)$pdo->query("SELECT COUNT(*) FROM development_work_items WHERE task_id='D'")->fetchColumn()===0
    &&$storedBlocked===$pdo->query("SELECT * FROM development_work_resolutions WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC)
    &&$originalUnknown===$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC),
    'forged_acceptance_does_not_change_blocked_evidence_plan_or_dependency_admission');

// Receipt readback must follow insertion order, not task ordinal or equal wall clocks.
[$pdo,$q,$clock]=devFixture([devSpec('A',[],1),devSpec('B',[],90)]);$b=devStart($q,'RUN-B');
$q->complete(1,str_repeat('a',32),$b+['receipt'=>devReceipt('RUN-B')]);$a=devStart($q,'RUN-A');
$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt('RUN-A')]);$s=devStatus($q);
$late=$q->complete(1,str_repeat('a',32),$b+['receipt'=>devReceipt('RUN-B')]);
devAssert(($s['last_receipt']['task_id']??null)==='A'&&($late['recorded_receipt']['task_id']??null)==='B'
    &&(devStatus($q)['last_receipt']['task_id']??null)==='A','late_idempotent_reply_reads_requested_receipt_without_replacing_actual_latest_receipt');
$before=$pdo->query('SELECT * FROM development_work_receipts ORDER BY task_id')->fetchAll(PDO::FETCH_ASSOC);$writes=$pdo->writes;
devStatus($q);
devAssert($writes===$pdo->writes&&$before===$pdo->query('SELECT * FROM development_work_receipts ORDER BY task_id')->fetchAll(PDO::FETCH_ASSOC),
    'last_known_receipt_readback_is_read_only');

[$pdo,$q,$clock]=devFixture([devSpec('1'),devSpec('2',['1'])]);
devAssert(devStatus($q)['task_id']==='1','numeric_task_id_stays_a_string');
$a=devStart($q);$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt()]);
devAssert(devStatus($q)['task_id']==='2'&&devStatus($q)['deps']===['1'],'numeric_dependency_identity_survives_php_array_keys_and_sql');
devAssert(!array_diff(array_unique($pdo->writes),DevelopmentWorkTestSchema::tables()),'queue_never_writes_hotel_reply_or_business_tables');

// A dependency label alone is not acceptance. Keep the latest receipt valid while
// corrupting an earlier dependency's evidence, so the dependency check is exercised.
foreach (['missing','bad_hash','wrong_spec','blocked_receipt','changed_contract'] as $corrupt) {
    [$pdo,$q,$clock]=devFixture([devSpec('A',[],100),devSpec('C',[],90)]);
    $a=devStart($q,'RUN-A');$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt('RUN-A')]);
    $c=devStart($q,'RUN-C');$q->complete(1,str_repeat('a',32),$c+['receipt'=>devReceipt('RUN-C')]);
    if ($corrupt==='missing') $pdo->exec("DELETE FROM development_work_receipts WHERE task_id='A'");
    elseif ($corrupt==='changed_contract') $pdo->prepare("UPDATE development_work_items SET acceptance_contract_sha256=? WHERE task_id='A'")->execute([str_repeat('f',64)]);
    elseif ($corrupt==='wrong_spec') $pdo->prepare("UPDATE development_work_receipts SET spec_sha256=? WHERE task_id='A'")->execute([str_repeat('f',64)]);
    elseif ($corrupt==='bad_hash') $pdo->prepare("UPDATE development_work_receipts SET receipt_sha256=? WHERE task_id='A'")->execute([str_repeat('f',64)]);
    else {
        $receipt=devReceipt('RUN-A','terminal_blocked');ksort($receipt);$json=json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $pdo->prepare("UPDATE development_work_receipts SET receipt_json=?,receipt_sha256=? WHERE task_id='A'")->execute([$json,hash('sha256',$json)]);
    }
    $beforeQueue=$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC);
    devReject(fn()=>$q->append(1,str_repeat('a',32),['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('0',64),
        'plan_sha256'=>str_repeat('9',64),'tasks'=>[devSpec('B',['A'])]]),RuntimeException::class,
        'accepted_dependency_requires_actual_matching_uncorrupted_acceptance_evidence');
    devAssert($beforeQueue===$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC)
        &&(int)$pdo->query("SELECT COUNT(*) FROM development_work_items WHERE task_id='B'")->fetchColumn()===0,
        'corrupt_dependency_evidence_rolls_back_append_without_admission');
}
foreach (['missing_resolution','wrong_unknown_link'] as $corrupt) {
    [$pdo,$q,$clock]=devFixture([devSpec('A',[],100),devSpec('C',[],90)]);$a=devStart($q,'RUN-A');
    $unknown=devReceipt('RUN-A');$unknown['outcome']='unknown';$unknown['readback_sha256']=null;
    $unknown['acceptance_sha256']=null;$unknown['reviewer_run_id']=null;$unknown['owned_claims_released']=false;
    $q->complete(1,str_repeat('a',32),$a+['receipt'=>$unknown]);$held=devStatus($q);
    $q->resolve_existing_run(1,str_repeat('a',32),$a+['unknown_receipt_sha256'=>$held['unknown_receipt_sha256'],
        'expected_run_id'=>'RUN-A','receipt'=>devReceipt('RUN-A')]);
    $c=devStart($q,'RUN-C');$q->complete(1,str_repeat('a',32),$c+['receipt'=>devReceipt('RUN-C')]);
    if ($corrupt==='missing_resolution') $pdo->exec("DELETE FROM development_work_resolutions WHERE task_id='A'");
    else $pdo->prepare("UPDATE development_work_resolutions SET unknown_receipt_sha256=? WHERE task_id='A'")->execute([str_repeat('f',64)]);
    devReject(fn()=>$q->append(1,str_repeat('a',32),['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('0',64),
        'plan_sha256'=>str_repeat('9',64),'tasks'=>[devSpec('B',['A'])]]),RuntimeException::class,
        'resolved_dependency_requires_saved_resolution_linked_to_original_unknown');
    devAssert((int)$pdo->query("SELECT COUNT(*) FROM development_work_items WHERE task_id='B'")->fetchColumn()===0,
        'corrupt_resolution_never_satisfies_dependency');
}

// Revalidate old accepted dependencies at admission, even after the dependent was READY.
foreach (['missing','bad_hash','wrong_spec','changed_contract'] as $corrupt) {
    [$pdo,$q,$clock]=devFixture([devSpec('A',[],100),devSpec('C',[],90),devSpec('B',['A'],1)]);
    $a=devStart($q,'RUN-A');$q->complete(1,str_repeat('a',32),$a+['receipt'=>devReceipt('RUN-A')]);
    $c=devStart($q,'RUN-C');$q->complete(1,str_repeat('a',32),$c+['receipt'=>devReceipt('RUN-C')]);
    $s=devStatus($q);$event=$q->pendingEvent(1,str_repeat('a',32),'DEV');
    devAssert($s['task_id']==='B'&&($s['last_receipt']['task_id']??null)==='C',
        'late_dependency_fixture_keeps_latest_receipt_valid_and_dependent_ready');
    $admit=['queue_id'=>'DEV','task_id'=>'B','spec_sha256'=>$s['spec_sha256'],'claim_key'=>str_repeat('b',64),
        'expected_pending_event_id'=>$event['event_id']];
    if ($corrupt==='missing') $pdo->exec("DELETE FROM development_work_receipts WHERE task_id='A'");
    elseif ($corrupt==='changed_contract') $pdo->prepare("UPDATE development_work_items SET acceptance_contract_sha256=? WHERE task_id='A'")->execute([str_repeat('f',64)]);
    else $pdo->prepare('UPDATE development_work_receipts SET '.($corrupt==='wrong_spec'?'spec_sha256':'receipt_sha256')."=? WHERE task_id='A'")->execute([str_repeat('f',64)]);
    $beforeReady=$pdo->query("SELECT * FROM development_work_items WHERE task_id='B'")->fetch(PDO::FETCH_ASSOC);
    devReject(fn()=>$q->pendingEvent(1,str_repeat('a',32),'DEV'),RuntimeException::class,
        'ready_pending_cannot_advertise_work_after_prior_dependency_evidence_is_corrupted');
    devReject(fn()=>$q->claim(1,str_repeat('a',32),$admit),RuntimeException::class,
        'ready_claim_rechecks_prior_dependency_evidence_before_persisting_admission');
    devAssert($beforeReady===$pdo->query("SELECT * FROM development_work_items WHERE task_id='B'")->fetch(PDO::FETCH_ASSOC)
        &&(int)$pdo->query("SELECT COUNT(*) FROM development_work_receipts WHERE task_id='B'")->fetchColumn()===0,
        'late_dependency_corruption_never_creates_claim_run_or_receipt');
}
foreach (['missing_resolution','wrong_unknown_link'] as $corrupt) {
    [$pdo,$q,$clock]=devFixture([devSpec('A',[],100),devSpec('C',[],90),devSpec('B',['A'],1)]);
    foreach (['A','C'] as $id) {
        $run='RUN-'.$id;$args=devStart($q,$run);$unknown=devReceipt($run);$unknown['outcome']='unknown';
        $unknown['readback_sha256']=null;$unknown['acceptance_sha256']=null;$unknown['reviewer_run_id']=null;$unknown['owned_claims_released']=false;
        $q->complete(1,str_repeat('a',32),$args+['receipt'=>$unknown]);$held=devStatus($q);
        $q->resolve_existing_run(1,str_repeat('a',32),$args+['unknown_receipt_sha256'=>$held['unknown_receipt_sha256'],
            'expected_run_id'=>$run,'receipt'=>devReceipt($run)]);
    }
    $s=devStatus($q);$event=$q->pendingEvent(1,str_repeat('a',32),'DEV');
    devAssert($s['task_id']==='B'&&($s['last_resolution']['task_id']??null)==='C',
        'late_resolution_fixture_keeps_latest_resolution_valid');
    $admit=['queue_id'=>'DEV','task_id'=>'B','spec_sha256'=>$s['spec_sha256'],'claim_key'=>str_repeat('b',64),
        'expected_pending_event_id'=>$event['event_id']];
    if ($corrupt==='missing_resolution') $pdo->exec("DELETE FROM development_work_resolutions WHERE task_id='A'");
    else $pdo->prepare("UPDATE development_work_resolutions SET unknown_receipt_sha256=? WHERE task_id='A'")->execute([str_repeat('f',64)]);
    $beforeReady=$pdo->query("SELECT * FROM development_work_items WHERE task_id='B'")->fetch(PDO::FETCH_ASSOC);
    devReject(fn()=>$q->pendingEvent(1,str_repeat('a',32),'DEV'),RuntimeException::class,
        'ready_pending_rechecks_older_unknown_resolution_chain');
    devReject(fn()=>$q->claim(1,str_repeat('a',32),$admit),RuntimeException::class,
        'ready_claim_cannot_use_a_now_broken_older_resolution_chain');
    devAssert($beforeReady===$pdo->query("SELECT * FROM development_work_items WHERE task_id='B'")->fetch(PDO::FETCH_ASSOC),
        'broken_resolution_after_ready_does_not_commit_claim_or_replacement_run');
}

// A proven pre-dispatch block retires metadata, never fabricates a worker run or receipt.
[$pdo,$q,$clock]=devFixture();$retire=devRetireArgs($q);$originalPending=$q->pendingEvent(1,str_repeat('a',32),'DEV');
$beforeRetire=devStatus($q);
foreach ([
    ['task_id'=>'B'],['spec_sha256'=>str_repeat('f',64)],['expected_plan_sha256'=>str_repeat('f',64)],
    ['expected_pending_event_id'=>'dev_evt_'.str_repeat('f',64)],
    ['decision'=>array_replace($retire['decision'],['decision_sha256'=>null])],
    ['decision'=>array_replace($retire['decision'],['readback_sha256'=>null])],
    ['decision'=>array_replace($retire['decision'],['reviewer_run_id'=>''])],
    ['claim_key'=>str_repeat('b',64)],['run_id'=>'INVENTED']
] as $bad) {
    devReject(fn()=>$q->retire_unclaimed(1,str_repeat('a',32),array_replace($retire,$bad)),
        [InvalidArgumentException::class,DevelopmentWorkQueueConflict::class],
        'unclaimed_retirement_requires_exact_task_spec_plan_generation_and_independent_decision');
    devAssert(devStatus($q)===$beforeRetire&&(int)$pdo->query('SELECT COUNT(*) FROM development_work_retirements')->fetchColumn()===0,
        'invalid_retirement_intent_changes_no_task_claim_run_or_audit');
}
$retired=$q->retire_unclaimed(1,str_repeat('a',32),$retire);$afterRetire=devStatus($q);
$audit=$pdo->query('SELECT * FROM development_work_retirements')->fetch(PDO::FETCH_ASSOC);
$readback=json_decode($audit['retirement_json'],true,16,JSON_THROW_ON_ERROR);$expected=$retire;ksort($readback);ksort($expected);
$retirementHash=hash('sha256',json_encode(['intent'=>$readback,'namespace'=>'development_work_retirement_v1'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
devAssert($retired['retirement_status']==='retired_unclaimed_not_worker_receipt'
    &&$afterRetire['task_id']==='C'&&$afterRetire['state']==='ready'&&$afterRetire['current_run_id']===null
    &&$afterRetire['accepted_count']===0&&$afterRetire['blocked_count']===1&&$afterRetire['retirement_count']===1
    &&$afterRetire['worker_receipt_count']===0&&$afterRetire['receipt_count']===0
    &&($afterRetire['last_retirement']['record_type']??null)==='metadata_retirement_not_worker_receipt'
    &&$readback===$expected&&$retirementHash===$audit['retirement_sha256']
    &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_receipts')->fetchColumn()===0,
    'retirement_records_one_metadata_decision_and_selects_only_independent_unclaimed_work');
$nextPending=$q->pendingEvent(1,str_repeat('a',32),'DEV');
devAssert($nextPending['name']==='development_work.pending'&&$nextPending['data']['task_id']==='C'
    &&$nextPending['event_id']!==$originalPending['event_id'],'retirement_next_task_requires_distinct_normal_pending_event');
devAssert($q->retire_unclaimed(1,str_repeat('a',32),$retire)['retirement_status']==='already_recorded'
    &&devStatus($q)===$afterRetire&&$audit===$pdo->query('SELECT * FROM development_work_retirements')->fetch(PDO::FETCH_ASSOC),
    'byte_identical_retirement_repeat_never_advances_or_rewrites_audit');
foreach ([
    ['decision'=>array_replace($retire['decision'],['decision_sha256'=>str_repeat('f',64)])],
    ['decision'=>array_replace($retire['decision'],['readback_sha256'=>str_repeat('f',64)])],
    ['decision'=>array_replace($retire['decision'],['reviewer_run_id'=>'OTHER-REVIEW'])],
    ['expected_plan_sha256'=>str_repeat('f',64)]
] as $changedDecision) {
    devReject(fn()=>$q->retire_unclaimed(1,str_repeat('a',32),array_replace($retire,$changedDecision)),DevelopmentWorkQueueConflict::class,
        'changed_retirement_intent_cannot_replace_recorded_decision');
}
$oldClaim=['queue_id'=>'DEV','task_id'=>$retire['task_id'],'spec_sha256'=>$retire['spec_sha256'],
    'claim_key'=>str_repeat('b',64),'expected_pending_event_id'=>$retire['expected_pending_event_id']];
devReject(fn()=>$q->claim(1,str_repeat('a',32),$oldClaim),DevelopmentWorkQueueConflict::class,
    'late_original_claim_after_retirement_cannot_dispatch_retired_or_next_task');
devReject(fn()=>$q->markEventDelivered(1,str_repeat('a',32),'DEV',$originalPending['event_id']),DevelopmentWorkQueueConflict::class,
    'late_retired_task_ack_cannot_acknowledge_new_current_pending');
devAssert(devStatus($q)===$afterRetire&&$audit===$pdo->query('SELECT * FROM development_work_retirements')->fetch(PDO::FETCH_ASSOC),
    'changed_decision_old_claim_and_old_ack_preserve_new_task_and_original_audit');
$c=devStart($q,'AFTER-RETIRE-C');$q->complete(1,str_repeat('a',32),$c+['receipt'=>devReceipt('AFTER-RETIRE-C')]);
$s=devStatus($q);
devAssert($s['state']==='blocked'&&$s['task_id']===null&&$s['accepted_count']===1&&$s['blocked_count']===1
    &&$s['retirement_count']===1&&$s['worker_receipt_count']===1&&$s['receipt_count']===1
    &&($s['last_receipt']['task_id']??null)==='C'
    &&(int)$pdo->query("SELECT COUNT(*) FROM development_work_receipts WHERE task_id='A'")->fetchColumn()===0,
    'metadata_retirement_is_not_counted_as_worker_acceptance_or_worker_receipt');
devAssert($q->retire_unclaimed(1,str_repeat('a',32),$retire)['retirement_status']==='already_recorded'&&devStatus($q)===$s,
    'retirement_unknown_response_reconciles_after_later_work_without_replay');
$pdo->exec("UPDATE development_work_items SET state='accepted' WHERE task_id='A'");
devReject(fn()=>$q->append(1,str_repeat('a',32),['queue_id'=>'DEV','expected_plan_sha256'=>str_repeat('0',64),
    'plan_sha256'=>str_repeat('9',64),'tasks'=>[devSpec('D',['A'])]]),RuntimeException::class,
    'retirement_cannot_satisfy_dependency_even_with_forged_accepted_label');
devAssert((int)$pdo->query("SELECT COUNT(*) FROM development_work_items WHERE task_id='D'")->fetchColumn()===0
    &&$audit===$pdo->query('SELECT * FROM development_work_retirements')->fetch(PDO::FETCH_ASSOC),
    'forged_retirement_acceptance_inserts_no_dependent_and_preserves_decision');

// Accepted history is preserved and does not count as a currently unresolved worker.
[$pdo,$q,$clock]=devFixture([devSpec('H',[],100),devSpec('A',[],90),devSpec('B',['A'],1),devSpec('C',[],0)]);
$h=devStart($q,'HISTORY-H');$q->complete(1,str_repeat('a',32),$h+['receipt'=>devReceipt('HISTORY-H')]);
$history=$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='H'")->fetch(PDO::FETCH_ASSOC);
$q->retire_unclaimed(1,str_repeat('a',32),devRetireArgs($q));$s=devStatus($q);
devAssert($s['task_id']==='C'&&$s['accepted_count']===1&&$s['retirement_count']===1
    &&$s['worker_receipt_count']===1&&$history===$pdo->query("SELECT * FROM development_work_receipts WHERE task_id='H'")->fetch(PDO::FETCH_ASSOC),
    'unrelated_accepted_history_neither_blocks_retirement_nor_gets_erased');

foreach (['claimed','started','unknown_without_run','unknown_with_run'] as $heldState) {
    [$pdo,$q,$clock]=devFixture();$retire=devRetireArgs($q);
    $run=in_array($heldState,['started','unknown_with_run'],true)?'HELD-RUN':null;
    $args=$run===null?devClaim($q):devStart($q,$run);
    if (str_starts_with($heldState,'unknown')) {
        $unknown=['run_id'=>$run,'outcome'=>'unknown','result_sha256'=>null,'readback_sha256'=>null,
            'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>false];
        $q->complete(1,str_repeat('a',32),$args+['receipt'=>$unknown]);
    }
    $beforeItems=$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC);
    $beforeReceipts=$pdo->query('SELECT * FROM development_work_receipts ORDER BY task_id')->fetchAll(PDO::FETCH_ASSOC);
    $clock->at+=3600;
    devReject(fn()=>$q->retire_unclaimed(1,str_repeat('a',32),$retire),DevelopmentWorkQueueConflict::class,
        'claimed_started_or_unknown_work_cannot_be_retired_by_age_or_a_new_decision');
    devAssert($beforeItems===$pdo->query('SELECT * FROM development_work_items ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC)
        &&$beforeReceipts===$pdo->query('SELECT * FROM development_work_receipts ORDER BY task_id')->fetchAll(PDO::FETCH_ASSOC)
        &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_retirements')->fetchColumn()===0,
        'retirement_refusal_retains_original_claim_run_and_unknown_evidence');
}
foreach (['receipt','resolution','claim_field','run_field','other_item_claim','other_item_run','orphan_claimed','orphan_unknown'] as $guard) {
    [$pdo,$q,$clock]=devFixture();$retire=devRetireArgs($q);
    if ($guard==='receipt') $pdo->prepare('INSERT INTO development_work_receipts (queue_id,task_id,spec_sha256,receipt_sha256,receipt_json,created_at) VALUES (?,?,?,?,?,?)')
        ->execute(['DEV','A',$retire['spec_sha256'],str_repeat('f',64),'{}',gmdate('Y-m-d H:i:s',$clock->at)]);
    elseif ($guard==='resolution') $pdo->prepare('INSERT INTO development_work_resolutions (queue_id,task_id,unknown_receipt_sha256,resolution_sha256,receipt_sha256,receipt_json,created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute(['DEV','A',str_repeat('1',64),str_repeat('2',64),str_repeat('3',64),'{}',gmdate('Y-m-d H:i:s',$clock->at)]);
    elseif ($guard==='claim_field') $pdo->prepare("UPDATE development_work_items SET claim_sha256=? WHERE task_id='A'")->execute([str_repeat('b',64)]);
    elseif ($guard==='run_field') $pdo->exec("UPDATE development_work_items SET current_run_id='UNRESOLVED' WHERE task_id='A'");
    else {
        $orphan=$pdo->query("SELECT * FROM development_work_queues WHERE queue_id='DEV'")->fetch(PDO::FETCH_ASSOC);
        $orphan['queue_id']='ORPHAN';$orphan['state']=$guard==='orphan_claimed'?'claimed':($guard==='orphan_unknown'?'unknown':'ready');
        $cols=array_keys($orphan);$pdo->prepare('INSERT INTO development_work_queues ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($orphan));
        if (str_starts_with($guard,'other_item')) {
            $item=$pdo->query("SELECT * FROM development_work_items WHERE task_id='A'")->fetch(PDO::FETCH_ASSOC);
            $item['queue_id']='ORPHAN';$item['claim_sha256']=$guard==='other_item_claim'?str_repeat('c',64):null;
            $item['current_run_id']=$guard==='other_item_run'?'UNRESOLVED-OTHER':null;
            $cols=array_keys($item);$pdo->prepare('INSERT INTO development_work_items ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($item));
        }
    }
    $beforeItems=$pdo->query("SELECT * FROM development_work_items WHERE queue_id='DEV' ORDER BY ordinal")->fetchAll(PDO::FETCH_ASSOC);
    devReject(fn()=>$q->retire_unclaimed(1,str_repeat('a',32),$retire),DevelopmentWorkQueueConflict::class,
        'current_evidence_and_any_persisted_unresolved_development_scope_block_retirement');
    devAssert($beforeItems===$pdo->query("SELECT * FROM development_work_items WHERE queue_id='DEV' ORDER BY ordinal")->fetchAll(PDO::FETCH_ASSOC)
        &&(int)$pdo->query('SELECT COUNT(*) FROM development_work_retirements')->fetchColumn()===0,
        'blocked_retirement_writes_no_decision_and_clears_no_uncertain_scope');
}

// Real processes pin one event before competing on SQLite/MySQL lane and item locks.
foreach (['first_b','first_c','complete_before_old_ack'] as $scenario) {
    $dir=sys_get_temp_dir().'/development-work-race-'.bin2hex(random_bytes(8));
    if (!mkdir($dir,0700)) throw new RuntimeException('development_race_directory_unavailable');
    $processes=[];$racePdo=null;$raceQ=null;
    try {
        $file=getenv('DEVELOPMENT_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/queue.sqlite';
        $racePdo=new DevelopmentWorkTestPdo($file==='MYSQL'?null:$file);$raceQ=new DevelopmentWorkQueue($racePdo,static fn():int=>1793100000);
        $raceQ->create(1,str_repeat('a',32),['queue_id'=>'DEV','plan_sha256'=>str_repeat('0',64),
            'tasks'=>[devSpec('A'),devSpec('B',['A'],90)]]);
        $s=$raceQ->status(1,str_repeat('a',32),'DEV');$event=$raceQ->pendingEvent(1,str_repeat('a',32),'DEV');
        if ($scenario==='complete_before_old_ack') {
            $args=['queue_id'=>'DEV','task_id'=>'A','spec_sha256'=>$s['spec_sha256'],'claim_key'=>str_repeat('b',64)];
            $raceQ->claim(1,str_repeat('a',32),$args+['expected_pending_event_id'=>$event['event_id']]);
            $raceQ->started(1,str_repeat('a',32),$args+['run_id'=>'RUN-A']);
        }
        $actions=$scenario==='first_b'?['claim-b-winner','claim-c']:
            ($scenario==='first_c'?['claim-b','claim-c-winner']:['ack','complete-winner']);
        foreach ($actions as $i=>$action) {
            file_put_contents($dir.'/go'.$i.'.event',$event['event_id']);
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--development-race-child',$dir.'/go'.$i,$dir.'/out'.$i,$file,$action],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('development_race_process_unavailable');
            fclose($pipes[0]);$processes[]=[$process,$pipes];
        }
        $until=microtime(true)+10;
        while ((!is_file($dir.'/out0.ready')||!is_file($dir.'/out1.ready'))&&microtime(true)<$until) usleep(1000);
        devAssert(is_file($dir.'/out0.ready')&&is_file($dir.'/out1.ready'),'native_race_children_ready');
        devAssert(file_get_contents($dir.'/out0.ready')==='A'&&file_get_contents($dir.'/out1.ready')==='A'
            &&file_get_contents($dir.'/out0.spec')===file_get_contents($dir.'/out1.spec')
            &&file_get_contents($dir.'/out0.event')===$event['event_id']&&file_get_contents($dir.'/out1.event')===$event['event_id'],
            'native_race_contenders_pin_same_actual_task_spec_and_event');
        $winner=$scenario==='first_b'?0:1;$contender=1-$winner;
        file_put_contents($dir.'/go'.$winner,'go');$until=microtime(true)+10;
        while (!is_file($dir.'/out'.$winner.'.locked')&&microtime(true)<$until) usleep(1000);
        devAssert(is_file($dir.'/out'.$winner.'.locked'),'native_winner_holds_actual_lane_queue_item_locks');
        file_put_contents($dir.'/go'.$contender,'go');$until=microtime(true)+5;
        while (!is_file($dir.'/out'.$contender.'.attempting')&&microtime(true)<$until) usleep(1000);
        devAssert(is_file($dir.'/out'.$contender.'.attempting'),'native_contender_attempts_during_lock');
        usleep(100000);
        devAssert(!is_file($dir.'/out'.$winner)&&!is_file($dir.'/out'.$contender)
            &&proc_get_status($processes[$contender][0])['running'],'native_contender_cannot_finish_before_winner_releases_lock');
        file_put_contents($dir.'/out'.$winner.'.release','release');$until=microtime(true)+10;
        while ((!is_file($dir.'/out0')||!is_file($dir.'/out1'))&&microtime(true)<$until) usleep(1000);
        devAssert(is_file($dir.'/out0')&&is_file($dir.'/out1'),'native_race_children_finish_before_deadline');
        foreach ($processes as [$process,$pipes]) {
            $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            devAssert(proc_close($process)===0&&$stdout===''&&$stderr==='','native_race_child_clean');
        }
        $results=[file_get_contents($dir.'/out0'),file_get_contents($dir.'/out1')];$s=$raceQ->status(1,str_repeat('a',32),'DEV');
        if ($scenario==='complete_before_old_ack') {
            devAssert($results===['conflict','completed']&&$s['task_id']==='B'&&$s['state']==='ready'
                &&$s['accepted_count']===1&&$s['delivery_state']==='ready',
                'accepted_completion_fences_late_ack_from_previous_task_without_acknowledging_next');
            devAssert((int)$racePdo->query('SELECT COUNT(*) FROM development_work_receipts')->fetchColumn()===1,
                'native_completion_commits_one_receipt');
        } else {
            devAssert($results[$winner]==='claimed'&&$results[$contender]==='conflict'&&$s['state']==='claimed'
                &&$s['task_id']==='A'&&$s['current_run_id']===null&&$s['accepted_count']===0,'forced_both_lock_orders_have_exactly_one_claim_winner');
            $stored=$racePdo->query("SELECT claim_sha256 FROM development_work_items WHERE task_id='A'")->fetchColumn();
            devAssert($stored===hash('sha256',str_repeat($winner===0?'b':'c',64)),'winning_claim_key_digest_belongs_only_to_actual_winner');
        }
        devAssert((int)$racePdo->query('SELECT COUNT(*) FROM development_work_items')->fetchColumn()===2
            &&$s['plan_sha256']===str_repeat('0',64),'native_race_never_invents_tasks_or_rewrites_plan');
    } finally {
        foreach ($processes as [$process,$pipes]) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) {proc_terminate($process,9);proc_close($process);}
        }
        $raceQ=null;$racePdo=null;foreach (glob($dir.'/*')?:[] as $path) unlink($path);rmdir($dir);
    }
}
// Retirement and admission contend on the same real SQL lane; the loser never restarts work.
foreach (['claim_first','block_first','old_ack_after_block'] as $scenario) {
    $dir=sys_get_temp_dir().'/development-retirement-race-'.bin2hex(random_bytes(8));
    if (!mkdir($dir,0700)) throw new RuntimeException('retirement_race_directory_unavailable');
    $processes=[];$racePdo=null;$raceQ=null;
    try {
        $file=getenv('DEVELOPMENT_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/queue.sqlite';
        $racePdo=new DevelopmentWorkTestPdo($file==='MYSQL'?null:$file);$raceQ=new DevelopmentWorkQueue($racePdo,static fn():int=>1793100000);
        $raceQ->create(1,str_repeat('a',32),['queue_id'=>'DEV','plan_sha256'=>str_repeat('0',64),
            'tasks'=>[devSpec('A'),devSpec('B',['A'],90),devSpec('C',[],5)]]);
        $original=$raceQ->pendingEvent(1,str_repeat('a',32),'DEV');
        $actions=$scenario==='claim_first'?['claim-winner','block']:
            ($scenario==='block_first'?['claim','block-winner']:['ack','block-winner']);
        foreach ($actions as $i=>$action) {
            $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--development-retire-race-child',$dir.'/go'.$i,$dir.'/out'.$i,$file,$action],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('retirement_race_process_unavailable');
            fclose($pipes[0]);$processes[]=[$process,$pipes];
        }
        $until=microtime(true)+10;
        while ((!is_file($dir.'/out0.ready')||!is_file($dir.'/out1.ready'))&&microtime(true)<$until) usleep(1000);
        devAssert(is_file($dir.'/out0.ready')&&is_file($dir.'/out1.ready'),'retirement_race_children_ready');
        devAssert(file_get_contents($dir.'/out0.ready')==='A'&&file_get_contents($dir.'/out1.ready')==='A'
            &&file_get_contents($dir.'/out0.event')===$original['event_id']&&file_get_contents($dir.'/out1.event')===$original['event_id'],
            'retirement_and_claim_pin_same_original_task_generation');
        $winner=$scenario==='claim_first'?0:1;$contender=1-$winner;
        file_put_contents($dir.'/go'.$winner,'go');$until=microtime(true)+10;
        while (!is_file($dir.'/out'.$winner.'.locked')&&microtime(true)<$until) usleep(1000);
        devAssert(is_file($dir.'/out'.$winner.'.locked'),'retirement_winner_holds_real_lane_queue_item_locks');
        file_put_contents($dir.'/go'.$contender,'go');$until=microtime(true)+5;
        while (!is_file($dir.'/out'.$contender.'.attempting')&&microtime(true)<$until) usleep(1000);
        devAssert(is_file($dir.'/out'.$contender.'.attempting'),'retirement_contender_attempts_during_lock');
        usleep(100000);
        devAssert(!is_file($dir.'/out'.$winner)&&!is_file($dir.'/out'.$contender)
            &&proc_get_status($processes[$contender][0])['running'],'retirement_contender_waits_for_actual_winner_commit');
        file_put_contents($dir.'/out'.$winner.'.release','release');$until=microtime(true)+10;
        while ((!is_file($dir.'/out0')||!is_file($dir.'/out1'))&&microtime(true)<$until) usleep(1000);
        devAssert(is_file($dir.'/out0')&&is_file($dir.'/out1'),'retirement_race_finishes_before_deadline');
        foreach ($processes as [$process,$pipes]) {
            $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
            devAssert(proc_close($process)===0&&$stdout===''&&$stderr==='','retirement_race_child_clean');
        }
        $results=[file_get_contents($dir.'/out0'),file_get_contents($dir.'/out1')];$s=$raceQ->status(1,str_repeat('a',32),'DEV');
        if ($scenario==='claim_first') {
            devAssert($results===['claimed','conflict']&&$s['state']==='claimed'&&$s['task_id']==='A'
                &&$s['current_run_id']===null&&$s['retirement_count']===0&&$s['blocked_count']===0,
                'claim_first_blocks_retirement_without_clearing_admission_or_creating_decision');
        } else {
            $next=$raceQ->pendingEvent(1,str_repeat('a',32),'DEV');
            devAssert($results===['conflict','blocked']&&$s['state']==='ready'&&$s['task_id']==='C'
                &&$s['current_run_id']===null&&$s['retirement_count']===1&&$s['blocked_count']===1&&$s['accepted_count']===0
                &&$next['event_id']!==$original['event_id']&&$next['data']['task_id']==='C'&&$s['delivery_state']==='ready',
                'retirement_first_fences_late_claim_or_old_ack_and_preserves_new_independent_pending');
            $audit=$racePdo->query('SELECT * FROM development_work_retirements')->fetch(PDO::FETCH_ASSOC);
            devAssert($audit['expected_pending_event_id']===$original['event_id']&&$audit['task_id']==='A',
                'retirement_race_keeps_original_generation_and_decision_audit');
        }
        devAssert($s['receipt_count']===0&&$s['worker_receipt_count']===0
            &&(int)$racePdo->query('SELECT COUNT(*) FROM development_work_receipts')->fetchColumn()===0
            &&(int)$racePdo->query('SELECT COUNT(*) FROM development_work_items')->fetchColumn()===3,
            'retirement_race_invents_no_worker_run_receipt_or_plan_task');
    } finally {
        foreach ($processes as [$process,$pipes]) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) {proc_terminate($process,9);proc_close($process);}
        }
        $raceQ=null;$racePdo=null;foreach (glob($dir.'/*')?:[] as $path) unlink($path);rmdir($dir);
    }
}
echo 'DEVELOPMENT WORK QUEUE: '.$checks." PASS\n";
