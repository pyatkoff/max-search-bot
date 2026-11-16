<?php
declare(strict_types=1);
// Install only the already accepted module bytes. Never execute the module or collect/edit prices.
const BUNDLE_B64='__BUNDLE__';
const OLD_SHA='ff8adc4a22fca3d1305648688a72fac23dfaa203a917fe4eb29d49f09a9827e1';
const NEW_SHA='dd85c064745f77f2b72b637fa4b572562ffcc2a318c2f35578c016e6f5e88e72';
const APP_SHA='a4bab4c0a2ed48773fe159c40ada982b88c7af40';
const JOURNAL_SHA='918ad5f627d274c8f6793a59eeae54984b2293cc78deddf60bf0fcc882e34b5a';
function must(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function admission(array $s):void{
    $expected=['app_head'=>APP_SHA,'tracked_clean'=>true,'old_sha'=>OLD_SHA,'new_sha'=>NEW_SHA,'new_bytes'=>608018,'old_count'=>608,'new_count'=>660,'pin_count'=>23,'pins_match'=>true,'pending_null'=>true,'journal_bytes'=>339101253,'last_line_sha'=>JOURNAL_SHA,'active_collectors'=>0];
    foreach($expected as $k=>$v)must(array_key_exists($k,$s)&&$s[$k]===$v,'admission_'.$k);
}
function gitread(string $root,array $args):string{
    $p=proc_open(array_merge(['git','-C',$root],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    must(is_resource($p),'git_start_failed');fclose($pipes[0]);$out=stream_get_contents($pipes[1],1048577);fclose($pipes[1]);stream_get_contents($pipes[2],4096);fclose($pipes[2]);
    must(proc_close($p)===0&&is_string($out)&&strlen($out)<=1048576,'git_read_failed');return trim($out);
}
function readfile_exact(string $path,int $limit):array{
    clearstatcache(true,$path);must(!is_link($path)&&is_file($path)&&realpath($path)===$path,'path_invalid');
    $f=fopen($path,'rb');must(is_resource($f),'file_open_failed');
    try{
        $before=fstat($f);must(is_array($before)&&$before['size']>0&&$before['size']<=$limit,'file_size_invalid');
        $raw=stream_get_contents($f,$limit+1);must(is_string($raw)&&strlen($raw)===$before['size'],'file_read_changed');clearstatcache(true,$path);$after=lstat($path);
        foreach(['ino','dev','size','mtime','ctime'] as $k)must(($after[$k]??null)===$before[$k],'file_changed');
        return [$raw,$before];
    }finally{fclose($f);}
}
function write_new(string $path,string $raw,int $mode=0600):void{
    $f=fopen($path,'xb');must(is_resource($f),'exclusive_create_failed');
    try{must(chmod($path,$mode),'chmod_failed');$n=0;while($n<strlen($raw)){$written=fwrite($f,substr($raw,$n));must(is_int($written)&&$written>0,'file_write_failed');$n+=$written;}must(fflush($f),'flush_failed');if(function_exists('fsync'))must(fsync($f),'sync_failed');}
    finally{fclose($f);}
}
function journal_line($f,string $path):array{
    $stat=fstat($f);must(is_array($stat)&&$stat['size']>0,'journal_empty');$offset=max(0,$stat['size']-4194304);
    must(fseek($f,$offset)===0,'journal_seek_failed');$raw=stream_get_contents($f,4194305);must(is_string($raw)&&strlen($raw)===$stat['size']-$offset&&str_ends_with($raw,"\n"),'journal_incomplete');
    $body=substr($raw,0,-1);$at=strrpos($body,"\n");must($offset===0||$at!==false,'journal_line_too_large');$line=$at===false?$body:substr($body,$at+1);
    $state=json_decode($line,true,64,JSON_THROW_ON_ERROR);must(is_array($state)&&array_key_exists('pending',$state),'journal_invalid');
    clearstatcache(true,$path);$named=lstat($path);foreach(['ino','dev','size','mtime','ctime'] as $k)must(($named[$k]??null)===$stat[$k],'journal_changed');
    return ['bytes'=>$stat['size'],'sha256'=>hash('sha256',$line."\n"),'pending_null'=>$state['pending']===null];
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??null)==='--self-test'){
    $valid=['app_head'=>APP_SHA,'tracked_clean'=>true,'old_sha'=>OLD_SHA,'new_sha'=>NEW_SHA,'new_bytes'=>608018,'old_count'=>608,'new_count'=>660,'pin_count'=>23,'pins_match'=>true,'pending_null'=>true,'journal_bytes'=>339101253,'last_line_sha'=>JOURNAL_SHA,'active_collectors'=>0];
    admission($valid);$checks=1;
    foreach($valid as $key=>$value){foreach(['remove','null','wrong'] as $mode){$s=$valid;if($mode==='remove')unset($s[$key]);elseif($mode==='null')$s[$key]=null;else $s[$key]=is_bool($value)?!$value:(is_int($value)?$value+1:$value.'changed');$thrown=false;try{admission($s);}catch(RuntimeException $e){$thrown=$e->getMessage()==='admission_'.$key;}must($thrown,'negative_fixture_failed');$checks++;}}
    echo json_encode(['ok'=>true,'checks'=>$checks,'production_calls'=>0,'writes'=>0]),"\n";exit;
}
ini_set('display_errors','0');umask(0077);
$out=['ok'=>false,'operation'=>'accepted-monitor660-module-install-20261116-v1','mode'=>'install_accepted_code_only','at'=>gmdate('c'),'code_replacements'=>0,'collector_runs'=>0,'post_writes'=>0,'feed_writes'=>0,'supplier_calls'=>0,'global_journal_writes'=>0];
$lock=null;$temp=null;$renamed=false;
try{
    $root='/var/www/anytoour/data/channel-post-operations';$app='/var/www/anytoour/data/www/app.anytoour.ru';$target='/var/www/anytoour/data/www/anytoour.ru/data/monitor-price-hook.php';
    foreach([$root,$app,dirname($target)] as $dir)must(!is_link($dir)&&realpath($dir)===$dir,'root_changed');
    $j=$root.'/monitor-price-after-collection-v1.jsonl';must(!is_link($j)&&is_file($j)&&realpath($j)===$j,'journal_path_invalid');
    $lock=fopen($j,'rb');must(is_resource($lock)&&flock($lock,LOCK_EX|LOCK_NB),'journal_busy_stop');
    $journal=journal_line($lock,$j);[$old,$oldstat]=readfile_exact($target,2000000);
    [$cohort]=readfile_exact(dirname($target).'/published-hotels-monitor-v1.php',1000000);must(hash('sha256',$cohort)==='cf33320708bdc911e944a560324566c562f4fefb97f3a601497f8ca0a86c8ae8','installed_cohort_changed');
    $bundle=base64_decode(BUNDLE_B64,true);must(is_string($bundle)&&hash('sha256',$bundle)===NEW_SHA&&strlen($bundle)===608018,'accepted_bundle_invalid');
    must(preg_match('/const HOOK_ROSTER_COUNT=([0-9]+);/',$bundle,$newCount)===1&&preg_match('/const HOOK_ROSTER_COUNT=([0-9]+);/',$old,$oldCount)===1,'roster_count_missing');
    must(preg_match('/const HOOK_RUNTIME_PINS_B64="([A-Za-z0-9+\/=]+)";/',$bundle,$m)===1&&strlen($m[1])<16384,'pins_missing');
    $pins=json_decode(base64_decode($m[1],true),true,16,JSON_THROW_ON_ERROR);must(is_array($pins)&&count($pins)===23,'pins_scope_invalid');
    foreach($pins as $path=>$blob){must(is_string($path)&&preg_match('~\A(?:services|integrations|migrations)/[A-Za-z0-9_-]+\.(?:php|sql)\z~D',$path)===1,'pin_path_invalid');[$content]=readfile_exact($app.'/'.$path,2000000);must(hash('sha1','blob '.strlen($content)."\0".$content)===$blob,'runtime_pin_mismatch');}
    $p=proc_open(['ps','-eo','args='],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);must(is_resource($p),'process_read_failed');fclose($pipes[0]);$processes=stream_get_contents($pipes[1],2097153);fclose($pipes[1]);stream_get_contents($pipes[2],4096);fclose($pipes[2]);must(proc_close($p)===0&&is_string($processes)&&strlen($processes)<=2097152,'process_read_failed');
    $active=0;foreach(explode("\n",$processes) as $cmd)if(preg_match('~\A\s*(?:/[^\s]+/)?php(?:[0-9.]*)?\s+.*(?:top500-daily-collector-v1|scheduled-collector-v1|matrix-collector-v1|monitor-price-hook)\.php(?:\s|$)~',$cmd))$active++;
    $state=['app_head'=>gitread($app,['rev-parse','HEAD']),'tracked_clean'=>gitread($app,['status','--porcelain','--untracked-files=no'])==='','old_sha'=>hash('sha256',$old),'new_sha'=>hash('sha256',$bundle),'new_bytes'=>strlen($bundle),'old_count'=>(int)$oldCount[1],'new_count'=>(int)$newCount[1],'pin_count'=>count($pins),'pins_match'=>true,'pending_null'=>$journal['pending_null'],'journal_bytes'=>$journal['bytes'],'last_line_sha'=>$journal['sha256'],'active_collectors'=>$active];admission($state);
    $op=$root.'/'.$out['operation'];must(!file_exists($op)&&!is_link($op),'operation_exists_no_replay');
    $temp=dirname($target).'/.'.$out['operation'].'.tmp';must(!file_exists($temp)&&!is_link($temp),'temporary_exists_stop');
    must(mkdir($op,0700),'operation_create_failed');write_new($op.'/original-hook.php',$old);write_new($op.'/intent.json',json_encode(['source_pr'=>1578,'consumer_pr'=>5062,'source'=>'b3d5dca2a7c8cecee88db1404cb2a8c28d26841e','consumer'=>'e1b510bffc3c7f4214023a43528efbfe4915aaa3','preflight'=>$state],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    write_new($temp,$bundle);$tempstat=lstat($temp);must(is_array($tempstat),'temp_stat_failed');
    if($tempstat['uid']!==$oldstat['uid'])must(chown($temp,$oldstat['uid']),'owner_preservation_failed');
    if($tempstat['gid']!==$oldstat['gid'])must(chgrp($temp,$oldstat['gid']),'group_preservation_failed');
    must(chmod($temp,$oldstat['mode']&0777),'mode_preservation_failed');
    [$check,$currentstat]=readfile_exact($target,2000000);must(hash('sha256',$check)===OLD_SHA&&$currentstat['ino']===$oldstat['ino'],'target_changed_before_commit');
    must(gitread($app,['rev-parse','HEAD'])===APP_SHA,'app_changed_before_commit');must(journal_line($lock,$j)===$journal,'journal_changed_before_commit');
    must(rename($temp,$target),'atomic_replace_failed');$renamed=true;$out['code_replacements']=1;
    [$installed,$installedStat]=readfile_exact($target,2000000);must(hash('sha256',$installed)===NEW_SHA&&strlen($installed)===608018,'installed_readback_failed');
    must($installedStat['uid']===$oldstat['uid']&&$installedStat['gid']===$oldstat['gid']&&($installedStat['mode']&0777)===($oldstat['mode']&0777),'permissions_changed');
    must(journal_line($lock,$j)===$journal,'journal_changed_after_commit');must(gitread($app,['rev-parse','HEAD'])===APP_SHA,'app_changed_after_commit');
    $out+=['old_count'=>608,'new_count'=>660,'old_sha256'=>OLD_SHA,'installed_sha256'=>NEW_SHA,'bytes'=>608018,'app_head'=>APP_SHA,'runtime_pins_verified'=>23,'journal_unchanged'=>true,'backup_retained'=>true,'module_executions'=>0];$out['ok']=true;
    write_new($op.'/result.json',json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())===1?$e->getMessage():'installation_failed';$out['mutation_outcome']=$renamed?'replacement_occurred_requires_receipt_review':'no_target_replacement';}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($out['ok']?0:2);
