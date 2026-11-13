<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-hook-contract.php';
$checks=0;
function check(bool $ok,string $case):void {global $checks;if(!$ok)throw new RuntimeException($case);$checks++;}
function rejects(callable $fn,string $reason):void {
    try{$fn();}catch(RuntimeException $e){check($e->getMessage()===$reason,'wrong rejection: '.$reason);return;}
    throw new RuntimeException('accepted: '.$reason);
}
function instant(string $date):int {return (int)strtotime($date);}
$now=instant('2026-11-11T21:20:00Z');$collection='2026-11-11T21:00:00Z';$run='43205530035';
$gate=monitor_hook_nightly_gate([],$now,$collection,$run);
check($gate['due']&&$gate['night_date']==='2026-11-12'&&$gate['cycle']['state']==='started','Moscow midnight starts one cycle, not UTC date');
check($gate['cycle']['collector_run_id']===$run&&$gate['cycle']['collection_started_at']==='2026-11-11T21:00:00+00:00','original collection identity retained');
$day=monitor_hook_nightly_gate([],instant('2026-11-12T08:20:00Z'),'2026-11-12T08:00:00Z',$run);
check($day['due']&&!$day['blocked']&&$day['cycle']['state']==='started','day collector receives the same saved-price maintenance');
$late=monitor_hook_nightly_gate([],instant('2026-11-12T03:25:00Z'),'2026-11-12T02:59:59Z',$run);
check($late['due'],'night collection finishing after six remains night work');
$end=monitor_hook_nightly_gate([],instant('2026-11-12T03:25:00Z'),'2026-11-12T03:00:00Z',$run);
check($end['due'],'six oclock is not a publication boundary');
rejects(fn()=>monitor_hook_nightly_gate([],$now,'2026-11-11T20:19:59Z',$run),'hook_nightly_collection_time_invalid');
rejects(fn()=>monitor_hook_nightly_gate([],$now,'2026-11-11T21:21:01Z',$run),'hook_nightly_collection_time_invalid');
rejects(fn()=>monitor_hook_nightly_gate([],$now,$collection,null),'hook_nightly_collector_run_invalid');
$state=['schema'=>1,'completed'=>[249=>['operation'=>'retained']],'holds'=>[1142=>['operation'=>'retained-hold']],'pending'=>null,'nightly_cycle'=>$gate['cycle']];
$before=$state;
$again=monitor_hook_nightly_gate($state,$now,$collection,$run);
check(!$again['due']&&$again['blocked'],'started original cannot automatically repeat');
check($state===$before,'admission preserves existing successes and holds');
$state['nightly_cycle']['state']='completed';
check(monitor_hook_nightly_gate($state,$now,$collection,'43205530036')['due'],'new collector in the same Moscow day is admitted');
$same=monitor_hook_nightly_gate($state,$now,$collection,$run);
check(!$same['due']&&!$same['blocked']&&$same['reason']==='collector_cycle_already_attempted','completed original collector cannot repeat');
$changedTime=monitor_hook_nightly_gate($state,$now,'2026-11-11T21:10:00Z',$run);
check(!$changedTime['due'],'changed timestamp cannot replay the same collector');
$state['nightly_cycle']['state']='budget_exhausted';
$partial=monitor_hook_nightly_gate($state,$now,$collection,$run);
check(!$partial['due']&&!$partial['blocked']&&$partial['cycle']['state']==='budget_exhausted','partial original is not a completed full sweep or fresh budget');
check(monitor_hook_nightly_gate($state,$now,'2026-11-11T21:10:00Z','43205530036')['due'],'new collector can continue after a bounded terminal pass');
$next=monitor_hook_nightly_gate($state,instant('2026-11-12T21:20:00Z'),'2026-11-12T21:00:00Z','43205530037');
check($next['due']&&$next['night_date']==='2026-11-13','next night receives its own budget');
$pending=$state;$pending['pending']=['operation'=>'unknown-original','phase'=>'post_started'];
$blocked=monitor_hook_nightly_gate($pending,instant('2026-11-12T21:20:00Z'),'2026-11-12T21:00:00Z','43205530037');
check(!$blocked['due']&&$blocked['blocked']&&$blocked['reason']==='hook_pending_operation_requires_reconciliation','unknown original stops without replay or new cycle');
$unfinished=$state;$unfinished['nightly_cycle']['state']='started';
$blocked=monitor_hook_nightly_gate($unfinished,instant('2026-11-12T21:20:00Z'),'2026-11-12T21:00:00Z','43205530037');
check(!$blocked['due']&&$blocked['blocked']&&$blocked['cycle']===$unfinished['nightly_cycle'],'yesterday unfinished original remains blocked');
$future=$state;$future['nightly_cycle']['night_date']='2026-11-13';
rejects(fn()=>monitor_hook_nightly_gate($future,$now,$collection,$run),'hook_nightly_clock_moved_back');
rejects(fn()=>monitor_hook_nightly_gate($state,$now,'2026-11-11T20:59:59Z','43205530036'),'hook_nightly_clock_moved_back');
$lines=[json_encode(['schema'=>1,'pending'=>null,'completed'=>[]],JSON_THROW_ON_ERROR),json_encode($before,JSON_THROW_ON_ERROR),json_encode($state,JSON_THROW_ON_ERROR)];
$seen=monitor_hook_attempted_collector_runs($lines);
check(count($seen)===1&&isset($seen['run:'.$run]),'original append-only journal supplies replay history without a second store');
$newer=$state;$newer['nightly_cycle']=$day['cycle'];$newer['nightly_cycle']['collector_run_id']='43205530036';$newer['nightly_cycle']['state']='completed';
$lines[]=json_encode($newer,JSON_THROW_ON_ERROR);$seen=monitor_hook_attempted_collector_runs($lines);
$old=monitor_hook_nightly_gate($newer,instant('2026-11-12T08:20:00Z'),'2026-11-12T08:15:00Z',$run,$seen);
check(!$old['due']&&!$old['blocked']&&$old['reason']==='collector_cycle_already_attempted','older original remains rejected after a newer collector even with a new timestamp');
$nextDay=monitor_hook_nightly_gate($newer,instant('2026-11-12T08:30:00Z'),'2026-11-12T08:25:00Z','43205530037',$seen);
check($nextDay['due'],'new later daytime collector is admitted');
$large=$state;$large['nightly_cycle']['collector_run_id']='99999999999999999999';
$largeSeen=monitor_hook_attempted_collector_runs([json_encode($large,JSON_THROW_ON_ERROR)]);
check(isset($largeSeen['run:99999999999999999999']),'run identity never relies on PHP integer range or numeric ordering');
rejects(fn()=>monitor_hook_attempted_collector_runs(['{broken}']),'hook_nightly_history_invalid');
$invalid=$state;$invalid['nightly_cycle']['collector_run_id']='';
rejects(fn()=>monitor_hook_attempted_collector_runs([json_encode($invalid,JSON_THROW_ON_ERROR)]),'hook_nightly_history_invalid');
rejects(fn()=>monitor_hook_nightly_gate($invalid,$now,$collection,'43205530036'),'hook_nightly_cycle_invalid');
$snapshot=$state;monitor_hook_nightly_gate($state,$now,$collection,'43205530038',$seen);
check($state===$snapshot,'legacy completed map holds and original cycle remain byte-for-byte untouched by admission');
// Synthetic state shapes exercise the pure transition; runtime hashes actual journals under lock.
$proof=monitor_hook_cycle_recovery_proof();$legacyState=$state;
$legacyState['nightly_cycle']=['night_date'=>'2026-11-13','state'=>'started','collector_run_id'=>$proof['collector_run_id'],
    'collection_started_at'=>'2026-11-13T00:20:00+00:00','started_at'=>'2026-11-13T00:35:00+00:00','budget_seconds'=>1800,'finish_reserve_seconds'=>300];
