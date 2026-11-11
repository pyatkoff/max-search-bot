<?php
declare(strict_types=1);
require_once __DIR__.'/monitor-price-contract.php';
ini_set('display_errors','0');ini_set('log_errors','0');
function upload_insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function static_feed_replacement_action(?string $current,?string $expectedPrevious,string $next):string{
 upload_insist(preg_match('/\A[a-f0-9]{64}\z/D',$next)===1,'invalid_next_feed_hash');
 upload_insist($current===null||preg_match('/\A[a-f0-9]{64}\z/D',$current)===1,'invalid_current_feed_hash');
 upload_insist($expectedPrevious===null||preg_match('/\A[a-f0-9]{64}\z/D',$expectedPrevious)===1,'invalid_previous_feed_hash');
 if($current!==null&&hash_equals($next,$current))return 'reuse';
 if($current===null){upload_insist($expectedPrevious===null,'previous_feed_missing_stop');return 'create';}
 upload_insist($expectedPrevious!==null&&hash_equals($expectedPrevious,$current),'unexpected_existing_feed_stop');
 return 'replace';
}
if(getenv('ANYTOUR_STATIC_FEED_UPDATE_FIXTURE')==='1'){
 $a=str_repeat('a',64);$b=str_repeat('b',64);$c=str_repeat('c',64);
 upload_insist(static_feed_replacement_action(null,null,$a)==='create','fixture_first_creation');
 upload_insist(static_feed_replacement_action($a,$a,$b)==='replace','fixture_exact_accepted_replacement');
 upload_insist(static_feed_replacement_action($b,$a,$b)==='reuse','fixture_safe_reconcile_after_write');
 upload_insist(static_feed_replacement_action($a,null,$a)==='reuse','fixture_existing_exact_bytes');
 $failures=[[$a,null,$b,'unexpected_existing_feed_stop'],[$c,$a,$b,'unexpected_existing_feed_stop'],[null,$a,$b,'previous_feed_missing_stop'],[$a,$a,'bad','invalid_next_feed_hash'],['bad',$a,$b,'invalid_current_feed_hash'],[$a,'bad',$b,'invalid_previous_feed_hash']];
 foreach($failures as [$current,$previous,$next,$why]){$rejected=false;try{static_feed_replacement_action($current,$previous,$next);}catch(RuntimeException $e){$rejected=$e->getMessage()===$why;}upload_insist($rejected,'fixture_previous_hash_guard');}
 echo "STATIC_FEED_UPDATE_PREPARATION_OK cases=10 exact_previous_hash=required unknown_previous=rejected missing_previous=stopped post_write_reconcile=reused provider_calls=0 server_calls=0 writes=0\n";exit;
}

