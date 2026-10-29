<?php
declare(strict_types=1);

/** Included by the existing SQLite and native MySQL HOTEL queue regression. */
function hotelSkipFixture(bool $unknown=false,bool $sameConnection=false): array
{
    [$pdo,$q,$clock]=hotelFixture();$claim=hotelStart($q,'ABANDONED_RESEARCH');
    $unknownHash=null;
    if ($unknown) {
        $receipt=['run_id'=>'ABANDONED_RESEARCH','outcome'=>'unknown','packet_sha256'=>null,'readback_sha256'=>null,
            'ledger_sha256'=>null,'hotel_count'=>null,'record_count'=>null,'source_count'=>null,'read_update_count'=>null,'criteria'=>null];
        $unknownHash=$q->complete(1,str_repeat('a',32),$claim+['receipt'=>$receipt])['receipt_sha256'];
    }
    $before=hotelStatus($q);$caller=str_repeat($sameConnection?'a':'f',32);
    $args=['queue_id'=>'PILOT','stage_id'=>$before['stage_id'],'baseline_sha256'=>$before['baseline_sha256'],
        'old_connection_id'=>str_repeat('a',32),'expected_caller_connection_id'=>$caller,'expected_run_id'=>'ABANDONED_RESEARCH',
        'expected_unknown_receipt_sha256'=>$unknownHash,'decision_sha256'=>str_repeat('d',64),
        'external_effects'=>['status'=>$unknown?'isolated':'stopped','readback_sha256'=>str_repeat('e',64)]];
    $proof=static fn(string $id,int $admin,string $mode):bool=>$admin===1
        &&(($id===$caller&&$mode==='current')||($id===str_repeat('a',32)&&$mode==='ready'));
    return [$pdo,$q,$caller,$args,$proof,$claim,$before];
}

function hotelSkipSnapshot(PDO $pdo): array
{
    $out=[];
    foreach (['hotel_work_lane','hotel_work_queues','hotel_work_receipts','hotel_work_resolutions',
        'hotel_work_connection_handoffs','hotel_work_delivery_recoveries','hotel_work_research_skips'] as $table)
        $out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}

