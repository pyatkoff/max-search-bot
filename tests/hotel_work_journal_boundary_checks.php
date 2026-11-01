<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/HotelWorkQueueFactory.php';
require_once dirname(__DIR__).'/services/HotelWorkOperationJournalPrototype.php';
require_once __DIR__.'/fixtures/hotel_work_queue.php';
require_once __DIR__.'/fixtures/hotel_work_operation_journal.php';

$journalBoundaryChecks=0;
function boundaryCheck(bool $ok,string $name):void {
    global $journalBoundaryChecks;++$journalBoundaryChecks;
    if(!$ok) throw new RuntimeException('journal_boundary_assertion:'.$name);
}
function boundaryWait(string $path):void {
    $until=microtime(true)+15;while(!is_file($path)&&microtime(true)<$until) usleep(1000);
    if(!is_file($path)) throw new RuntimeException('journal_boundary_barrier:'.basename($path));
}
function boundarySnapshot(PDO $pdo):array {
    $out=[];
    foreach(['hotel_work_lane','hotel_work_queues','hotel_work_receipts','hotel_work_resolutions','hotel_work_connection_handoffs',
        'hotel_work_delivery_recoveries','hotel_work_research_skips','hotel_work_consumer_delivery_repairs',
        'hotel_work_execution_attempts','hotel_work_execution_attempt_entries','hotel_work_execution_attempt_associations',
        'hotel_journal_heads','hotel_journal_operations','hotel_journal_requests','hotel_journal_snapshots'] as $table) {
        try{$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);}
        catch(PDOException $missing){$out[$table]='missing';}
    }
    return $out;
}
function boundaryReject(PDO $pdo,callable $work,string $name,string $reason='generation_required_legacy_mutation'):void {
    $before=boundarySnapshot($pdo);$caught=null;
    try{$work();}catch(Throwable $e){$caught=$e;}
    boundaryCheck($caught instanceof HotelWorkQueueConflict&&$caught->getMessage()===$reason,$name.'_typed_hold');
    boundaryCheck($before===boundarySnapshot($pdo),$name.'_no_writes');
}
function boundaryReplay(PDO $pdo,callable $work,string $name):array {
    $before=boundarySnapshot($pdo);$result=$work();
    boundaryCheck($before===boundarySnapshot($pdo),$name.'_read_only');return $result;
}
/** Only adversarial test fixtures use this helper to retain old claimed/history
 * state while testing the fence. Production enroll never adopts that state. */
