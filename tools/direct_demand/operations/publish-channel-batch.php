<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
const TARGET_CHANNEL='-80492244209683';
const TARGET_PUBLIC='https://max.ru/channel_anytour';
const BOT_KEY='id9704048781_1_bot';
const OPERATION='channel-batch-20261025-v1';
const OPERATION_DIR='/tmp/anytour-channel-batch-20261025-v1';
$out=['ok'=>false,'operation'=>OPERATION,'target'=>TARGET_PUBLIC,'post_write_attempts'=>0,'upload_attempts'=>0,'posts'=>[]];
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
 $file=OPERATION_DIR.'/'.$key.'.jpg';$bytes=base64_decode($row['cover_jpeg_base64'],true);insist(is_string($bytes)&&hash('sha256',$bytes)===$row['cover_sha256'],'cover_bytes_changed');
 insist(file_put_contents($file,$bytes,LOCK_EX)===strlen($bytes),'cover_write_failed');chmod($file,0600);
 $raw='';$ch=curl_init($endpoint['url']);curl_setopt_array($ch,MaxTlsConfig::strictCurlOptions()+[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,CURLOPT_POSTFIELDS=>['data'=>new CURLFile($file,'image/jpeg','cover.jpg')],CURLOPT_WRITEFUNCTION=>static function($c,string $s)use(&$raw):int{if(strlen($raw)+strlen($s)>1048576)return 0;$raw.=$s;return strlen($s);}]);
 $sent=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=$sent===false||curl_errno($ch)!==0;curl_close($ch);unlink($file);
 $data=$error?null:json_decode($raw,true,32);insist(!$error&&$http>=200&&$http<300&&is_array($data),'cover_upload_failed');$token=photoToken($data);insist($token!=='','uploaded_photo_token_missing');
 $id=null;if(is_array($data['photos']??null)&&count($data['photos'])===1){$candidate=(string)array_key_first($data['photos']);if(ctype_digit($candidate))$id=$candidate;}
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
  if($matches){insist(is_array($saved)&&!empty($saved['post_started'])&&is_string($saved['uploaded_photo_id']??null)&&messageMatches($matches[0],$text,$saved['uploaded_photo_id']),'existing_same_text_requires_review');$message=$matches[0];}
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
 insist(preg_match('~\Ahttps://max\.ru/channel_anytour/[A-Za-z0-9_-]{1,100}\z/D',$url)===1,'canonical_post_url_missing');
 insist(is_array($resolved)&&(int)$resolved['publication_id']===(int)$p['publication_id']&&(int)$resolved['version_id']===(int)$p['version_id']&&$resolved['snapshot']['text']===$text&&($resolved['snapshot']['post_url']??'')===$url&&$resolved['source_key']==='max_anytour_msk1','canonical_entry_resolution_mismatch');
 $receipt=['key'=>$key,'post_url'=>$url,'message_id'=>$mid,'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'start_payload'=>(string)$p['start_payload'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'text_sha256'=>$row['text_sha256'],'cover_sha256'=>$row['cover_sha256'],'photo_id'=>$state['posts'][$key]['photo_id']??null,'body_photo_button_verified'=>true,'canonical_token_resolved'=>true,'real_customer_entry_tested'=>false,'yclid_live_tested'=>false];
 $state['posts'][$key]['state']='verified';$state['posts'][$key]['receipt']=$receipt;durable($lock,$state);$out['posts'][]=$receipt;
}
$lock=null;
try{
 insist(PHP_SAPI==='cli'&&is_dir(OPERATION_DIR)&&!is_link(OPERATION_DIR),'invalid_execution_context');
 $key=openssl_pkey_get_private(file_get_contents(OPERATION_DIR.'/envelope-key.pem'));insist($key!==false,'envelope_key_missing');
 $rows=[];$envelopes=json_decode(base64_decode(ENVELOPES_BASE64,true),true,16,JSON_THROW_ON_ERROR);insist(is_array($envelopes)&&count($envelopes)===3,'batch_size_changed');
 $expectedKeys=['alma_cam_ranh','EG_family','phuket_five_naiyang_kata_karon'];
 foreach($envelopes as $i=>$e){
  insist(($e['operation']??'')===OPERATION&&($e['key']??'')===$expectedKeys[$i],'envelope_scope_changed');$aes='';
  insist(openssl_private_decrypt(base64_decode($e['wrapped_key'],true),$aes,$key,OPENSSL_PKCS1_OAEP_PADDING)&&strlen($aes)===32,'envelope_unwrap_failed');
  $raw=openssl_decrypt(base64_decode($e['ciphertext'],true),'aes-256-gcm',$aes,OPENSSL_RAW_DATA,base64_decode($e['nonce'],true),base64_decode($e['tag'],true));
  insist(is_string($raw)&&hash('sha256',$raw)===$e['plaintext_sha256'],'envelope_decryption_failed');$plain=json_decode($raw,true,16,JSON_THROW_ON_ERROR);$row=$plain['row']??null;
  insist(($plain['operation']??'')===OPERATION&&($plain['channel_id']??'')===TARGET_CHANNEL&&is_array($row)&&$row['key']===$expectedKeys[$i]&&hash('sha256',$row['text'])===$row['text_sha256']&&str_contains($row['text'],'✈️ Рассчитать на мои даты')&&mb_strlen($row['text'],'UTF-8')<2000,'prepared_row_changed');$rows[]=$row;
 }
 ob_start();try{require getcwd().'/config.php';require_once getcwd().'/services/ChannelPostRuntime.php';require_once getcwd().'/services/ChannelPostEntryService.php';require_once getcwd().'/services/MaxTransport.php';}finally{ob_end_clean();}
 $identity=ChannelPostRuntime::identity('max');insist(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===BOT_KEY,'configured_bot_changed');

 $registry=new ChannelPostRegistryService();$channel=currentChannel($registry);
 $raw=file_get_contents(OPERATION_DIR.'/publication-receipt.json');$state=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
 $recent=readRecent();$out['recent_count']=count($recent);$out['state_summary']=[];
 foreach($rows as $row){
  $saved=$state['posts'][$row['key']]??[];$out['state_summary'][]=['key'=>$row['key'],'post_started'=>!empty($saved['post_started']),'mid_known'=>!empty($saved['mid']),'upload_token_saved'=>!empty($saved['upload_token']),'uploaded_photo_id'=>$saved['uploaded_photo_id']??null];$comparisons=[];
  foreach($recent as $m){if(!is_array($m))continue;
   $text=(string)($m['body']['text']??'');$attachments=(array)($m['body']['attachments']??[]);$images=array_values(array_filter($attachments,static fn($a)=>is_array($a)&&($a['type']??'')==='image'));
   if($text!==$row['text']&&count($images)===0)continue;
   $image=$images[0]['payload']??[];
   $comparisons[]=['mid'=>(string)($m['body']['mid']??''),'timestamp'=>$m['timestamp']??null,'recipient_channel'=>($m['recipient']['chat_type']??'')==='channel','recipient_id_equal'=>(string)($m['recipient']['chat_id']??'')===TARGET_CHANNEL,'text_equal'=>$text===$row['text'],'text_length'=>mb_strlen($text),'expected_length'=>mb_strlen($row['text']),'text_sha256'=>hash('sha256',$text),'image_count'=>count($images),'image_payload_keys'=>array_keys($image),'photo_id_type'=>get_debug_type($image['photo_id']??null),'photo_id'=>(string)($image['photo_id']??''),'photo_id_equals_uploaded'=>isset($saved['uploaded_photo_id'])&&(string)($image['photo_id']??'')===(string)$saved['uploaded_photo_id'],'token_equals_uploaded'=>isset($saved['upload_token'])&&is_string($image['token']??null)&&hash_equals($saved['upload_token'],$image['token']),'attachment_types'=>array_column($attachments,'type')];
  }
  $out['comparisons'][$row['key']]=$comparisons;
 }
 $out['ok']=true;
}catch(Throwable $e){$out['error']='bounded_readback_probe_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
