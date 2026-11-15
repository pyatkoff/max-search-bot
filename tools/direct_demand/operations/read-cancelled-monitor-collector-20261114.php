<?php
declare(strict_types=1);
// Standalone read-only evidence collector. Never load or execute application code.
const CR_ROOT='/var/www/anytoour/data/channel-post-operations';
const CR_APP='/var/www/anytoour/data/www/app.anytoour.ru';
const CR_SITE='/var/www/anytoour/data/www/anytoour.ru/data';
const CR_RUN='43654792363';
const CR_INSTALLED='62fc5cf8cf5cb3e41b370a808c2281d27ae83da7';
const CR_HOOK_SHA='6ea8080b59cfd02bf20d270701530a8e45376a4618dc53d1a22845be74e5038d';
const CR_READER_SHA='6628fe156013bb6e856313bc2d7f058a54da21325768fbc9fb1886ce749134e3';
const CR_BASE_OP='commercial-monitor-price-h462-c9272773e41751b80a3c55aaa5e6bfb57c3f0425cdeec40ebcccde398fcb4ae5';
const CR_BASE_FEED='commercial-channel-hotel-feeds-20261114-update1205137553359229';
const CR_HELD_TV=13941;
const CR_RUN_STARTED_AT='2026-11-14T23:45:12+00:00';
const CR_PASS_COMPLETED_AT='2026-11-14T23:50:38+00:00';
const CR_PRIOR_GLOBAL_SHA='07660504a4ae5ed560ac309f025bf1b34ccfb8687d46fcfb0ba636205e0823b2';
const CR_PRIOR_GLOBAL_BYTES=199500601;
const CR_PRIOR_GLOBAL_LINES=2516;
const CR_BASE_COMPLETED_SHA='ec6ad537987ebcc73fed5b239aad4fd7ecdb074abe2be05240fcd4d06aa7e0d5';
const CR_HELD_POSTS=['max'=>'https://max.ru/channel_anytour/AaG2QvvlA2g','telegram'=>'https://t.me/any_toour/242'];
$crLocks=[];$crStart=hrtime(true);
function cr_need(bool $ok,string $code):void{if(!$ok)throw new RuntimeException($code);}
function cr_json($v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
function cr_hash($v):string{return hash('sha256',cr_json($v));}
function cr_time($v):?string{return is_string($v)&&preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D',$v)===1?$v:null;}
function cr_id($v):?string{return (is_int($v)||is_string($v))&&preg_match('/\A[1-9][0-9]{0,19}\z/D',(string)$v)===1?(string)$v:null;}
function cr_enum($v,array $allowed):?string{return is_string($v)&&in_array($v,$allowed,true)?$v:null;}
function cr_deadline():void{global $crStart;cr_need((hrtime(true)-$crStart)<120000000000,'read_time_limit_unknown');}
function cr_operation($v,int $tv=0):string{
    cr_need(is_string($v)&&preg_match('/\Acommercial-monitor-price-h([1-9][0-9]*)-[a-f0-9]{64}\z/D',$v,$m)===1,'operation_shape_unknown');
    cr_need($tv===0||(string)$tv===$m[1],'operation_hotel_mismatch_unknown');return $v;
}
function cr_stat(array $s):array{return array_intersect_key($s,array_flip(['dev','ino','size','mtime','ctime']));}
function cr_check_locks():void{
    global $crLocks;
    foreach($crLocks as $r){clearstatcache(true,$r['path']);$named=@lstat($r['path']);$open=fstat($r['stream']);
        cr_need(is_array($named)&&is_array($open)&&!is_link($r['path'])&&realpath($r['path'])===$r['path'],'locked_file_changed_unknown');
        cr_need(cr_stat($named)===cr_stat($r['before'])&&cr_stat($open)===cr_stat($r['before']),'locked_file_changed_unknown');}
}
function cr_stream(string $path,int $cap,callable $visit,array &$meta):array{
    global $crLocks;
    $meta=['complete_jsonl'=>false,'sha256'=>null,'bytes'=>0,'line_count'=>0];
    cr_need(!is_link(dirname($path))&&realpath(dirname($path))===dirname($path)&&!is_link($path)&&realpath($path)===$path,'journal_path_unknown');
    $before=@lstat($path);cr_need(is_array($before)&&($before['mode']&0170000)===0100000,'journal_missing_or_not_regular_unknown');
    $meta['observed_stat']=cr_stat($before);cr_need($before['size']>0&&$before['size']<=$cap,'journal_size_limit_unknown');
    $f=@fopen($path,'rb');cr_need(is_resource($f),'journal_open_unknown');
    $crLocks[]=['path'=>$path,'stream'=>$f,'before'=>$before];
    cr_need(flock($f,LOCK_SH|LOCK_NB),'journal_busy_unknown');$meta['shared_lock_acquired']=true;
    cr_check_locks();$h=hash_init('sha256');$last=[];
    while(!feof($f)){
        cr_deadline();$line=fgets($f,2000002);if($line===false){cr_need(feof($f),'journal_read_unknown');break;}
        $meta['bytes']+=strlen($line);$meta['line_count']++;
        cr_need($meta['bytes']<=$cap&&$meta['line_count']<=100000&&strlen($line)<=2000000&&str_ends_with($line,"\n"),'journal_partial_or_limit_unknown');
        hash_update($h,$line);
        try{$row=json_decode($line,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new RuntimeException('journal_json_unknown');}
        cr_need(is_array($row),'journal_shape_unknown');$visit($row,$meta['line_count'],hash('sha256',$line),$line);$last=$row;
    }
    cr_need($meta['bytes']===$before['size'],'journal_size_changed_unknown');cr_check_locks();
    $meta['sha256']=hash_final($h);$meta['complete_jsonl']=true;return $last;
}
function cr_file(string $path,int $cap,string $expected,?int $expectedSize=null):array{
    cr_need(!is_link($path)&&realpath($path)===$path,'source_path_unknown');$s=@lstat($path);
    cr_need(is_array($s)&&($s['mode']&0170000)===0100000&&$s['size']>0&&$s['size']<=$cap,'source_file_unknown');
    $f=@fopen($path,'rb');cr_need(is_resource($f),'source_read_unknown');
    try{$h=hash_init('sha256');$n=hash_update_stream($h,$f,$cap+1);$sha=hash_final($h);$open=fstat($f);}finally{fclose($f);}
    clearstatcache(true,$path);$after=@lstat($path);
    cr_need($n===$s['size']&&is_array($after)&&is_array($open)&&cr_stat($s)===cr_stat($after)&&cr_stat($s)===cr_stat($open),'source_changed_during_read_unknown');
    return ['sha256'=>$sha,'stat'=>cr_stat($s),'expected_sha256'=>$expected,'expected_size'=>$expectedSize,
        'matches_expected'=>$sha===$expected&&($expectedSize===null||$s['size']===$expectedSize)];
}
function cr_completed(array $map):array{
    cr_need(count($map)<=1000,'completed_count_limit_unknown');$out=[];
    foreach($map as $tv=>$row){cr_need(cr_id($tv)!==null&&is_array($row),'completed_shape_unknown');
        $op=cr_operation($row['operation']??null,(int)$tv);$feed=$row['feed_operation']??null;
        cr_need(is_string($feed)&&preg_match('/\Acommercial-channel-hotel-feeds-[0-9]{8}-update[0-9]+\z/D',$feed)===1,'completed_feed_shape_unknown');
        $out[(string)$tv]=['operation'=>$op,'at'=>cr_time($row['at']??null),'feed_operation'=>$feed,'tuple_sha256'=>cr_hash($row)];}
    return $out;
}
function cr_cycle($cycle):?array{
    if($cycle===null)return null;cr_need(is_array($cycle)&&is_string($cycle['collector_run_id']??null)&&cr_id($cycle['collector_run_id'])!==null,'cycle_shape_unknown');
    $state=cr_enum($cycle['state']??null,['started','completed','budget_exhausted','interrupted_reconciled','preflight_blocked']);
    cr_need($state!==null,'cycle_state_unknown');
    $out=['collector_run_id'=>$cycle['collector_run_id'],'state'=>$state,'collection_started_at'=>cr_time($cycle['collection_started_at']??null),
        'started_at'=>cr_time($cycle['started_at']??null),'finished_at'=>cr_time($cycle['finished_at']??null),'tuple_sha256'=>cr_hash($cycle)];
    foreach(['budget_seconds','finish_reserve_seconds','duration_seconds','updated_pairs','unchanged_pairs','eligible_pairs','held_pairs','scanned_pairs','remaining_unscanned_pairs'] as $k)
        if(array_key_exists($k,$cycle))$out[$k]=is_int($cycle[$k])&&$cycle[$k]>=0?$cycle[$k]:null;
    return $out;
}
function cr_pending($pending):?array{
    if($pending===null)return null;cr_need(is_array($pending)&&is_int($pending['tourvisor_hotel_id']??null),'pending_shape_unknown');
    $tv=$pending['tourvisor_hotel_id'];$op=cr_operation($pending['operation']??null,$tv);
    $phase=cr_enum($pending['phase']??null,['post_started','feed_started']);cr_need($phase!==null,'pending_phase_unknown');
    $out=['tourvisor_hotel_id'=>$tv,'operation'=>$op,'phase'=>$phase,'at'=>cr_time($pending['at']??null),'tuple_sha256'=>cr_hash($pending)];
    if(isset($pending['child_failure'])){$f=$pending['child_failure'];cr_need(is_array($f),'child_failure_shape_unknown');
        $out['child_failure']=['exit_code'=>is_int($f['exit_code']??null)?$f['exit_code']:null,'error_present'=>isset($f['error']),'error_sha256'=>isset($f['error'])?cr_hash($f['error']):null];}
    if(isset($pending['files'])){
        cr_need(is_array($pending['files'])&&count($pending['files'])===3,'pending_files_shape_unknown');$out['files']=[];$names=[];
        foreach($pending['files'] as $file){cr_need(is_array($file)&&in_array($file['name']??null,['hotel-posts-max.xml','hotel-posts-telegram.xml','hotel-posts-all.xml'],true),'pending_file_scope_unknown');
            cr_need(!isset($names[$file['name']]),'pending_duplicate_file_unknown');$names[$file['name']]=true;
            foreach(['sha256','expected_previous_sha256'] as $k)cr_need(is_string($file[$k]??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$file[$k])===1,'pending_file_hash_unknown');
            $out['files'][]=['name'=>$file['name'],'sha256'=>$file['sha256'],'expected_previous_sha256'=>$file['expected_previous_sha256'],
                'bytes'=>is_int($file['bytes']??null)?$file['bytes']:null,'rows'=>is_int($file['rows']??null)?$file['rows']:null];}}
    return $out;
}
function cr_small(string $path,int $cap):?string{
    $f=@fopen($path,'rb');if(!is_resource($f))return null;
    try{$v=stream_get_contents($f,$cap+1);}finally{fclose($f);}return is_string($v)&&strlen($v)<=$cap?$v:null;
}
function cr_ticks():?int{
    $raw=cr_small('/proc/self/auxv',8192);if($raw===null)return null;$width=PHP_INT_SIZE;$format=$width===8?'Qtype/Qvalue':'Ltype/Lvalue';
    for($i=0;$i+2*$width<=strlen($raw);$i+=2*$width){$v=unpack($format,substr($raw,$i,2*$width));if($v['type']===0)break;
        if($v['type']===17)return is_int($v['value'])&&$v['value']>0&&$v['value']<=100000?$v['value']:null;}
    return null;
}
function cr_processes():array{
    $clock=cr_ticks();$up=cr_small('/proc/uptime',256);$uptime=$up!==null?(float)explode(' ',trim($up))[0]:null;
    $dir=@opendir('/proc');cr_need(is_resource($dir),'process_listing_unknown');$rows=[];$inaccessible=0;$raced=0;$scanned=0;
    $tags=['top500-daily-collector-v1.php'=>'collector','rollup-price-daily-v1.php'=>'daily_rollup','rollup-price-daily-exact-v1.php'=>'exact_rollup',
        'build-seo-offer-snapshots-v1.php'=>'seo_snapshot','monitor-price-hook.php'=>'monitor_hook'];
    try{while(($entry=readdir($dir))!==false){if(preg_match('/\A[0-9]+\z/D',$entry)!==1)continue;cr_deadline();cr_need(++$scanned<=16384,'process_count_limit_unknown');
        $pid=(int)$entry;$stat=cr_small('/proc/'.$entry.'/stat',8192);$cmd=cr_small('/proc/'.$entry.'/cmdline',131072);
        if($stat===null||$cmd===null){if(is_dir('/proc/'.$entry))$inaccessible++;else$raced++;continue;}
        $end=strrpos($stat,') ');if($end===false){$inaccessible++;continue;}$parts=explode(' ',substr($stat,$end+2));
        if(count($parts)<20||preg_match('/\A[0-9]+\z/D',$parts[1])!==1||preg_match('/\A[0-9]+\z/D',$parts[19])!==1){$inaccessible++;continue;}
        $argv=explode("\0",rtrim($cmd,"\0"));$role=null;
        foreach($argv as $arg)if(isset($tags[basename($arg)])){$role=$tags[basename($arg)];break;}
        $shell=str_contains($cmd,'ANYTOUR_MONITOR_COLLECTOR_RUN_ID')&&str_contains($cmd,CR_RUN)
            &&str_contains($cmd,'top500-daily-collector-v1.php')&&str_contains($cmd,'rollup-price-daily-exact-v1.php');
        if($shell)$role='original_remote_shell';
        $rows[$pid]=['pid'=>$pid,'ppid'=>(int)$parts[1],'start_ticks'=>(int)$parts[19],
            'elapsed_seconds'=>$clock!==null&&$uptime!==null?max(0,(int)floor($uptime-(int)$parts[19]/$clock)):null,
            'state'=>cr_enum($parts[0],['R','S','D','Z','T','t','X','x','K','W','P','I']),'script_tag'=>$role,
            '_shell'=>$shell,'_php'=>preg_match('/\Aphp(?:[0-9]+\.[0-9]+)?\z/D',basename($argv[0]??''))===1];
        unset($cmd,$argv);
    }}finally{closedir($dir);}
    $out=[];$roots=[];foreach($rows as $pid=>$r)if($r['_shell'])$roots[$pid]=true;
    foreach($rows as $pid=>$r){if($pid===getmypid())continue;$parent=$pid;$seen=[];$linked=false;
        for($depth=0;$depth<64&&isset($rows[$parent])&&!isset($seen[$parent]);$depth++){$seen[$parent]=true;if(isset($roots[$parent])){$linked=true;break;}$parent=$rows[$parent]['ppid'];}
        if($r['script_tag']===null&&!$linked&&!$r['_php'])continue;
        if($r['script_tag']===null)$r['script_tag']=!$linked?'unattributed_php_cli':($r['_php']?'php_stdin_descendant':'other_descendant');
        $r['original_ancestry']=$linked?'matched_original_shell':'unproved_scoped_script';unset($r['_shell'],$r['_php']);
        if($r['script_tag']==='monitor_hook'){
            $env=cr_small('/proc/'.$pid.'/environ',262144);$r['collector_environment_readable']=$env!==null;
            $r['collector_run_id']=null;$r['collection_started_at']=null;
            if($env!==null)foreach(explode("\0",$env) as $pair){$parts=explode('=',$pair,2);if(count($parts)!==2)continue;
                if($parts[0]==='ANYTOUR_MONITOR_COLLECTOR_RUN_ID')$r['collector_run_id']=cr_id($parts[1]);
                if($parts[0]==='ANYTOUR_MONITOR_COLLECTION_STARTED_AT')$r['collection_started_at']=cr_time($parts[1]);}
            unset($env,$pair,$parts);
        }
        $out[]=$r;cr_need(count($out)<=256,'scoped_process_limit_unknown');
    }
    return ['observed_at'=>gmdate('c'),'scan_complete'=>$inaccessible===0,'inaccessible_or_oversize'=>$inaccessible,'vanished_during_scan'=>$raced,
        'scanned_pids'=>$scanned,'clock_ticks_per_second'=>$clock,'processes'=>$out];
}
function cr_child(array $pending,array &$meta):array{
    $op=$pending['operation'];$manifest=null;$flags=['any_posts'=>false,'any_configs'=>false,'any_complete'=>false,'any_terminal'=>false];
    $last=cr_stream(CR_ROOT.'/'.$op.'/receipt.jsonl',10000000,static function(array $r,int $line,string $hash)use($op,&$manifest,&$flags):void{
        cr_need(($r['operation']??null)===$op&&is_array($r['posts']??null)&&is_array($r['configs']??null),'child_history_shape_unknown');
        $m=$r['manifest_hash']??null;cr_need(is_string($m)&&preg_match('/\A[a-f0-9]{64}\z/D',$m)===1,'child_manifest_unknown');
        if($manifest===null)$manifest=$m;cr_need($m===$manifest,'child_manifest_changed_unknown');
        $flags['any_posts']=$flags['any_posts']||$r['posts']!==[];$flags['any_configs']=$flags['any_configs']||$r['configs']!==[];
        $flags['any_complete']=$flags['any_complete']||($r['complete']??false)===true;$flags['any_terminal']=$flags['any_terminal']||isset($r['terminal']);
    },$meta);
    $out=$flags+['operation'=>$op,'manifest_hash'=>$manifest,'last_complete'=>is_bool($last['complete']??null)?$last['complete']:null,'last_terminal_present'=>isset($last['terminal']),
        'last_terminal_sha256'=>isset($last['terminal'])?cr_hash($last['terminal']):null,'last_posts'=>[],'no_write_conclusion'=>'not_inferred'];
    foreach($last['posts'] as $post){cr_need(is_array($post),'child_post_shape_unknown');$receipt=is_array($post['receipt']??null)?$post['receipt']:[];
        $out['last_posts'][]=['platform'=>cr_enum($post['platform']??$receipt['platform']??null,['max','telegram']),
            'state'=>cr_enum($post['state']??null,['edit_started','provider_response_recorded','text_edit_returned','confirmed']),
            'plan_present'=>isset($post['plan']),'plan_sha256'=>isset($post['plan'])?cr_hash($post['plan']):null,
            'receipt_state'=>cr_enum($receipt['state']??null,['sent','failed','unknown']),
            'publication_id'=>cr_id($receipt['publication_id']??null),'version_id'=>cr_id($receipt['version_id']??null),
            'body_photo_button_verified'=>is_bool($receipt['body_photo_button_verified']??null)?$receipt['body_photo_button_verified']:null,
            'existing_message_reused'=>is_bool($receipt['existing_message_reused']??null)?$receipt['existing_message_reused']:null];}
    return $out;
}

/** Pure validation of one recorded no-write terminal; no current provider inference. */
function cr_held_terminal(array $first,array $last,array $prefix,array $hold,array $meta):array{
    cr_need(array_keys($hold)===['operation','reason','terminal_journal_sha256','at']
        &&($hold['reason']??null)==='abandoned_before_write','held_shape_unknown');
    $op=cr_operation($hold['operation']??null,CR_HELD_TV);
    cr_need(is_string($hold['terminal_journal_sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$hold['terminal_journal_sha256'])===1
        &&($meta['sha256']??null)===$hold['terminal_journal_sha256'],'held_child_hash_changed');
    cr_need(($first['operation']??null)===$op&&($first['posts']??null)===[]&&($first['configs']??null)===[]
        &&(!array_key_exists('complete',$first)||$first['complete']===false)
        &&!array_key_exists('terminal',$first)&&!array_key_exists('_terminal_journal_sha256',$first),'held_prefix_has_write_intent');
    cr_need(is_string($first['manifest_hash']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$first['manifest_hash'])===1
        &&is_array($first['monitor_snapshot']??null)&&($first['feed_binding']['tourvisor_hotel_id']??null)===CR_HELD_TV
        &&($first['feed_binding']['posts']??null)===CR_HELD_POSTS,'held_prefix_scope_changed');
    $terminal=$last['terminal']??null;
    cr_need(is_array($terminal)&&array_keys($terminal)===['state','reason','prior_sha256','prior_bytes','prior_lines','recorded_at','preflight_error','child_exit_code']
        &&$terminal['state']==='abandoned_before_write'&&$terminal['reason']==='child_preflight_failed_before_write'
        &&$terminal['preflight_error']==='max_read_failed'&&$terminal['child_exit_code']===2,'held_terminal_scope_changed');
    cr_need(($prefix['lines']??0)>0&&($prefix['bytes']??0)>0
        &&$terminal['prior_sha256']===($prefix['sha256']??null)&&$terminal['prior_bytes']===$prefix['bytes']
        &&$terminal['prior_lines']===$prefix['lines'],'held_terminal_prefix_changed');
    $at=$terminal['recorded_at'];
    cr_need(is_string($at)&&preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/D',$at)===1
        &&$at>=CR_RUN_STARTED_AT&&$at<=CR_PASS_COMPLETED_AT&&$hold['at']===$at,'held_terminal_time_changed');
    $withoutTerminal=$last;unset($withoutTerminal['terminal']);
    cr_need($withoutTerminal===$first,'held_terminal_state_changed');
    return ['tourvisor_hotel_id'=>CR_HELD_TV,'operation'=>$op,'hold'=>$hold,'hold_tuple_sha256'=>cr_hash($hold),
        'manifest_hash'=>$first['manifest_hash'],'terminal'=>$terminal,'terminal_tuple_sha256'=>cr_hash($terminal),
        'no_write_prefix'=>$prefix,'all_prefix_states_equal'=>true,'all_history_posts_configs_empty'=>true,
        'all_history_complete_false'=>true,'terminal_retains_original_state'=>true,
        'monitor_snapshot_sha256'=>cr_hash($first['monitor_snapshot']),'feed_binding_sha256'=>cr_hash($first['feed_binding']),
        'feed_binding'=>['tourvisor_hotel_id'=>CR_HELD_TV,'posts'=>CR_HELD_POSTS],
        'current_native_message_readback'=>false,'business_admission'=>'blocked_pending_review'];
}
/** Same existing stream reader, one fixed held child, full prefix and terminal. */
function cr_held_child(array $hold,array &$meta):array{
    $op=cr_operation($hold['operation']??null,CR_HELD_TV);$first=null;$terminalSeen=false;
    $ctx=hash_init('sha256');$bytes=0;$lines=0;
    $last=cr_stream(CR_ROOT.'/'.$op.'/receipt.jsonl',10000000,static function(array $r,int $line,string $hash,string $raw)use($op,&$first,&$terminalSeen,$ctx,&$bytes,&$lines):void{
        cr_need(!$terminalSeen&&$raw===cr_json($r)."\n",'held_child_encoding_or_suffix_changed');
        cr_need(($r['operation']??null)===$op&&($r['posts']??null)===[]&&($r['configs']??null)===[]
            &&(!array_key_exists('complete',$r)||$r['complete']===false)&&!array_key_exists('_terminal_journal_sha256',$r),'held_history_has_write_intent');
        if(array_key_exists('terminal',$r)){cr_need($first!==null,'held_prefix_missing');$terminalSeen=true;return;}
        if($first===null)$first=$r;else cr_need($r===$first,'held_prefix_state_changed');
        hash_update($ctx,$raw);$bytes+=strlen($raw);$lines++;
    },$meta);
    cr_need($first!==null&&$terminalSeen,'held_terminal_missing');
    return cr_held_terminal($first,$last,['sha256'=>hash_final($ctx),'bytes'=>$bytes,'lines'=>$lines],$hold,$meta);
}

if(PHP_SAPI!=='cli'){exit(2);}
if(($argv[1]??null)==='--self-test'){
    cr_need(cr_time('2026-11-14T17:41:07+00:00')!==null&&cr_time('secret')===null,'selftest_time');
    cr_need(cr_pending(null)===null&&cr_id('43654792363')===CR_RUN,'selftest_identity');
    $p=cr_pending(['tourvisor_hotel_id'=>462,'operation'=>CR_BASE_OP,'phase'=>'post_started','at'=>'2026-11-14T17:41:07+00:00','secret'=>'not_exported']);
    cr_need(!array_key_exists('secret',$p)&&$p['operation']===CR_BASE_OP,'selftest_projection');
    $blocked=false;try{cr_operation(CR_BASE_OP,1142);}catch(RuntimeException $e){$blocked=true;}cr_need($blocked,'selftest_binding');

    $op='commercial-monitor-price-h13941-'.str_repeat('a',64);
    $first=['operation'=>$op,'manifest_hash'=>str_repeat('b',64),'configs'=>[],'posts'=>[],
        'monitor_snapshot'=>['synthetic'=>true],'feed_binding'=>['tourvisor_hotel_id'=>CR_HELD_TV,'posts'=>CR_HELD_POSTS]];
    $raw=cr_json($first)."\n";$prefix=['sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw),'lines'=>1];
    $last=$first;$last['terminal']=['state'=>'abandoned_before_write','reason'=>'child_preflight_failed_before_write',
        'prior_sha256'=>$prefix['sha256'],'prior_bytes'=>$prefix['bytes'],'prior_lines'=>1,
        'recorded_at'=>'2026-11-14T23:46:00+00:00','preflight_error'=>'max_read_failed','child_exit_code'=>2];
    $meta=['sha256'=>hash('sha256',$raw.cr_json($last)."\n")];
    $hold=['operation'=>$op,'reason'=>'abandoned_before_write','terminal_journal_sha256'=>$meta['sha256'],'at'=>$last['terminal']['recorded_at']];
    $proof=cr_held_terminal($first,$last,$prefix,$hold,$meta);
    cr_need($proof['all_history_posts_configs_empty']&&$proof['terminal']===$last['terminal'],'selftest_held_positive');
    foreach(['hold_hash','prefix_hash','reason','error','exit','write_intent','time'] as $case){
        $f=$first;$l=$last;$h=$hold;
        if($case==='hold_hash')$h['terminal_journal_sha256']=str_repeat('0',64);
        elseif($case==='prefix_hash')$l['terminal']['prior_sha256']=str_repeat('0',64);
        elseif($case==='reason')$l['terminal']['reason']='expired_observation_after_no_write_failure';
        elseif($case==='error')$l['terminal']['preflight_error']='existing_max_readback_changed';
        elseif($case==='exit')$l['terminal']['child_exit_code']=1;
        elseif($case==='write_intent')$f['posts']=['intent'=>true];
        else $l['terminal']['recorded_at']='2026-11-14T23:51:00+00:00';
        $blocked=false;try{cr_held_terminal($f,$l,$prefix,$h,$meta);}catch(RuntimeException $e){$blocked=true;}
        cr_need($blocked,'selftest_held_negative');
    }

    echo "MONITOR_HOLD_READER_SELF_TEST_OK cases=12 runtime_reads=0 writes=0\n";exit(0);
}
$out=['ok'=>false,'mode'=>'read_only_monitor_hold_reconciliation','original_run_id'=>CR_RUN,'target_tourvisor_hotel_id'=>CR_HELD_TV,'expected_installed_sha'=>CR_INSTALLED,
    'started_at'=>gmdate('c'),'business_admission'=>'blocked_pending_review','original_run_kind'=>'standalone_saved_monitor_continuation',
    'journal_writes'=>0,'database_calls'=>0,'provider_calls'=>0,'network_calls'=>0,'hook_executions'=>0,'process_signals'=>0];
try{
    cr_need(count($argv)===1,'reader_argument_invalid');
    $out['process_before']=cr_processes();
    $out['reader_file']=cr_file(CR_SITE.'/hotel-monitor-prices-v1.php',100000,CR_READER_SHA);
    $out['original_hook_file']=cr_file(CR_SITE.'/monitor-price-hook.php',2000000,CR_HOOK_SHA,575978);
    $baseline=null;$baselineLine=null;$baselineLineHash=null;$cycles=[];$pendingChanges=[];$lastPendingHash=null;
    $priorCtx=hash_init('sha256');$priorBytes=0;$priorVerified=false;
    $out['global_journal']=[];
    $state=cr_stream(CR_ROOT.'/monitor-price-after-collection-v1.jsonl',400000000,static function(array $r,int $line,string $hash,string $raw)use(&$baseline,&$baselineLine,&$baselineLineHash,&$cycles,&$pendingChanges,&$lastPendingHash,$priorCtx,&$priorBytes,&$priorVerified):void{
        cr_need(($r['schema']??null)===1&&array_key_exists('pending',$r)&&is_array($r['completed']??null),'global_history_schema_unknown');
        $cycle=cr_cycle($r['nightly_cycle']??null);
        if($cycle!==null){$key='run:'.$cycle['collector_run_id'];
            if(!isset($cycles[$key]))$cycles[$key]=['first_line'=>$line,'first'=>$cycle,'states'=>[]];
            $cycles[$key]['last_line']=$line;$cycles[$key]['last']=$cycle;$cycles[$key]['states'][$cycle['state']]=true;cr_need(count($cycles)<=1000,'cycle_history_limit_unknown');}
        if($line<=CR_PRIOR_GLOBAL_LINES){hash_update($priorCtx,$raw);$priorBytes+=strlen($raw);}
        if($line===CR_PRIOR_GLOBAL_LINES){
            cr_need($priorBytes===CR_PRIOR_GLOBAL_BYTES&&hash_final(hash_copy($priorCtx))===CR_PRIOR_GLOBAL_SHA,'prior_global_prefix_changed');
            cr_need($r['pending']===null&&($r['completed'][462]['operation']??null)===CR_BASE_OP
                &&($r['completed'][462]['feed_operation']??null)===CR_BASE_FEED&&cr_hash($r['completed'])===CR_BASE_COMPLETED_SHA,'prior_completed_baseline_changed');
            $baseline=$r['completed'];$baselineLine=$line;$baselineLineHash=$hash;$priorVerified=true;}
        if($baseline!==null){$pendingHash=cr_hash($r['pending']);if($pendingHash!==$lastPendingHash){
            $pendingChanges[]=['line'=>$line,'pending'=>cr_pending($r['pending']),'tuple_sha256'=>$pendingHash];$lastPendingHash=$pendingHash;
            cr_need(count($pendingChanges)<=2000,'pending_history_limit_unknown');}}
    },$out['global_journal']);
    cr_need($priorVerified,'prior_global_prefix_not_reached');
    $out['prior_global_prefix']=['sha256'=>CR_PRIOR_GLOBAL_SHA,'bytes'=>CR_PRIOR_GLOBAL_BYTES,'lines'=>CR_PRIOR_GLOBAL_LINES,'verified'=>true];
    $out['pending']=cr_pending($state['pending']);$out['current_cycle']=cr_cycle($state['nightly_cycle']??null);
    foreach($cycles as &$c)$c['states']=array_keys($c['states']);unset($c);
    $out['attempted_cycles']=array_values($cycles);$out['original_cycle_observed']=isset($cycles['run:'.CR_RUN]);
    $out['pending_transitions_from_confirmed_checkpoint']=$pendingChanges;
    $completed=cr_completed($state['completed']);$out['completed_count']=count($completed);$out['completed_tuple_sha256']=cr_hash($state['completed']);
    $holds=$state['holds']??[];cr_need(is_array($holds)&&count($holds)<=1000,'holds_shape_unknown');
    $out['holds_count']=count($holds);$out['held_hotel_ids']=[];foreach(array_keys($holds) as $tv){cr_need(cr_id($tv)!==null,'held_hotel_id_unknown');$out['held_hotel_ids'][]=(int)$tv;}
    $out['confirmed_pass_baseline']=null;$out['changed_completed_entries']=null;
    if($baseline!==null){$before=cr_completed($baseline);$out['confirmed_pass_baseline']=['line'=>$baselineLine,'line_sha256'=>$baselineLineHash,'completed_count'=>count($before),
            'completed_tuple_sha256'=>cr_hash($baseline),'anchor'=>$before['462'],'confirmed_pass_completed_at'=>'2026-11-14T17:41:07+00:00'];
        $changes=[];foreach(array_unique(array_merge(array_keys($before),array_keys($completed))) as $tv){$b=$before[$tv]??null;$a=$completed[$tv]??null;
            if($b!==$a)$changes[]=['tourvisor_hotel_id'=>(int)$tv,'before'=>$b,'after'=>$a];}
        $out['changed_completed_entries']=$changes;}
    cr_need($out['pending']===null,'held_read_pending_operation_stop');
    cr_need(is_array($holds[CR_HELD_TV]??null),'target_hold_missing_or_changed');
    $out['target_completed']=$completed[(string)CR_HELD_TV]??null;
    $out['held_child_journal']=[];$out['held_child']=cr_held_child($holds[CR_HELD_TV],$out['held_child_journal']);
    cr_need(($out['target_completed']['operation']??null)!==$out['held_child']['operation'],'held_operation_marked_completed');
    $out['read_complete']=true;
}catch(Throwable $e){$out['error']=$e instanceof RuntimeException&&preg_match('/\A[a-z_]+\z/D',$e->getMessage())===1?$e->getMessage():'read_failed_unknown';}
finally{
    try{$out['process_after']=cr_processes();cr_check_locks();$out['held_files_unchanged']=true;}
    catch(Throwable $e){$out['final_stability_error']='final_snapshot_or_lock_unknown';}
    foreach($crLocks as $r){flock($r['stream'],LOCK_UN);fclose($r['stream']);}
}
$out['ok']=($out['read_complete']??false)===true&&!isset($out['error'])&&!isset($out['final_stability_error']);
$out['completed_at']=gmdate('c');$out['duration_seconds']=round((hrtime(true)-$crStart)/1000000000,3);
echo 'MONITOR_HOLD_READ_RESULT='.cr_json($out)."\n";
exit($out['ok']?0:2);
