<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/DevelopmentWorkEventService.php';
require_once __DIR__.'/fixtures/development_work_events.php';

$checks=0;
function developmentEventAssert(bool $ok,string $name): void
{
    global $checks;++$checks;if (!$ok) throw new RuntimeException('development_event_assertion_failed:'.$name);
}
function developmentEventReject(callable $work,string $class,string $name,?string $reason=null): void
{
    try {$work();} catch (Throwable $error) {
        developmentEventAssert($error instanceof $class&&($reason===null||$error->getMessage()===$reason),$name);return;
    }
    developmentEventAssert(false,$name);
}
function developmentEventSignature(array $request,string $secret): bool
{
    $h=$request['headers'];$key=ManagerPreparationWebhook::secretBytes($secret);
    $expected='v1,'.base64_encode(hash_hmac('sha256',$h['webhook-id'].'.'.$h['webhook-timestamp'].'.'.$request['body'],$key,true));
    return in_array($expected,explode(' ',$h['webhook-signature']),true);
}

try {
    $f=new DevelopmentEventsFixture(false);
    developmentEventAssert(!$f->events->hasActiveSubscriptions(),'empty_has_no_subscription');
    developmentEventAssert($f->events->deliverPending()===['subscriptions'=>0,'accepted'=>0,'retried'=>0,'stopped'=>0,'unchanged'=>0]
        &&$f->requests===[]&&(int)$f->pdo->query('SELECT COUNT(*) FROM development_work_queues')->fetchColumn()===0,'empty_tick_does_not_create_plan_or_callbacks');

    $f=new DevelopmentEventsFixture();$f->grants[str_repeat('e',32)]=['admin'=>1,'active'=>true,'scopes'=>['development_work:write']];
    foreach ([[1,str_repeat('b',32)],[1,str_repeat('c',32)],[1,str_repeat('e',32)],[2,str_repeat('f',32)],[0,$f->connection]] as [$admin,$connection]) {
        developmentEventReject(fn()=>$f->events->subscribe($f->params(),$admin,$connection),DomainException::class,'scope_admin_and_exact_owned_queue_required');
    }
    developmentEventAssert($f->requests===[]&&(int)$f->pdo->query('SELECT COUNT(*) FROM development_work_events')->fetchColumn()===0,
        'legacy_reply_hotel_or_other_bindings_cannot_verify_or_subscribe');
    foreach ([['name'=>'hotel_work.pending'],['arguments'=>['project_key'=>'anytour','queue_id'=>'PILOT','private_context'=>'forbidden']],
        ['cursor'=>'unsupported'],['ttlMs'=>false],['ttlMs'=>999],['arguments'=>['project_key'=>'other','queue_id'=>'PILOT']]] as $override) {
        developmentEventReject(fn()=>$f->events->subscribe(array_replace($f->params(),$override),1,$f->connection),InvalidArgumentException::class,'subscription_inputs_are_allowlisted');
    }
    developmentEventAssert($f->requests===[],'malformed_subscription_never_calls_transport');
    $f=new DevelopmentEventsFixture();$f->verifyHook=function()use($f):void {$f->grants[$f->connection]['active']=false;};
    developmentEventReject(fn()=>$f->events->subscribe($f->params(),1,$f->connection),DomainException::class,'grant_rechecked_after_challenge');
    developmentEventAssert(count($f->requests)===1&&(int)$f->pdo->query('SELECT COUNT(*) FROM development_work_events')->fetchColumn()===0,'revoked_during_challenge_has_no_subscription');
    $f=new DevelopmentEventsFixture();$f->challengeGood=false;
    developmentEventReject(fn()=>$f->events->subscribe($f->params(),1,$f->connection),DevelopmentWorkCallbackError::class,'failed_challenge_is_fixed_error');
    developmentEventAssert((int)$f->pdo->query('SELECT COUNT(*) FROM development_work_events')->fetchColumn()===0,'failed_challenge_never_persists_subscription');

    $f=new DevelopmentEventsFixture();$first=$f->events->subscribe($f->params(),1,$f->connection);
    $params=$f->params();$params['arguments']=array_reverse($params['arguments'],true);
    $again=$f->events->subscribe($params,1,$f->connection);
    developmentEventAssert($first===$again&&count($f->requests)===1,'canonical_subscription_is_idempotent_and_challenge_cached');
    developmentEventAssert((int)$f->pdo->query('SELECT COUNT(*) FROM development_work_events')->fetchColumn()===1,'argument_order_cannot_add_consumer');
    developmentEventReject(fn()=>$f->events->subscribe($f->params('development_work.pending','https://callbacks.example/other'),1,$f->connection),
        InvalidArgumentException::class,'single_callback_for_owned_queue_event','development_event_consumer_exists');
    $rotated='whsec_'.base64_encode(str_repeat('n',32));$before=count($f->requests);
    $f->events->subscribe($f->params('development_work.pending','https://callbacks.example/development',$rotated),1,$f->connection);
    developmentEventAssert(count($f->requests)===$before+1&&$f->row()['signing_secret']===$rotated&&$f->row()['previous_secret']===$f->secret,
        'rotation_requires_fresh_challenge_inside_cache');
    $f->requests=[];$f->events->deliverPending();
    developmentEventAssert(count($f->requests)===1&&developmentEventSignature($f->requests[0],$rotated)
        &&developmentEventSignature($f->requests[0],$f->secret),'rotation_delivers_both_signatures_during_overlap');

    $f=new DevelopmentEventsFixture();$definitions=$f->events->eventDefinitions();
    developmentEventAssert(array_column($definitions,'name')===['development_work.pending','development_work.attention']
        &&$definitions[0]['payloadSchema']['required']===['project_key','queue_id','task_id','spec_sha256','owner_task_id','dispatch_allowed']
        &&$definitions[0]['payloadSchema']['properties']['dispatch_allowed']['const']===true
        &&$definitions[1]['payloadSchema']['properties']['dispatch_allowed']['const']===false,'definitions_publish_only_exact_task_metadata_and_dispatch_boundary');
    $f->subscribe();developmentEventAssert($f->events->hasActiveSubscriptions(),'current_owned_scoped_subscription_visible');
    $s=$f->status();$r=$f->events->deliverPending();$request=$f->requests[0];$payload=$request['data'];
    developmentEventAssert($r['accepted']===1&&count($f->requests)===1,'one_ready_task_delivers_one_pending');
    developmentEventAssert($payload['name']==='development_work.pending'&&$payload['data']===[
        'project_key'=>'anytour','queue_id'=>'PILOT','task_id'=>'TASK1','spec_sha256'=>$s['spec_sha256'],
        'owner_task_id'=>'OWNER_TASK1','dispatch_allowed'=>true],'pending_contains_exact_current_task_spec_and_existing_owner');
    developmentEventAssert(array_keys($payload)===['eventId','name','timestamp','data','cursor']&&$payload['cursor']===null
        &&preg_match('/\Adev_evt_[a-f0-9]{64}\z/D',$payload['eventId'])===1&&strlen($request['body'])<=2048
        &&$request['headers']['webhook-id']===$payload['eventId']&&developmentEventSignature($request,$f->secret),'bounded_envelope_has_stable_identity_and_actual_hmac');
    foreach ([$f->connection,$f->secret,'reviewer_task_id','acceptance_contract_sha256','result_sha256','claim_key'] as $private) {
        developmentEventAssert(!str_contains($request['body'],$private),'event_never_exports_private_grant_claim_review_or_result');
    }
    developmentEventAssert($f->status()['state']==='ready'&&$f->status()['current_run_id']===null
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM development_work_receipts')->fetchColumn()===0,'webhook_2xx_is_not_claim_execution_or_acceptance');
    $f->requests=[];$f->at+=179;$f->events->deliverPending();developmentEventAssert($f->requests===[],'unchanged_ack_is_quiet_before_deadline');
    ++$f->at;$r=$f->events->deliverPending();$attention=$f->requests[0];
    developmentEventAssert($r['accepted']===1&&count($f->requests)===1&&$attention['data']['name']==='development_work.attention'
        &&$attention['data']['data']===array_replace($payload['data'],['dispatch_allowed'=>false])
        &&preg_match('/\Adev_attention_[a-f0-9]{64}\z/D',$attention['data']['eventId'])===1
        &&developmentEventSignature($attention,$f->secret),'unclaimed_task_attention_is_signed_and_forbids_dispatch');
    $f->requests=[];$f->at+=3600;$f->events->deliverPending();
    developmentEventAssert($f->requests===[]&&$f->status()['state']==='ready','acknowledged_attention_cannot_start_an_unbounded_poll_or_retry');

    $validate=new ReflectionMethod(DevelopmentWorkEventService::class,'validEvent');
    $good=['event_id'=>$payload['eventId'],'name'=>'development_work.pending','data'=>array_diff_key($payload['data'],['project_key'=>true])];
    $validate->invoke(null,$good,'PILOT');
    foreach ([
        array_replace($good,['event_id'=>$attention['data']['eventId']]),
        array_replace($good,['name'=>'hotel_work.pending']),
        array_replace($good,['data'=>array_replace($good['data'],['dispatch_allowed'=>false])]),
        array_replace($good,['data'=>array_replace($good['data'],['queue_id'=>'OTHER'])]),
        array_replace($good,['data'=>array_replace($good['data'],['spec_sha256'=>str_repeat('g',64)])]),
        array_replace($good,['data'=>$good['data']+['private_text'=>'forbidden']])
    ] as $bad) {
        developmentEventReject(fn()=>$validate->invoke(null,$bad,'PILOT'),Throwable::class,'malformed_or_cross_namespace_queue_event_is_rejected');
    }

    $f=new DevelopmentEventsFixture();$f->subscribe(false);$f->statuses=[503,204];
    $r=$f->events->deliverPending();$firstRequest=$f->requests[0];$row=$f->row();
    developmentEventAssert($r['retried']===1&&(int)$row['attempts']===1&&$row['next_attempt_at']===gmdate('Y-m-d H:i:s',$f->at+60),'transient_failure_persists_bounded_backoff');
    $f->requests=[];$f->at+=59;$f->events->deliverPending();developmentEventAssert($f->requests===[],'retry_waits_until_own_due_time');
    ++$f->at;$r=$f->events->deliverPending();$retry=$f->requests[0];
    developmentEventAssert($r['accepted']===1&&$retry['body']===$firstRequest['body']
        &&$retry['data']['eventId']===$firstRequest['data']['eventId']&&$retry['headers']['webhook-timestamp']!==$firstRequest['headers']['webhook-timestamp']
        &&$retry['headers']['webhook-signature']!==$firstRequest['headers']['webhook-signature']
        &&developmentEventSignature($retry,$f->secret)&&developmentEventSignature($firstRequest,$f->secret),'retry_preserves_exact_frozen_body_with_fresh_transport_signature');

    foreach ([410,413] as $status) {
        $f=new DevelopmentEventsFixture();$f->subscribe(false);$f->statuses=[$status];$r=$f->events->deliverPending();
        $f->requests=[];$f->at+=3600;$f->events->deliverPending();
        developmentEventAssert($r['stopped']===1&&(int)$f->row()['is_active']===0&&$f->requests===[]&&$f->status()['state']==='ready','terminal_callback_status_stops_delivery_without_executing_task');
    }
    $f=new DevelopmentEventsFixture();$f->subscribe(false);$f->statuses=array_fill(0,5,503);
    for ($i=1;$i<=5;$i++) {$r=$f->events->deliverPending();$row=$f->row();if ($i<5) $f->at=strtotime($row['next_attempt_at'].' UTC');}
    $f->requests=[];$f->at+=3600;$f->events->deliverPending();
    developmentEventAssert($r['stopped']===1&&(int)$row['attempts']===5&&(int)$row['is_active']===0&&$f->requests===[],'transport_retry_budget_is_finite');

    $f=new DevelopmentEventsFixture();$f->subscribe(false);$f->statuses=[503];$f->events->deliverPending();
    $args=$f->claim($f->requests[0]);$f->requests=[];++$f->at;$f->events->deliverPending();
    developmentEventAssert($f->requests===[]&&$f->row()['pending_body']===null&&$f->row()['next_attempt_at']===null
        &&(int)$f->row()['attempts']===0&&$f->status()['state']==='claimed','claimed_task_retires_frozen_pending_before_old_backoff');

    $tasks=[DevelopmentEventsFixture::task('TASK1'),DevelopmentEventsFixture::task('TASK2',['TASK1'],40)];
    $f=new DevelopmentEventsFixture(true,$tasks);$f->subscribe(false);$f->statuses=[503];$f->events->deliverPending();$original=$f->requests[0];
    $args=$f->claim($original);$f->accept($args);$next=$f->status();$f->requests=[];++$f->at;$f->events->deliverPending();
    developmentEventAssert(count($f->requests)===1&&$f->requests[0]['data']['eventId']!==$original['data']['eventId']
        &&$f->requests[0]['data']['data']['task_id']==='TASK2'&&$f->requests[0]['data']['data']['spec_sha256']===$next['spec_sha256']
        &&$f->requests[0]['data']['data']['owner_task_id']==='OWNER_TASK2'
        &&$f->row()['pending_body']===null&&$f->row()['next_attempt_at']===null&&(int)$f->row()['attempts']===0,
        'accepted_task_announces_only_next_dependency_ready_owner_without_old_retry_delay');
    developmentEventAssert((int)$f->pdo->query('SELECT COUNT(*) FROM development_work_receipts')->fetchColumn()===1,'next_event_follows_persisted_acceptance_receipt');
    $f=new DevelopmentEventsFixture();$f->subscribe(false);$f->events->deliverPending();$f->accept($f->claim($f->requests[0]));$f->requests=[];++$f->at;$f->events->deliverPending();
    developmentEventAssert($f->status()['state']==='complete'&&$f->requests===[],'accepted_final_task_has_no_extra_pending');

    $f=new DevelopmentEventsFixture(true,$tasks);$f->subscribe();$f->events->deliverPending();$args=$f->claim($f->requests[0]);
    $f->queue->started(1,$f->connection,$args+['run_id'=>'RUN1']);
    $f->queue->complete(1,$f->connection,$args+['receipt'=>['run_id'=>'RUN1','outcome'=>'unknown',
        'result_sha256'=>null,'readback_sha256'=>null,'acceptance_sha256'=>null,'reviewer_run_id'=>null,'owned_claims_released'=>false]]);
    $originalUnknown=$f->pdo->query('SELECT * FROM development_work_receipts')->fetch(PDO::FETCH_ASSOC);
    $f->requests=[];$f->at+=299;$f->events->deliverPending();
    developmentEventAssert($f->requests===[],'unknown_watch_is_quiet_until_due');
    ++$f->at;$r=$f->events->deliverPending();$firstWatch=$f->requests[0];
    developmentEventAssert($r['accepted']===1&&count($f->requests)===1&&$firstWatch['data']['name']==='development_work.attention'
        &&$firstWatch['data']['data']['dispatch_allowed']===false&&$firstWatch['data']['data']['task_id']==='TASK1'
        &&$f->status()['state']==='unknown'&&$f->status()['current_run_id']==='RUN1'
        &&developmentEventSignature($firstWatch,$f->secret),'unknown_exact_run_receives_signed_attention_only');
    $f->requests=[];$f->at+=300;$f->events->deliverPending();
    developmentEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='development_work.attention'
        &&$f->requests[0]['data']['eventId']!==$firstWatch['data']['eventId']
        &&$f->requests[0]['data']['data']['dispatch_allowed']===false&&$f->status()['task_id']==='TASK1'
        &&$originalUnknown===$f->pdo->query('SELECT * FROM development_work_receipts')->fetch(PDO::FETCH_ASSOC),
        'unknown_watch_epoch_advances_without_replay_next_task_or_receipt_rewrite');

    $f=new DevelopmentEventsFixture();$f->subscribe(false);$f->statuses=[503];$f->events->deliverPending();
    $f->grants[$f->connection]['active']=false;$f->requests=[];$f->at+=60;$r=$f->events->deliverPending();
    developmentEventAssert($r['stopped']===1&&$f->requests===[]&&(int)$f->row()['is_active']===0,'revoked_grant_never_retries_callback');
    $f=new DevelopmentEventsFixture();$f->subscribe(false);$f->pdo->afterLease=function()use($f):void {$f->grants[$f->connection]['active']=false;};
    $r=$f->events->deliverPending();
    developmentEventAssert($r['stopped']===1&&$f->requests===[],'grant_is_rechecked_after_sql_lease');
    $f=new DevelopmentEventsFixture();$f->subscribe(false);
    $f->authorizeHook=function()use($f):void {if ($f->authCalls===2) $f->at+=86400;};
    $r=$f->events->deliverPending();
    developmentEventAssert($r['accepted']===0&&$f->requests===[]&&$f->row()['pending_body']===null,'subscription_expiry_is_rechecked_immediately_before_send');
    $f=new DevelopmentEventsFixture();$f->events->subscribe(array_replace($f->params(),['ttlMs'=>1000]),1,$f->connection);$f->requests=[];++$f->at;
    developmentEventAssert(!$f->events->hasActiveSubscriptions()&&$f->events->deliverPending()['subscriptions']===0&&$f->requests===[],'expired_subscription_is_not_an_active_route');
    $f=new DevelopmentEventsFixture();$f->subscribe(false);$f->events->unsubscribe($f->params(),1,$f->connection);$f->requests=[];$f->events->deliverPending();
    developmentEventAssert($f->requests===[]&&(int)$f->row()['is_active']===0,'unsubscribe_stops_only_delivery');

    $f=new DevelopmentEventsFixture();$f->subscribe(false);$nested=null;
    $f->pdo->afterLease=function()use($f,&$nested):void {$nested=$f->events->deliverPending();};
    $r=$f->events->deliverPending();
    developmentEventAssert($nested!==null&&$nested['subscriptions']===1&&$nested['accepted']===0&&$nested['retried']===0,
        'actual_sql_lease_excludes_reentrant_sender');
    developmentEventAssert($r['accepted']===1&&count($f->requests)===1&&$f->row()['lease_token']===null&&$f->row()['lease_until']===null,
        'overlap_has_one_callback_and_releases_lease');
    developmentEventAssert(!array_diff(array_unique($f->pdo->writes),DevelopmentWorkTestSchema::tables()),'lifecycle_writes_only_development_metadata');
    echo 'DEVELOPMENT WORK EVENTS: '.$checks." PASS\n";
} catch (Throwable $error) {
    $message=$error instanceof RuntimeException&&str_starts_with($error->getMessage(),'development_event_assertion_failed:')?$error->getMessage():get_class($error);
    fwrite(STDERR,$message."\n");exit(1);
}
