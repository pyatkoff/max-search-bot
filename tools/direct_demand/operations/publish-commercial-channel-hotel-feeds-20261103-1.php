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
/** Add confirmed hotel pairs without touching prior (possibly priced) offers. */
function static_extension_rows(array $rows,array $summaries):array{
 upload_insist(count($rows)>=1&&count($rows)<=10&&array_is_list($rows),'extension_scope_invalid');
 $out=[];$ids=[];$groups=[];
 $keys=['publication_operation','publication_summary_sha256','hotel_id','logical_id','text_sha256','photo_sha256','photo_url','name','destination','telegram_post_url','max_post_url','public_route_alias'];
 foreach($rows as $r){
  upload_insist(is_array($r)&&array_keys($r)===$keys,'extension_row_schema');
  $id=$r['hotel_id'];$op=$r['publication_operation'];
  upload_insist(is_int($id)&&$id>0&&!isset($ids[$id])&&is_string($op)&&preg_match('/\Acommercial-hotel-batch-[0-9]{8}-[1-9][0-9]*-v[1-9][0-9]*\z/D',$op)===1,'extension_identity_invalid');
  $ids[$id]=true;$groups[$op][]=$id;
  upload_insist(is_string($r['logical_id'])&&preg_match('/\A[a-z0-9-]{1,100}\z/D',$r['logical_id'])===1,'extension_logical_id_invalid');
  foreach(['publication_summary_sha256','text_sha256','photo_sha256'] as $k)upload_insist(is_string($r[$k])&&preg_match('/\A[a-f0-9]{64}\z/D',$r[$k])===1,'extension_hash_invalid');
  upload_insist(is_string($r['photo_url'])&&preg_match('~\Ahttps://raw\.githubusercontent\.com/pyatkoff/max-search-bot/[a-f0-9]{40}/\.github/traffic-publish/[a-zA-Z0-9/_.-]+\.png\z~D',$r['photo_url'])===1,'extension_image_invalid');
  upload_insist(is_string($r['telegram_post_url'])&&preg_match('~\Ahttps://t\.me/any_toour/[1-9][0-9]*\z~D',$r['telegram_post_url'])===1,'extension_telegram_url_invalid');
  upload_insist(is_string($r['max_post_url'])&&preg_match('~\Ahttps://max\.ru/(?:channel_anytour|c/-80492244209683)/[A-Za-z0-9_-]{1,100}\z~D',$r['max_post_url'])===1,'extension_max_url_invalid');
  $alias='h1_'.substr(hash('sha256','anytour|max_'.$id.'|'.$r['max_post_url']),0,32);
  upload_insist(is_string($r['public_route_alias'])&&hash_equals($alias,$r['public_route_alias']),'extension_route_alias_invalid');
  foreach(['name','destination'] as $k)upload_insist(is_string($r[$k])&&trim($r[$k])===$r[$k]&&$r[$k]!==''&&mb_check_encoding($r[$k],'UTF-8')&&preg_match('/[\x00-\x1f<>]/u',$r[$k])===0,'extension_public_label_invalid');
  upload_insist(mb_strlen('Путевки в '.$r['name'],'UTF-8')<=56&&mb_strlen($r['destination'],'UTF-8')<=25&&preg_match('/[-\x{2010}-\x{2015}\x{2212}]/u',$r['name'].$r['destination'])===0,'extension_public_label_invalid');
  $raw=$summaries[$op]??null;
  upload_insist(is_string($raw)&&hash_equals($r['publication_summary_sha256'],hash('sha256',$raw)),'extension_original_receipt_changed');
  $s=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
  upload_insist(($s['ok']??null)===true&&($s['operation']??null)===$op&&($s['mode']??null)==='publish_approved_commercial_batch'&&is_array($s['posts']??null),'extension_original_receipt_not_complete');
  foreach(['telegram','max'] as $platform){
   $matched=array_values(array_filter($s['posts'],static fn($p)=>is_array($p)&&($p['hotel_id']??null)===$id&&($p['platform']??null)===$platform));
   upload_insist(count($matched)===1,'extension_original_pair_not_unique');$p=$matched[0];
   upload_insist(($p['logical_id']??null)===$r['logical_id']&&($p['state']??null)==='sent'&&($p['body_photo_button_verified']??null)===true&&($p['button_text']??null)==='✈️ Узнать стоимость тура'
    &&($p['post_url']??null)===$r[$platform.'_post_url']&&($p['text_sha256']??null)===$r['text_sha256']&&($p['photo_sha256']??null)===$r['photo_sha256']&&($p['photo_url']??null)===$r['photo_url']
    &&is_int($p['publication_id']??null)&&$p['publication_id']>0&&is_int($p['version_id']??null)&&$p['version_id']>0,'extension_original_post_changed');
  }
  $out[]=$r;
 }
 foreach($groups as $op=>$group){
  $s=json_decode($summaries[$op],true,64,JSON_THROW_ON_ERROR);$receiptIds=array_values(array_unique(array_column($s['posts'],'hotel_id')));sort($receiptIds,SORT_NUMERIC);sort($group,SORT_NUMERIC);
  upload_insist($receiptIds===$group&&count($s['posts'])===2*count($group),'extension_original_batch_scope_changed');
 }
 return $out;
}
function static_extension_read_summaries(array $rows):array{
 $out=[];$root='/var/www/anytoour/data/channel-post-operations';
 foreach($rows as $r){
  $op=$r['publication_operation']??'';upload_insist(is_string($op)&&preg_match('/\Acommercial-hotel-batch-[0-9]{8}-[1-9][0-9]*-v[1-9][0-9]*\z/D',$op)===1,'extension_identity_invalid');
  if(isset($out[$op]))continue;
  $dir=$root.'/'.$op;$path=$dir.'/publication-summary.json';$lock=$dir.'/three-hotels.lock';
  upload_insist(!is_link($root)&&!is_link($dir)&&!is_link($path)&&!is_link($lock)&&is_file($path)&&is_file($lock)&&realpath($path)===$path&&filesize($path)<=4000000,'extension_original_receipt_path');
  $f=fopen($lock,'rb');upload_insist($f!==false&&flock($f,LOCK_SH|LOCK_NB),'extension_original_publication_busy');
  try{$raw=file_get_contents($path);upload_insist(is_string($raw)&&$raw!=='','extension_original_receipt_unreadable');$out[$op]=$raw;}finally{flock($f,LOCK_UN);fclose($f);}
 }
 return $out;
}
function static_extension_route_receipt(string $raw,string $expectedHash,string $expectedOperation,int $previousCount,int $newCount):void{
 upload_insist(preg_match('/\A[a-f0-9]{64}\z/D',$expectedHash)===1&&hash_equals($expectedHash,hash('sha256',$raw)),'extension_route_receipt_changed');
 $r=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$c=$r['checks']??[];
 upload_insist(($r['operation']??null)===$expectedOperation&&($r['ok']??null)===true&&($r['state']??null)==='INSTALLED_AND_NATIVE_VERIFIED','extension_route_not_accepted');
 upload_insist($previousCount>0&&$newCount>=1&&$newCount<=10&&($c['ok']??null)===true&&($c['exact_total_routes']??null)===$previousCount+$newCount&&($c['new_native_routes_verified']??null)===$newCount&&($c['previous'.$previousCount.'_rows_identical']??null)===true
  &&($c['private_projection_http_status']??null)===404&&($c['save_calls']??null)===0&&($c['project_active']??null)===true&&($c['counter_config_matches']??null)===true&&($c['required_post_fields_ready']??null)===true,'extension_route_scope_changed');
 foreach(['provider_calls','db_writes','message_sends','feed_writes'] as $k)upload_insist(($r[$k]??null)===0,'extension_route_write_scope_changed');
}
/** The caller binds previous bytes by SHA before this exact append. No XML reserialization. */
function static_extension_render_feed(string $xml,array $rows,string $name,int $at,int $previousCount):string{
 upload_insist(in_array($name,['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'],true)&&stripos($xml,'<!DOCTYPE')===false&&stripos($xml,'<!ENTITY')===false&&stripos($xml,'available=')===false,'extension_feed_invalid');
 $dom=new DOMDocument();$prior=libxml_use_internal_errors(true);
 try{$loaded=$dom->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
 upload_insist($loaded&&$dom->documentElement?->tagName==='yml_catalog','extension_feed_invalid');
 $existing=[];foreach($dom->getElementsByTagName('offer') as $e){$id=$e->getAttribute('id');upload_insist($id!==''&&!isset($existing[$id]),'extension_feed_duplicate');$existing[$id]=true;}
 upload_insist(count($existing)===$previousCount*($name==='hotel-posts-all.xml'?2:1),'extension_previous_count_changed');
 $extra=['telegram'=>'','max'=>''];$seen=[];
 foreach($rows as $r)foreach(['telegram','max'] as $platform){
  if(($name==='hotel-posts-max.xml'&&$platform!=='max')||($name==='hotel-posts-telegram.xml'&&$platform!=='telegram'))continue;
  $id=($platform==='telegram'?'tg_':'max_').$r['hotel_id'];upload_insist(!isset($existing[$id])&&!isset($seen[$id]),'extension_offer_already_present');$seen[$id]=true;
  if($platform==='telegram')$url=$r['telegram_post_url'];else{
   $context=json_encode(['post_id'=>$r['public_route_alias'],'offer_id'=>'max_'.$r['hotel_id']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
   $token=rtrim(strtr(base64_encode($context),'+/','-_'),'=');
   $url='https://max.ru/id9704048781_2_bot?startapp={yclid}_region_{region_id}_campaign_{campaign_id}_ctx1_'.$token;
  }
  $title=($platform==='telegram'?'Путевки в ':'Туры в ').$r['name'];$description='Тур с перелётом. '.$r['destination'].'. Фото и особенности отеля. Узнайте стоимость.';
  upload_insist(mb_strlen($description,'UTF-8')<=81,'extension_description_too_long');
  $esc=static fn(string $x):string=>htmlspecialchars($x,ENT_XML1|ENT_QUOTES,'UTF-8');
  $extra[$platform].='      <offer id="'.$id.'">'."\n".'        <url>'.$esc($url).'</url>'."\n".'        <categoryId>1</categoryId>'."\n".'        <picture>'.$esc($r['photo_url']).'</picture>'."\n".'        <name>'.$esc($title).'</name>'."\n".'        <description>'.$esc($description).'</description>'."\n".'        <custom_label_0>'.$platform.'</custom_label_0>'."\n".'        <custom_label_1>'.$esc($r['destination']).'</custom_label_1>'."\n".'      </offer>'."\n";
 }
 $addition=$name==='hotel-posts-all.xml'?$extra['telegram'].$extra['max']:($name==='hotel-posts-max.xml'?$extra['max']:$extra['telegram']);
 upload_insist($addition!==''&&substr_count($xml,'    </offers>')===1,'extension_offers_envelope_changed');
 $next=str_replace('    </offers>',$addition.'    </offers>',$xml);
 $date=(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i');
 $next=preg_replace('/<yml_catalog date="[^"<>]+">/','<yml_catalog date="'.$date.'">',$next,1,$changed);
 upload_insist(is_string($next)&&$changed===1,'extension_feed_date_invalid');return $next;
}

function static_extension_assert_content(string $previous,string $expectedPrevious,string $next,array $rows,string $name,int $at,int $previousCount):void{
 upload_insist(strlen($previous)>1000&&strlen($previous)<4000000&&hash_equals($expectedPrevious,hash('sha256',$previous)),'extension_previous_bytes_changed');
 upload_insist(static_extension_render_feed($previous,$rows,$name,$at,$previousCount)===$next,'extension_feed_content_mismatch');
}
// Offline preparation and fixtures import the same append validator; no connection or write.
if(getenv('ANYTOUR_STATIC_FEED_LIBRARY_ONLY')==='1')return;


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

$captionLocks=[];
$out=['operation'=>PUBLIC_STATIC_FEED_OPERATION,'mode'=>'publish_three_static_public_direct_yml_feeds','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'files'=>[]];
try{
 upload_insist(PHP_SAPI==='cli'&&trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
 upload_insist(trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','tracked_tree_not_clean');
 $raw=base64_decode(PUBLIC_STATIC_FEED_INPUT_B64,true);upload_insist(is_string($raw)&&hash('sha256',$raw)===PUBLIC_STATIC_FEED_INPUT_SHA256,'static_input_changed');
 $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$monitorMode=($input['schema_version']??null)===3;$extensionMode=($input['schema_version']??null)===4;$captionMode=($input['schema_version']??null)===5;
 upload_insist(array_keys($input)===($monitorMode?['schema_version','operation','files','generated_at','price_updates']:($extensionMode?['schema_version','operation','files','generated_at','publication_updates','route_receipt_base64']:($captionMode?['schema_version','operation','files','generated_at','caption_operations','caption_updates']:['schema_version','operation','files'])))&&in_array($input['schema_version'],[2,3,4,5],true)&&$input['operation']===$out['operation'],'static_input_schema');
 $priceUpdates=[];$captionUpdates=[];$generatedAt=0;
 if($captionMode){
  $generatedAt=monitor_price_time($input['generated_at']);
  upload_insist($generatedAt<=time()+60&&$generatedAt>=time()-900&&is_array($input['caption_operations'])&&is_array($input['caption_updates']),'caption_feed_input_invalid');
 }
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

 $extensionRows=[];
 if($extensionMode){
  upload_insist(defined('PUBLIC_STATIC_FEED_ROUTE_RECEIPT_SHA256')&&defined('PUBLIC_STATIC_FEED_ROUTE_OPERATION')&&defined('PUBLIC_STATIC_FEED_PREVIOUS_COUNT'),'extension_route_admission_missing');
  $generatedAt=monitor_price_time($input['generated_at']);upload_insist($generatedAt<=time()+60&&$generatedAt>=time()-900&&is_array($input['publication_updates']),'extension_input_expired');
  $extensionRows=static_extension_rows($input['publication_updates'],static_extension_read_summaries($input['publication_updates']));
  $routeRaw=base64_decode($input['route_receipt_base64'],true);upload_insist(is_string($routeRaw),'extension_route_receipt_invalid');
  static_extension_route_receipt($routeRaw,PUBLIC_STATIC_FEED_ROUTE_RECEIPT_SHA256,PUBLIC_STATIC_FEED_ROUTE_OPERATION,PUBLIC_STATIC_FEED_PREVIOUS_COUNT,count($extensionRows));
 }

 $names=['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'];upload_insist(array_column($input['files'],'name')===$names,'static_scope_changed');
 $root=realpath(getcwd());upload_insist(is_string($root)&&$root==='/var/www/anytoour/data/www/app.anytoour.ru','document_root_changed');
 if($captionMode){
  if(!function_exists('caption_feed_admit'))require_once __DIR__.'/caption-feed-contract.php';
  $captionUpdates=caption_feed_admit($input,EXPECTED_INSTALLED_SHA,$captionLocks);
 }
 $dir=$root.'/feed';upload_insist(!is_link($dir),'feed_directory_symlink');
 if($captionMode)upload_insist(is_dir($dir)&&realpath($dir)===$dir,'caption_feed_directory_missing');
 if(!is_dir($dir))upload_insist(mkdir($dir,0755),'feed_directory_create_failed');
 upload_insist(realpath($dir)===$dir&&is_writable($dir),'feed_directory_not_writable');
 $prepared=[];
 foreach($input['files'] as $r){
  upload_insist(array_keys($r)===(($extensionMode||$captionMode)?['name','sha256','bytes','rows','expected_previous_sha256','content_base64','previous_content_base64']:['name','sha256','bytes','rows','expected_previous_sha256','content_base64']),'static_file_schema');
  $bytes=base64_decode((string)$r['content_base64'],true);upload_insist(is_string($bytes)&&strlen($bytes)===$r['bytes']&&hash('sha256',$bytes)===$r['sha256']&&strlen($bytes)>1000&&strlen($bytes)<(($monitorMode||$extensionMode||$captionMode)?4000000:1000000),'static_bytes_changed');
  upload_insist(str_starts_with($bytes,'<?xml version="1.0" encoding="UTF-8"?>'."\n<yml_catalog date=\"")&&str_ends_with($bytes,"</yml_catalog>\n"),'static_yml_envelope_changed');
  upload_insist(preg_match('//u',$bytes)===1&&substr_count($bytes,'<offer id="')===$r['rows']&&substr_count($bytes,'<description>')===$r['rows'],'static_yml_rows_changed');
  upload_insist(stripos($bytes,'<!DOCTYPE')===false&&stripos($bytes,'<!ENTITY')===false&&($monitorMode||$extensionMode||$captionMode||stripos($bytes,'<price')===false)&&stripos($bytes,'available=')===false,'unsafe_static_yml_content');
  $dest=$dir.'/'.$r['name'];upload_insist(!is_link($dest),'static_destination_symlink');
  upload_insist(!file_exists($dest)||is_file($dest),'static_destination_not_file');
  $current=file_exists($dest)?hash_file('sha256',$dest):null;upload_insist($current===null||is_string($current),'static_current_hash_unreadable');
  $action=static_feed_replacement_action($current,$r['expected_previous_sha256'],$r['sha256']);
  if($monitorMode){upload_insist($current!==null,'monitor_feed_requires_existing');$original=file_get_contents($dest);upload_insist(is_string($original)&&monitor_price_render_feed($original,$priceUpdates,$r['name'],$generatedAt)===$bytes,'monitor_feed_receipt_content_mismatch');}
  if($extensionMode){
   $original=base64_decode($r['previous_content_base64'],true);upload_insist(is_string($original)&&is_string($r['expected_previous_sha256']),'extension_previous_bytes_changed');
   static_extension_assert_content($original,$r['expected_previous_sha256'],$bytes,$extensionRows,$r['name'],$generatedAt,PUBLIC_STATIC_FEED_PREVIOUS_COUNT);
  }
  if($captionMode){
   upload_insist($current!==null&&is_string($r['expected_previous_sha256']),'caption_feed_requires_existing');
   $original=base64_decode($r['previous_content_base64'],true);
   upload_insist(is_string($original)&&strlen($original)>1000&&strlen($original)<4000000&&hash('sha256',$original)===$r['expected_previous_sha256'],'caption_feed_previous_bytes_changed');
   upload_insist(caption_feed_render($original,$captionUpdates,$r['name'])===$bytes,'caption_feed_receipt_content_mismatch');
  }
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
foreach(array_reverse($captionLocks) as $captionLock){if(is_resource($captionLock)){flock($captionLock,LOCK_UN);fclose($captionLock);}}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);

