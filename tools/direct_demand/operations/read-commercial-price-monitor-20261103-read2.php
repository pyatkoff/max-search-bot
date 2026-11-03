<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-monitor-20261103-read2','mode'=>'read_existing_monitored_price_inputs','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0];
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

    $scope=json_decode(<<<'_ANYTOUR_PRICE90_SCOPE_'
[
  {
    "anytour_id": 1554,
    "local_tv_id": 9374,
    "expected_name": "SUNRISE ARABIAN BEACH RESORT",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 81,
    "local_tv_id": 241,
    "expected_name": "ALBATROS MAKADI RESORT",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 80,
    "local_tv_id": 240,
    "expected_name": "HAPPY LIFE VILLAGE",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 99,
    "local_tv_id": 268,
    "expected_name": "JAZ MAKADI SARAYA RESORT (EX. IBEROTEL MAKADI SARAYA RESORT)",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 877,
    "local_tv_id": 1981,
    "expected_name": "JAZ NEO NAAMA BAY (EX. SOLYMAR NAAMA BAY)",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 1654,
    "local_tv_id": 12111,
    "expected_name": "MOVENPICK RESORT SHARM EL SHEIKH NAAMA BAY",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 228,
    "local_tv_id": 498,
    "expected_name": "TITANIC BEACH SPA & AQUA PARK",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 4101,
    "local_tv_id": 157359,
    "expected_name": "AVIA RESORT & SPA",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 477,
    "local_tv_id": 1077,
    "expected_name": "CLUB AQUA PLAZA (EX. SUNEOCLUB AQUA PLAZA)",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 3726,
    "local_tv_id": 109900,
    "expected_name": "DREAM FUN WORLD",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 2077,
    "local_tv_id": 28666,
    "expected_name": "PRIMASOL HANE FAMILY RESORT (EX. FAMILY HANE RESORT)",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 2432,
    "local_tv_id": 46672,
    "expected_name": "JADORE DELUXE HOTEL & SPA",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 3742,
    "local_tv_id": 111129,
    "expected_name": "SIDE MOON PALACE HOTEL",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 3737,
    "local_tv_id": 111045,
    "expected_name": "VOX MARIS RESORT ADULTS ONLY 16+",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 516,
    "local_tv_id": 1146,
    "expected_name": "AKRA ANTALYA",
    "identity_provenance": "accepted_typed_bridge"
  },
  {
    "anytour_id": 482,
    "local_tv_id": 482,
    "expected_name": "SUNRISE Mamlouk Palace Resort",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 476,
    "local_tv_id": 476,
    "expected_name": "SUNRISE Garden Beach Resort",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 81173,
    "local_tv_id": 81173,
    "expected_name": "White Hills Resort",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 9373,
    "local_tv_id": 9373,
    "expected_name": "SUNRISE Diamond Beach Resort",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 32577,
    "local_tv_id": 32577,
    "expected_name": "SUNRISE Montemare Resort",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 4191,
    "local_tv_id": 4191,
    "expected_name": "Tropitel Sahl Hasheesh",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 416,
    "local_tv_id": 416,
    "expected_name": "SUNRISE Crystal Bay Resort",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 12112,
    "local_tv_id": 12112,
    "expected_name": "SUNRISE Aqua Joy Resort",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 478,
    "local_tv_id": 478,
    "expected_name": "SUNRISE Holidays Resort",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 77914,
    "local_tv_id": 77914,
    "expected_name": "SUNRISE TUCANA RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 237,
    "local_tv_id": 237,
    "expected_name": "SUNRISE ALMA BAY RESORT (EX. GRAND SEAS BY SUNRISE)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 32575,
    "local_tv_id": 32575,
    "expected_name": "SUNRISE ALORA AQUA PARK RESORT (EX. CAESAR PALACE)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 112914,
    "local_tv_id": 112914,
    "expected_name": "SUNRISE ANJUM RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 64895,
    "local_tv_id": 64895,
    "expected_name": "MERAKI RESORT ADULTS ONLY 16+",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 262,
    "local_tv_id": 262,
    "expected_name": "JAZ AQUAMARINE RESORT (EX. IBEROTEL AQUAMARINE RESORT)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 12117,
    "local_tv_id": 12117,
    "expected_name": "JAZ BLUEMARINE",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 232,
    "local_tv_id": 232,
    "expected_name": "JAZ ELITE CASA DEL MAR BEACH (EX. JAZ CASA DEL MAR BEACH)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 233,
    "local_tv_id": 233,
    "expected_name": "JAZ CASA DEL MAR RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 13872,
    "local_tv_id": 13872,
    "expected_name": "JAZ MAKADINA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 13873,
    "local_tv_id": 13873,
    "expected_name": "JAZ MAKADI OASIS RESORT & CLUB (EX. IBEROTEL MAKADI OASIS CLUB)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 280,
    "local_tv_id": 280,
    "expected_name": "JAZ MAKADI STAR & SPA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 456,
    "local_tv_id": 456,
    "expected_name": "JAZ NEO DAR EL MADINA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 265,
    "local_tv_id": 265,
    "expected_name": "JAZ LAMAYA RESORT (EX. IBEROTEL LAMAYA RESORT)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 454,
    "local_tv_id": 454,
    "expected_name": "JAZ SOLAYA (EX. SOL Y MAR SOLAYA)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 12119,
    "local_tv_id": 12119,
    "expected_name": "STEIGENBERGER AL DAU BEACH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 26044,
    "local_tv_id": 26044,
    "expected_name": "STEIGENBERGER AQUA MAGIC",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 66150,
    "local_tv_id": 66150,
    "expected_name": "STEIGENBERGER PURE LIFE STYLE ADULTS ONLY 16+",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 21475,
    "local_tv_id": 21475,
    "expected_name": "BARON PALACE RESORT SAHL HASHEESH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 1963,
    "local_tv_id": 1963,
    "expected_name": "BARON RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 358,
    "local_tv_id": 358,
    "expected_name": "RIXOS SHARM EL SHEIKH ADULTS ONLY",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 37668,
    "local_tv_id": 37668,
    "expected_name": "RIXOS PREMIUM SEAGATE",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 174,
    "local_tv_id": 174,
    "expected_name": "PICKALBATROS DANA BEACH RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 55574,
    "local_tv_id": 55574,
    "expected_name": "PICKALBATROS WHITE BEACH RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 383,
    "local_tv_id": 383,
    "expected_name": "JAZ GRAND MARSA (EX. JAZ GRAND RESTA)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 269,
    "local_tv_id": 269,
    "expected_name": "JAZ SAMAYA RESORT (EX. IBEROTEL SAMAYA)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 108090,
    "local_tv_id": 108090,
    "expected_name": "JAZ ELITE AMARA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 73342,
    "local_tv_id": 73342,
    "expected_name": "JAZ SOMA BEACH (EX. SOLYMAR SOMA BEACH)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 278,
    "local_tv_id": 278,
    "expected_name": "JAZ BELVEDERE",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 246,
    "local_tv_id": 246,
    "expected_name": "JAZ FAYROUZ RESORT (EX. HILTON SHARM FAYROUZ RESORT)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 263,
    "local_tv_id": 263,
    "expected_name": "STEIGENBERGER CORAYA BEACH ADULTS ONLY 16+",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 267,
    "local_tv_id": 267,
    "expected_name": "IBEROTEL MAKADI BEACH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 150,
    "local_tv_id": 150,
    "expected_name": "PICKALBATROS CITADEL RESORT SAHL HASHEESH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 172,
    "local_tv_id": 172,
    "expected_name": "PICKALBATROS SANDS PORT GHALIB",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 275,
    "local_tv_id": 275,
    "expected_name": "PICKALBATROS THE PALACE PORT GHALIB",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 173,
    "local_tv_id": 173,
    "expected_name": "PICKALBATROS OASIS PORT GHALIB",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 110,
    "local_tv_id": 110,
    "expected_name": "PICKALBATROS JUNGLE AQUA PARK RESORT NEVERLAND",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 57028,
    "local_tv_id": 57028,
    "expected_name": "PICKALBATROS AQUA PARK SHARM",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 125,
    "local_tv_id": 125,
    "expected_name": "PICKALBATROS AQUA BLU RESORT SHARM EL SHEIKH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 178,
    "local_tv_id": 178,
    "expected_name": "DESERT ROSE RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 371,
    "local_tv_id": 371,
    "expected_name": "REEF OASIS BEACH RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 132,
    "local_tv_id": 132,
    "expected_name": "BEACH ALBATROS RESORT HURGADA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 69155,
    "local_tv_id": 69155,
    "expected_name": "STEIGENBERGER RESORT ALAYA MARSA ALAM ADULTS ONLY",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 69409,
    "local_tv_id": 69409,
    "expected_name": "STEIGENBERGER RESORT RAS SOMA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 389,
    "local_tv_id": 389,
    "expected_name": "PICKALBATROS ROYAL MODERNA SHARM EL SHEIKH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 109,
    "local_tv_id": 109,
    "expected_name": "PICKALBATROS AQUA VISTA RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 293,
    "local_tv_id": 293,
    "expected_name": "PICKALBATROS LAGUNA VISTA BEACH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 107965,
    "local_tv_id": 107965,
    "expected_name": "PICKALBATROS LAGUNA CLUB RESORT (EX. ALBATROS LAGUNA VISTA GARDEN)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 393,
    "local_tv_id": 393,
    "expected_name": "PICKALBATROS ROYAL GRAND RESORT ADULTS ONLY 16+",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 78898,
    "local_tv_id": 78898,
    "expected_name": "PICKALBATROS BLU SPA RESORT HURGHADA ADULTS ONLY 16+",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 83105,
    "local_tv_id": 83105,
    "expected_name": "PICKALBATROS VITA RESORT PORTOFINO MARSA ALAM",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 79059,
    "local_tv_id": 79059,
    "expected_name": "PICKALBATROS WATER VALLEY RESORT NEVERLAND HURGHADA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 115918,
    "local_tv_id": 115918,
    "expected_name": "RIXOS RADAMIS SHARM EL SHEIKH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 56092,
    "local_tv_id": 56092,
    "expected_name": "GRAVITY HOTEL & AQUA PARK HURGHADA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 80964,
    "local_tv_id": 80964,
    "expected_name": "MERAKI RESORT SHARM EL SHEIKH",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 264,
    "local_tv_id": 264,
    "expected_name": "JAZ DAHABEYA (EX. IBEROTEL DAHABEYA)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 13869,
    "local_tv_id": 13869,
    "expected_name": "JAZ NEO SARAYA PALMS (EX. JAZ MAKADI SARAYA PALMS)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 245,
    "local_tv_id": 245,
    "expected_name": "JAZ SHARM DREAMS RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 43204,
    "local_tv_id": 43204,
    "expected_name": "STEIGENBERGER ALCAZAR",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 13870,
    "local_tv_id": 13870,
    "expected_name": "STEIGENBERGER MAKADI ADULTS ONLY",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 1983,
    "local_tv_id": 1983,
    "expected_name": "JAZ NEO SHARKS BAY (EX. JAZ SHARKS BAY)",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 111801,
    "local_tv_id": 111801,
    "expected_name": "JAZ ELITE ASTERIA",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 287,
    "local_tv_id": 287,
    "expected_name": "KEMPINSKI HOTEL SOMA BAY",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 435,
    "local_tv_id": 435,
    "expected_name": "SHERATON SOMA BAY RESORT",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 109149,
    "local_tv_id": 109149,
    "expected_name": "THE BREAKERS DIVING & SURFING LODGE",
    "identity_provenance": "accepted_native_catalog_read"
  },
  {
    "anytour_id": 12114,
    "local_tv_id": 12114,
    "expected_name": "JAZ ELITE PALACE ADULTS ONLY 16+ (EX. IBEROTEL PALACE ADULTS ONLY)",
    "identity_provenance": "accepted_native_catalog_read"
  }
]
_ANYTOUR_PRICE90_SCOPE_,true,512,JSON_THROW_ON_ERROR);
    insist(count($scope)===90&&count(array_unique(array_column($scope,'local_tv_id')))===90,'price_scope_not_exact90');
    $required=['catalog_hotels','catalog_countries','catalog_departures','tour_price_observations'];
    $t=$catalog->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?,?)");
    $t->execute($required);
    insist(count(array_intersect($required,$t->fetchAll(PDO::FETCH_COLUMN)))===4,'price_data_tables_unavailable');
    $catalog->exec('START TRANSACTION READ ONLY');
    $out['readback_epoch_utc']=time();
    $out['readback_at_utc']=gmdate('Y-m-d\\TH:i:s\\Z',$out['readback_epoch_utc']);
    $out['readback_clock_is_not_producer_observation_time']=true;
    $out['candidate_filter']=['departure_from'=>'2026-11-04','departure_to'=>'2027-02-02','adults'=>2,'children'=>0,'max_rows_per_hotel'=>2,'source'=>'scheduled_monitor','currency'=>'RUB'];
    $schema=$catalog->query("SELECT COLUMN_NAME AS name,DATA_TYPE AS data_type,COLUMN_DEFAULT AS column_default,EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tour_price_observations' ORDER BY ORDINAL_POSITION LIMIT 64")->fetchAll(PDO::FETCH_ASSOC);
    insist(count($schema)>=20&&count($schema)<64,'bounded_observation_schema_unexpected');
    $out['observation_schema']=$schema;
    $allowedClock=['created_at','inserted_at','recorded_at','received_at','ingested_at','observed_at_epoch','observed_at_utc','observed_at_timezone','observed_timezone','observation_epoch'];
    $extra='';$clockContracts=[];
    foreach($schema as $column){
        $name=(string)$column['name'];
        if(!in_array($name,$allowedClock,true))continue;
        $extra.=',o.`'.$name.'`';
        $contract=['column'=>$name,'data_type'=>$column['data_type'],'default'=>$column['column_default'],'producer_observed_time_verified'=>false];
        if(in_array($name,['created_at','inserted_at','recorded_at','received_at','ingested_at'],true)&&$column['data_type']==='timestamp'&&str_contains(strtoupper((string)$column['column_default']),'CURRENT_TIMESTAMP')){
            $extra.=',UNIX_TIMESTAMP(o.`'.$name.'`) AS `'.$name.'_stored_epoch_utc`';
            $contract['basis']='database_persistence_timestamp_default_not_producer_observation_clock';
        }else $contract['basis']='retained_clock_field_contract_requires_review';
        $clockContracts[]=$contract;
    }
    $out['retained_clock_contracts']=$clockContracts;
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
