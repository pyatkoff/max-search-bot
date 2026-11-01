<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelNativeAutomationAdapter.php';

function hnaFixture():array
{
    $f=hccFixture();$p=$f['p'];hccNewSchema($p);
    foreach(['hotel_work_events','hotel_native_bindings','hotel_native_deliveries']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        foreach(['071_hotel_work_events.sql','086_hotel_native_automation_adapter.sql']as$file)
            foreach(explode(';',(string)file_get_contents(dirname(__DIR__).'/migrations/'.$file))as$sql)if(trim($sql)!=='')$p->exec($sql);
    }else{
        $p->exec('CREATE TABLE hotel_work_events(subscription_id TEXT PRIMARY KEY,single_consumer_key TEXT UNIQUE,admin_id INTEGER,oauth_connection_id TEXT,project_key TEXT,queue_id TEXT,event_name TEXT,arguments_json TEXT,callback_url TEXT,signing_secret TEXT,previous_secret TEXT,previous_secret_until TEXT,verified_at TEXT,expires_at TEXT,is_active INTEGER DEFAULT 1,pending_event_id TEXT,pending_body TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,lease_token TEXT,lease_until TEXT,last_status INTEGER,created_at TEXT,updated_at TEXT);
        CREATE TABLE hotel_native_bindings(queue_id TEXT,role TEXT,task_id TEXT,admin_id INTEGER,connection_id TEXT,subscription_id TEXT,binding_sha256 TEXT,host_trigger_id TEXT,host_evidence_sha256 TEXT,PRIMARY KEY(queue_id,role));
        CREATE TABLE hotel_native_deliveries(event_id TEXT PRIMARY KEY,queue_id TEXT,stage_id TEXT,generation INTEGER,role TEXT,task_id TEXT,subscription_id TEXT,binding_sha256 TEXT,request_sha256 TEXT,state TEXT,http_status INTEGER,UNIQUE(queue_id,stage_id,generation,role));');
    }
    $bytes=hccJson(['knowledge'=>['claims'=>[],'sources_including_unclaimed'=>[],'source_read_updates'=>[]],'hotel_index'=>[],
        'working_acceptances'=>[],'country_import_ledger'=>[],'coordination'=>[],'criteria_updates'=>[],'scoped_question_results'=>[]]);
    $f['service']->importWorking('COLLECTION-SERVICE',$f['stage'],1,$f['control'],'native-import',$bytes,hash('sha256',$bytes));
    $f['native_intent']=$f['journal']->intent('COLLECTION-SERVICE',$f['stage'],1,$f['control'],'native-intent','native-event',str_repeat('a',32));
    $f['assignment']=$f['service']->prepare('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$f['native_intent']['operation_id'],'native-scope','native-packet',['courtyard-fixture'],0);
    $event=$f['service']->queueCycle('COLLECTION-SERVICE',$f['stage'],1,$f['control']);
    $f['queue']->markEventDelivered(901001,$f['conn'],'COLLECTION-SERVICE',$event['event_id']);
    $f['event']=$event['event_id'];
    return $f;
}
function hnaRequest(array$f):array
{return ['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$f['stage'],'generation'=>1,'operation_id'=>$f['native_intent']['operation_id'],'task_id'=>str_repeat('a',32),'worker_capability'=>$f['native_intent']['worker_capability'],'role'=>'producer'];}
function hnaSubscribe(array$f,ManagerPreparationWebhook$webhook,string$role,string$task):array
{
    $events=new HotelWorkEventService($f['p'],$webhook,$f['clock'],connectionAuthorized:static fn(string$c,int$a,array$s):bool=>(new ManagerPreparationOAuth($f['p'],$f['clock']))->eventConnectionAuthorizedAcrossProfiles('hotels',$c,$a),queue:$f['queue']);
    $name=$role==='producer'?HotelWorkEventService::WORKER_EVENTS[0]:HotelWorkEventService::WORKER_EVENTS[1];
    $params=['name'=>$name,'arguments'=>['project_key'=>'anytour','queue_id'=>'COLLECTION-SERVICE','task_id'=>$task],
        'delivery'=>['mode'=>'webhook','url'=>'https://hooks.example.com/'.$role,'secret'=>'whsec_'.base64_encode(str_repeat($role==='producer'?'p':'r',32))]];
    $sub=$events->subscribe($params,901001,$f['conn']);
    return [$events,$sub,['task_id'=>$task,'subscription_id'=>$sub['id'],'trigger_id'=>'host-trigger-'.$role,'event'=>$name,'project_key'=>'anytour','queue_id'=>'COLLECTION-SERVICE','connection_id'=>$f['conn'],'evidence_sha256'=>hash('sha256','synthetic exact host readback '.$role)],$params];
}

$f=hnaFixture();$p=$f['p'];$requests=[];$responseStatus=202;$onSend=null;
$webhook=new ManagerPreparationWebhook(static function(string$url,array$headers,string$body,array$destination)use(&$requests,&$responseStatus,&$onSend):array{
    $d=json_decode($body,true,16,JSON_THROW_ON_ERROR);
    if(($d['type']??null)==='verification')return ['status'=>200,'body'=>json_encode(['challenge'=>$d['challenge']])];
    $requests[]=compact('url','headers','body','destination');
    if($onSend!==null){$hook=$onSend;$onSend=null;$hook();}
    return ['status'=>$responseStatus,'body'=>''];
},static fn(string$host):array=>['93.184.216.34'],$f['clock']);
$native=new HotelNativeAutomationAdapter($p,901001,$f['conn'],$webhook,$f['clock']);$r=hnaRequest($f);
hccReject(fn()=>$native->assertReady($r),'native_missing_route_holds','native_subscription_absent_or_ambiguous');
[$events,$sub,$evidence,$params]=hnaSubscribe($f,$webhook,'producer',$r['task_id']);
hccReject(fn()=>$native->assertReady($r),'native_challenge_not_host_proof','native_host_binding_required');
$bad=$evidence;$bad['task_id']=str_repeat('b',32);hccReject(fn()=>$native->bind('COLLECTION-SERVICE','producer',$r['task_id'],$sub['id'],$bad),'native_wrong_host_task_rejected','native_host_binding_evidence_required');
$native->bind('COLLECTION-SERVICE','producer',$r['task_id'],$sub['id'],$evidence);
$native->assertReady($r);
$p->prepare('UPDATE hotel_work_events SET expires_at=? WHERE subscription_id=?')->execute([gmdate('Y-m-d H:i:s',1793500000),$sub['id']]);
hccReject(fn()=>$native->assertReady($r),'native_expired_subscription');
$p->prepare('UPDATE hotel_work_events SET expires_at=? WHERE subscription_id=?')->execute([gmdate('Y-m-d H:i:s',1793500000+86400),$sub['id']]);
$row=$p->query('SELECT * FROM hotel_work_events')->fetch(PDO::FETCH_ASSOC);$row['subscription_id']='hotel_sub_'.str_repeat('f',64);$row['single_consumer_key']=null;
$columns=array_keys($row);$p->prepare('INSERT INTO hotel_work_events('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
hccReject(fn()=>$native->assertReady($r),'native_ambiguous_candidates_hold','native_subscription_absent_or_ambiguous');
$p->prepare('DELETE FROM hotel_work_events WHERE subscription_id=?')->execute([$row['subscription_id']]);
hccReject(fn()=>$native($r),'native_direct_send_cannot_skip_dispatch_intent');
hccCheck($requests===[],'native_no_socket_before_consumed_permit');
$wrong=$r;$wrong['role']='reviewer';hccReject(fn()=>$native->assertReady($wrong),'native_wrong_role');
$wrong=$r;$wrong['generation']=2;hccReject(fn()=>$native->assertReady($wrong),'native_old_generation');
$wrong=$r;$wrong['worker_capability']=$f['control'];hccReject(fn()=>$native->assertReady($wrong),'native_control_not_worker');
$other=new HotelNativeAutomationAdapter($p,901002,str_repeat('b',32),$webhook,$f['clock']);hccReject(fn()=>$other->assertReady($r),'native_wrong_current_owner');
$p->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=? WHERE grant_id=?')->execute([1793500001,$f['conn']]);
hccReject(fn()=>$native->assertReady($r),'native_revoked_grant');$p->prepare('UPDATE manager_preparation_oauth_grants SET revoked_at=NULL WHERE grant_id=?')->execute([$f['conn']]);

$originalSecret=$params['delivery']['secret'];$p->prepare('UPDATE hotel_work_events SET signing_secret=? WHERE subscription_id=?')->execute(['whsec_'.base64_encode(str_repeat('z',32)),$sub['id']]);
hccReject(fn()=>$native->assertReady($r),'native_secret_rotation_invalidates_host_binding','native_host_binding_required');
$p->prepare('UPDATE hotel_work_events SET signing_secret=? WHERE subscription_id=?')->execute([$originalSecret,$sub['id']]);
$beforeOutbox=$p->query('SELECT state FROM hotel_collection_outbox')->fetchColumn();
$p->prepare('UPDATE hotel_work_events SET is_active=0 WHERE subscription_id=?')->execute([$sub['id']]);
$coordinator=new HotelCollectionCoordinator($p,901001,$f['conn'],$native,$f['clock']);
$intent=['operation_id'=>$r['operation_id'],'task_id'=>$r['task_id'],'request_id'=>'native-dispatch','worker_capability'=>$r['worker_capability']];
hccReject(fn()=>$coordinator->dispatch('COLLECTION-SERVICE',$r['stage_id'],1,$f['control'],$f['event'],$intent),'native_unavailable_before_burning_permit');
hccCheck($beforeOutbox===$p->query('SELECT state FROM hotel_collection_outbox')->fetchColumn(),'native_bad_binding_preserves_delivery_permit');
$p->prepare('UPDATE hotel_work_events SET is_active=1 WHERE subscription_id=?')->execute([$sub['id']]);
// Reentrant sender sees the durable UNKNOWN before the first socket returns.
$onSend=static function()use($native,$r):void{hccCheck($native($r)['replay']===true,'native_concurrent_sender_cannot_send_twice');};
$ack=$coordinator->dispatch('COLLECTION-SERVICE',$r['stage_id'],1,$f['control'],$f['event'],$intent);
hccCheck(count($requests)===1&&$ack['ack']['state']==='callback_acknowledged'&&!$ack['native_run_verified'],'native_real_signed_transport_not_native_run_proof');
$sent=json_decode($requests[0]['body'],true);$headers=[];foreach($requests[0]['headers']as$header){[$k,$v]=explode(': ',$header,2);$headers[$k]=$v;}
$signature='v1,'.base64_encode(hash_hmac('sha256',$headers['webhook-id'].'.'.$headers['webhook-timestamp'].'.'.$requests[0]['body'],ManagerPreparationWebhook::secretBytes($originalSecret),true));
hccCheck($headers['webhook-signature']===$signature&&$headers['X-MCP-Subscription-Id']===$sub['id'],'native_hmac_exact_body_subscription_and_timestamp');
hccCheck($sent['name']==='hotel_work.research_ready'&&$sent['data']===(['project_key'=>'anytour']+$r)&&!str_contains($requests[0]['body'],$f['control']),'native_bounded_exact_role_assignment_without_control_secret');
hccCheck($native($r)['replay']===true&&count($requests)===1,'native_callback_ack_replay_no_resend');
$events->deliverPending();hccCheck(count($requests)===1,'native_role_subscription_not_legacy_pending_consumer');
$a=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$r['stage_id'],'generation'=>1,'operation_id'=>$r['operation_id'],'worker_capability'=>$r['worker_capability'],'instance_id'=>'native-producer-instance'];
$f['service']->enterProducer($a,'native-enter');$otherInstance=$a;$otherInstance['instance_id']='duplicate-native-instance';
hccReject(fn()=>$f['service']->enterProducer($otherInstance,'other-enter'),'native_second_automation_instance_denied');
$candidate=$f['service']->workerSubmit($a+['request_id'=>'native-candidate','packet_json'=>hccPacket($f['assignment'])]);
$review=$f['service']->sealResearch('COLLECTION-SERVICE',$r['stage_id'],1,$f['control'],$r['operation_id'],'synthetic-producer-run',str_repeat('b',32));
[$reviewEvents,$reviewSub,$reviewEvidence]=hnaSubscribe($f,$webhook,'reviewer',str_repeat('b',32));
$native->bind('COLLECTION-SERVICE','reviewer',str_repeat('b',32),$reviewSub['id'],$reviewEvidence);
$pending=$f['queue']->pendingEvent(901001,$f['conn'],'COLLECTION-SERVICE');$f['queue']->markEventDelivered(901001,$f['conn'],'COLLECTION-SERVICE',$pending['event_id']);
$reviewIntent=['operation_id'=>$r['operation_id'],'task_id'=>str_repeat('b',32),'request_id'=>'native-review-dispatch','worker_capability'=>$review['review_capability']];
$responseStatus=0;$coordinator->dispatch('COLLECTION-SERVICE',$review['intake_stage_id'],1,$f['control'],$pending['event_id'],$reviewIntent);
$rr=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$review['intake_stage_id'],'generation'=>1,'operation_id'=>$r['operation_id'],'task_id'=>str_repeat('b',32),'worker_capability'=>$review['review_capability'],'role'=>'reviewer'];
hccCheck($native($rr)['state']==='UNKNOWN'&&count($requests)===2,'native_timeout_unknown_no_retry');
$reviewSent=json_decode($requests[1]['body'],true);
hccCheck($reviewSent['name']==='hotel_work.review_ready'&&$reviewSent['data']['worker_capability']===$review['review_capability']&&!str_contains($requests[1]['body'],$r['worker_capability']),'native_review_has_only_own_capability');
$ra=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$review['intake_stage_id'],'generation'=>1,'review_capability'=>$review['review_capability'],'instance_id'=>'native-review-instance'];
$f['service']->reviewContext($ra);$duplicate=$ra;$duplicate['instance_id']='duplicate-review-instance';hccReject(fn()=>$f['service']->reviewContext($duplicate),'native_second_review_instance_denied');
$f['service']->submitReview($ra,['candidate_sha256'=>$candidate['raw_sha256'],'decision'=>'accept','criteria'=>null,'review_run'=>'synthetic-review-run']);
$verdict=$p->query('SELECT verdict_bytes FROM hotel_collection_reviews')->fetchColumn();
$f['service']->finalizeIntake('COLLECTION-SERVICE',$review['intake_stage_id'],1,$f['control'],'native-finalize',hash('sha256',$verdict),$f['assignment']['base_revision'],$f['assignment']['base_snapshot_sha256']);
hccReject(fn()=>$native->assertReady($r),'native_old_accepted_producer_cannot_replay');hccReject(fn()=>$native->assertReady($rr),'native_old_accepted_review_cannot_replay');
hccCheck((int)$p->query('SELECT accepted_batches FROM hotel_work_queues')->fetchColumn()===1&&count($requests)===2,'native_same_queue_one_acceptance_no_research_replay');
echo "HOTEL NATIVE AUTOMATION CHECKS PASS\n";

// Test the composition used by the existing cron, with delivery held disabled.
(new ReflectionProperty(HotelCollectionCronRuntime::class,'tick'))->setValue(null,null);
$plan=['admin_id'=>901001,'connection_id'=>$f['conn'],'queue_id'=>'COLLECTION-SERVICE','stage_id'=>$r['stage_id'],'generation'=>1,
    'control_capability'=>$f['control'],'event_id'=>$f['event'],'intent'=>$intent,'setup'=>['dispatch_allowed'=>false],'replacement_worker_setup'=>['dispatch_allowed'=>false]];
HotelCollectionCronRuntime::configureAutomations(static fn(PDO$same):array=>[$plan]);
hccCheck(HotelCollectionCronRuntime::tick($p)['processed']===0&&count($requests)===2,'native_cron_false_false_never_dispatches');
(new ReflectionProperty(HotelCollectionCronRuntime::class,'tick'))->setValue(null,null);
$plan['setup']['dispatch_allowed']=true;$plan['replacement_worker_setup']['dispatch_allowed']='true';
HotelCollectionCronRuntime::configureAutomations(static fn(PDO$same):array=>[$plan]);
hccCheck(HotelCollectionCronRuntime::tick($p)['processed']===0&&count($requests)===2,'native_cron_strict_both_true_required');
