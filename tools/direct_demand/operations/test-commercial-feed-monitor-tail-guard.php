<?php
declare(strict_types=1);
// Native PHP fixture for the existing guard. Only its fixed journal path is rebound
// into a private temporary directory; the guard body and lock calls run unchanged.
$source=file_get_contents(__DIR__.'/commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php');
if(!is_string($source))throw new RuntimeException('fixture_source_missing');
$anchor="'/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl'";
if(substr_count($source,$anchor)!==1)throw new RuntimeException('fixture_path_anchor_changed');
$dir=sys_get_temp_dir().'/feed-monitor-tail-guard-'.bin2hex(random_bytes(8));
if(!mkdir($dir,0700))throw new RuntimeException('fixture_directory_failed');
$journal=$dir.'/journal.jsonl';$script=$dir.'/guard.php';$cases=[];
function fixture_need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function fixture_json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";}
function fixture_padded_row(int $bytes):string{
    $head='{"pending":null,"padding":"';$end='"}'."\n";
    return $head.str_repeat('x',$bytes-strlen($head)-strlen($end)).$end;
}
$normal=fixture_json(['schema'=>1,'pending'=>null,'completed'=>[]]);
$active=fixture_json(['schema'=>1,'pending'=>null,'nightly_cycle'=>['state'=>'started','collector_run_id'=>'fixture']]);
$bound=str_replace($anchor,var_export($journal,true),$source);
$bound.="\n\$probe=fopen(".var_export($journal,true).",'rb');\n";
$bound.="if(\$probe===false||flock(\$probe,LOCK_EX|LOCK_NB)){throw new RuntimeException('fixture_guard_did_not_retain_lock');}\n";
$bound.="fclose(\$probe);echo json_encode(['fixture_guard_passed'=>true,'lock_held'=>true],JSON_THROW_ON_ERROR),PHP_EOL;\n";
file_put_contents($script,$bound);chmod($script,0600);
$run=static function(string $name,?string $error=null,bool $holdLock=false)use($journal,$script,&$cases):void{
    $before=hash_file('sha256',$journal);$size=filesize($journal);$holder=null;
    if($holdLock){$holder=fopen($journal,'rb');fixture_need(is_resource($holder)&&flock($holder,LOCK_EX|LOCK_NB),'fixture_lock_setup_failed');}
    try{
        // The >100 MB journal case must pass with bounded memory. A whole-file read
        // cannot pass this budget, even if its former size check is simply removed.
        $process=proc_open([PHP_BINARY,'-d','display_errors=stderr','-d','memory_limit=16M',$script],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fixture_need(is_resource($process),'fixture_child_failed');fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    }finally{if(is_resource($holder)){flock($holder,LOCK_UN);fclose($holder);}}
    fixture_need($stderr==='',$name.':unexpected_stderr');
    $out=json_decode(trim((string)$stdout),true,64,JSON_THROW_ON_ERROR);
    if($error===null){
        fixture_need($exit===0&&$out===['fixture_guard_passed'=>true,'lock_held'=>true],$name.':guard_not_accepted');
    }else{
        fixture_need($exit===2&&$out['ok']===false&&$out['error']===$error,$name.':guard_not_rejected');
        fixture_need($out['operation']==='commercial-channel-hotel-feeds-20261113-update10'
            &&$out['mode']==='publish_three_static_public_direct_yml_feeds'
            &&$out['db_writes']===$out['provider_calls']&&$out['provider_calls']===$out['message_sends']
            &&$out['message_sends']===0&&$out['files']===[],$name.':failure_boundary_changed');
    }
    clearstatcache(true,$journal);
    fixture_need(filesize($journal)===$size&&hash_file('sha256',$journal)===$before,$name.':journal_changed');
    $released=fopen($journal,'rb');fixture_need(is_resource($released)&&flock($released,LOCK_EX|LOCK_NB),$name.':lock_not_released_at_exit');
    flock($released,LOCK_UN);fclose($released);
    $cases[]=['case'=>$name,'result'=>'PASS','expected'=>$error??'accepted'];
};
$put=static function(string $raw)use($journal):void{file_put_contents($journal,$raw);clearstatcache(true,$journal);};
try{
    $put($normal);$run('complete_single_record');
    $put($normal.$normal);$run('complete_last_record');
    $put($normal."\n\n");$run('legacy_trailing_lf_semantics');
    $put('');$run('empty','monitor_journal_incomplete');
    $put(rtrim($normal,"\n"));$run('truncated_without_final_lf','monitor_journal_incomplete');
    $put("{broken}\n");$run('invalid_json','monitor_guard_failed');
    $put(fixture_json(['schema'=>1]));$run('missing_pending','monitor_pending_operation_stop');
    $put(fixture_json(['schema'=>1,'pending'=>['phase'=>'post_started']]));$run('pending_operation','monitor_pending_operation_stop');
    $put($active);$run('active_cycle_with_live_lock','monitor_already_running',true);
    // The old guard has no independent cycle/age/schema policy. Preserve that
    // behavior: pending-null plus an available lock is its existing admission.
    $put($active);$run('historical_active_cycle_without_lock_unchanged');
    $put(fixture_json(['pending'=>null]));$run('existing_pending_only_schema_unchanged');
    $put($normal.fixture_padded_row(2000000));$run('exact_last_record_bound');
    $put($normal.fixture_padded_row(2000001));$run('oversize_last_record','monitor_journal_incomplete');
    $put(fixture_padded_row(2000001));$run('oversize_only_record','monitor_journal_incomplete');
    $f=fopen($journal,'wb');fixture_need(is_resource($f),'fixture_large_open_failed');
    $chunk=str_repeat($normal,2048);$written=0;
    while($written<=100000000){$n=fwrite($f,$chunk);fixture_need($n===strlen($chunk),'fixture_large_write_failed');$written+=$n;}
    fixture_need(fwrite($f,$normal)===strlen($normal),'fixture_terminal_write_failed');fclose($f);clearstatcache(true,$journal);
    $run('complete_journal_above_former_100mb_limit');
    echo json_encode(['ok'=>true,'fixture'=>'existing_feed_monitor_tail_guard','cases'=>$cases,
        'last_record_byte_limit'=>2000000,'tail_read_byte_limit'=>2000001,'child_memory_limit'=>'16M',
        'provider_calls'=>0,'network_calls'=>0,'production_journal_access'=>0],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
}finally{
    if(is_file($journal))unlink($journal);if(is_file($script))unlink($script);rmdir($dir);
}