function boundaryProtectHistorical(PDO $pdo,string $queue='PILOT'):void {
    hotelJournalFixtureSchema($pdo);
    $s=$pdo->prepare('SELECT * FROM hotel_work_queues WHERE queue_id=?');$s->execute([$queue]);$row=$s->fetch(PDO::FETCH_ASSOC);
    $stage=hash('sha256',json_encode([$queue,$row['plan_sha256'],(int)$row['stage_index']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $pdo->prepare('INSERT INTO hotel_journal_heads(queue_id,stage_id,baseline_sha256,generation,control_sha256,owner_label) VALUES(?,?,?,1,?,?)')
        ->execute([$queue,$stage,$row['baseline_sha256'],hash('sha256',str_repeat('1',64)),'test-fixture-only']);
    $pdo->prepare('UPDATE hotel_work_queues SET write_protocol=? WHERE queue_id=?')->execute(['journal_v1',$queue]);
}

// Standalone child entry point: never recurse through the parent Queue suite.
if(($argv[1]??'')==='--journal-boundary-child') {
    [$unused,$mode,$file,$input,$action,$out,$winner]=array_pad($argv,7,'');
    $a=json_decode((string)file_get_contents($input),true,16,JSON_THROW_ON_ERROR);
    $pdo=new HotelWorkTestPdo($file==='MYSQL'?null:$file,false);$armed=$winner==='1';
    $hold=static function()use($out):void{file_put_contents($out.'.locked','locked');boundaryWait($out.'.release');};
    $clock=static function()use(&$armed,$hold):int{if($armed){$armed=false;$hold();}return 1793500000;};
    $queue=new HotelWorkQueue($pdo,$clock);
    $journal=new HotelWorkOperationJournalPrototype($pdo,1,str_repeat('a',32),static function(string $point)use(&$armed,$hold):void{
        if($armed&&$point==='queue_locked'){$armed=false;$hold();}
    });
    file_put_contents($out.'.ready','ready');boundaryWait($out.'.go');file_put_contents($out.'.attempting','attempting');
    try{
        $result=match($action){
            'enroll'=>$journal->enroll('BOUNDARY',$a['stage'],str_repeat('0',64),str_repeat('1',64),'controller'),
            'claim'=>$queue->claim(1,str_repeat('a',32),['queue_id'=>'BOUNDARY','stage_id'=>$a['stage'],'claim_key'=>str_repeat('b',64)]),
            'release'=>$queue->release(1,str_repeat('a',32),['queue_id'=>'BOUNDARY','stage_id'=>$a['stage']]),
            'handoff'=>$queue->handoff_connection(1,str_repeat('f',32),['queue_id'=>'BOUNDARY','stage_id'=>$a['stage'],'baseline_sha256'=>str_repeat('0',64),'old_connection_id'=>str_repeat('a',32)],static fn():bool=>true),
            default=>throw new LogicException('invalid_boundary_action'),
        };
        $r=['ok'=>true,'result'=>$result];
    }catch(Throwable $e){$r=['ok'=>false,'class'=>get_class($e),'reason'=>$e->getMessage()];}
    file_put_contents($out,json_encode($r,JSON_THROW_ON_ERROR));exit(0);
}

// Included after the existing Queue/attempt suites: reuse their real valid
// fixtures so denial is exercised at the write boundary, not input parsing.
if(!function_exists('hotelFixture')||!function_exists('executionPrepared')) throw new LogicException('include_boundary_after_queue_suites');
$inventory=json_decode((string)file_get_contents(dirname(__DIR__).'/docs/HOTEL_JOURNAL_MUTATION_INVENTORY.json'),true,16,JSON_THROW_ON_ERROR);
$public=[];foreach((new ReflectionClass(HotelWorkQueue::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method)if($method->name!=='__construct')$public[]=$method->name;
$listed=array_merge(array_keys($inventory['writes']),$inventory['reads']);sort($public);sort($listed);
boundaryCheck($public===$listed&&count($inventory['writes'])===14,'all_public_methods_in_boundary_inventory');

[$p,$q,$clock]=hotelFixture();hotelJournalFixtureSchema($p);$s=hotelStatus($q);
$precreated=HotelWorkQueueFactory::create($p,static fn():int=>$clock->at,false);
$event=$q->pendingEvent(1,str_repeat('a',32),'PILOT');
$j=new HotelWorkOperationJournalPrototype($p,1,str_repeat('a',32));
$j->enroll('PILOT',$s['stage_id'],str_repeat('0',64),str_repeat('1',64),'controller');
$args=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('b',64)];
foreach(['raw_precreated'=>$q,'factory_precreated_false'=>$precreated,'factory_new_false'=>HotelWorkQueueFactory::create($p,null,false),
    'raw_new'=>new HotelWorkQueue($p),'factory_new_true'=>HotelWorkQueueFactory::create($p,null,true)] as $label=>$queue)
    boundaryReject($p,fn()=>$queue->claim(1,str_repeat('a',32),$args),$label);
boundaryReject($p,fn()=>$q->started(1,str_repeat('a',32),$args+['run_id'=>'RUN']),'started');
boundaryReject($p,fn()=>$q->complete(1,str_repeat('a',32),$args+['receipt'=>hotelReceipt()]),'complete');
boundaryReject($p,fn()=>$q->release(1,str_repeat('a',32),['queue_id'=>'PILOT','stage_id'=>$s['stage_id']]),'release');
boundaryReject($p,fn()=>$q->pendingEvent(1,str_repeat('a',32),'PILOT'),'pending_event_no_write_or_dispatch');
boundaryReject($p,fn()=>$q->markEventDelivered(1,str_repeat('a',32),'PILOT',$event['event_id']),'delivery_ack');
boundaryReject($p,fn()=>$q->handoff_connection(1,str_repeat('f',32),['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'baseline_sha256'=>str_repeat('0',64),'old_connection_id'=>str_repeat('a',32)],static fn():bool=>true),'handoff');
$created=boundaryReplay($p,fn()=>$q->create(1,str_repeat('a',32),['queue_id'=>'PILOT','baseline_sha256'=>str_repeat('0',64)]),'create_exact_existing_plan');
boundaryCheck($created['created']===false,'create_is_observation_not_recreation');
boundaryReplay($p,fn()=>$q->status(1,str_repeat('a',32),'PILOT'),'status_remains_readable');
$p->exec('DROP TABLE hotel_journal_heads');
boundaryReject($p,fn()=>$q->claim(1,str_repeat('a',32),$args),'missing_protected_store','journal_protection_unavailable');
// Legacy-only operation requires no optional journal table.
[$p,$q]=hotelFixture();$p->exec('DROP TABLE IF EXISTS hotel_journal_heads');$s=hotelStatus($q);
$claim=$q->claim(1,str_repeat('a',32),['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('b',64)]);
boundaryCheck($claim['claim_status']==='claimed','unenrolled_missing_store_keeps_legacy_behavior');

// Enrollment and marker fail/commit together; no runtime DDL or activation flag.
[$p,$q]=hotelFixture();hotelJournalFixtureSchema($p);$s=hotelStatus($q);$before=boundarySnapshot($p);
$j=new HotelWorkOperationJournalPrototype($p,1,str_repeat('a',32),static function(string $at):void{if($at==='enrollment_written')throw new RuntimeException('enrollment_fault');});
try{$j->enroll('PILOT',$s['stage_id'],str_repeat('0',64),str_repeat('1',64),'controller');}catch(RuntimeException $e){boundaryCheck($e->getMessage()==='enrollment_fault','enrollment_fault_reached');}
boundaryCheck($before===boundarySnapshot($p),'enrollment_marker_and_head_rollback_together');
$j=new HotelWorkOperationJournalPrototype($p,1,str_repeat('a',32),static function(string $at):void{if($at==='after_commit:enroll')throw new RuntimeException('enrollment_ack_lost');});
try{$j->enroll('PILOT',$s['stage_id'],str_repeat('0',64),str_repeat('1',64),'controller');}catch(RuntimeException $e){boundaryCheck($e->getMessage()==='enrollment_ack_lost','enrollment_ack_fault_reached');}
$j=new HotelWorkOperationJournalPrototype($p,1,str_repeat('a',32));
boundaryReplay($p,fn()=>$j->enroll('PILOT',$s['stage_id'],str_repeat('0',64),str_repeat('1',64),'controller'),'enrollment_ack_reconciliation');
boundaryCheck($p->query('SELECT write_protocol FROM hotel_work_queues')->fetchColumn()==='journal_v1','committed_marker_is_sticky');

[$p,$q,$clock,$identity]=hotelDeliveryRepairFixture();$repair=hotelDeliveryRepairArgs($q,$identity);boundaryProtectHistorical($p);
boundaryReject($p,fn()=>$q->repair_delivery(1,str_repeat('a',32),$repair),'repair_delivery');
[$p,$q,$clock,$identity]=hotelDeliveryRepairFixture();$repair=hotelDeliveryRepairArgs($q,$identity);$q->repair_delivery(1,str_repeat('a',32),$repair);boundaryProtectHistorical($p);
boundaryReplay($p,fn()=>$q->repair_delivery(1,str_repeat('a',32),$repair),'repair_receipt');
[$p,$q,$clock,$h]=hotelResumeFixture();boundaryProtectHistorical($p);
boundaryReject($p,fn()=>$q->handoff_connection(1,str_repeat('a',32),$h,static fn():bool=>true),'same_binding_resume');
[$p,$q,$clock,$h]=hotelResumeFixture();$q->handoff_connection(1,str_repeat('a',32),$h,static fn():bool=>true);boundaryProtectHistorical($p);
boundaryReplay($p,fn()=>$q->handoff_connection(1,str_repeat('a',32),$h,static fn():bool=>false),'handoff_receipt');
[$p,$q,$caller,$skip,$proof,$claim]=hotelSkipFixture(true,true);boundaryProtectHistorical($p);
boundaryReject($p,fn()=>$q->skip_research(1,$caller,$skip,$proof),'skip_research');
$resolve=$claim+['unknown_receipt_sha256'=>$skip['expected_unknown_receipt_sha256'],'expected_run_id'=>'ABANDONED_RESEARCH','expected_packet_sha256'=>str_repeat('1',64),'receipt'=>hotelReceipt('ABANDONED_RESEARCH')];
boundaryReject($p,fn()=>$q->resolve_existing_run(1,$caller,$resolve),'resolve_existing_run');
[$p,$q,$caller,$skip,$proof,$claim]=hotelSkipFixture(true,true);
$resolve=$claim+['unknown_receipt_sha256'=>$skip['expected_unknown_receipt_sha256'],'expected_run_id'=>'ABANDONED_RESEARCH','expected_packet_sha256'=>str_repeat('1',64),'receipt'=>hotelReceipt('ABANDONED_RESEARCH')];
$q->resolve_existing_run(1,$caller,$resolve);boundaryProtectHistorical($p);
boundaryReplay($p,fn()=>$q->resolve_existing_run(1,$caller,$resolve),'resolution_receipt');
[$p,$q,$caller,$skip,$proof]=hotelSkipFixture(true,true);$q->skip_research(1,$caller,$skip,$proof);boundaryProtectHistorical($p);
boundaryReplay($p,fn()=>$q->skip_research(1,$caller,$skip,$proof),'skip_receipt');

[$p,$q,$clock,$identity]=executionFixture();$prepare=executionPrepareArgs($q,$identity);boundaryProtectHistorical($p,'ATTEMPT');
boundaryReject($p,fn()=>$q->prepare_execution_attempt(1,str_repeat('a',32),$prepare),'prepare_execution_attempt');
[$p,$q,$clock,$identity,$claim,$prepare]=executionPrepared();boundaryProtectHistorical($p,'ATTEMPT');
boundaryReplay($p,fn()=>$q->prepare_execution_attempt(1,str_repeat('a',32),$prepare),'permit_receipt');
boundaryReject($p,fn()=>$q->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($prepare)),'enter_execution_attempt');
[$p,$q,$clock,$identity,$claim,$prepare,$permit,$entry]=executionEntered();$bind=executionBindArgs($q,$identity,$prepare,$entry);boundaryProtectHistorical($p,'ATTEMPT');
$again=boundaryReplay($p,fn()=>$q->enter_execution_attempt(1,str_repeat('a',32),executionEnterArgs($prepare)),'entry_receipt');
boundaryCheck($again['work_authorized']===false,'entry_receipt_never_readmits');
boundaryReject($p,fn()=>$q->bind_execution_attempt(1,str_repeat('a',32),$bind),'bind_execution_attempt');
[$p,$q,$clock,$identity,$claim,$prepare,$permit,$entry,$bind]=executionBound();boundaryProtectHistorical($p,'ATTEMPT');
boundaryReplay($p,fn()=>$q->bind_execution_attempt(1,str_repeat('a',32),$bind),'association_receipt');

// All lifecycle writers have matching deterministic both-order races.
foreach(['claim','handoff','release'] as $legacy) foreach([true,false] as $enrollWins){
    $dir=sys_get_temp_dir().'/hotel-boundary-'.bin2hex(random_bytes(6));mkdir($dir);$children=[];$p=null;$q=null;
    $file=getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'MYSQL':$dir.'/queue.sqlite';
    try{
        $p=new HotelWorkTestPdo($file==='MYSQL'?null:$file);hotelJournalFixtureSchema($p);$q=new HotelWorkQueue($p,static fn():int=>1793500000);
        $s=$q->create(1,str_repeat('a',32),['queue_id'=>'BOUNDARY','baseline_sha256'=>str_repeat('0',64)]);
        file_put_contents($dir.'/input',json_encode(['stage'=>$s['stage_id']],JSON_THROW_ON_ERROR));
        $actions=$enrollWins?['enroll',$legacy]:[$legacy,'enroll'];
        foreach($actions as $i=>$action){
            $out=$dir.'/out'.$i;$pipes=[];
            $command=[PHP_BINARY];$ini=php_ini_loaded_file();if(is_string($ini)&&$ini!=='')$command=[...$command,'-c',$ini];
            $command=[...$command,__FILE__,'--journal-boundary-child',$file,$dir.'/input',$action,$out,$i===0?'1':'0'];
            $process=proc_open($command,[0=>['pipe','r'],1=>['file',$out.'.stdout','w'],2=>['file',$out.'.stderr','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('boundary_child_start_failed');fclose($pipes[0]);$children[]=[$process,$out];
            boundaryWait($out.'.ready');
        }
        file_put_contents($children[0][1].'.go','go');boundaryWait($children[0][1].'.locked');
        file_put_contents($children[1][1].'.go','go');boundaryWait($children[1][1].'.attempting');
        boundaryCheck(!is_file($children[1][1]),'loser_cannot_finish_under_winner_lock_'.$legacy);
        file_put_contents($children[0][1].'.release','release');
        $results=[];foreach($children as [$process,$out]){boundaryWait($out);$code=proc_close($process);boundaryCheck($code===0,'race_child_exit');$results[]=json_decode((string)file_get_contents($out),true,16,JSON_THROW_ON_ERROR);}
        $children=[];
        boundaryCheck($results[0]['ok']===true&&$results[1]['ok']===false,'exactly_winner_succeeds_'.$legacy);
        $reason=$enrollWins?'generation_required_legacy_mutation':($legacy==='handoff'?'journal_access_denied':'live_adoption_not_supported');
        boundaryCheck(($results[1]['reason']??null)===$reason,'loser_current_read_reason_'.$legacy);
        $row=$p->query('SELECT * FROM hotel_work_queues')->fetch(PDO::FETCH_ASSOC);
        boundaryCheck($row['write_protocol']===($enrollWins?'journal_v1':'legacy'),'race_marker_matches_winner');
        boundaryCheck((int)$p->query('SELECT COUNT(*) FROM hotel_journal_heads')->fetchColumn()===($enrollWins?1:0),'race_head_matches_winner');
        if($enrollWins)boundaryCheck($row['state']==='ready'&&$row['claim_sha256']===null&&$row['oauth_connection_id']===str_repeat('a',32),'enrollment_preserves_idle_owner');
    }finally{
        foreach($children as [$process,$out])if(is_resource($process)){@file_put_contents($out.'.go','go');@file_put_contents($out.'.release','release');proc_terminate($process);proc_close($process);}
        $q=null;$p=null;foreach(glob($dir.'/*')?:[] as $path)@unlink($path);@rmdir($dir);
    }
}
echo 'HOTEL JOURNAL BOUNDARY: '.$journalBoundaryChecks." PASS (6 real SQL process races; sticky marker; no HOST calls)\n";
