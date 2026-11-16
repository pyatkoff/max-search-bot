<?php
declare(strict_types=1);
require __DIR__.'/reconcile-stale-feed-20261116.php';
$checks=0;
function ck(bool $ok,string $why):void {global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function reject(callable $run,string $why):void {$rejected=false;try{$run();}catch(Throwable $e){$rejected=true;}ck($rejected,$why);}
$p=sfr_pins();$now=strtotime('2026-11-16T10:00:00Z');$execution='999000001';$prefix=str_repeat('a',64);
$cycle=['night_date'=>'2026-11-15','state'=>'started','collector_run_id'=>$p['run'],
    'collection_started_at'=>'2026-11-15T09:22:59+00:00','started_at'=>'2026-11-15T09:35:56+00:00',
    'budget_seconds'=>1800,'finish_reserve_seconds'=>300];
$files=[];foreach($p['feeds'] as $name=>$h)$files[]=['name'=>$name,'expected_previous_sha256'=>$h,'sha256'=>str_repeat('c',64)];
$s=['schema'=>1,'completed'=>[1236=>['operation'=>'older-success-kept','at'=>'2026-11-14T10:00:00Z']],
    'pending'=>['operation'=>$p['operation'],'feed_operation'=>$p['feed_operation'],'tourvisor_hotel_id'=>1236,
        'phase'=>'feed_started','at'=>'2026-11-15T09:38:33+00:00','collector_cycle'=>$cycle,
        'files'=>$files,'child_failure'=>['exit_code'=>2,'error'=>'installed_source_changed']],
    'nightly_cycle'=>$cycle,'other_prior_metadata'=>['keep'=>'exact']];
$c=['operation'=>$p['operation'],'manifest_hash'=>$p['manifest'],'complete'=>true,'configs'=>[],
    'feed_binding'=>['tourvisor_hotel_id'=>1236],'posts'=>[]];
foreach($p['posts'] as $platform=>[$pub,$version,$message,$hash])$c['posts'][$platform.':fixture']=['state'=>'confirmed','receipt'=>[
    'platform'=>$platform,'publication_id'=>$pub,'version_id'=>$version,'message_id'=>$message,
    'text_sha256'=>$hash,'state'=>'sent','body_photo_button_verified'=>true]];
// Synthetic pins belong only to fixtures; production entry takes sfr_pins() without inputs.
$p['cycle_sha256']=sfr_digest($cycle);$p['completed_count']=count($s['completed']);$p['completed_sha256']=sfr_digest($s['completed']);
$n=sfr_propose($s,$c,$p,$p['feeds'],$prefix,$execution,$now);
ck($n['pending']===null&&$n['nightly_cycle']===null,'active pointers clear');
ck($n['completed']===$s['completed'],'all completed records unchanged');
ck($n['other_prior_metadata']===$s['other_prior_metadata'],'other metadata unchanged');
$a=$n['reconciled_interruptions']['run:'.$p['run']];
ck($a['original_cycle']===$s['nightly_cycle'],'actual interrupted cycle retained');
ck($a['original_pending']===$s['pending'],'actual pending tuple retained');
ck($a['original_collector_remains_failed']===true&&$a['state']==='interrupted_reconciled','never falsely completed');
ck($a['post_writes']===0&&$a['feed_writes']===0&&$a['supplier_calls']===0,'no business side effects');
ck($a['global_prefix_sha256']===$prefix&&$a['recovery_run_id']===$execution,'actual invocation evidence');
$restored=$n;unset($restored['reconciled_interruptions']);$restored['pending']=$s['pending'];$restored['nightly_cycle']=$s['nightly_cycle'];
ck($restored===$s,'only two pointers plus explicit retained audit change');
reject(fn()=>sfr_propose($n,$c,$p,$p['feeds'],$prefix,$execution,$now),'never replay metadata transition');
foreach(['operation','feed_operation','phase','tourvisor_hotel_id'] as $field){$x=$s;$x['pending'][$field]='changed';reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'changed pending '.$field);}
foreach(['error'=>'other_error','exit_code'=>0,'extra'=>'unknown'] as $field=>$value){$x=$s;$x['pending']['child_failure'][$field]=$value;reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'changed failure '.$field);}
foreach(['state'=>'completed','collector_run_id'=>'123','night_date'=>'2026-11-16'] as $field=>$value){$x=$s;$x['nightly_cycle'][$field]=$value;reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'changed cycle '.$field);}
$x=$s;$x['completed'][7]=['operation'=>'new'];reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'changed completed count');
$x=$s;$x['completed'][1236]['at']='changed';reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'changed completed bytes');
$x=$s;$x['holds']=[1236=>['reason'=>'hold']];reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'do not clear holds');
foreach(['operation'=>'other','manifest_hash'=>'bad','complete'=>false,'configs'=>['modified'],'terminal'=>['state'=>'unknown']] as $field=>$value){$x=$c;$x[$field]=$value;reject(fn()=>sfr_propose($s,$x,$p,$p['feeds'],$prefix,$execution,$now),'changed child '.$field);}
foreach(['max','telegram'] as $platform){
 foreach(['publication_id'=>1,'version_id'=>1,'message_id'=>'wrong','text_sha256'=>'bad','state'=>'unknown','body_photo_button_verified'=>false,'platform'=>'wrong'] as $field=>$value){
  $x=$c;$x['posts'][$platform.':fixture']['receipt'][$field]=$value;reject(fn()=>sfr_propose($s,$x,$p,$p['feeds'],$prefix,$execution,$now),'changed receipt '.$platform.$field);
 }
 $x=$c;$x['posts'][$platform.':fixture']['state']='edit_started';reject(fn()=>sfr_propose($s,$x,$p,$p['feeds'],$prefix,$execution,$now),'no incomplete post');
 $x=$c;unset($x['posts'][$platform.':fixture']);reject(fn()=>sfr_propose($s,$x,$p,$p['feeds'],$prefix,$execution,$now),'no missing post');
}
$x=$c;$x['posts']['extra']=$x['posts']['max:fixture'];reject(fn()=>sfr_propose($s,$x,$p,$p['feeds'],$prefix,$execution,$now),'no duplicate post');
foreach(array_keys($p['feeds']) as $i=>$name){
 $h=$p['feeds'];$h[$name]=str_repeat('b',64);reject(fn()=>sfr_propose($s,$c,$p,$h,$prefix,$execution,$now),'feed changed '.$name);
 $x=$s;$x['pending']['files'][$i]['expected_previous_sha256']='wrong';reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'preimage changed '.$name);
 $x=$s;$x['pending']['files'][$i]['sha256']=$p['feeds'][$name];reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'ambiguous already written '.$name);
}
$x=$s;$x['pending']['files'][1]=$x['pending']['files'][0];reject(fn()=>sfr_propose($x,$c,$p,$p['feeds'],$prefix,$execution,$now),'duplicate feed');
reject(fn()=>sfr_propose($s,$c,$p,$p['feeds'],$prefix,$p['run'],$now),'do not invent original run identity');
reject(fn()=>sfr_propose($s,$c,$p,$p['feeds'],$prefix,'bad',$now),'bad execution identity');
reject(fn()=>sfr_propose($s,$c,$p,$p['feeds'],'bad',$execution,$now),'bad prefix');
reject(fn()=>sfr_propose($s,$c,$p,$p['feeds'],$prefix,$execution,0),'old clock');
if(($argv[1]??null)==='--native'){
 require __DIR__.'/monitor-price-hook-contract.php';
 $attempted=monitor_hook_attempted_collector_runs([sfr_json($s),sfr_json($n)]);
 ck(isset($attempted['run:'.$p['run']]),'original run remains in canonical replay history');
 $old=monitor_hook_nightly_gate($n,$now,gmdate('c',$now),$p['run'],$attempted);
 ck(!$old['due']&&!$old['blocked']&&$old['reason']==='collector_cycle_already_attempted','canonical old run denied');
 $new=monitor_hook_nightly_gate($n,$now,gmdate('c',$now),$execution,$attempted);
 ck($new['due']===true&&!$new['blocked'],'new genuine run admitted, not executed');
 $blocked=monitor_hook_nightly_gate($s,$now,gmdate('c',$now),$execution,$attempted);
 ck(!$blocked['due']&&$blocked['blocked'],'original pending was blocked');
 $held=$n;$held['pending']=$s['pending'];
 ck(monitor_hook_nightly_gate($held,$now,gmdate('c',$now),$execution,$attempted)['blocked'],'other pending still blocked');
 $active=$n;$active['nightly_cycle']=$cycle;
 ck(monitor_hook_nightly_gate($active,$now,gmdate('c',$now),$execution,$attempted)['reason']==='hook_nightly_original_attempt_unresolved','other started cycle still blocked');
}
echo 'STALE_FEED_RECOVERY_TESTS_OK checks='.$checks.' native_gate='.(($argv[1]??null)==='--native'?'tested':'not_requested').' writes=0',PHP_EOL;
