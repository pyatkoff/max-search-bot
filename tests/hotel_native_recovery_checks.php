<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelNativeBindingInventory.php';
require_once dirname(__DIR__).'/services/HotelLegacyEffectQuarantine.php';

$f=hnaFixture();$p=$f['p'];$webhook=new ManagerPreparationWebhook(static function(string$u,array$h,string$b):array{
    $d=json_decode($b,true);return ['status'=>200,'body'=>json_encode(['challenge'=>$d['challenge']??null])];
},static fn(string$host):array=>['93.184.216.34'],$f['clock']);
[, $psub]=hnaSubscribe($f,$webhook,'producer',str_repeat('a',32));
[, $rsub]=hnaSubscribe($f,$webhook,'reviewer',str_repeat('b',32));
$context=new HotelNativeRecoveryContext($p,901001,$f['conn'],$f['clock']);$inventory=new HotelNativeBindingInventory($context);
$read=$inventory->read('COLLECTION-SERVICE');
hccCheck(count($read['subscriptions'])===2&&$read['dispatch_authority']===false,'recovery_exact_role_inventory');
hccCheck(!str_contains(hccJson($read),'whsec_')&&!str_contains(hccJson($read),'https://')&&!str_contains(hccJson($read),'callback_url'),'recovery_inventory_no_callback_or_secret');
hccCheck($read['subscriptions'][0]['host_task_verified']===false&&$read['subscriptions'][0]['host_trigger_id']===null,'recovery_task_filter_not_host_proof');
$tasks=['producer'=>str_repeat('a',32),'reviewer'=>str_repeat('b',32)];
$export=['source'=>'native_host_export','observed_at'=>gmdate('Y-m-d\TH:i:s\Z',1793500000),'bindings'=>[]];
foreach([['producer',$psub],['reviewer',$rsub]]as[$role,$sub])$export['bindings'][]=['task_id'=>$tasks[$role],'trigger_id'=>'host-trigger-'.$role,
    'subscription_id'=>$sub['id'],'connector_id'=>'existing-hotel-connector','event'=>$role==='producer'?'hotel_work.research_ready':'hotel_work.review_ready',
    'arguments'=>['project_key'=>'anytour','queue_id'=>'COLLECTION-SERVICE','task_id'=>$tasks[$role]]];
$before=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);
$joined=$inventory->verifyHostExport('COLLECTION-SERVICE',$tasks,'existing-hotel-connector',$export);
hccCheck($joined['state']==='HOST_EXPORT_MATCHES_SERVER'&&!$joined['binding_applied']&&!$joined['native_run_verified'],'recovery_exact_host_join_prepares_only');
hccCheck($joined['evidence']['producer']['subscription_id']===$psub['id']&&$joined['evidence']['reviewer']['subscription_id']===$rsub['id'],'recovery_preserves_exact_subscription_ids');
foreach(['hidden','wrong_task','wrong_subscription','wrong_filter','wrong_connector','stale','extra_private_field']as$case){
    $bad=$export;
    if($case==='hidden')$bad['bindings'][0]['trigger_id']=null;
    elseif($case==='wrong_task')$bad['bindings'][0]['task_id']=str_repeat('c',32);
    elseif($case==='wrong_subscription')$bad['bindings'][0]['subscription_id']='hotel_sub_'.str_repeat('f',64);
    elseif($case==='wrong_filter')$bad['bindings'][0]['arguments']['queue_id']='OTHER';
    elseif($case==='wrong_connector')$bad['bindings'][0]['connector_id']='OTHER';
    elseif($case==='stale')$bad['observed_at']=gmdate('Y-m-d\TH:i:s\Z',1793500000-301);
    else$bad['bindings'][0]['callback_url']='PRIVATE';
    $held=$inventory->verifyHostExport('COLLECTION-SERVICE',$tasks,'existing-hotel-connector',$bad);
    hccCheck($held['state']==='HOLD'&&!$held['dispatch_authority']&&!str_contains(hccJson($held),'PRIVATE'),'recovery_hold_'.$case);
}
$p->prepare('UPDATE hotel_work_events SET is_active=0 WHERE subscription_id=?')->execute([$psub['id']]);
hccCheck($inventory->verifyHostExport('COLLECTION-SERVICE',$tasks,'existing-hotel-connector',$export)['state']==='HOLD','recovery_inactive_subscription_cannot_join');
$foreign=new HotelNativeBindingInventory(new HotelNativeRecoveryContext($p,901002,str_repeat('b',32),$f['clock']));hccReject(fn()=>$foreign->read('COLLECTION-SERVICE'),'recovery_inventory_wrong_owner','native_recovery_access_denied');
hccCheck($before===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC)&&(int)$p->query('SELECT COUNT(*) FROM hotel_native_bindings')->fetchColumn()===0,'recovery_read_only_no_business_or_binding_changes');

