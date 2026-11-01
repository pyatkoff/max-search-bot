<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'content100-bot-publication-20261101-v1','mode'=>'publish_one_authorized_rixos_telegram','ok'=>false,'post_write_attempts'=>0,'channels'=>[]];
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
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally {ob_end_clean();}

    $material=json_decode(base64_decode('eyJsb2dpY2FsX2lkIjoiYXQtaG90ZWwtcml4b3MiLCJjYXB0aW9uIjoiUml4b3MgUHJlbWl1bSBNYWdhd2lzaCBTdWl0ZXMgJiBWaWxsYXNcbtCV0LPQuNC/0LXRgiDCtyDQpdGD0YDQs9Cw0LTQsFxuXG7Qn9GA0L7RgdGC0L7RgNC90YvQtSDRgdGM0Y7RgtGLINC4INCy0LjQu9C70YssINC/0LXRgdGH0LDQvdGL0Lkg0L/Qu9GP0LYg0LggwqvRg9C70YzRgtGA0LAg0LLRgdGRINCy0LrQu9GO0YfQtdC90L7CuyDigJQg0LTQu9GPINC+0YLQtNGL0YXQsCDRgdC10LzRjNGR0Lkg0LjQu9C4INCy0LTQstC+0ZHQvC5cblxu4oCiINCf0LXRgNCy0LDRjyDQu9C40L3QuNGPINC4INGB0L7QsdGB0YLQstC10L3QvdGL0Lkg0L/Qu9GP0LYg0L/RgNC+0YLRj9C20ZHQvdC90L7RgdGC0YzRjiDQvtC60L7Qu9C+INC60LjQu9C+0LzQtdGC0YDQsC5cbuKAoiDQktGL0LHQvtGAINGA0LDQt9C80LXRidC10L3QuNGPOiDQvtGCINGB0YzRjtGC0L7QsiDQtNC+INCy0LjQu9C7Llxu4oCiINCW0LjQstCw0Y8g0LzRg9C30YvQutCwINC4INCy0LXRh9C10YDQvdC40LUg0YjQvtGDLlxuXG7QntGC0LXQu9GMINC00LvRjyDRgdC10LzQtdC5INC4INC/0LDRgC5cblxu0J3QsNC20LzQuNGC0LUgwqvQoNCw0YHRgdGH0LjRgtCw0YLRjCDQvdCwINC80L7QuCDQtNCw0YLRi8K7IOKAlCDQv9C+0LTQsdC10YDRkdC8INGC0YPRgCDRgSDQv9C10YDQtdC70ZHRgtC+0LwuIiwicGhvdG9fdXJsIjoiaHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL3B5YXRrb2ZmL21heC1zZWFyY2gtYm90L2VkMmI4MDM0ODg2MWZlMWZmMzEzYmQ4OTQ0YzE4MzMwNTk2ZWEwMzYvLmdpdGh1Yi90cmFmZmljLXB1Ymxpc2gvaG90ZWwtc2l4LTIwMjYxMDI2L3JpeG9zLnBuZyIsInBob3RvX3NoYTI1NiI6IjBiMTkyYTliYTJiMWRmMGUyOTFiMmM2YWQzZjYxMjYwMmFhYzFmM2QyOGJkMTA1ODdhMDBmODE2OTQ5ODBiYjUifQ=='),true,512,JSON_THROW_ON_ERROR);

    $pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $migration){
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$migration]);
        if($q->fetchColumn()!==hash_file('sha256',getcwd().'/migrations/'.$migration))throw new RuntimeException('publishing_schema_not_accepted');
    }
    $registry=new ChannelPostRegistryService($pdo);
    $channel=$registry->channel('anytour','telegram','anyru_bot','-1002262666616');
    if(!$channel||$channel['source_key']!=='tg_anytour_msk'||(int)$channel['config_version']!==1)throw new RuntimeException('channel_configuration_changed');
    $verification=(new ChannelPostButtonTransport())->verifyChannel($channel);
    if(($verification['state']??'')!=='ready')throw new RuntimeException('provider_rights_not_ready');
    $dir='/var/www/anytoour/data/channel-post-operations/content100-bot-publication-20261101-v1';
    if(!is_dir($dir))throw new RuntimeException('configuration_receipt_missing');
    $lock=fopen($dir.'/telegram-at-hotel-rixos.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('operation_busy');
    $path=$dir.'/telegram-at-hotel-rixos.json';
    if(file_exists($path))throw new RuntimeException('prior_publication_attempt_requires_reconciliation');
    // Reuse registry content if this exact prepared caption was independently published.
    $q=$pdo->prepare('SELECT pp.id,v.snapshot_json FROM post_publications pp JOIN post_versions v ON v.id=pp.current_version_id WHERE pp.post_channel_id=? ORDER BY pp.id LIMIT 1000');$q->execute([(int)$channel['id']]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$snap=json_decode($row['snapshot_json'],true);if(($snap['text']??'')===$material['caption'])throw new RuntimeException('matching_existing_publication_reconcile_instead');}
    $ch=curl_init($material['photo_url']);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $bytes=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($http!==200||!is_string($bytes)||!hash_equals($material['photo_sha256'],hash('sha256',$bytes)))throw new RuntimeException('approved_photo_verification_failed');
    $f=fopen($path,'x');if(!$f)throw new RuntimeException('once_receipt_create_failed');
    $started=json_encode(['state'=>'send_started','logical_id'=>$material['logical_id'],'caption_sha256'=>hash('sha256',$material['caption']),'photo_sha256'=>$material['photo_sha256']],JSON_THROW_ON_ERROR);
    if(fwrite($f,$started)!==strlen($started)||!fflush($f))throw new RuntimeException('once_receipt_persist_failed');if(function_exists('fsync')&&!fsync($f))throw new RuntimeException('once_receipt_sync_failed');fclose($f);
    $out['post_write_attempts']=1;
    if(!defined('TELEGRAM_BOT_TOKEN')||TELEGRAM_BOT_TOKEN==='')throw new RuntimeException('configured_bot_token_missing');
    $ch=curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/sendPhoto');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['chat_id'=>'-1002262666616','caption'=>$material['caption'],'photo'=>new CURLStringFile($bytes,'rixos.png','image/png')],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>50,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $response=is_string($raw)?json_decode($raw,true):null;
    if($http!==200||!is_array($response)||($response['ok']??false)!==true||!is_array($response['result']??null))throw new RuntimeException('publication_send_outcome_unconfirmed_do_not_retry');
    $message=$response['result'];
    $receipt=['state'=>'photo_sent','logical_id'=>$material['logical_id'],'message'=>$message];
    if(file_put_contents($path,json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('sent_receipt_storage_failed');
    if((string)($message['chat']['id']??'')!=='-1002262666616'||($message['chat']['username']??'')!=='any_toour'||($message['caption']??'')!==$material['caption']||empty($message['photo']))throw new RuntimeException('provider_post_identity_or_content_mismatch');
    $snapshot=ChannelPostIncomingAdapter::telegramSnapshot($message);if(!$snapshot)throw new RuntimeException('provider_snapshot_invalid');
    $snapshot['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snapshot,'',(string)$channel['bot_url']);
    $snapshot['metadata']['observation_kind']='provider_send_response';
    $snapshot['metadata']['source_event_id']='content100-bot-publication-20261101-v1:telegram:at-hotel-rixos:'.(string)$message['message_id'];
    $publication=$registry->registerPublication('anytour','telegram','anyru_bot','-1002262666616',(string)$message['message_id'],$snapshot);
    if(!$publication)throw new RuntimeException('publication_registration_failed');
    $receipt['publication_id']=$publication['publication_id'];$receipt['version_id']=$publication['version_id'];$receipt['state']='registered';file_put_contents($path,json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
    $result=(new ChannelPostAutomationService($registry))->processPublication('anytour','telegram','anyru_bot',(int)$publication['publication_id'],(int)$publication['version_id']);
    $receipt['button_result']=$result;$receipt['state']=($result['state']??'')==='sent'?'confirmed':'button_requires_reconciliation';file_put_contents($path,json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
    $fresh=$registry->publication((int)$publication['publication_id']);
    if(($result['state']??'')!=='sent'||empty($result['receipt']['button_verified'])||empty($result['receipt']['content_preserved'])||($fresh['state']??'')!=='sent')throw new RuntimeException('button_not_confirmed_no_automatic_retry');
    $out['publication']=['logical_id'=>$material['logical_id'],'platform'=>'telegram','channel_id'=>'-1002262666616','message_id'=>(string)$message['message_id'],'post_url'=>$fresh['snapshot']['post_url'],'publication_id'=>(int)$fresh['publication_id'],'version_id'=>(int)$fresh['version_id'],'source_key'=>'tg_anytour_msk','button_url'=>ChannelPostButtonTransport::buttonUrl($fresh),'button_verified'=>true,'content_preserved'=>true,'state'=>'sent'];
    $out['ok']=true;$out['installed_sha']=EXPECTED_INSTALLED_SHA;
} catch(Throwable $e){
    $allowed=['installed_source_changed','publishing_surface_changed','publishing_schema_not_accepted','channel_configuration_changed','provider_rights_not_ready','configuration_receipt_missing','operation_busy','prior_publication_attempt_requires_reconciliation','matching_existing_publication_reconcile_instead','approved_photo_verification_failed','once_receipt_create_failed','once_receipt_persist_failed','once_receipt_sync_failed','configured_bot_token_missing','publication_send_outcome_unconfirmed_do_not_retry','sent_receipt_storage_failed','provider_post_identity_or_content_mismatch','provider_snapshot_invalid','publication_registration_failed','button_not_confirmed_no_automatic_retry'];
    $out['error']=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'bounded_publication_failed_no_automatic_retry';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
