<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>APPROVED_QUOTE_OPERATION,'mode'=>'one_existing_gateway_quote_contract','ok'=>false,'db_writes'=>0,'gateway_requests_attempted'=>0,'upstream_request_limit'=>1,'message_sends'=>0,'price_block_updates'=>0];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function quote_age_ok(int $age):bool{return $age>=0&&$age<=259200;}
function quote8_hotel_allowed(int $id):bool{return $id>0&&!in_array($id,[233,110,73342,13869,13873,109,12114,9432,56092,32575,83105,250,132,235,482,476,81173,32577,478,80964],true);}
if(getenv('ANYTOUR_PRICE72_QUOTE_AGE_CASES')==='1'){
 insist(quote_age_ok(259200),'new72h_boundary');insist(!quote_age_ok(259201),'new72h_after_boundary');insist(!quote_age_ok(-1),'new72h_future_clock');
 echo "PRICE72_QUOTE_NEW_AGE_OK new_cases=3 old30h_age_fixtures_rerun=0 server_calls=0 provider_calls=0 writes=0\n";exit;
}
if(getenv('ANYTOUR_PRICE72_QUOTE_INPUT_CASES')==='1'){
 insist(defined('APPROVED_HOTEL_ID')&&quote8_hotel_allowed(APPROVED_HOTEL_ID),'new72h_selected_unconsumed_hotel');
 insist(!quote8_hotel_allowed(235)&&!quote8_hotel_allowed(233)&&!quote8_hotel_allowed(482),'old_consumed_and_name_review_stops');
 insist(defined('APPROVED_QUOTE_OPERATION')&&APPROVED_QUOTE_OPERATION==='commercial-price-monitor-20261104-72h-pass1-h'.APPROVED_HOTEL_ID,'new_operation_exact_native_binding');
 echo "PRICE72_QUOTE_NEW_INPUT_OK new_cases=3 old_identity_fixtures_rerun=0 server_calls=0 provider_calls=0 writes=0\n";exit;
}
function quote3_identity_ok(int $id,array $row,int $country,string $name):bool{
 return $id>0&&(int)($row['hotel_id']??0)===$id&&(int)($row['is_active']??0)===1&&(int)($row['country_id']??0)===$country&&in_array($country,[1,4],true)&&trim((string)($row['hotel_name']??''))===$name;
}
if(getenv('ANYTOUR_QUOTE3_IDENTITY_FIXTURE')==='1'){
 $h=['hotel_id'=>132,'is_active'=>1,'country_id'=>1,'hotel_name'=>'Fixture'];
 insist(quote3_identity_ok(132,$h,1,'Fixture'),'new_exact_native_quote_binding');
 insist(!quote3_identity_ok(133,$h,1,'Fixture')&&!quote3_identity_ok(132,$h,4,'Fixture'),'wrong_native_or_country_stops');
 $h['is_active']=0;insist(!quote3_identity_ok(132,$h,1,'Fixture'),'inactive_stops');
 echo "QUOTE3_NEW_NATIVE_BINDING_OK new_cases=3 old_age_cases_rerun=0 server_calls=0 provider_calls=0 writes=0\n";exit;
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



    require_once getcwd().'/services/HotelDatabase.php';
    insist(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $catalog=HotelDatabase::connection();


    insist(defined('APPROVED_CANDIDATE_B64'),'exact_retained_candidate_required');
    $approvedRaw=base64_decode(APPROVED_CANDIDATE_B64,true);insist(is_string($approvedRaw)&&defined('APPROVED_CANDIDATE_SHA256')&&hash_equals(APPROVED_CANDIDATE_SHA256,hash('sha256',$approvedRaw)),'approved_candidate_hash_changed');
    $approved=json_decode($approvedRaw,true,64,JSON_THROW_ON_ERROR);
    insist(is_array($approved)&&isset($approved['observation_id'],$approved['retained_tour_fingerprint'],$approved['created_at_stored_epoch_utc']),'exact_retained_candidate_required');
    insist(defined('APPROVED_HOTEL_ID')&&(int)($approved['hotel_id']??0)===APPROVED_HOTEL_ID&&quote8_hotel_allowed(APPROVED_HOTEL_ID)&&$approved['departure_date']>gmdate('Y-m-d')&&$approved['departure_date']<='2027-02-02'&&(int)$approved['nights']>=1&&(int)$approved['nights']<=60&&(int)$approved['adults']===2&&(int)$approved['children_count']===0&&$approved['child_ages_signature']===''&&$approved['currency']==='RUB'&&trim((string)$approved['room_type'])!=='','approved_quote_scope_changed');
    $catalog->exec('START TRANSACTION READ ONLY');
    $q=$catalog->prepare("SELECT o.id AS observation_id,o.source AS retained_source,o.tour_id,SHA2(COALESCE(o.tour_id,''),256) AS retained_tour_fingerprint,o.hotel_id,o.departure_id,o.departure_date,o.nights,o.adults,o.children_count,o.child_ages_signature,o.meal_id,o.room_type,o.price AS raw_observed_price,o.fuel_charge,o.currency,UNIX_TIMESTAMP(o.created_at) AS created_at_stored_epoch_utc,h.name AS hotel_name,h.is_active,h.country_id FROM tour_price_observations o JOIN catalog_hotels h ON h.id=o.hotel_id WHERE o.id=? AND o.hotel_id=? AND o.source IN ('scheduled_monitor','user_search','hot_tours') LIMIT 1");
    $q->execute([(int)$approved['observation_id'],APPROVED_HOTEL_ID]);$row=$q->fetch(PDO::FETCH_ASSOC);
    insist(is_array($row)&&quote3_identity_ok(APPROVED_HOTEL_ID,$row,APPROVED_COUNTRY_ID,base64_decode(APPROVED_HOTEL_NAME_B64,true)),'exact_quote_hotel_unavailable');
    foreach(['retained_source','retained_tour_fingerprint','departure_id','departure_date','nights','adults','children_count','child_ages_signature','meal_id','room_type','raw_observed_price','fuel_charge','currency','created_at_stored_epoch_utc'] as $key)
        insist((string)$row[$key]===(string)$approved[$key],'retained_candidate_changed');
    $now=time();$persisted=(int)$row['created_at_stored_epoch_utc'];
    insist($persisted>0&&quote_age_ok($now-$persisted),'retained_quote_candidate_too_old');
    insist($row['departure_date']>gmdate('Y-m-d',$now),'retained_quote_departure_not_future');
    $tour=(string)$row['tour_id'];insist(strlen($tour)>0&&strlen($tour)<=200,'retained_tour_identity_unusable');
    $catalog->rollBack();
    $out['catalog_id']=APPROVED_HOTEL_ID;
    $out['retained_terms']=['departure_id'=>(int)$row['departure_id'],'departure_date'=>$row['departure_date'],'nights'=>(int)$row['nights'],'adults'=>2,'children'=>0,'meal_id'=>(int)$row['meal_id'],'room_type'=>$row['room_type'],'raw_observed_price'=>$row['raw_observed_price'],'fuel_charge'=>$row['fuel_charge'],'currency'=>$row['currency'],'persistence_epoch_utc'=>$persisted];
    $url='https://anytoour.ru/api-v2.php?action=flights&tourId='.rawurlencode($tour).'&currency=RUB';
    $body='';$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','Cache-Control: no-cache'],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>300000)return 0;$body.=$chunk;return strlen($chunk);}]);
    $out['request_started_epoch_utc']=time();$out['gateway_requests_attempted']=1;
    $result=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);$contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
    $out['response_received_epoch_utc']=time();$out['http_status']=$http;$out['response_bytes']=strlen($body);$out['response_sha256']=hash('sha256',$body);
    $out['response_time_is_not_price_guarantee']=true;$out['automatic_retry_allowed']=false;
    if($result===false||$errno!==0){$out['quote_state']='GATEWAY_ATTEMPT_OUTCOME_UNKNOWN_DO_NOT_REPEAT';}
    elseif($http!==200||stripos($contentType,'application/json')===false){$out['quote_state']='GATEWAY_RESPONSE_NOT_ACCEPTED';}
    else{
        try{$decoded=json_decode($body,true,64,JSON_THROW_ON_ERROR);$out['gateway_response']=$decoded;$out['quote_state']='RESPONSE_RETAINED_AMOUNT_CONTRACT_REQUIRES_REVIEW';}
        catch(Throwable $e){$out['quote_state']='GATEWAY_JSON_UNUSABLE';}
    }
    $out['full_total_verified']=false;$out['current_availability_verified']=false;$out['price_block_updates']=0;$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;
} catch(Throwable $e){
    if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();
    $out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'targeted_quote_contract_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
exit($out['ok']?0:2);