// Synthetic legacy claimed intake; never change live data or use real archives.
$f=hccFixture();$p=$f['p'];hotelJournalFixtureSchema($p);hotelCollectionCanonicalFixtureSchema($p);
$p->exec('DROP TABLE IF EXISTS hotel_legacy_effect_quarantine');
if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    foreach(explode(';',(string)file_get_contents(dirname(__DIR__).'/migrations/087_hotel_legacy_effect_quarantine.sql'))as$sql)if(trim($sql)!=='')$p->exec($sql);
}else$p->exec('CREATE TABLE hotel_legacy_effect_quarantine(queue_id TEXT PRIMARY KEY,stage_id TEXT,request_id TEXT,request_sha256 TEXT,original_run_id TEXT,queue_bytes BLOB,working_sha256 TEXT,working_bytes BLOB,packet_sha256 TEXT,evidence_sha256 TEXT,receipt_bytes BLOB)');
$p->exec("UPDATE hotel_work_queues SET write_protocol='legacy',stage_index=41,state='ready',accepted_batches=19");
$cached=new HotelWorkQueue($p,$f['clock']);$stage=$cached->status(901001,$f['conn'],'COLLECTION-SERVICE')['stage_id'];
$key=str_repeat('d',64);$cached->claim(901001,$f['conn'],['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$stage,'claim_key'=>$key]);
$context=new HotelNativeRecoveryContext($p,901001,$f['conn'],$f['clock']);$quarantine=new HotelLegacyEffectQuarantine($context);
$pre=$quarantine->preflight('COLLECTION-SERVICE');hccCheck($pre['eligible']&&!$pre['dispatch_authority']&&!$pre['host_and_Library_fenced'],'quarantine_read_only_preflight_no_false_native_proof');
$body=hccJson(['knowledge'=>[],'hotel_index'=>[],'working_acceptances'=>['old-accepted'],'country_import_ledger'=>['old-ledger'],'coordination'=>['protected'=>['Melissa','Ibis','legacy162']],'criteria_updates'=>[],'scoped_question_results'=>[]]);
$args=['COLLECTION-SERVICE',$pre['expected_queue_sha256'],'preserve-one','00000000-0000-0000-0000-000000000041',$body,hash('sha256',$body),hash('sha256','old TR producer packet'),hash('sha256','approved snapshot provenance fixture')];
$before=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);
$p->exec('UPDATE hotel_work_queues SET accepted_batches=20');hccReject(fn()=>$quarantine->apply(...$args),'quarantine_stale_business_CAS','quarantine_business_CAS_conflict');$p->exec('UPDATE hotel_work_queues SET accepted_batches=19');
$fault=new HotelLegacyEffectQuarantine($context,static function(string$at):void{throw new RuntimeException('quarantine_fault');});
hccReject(fn()=>$fault->apply(...$args),'quarantine_atomic_rollback','quarantine_fault');
hccCheck($before===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC)&&(int)$p->query('SELECT COUNT(*) FROM hotel_legacy_effect_quarantine')->fetchColumn()===0,'quarantine_snapshot_and_fence_rollback_together');
$foreign=new HotelLegacyEffectQuarantine(new HotelNativeRecoveryContext($p,901002,str_repeat('b',32),$f['clock']));hccReject(fn()=>$foreign->apply(...$args),'quarantine_wrong_owner','native_recovery_access_denied');
$receipt=$quarantine->apply(...$args);$after=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);
hccCheck($after[0]['write_protocol']==='quarantine_v1','quarantine_sticky_protocol');$after[0]['write_protocol']='legacy';hccCheck($before===$after,'quarantine_preserves_every_queue_field_except_protocol');
hccCheck(!$receipt['continuation_allowed']&&!$receipt['host_and_Library_fenced']&&$receipt['original_run_status']==='UNKNOWN'&&$receipt['accepted_batches']===19,'quarantine_never_claims_native_termination_acceptance_or_dispatch');
hccCheck($quarantine->apply(...$args)===$receipt,'quarantine_lost_ack_exact_replay');
hccCheck($p->query('SELECT working_bytes FROM hotel_legacy_effect_quarantine')->fetchColumn()===$body,'quarantine_exact_history_and_protected_snapshot');
$late=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$stage,'claim_key'=>$key];
hccReject(fn()=>$cached->claim(901001,$f['conn'],$late),'quarantine_cached_claim_refused','legacy_run_quarantined');
hccReject(fn()=>$cached->started(901001,$f['conn'],$late+['run_id'=>'old-delayed-run']),'quarantine_delayed_started_refused','legacy_run_quarantined');
hccReject(fn()=>$cached->release(901001,$f['conn'],['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$stage]),'quarantine_no_release','legacy_run_quarantined');
hccReject(fn()=>$f['journal']->enroll('COLLECTION-SERVICE',$stage,$f['sha'],$f['control'],'controller'),'quarantine_no_journal_downgrade_or_adoption','journal_marker_mismatch');
$p->exec('DROP TABLE hotel_legacy_effect_quarantine');hccReject(fn()=>$cached->started(901001,$f['conn'],$late+['run_id'=>'old-delayed-run']),'quarantine_missing_store_still_fences','legacy_run_quarantined');
echo "HOTEL NATIVE RECOVERY CHECKS PASS\n";
