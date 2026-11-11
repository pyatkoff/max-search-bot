<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
function contextNeed(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function contextPhoto(array $snapshot):string{$a=$snapshot['attachments']??[];contextNeed(count($a)===1&&($a[0]['type']??'')==='image','expected_one_photo');$id=(string)($a[0]['photo_id']??$a[0]['file_unique_id']??'');contextNeed($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/D',$id)===1,'photo_id_missing');return ($a[0]['provider']??'').':'.$id;}
$out=['operation'=>'monitor-price-six-context-20261111-v1','ok'=>false,'db_writes'=>0,'message_writes'=>0,'supplier_calls'=>0,'bindings'=>[],'deferred'=>[]];
try{
 contextNeed(PHP_SAPI==='cli'&&trim((string)shell_exec('git rev-parse HEAD'))===EXPECTED_INSTALLED_SHA,'installed_source_changed');
 contextNeed(trim((string)shell_exec('git status --porcelain --untracked-files=no'))==='','tracked_tree_changed');
 $pins=json_decode(base64_decode(APPROVED_CONTEXT_PINS_B64,true),true,32,JSON_THROW_ON_ERROR);
 foreach($pins as $file=>$blob)contextNeed(trim((string)shell_exec('git hash-object -- '.escapeshellarg($file)))===$blob,'publishing_surface_changed');
 $raw=base64_decode(APPROVED_CONTEXT_INPUT_B64,true);contextNeed(is_string($raw)&&hash('sha256',$raw)===APPROVED_CONTEXT_INPUT_SHA256,'context_input_changed');$input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
 contextNeed(($input['operation']??'')===$out['operation']&&array_column($input['rows'],'tourvisor_hotel_id')===[1183,117935,27426,13869,1151,1173],'context_scope_changed');
 ob_start();try{require getcwd().'/config.php';require_once getcwd().'/services/ChannelPostRuntime.php';require_once getcwd().'/services/ChannelPostAdminService.php';require_once getcwd().'/services/ChannelPostEntryService.php';require_once getcwd().'/integrations/ChannelPostApiTransport.php';require_once getcwd().'/services/ChannelPostButtonTransport.php';}finally{ob_end_clean();}
 $pdo=ConversationDb::connection();$registry=new ChannelPostRegistryService($pdo);
 $targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];
 foreach($targets as $platform=>$t){$identity=ChannelPostRuntime::identity($platform);contextNeed(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===$t['bot'],'configured_bot_changed');$channel=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);contextNeed(is_array($channel)&&(int)$channel['id']===$t['id']&&$channel['source_key']===$t['source']&&(int)$channel['config_version']===2&&$channel['button_text']==='✈️ Узнать стоимость тура','channel_configuration_changed');contextNeed(((new ChannelPostButtonTransport())->verifyChannel($channel)['state']??'')==='ready','channel_rights_not_ready');}
 foreach($input['rows'] as $r){try{
  contextNeed(preg_match('/\A[a-z0-9-]{1,150}\z/D',$r['original_publication_operation'])===1&&preg_match('/\A[a-z0-9-]{1,150}\z/D',$r['logical_id'])===1,'canonical_path_invalid');
  $entry=$r;$entry['channels']=[];
  foreach($targets as $platform=>$t){
   $root='/var/www/anytoour/data/channel-post-operations';$dir=$root.'/'.$r['original_publication_operation'];$path=$dir.'/'.$platform.'-'.$r['logical_id'].'-approved.json';
   contextNeed(!is_link($root)&&!is_link($dir)&&!is_link($path)&&is_file($path)&&realpath($path)===$path&&filesize($path)<=1048576,'original_receipt_missing');
   $original=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);$proof=$original['receipt']??[];
   contextNeed(($original['state']??'')==='confirmed'&&($original['text_sha256']??'')===$r['original_text_sha256']&&($original['photo_sha256']??'')===$r['cover_sha256']&&($proof['logical_id']??'')===$r['logical_id']&&(int)($proof['hotel_id']??0)===$r['hotel_id']&&($proof['platform']??'')===$platform&&($proof['post_url']??'')===$r['posts'][$platform]&&($proof['body_photo_button_verified']??false)===true,'canonical_receipt_pair_mismatch');
   $p=$registry->publication((int)$proof['publication_id']);contextNeed(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$t['bot']&&$p['channel_id']===$t['channel']&&$p['source_key']===$t['source']&&$p['state']==='sent'&&$p['external_message_id']===(string)$proof['message_id'],'current_publication_changed');
   $s=$p['snapshot'];contextNeed(($s['post_url']??'')===$r['posts'][$platform]&&hash('sha256',$s['text']??'')===$r['expected_text_sha256'][$platform],'current_caption_changed');
   if($platform==='max'){$mid=(string)$p['external_message_id'];contextNeed(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/D',$mid)===1,'message_id_invalid');$response=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$mid,[],null);contextNeed(empty($response['transport_error'])&&$response['http']===200&&is_array($response['data']),'max_read_failed');$live=ChannelPostIncomingAdapter::maxSnapshot($response['data']);contextNeed(is_array($live)&&($live['text']??'')===$s['text']&&contextPhoto($live)===contextPhoto($s)&&($live['post_url']??'')===$s['post_url']&&ChannelPostIncomingAdapter::containsOnlyButton($live,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура'),'max_current_readback_changed');}
   $entry['channels'][$platform]=['publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'message_id'=>$p['external_message_id'],'post_url'=>$s['post_url'],'current_text'=>$s['text'],'current_text_sha256'=>hash('sha256',$s['text']),'photo_signature'=>contextPhoto($s),'original_text_sha256'=>$original['text_sha256']];
  }
  $out['bindings'][]=$entry;
 }catch(Throwable $e){$out['deferred'][]=['tourvisor_hotel_id'=>$r['tourvisor_hotel_id'],'reason'=>preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'context_read_failed'];}}
 $out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['captured_at']=gmdate('c');$out['input_sha256']=APPROVED_CONTEXT_INPUT_SHA256;$out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())?$e->getMessage():'context_preflight_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($out['ok']?0:2);
