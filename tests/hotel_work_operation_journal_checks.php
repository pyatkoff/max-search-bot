<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkOperationJournalPrototype.php';
require_once dirname(__DIR__).'/services/HotelWorkQueue.php';
require_once __DIR__.'/fixtures/hotel_work_queue.php';
require_once __DIR__.'/fixtures/hotel_work_operation_journal.php';

$journalChecks = 0;
function journalCheck(bool $ok, string $name): void { global $journalChecks; ++$journalChecks; if (!$ok) throw new RuntimeException('journal_assertion:'.$name); }
function journalReject(callable $work, string $name, ?string $reason = null): void {
    try { $work(); } catch (Throwable $e) { journalCheck($reason === null || $e->getMessage() === $reason, $name.':'.$e->getMessage()); return; }
    journalCheck(false, $name);
}
function journalSnapshot(PDO $pdo): array {
    $out = []; foreach (['hotel_journal_heads','hotel_journal_operations','hotel_journal_requests','hotel_journal_snapshots'] as $table)
        $out[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2,3')->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}
function journalNoWrite(PDO $pdo, callable $work, string $name, ?string $reason = null): void {
    $before = journalSnapshot($pdo); journalReject($work, $name, $reason); journalCheck($before === journalSnapshot($pdo), $name.'_no_partial_write');
}
function journalBytes(string $hotel = 'Synthetic Hotel'): string {
    return json_encode(['schema_version'=>1,'hotels'=>[['key'=>'fixture-1','name'=>$hotel]],
        'delta'=>[['key'=>'fixture-1','op'=>'upsert']], 'ledger'=>[['key'=>'fixture-1','decision'=>'synthetic-reviewed']]], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function journalService(PDO $pdo, ?callable $fault = null): HotelWorkOperationJournalPrototype { return new HotelWorkOperationJournalPrototype($pdo, 1, str_repeat('a',32), $fault); }
function journalFixture(?string $file = null): array {
    $pdo = new HotelWorkTestPdo($file); hotelJournalFixtureSchema($pdo);
    $queue = new HotelWorkQueue($pdo, static fn():int=>1793500000);
    $queue->create(1,str_repeat('a',32),['queue_id'=>'JOURNAL','mode'=>'continuous','start_country'=>'TH','phase_counter'=>0,'baseline_sha256'=>str_repeat('0',64)]);
    $stage = $queue->status(1,str_repeat('a',32),'JOURNAL')['stage_id']; $j = journalService($pdo);
    $j->enroll('JOURNAL',$stage,str_repeat('0',64),str_repeat('1',64),'controller-a');
    return [$pdo,$j,$stage,$queue];
}
function journalIntent(HotelWorkOperationJournalPrototype $j, string $stage): array { return $j->intent('JOURNAL',$stage,1,str_repeat('1',64),'intent-1','event-1','existing-task'); }
function journalDispatch(HotelWorkOperationJournalPrototype $j, string $stage, array $intent): array { return $j->dispatchIntent('JOURNAL',$stage,1,str_repeat('1',64),$intent['operation_id'],'dispatch-1'); }
function journalAdmit(HotelWorkOperationJournalPrototype $j, string $stage, array $intent): array { return $j->admit('JOURNAL',$stage,1,$intent['operation_id'],$intent['worker_capability'],'admit-1','instance-1'); }
function journalResult(HotelWorkOperationJournalPrototype $j, string $stage, array $intent, ?string $bytes = null): array {
    return $j->result('JOURNAL',$stage,1,$intent['operation_id'],$intent['worker_capability'],'result-1','instance-1',$bytes??journalBytes(),null,null);
}
function journalCandidate(?string $file = null): array {
    [$pdo,$j,$stage,$queue]=journalFixture($file);$intent=journalIntent($j,$stage);journalDispatch($j,$stage,$intent);journalAdmit($j,$stage,$intent);$result=journalResult($j,$stage,$intent);
    return [$pdo,$j,$stage,$queue,$intent,$result];
}
function journalCommit(HotelWorkOperationJournalPrototype $j, string $stage, array $intent): array { return $j->commit('JOURNAL',$stage,1,str_repeat('1',64),$intent['operation_id'],'commit-1',hash('sha256',journalBytes())); }
function journalFault(string $point): callable {
    $armed=true;return static function(string $at)use($point,&$armed):void { if($armed&&$at===$point){$armed=false;throw new RuntimeException('simulated_crash:'.$point);} };
}
function journalWait(string $path): void { $until=microtime(true)+12;while(!is_file($path)&&microtime(true)<$until)usleep(1000);if(!is_file($path))throw new RuntimeException('journal_barrier_timeout'); }

if (($argv[1]??'')==='--journal-child') {
    [$unused,$mode,$file,$input,$out]=array_pad($argv,5,'');
    $pdo=new HotelWorkTestPdo($file==='MYSQL'?null:$file,false);$j=journalService($pdo);$a=json_decode((string)file_get_contents($input),true,32,JSON_THROW_ON_ERROR);
    file_put_contents($out.'.ready','ready');journalWait($input.'.go');
    try {
        if(str_starts_with($a['action'],'crash-')) {
            $point=$a['action']==='crash-before'?'dispatch_intent_written':'after_commit:dispatch';
            $j=journalService($pdo,static function(string $at)use($point):void {if($at===$point)exit(73);});
            $j->dispatchIntent('JOURNAL',$a['stage'],1,str_repeat('1',64),$a['operation'],'dispatch-1');exit(74);
        }
        elseif($a['action']==='admit') $r=$j->admit('JOURNAL',$a['stage'],1,$a['operation'],$a['capability'],$a['request'],$a['instance']);
        elseif($a['action']==='commit') $r=$j->commit('JOURNAL',$a['stage'],1,str_repeat('1',64),$a['operation'],$a['request'],hash('sha256',journalBytes()));
        else $r=$j->handover('JOURNAL',$a['stage'],1,str_repeat('1',64),str_repeat('2',64),'controller-b',$a['request']);
        $r=['ok'=>true,'receipt'=>$r];
    }catch(DomainException $e){$r=['ok'=>false,'reason'=>$e->getMessage()];}
    file_put_contents($out,json_encode($r,JSON_THROW_ON_ERROR));exit(0);
}

// Lost enrollment ACK cannot orphan an operation: control is caller-provisioned,
// and enrollment does not generate a worker key or logical operation.
$enrollPdo=new HotelWorkTestPdo();hotelJournalFixtureSchema($enrollPdo);
$enrollQueue=new HotelWorkQueue($enrollPdo,static fn():int=>1793500000);
$enrollQueue->create(1,str_repeat('a',32),['queue_id'=>'JOURNAL','mode'=>'continuous','start_country'=>'TH','phase_counter'=>0,'baseline_sha256'=>str_repeat('0',64)]);
$enrollStage=$enrollQueue->status(1,str_repeat('a',32),'JOURNAL')['stage_id'];
$enrollBad=journalService($enrollPdo,journalFault('after_commit:enroll'));
journalReject(fn()=>$enrollBad->enroll('JOURNAL',$enrollStage,str_repeat('0',64),str_repeat('1',64),'controller-a'),'lost_enrollment_ack','simulated_crash:after_commit:enroll');
$enrollGood=journalService($enrollPdo);$enrollReceipt=$enrollGood->enroll('JOURNAL',$enrollStage,str_repeat('0',64),str_repeat('1',64),'controller-a');
journalCheck($enrollReceipt===$enrollGood->enroll('JOURNAL',$enrollStage,str_repeat('0',64),str_repeat('1',64),'controller-a')&&count(journalSnapshot($enrollPdo)['hotel_journal_heads'])===1&&count(journalSnapshot($enrollPdo)['hotel_journal_operations'])===0,'enrollment_ack_replay_no_orphaned_operation');

// Lost initial claim/intent ACK: original private capability is recoverable only
// by the control plane. Duplicate events cannot mint another operation.
[$jp,$jj,$js]=journalFixture();
$broken=journalService($jp,journalFault('after_commit:intent'));
journalReject(fn()=>journalIntent($broken,$js),'lost_claim_intent_ack','simulated_crash:after_commit:intent');
$intent=journalIntent($jj,$js);$same=journalIntent($jj,$js);
journalCheck($intent===$same,'intent_ack_byte_stable');
journalCheck(count(journalSnapshot($jp)['hotel_journal_operations'])===1,'duplicate_intent_one_operation');
journalNoWrite($jp,fn()=>$jj->intent('JOURNAL',$js,1,str_repeat('1',64),'other-request','event-1','existing-task'),'duplicate_event_other_request','event_already_admitted');
journalNoWrite($jp,fn()=>$jj->intent('JOURNAL',$js,1,str_repeat('1',64),'intent-1','other-event','existing-task'),'request_reuse_different_event','request_conflict');
$status=json_encode($jj->status('JOURNAL',$js),JSON_THROW_ON_ERROR);
foreach([$intent['worker_capability'],hash('sha256',$intent['worker_capability']),str_repeat('1',64),hash('sha256',str_repeat('1',64))] as $secret)
    journalCheck(!str_contains($status,$secret),'status_has_no_capability_or_capability_hash');
journalNoWrite($jp,fn()=>$jj->handover('JOURNAL',$js,1,$intent['worker_capability'],str_repeat('3',64),'worker-self','worker-bump'),'same_oauth_worker_cannot_bump_generation','control_capability_required');
journalNoWrite($jp,fn()=>$jj->intent('JOURNAL',$js,1,$intent['worker_capability'],'worker-intent','worker-event','existing-task'),'same_oauth_worker_cannot_issue_intent','control_capability_required');
journalNoWrite($jp,fn()=>$jj->readCandidate('JOURNAL',$js,1,$intent['worker_capability'],$intent['operation_id']),'worker_cannot_use_status_as_control','control_capability_required');

// A recorded intent does not mean a HOST request was sent. A lost send/ACK
// remains UNKNOWN and this class has no method that can replay native effects.
journalNoWrite($jp,fn()=>journalAdmit($jj,$js,$intent),'admission_before_dispatch_intent','admission_already_spent');
$broken=journalService($jp,journalFault('after_commit:dispatch'));
journalReject(fn()=>journalDispatch($broken,$js,$intent),'lost_dispatch_intent_ack','simulated_crash:after_commit:dispatch');
$dispatch=journalDispatch($jj,$js,$intent);
journalCheck($dispatch===journalDispatch($jj,$js,$intent)&&$dispatch['native_effect']==='unknown'&&$dispatch['dispatch_authority']===false,'dispatch_ack_replay_is_not_new_send_authority');
journalNoWrite($jp,fn()=>$jj->intent('JOURNAL',$js,1,str_repeat('1',64),'new-intent','new-event','existing-task'),'unknown_native_effect_blocks_new_intent','native_effect_unknown');
$broken=journalService($jp,journalFault('after_commit:admit'));
journalReject(fn()=>journalAdmit($broken,$js,$intent),'lost_admission_ack','simulated_crash:after_commit:admit');
$admission=journalAdmit($jj,$js,$intent);journalCheck($admission===journalAdmit($jj,$js,$intent),'admission_ack_exact_receipt');
journalNoWrite($jp,fn()=>$jj->admit('JOURNAL',$js,1,$intent['operation_id'],$intent['worker_capability'],'admit-other','instance-2'),'duplicate_admission_other_instance','admission_already_spent');
journalNoWrite($jp,fn()=>$jj->result('JOURNAL',$js,1,$intent['operation_id'],$intent['worker_capability'],'hashes-only','instance-1',str_repeat('a',64),null,null),'supplied_hash_is_not_snapshot_bytes');
journalNoWrite($jp,fn()=>$jj->result('JOURNAL',$js,1,$intent['operation_id'],$intent['worker_capability'],'wrong-instance','instance-other',journalBytes(),null,null),'result_wrong_instance','instance_mismatch');
$result=journalResult($jj,$js,$intent);journalCheck($result===journalResult($jj,$js,$intent),'result_ack_exact_receipt');
journalNoWrite($jp,fn()=>$jj->result('JOURNAL',$js,1,$intent['operation_id'],$intent['worker_capability'],'result-1','instance-1',journalBytes('Conflicting'),null,null),'same_request_conflicting_packet','request_conflict');
journalNoWrite($jp,fn()=>$jj->result('JOURNAL',$js,1,$intent['operation_id'],$intent['worker_capability'],'different-result','instance-1',journalBytes('Conflicting'),null,null),'different_request_conflicting_packet','candidate_conflict');
journalCheck($jj->readCandidate('JOURNAL',$js,1,str_repeat('1',64),$intent['operation_id'])['bytes']===journalBytes(),'independent_reader_gets_immutable_candidate_bytes');
journalNoWrite($jp,fn()=>$jj->commit('JOURNAL',$js,1,str_repeat('1',64),$intent['operation_id'],'bad-review',str_repeat('f',64)),'wrong_independent_readback','candidate_readback_mismatch');
$broken=journalService($jp,journalFault('after_commit:commit'));
journalReject(fn()=>journalCommit($broken,$js,$intent),'lost_commit_ack','simulated_crash:after_commit:commit');
$commit=journalCommit($jj,$js,$intent);$before=journalSnapshot($jp);
journalCheck($commit===journalCommit($jj,$js,$intent)&&$before===journalSnapshot($jp),'commit_ack_exact_receipt_no_second_effect');
journalCheck(count($before['hotel_journal_snapshots'])===1,'exactly_one_canonical_snapshot');
journalCheck($jj->canonicalSnapshot('JOURNAL',$js)['bytes']===journalBytes(),'canonical_pointer_reads_stored_bytes_delta_and_ledger');
journalCheck($commit['business_accepted']===false&&$jj->status('JOURNAL',$js)['native_run_verified']===false,'no_business_or_host_proof_claim');
journalCheck($jj->status('JOURNAL',$js)['operations'][0]['native_effect']==='unknown','original_native_unknown_preserved_even_after_snapshot_commit');
journalNoWrite($jp,fn()=>$jj->commit('JOURNAL',$js,1,str_repeat('1',64),$intent['operation_id'],'duplicate-commit',hash('sha256',journalBytes())),'duplicate_intake_new_request','canonical_pointer_conflict');

// Every crash boundary rolls back the entire unit, including ACK row/pointer.
foreach(['intent_written','receipt_written:intent'] as $point){
    [$p,$j,$s]=journalFixture();$before=journalSnapshot($p);$bad=journalService($p,journalFault($point));journalReject(fn()=>journalIntent($bad,$s),'crash_'.$point,'simulated_crash:'.$point);
    journalCheck($before===journalSnapshot($p),'atomic_intent_and_capability_'.$point);journalIntent($j,$s);
}
foreach(['dispatch_intent_written','receipt_written:dispatch'] as $point){
    [$p,$j,$s]=journalFixture();$i=journalIntent($j,$s);$before=journalSnapshot($p);$bad=journalService($p,journalFault($point));journalReject(fn()=>journalDispatch($bad,$s,$i),'crash_'.$point,'simulated_crash:'.$point);
    journalCheck($before===journalSnapshot($p),'before_dispatch_crash_keeps_prepared_'.$point);journalDispatch($j,$s,$i);
}
foreach(['admission_written','receipt_written:admit'] as $point){
    [$p,$j,$s]=journalFixture();$i=journalIntent($j,$s);journalDispatch($j,$s,$i);$before=journalSnapshot($p);$bad=journalService($p,journalFault($point));journalReject(fn()=>journalAdmit($bad,$s,$i),'crash_'.$point,'simulated_crash:'.$point);
    journalCheck($before===journalSnapshot($p),'atomic_admission_'.$point);journalAdmit($j,$s,$i);
}
foreach(['candidate_written','receipt_written:result'] as $point){
    [$p,$j,$s]=journalFixture();$i=journalIntent($j,$s);journalDispatch($j,$s,$i);journalAdmit($j,$s,$i);$before=journalSnapshot($p);$bad=journalService($p,journalFault($point));journalReject(fn()=>journalResult($bad,$s,$i),'crash_'.$point,'simulated_crash:'.$point);
    journalCheck($before===journalSnapshot($p),'atomic_candidate_bytes_'.$point);journalResult($j,$s,$i);
}
foreach(['snapshot_inserted','canonical_pointer_updated','receipt_written:commit'] as $point){
    [$p,$j,$s,$q,$i]=journalCandidate();$before=journalSnapshot($p);$bad=journalService($p,journalFault($point));journalReject(fn()=>journalCommit($bad,$s,$i),'crash_'.$point,'simulated_crash:'.$point);
    journalCheck($before===journalSnapshot($p)&&$j->canonicalSnapshot('JOURNAL',$s)===null,'atomic_pointer_snapshot_ledger_receipt_'.$point);journalCommit($j,$s,$i);
}

// A generation is visible metadata, not a credential. Both old workers have the
// SAME OAuth context; only server-owned control may rotate capabilities.
[$p,$j,$s]=journalFixture();$i=journalIntent($j,$s);$before=journalSnapshot($p);$bad=journalService($p,journalFault('generation_updated'));
journalReject(fn()=>$bad->handover('JOURNAL',$s,1,str_repeat('1',64),str_repeat('2',64),'controller-b','handover-1'),'generation_handover_crash','simulated_crash:generation_updated');
journalCheck($before===journalSnapshot($p),'handover_atomic_rollback');
$bad=journalService($p,journalFault('after_commit:handover'));
journalReject(fn()=>$bad->handover('JOURNAL',$s,1,str_repeat('1',64),str_repeat('2',64),'controller-b','handover-1'),'lost_handover_ack','simulated_crash:after_commit:handover');
$handover=$j->handover('JOURNAL',$s,1,str_repeat('1',64),str_repeat('2',64),'controller-b','handover-1');journalCheck($handover['generation']===2,'handover_ack_replay_requires_new_control');
journalNoWrite($p,fn()=>$j->dispatchIntent('JOURNAL',$s,1,str_repeat('1',64),$i['operation_id'],'old-send'),'old_controller_fenced','control_capability_required');
journalNoWrite($p,fn()=>$j->admit('JOURNAL',$s,1,$i['operation_id'],$i['worker_capability'],'old-admit','old-worker'),'stale_actor_generation_fenced','stale_generation');
journalNoWrite($p,fn()=>$j->admit('JOURNAL',$s,2,$i['operation_id'],$i['worker_capability'],'guessed-generation','old-worker'),'visible_new_generation_is_not_capability','operation_generation_mismatch');
$new=$j->intent('JOURNAL',$s,2,str_repeat('2',64),'new-intent','event-1','existing-task');journalCheck($new['worker_capability']!==$i['worker_capability'],'new_generation_private_capability');
$safe=json_encode($j->status('JOURNAL',$s));journalCheck(!str_contains($safe,$new['worker_capability'])&&!str_contains($safe,str_repeat('2',64)),'old_same_oauth_status_cannot_get_new_control_or_worker_capability');
journalNoWrite($p,fn()=>$j->admit('JOURNAL',$s,2,$new['operation_id'],$i['worker_capability'],'stolen-new-id','old-worker'),'old_capability_cannot_borrow_current_operation_or_generation','worker_capability_required');
journalNoWrite($p,fn()=>$j->handover('JOURNAL',$s,2,$i['worker_capability'],str_repeat('4',64),'old-worker','worker-bump-2'),'worker_cannot_bump_even_with_current_generation','control_capability_required');

// Handover after a send intent DOES NOT turn UNKNOWN into cancellation or
// authorize a replacement. This prototype deliberately has no live takeover.
[$p,$j,$s]=journalFixture();$i=journalIntent($j,$s);journalDispatch($j,$s,$i);
$j->handover('JOURNAL',$s,1,str_repeat('1',64),str_repeat('2',64),'controller-b','handover-unknown');
journalNoWrite($p,fn()=>$j->intent('JOURNAL',$s,2,str_repeat('2',64),'replacement','event-2','existing-task'),'unknown_handover_fail_closed','native_effect_unknown');
journalCheck($j->status('JOURNAL',$s)['operations'][0]['native_effect']==='unknown','handover_preserves_original_unknown');

// Transactional opt-in rejection of actual legacy Queue methods, including
// generation-less complete carrying an old-style valid receipt.
[$p,$j,$s,$q]=journalFixture();$args=['queue_id'=>'JOURNAL','stage_id'=>$s,'claim_key'=>str_repeat('b',64)];$q->claim(1,str_repeat('a',32),$args);
$guarded=new HotelWorkQueue($p,static fn():int=>1793500000,$j->legacyGuard($p));$legacyBefore=$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC);
journalNoWrite($p,fn()=>$guarded->started(1,str_repeat('a',32),$args+['run_id'=>'legacy-run']),'legacy_started_rejects_converted_path','generation_required_legacy_mutation');
$legacyReceipt=['run_id'=>'legacy-run','outcome'=>'accepted_readback','packet_sha256'=>str_repeat('3',64),'readback_sha256'=>str_repeat('4',64),'ledger_sha256'=>str_repeat('5',64),'hotel_count'=>1,'record_count'=>1,'source_count'=>0,'read_update_count'=>0,'criteria'=>null];
journalNoWrite($p,fn()=>$guarded->complete(1,str_repeat('a',32),$args+['receipt'=>$legacyReceipt]),'legacy_complete_rejects_converted_path','generation_required_legacy_mutation');
journalCheck($legacyBefore===$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC)&&$p->query('SELECT COUNT(*) FROM hotel_work_receipts')->fetchColumn()==0,'legacy_rejection_transaction_preserves_real_queue_and_receipts');
journalReject(fn()=>$j->enroll('JOURNAL',str_repeat('f',64),str_repeat('0',64),str_repeat('6',64),'other-owner'),'live_claimed_stage_adoption_rejected','live_adoption_not_supported');
// Negative integration control: the default production factory is NOT switched.
$q->started(1,str_repeat('a',32),$args+['run_id'=>'legacy-run']);$q->complete(1,str_repeat('a',32),$args+['receipt'=>$legacyReceipt]);
journalCheck($q->status(1,str_repeat('a',32),'JOURNAL')['stage_index']===1&&$j->canonicalSnapshot('JOURNAL',$s)===null,'default_legacy_path_not_production_fenced_or_converted');
$allBefore=[$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC),$p->query('SELECT * FROM hotel_work_receipts')->fetchAll(PDO::FETCH_ASSOC),journalSnapshot($p)];
$readback=$guarded->complete(1,str_repeat('a',32),$args+['receipt'=>$legacyReceipt]);
journalCheck($readback['completion_status']==='already_recorded','immutable_legacy_receipt_readback_remains_available');
journalCheck($allBefore===[$p->query('SELECT * FROM hotel_work_queues')->fetchAll(PDO::FETCH_ASSOC),$p->query('SELECT * FROM hotel_work_receipts')->fetchAll(PDO::FETCH_ASSOC),journalSnapshot($p)],'legacy_receipt_replay_no_new_admission_or_mutation');
$conflicting=$legacyReceipt;$conflicting['packet_sha256']=str_repeat('6',64);
journalNoWrite($p,fn()=>$guarded->complete(1,str_repeat('a',32),$args+['receipt'=>$conflicting]),'legacy_receipt_conflict_still_rejected','completion_conflict');

// Same bytes cannot become a second canonical intake in a different stage.
[$p,$j,$s,$q,$i]=journalCandidate();journalCommit($j,$s,$i);
$p->exec('UPDATE hotel_work_queues SET stage_index=1');$s2=$q->status(1,str_repeat('a',32),'JOURNAL')['stage_id'];
$j->enroll('JOURNAL',$s2,str_repeat('0',64),str_repeat('1',64),'controller-a');$i2=journalIntent($j,$s2);journalDispatch($j,$s2,$i2);journalAdmit($j,$s2,$i2);journalResult($j,$s2,$i2);
journalNoWrite($p,fn()=>journalCommit($j,$s2,$i2),'same_packet_second_intake_rejected','packet_already_committed');

// Negative Library control: SQL cannot fence an external file. The prototype's
// own canonical reader stays on independently stored immutable bytes.
$library=tempnam(sys_get_temp_dir(),'hotel-library-negative-');try{
    file_put_contents($library,journalBytes());$canonical=$j->canonicalSnapshot('JOURNAL',$s);
    file_put_contents($library,journalBytes('STALE ACTOR WRITE'));
    journalCheck(file_get_contents($library)!==$canonical['bytes'],'legacy_direct_library_write_is_NOT_prevented');
    journalCheck($j->canonicalSnapshot('JOURNAL',$s)===$canonical,'canonical_sql_snapshot_ignores_unaccepted_library_mutation');
}finally{unlink($library);}

// Corrupt storage/foreign transaction/authentication fail closed; no repair.
journalNoWrite($p,fn()=>(new HotelWorkOperationJournalPrototype($p,2,str_repeat('a',32)))->status('JOURNAL',$s),'foreign_admin_denied','journal_access_denied');
journalNoWrite($p,fn()=>(new HotelWorkOperationJournalPrototype($p,1,str_repeat('b',32)))->status('JOURNAL',$s),'foreign_connection_denied','journal_access_denied');
$p->beginTransaction();try{journalReject(fn()=>journalIntent($j,$s),'foreign_transaction_not_owned','journal_foreign_transaction');journalCheck($p->inTransaction(),'foreign_transaction_preserved');}finally{$p->rollBack();}
$p->prepare('UPDATE hotel_journal_snapshots SET body_bytes=?')->execute(['corrupt']);
journalReject(fn()=>$j->canonicalSnapshot('JOURNAL',$s),'tampered_bytes_never_returned','canonical_bytes_unavailable');

// Hard process death, not only caught exceptions: OS closes the transaction.
foreach(['crash-before','crash-after'] as $action){
    $dir=sys_get_temp_dir().'/hotel-journal-kill-'.bin2hex(random_bytes(6));mkdir($dir);$file=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/journal.sqlite';$process=null;
    try{
        [$p,$j,$s,$q]=journalFixture($file==='MYSQL'?null:$file);$i=journalIntent($j,$s);
        $in=$dir.'/in';$out=$dir.'/out';file_put_contents($in,json_encode(['stage'=>$s,'operation'=>$i['operation_id'],'action'=>$action],JSON_THROW_ON_ERROR));
        $command=[PHP_BINARY];if(php_ini_loaded_file()){$command[]='-c';$command[]=php_ini_loaded_file();}array_push($command,__FILE__,'--journal-child',$file,$in,$out);
        $process=proc_open($command,[0=>['pipe','r'],1=>['file',$out.'.stdout','w'],2=>['file',$out.'.stderr','w']],$pipes);if(!is_resource($process))throw new RuntimeException('journal_crash_child_start');fclose($pipes[0]);
        journalWait($out.'.ready');file_put_contents($in.'.go','go');journalCheck(proc_close($process)===73,$action.'_actual_process_exit');$process=null;
        $st=$j->status('JOURNAL',$s);$snap=journalSnapshot($p);$expected=$action==='crash-before'?'prepared':'native_unknown';
        journalCheck($st['operations'][0]['state']===$expected,$action.'_durable_dispatch_state');
        journalCheck(count(array_filter($snap['hotel_journal_requests'],static fn($r)=>$r['method']==='dispatch'))===($action==='crash-before'?0:1),$action.'_atomic_receipt');
        journalCheck($st['canonical_commits']===0,$action.'_never_implies_acceptance');
    }finally{
        if(is_resource($process)){proc_terminate($process);proc_close($process);}$p=null;$j=null;$q=null;gc_collect_cycles();foreach(glob($dir.'/*')?:[] as $path)unlink($path);rmdir($dir);
    }
}

// Real two-process SQL races, using the existing prepared test DB contract.
foreach(['admit_vs_admit','commit_vs_commit','commit_vs_handover','cross_stage_same_packet'] as $race){
    $dir=sys_get_temp_dir().'/hotel-journal-'.bin2hex(random_bytes(6));mkdir($dir);$file=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/journal.sqlite';$children=[];
    try{
        if($race==='admit_vs_admit'){[$p,$j,$s]=journalFixture($file==='MYSQL'?null:$file);$i=journalIntent($j,$s);journalDispatch($j,$s,$i);}
        else{[$p,$j,$s,$q,$i]=journalCandidate($file==='MYSQL'?null:$file);}
        if($race==='cross_stage_same_packet'){
            $p->exec('UPDATE hotel_work_queues SET stage_index=1');$otherStage=$q->status(1,str_repeat('a',32),'JOURNAL')['stage_id'];
            $j->enroll('JOURNAL',$otherStage,str_repeat('0',64),str_repeat('1',64),'controller-a');$otherIntent=journalIntent($j,$otherStage);journalDispatch($j,$otherStage,$otherIntent);journalAdmit($j,$otherStage,$otherIntent);journalResult($j,$otherStage,$otherIntent);
        }
        foreach([0,1] as $n){
            $a=['stage'=>$s,'operation'=>$i['operation_id'],'capability'=>$i['worker_capability'],'request'=>$race.'-'.$n,'instance'=>'instance-'.$n,
                'action'=>$race==='admit_vs_admit'?'admit':($race==='commit_vs_handover'&&$n===1?'handover':'commit')];
            if($race==='cross_stage_same_packet'&&$n===1){$a['stage']=$otherStage;$a['operation']=$otherIntent['operation_id'];}
            $in=$dir.'/in'.$n;$out=$dir.'/out'.$n;file_put_contents($in,json_encode($a,JSON_THROW_ON_ERROR));
            $command=[PHP_BINARY];if(php_ini_loaded_file()){$command[]='-c';$command[]=php_ini_loaded_file();}array_push($command,__FILE__,'--journal-child',$file,$in,$out);
            $process=proc_open($command,[0=>['pipe','r'],1=>['file',$out.'.stdout','w'],2=>['file',$out.'.stderr','w']],$pipes);if(!is_resource($process))throw new RuntimeException('journal_child_start_failed');fclose($pipes[0]);$children[]=[$process,$out,$in];
        }
        foreach($children as [$process,$out,$in])journalWait($out.'.ready');foreach($children as [$process,$out,$in])file_put_contents($in.'.go','go');
        $results=[];foreach($children as [$process,$out,$in]){journalWait($out);journalCheck(proc_close($process)===0,$race.'_child_exit');$results[]=json_decode((string)file_get_contents($out),true,32,JSON_THROW_ON_ERROR);}
        $children=[];$state=$j->status('JOURNAL',$s);$snap=journalSnapshot($p);
        if($race==='admit_vs_admit')journalCheck(count(array_filter($results,static fn($r)=>$r['ok']))===1&&count(array_filter($snap['hotel_journal_requests'],static fn($r)=>$r['method']==='admit'))===1,$race.'_one_admission');
        elseif($race==='commit_vs_commit')journalCheck(count(array_filter($results,static fn($r)=>$r['ok']))===1&&count($snap['hotel_journal_snapshots'])===1&&$state['canonical_commits']===1,$race.'_one_canonical_effect');
        elseif($race==='cross_stage_same_packet')journalCheck(count(array_filter($results,static fn($r)=>$r['ok']))===1&&count($snap['hotel_journal_snapshots'])===1,$race.'_one_packet_one_intake');
        else journalCheck($state['generation']===2&&count($snap['hotel_journal_snapshots'])<=1&&($results[0]['ok']||$state['canonical_commits']===0),$race.'_stale_commit_fenced_or_precedes_handover');
    }finally{
        foreach($children as [$process,$out,$in])if(is_resource($process)){proc_terminate($process);proc_close($process);}
        $p=null;$j=null;$q=null;gc_collect_cycles();
        foreach(glob($dir.'/*')?:[] as $path)unlink($path);rmdir($dir);
    }
}
echo 'HOTEL OPERATION JOURNAL PROTOTYPE: '.$journalChecks." PASS (4 real SQL process races + 2 process deaths; no HOST/business acceptance)\n";
