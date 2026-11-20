<?php
declare(strict_types=1);
// Standalone only after offline assembly. No callback loop or provider edit calls.
const CLOSE_HOOK_PATH='/var/www/anytoour/data/www/anytoour.ru/data/monitor-price-hook.php';
const CLOSE_OLD_HOOK_SHA='41e9f0151f97691f91258ebb37714090ba0798c76db18c3870ff93bcc23d0c9a';

/** Verify an exact prefix without retaining it in memory; return its real final state. */
function close27316_prefix($stream,array $pin):array {
    reconcile_need(rewind($stream),'close_prefix_rewind_failed');
    $h=hash_init('sha256');$bytes=0;$lines=0;$last='';
    while($bytes<$pin['bytes']){
        $line=fgets($stream,min(1000001,$pin['bytes']-$bytes+1));
        reconcile_need(is_string($line)&&str_ends_with($line,"\n"),'close_prefix_incomplete');
        $bytes+=strlen($line);$lines++;hash_update($h,$line);$last=$line;
    }
    reconcile_need($bytes===$pin['bytes']&&$lines===$pin['lines']&&hash_final($h)===$pin['sha256'],'close_prefix_changed');
    $state=json_decode($last,true,64,JSON_THROW_ON_ERROR);
    reconcile_need(is_array($state),'close_prefix_state_invalid');return $state;
}
function close27316_tail($stream,int $cap):string {
    $raw=stream_get_contents($stream,$cap+1);
    reconcile_need(is_string($raw)&&$raw!==''&&strlen($raw)<=$cap&&str_ends_with($raw,"\n")&&feof($stream),'close_tail_invalid');return $raw;
}
/** Exact feed_started/completed, optionally the single proved receipt-lock failure. */
function close27316_feed_states(string $raw,array $original):array {
    $lines=explode("\n",substr($raw,0,-1));reconcile_need(in_array(count($lines),[2,3],true),'close_unknown_global_append');
    $start=json_decode($lines[0],true,64,JSON_THROW_ON_ERROR);$end=json_decode(end($lines),true,64,JSON_THROW_ON_ERROR);
    if(count($lines)===3){
        $failure=json_decode($lines[1],true,64,JSON_THROW_ON_ERROR);$expected=$start;
        $expected['pending']['child_failure']=['exit_code'=>2,'error'=>'monitor_receipt_busy'];
        reconcile_need($failure===$expected,'close_unrecognized_feed_failure');
    }
    $id=monitor_hook_price27316_identity();$p=$start['pending']??null;
    reconcile_need(is_array($p)&&($p['phase']??null)==='feed_started'&&($p['operation']??null)===$id['operation']
        &&($p['tourvisor_hotel_id']??null)===27316&&is_array($p['files']??null)
        &&is_array($end)&&array_key_exists('pending',$end)&&$end['pending']===null,'close_feed_not_terminal');
    $expectedStart=$original;$expectedStart['pending']['phase']='feed_started';
    $expectedStart['pending']['feed_operation']=$p['feed_operation']??null;$expectedStart['pending']['files']=$p['files'];
    reconcile_need($start===$expectedStart,'close_feed_start_unrelated_changed');
    $evidence=['operation'=>$p['feed_operation']??null,'files'=>$p['files'],
        'local_and_public_hashes_verified'=>true,'verification_owner'=>'frozen_hook_finish_feed',
        'additional_public_gets'=>3,'appended_global_states_sha256'=>hash('sha256',$raw)];
    return [$end,$evidence];
}
function close27316_verified_feeds(array $feed):void {
    $names=array_column($feed['files'],'name');sort($names);
    reconcile_need($names===['hotel-posts-all.xml','hotel-posts-max.xml','hotel-posts-telegram.xml'],'close_feed_allowlist_changed');
    foreach($feed['files'] as $file){
        reconcile_need(monitor_hook_price27316_sha($file['sha256']??null),'close_feed_digest_missing');
        $local=hook_read(RECONCILE_RUNTIME.'/feed/'.$file['name'],4000000);
        $public=hook_get('https://app.anytoour.ru/feed/'.$file['name'],4000000);
        reconcile_need(hash('sha256',$local)===$file['sha256']&&hash('sha256',$public)===$file['sha256']
            &&strlen($local)===($file['bytes']??null),'close_feed_readback_changed');
    }
}
/** Exclusive files, durable data, ownership and mode preserved; never overwrite a backup. */
function close27316_write_file(string $path,string $raw,array $stat):void {
    $f=fopen($path,'xb');reconcile_need(is_resource($f),'close_exclusive_file_failed');
    try{
        reconcile_need(fwrite($f,$raw)===strlen($raw)&&fflush($f)&&fsync($f),'close_file_write_failed');
        reconcile_need(chown($path,$stat['uid'])&&chgrp($path,$stat['gid'])&&chmod($path,$stat['mode']&07777),'close_file_metadata_failed');
    }finally{fclose($f);}
    clearstatcache(true,$path);$s=lstat($path);
    reconcile_need(is_array($s)&&$s['uid']===$stat['uid']&&$s['gid']===$stat['gid']
        &&($s['mode']&07777)===($stat['mode']&07777)&&hash_file('sha256',$path)===hash('sha256',$raw),'close_file_readback_failed');
}
function close27316_selftest():void {
    $raw="{\"a\":1}\n{\"b\":2}\n";$f=fopen('php://temp','w+b');fwrite($f,$raw."tail\n");
    reconcile_need(close27316_prefix($f,monitor_hook_cycle_journal_pin($raw))===['b'=>2],'selftest_prefix');
    reconcile_need(close27316_tail($f,100)==="tail\n",'selftest_tail');
    $failed=false;try{close27316_prefix($f,['bytes'=>strlen($raw),'lines'=>2,'sha256'=>str_repeat('0',64)]);}catch(RuntimeException $e){$failed=true;}
    reconcile_need($failed,'selftest_bad_pin');fclose($f);
    $id=monitor_hook_price27316_identity();$original=['pending'=>['operation'=>$id['operation'],'tourvisor_hotel_id'=>27316],'completed'=>[]];
    $start=$original;$start['pending']['phase']='feed_started';$start['pending']['feed_operation']='fixture';$start['pending']['files']=[];
    $end=$start;$end['pending']=null;$tail=reconcile_json($start)."\n".reconcile_json($end)."\n";
    reconcile_need(close27316_feed_states($tail,$original)[0]===$end,'selftest_feed_states');
    $failure=$start;$failure['pending']['child_failure']=['exit_code'=>2,'error'=>'monitor_receipt_busy'];
    $three=reconcile_json($start)."\n".reconcile_json($failure)."\n".reconcile_json($end)."\n";
    reconcile_need(close27316_feed_states($three,$original)[0]===$end,'selftest_known_failure');
    $failure['pending']['child_failure']['error']='unknown';
    $unknown=reconcile_json($start)."\n".reconcile_json($failure)."\n".reconcile_json($end)."\n";
    foreach([$tail."{}\n",reconcile_json($end)."\n",$unknown,$three."{}\n"] as $bad){$failed=false;try{close27316_feed_states($bad,$original);}catch(RuntimeException $e){$failed=true;}reconcile_need($failed,'selftest_unknown_append');}
    $new=base64_decode(CLOSE_UPGRADED_HOOK_B64,true);
    reconcile_need(is_string($new)&&hash('sha256',$new)===CLOSE_UPGRADED_HOOK_SHA256,'selftest_embedded_hook');
    echo "PRICE27316_INSTALL_CLOSE_SELFTEST_OK checks=10 production_writes=0\n";
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!defined('CLOSE_UPGRADED_HOOK_B64')||!function_exists('reconcile_runtime')){fwrite(STDERR,"close_frozen_bundle_required\n");exit(2);}
if($argv===[$argv[0],'--self-test']){try{close27316_selftest();exit(0);}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(2);}}
$apply=getenv('ANYTOUR_PRICE27316_CLOSE_APPLY')==='1';$locks=[];
$out=['ok'=>false,'mode'=>$apply?'install_and_close_exact_price27316':'inspect_postfeed_price27316',
    'driver_sha256'=>CLOSE_DRIVER_SHA256,'hook_installed'=>false,'journal_appended'=>false,'provider_writes'=>0,'feed_writes'=>0];
