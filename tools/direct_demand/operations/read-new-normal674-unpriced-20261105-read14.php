<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-monitor-20261105-read14-new674-unpriced1','mode'=>'readonly_actual_new_normal674_window_unpriced74_only','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'price_edits'=>0,'hotels'=>[]];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function input13():array{
 $raw=base64_decode(APPROVED_INPUT_B64,true);insist(is_string($raw)&&hash_equals(APPROVED_INPUT_SHA256,hash('sha256',$raw)),'approved_new_input_bytes_changed');return json_decode($raw,true,256,JSON_THROW_ON_ERROR);
}
function scope14(array $d,array $m):bool{
 if(($d['source_run']??0)!==41982177052||($d['source_job']??0)!==127094628296||($d['lower_epoch']??0)!==1793861003||($d['upper_epoch']??0)!==1793861528||($d['old_retained_rows']??0)!==85||($d['consumed_manual_rows']??0)!==104||count($d['bindings']??[])!==74)return false;
 $priced=json_decode(base64_decode(APPROVED_PRICED_IDS_B64,true),true,128,JSON_THROW_ON_ERROR);$new=json_decode(base64_decode(APPROVED_NEW_ADMISSIONS_B64,true),true,128,JSON_THROW_ON_ERROR);$map=[];
 foreach($m['native_bindings']??[] as $b)if(!in_array((int)$b['local_tv_id'],$priced,true))$map[(int)$b['local_tv_id']]=$b;
 foreach($new as $b){if(!in_array((int)$b['local_tv_id'],[1422,78251],true)||(int)$b['expected_country_id']!==4||$b['identity_provenance']!=='accepted_native_catalog_read'||$b['anytour_id']!==null)return false;$map[(int)$b['local_tv_id']]=$b;}
 if(count($m['native_bindings']??[])!==121||count($priced)!==81||count($new)!==2||count($map)!==74)return false;$seen=[];
 foreach($d['bindings'] as $entry){$b=$entry['binding']??[];$hid=(int)($b['local_tv_id']??0);if(!isset($map[$hid])||$map[$hid]!==$b||isset($seen[$hid])||in_array($hid,$priced,true)||in_array($hid,[482,476,81173,32577,478,80964],true))return false;$seen[$hid]=true;}
 $blocked=$d['blocked_fingerprints']??[];if(count($blocked)<1||count($blocked)>189||hash('sha256',json_encode($blocked,JSON_THROW_ON_ERROR))!==APPROVED_BLOCKED_FP_SHA256)return false;
 foreach($blocked as $fp)if(!is_string($fp)||preg_match('/\A[0-9a-f]{64}\z/D',$fp)!==1)return false;return true;
}
function currentNative13(array $h,array $b):bool{
 $fold=static function(string $s):string{$s=preg_replace('/\\s*\\(.*?\\)/u','',$s)??$s;return trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower($s,'UTF-8'))??'');};
 return (int)($h['id']??0)===(int)$b['local_tv_id']&&(int)($h['is_active']??0)===1&&(int)($h['country_id']??0)===(int)$b['expected_country_id']&&$fold((string)$h['name'])===$fold($b['expected_name']);
}
if(getenv('ANYTOUR_PRICE14_NEW_NORMAL_SCOPE_CASES')==='1'){
 $d=input13();$m=json_decode(base64_decode(APPROVED_MANIFEST_B64,true),true,256,JSON_THROW_ON_ERROR);insist(scope14($d,$m),'actual_new_normal_scope_not_bound');
 $bad=$d;$bad['bindings'][0]['binding']['local_tv_id']=67910;insist(!scope14($bad,$m),'new_already_priced_native_accepted');
 $bad=$d;$bad['bindings'][0]['binding']['expected_country_id']=99;insist(!scope14($bad,$m),'new_country_binding_overwrite_accepted');
 $bad=$d;$bad['lower_epoch']--;insist(!scope14($bad,$m),'new_closed_window_expansion_accepted');
 $bad=$d;array_pop($bad['blocked_fingerprints']);insist(!scope14($bad,$m),'new_consumed_fingerprint_exclusion_removed');
 echo "PRICE14_NEW_NORMAL_WINDOW_NATIVE_COUNTRY_CONSUMED_CASES_OK new_cases=4 old_fixtures0 provider0 db0\n";exit;
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


 $d=input13();$m=json_decode(base64_decode(APPROVED_MANIFEST_B64,true),true,256,JSON_THROW_ON_ERROR);insist(scope14($d,$m),'approved_new_normal_scope_changed');
 $catalog->exec('START TRANSACTION READ ONLY');
 $now=time();$lower=max(1793861003,$now-259200);$upper=1793861528;insist($lower<$upper,'new_normal_window_outside_freshness');
 $from=gmdate('Y-m-d',$now+86400);$to='2027-02-02';insist($from<=$to,'new_normal_dates_not_future_now');
 $identity=$catalog->prepare('SELECT h.id,h.name,h.is_active,h.country_id,c.name AS country FROM catalog_hotels h LEFT JOIN catalog_countries c ON c.id=h.country_id WHERE h.id=? LIMIT 1');
 $blocked=$d['blocked_fingerprints'];$notIn=implode(',',array_fill(0,count($blocked),'?'));
 $sql="SELECT o.id AS observation_id,o.source AS retained_source,SHA2(COALESCE(o.tour_id,''),256) AS retained_tour_fingerprint,o.search_id AS original_source_search_id,o.departure_id,dep.name AS departure_name,o.departure_date,o.nights,o.adults,o.children_count,o.child_ages_signature,o.meal_id,o.room_id,o.room_type,o.operator_id,o.price AS raw_observed_price,o.fuel_charge,o.currency,o.observed_at,o.created_at,UNIX_TIMESTAMP(o.created_at) AS created_at_stored_epoch_utc FROM tour_price_observations o LEFT JOIN catalog_departures dep ON dep.id=o.departure_id WHERE o.hotel_id=? AND o.country_id=? AND o.source='scheduled_monitor' AND o.departure_id=1 AND o.departure_date BETWEEN ? AND ? AND o.nights BETWEEN 5 AND 14 AND o.adults=2 AND o.children_count=0 AND o.child_ages_signature='' AND o.currency='RUB' AND o.price>0 AND TRIM(o.room_type)<>'' AND o.meal_id IN (2,3,4,5,7,9) AND UNIX_TIMESTAMP(o.created_at)>=? AND UNIX_TIMESTAMP(o.created_at)<? AND COALESCE(o.tour_id,'')<>'' AND SHA2(COALESCE(o.tour_id,''),256) NOT IN (".$notIn.") ORDER BY o.price ASC,o.id DESC LIMIT 2";
 $prices=$catalog->prepare($sql);$meals=[2=>'RO',3=>'BB',4=>'HB',5=>'FB',7=>'AI',9=>'UAI'];
 foreach($d['bindings'] as $entry){$b=$entry['binding'];$hid=(int)$b['local_tv_id'];$identity->execute([$hid]);$h=$identity->fetch(PDO::FETCH_ASSOC);$row=$b+['observations'=>[],'identity_state'=>'current_native_not_admitted','actual_normal_source_run'=>41982177052];
  if(is_array($h)){$row['catalog']=$h;$row['current_name_matches_expected']=currentNative13($h,$b);
   if($row['current_name_matches_expected']){
    $row['identity_state']=$b['identity_provenance']==='accepted_typed_bridge'?'accepted_bridge_current_name_matches':'accepted_native_catalog_id_current_name_matches';
    $params=array_merge([$hid,(int)$b['expected_country_id'],$from,$to,$lower,$upper],$blocked);$prices->execute($params);$obs=$prices->fetchAll(PDO::FETCH_ASSOC);
    foreach($obs as &$o){insist(!in_array($o['retained_tour_fingerprint'],$blocked,true)&&$o['retained_source']==='scheduled_monitor'&&(int)$o['original_source_search_id']>0,'new_original_source_binding_changed');$o['native_meal_label']=$meals[(int)$o['meal_id']];$o['final_total_verified']=false;$o['current_availability_verified']=false;$o['producer_observed_epoch_verified']=false;$o['normal_source_run']=41982177052;$o['normal_window_start_epoch_utc']=1793861003;$o['normal_window_end_epoch_utc']=1793861528;}unset($o);$row['observations']=$obs;
   }
  }$out['hotels'][]=$row;
 }
 $catalog->rollBack();$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['source_run']=41982177052;$out['source_collect_job']=127094628296;$out['source_monthly_stage_success']=true;$out['source_aggregate_workflow_success']=false;$out['max_age_seconds']=259200;$out['readback_epoch_utc']=$now;$out['created_after_persistence_epoch_utc']=$lower;$out['exclusive_upper_epoch_utc']=$upper;$out['candidate_filter']=['departure_from'=>$from,'departure_to'=>$to,'nights_from'=>5,'nights_to'=>14,'adults'=>2,'children'=>0,'currency'=>'RUB','max_rows_per_hotel'=>2,'actual_new_normal_persisted_window_only'=>true,'old_retained_rows_excluded'=>85,'manual_consumed_rows_excluded'=>104,'unique_blocked_fingerprints'=>count($blocked)];$out['naive_observed_at_relabelled_as_utc']=false;$out['schema_contract_proof']='closed41603842788';$out['ok']=true;
}catch(Throwable $e){if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();$out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$e->getMessage())?$e->getMessage():'bounded_new_approved_source_read_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
