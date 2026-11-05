<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-legacy9-contexts-20261105-read1','mode'=>'readonly_exact_legacy_publication_bindings','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'gateway_calls'=>0,'message_sends'=>0,'price_edits'=>0,'bindings'=>[],'deferred'=>[]];
function need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function compactName(string $s):string{$s=preg_replace('/\\s*\\(.*?\\)/u','',$s)??$s;return preg_replace('/[^\\p{L}\\p{N}]+/u','',mb_strtolower($s,'UTF-8'))??'';}
function photoSignature(array $s):string{$a=$s['attachments']??[];need(count($a)===1&&($a[0]['type']??'')==='image','expected_one_existing_image');$id=(string)($a[0]['photo_id']??$a[0]['file_unique_id']??'');need($id!==''&&preg_match('/\\A[A-Za-z0-9_-]+\\z/',$id)===1,'stable_photo_id_missing');return (string)($a[0]['provider']??'').':'.$id;}
function rolesOk(array $b):bool{
 $fixed=[240=>[80,'accepted_typed_bridge',1],1981=>[877,'accepted_typed_bridge',1],46672=>[2432,'accepted_typed_bridge',4],498=>[228,'accepted_typed_bridge',1],9373=>[9373,'accepted_native_catalog_read',1],469=>[null,'accepted_native_catalog_read',1],381=>[null,'accepted_native_catalog_read',1],310=>[null,'accepted_native_catalog_read',1],1971=>[null,'accepted_native_catalog_read',1]];
 $id=(int)($b['native_hotel_id']??0);if(!isset($fixed[$id]))return false;
 [$publisher,$provenance,$country]=$fixed[$id];
 return ($b['publisher']['hotel_id']??null)===$publisher&&($b['identity_provenance']??'')===$provenance&&(int)($b['expected_country_id']??0)===$country;
}
function frozenInput():array{
 $raw=base64_decode(APPROVED_BINDINGS_B64,true);need(is_string($raw)&&hash_equals(APPROVED_BINDINGS_SHA256,hash('sha256',$raw)),'approved_binding_bytes_changed');
 $d=json_decode($raw,true,128,JSON_THROW_ON_ERROR);need(is_array($d)&&count($d)===9&&count(array_unique(array_column($d,'native_hotel_id')))===9,'exact_nine_scope_required');
 foreach($d as $b)need(rolesOk($b),'publisher_native_identity_roles_changed');return $d;
}
if(getenv('ANYTOUR_LEGACY9_ROLE_FIXTURE')==='1'){
 $bindings=frozenInput();$by=[];foreach($bindings as $b)$by[$b['native_hotel_id']]=$b;
 need(rolesOk($by[240])&&rolesOk($by[9373]),'new_typed_and_native_role_positive');
 $bad=$by[240];$bad['publisher']['hotel_id']=240;need(!rolesOk($bad),'publisher_id_not_overwritten_with_native_id');
 $bad=$by[240];$bad['native_hotel_id']=1981;need(!rolesOk($bad),'cross_hotel_source_proof_blocked');
 echo "LEGACY9_NEW_ROLE_SCOPE_OK new_cases=3 old_typed15_native_age_fixtures_rerun=0 server_calls=0 provider_calls=0 writes=0\n";exit;
}
function readCanonical(string $path,int $max):array{
 static $cache=[];if(isset($cache[$path]))return $cache[$path];
 need(!is_link($path)&&is_file($path),'canonical_source_receipt_missing');$size=filesize($path);need(is_int($size)&&$size>0&&$size<=$max,'canonical_source_receipt_size_invalid');
 $raw=file_get_contents($path);need(is_string($raw)&&strlen($raw)===$size&&str_ends_with($raw,"\n"),'canonical_source_receipt_partial');
 $lines=explode("\n",rtrim($raw,"\n"));$data=json_decode(end($lines),true,128,JSON_THROW_ON_ERROR);need(is_array($data),'canonical_source_receipt_invalid');$GLOBALS['legacy_source_receipts_read']=($GLOBALS['legacy_source_receipts_read']??0)+1;$cache[$path]=$data;return $data;
}
function sourceDir(string $op):string{
 $allowed=['commercial-human-copy-early-nine-20261102-v1','commercial-human-copy-three-20261102-v1','commercial-hotel-next-three-publish-20261102-v1','commercial-hotel-three-20261102-2-v1','commercial-hotel-three-20261102-3-v1','commercial-hotel-three-20261102-5-v1','commercial-hotel-three-20261102-7-v1'];
 need(in_array($op,$allowed,true),'unapproved_legacy_operation');$dir='/var/www/anytoour/data/channel-post-operations/'.$op;need(!is_link(dirname($dir))&&!is_link($dir)&&is_dir($dir)&&realpath($dir)===$dir,'canonical_operation_directory_changed');return $dir;
}
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



    $bindings=frozenInput();
    require_once getcwd().'/services/HotelDatabase.php';need(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $catalog=HotelDatabase::connection();$pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $m){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$m]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$m),'publishing_schema_not_accepted');}
    $sharedReadonlyConnection=$catalog===$pdo;$catalog->exec('START TRANSACTION READ ONLY');if(!$sharedReadonlyConnection)$pdo->exec('START TRANSACTION READ ONLY');$out['readonly_connection_mode']=$sharedReadonlyConnection?'shared':'separate';
    $registry=new ChannelPostRegistryService($pdo);
    $identity=$catalog->prepare('SELECT id,name,is_active,country_id FROM catalog_hotels WHERE id=? LIMIT 1');
    $targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];
    foreach($bindings as $b){
     $id=(int)$b['native_hotel_id'];
     try{
      $identity->execute([$id]);$hotel=$identity->fetch(PDO::FETCH_ASSOC);
      need(is_array($hotel)&&(int)$hotel['id']===$id&&(int)$hotel['is_active']===1&&(int)$hotel['country_id']===(int)$b['expected_country_id']&&compactName($hotel['name'])===compactName($b['expected_name']),'current_native_country_activity_or_name_changed');
      $allowed=array_map('compactName',$b['approved_source_hotel_names']);need(in_array(compactName($hotel['name']),$allowed,true),'approved_source_name_not_bound_to_native');
      $contexts=[];$copy=null;
      if($b['copy']!==null){$copy=readCanonical(sourceDir($b['copy']['operation']).'/receipt.jsonl',5242880);need(($copy['operation']??'')===$b['copy']['operation']&&($copy['complete']??false)===true&&hash_equals($b['copy']['manifest_hash'],(string)($copy['manifest_hash']??'')),'prior_copy_operation_not_exact_confirmed');}
      foreach($targets as $platform=>$t){
       $original=null;$copied=null;
       if($b['publisher']!==null){
        need(preg_match('/\\A[a-z0-9-]{1,120}\\z/',$b['logical_id'])===1,'legacy_logical_id_invalid');
        $original=readCanonical(sourceDir($b['publisher']['operation']).'/'.$platform.'-'.$b['logical_id'].'-approved.json',1048576);
        $r=$original['receipt']??[];
        need(($original['state']??'')==='confirmed'&&($original['text_sha256']??'')===$b['publisher']['text_sha256']&&($original['photo_sha256']??'')===$b['publisher']['photo_sha256']&&($r['logical_id']??'')===$b['logical_id']&&(int)($r['hotel_id']??0)===(int)$b['publisher']['hotel_id']&&!empty($r['body_photo_button_verified']),'original_publisher_receipt_not_exact_confirmed');
       }
       if($copy!==null){
        $member=$copy['posts'][$platform.':'.$b['copy']['key']]??[];
        $copied=$member['receipt']??[];
        need(($member['state']??'')==='confirmed'&&($copied['logical_id']??'')===$b['logical_id']&&($copied['text_sha256']??'')===$b['caption_sha256']&&!empty($copied['body_photo_button_verified'])&&($copied['platform']??'')===$platform,'latest_authorized_copy_receipt_not_confirmed');
       }
       $proof=$copied??($original['receipt']??null);need(is_array($proof),'canonical_publication_reference_missing');
       if($copied!==null&&$original!==null)need((int)$copied['publication_id']===(int)$original['receipt']['publication_id']&&(string)$copied['message_id']===(string)$original['receipt']['message_id'],'copy_and_original_publication_differ');
       $pub=$registry->publication((int)$proof['publication_id']);
       $channel=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);
       need(is_array($pub)&&is_array($channel)&&$pub['project_key']==='anytour'&&$pub['platform']===$platform&&$pub['bot_key']===$t['bot']&&$pub['channel_id']===$t['channel']&&$pub['external_message_id']===(string)$proof['message_id']&&$pub['source_key']===$t['source']&&$pub['state']==='sent','current_canonical_publication_identity_changed');
       need((int)$channel['id']===$t['id']&&$channel['source_key']===$t['source']&&(int)$channel['config_version']===2&&$channel['button_text']==='✈️ Узнать стоимость тура','channel_configuration_changed');
       $snapshot=$pub['snapshot'];need(($snapshot['text']??'')===$b['caption']&&hash('sha256',$snapshot['text'])===$b['caption_sha256'],'current_caption_differs_from_last_approved_source');
       $sig=photoSignature($snapshot);
       if($copied!==null)need($sig===($copied['photo_signature']??''),'current_photo_differs_from_confirmed_copy');
       else need((int)$pub['version_id']===(int)$proof['version_id'],'original_only_version_requires_new_proof');
       need(is_string($pub['start_payload'])&&$pub['start_payload']!=='','existing_start_payload_missing');$button=ChannelPostButtonTransport::buttonUrl($pub);
       need(($proof['platform']??'')===$platform&&($proof['source_key']??'')===$t['source']&&($proof['post_url']??'')===($snapshot['post_url']??'')&&($proof['button_url']??'')===$button&&ChannelPostIncomingAdapter::containsOnlyButton($snapshot,$button,'✈️ Узнать стоимость тура'),'canonical_source_url_or_button_context_changed');
       $contexts[$platform]=['publication_id'=>(int)$pub['publication_id'],'message_id'=>$pub['external_message_id'],'version_id'=>(int)$pub['version_id'],'logical_id'=>$b['logical_id'],'source_key'=>$pub['source_key'],'start_payload_sha256'=>hash('sha256',$pub['start_payload']),'button_url_sha256'=>hash('sha256',$button),'post_url'=>$snapshot['post_url'],'photo_signature'=>$sig,'old_text'=>$snapshot['text'],'old_text_sha256'=>$b['caption_sha256'],'canonical_proof_kind'=>$copied!==null?'CONFIRMED_COPY_RECEIPT_AND_CURRENT_CANONICAL_PHOTO_SIGNATURE':'ORIGINAL_PUBLISHER_APPROVED_RECEIPT_AND_EXACT_CURRENT_VERSION','original_publication_hotel_id'=>$b['publisher']['hotel_id']??null,'original_photo_sha256'=>$b['publisher']['photo_sha256']??null];
      }
      need(count($contexts)===2,'two_legacy_platform_bindings_required');
      $out['bindings'][]=['native_hotel_id'=>$id,'expected_country_id'=>$b['expected_country_id'],'catalog_name'=>$hotel['name'],'identity_provenance'=>$b['identity_provenance'],'publisher_hotel_id'=>$b['publisher']['hotel_id']??null,'key'=>'legacy-'.$id,'logical_id'=>$b['logical_id'],'caption_sha256'=>$b['caption_sha256'],'caption'=>$b['caption'],'source_copy_operation'=>$b['copy']['operation']??null,'source_copy_manifest_hash'=>$b['copy']['manifest_hash']??null,'source_original_publisher'=>$b['publisher']['operation']??null,'contexts'=>$contexts];
     }catch(Throwable $e){$out['deferred'][]=['native_hotel_id'=>$id,'reason'=>preg_match('/\\A[a-z][a-z0-9_]{1,100}\\z/D',$e->getMessage())?$e->getMessage():'legacy_canonical_binding_not_admitted'];}
    }
    if(!$sharedReadonlyConnection)$pdo->rollBack();$catalog->rollBack();$out['canonical_source_receipts_read']=$GLOBALS['legacy_source_receipts_read']??0;$out['captured_epoch_utc']=time();$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['input_sha256']=APPROVED_BINDINGS_SHA256;$out['ok']=true;
} catch(Throwable $e){
 if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();
 $out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,100}\\z/D',$e->getMessage())?$e->getMessage():'legacy_context_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
