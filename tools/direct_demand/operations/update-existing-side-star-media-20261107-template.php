<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>APPROVED_MEDIA_OPERATION,'mode'=>'replace_exact_side_star_cover_on_existing_two_posts','ok'=>false,'new_posts_created'=>0,'media_edit_attempts'=>0,'max_upload_attempts'=>0,'caption_edits'=>0,'price_queries'=>0,'tour_search_calls'=>0,'posts'=>[]];
function need(bool $v,string $reason):void{if(!$v)throw new RuntimeException($reason);}
function durable($f,array $s):void{need(fseek($f,0,SEEK_END)===0,'receipt_write_failed');$raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";need(fwrite($f,$raw)===strlen($raw)&&fflush($f),'receipt_write_failed');if(function_exists('fsync'))need(fsync($f),'receipt_sync_failed');}
function photoSignature(array $snapshot):string{$media=$snapshot['attachments']??[];need(count($media)===1&&($media[0]['type']??'')==='image','expected_one_photo');$m=$media[0];$id=(string)($m['photo_id']??$m['file_unique_id']??'');need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$id)===1,'photo_stable_id_missing');return (string)($m['provider']??'').':'.$id;}
function tgMedia(array $body,string $file):array{$body['photo']=new CURLFile($file,'image/png','side-star-resort.png');$ch=curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/editMessageMedia');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45]);$raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_errno($ch)!==0||$raw===false;curl_close($ch);$d=is_string($raw)?json_decode($raw,true):null;return ['http'=>$http,'transport_error'=>$error,'data'=>is_array($d)?$d:[]];}
function sameContext(array $p,array $s,array $proof,string $text):bool{return ($s['text']??'')===$text&&($s['post_url']??'')===$proof['post_url']&&ChannelPostButtonTransport::buttonUrl($p)===$proof['button_url']&&ChannelPostIncomingAdapter::containsOnlyButton($s,$proof['button_url'],'✈️ Узнать стоимость тура');}
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function persist(string $path,array $value):void{
 $tmp=$path.'.tmp';$raw=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $f=fopen($tmp,'w');insist($f!==false,'receipt_storage_failed');insist(fwrite($f,$raw)===strlen($raw)&&fflush($f),'receipt_storage_failed');if(function_exists('fsync'))insist(fsync($f),'receipt_storage_failed');fclose($f);insist(rename($tmp,$path),'receipt_storage_failed');chmod($path,0600);
}
function photo(array $row,string $dir):string{
 $file=$dir.'/media-'.$row['photo_sha256'];
 if(is_file($file)){insist(hash_file('sha256',$file)===$row['photo_sha256'],'cached_photo_changed');return $file;}
 $raw='';$ch=curl_init($row['photo_url']);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>50,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_WRITEFUNCTION=>static function($c,string $s)use(&$raw):int{if(strlen($raw)+strlen($s)>12000000)return 0;$raw.=$s;return strlen($s);}]);
 $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 insist($ok!==false&&$http===200&&hash('sha256',$raw)===$row['photo_sha256'],'approved_photo_download_or_hash_failed');
 $size=@getimagesizefromstring($raw);insist(is_array($size)&&max($size[0],$size[1])>=500&&in_array($size['mime']??'', ['image/jpeg','image/png','image/webp'],true),'approved_photo_format_failed');
 insist(file_put_contents($file,$raw,LOCK_EX)===strlen($raw),'photo_cache_write_failed');chmod($file,0600);return $file;
}
function readMax(string $mid):array{
 insist(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$mid)===1,'invalid_saved_message_id');$r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$mid,[],null);
 insist(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']),'provider_post_read_failed');return $r['data'];
}
function token(array $a):string{if(is_string($a['token']??null)&&$a['token']!=='')return $a['token'];foreach($a as $v)if(is_array($v)){ $s=token($v);if($s!=='')return $s;}return '';}
function maxPhoto(string $file,array $row,string $dir):string{
 $path=$dir.'/max-upload-'.$row['photo_sha256'].'.json';
 if(is_file($path)){$s=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);insist(($s['state']??'')==='confirmed'&&is_string($s['token']??null),'prior_upload_unknown_no_retry');return $s['token'];}
 persist($path,['state'=>'started','photo_sha256'=>$row['photo_sha256']]);
 $r=MaxTransport::request('https://platform-api2.max.ru',MAX_SEARCH_TOKEN,'POST','/uploads',['type'=>'image'],null,null);insist(is_array($r)&&is_string($r['url']??null),'upload_endpoint_unconfirmed');
 $parts=parse_url($r['url']);insist(($parts['scheme']??'')==='https'&&!isset($parts['user'],$parts['pass'])&&!isset($parts['port'])&&!isset($parts['fragment'])&&preg_match('/\A(?:[a-z0-9-]+\.)*(?:max\.ru|oneme\.ru|okcdn\.ru|mycdn\.me)\z/D',(string)($parts['host']??''))===1,'upload_host_requires_review');
 $mime=(getimagesize($file)['mime']??'');$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??'';insist($ext!=='','upload_mime_invalid');
 $raw='';$ch=curl_init($r['url']);curl_setopt_array($ch,MaxTlsConfig::strictCurlOptions()+[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_POSTFIELDS=>['data'=>new CURLFile($file,$mime,'photo.'.$ext)],CURLOPT_WRITEFUNCTION=>static function($c,string $s)use(&$raw):int{if(strlen($raw)+strlen($s)>1048576)return 0;$raw.=$s;return strlen($s);}]);
 $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode($raw,true);
 insist($ok!==false&&$http>=200&&$http<300&&is_array($data)&&token($data)!=='','photo_upload_outcome_unconfirmed');$t=token($data);persist($path,['state'=>'confirmed','photo_sha256'=>$row['photo_sha256'],'token'=>$t]);return $t;
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


    $raw=base64_decode(MANIFEST_BASE64,true);need(is_string($raw)&&hash('sha256',$raw)===APPROVED_MANIFEST_SHA256,'approved_media_input_changed');$in=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
    need(APPROVED_MEDIA_OPERATION==='commercial-side-star-media-20261107-1-v1'&&($in['operation']??'')===APPROVED_MEDIA_OPERATION,'media_operation_changed');
    $row=$in['media'];need($row['hotel_id']===1095&&$row['logical_id']==='commercial-side-star-resort-1095-v1'&&$row['photo_sha256']==='0a117604ce61e6f0d30e6cc16fe90da418ff674298ceec04ee6ce736eff78733'&&$row['photo_bytes']===698890,'approved_side_star_photo_changed');
    need($row['photo_url']==='https://raw.githubusercontent.com/pyatkoff/max-search-bot/1cc100a9817085458305aedc90db5fb950e947f0/.github/traffic-publish/commercial-side-star-media-20261107-1/1095.png','immutable_media_url_changed');
    need(hash('sha256',$row['text'])===$row['text_sha256']&&$row['text_sha256']==='b9835538a3ac268c97257375988a96681242a5173d75e8b137dc18306a976e45','current_cta_caption_changed');
    $prior=$in['prior_result'];need(($prior['operation']??'')==='commercial-hotel-caption-cta-dedup-20261107-1-v1'&&($prior['ok']??null)===true&&($prior['commercial_text_updates']??null)===6&&($prior['new_posts_created']??null)===0,'prior_cta_receipt_invalid');
    $proofs=[];foreach($prior['posts'] as $r)if($r['logical_id']===$row['logical_id']){$platform=$r['platform'];need(in_array($platform,['max','telegram'],true)&&!isset($proofs[$platform])&&$r['text_sha256']===$row['text_sha256']&&$r['body_photo_button_verified']===true&&$r['existing_message_reused']===true,'prior_side_star_binding_changed');$proofs[$platform]=$r;}
    need(count($proofs)===2&&[$proofs['max']['publication_id'],$proofs['max']['version_id']]===[458,929]&&[$proofs['telegram']['publication_id'],$proofs['telegram']['version_id']]===[455,932],'exact_two_current_versions_changed');
    $pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $m){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$m]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$m),'publishing_schema_not_accepted');}
    $active=$pdo->query("SELECT COUNT(*) FROM post_versions WHERE publication_id IN (455,458) AND (state IN ('claimed','write_started','unknown') OR claim_token IS NOT NULL OR claimed_at IS NOT NULL)");need((int)$active->fetchColumn()===0,'side_star_active_or_unknown_stop');
    $dir='/var/www/anytoour/data/channel-post-operations/'.APPROVED_MEDIA_OPERATION;need(!is_link(dirname($dir))&&!is_link($dir),'operation_directory_invalid');if(!is_dir($dir))need(mkdir($dir,0700,true),'operation_directory_create_failed');need(realpath($dir)===$dir,'operation_directory_invalid');
    $receiptPath=$dir.'/receipt.jsonl';need(!file_exists($receiptPath)&&!is_link($receiptPath),'prior_media_operation_do_not_replay');$lock=fopen($receiptPath,'x+');need($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'media_operation_busy');
    $state=['operation'=>APPROVED_MEDIA_OPERATION,'manifest_sha256'=>APPROVED_MANIFEST_SHA256,'state'=>'preflight','posts'=>[]];durable($lock,$state);
    $registry=new ChannelPostRegistryService($pdo);$targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];$plans=[];
    foreach($targets as $platform=>$t){
        $proof=$proofs[$platform];$id=$proof['publication_id'];$p=$registry->publication($id);
        need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$t['bot']&&$p['channel_id']===$t['channel']&&$p['source_key']===$t['source']&&$p['state']==='sent'&&(int)$p['version_id']===$proof['version_id']&&$p['external_message_id']===$proof['message_id'],'current_side_star_publication_changed');
        $identity=ChannelPostRuntime::identity($platform);need(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===$t['bot'],'configured_bot_changed');
        $c=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);need(is_array($c)&&(int)$c['id']===$t['id']&&(int)$c['config_version']===2&&$c['source_key']===$t['source']&&$c['button_text']==='✈️ Узнать стоимость тура','channel_configuration_changed');need(((new ChannelPostButtonTransport())->verifyChannel($c)['state']??'')==='ready','channel_rights_not_ready');
        need(sameContext($p,$p['snapshot'],$proof,$row['text'])&&photoSignature($p['snapshot'])===$proof['photo_signature'],'current_photo_caption_button_changed');
        if($platform==='max'){$before=ChannelPostIncomingAdapter::maxSnapshot(readMax($proof['message_id']));need(is_array($before)&&sameContext($p,$before,$proof,$row['text'])&&photoSignature($before)===$proof['photo_signature'],'current_max_photo_caption_button_changed');}
        $plans[$platform]=['p'=>$p,'c'=>$c,'proof'=>$proof,'token'=>$p['start_payload']];
    }
    $out['preflight_existing_messages']=2;$file=photo($row,$dir);need(filesize($file)===$row['photo_bytes']&&getimagesize($file)[0]===600&&getimagesize($file)[1]===600,'approved_media_dimensions_changed');
    $state['state']='approved_media_ready';durable($lock,$state);$out['max_upload_attempts']=1;$maxToken=maxPhoto($file,$row,$dir);$state['max_upload_confirmed']=true;durable($lock,$state);
    foreach($targets as $platform=>$t){
        $plan=$plans[$platform];$proof=$plan['proof'];$p=$registry->publication($proof['publication_id']);$c=$plan['c'];
        need(is_array($p)&&$p['state']==='sent'&&(int)$p['version_id']===$proof['version_id']&&sameContext($p,$p['snapshot'],$proof,$row['text'])&&photoSignature($p['snapshot'])===$proof['photo_signature']&&hash_equals((string)$p['start_payload'],(string)$plan['token']),'side_star_changed_before_media_edit');
        $requestStart=(int)floor(microtime(true)*1000);$state['posts'][$platform]=['state'=>'media_edit_started','publication_id'=>$proof['publication_id'],'previous_version_id'=>$proof['version_id'],'message_id'=>$proof['message_id'],'expected_old_photo_signature'=>$proof['photo_signature'],'approved_new_photo_sha256'=>$row['photo_sha256'],'caption_sha256'=>$row['text_sha256'],'request_start_ms'=>$requestStart];durable($lock,$state);$out['media_edit_attempts']++;
        if($platform==='max'){
            $body=['attachments'=>[['type'=>'image','payload'=>['token'=>$maxToken]],['type'=>'inline_keyboard','payload'=>['buttons'=>[[['type'=>'link','text'=>'✈️ Узнать стоимость тура','url'=>$proof['button_url']]]]]]]];
            $response=ChannelPostApiTransport::buttonRequest('max','PUT','/messages',['message_id'=>$proof['message_id']],$body);$state['posts'][$platform]['response_private']=$response;$state['posts'][$platform]['state']='provider_returned';durable($lock,$state);
            $message=readMax($proof['message_id']);need(($message['body']['mid']??'')===$proof['message_id']&&(string)($message['recipient']['chat_id']??'')===$t['channel']&&($message['recipient']['chat_type']??'')==='channel','max_media_response_identity_changed');
            $images=array_values(array_filter($message['body']['attachments']??[],static fn($a)=>($a['type']??'')==='image'));need(count($images)===1&&($images[0]['payload']['token']??'')===$maxToken,'max_uploaded_photo_token_not_confirmed_no_retry');$snap=ChannelPostIncomingAdapter::maxSnapshot($message);
        }else{
            $media=['type'=>'photo','media'=>'attach://photo','caption'=>$row['text']];$keyboard=['inline_keyboard'=>[[['text'=>'✈️ Узнать стоимость тура','url'=>$proof['button_url']]]]];
            $response=tgMedia(['chat_id'=>$t['channel'],'message_id'=>$proof['message_id'],'media'=>json_encode($media,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'reply_markup'=>json_encode($keyboard,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)],$file);$state['posts'][$platform]['response_private']=$response;$state['posts'][$platform]['state']='provider_returned';durable($lock,$state);
            need(!$response['transport_error']&&$response['http']===200&&($response['data']['ok']??false)===true&&is_array($response['data']['result']??null),'telegram_media_edit_not_confirmed_no_retry');$message=$response['data']['result'];need((string)($message['chat']['id']??'')===$t['channel']&&(string)($message['message_id']??'')===$proof['message_id']&&($message['chat']['username']??'')==='any_toour','telegram_media_response_identity_changed');$snap=ChannelPostIncomingAdapter::telegramSnapshot($message);
        }
        need(is_array($snap)&&sameContext($p,$snap,$proof,$row['text']),'media_edit_changed_caption_url_or_button');$newPhoto=photoSignature($snap);need($newPhoto!==$proof['photo_signature'],'new_photo_not_observed_no_retry');
        $snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap,'',(string)$c['bot_url']);$snap['metadata']=array_replace($p['snapshot']['metadata']??[],$snap['metadata']??[]);$snap['metadata']['source_event_id']=APPROVED_MEDIA_OPERATION.':'.$platform.':'.$proof['message_id'];$snap['metadata']['observation_kind']='authorized_admin_media_edit';$snap['metadata']['media_edit_operation']=APPROVED_MEDIA_OPERATION;$snap['metadata']['photo_sha256']=$row['photo_sha256'];$snap['metadata']['photo_url']=$row['photo_url'];$snap['metadata']['logical_id']=$row['logical_id'];$snap['metadata']['hotel_catalog_id']=1095;
        if($platform==='max'){$snap['metadata']['source_revision_ms']=(string)$requestStart;$snap['metadata']['revision_clock_origin']='authorized_edit_request_start';$snap['metadata']['operation_observed_at_ms']=(string)(int)floor(microtime(true)*1000);}
        $p=$registry->registerPublication('anytour',$platform,$t['bot'],$t['channel'],$proof['message_id'],$snap);need(is_array($p)&&(int)$p['publication_id']===$proof['publication_id']&&sameContext($p,$p['snapshot'],$proof,$row['text'])&&photoSignature($p['snapshot'])===$newPhoto,'media_registry_context_changed');
        if($p['state']!=='sent'){
            $pdo->beginTransaction();$q=$pdo->prepare('SELECT state,claim_token,claimed_at,write_attempts FROM post_versions WHERE id=? AND publication_id=? FOR UPDATE');$q->execute([(int)$p['version_id'],$proof['publication_id']]);$held=$q->fetch(PDO::FETCH_ASSOC);need(is_array($held)&&$held['state']==='pending'&&$held['claim_token']===null&&$held['claimed_at']===null&&(int)$held['write_attempts']===0,'new_media_version_active_or_unknown_stop');
            need($registry->confirmObservedButton($proof['publication_id'],(int)$p['version_id'],['button_verified'=>true,'content_preserved'=>true,'media_verified'=>true,'operation'=>APPROVED_MEDIA_OPERATION]),'observed_button_confirmation_failed');$pdo->commit();
        }
        $p=$registry->publication($proof['publication_id']);need(is_array($p)&&$p['state']==='sent'&&sameContext($p,$p['snapshot'],$proof,$row['text'])&&photoSignature($p['snapshot'])===$newPhoto&&hash_equals((string)$p['start_payload'],(string)$plan['token']),'final_media_registry_mismatch');
        $resolved=ChannelPostEntryService::resolve($platform,$plan['token'],$registry);need(is_array($resolved)&&(int)$resolved['version_id']===(int)$p['version_id']&&$resolved['source_key']===$t['source']&&($resolved['snapshot']['text']??'')===$row['text']&&photoSignature($resolved['snapshot'])===$newPhoto,'existing_token_new_media_version_mismatch');
        $r=['hotel_id'=>1095,'logical_id'=>$row['logical_id'],'platform'=>$platform,'message_id'=>$proof['message_id'],'publication_id'=>$proof['publication_id'],'previous_version_id'=>$proof['version_id'],'version_id'=>(int)$p['version_id'],'post_url'=>$proof['post_url'],'button_url'=>$proof['button_url'],'button_text'=>'✈️ Узнать стоимость тура','text_sha256'=>$row['text_sha256'],'previous_photo_signature'=>$proof['photo_signature'],'photo_signature'=>$newPhoto,'photo_sha256'=>$row['photo_sha256'],'photo_url'=>$row['photo_url'],'source_key'=>$t['source'],'state'=>'sent','caption_unchanged'=>true,'body_photo_button_verified'=>true,'existing_message_reused'=>true,'media_updated'=>true];$out['posts'][]=$r;$state['posts'][$platform]['state']='confirmed';$state['posts'][$platform]['receipt']=$r;durable($lock,$state);
    }
    $out['ok']=true;$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['media_updates']=2;$state['state']='complete';durable($lock,$state);
    // One current read-only dedup projection for the four explicitly bound next candidates; no content or provider request.
    try{
        $candidates=[['hotel_id'=>24462,'aliases'=>['UGA JUNGLE BEACH','Jungle Beach an Uga Experience','Jungle Beach']],['hotel_id'=>43236,'aliases'=>['SUN SIYAM PASIKUDAH']],['hotel_id'=>101694,'aliases'=>['NOOE MALDIVES KUNAAVASHI','NOOE KUNAAVASHI']],['hotel_id'=>2469,'aliases'=>['NIKA ISLAND RESORT','NIKA ISLAND']]];
        $q=$pdo->query("SELECT pc.platform,v.state,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) AS native_id,SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.text')),CHAR(10),1) AS first_line,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.post_url')) AS post_url FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) ORDER BY pp.id LIMIT 2000");$all=$q->fetchAll(PDO::FETCH_ASSOC);need(count($all)<2000,'candidate_registry_limit_reached');$fold=static fn($v)=>preg_replace('/[^\pL\pN]/u','',mb_strtolower((string)$v,'UTF-8'));
        $clear=[];foreach($candidates as $candidate){$matches=[];foreach($all as $r){$hit=(int)$r['native_id']===$candidate['hotel_id'];$title=$fold($r['first_line']);foreach($candidate['aliases'] as $alias)if(str_contains($title,$fold($alias)))$hit=true;if($hit)$matches[]=['platform'=>$r['platform'],'state'=>$r['state'],'post_url'=>$r['post_url']];}$clear[]=['native_hotel_id'=>$candidate['hotel_id'],'matches'=>$matches,'clear'=>count($matches)===0];}$out['next_candidate_registry_clearance']=['state'=>'CURRENT_BOUNDED_REGISTRY_READ','read_at_utc'=>gmdate('c'),'rows'=>$clear,'provider_calls'=>0,'writes'=>0];
    }catch(Throwable $e){$out['next_candidate_registry_clearance']=['state'=>'READ_NOT_CONFIRMED','error'=>preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'candidate_registry_read_failed','provider_calls'=>0,'writes'=>0];}
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_side_star_media_update_failed';}
if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
