<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-source-refresh-20261105-pass1-helper-drift-diagnostic1','ok'=>false,'provider_calls'=>0,'db_calls'=>0,'writes'=>0,'private_source_config_read'=>false,'secret_values_exported'=>0,'manual_capture'=>false];
try{
 if(PHP_SAPI!=='cli'||trim((string)shell_exec('git rev-parse HEAD'))!=='627a9c6284624e63b24287bea61277ef6c8f5364')throw new RuntimeException('installed_source_changed');
 if(trim((string)shell_exec('git status --porcelain --untracked-files=no'))!=='')throw new RuntimeException('installed_source_not_clean');
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

 $home=(string)getenv('HOME');if($home===''||$home[0]!=='/')throw new RuntimeException('existing_source_home_unavailable');
 $root=$home.'/www/anytoour.ru/data';if(!is_dir($root)||is_link($root))throw new RuntimeException('existing_normal_directory_unavailable');
 $pins=json_decode('{"db-v1.php":"ca7f11d6ec53e0cd3d4464e0f645f15b761bb734da1323689479838868d4abc4","price-observer-v1.php":"7e057905b1218815fc828c1c558f681eca67d079ad220ee3487b315661357843","top500-daily-plan-v1.php":"2a1440f21d65b6fb8bb5e3539e0edac83a72871135f36999148151d675446099","tourvisor-client-v1.php":"f669434c2b0bf6af0a68684d02eea964173f2d3c0408e49b94db9606e8273a17","top500-monthly-plan-v1.php":"3475098786dbd309cd8b2ce9a2c4f73ca6e6c323c19100e24ed6647bb1609a6c","local-tv-catalog-v1.php":"9dc60eb39100126d97307b83083d6a1b190cfdf8f684a18af47d8edd87b1c3b5","seasonal-page-gap-plan-v1.php":"3e1ef84ae88979f6e31691241dce13d01fdc40fd41138679043de3b83b616a5f","hotel-details-v1.php":"137bbf53ef87fab50505ecd41a075cb5f571193480da7f0e3face51190c0f77f"}',true,32,JSON_THROW_ON_ERROR);
 $rows=[];foreach($pins as $name=>$expected){$path=$root.'/'.$name;$file=is_file($path);$link=is_link($path);$actual=$file&&!$link?hash_file('sha256',$path):null;$rows[]=['file'=>$name,'regular_file'=>$file,'symlink'=>$link,'sha256'=>$actual,'matches_approved'=>is_string($actual)&&hash_equals($expected,$actual)];}
 $out['ok']=true;$out['installed_source']='627a9c6284624e63b24287bea61277ef6c8f5364';$out['publishing_blob_count']=21;$out['helper_hashes']=$rows;
}catch(Throwable $e){$out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$e->getMessage())?$e->getMessage():'readonly_helper_diagnostic_stopped';}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
