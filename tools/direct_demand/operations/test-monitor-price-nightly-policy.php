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
check(!$day['due']&&!$day['blocked']&&!isset($day['cycle']),'day skip does not create completion');
$late=monitor_hook_nightly_gate([],instant('2026-11-12T03:25:00Z'),'2026-11-12T02:59:59Z',$run);
check($late['due'],'night collection finishing after six remains night work');
$end=monitor_hook_nightly_gate([],instant('2026-11-12T03:25:00Z'),'2026-11-12T03:00:00Z',$run);
check(!$end['due'],'six oclock collection is outside window');
rejects(fn()=>monitor_hook_nightly_gate([],$now,'2026-11-11T20:19:59Z',$run),'hook_nightly_collection_time_invalid');
rejects(fn()=>monitor_hook_nightly_gate([],$now,'2026-11-11T21:21:01Z',$run),'hook_nightly_collection_time_invalid');
rejects(fn()=>monitor_hook_nightly_gate([],$now,$collection,null),'hook_nightly_collector_run_invalid');
$state=['schema'=>1,'completed'=>[249=>['operation'=>'retained']],'holds'=>[1142=>['operation'=>'retained-hold']],'pending'=>null,'nightly_cycle'=>$gate['cycle']];
$before=$state;
$again=monitor_hook_nightly_gate($state,$now,$collection,$run);
check(!$again['due']&&$again['blocked'],'started original cannot automatically repeat');
check($state===$before,'admission preserves existing successes and holds');
$state['nightly_cycle']['state']='completed';
check(!monitor_hook_nightly_gate($state,$now,$collection,'43205530036')['due'],'second scheduled run same Moscow day cannot repeat successful cycle');
$state['nightly_cycle']['state']='budget_exhausted';
$partial=monitor_hook_nightly_gate($state,$now,$collection,'43205530036');
check(!$partial['due']&&!$partial['blocked']&&$partial['cycle']['state']==='budget_exhausted','partial day is not a completed full sweep or fresh budget');
$next=monitor_hook_nightly_gate($state,instant('2026-11-12T21:20:00Z'),'2026-11-12T21:00:00Z','43205530037');
check($next['due']&&$next['night_date']==='2026-11-13','next night receives its own budget');
$pending=$state;$pending['pending']=['operation'=>'unknown-original','phase'=>'post_started'];
$blocked=monitor_hook_nightly_gate($pending,instant('2026-11-12T21:20:00Z'),'2026-11-12T21:00:00Z','43205530037');
check(!$blocked['due']&&$blocked['blocked']&&$blocked['reason']==='hook_pending_operation_requires_reconciliation','unknown original stops without replay or new cycle');
$future=$state;$future['nightly_cycle']['night_date']='2026-11-13';
rejects(fn()=>monitor_hook_nightly_gate($future,$now,$collection,$run),'hook_nightly_clock_moved_back');
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
