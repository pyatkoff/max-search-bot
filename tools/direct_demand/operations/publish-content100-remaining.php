<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'content100-bot-publication-20261101-v1','mode'=>'publish_remaining_approved_content100','ok'=>false,'post_write_attempts'=>0,'channels'=>[]];

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
function safeReceipt(array $p,array $row,string $platform,bool $reused):array{
 insist(($p['state']??'')==='sent'&&($p['snapshot']['text']??'')===$row['text'],'registry_post_not_confirmed');
 $media=$p['snapshot']['attachments']??[];insist(count($media)===1&&($media[0]['type']??'')==='image','registry_photo_missing');
 $url=$p['snapshot']['post_url']??'';$pattern=$platform==='telegram'?'~\Ahttps://t\.me/any_toour/[1-9][0-9]*\z~D':'~\Ahttps://max\.ru/(?:channel_anytour|c/-80492244209683)/[A-Za-z0-9_-]{1,100}\z~D';insist(preg_match($pattern,$url)===1,'canonical_post_url_missing');
 return ['logical_id'=>$row['logical_id'],'platform'=>$platform,'channel_id'=>(string)$p['channel_id'],'message_id'=>(string)$p['external_message_id'],'post_url'=>$url,'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'source_key'=>$p['source_key'],'text_sha256'=>$row['text_sha256'],'photo_sha256'=>$row['photo_sha256'],'body_photo_button_verified'=>true,'state'=>'sent','reused'=>$reused];
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
        "services/ChannelPostAutomationService.php"=>"a6854a8df4ac57ca2b5a50856786c03205b75357",
        "services/ChannelPostButtonTransport.php"=>"16d4e16bb13058e6563f1e76c0eafdf8b5246110",
        "services/ChannelPostEntryService.php"=>"f0e04e9b4e0ed6859ced96279cbf0619931596e6",
        "services/ChannelPostMaxAlbumRecovery.php"=>"daca76263ab438e93c8a4617edbe66a81f046191",
        "services/ChannelPostMediaService.php"=>"f794efd0f6f24df916e18909ff6281b0bc928433",
        "services/ChannelPostMemberDeliveryService.php"=>"20c1d7984898b2a5996a5f9e20e4d2d8c20b4778",
        "services/ChannelPostReconciliationService.php"=>"35f9261c6d51d0338f4b38c67ce2bc8f85bead70",
        "services/ChannelPostReconciliationState.php"=>"d9583ceca80d1424a44ade3561c21282a23a52f0",
        "services/ChannelPostRegistryService.php"=>"ac9b669173d803d3385a03a5efc7822f181e3839",
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


    $payload=json_decode(base64_decode(MANIFEST_BASE64,true),true,64,JSON_THROW_ON_ERROR);
    insist(($payload['operation']??'')==='content100-bot-publication-20261101-v1'&&count($payload['rows']??[])===100,'approved_manifest_invalid');$rows=$payload['rows'];
    foreach($rows as $row)insist(preg_match('/\A[a-z0-9-]{1,100}\z/',$row['logical_id'])===1&&hash('sha256',$row['text'])===$row['text_sha256']&&preg_match('/\A[a-f0-9]{64}\z/',$row['photo_sha256'])===1&&strlen(mb_convert_encoding($row['text'],'UTF-16LE','UTF-8'))/2<=1024,'prepared_row_changed');
    $pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $migration){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$migration]);insist($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$migration),'publishing_schema_not_accepted');}
    $registry=new ChannelPostRegistryService($pdo);$automation=new ChannelPostAutomationService($registry);
    $dir='/var/www/anytoour/data/channel-post-operations/content100-bot-publication-20261101-v1';insist(is_dir($dir)&&!is_link($dir)&&realpath($dir)===$dir,'operation_directory_invalid');
    $batchLock=fopen($dir.'/remaining-library.lock','c');insist($batchLock&&flock($batchLock,LOCK_EX|LOCK_NB),'publication_operation_busy');
    $targets=['telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10],'max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9]];
    $channels=[];$existing=[];
    foreach($targets as $platform=>$t){
        $identity=ChannelPostRuntime::identity($platform);insist(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===$t['bot'],'configured_bot_changed');
        $c=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);insist(is_array($c)&&(int)$c['id']===$t['id']&&(int)$c['config_version']===1&&$c['source_key']===$t['source']&&$c['button_text']==='✈️ Рассчитать на мои даты','channel_configuration_changed');
        insist(((new ChannelPostButtonTransport())->verifyChannel($c)['state']??'')==='ready','channel_rights_not_ready');$channels[$platform]=$c;
        $q=$pdo->prepare('SELECT pp.id,v.snapshot_json FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id WHERE pp.post_channel_id=? ORDER BY pp.id LIMIT 2000');$q->execute([$t['id']]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$s=json_decode($r['snapshot_json'],true);$hash=hash('sha256',(string)($s['text']??''));$existing[$platform][$hash][]=(int)$r['id'];}
    }
    // Read all approved media before the first new channel send; cache exact bytes.
    foreach($rows as $row)photo($row,$dir);
    $out['posts']=[];$out['reused']=0;$out['new_posts']=0;
    foreach($targets as $platform=>$t){foreach($rows as $row){
        $out['current']=['platform'=>$platform,'logical_id'=>$row['logical_id']];$path=$dir.'/'.$platform.'-'.$row['logical_id'].'-bulk.json';
        if(is_file($path)){
            $saved=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);insist(($saved['text_sha256']??'')===$row['text_sha256']&&($saved['photo_sha256']??'')===$row['photo_sha256'],'prior_receipt_content_changed');
            insist(($saved['state']??'')==='confirmed'&&is_array($saved['receipt']??null),'prior_send_or_button_unknown_no_retry');
            $out['posts'][]=$saved['receipt'];$out['reused']++;continue;
        }
        $matches=$existing[$platform][$row['text_sha256']]??[];insist(count($matches)<=1,'existing_duplicate_text_requires_review');
        if(count($matches)===1){
            $p=$registry->publication($matches[0]);insist(is_array($p)&&($p['state']??'')==='sent','existing_post_not_confirmed');
            $verified=!empty($p['receipt']['button_verified'])&&(!empty($p['receipt']['content_preserved'])||!empty($p['receipt']['verified']));
            // Existing accepted six/MAX and Rixos/TG receipts are authoritative; do not resend/retest their unchanged path.
            if(!$verified&&$platform==='max'){$old=$dir.'/../catalog-hotel-six-20261026-v1/publication-receipt.json';if(is_file($old)){$oldState=json_decode(file_get_contents($old),true);foreach(($oldState['posts']??[]) as $entry){$er=$entry['receipt']??[];if(($entry['state']??'')==='verified'&&($er['message_id']??'')===$p['external_message_id']&&($er['text_sha256']??'')===$row['text_sha256']&&($er['cover_sha256']??'')===$row['photo_sha256']&&!empty($er['body_photo_button_verified']))$verified=true;}}}
            insist($verified,'existing_button_evidence_requires_reconciliation');$receipt=safeReceipt($p,$row,$platform,true);persist($path,['state'=>'confirmed','text_sha256'=>$row['text_sha256'],'photo_sha256'=>$row['photo_sha256'],'receipt'=>$receipt]);$out['posts'][]=$receipt;$out['reused']++;continue;
        }
        $c=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);insist(is_array($c)&&(int)$c['config_version']===1&&$c['source_key']===$t['source'],'channel_configuration_changed');
        $file=photo($row,$dir);$mime=getimagesize($file)['mime'];$state=['state'=>'prepared','text_sha256'=>$row['text_sha256'],'photo_sha256'=>$row['photo_sha256']];
        $upload=$platform==='max'?maxPhoto($file,$row,$dir):null;
        $state['state']='send_started';persist($path,$state);$out['post_write_attempts']++;
        if($platform==='telegram'){
            $ch=curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/sendPhoto');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['chat_id'=>$t['channel'],'caption'=>$row['text'],'photo'=>new CURLFile($file,$mime,'hotel-photo.'.(['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]))],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
            $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$response=is_string($raw)?json_decode($raw,true):null;
            if($http!==200||!is_array($response)||($response['ok']??false)!==true||!is_array($response['result']??null)){$state['state']='send_outcome_unconfirmed';$state['provider_http']=$http;$state['provider_error_code']=$response['error_code']??null;$state['retry_after_seconds']=$response['parameters']['retry_after']??null;persist($path,$state);throw new RuntimeException('telegram_send_unconfirmed_no_retry');}
            $m=$response['result'];$state['message']=$m;$state['state']='photo_sent';persist($path,$state);
            insist((string)($m['chat']['id']??'')===$t['channel']&&($m['chat']['username']??'')==='any_toour'&&($m['caption']??'')===$row['text']&&!empty($m['photo']),'telegram_post_readback_mismatch');
            $snapshot=ChannelPostIncomingAdapter::telegramSnapshot($m);insist(is_array($snapshot),'provider_snapshot_invalid');$snapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snapshot,'',(string)$c['bot_url']);$snapshot['metadata']['observation_kind']='provider_send_response';$snapshot['metadata']['source_event_id']='content100-bot-publication-20261101-v1:telegram:'.$row['logical_id'].':'.$m['message_id'];
            $p=$registry->registerPublication('anytour','telegram',$t['bot'],$t['channel'],(string)$m['message_id'],$snapshot);
        }else{
            $parts=explode("\n",$row['text'],2);$html='<b>'.htmlspecialchars($parts[0],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</b>'.(isset($parts[1])?"\n".htmlspecialchars($parts[1],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):'');
            $response=MaxTransport::request('https://platform-api2.max.ru',MAX_SEARCH_TOKEN,'POST','/messages',['chat_id'=>$t['channel'],'disable_link_preview'=>'true'],['text'=>$html,'format'=>'html','attachments'=>[['type'=>'image','payload'=>['token'=>$upload]]]],null);
            $m=is_array($response)?($response['message']??null):null;insist(is_array($m),'max_send_unconfirmed_no_retry');$state['message']=$m;$state['state']='photo_sent';persist($path,$state);
            insist((string)($m['recipient']['chat_id']??'')===$t['channel']&&($m['recipient']['chat_type']??'')==='channel'&&($m['body']['text']??'')===$row['text'],'max_post_identity_mismatch');
            $m=readMax((string)$m['body']['mid']);$p=$registry->registerProviderObservation('anytour',$t['bot'],$c,$m,1);
        }
        insist(is_array($p),'registry_observation_failed');$state['publication_id']=$p['publication_id'];$state['version_id']=$p['version_id'];$state['state']='registered';persist($path,$state);
        $fresh=$registry->publication((int)$p['publication_id']);
        if(($fresh['state']??'')!=='sent'){$button=$automation->processPublication('anytour',$platform,$t['bot'],(int)$p['publication_id'],(int)$p['version_id']);$state['button_result']=$button;$state['state']='button_attempted';persist($path,$state);insist(($button['state']??'')==='sent'&&!empty($button['receipt']['button_verified'])&&!empty($button['receipt']['content_preserved']),'button_outcome_not_confirmed_no_retry');}
        $fresh=$registry->publication((int)$p['publication_id']);insist(is_array($fresh)&&$fresh['state']==='sent','registry_post_not_confirmed');
        if($platform==='max'){$snap=ChannelPostIncomingAdapter::maxSnapshot(readMax((string)$fresh['external_message_id']));insist(is_array($snap)&&$snap['text']===$row['text']&&ChannelPostIncomingAdapter::containsOnlyButton($snap,ChannelPostButtonTransport::buttonUrl($fresh),(string)$c['button_text']),'max_photo_body_button_readback_failed');}
        $resolved=ChannelPostEntryService::resolve($platform,(string)$fresh['start_payload'],$registry);insist(is_array($resolved)&&(int)$resolved['publication_id']===(int)$fresh['publication_id']&&(int)$resolved['version_id']===(int)$fresh['version_id']&&$resolved['source_key']===$t['source']&&($resolved['snapshot']['text']??'')===$row['text'],'canonical_context_resolution_failed');
        $receipt=safeReceipt($fresh,$row,$platform,false);$state['state']='confirmed';$state['receipt']=$receipt;persist($path,$state);$out['posts'][]=$receipt;$out['new_posts']++;$existing[$platform][$row['text_sha256']]=[(int)$fresh['publication_id']];
        echo json_encode(['progress'=>'post_confirmed','platform'=>$platform,'logical_id'=>$row['logical_id'],'post_url'=>$receipt['post_url'],'completed'=>count($out['posts'])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;flush();usleep($platform==='telegram'?3100000:1100000);
    }}
    $out['ok']=true;$out['installed_sha']=EXPECTED_INSTALLED_SHA;unset($out['current']);
} catch(Throwable $e){
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/',$e->getMessage())?$e->getMessage():'bounded_publication_failed_no_retry';
}
if(isset($dir)&&is_dir($dir))persist($dir.'/remaining-library-summary.json',$out);
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