$legacyState['completed'][112914]=['operation'=>$proof['operation'],'feed_operation'=>$proof['feed_operation'],'at'=>'2026-11-13T02:31:07+00:00'];
$child=['operation'=>$proof['operation'],'complete'=>true];$closeAt=instant('2026-11-13T08:00:00Z');$legacyBefore=$legacyState;
$closed=monitor_hook_close_reconciled_cycle($legacyState,$proof['global'],$proof['child'],$child,$closeAt);
check($closed!==null&&monitor_hook_reconciled_cycle_valid($closed['nightly_cycle']),'exact accepted recovery closes only the interrupted metadata');
check($closed['nightly_cycle']['state']==='interrupted_reconciled'&&!isset($closed['nightly_cycle']['finished_at']),'failed partial sweep is never relabelled completed');
check(array_diff_key($closed,['nightly_cycle'=>true])===array_diff_key($legacyState,['nightly_cycle'=>true]),'completed hotels holds and pending are preserved exactly');
check($legacyState===$legacyBefore,'pure closeout never mutates its original input');
check(monitor_hook_close_reconciled_cycle($closed,[],[],[],0)===null,'already closed original needs no second metadata append or old-prefix replay');
$closedRuns=monitor_hook_attempted_collector_runs([json_encode($legacyState,JSON_THROW_ON_ERROR),json_encode($closed,JSON_THROW_ON_ERROR)]);
check(monitor_hook_nightly_gate($closed,$closeAt,'2026-11-13T07:50:00Z','43394479904',$closedRuns)['due'],'new daytime collector follows exact interrupted recovery');
check(!monitor_hook_nightly_gate($closed,$closeAt,'2026-11-13T07:50:00Z',$proof['collector_run_id'],$closedRuns)['due'],'reconciled original collector itself remains non-repeatable');
foreach(['sha256','bytes','lines'] as $field){
    $bad=$proof['global'];$bad[$field]=$field==='sha256'?str_repeat('0',64):$bad[$field]+1;
    rejects(fn()=>monitor_hook_close_reconciled_cycle($legacyState,$bad,$proof['child'],$child,$closeAt),'hook_cycle_recovery_prefix_changed');
    $bad=$proof['child'];$bad[$field]=$field==='sha256'?str_repeat('0',64):$bad[$field]+1;
    rejects(fn()=>monitor_hook_close_reconciled_cycle($legacyState,$proof['global'],$bad,$child,$closeAt),'hook_cycle_recovery_prefix_changed');
}
$bad=$legacyState;$bad['pending']=['operation'=>'unknown'];
rejects(fn()=>monitor_hook_close_reconciled_cycle($bad,$proof['global'],$proof['child'],$child,$closeAt),'hook_cycle_recovery_not_terminal');
$badChild=$child;$badChild['complete']=false;
rejects(fn()=>monitor_hook_close_reconciled_cycle($legacyState,$proof['global'],$proof['child'],$badChild,$closeAt),'hook_cycle_recovery_not_terminal');
foreach(['operation','feed_operation'] as $field){$bad=$legacyState;$bad['completed'][112914][$field]='different';
    rejects(fn()=>monitor_hook_close_reconciled_cycle($bad,$proof['global'],$proof['child'],$child,$closeAt),'hook_cycle_recovery_not_terminal');}
