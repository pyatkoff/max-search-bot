<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-monitor-20261105-context13-approved-manual1','mode'=>'readonly_current_context_before_any_new_quote','ok'=>false,'db_writes'=>0,'gateway_calls'=>0,'provider_post_reads'=>0,'provider_channel_rights_checks'=>0,'message_sends'=>0,'price_edits'=>0,'bindings'=>[],'deferred'=>[]];
function need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function photoSignature(array $s):string{$a=$s['attachments']??[];need(count($a)===1&&($a[0]['type']??'')==='image','expected_one_existing_image');$id=(string)($a[0]['photo_id']??$a[0]['file_unique_id']??'');need($id!==''&&preg_match('/\\A[A-Za-z0-9_-]+\\z/',$id)===1,'stable_photo_id_missing');return (string)($a[0]['provider']??'').':'.$id;}
function contextRole13(array $x):bool{
 $h=$x['hotel']??[];$id=(int)($h['local_tv_id']??0);$country=(int)($h['expected_country_id']??0);
 if($id<1||!in_array($country,[1,4],true)||empty($h['observations'])||!in_array($h['identity_provenance']??'',['accepted_native_catalog_read','accepted_typed_bridge'],true))return false;
 if(($x['kind']??'')==='accepted_legacy_frame_exact_current_refresh'){
  $r=[240=>[80,1],381=>[null,1],46672=>[2432,4]];if(!isset($r[$id]))return false;$f=$x['prior_frame']??[];$b=$f['binding']??[];
  return ($f['capture_source_sha']??'')==='25a2d5e31aec7243b45c47e836a544e659e28b1d'&&(int)($b['native_hotel_id']??0)===$id&&($b['publisher_hotel_id']??null)===$r[$id][0]&&(int)($b['expected_country_id']??0)===$country&&$country===$r[$id][1]&&isset($b['contexts']['max'],$b['contexts']['telegram']);
 }
 $r=$x['original_manifest_row']??[];$expected=$h['identity_provenance']==='accepted_typed_bridge'?(int)($h['anytour_id']??0):$id;
 return ($x['kind']??'')==='original_publisher_and_latest_confirmed_caption'&&$expected>0&&(int)($x['publisher_hotel_id']??0)===$expected&&(int)($r['hotel_id']??0)===$expected&&is_string($r['text']??null)&&hash('sha256',$r['text'])===($r['text_sha256']??'')&&preg_match('/\\A[0-9a-f]{64}\\z/D',(string)($r['photo_sha256']??''))===1&&!empty($r['logical_id']);
}
function contextInput13():array{$raw=base64_decode(APPROVED_CONTEXT_INPUT_B64,true);need(is_string($raw)&&hash_equals(APPROVED_CONTEXT_INPUT_SHA256,hash('sha256',$raw)),'context_input_bytes_changed');$d=json_decode($raw,true,256,JSON_THROW_ON_ERROR);need(($d['source_run']??0)===41976677950&&($d['price_read13_run']??0)===41978466494&&count($d['items'])<=56&&count($d['items'])>0,'new_source_context_scope_changed');foreach($d['items'] as $x)need(contextRole13($x),'new_native_publisher_context_roles_invalid');return $d;}
if(getenv('ANYTOUR_CONTEXT13_NEW_BINDING_CASES')==='1'){
 $d=contextInput13();$found=null;foreach($d['items'] as $x)if($x['kind']==='original_publisher_and_latest_confirmed_caption'){$found=$x;break;}need(is_array($found),'new_actual_standard_context_required');
 $bad=$found;$bad['hotel']['local_tv_id']=1422;need(!contextRole13($bad),'foreign_native_context_accepted');
 $bad=$found;$bad['publisher_hotel_id']=0;need(!contextRole13($bad),'publisher_role_overwrite_accepted');
 $bad=$found;$bad['original_manifest_row']['text'].='x';need(!contextRole13($bad),'caption_source_hash_overwrite_accepted');
 echo "CONTEXT13_NEW_NATIVE_PUBLISHER_CAPTION_CASES_OK new_cases=3 old_fixtures0 provider0 db0\\n";exit;
}
function sourceDir13(string $op,array $allowed):string{need(in_array($op,$allowed,true)&&preg_match('/\\A[a-z0-9-]{1,150}\\z/D',$op)===1,'unapproved_source_operation');$dir='/var/www/anytoour/data/channel-post-operations/'.$op;need(!is_link(dirname($dir))&&!is_link($dir)&&is_dir($dir)&&realpath($dir)===$dir,'canonical_operation_directory_changed');return $dir;}
function atomicPublisher13(string $path):array{static $cache=[];if(isset($cache[$path]))return $cache[$path];need(!is_link($path)&&is_file($path),'original_publisher_receipt_missing');$n=filesize($path);need(is_int($n)&&$n>0&&$n<=1048576,'publisher_receipt_size');$raw=file_get_contents($path);need(is_string($raw)&&strlen($raw)===$n,'publisher_receipt_partial');$d=json_decode($raw,true,128,JSON_THROW_ON_ERROR);need(is_array($d),'publisher_atomic_json_invalid');$GLOBALS['context13_receipt_reads']=($GLOBALS['context13_receipt_reads']??0)+1;return $cache[$path]=$d;}
function copyJournal13(string $path):array{static $cache=[];if(isset($cache[$path]))return $cache[$path];need(!is_link($path)&&is_file($path),'copy_receipt_missing');$n=filesize($path);need(is_int($n)&&$n>0&&$n<=5242880,'copy_receipt_size');$raw=file_get_contents($path);need(is_string($raw)&&strlen($raw)===$n&&str_ends_with($raw,"\n"),'copy_journal_partial');$lines=explode("\n",rtrim($raw,"\n"));$d=json_decode(end($lines),true,128,JSON_THROW_ON_ERROR);need(is_array($d),'copy_journal_invalid');$GLOBALS['context13_receipt_reads']=($GLOBALS['context13_receipt_reads']??0)+1;return $cache[$path]=$d;}
function emptyStoredButton13(array $s):bool{foreach(['keyboard','reply_markup','buttons'] as $k)if(!empty($s[$k]))return false;return true;}
try {
    if(PHP_SAPI!=='cli'||trim((string)shell_exec('git rev-parse HEAD'))!==EXPECTED_INSTALLED_SHA)throw new RuntimeException('installed_source_changed');
    // Inspect only the unchanged, previously accepted publishing surface.
    $accepted=[
        "integrations/ChannelPostApiTransport.php"=>"300634d77cb9fddf51adf9869974f956cca6e3f9",
        "migrations/033_channel_post_context.sql"=>"0530458030a4bfe6b6cc55d1e5e441745c29b947",
        "migrations/036_channel_post_member_delivery.sql"=>"1bda463e52b33130ad12752ddef4ee5c92699c05",
        "migrations/040_channel_post_reconciliation.sql"=>"0f24b838eacd45333c5ade9a2991d060488c6035",
        "services/ChannelPostAdminService.php"=>"b80eefc801054bfe6fdb58ec345ecdf687b2e89a",
        "services/ChannelPostAutomationService.php"=>"f5975ab95de5f45e087eb7a0f768f566c5bca648",
        "services/ChannelPostButtonTransport.php"=>"b6c123069810ad490391e825315abd8567c55310",
        "services/ChannelPostEntryService.php"=>"f0e04e9b4e0ed6859ced96279cbf0619931596e6",
        "services/ChannelPostMaxAlbumRecovery.php"=>"de266cd068587cc3b2ff83f308a966ea66887a04",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"2b559b067a91abb9402a9dc2f3ce75d1ddedc7c3",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"a407757b9ac54a9e286900d34385d519d22b8c84",
        "services/ChannelPostRuntime.php"=>"2f1dd9b77474fdbaa92f0402f3b0aa170c595474",
        "services/ChannelPostTelegramAlbum.php"=>"faa1a14bc279a4af33936282126bf0d46e343040",
        "services/ChannelPostWebhookConfigService.php"=>"d5fdcb056088f1e3a541b4046325d97eef6417d9",
        "services/ConversationDb.php"=>"bb6281faf55aca7beeae7f8a57389d19751936f7",
        "services/MaxTlsConfig.php"=>"032c0211ee3f39c6f0d27c669abd5b467e321dea",
        "services/MaxTransport.php"=>"bfa583167347ff42ed632c52a552e5585bb97de9",
        "services/ProjectConfig.php"=>"538b596981f2c61fbf524e18756bf672578948f6",
    ];
    foreach($accepted as $path=>$blob)if(trim((string)shell_exec('git hash-object -- '.escapeshellarg($path)))!==$blob)throw new RuntimeException('publishing_surface_changed');
    ob_start();try {
        require getcwd().'/config.php';
        require_once getcwd().'/services/ChannelPostRuntime.php';
        require_once getcwd().'/services/ChannelPostAdminService.php';
        require_once getcwd().'/services/ChannelPostEntryService.php';
        require_once getcwd().'/services/MaxTransport.php';
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally {ob_end_clean();}



    require_once getcwd().'/services/HotelDatabase.php';need(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $catalog=HotelDatabase::connection();
 $d=contextInput13();$pdo=ConversationDb::connection();
 foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $name){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$name]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$name),'publishing_schema_not_accepted');}
 $shared=$catalog===$pdo;$catalog->exec('START TRANSACTION READ ONLY');if(!$shared)$pdo->exec('START TRANSACTION READ ONLY');$out['readonly_connection_mode']=$shared?'shared':'separate';
 $registry=new ChannelPostRegistryService($pdo);$targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];$channels=[];
 foreach($targets as $platform=>$t){$bot=ChannelPostRuntime::identity($platform);need(is_array($bot)&&$bot['project_key']==='anytour'&&$bot['bot_key']===$t['bot'],'configured_bot_changed');$c=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);need(is_array($c)&&(int)$c['id']===$t['id']&&$c['source_key']===$t['source']&&(int)$c['config_version']===2&&$c['button_text']==='✈️ Узнать стоимость тура','channel_configuration_changed');ob_start();try{$right=(new ChannelPostButtonTransport())->verifyChannel($c);}finally{ob_end_clean();}$out['provider_channel_rights_checks']++;need(($right['state']??'')==='ready','provider_channel_rights_changed');$channels[$platform]=$c;}
 $fold=static function(string $s):string{$s=preg_replace('/\\s*\\(.*?\\)/u','',$s)??$s;return trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower($s,'UTF-8'))??'');};$identity=$catalog->prepare('SELECT id,name,is_active,country_id FROM catalog_hotels WHERE id=? LIMIT 1');
 foreach($d['items'] as $x){$h=$x['hotel'];$id=(int)$h['local_tv_id'];try{
  $identity->execute([$id]);$native=$identity->fetch(PDO::FETCH_ASSOC);need(is_array($native)&&(int)$native['is_active']===1&&(int)$native['country_id']===(int)$h['expected_country_id']&&$fold($native['name'])===$fold($h['expected_name']),'current_native_country_active_name_changed');
  $contexts=[];$caption=null;$logical=null;$publisher=null;$sourceOp=null;$copyOp=null;
  foreach($targets as $platform=>$t){
   if($x['kind']==='accepted_legacy_frame_exact_current_refresh'){$f=$x['prior_frame'];$b=$f['binding'];$old=$b['contexts'][$platform];$proof=['publication_id'=>$old['publication_id'],'message_id'=>$old['message_id'],'version_id'=>$old['version_id']];$publisher=$b['publisher_hotel_id'];$logical=$b['logical_id'];$caption=$b['caption'];$captionHash=$b['caption_sha256'];$sourceOp=$b['source_original_publisher']??null;$copyOp=$b['source_copy_operation']??null;}
   else{$r=$x['original_manifest_row'];$publisher=(int)$x['publisher_hotel_id'];$logical=$r['logical_id'];$sourceOp=$x['original_publication_operation'];$prior=atomicPublisher13(sourceDir13($sourceOp,$d['allowed_source_operations']).'/'.$platform.'-'.$logical.'-approved.json');$proof=$prior['receipt']??[];
    need(($prior['state']??'')==='confirmed'&&($prior['text_sha256']??'')===$r['text_sha256']&&($prior['photo_sha256']??'')===$r['photo_sha256']&&($proof['logical_id']??'')===$logical&&(int)($proof['hotel_id']??0)===$publisher&&($proof['platform']??'')===$platform&&!empty($proof['body_photo_button_verified']),'original_publisher_exact_proof_failed');
   }
   $p=$registry->publication((int)$proof['publication_id']);need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$t['bot']&&$p['channel_id']===$t['channel']&&$p['external_message_id']===(string)$proof['message_id']&&$p['source_key']===$t['source']&&$p['state']==='sent'&&empty($p['claim_token'])&&empty($p['claimed_at']),'current_publication_identity_or_outcome_not_accepted');$s=$p['snapshot'];$sig=photoSignature($s);$button=ChannelPostButtonTransport::buttonUrl($p);
   if($x['kind']==='accepted_legacy_frame_exact_current_refresh'){
    need((int)$p['version_id']===(int)$old['version_id']&&($s['text']??'')===$old['old_text']&&hash('sha256',$s['text'])===$old['old_text_sha256']&&$sig===$old['photo_signature']&&($s['post_url']??'')===$old['post_url']&&hash('sha256',$p['start_payload'])===$old['start_payload_sha256']&&hash('sha256',$button)===$old['button_url_sha256'],'accepted_legacy_current_version_caption_photo_source_changed');$kind='ACCEPTED_ORIGINAL_LEGACY_FRAME_AND_EXACT_CURRENT_VERSION';
    $buttonOk=ChannelPostIncomingAdapter::containsOnlyButton($s,$button,'✈️ Узнать стоимость тура');if(!$buttonOk)need($id===46672&&(int)$f['capture_run']===41943048307&&emptyStoredButton13($s),'legacy_empty_keyboard_without_exact_owned_provider_proof');
    $originalPhoto=$old['original_photo_sha256']??null;
   }else{
    $matches=[];foreach($x['caption_variants'] as $v)if(($s['text']??'')===$v['caption']&&hash('sha256',$v['caption'])===$v['caption_sha256'])$matches[]=$v;need(count($matches)===1,'current_caption_not_exact_authorized_source');
    $v=$matches[0];$caption=$v['caption'];$captionHash=$v['caption_sha256'];$kind='ORIGINAL_PUBLISHER_APPROVED_RECEIPT_AND_EXACT_CURRENT_VERSION';$copyOp=null;
    if($v['source_kind']==='confirmed_copy'){$journal=copyJournal13(sourceDir13($v['source_operation'],$d['allowed_source_operations']).'/receipt.jsonl');need(($journal['operation']??'')===$v['source_operation']&&($journal['complete']??false)===true&&hash_equals($v['source_manifest_hash'],(string)($journal['manifest_hash']??'')),'confirmed_copy_operation_not_exact');$member=$journal['posts'][$platform.':'.$v['source_key']]??[];$copy=$member['receipt']??[];need(($member['state']??'')==='confirmed'&&($copy['logical_id']??'')===$logical&&(int)($copy['publication_id']??0)===(int)$p['publication_id']&&(string)($copy['message_id']??'')===$p['external_message_id']&&($copy['text_sha256']??'')===$captionHash&&($copy['photo_signature']??'')===$sig&&!empty($copy['body_photo_button_verified']),'latest_confirmed_copy_current_proof_failed');$copyOp=$v['source_operation'];$kind='CONFIRMED_COPY_RECEIPT_AND_CURRENT_CANONICAL_PHOTO_SIGNATURE';}
    else need((int)$p['version_id']===(int)$proof['version_id'],'original_only_current_version_changed');
    need(ChannelPostIncomingAdapter::containsOnlyButton($s,$button,'✈️ Узнать стоимость тура'),'current_original_button_shape_not_proven');$originalPhoto=$x['original_manifest_row']['photo_sha256'];
    need(($proof['source_key']??'')===$t['source']&&($proof['post_url']??'')===($s['post_url']??'')&&($proof['button_url']??'')===$button,'original_source_token_post_url_changed');
   }
   need(is_string($p['start_payload'])&&$p['start_payload']!==''&&hash('sha256',$s['text'])===$captionHash,'current_caption_or_start_context_invalid');
   $contexts[$platform]=['publication_id'=>(int)$p['publication_id'],'message_id'=>$p['external_message_id'],'version_id'=>(int)$p['version_id'],'logical_id'=>$logical,'source_key'=>$p['source_key'],'start_payload_sha256'=>hash('sha256',$p['start_payload']),'button_url_sha256'=>hash('sha256',$button),'post_url'=>$s['post_url'],'photo_signature'=>$sig,'old_text'=>$s['text'],'old_text_sha256'=>$captionHash,'canonical_proof_kind'=>$kind,'original_publication_hotel_id'=>$publisher,'original_photo_sha256'=>$originalPhoto];
  }
  need(count($contexts)===2&&$contexts['max']['old_text']===$contexts['telegram']['old_text']&&$contexts['max']['logical_id']===$contexts['telegram']['logical_id'],'two_platform_current_contexts_differ');
  $b=['native_hotel_id'=>$id,'expected_country_id'=>(int)$h['expected_country_id'],'catalog_name'=>$native['name'],'identity_provenance'=>$h['identity_provenance'],'publisher_hotel_id'=>$publisher,'key'=>'manual-price-'.$id,'logical_id'=>$logical,'caption_sha256'=>$captionHash,'caption'=>$caption,'source_copy_operation'=>$copyOp,'source_original_publisher'=>$sourceOp,'contexts'=>$contexts,'original_role_proof_kind'=>$x['kind']];
  $frame=['capture_operation'=>$out['operation'],'capture_run'=>APPROVED_CAPTURE_RUN,'capture_epoch_utc'=>time(),'capture_source_sha'=>EXPECTED_INSTALLED_SHA,'capture_input_sha256'=>APPROVED_CONTEXT_INPUT_SHA256,'binding'=>$b];
  if(isset($x['prior_frame']))$frame['prior_accepted_frame_provenance']=['capture_operation'=>$x['prior_frame']['capture_operation'],'capture_run'=>$x['prior_frame']['capture_run'],'capture_source_sha'=>$x['prior_frame']['capture_source_sha']];
  $out['bindings'][]=['hotel'=>$h,'canonical_context'=>$frame];
 }catch(Throwable $e){$out['deferred'][]=['native_hotel_id'=>$id,'reason'=>preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$e->getMessage())?$e->getMessage():'current_canonical_context_not_admitted'];}}
 if(!$shared)$pdo->rollBack();$catalog->rollBack();$out['canonical_source_receipts_read']=$GLOBALS['context13_receipt_reads']??0;$out['captured_epoch_utc']=time();$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['input_sha256']=APPROVED_CONTEXT_INPUT_SHA256;$out['deferred_before_server']=$d['deferred_before_server'];$out['ok']=true;
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();$out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$e->getMessage())?$e->getMessage():'new_current_context_read_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