try{
    reconcile_need(count($argv)===1&&in_array(getenv('ANYTOUR_PRICE27316_CLOSE_APPLY'),[false,'','1'],true),'close_arguments_invalid');
    $out['runtime_sha']=reconcile_runtime();$id=monitor_hook_price27316_identity();
    reconcile_need(!is_link(RECONCILE_ROOT)&&realpath(RECONCILE_ROOT)===RECONCILE_ROOT,'close_root_changed');
    [$globalLock]=reconcile_open(RECONCILE_ROOT.'/monitor-price-after-collection-v1.jsonl',450000000,$apply,$locks,true);
    $original=close27316_prefix($globalLock,$id['global']);$tail=close27316_tail($globalLock,2000000);
    [$current,$feed]=close27316_feed_states($tail,$original);
    [$childLock]=reconcile_open(RECONCILE_ROOT.'/'.$id['operation'].'/receipt.jsonl',10000000,$apply,$locks,true);
    $originalChild=close27316_prefix($childLock,$id['child']);$childTail=close27316_tail($childLock,1000000);
    $childLines=explode("\n",substr($childTail,0,-1));$child=json_decode(end($childLines),true,64,JSON_THROW_ON_ERROR);
    reconcile_need(is_array($child)&&($child['monitor_snapshot']??null)===($originalChild['monitor_snapshot']??null),'close_source_changed');
    $binding=$child['feed_binding']??[];$binding['operation']=$id['operation'];$binding['manifest_hash']=$id['manifest_hash'];
    monitor_price_confirmed_journal($child,$binding,time());
    // First validate the whole bounded proof; only then make the three allowlisted GETs.
    $terminal=monitor_hook_close_price27316_cycle($current,$original,$id['global'],$id['child'],$child,$feed,time());
    close27316_verified_feeds($feed);
    $old=hook_read(CLOSE_HOOK_PATH,1000000);$stat=lstat(CLOSE_HOOK_PATH);
    reconcile_need(hash('sha256',$old)===CLOSE_OLD_HOOK_SHA&&is_array($stat)
        &&!is_link(dirname(CLOSE_HOOK_PATH))&&realpath(dirname(CLOSE_HOOK_PATH))===dirname(CLOSE_HOOK_PATH),'close_installed_hook_changed');
    $new=base64_decode(CLOSE_UPGRADED_HOOK_B64,true);
    reconcile_need(is_string($new)&&hash('sha256',$new)===CLOSE_UPGRADED_HOOK_SHA256,'close_embedded_hook_changed');
    $out['feed_evidence']=$feed;$out['upgraded_hook_sha256']=CLOSE_UPGRADED_HOOK_SHA256;
    $out['terminal_cycle']=$terminal['nightly_cycle'];
    if($apply){
        reconcile_need(reconcile_runtime()===$out['runtime_sha'],'close_runtime_raced');
        $backup=CLOSE_HOOK_PATH.'.before-price27316-44012967294';$temp=CLOSE_HOOK_PATH.'.price27316-new';
        reconcile_need(!file_exists($backup)&&!is_link($backup)&&!file_exists($temp)&&!is_link($temp),'close_prior_install_attempt_stop');
        close27316_write_file($backup,$old,$stat);$out['backup_created']=true;
        close27316_write_file($temp,$new,$stat);
        clearstatcache(true,CLOSE_HOOK_PATH);$check=lstat(CLOSE_HOOK_PATH);
        reconcile_need(is_array($check)&&$check['ino']===$stat['ino']&&$check['dev']===$stat['dev']
            &&hash_file('sha256',CLOSE_HOOK_PATH)===CLOSE_OLD_HOOK_SHA,'close_hook_identity_raced');
        reconcile_need(rename($temp,CLOSE_HOOK_PATH),'close_atomic_install_failed');$out['hook_installed']=true;
        reconcile_need(hash_file('sha256',CLOSE_HOOK_PATH)===CLOSE_UPGRADED_HOOK_SHA256,'close_installed_readback_failed');
        // New reader is present before terminal state is ever durable.
        hook_append($globalLock,$terminal);$out['journal_appended']=true;
        close27316_prefix($globalLock,$id['global']);$actual=close27316_tail($globalLock,3000000);
        reconcile_need($actual===$tail.reconcile_json($terminal)."\n",'close_terminal_readback_changed');
        close27316_prefix($childLock,$id['child']);
        reconcile_need(close27316_tail($childLock,1000000)===$childTail,'close_child_raced');
    }
    $out['ok']=true;$out['all_660_completed']=false;
}catch(Throwable $e){$out['error']=$e->getMessage();$out['unknown_requires_inspection']=true;}
foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
exit($out['ok']?0:2);
