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

