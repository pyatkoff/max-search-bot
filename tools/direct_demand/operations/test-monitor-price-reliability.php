<?php
declare(strict_types=1);
// Pure contracts and temporary local streams; no driver, runtime or provider is loaded.
require_once __DIR__.'/monitor-price-hook-contract.php';
$checks=0;
function reliability_check(bool $ok,string $name):void {
    global $checks;if(!$ok)throw new RuntimeException($name);$checks++;
}
function reliability_reject(callable $run,string $expected):void {
    try{$run();}catch(RuntimeException $e){reliability_check($e->getMessage()===$expected,'wrong_failure:'.$e->getMessage());return;}
    throw new RuntimeException('not_rejected:'.$expected);
}
function reliability_line(array $state):string {
    return json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
function reliability_read(string $raw):array {
    $stream=fopen('php://temp','w+b');monitor_price_require(is_resource($stream),'fixture_stream_unavailable');
    try{
        monitor_price_require(fwrite($stream,$raw)===strlen($raw),'fixture_write_failed');
        // The writer left the cursor at EOF: the reader must authenticate the whole prefix.
        $result=monitor_hook_read_history($stream);
        reliability_check(is_resource($stream)&&ftell($stream)===strlen($raw),'reader_closed_or_partly_read_stream');
        return $result;
    }finally{fclose($stream);}
}

$now=strtotime('2026-11-16T10:00:00Z');
$first=['schema'=>1,'pending'=>null,'completed'=>[],
    'nightly_cycle'=>monitor_hook_nightly_gate([],$now,gmdate('c',$now),'900000101')['cycle']];
$first['nightly_cycle']['state']='completed';
$last=$first;$last['nightly_cycle']=monitor_hook_nightly_gate([],$now+60,gmdate('c',$now+60),'900000102')['cycle'];
$last['nightly_cycle']['state']='completed';$last['completed']=[1236=>['operation'=>'fixture-confirmed','at'=>gmdate('c',$now+60)]];
$withoutCycle=['schema'=>1,'pending'=>null,'completed'=>[]];
$raw=reliability_line($first).reliability_line($withoutCycle).reliability_line($last);
$read=reliability_read($raw);
reliability_check($read['state']===$last&&$read['trailing_blank_lines']===0,'last_state_changed');
reliability_check($read['pin']===monitor_hook_cycle_journal_pin($raw),'stream_changed_historical_pin');
reliability_check($read['attempted_runs']===['run:900000101'=>true,'run:900000102'=>true],'old_attempted_collector_lost');
$replay=monitor_hook_nightly_gate($read['state'],$now+120,gmdate('c',$now+120),'900000101',$read['attempted_runs']);
reliability_check(!$replay['due']&&!$replay['blocked']&&$replay['reason']==='collector_cycle_already_attempted','old_collector_replayed_after_newer_cycle');
reliability_check(monitor_hook_nightly_gate($read['state'],$now+120,gmdate('c',$now+120),'900000103',$read['attempted_runs'])['due'],'fresh_collector_incorrectly_blocked');
$legacy=$withoutCycle;$legacy['nightly_cycle']=['state'=>'interrupted_reconciled','collector_run_id'=>'43336135923',
    'night_date'=>'2026-11-13','recovery'=>monitor_hook_cycle_recovery_proof(),'reconciled_at'=>'2026-11-13T02:31:07+00:00'];
$legacyRaw=reliability_line($legacy).$raw;$legacyRead=reliability_read($legacyRaw);
reliability_check(isset($legacyRead['attempted_runs']['run:43336135923'])&&$legacyRead['pin']===monitor_hook_cycle_journal_pin($legacyRaw),'legacy_recovery_history_changed');
$empty=reliability_read('');
reliability_check($empty===['state'=>null,'attempted_runs'=>[],
    'pin'=>['sha256'=>hash('sha256',''),'bytes'=>0,'lines'=>0],'trailing_blank_lines'=>0],'empty_stream_changed');
$blankRaw=$raw."\n\n";$blank=reliability_read($blankRaw);
reliability_check($blank['state']===$last&&$blank['attempted_runs']===$read['attempted_runs']&&$blank['trailing_blank_lines']===2
    &&$blank['pin']===monitor_hook_cycle_journal_pin($blankRaw),'terminal_blank_bytes_trimmed');
$crlfRaw=rtrim(reliability_line($withoutCycle),"\n")."\r\n";$crlf=reliability_read($crlfRaw);
reliability_check($crlf['state']===$withoutCycle&&$crlf['pin']===monitor_hook_cycle_journal_pin($crlfRaw),'valid_crlf_pin_changed');
foreach(["{broken}\n".$raw,"null\n".$raw,"\n".$raw,$blankRaw.reliability_line($first),$raw." \n",$raw."\r\n","\n\n"] as $invalid){
    reliability_reject(static fn()=>reliability_read($invalid),'hook_nightly_history_invalid');
}
$badCycle=$first;$badCycle['nightly_cycle']['collector_run_id']='00012';
reliability_reject(static fn()=>reliability_read(reliability_line($badCycle).$raw),'hook_nightly_history_invalid');
$badRecovery=$legacy;$badRecovery['nightly_cycle']['recovery']['recovery_run_id']='999999999';
reliability_reject(static fn()=>reliability_read(reliability_line($badRecovery).$raw),'hook_nightly_history_invalid');
reliability_reject(static fn()=>reliability_read(substr($raw,0,-1)),'hook_journal_incomplete_stop');
reliability_reject(static fn()=>monitor_hook_read_history(null),'hook_journal_read_failed');

// Exceed the old inspection cap without ever constructing the full journal string.
$large=fopen('php://temp/maxmemory:1048576','w+b');monitor_price_require(is_resource($large),'large_fixture_stream_unavailable');
try{
    $largeState=$last;$largeState['fixture_padding']=str_repeat('x',524288);$largeLine=reliability_line($largeState);
    $expectedHash=hash_init('sha256');$largeBytes=0;$largeLines=201;
    for($i=0;$i<$largeLines;$i++){
        monitor_price_require(fwrite($large,$largeLine)===strlen($largeLine),'large_fixture_write_failed');
        hash_update($expectedHash,$largeLine);$largeBytes+=strlen($largeLine);
    }
    $largeRead=monitor_hook_read_history($large);
    reliability_check($largeBytes>100000000&&$largeRead['state']===$largeState
        &&$largeRead['pin']===['sha256'=>hash_final($expectedHash),'bytes'=>$largeBytes,'lines'=>$largeLines]
        &&$largeRead['attempted_runs']===['run:900000102'=>true]&&$largeRead['trailing_blank_lines']===0,'large_history_changed_or_rejected');
}finally{fclose($large);}

$out=['ok'=>true,'mode'=>'existing_monitor_post_feed_hook','roster_pairs'=>3,'scanned_pairs'=>3,'remaining_unscanned_pairs'=>0,
    'updated_pairs'=>2,'unchanged_pairs'=>1,'held_pairs'=>0,'deferred'=>[],'nightly_cycle_completed'=>true];
$originalOut=$out;$summary=monitor_hook_result_summary($out);
reliability_check($summary===['result_status'=>'completed','scan_complete'=>true,'all_pairs_applied_or_unchanged'=>true,'no_fresh_observation_pairs'=>0]
    &&$out===$originalOut,'complete_summary_or_legacy_outcome_changed');
$unchanged=$out;$unchanged['updated_pairs']=0;$unchanged['unchanged_pairs']=3;
reliability_check(monitor_hook_result_summary($unchanged)['result_status']==='completed','all_unchanged_not_complete');
$noFresh=$out;$noFresh['updated_pairs']=1;$noFresh['deferred']=[['tourvisor_hotel_id'=>1236,'reason'=>'no_fresh_observation']];
reliability_check(monitor_hook_result_summary($noFresh)===['result_status'=>'partial','scan_complete'=>true,
    'all_pairs_applied_or_unchanged'=>false,'no_fresh_observation_pairs'=>1]&&$noFresh['nightly_cycle_completed']===true,'no_fresh_promoted_to_complete');
$held=$out;$held['unchanged_pairs']=0;$held['held_pairs']=1;$held['deferred']=[['reason'=>'abandoned_before_write']];
reliability_check(monitor_hook_result_summary($held)===['result_status'=>'partial','scan_complete'=>true,
    'all_pairs_applied_or_unchanged'=>false,'no_fresh_observation_pairs'=>0],'held_pair_promoted_to_complete');
$budget=$out;$budget['scanned_pairs']=2;$budget['remaining_unscanned_pairs']=1;$budget['unchanged_pairs']=0;
$budget['bounded_pass_stopped']=true;$budget['nightly_cycle_completed']=false;
reliability_check(monitor_hook_result_summary($budget)['result_status']==='partial'&&monitor_hook_result_summary($budget)['scan_complete']===false,'budget_stop_promoted_to_complete');
$blocked=$out;$blocked['ok']=false;$blocked['error']='hook_feed_outcome_unresolved_stop';
reliability_check(monitor_hook_result_summary($blocked)===['result_status'=>'blocked','scan_complete'=>true,
    'all_pairs_applied_or_unchanged'=>false,'no_fresh_observation_pairs'=>0],'failure_promoted_to_complete');
$skip=['ok'=>true,'skipped'=>'collector_cycle_already_attempted','scanned_pairs'=>0,'deferred'=>[]];
reliability_check(monitor_hook_result_summary($skip)===['result_status'=>'skipped','scan_complete'=>null,
    'all_pairs_applied_or_unchanged'=>null,'no_fresh_observation_pairs'=>0],'skipped_pass_claimed_scan');
$skip['ok']=false;$skip['skipped']='hook_pending_operation_requires_reconciliation';
reliability_check(monitor_hook_result_summary($skip)['result_status']==='blocked'&&monitor_hook_result_summary($skip)['scan_complete']===null,'blocked_admission_reported_as_skip');
$inspect=$out;$inspect['mode']='read_only_hook_inspection';$inspect['updated_pairs']=0;$inspect['unchanged_pairs']=0;
reliability_check(monitor_hook_result_summary($inspect)===['result_status'=>'inspected','scan_complete'=>true,
    'all_pairs_applied_or_unchanged'=>null,'no_fresh_observation_pairs'=>0],'inspection_claimed_application');
$unknown=$out;unset($unknown['roster_pairs']);
reliability_check(monitor_hook_result_summary($unknown)['result_status']==='partial'&&monitor_hook_result_summary($unknown)['scan_complete']===null,'unknown_scope_claimed_completion');
$negative=$out;$negative['updated_pairs']=-1;$negative['unchanged_pairs']=4;
reliability_check(monitor_hook_result_summary($negative)['result_status']==='partial','invalid_counts_claimed_completion');
$missing=$out;unset($missing['held_pairs']);
reliability_check(monitor_hook_result_summary($missing)['result_status']==='partial','missing_hold_count_became_zero');
echo 'MONITOR_PRICE_RELIABILITY_OK checks='.$checks.' history_bytes='.$largeBytes." local_fixture_only=1 production_writes=0\n";
