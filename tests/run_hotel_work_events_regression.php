<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkEventService.php';
require_once __DIR__.'/fixtures/hotel_work_events.php';

$checks=0;
function hotelEventAssert(bool $ok,string $name): void
{
    global $checks;++$checks;
    if (!$ok) throw new RuntimeException('hotel_event_assertion_failed:'.$name);
}
function hotelEventReject(callable $operation,string $class,string $name,?string $reason=null): void
{
    try { $operation(); }
    catch (Throwable $error) { hotelEventAssert($error instanceof $class&&($reason===null||$error->getMessage()===$reason),$name);return; }
    hotelEventAssert(false,$name);
}
function hotelEventSignature(array $request,string $secret): bool
{
    $h=$request['headers'];$key=ManagerPreparationWebhook::secretBytes($secret);
    $expected='v1,'.base64_encode(hash_hmac('sha256',$h['webhook-id'].'.'.$h['webhook-timestamp'].'.'.$request['body'],$key,true));
    return in_array($expected,explode(' ',$h['webhook-signature']),true);
}

try {
    $f=new HotelEventsFixture(false);
    hotelEventAssert(!$f->events->hasActiveSubscriptions(),'empty_has_no_subscription');
    $empty=$f->events->deliverPending();
    hotelEventAssert($empty===['subscriptions'=>0,'accepted'=>0,'retried'=>0,'stopped'=>0,'unchanged'=>0]&&$f->requests===[],'empty_tick_zero_callbacks');
    hotelEventAssert((int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_queues')->fetchColumn()===0,'empty_tick_never_creates_work');

    $f=new HotelEventsFixture();
    foreach ([[1,str_repeat('b',32)],[1,str_repeat('c',32)],[2,str_repeat('f',32)],[0,$f->connection]] as [$admin,$connection]) {
        hotelEventReject(fn()=>$f->events->subscribe($f->params(),$admin,$connection),DomainException::class,'scope_or_owned_queue_required_before_verification');
    }
    hotelEventAssert($f->requests===[]&&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_events')->fetchColumn()===0,'denied_grants_or_queue_owners_never_verify_or_write');
    foreach ([['name'=>'manager_replies.pending'],['arguments'=>['project_key'=>'anytour','queue_id'=>'PILOT','private_context'=>'forbidden']],['cursor'=>'unsupported']] as $override) {
        hotelEventReject(fn()=>$f->events->subscribe(array_replace($f->params(),$override),1,$f->connection),InvalidArgumentException::class,'hotel_subscription_exact_allowlists');
    }
    hotelEventAssert($f->requests===[],'malformed_subscription_no_transport');

    $f=new HotelEventsFixture();$f->verifyHook=function()use($f):void {$f->grants[$f->connection]['active']=false;};
    hotelEventReject(fn()=>$f->events->subscribe($f->params(),1,$f->connection),DomainException::class,'grant_rechecked_after_network_challenge');
    hotelEventAssert(count($f->requests)===1&&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_events')->fetchColumn()===0,'revoked_during_challenge_no_subscription_insert');
    $f=new HotelEventsFixture();$f->challengeGood=false;
    hotelEventReject(fn()=>$f->events->subscribe($f->params(),1,$f->connection),HotelWorkCallbackError::class,'failed_challenge_is_fixed_error');
    hotelEventAssert((int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_events')->fetchColumn()===0,'failed_challenge_never_creates_subscription');

    $f=new HotelEventsFixture();$first=$f->events->subscribe($f->params(),1,$f->connection);
    $params=$f->params();$params['arguments']=array_reverse($params['arguments'],true);
    $again=$f->events->subscribe($params,1,$f->connection);
    hotelEventAssert($first===$again&&count($f->requests)===1,'same_canonical_subscription_is_idempotent_and_verification_cached');
    hotelEventAssert((int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_events')->fetchColumn()===1,'argument_order_does_not_create_second_consumer');
    hotelEventReject(fn()=>$f->events->subscribe($f->params('hotel_work.pending','https://callbacks.example/other'),1,$f->connection),InvalidArgumentException::class,'only_one_active_consumer_for_queue_event','hotel_event_consumer_exists');
    hotelEventAssert((int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_events WHERE is_active=1')->fetchColumn()===1,'conflicting_callback_cannot_replace_active_consumer');
    $rotated='whsec_'.base64_encode(str_repeat('n',32));$before=count($f->requests);
    $f->events->subscribe($f->params('hotel_work.pending','https://callbacks.example/hotels',$rotated),1,$f->connection);
    hotelEventAssert(count($f->requests)===$before+1&&$f->row()['signing_secret']===$rotated&&$f->row()['previous_secret']===$f->secret,'secret_rotation_requires_fresh_challenge_inside_cache');
    $f->requests=[];$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&hotelEventSignature($f->requests[0],$rotated)&&hotelEventSignature($f->requests[0],$f->secret),'rotation_delivery_has_both_verified_signatures_during_overlap');

    $f=new HotelEventsFixture();$f->subscribe();
    hotelEventAssert($f->events->hasActiveSubscriptions(),'valid_scoped_owned_subscription_visible');
    $result=$f->events->deliverPending();
    hotelEventAssert($result['accepted']===1&&count($f->requests)===1,'one_ready_stage_delivers_one_pending_event');
    $request=$f->requests[0];$payload=$request['data'];
    hotelEventAssert($payload['name']==='hotel_work.pending'&&$payload['data']===[
        'project_key'=>'anytour','queue_id'=>'PILOT','stage_id'=>$f->status()['stage_id'],'stage_kind'=>'research','country'=>'EG','dispatch_allowed'=>true],
        'pending_event_exact_bounded_metadata');
    hotelEventAssert(array_keys($payload)===['eventId','name','timestamp','data','cursor']&&$payload['cursor']===null&&strlen($request['body'])<=2048,'event_envelope_and_size_bound');
    hotelEventAssert($request['headers']['webhook-id']===$payload['eventId']&&hotelEventSignature($request,$f->secret),'actual_callback_hmac_binds_id_time_and_exact_body');
    hotelEventAssert(!str_contains($request['body'],$f->connection)&&!str_contains($request['body'],$f->secret)&&!str_contains($request['body'],'packet_sha256'),'event_body_contains_no_grant_secret_or_hotel_receipt');
    hotelEventAssert($f->status()['state']==='ready'&&$f->status()['accepted_batches']===0&&$f->status()['delivery_state']==='awaiting_claim','callback_acknowledgment_is_not_worker_execution');
    $f->requests=[];$f->at+=179;$quiet=$f->events->deliverPending();
    hotelEventAssert($quiet['accepted']===0&&$f->requests===[],'acknowledged_unchanged_queue_quiet_before_claim_deadline');
    ++$f->at;$attention=$f->events->deliverPending();
    hotelEventAssert($attention['accepted']===1&&count($f->requests)===1,'unclaimed_delivery_has_separate_attention_at_180_seconds');
    hotelEventAssert($f->requests[0]['data']['name']==='hotel_work.attention'&&$f->requests[0]['data']['data']['dispatch_allowed']===false
        &&$f->requests[0]['data']['eventId']!==$payload['eventId']&&hotelEventSignature($f->requests[0],$f->secret),'attention_is_signed_and_never_authorizes_redispatch');
    $f->requests=[];$f->at+=3600;$f->events->deliverPending();
    hotelEventAssert($f->requests===[]&&$f->status()['delivery_state']==='attention_required','acknowledged_attention_stays_quiet_without_replaying_work');

    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=[503,204];
    $retried=$f->events->deliverPending();$firstRequest=$f->requests[0];$row=$f->row();
    hotelEventAssert($retried['retried']===1&&(int)$row['attempts']===1&&$row['next_attempt_at']===gmdate('Y-m-d H:i:s',$f->at+60),'transient_failure_persists_bounded_retry_and_backoff');
    $f->requests=[];$f->at+=59;$f->events->deliverPending();hotelEventAssert($f->requests===[],'retry_not_sent_before_due_time');
    ++$f->at;$success=$f->events->deliverPending();$retryRequest=$f->requests[0];
    hotelEventAssert($success['accepted']===1&&$firstRequest['body']===$retryRequest['body']
        &&$firstRequest['headers']['webhook-id']===$retryRequest['headers']['webhook-id'],'retry_reuses_exact_event_id_and_frozen_body');
    hotelEventAssert($firstRequest['headers']['webhook-timestamp']!==$retryRequest['headers']['webhook-timestamp']
        &&$firstRequest['headers']['webhook-signature']!==$retryRequest['headers']['webhook-signature']
        &&hotelEventSignature($firstRequest,$f->secret)&&hotelEventSignature($retryRequest,$f->secret),'retry_has_fresh_valid_signature_without_new_dispatch_identity');

    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=[503];$f->events->deliverPending();
    $f->claim();$f->requests=[];++$f->at;$f->events->deliverPending();
    hotelEventAssert($f->requests===[]&&$f->row()['pending_body']===null&&$f->row()['next_attempt_at']===null
        &&(int)$f->row()['attempts']===0&&$f->status()['state']==='claimed','claimed_current_stage_clears_stale_retry_before_its_backoff_expires');
    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=[503];$f->events->deliverPending();$oldEvent=$f->requests[0]['data']['eventId'];
    $f->completeResearch($f->claim());$next=$f->status();$f->requests=[];++$f->at;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['eventId']!==$oldEvent
        &&$f->requests[0]['data']['data']['stage_id']===$next['stage_id']&&$f->requests[0]['data']['data']['stage_kind']==='intake','completed_stage_retires_old_retry_and_announces_new_stage_before_old_backoff');
    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=[503,503,503,503,204];
    for ($attempt=1;$attempt<=4;$attempt++) {
        $f->events->deliverPending();$row=$f->row();
        if ($attempt<4) $f->at=strtotime($row['next_attempt_at'].' UTC');
    }
    hotelEventAssert((int)$row['attempts']===4&&$row['next_attempt_at']===gmdate('Y-m-d H:i:s',$f->at+480),'fourth_failure_has_eight_minute_backoff');
    $oldId=$row['pending_event_id'];$f->completeResearch($f->claim());$next=$f->status();$f->requests=[];++$f->at;
    $newStage=$f->events->deliverPending();
    hotelEventAssert($newStage['accepted']===1&&count($f->requests)===1&&$f->requests[0]['data']['eventId']!==$oldId
        &&$f->requests[0]['data']['data']['stage_id']===$next['stage_id']&&$f->row()['next_attempt_at']===null
        &&(int)$f->row()['attempts']===0,'new_stage_is_ready_next_tick_without_inheriting_eight_minute_old_backoff');

    foreach ([410,413] as $terminalStatus) {
        $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=[$terminalStatus];$stopped=$f->events->deliverPending();
        hotelEventAssert($stopped['stopped']===1&&(int)$f->row()['is_active']===0&&$f->row()['single_consumer_key']===null,'terminal_callback_status_releases_subscription_consumer');
        $f->requests=[];$f->at+=3600;$f->events->deliverPending();hotelEventAssert($f->requests===[],'terminal_callback_status_never_retries');
    }
    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=array_fill(0,5,503);$eventIds=[];$bodies=[];
    for ($attempt=1;$attempt<=5;$attempt++) {
        $result=$f->events->deliverPending();$eventIds[]=$f->requests[$attempt-1]['data']['eventId'];$bodies[]=$f->requests[$attempt-1]['body'];
        $row=$f->row();hotelEventAssert((int)$row['attempts']===$attempt,'retry_attempt_counter_monotonic');
        if ($attempt<5) {
            hotelEventAssert($result['retried']===1&&$row['next_attempt_at']===gmdate('Y-m-d H:i:s',$f->at+60*(2**($attempt-1))),'retry_exponential_schedule');
            $f->at=strtotime($row['next_attempt_at'].' UTC');
        } else hotelEventAssert($result['stopped']===1&&(int)$row['is_active']===0,'fifth_failure_stops_subscription');
    }
    hotelEventAssert(count(array_unique($eventIds))===1&&count(array_unique($bodies))===1,'all_bounded_retries_keep_one_logical_event');
    $f->requests=[];$f->at+=10000;$f->events->deliverPending();hotelEventAssert($f->requests===[],'failure_limit_not_reset_by_next_tick');

    $f=new HotelEventsFixture();$params=$f->params();$params['ttlMs']=1000;$f->events->subscribe($params,1,$f->connection);
    $f->requests=[];++$f->at;hotelEventAssert(!$f->events->hasActiveSubscriptions(),'expired_subscription_not_active');
    $f->events->deliverPending();hotelEventAssert($f->requests===[],'expired_subscription_cannot_send');
    $f=new HotelEventsFixture();$f->subscribe(false);$f->grants[$f->connection]['active']=false;
    $stopped=$f->events->deliverPending();hotelEventAssert($stopped['stopped']===1&&$f->requests===[]&&(int)$f->row()['is_active']===0,'revoked_grant_stops_before_callback');
    $f=new HotelEventsFixture();$f->subscribe(false);
    $f->authorizeHook=function()use($f):void {if ($f->authCalls===2) $f->grants[$f->connection]['active']=false;};
    $stopped=$f->events->deliverPending();
    hotelEventAssert($f->authCalls===2&&$stopped['stopped']===1&&$f->requests===[]&&$f->status()['delivery_state']==='ready','grant_rechecked_immediately_before_send');
    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=[503];$f->events->deliverPending();
    $remove=$f->params();unset($remove['delivery']['secret']);$f->events->unsubscribe($remove,1,$f->connection);
    $row=$f->row();hotelEventAssert((int)$row['is_active']===0&&$row['pending_event_id']===null&&$row['pending_body']===null&&$row['single_consumer_key']===null,'unsubscribe_clears_pending_delivery_only');
    $f->requests=[];$f->at+=3600;$f->events->deliverPending();hotelEventAssert($f->requests===[]&&$f->status()['state']==='ready','unsubscribe_never_claims_or_releases_work');

    // Keep observing the same real run while it is claimed/UNKNOWN, including
    // a newly consented connection. Attention is never permission to restart it.
    $f=new HotelEventsFixture();$f->subscribe();$claim=$f->claim();
    $f->queue->started(1,$f->connection,$claim+['run_id'=>'SYNTHETIC_KNOWN_RUN']);
    $f->at+=HotelWorkQueue::WATCHDOG_SECONDS-1;$f->events->deliverPending();
    hotelEventAssert($f->requests===[],'claimed_run_quiet_before_watchdog_deadline');
    ++$f->at;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.attention'
        &&$f->requests[0]['data']['data']['dispatch_allowed']===false&&$f->status()['state']==='claimed'
        &&$f->status()['current_run_id']==='SYNTHETIC_KNOWN_RUN'&&$f->status()['watchdog_epoch']===1,'claimed_watchdog_reports_same_run_without_dispatch');
    $unknown=['run_id'=>'SYNTHETIC_KNOWN_RUN','outcome'=>'unknown','packet_sha256'=>null,'readback_sha256'=>null,
        'ledger_sha256'=>null,'hotel_count'=>null,'record_count'=>null,'source_count'=>null,'read_update_count'=>null,'criteria'=>null];
    $held=$f->queue->complete(1,$f->connection,$claim+['receipt'=>$unknown]);
    $original=$f->pdo->query('SELECT receipt_json FROM hotel_work_receipts')->fetchColumn();
    $f->requests=[];$f->at+=HotelWorkQueue::WATCHDOG_SECONDS;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.attention'
        &&$f->requests[0]['data']['data']['dispatch_allowed']===false&&$f->status()['state']==='unknown','unknown_watchdog_observes_held_work_without_releasing_claim');
    $oldConnection=$f->connection;$newConnection=str_repeat('c',32);$f->grants[$oldConnection]['active']=false;
    $f->queue->handoff_connection(1,$newConnection,['queue_id'=>'PILOT','stage_id'=>$claim['stage_id'],
        'baseline_sha256'=>str_repeat('0',64),'old_connection_id'=>$oldConnection,'handoff_mode'=>'existing_run',
        'claim_key'=>$claim['claim_key'],'expected_run_id'=>'SYNTHETIC_KNOWN_RUN'],static fn(string $old,int $sameAdmin):bool=>$old===$oldConnection&&$sameAdmin===1);
    $f->connection=$newConnection;$f->subscribe();$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.attention'
        &&$f->requests[0]['data']['data']['dispatch_allowed']===false&&$f->status()['state']==='unknown'
        &&$f->status()['current_run_id']==='SYNTHETIC_KNOWN_RUN'
        &&$f->status()['unknown_receipt_sha256']===$held['receipt_sha256'],'fresh_existing_run_connection_receives_only_watch_attention');
    $accepted=['run_id'=>'SYNTHETIC_KNOWN_RUN','outcome'=>'accepted_readback','packet_sha256'=>str_repeat('1',64),
        'readback_sha256'=>str_repeat('2',64),'ledger_sha256'=>str_repeat('3',64),'hotel_count'=>1,
        'record_count'=>1,'source_count'=>0,'read_update_count'=>0,'criteria'=>null];
    $f->queue->resolve_existing_run(1,$f->connection,$claim+['unknown_receipt_sha256'=>$held['receipt_sha256'],
        'expected_run_id'=>'SYNTHETIC_KNOWN_RUN','expected_packet_sha256'=>str_repeat('1',64),'receipt'=>$accepted]);
    hotelEventAssert($f->status()['state']==='ready'&&$f->status()['stage_kind']==='intake'&&$f->status()['watchdog_due_at']===null
        &&$f->status()['watchdog_epoch']===0&&$f->pdo->query('SELECT receipt_json FROM hotel_work_receipts')->fetchColumn()===$original
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_resolutions')->fetchColumn()===1,'exact_resolution_advances_once_preserves_unknown_and_ends_old_watch');
    $f->requests=[];++$f->at;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.pending'
        &&$f->requests[0]['data']['data']['stage_id']!==$claim['stage_id']&&$f->requests[0]['data']['data']['stage_kind']==='intake','resolved_watch_announces_only_new_stage_not_old_attention');
    $f->requests=[];++$f->at;$f->events->deliverPending();hotelEventAssert($f->requests===[],'resolved_old_watch_stays_quiet_after_new_stage_ack');

    // Reenter after a real SQL lease is acquired. The second sender sees the
    // live lease before the first callback can acknowledge or advance anything.
    $f=new HotelEventsFixture();$f->subscribe(false);$nested=null;
    $f->pdo->afterLease=function()use($f,&$nested):void {$nested=$f->events->deliverPending();};
    $first=$f->events->deliverPending();
    hotelEventAssert($nested!==null&&$nested['subscriptions']===1&&$nested['accepted']===0&&$nested['retried']===0,'active_sql_lease_excludes_overlapping_sender');
    hotelEventAssert($first['accepted']===1&&count($f->requests)===1&&$f->row()['lease_token']===null&&$f->row()['lease_until']===null,'overlap_has_one_callback_and_explicit_lease_release');
    hotelEventAssert(!array_diff(array_unique($f->pdo->writes),['hotel_work_events','hotel_work_queues','hotel_work_receipts','hotel_work_lane','hotel_work_connection_handoffs','hotel_work_resolutions']),'event_lifecycle_never_writes_business_tables');
    echo 'HOTEL WORK EVENTS: '.$checks." PASS\n";
} catch (Throwable $error) {
    $message=$error instanceof RuntimeException&&str_starts_with($error->getMessage(),'hotel_event_assertion_failed:')?$error->getMessage():get_class($error);
    fwrite(STDERR,$message."\n");exit(1);
}
