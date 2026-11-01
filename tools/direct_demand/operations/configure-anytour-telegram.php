<?php
declare(strict_types=1);
// Fixed, read-only preflight for the two directly authorized publication targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'content100-bot-publication-20261101-v1','mode'=>'configure_authorized_telegram_channel','ok'=>false,'post_write_attempts'=>0,'channels'=>[]];
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

    $pdo=ConversationDb::connection();
    foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $migration){
        $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$migration]);
        if($q->fetchColumn()!==hash_file('sha256',getcwd().'/migrations/'.$migration))throw new RuntimeException('publishing_schema_not_accepted');
    }
    $registry=new ChannelPostRegistryService($pdo);
    $dir='/var/www/anytoour/data/channel-post-operations/content100-bot-publication-20261101-v1';
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('receipt_directory_failed');
    $lock=fopen($dir.'/telegram-channel-config.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('operation_busy');
    $receipt=$dir.'/telegram-channel-config.json';
    $input=['project_key'=>'anytour','platform'=>'telegram','channel_id'=>'-1002262666616','source_key'=>'tg_anytour_msk','display_name'=>'AnyTour — @any_toour','button_text'=>'✈️ Рассчитать на мои даты','is_enabled'=>true,'expected_version'=>0];
    $current=$registry->channel('anytour','telegram','anyru_bot','-1002262666616',false);
    $matches=static function($r):bool{return is_array($r)&&$r['source_key']==='tg_anytour_msk'&&(int)$r['is_enabled']===1&&$r['button_text']==='✈️ Рассчитать на мои даты';};
    if($current!==null){
        if(!$matches($current))throw new RuntimeException('existing_channel_configuration_conflict');
        $out['configuration']='already_matches_no_write';
    }else{
        if(file_exists($receipt))throw new RuntimeException('prior_configuration_attempt_requires_reconciliation');
        $f=fopen($receipt,'x');if(!$f)throw new RuntimeException('receipt_create_failed');
        $started=json_encode(['state'=>'write_started','input'=>$input],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(fwrite($f,$started)!==strlen($started)||!fflush($f))throw new RuntimeException('receipt_persist_failed');
        if(function_exists('fsync')&&!fsync($f))throw new RuntimeException('receipt_sync_failed');fclose($f);
        $out['configuration_write_attempts']=1;
        $service=new ChannelPostAdminService($pdo,null,ChannelPostRuntime::botIdentities());
        $result=$service->save(2,$input);
        if(($result['ok']??false)!==true){$out['service_result']=$result;throw new RuntimeException('canonical_configuration_rejected');}
        $current=$registry->channel('anytour','telegram','anyru_bot','-1002262666616',false);
        if(!$matches($current))throw new RuntimeException('configuration_readback_failed');
        $out['configuration']='created_and_read_back';
        file_put_contents($receipt,json_encode(['state'=>'confirmed','result'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);
    }
    $out['channel']=['id'=>(int)$current['id'],'channel_id'=>$current['channel_id'],'source_key'=>$current['source_key'],'config_version'=>(int)$current['config_version'],'is_enabled'=>(bool)$current['is_enabled'],'button_text'=>$current['button_text']];
    $out['ok']=true;$out['installed_sha']=EXPECTED_INSTALLED_SHA;
} catch(Throwable $e){
    $allowed=['installed_source_changed','publishing_surface_changed','publishing_schema_not_accepted','receipt_directory_failed','operation_busy','existing_channel_configuration_conflict','prior_configuration_attempt_requires_reconciliation','receipt_create_failed','receipt_persist_failed','receipt_sync_failed','canonical_configuration_rejected','configuration_readback_failed'];
    $out['error']=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'bounded_channel_configuration_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
