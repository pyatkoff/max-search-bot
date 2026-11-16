<?php
declare(strict_types=1);
namespace MonitorDriverReliabilityFixture;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Exercise source-extracted driver functions/blocks. Never include its main,
// application config, transport, child process, database or production paths.
$driver=(string)file_get_contents(__DIR__.'/monitor-price-after-collection.php');
$inspector=(string)file_get_contents(__DIR__.'/inspect-monitor-price-pending-20261116.php');
$contract=(string)file_get_contents(__DIR__.'/monitor-price-hook-contract.php');
$cases=0;$fixture=[];
function check(bool $ok,string $name):void { global $cases;if(!$ok)throw new RuntimeException($name);$cases++; }
function reject(callable $call,string $reason):void {
    $actual=null;try{$call();}catch(Throwable $e){$actual=$e->getMessage();}
    check($actual===$reason,'expected_'.$reason.'_got_'.($actual??'success'));
}
function source_function(string $source,string $name):string {
    check(preg_match('/^function '.preg_quote($name,'/').'\(/m',$source,$match,PREG_OFFSET_CAPTURE)===1,'function_missing_'.$name);
    $tokens=token_get_all('<?php '.substr($source,$match[0][1]));$body='';$depth=0;$opened=false;
    foreach(array_slice($tokens,1) as $token){
        $text=is_array($token)?$token[1]:$token;$body.=$text;
        if($token==='{'||(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$depth++;$opened=true;}
        elseif($token==='}'&&--$depth===0&&$opened)return $body;
    }
    throw new RuntimeException('function_unclosed_'.$name);
}
function source_between(string $source,string $start,string $end):string {
    check(substr_count($source,$start)===1,'block_start_not_unique');
    $from=strpos($source,$start);$to=strpos($source,$end,$from+strlen($start));
    check($to!==false,'block_end_missing');return substr($source,$from,$to-$from);
}
function define_source(string $source):void {
    eval('namespace '.__NAMESPACE__.'; use DateTimeImmutable; use RuntimeException; use Throwable; '.$source);
}
function monitor_price_require(bool $ok,string $reason):void { if(!$ok)throw new RuntimeException($reason); }
function reset_fixture(array $replies=[]):void {
    global $fixture;
    $stat=['dev'=>1,'ino'=>2,'size'=>123,'mtime'=>5,'ctime'=>6];
    $fixture=['replies'=>$replies,'events'=>[],'appends'=>[],'fstat_calls'=>0,'lstat_calls'=>0,
        'opened'=>$stat,'after'=>$stat,'named'=>$stat,'named_after'=>$stat,'clock'=>1000,'ticks'=>0,'reads'=>[],
        'history'=>['state'=>['schema'=>1,'pending'=>null,'completed'=>[7=>['operation'=>'retained']]],
            'attempted_runs'=>['run:123'=>true],'pin'=>['sha256'=>str_repeat('e',64),'bytes'=>123,'lines'=>2],'trailing_blank_lines'=>0]];
}
// These namespace doubles are the only I/O boundaries used by extracted code.
function exec(string $command,?array &$output=null,?int &$status=null):string|false {
    global $fixture;$fixture['events'][]='git:'.$command;
    check(str_starts_with($command,'GIT_OPTIONAL_LOCKS=0 git -c core.fsmonitor=false -c core.untrackedCache=false '),'git_read_not_readonly');
    $reply=array_shift($fixture['replies']);check(is_array($reply),'unexpected_git_execution');
    check(str_contains($command,$reply['command']),'unexpected_git_command');
    $output=$reply['lines'];$status=$reply['status'];return $output?end($output):'';
}
function fopen(string $path,string $mode){
    global $fixture;check($path==='fixture://journal','unexpected_file_open');
    $fixture['events'][]='open:'.$mode;return \fopen('php://temp','w+b');
}
function flock($handle,int $mode):bool { global $fixture;$fixture['events'][]='lock:'.$mode;return true; }
function fclose($handle):bool { global $fixture;$fixture['events'][]='close';return \fclose($handle); }
function is_link(string $path):bool { return false; }
function clearstatcache(bool $clear=false,string $path=''):void {}
function fstat($handle):array { global $fixture;return ++$fixture['fstat_calls']===1?$fixture['opened']:$fixture['after']; }
function lstat(string $path):array { global $fixture;return ++$fixture['lstat_calls']===1?$fixture['named']:$fixture['named_after']; }
function time():int { global $fixture;return $fixture['clock']; }
function hrtime(bool $number=false):int|array { global $fixture;return ++$fixture['ticks']*1000000; }
function monitor_hook_read_history($handle):array { global $fixture;$fixture['events'][]='history';return $fixture['history']; }
function hook_append($handle,array $state):void { global $fixture;$fixture['events'][]='append';$fixture['appends'][]=$state; }
function hook_snapshots(array $rows,DateTimeImmutable $today):array {
    global $fixture;$ids=array_column($rows,'tourvisor_hotel_id');$fixture['reads'][]=$ids;$read=count($fixture['reads']);
    $fixture['clock']+=$fixture['read_advance'][$read]??0;
    return array_fill_keys($ids,['read'=>$read]);
}
function hook_journal(string $operation):array { return ['operation'=>$operation,'complete'=>true]; }
function monitor_hook_apply_abandonment(array $state,array $journal):?array { return null; }
function hook_prepare_feed(array $journal,string $installed):array {
    return ['operation'=>'fixture-feed','files'=>[['name'=>'fixture.xml','sha256'=>str_repeat('b',64),'bytes'=>1,'rows'=>1,'expected_previous_sha256'=>str_repeat('c',64)]]];
}
function hook_child(string $source,array $constants,?array &$failure=null):array {
    check($source==='fixture-writer','unexpected_child_source');
    $failure=['exit_code'=>2,'error'=>'fixture_failure'];throw new RuntimeException('hook_child_failed_read_original_journal');
}

$pins=[];foreach(range(1,22) as $id)$pins['fixture-'.$id.'.php']=sha1('fixture-'.$id);
define(__NAMESPACE__.'\HOOK_ACCEPTED_BASE',str_repeat('a',40));
define(__NAMESPACE__.'\HOOK_RUNTIME_PINS_B64',base64_encode(json_encode($pins,JSON_THROW_ON_ERROR)));
define(__NAMESPACE__.'\HOOK_WRITER_B64',base64_encode('fixture-writer'));
foreach(['hook_need','hook_git','hook_runtime','hook_measure','hook_finish_feed'] as $name)define_source(source_function($driver,$name));
foreach(['need','read_git'] as $name)define_source(source_function($inspector,$name));
foreach(['monitor_hook_order','monitor_hook_can_start_pair','monitor_hook_snapshot_refresh_due'] as $name)define_source(source_function($contract,$name));
function runtime_replies(string $head):array {
    global $pins;
    return [['command'=>"'rev-parse' 'HEAD'",'lines'=>[$head],'status'=>0],
        ['command'=>"'status' '--porcelain' '--untracked-files=no'",'lines'=>[],'status'=>0],
        ['command'=>"'merge-base' '--is-ancestor'",'lines'=>[],'status'=>0],
        ['command'=>"'hash-object' '--'",'lines'=>array_values($pins),'status'=>0]];
}

// Git failure with empty output cannot become a clean runtime. Batch comparison
// must retain all 22 pins in their original order and propagate process errors.
$head=str_repeat('d',40);reset_fixture(runtime_replies($head));
check(hook_runtime()===$head&&$fixture['replies']===[],'runtime_success_changed');
check(str_contains(end($fixture['events']),"'fixture-1.php'")&&str_contains(end($fixture['events']),"'fixture-22.php'"),'pin_arguments_missing');
foreach(['empty_failure','dirty','ancestor','hash_failure','hash_missing','hash_reordered','hash_changed'] as $bad){
    $replies=runtime_replies($head);$reason='hook_publishing_surface_changed';
    if($bad==='empty_failure'){$replies[1]['status']=128;$reason='hook_runtime_git_failed';}
    if($bad==='dirty'){$replies[1]['lines']=[' M tracked.php'];$reason='hook_runtime_changed';}
    if($bad==='ancestor'){$replies[2]['status']=1;$reason='hook_runtime_not_accepted_descendant';}
    if($bad==='hash_failure'){$replies[3]['status']=128;$replies[3]['lines']=[];}
    if($bad==='hash_missing')array_pop($replies[3]['lines']);
    if($bad==='hash_reordered')$replies[3]['lines']=array_reverse($replies[3]['lines']);
    if($bad==='hash_changed')$replies[3]['lines'][0]=str_repeat('0',40);
    reset_fixture($replies);reject(fn()=>hook_runtime(),$reason);
}

// Run the real lock/history/init block, with only a memory stream and finite stat
// records. In particular, a post-lock source change must precede every append.
$initial=source_between($driver,'    $installed=hook_measure(',"\n    \$private=");
$history=source_between($driver,"    \$history=['state'=>null",'    if(!$inspect)hook_reconcile_channel_preflight');
define_source('function run_history_fixture(bool $inspect=false,bool $existed=true):array { $out=[];$path="fixture://journal";$lock=null;try {'
    .$initial.$history.'return ["state"=>$state,"history"=>$history,"out"=>$out];}finally{if(is_resource($lock))\\fclose($lock);}}');
reset_fixture(array_merge(runtime_replies($head),runtime_replies($head)));
$saved=$fixture['history'];$result=run_history_fixture();
check($result['history']===$saved&&$result['state']===$saved['state']&&$result['out']['journal_read']===$saved['pin'],'history_projection_changed');
check($fixture['appends']===[],'existing_history_appended');
$gitEvents=array_keys(array_filter($fixture['events'],static fn(string $event)=>str_starts_with($event,'git:')));
$lockedAt=array_search('lock:'.(LOCK_EX|LOCK_NB),$fixture['events'],true);$readAt=array_search('history',$fixture['events'],true);
check(count($gitEvents)===8&&is_int($lockedAt)&&is_int($readAt)&&$gitEvents[3]<$lockedAt
    &&$lockedAt<$gitEvents[4]&&$gitEvents[7]<$readAt,'runtime_recheck_not_between_lock_and_history');
check($result['out']['timings_ms']===['runtime_validation'=>2,'journal_read'=>1],'stage_timings_not_accumulated');
reset_fixture(array_merge(runtime_replies($head),runtime_replies(str_repeat('f',40))));
reject(fn()=>run_history_fixture(),'hook_runtime_changed');
check(!in_array('history',$fixture['events'],true)&&$fixture['appends']===[],'source_change_reached_history_or_append');
reset_fixture(array_merge(runtime_replies($head),runtime_replies($head)));$fixture['named']['ino']=99;
reject(fn()=>run_history_fixture(),'hook_journal_identity_changed_stop');
check(!in_array('history',$fixture['events'],true)&&$fixture['appends']===[],'wrong_inode_reached_history_or_append');
reset_fixture(array_merge(runtime_replies($head),runtime_replies($head)));$fixture['after']['size']++;
reject(fn()=>run_history_fixture(),'hook_journal_changed_stop');check($fixture['appends']===[],'changed_journal_appended');
reset_fixture(array_merge(runtime_replies($head),runtime_replies($head)));
$fixture['history']=['state'=>null,'attempted_runs'=>[],'pin'=>['sha256'=>hash('sha256',''),'bytes'=>0,'lines'=>0],'trailing_blank_lines'=>0];
$result=run_history_fixture(false,false);
check(count($fixture['appends'])===1&&end($fixture['events'])==='append','new_state_not_appended_after_checks');
reset_fixture(array_merge(runtime_replies($head),runtime_replies($head)));run_history_fixture(true);
check(in_array('open:rb',$fixture['events'],true)&&in_array('lock:'.(LOCK_SH|LOCK_NB),$fixture['events'],true)
    &&in_array('close',$fixture['events'],true)&&$fixture['appends']===[],'inspection_lock_or_readonly_changed');
reset_fixture(array_merge(runtime_replies($head),runtime_replies($head)));$fixture['history']['trailing_blank_lines']=1;
reject(fn()=>run_history_fixture(),'hook_journal_trailing_blank_stop');check($fixture['appends']===[],'trailing_blank_history_appended');
reset_fixture(array_merge(runtime_replies($head),runtime_replies($head)));$fixture['history']['trailing_blank_lines']=1;
run_history_fixture(true);check($fixture['appends']===[],'trailing_blank_inspection_wrote');

// Inspector's actual runtime projection distinguishes failed Git from clean and
// refuses a HEAD change within the observation, without config or app loading.
$runtime=source_between($inspector,"    \$out['runtime']=['head'=>null",'    $out[\'ok\'] = true;');
define_source('function run_inspector_fixture():array {$out=[];try {'.$runtime.'}catch(Throwable $e){$out["error"]=$e->getMessage();}return $out;}');
foreach(['stable','status_failed','head_changed'] as $mode){
    $replies=[['command'=>"'rev-parse' 'HEAD'",'lines'=>[$head],'status'=>0],
        ['command'=>"'status' '--porcelain' '--untracked-files=no'",'lines'=>[],'status'=>$mode==='status_failed'?128:0],
        ['command'=>"'rev-parse' 'HEAD'",'lines'=>[$mode==='head_changed'?str_repeat('f',40):$head],'status'=>0]];
    reset_fixture($replies);$result=run_inspector_fixture();
    if($mode==='stable')check($result['runtime']===['head'=>$head,'tracked_clean'=>true,'git_checked'=>true],'inspector_stable_rejected');
    else check($result['runtime']===['head'=>null,'tracked_clean'=>null,'git_checked'=>false]
        &&$result['error']===($mode==='status_failed'?'runtime_git_failed':'runtime_head_changed'),'inspector_failure_became_clean');
}

// Run the actual foreach/refresh prefix over an initially sparse-key roster.
// The production ordering function reindexes it; refresh must include current.
$initialRead=source_between($driver,"\n    \$snapshotsFetchedAt=time();",'    foreach($roster as');
$scanMarker="        \$out['scanned_pairs']++;";
$loop=source_between($driver,'    foreach($roster as',$scanMarker).$scanMarker;
define_source('function run_refresh_fixture(int $admitUntil):array {global $fixture;
    $roster=monitor_hook_order([9=>["tourvisor_hotel_id"=>30],7=>["tourvisor_hotel_id"=>10],42=>["tourvisor_hotel_id"=>20]],[]);
    $inspect=false;$nightly=true;$policy=["admit_until"=>$admitUntil,"pair_limit"=>100];$today=new DateTimeImmutable("@0");
    $out=["updated_pairs"=>0,"scanned_pairs"=>0];$processed=[];'.$initialRead.$loop.'
        $tv=$row["tourvisor_hotel_id"];check(isset($snapshots[$tv]),"current_snapshot_omitted");
        $processed[$tv]=$snapshots[$tv]["read"];$fixture["clock"]+=700;
    }return ["out"=>$out,"processed"=>$processed];}');
