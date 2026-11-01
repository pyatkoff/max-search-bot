<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'content100-bot-publication-20261101-v1','mode'=>'read_only_preflight','ok'=>false,'post_write_attempts'=>0,'channels'=>[]];
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
        require_once getcwd().'/integrations/ChannelPostApiTransport.php';
        require_once getcwd().'/services/ChannelPostButtonTransport.php';
    } finally {ob_end_clean();}
    $pdo=ConversationDb::connection();$pdo->exec('START TRANSACTION READ ONLY');
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $migration){
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$migration]);
        if($q->fetchColumn()!==hash_file('sha256',getcwd().'/migrations/'.$migration))throw new RuntimeException('publishing_schema_not_accepted');
    }
    $out['publishing_surface']='unchanged_accepted_PR1260';$out['publishing_schema_ready']=true;
    $q=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');$q->execute(['083_hotel_work_retirement.sql']);$out['unrelated083_pending']=(int)$q->fetchColumn()===0;
    $registry=new ChannelPostRegistryService($pdo);
    foreach(['max','telegram'] as $platform){
        $identity=ChannelPostRuntime::identity($platform);
        $row=['platform'=>$platform,'target'=>$platform==='max'?'https://max.ru/channel_anytour':'https://t.me/any_toour','ready'=>false];
        if(!$identity){$row['reason']='runtime_identity_unavailable';$out['channels'][]=$row;continue;}
        $row['configured_bot']=$identity['bot_key'];
        $expectedBot=$platform==='max'?'id9704048781_1_bot':'anyru_bot';
        if($identity['project_key']!=='anytour'||$identity['bot_key']!==$expectedBot){$row['reason']='configured_bot_identity_mismatch';$out['channels'][]=$row;continue;}
        $response=ChannelPostApiTransport::buttonRequest($platform,$platform==='max'?'GET':'POST',$platform==='max'?'/me':'getMe',[],$platform==='max'?null:[]);
        $me=$platform==='max'?($response['data']??null):($response['data']['result']??null);
        $row['provider_http']=$response['http']??0;$row['provider_bot_username']=is_array($me)?($me['username']??null):null;
        if(!empty($response['transport_error'])||$response['http']!==200||!is_array($me)||strtolower((string)($me['username']??''))!==strtolower($expectedBot)){$row['reason']='provider_bot_identity_unconfirmed';$out['channels'][]=$row;continue;}
        $id=$platform==='max'?'-80492244209683':null;
        if($platform==='telegram'){
            $r=ChannelPostApiTransport::buttonRequest('telegram','POST','getChat',[],['chat_id'=>'@any_toour']);
            $chat=$r['data']['result']??null;
            if(!empty($r['transport_error'])||$r['http']!==200||!is_array($chat)||($chat['type']??'')!=='channel'||strtolower((string)($chat['username']??''))!=='any_toour'){$row['reason']='telegram_channel_not_resolved_for_bot';$out['channels'][]=$row;continue;}
            $id=(string)$chat['id'];
            $member=ChannelPostApiTransport::buttonRequest('telegram','POST','getChatMember',[],['chat_id'=>$id,'user_id'=>$me['id']]);
            $rights=$member['data']['result']??[];
            $row['bot_membership']=$rights['status']??'unconfirmed';
            $row['can_post_messages']=($rights['can_post_messages']??false)===true;
            $row['can_edit_messages']=($rights['can_edit_messages']??false)===true;
        }
        $row['channel_id']=$id;
        $channel=$registry->channel('anytour',$platform,$expectedBot,$id,false);
        if(!is_array($channel)){
            $row['reason']='channel_not_registered';
            $q=ConversationDb::connection()->prepare("SELECT s.source_key,s.display_name,s.handling_mode FROM conversation_sources s JOIN projects p ON p.id=s.project_id WHERE p.project_key=? AND s.channel=? AND s.is_active=1 AND s.handling_mode IN ('manager','ask') ORDER BY s.id LIMIT 20");
            $q->execute(['anytour',$platform]);$row['existing_source_choices']=$q->fetchAll(PDO::FETCH_ASSOC);
            $out['channels'][]=$row;continue;
        }
        foreach(['id','config_version','source_key','button_text','is_enabled'] as $key)$row[$key]=$channel[$key]??null;
        $verification=(new ChannelPostButtonTransport())->verifyChannel($channel);
        $row['verification_state']=$verification['state']??'unconfirmed';
        $row['ready']=($verification['state']??'')==='ready';
        if($platform==='telegram'&&empty($row['can_post_messages'])){$row['ready']=false;$row['reason']='bot_requires_channel_posting_right';}
        $out['channels'][]=$row;
    }
    $out['ok']=true;$out['installed_sha']=EXPECTED_INSTALLED_SHA;
} catch(Throwable $e){$out['error']=in_array($e->getMessage(),['installed_source_changed','publishing_surface_changed','publishing_schema_not_accepted'],true)?$e->getMessage():'bounded_read_only_preflight_failed';}
if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