foreach ([false,true] as $unknown) foreach ([false,true] as $sameConnection) {
    [$db,$queue,$caller,$args,$proof,$claim,$before]=hotelSkipFixture($unknown,$sameConnection);
    $history=hotelSkipSnapshot($db);$oldEvent=$queue->pendingEvent(1,str_repeat('a',32),'PILOT');
    $result=$queue->skip_research(1,$caller,$args,$proof);
    hotelAssert($result['skip_status']==='research_batch_skipped'&&$result['stage_index']===2
        &&$result['stage_kind']==='research'&&$result['country']==='TH'&&$result['state']==='ready',
        'skip advances research and its intake to the next country');
    hotelAssert($result['accepted_batches']===0&&$result['phase_counter']===$before['phase_counter']
        &&$result['receipt_count']===($unknown?1:0)&&$result['skipped_batches']===1,
        'skip never counts abandoned hotels or imaginary receipts as accepted work');
    hotelAssert($result['dispatch_allowed']===false&&$result['external_run_cancelled']===false
        &&$db->query('SELECT queue_id FROM hotel_work_lane')->fetchColumn()==='PILOT',
        'skip retains the shared lane and does not claim hosted cancellation or dispatch');
    $audit=$db->query('SELECT * FROM hotel_work_research_skips')->fetch(PDO::FETCH_ASSOC);
    hotelAssert($audit['run_id']==='ABANDONED_RESEARCH'&&$audit['claim_sha256']===hash('sha256',$claim['claim_key'])
        &&$audit['previous_state']===($unknown?'unknown':'claimed')&&$audit['unknown_receipt_sha256']===$args['expected_unknown_receipt_sha256']
        &&$audit['external_effects_readback_sha256']===$args['external_effects']['readback_sha256'],
        'append-only skip audit retains the exact old claim run and effects attestation');
    hotelAssert($db->query('SELECT * FROM hotel_work_receipts')->fetchAll(PDO::FETCH_ASSOC)===$history['hotel_work_receipts']
        &&$db->query('SELECT * FROM hotel_work_resolutions')->fetchAll(PDO::FETCH_ASSOC)===$history['hotel_work_resolutions'],
        'skip preserves original UNKNOWN and historical resolution evidence');
    hotelReject(fn()=>$queue->complete(1,$caller,$claim+['receipt'=>hotelReceipt('ABANDONED_RESEARCH')]),
        HotelWorkQueueConflict::class,'late abandoned completion is rejected','research_batch_skipped');
    if ($unknown) hotelReject(fn()=>$queue->resolve_existing_run(1,$caller,$claim+[
        'unknown_receipt_sha256'=>$args['expected_unknown_receipt_sha256'],'expected_run_id'=>'ABANDONED_RESEARCH',
        'expected_packet_sha256'=>str_repeat('1',64),'receipt'=>hotelReceipt('ABANDONED_RESEARCH')]),
        HotelWorkQueueConflict::class,'late abandoned UNKNOWN resolution is rejected','research_batch_skipped');
    hotelReject(fn()=>$queue->started(1,$caller,$claim+['run_id'=>'ABANDONED_RESEARCH']),
        HotelWorkQueueConflict::class,'abandoned stage cannot bind another run','stale_stage');
    if (!$sameConnection) {
        hotelReject(fn()=>$queue->complete(1,str_repeat('a',32),$claim+['receipt'=>hotelReceipt('ABANDONED_RESEARCH')]),
            DomainException::class,'old connection loses only this queue authority');
        $takeBack=['queue_id'=>'PILOT','stage_id'=>$result['stage_id'],'baseline_sha256'=>$result['baseline_sha256'],
            'old_connection_id'=>$caller];
        hotelReject(fn()=>$queue->handoff_connection(1,str_repeat('a',32),$takeBack,static fn():bool=>true),
            HotelWorkQueueConflict::class,'skipped queue cannot be reacquired by relinquished binding','binding_relinquished');
    }
    $pending=$queue->pendingEvent(1,$caller,'PILOT');
    hotelAssert($pending!==null&&$pending['event_id']===$result['expected_pending_event_id']
        &&($oldEvent===null||$oldEvent['event_id']!==$pending['event_id']),'next batch has a distinct pending identity');
    $nextClaim=['queue_id'=>'PILOT','stage_id'=>$result['stage_id'],'claim_key'=>str_repeat('c',64)];
    hotelReject(fn()=>$queue->claim(1,$caller,$nextClaim),HotelWorkQueueConflict::class,
        'next batch requires the received pending identity','pending_event_required');
    hotelReject(fn()=>$queue->claim(1,$caller,$nextClaim+['expected_pending_event_id'=>'hotel_evt_'.str_repeat('0',64)]),
        HotelWorkQueueConflict::class,'old or manufactured pending identity cannot claim next batch','stale_pending_event');
    $queue->claim(1,$caller,$nextClaim+['expected_pending_event_id'=>$pending['event_id']]);
    $queue->started(1,$caller,$nextClaim+['run_id'=>'NEXT_RESEARCH']);
    $snapshot=hotelSkipSnapshot($db);$writes=count($db->writes);
    $repeat=$queue->skip_research(1,$caller,$args,$proof);
    hotelAssert($repeat['skip_status']==='already_recorded'&&$repeat['current_run_id']==='NEXT_RESEARCH'
        &&hotelSkipSnapshot($db)===$snapshot&&count($db->writes)===$writes,'exact skip replay never advances or clears the next run');
    $changed=$args;$changed['decision_sha256']=str_repeat('c',64);
    hotelReject(fn()=>$queue->skip_research(1,$caller,$changed,$proof),HotelWorkQueueConflict::class,
        'changed skip decision cannot reuse the previous stage','skip_conflict');
    hotelAssert(hotelSkipSnapshot($db)===$snapshot,'conflicting skip leaves the next run untouched');
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        foreach (explode(';',(string)file_get_contents(dirname(__DIR__).'/migrations/076_hotel_work_research_skips.sql')) as $sql)
            if (trim($sql)!=='') $db->exec($sql);
        hotelAssert(hotelSkipSnapshot($db)===$snapshot,'native forward migration reapply preserves skips and active work');
    }
}

