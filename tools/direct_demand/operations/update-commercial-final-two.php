<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-hotel-final-two-update-20261102-v1','mode'=>'update_existing_remaining_commercial_posts','ok'=>false,'post_write_attempts'=>0,'channels'=>[]];

function need(bool $v,string $reason):void{if(!$v)throw new RuntimeException($reason);}
function durable($f,array $s):void{need(fseek($f,0,SEEK_END)===0,'receipt_write_failed');$raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";need(fwrite($f,$raw)===strlen($raw)&&fflush($f),'receipt_write_failed');if(function_exists('fsync'))need(fsync($f),'receipt_sync_failed');}
function tg(string $method,array $body):array{
 need(in_array($method,['editMessageCaption','editMessageReplyMarkup'],true),'telegram_method_outside_update_scope');
 $ch=curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/'.$method);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45]);$raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=is_string($raw)?json_decode($raw,true):null;return ['http'=>$http,'data'=>is_array($data)?$data:[]];
}
function readMax(string $id):array{need(preg_match('/\A[A-Za-z0-9_.-]{1,191}\z/',$id)===1,'message_id_invalid');$r=ChannelPostApiTransport::buttonRequest('max','GET','/messages/'.$id,[],null);need(empty($r['transport_error'])&&(int)$r['http']===200&&is_array($r['data']),'max_read_failed');return $r['data'];}
function photoSignature(array $snapshot):string{$media=$snapshot['attachments']??[];need(count($media)===1&&($media[0]['type']??'')==='image','expected_one_photo');$m=$media[0];$id=(string)($m['photo_id']??$m['file_unique_id']??'');need($id!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$id)===1,'photo_stable_id_missing');return (string)($m['provider']??'').':'.$id;}
function snapshotMatches(array $p,array $snap,array $plan):bool{return ($snap['text']??'')===$plan['text']&&photoSignature($snap)===$plan['photo_signature']&&($snap['post_url']??'')===$plan['post_url']&&ChannelPostIncomingAdapter::containsOnlyButton($snap,ChannelPostButtonTransport::buttonUrl($p),'✈️ Узнать стоимость тура');}
function safeResult(array $p,array $plan):array{return ['logical_id'=>$plan['logical_id'],'platform'=>$p['platform'],'channel_id'=>$p['channel_id'],'post_url'=>$plan['post_url'],'message_id'=>$plan['message_id'],'publication_id'=>(int)$p['publication_id'],'version_id'=>(int)$p['version_id'],'previous_version_id'=>$plan['old_version_id'],'button_url'=>ChannelPostButtonTransport::buttonUrl($p),'button_text'=>'✈️ Узнать стоимость тура','text_sha256'=>hash('sha256',$plan['text']),'photo_signature'=>$plan['photo_signature'],'source_key'=>$p['source_key'],'content_updated'=>$plan['update_text'],'state'=>'sent','body_photo_button_verified'=>true,'existing_message_reused'=>true];}

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


    $payload=json_decode(base64_decode(MANIFEST_BASE64,true),true,64,JSON_THROW_ON_ERROR);need(($payload['operation']??'')==='commercial-hotel-final-two-update-20261102-v1'&&count($payload['rows']??[])===2,'manifest_scope_changed');$rows=$payload['rows'];
    foreach($rows as $r)need(in_array($r['key'],['rehana','solara'],true)&&is_string($r['new_text'])&&strlen(mb_convert_encoding($r['new_text'],'UTF-16LE','UTF-8'))/2<=1024,'prepared_text_invalid');
    $pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $m){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$m]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$m),'publishing_schema_not_accepted');}
    $dir='/var/www/anytoour/data/channel-post-operations/commercial-hotel-final-two-update-20261102-v1';need(!is_link(dirname($dir))&&!is_link($dir),'invalid_operation_directory');if(!is_dir($dir))need(mkdir($dir,0700,true),'invalid_operation_directory');need(realpath($dir)===$dir,'invalid_operation_directory');
    $receiptPath=$dir.'/receipt.jsonl';need(!is_link($receiptPath),'invalid_receipt_path');$receiptExisted=is_file($receiptPath);$lock=fopen($receiptPath,'c+');need($lock!==false&&flock($lock,LOCK_EX|LOCK_NB),'operation_busy');$journal=stream_get_contents($lock);need(is_string($journal)&&(!$receiptExisted||$journal!==''),'empty_existing_receipt_unresolved');need($journal===''||str_ends_with($journal,"\n"),'partial_receipt_unresolved');$lines=$journal===''?[]:explode("\n",rtrim($journal,"\n"));$raw=$lines?end($lines):'';$manifestHash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $state=$raw===''?['operation'=>$payload['operation'],'manifest_hash'=>$manifestHash,'configs'=>[],'posts'=>[]]:json_decode($raw,true,64,JSON_THROW_ON_ERROR);need(($state['operation']??'')===$payload['operation']&&($state['manifest_hash']??'')===$manifestHash,'operation_scope_changed');durable($lock,$state);
    foreach($state['configs'] as $r)need(($r['state']??'')==='confirmed','prior_configuration_outcome_unresolved');foreach($state['posts'] as $r)need(($r['state']??'')==='confirmed','prior_edit_outcome_unresolved');
    $registry=new ChannelPostRegistryService($pdo);$automation=new ChannelPostAutomationService($registry);
    $old=json_decode(file_get_contents('/var/www/anytoour/data/channel-post-operations/catalog-hotel-six-20261026-v1/publication-receipt.json'),true,64,JSON_THROW_ON_ERROR);need(($old['operation']??'')==='channel-hotel-six-20261026-v1','old_post_evidence_missing');
    $targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];$channels=[];$plans=[];
    // Resolve all four existing messages and exact retained photos before any provider/configuration write.
    foreach($targets as $platform=>$t){
        $identity=ChannelPostRuntime::identity($platform);need(is_array($identity)&&$identity['project_key']==='anytour'&&$identity['bot_key']===$t['bot'],'configured_bot_changed');
        $channel=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);need(is_array($channel)&&(int)$channel['id']===$t['id']&&$channel['source_key']===$t['source']&&$channel['button_text']===$payload['button_text']&&(int)$channel['config_version']===2,'channel_configuration_changed');
        need(((new ChannelPostButtonTransport())->verifyChannel($channel)['state']??'')==='ready','provider_channel_rights_changed');$channels[$platform]=$channel;
        foreach($rows as $r){
            if($platform==='max'){$prior=$old['posts'][$r['key']]??null;$proof=$prior['receipt']??[];need(is_array($prior)&&($prior['state']??'')==='verified'&&($proof['cover_sha256']??'')===$r['cover_sha256'],'old_post_evidence_mismatch');$id=(int)($proof['publication_id']??0);$mid=(string)($proof['message_id']??'');}
            else{$id=(int)$r['telegram_publication_id'];$mid=(string)$r['telegram_message_id'];}
            $p=$registry->publication($id);need(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$t['bot']&&$p['channel_id']===$t['channel']&&$p['external_message_id']===$mid&&$p['source_key']===$t['source']&&$p['state']==='sent','existing_publication_identity_changed');
            $key=$platform.':'.$r['key'];$done=$state['posts'][$key]??null;
            need(($p['snapshot']['text']??'')===($done?$r['new_text']:$r['old_text']),'existing_caption_changed');
            $snap=$p['snapshot'];
            if($platform==='max'){$actual=ChannelPostIncomingAdapter::maxSnapshot(readMax($mid));need(is_array($actual)&&($actual['text']??'')===$snap['text']&&photoSignature($actual)===photoSignature($snap)&&($actual['post_url']??'')===($snap['post_url']??''),'existing_max_readback_changed');}
            $plans[$key]=['platform'=>$platform,'key'=>$r['key'],'logical_id'=>$r['logical_id'],'message_id'=>$mid,'publication_id'=>$id,'old_version_id'=>(int)$p['version_id'],'token'=>$p['start_payload'],'post_url'=>$snap['post_url'],'photo_signature'=>photoSignature($snap),'text'=>$r['new_text'],'update_text'=>$r['update_text']];
        }
    }
    $out['preflight_existing_messages']=count($plans);$out['configuration_write_attempts']=0;$out['content_edit_attempts']=0;$out['button_only_edit_attempts']=0;$out['posts']=[];
    foreach($plans as $key=>$plan){
        $out['current']=['platform'=>$plan['platform'],'logical_id'=>$plan['logical_id']];if(isset($state['posts'][$key])){$out['posts'][]=$state['posts'][$key]['receipt'];continue;}
        $platform=$plan['platform'];$t=$targets[$platform];$p=$registry->publication($plan['publication_id']);$c=$channels[$platform];need(is_array($p)&&(int)$p['version_id']===$plan['old_version_id']&&$p['state']==='sent'&&(int)$p['channel']['config_version']===2&&$p['channel']['button_text']===$payload['button_text']&&hash_equals((string)$p['start_payload'],(string)$plan['token']),'target_changed_before_edit');
        $state['posts'][$key]=['state'=>'edit_started','plan'=>$plan,'edit_request_start_ms'=>(int)floor(microtime(true)*1000)];durable($lock,$state);$requestStart=$state['posts'][$key]['edit_request_start_ms'];
        if($platform==='telegram'){
            $body=['chat_id'=>$t['channel'],'message_id'=>$plan['message_id'],'reply_markup'=>json_encode(['inline_keyboard'=>[[['text'=>$payload['button_text'],'url'=>ChannelPostButtonTransport::buttonUrl($p)]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
            if($plan['update_text']){$method='editMessageCaption';$body['caption']=$plan['text'];$out['content_edit_attempts']++;}else{$method='editMessageReplyMarkup';$out['button_only_edit_attempts']++;}
            $response=tg($method,$body);$state['posts'][$key]['provider_response']=$response;$state['posts'][$key]['state']='provider_response_recorded';durable($lock,$state);
            need($response['http']===200&&($response['data']['ok']??false)===true&&is_array($response['data']['result']??null),'telegram_edit_not_confirmed_no_retry');$message=$response['data']['result'];
            need((string)($message['chat']['id']??'')===$t['channel']&&(string)($message['message_id']??'')===$plan['message_id']&&($message['chat']['username']??'')==='any_toour','telegram_response_identity_changed');
            $snap=ChannelPostIncomingAdapter::telegramSnapshot($message);need(is_array($snap)&&snapshotMatches($p,$snap,$plan),'telegram_body_photo_button_mismatch');
            $received=$automation->receive('telegram',['edited_channel_post'=>$message],'anytour',$t['bot']);need(($received['recognized']??false)===true&&!empty($received['publication_id'])&&(int)$received['publication_id']===$plan['publication_id'],'telegram_registry_receive_failed');
            $p=$registry->publication($plan['publication_id']);need(is_array($p)&&$p['state']==='sent','telegram_registry_not_confirmed');
        }else{
            if($plan['update_text']){
                $parts=explode("\n",$plan['text'],2);$html='<b>'.htmlspecialchars($parts[0],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</b>'.(isset($parts[1])?"\n".htmlspecialchars($parts[1],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):'');$out['content_edit_attempts']++;
                // No attachments field: exact provider photo and existing keyboard are retained.
                $response=MaxTransport::request('https://platform-api2.max.ru',MAX_SEARCH_TOKEN,'PUT','/messages',['message_id'=>$plan['message_id']],['text'=>$html,'format'=>'html'],null);
                $state['posts'][$key]['provider_edit_returned']=is_array($response)||$response===true;$state['posts'][$key]['state']='text_edit_returned';durable($lock,$state);
                $message=readMax($plan['message_id']);$snap=ChannelPostIncomingAdapter::maxSnapshot($message);need(is_array($snap)&&($snap['text']??'')===$plan['text']&&photoSignature($snap)===$plan['photo_signature']&&($snap['post_url']??'')===$plan['post_url'],'max_content_edit_readback_mismatch');
                $snap['content_hash']=ChannelPostIncomingAdapter::contentFingerprint($snap,'',(string)$c['bot_url']);$snap['metadata']['source_event_id']='commercial-hotel-final-two-update-20261102-v1:'.$key;$snap['metadata']['observation_kind']='authorized_admin_content_edit';$snap['metadata']['operation_observed_at_ms']=(string)(int)floor(microtime(true)*1000);$snap['metadata']['source_revision_ms']=(string)$requestStart;$snap['metadata']['revision_clock_origin']='authorized_edit_request_start';
                $p=$registry->registerPublication('anytour','max',$t['bot'],$t['channel'],$plan['message_id'],$snap);
            }else{
                $message=readMax($plan['message_id']);$p=$registry->registerProviderObservation('anytour',$t['bot'],$c,$message,1);$out['button_only_edit_attempts']++;
            }
            need(is_array($p)&&(int)$p['publication_id']===$plan['publication_id']&&($p['snapshot']['text']??'')===$plan['text'],'max_registry_version_not_current');
            if($p['state']!=='sent'){$button=$automation->processPublication('anytour','max',$t['bot'],(int)$p['publication_id'],(int)$p['version_id']);$state['posts'][$key]['button_result']=$button;durable($lock,$state);need(($button['state']??'')==='sent','max_button_outcome_not_confirmed');}
            $p=$registry->publication($plan['publication_id']);$snap=ChannelPostIncomingAdapter::maxSnapshot(readMax($plan['message_id']));need(is_array($snap)&&snapshotMatches($p,$snap,$plan),'max_final_photo_body_button_mismatch');
        }
        need($p['state']==='sent'&&$p['platform']===$platform&&hash_equals((string)$p['start_payload'],(string)$plan['token'])&&($p['snapshot']['text']??'')===$plan['text']&&photoSignature($p['snapshot'])===$plan['photo_signature']&&($p['snapshot']['post_url']??'')===$plan['post_url'],'final_registry_context_mismatch');
        $resolved=ChannelPostEntryService::resolve($platform,$plan['token'],$registry);need(is_array($resolved)&&(int)$resolved['version_id']===(int)$p['version_id']&&($resolved['snapshot']['text']??'')===$plan['text']&&$resolved['source_key']===$t['source'],'existing_token_new_version_resolution_failed');
        $receipt=safeResult($p,$plan);$state['posts'][$key]['state']='confirmed';$state['posts'][$key]['receipt']=$receipt;durable($lock,$state);$out['posts'][]=$receipt;
        echo json_encode(['progress'=>'existing_post_updated','platform'=>$platform,'logical_id'=>$plan['logical_id'],'post_url'=>$plan['post_url'],'content_updated'=>$plan['update_text']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;flush();usleep(3200000);
    }
    $out['ok']=true;$out['new_posts_created']=0;$out['commercial_text_updates']=count(array_filter($out['posts'],static fn($p)=>$p['content_updated']));$out['button_only_updates']=count($out['posts'])-$out['commercial_text_updates'];$out['installed_sha']=EXPECTED_INSTALLED_SHA;unset($out['current']);$state['complete']=true;durable($lock,$state);
} catch(Throwable $e){$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/',$e->getMessage())?$e->getMessage():'bounded_existing_post_update_failed';}
if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
