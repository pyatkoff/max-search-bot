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

// Stop immediately before the one bounded automatic recovery becomes eligible.
function hotelEventRecoveryFixture(): array
{
    $f=new HotelEventsFixture();$f->subscribe();
    $f->events->deliverPending();$f->at+=180;$f->events->deliverPending();
    $s=$f->status();$connection=$f->connection;
    $args=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],'old_connection_id'=>$connection];
    $proof=static fn(string $old,int $admin,string $mode):bool=>$old===$connection&&$admin===1&&$mode==='ready';
    $f->queue->handoff_connection(1,$connection,$args,$proof);
    $f->requests=[];++$f->at;$f->events->deliverPending();$pending=$f->requests[0];
    $f->requests=[];$f->at+=180;$f->events->deliverPending();$attention=$f->requests[0];
    return [$f,$args,$proof,$pending,$attention];
}

function hotelClaimAuthorityEventChecks(): void
{
    $f=new HotelEventsFixture();$f->subscribe();$f->events->deliverPending();$claim=$f->claim();$f->requests=[];
    $f->at+=HotelWorkQueue::WATCHDOG_SECONDS;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.attention','claim_authority_fixture_has_existing_watch_ack');
    $old=$f->connection;$new=str_repeat('c',32);$before=$f->pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
    $subscriptions=$f->pdo->query('SELECT * FROM hotel_work_events ORDER BY subscription_id')->fetchAll(PDO::FETCH_ASSOC);
    $h=['queue_id'=>'PILOT','stage_id'=>$claim['stage_id'],'baseline_sha256'=>str_repeat('0',64),
        'old_connection_id'=>$old,'handoff_mode'=>'claim_authority','claim_key'=>$claim['claim_key']];++$f->at;$f->requests=[];
    $proof=static fn(string $connection,int $admin,string $mode):bool=>$connection===$old&&$admin===1&&$mode==='claim_authority'
        &&$f->grants[$old]['active']&&$f->grants[$old]['admin']===$admin&&in_array('hotel_work:write',$f->grants[$old]['scopes'],true);
    $accepted=$f->queue->handoff_connection(1,$new,$h,$proof);
    $after=$f->pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
    hotelEventAssert($accepted['handoff_status']==='claim_authority_handed_off'&&$accepted['authority_only']===true&&$accepted['dispatch_allowed']===false
        &&$after['oauth_connection_id']===$new&&array_diff_key($after,array_flip(['oauth_connection_id','updated_at']))===array_diff_key($before,array_flip(['oauth_connection_id','updated_at']))
        &&$f->requests===[]&&$subscriptions===$f->pdo->query('SELECT * FROM hotel_work_events ORDER BY subscription_id')->fetchAll(PDO::FETCH_ASSOC),
        'claim_authority_transfer_preserves_original_watch_and_delivery_without_touching_subscriptions_or_sending');
    $f->connection=$new;$f->subscribe();$f->events->deliverPending();
    hotelEventAssert($f->requests===[]&&$f->grants[$old]['active']&&$f->status()['state']==='claimed'
        &&$f->status()['current_run_id']===null&&$f->status()['watchdog_due_at']===$before['watchdog_due_at'],
        'active_old_grant_does_not_authorize_old_binding_and_new_binding_has_no_immediate_dispatch');
    $q=$f->pdo->prepare('SELECT COUNT(*) FROM hotel_work_events WHERE oauth_connection_id=? AND is_active=1');$q->execute([$old]);
    hotelEventAssert((int)$q->fetchColumn()===0,'claim_authority_old_family_subscriptions_are_fenced_before_transport');
    $f->at=strtotime($before['watchdog_due_at'].' UTC')-1;$f->events->deliverPending();
    hotelEventAssert($f->requests===[],'claim_authority_waits_for_original_watchdog_deadline');
    ++$f->at;$f->events->deliverPending();$request=$f->requests[0]??null;
    hotelEventAssert(count($f->requests)===1&&$request['data']['name']==='hotel_work.attention'
        &&$request['data']['data']['dispatch_allowed']===false&&$request['data']['data']['stage_id']===$claim['stage_id']
        &&hotelEventSignature($request,$f->secret)&&$f->status()['state']==='claimed'&&$f->status()['current_run_id']===null
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_receipts')->fetchColumn()===0,
        'claim_authority_original_watchdog_emits_only_signed_attention_for_same_unbound_claim');
    $afterWatch=$f->pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
    $eventRows=$f->pdo->query('SELECT * FROM hotel_work_events ORDER BY subscription_id')->fetchAll(PDO::FETCH_ASSOC);++$f->at;
    $repeat=$f->queue->handoff_connection(1,$new,$h,static fn():bool=>false);
    hotelEventAssert($repeat['handoff_status']==='already_recorded'&&$repeat['authority_only']===true&&$repeat['dispatch_allowed']===false
        &&$afterWatch===$f->pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC)
        &&$eventRows===$f->pdo->query('SELECT * FROM hotel_work_events ORDER BY subscription_id')->fetchAll(PDO::FETCH_ASSOC)
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_connection_handoffs')->fetchColumn()===1,
        'claim_authority_repeat_never_resets_new_attention_ack_or_delivery');
    $f->requests=[];$f->events->deliverPending();hotelEventAssert($f->requests===[],'claim_authority_repeat_does_not_replay_attention_or_pending');

    // A frozen pending callback from the former active grant is not a dispatch
    // opportunity after authority changes, even before its retry backoff expires.
    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=[503];$f->events->deliverPending();
    $old=$f->connection;$oldBody=$f->row()['pending_body'];$claim=$f->claim();$due=$f->status()['watchdog_due_at'];$f->requests=[];
    $h=['queue_id'=>'PILOT','stage_id'=>$claim['stage_id'],'baseline_sha256'=>str_repeat('0',64),
        'old_connection_id'=>$old,'handoff_mode'=>'claim_authority','claim_key'=>$claim['claim_key']];
    $f->queue->handoff_connection(1,$new,$h,static fn(string $connection,int $admin,string $mode):bool=>$connection===$old&&$admin===1&&$mode==='claim_authority');
    $f->connection=$new;$f->subscribe();$f->events->deliverPending();
    $q=$f->pdo->prepare('SELECT * FROM hotel_work_events WHERE oauth_connection_id=?');$q->execute([$old]);$oldRow=$q->fetch(PDO::FETCH_ASSOC);
    $f->at+=61;$f->events->deliverPending();
    hotelEventAssert(is_string($oldBody)&&$oldBody!==''&&$f->requests===[]&&(int)$oldRow['is_active']===0
        &&$oldRow['single_consumer_key']===null&&$oldRow['pending_body']===$oldBody
        &&$f->grants[$old]['active']&&$f->status()['watchdog_due_at']===$due
        &&$f->status()['state']==='claimed'&&$f->status()['current_run_id']===null,
        'claim_authority_fences_frozen_old_pending_past_backoff_without_erasing_evidence_or_resetting_watchdog');
}

