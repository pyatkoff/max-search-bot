<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>'commercial-price-monitor-20261105-read13-approved-manual1','mode'=>'readonly_new_approved_search_ids_only','ok'=>false,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'price_edits'=>0,'hotels'=>[]];
function insist(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function input13():array{
 $raw=base64_decode(APPROVED_INPUT_B64,true);insist(is_string($raw)&&hash_equals(APPROVED_INPUT_SHA256,hash('sha256',$raw)),'approved_new_input_bytes_changed');return json_decode($raw,true,256,JSON_THROW_ON_ERROR);
}
function scope13(array $d,array $m):bool{
 if(($d['source_run']??0)!==41976677950||($d['source_artifact']??0)!==13391673842||($d['source_groups']??0)!==7||($d['source_hotels']??0)!==56||($d['old_retained_rows']??0)!==85||($d['original_manifest_sha256']??'')!=='2035f0382b815f721a42dfe4a0045f75b0e1db9f0d16891ed0db8ac1081b0361')return false;
 $ids=$d['original_search_ids']??[];if(count($ids)!==7||count(array_unique($ids))!==7||min($ids)<1||count($d['bindings']??[])>56||count($d['bindings']??[])<1)return false;
 $map=[];foreach($m['native_bindings'] as $b)$map[(int)$b['local_tv_id']]=$b;$seen=[];
 foreach($d['bindings'] as $entry){$b=$entry['binding']??[];$hid=(int)($b['local_tv_id']??0);$group=$entry['original_group_index']??-1;
  if(!isset($map[$hid])||$map[$hid]!==$b||isset($seen[$hid])||!in_array($hid,$d['all_original_source_hotels'],true)||!isset($m['groups'][$group])||(int)$entry['original_search_id']!==(int)$ids[$group]||!in_array($hid,$m['groups'][$group]['hotel_ids'],true)||(int)$b['expected_country_id']!==(int)$m['groups'][$group]['country_id'])return false;
  $seen[$hid]=true;
 }
 foreach($d['old_retained_fingerprints'] as $fp)if(!is_string($fp)||preg_match('/\\A[0-9a-f]{64}\\z/D',$fp)!==1)return false;
 return count($map)===121&&count($d['old_retained_fingerprints'])>0&&count($d['old_retained_fingerprints'])<=85;
}
function currentNative13(array $h,array $b):bool{
 $fold=static function(string $s):string{$s=preg_replace('/\\s*\\(.*?\\)/u','',$s)??$s;return trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower($s,'UTF-8'))??'');};
 return (int)($h['id']??0)===(int)$b['local_tv_id']&&(int)($h['is_active']??0)===1&&(int)($h['country_id']??0)===(int)$b['expected_country_id']&&$fold((string)$h['name'])===$fold($b['expected_name']);
}
if(getenv('ANYTOUR_PRICE13_NEW_SOURCE_INPUT_CASES')==='1'){
 $d=input13();$m=json_decode(base64_decode(APPROVED_MANIFEST_B64,true),true,256,JSON_THROW_ON_ERROR);insist(scope13($d,$m),'actual_new_scope_not_bound');
 foreach(['native','country','search'] as $kind){$bad=$d;
  if($kind==='native')$bad['bindings'][0]['binding']['local_tv_id']=1422;
  elseif($kind==='country')$bad['bindings'][0]['binding']['expected_country_id']=99;
  else $bad['bindings'][0]['original_search_id']=0;
  insist(!scope13($bad,$m),'new_source_scope_negative_accepted');
 }
 echo "PRICE13_NEW_ACTUAL_MANUAL_SCOPE_OK new_cases=3 old_fixtures0 provider0 db0\\n";exit;
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


 $d=input13();$m=json_decode(base64_decode(APPROVED_MANIFEST_B64,true),true,256,JSON_THROW_ON_ERROR);insist(scope13($d,$m),'approved_source_scope_changed');
 $catalog->exec('START TRANSACTION READ ONLY');
 $now=time();$lower=max(SOURCE_COLLECTION_INTENT_EPOCH_UTC,$now-259200);$upper=$now+1;
 $from=max($m['departure_date_from'],gmdate('Y-m-d',$now+86400));$to=min($m['departure_date_to'],'2027-02-02');insist($from<=$to,'original_source_dates_not_future_now');
 $identity=$catalog->prepare('SELECT h.id,h.name,h.is_active,h.country_id,c.name AS country FROM catalog_hotels h LEFT JOIN catalog_countries c ON c.id=h.country_id WHERE h.id=? LIMIT 1');
 $blocked=$d['old_retained_fingerprints'];$notIn=implode(',',array_fill(0,count($blocked),'?'));
 $sql="SELECT o.id AS observation_id,o.source AS retained_source,SHA2(COALESCE(o.tour_id,''),256) AS retained_tour_fingerprint,o.search_id AS original_source_search_id,o.departure_id,dep.name AS departure_name,o.departure_date,o.nights,o.adults,o.children_count,o.child_ages_signature,o.meal_id,o.room_id,o.room_type,o.operator_id,o.price AS raw_observed_price,o.fuel_charge,o.currency,o.observed_at,o.created_at,UNIX_TIMESTAMP(o.created_at) AS created_at_stored_epoch_utc FROM tour_price_observations o LEFT JOIN catalog_departures dep ON dep.id=o.departure_id WHERE o.hotel_id=? AND o.search_id=? AND o.country_id=? AND o.source='scheduled_monitor' AND o.departure_id=1 AND o.departure_date BETWEEN ? AND ? AND o.nights BETWEEN 5 AND 14 AND o.adults=2 AND o.children_count=0 AND o.child_ages_signature='' AND o.currency='RUB' AND o.price>0 AND TRIM(o.room_type)<>'' AND o.meal_id IN (2,3,4,5,7,9) AND UNIX_TIMESTAMP(o.created_at)>=? AND UNIX_TIMESTAMP(o.created_at)<? AND COALESCE(o.tour_id,'')<>'' AND SHA2(COALESCE(o.tour_id,''),256) NOT IN (".$notIn.") ORDER BY o.price ASC,o.id DESC LIMIT 2";
 $prices=$catalog->prepare($sql);$meals=[2=>'RO',3=>'BB',4=>'HB',5=>'FB',7=>'AI',9=>'UAI'];
 foreach($d['bindings'] as $entry){$b=$entry['binding'];$hid=(int)$b['local_tv_id'];$identity->execute([$hid]);$h=$identity->fetch(PDO::FETCH_ASSOC);$row=$b+['observations'=>[],'identity_state'=>'current_native_not_admitted','original_source_group_index'=>$entry['original_group_index']];
  if(is_array($h)){$row['catalog']=$h;$row['current_name_matches_expected']=currentNative13($h,$b);
   if($row['current_name_matches_expected']){
    $row['identity_state']=$b['identity_provenance']==='accepted_typed_bridge'?'accepted_bridge_current_name_matches':'accepted_native_catalog_id_current_name_matches';
    $params=array_merge([$hid,(int)$entry['original_search_id'],(int)$b['expected_country_id'],$from,$to,$lower,$upper],$blocked);$prices->execute($params);$obs=$prices->fetchAll(PDO::FETCH_ASSOC);
    foreach($obs as &$o){insist(!in_array($o['retained_tour_fingerprint'],$blocked,true)&&$o['retained_source']==='scheduled_monitor'&&(int)$o['original_source_search_id']===(int)$entry['original_search_id'],'new_original_source_binding_changed');$o['native_meal_label']=$meals[(int)$o['meal_id']];$o['final_total_verified']=false;$o['current_availability_verified']=false;$o['producer_observed_epoch_verified']=false;$o['manual_trigger_proof']='actual_approved_original_search_run41976677950';}unset($o);$row['observations']=$obs;
   }
  }$out['hotels'][]=$row;
 }
 $catalog->rollBack();$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['source_run']=41976677950;$out['source_artifact']=13391673842;$out['source_projection_run']=41977589739;$out['max_age_seconds']=259200;$out['readback_epoch_utc']=$now;$out['created_after_persistence_epoch_utc']=$lower;$out['exclusive_upper_epoch_utc']=$upper;$out['candidate_filter']=['departure_from'=>$from,'departure_to'=>$to,'nights_from'=>5,'nights_to'=>14,'adults'=>2,'children'=>0,'currency'=>'RUB','max_rows_per_hotel'=>2,'only_original_actual_search_ids'=>true,'all_old_retained_fingerprints_excluded'=>85];$out['naive_observed_at_relabelled_as_utc']=false;$out['schema_contract_proof']='closed41603842788';$out['ok']=true;
}catch(Throwable $e){if(isset($catalog)&&$catalog instanceof PDO&&$catalog->inTransaction())$catalog->rollBack();$out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$e->getMessage())?$e->getMessage():'bounded_new_approved_source_read_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
