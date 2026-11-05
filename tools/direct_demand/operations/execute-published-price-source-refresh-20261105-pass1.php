<?php
declare(strict_types=1);
// Ephemeral, inactive until a separately recorded explicit manual-collection approval.
// Existing bot source guards, native source clients and observer are unchanged.
ini_set('display_errors','0');ini_set('log_errors','0');
$out=['operation'=>APPROVED_MANUAL_OPERATION,'ok'=>false,'new_searches_attempted'=>0,'http_attempts'=>0,'observation_rows_written'=>0,'post_edits'=>0,'new_posts'=>0,'installed_runtime_changes'=>0,'automatic_retry'=>false,'groups'=>[]];
function manualNeed(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function manualScopeOk(array $m):bool{
 if(($m['operation']??'')!=='commercial-price-source-refresh-20261105-pass1'||($m['published_scope_count']??0)!==159||count($m['native_bindings']??[])!==121||count($m['groups']??[])!==7||($m['max_new_searches']??0)!==7||($m['max_http_attempts']??0)!==154||($m['max_poll_requests_per_search']??0)!==20||($m['max_result_requests_per_search']??0)!==1||($m['automatic_http_retries']??true)!==false)return false;
 if(($m['departure_id']??0)!==1||($m['adults']??0)!==2||($m['children']??-1)!==0||($m['currency']??'')!=='RUB'||($m['nights_from']??0)!==5||($m['nights_to']??0)!==14)return false;
 $priced=$m['excluded_priced_native_ids']??[];$review=$m['excluded_name_review_native_ids']??[];
 if(count($priced)!==32||count($review)!==6)return false;
 $rows=[];$groupIds=[];
 foreach($m['native_bindings'] as $b){$id=(int)($b['local_tv_id']??0);if($id<1||isset($rows[$id])||in_array($id,array_merge($priced,$review),true)||!in_array((int)($b['expected_country_id']??0),[1,4],true)||!in_array($b['identity_provenance']??'',['accepted_native_catalog_read','accepted_typed_bridge'],true))return false;
 if(($b['identity_provenance']??'')==='accepted_typed_bridge'&&((int)($b['anytour_id']??0)<1||(int)$b['anytour_id']===$id))return false;$rows[$id]=$b;}
 foreach($m['groups'] as $g){if(!in_array($g['country_id']??0,[1,4],true)||count($g['hotel_ids']??[])<1||count($g['hotel_ids'])>20)return false;foreach($g['hotel_ids'] as $id){if(!isset($rows[$id])||isset($groupIds[$id])||(int)$rows[$id]['expected_country_id']!==$g['country_id'])return false;$groupIds[$id]=true;}}
 return count($rows)===121&&count($groupIds)===121&&!array_diff_key($rows,$groupIds);
}
function manualMayStart(bool $approved,bool $priorAttempt,int $started,int $limit,bool $unknown):bool{return $approved&&!$priorAttempt&&!$unknown&&$started<$limit&&$limit===7;}
function manualTargetParams(array $target):array
{
    $params=['departureId'=>(int)$target['departure_id'],'countryId'=>(int)$target['country_id'],'dateFrom'=>$target['date_from'],'dateTo'=>$target['date_to'],'nightsFrom'=>5,'nightsTo'=>14,'adults'=>2,'currency'=>'RUB','onlyCharter'=>false,'onlyDirect'=>false];
    $criterion=(string)($target['criterion']??'');
    if($criterion==='hotel_batch'){
        $ids=array_values(array_unique(array_filter(array_map('intval',$target['hotel_ids']??[]),static fn(int $id):bool=>$id>0)));
        if(!$ids||count($ids)>30)throw new InvalidArgumentException('invalid priority hotel batch');
        $params['hotelIds']=$ids;
    }elseif($criterion==='resort'){
        $region=(int)($target['region_id']??0);if($region<=0)throw new InvalidArgumentException('missing resort identity');
        $params['regionIds']=[$region];
    }elseif($criterion!=='month')throw new InvalidArgumentException('unsupported collection target');
    return $params;
}

function manualAcceptHotel(array $target,array $hotel):bool
{
    $idValue=static function(mixed $v):int{if(is_array($v))$v=$v['id']??0;$n=filter_var($v,FILTER_VALIDATE_INT);return $n!==false&&$n>0?(int)$n:0;};
    $id=$idValue($hotel['id']??0);$country=$idValue($hotel['country']??0);
    if($id<=0||($country>0&&$country!==(int)$target['country_id']))return false;
    if(($target['criterion']??'')==='hotel_batch')return in_array($id,$target['hotel_ids'],true);
    if($country!==(int)$target['country_id'])return false;
    return ($target['criterion']??'')==='month'||(($target['criterion']??'')==='resort'&&$idValue($hotel['region']??0)===(int)$target['region_id']);
}
if(getenv('ANYTOUR_MANUAL_REFRESH2_EXECUTION_FIXTURE')==='1'){
 $raw=base64_decode(APPROVED_MANUAL_MANIFEST_B64,true);manualNeed(is_string($raw)&&hash('sha256',$raw)===APPROVED_MANUAL_MANIFEST_SHA256,'pure_manifest_changed');$m=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
 manualNeed(manualScopeOk($m),'new_actual121_group_input');
 manualNeed(!manualMayStart(false,false,0,7,false),'manual_user_approval_required');
 manualNeed(!manualMayStart(true,true,0,7,false)&&!manualMayStart(true,false,0,7,true),'prior_or_unknown_no_replay');
 manualNeed(!manualMayStart(true,false,7,7,false)&&!manualMayStart(true,false,0,8,false),'exact_seven_search_limit');
 $bad=$m;$bad['groups'][0]['hotel_ids'][0]=$m['excluded_priced_native_ids'][0];manualNeed(!manualScopeOk($bad),'priced_hotel_scope_swap_stops');
 $bad=$m;$bad['groups'][0]['country_id']=4;manualNeed(!manualScopeOk($bad),'country_binding_swap_stops');
 echo "MANUAL_REFRESH2_EXECUTION_GUARDS_OK new_cases=5 actual_unpriced121 groups7 server_calls0 db_writes0 provider_calls0 old_fixtures0\n";exit;
}
function manualApi(string $path,array $params,array &$out):array{
 manualNeed($out['http_attempts']<154,'http_budget_exhausted');
 manualNeed($path==='/tours/search'||preg_match('~\\A/tours/search/[1-9][0-9]{0,15}(?:/status)?\\z~D',$path)===1,'provider_path_outside_finite_search_scope');
 static $last=0.0;$delay=1.05-(microtime(true)-$last);if($delay>0)usleep((int)ceil($delay*1000000));$last=microtime(true);$out['http_attempts']++;
 ob_start();try{return v2_data_tv_get($path,$params);}finally{ob_end_clean();}
}
function manualRows(array $data):array{if(array_is_list($data))return $data;foreach(['hotels','items','results'] as $key)if(is_array($data[$key]??null))return $data[$key];throw new RuntimeException('result_shape_unusable');}
try {
 manualNeed(defined('APPROVED_MANUAL_COLLECTION')&&APPROVED_MANUAL_COLLECTION===true&&defined('APPROVED_MANUAL_APPROVAL_SHA256')&&preg_match('/\\A[0-9a-f]{64}\\z/D',APPROVED_MANUAL_APPROVAL_SHA256)===1,'explicit_manual_collection_approval_required');
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




 $raw=base64_decode(APPROVED_MANUAL_MANIFEST_B64,true);manualNeed(is_string($raw)&&hash_equals(APPROVED_MANUAL_MANIFEST_SHA256,hash('sha256',$raw)),'manual_manifest_bytes_changed');$m=json_decode($raw,true,128,JSON_THROW_ON_ERROR);manualNeed(manualScopeOk($m),'manual_manifest_scope_changed');
 manualNeed($m['departure_date_from']===gmdate('Y-m-d',time()+86400)&&$m['departure_date_to']===gmdate('Y-m-d',time()+7*86400)&&$m['departure_date_to']<='2027-02-02','approved_manual_date_changed_regenerate_before_execution');
 $home=(string)getenv('HOME');manualNeed($home!==''&&$home[0]==='/','existing_source_home_unavailable');$root=$home.'/www/anytoour.ru/data';manualNeed(is_dir($root)&&!is_link($root),'existing_normal_collector_directory_unavailable');
 $pins=json_decode('{"db-v1.php":"ca7f11d6ec53e0cd3d4464e0f645f15b761bb734da1323689479838868d4abc4","price-observer-v1.php":"7e057905b1218815fc828c1c558f681eca67d079ad220ee3487b315661357843","top500-daily-plan-v1.php":"2a1440f21d65b6fb8bb5e3539e0edac83a72871135f36999148151d675446099","tourvisor-client-v1.php":"f669434c2b0bf6af0a68684d02eea964173f2d3c0408e49b94db9606e8273a17","top500-monthly-plan-v1.php":"3475098786dbd309cd8b2ce9a2c4f73ca6e6c323c19100e24ed6647bb1609a6c","local-tv-catalog-v1.php":"9dc60eb39100126d97307b83083d6a1b190cfdf8f684a18af47d8edd87b1c3b5","seasonal-page-gap-plan-v1.php":"3e1ef84ae88979f6e31691241dce13d01fdc40fd41138679043de3b83b616a5f","hotel-details-v1.php":"137bbf53ef87fab50505ecd41a075cb5f571193480da7f0e3face51190c0f77f"}',true,32,JSON_THROW_ON_ERROR);
 foreach($pins as $file=>$sha)manualNeed(is_file($root.'/'.$file)&&!is_link($root.'/'.$file)&&hash_file('sha256',$root.'/'.$file)===$sha,'installed_existing_source_helper_changed');
 // Do not redeclare a different physical copy or expose/import any credentials into artifacts.
 foreach(['v2_data_db'=>'db-v1.php','v2_data_tv_get'=>'tourvisor-client-v1.php','v2_data_observe_search_results'=>'price-observer-v1.php'] as $fn=>$file){
  if(function_exists($fn)){manualNeed(realpath((string)(new ReflectionFunction($fn))->getFileName())===realpath($root.'/'.$file),'existing_helper_loaded_from_other_boundary');}
  else{require_once $root.'/'.$file;}
 }
 manualNeed(function_exists('v2_data_db')&&function_exists('v2_data_tv_get')&&function_exists('v2_data_observe_search_results'),'existing_collector_interface_missing');
 putenv('TOURVISOR_HTTP_MAX_ATTEMPTS=1');manualNeed(v2_data_tv_max_attempts()===1,'automatic_retry_not_disabled_for_this_invocation');
 ob_start();try{manualNeed(v2_data_tourvisor_token()!=='','existing_source_gateway_not_configured');$pdo=v2_data_db();}finally{ob_end_clean();}
 manualNeed(defined('ANYTOUR_DATA_DB_NAME')&&hash_equals((string)ANYTOUR_DATA_DB_NAME,(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),'existing_data_database_boundary_changed');
 manualNeed(v2_data_tv_http_attempt_count()===0,'unexpected_prior_source_http_activity');$pdo->exec('START TRANSACTION READ ONLY');
 $check=$pdo->prepare('SELECT id,name,is_active,country_id FROM catalog_hotels WHERE id=? LIMIT 1');
 $fold=static fn(string $s):string=>trim(preg_replace('/[^\\p{L}\\p{N}]+/u',' ',mb_strtolower(preg_replace('/\\s*\\(.*?\\)/u','',$s)??$s,'UTF-8'))??'');
 foreach($m['native_bindings'] as $b){$check->execute([(int)$b['local_tv_id']]);$h=$check->fetch(PDO::FETCH_ASSOC);manualNeed(is_array($h)&&(int)$h['id']===(int)$b['local_tv_id']&&(int)$h['is_active']===1&&(int)$h['country_id']===(int)$b['expected_country_id']&&$fold($h['name'])===$fold($b['expected_name']),'current_native_country_active_name_changed');}
 $departure=$pdo->query('SELECT id,name,is_active FROM catalog_departures WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);manualNeed(is_array($departure)&&(int)$departure['is_active']===1&&trim($departure['name'])==='Москва','moscow_departure_identity_changed');
 $pdo->rollBack();
 manualNeed((int)$pdo->query("SELECT GET_LOCK('anytour_top500_monthly_v1',0)")->fetchColumn()===1,'existing_monthly_collector_busy_stop_without_taking_ownership');$locked=true;
 $prior=$pdo->prepare('SELECT id,search_id,status FROM tour_matrix_collection_attempts WHERE target_key=? ORDER BY id DESC LIMIT 2');
 $insert=$pdo->prepare("INSERT INTO tour_matrix_collection_attempts (criterion,target_key,departure_id,country_id,region_id,hotel_ids_json,date_from,date_to,nights_from,nights_to,status,started_at) VALUES ('hotel_batch',?,1,?,NULL,?,?,?,5,14,'started',NOW())");
 $bind=$pdo->prepare('UPDATE tour_matrix_collection_attempts SET search_id=? WHERE id=? AND search_id IS NULL');
 $finish=$pdo->prepare('UPDATE tour_matrix_collection_attempts SET status=?,rows_received=?,observations_written=observations_written+?,error_text=?,finished_at=NOW() WHERE id=?');
 $unknownRecord=$pdo->prepare("UPDATE tour_matrix_collection_attempts SET error_text='UNKNOWN_START_OUTCOME_DO_NOT_REPEAT' WHERE id=? AND status='started'");
 $deadline=microtime(true)+1200;
 foreach($m['groups'] as $i=>$g){
  manualNeed(microtime(true)<$deadline,'manual_wall_time_budget_exhausted');
  $target=['criterion'=>'hotel_batch','departure_id'=>1,'country_id'=>$g['country_id'],'region_id'=>null,'hotel_ids'=>$g['hotel_ids'],'date_from'=>$m['departure_date_from'],'date_to'=>$m['departure_date_to']];
  $params=manualTargetParams($target);$key='manualprice:'.substr(APPROVED_MANUAL_MANIFEST_SHA256,0,32).':g'.($i+1);$prior->execute([$key]);$existing=$prior->fetchAll(PDO::FETCH_ASSOC);
  manualNeed(manualMayStart(true,$existing!==[],$out['new_searches_attempted'],7,false),'original_attempt_exists_or_budget_stop_no_replay');
  $insert->execute([$key,$g['country_id'],json_encode($g['hotel_ids'],JSON_THROW_ON_ERROR),$m['departure_date_from'],$m['departure_date_to']]);$attempt=(int)$pdo->lastInsertId();
  $entry=['group_index'=>$i,'target_key'=>$key,'attempt_id'=>$attempt,'state'=>'SEARCH_START_INTENT_PERSISTED_BEFORE_PROVIDER','search_id'=>null,'source_http_attempts'=>0,'observations_written'=>0];
  $out['groups'][]=$entry;$n=count($out['groups'])-1;$out['new_searches_attempted']++;
  try{$start=manualApi('/tours/search',$params,$out);$sid=filter_var($start['searchId']??$start['id']??null,FILTER_VALIDATE_INT);manualNeed($sid!==false&&$sid>0,'search_identity_unusable_after_one_attempt');}
  catch(Throwable $problem){$unknownRecord->execute([$attempt]);$out['groups'][$n]['state']='SEARCH_START_OUTCOME_UNKNOWN_NO_RETRY';throw new RuntimeException('original_search_start_unknown_stop_no_replay');}
  $out['groups'][$n]['search_id']=$sid;$out['groups'][$n]['state']='ORIGINAL_SEARCH_ID_RECEIVED';$bind->execute([$sid,$attempt]);$complete=false;$polls=0;
  for($poll=0;$poll<20&&microtime(true)<$deadline;$poll++){sleep(2);$status=manualApi('/tours/search/'.$sid.'/status',['operatorStatus'=>false],$out);$polls++;if((int)($status['progress']??0)>=100||strtolower(trim((string)($status['status']??'')))==='complete'){$complete=true;break;}}
  $rows=manualRows(manualApi('/tours/search/'.$sid,['limit'=>100],$out));$trusted=[];foreach($rows as $hotel)if(is_array($hotel)&&manualAcceptHotel($target,$hotel))$trusted[]=$hotel;
  manualNeed(count($trusted)<=20,'returned_scope_exceeded');
  ob_start();try{$observed=v2_data_observe_search_results($trusted,['searchId'=>$sid,'departureId'=>1,'countryId'=>$g['country_id'],'adults'=>2,'childs'=>[],'currency'=>'RUB','source'=>'scheduled_monitor','maxHotels'=>20,'maxTours'=>50000]);}finally{ob_end_clean();}
  $written=(int)($observed['written']??0);$out['observation_rows_written']+=$written;$state=$complete?($trusted===[]?'empty':'success'):'timeout';
  $finish->execute([$state,count($trusted),$written,$complete?null:'partial results saved; original search only; no automatic repeat',$attempt]);
  $out['groups'][$n]=array_replace($out['groups'][$n],['state'=>$state,'poll_requests'=>$polls,'result_requests'=>1,'accepted_native_hotels'=>count($trusted),'observations_written'=>$written,'provider_results'=>$trusted,'observer_result'=>$observed]);
 }
 $out['ok']=true;$out['complete']=true;$out['manual_approval_sha256']=APPROVED_MANUAL_APPROVAL_SHA256;$out['manifest_sha256']=APPROVED_MANUAL_MANIFEST_SHA256;$out['actual_http_attempt_count']=v2_data_tv_http_attempt_count();$out['source_or_runtime_files_modified']=0;
}catch(Throwable $error){
 if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
 $out['error']=preg_match('/\\A[a-z][a-z0-9_]{1,120}\\z/D',$error->getMessage())?$error->getMessage():'manual_collection_stopped_original_outcome_must_be_reconciled';
 $out['automatic_retry']=false;
}finally{if(isset($locked)&&$locked&&isset($pdo))$pdo->query("SELECT RELEASE_LOCK('anytour_top500_monthly_v1')");}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
