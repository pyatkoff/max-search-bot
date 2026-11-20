<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$out=['ok'=>false,'mode'=>'read_only_feed_failure_inspector','database_writes'=>0,'provider_writes'=>0,'journal_appends'=>0,'feed_writes'=>0,'supplier_calls'=>0];$locks=[];
try{
 reconcile_need(count($argv)===1,'inspect_arguments_invalid');$out['runtime_sha']=reconcile_runtime();
 [$g]=reconcile_open(RECONCILE_ROOT.'/monitor-price-after-collection-v1.jsonl',500000000,false,$locks,true);
 reconcile_assert_global_prefix($g);$suffix=stream_get_contents($g,5000001);
 reconcile_need(is_string($suffix)&&strlen($suffix)>0&&strlen($suffix)<=5000000&&feof($g)&&str_ends_with($suffix,"\n"),'inspect_global_suffix_invalid');
 $out['global_prefix_preserved']=true;$out['global_suffix_sha256']=hash('sha256',$suffix);$out['appended_states']=[];$global=null;
 foreach(explode("\n",substr($suffix,0,-1)) as $line){
  $global=json_decode($line,true,64,JSON_THROW_ON_ERROR);$pending=$global['pending']??null;$failure=$pending['child_failure']??null;
  $safeFailure=is_array($failure)?array_intersect_key($failure,array_flip(['exit_code','error','ok','db_writes','provider_calls','message_sends','files'])):null;
  $out['appended_states'][]=['pending_operation'=>$pending['operation']??null,'phase'=>$pending['phase']??null,'feed_operation'=>$pending['feed_operation']??null,'files'=>$pending['files']??null,'child_failure'=>$safeFailure,'completed_count'=>count($global['completed']??[])];
 }
 reconcile_need(is_array($global),'inspect_no_state');$pending=$global['pending']??null;
 reconcile_need(is_array($pending)&&($pending['operation']??null)===RECONCILE_OPERATION,'inspect_pending_not_original');
 [$c,$raw]=reconcile_open(RECONCILE_ROOT.'/'.RECONCILE_OPERATION.'/receipt.jsonl',10000000,false,$locks);
 reconcile_need(strlen($raw)>40405&&hash('sha256',substr($raw,0,40405))===RECONCILE_ORIGINAL_SHA&&str_ends_with($raw,"\n"),'inspect_child_prefix_changed');
 $lines=explode("\n",substr($raw,0,-1));$child=json_decode(end($lines),true,64,JSON_THROW_ON_ERROR);
 $out['child']=['complete'=>$child['complete']??null,'operation'=>$child['operation']??null,'manifest_hash'=>$child['manifest_hash']??null,'bytes'=>strlen($raw),'lines'=>count($lines),'sha256'=>hash('sha256',$raw),'prefix_preserved'=>true,'posts'=>[]];
 foreach(['max','telegram'] as $platform){$post=$child['posts'][$platform.':'.RECONCILE_KEY]??[];$receipt=$post['receipt']??[];$out['child']['posts'][$platform]=['state'=>$post['state']??null,'receipt'=>array_intersect_key($receipt,array_flip(['publication_id','version_id','platform','message_id','post_url','body_photo_button_verified','existing_message_reused','price_link_verified']))];}
 $files=$pending['files']??[];reconcile_need(array_column($files,'name')===['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'],'inspect_feed_scope_changed');
 $out['feeds']=[];
 foreach($files as $file){$path=getcwd().'/feed/'.$file['name'];reconcile_need(!is_link($path)&&is_file($path),'inspect_local_feed_path_invalid');
  $local=hash_file('sha256',$path);$public=null;$publicError=null;
  try{$public=hash('sha256',hook_get('https://app.anytoour.ru/feed/'.$file['name']));}catch(Throwable $e){$publicError=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'public_read_failed';}
  $out['feeds'][]=['name'=>$file['name'],'expected_sha256'=>$file['sha256']??null,'expected_previous_sha256'=>$file['expected_previous_sha256']??null,'local_sha256'=>$local,'public_sha256'=>$public,'public_error'=>$publicError,'local_matches_expected'=>$local===($file['sha256']??null),'local_matches_previous'=>$local===($file['expected_previous_sha256']??null),'public_matches_previous'=>$public===($file['expected_previous_sha256']??null)];
 }
 $out['feed_writer_evidence']='captured_child_failure_in_original_global_journal';
 $out['ok']=true;$out['checked_at']=gmdate('c');
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'inspect_failed';}
foreach(array_reverse($locks) as $lock)if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo 'PRICE27316_FEED_FAILURE_INSPECTION='.reconcile_json($out)."\n";exit($out['ok']?0:2);
