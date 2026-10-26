<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
const TARGET_CHANNEL='-80492244209683';
const TARGET_PUBLIC='https://max.ru/channel_anytour';
const BOT_KEY='id9704048781_1_bot';
const OPERATION='channel-hotel-six-20261026-v1';
const OPERATION_DIR='/var/www/anytoour/data/channel-post-operations/catalog-hotel-six-20261026-v1';
$out=['ok'=>false,'mode'=>OP_MODE,'operation'=>OPERATION,'target'=>TARGET_PUBLIC,'post_write_attempts'=>0,'upload_attempts'=>0,'posts'=>[]];
function insist(bool $condition,string $reason):void{if(!$condition)throw new RuntimeException($reason);}
function durable($f,array $state):void{
 insist(rewind($f)&&ftruncate($f,0),'receipt_write_failed');$raw=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 insist(fwrite($f,$raw)===strlen($raw)&&fflush($f),'receipt_write_failed');if(function_exists('fsync'))insist(fsync($f),'receipt_sync_failed');
}
function messageMatches(array $m,string $text,?string $photoId=null):bool{
 if((string)($m['recipient']['chat_id']??'')!==TARGET_CHANNEL||($m['recipient']['chat_type']??'')!=='channel'||($m['body']['text']??'')!==$text)return false;
 $images=array_values(array_filter((array)($m['body']['attachments']??[]),static fn($a)=>is_array($a)&&($a['type']??'')==='image'));
 return count($images)===1&&preg_match('/\A[0-9]+\z/',(string)($images[0]['payload']['photo_id']??''))===1&&($photoId===null||(string)$images[0]['payload']['photo_id']===$photoId);
}
function readMessage(string $mid):array{
 insist(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$mid)===1,'invalid_saved_mid');
 $r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$mid,[],null);
 insist(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']),'post_read_failed');return $r['data'];
}
function readRecent():array{
 $r=ChannelPostApiTransport::buttonRequest('max','GET','/messages',['chat_id'=>TARGET_CHANNEL,'from'=>(int)floor(microtime(true)*1000),'to'=>1,'count'=>100],null);
 insist(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']['messages']??null),'recent_read_failed');return $r['data']['messages'];
}
function photoToken(array $data):string{
 if(is_string($data['token']??null)&&$data['token']!=='')return $data['token'];
 foreach($data as $v)if(is_array($v)){ $token=photoToken($v);if($token!=='')return $token;}return '';
}
function uploadCover(array $row,$lock,array &$state,array &$out):array{
 $key=$row['key'];$saved=$state['posts'][$key]??[];
 if(isset($saved['upload_token']))return ['token'=>$saved['upload_token'],'photo_id'=>$saved['uploaded_photo_id']??null];
 insist(empty($saved['upload_started']),'upload_outcome_unknown');
 $state['posts'][$key]=array_merge($saved,['text_sha256'=>$row['text_sha256'],'cover_sha256'=>$row['cover_sha256'],'upload_started'=>true]);durable($lock,$state);
 $endpoint=MaxTransport::request('https://platform-api2.max.ru',MAX_SEARCH_TOKEN,'POST','/uploads',['type'=>'image'],null,null);$out['upload_attempts']++;
 insist(is_array($endpoint)&&is_string($endpoint['url']??null),'upload_endpoint_failed');
 $parts=parse_url($endpoint['url']);$host=(string)($parts['host']??'');
 insist(($parts['scheme']??'')==='https'&&!isset($parts['user'])&&!isset($parts['pass'])&&!isset($parts['port'])&&!isset($parts['fragment'])&&preg_match('/\A(?:[a-z0-9-]+\.)*(?:max\.ru|oneme\.ru|okcdn\.ru|mycdn\.me)\z/D',$host)===1,'upload_host_requires_review');
 $file=OPERATION_DIR.'/'.$key.'.png';$bytes=base64_decode($row['cover_png_base64'],true);insist(is_string($bytes)&&hash('sha256',$bytes)===$row['cover_sha256'],'cover_bytes_changed');
 insist(file_put_contents($file,$bytes,LOCK_EX)===strlen($bytes),'cover_write_failed');chmod($file,0600);
 $raw='';$ch=curl_init($endpoint['url']);curl_setopt_array($ch,MaxTlsConfig::strictCurlOptions()+[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,CURLOPT_POSTFIELDS=>['data'=>new CURLFile($file,'image/png','cover.png')],CURLOPT_WRITEFUNCTION=>static function($c,string $s)use(&$raw):int{if(strlen($raw)+strlen($s)>1048576)return 0;$raw.=$s;return strlen($s);}]);
 $sent=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=$sent===false||curl_errno($ch)!==0;curl_close($ch);unlink($file);
 $data=$error?null:json_decode($raw,true,32);insist(!$error&&$http>=200&&$http<300&&is_array($data),'cover_upload_failed');$token=photoToken($data);insist($token!=='','uploaded_photo_token_missing');
 $id=null; // Stable photo identity comes from the created provider message, never an upload-list index.
 $state['posts'][$key]['upload_token']=$token;$state['posts'][$key]['uploaded_photo_id']=$id;durable($lock,$state);return ['token'=>$token,'photo_id'=>$id];
}
function currentChannel(ChannelPostRegistryService $registry):array{
 insist(trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
 $c=$registry->channel('anytour','max',BOT_KEY,TARGET_CHANNEL);
 insist(is_array($c)&&(int)$c['id']===9&&(int)$c['config_version']===1&&$c['source_key']==='max_anytour_msk1'&&$c['canonical_bot_username']===BOT_KEY&&$c['button_text']==='✈️ Рассчитать на мои даты','channel_configuration_changed');
 $r=ChannelPostApiTransport::buttonRequest('max','GET','/chats/'.TARGET_CHANNEL,[],null);
 insist(empty($r['transport_error'])&&(int)$r['http']===200&&($r['data']['link']??'')===TARGET_PUBLIC&&($r['data']['type']??'')==='channel'&&(string)($r['data']['chat_id']??'')===TARGET_CHANNEL,'provider_channel_identity_changed');return $c;
}
function publishOne(array $row,$lock,array &$state,ChannelPostRegistryService $registry,array &$out):void{
 $key=$row['key'];$text=$row['text'];$saved=$state['posts'][$key]??null;
 insist(!$saved||(($saved['text_sha256']??'')===$row['text_sha256']&&($saved['cover_sha256']??'')===$row['cover_sha256']),'prepared_body_changed');
 $channel=currentChannel($registry);$message=null;
 if(is_array($saved)&&is_string($saved['mid']??null)){$message=readMessage($saved['mid']);insist(messageMatches($message,$text,$saved['photo_id']??null),'saved_post_changed');}
 if(!$message){
  $matches=[];foreach(readRecent() as $m)if(is_array($m)&&messageMatches($m,$text))$matches[]=$m;
  insist(count($matches)<=1,'duplicate_text_requires_review');
  insist(count($matches)===0,'existing_same_text_requires_review');
 }
 if(!$message){
  insist(empty($saved['post_started']),'previous_post_outcome_unknown');$photo=uploadCover($row,$lock,$state,$out);$channel=currentChannel($registry);
  $state['posts'][$key]['post_started']=true;$state['posts'][$key]['started_ms']=(int)floor(microtime(true)*1000);durable($lock,$state);
  $parts=explode("\n",$text,2);$html='<b>'.htmlspecialchars($parts[0],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</b>'.(isset($parts[1])?"\n".htmlspecialchars($parts[1],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):'');
  $out['post_write_attempts']++;
  $r=MaxTransport::request('https://platform-api2.max.ru',MAX_SEARCH_TOKEN,'POST','/messages',['chat_id'=>TARGET_CHANNEL,'disable_link_preview'=>'true'],['text'=>$html,'format'=>'html','attachments'=>[['type'=>'image','payload'=>['token'=>$photo['token']]]]],null);
  $candidate=is_array($r)?($r['message']??null):null;
  if(is_array($candidate)&&messageMatches($candidate,$text,$photo['photo_id']))$message=$candidate;
  else {
   $matches=[];foreach(readRecent() as $m)if(is_array($m)&&$photo['photo_id']!==null&&messageMatches($m,$text,$photo['photo_id']))$matches[]=$m;
   insist(count($matches)===1,'post_outcome_unknown');$message=$matches[0];
  }
  $mid=(string)($message['body']['mid']??'');insist(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$mid)===1,'created_mid_invalid');
  $images=array_values(array_filter((array)$message['body']['attachments'],static fn($a)=>($a['type']??'')==='image'));
  $state['posts'][$key]['mid']=$mid;$state['posts'][$key]['photo_id']=(string)$images[0]['payload']['photo_id'];$state['posts'][$key]['state']='published';durable($lock,$state);
 }
 $mid=(string)$message['body']['mid'];$message=readMessage($mid);insist(messageMatches($message,$text,$state['posts'][$key]['photo_id']??null),'published_readback_mismatch');
 $p=$registry->registerProviderObservation('anytour',BOT_KEY,$channel,$message,1);insist(is_array($p),'registry_observation_failed');
 $service=new ChannelPostAutomationService($registry);$service->processPublication('anytour','max',BOT_KEY,(int)$p['publication_id'],(int)$p['version_id']);
 $verified=false;
 for($n=0;$n<6;$n++){
  $message=readMessage($mid);$snapshot=ChannelPostIncomingAdapter::maxSnapshot($message);$p=$registry->publication((int)$p['publication_id']);
  if(is_array($snapshot)&&is_array($p)&&$p['state']==='sent'&&messageMatches($message,$text,$state['posts'][$key]['photo_id']??null)&&ChannelPostIncomingAdapter::containsOnlyButton($snapshot,ChannelPostButtonTransport::buttonUrl($p),(string)$channel['button_text'])){$verified=true;break;}
  if($n<5)usleep(700000);
 }
 insist($verified,'unique_button_or_content_unverified');
 $resolved=ChannelPostEntryService::resolve('max',(string)$p['start_payload'],$registry);$url=(string)($snapshot['post_url']??'');
 insist(preg_match('~\Ahttps://max\.ru/(?:channel_anytour|c/-80492244209683)/[A-Za-z0-9_-]{1,100}\z~D',$url)===1,'canonical_post_url_missing');
 insist(is_array($resolved)&&(int)$resolved['publication_id']===(int)$p['publication_id']&&(int)$resolved['version_id']===(int)$p['version_id']&&$resolved['snapshot']['text']===$text&&($resolved['snapshot']['post_url']??'')===$url&&$resolved['source_key']==='max_anytour_msk1','canonical_entry_resolution_mismatch');
 $receipt=['key'=>$key,'post_url'=>$url,'message_id'=>$mid,'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'start_payload'=>(string)$p['start_payload'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'text_sha256'=>$row['text_sha256'],'cover_sha256'=>$row['cover_sha256'],'photo_id'=>$state['posts'][$key]['photo_id']??null,'body_photo_button_verified'=>true,'canonical_token_resolved'=>true,'real_customer_entry_tested'=>false,'yclid_live_tested'=>false];
 $state['posts'][$key]['state']='verified';$state['posts'][$key]['receipt']=$receipt;durable($lock,$state);$out['posts'][]=$receipt;
}
$lock=null;
try{
 insist(PHP_SAPI==='cli'&&in_array(OP_MODE,['preview','apply'],true),'invalid_execution_context');
 $raw=gzdecode(base64_decode(ROWS_GZIP_BASE64,true));insist(is_string($raw),'payload_decode_failed');
 $payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 insist(is_array($payload)&&($payload['operation']??'')===OPERATION&&($payload['channel_id']??'')===TARGET_CHANNEL,'payload_scope_changed');
 $rows=$payload['rows']??null;insist(is_array($rows)&&count($rows)===6,'batch_size_changed');
 $expectedKeys=['golf','sultan','rixos','rehana','solara','palace'];
 foreach($rows as $i=>$row){
  insist(is_array($row)&&($row['key']??'')===$expectedKeys[$i]&&is_string($row['text']??null)&&hash('sha256',$row['text'])===($row['text_sha256']??'')&&str_contains($row['text'],'Рассчитать на мои даты')&&strlen(mb_convert_encoding($row['text'],'UTF-16LE','UTF-8'))/2<=1024,'prepared_row_changed');
  $bytes=base64_decode($row['cover_png_base64']??'',true);$image=is_string($bytes)?@getimagesizefromstring($bytes):false;
  insist(is_string($bytes)&&hash('sha256',$bytes)===($row['cover_sha256']??'')&&is_array($image)&&$image[0]===600&&$image[1]===600&&($image['mime']??'')==='image/png','cover_bytes_changed');
 }
 ob_start();try{require getcwd().'/config.php';require_once getcwd().'/services/ChannelPostRuntime.php';require_once getcwd().'/services/ChannelPostEntryService.php';require_once getcwd().'/services/MaxTransport.php';}finally{ob_end_clean();}
 $identity=ChannelPostRuntime::identity('max');insist(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===BOT_KEY,'configured_bot_changed');
 $registry=new ChannelPostRegistryService();$channel=currentChannel($registry);insist(((new ChannelPostButtonTransport())->verifyChannel($channel)['state']??'')==='ready','channel_rights_not_ready');
 if(OP_MODE==='preview'){
  $pdo=ConversationDb::connection();$pdo->exec('START TRANSACTION READ ONLY');
  try{
   $recent=readRecent();$out['ready_rows']=[];
   foreach($rows as $row){
    $matches=[];foreach($recent as $m)if(is_array($m)&&messageMatches($m,$row['text']))$matches[]=$m;
    insist(count($matches)===0,'existing_same_text_requires_review');
    $out['ready_rows'][]=['key'=>$row['key'],'text_sha256'=>$row['text_sha256'],'cover_sha256'=>$row['cover_sha256'],'width'=>600,'height'=>600,'existing_exact_text_count'=>0];
   }
   $out['ok']=true;$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['server_utc']=gmdate('c');
  }finally{$pdo->rollBack();}
  echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit(0);
 }
 insist(!is_link(dirname(OPERATION_DIR))&&!is_link(OPERATION_DIR),'invalid_operation_directory');
 if(!is_dir(OPERATION_DIR))insist(mkdir(OPERATION_DIR,0700,true),'invalid_operation_directory');
 insist(realpath(OPERATION_DIR)===OPERATION_DIR&&is_dir(OPERATION_DIR)&&chmod(OPERATION_DIR,0700),'invalid_operation_directory');
 insist(!is_link(OPERATION_DIR.'/publication-receipt.json'),'invalid_receipt_path');$lock=fopen(OPERATION_DIR.'/publication-receipt.json','c+');insist($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'publication_operation_busy');
 $raw=stream_get_contents($lock);$state=$raw===''?['operation'=>OPERATION,'channel_id'=>TARGET_CHANNEL,'posts'=>[]]:json_decode($raw,true,64,JSON_THROW_ON_ERROR);insist(is_array($state)&&($state['operation']??'')===OPERATION&&($state['channel_id']??'')===TARGET_CHANNEL,'receipt_scope_changed');
 foreach($rows as $row){publishOne($row,$lock,$state,$registry,$out);usleep(600000);}
 $out['ok']=true;$out['server_utc']=gmdate('c');$out['installed_sha']=EXPECTED_INSTALLED_SHA;
}catch(Throwable $e){
 $known=['payload_decode_failed','payload_scope_changed','invalid_operation_directory','invalid_execution_context','batch_size_changed','prepared_row_changed','configured_bot_changed','installed_source_changed','channel_configuration_changed','provider_channel_identity_changed','channel_rights_not_ready','invalid_receipt_path','publication_operation_busy','receipt_scope_changed','receipt_write_failed','receipt_sync_failed','prepared_body_changed','saved_post_changed','invalid_saved_mid','post_read_failed','recent_read_failed','duplicate_text_requires_review','existing_same_text_requires_review','previous_post_outcome_unknown','upload_outcome_unknown','upload_endpoint_failed','upload_host_requires_review','cover_bytes_changed','cover_write_failed','cover_upload_failed','uploaded_photo_token_missing','post_outcome_unknown','created_mid_invalid','published_readback_mismatch','registry_observation_failed','unique_button_or_content_unverified','canonical_post_url_missing','canonical_entry_resolution_mismatch'];
 $out['error']=in_array($e->getMessage(),$known,true)?$e->getMessage():'bounded_publication_failed';
}
if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);

