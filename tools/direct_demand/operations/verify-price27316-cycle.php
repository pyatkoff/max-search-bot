<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$out=['ok'=>false,'mode'=>'read_only_installed_cycle_verification','provider_calls'=>0,'database_writes'=>0,'feed_writes'=>0,'journal_appends'=>0,'collector_started'=>false];$locks=[];
try{
 reconcile_need(count($argv)===1,'verify_cycle_arguments_invalid');
 $out['runtime_sha']=reconcile_runtime();
 $path='/var/www/anytoour/data/www/anytoour.ru/data/monitor-price-hook.php';
 $installed=hook_read($path,1000000);
 reconcile_need(hash('sha256',$installed)===CLOSE_UPGRADED_HOOK_SHA256,'verify_cycle_installed_hook_changed');
 $out['installed_hook_sha256']=CLOSE_UPGRADED_HOOK_SHA256;
 [$g]=reconcile_open(RECONCILE_ROOT.'/monitor-price-after-collection-v1.jsonl',450000000,false,$locks,true);
 reconcile_assert_global_prefix($g);
 // The verified installed bytes and this reader share the exact upgraded frozen contract.
 $history=monitor_hook_read_history($g);$state=$history['state'];$id=monitor_hook_price27316_identity();
 reconcile_need(is_array($state)&&array_key_exists('pending',$state)&&$state['pending']===null,'verify_cycle_pending_not_clear');
 $cycle=$state['nightly_cycle']??null;
 reconcile_need(is_array($cycle)&&monitor_hook_price27316_cycle_valid($cycle),'verify_cycle_terminal_not_accepted');
 reconcile_need(isset($history['attempted_runs']['run:'.$id['collector_run_id']]),'verify_cycle_original_attempt_lost');
 $now=time();$collection=gmdate('c',$now);
 $prior=monitor_hook_nightly_gate($state,$now,$collection,$id['collector_run_id'],$history['attempted_runs']);
 reconcile_need(($prior['due']??null)===false&&($prior['reason']??null)==='collector_cycle_already_attempted','verify_cycle_original_can_replay');
 // Pure hypothetical admission only. The identifier is intentionally not an actual collector run.
 $hypothetical='99999999999999999999';
 reconcile_need(!isset($history['attempted_runs']['run:'.$hypothetical]),'verify_cycle_test_identifier_collided');
 $next=monitor_hook_nightly_gate($state,$now,$collection,$hypothetical,$history['attempted_runs']);
 reconcile_need(($next['due']??null)===true&&($next['blocked']??null)===false,'verify_cycle_next_collector_blocked');
 $out['original_global_prefix_preserved']=true;$out['current_global_pin']=$history['pin'];
 $out['pending_cleared']=true;$out['terminal_accepted_by_installed_reader']=true;
 $out['original_collector_replay_blocked']=true;$out['hypothetical_fresh_collector_admitted']=true;
 $out['attempted_collector_count']=count($history['attempted_runs']);$out['cycle_state']=$cycle['state'];
 $out['all_660_completed']=false;$out['checked_at']=gmdate('c');$out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'verify_cycle_failed';}
foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo 'PRICE27316_INSTALLED_CYCLE_VERIFICATION='.reconcile_json($out)."\n";exit($out['ok']?0:2);
