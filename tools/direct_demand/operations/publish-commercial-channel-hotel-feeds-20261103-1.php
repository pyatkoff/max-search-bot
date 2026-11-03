<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
function upload_insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$out=['operation'=>'commercial-channel-hotel-feeds-20261103-upload1','mode'=>'publish_three_static_public_channel_hotel_feeds','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'files'=>[]];
try{
 upload_insist(PHP_SAPI==='cli'&&trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
 upload_insist(trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','tracked_tree_not_clean');
 $raw=base64_decode(PUBLIC_STATIC_FEED_INPUT_B64,true);upload_insist(is_string($raw)&&hash('sha256',$raw)===PUBLIC_STATIC_FEED_INPUT_SHA256,'static_input_changed');
 $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);upload_insist(array_keys($input)===['schema_version','operation','files']&&$input['schema_version']===1&&$input['operation']===$out['operation'],'static_input_schema');
 $names=['hotel-posts-telegram.csv','hotel-posts-max.csv','hotel-posts-all.csv'];upload_insist(array_column($input['files'],'name')===$names,'static_scope_changed');
 $root=realpath(getcwd());upload_insist(is_string($root)&&$root==='/var/www/anytoour/data/www/app.anytoour.ru','document_root_changed');
 $dir=$root.'/feed';upload_insist(!is_link($dir),'feed_directory_symlink');
 if(!is_dir($dir))upload_insist(mkdir($dir,0755),'feed_directory_create_failed');
 upload_insist(realpath($dir)===$dir&&is_writable($dir),'feed_directory_not_writable');
 $prepared=[];
 foreach($input['files'] as $r){
  upload_insist(array_keys($r)===['name','sha256','bytes','rows','content_base64'],'static_file_schema');
  $bytes=base64_decode((string)$r['content_base64'],true);upload_insist(is_string($bytes)&&strlen($bytes)===$r['bytes']&&hash('sha256',$bytes)===$r['sha256']&&strlen($bytes)>1000&&strlen($bytes)<1000000,'static_bytes_changed');
  upload_insist(str_starts_with($bytes,"Property ID,Property name,Final URL,Destination name,Image URL,Category\r\n"),'static_csv_header_changed');
  $dest=$dir.'/'.$r['name'];upload_insist(!is_link($dest),'static_destination_symlink');
  if(file_exists($dest)){upload_insist(is_file($dest)&&hash_file('sha256',$dest)===$r['sha256'],'unexpected_existing_feed_stop');$prepared[]=['r'=>$r,'dest'=>$dest,'tmp'=>null];continue;}
  $tmp=$dir.'/.commercial-hotel-feeds-20261103-upload1-'.$r['name'].'.tmp';
  upload_insist(!file_exists($tmp)&&!is_link($tmp),'prior_static_attempt_requires_reconciliation');
  $prepared[]=['r'=>$r,'dest'=>$dest,'tmp'=>$tmp,'bytes'=>$bytes];
 }
 foreach($prepared as $p){if($p['tmp']===null)continue;$f=fopen($p['tmp'],'xb');upload_insist($f!==false,'static_temp_create_failed');$written=0;$size=strlen($p['bytes']);while($written<$size){$n=fwrite($f,substr($p['bytes'],$written));upload_insist(is_int($n)&&$n>0,'static_write_failed');$written+=$n;}upload_insist(fflush($f),'static_flush_failed');if(function_exists('fsync'))upload_insist(fsync($f),'static_fsync_failed');fclose($f);upload_insist(chmod($p['tmp'],0644)&&hash_file('sha256',$p['tmp'])===$p['r']['sha256'],'static_temp_hash_changed');}
 foreach($prepared as $p){if($p['tmp']!==null)upload_insist(rename($p['tmp'],$p['dest']),'static_atomic_rename_failed');upload_insist(hash_file('sha256',$p['dest'])===$p['r']['sha256'],'static_final_hash_changed');$out['files'][]=['name'=>$p['r']['name'],'bytes'=>$p['r']['bytes'],'sha256'=>$p['r']['sha256'],'rows'=>$p['r']['rows'],'created'=>$p['tmp']!==null,'public_url'=>'https://app.anytoour.ru/feed/'.$p['r']['name']];}
 $out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'static_feed_publish_failed';}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
