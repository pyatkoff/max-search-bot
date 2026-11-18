<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/guards.php';
const HI_ENABLED=true;
const HI_APP='/var/www/anytoour/data/www/app.anytoour.ru';
const HI_DATA='/var/www/anytoour/data/www/anytoour.ru/data';
const HI_PRIVATE='/var/www/anytoour/data/channel-post-operations';
function hi_json_file(string $path,string $sha):array {[$raw]=readfile_exact($path,4000000);must(preg_match('/\A[a-f0-9]{64}\z/D',$sha)===1&&hash('sha256',$raw)===$sha,'input_pin_changed');return json_decode($raw,true,128,JSON_THROW_ON_ERROR);}
function hi_load_definitions(string $raw):void {
 $marker="\n\$inspect=getenv('ANYTOUR_MONITOR_HOOK_INSPECT')";must(substr_count($raw,$marker)===1,'definitions_boundary_invalid');eval(substr(explode($marker,$raw,2)[0],5));
}
function hi_journal($lock,string $path,array $pin):array {
 $before=fstat($lock);must($before['size']>0&&$before['size']<=1073741824,'journal_bound');
 $h=monitor_hook_read_history($lock);must(hi_pin_equal($h['pin'],$pin)&&$h['trailing_blank_lines']===0,'journal_changed');
 clearstatcache(true,$path);$named=lstat($path);$after=fstat($lock);must(!is_link($path)&&realpath($path)===$path,'journal_path_changed');
 foreach(['dev','ino','size','mtime','ctime'] as $key)must(($before[$key]??null)===($named[$key]??null)&&($before[$key]??null)===($after[$key]??null),'journal_identity_changed');
 hi_state($h['state']);return ['pin'=>$h['pin'],'identity'=>array_intersect_key($before,array_flip(['dev','ino','size','mtime','ctime']))];
}
function hi_feeds(array $c,bool $public):void {
 hi_feed_map($c['feed_shas']);hi_feed_map($c['feed_counts'],true);
 foreach($c['feed_shas'] as $name=>$sha){[$raw]=readfile_exact(HI_APP.'/feed/hotel-posts-'.$name.'.xml',4000000);
  must(hash('sha256',$raw)===$sha&&is_int($c['feed_counts'][$name])&&$c['feed_counts'][$name]>0&&substr_count($raw,'<offer id="')===$c['feed_counts'][$name],'feed_changed');
  if($public)must(hash('sha256',hook_get('https://app.anytoour.ru/feed/hotel-posts-'.$name.'.xml'))===$sha,'public_feed_changed');
 }
}
function hi_runtime(array $c):void {must(hook_runtime()===$c['runtime_sha']&&active_collectors()===0,'runtime_or_collector_changed');hi_consumer_sql_idle();}
function hi_read_consumers(array $c):array {$result=[];foreach(['published-hotels-monitor-v1.php'=>'cohort_sha256','top500-daily-collector-v1.php'=>'collector_sha256'] as $name=>$key){[$raw,$stat]=readfile_exact(HI_DATA.'/'.$name,2000000);must(hash('sha256',$raw)===$c[$key],'consumer_changed');$result[$name]=hi_stat($stat);}return $result;}
function hi_child(array $c):void {
 $path=HI_PRIVATE.'/'.HI_RECOVERED.'/receipt.jsonl';[$raw]=readfile_exact($path,2000000);$pin=['sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw),'lines'=>substr_count($raw,"\n")];must(hi_pin_equal($pin,$c['child_pin']),'recovered_child_changed');
 $child=monitor_hook_parse_journal($raw,HI_RECOVERED);must(($child['complete']??null)===true,'recovered_child_incomplete');
 foreach(['telegram','max'] as $platform){$r=$child['posts'][$platform.':commercial-hotel-4294-v1']??[];must(($r['state']??null)==='confirmed'&&($r['receipt']['version_id']??null)===($platform==='telegram'?9092:9091),'recovered_pair_changed');}
}
$out=['ok'=>false,'operation'=>HI_OPERATION,'phase'=>'closed','code_replacements'=>0,'consumer_code_writes'=>0,'post_writes'=>0,'feed_writes'=>0,'journal_writes'=>0,'collector_runs'=>0,'broad_passes'=>0];$lock=null;$renameAttempted=false;
try {
 $hiOriginalConsumerEnv=hi_sql_bootstrap_snapshot();
 must(HI_ENABLED,'installation_closed');$mode=$argv[1]??'';must(in_array($mode,['inspect-old','install','inspect-new'],true)&&count($argv)===($mode==='install'?9:8),'arguments_invalid');$out['mode']=$mode;
 $c=hi_json_file($argv[2],$argv[7]);must(($c['enabled']??null)===true&&$c['operation']===HI_OPERATION&&$c['old_hook_sha256']===HI_OLD&&$c['new_hook_sha256']===HI_NEW
  &&$c['new_hook_bytes']===779768&&$c['source_sha']==='e8089224d98ec07bb4908a76125a909cca1506c4'&&preg_match('/\A[a-f0-9]{40}\z/D',$c['runtime_sha'])===1,'config_closed');
 foreach([HI_APP,HI_DATA,HI_PRIVATE] as $root)must(!is_link($root)&&realpath($root)===$root,'root_changed');
 [$bundle]=readfile_exact($argv[3],2000000);must(hash('sha256',$bundle)===HI_NEW&&strlen($bundle)===779768,'candidate_changed');hi_load_definitions($bundle);
 $inspect=hi_json_file($argv[4],$c['inspect_receipt_sha256']);$admission=hi_json_file($argv[5],$c['admission_sha256']);$recovery=hi_json_file($argv[6],$c['recovery_receipt_sha256']);
 must(($recovery['ok']??null)===true&&($recovery['operation']??null)===HI_RECOVERED&&($recovery['phase']??null)==='completed'
  &&array_key_exists('pending',$recovery)&&$recovery['pending']===null&&hi_pin_equal($recovery['global_pin']??null,$c['journal_pin'])&&hi_pin_equal($recovery['child_pin']??null,$c['child_pin'])&&hi_feed_map($recovery['feed_shas']??null)===hi_feed_map($c['feed_shas']),'recovery_receipt_not_verified');
 $rows=json_decode(gzdecode(base64_decode(HOOK_ROSTER_GZIP_B64,true)),true,64,JSON_THROW_ON_ERROR)['rows'];hi_inspect($inspect,$c,array_column($rows,'tourvisor_hotel_id'));hi_admission($admission,$c);hi_deadline($admission,$inspect,time());
 must(chdir(HI_APP),'app_unavailable');hi_runtime($c);
 $journalPath=HI_PRIVATE.'/monitor-price-after-collection-v1.jsonl';must(!is_link($journalPath)&&realpath($journalPath)===$journalPath,'journal_path_invalid');
 $lock=fopen($journalPath,'rb');must(is_resource($lock)&&flock($lock,($mode==='install'?LOCK_EX:LOCK_SH)|LOCK_NB),'global_busy');$journal=hi_journal($lock,$journalPath,$c['journal_pin']);hi_runtime($c);hi_child($c);hi_feeds($c,true);
 $path=HI_DATA.'/monitor-price-hook.php';[$original,$stat]=readfile_exact($path,2000000);$stat=hi_stat($stat);must(hash('sha256',$original)===($mode==='inspect-new'?HI_NEW:HI_OLD),'hook_preimage_changed');
 $consumers=hi_read_consumers($c);$op=HI_PRIVATE.'/'.HI_OPERATION;
 if($mode!=='inspect-new'){hi_repin($original,$bundle);must(!file_exists($op)&&!is_link($op),'operation_consumed_no_retry');}
 $out+=['journal'=>$journal,'hook_sha256'=>hash('sha256',$original),'target_identity'=>$stat,'consumer_identities'=>$consumers,'observed_at_epoch'=>time(),'runtime_sha'=>$c['runtime_sha'],'feed_shas'=>$c['feed_shas']];
 if($mode==='inspect-new'){
  [$receiptRaw]=readfile_exact($op.'/result.json',2000000);$receipt=json_decode($receiptRaw,true,64,JSON_THROW_ON_ERROR);
  must(($receipt['ok']??null)===true&&($receipt['operation']??null)===HI_OPERATION&&($receipt['code_replacements']??null)===1&&($receipt['hook_sha256']??null)===HI_NEW&&($receipt['journal']??null)===$journal&&($receipt['consumer_identities']??null)===$consumers,'installation_receipt_invalid');
  hi_cas($receipt['target_identity'],$stat);
 }
 if($mode==='install'){
  [$baselineRaw]=readfile_exact($argv[8],2000000);$baseline=json_decode($baselineRaw,true,64,JSON_THROW_ON_ERROR);
  must(($baseline['ok']??null)===true&&($baseline['mode']??null)==='inspect-old'&&($baseline['operation']??null)===HI_OPERATION&&($baseline['journal']??null)===$journal&&($baseline['target_identity']??null)===$stat&&($baseline['consumer_identities']??null)===$consumers&&is_int($baseline['observed_at_epoch']??null),'baseline_changed');
  hi_deadline($admission,$inspect,time(),$baseline['observed_at_epoch']);must(mkdir($op,0700),'operation_create_failed');
  write_new($op.'/original-monitor-price-hook.php',$original);$tmp=HI_DATA.'/.'.HI_OPERATION.'.tmp';write_new($tmp,$bundle);
  $ts=lstat($tmp);if($ts['uid']!==$stat['uid'])must(chown($tmp,$stat['uid']),'owner_failed');if($ts['gid']!==$stat['gid'])must(chgrp($tmp,$stat['gid']),'group_failed');must(chmod($tmp,$stat['mode']&07777),'mode_failed');
  hi_runtime($c);must(hi_journal($lock,$journalPath,$c['journal_pin'])===$journal,'journal_changed');hi_child($c);hi_feeds($c,true);must(hi_read_consumers($c)===$consumers,'consumer_identity_changed');
  [$oldNow,$currentStat]=readfile_exact($path,2000000);must(hash('sha256',$oldNow)===HI_OLD,'hook_cas_changed');hi_cas($stat,$currentStat);
  [$tmpRaw,$tmpStat]=readfile_exact($tmp,2000000);must(hash('sha256',$tmpRaw)===HI_NEW&&$tmpStat['uid']===$stat['uid']&&$tmpStat['gid']===$stat['gid']&&($tmpStat['mode']&07777)===($stat['mode']&07777),'temp_changed');
  hi_deadline($admission,$inspect,time(),$baseline['observed_at_epoch']);write_new($op.'/rename-intent.json',json_encode(['before'=>HI_OLD,'after'=>HI_NEW,'target_identity'=>$stat,'journal'=>$journal],JSON_THROW_ON_ERROR)."\n");
  hi_sync_dir($op);hi_sync_dir(HI_PRIVATE);
  // A rename acknowledgement can be lost. Mark uncertainty BEFORE the call.
  hi_runtime($c);hi_deadline($admission,$inspect,time(),$baseline['observed_at_epoch']);
  $renameAttempted=true;$out['phase']='rename_attempted';must(rename($tmp,$path),'rename_unknown');$out['code_replacements']=1;$dirHandle=fopen(HI_DATA,'r');must(is_resource($dirHandle)&&fsync($dirHandle),'rename_directory_sync_unknown');fclose($dirHandle);
  [$after,$afterStat]=readfile_exact($path,2000000);$afterStat=hi_stat($afterStat);must(hash('sha256',$after)===HI_NEW&&$afterStat['uid']===$stat['uid']&&$afterStat['gid']===$stat['gid']&&($afterStat['mode']&07777)===($stat['mode']&07777)&&$afterStat['dev']===$tmpStat['dev']&&$afterStat['ino']===$tmpStat['ino'],'replacement_readback_failed');
  hi_runtime($c);must(hi_journal($lock,$journalPath,$c['journal_pin'])===$journal,'post_install_journal_changed');hi_child($c);hi_feeds($c,true);must(hi_read_consumers($c)===$consumers,'post_install_consumer_changed');
  $out['hook_sha256']=HI_NEW;$out['target_identity']=$afterStat;$out['backup_retained']=true;$out['journal_unchanged']=true;$out['phase']='installed';$out['ok']=true;write_new($op.'/result.json',json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
 }else{$out['ok']=true;$out['phase']=$mode;}
}catch(Throwable $e){$out['ok']=false;$out['error']=preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'installation_failed';$out['mutation_outcome']=$renameAttempted?'UNKNOWN_REQUIRES_READBACK_NO_RETRY':'NO_TARGET_REPLACEMENT';}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($out['ok']?0:2);
