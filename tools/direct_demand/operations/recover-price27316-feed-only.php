<?php
declare(strict_types=1);
// Source-only bounded recovery. Bundle using build-verify-price27316-completed.py optional source.
// PIN REQUIRED: replace only after independently reading exact completed child bytes.
const FEED27316_CHILD_SHA='0d5559d40eb4263ba5418f38ab56db5aa5b9e991498af2f7b1dcac7c026e8330';
const FEED27316_GENERATED_AT='2026-11-17T16:55:00Z';
function feed27316_prefix($f):array {
 reconcile_need(rewind($f),'feed_prefix_rewind_failed');$h=hash_init('sha256');$n=0;$lines=0;$last='';
 while($n<443818242){$line=fgets($f);reconcile_need(is_string($line)&&str_ends_with($line,"\n"),'feed_prefix_incomplete');$n+=strlen($line);$lines++;hash_update($h,$line);$last=$line;}
 reconcile_need($n===443818242&&$lines===4352&&hash_final($h)===RECONCILE_GLOBAL_SHA,'feed_original_prefix_changed');return json_decode($last,true,64,JSON_THROW_ON_ERROR);
}
function feed27316_lock_selftest():void {
 $path=tempnam(sys_get_temp_dir(),'price27316-lock-');reconcile_need(is_string($path),'lock_fixture_failed');$f=fopen($path,'r+b');
 try{
  reconcile_need(flock($f,LOCK_EX|LOCK_NB),'lock_ex_failed');
  $childCode='$f=fopen($argv[1],"rb");exit(flock($f,LOCK_SH|LOCK_NB)?0:7);';
  $run=static function()use($path,$childCode):int{$pipes=[];$p=proc_open([PHP_BINARY,'-n','-r',$childCode,$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);reconcile_need(is_resource($p),'lock_child_failed');foreach($pipes as $x)fclose($x);return proc_close($p);};
  reconcile_need($run()===7,'exclusive_lock_did_not_block_writer');
  reconcile_need(flock($f,LOCK_SH|LOCK_NB),'shared_downgrade_failed');
  reconcile_need($run()===0,'shared_lock_blocked_writer');
  echo "PRICE27316_FLOCK_REGRESSION_OK actual_child_process=1 checks=2 production_writes=0\n";
 }finally{flock($f,LOCK_UN);fclose($f);unlink($path);}
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??null)==='--self-test'&&count($argv)===2){feed27316_lock_selftest();exit;}
$apply=getenv('ANYTOUR_PRICE27316_FEED_APPLY')==='1';$locks=[];
$out=['ok'=>false,'mode'=>$apply?'recover_original_feed_only':'inspect_original_feed_only','provider_writes'=>0,'database_writes'=>0,'new_posts'=>0,'supplier_calls'=>0,'child_journal_appends'=>0,'feed_writer_calls'=>0,'global_appends'=>0,'intent_created'=>false];
try{
 reconcile_need(count($argv)===1&&in_array(getenv('ANYTOUR_PRICE27316_FEED_APPLY'),[false,'','1'],true),'feed_arguments_invalid');
 reconcile_need(preg_match('/\A[a-f0-9]{64}\z/D',FEED27316_CHILD_SHA)===1,'feed_live_child_pin_required');
 $installed=reconcile_runtime();$out['runtime_sha']=$installed;
 [$g]=reconcile_open(RECONCILE_ROOT.'/monitor-price-after-collection-v1.jsonl',500000000,$apply,$locks,true);
 $original=feed27316_prefix($g);$tail=stream_get_contents($g,2000001);
 reconcile_need(is_string($tail)&&strlen($tail)<=2000000&&feof($g)&&str_ends_with($tail,"\n"),'feed_tail_invalid');
 reconcile_need(hash('sha256',$tail)==='10d9f8a7a84236ec1c27f091510ebe4c9ae040b8da8e245446735b0df13a025d','feed_live_suffix_changed');
 $lines=explode("\n",substr($tail,0,-1));reconcile_need(count($lines)===2,'feed_unknown_global_append');
 $start=json_decode($lines[0],true,64,JSON_THROW_ON_ERROR);$global=json_decode($lines[1],true,64,JSON_THROW_ON_ERROR);$pending=$start['pending']??null;
 reconcile_need(is_array($pending)&&($pending['operation']??null)===RECONCILE_OPERATION&&($pending['tourvisor_hotel_id']??null)===27316&&($pending['phase']??null)==='feed_started','feed_pending_not_original');
 $expected=$original;$expected['pending']['phase']='feed_started';$expected['pending']['feed_operation']=$pending['feed_operation'];$expected['pending']['files']=$pending['files'];
 reconcile_need($start===$expected,'feed_start_unrelated_changed');$expected['pending']['child_failure']=['exit_code'=>2,'error'=>'monitor_receipt_busy'];
 reconcile_need($global===$expected,'feed_failure_not_exact_lock_failure');
 // Shared child lock is deliberate: frozen writer takes its own shared lock in another process.
 [$c,$childRaw]=reconcile_open(RECONCILE_ROOT.'/'.RECONCILE_OPERATION.'/receipt.jsonl',10000000,false,$locks);
 reconcile_need(strlen($childRaw)===150020&&substr_count($childRaw,"\n")===9&&hash('sha256',$childRaw)===FEED27316_CHILD_SHA&&hash('sha256',substr($childRaw,0,40405))===RECONCILE_ORIGINAL_SHA&&str_ends_with($childRaw,"\n"),'feed_child_pin_changed');
 $childLines=explode("\n",substr($childRaw,0,-1));$child=json_decode(end($childLines),true,64,JSON_THROW_ON_ERROR);
 reconcile_need(($child['complete']??null)===true&&($child['operation']??null)===RECONCILE_OPERATION&&($child['manifest_hash']??null)===RECONCILE_MANIFEST,'feed_child_not_complete');
 $binding=$child['feed_binding']+['operation'=>RECONCILE_OPERATION,'manifest_hash'=>RECONCILE_MANIFEST];$update=monitor_price_confirmed_journal($child,$binding,time());
 $files=$pending['files'];reconcile_need(array_column($files,'name')===['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'],'feed_file_scope_changed');
 $operation=$pending['feed_operation'];reconcile_need($operation===monitor_hook_feed_operation(RECONCILE_OPERATION,'20261117'),'feed_operation_changed');
 $at=monitor_price_time(FEED27316_GENERATED_AT);$inputFiles=[];$out['files']=[];
 foreach($files as $file){
  $path=getcwd().'/feed/'.$file['name'];$temp=getcwd().'/feed/.'.$operation.'-'.$file['name'].'.tmp';
  reconcile_need(!file_exists($temp)&&!is_link($temp),'feed_previous_temp_exists');
  $before=hook_read($path,4000000);$previous=hash('sha256',$before);$public=hash('sha256',hook_get('https://app.anytoour.ru/feed/'.$file['name']));
  reconcile_need($previous===$file['expected_previous_sha256']&&$public===$previous,'feed_previous_local_public_changed');
  $after=monitor_price_render_feed($before,[$update],$file['name'],$at);
  reconcile_need(hash('sha256',$after)===$file['sha256']&&strlen($after)===$file['bytes']&&substr_count($after,'<offer id="')===$file['rows'],'feed_original_bytes_not_reconstructed');
  $inputFiles[]=$file+['content_base64'=>base64_encode($after)];$out['files'][]=$file;
 }
 $input=['schema_version'=>3,'operation'=>$operation,'files'=>$inputFiles,'generated_at'=>FEED27316_GENERATED_AT,'price_updates'=>[$binding]];
 $raw=reconcile_json($input);$out['input_sha256']=hash('sha256',$raw);$out['original_feed_operation']=$operation;
 $marker=RECONCILE_ROOT.'/'.RECONCILE_OPERATION.'/feed-recovery-intent.json';
 reconcile_need(!file_exists($marker)&&!is_link($marker),'feed_recovery_already_attempted_no_retry');
 // Same frozen writer freshness contract. Stop before intent if the original input is too old.
 reconcile_need($at<=time()+60&&$at>=time()-900,'feed_original_input_expired_no_replan');
 $out['ready_for_first_feed_writer']=$apply?false:true;
 if($apply){
  reconcile_need(reconcile_runtime()===$installed,'feed_runtime_changed');
  reconcile_need($at>=time()-900,'feed_original_input_expired_before_intent');
  umask(0077);
  $intent=reconcile_json(['operation'=>$operation,'child_sha256'=>FEED27316_CHILD_SHA,'input_sha256'=>hash('sha256',$raw),'started_at'=>gmdate('c'),'write_attempts'=>1,'retry_permitted'=>false])."\n";
  $m=fopen($marker,'xb');reconcile_need(is_resource($m),'feed_intent_exclusive_failed');
  try{reconcile_need(fwrite($m,$intent)===strlen($intent)&&fflush($m)&&fsync($m),'feed_intent_not_durable');}finally{fclose($m);}
  $out['intent_created']=true;$failure=null;$out['feed_writer_calls']++;
  try{$writer=hook_child(base64_decode(HOOK_WRITER_B64,true),['EXPECTED_INSTALLED_SHA'=>$installed,'PUBLIC_STATIC_FEED_OPERATION'=>$operation,'PUBLIC_STATIC_FEED_INPUT_SHA256'=>hash('sha256',$raw),'PUBLIC_STATIC_FEED_INPUT_B64'=>base64_encode($raw)],$failure);}
  catch(Throwable $e){$out['writer_failure']=$failure;throw $e;}
  reconcile_need(($writer['ok']??null)===true,'feed_writer_outcome_unknown_no_retry');
  // phase feed_started excludes prepare/write branch; only verify local/public and append completed.
  reconcile_need($global['pending']['phase']==='feed_started','feed_phase_changed');
  reconcile_need(hook_finish_feed($global,$g,$installed,$out)==='feed_completed','feed_readback_not_complete');$out['global_appends']=1;
  reconcile_need(rewind($c)&&stream_get_contents($c,10000001)===$childRaw,'feed_child_bytes_changed');
  $oldOthers=$original['completed'];$newOthers=$global['completed'];unset($oldOthers[27316],$newOthers[27316]);
  reconcile_need(count($oldOthers)===588&&$oldOthers===$newOthers&&($global['holds']??[])===($original['holds']??[])&&$global['pending']===null,'feed_unrelated_state_changed');
  reconcile_assert_global_prefix($g);$out['pending_cleared']=true;$out['child_bytes_preserved']=true;$out['other_hotels_preserved']=588;$out['global_cycle_closure_pending']=true;
 }
 $out['ok']=true;$out['checked_at']=gmdate('c');
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'feed_recovery_failed_no_retry';}
foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo 'PRICE27316_FEED_ONLY_RECOVERY='.reconcile_json($out)."\n";exit($out['ok']?0:2);
