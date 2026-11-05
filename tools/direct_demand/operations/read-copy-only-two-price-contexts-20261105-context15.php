<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-monitor-20261105-context15-copy-only-two','mode'=>'readonly_exact_two_current_copy_contexts_before_new_quote','ok'=>false,'db_writes'=>0,'gateway_calls'=>0,'provider_post_reads'=>0,'provider_channel_rights_checks'=>0,'message_sends'=>0,'price_edits'=>0,'bindings'=>[],'deferred'=>[]];
function need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function photoSignature(array $s):string{$a=$s['attachments']??[];need(count($a)===1&&($a[0]['type']??'')==='image','expected_one_existing_image');$id=(string)($a[0]['photo_id']??$a[0]['file_unique_id']??'');need($id!==''&&preg_match('/\\A[A-Za-z0-9_-]+\\z/',$id)===1,'stable_photo_id_missing');return (string)($a[0]['provider']??'').':'.$id;}
function readMax(string $id):array{need(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$id)===1,'message_id_invalid');$r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$id,[],null);need(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']),'max_read_failed');return $r['data'];}
function copyContext15Role(array $x):bool{
 $h=$x['hotel']??[];$id=(int)($h['local_tv_id']??0);$exact=[67910=>['rixos','at-hotel-rixos','8657f52059dd18d6856d37dd9ca4cd79e769895f7511008761dbb7c4c0ba433a'],68323=>['solara','at-hotel-solara','40069e09adf6f7f148900a07f46475b2a5cdcac662519dfa7e944455410b0a8e']];
 if(!isset($exact[$id])||(int)($h['expected_country_id']??0)!==1||empty($h['observations'])||!in_array($h['identity_provenance']??'',['accepted_native_catalog_read','accepted_typed_bridge'],true))return false;
 $r=$x['copy_row']??[];$e=$exact[$id];
 return array_key_exists('publisher_hotel_id',$x)&&$x['publisher_hotel_id']===null&&($x['copy_operation']??'')==='commercial-human-copy-early-nine-20261102-v1'&&($r['key']??'')===$e[0]&&($r['logical_id']??'')===$e[1]&&($r['new_text_sha256']??'')===$e[2]&&is_string($r['new_text']??null)&&hash('sha256',$r['new_text'])===$e[2]&&($r['update_text']??false)===true&&preg_match('/\A[0-9a-f]{64}\z/D',(string)($x['copy_manifest_sha256']??''))===1;
}
function copyContext15Input():array{
 $raw=base64_decode(APPROVED_CONTEXT15_INPUT_B64,true);need(is_string($raw)&&hash_equals(APPROVED_CONTEXT15_INPUT_SHA256,hash('sha256',$raw)),'copy_context_input_bytes_changed');$d=json_decode($raw,true,256,JSON_THROW_ON_ERROR);
 need(($d['operation']??'')==='commercial-price-monitor-20261105-context15-copy-only-two'&&($d['source_run']??0)===41976677950&&($d['price_read13_run']??0)===41978466494&&count($d['items']??[])===2,'copy_two_context_scope_changed');
 $ids=[];foreach($d['items'] as $x){need(copyContext15Role($x),'native_copy_only_roles_invalid');$ids[]=(int)$x['hotel']['local_tv_id'];}sort($ids);need($ids===[67910,68323],'copy_two_exact_native_ids_required');return $d;
}
if(getenv('ANYTOUR_CONTEXT15_NEW_COPY_CASES')==='1'){
 $d=copyContext15Input();$x=$d['items'][0];
 $bad=$x;$bad['hotel']['local_tv_id']=240;need(!copyContext15Role($bad),'consumed_native_copy_context_accepted');
 $bad=$x;$bad['publisher_hotel_id']=67910;need(!copyContext15Role($bad),'invented_publisher_role_accepted');
 $bad=$x;$bad['copy_row']['new_text'].='x';need(!copyContext15Role($bad),'copy_caption_hash_changed_accepted');
 echo "CONTEXT15_NEW_NATIVE_COPY_ONLY_CASES_OK new_cases=3 old_fixtures0 provider0 db0\n";exit;
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
 $d=copyContext15Input();$pdo=ConversationDb::connection();
 foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $name){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$name]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$name),'publishing_schema_not_accepted');}
 $shared=$catalog===$pdo;$catalog->exec('START TRANSACTION READ ONLY');if(!$shared)$pdo->exec('START TRANSACTION READ ONLY');$out['readonly_connection_mode']=$shared?'shared':'separate';
 $registry=new ChannelPostRegistryService($pdo);$targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];$channels=[];
 foreach($targets as $platform=>$t){$bot=ChannelPostRuntime::identity($platform);need(is_array($bot)&&$bot['project_key']==='anytour'&&$bot['bot_key']===$t['bot'],'configured_bot_changed');$c=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);need(is_array($c)&&(int)$c['id']===$t['id']&&$c['source_key']===$t['source']&&(int)$c['config_version']===2&&$c['button_text']==='✈️ Узнать стоимость тура','channel_configuration_changed');ob_start();try{$right=(new ChannelPostButtonTransport())->verifyChannel($c);}finally{ob_end_clean();}$out['provider_channel_rights_checks']++;need(($right['state']??'')==='ready','provider_channel_rights_changed');$channels[$platform]=$c;}
 $fold=static function(string $s):string{$s=preg_replace('/\\s*\\(.*?\\)/u','',$s)??$s;return trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower($s,'UTF-8'))??'');};$identity=$catalog->prepare('SELECT id,name,is_active,country_id FROM catalog_hotels WHERE id=? LIMIT 1');
 foreach($d['items'] as $x){$h=$x['hotel'];$id=(int)$h['local_tv_id'];try{
  $identity->execute([$id]);$native=$identity->fetch(PDO::FETCH_ASSOC);need(is_array($native)&&(int)$native['is_active']===1&&(int)$native['country_id']===1&&$fold($native['name'])===$fold($h['expected_name']),'current_native_country_active_name_changed');
  $r=$x['copy_row'];$caption=$r['new_text'];$captionHash=$r['new_text_sha256'];$logical=$r['logical_id'];$copyOp=$x['copy_operation'];
  $heading=explode("\n",$caption)[0];$prefix='✈️ Туры в ';$suffix=' с перелётом';need(str_starts_with($heading,$prefix)&&str_ends_with($heading,$suffix),'copy_native_heading_missing');$headingName=substr($heading,strlen($prefix),strlen($heading)-strlen($prefix)-strlen($suffix));need($fold($headingName)===$fold($native['name']),'copy_heading_exact_native_name_mismatch');
  $journal=copyJournal13(sourceDir13($copyOp,[$copyOp]).'/receipt.jsonl');need(($journal['operation']??'')===$copyOp&&($journal['complete']??false)===true&&hash_equals($x['copy_manifest_sha256'],(string)($journal['manifest_hash']??'')),'confirmed_copy_operation_not_exact');$contexts=[];
  foreach($targets as $platform=>$t){
   $member=$journal['posts'][$platform.':'.$r['key']]??[];$proof=$member['receipt']??[];
   need(($member['state']??'')==='confirmed'&&($proof['logical_id']??'')===$logical&&($proof['platform']??'')===$platform&&($proof['text_sha256']??'')===$captionHash&&!empty($proof['body_photo_button_verified'])&&(int)($proof['publication_id']??0)>0&&(int)($proof['version_id']??0)>0,'confirmed_copy_receipt_not_admitted');
   $p=$registry->publication((int)$proof['publication_id']);need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$t['bot']&&$p['channel_id']===$t['channel']&&$p['external_message_id']===(string)($proof['message_id']??'')&&$p['source_key']===$t['source']&&$p['state']==='sent'&&empty($p['claim_token'])&&empty($p['claimed_at'])&&(int)$p['version_id']===(int)$proof['version_id'],'current_copy_publication_identity_or_version_changed');
   $s=$p['snapshot'];$sig=photoSignature($s);$button=ChannelPostButtonTransport::buttonUrl($p);
   need(($s['text']??'')===$caption&&hash('sha256',$s['text'])===$captionHash&&($proof['photo_signature']??'')===$sig&&($proof['source_key']??'')===$t['source']&&($proof['post_url']??'')===($s['post_url']??'')&&($proof['button_url']??'')===$button&&is_string($p['start_payload'])&&$p['start_payload']!=='','confirmed_copy_current_caption_photo_source_token_mismatch');
   $storedButton=ChannelPostIncomingAdapter::containsOnlyButton($s,$button,'✈️ Узнать стоимость тура');need($storedButton||emptyStoredButton13($s),'nonempty_conflicting_copy_keyboard_stop');
   if($platform==='max'){
    $out['provider_post_reads']++;$actual=ChannelPostIncomingAdapter::maxSnapshot(readMax($p['external_message_id']));
    need(is_array($actual)&&($actual['text']??'')===$caption&&photoSignature($actual)===$sig&&($actual['post_url']??'')===($s['post_url']??'')&&ChannelPostIncomingAdapter::containsOnlyButton($actual,$button,'✈️ Узнать стоимость тура'),'copy_max_owned_actual_context_mismatch');
    $kind='CONFIRMED_COPY_RECEIPT_EXACT_CURRENT_VERSION_AND_OWNED_MAX_BODY_PHOTO_ONLY_BUTTON';
   }else{$kind='CONFIRMED_COPY_TRANSPORT_RECEIPT_EXACT_CURRENT_TG_VERSION_AND_STABLE_PHOTO_SIGNATURE';}
   $contexts[$platform]=['publication_id'=>(int)$p['publication_id'],'message_id'=>$p['external_message_id'],'version_id'=>(int)$p['version_id'],'logical_id'=>$logical,'source_key'=>$p['source_key'],'start_payload_sha256'=>hash('sha256',$p['start_payload']),'button_url_sha256'=>hash('sha256',$button),'post_url'=>$s['post_url'],'photo_signature'=>$sig,'old_text'=>$s['text'],'old_text_sha256'=>$captionHash,'canonical_proof_kind'=>$kind,'original_publication_hotel_id'=>null,'original_photo_sha256'=>null];
  }
  need(count($contexts)===2&&$contexts['max']['old_text']===$contexts['telegram']['old_text'],'two_copy_platform_contexts_differ');
  $b=['native_hotel_id'=>$id,'expected_country_id'=>1,'catalog_name'=>$native['name'],'identity_provenance'=>$h['identity_provenance'],'publisher_hotel_id'=>null,'key'=>'manual-price-'.$id,'logical_id'=>$logical,'caption_sha256'=>$captionHash,'caption'=>$caption,'source_copy_operation'=>$copyOp,'source_original_publisher'=>null,'contexts'=>$contexts,'original_role_proof_kind'=>'EXPLICIT_COPY_ONLY_NULL_PUBLISHER_CANONICAL_RECEIPT_AND_STABLE_PROVIDER_PHOTO_SIGNATURE'];
  $frame=['capture_operation'=>$out['operation'],'capture_run'=>APPROVED_CAPTURE_RUN,'capture_epoch_utc'=>time(),'capture_source_sha'=>EXPECTED_INSTALLED_SHA,'capture_input_sha256'=>APPROVED_CONTEXT15_INPUT_SHA256,'binding'=>$b];
  $out['bindings'][]=['hotel'=>$h,'canonical_context'=>$frame];
 }catch(Throwable $e){$out['deferred'][]=['native_hotel_id'=>$id,'reason'=>preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'current_copy_context_not_admitted'];}}
 if(!$shared)$pdo->rollBack();$catalog->rollBack();$out['canonical_source_receipts_read']=$GLOBALS['context13_receipt_reads']??0;$out['captured_epoch_utc']=time();$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['input_sha256']=APPROVED_CONTEXT15_INPUT_SHA256;$out['ok']=true;
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,120}\z/D',$e->getMessage())?$e->getMessage():'new_copy_only_context_read_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