function hotelSubscriptionSetupChecks(): void
{
    $pending='hotel_work.pending';$attention='hotel_work.attention';$sequence=0;
    $epoch=static fn(mixed $value):int|false=>is_string($value)?strtotime($value):false;
    $insert=static function(HotelEventsFixture $f,string $event,array $changes=[])use(&$sequence):string {
        $id='hotel_setup_'.hash('sha256',(string)++$sequence);$now=gmdate('Y-m-d H:i:s',$f->at);
        $row=['subscription_id'=>$id,'single_consumer_key'=>null,'admin_id'=>1,'oauth_connection_id'=>$f->connection,
            'project_key'=>'anytour','queue_id'=>'PILOT','event_name'=>$event,
            'arguments_json'=>'{"project_key":"anytour","queue_id":"PILOT"}',
            'callback_url'=>'https://private.example/PRIVATE_HOTEL_SETUP_CALLBACK',
            'signing_secret'=>'PRIVATE_HOTEL_SETUP_SECRET','previous_secret'=>'PRIVATE_HOTEL_SETUP_PREVIOUS',
            'verified_at'=>gmdate('Y-m-d H:i:s',$f->at-60),'expires_at'=>gmdate('Y-m-d H:i:s',$f->at+3600),
            'is_active'=>1,'pending_event_id'=>'PRIVATE_HOTEL_SETUP_EVENT','pending_body'=>'PRIVATE_HOTEL_SETUP_BODY',
            'attempts'=>0,'last_status'=>204,'next_attempt_at'=>null,'created_at'=>$now,'updated_at'=>$now];
        $row=array_replace($row,$changes);
        $f->pdo->prepare('INSERT INTO hotel_work_events ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')
            ->execute(array_values($row));
        return $id;
    };
    $snapshot=static function(HotelEventsFixture $f):array {
        $out=['grants'=>$f->grants];
        foreach (['hotel_work_queues','hotel_work_events','hotel_work_lane','hotel_work_receipts',
            'hotel_work_connection_handoffs','hotel_work_resolutions','hotel_work_delivery_recoveries'] as $table)
            $out[$table]=$f->pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        return $out;
    };
    $read=static function(HotelEventsFixture $f)use($pending,$attention,$epoch,$snapshot):array {
        $before=$snapshot($f);$writes=$f->pdo->writes;$f->pdo->eventReads=[];
        $dto=$f->events->subscriptionSetup(1,$f->connection,'PILOT');
        hotelEventAssert($before===$snapshot($f)&&$writes===$f->pdo->writes&&$f->requests===[],
            'setup_preserves_all_queue_event_grant_audit_state_without_callback');
        hotelEventAssert(($dto['semantics']??null)==='owned_queue_subscription_metadata_not_host_delivery_proof'
            &&($dto['coverage']??null)==='same_admin_owned_queue'&&$epoch($dto['observed_at']??null)===$f->at
            &&array_column($dto['events']??[],'event_name')===[$pending,$attention],
            'setup_reports_exact_coverage_utc_observation_and_canonical_events');
        hotelEventAssert(count($f->pdo->eventReads)===1,'setup_uses_one_scoped_subscription_read');
        $sql=$f->pdo->eventReads[0];$projection=[];preg_match('/\ASELECT\s+(.+?)\s+FROM\s+hotel_work_events\b/is',trim($sql),$projection);
        hotelEventAssert(isset($projection[1])&&!str_contains($projection[1],'*')
            &&preg_match('/\b(callback_url|signing_secret|previous_secret|subscription_id|arguments_json|pending_body|pending_event_id|lease_token)\b/i',$projection[1])===0
            &&preg_match('/\bLIMIT\s+21\s*;?\s*\z/i',trim($sql))===1,'setup_does_not_read_callback_credentials_payload_or_unbounded_rows');
        $wire=json_encode($dto,JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_HOTEL_SETUP','hotel_setup_','private.example',$f->connection,str_repeat('c',32),'PILOT'] as $private)
            hotelEventAssert(!str_contains($wire,$private),'setup_exports_no_callback_credential_connection_or_event_identity');
        return $dto;
    };
    $unknown=static function(array $dto,string $reason):void {
        hotelEventAssert(($dto['available']??null)===false&&($dto['reason']??null)===$reason
            &&($dto['other_binding_rows']??null)===null,'setup_unavailable_does_not_invent_binding_inventory');
        foreach ($dto['events'] as $event) hotelEventAssert($event['state']==='unknown'&&$event['matching_rows']===null
            &&$event['eligible']===null&&$event['verified']===null&&$event['retrying']===null&&$event['last_http_status']===null
            &&$event['expires_at']===null&&$event['attempts']===null&&$event['next_attempt_at']===null
            &&$event['verified_at']===null&&$event['updated_at']===null,'setup_unknown_has_no_fabricated_subscription_fields');
    };

    $f=new HotelEventsFixture();$dto=$read($f);
    hotelEventAssert($dto['available']===true&&$dto['other_binding_rows']===0,'setup_empty_owned_inventory_is_known');
    foreach ($dto['events'] as $event) hotelEventAssert($event['state']==='absent'&&$event['matching_rows']===0
        &&$event['eligible']===false&&$event['verified']===null&&$event['expires_at']===null,
        'setup_current_absence_does_not_claim_verification');

    $f=new HotelEventsFixture();
    foreach ([['admin_id'=>2],['project_key'=>'foreign'],['queue_id'=>'OTHER'],['event_name'=>'other.event']] as $foreign)
        for ($i=0;$i<21;$i++) $insert($f,$pending,$foreign);
    $insert($f,$pending,['oauth_connection_id'=>str_repeat('c',32)]);
    $insert($f,$attention,['oauth_connection_id'=>str_repeat('c',32),'is_active'=>0]);
    $insert($f,$pending);$insert($f,$attention);$dto=$read($f);
    hotelEventAssert($dto['available']===true&&$dto['other_binding_rows']===2,'setup_filters_foreign_scope_and_separates_old_bindings_before_limit');
    foreach ($dto['events'] as $event) hotelEventAssert($event['state']==='active_verified'&&$event['matching_rows']===1
        &&$event['eligible']===true&&$event['verified']===true&&$event['retrying']===false&&$event['last_http_status']===204
        &&$event['attempts']===0&&$event['next_attempt_at']===null&&$epoch($event['expires_at'])===$f->at+3600
        &&$epoch($event['verified_at'])===$f->at-60&&$epoch($event['updated_at'])===$f->at,
        'setup_old_binding_does_not_make_one_current_subscription_ambiguous_or_eligible');
    hotelEventAssert($f->authCalls===2,'setup_never_authorizes_or_inspects_old_connection_grants');

    $f=new HotelEventsFixture();$insert($f,$pending,['oauth_connection_id'=>str_repeat('c',32)]);$dto=$read($f);
    hotelEventAssert($dto['other_binding_rows']===1&&$dto['events'][0]['state']==='absent'
        &&$dto['events'][0]['eligible']===false,'setup_old_binding_is_not_current_registration');
    foreach ([
        ['inactive',['is_active'=>0,'attempts'=>5,'next_attempt_at'=>gmdate('Y-m-d H:i:s',$f->at+60)],'inactive',false,true,false,204],
        ['expired',['expires_at'=>gmdate('Y-m-d H:i:s',$f->at-1)],'expired',false,true,false,204],
        ['expiry_boundary',['expires_at'=>gmdate('Y-m-d H:i:s',$f->at)],'expired',false,true,false,204],
        ['retry_transport_zero',['last_status'=>0,'attempts'=>2,'next_attempt_at'=>gmdate('Y-m-d H:i:s',$f->at+60)],'active_verified',true,true,true,null],
        ['future_verification',['verified_at'=>gmdate('Y-m-d H:i:s',$f->at+60)],'unknown',null,null,null,null],
        ['invalid_http',['last_status'=>999],'unknown',null,null,null,null],
        ['malformed_active',['is_active'=>2],'unknown',null,null,null,null]
    ] as [$name,$changes,$state,$eligible,$verified,$retrying,$last]) {
        $f=new HotelEventsFixture();$insert($f,$pending,$changes);$dto=$read($f);$event=$dto['events'][0];
        hotelEventAssert($dto['available']===true&&$event['state']===$state&&$event['matching_rows']===1
            &&$event['eligible']===$eligible&&$event['verified']===$verified&&$event['retrying']===$retrying
            &&$event['last_http_status']===$last,'setup_classifies_current_row_'.$name);
        if ($name==='retry_transport_zero') hotelEventAssert($event['attempts']===2&&$epoch($event['next_attempt_at'])===$f->at+60,
            'setup_transport_failure_keeps_retry_count_and_time_without_inventing_http_status');
        if ($state==='unknown') hotelEventAssert($event['expires_at']===null&&$event['attempts']===null
            &&$event['next_attempt_at']===null&&$event['verified_at']===null&&$event['updated_at']===null,
            'setup_malformed_row_does_not_export_partial_registration_proof');
    }
    // Renewal preserves the frozen event and its attempts. A further failure
    // legitimately exceeds the per-subscription stop threshold; it is not corrupt metadata.
    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=array_fill(0,5,503);
    for ($attempt=1;$attempt<=5;$attempt++) {
        $f->events->deliverPending();$row=$f->row();
        if ($attempt<5) $f->at=strtotime($row['next_attempt_at'].' UTC');
    }
    hotelEventAssert((int)$row['attempts']===5&&(int)$row['is_active']===0,'setup_renewal_fixture_reaches_real_delivery_stop');
    $originalEvent=$row['pending_event_id'];$originalBody=$row['pending_body'];
    $f->events->subscribe($f->params(),1,$f->connection);$f->statuses=[503];$f->events->deliverPending();
    $row=$f->row();$f->requests=[];$dto=$read($f);$event=$dto['events'][0];
    hotelEventAssert((int)$row['attempts']===6&&(int)$row['is_active']===0
        &&$row['pending_event_id']===$originalEvent&&$row['pending_body']===$originalBody
        &&$event['state']==='inactive'&&$event['attempts']===6&&$event['last_http_status']===503
        &&$event['eligible']===false&&$event['verified']===true&&$event['retrying']===false,
        'setup_reports_preserved_attempts_above_stop_threshold_after_real_renewal_failure');
    $f=new HotelEventsFixture();$insert($f,$pending);$insert($f,$pending,['is_active'=>0]);$insert($f,$attention);$dto=$read($f);
    hotelEventAssert($dto['events'][0]['state']==='ambiguous'&&$dto['events'][0]['matching_rows']===2
        &&$dto['events'][0]['eligible']===null&&$dto['events'][0]['expires_at']===null
        &&$dto['events'][1]['state']==='active_verified','setup_duplicate_current_rows_remain_ambiguous_per_event');
    $f=new HotelEventsFixture();$insert($f,$pending);$insert($f,$attention);
    for ($i=0;$i<18;$i++) $insert($f,$pending,['oauth_connection_id'=>str_repeat('c',32)]);
    $dto=$read($f);hotelEventAssert($dto['available']===true&&$dto['other_binding_rows']===18,'setup_exact_twenty_scoped_rows_are_complete');
    $insert($f,$pending,['oauth_connection_id'=>str_repeat('c',32)]);$unknown($read($f),'row_limit');
    $f=new HotelEventsFixture();$insert($f,$pending);$f->pdo->failEventRead=true;$unknown($read($f),'unavailable');
    hotelEventAssert($f->authCalls===2,'setup_sql_failure_still_rechecks_current_grant');

    $f=new HotelEventsFixture();$writes=$f->pdo->writes;
    hotelEventReject(fn()=>$f->events->subscriptionSetup(1,str_repeat('c',32),'PILOT'),DomainException::class,'setup_foreign_current_binding_denied_before_read');
    hotelEventAssert($f->pdo->eventReads===[]&&$writes===$f->pdo->writes&&$f->requests===[],'setup_queue_ownership_precedes_inventory');
    $f=new HotelEventsFixture();$f->grants[$f->connection]['active']=false;
    hotelEventReject(fn()=>$f->events->subscriptionSetup(1,$f->connection,'PILOT'),DomainException::class,'setup_revoked_current_grant_denied_before_read');
    hotelEventAssert($f->pdo->eventReads===[]&&$f->requests===[],'setup_invalid_grant_never_reads_subscription_rows');

    foreach ([false,true] as $sqlFailure) {
        $f=new HotelEventsFixture();$insert($f,$pending);$writes=$f->pdo->writes;
        $hook=function()use($f):void {$f->grants[$f->connection]['active']=false;};
        if ($sqlFailure) {$f->pdo->beforeEventRead=$hook;$f->pdo->failEventRead=true;}else $f->pdo->afterEventRead=$hook;
        try {$f->events->subscriptionSetup(1,$f->connection,'PILOT');hotelEventAssert(false,'setup_late_revocation_must_deny');}
        catch (DomainException $error) {hotelEventAssert($error->getMessage()!=='hotel_work_access_denied','setup_late_grant_denial_is_not_owned_queue_absence');}
        hotelEventAssert(count($f->pdo->eventReads)===1&&$f->authCalls===2&&$writes===$f->pdo->writes&&$f->requests===[],
            'setup_rechecks_grant_after_success_or_failed_sql_without_callback');

        $f=new HotelEventsFixture();$insert($f,$pending);
        $hook=function()use($f):void {$f->pdo->prepare("UPDATE hotel_work_queues SET oauth_connection_id=? WHERE queue_id='PILOT'")->execute([str_repeat('c',32)]);};
        if ($sqlFailure) {$f->pdo->beforeEventRead=$hook;$f->pdo->failEventRead=true;}else $f->pdo->afterEventRead=$hook;
        hotelEventReject(fn()=>$f->events->subscriptionSetup(1,$f->connection,'PILOT'),DomainException::class,
            'setup_rechecks_queue_owner_after_success_or_failed_sql','hotel_work_access_denied');
        hotelEventAssert(count($f->pdo->eventReads)===1&&$f->authCalls===2&&$f->requests===[],
            'setup_late_queue_move_returns_no_metadata_or_callback');
    }
    $f=new HotelEventsFixture();$writes=$f->pdo->writes;$f->pdo->beginTransaction();
    try {
        hotelEventReject(fn()=>$f->events->subscriptionSetup(1,$f->connection,'PILOT'),LogicException::class,'setup_foreign_transaction_rejected');
        hotelEventAssert($f->pdo->eventReads===[]&&$writes===$f->pdo->writes&&$f->requests===[],
            'setup_foreign_snapshot_has_no_inventory_read_or_side_effect');
    } finally {if ($f->pdo->inTransaction()) $f->pdo->rollBack();}

    require_once dirname(__DIR__).'/services/HotelWorkMcpAdapter.php';
    $f=new HotelEventsFixture();$insert($f,$pending);$f->pdo->eventReads=[];
    $adapter=new HotelWorkMcpAdapter($f->pdo,$f->queue,fn():int=>$f->at,$f->events);
    $dto=$adapter->call('hotel_work_status',['queue_id'=>'PILOT'],1,$f->connection);
    hotelEventAssert($dto['subscription_setup']['events'][0]['state']==='active_verified'
        &&$dto['subscription_setup']['events'][1]['state']==='absent'&&count($f->pdo->eventReads)===1,
        'status_adapter_returns_the_same_scoped_real_subscription_projection');
    $f->pdo->eventReads=[];$calls=[];
    $adapter=new HotelWorkMcpAdapter($f->pdo,function(string $operation,array $arguments)use(&$calls):array {$calls[]=[$operation,$arguments];return ['ok'=>true];},fn():int=>$f->at,$f->events);
    $dto=$adapter->call('hotel_work_status',['queue_id'=>'PILOT'],1,$f->connection);
    hotelEventAssert($calls===[['status',[1,$f->connection,'PILOT']]]&&$f->pdo->eventReads===[]
        &&$dto['subscription_setup']['available']===false&&$dto['subscription_setup']['events'][0]['state']==='unknown',
        'callable_store_does_not_authorize_a_hidden_subscription_read');
}

try {
    hotelClaimAuthorityEventChecks();
    if (($argv[1]??'')==='--claim-authority-only') {echo 'HOTEL WORK CLAIM AUTHORITY EVENTS: '.$checks." PASS\n";exit(0);}
    hotelSubscriptionSetupChecks();
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

    // One explicit same-owner resume changes delivery identity, never the stage.
    $oldPendingId=$payload['eventId'];
    $oldAttentionId=$f->pdo->query("SELECT attention_ack FROM hotel_work_queues WHERE queue_id='PILOT'")->fetchColumn();
    $beforeResume=$f->status();$resumeAt=++$f->at;
    $resumeArgs=['queue_id'=>'PILOT','stage_id'=>$beforeResume['stage_id'],
        'baseline_sha256'=>$beforeResume['baseline_sha256'],'old_connection_id'=>$f->connection];
    $expectedConnection=$f->connection;
    $resumeProof=static fn(string $old,int $admin,string $mode):bool=>$old===$expectedConnection&&$admin===1&&$mode==='ready';
    $f->queue->handoff_connection(1,$f->connection,$resumeArgs,$resumeProof);$resumed=$f->status();
    $newPendingId=$resumed['expected_pending_event_id'];
    $businessKeys=array_flip(['stage_id','stage_index','stage_kind','country','state','oauth_connection_id',
        'baseline_sha256','phase_counter','accepted_batches','receipt_count','current_run_id']);
    hotelEventAssert(is_string($newPendingId)&&$newPendingId!==$oldPendingId
        &&array_intersect_key($beforeResume,$businessKeys)===array_intersect_key($resumed,$businessKeys),
        'same_owner_resume_changes_pending_identity_without_changing_work');
    foreach ([$oldPendingId,$oldAttentionId] as $staleId) {
        hotelEventReject(fn()=>$f->queue->markEventDelivered(1,$f->connection,'PILOT',$staleId),
            HotelWorkQueueConflict::class,'old_delivery_ack_cannot_acknowledge_resumed_stage','stale_event');
    }
    hotelEventAssert($f->status()===$resumed,'stale_pending_and_attention_ack_preserve_resumed_state');
    $f->requests=[];$delivered=$f->events->deliverPending();$fresh=$f->requests[0];
    hotelEventAssert($delivered['accepted']===1&&count($f->requests)===1
        &&$fresh['data']['eventId']===$newPendingId&&$fresh['data']['name']==='hotel_work.pending'
        &&$fresh['data']['data']===$payload['data']&&$fresh['data']['timestamp']===gmdate('Y-m-d\TH:i:s\Z',$resumeAt)
        &&hotelEventSignature($fresh,$f->secret),'same_stage_resume_delivers_one_fresh_signed_pending_envelope');
    $acknowledged=$f->status();
    $f->queue->handoff_connection(1,$f->connection,$resumeArgs,$resumeProof);
    hotelEventAssert($f->status()===$acknowledged
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_connection_handoffs')->fetchColumn()===1,
        'duplicate_resume_preserves_new_ack_and_one_audit');
    $f->requests=[];++$f->at;$f->events->deliverPending();
    hotelEventAssert($f->requests===[],'duplicate_ready_resume_never_replays_acknowledged_pending');
    $f->at=$resumeAt+180;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['eventId']!==$oldAttentionId
        &&$f->requests[0]['data']['name']==='hotel_work.attention'
        &&$f->requests[0]['data']['data']['stage_id']===$beforeResume['stage_id']
        &&$f->requests[0]['data']['data']['dispatch_allowed']===false,'resumed_unclaimed_stage_gets_new_attention_only');
    $beforeRecoveryRow=$f->pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
    $originalResumeAudit=$f->pdo->query('SELECT * FROM hotel_work_connection_handoffs ORDER BY handoff_sha256')->fetchAll(PDO::FETCH_ASSOC);
    hotelEventAssert((int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===0,
        'resume_attention_ack_does_not_recover_inside_the_same_tick');
    $f->requests=[];$f->at+=HotelWorkQueue::DELIVERY_RECOVERY_BACKOFF_SECONDS-1;$f->events->deliverPending();
    hotelEventAssert($f->requests===[]&&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===0,
        'automatic_recovery_waits_for_its_bounded_future_tick');
    ++$f->at;$recoveryAt=$f->at;$recoveryDelivery=$f->events->deliverPending();$recovered=$f->requests[0];
    hotelEventAssert($recoveryDelivery['accepted']===1&&count($f->requests)===1
        &&$recovered['data']['name']==='hotel_work.pending'&&$recovered['data']['eventId']!==$newPendingId
        &&$recovered['data']['eventId']!==$oldPendingId
        &&$recovered['data']['eventId']===$f->status()['expected_pending_event_id']
        &&$recovered['data']['data']===$payload['data']&&$recovered['data']['timestamp']===gmdate('Y-m-d\TH:i:s\Z',$recoveryAt)
        &&hotelEventSignature($recovered,$f->secret),'automatic_recovery_has_one_new_signed_pending_identity_and_fresh_body_time');
    hotelEventAssert(array_keys($recovered['data'])===['eventId','name','timestamp','data','cursor']
        &&$recovered['data']['cursor']===null&&strlen($recovered['body'])<=2048
        &&!str_contains($recovered['body'],$f->connection)&&!str_contains($recovered['body'],$f->secret)
        &&!str_contains($recovered['body'],'packet_sha256'),'recovery_keeps_existing_bounded_event_contract_without_private_fields');
    $recoveryAudit=$f->pdo->query('SELECT * FROM hotel_work_delivery_recoveries')->fetch(PDO::FETCH_ASSOC);
    if ($f->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        $migrationStatus=$f->status();$f->pdo->reapplyDeliveryRecoveryMigration();
        hotelEventAssert($recoveryAudit===$f->pdo->query('SELECT * FROM hotel_work_delivery_recoveries')->fetch(PDO::FETCH_ASSOC)
            &&$migrationStatus===$f->status(),'native_073_reapply_preserves_saved_recovery_and_queue_state');
        $duplicate=$recoveryAudit;$duplicate['recovery_sha256']=str_repeat('f',64);$columns=array_keys($duplicate);
        hotelEventReject(fn()=>$f->pdo->prepare('INSERT INTO hotel_work_delivery_recoveries ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($duplicate)),
            PDOException::class,'native_073_reapplied_unique_budget_still_rejects_second_generation');
        echo "HOTEL EVENTS MIGRATION073 APPLY/REAPPLY: PASS\n";
    }
    $savedAckFields=['pending_event_id'=>'event_ack','attention_event_id'=>'attention_ack',
        'delivered_at'=>'delivered_at','claim_deadline'=>'claim_deadline','attention_at'=>'attention_at','previous_updated_at'=>'updated_at'];
    foreach ($savedAckFields as $auditField=>$queueField) {
        hotelEventAssert($recoveryAudit[$auditField]===$beforeRecoveryRow[$queueField],'recovery_preserves_previous_ack_evidence_'.$auditField);
    }
    hotelEventAssert($recoveryAudit['stage_id']===$beforeResume['stage_id']
        &&$recoveryAudit['oauth_connection_id']===$f->connection
        &&$recoveryAudit['baseline_sha256']===$beforeResume['baseline_sha256']
        &&$recoveryAudit['resume_sha256']===$originalResumeAudit[0]['handoff_sha256']
        &&$recoveryAudit['created_at']===gmdate('Y-m-d H:i:s',$recoveryAt)
        &&$originalResumeAudit===$f->pdo->query('SELECT * FROM hotel_work_connection_handoffs ORDER BY handoff_sha256')->fetchAll(PDO::FETCH_ASSOC),
        'recovery_pins_original_stage_owner_resume_and_keeps_resume_audit_immutable');
    hotelEventAssert(array_intersect_key($beforeResume,$businessKeys)===array_intersect_key($f->status(),$businessKeys)
        &&$f->pdo->query("SELECT claim_sha256 FROM hotel_work_queues WHERE queue_id='PILOT'")->fetchColumn()===null
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_receipts')->fetchColumn()===0,
        'automatic_recovery_delivers_metadata_without_claim_run_receipt_or_business_progress');
    $recoveredAck=$f->status();
    foreach ([$oldPendingId,$oldAttentionId,$newPendingId,$beforeRecoveryRow['attention_ack']] as $staleId) {
        hotelEventReject(fn()=>$f->queue->markEventDelivered(1,$f->connection,'PILOT',$staleId),
            HotelWorkQueueConflict::class,'pre_recovery_ack_cannot_acknowledge_new_delivery','stale_event');
    }
    $f->queue->handoff_connection(1,$f->connection,$resumeArgs,$resumeProof);
    hotelEventAssert($f->status()===$recoveredAck
        &&$recoveryAudit===$f->pdo->query('SELECT * FROM hotel_work_delivery_recoveries')->fetch(PDO::FETCH_ASSOC),
        'stale_acks_and_original_resume_repeat_cannot_reset_recovered_ack_or_audit');
    $f->requests=[];$f->at+=179;$f->events->deliverPending();
    hotelEventAssert($f->requests===[],'recovered_ack_is_quiet_before_its_claim_deadline');
    ++$f->at;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.attention'
        &&$f->requests[0]['data']['data']['dispatch_allowed']===false
        &&$f->requests[0]['data']['eventId']!==$beforeRecoveryRow['attention_ack'],
        'unclaimed_recovery_gets_only_one_new_generation_attention');
    $f->requests=[];$f->at+=3600;$f->events->deliverPending();
    hotelEventAssert($f->requests===[]&&$f->status()['delivery_state']==='attention_required'
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===1
        &&$recoveryAudit===$f->pdo->query('SELECT * FROM hotel_work_delivery_recoveries')->fetch(PDO::FETCH_ASSOC),
        'spent_recovery_budget_stays_quiet_without_infinite_pending_or_audit_rewrites');

    // The automatic namespace must discard an older frozen transport body and
    // backoff, while retries of the new identity keep their exact signed body.
    $f=new HotelEventsFixture();$f->subscribe(false);$f->events->deliverPending();
    $f->at+=180;$f->queue->pendingEvent(1,$f->connection,'PILOT');$s=$f->status();$connection=$f->connection;
    $resumeArgs=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],'old_connection_id'=>$connection];
    $resumeProof=static fn(string $old,int $admin,string $mode):bool=>$old===$connection&&$admin===1&&$mode==='ready';
    $f->queue->handoff_connection(1,$connection,$resumeArgs,$resumeProof);
    $f->requests=[];++$f->at;$f->statuses=array_fill(0,4,503);
    for ($attempt=1;$attempt<=4;$attempt++) {
        $f->events->deliverPending();$frozen=$f->row();
        if ($attempt<4) $f->at=strtotime($frozen['next_attempt_at'].' UTC');
    }
    $frozenRequest=$f->requests[0];$frozenPendingId=$frozenRequest['data']['eventId'];
    $f->queue->markEventDelivered(1,$f->connection,'PILOT',$frozenPendingId);
    $f->at+=180;$unclaimed=$f->queue->pendingEvent(1,$f->connection,'PILOT');
    $f->queue->markEventDelivered(1,$f->connection,'PILOT',$unclaimed['event_id']);
    $f->at+=HotelWorkQueue::DELIVERY_RECOVERY_BACKOFF_SECONDS;
    hotelEventAssert($frozen['next_attempt_at']>gmdate('Y-m-d H:i:s',$f->at)
        &&$f->row()['pending_body']===$frozenRequest['body'],'automatic_recovery_fixture_retains_late_acked_frozen_body_and_backoff');
    $f->requests=[];$f->statuses=[503,204];$newAttempt=$f->events->deliverPending();$recovered=$f->requests[0];$newTransport=$f->row();
    hotelEventAssert($newAttempt['retried']===1&&count($f->requests)===1
        &&$recovered['data']['eventId']!==$frozenPendingId&&$recovered['body']!==$frozenRequest['body']
        &&$recovered['data']['data']===$frozenRequest['data']['data']
        &&$recovered['data']['timestamp']===gmdate('Y-m-d\TH:i:s\Z',$f->at)
        &&hotelEventSignature($recovered,$f->secret)
        &&(int)$newTransport['attempts']===1&&$newTransport['next_attempt_at']===gmdate('Y-m-d H:i:s',$f->at+60)
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===1,
        'automatic_generation_replaces_old_body_and_backoff_without_new_subscription_or_stage');
    hotelEventReject(fn()=>$f->queue->markEventDelivered(1,$f->connection,'PILOT',$frozenPendingId),
        HotelWorkQueueConflict::class,'late_old_transport_success_cannot_acknowledge_recovery','stale_event');
    $f->requests=[];$f->at+=59;$f->events->deliverPending();
    hotelEventAssert($f->requests===[]&&$f->row()['pending_body']===$recovered['body'],'new_recovery_retry_respects_its_own_backoff');
    ++$f->at;$success=$f->events->deliverPending();$retry=$f->requests[0];
    hotelEventAssert($success['accepted']===1&&count($f->requests)===1&&$retry['body']===$recovered['body']
        &&$retry['data']['eventId']===$recovered['data']['eventId']
        &&$retry['headers']['webhook-timestamp']!==$recovered['headers']['webhook-timestamp']
        &&$retry['headers']['webhook-signature']!==$recovered['headers']['webhook-signature']
        &&hotelEventSignature($retry,$f->secret)
        &&$f->row()['pending_body']===null&&$f->row()['next_attempt_at']===null
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===1,
        'automatic_generation_retry_keeps_body_identity_and_budget_with_fresh_transport_signature');

    // Canonical authorization is checked before a recovery can be persisted.
    [$f,$resumeArgs,$resumeProof]=hotelEventRecoveryFixture();
    $beforeDenied=$f->pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC);
    $f->grants[$f->connection]['active']=false;$f->at+=HotelWorkQueue::DELIVERY_RECOVERY_BACKOFF_SECONDS;$f->requests=[];
    $denied=$f->events->deliverPending();
    hotelEventAssert($denied['stopped']===2&&$f->requests===[]
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===0
        &&$beforeDenied===$f->pdo->query("SELECT * FROM hotel_work_queues WHERE queue_id='PILOT'")->fetch(PDO::FETCH_ASSOC),
        'revoked_current_grant_cannot_create_recovery_or_callback');
    [$f,$resumeArgs,$resumeProof]=hotelEventRecoveryFixture();$beforeExpired=$f->status();
    $f->at+=86400;$f->requests=[];$expired=$f->events->deliverPending();
    hotelEventAssert($expired['subscriptions']===0&&$f->requests===[]&&$f->status()===$beforeExpired
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===0,
        'expired_subscriptions_do_not_create_automatic_generation');

    // A genuine binding transfer is not another same-binding recovery budget.
    [$f,$resumeArgs,$resumeProof]=hotelEventRecoveryFixture();$originalBinding=$f->connection;
    $originalAudit=$f->pdo->query('SELECT * FROM hotel_work_connection_handoffs')->fetch(PDO::FETCH_ASSOC);
    $newBinding=str_repeat('c',32);$f->queue->handoff_connection(1,$newBinding,$resumeArgs,static fn():bool=>true);
    $f->connection=$newBinding;$f->subscribe();++$f->at;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.pending'
        &&$f->status()['oauth_connection_id']===$newBinding
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===0,
        'genuine_authority_handoff_delivers_its_normal_pending_without_recovery_audit');
    $f->requests=[];$f->at+=180;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.attention'
        &&$f->requests[0]['data']['data']['dispatch_allowed']===false,'new_authority_attention_still_forbids_dispatch');
    $f->requests=[];$f->at+=HotelWorkQueue::DELIVERY_RECOVERY_BACKOFF_SECONDS;$f->events->deliverPending();
    $q=$f->pdo->prepare('SELECT * FROM hotel_work_connection_handoffs WHERE handoff_sha256=?');$q->execute([$originalAudit['handoff_sha256']]);
    hotelEventAssert($f->requests===[]&&$f->status()['delivery_state']==='attention_required'
        &&$q->fetch(PDO::FETCH_ASSOC)===$originalAudit
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===0,
        'foreign_binding_cannot_spend_original_self_resume_recovery_budget');

    // Only an actual new pending envelope admits one claim; completion keeps the
    // ordinary research-to-intake progression and does not replay the producer.
    [$f,$resumeArgs,$resumeProof]=hotelEventRecoveryFixture();
    $f->at+=HotelWorkQueue::DELIVERY_RECOVERY_BACKOFF_SECONDS;$f->requests=[];$f->events->deliverPending();$actual=$f->requests[0];
    $admission=['queue_id'=>'PILOT','stage_id'=>$actual['data']['data']['stage_id'],'claim_key'=>str_repeat('d',64),
        'expected_pending_event_id'=>$actual['data']['eventId']];
    $claimed=$f->queue->claim(1,$f->connection,$admission);$again=$f->queue->claim(1,$f->connection,$admission);
    hotelEventAssert($claimed['claim_status']==='claimed'&&$again['claim_status']==='already_claimed_do_not_replay'
        &&$f->status()['state']==='claimed'&&$f->status()['current_run_id']===null,
        'received_recovery_pending_admits_one_claim_without_starting_a_worker');
    $f->requests=[];++$f->at;$f->events->deliverPending();
    hotelEventAssert($f->requests===[],'claimed_recovery_stops_pending_redelivery');
    $completionArgs=$admission;unset($completionArgs['expected_pending_event_id']);
    $f->completeResearch($completionArgs);$next=$f->status();$f->requests=[];++$f->at;$f->events->deliverPending();
    hotelEventAssert(count($f->requests)===1&&$f->requests[0]['data']['name']==='hotel_work.pending'
        &&$f->requests[0]['data']['data']['stage_kind']==='intake'
        &&$f->requests[0]['data']['data']['stage_id']===$next['stage_id']
        &&$next['stage_id']!==$admission['stage_id']&&$next['stage_index']===1
        &&$next['accepted_batches']===0&&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_receipts')->fetchColumn()===1
        &&(int)$f->pdo->query('SELECT COUNT(*) FROM hotel_work_delivery_recoveries')->fetchColumn()===1,
        'accepted_recovered_research_announces_only_its_ordinary_new_intake_stage');

    // A late success can acknowledge the queue while a sender retains its old
    // frozen failed body. Use real queue ACK/readiness methods, not storage edits.
    $f=new HotelEventsFixture();$f->subscribe(false);$f->statuses=array_fill(0,4,503);
    for ($attempt=1;$attempt<=4;$attempt++) {
        $f->events->deliverPending();$oldRow=$f->row();
        if ($attempt<4) $f->at=strtotime($oldRow['next_attempt_at'].' UTC');
    }
    $oldRequest=$f->requests[0];$oldPendingId=$oldRequest['data']['eventId'];
    $f->queue->markEventDelivered(1,$f->connection,'PILOT',$oldPendingId);
    $f->at+=180;$oldAttention=$f->queue->pendingEvent(1,$f->connection,'PILOT');$beforeResume=$f->status();
    hotelEventAssert($oldAttention['name']==='hotel_work.attention'&&$oldRow['next_attempt_at']>gmdate('Y-m-d H:i:s',$f->at),
        'resume_fixture_has_real_old_ack_attention_and_future_frozen_retry');
    $resumeArgs=['queue_id'=>'PILOT','stage_id'=>$beforeResume['stage_id'],
        'baseline_sha256'=>$beforeResume['baseline_sha256'],'old_connection_id'=>$f->connection];
    $f->queue->handoff_connection(1,$f->connection,$resumeArgs,$resumeProof);$newPendingId=$f->status()['expected_pending_event_id'];
    $f->requests=[];$f->statuses=[503,204];$freshAttempt=$f->events->deliverPending();$fresh=$f->requests[0];$newRow=$f->row();
    hotelEventAssert($freshAttempt['retried']===1&&count($f->requests)===1&&$fresh['data']['eventId']===$newPendingId
        &&$newPendingId!==$oldPendingId&&$fresh['body']!==$oldRequest['body']
        &&$fresh['data']['data']===$oldRequest['data']['data']
        &&$fresh['data']['timestamp']===gmdate('Y-m-d\TH:i:s\Z',$f->at)&&hotelEventSignature($fresh,$f->secret)
        &&(int)$newRow['attempts']===1&&$newRow['next_attempt_at']===gmdate('Y-m-d H:i:s',$f->at+60),
        'same_stage_new_identity_discards_frozen_body_and_eight_minute_old_backoff');
    $f->at+=60;$f->requests=[];$retried=$f->events->deliverPending();$retry=$f->requests[0];
    hotelEventAssert($retried['accepted']===1&&count($f->requests)===1&&$retry['body']===$fresh['body']
        &&$retry['data']['eventId']===$newPendingId&&$retry['headers']['webhook-timestamp']!==$fresh['headers']['webhook-timestamp']
        &&hotelEventSignature($retry,$f->secret),'resumed_event_retries_same_new_body_with_fresh_signature');


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
    hotelEventAssert(!array_diff(array_unique($f->pdo->writes),['hotel_work_events','hotel_work_queues','hotel_work_receipts','hotel_work_lane','hotel_work_connection_handoffs','hotel_work_resolutions','hotel_work_delivery_recoveries']),'event_lifecycle_never_writes_business_tables');
    echo 'HOTEL WORK EVENTS: '.$checks." PASS\n";
} catch (Throwable $error) {
    $message=$error instanceof RuntimeException&&str_starts_with($error->getMessage(),'hotel_event_assertion_failed:')?$error->getMessage():get_class($error);
    fwrite(STDERR,$message."\n");exit(1);
}