foreach (['wrong_admin','wrong_old','wrong_caller','baseline','stage','run','receipt','missing_evidence',
    'unknown_effects','invalid_evidence','current_revoked','old_foreign','revoked_before_write','revoked_after_write'] as $case) {
    [$db,$queue,$caller,$args,$proof]=hotelSkipFixture();$snapshot=hotelSkipSnapshot($db);$admin=1;
    $class=HotelWorkQueueConflict::class;$reason=null;
    switch ($case) {
        case 'wrong_admin': $admin=2;$class=DomainException::class;break;
        case 'wrong_old': $args['old_connection_id']=str_repeat('e',32);$class=DomainException::class;break;
        case 'wrong_caller': $args['expected_caller_connection_id']=str_repeat('e',32);$reason='caller_connection_changed';break;
        case 'baseline': $args['baseline_sha256']=str_repeat('1',64);$reason='baseline_mismatch';break;
        case 'stage': $args['stage_id']=str_repeat('1',64);$reason='stale_stage';break;
        case 'run': $args['expected_run_id']='DIFFERENT_RUN';$reason='existing_run_proof_mismatch';break;
        case 'receipt': $args['expected_unknown_receipt_sha256']=str_repeat('1',64);$reason='stage_receipt_mismatch';break;
        case 'missing_evidence': unset($args['external_effects']);$class=InvalidArgumentException::class;break;
        case 'unknown_effects': $args['external_effects']['status']='unknown';$class=InvalidArgumentException::class;break;
        case 'invalid_evidence': $args['external_effects']['readback_sha256']=true;$class=InvalidArgumentException::class;break;
        case 'current_revoked': $proof=static fn(string $id,int $a,string $mode):bool=>$mode!=='current';$class=DomainException::class;break;
        case 'old_foreign': $proof=static fn(string $id,int $a,string $mode):bool=>$mode!=='ready';$class=DomainException::class;break;
        default:
            $calls=0;$limit=$case==='revoked_before_write'?2:4;
            $proof=static function()use(&$calls,$limit):bool{return ++$calls<=$limit;};$class=DomainException::class;
    }
    hotelReject(fn()=>$queue->skip_research($admin,$caller,$args,$proof),$class,'invalid skip rejected: '.$case,$reason);
    hotelAssert(hotelSkipSnapshot($db)===$snapshot,'invalid skip has no persisted effects: '.$case);
}

[$db,$queue,$caller,$args,$proof]=hotelSkipFixture(true);
$snapshot=hotelSkipSnapshot($db);$args['expected_unknown_receipt_sha256']=str_repeat('0',64);
hotelReject(fn()=>$queue->skip_research(1,$caller,$args,$proof),HotelWorkQueueConflict::class,
    'UNKNOWN skip pins its original receipt','unknown_receipt_mismatch');
hotelAssert(hotelSkipSnapshot($db)===$snapshot,'mismatched UNKNOWN receipt remains unchanged');

[$db,$queue,$caller,$args,$proof,$claim]=hotelSkipFixture();
$queue->complete(1,str_repeat('a',32),$claim+['receipt'=>hotelReceipt('ABANDONED_RESEARCH')]);
hotelReject(fn()=>$queue->skip_research(1,$caller,$args,$proof),HotelWorkQueueConflict::class,
    'completion winning first prevents the old skip','stale_stage');
$intake=hotelStart($queue,'CENTRAL_ALREADY_STARTED');$status=hotelStatus($queue);
$args['stage_id']=$status['stage_id'];$args['expected_run_id']='CENTRAL_ALREADY_STARTED';$snapshot=hotelSkipSnapshot($db);
hotelReject(fn()=>$queue->skip_research(1,$caller,$args,$proof),HotelWorkQueueConflict::class,
    'an intake with possible central writes cannot be skipped','research_stage_required');
hotelAssert(hotelSkipSnapshot($db)===$snapshot,'rejected intake skip preserves original claim and accepted research');

