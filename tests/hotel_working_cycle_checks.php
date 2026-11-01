<?php
declare(strict_types=1);
function hccNewSchema(PDO $p):void {
 if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
  foreach(['hotel_collection_reviews','hotel_collection_outbox','hotel_working_imports']as$t)$p->exec('DROP TABLE IF EXISTS '.$t);
  foreach(['084_hotel_working_import.sql','085_hotel_collection_cycle.sql']as$f)foreach(explode(';',(string)file_get_contents(dirname(__DIR__).'/migrations/'.$f))as$s)if(trim($s)!=='')$p->exec($s);
 }else{
  $p->exec('ALTER TABLE hotel_work_queues ADD COLUMN data_snapshot_sha256 TEXT; CREATE TABLE hotel_working_imports(queue_id TEXT PRIMARY KEY,request_id TEXT,input_sha256 TEXT,data_sha256 TEXT,origin_sha256 TEXT,body_bytes BLOB,receipt_bytes BLOB);
  CREATE TABLE hotel_collection_reviews(queue_id TEXT,intake_stage TEXT,producer_stage TEXT,producer_operation TEXT,producer_run TEXT,review_task TEXT,capability_sha256 TEXT,private_receipt BLOB,instance_id TEXT,verdict_bytes BLOB,acceptance_bytes BLOB,finalize_sha256 TEXT,PRIMARY KEY(queue_id,intake_stage),UNIQUE(queue_id,producer_operation));
  CREATE TABLE hotel_collection_outbox(event_id TEXT PRIMARY KEY,queue_id TEXT,stage_id TEXT,state TEXT);');
 }
}
$f=hccFixture();$p=$f['p'];hccNewSchema($p);
$working=['knowledge'=>['claims'=>['old'=>[['record'=>['id'=>'old','text'=>'first'],'origins'=>['one']],['record'=>['id'=>'old','text'=>'second'],'origins'=>['two']]]],'sources_including_unclaimed'=>[],'source_read_updates'=>[]], 'hotel_index'=>[],'working_acceptances'=>['accepted-history'],'country_import_ledger'=>[],'coordination'=>[],'criteria_updates'=>[],'scoped_question_results'=>[]];
$bytes=hccJson($working);$sha=hash('sha256',$bytes);$before=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);
$receipt=$f['service']->importWorking('COLLECTION-SERVICE',$f['stage'],1,$f['control'],'import-one',$bytes,$sha);
hccCheck($receipt['imported_data_snapshot_sha256']===$sha&&$receipt['queue_origin_baseline_sha256']===$f['sha']&&$sha!==$f['sha'],'current_working_independent_of_original_baseline');
$after=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);hccCheck($after[0]['data_snapshot_sha256']===$sha,'import_marker_atomic');$after[0]['data_snapshot_sha256']=null;hccCheck($before===$after,'import_preserves_business_state');
hccCheck($f['service']->importWorking('COLLECTION-SERVICE',$f['stage'],1,$f['control'],'import-one',$bytes,$sha)===$receipt,'import_exact_lost_ack');
$r=$f['service']->readWorking('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$sha,'/knowledge/claims/old');hccCheck(count(json_decode(base64_decode($r['chunk_base64']),true))===2,'reader_preserves_all_variants_origins');
hccReject(fn()=>$f['service']->importWorking('COLLECTION-SERVICE',$f['stage'],1,$f['control'],'changed',$bytes.' ',$sha),'reject_changed_import_bytes','working_import_digest_mismatch');
hccReject(fn()=>HotelCollectionCanonicalDocument::decodeObject('{"x":1,"x":2}'),'reject_duplicate_import_keys');
$event=$f['service']->queueCycle('COLLECTION-SERVICE',$f['stage'],1,$f['control']);
$pending=$f['queue']->pendingEvent(901001,$f['conn'],'COLLECTION-SERVICE');
hccCheck($pending['event_id']===$event['event_id'],'existing_cron_reads_protected_outbox');
$f['queue']->markEventDelivered(901001,$f['conn'],'COLLECTION-SERVICE',$event['event_id']);
require_once dirname(__DIR__).'/services/HotelCollectionCoordinator.php';
$preIntent=hccIntent($f);$f['service']->prepare('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$preIntent['operation_id'],'scope-courtyard','source-packet-one',['courtyard-fixture'],0);
$calls=0;$coordinator=new HotelCollectionCoordinator($p,901001,$f['conn'],static function(array $request)use(&$calls):array{++$calls;return ['transport_only'=>true];},$f['clock']);
$route=['operation_id'=>$preIntent['operation_id'],'task_id'=>'existing-native-task-fixture','request_id'=>'dispatch-one','worker_capability'=>$preIntent['worker_capability']];
$sent=$coordinator->dispatch('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$event['event_id'],$route);
hccCheck($calls===1&&$sent['native_run_verified']===false,'server_adapter_sends_once_without_inventing_host_proof');
hccCheck($f['service']->consumeDispatch('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$event['event_id'])['dispatch_authority']===false,'lost_send_never_refunds_permit');
[$intent,$a,$args]=hccPrepared($f);$c=$f['service']->workerSubmit($args+['request_id'=>'cycle-candidate','packet_json'=>hccPacket($a)]);
$review=$f['service']->sealResearch('COLLECTION-SERVICE',$f['stage'],1,$f['control'],$intent['operation_id'],'producer-real-fixture','central-task');
$ra=['queue_id'=>'COLLECTION-SERVICE','stage_id'=>$review['intake_stage_id'],'generation'=>1,'review_capability'=>$review['review_capability'],'instance_id'=>'review-instance'];
$view=$f['service']->reviewContext($ra);hccCheck($view['candidate']['raw_sha256']===$c['raw_sha256'],'review_reads_original_producer_bytes');
$bad=$ra;$bad['review_capability']=$intent['worker_capability'];hccReject(fn()=>$f['service']->reviewContext($bad),'producer_not_reviewer','canonical_service_review_capability_required');
$f['service']->submitReview($ra,['candidate_sha256'=>$c['raw_sha256'],'decision'=>'accept','criteria'=>null,'review_run'=>'review-real-fixture']);
$verdict=$p->query('SELECT verdict_bytes FROM hotel_collection_reviews')->fetchColumn();
$beforeHead=$p->query('SELECT * FROM hotel_collection_heads')->fetchAll(PDO::FETCH_ASSOC);
$beforeQueue=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);
$fault=new HotelCollectionCanonicalService($p,901001,$f['conn'],$f['clock'],static function(string $at):void{if($at==='cycle_data_committed_before_queue')throw new RuntimeException('cycle_fault');});
hccReject(fn()=>$fault->finalizeIntake('COLLECTION-SERVICE',$review['intake_stage_id'],1,$f['control'],'finalize-one',hash('sha256',$verdict),$a['base_revision'],$a['base_snapshot_sha256']),'rollback_between_data_and_queue','cycle_fault');
hccCheck($beforeHead===$p->query('SELECT * FROM hotel_collection_heads')->fetchAll(PDO::FETCH_ASSOC)&&$beforeQueue===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC),'data_and_business_rollback_together');
$accept=$f['service']->finalizeIntake('COLLECTION-SERVICE',$review['intake_stage_id'],1,$f['control'],'finalize-one',hash('sha256',$verdict),$a['base_revision'],$a['base_snapshot_sha256']);
hccCheck($accept['business_accepted']===true&&$accept['accepted_batches']===1,'atomic_cycle_accepted_once');
hccCheck($f['service']->finalizeIntake('COLLECTION-SERVICE',$review['intake_stage_id'],1,$f['control'],'finalize-one',hash('sha256',$verdict),$a['base_revision'],$a['base_snapshot_sha256'])===$accept,'finalize_lost_ack_replay_no_second_batch');
hccCheck((int)$p->query('SELECT stage_index FROM hotel_work_queues')->fetchColumn()===2,'same_queue_advances_research_and_intake');
hccCheck((int)$p->query('SELECT COUNT(*) FROM hotel_collection_outbox')->fetchColumn()===3,'durable_initial_research_and_intake_outbox');
hccCheck($f['queue']->pendingEvent(901001,$f['conn'],'COLLECTION-SERVICE')['name']==='hotel_work.pending','acceptance_next_event_uses_same_cron');
echo "HOTEL WORKING/CYCLE CHECKS PASS\n";
$p->exec('DROP TABLE hotel_working_imports');
hccReject(fn()=>$f['service']->status('COLLECTION-SERVICE'),'missing_import_store_fail_closed');
hccReject(fn()=>$f['queue']->pendingEvent(901001,$f['conn'],'COLLECTION-SERVICE'),'missing_import_never_emits_dispatch','journal_protection_unavailable');
