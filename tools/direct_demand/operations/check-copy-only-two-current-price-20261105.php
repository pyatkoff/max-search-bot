<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>APPROVED_QUOTE_OPERATION,'mode'=>'one_existing_gateway_quote_contract','ok'=>false,'db_writes'=>0,'gateway_requests_attempted'=>0,'upstream_request_limit'=>1,'message_sends'=>0,'price_block_updates'=>0];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function quote_age_ok(int $age):bool{return $age>=0&&$age<=259200;}
function quote8_hotel_allowed(int $id):bool{return in_array($id,json_decode(base64_decode(APPROVED_SCOPE_NATIVE_IDS_B64,true),true,128,JSON_THROW_ON_ERROR),true);}
function quote3_identity_ok(int $id,array $row,int $country,string $name):bool{
 return $id>0&&(int)($row['hotel_id']??0)===$id&&(int)($row['is_active']??0)===1&&(int)($row['country_id']??0)===$country&&in_array($country,[1,4],true)&&trim((string)($row['hotel_name']??''))===$name;
}
function currentFrame13():array{
 $raw=base64_decode(APPROVED_CURRENT_FRAME_B64,true);insist(is_string($raw)&&hash_equals(APPROVED_CURRENT_FRAME_SHA256,hash('sha256',$raw)),'current_frame_bytes_changed');return json_decode($raw,true,128,JSON_THROW_ON_ERROR);
}
function newQuoteInput13(array $a,array $f):bool{
 $b=$f['binding']??[];$id=(int)($a['hotel_id']??0);$blocked=json_decode(base64_decode(APPROVED_OLD_FINGERPRINTS_B64,true),true,128,JSON_THROW_ON_ERROR);$sources=json_decode(base64_decode(APPROVED_ORIGINAL_SEARCH_IDS_B64,true),true,128,JSON_THROW_ON_ERROR);
 return $id===APPROVED_HOTEL_ID&&in_array($id,[67910,68323],true)&&quote8_hotel_allowed($id)&&(int)($b['native_hotel_id']??0)===$id&&(int)($b['expected_country_id']??0)===APPROVED_COUNTRY_ID&&($f['capture_run']??0)===APPROVED_CURRENT_FRAME_RUN&&($f['capture_operation']??'')==='commercial-price-monitor-20261105-context15-copy-only-two'&&($f['capture_run']??0)===41990234208&&($f['capture_source_sha']??'')===EXPECTED_INSTALLED_SHA&&array_key_exists('publisher_hotel_id',$b)&&$b['publisher_hotel_id']===null&&isset($b['contexts']['max'],$b['contexts']['telegram'])&&hash('sha256',(string)($b['caption']??''))===($b['caption_sha256']??'')&&$b['contexts']['max']['old_text']===$b['caption']&&$b['contexts']['telegram']['old_text']===$b['caption']&&in_array((int)($a['original_source_search_id']??0),$sources,true)&&!in_array($a['retained_tour_fingerprint']??'',$blocked,true)&&($a['retained_source']??'')==='scheduled_monitor'&&(int)($a['departure_id']??0)===1&&(int)($a['adults']??0)===2&&(int)($a['children_count']??-1)===0&&($a['child_ages_signature']??'x')===''&&($a['currency']??'')==='RUB'&&(int)($a['nights']??0)>=5&&(int)$a['nights']<=14&&($a['departure_date']??'')>gmdate('Y-m-d')&&$a['departure_date']>='2026-11-06'&&$a['departure_date']<='2026-11-12'&&trim((string)($a['room_type']??''))!==''&&in_array((int)($a['meal_id']??0),[2,3,4,5,7,9],true);
}
function currentPhoto13(array $s):string{$a=$s['attachments']??[];insist(count($a)===1&&($a[0]['type']??'')==='image','expected_exact_current_photo');$id=(string)($a[0]['photo_id']??$a[0]['file_unique_id']??'');insist($id!=='','current_photo_signature_missing');return (string)($a[0]['provider']??'').':'.$id;}
function currentButton13(array $s,array $f,string $platform,string $url):bool{
 if(ChannelPostIncomingAdapter::containsOnlyButton($s,$url,'✈️ Узнать стоимость тура'))return true;
 foreach(['keyboard','reply_markup','buttons'] as $k)if(!empty($s[$k]))return false;
 $b=$f['binding'];$kind=$b['contexts'][$platform]['canonical_proof_kind']??'';
 if(!in_array((int)($b['native_hotel_id']??0),[67910,68323],true)||($f['capture_run']??0)!==41990234208)return false;
 return $platform==='max'?$kind==='CONFIRMED_COPY_RECEIPT_EXACT_CURRENT_VERSION_AND_OWNED_MAX_BODY_PHOTO_ONLY_BUTTON':($platform==='telegram'&&$kind==='CONFIRMED_COPY_TRANSPORT_RECEIPT_EXACT_CURRENT_TG_VERSION_AND_STABLE_PHOTO_SIGNATURE');
}
if(getenv('ANYTOUR_MANUAL1_TWO_COPY_QUOTE_NEW_CASES')==='1'){
 $a=json_decode(base64_decode(APPROVED_CANDIDATE_B64,true),true,128,JSON_THROW_ON_ERROR);$f=currentFrame13();insist(newQuoteInput13($a,$f),'new_exact_source_frame_candidate_not_admitted');
 $bad=$a;$bad['hotel_id']=1422;insist(!newQuoteInput13($bad,$f),'new_foreign_native_input_accepted');
 $bad=$a;$bad['original_source_search_id']=0;insist(!newQuoteInput13($bad,$f),'new_nonoriginal_search_input_accepted');
 $bad=$f;$bad['binding']['caption'].='x';insist(!newQuoteInput13($a,$bad),'new_current_caption_source_hash_accepted');
 echo "MANUAL1_TWO_COPY_QUOTE_NEW_SOURCE_NULL_ROLE_FRAME_OK new_cases=3 old_age_native_fixtures0 gateway0 db0\\n";exit;
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
    $frame=currentFrame13();insist(newQuoteInput13($approved,$frame),'new_original_search_current_frame_binding_changed');
    insist(defined('APPROVED_HOTEL_ID')&&(int)($approved['hotel_id']??0)===APPROVED_HOTEL_ID&&quote8_hotel_allowed(APPROVED_HOTEL_ID)&&$approved['departure_date']>gmdate('Y-m-d')&&$approved['departure_date']<='2027-02-02'&&(int)$approved['nights']>=1&&(int)$approved['nights']<=60&&(int)$approved['adults']===2&&(int)$approved['children_count']===0&&$approved['child_ages_signature']===''&&$approved['currency']==='RUB'&&trim((string)$approved['room_type'])!=='','approved_quote_scope_changed');
    $pdo=ConversationDb::connection();$registry=new ChannelPostRegistryService($pdo);$b=$frame['binding'];
    foreach(['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1'],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk']] as $platform=>$target){
     $c=$b['contexts'][$platform];$p=$registry->publication((int)$c['publication_id']);
     insist(is_array($p)&&$p['project_key']==='anytour'&&$p['platform']===$platform&&$p['bot_key']===$target['bot']&&$p['channel_id']===$target['channel']&&$p['source_key']===$target['source']&&$p['external_message_id']===$c['message_id']&&(int)$p['version_id']===(int)$c['version_id']&&$p['state']==='sent'&&empty($p['claim_token'])&&empty($p['claimed_at']),'current_publication_or_outcome_changed_before_quote');
     $snap=$p['snapshot'];$button=ChannelPostButtonTransport::buttonUrl($p);
     insist(($snap['text']??'')===$c['old_text']&&hash('sha256',$snap['text'])===$c['old_text_sha256']&&currentPhoto13($snap)===$c['photo_signature']&&($snap['post_url']??'')===$c['post_url']&&hash('sha256',$p['start_payload'])===$c['start_payload_sha256']&&hash('sha256',$button)===$c['button_url_sha256']&&currentButton13($snap,$frame,$platform,$button),'current_wholecaption_photo_button_source_changed_before_quote');
    }
    $out['current_wholecaption_photo_button_source_bound_before_gateway']=true;$out['canonical_context_sha256']=APPROVED_CURRENT_FRAME_SHA256;$out['original_approved_source_run']=41976677950;
    $catalog->exec('START TRANSACTION READ ONLY');
    $q=$catalog->prepare("SELECT o.id AS observation_id,o.source AS retained_source,o.search_id AS original_source_search_id,o.tour_id,SHA2(COALESCE(o.tour_id,''),256) AS retained_tour_fingerprint,o.hotel_id,o.departure_id,o.departure_date,o.nights,o.adults,o.children_count,o.child_ages_signature,o.meal_id,o.room_type,o.price AS raw_observed_price,o.fuel_charge,o.currency,UNIX_TIMESTAMP(o.created_at) AS created_at_stored_epoch_utc,h.name AS hotel_name,h.is_active,h.country_id FROM tour_price_observations o JOIN catalog_hotels h ON h.id=o.hotel_id WHERE o.id=? AND o.hotel_id=? AND o.search_id=? AND o.source='scheduled_monitor' LIMIT 1");
    $q->execute([(int)$approved['observation_id'],APPROVED_HOTEL_ID,(int)$approved['original_source_search_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    insist(is_array($row)&&quote3_identity_ok(APPROVED_HOTEL_ID,$row,APPROVED_COUNTRY_ID,base64_decode(APPROVED_HOTEL_NAME_B64,true)),'exact_quote_hotel_unavailable');
    foreach(['retained_source','retained_tour_fingerprint','original_source_search_id','departure_id','departure_date','nights','adults','children_count','child_ages_signature','meal_id','room_type','raw_observed_price','fuel_charge','currency','created_at_stored_epoch_utc'] as $key)
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
