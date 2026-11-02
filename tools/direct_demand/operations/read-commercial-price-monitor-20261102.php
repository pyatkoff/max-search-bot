<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-monitor-20261102-read1','mode'=>'read_existing_monitored_price_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
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



    require_once getcwd().'/services/HotelDatabase.php';
    insist(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $catalog=HotelDatabase::connection();

    $scope=[
        ['anytour_id'=>1554,'local_tv_id'=>9374,'expected_name'=>"SUNRISE ARABIAN BEACH RESORT"],
        ['anytour_id'=>81,'local_tv_id'=>241,'expected_name'=>"ALBATROS MAKADI RESORT"],
        ['anytour_id'=>80,'local_tv_id'=>240,'expected_name'=>"HAPPY LIFE VILLAGE"],
        ['anytour_id'=>99,'local_tv_id'=>268,'expected_name'=>"JAZ MAKADI SARAYA RESORT (EX. IBEROTEL MAKADI SARAYA RESORT)"],
        ['anytour_id'=>877,'local_tv_id'=>1981,'expected_name'=>"JAZ NEO NAAMA BAY (EX. SOLYMAR NAAMA BAY)"],
        ['anytour_id'=>1654,'local_tv_id'=>12111,'expected_name'=>"MOVENPICK RESORT SHARM EL SHEIKH NAAMA BAY"],
        ['anytour_id'=>228,'local_tv_id'=>498,'expected_name'=>"TITANIC BEACH SPA & AQUA PARK"],
        ['anytour_id'=>4101,'local_tv_id'=>157359,'expected_name'=>"AVIA RESORT & SPA"],
        ['anytour_id'=>477,'local_tv_id'=>1077,'expected_name'=>"CLUB AQUA PLAZA (EX. SUNEOCLUB AQUA PLAZA)"],
        ['anytour_id'=>3726,'local_tv_id'=>109900,'expected_name'=>"DREAM FUN WORLD"],
        ['anytour_id'=>2077,'local_tv_id'=>28666,'expected_name'=>"PRIMASOL HANE FAMILY RESORT (EX. FAMILY HANE RESORT)"],
        ['anytour_id'=>2432,'local_tv_id'=>46672,'expected_name'=>"JADORE DELUXE HOTEL & SPA"],
        ['anytour_id'=>3742,'local_tv_id'=>111129,'expected_name'=>"SIDE MOON PALACE HOTEL"],
        ['anytour_id'=>3737,'local_tv_id'=>111045,'expected_name'=>"VOX MARIS RESORT ADULTS ONLY 16+"],
        ['anytour_id'=>516,'local_tv_id'=>1146,'expected_name'=>"AKRA ANTALYA"],
    ];
    $required=['catalog_hotels','catalog_countries','catalog_departures','tour_price_observations'];
    $tableQuery=$catalog->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?,?)");
    $tableQuery->execute($required);
    $present=$tableQuery->fetchAll(PDO::FETCH_COLUMN);
    insist(count(array_intersect($required,$present))===4,'price_data_tables_unavailable');
    $catalog->exec('START TRANSACTION READ ONLY');
    $out['read_at']=$catalog->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
    $out['observation_window_hours']=72;
    $out['hotels']=[];
    $identity=$catalog->prepare("SELECT h.id,h.name,h.is_active,h.country_id,c.name AS country FROM catalog_hotels h LEFT JOIN catalog_countries c ON c.id=h.country_id WHERE h.id=? LIMIT 1");
    $totals=$catalog->prepare("SELECT COUNT(*) AS observation_count,MAX(observed_at) AS last_observed_at FROM tour_price_observations WHERE hotel_id=? AND source='scheduled_monitor' AND observed_at>=DATE_SUB(NOW(),INTERVAL 72 HOUR) AND departure_date>=CURDATE() AND price>0 AND currency='RUB'");
    $prices=$catalog->prepare("SELECT o.id AS observation_id,o.departure_id,d.name AS departure_name,o.departure_date,o.nights,o.adults,o.children_count,o.child_ages_signature,o.meal_id,o.room_type,o.price AS observed_price,o.fuel_charge,o.currency,o.observed_at FROM tour_price_observations o LEFT JOIN catalog_departures d ON d.id=o.departure_id WHERE o.hotel_id=? AND o.source='scheduled_monitor' AND o.observed_at>=DATE_SUB(NOW(),INTERVAL 72 HOUR) AND o.departure_date>=CURDATE() AND o.price>0 AND o.currency='RUB' ORDER BY o.observed_at DESC,o.price ASC,o.id DESC LIMIT 6");
    $fold=static function(string $name):string{
        $name=preg_replace('/\\s*\\(.*?\\)/u','',$name)??$name;
        return trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower($name,'UTF-8'))??'');
    };
    foreach($scope as $binding){
        $id=$binding['local_tv_id'];$identity->execute([$id]);$hotel=$identity->fetch(PDO::FETCH_ASSOC);
        $row=['anytour_id'=>$binding['anytour_id'],'local_tv_id'=>$id,'expected_name'=>$binding['expected_name'],'identity_state'=>'catalog_missing','observations'=>[]];
        if($hotel){
            $row['catalog']=$hotel;
            $row['identity_state']=(int)$hotel['is_active']===1&&$fold((string)$hotel['name'])===$fold($binding['expected_name'])?'accepted_bridge_current_name_matches':'current_name_or_activity_requires_review';
            if($row['identity_state']==='accepted_bridge_current_name_matches'){
                $totals->execute([$id]);$row['coverage']=$totals->fetch(PDO::FETCH_ASSOC);
                $prices->execute([$id]);$row['observations']=$prices->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        $row['current_availability_verified']=false;
        $row['final_total_verified']=false;
        $out['hotels'][]=$row;
    }
    $catalog->rollBack();
    $out['installed_sha']=EXPECTED_INSTALLED_SHA;
    $out['price_block_updates']=0;
    $out['ok']=true;
} catch(Throwable $e){
    if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();
    $out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,100}\\z/D',$e->getMessage())?$e->getMessage():'bounded_price_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