reset_fixture();$result=run_refresh_fixture(4000);
check($fixture['reads']===[[10,20,30],[20,30],[30]],'refresh_revisited_prefix_or_omitted_current');
check($result['processed']===[10=>1,20=>2,30=>3]&&$result['out']['snapshot_reads']===3&&$result['out']['scanned_pairs']===3,'refresh_processed_wrong_snapshot');
reset_fixture();$fixture['read_advance'][2]=400;$result=run_refresh_fixture(2000);
check($fixture['reads']===[[10,20,30],[20,30]]&&$result['processed']===[10=>1]
    &&$result['out']['scanned_pairs']===1&&$result['out']['bounded_pass_stopped']===true,'refresh_overran_budget_or_counted_unscanned_current');

// A real hook_finish_feed failure path tests the new timing closure's reference
// capture: the child failure must remain pending and recorded, never completed.
reset_fixture();$out=[];$state=['pending'=>['operation'=>'fixture-post','tourvisor_hotel_id'=>7,'phase'=>'post_started'],'completed'=>[]];
reject(function()use(&$state,&$out,$head){hook_finish_feed($state,null,$head,$out);},'hook_child_failed_read_original_journal');
check(($state['pending']['child_failure']??null)===['exit_code'=>2,'error'=>'fixture_failure']&&$state['completed']===[],'timing_closure_lost_child_failure');
check(count($fixture['appends'])===2&&end($fixture['appends'])===$state,'feed_failure_not_journaled');
check(($out['timings_ms']['feed_prepare']??null)===1&&($out['timings_ms']['feed_write']??null)===1
    &&!isset($out['timings_ms']['feed_readback']),'failed_stage_timing_lost_or_readback_started');
echo 'MONITOR_PRICE_DRIVER_RELIABILITY_OK cases='.$cases."\n";