rejects(fn()=>monitor_hook_close_reconciled_cycle($legacyState,$proof['global'],$proof['child'],$child,instant('2026-11-13T02:31:06Z')),'hook_cycle_recovery_clock_invalid');
$other=$legacyState;$other['nightly_cycle']['collector_run_id']='43336135924';
check(monitor_hook_close_reconciled_cycle($other,[],[],[],0)===null,'unrelated unfinished collector has no recovery exception');
$forged=$closed;unset($forged['nightly_cycle']['recovery']);
rejects(fn()=>monitor_hook_nightly_gate($forged,$closeAt,'2026-11-13T07:50:00Z','43394479904'),'hook_nightly_cycle_invalid');
rejects(fn()=>monitor_hook_attempted_collector_runs([json_encode($forged,JSON_THROW_ON_ERROR)]),'hook_nightly_history_invalid');
$forged=$closed;$forged['nightly_cycle']['recovery']['recovery_result_sha256']=str_repeat('0',64);
check(!monitor_hook_reconciled_cycle_valid($forged['nightly_cycle']),'different recovery proof cannot create a general terminal bypass');
$raw="{\"schema\":1}\n";$pin=monitor_hook_cycle_journal_pin($raw);
check($pin===['sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw),'lines'=>1],'actual bytes produce the full journal CAS');
rejects(fn()=>monitor_hook_cycle_journal_pin(rtrim($raw,"\n")),'hook_cycle_recovery_journal_incomplete');
$night=monitor_hook_pass_policy(true,481,1000);
check($night['pair_limit']===481&&$night['budget_seconds']===1800&&$night['finish_reserve_seconds']===300,'whole catalogue admitted within one maintenance budget');
check(monitor_hook_can_start_pair($night,1300,30),'night cycle continues beyond old thirty pair limit');
check(monitor_hook_can_start_pair($night,2499,200),'work admitted before finish reserve');
check(!monitor_hook_can_start_pair($night,2500,200),'new pair not started during finish reserve');
check(!monitor_hook_can_start_pair($night,1200,481),'scope cannot exceed roster');
$legacy=monitor_hook_pass_policy(false,481,1000);
check(monitor_hook_can_start_pair($legacy,1479,29)&&!monitor_hook_can_start_pair($legacy,1479,30)
    &&!monitor_hook_can_start_pair($legacy,1480,1),'legacy manual limit and deadline retained');
check(!monitor_hook_snapshot_refresh_due(1000,1599)&&monitor_hook_snapshot_refresh_due(1000,1600),'saved export refreshed before fifteen minute expiry');
check(monitor_hook_can_start_pair($night,1300,31)&&!monitor_hook_can_start_pair($night,2501,31),'budget rechecked after saved export read');
echo json_encode(['ok'=>true,'nightly_policy_cases'=>$checks,'supplier_calls'=>0,'post_writes'=>0,'journal_writes'=>0],JSON_THROW_ON_ERROR),PHP_EOL;

