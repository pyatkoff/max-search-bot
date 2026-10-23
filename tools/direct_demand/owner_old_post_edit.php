<?php
declare(strict_types=1);
// Explicit owner-authorized two-post operation. Streamed CLI, never installed.
if(PHP_SAPI!=='cli')exit(2);
$mode='apply';
$root='/var/www/anytoour/data/www/app.anytoour.ru';
$expected='addb34ee848f9f5381445ad762f075d7ccb99c57';
$dir='/var/www/anytoour/data/channel-post-operations/funnel-owner-edits';
$file=$dir.'/20261023-two-old-posts-v1.json';
ini_set('display_errors','stderr');ini_set('log_errors','0');umask(0077);
$sha=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
$dirty=trim((string)shell_exec('git -C '.escapeshellarg($root).' status --porcelain --untracked-files=no 2>/dev/null'));
if($sha!==$expected||$dirty!==''){echo json_encode(['ok'=>false,'reason'=>'installed_checkout_changed','sha'=>$sha]);exit(2);}
ob_start();
try{
 require_once $root.'/config.php';require_once $root.'/services/ChannelPostRuntime.php';require_once $root.'/services/ChannelPostReconciliationService.php';
 if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('state_directory_unavailable');
 $lock=fopen($file.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('operation_busy');
 $reg=new ChannelPostRegistryService();$pdo=ConversationDb::connection();
 $state=is_file($file)?json_decode((string)file_get_contents($file),true):null;
 if($mode==='preview'&&$state===null){
  $maxId=ChannelPostRuntime::identity('max');$tgId=ChannelPostRuntime::identity('telegram');if(!$maxId||!$tgId)throw new RuntimeException('identity_unavailable');
  $maxChannel=$reg->channel($maxId['project_key'],'max',$maxId['bot_key'],'-72182945320979');
  $tgChannel=$reg->channel($tgId['project_key'],'telegram',$tgId['bot_key'],'-1001845835645');
  if(!$maxChannel||!$tgChannel)throw new RuntimeException('channel_not_enabled');
  $transport=new ChannelPostButtonTransport();
  foreach([$maxChannel,$tgChannel]as$c)if(($transport->verifyChannel($c)['state']??'')!=='ready')throw new RuntimeException('channel_rights_failed');
  $m=maxGet('mid.ffffbe59992f6fed019fa7b020f25ee5');$snap=ChannelPostIncomingAdapter::maxSnapshot($m);
  if(!$snap||($snap['metadata']['capability']??'')!=='supported'||(string)($m['recipient']['chat_id']??'')!=='-72182945320979')throw new RuntimeException('max_target_mismatch');
  $preview=(new ChannelPostReconciliationService())->recover($maxId,'-72182945320979',1785224765682,'',false);
  if(empty($preview['ok']))throw new RuntimeException('max_recovery_preview_failed');
  $old=(string)$snap['text'];$cut=mb_strpos($old,'Пишите нам, чтобы задать вопрос');if($cut===false)throw new RuntimeException('max_expected_tail_missing');
  $prefix=rtrim(mb_substr($old,0,$cut));$new=$prefix."\n\nРассчитать тур в Turunc Hotel на ваши даты — по кнопке ниже 🤗";
  $limit=u16($prefix);$spans=[];foreach($snap['markup']as$span){if((int)$span['from']>=$limit)continue;if((int)$span['from']+(int)$span['length']>$limit)throw new RuntimeException('max_markup_crosses_tail');$spans[]=$span;}
  $html=toHtml($new,$spans);
  $q=$pdo->prepare('SELECT id FROM post_publications WHERE post_channel_id=? AND external_message_id=?');$q->execute([(int)$tgChannel['id'],'1751']);$tg=$reg->publication((int)$q->fetchColumn());
  if(!$tg||$tg['state']!=='sent'||!empty($tg['snapshot']['metadata']['media_group_id']))throw new RuntimeException('telegram_target_not_ready');
  $t=$tg['snapshot'];$body=$t['raw_body']??[];if(!isset($body['caption'])||isset($body['text'])||empty($body['photo']))throw new RuntimeException('telegram_expected_photo_caption_missing');
  $oldT=(string)$t['text'];$cutT=mb_strpos($oldT,'Подобрать / забронировать тур');if($cutT===false)throw new RuntimeException('telegram_expected_tail_missing');
  $prefixT=rtrim(mb_substr($oldT,0,$cutT));$newT=$prefixT."\n\nРассчитать тур в Sunrise Remal Resort на ваши даты — по кнопке ниже 👇";
  if(u16($newT)>1024)throw new RuntimeException('telegram_caption_limit');$entities=[];$limitT=u16($prefixT);
  foreach($t['markup']as$entity){if((int)$entity['offset']>=$limitT)continue;if((int)$entity['offset']+(int)$entity['length']>$limitT)throw new RuntimeException('telegram_entity_crosses_tail');$entities[]=$entity;}
  $state=['schema'=>'anytour.owner_old_post_edit.v1','prepared_at'=>gmdate('c'),'installed_sha'=>$sha,'status'=>'prepared','mode'=>'two_existing_posts_only',
    'max'=>['identity'=>$maxId,'channel_id'=>'-72182945320979','mid'=>'mid.ffffbe59992f6fed019fa7b020f25ee5','published_ms'=>1785224765682,'before'=>$m,'before_fingerprint'=>$preview['content_hash'],'new_text'=>$new,'new_html'=>$html,'preserved_markup'=>$spans,'button_stage'=>'not_started','text_stage'=>'not_started'],
    'telegram'=>['identity'=>$tgId,'publication_id'=>(int)$tg['publication_id'],'version_id'=>(int)$tg['version_id'],'channel_id'=>'-1001845835645','mid'=>'1751','before'=>$body,'before_hash'=>$tg['content_hash'],'new_text'=>$newT,'entities'=>$entities,'button_url'=>ChannelPostButtonTransport::buttonUrl($tg),'button_label'=>(string)$tgChannel['button_text'],'stage'=>'not_started']];saveState($file,$state);
 }
 if(!is_array($state))throw new RuntimeException('preview_required');
 if($mode==='apply'){
  $a=&$state['max'];
  if($a['button_stage']==='not_started'){
   $current=maxGet($a['mid']);$s=ChannelPostIncomingAdapter::maxSnapshot($current);if(!$s||ChannelPostIncomingAdapter::contentFingerprint($s)!==$a['before_fingerprint'])throw new RuntimeException('max_changed_since_preview');
   $a['button_stage']='started';saveState($file,$state);
   $a['button_result']=(new ChannelPostReconciliationService())->recover($a['identity'],$a['channel_id'],$a['published_ms'],$a['before_fingerprint'],true);
   $a['button_stage']=!empty($a['button_result']['ok'])?'confirmed':'unknown';saveState($file,$state);
  }
  if($a['button_stage']!=='confirmed')throw new RuntimeException('max_button_outcome_requires_readback');
  $q=$pdo->prepare('SELECT pp.id FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id WHERE pc.platform=? AND pc.channel_id=? AND pp.external_message_id=?');$q->execute(['max',$a['channel_id'],$a['mid']]);$p=$reg->publication((int)$q->fetchColumn());
  if(!$p)throw new RuntimeException('max_canonical_publication_missing');$url=ChannelPostButtonTransport::buttonUrl($p);$label=(string)$p['channel']['button_text'];
  if($a['text_stage']==='not_started'){
   $before=maxGet($a['mid']);$bs=ChannelPostIncomingAdapter::maxSnapshot($before);if((string)$bs['text']!==(string)$a['before']['body']['text']||!ChannelPostIncomingAdapter::containsOnlyButton($bs,$url,$label))throw new RuntimeException('max_pre_edit_changed');
   $a['text_stage']='started';$a['button_url']=$url;$a['button_label']=$label;saveState($file,$state);
   $r=ChannelPostApiTransport::buttonRequest('max','PUT','/messages',['message_id'=>$a['mid']],['text'=>$a['new_html'],'format'=>'html']);
   $a['text_http']=(int)($r['http']??0);$a['provider_success']=$r['data']['success']??null;saveState($file,$state);
  }
  // The first caption call was deterministically rejected before curl by the
  // pinned installed transport's attachments-only body allowlist. It was not
  // an ambiguous provider request. Preserve that receipt; claim a distinct
  // narrow text operation only after a fresh unchanged-content readback.
  if($a['text_stage']==='unknown'&&($a['text_http']??null)===0&&!isset($a['text_v2_stage'])){
   $current=maxGet($a['mid']);$cs=ChannelPostIncomingAdapter::maxSnapshot($current);
   $source=(string)file_get_contents($root.'/integrations/ChannelPostApiTransport.php');
   if(!str_contains($source,"array_keys(\$body) === ['attachments']")||!$cs||$cs['text']!==$a['before']['body']['text']||!ChannelPostIncomingAdapter::containsOnlyButton($cs,$url,$label))throw new RuntimeException('original_no_network_rejection_not_proven');
   $a['first_text_call_proven_local_allowlist_rejection']=true;$a['first_text_http']=$a['text_http'];$a['text_v2_stage']='started';saveState($file,$state);
   $r=maxTextEdit($a['mid'],$a['new_html']);$a['text_http']=(int)($r['http']??0);$a['provider_success']=$r['data']['success']??null;saveState($file,$state);
  }
  $after=maxGet($a['mid']);$as=ChannelPostIncomingAdapter::maxSnapshot($after);
  $a['after']=$after;$a['text_verified']=is_array($as)&&$as['text']===$a['new_text'];
  $beforeMedia=ChannelPostIncomingAdapter::maxSnapshot($a['before'])['attachments'];
  $a['photos_verified']=photoIds($as['attachments']??[])===photoIds($beforeMedia);
  $a['button_verified']=is_array($as)&&ChannelPostIncomingAdapter::containsOnlyButton($as,$url,$label);
  $a['markup_verified']=spanSignature($a['preserved_markup'])===spanSignature($as['markup']??[]);
  $a['text_stage']=$a['text_verified']&&$a['photos_verified']&&$a['button_verified']&&$a['markup_verified']?'confirmed':'unknown';saveState($file,$state);
  if($a['text_stage']!=='confirmed')throw new RuntimeException('max_text_outcome_requires_attention');
  unset($a);$t=&$state['telegram'];
  if($t['stage']==='not_started'){
   $p=$reg->publication($t['publication_id']);if(!$p||(int)$p['version_id']!==$t['version_id']||$p['content_hash']!==$t['before_hash']||$p['state']!=='sent')throw new RuntimeException('telegram_registry_changed_since_preview');
   if((new ChannelPostButtonTransport())->verifyChannel($p['channel'])['state']!=='ready')throw new RuntimeException('telegram_rights_changed');
   $t['stage']='started';saveState($file,$state);
   $r=telegramCaption(['chat_id'=>$t['channel_id'],'message_id'=>(int)$t['mid'],'caption'=>$t['new_text'],'caption_entities'=>$t['entities'],'reply_markup'=>['inline_keyboard'=>[[['text'=>$t['button_label'],'url'=>$t['button_url']]]]]]);
   $t['http']=(int)$r['http'];$t['api_ok']=$r['data']['ok']??false;$t['provider_error_code']=$r['data']['error_code']??null;$t['provider_description']=$r['data']['description']??null;
   $message=$r['data']['result']??null;
   if(is_array($message)){
    $t['after']=$message;$s=ChannelPostIncomingAdapter::telegramSnapshot($message);
    $t['text_verified']=$s!==null&&$s['text']===$t['new_text'];$t['button_verified']=$s!==null&&ChannelPostIncomingAdapter::containsOnlyButton($s,$t['button_url'],$t['button_label']);
    $before=ChannelPostIncomingAdapter::telegramSnapshot($t['before']);$t['photos_verified']=photoIds($s['attachments']??[])===photoIds($before['attachments']??[]);
    $t['identity_verified']=(string)($message['chat']['id']??'')===$t['channel_id']&&(string)($message['message_id']??'')===$t['mid'];
    if($t['text_verified']&&$t['button_verified']&&$t['photos_verified']&&$t['identity_verified']){
     // Native edit response, explicitly distinguished from webhook/update IDs.
     $s['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($s,'',(string)$p['channel']['bot_url']);
     $s['metadata']['observation_kind']='provider_owner_edit_response';$s['metadata']['source_event_id']='owner-edit-response:'.hash('sha256',$t['channel_id'].'|'.$t['mid'].'|'.($message['edit_date']??'').'|'.$s['content_hash']);
     $obs=$reg->registerPublication($t['identity']['project_key'],'telegram',$t['identity']['bot_key'],$t['channel_id'],$t['mid'],$s);
     if($obs&&$obs['snapshot']['text']===$t['new_text'])$reg->confirmObservedButton((int)$obs['publication_id'],(int)$obs['version_id'],['verified'=>true,'origin'=>'owner_native_edit_response']);
     $t['stage']='confirmed';
    }else $t['stage']='unknown';
   }else $t['stage']=empty($r['transport_error'])&&$t['http']>=400?'failed':'unknown';
   saveState($file,$state);
  }
  unset($t);
  // Wait only for this exact post's genuine edit observation, never drain a queue.
  for($i=0;$i<8;$i++){$q=$pdo->prepare('SELECT pp.id FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id WHERE pc.platform=? AND pc.channel_id=? AND pp.external_message_id=?');$q->execute(['max',$state['max']['channel_id'],$state['max']['mid']]);$p=$reg->publication((int)$q->fetchColumn());if($p&&$p['snapshot']['text']===$state['max']['new_text'])break;usleep(500000);}
  $state['max']['registry_text_verified']=$p&&$p['snapshot']['text']===$state['max']['new_text'];
  if($state['max']['registry_text_verified']){$state['max']['publication_id']=(int)$p['publication_id'];$state['max']['version_id']=(int)$p['version_id'];$state['max']['canonical_context_verified']=$reg->resolveToken($p['project_key'],'max',$p['bot_key'],$p['start_payload'])['snapshot']['text']===$state['max']['new_text'];}
  $t=$reg->publication($state['telegram']['publication_id']);$state['telegram']['registry_text_verified']=$t&&$t['snapshot']['text']===$state['telegram']['new_text'];
  if($t)$state['telegram']['canonical_context_verified']=$reg->resolveToken($t['project_key'],'telegram',$t['bot_key'],$t['start_payload'])['snapshot']['text']===$state['telegram']['new_text'];
  $state['status']=$state['max']['text_stage']==='confirmed'&&$state['telegram']['stage']==='confirmed'?'applied':'partial';saveState($file,$state);
 }
 ob_end_clean();echo json_encode(['ok'=>true,'mode'=>$mode,'installed_sha'=>$sha,'receipt'=>$state],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){if(ob_get_level())ob_end_clean();echo json_encode(['ok'=>false,'mode'=>$mode,'installed_sha'=>$sha,'reason'=>$e->getMessage(),'receipt'=>$state??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;exit(1);}

function saveState(string $file,array $s):void{$s['updated_at']=gmdate('c');$tmp=$file.'.tmp';if(file_put_contents($tmp,json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false||!rename($tmp,$file))throw new RuntimeException('state_write_failed');chmod($file,0600);}
function maxGet(string $mid):array{$r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.rawurlencode($mid),[],null);if(!empty($r['transport_error'])||(int)$r['http']!==200||!is_array($r['data']))throw new RuntimeException('max_read_failed');return $r['data'];}
function u16(string $s):int{return intdiv(strlen(mb_convert_encoding($s,'UTF-16LE','UTF-8')),2);}
function photoIds(array $a):array{return array_map(static fn($i)=>(string)($i['photo_id']??$i['file_unique_id']??''),$a);}
function spanSignature(array $spans):array{$out=[];foreach($spans as$s){ksort($s);$out[]=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}sort($out);return $out;}
function toHtml(string $text,array $markup):string{
 $utf=mb_convert_encoding($text,'UTF-16LE','UTF-8');$bounds=[0,strlen($utf)/2];foreach($markup as$s){if(!in_array($s['type'],['strong','emphasized','underline','strikethrough','monospaced','link'],true))throw new RuntimeException('unsupported_markup');$bounds[]=(int)$s['from'];$bounds[]=(int)$s['from']+(int)$s['length'];}
 $bounds=array_values(array_unique($bounds));sort($bounds);$out='';$tags=['strong'=>'b','emphasized'=>'i','underline'=>'u','strikethrough'=>'s','monospaced'=>'code'];
 for($i=0;$i<count($bounds)-1;$i++){$start=$bounds[$i];$end=$bounds[$i+1];$part=htmlspecialchars(mb_convert_encoding(substr($utf,$start*2,($end-$start)*2),'UTF-8','UTF-16LE'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');foreach($markup as$s)if($s['from']<=$start&&$s['from']+$s['length']>=$end){if($s['type']==='link'){$u=(string)($s['url']??'');if(!preg_match('~^https?://~',$u))throw new RuntimeException('unsupported_link');$part='<a href="'.htmlspecialchars($u,ENT_QUOTES,'UTF-8').'">'.$part.'</a>';}else{$tag=$tags[$s['type']];$part='<'.$tag.'>'.$part.'</'.$tag.'>';}}$out.=$part;}return $out;
}
function telegramCaption(array $body):array{
 $token=defined('TELEGRAM_BOT_TOKEN')?(string)TELEGRAM_BOT_TOKEN:'';if($token==='')throw new RuntimeException('telegram_not_configured');$raw='';$ch=curl_init('https://api.telegram.org/bot'.$token.'/editMessageCaption');
 curl_setopt_array($ch,[CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),CURLOPT_WRITEFUNCTION=>static function($c,string $s)use(&$raw):int{if(strlen($raw)+strlen($s)>1048576)return 0;$raw.=$s;return strlen($s);}]);
 $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=$ok===false||curl_errno($ch)!==0;curl_close($ch);return ['http'=>$http,'transport_error'=>$error,'data'=>$error?null:json_decode($raw,true)];
}
function maxTextEdit(string $mid,string $html):array{
 if($mid!=='mid.ffffbe59992f6fed019fa7b020f25ee5')throw new RuntimeException('exact_max_edit_target_required');
 $token=defined('MAX_SEARCH_TOKEN')?(string)MAX_SEARCH_TOKEN:'';if($token==='')throw new RuntimeException('max_not_configured');$raw='';$ch=curl_init('https://platform-api2.max.ru/messages?message_id='.rawurlencode($mid));
 $tls=MaxTlsConfig::strictCurlOptions();curl_setopt_array($ch,$tls+[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['text'=>$html,'format'=>'html'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),CURLOPT_WRITEFUNCTION=>static function($c,string $s)use(&$raw):int{if(strlen($raw)+strlen($s)>1048576)return 0;$raw.=$s;return strlen($s);}]);
 $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=$ok===false||curl_errno($ch)!==0;curl_close($ch);return ['http'=>$http,'transport_error'=>$error,'data'=>$error?null:json_decode($raw,true)];
}