[$db,$queue,$caller,$args,$proof]=hotelSkipFixture();
$queue->skip_research(1,$caller,$args,$proof);
for ($batch=0;$batch<3;++$batch) {
    foreach (['research','intake'] as $kind) {
        $s=$queue->status(1,$caller,'PILOT');$run='NEW_'.$batch.'_'.$kind;
        $claim=['queue_id'=>'PILOT','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('c',64)];
        $queue->claim(1,$caller,$claim+['expected_pending_event_id'=>$s['expected_pending_event_id']]);
        $queue->started(1,$caller,$claim+['run_id'=>$run]);
        $queue->complete(1,$caller,$claim+['receipt'=>hotelReceipt($run,'1',$s['criteria_due']?hotelCriteria():null)]);
    }
}
$s=$queue->status(1,$caller,'PILOT');
hotelAssert($s['state']==='complete'&&$s['accepted_batches']===3&&$s['receipt_count']===6&&$s['skipped_batches']===1
    &&$s['stage_index']===8&&$db->query('SELECT queue_id FROM hotel_work_lane')->fetchColumn()===null,
    'finite queue still needs three accepted intakes after one abandoned batch');

foreach ([['skip','complete'],['complete','skip'],['skip','skip']] as [$first,$second]) {
    $prefix=tempnam(sys_get_temp_dir(),'hotel-skip-');if ($prefix===false) throw new RuntimeException('skip_race_fixture');
    unlink($prefix);$file=$prefix.'.sqlite';$input=$prefix.'.json';
    $db=new HotelWorkTestPdo($file);$queue=new HotelWorkQueue($db,static fn():int=>1793100000);
    $s=$queue->create(1,str_repeat('a',32),['queue_id'=>'RACE','baseline_sha256'=>str_repeat('0',64)]);
    $claim=['queue_id'=>'RACE','stage_id'=>$s['stage_id'],'claim_key'=>str_repeat('b',64)];
    $queue->claim(1,str_repeat('a',32),$claim);$queue->started(1,str_repeat('a',32),$claim+['run_id'=>'ORIGINAL_RACE']);
    $args=['queue_id'=>'RACE','stage_id'=>$s['stage_id'],'baseline_sha256'=>$s['baseline_sha256'],
        'old_connection_id'=>str_repeat('a',32),'expected_caller_connection_id'=>str_repeat('a',32),'expected_run_id'=>'ORIGINAL_RACE',
        'expected_unknown_receipt_sha256'=>null,'decision_sha256'=>str_repeat('d',64),
        'external_effects'=>['status'=>'stopped','readback_sha256'=>str_repeat('e',64)]];
    file_put_contents($input,json_encode($args,JSON_THROW_ON_ERROR));$workers=[];
    $wait=static function(string $path):void {
        $until=microtime(true)+10;while (!is_file($path)&&microtime(true)<$until) usleep(1000);
        if (!is_file($path)) throw new RuntimeException('skip_race_parent_timeout: '.basename($path));
    };
    try {
        foreach ([$first,$second] as $i=>$action) {
            $out=$prefix.'.'.$i;
            $process=proc_open([PHP_BINARY,__DIR__.'/run_hotel_work_queue_regression.php','--skip-race-child',
                $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'MYSQL':$file,$input,$action,$i===0?'1':'0',$out],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('skip_race_process');
            fclose($pipes[0]);$workers[]=[$process,$pipes,$out];
        }
        foreach ($workers as $worker) $wait($worker[2].'.ready');
        touch($workers[0][2].'.go');$wait($workers[0][2].'.locked');
        touch($workers[1][2].'.go');$wait($workers[1][2].'.attempting');
        usleep(20000);hotelAssert(!is_file($workers[1][2]),'competing skip/completion waits for the held lane transaction');
        touch($workers[0][2].'.release');
        foreach ($workers as [$process,$pipes,$out]) {
            $wait($out);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
            hotelAssert($exit===0&&$stdout===''&&$stderr==='','skip race child completes cleanly');
        }
        $results=[file_get_contents($prefix.'.0'),file_get_contents($prefix.'.1')];
        $expected=$first==='complete'?['completed','conflict:stale_stage']:
            ['research_batch_skipped',$second==='complete'?'conflict:research_batch_skipped':'already_recorded'];
        $s=$queue->status(1,str_repeat('a',32),'RACE');
        hotelAssert($results===$expected&&$s['stage_index']===($first==='complete'?1:2)
            &&$s['receipt_count']===($first==='complete'?1:0)&&$s['skipped_batches']===($first==='complete'?0:1),
            'real SQL race has one winning outcome: '.$first.' then '.$second);
    } finally {
        foreach ($workers as [$process,$pipes,$out]) {
            if (is_resource($process)) {proc_terminate($process);proc_close($process);}
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        }
        unset($queue,$db);
        foreach (glob($prefix.'*')?:[] as $path) if (is_file($path)) unlink($path);
    }
}

