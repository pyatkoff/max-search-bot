<?php
declare(strict_types=1);
// Exact, one-time journal metadata reconciliation. No sender, collector or feed writer.
function sfr_need(bool $ok,string $why):void { if(!$ok)throw new RuntimeException($why); }
function sfr_json(array $v):string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
function sfr_digest(array $v):string { return hash('sha256',sfr_json($v)); }
function sfr_pins():array {
    return [
        'run'=>'43698862104','hotel'=>1236,
        'operation'=>'commercial-monitor-price-h1236-d0cad7bf37d9bc1440efe6190cb63f65cb3bb6bbce47b0894367a454364e4a15',
        'feed_operation'=>'commercial-channel-hotel-feeds-20261115-update41478664365268597',
        'global_bytes'=>277450129,
        'last_sha256'=>'4f77b40babfab3e9409fedc1eaedb8860322e886b34a63b7748a22d67e611f15',
        'child_sha256'=>'68fa3911da24e1f0169c471b8c8ad6cb100ee00ebac01622e334e2fda9d6b2a5',
        'manifest'=>'442fd5ba3b72162f4084cd52910ccb550df00d5a31e7c540852f23fb46916166',
        'cycle_sha256'=>'10606927c7e38347fdc2765bfb7a4ee00b2f600db74ea2225aa73390c1234812',
        'completed_sha256'=>'bf9826fb148aa443d13d7401eedff5ee5883bdf2da87845660b763f3ac9af425',
        'completed_count'=>537,
        'feeds'=>[
            'hotel-posts-telegram.xml'=>'e7fdcc2a2cdd55073f7c394110eb4f08ccb202c4266b029d78f392307dee35d0',
            'hotel-posts-max.xml'=>'cf3788b350bd350fa525845207b42b0e4fad613d5f345f6df60785b18e9106f9',
            'hotel-posts-all.xml'=>'b0289df250c2946c1e4dafe222eb56ea03e5480ae4c073e69a597fbb91596303'],
        'posts'=>['max'=>[854,6093,'mid.ffffb6caf0606fed01a1b74524d273de','72b7f374a0a88bcfad1bc3bfb7ce98248d4f485a5f25db5734f4fdbf6e5d734a'],
                  'telegram'=>[844,6094,'376','1092caaee20ad68934e173969a829ef329a66fc4fb4cdb32c476dc7ed40b3701']],
        'evidence_run'=>'43801917345','evidence_artifact'=>14197116176,
        'evidence_archive_sha256'=>'5576225ea6772946a6e28d23d78c1c0961a1e0aeda29ba765ef191f0a34adf9c'];
}
/** Pure transition. Production invokes it only with immutable sfr_pins(). */
function sfr_propose(array $state,array $child,array $pins,array $hashes,string $prefixSha,string $execution,int $now):array {
    sfr_need(preg_match('/\A[1-9][0-9]{0,19}\z/D',$execution)===1&&$execution!==$pins['run'],'recovery_execution_invalid');
    sfr_need(preg_match('/\A[a-f0-9]{64}\z/D',$prefixSha)===1&&$now>=strtotime('2026-11-16T04:05:41Z'),'recovery_evidence_invalid');
    $pending=$state['pending']??null;$cycle=$state['nightly_cycle']??null;
    sfr_need(($state['schema']??null)===1&&is_array($pending)&&is_array($cycle),'recovery_scope_changed');
    sfr_need(($pending['operation']??null)===$pins['operation']&&($pending['feed_operation']??null)===$pins['feed_operation']
        &&($pending['tourvisor_hotel_id']??null)===$pins['hotel']&&($pending['phase']??null)==='feed_started'
        &&($pending['child_failure']['error']??null)==='installed_source_changed'&&($pending['child_failure']['exit_code']??null)===2
        &&count($pending['child_failure'])===2,'recovery_failure_not_proved');
    sfr_need(($cycle['collector_run_id']??null)===$pins['run']&&($cycle['state']??null)==='started'
        &&sfr_digest($cycle)===$pins['cycle_sha256'],'recovery_cycle_changed');
    sfr_need(is_array($state['completed']??null)&&count($state['completed'])===$pins['completed_count']
        &&sfr_digest($state['completed'])===$pins['completed_sha256']&&empty($state['holds']),'recovery_completed_or_holds_changed');
    sfr_need(($child['operation']??null)===$pins['operation']&&($child['manifest_hash']??null)===$pins['manifest']
        &&($child['complete']??null)===true&&($child['configs']??null)===[]
        &&($child['feed_binding']['tourvisor_hotel_id']??null)===$pins['hotel']
        &&count($child['posts']??[])===2&&!isset($child['terminal']),'recovery_posts_not_terminal');
    $seen=[];
    foreach($child['posts'] as $post){
        $r=$post['receipt']??[];$platform=$r['platform']??'';$pin=$pins['posts'][$platform]??null;
        sfr_need(($post['state']??null)==='confirmed'&&is_array($pin)&&!isset($seen[$platform])
            &&($r['state']??null)==='sent'&&($r['body_photo_button_verified']??null)===true
            &&[$r['publication_id']??null,$r['version_id']??null,$r['message_id']??null,$r['text_sha256']??null]===$pin,
            'recovery_post_receipt_changed');$seen[$platform]=true;
    }
    sfr_need($hashes===$pins['feeds']&&count($pending['files']??[])===3,'recovery_feed_preimage_changed');
    $names=[];
    foreach($pending['files'] as $file){
        $name=$file['name']??'';
        sfr_need(isset($hashes[$name])&&!isset($names[$name])&&($file['expected_previous_sha256']??null)===$hashes[$name]
            &&is_string($file['sha256']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$file['sha256'])===1
            &&$file['sha256']!==$hashes[$name],'recovery_feed_outcome_ambiguous');$names[$name]=true;
    }
    $key='run:'.$pins['run'];
    sfr_need(!array_key_exists('reconciled_interruptions',$state)||is_array($state['reconciled_interruptions']),'recovery_audit_invalid');
    sfr_need(!isset($state['reconciled_interruptions'][$key]),'recovery_already_recorded');
    // Preserve the actual failed cycle and pending tuple. Clear only active pointers.
    // The unchanged original journal history continues to reject its collector run ID.
    $state['reconciled_interruptions'][$key]=[
        'state'=>'interrupted_reconciled','reason'=>'posts_confirmed_feed_preflight_refused_observation_expired',
        'original_cycle'=>$cycle,'original_pending'=>$pending,'original_collector_remains_failed'=>true,
        'post_outcome'=>'two_original_posts_confirmed_no_replay','feed_outcome'=>'three_preimages_unchanged_not_published',
        'global_prefix_bytes'=>$pins['global_bytes'],'global_prefix_sha256'=>$prefixSha,
        'last_original_line_sha256'=>$pins['last_sha256'],'child_journal_sha256'=>$pins['child_sha256'],
        'feed_preimage_sha256'=>$hashes,'completed_tuple_sha256'=>$pins['completed_sha256'],
        'evidence_run'=>$pins['evidence_run'],'evidence_artifact'=>$pins['evidence_artifact'],
        'evidence_archive_sha256'=>$pins['evidence_archive_sha256'],
        'recovery_run_id'=>$execution,'reconciled_at'=>gmdate('c',$now),
        'post_writes'=>0,'feed_writes'=>0,'supplier_calls'=>0];
    $state['pending']=null;$state['nightly_cycle']=null;
    return $state;
}
if(!defined('ANYTOUR_STALE_FEED_RECOVERY_EXECUTE'))return;
sfr_need(PHP_SAPI==='cli'&&ANYTOUR_STALE_FEED_RECOVERY_EXECUTE===true,'cli_recovery_only');
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['ok'=>false,'mode'=>'one_exact_stale_feed_metadata_reconciliation','post_writes'=>0,'feed_writes'=>0,
    'supplier_calls'=>0,'db_writes'=>0,'new_posts'=>0,'journal_append_attempted'=>false,'journal_append_confirmed'=>false];
