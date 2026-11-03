<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-monitor-20261103-read3','mode'=>'read_existing_monitored_price_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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

    $scope=json_decode(<<<'_ANYTOUR_PRICE19_SCOPE_'
[
  {
    "local_tv_id": 310,
    "expected_name": "PICKALBATROS GOLF BEACH RESORT (EX. GOLF BEACH RESORT MANAGED BY RIXOS)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 469,
    "expected_name": "SULTAN GARDENS RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 67910,
    "expected_name": "RIXOS PREMIUM MAGAWISH SUITES & VILLAS",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 381,
    "expected_name": "REHANA ROYAL BEACH RESORT AQUA PARK & SPA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 68323,
    "expected_name": "SUNRISE SOLARA AQUA PARK RESORT (EX.CAESAR DREAMS RESORT BY SUNRISE)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 1971,
    "expected_name": "PICKALBATROS PALACE RESORT SHARM EL SHEIKH (EX. CYRENE GRAND)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 384,
    "expected_name": "JAZ NEO REEF MARSA (EX. SOLYMAR REEF MARSA)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 281,
    "expected_name": "JAZ MIRABEL BEACH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 75725,
    "expected_name": "JAZ COSTA MARES ADULT ONLY (EX. IBEROTEL COSTA MARES)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 249,
    "expected_name": "HILTON PLAZA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 315,
    "expected_name": "HURGADA MARRIOTT BEACH RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 465,
    "expected_name": "STELLA DI MARE BEACH HOTEL & SPA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 272,
    "expected_name": "CONTINENTAL HOTEL HURGHADA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 260,
    "expected_name": "PARK REGENCY SHARM EL SHEIKH (EX. HYATT REGENCY SSH)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 273,
    "expected_name": "PALM ROYALE SOMA BAY (EX. SENTIDO PALM ROYALE)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 408,
    "expected_name": "SAVOY SHARM EL SHEIKH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 441,
    "expected_name": "SIVA GRAND BEACH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 21477,
    "expected_name": "MOVENPICK WATERPARK RESORT & SPA SOMA BAY",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "local_tv_id": 60589,
    "expected_name": "JAZ NEO IVORY (EX. SOLYMAR IVORY SUITES)",
    "identity_provenance": "accepted_native_catalog_read"
  }
]
_ANYTOUR_PRICE19_SCOPE_,true,512,JSON_THROW_ON_ERROR);
    insist(count($scope)===19&&count(array_unique(array_column($scope,'local_tv_id')))===19,'price_scope_not_exact19');
    $catalog->exec('START TRANSACTION READ ONLY');
    $out['readback_epoch_utc']=time();
    $out['readback_at_utc']=gmdate('Y-m-d\\TH:i:s\\Z',$out['readback_epoch_utc']);
    $out['readback_clock_is_not_producer_observation_time']=true;
    $out['candidate_filter']=['departure_from'=>'2026-11-04','departure_to'=>'2027-02-02','adults'=>2,'children'=>0,'max_rows_per_hotel'=>2,'source'=>'scheduled_monitor','currency'=>'RUB'];
    // Reuse the closed read2 26-column contract without rediscovery.
    $extra=',o.created_at,UNIX_TIMESTAMP(o.created_at) AS created_at_stored_epoch_utc';
    $out['retained_clock_contracts']=[['column'=>'created_at','data_type'=>'timestamp','default'=>'CURRENT_TIMESTAMP','producer_observed_time_verified'=>false,'basis'=>'database_persistence_timestamp_default_not_producer_observation_clock']];
    $out['schema_contract_proof']='closed41603842788';
    $out['naive_observed_at_relabelled_as_utc']=false;
    $identity=$catalog->prepare("SELECT h.id,h.name,h.is_active,h.country_id,c.name AS country FROM catalog_hotels h LEFT JOIN catalog_countries c ON c.id=h.country_id WHERE h.id=? LIMIT 1");
    $predicate="hotel_id=? AND source='scheduled_monitor' AND departure_date BETWEEN ? AND ? AND adults=2 AND children_count=0 AND child_ages_signature='' AND price>0 AND currency='RUB'";
    $totals=$catalog->prepare("SELECT COUNT(*) AS retained_candidate_count,MAX(id) AS last_inserted_observation_id,MAX(observed_at) AS raw_last_observed_at FROM tour_price_observations WHERE ".$predicate);
    $prices=$catalog->prepare("SELECT o.id AS observation_id,SHA2(COALESCE(o.tour_id,''),256) AS retained_tour_fingerprint,o.departure_id,d.name AS departure_name,o.departure_date,o.nights,o.adults,o.children_count,o.child_ages_signature,o.meal_id,o.room_type,o.price AS raw_observed_price,o.fuel_charge,o.currency,o.observed_at".$extra." FROM tour_price_observations o LEFT JOIN catalog_departures d ON d.id=o.departure_id WHERE o.".$predicate." ORDER BY o.id DESC LIMIT 2");
    $fold=static function(string $name):string{
        $name=preg_replace('/\\s*\\(.*?\\)/u','',$name)??$name;
        return trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower($name,'UTF-8'))??'');
    };
    $nativeMeals=[2=>'RO',3=>'BB',4=>'HB',5=>'FB',7=>'AI',9=>'UAI'];
    $out['native_meal_dictionary_proof']='closed41527602876';
    $out['hotels']=[];
    foreach($scope as $binding){
        $id=$binding['local_tv_id'];$identity->execute([$id]);$hotel=$identity->fetch(PDO::FETCH_ASSOC);
        $row=$binding+['identity_state'=>'catalog_missing','observations'=>[]];
        if($hotel){
            $row['catalog']=$hotel;
            $active=(int)$hotel['is_active']===1;
            $nameMatches=$fold((string)$hotel['name'])===$fold($binding['expected_name']);
            if($binding['identity_provenance']==='accepted_typed_bridge'){
                $eligible=$active&&$nameMatches;
                $row['identity_state']=$eligible?'accepted_bridge_current_name_matches':'typed_bridge_name_or_activity_requires_review';
            }else{
                $eligible=$active&&(int)$hotel['country_id']===1;
                $row['identity_state']=!$eligible?'native_country_or_activity_requires_review':($nameMatches?'accepted_native_catalog_id_current_name_matches':'accepted_native_id_current_name_requires_review');
            }
            $row['current_name_matches_expected']=$nameMatches;
            if($eligible){
                $params=[$id,'2026-11-04','2027-02-02'];
                $totals->execute($params);$row['coverage']=$totals->fetch(PDO::FETCH_ASSOC);
                $prices->execute($params);$rows=$prices->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as &$candidate){
                    $candidate['native_meal_label']=$nativeMeals[(int)$candidate['meal_id']]??null;
                    $candidate['final_total_verified']=false;
                    $candidate['current_availability_verified']=false;
                    $candidate['producer_observed_epoch_verified']=false;
                }
                unset($candidate);
                $row['observations']=$rows;
            }
        }
        $row['final_total_verified']=false;$row['current_availability_verified']=false;
        $out['hotels'][]=$row;
    }
    $catalog->rollBack();
    $out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['price_block_updates']=0;$out['ok']=true;
} catch(Throwable $e){
    if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();
    $out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,100}\\z/D',$e->getMessage())?$e->getMessage():'bounded_price_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
