<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
function need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function scopeOk(array $s):bool{
 $ids=$s['hotel_ids']??null;
 if(count(TOP500_CANONICAL_IDS)!==492||count(array_unique(TOP500_CANONICAL_IDS,SORT_REGULAR))!==492||!is_array($ids)||!array_is_list($ids)||$ids!==TOP500_HOTEL_IDS||count($ids)!==10||count(array_unique($ids,SORT_REGULAR))!==10)return false;
 foreach($ids as $id)if(!is_int($id)||$id<1||!in_array($id,TOP500_CANONICAL_IDS,true)||in_array($id,$s['excluded_published_native_ids']??[],true))return false;
 return ($s['schema']??null)===1&&($s['operation']??null)===TOP500_OPERATION&&($s['source_repository']??null)==='pyatkoff/poisk-turov-test'&&($s['source_revision']??null)==='64fa44201d77d8b6ea2abd4f9580deb7cf2b9d4d'&&($s['canonical_ids_sha256']??null)===TOP500_ROSTER_SHA256&&($s['id_namespace']??null)==='tourvisor_native_catalog_hotels_id'&&($s['excluded_published_native_ids']??null)===TOP500_PUBLISHED_IDS&&($s['max_hotels']??null)===10&&($s['price_max_age_seconds']??null)===259200&&($s['metadata_only']??null)===true&&($s['provider_post_reads']??null)===0&&($s['photo_http']??null)===0&&($s['quotes']??null)===0&&($s['post_writes']??null)===0;
}
function registryMetadataSql(array $ids):string{
 need($ids===TOP500_HOTEL_IDS,'registry_scope_changed');
 $marks=implode(',',array_fill(0,count($ids),'?'));
 return "SELECT pc.platform,CAST(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) AS UNSIGNED) AS native_hotel_id,v.state,COUNT(*) AS matches FROM post_publications pp JOIN post_channels pc ON pc.id=pp.post_channel_id JOIN post_versions v ON v.id=pp.current_version_id AND v.publication_id=pp.id WHERE pp.post_channel_id IN (9,10) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.metadata.hotel_catalog_id')) AS UNSIGNED) IN (".$marks.") GROUP BY pc.platform,native_hotel_id,v.state ORDER BY native_hotel_id,pc.platform,v.state";
}
function metadataUrl(?string $v):?string{
 if($v===null||strlen($v)>2048||preg_match('/\Ahttps:\/\/[^\s]+\z/D',$v)!==1)return null;
 $p=parse_url($v);if(!is_array($p)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))return null;
 if(str_contains(strtolower($p['host']),'firebase')||str_contains(strtolower($p['query']??''),'token='))return null;return $v;
}
function authoritativeTimeType(?string $v):bool{return $v!==null&&preg_match('/\Atimestamp(?:\([0-6]\))?\z/D',strtolower($v))===1;}
function columns(PDO $db,string $table):?array{
 need(in_array($table,['catalog_hotels','local_tv_hotels','tour_price_observations'],true),'unapproved_schema_table');
 try{$rows=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $r)$GLOBALS['top500_column_types'][$table][$r['Field']]=strtolower($r['Type']);return array_column($rows,'Field');}
 catch(PDOException $e){if(($e->errorInfo[0]??'')==='42S02')return null;throw $e;}
}
function selectColumn(array $cols,string $name):string{return in_array($name,$cols,true)?$name:'NULL AS '.$name;}
function jsonArrayMetadata($v):?array{if($v===null)return null;need(is_string($v)&&strlen($v)<=1048576,'metadata_field_size');$a=json_decode($v,true,128,JSON_THROW_ON_ERROR);need(is_array($a)&&count($a)<=1000,'metadata_array_invalid');return $a;}
$raw=base64_decode(TOP500_SCOPE_B64,true);need(is_string($raw)&&hash_equals(TOP500_SCOPE_SHA256,hash('sha256',$raw)),'scope_bytes_changed');
$scope=json_decode($raw,true,256,JSON_THROW_ON_ERROR);need(is_array($scope)&&scopeOk($scope),'scope_rejected');
if(getenv('ANYTOUR_TOP500_REGISTRY_OWNER_CASES')==='1'){
 $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->sqliteCreateFunction('JSON_UNQUOTE',fn($v)=>$v,1);
 $db->exec('CREATE TABLE post_channels(id INTEGER PRIMARY KEY,platform TEXT);CREATE TABLE post_publications(id INTEGER PRIMARY KEY,post_channel_id INTEGER,current_version_id INTEGER);CREATE TABLE post_versions(id INTEGER PRIMARY KEY,publication_id INTEGER,state TEXT,snapshot_json TEXT)');
 $db->exec("INSERT INTO post_channels VALUES(9,'max'),(10,'telegram'),(11,'max');INSERT INTO post_publications VALUES(1,9,1),(2,10,2),(3,9,3),(4,11,4)");
 $q=$db->prepare('INSERT INTO post_versions VALUES(?,?,?,?)');
 foreach([[1,1,'sent',22879],[2,2,'pending',22879],[3,3,'sent',999999999],[4,4,'sent',22879]] as $r)$q->execute([$r[0],$r[1],$r[2],json_encode(['metadata'=>['hotel_catalog_id'=>$r[3]]])]);
 $sql=registryMetadataSql(TOP500_HOTEL_IDS);$q=$db->prepare($sql);$q->execute(TOP500_HOTEL_IDS);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 need(count($rows)===2,'registry_native_channel_scope_not_exact');
 need(array_column($rows,'state')===['sent','pending'],'registry_version_states_not_preserved');
 need(array_keys($rows[0])===['platform','native_hotel_id','state','matches'],'registry_projection_private_fields_exposed');
 need(array_sum(array_column($rows,'matches'))===2&&array_unique(array_column($rows,'native_hotel_id'))===[22879],'foreign_hotel_or_channel_not_excluded');
 need(substr_count($sql,'?')===10,'registry_explicit_scope_bounds_changed');
 $rejected=false;try{$q=$db->prepare(str_replace('v.state','pp.state',$sql));$q->execute(TOP500_HOTEL_IDS);}catch(PDOException $e){$rejected=true;}need($rejected,'wrong_state_table_owner_not_detected');
 $rejected=false;try{registryMetadataSql(array_reverse(TOP500_HOTEL_IDS));}catch(RuntimeException $e){$rejected=true;}need($rejected,'registry_wrong_native_scope_admitted');
 echo json_encode(['operation'=>TOP500_OPERATION,'new_native_registry_case_groups'=>7,'old_case_groups_rerun'=>0,'server_db_provider_calls'=>0]),PHP_EOL;exit;
}
if(getenv('ANYTOUR_TOP500_NEW_CASES')==='1'){
 $n=1;
 foreach(['extra','missing','duplicate','string_id','reordered','foreign_id','published_id','namespace','source_repo','source_sha','roster_sha','age','writes','metadata_only'] as $case){
  $b=$scope;
  if($case==='extra')$b['hotel_ids'][]=1;elseif($case==='missing')array_pop($b['hotel_ids']);elseif($case==='duplicate')$b['hotel_ids'][1]=$b['hotel_ids'][0];elseif($case==='string_id')$b['hotel_ids'][0]=(string)$b['hotel_ids'][0];elseif($case==='reordered')$b['hotel_ids']=array_reverse($b['hotel_ids']);elseif($case==='foreign_id')$b['hotel_ids'][0]=999999999;elseif($case==='published_id')$b['hotel_ids'][0]=12114;elseif($case==='namespace')$b['id_namespace']='anytour_publisher_id';elseif($case==='source_repo')$b['source_repository']='pyatkoff/max-search-bot';elseif($case==='source_sha')$b['source_revision']=str_repeat('0',40);elseif($case==='roster_sha')$b['canonical_ids_sha256']=str_repeat('0',64);elseif($case==='age')$b['price_max_age_seconds']=259201;elseif($case==='writes')$b['post_writes']=1;else $b['metadata_only']=false;
  need(!scopeOk($b),'unsafe_scope_admitted');$n++;
 }
 need(metadataUrl('https://owner.example/declared.jpg')==='https://owner.example/declared.jpg','declared_metadata_url_rejected');$n++;
 foreach(['http://owner.example/photo.jpg','https://user:pass@owner.example/photo.jpg','https://firebasestorage.googleapis.com/photo.jpg?token=example','https://owner.example/photo.jpg#foreign'] as $v){need(metadataUrl($v)===null,'unsafe_metadata_url_admitted');$n++;}
 need(metadataUrl(null)===null&&jsonArrayMetadata(null)===null,'missing_metadata_became_zero');$n++;need(authoritativeTimeType('timestamp'),'native_timestamp_rejected');$n++;need(authoritativeTimeType('timestamp(6)'),'native_fractional_timestamp_rejected');$n++;need(!authoritativeTimeType('datetime')&&!authoritativeTimeType(null),'naive_or_missing_price_time_admitted');$n++;need($n===24,'new_case_count_changed');
 echo json_encode(['operation'=>TOP500_OPERATION,'new_native_input_cases'=>$n,'old_fixtures'=>0,'server_db_provider_photo_calls'=>0]),PHP_EOL;exit;
}
$out=['operation'=>TOP500_OPERATION,'mode'=>'exact_owner_top500_retained_internal_metadata','ok'=>false,'db_writes'=>0,'gateway_calls'=>0,'provider_post_reads'=>0,'provider_channel_rights_checks'=>0,'message_sends'=>0,'photo_http'=>0,'hotels'=>[]];
try {
 need(hash_file('sha256',getcwd().'/services/HotelDatabase.php')===TOP500_CATALOG_CONNECTOR_SHA256,'catalog_connector_changed');
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



    require_once getcwd().'/services/HotelDatabase.php';need(HotelDatabase::configured(),'hotel_catalog_connection_not_configured');
    $catalog=HotelDatabase::connection();

 $pdo=ConversationDb::connection();$shared=$catalog===$pdo;
 $catalog->exec('START TRANSACTION READ ONLY');if(!$shared)$pdo->exec('START TRANSACTION READ ONLY');
 foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $name){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$name]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$name),'publishing_schema_not_accepted');}
 $registry=new ChannelPostRegistryService($pdo);$targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];$channels=[];
 foreach($targets as $platform=>$t){$bot=ChannelPostRuntime::identity($platform);need(is_array($bot)&&$bot['project_key']==='anytour'&&$bot['bot_key']===$t['bot'],'configured_bot_changed');$c=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);need(is_array($c)&&(int)$c['id']===$t['id']&&$c['source_key']===$t['source']&&(int)$c['config_version']===2&&$c['button_text']==='✈️ Узнать стоимость тура','channel_configuration_changed');ob_start();try{$right=(new ChannelPostButtonTransport())->verifyChannel($c);}finally{ob_end_clean();}$out['provider_channel_rights_checks']++;need(($right['state']??'')==='ready','provider_channel_rights_changed');$channels[$platform]=$c;}
 $ids=$scope['hotel_ids'];$marks=implode(',',array_fill(0,count($ids),'?'));
 $hc=columns($catalog,'catalog_hotels');need(is_array($hc),'native_catalog_missing');foreach(['id','name','country_id','is_active'] as $c)need(in_array($c,$hc,true),'native_identity_schema_missing');
 $fields=['id','name','country_id','country_name','region_id','region_name','is_active','primary_image_url','image_updated_at'];
 $q=$catalog->prepare('SELECT '.implode(',',array_map(fn($f)=>selectColumn($hc,$f),$fields)).' FROM catalog_hotels WHERE id IN ('.$marks.') ORDER BY id');$q->execute($ids);$native=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$id=(int)$r['id'];need(in_array($id,$ids,true)&&!isset($native[$id]),'unexpected_native_row');$r['id']=$id;$r['country_id']=$r['country_id']===null?null:(int)$r['country_id'];$r['is_active']=$r['is_active']===null?null:(int)$r['is_active'];$r['primary_image_url']=metadataUrl($r['primary_image_url']);$native[$id]=$r;}
 $out['partial_native_metadata']=$native;$out['last_completed_stage']='native_metadata';
 $lc=columns($catalog,'local_tv_hotels');$details=[];$detailsState='TABLE_ABSENT_UNVERIFIED';
 $lreq=['id','state','revision','source_fetched_at','source_json','discovery_json','content_json','manual_json'];
 if(is_array($lc)&&!array_diff($lreq,$lc)){
  $detailsState='EXPLICIT_NATIVE_METADATA_READ';
  $sel="id,state,revision,source_fetched_at,(source_json IS NOT NULL) AS details_available,JSON_EXTRACT(discovery_json,'$.primaryImage') AS discovery_primary,JSON_EXTRACT(discovery_json,'$.images') AS discovery_images,JSON_EXTRACT(content_json,'$.primaryImage') AS content_primary,JSON_EXTRACT(content_json,'$.images') AS content_images,JSON_EXTRACT(manual_json,'$.primaryImage') AS manual_primary,JSON_EXTRACT(manual_json,'$.images') AS manual_images,JSON_KEYS(manual_json) AS manual_field_names";
  $q=$catalog->prepare('SELECT '.$sel.' FROM local_tv_hotels WHERE id IN ('.$marks.') ORDER BY id');$q->execute($ids);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
   $id=(int)$r['id'];need(in_array($id,$ids,true)&&!isset($details[$id]),'unexpected_local_tv_row');$urls=[];
   foreach(['discovery_primary','content_primary','manual_primary'] as $k){if($r[$k]!==null){$v=json_decode($r[$k],true,128,JSON_THROW_ON_ERROR);$url=is_string($v)?metadataUrl($v):null;if($url!==null)$urls[$url]=true;}}
   foreach(['discovery_images','content_images','manual_images'] as $k){foreach(jsonArrayMetadata($r[$k])??[] as $v){$url=is_string($v)?metadataUrl($v):null;if($url!==null)$urls[$url]=true;}}
   $details[$id]=['id'=>$id,'content_state'=>$r['state'],'revision'=>(int)$r['revision'],'source_fetched_at'=>$r['source_fetched_at'],'details_available'=>(bool)$r['details_available'],'distinct_declared_image_urls'=>array_keys($urls),'distinct_declared_image_url_count'=>count($urls),'manual_field_names'=>jsonArrayMetadata($r['manual_field_names']),'effective_media_proof'=>'NOT_PROJECTED_MANUAL_OVERRIDES_MAY_REQUIRE_CANONICAL_READER','rights'=>'UNVERIFIED','whole_complex_hero'=>'UNVERIFIED'];
  }
 }elseif(is_array($lc))$detailsState='SCHEMA_INCOMPLETE_UNVERIFIED';
 $out['partial_local_tv_metadata']=$details;$out['last_completed_stage']='local_tv_metadata';
 $pc=columns($catalog,'tour_price_observations');$prices=[];$priceState='TABLE_ABSENT_UNVERIFIED';
 $preq=['hotel_id','created_at','departure_date','departure_id','adults','children_count','currency','nights','room_type','meal_id','source','search_id','tour_id','price'];
 $now=time();$from=$now-259200;$today=gmdate('Y-m-d',$now);
 if(is_array($pc)&&!array_diff($preq,$pc)&&authoritativeTimeType($GLOBALS['top500_column_types']['tour_price_observations']['created_at']??null)){
  $priceState='LAST72H_PERSISTED_UTC_METADATA_ONLY';
  $sql="SELECT hotel_id,COUNT(*) AS recent_rows,MIN(UNIX_TIMESTAMP(created_at)) AS oldest_persisted_epoch,MAX(UNIX_TIMESTAMP(created_at)) AS newest_persisted_epoch,SUM(CASE WHEN departure_id=1 AND adults=2 AND children_count=0 AND currency='RUB' AND departure_date>? AND departure_date<='2027-02-02' AND nights BETWEEN 5 AND 14 AND room_type<>'' AND meal_id IN (2,3,4,5,7,9) AND search_id>0 AND tour_id<>'' AND price>0 AND source IN ('user_search','scheduled_monitor','hot_tours') THEN 1 ELSE 0 END) AS existing_policy_condition_rows FROM tour_price_observations WHERE hotel_id IN (".$marks.") AND UNIX_TIMESTAMP(created_at)>=? AND UNIX_TIMESTAMP(created_at)<=? GROUP BY hotel_id ORDER BY hotel_id";
  $q=$catalog->prepare($sql);$q->execute(array_merge([$today],$ids,[$from,$now]));
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$id=(int)$r['hotel_id'];need(in_array($id,$ids,true)&&!isset($prices[$id]),'unexpected_price_row');foreach($r as &$v)$v=$v===null?null:(int)$v;unset($v);$r['stored_search_price_is_final_or_available']=false;$prices[$id]=$r;}
 }elseif(is_array($pc))$priceState='NO_AUTHORITATIVE_PERSISTED_UTC_OR_CONDITION_SCHEMA_UNVERIFIED';
 $out['partial_stored_price_metadata']=$prices;$out['last_completed_stage']='stored_price_metadata';
 $sql=registryMetadataSql($ids);
 $q=$pdo->prepare($sql);$q->execute($ids);$dedup=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$id=(int)$r['native_hotel_id'];need(in_array($id,$ids,true)&&in_array($r['platform'],['max','telegram'],true),'unexpected_registry_metadata');$dedup[$id][]=['platform'=>$r['platform'],'state'=>$r['state'],'matches'=>(int)$r['matches']];}
 foreach($ids as $id)$out['hotels'][]=['native_hotel_id'=>$id,'owner_top500_membership'=>true,'current_native'=>$native[$id]??null,'local_tv_metadata_state'=>$detailsState,'local_tv_metadata'=>$details[$id]??null,'stored_price_metadata_state'=>$priceState,'stored_price_last72h'=>$prices[$id]??null,'registry_exact_native_metadata_matches'=>$dedup[$id]??[],'registry_metadata_absence_not_full_dedup_acceptance'=>true,'publisher_role'=>null,'ready_for_publication'=>false];
 $out['last_completed_stage']='registry_metadata';unset($out['partial_native_metadata'],$out['partial_local_tv_metadata'],$out['partial_stored_price_metadata']);
 $pdo->rollBack();if(!$shared)$catalog->rollBack();
 $out['catalog_connection_role']='EXISTING_ANYTOUR_DATA_DB_CONTRACT_SHARED_WITH_PRODUCER_SOURCE_NO_CONFIG_VALUES_EXPORTED';$out['server_epoch_utc']=$now;$out['price_window_start_epoch_utc']=$from;$out['price_departure_after_utc_date']=$today;$out['scope_native_ids']=$ids;$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['ok']=true;
}catch(Throwable $e){$out['error_class']=get_class($e);$out['sqlstate']=$e instanceof PDOException?($e->errorInfo[0]??null):null;$out['metadata_failure_is_not_absence']=true;if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if(isset($catalog)&&$catalog->inTransaction())$catalog->rollBack();$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_internal_metadata_read_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