$out=['operation'=>PUBLIC_STATIC_FEED_OPERATION,'mode'=>'publish_three_static_public_direct_yml_feeds','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'files'=>[]];
try{
 upload_insist(PHP_SAPI==='cli'&&trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
 upload_insist(trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','tracked_tree_not_clean');
 $raw=base64_decode(PUBLIC_STATIC_FEED_INPUT_B64,true);upload_insist(is_string($raw)&&hash('sha256',$raw)===PUBLIC_STATIC_FEED_INPUT_SHA256,'static_input_changed');
 $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$monitorMode=($input['schema_version']??null)===3;
 upload_insist(array_keys($input)===($monitorMode?['schema_version','operation','files','generated_at','price_updates']:['schema_version','operation','files'])&&in_array($input['schema_version'],[2,3],true)&&$input['operation']===$out['operation'],'static_input_schema');
 $priceUpdates=[];$generatedAt=0;
 if($monitorMode){
  $generatedAt=monitor_price_time($input['generated_at']);upload_insist($generatedAt<=time()+60&&$generatedAt>=time()-900&&is_array($input['price_updates'])&&count($input['price_updates'])>=1&&count($input['price_updates'])<=100,'monitor_feed_input_invalid');
  $seen=[];foreach($input['price_updates'] as $binding){
   $op=$binding['operation']??'';upload_insist(is_string($op)&&preg_match('/\Acommercial-monitor-price-h[1-9][0-9]*-[a-f0-9]{64}\z/D',$op)===1&&!isset($seen[$op]),'monitor_feed_operation_invalid');$seen[$op]=true;
   $journalRoot='/var/www/anytoour/data/channel-post-operations';$journalDir=$journalRoot.'/'.$op;$path=$journalDir.'/receipt.jsonl';
   upload_insist(!is_link($journalRoot)&&!is_link($journalDir)&&!is_link($path)&&is_file($path)&&realpath($path)===$path&&filesize($path)<=10000000,'monitor_receipt_path_invalid');
   $f=fopen($path,'rb');upload_insist($f!==false&&flock($f,LOCK_SH|LOCK_NB),'monitor_receipt_busy');$journal=stream_get_contents($f);flock($f,LOCK_UN);fclose($f);
   upload_insist(is_string($journal)&&$journal!==''&&str_ends_with($journal,"\n"),'monitor_receipt_incomplete');$lines=explode("\n",rtrim($journal,"\n"));
   $priceUpdates[]=monitor_price_confirmed_journal(json_decode(end($lines),true,64,JSON_THROW_ON_ERROR),$binding,time());
  }
 }

 $names=['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'];upload_insist(array_column($input['files'],'name')===$names,'static_scope_changed');
 $root=realpath(getcwd());upload_insist(is_string($root)&&$root==='/var/www/anytoour/data/www/app.anytoour.ru','document_root_changed');
 $dir=$root.'/feed';upload_insist(!is_link($dir),'feed_directory_symlink');
 if(!is_dir($dir))upload_insist(mkdir($dir,0755),'feed_directory_create_failed');
 upload_insist(realpath($dir)===$dir&&is_writable($dir),'feed_directory_not_writable');
 $prepared=[];
 foreach($input['files'] as $r){
  upload_insist(array_keys($r)===['name','sha256','bytes','rows','expected_previous_sha256','content_base64'],'static_file_schema');
  $bytes=base64_decode((string)$r['content_base64'],true);upload_insist(is_string($bytes)&&strlen($bytes)===$r['bytes']&&hash('sha256',$bytes)===$r['sha256']&&strlen($bytes)>1000&&strlen($bytes)<($monitorMode?4000000:1000000),'static_bytes_changed');
  upload_insist(str_starts_with($bytes,'<?xml version="1.0" encoding="UTF-8"?>'."\n<yml_catalog date=\"")&&str_ends_with($bytes,"</yml_catalog>\n"),'static_yml_envelope_changed');
  upload_insist(preg_match('//u',$bytes)===1&&substr_count($bytes,'<offer id="')===$r['rows']&&substr_count($bytes,'<description>')===$r['rows'],'static_yml_rows_changed');
  upload_insist(stripos($bytes,'<!DOCTYPE')===false&&stripos($bytes,'<!ENTITY')===false&&($monitorMode||stripos($bytes,'<price')===false)&&stripos($bytes,'available=')===false,'unsafe_static_yml_content');
  $dest=$dir.'/'.$r['name'];upload_insist(!is_link($dest),'static_destination_symlink');
  upload_insist(!file_exists($dest)||is_file($dest),'static_destination_not_file');
  $current=file_exists($dest)?hash_file('sha256',$dest):null;upload_insist($current===null||is_string($current),'static_current_hash_unreadable');
  $action=static_feed_replacement_action($current,$r['expected_previous_sha256'],$r['sha256']);
  if($monitorMode){upload_insist($current!==null,'monitor_feed_requires_existing');$original=file_get_contents($dest);upload_insist(is_string($original)&&monitor_price_render_feed($original,$priceUpdates,$r['name'],$generatedAt)===$bytes,'monitor_feed_receipt_content_mismatch');}
  if($action==='reuse'){$prepared[]=['r'=>$r,'dest'=>$dest,'tmp'=>null,'current'=>$current,'action'=>$action];continue;}
  upload_insist(preg_match('/\Acommercial-channel-hotel-feeds-[0-9]{8}-update[1-9][0-9]*\z/D',PUBLIC_STATIC_FEED_OPERATION)===1,'invalid_static_update_operation');
  $tmp=$dir.'/.'.PUBLIC_STATIC_FEED_OPERATION.'-'.$r['name'].'.tmp';
  upload_insist(!file_exists($tmp)&&!is_link($tmp),'prior_static_attempt_requires_reconciliation');
  $prepared[]=['r'=>$r,'dest'=>$dest,'tmp'=>$tmp,'bytes'=>$bytes,'current'=>$current,'action'=>$action];
 }
 foreach($prepared as $p){if($p['tmp']===null)continue;$f=fopen($p['tmp'],'xb');upload_insist($f!==false,'static_temp_create_failed');$written=0;$size=strlen($p['bytes']);while($written<$size){$n=fwrite($f,substr($p['bytes'],$written));upload_insist(is_int($n)&&$n>0,'static_write_failed');$written+=$n;}upload_insist(fflush($f),'static_flush_failed');if(function_exists('fsync'))upload_insist(fsync($f),'static_fsync_failed');fclose($f);upload_insist(chmod($p['tmp'],0644)&&hash_file('sha256',$p['tmp'])===$p['r']['sha256'],'static_temp_hash_changed');}
 foreach($prepared as $p){upload_insist(!is_link($p['dest']),'static_destination_symlink');$current=file_exists($p['dest'])?hash_file('sha256',$p['dest']):null;upload_insist($current===$p['current'],'static_destination_changed_before_commit');if($p['tmp']!==null)upload_insist(rename($p['tmp'],$p['dest']),'static_atomic_rename_failed');upload_insist(hash_file('sha256',$p['dest'])===$p['r']['sha256'],'static_final_hash_changed');$out['files'][]=['name'=>$p['r']['name'],'bytes'=>$p['r']['bytes'],'sha256'=>$p['r']['sha256'],'rows'=>$p['r']['rows'],'write_action'=>$p['action'],'public_url'=>'https://app.anytoour.ru/feed/'.$p['r']['name']];}
 $out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'static_feed_publish_failed';}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