$locks=[];
function sfr_open(string $path,string $mode,int $lockType,array &$locks) {
    sfr_need(!is_link($path)&&is_file($path)&&realpath($path)===$path,'recovery_path_changed');
    $f=fopen($path,$mode);sfr_need(is_resource($f),'recovery_open_failed');$locks[]=$f;
    sfr_need(flock($f,$lockType|LOCK_NB),'recovery_busy_stop');
    $a=fstat($f);$b=lstat($path);sfr_need(is_array($a)&&is_array($b)&&$a['ino']===$b['ino']&&$a['dev']===$b['dev'],'recovery_file_replaced');return $f;
}
function sfr_public_hash(string $name):string {
    sfr_need(in_array($name,array_keys(sfr_pins()['feeds']),true),'recovery_feed_name_invalid');
    $raw='';$ch=curl_init('https://app.anytoour.ru/feed/'.$name);
    curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER=>['Cache-Control: no-cache'],CURLOPT_WRITEFUNCTION=>static function($c,string $b)use(&$raw):int{
            if(strlen($raw)+strlen($b)>4000000)return 0;$raw.=$b;return strlen($b);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    sfr_need($ok===true&&$status===200&&strlen($raw)>1000,'recovery_public_read_failed');return hash('sha256',$raw);
}
try {
    $pins=sfr_pins();$root='/var/www/anytoour/data/channel-post-operations';$feed='/var/www/anytoour/data/www/app.anytoour.ru/feed';
    sfr_need(!is_link($root)&&realpath($root)===$root&&!is_link($feed)&&realpath($feed)===$feed,'recovery_root_changed');
    sfr_need(defined('ANYTOUR_STALE_FEED_RECOVERY_RUN_ID'),'recovery_run_missing');
    $path=$root.'/monitor-price-after-collection-v1.jsonl';$global=sfr_open($path,'r+b',LOCK_EX,$locks);$stat=fstat($global);
    sfr_need($stat['size']===$pins['global_bytes'],'recovery_original_size_changed_stop');
    $hash=hash_init('sha256');$last='';$attempted=[];$records=0;
    // Stream all history, preserving original replay protection without a 277MB memory copy.
    while(!feof($global)){
        $line=fgets($global,4194305);if($line===false)break;
        sfr_need(str_ends_with($line,"\n"),'recovery_original_journal_incomplete');
        hash_update($hash,$line);$last=$line;$records++;
        $attempted+=monitor_hook_attempted_collector_runs([substr($line,0,-1)]);
    }
    sfr_need(ftell($global)===$pins['global_bytes']&&isset($attempted['run:'.$pins['run']])
        &&hash('sha256',$last)===$pins['last_sha256'],'recovery_original_history_changed');
    $prefixSha=hash_final($hash);$state=json_decode($last,true,64,JSON_THROW_ON_ERROR);
    $childPath=$root.'/'.$pins['operation'].'/receipt.jsonl';$childHandle=sfr_open($childPath,'rb',LOCK_SH,$locks);
    sfr_need(fstat($childHandle)['size']===137631,'recovery_child_size_changed');
    $childRaw=stream_get_contents($childHandle,10000001);
    sfr_need(is_string($childRaw)&&hash('sha256',$childRaw)===$pins['child_sha256'],'recovery_child_changed');
    $child=monitor_hook_parse_journal($childRaw,$pins['operation']);
    $binding=$child['feed_binding'];$binding['operation']=$pins['operation'];$binding['manifest_hash']=$pins['manifest'];
    $originalAt=monitor_price_time($child['monitor_snapshot']['generatedAt']??null);
    monitor_price_confirmed_journal($child,$binding,$originalAt);
    $expired=false;try{monitor_price_observation($child['monitor_snapshot'],$pins['hotel'],time(),false);}
    catch(RuntimeException $e){sfr_need($e->getMessage()==='monitor_price_expired','recovery_observation_invalid');$expired=true;}
    sfr_need($expired,'recovery_observation_not_expired');
    $hashes=[];$feedHandles=[];
    foreach($pins['feeds'] as $name=>$expected){
        $tmp=$feed.'/.'.$pins['feed_operation'].'-'.$name.'.tmp';
        sfr_need(!file_exists($tmp)&&!is_link($tmp),'recovery_feed_temp_outcome_unknown');
        $fh=sfr_open($feed.'/'.$name,'rb',LOCK_SH,$locks);$feedHandles[$name]=$fh;
        sfr_need(fstat($fh)['size']>1000&&fstat($fh)['size']<4000000,'recovery_feed_size_changed');
        $fhHash=hash_init('sha256');hash_update_stream($fhHash,$fh);$hashes[$name]=hash_final($fhHash);
        sfr_need($hashes[$name]===$expected&&sfr_public_hash($name)===$expected,'recovery_feed_preimage_changed');
    }
    $now=time();$next=sfr_propose($state,$child,$pins,$hashes,$prefixSha,ANYTOUR_STALE_FEED_RECOVERY_RUN_ID,$now);
    $line=sfr_json($next)."\n";
    sfr_need(monitor_hook_attempted_collector_runs([substr($line,0,-1)])===[],'recovery_active_pointer_not_clear');
    $oldGate=monitor_hook_nightly_gate($next,$now,gmdate('c',$now),$pins['run'],$attempted);
    sfr_need(!$oldGate['due']&&$oldGate['reason']==='collector_cycle_already_attempted','recovery_replay_guard_failed');
    $newGate=monitor_hook_nightly_gate($next,$now,gmdate('c',$now),ANYTOUR_STALE_FEED_RECOVERY_RUN_ID,$attempted);
    sfr_need($newGate['due']===true,'recovery_future_gate_blocked');
    // Last compare before the only append; no replacement/truncation or business write.
    clearstatcache(true,$path);$named=lstat($path);
    sfr_need($named['ino']===$stat['ino']&&$named['dev']===$stat['dev']&&$named['size']===$stat['size']
        &&$named['mtime']===$stat['mtime']&&$named['ctime']===$stat['ctime'],'recovery_journal_raced');
    foreach($pins['feeds'] as $name=>$expected)sfr_need(hash_file('sha256',$feed.'/'.$name)===$expected,'recovery_feed_raced');
    sfr_need(hash_file('sha256',$childPath)===$pins['child_sha256'],'recovery_child_raced');
    sfr_need(fseek($global,0,SEEK_END)===0&&ftell($global)===$pins['global_bytes'],'recovery_append_position_changed');
    $out['journal_append_attempted']=true;
    echo 'STALE_FEED_METADATA_INTENT='.sfr_json(['run_id'=>ANYTOUR_STALE_FEED_RECOVERY_RUN_ID,'prefix_sha256'=>$prefixSha,
        'prefix_bytes'=>$pins['global_bytes'],'append_sha256'=>hash('sha256',$line),'append_bytes'=>strlen($line),'post_writes'=>0,'feed_writes'=>0])."\n";flush();
    sfr_need(fwrite($global,$line)===strlen($line)&&fflush($global),'recovery_append_unknown_no_retry');
    if(function_exists('fsync'))sfr_need(fsync($global),'recovery_sync_unknown_no_retry');
    sfr_need(fseek($global,$pins['global_bytes'],SEEK_SET)===0&&stream_get_contents($global,strlen($line)+1)===$line,'recovery_readback_unknown_no_retry');
    rewind($global);$verify=hash_init('sha256');sfr_need(hash_update_stream($verify,$global,$pins['global_bytes'])===$pins['global_bytes']
        &&hash_final($verify)===$prefixSha,'recovery_prefix_changed_after_append');
    sfr_need(hash_file('sha256',$childPath)===$pins['child_sha256'],'recovery_child_changed_after_append');
    foreach($pins['feeds'] as $name=>$expected)sfr_need(hash_file('sha256',$feed.'/'.$name)===$expected,'recovery_feed_changed_after_append');
    $out['ok']=true;$out['journal_append_confirmed']=true;$out['original_records_preserved']=$records;
    $out['original_global_prefix_sha256']=$prefixSha;$out['appended_line_sha256']=hash('sha256',$line);
    $out['completed_hotels_preserved']=count($next['completed']);$out['pending']=null;$out['active_nightly_cycle']=null;
    $out['original_cycle_status']='interrupted_reconciled_not_completed';$out['original_post_receipts_unchanged']=true;
    $out['three_feed_preimages_unchanged']=true;$out['original_collector_replay_blocked']=true;$out['next_fresh_collector_gate_ready']=true;
    $out['recovery_run_id']=ANYTOUR_STALE_FEED_RECOVERY_RUN_ID;$out['checked_at']=gmdate('c');
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'recovery_failed_stop';}
finally{foreach(array_reverse($locks) as $f){flock($f,LOCK_UN);fclose($f);}}
echo 'STALE_FEED_METADATA_RESULT='.sfr_json($out)."\n";exit($out['ok']?0:2);
