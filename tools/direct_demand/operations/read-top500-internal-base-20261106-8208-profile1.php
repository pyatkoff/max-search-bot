<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
function need(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function presentProfile($v):bool{if($v===null||$v===''||$v===[])return false;if(is_array($v)){foreach($v as $p)if(presentProfile($p))return true;return false;}return true;}
function mergeProfile(array $old,array $fresh):array{foreach($fresh as $key=>$v){if(!presentProfile($v))continue;$old[$key]=is_array($v)&&!array_is_list($v)?mergeProfile(is_array($old[$key]??null)?$old[$key]:[],$v):$v;}return $old;}
function identityProfile(array $n):bool{return (int)($n['id']??0)===8208&&(int)($n['country_id']??0)===2&&(int)($n['is_active']??0)===1&&mb_strtolower(trim((string)($n['name']??'')),'UTF-8')===mb_strtolower(PROFILE_EXPECTED_NAME,'UTF-8');}
function manualEmptyProfile(?string $type,$length):bool{return in_array($type,['ARRAY','OBJECT'],true)&&is_numeric($length)&&(int)$length===0;}
function galleryProfile(array $photos,array $expected):bool{$hashes=[];foreach($photos as $p){if(!is_array($p)||!is_string($p['url']??null))return false;$url=$p['url'];$u=parse_url($url);if(!is_array($u)||($u['scheme']??'')!=='https'||($u['host']??'')!=='static.tourvisor.ru'||isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||str_contains(strtolower($u['query']??''),'token='))return false;$hashes[]=hash('sha256',$url);}sort($hashes,SORT_STRING);sort($expected,SORT_STRING);return count($hashes)===41&&count(array_unique($hashes))===41&&$hashes===$expected;}
function profileSql(array $fields):string{need($fields===PROFILE_FIELDS,'profile_fields_changed');$select=[];foreach($fields as $i=>$f){need(preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$f)===1,'profile_field_name_invalid');$select[]="JSON_EXTRACT(discovery_json,'$.".$f."') AS d".$i;$select[]="JSON_EXTRACT(content_json,'$.".$f."') AS c".$i;}return 'SELECT '.implode(',',$select).' FROM local_tv_hotels WHERE id=? LIMIT 1';}
$raw=base64_decode(PROFILE_INPUT_B64,true);need(is_string($raw)&&hash_equals(PROFILE_INPUT_SHA256,hash('sha256',$raw)),'profile_input_bytes_changed');$input=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
need(($input['operation']??null)===PROFILE_OPERATION&&($input['native_hotel_id']??null)===8208&&($input['country_id']??null)===2&&($input['expected_local_revision']??null)===3&&($input['fields']??null)===PROFILE_FIELDS&&is_array($input['gallery_sha256']??null)&&count($input['gallery_sha256'])===41,'profile_input_binding_changed');
if(getenv('ANYTOUR_TOP500_PROFILE_NEW_CASES')==='1'){
 $tests=[];$check=function(bool $ok,string $name)use(&$tests){need($ok,$name);$tests[]=$name;};
 $n=['id'=>8208,'name'=>PROFILE_EXPECTED_NAME,'country_id'=>2,'is_active'=>1];$check(identityProfile($n),'new_country2_native_positive');
 foreach(['id','name','country_id','is_active'] as $k){$bad=$n;$bad[$k]=$k==='name'?'WRONG_PROPERTY':0;$check(!identityProfile($bad),'wrong_native_'.$k);}
 $check(manualEmptyProfile('ARRAY',0),'empty_manual_array');$check(manualEmptyProfile('OBJECT','0'),'empty_manual_object');$check(!manualEmptyProfile('ARRAY',1),'nonempty_manual_rejected');$check(!manualEmptyProfile(null,0),'unknown_manual_not_empty');
 $check(mergeProfile(['a'=>'retained'],['a'=>null])===['a'=>'retained'],'null_cannot_erase_source');$check(mergeProfile(['a'=>1,'b'=>true],['a'=>0,'b'=>false])===['a'=>0,'b'=>false],'zero_false_are_values');$check(mergeProfile(['a'=>[1,2]],['a'=>[3]])===['a'=>[3]],'lists_remain_atomic');
 $photos=[];$expected=[];for($i=0;$i<41;$i++){$url='https://static.tourvisor.ru/synthetic_profile_fixture_'.$i.'.jpg';$photos[]=['url'=>$url,'caption'=>null];$expected[]=hash('sha256',$url);}
 $check(galleryProfile($photos,$expected),'declared_exact_native_gallery_positive');$bad=$photos;$bad[0]['url']='https://shared.example/foreign.jpg';$check(!galleryProfile($bad,$expected),'foreign_shared_photo_rejected');$bad=$photos;$bad[1]=$bad[0];$check(!galleryProfile($bad,$expected),'duplicate_photo_rejected');$bad=$photos;array_pop($bad);$check(!galleryProfile($bad,$expected),'incomplete_gallery_rejected');
 $sql=profileSql(PROFILE_FIELDS);$check(substr_count($sql,'?')===1&&str_contains($sql,'WHERE id=? LIMIT 1'),'one_exact_native_sql_scope');$check(!str_contains($sql,'source_json')&&!str_contains($sql,'tour_price')&&!str_contains($sql,'post_publications'),'unrelated_private_sources_excluded');
 $bad=PROFILE_FIELDS;$bad[]='source_json';$rejected=false;try{profileSql($bad);}catch(RuntimeException $e){$rejected=true;}$check($rejected,'field_expansion_rejected');
 echo json_encode(['operation'=>PROFILE_OPERATION,'new_native_profile_case_groups'=>count($tests),'old_case_groups_rerun'=>0,'server_db_provider_photo_calls'=>0]),PHP_EOL;exit;
}
$out=['operation'=>PROFILE_OPERATION,'ok'=>false,'db_writes'=>0,'gateway_calls'=>0,'provider_post_reads'=>0,'provider_channel_rights_checks'=>0,'message_sends'=>0,'photo_http'=>0,'profile'=>null,'ready_for_publication'=>false];
try {
 need(hash_file('sha256',getcwd().'/services/HotelDatabase.php')===PROFILE_CATALOG_CONNECTOR_SHA256,'catalog_connector_changed');
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

 $pdo=ConversationDb::connection();$shared=$catalog===$pdo;$catalog->exec('START TRANSACTION READ ONLY');if(!$shared)$pdo->exec('START TRANSACTION READ ONLY');
 foreach(['033_channel_post_context.sql','036_channel_post_member_delivery.sql','040_channel_post_reconciliation.sql'] as $name){$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$name]);need($q->fetchColumn()===hash_file('sha256',getcwd().'/migrations/'.$name),'publishing_schema_not_accepted');}
 $registry=new ChannelPostRegistryService($pdo);$targets=['max'=>['bot'=>'id9704048781_1_bot','channel'=>'-80492244209683','source'=>'max_anytour_msk1','id'=>9],'telegram'=>['bot'=>'anyru_bot','channel'=>'-1002262666616','source'=>'tg_anytour_msk','id'=>10]];$channels=[];
 foreach($targets as $platform=>$t){$bot=ChannelPostRuntime::identity($platform);need(is_array($bot)&&$bot['project_key']==='anytour'&&$bot['bot_key']===$t['bot'],'configured_bot_changed');$c=$registry->channel('anytour',$platform,$t['bot'],$t['channel']);need(is_array($c)&&(int)$c['id']===$t['id']&&$c['source_key']===$t['source']&&(int)$c['config_version']===2&&$c['button_text']==='✈️ Узнать стоимость тура','channel_configuration_changed');ob_start();try{$right=(new ChannelPostButtonTransport())->verifyChannel($c);}finally{ob_end_clean();}$out['provider_channel_rights_checks']++;need(($right['state']??'')==='ready','provider_channel_rights_changed');$channels[$platform]=$c;}
 $q=$catalog->prepare('SELECT id,name,country_id,is_active FROM catalog_hotels WHERE id=? LIMIT 1');$q->execute([8208]);$native=$q->fetch(PDO::FETCH_ASSOC);need(is_array($native)&&identityProfile($native),'current_country2_native_identity_changed');$out['last_completed_stage']='fresh_native_identity_guard';
 $q=$catalog->prepare('SELECT id,revision,state,source_fetched_at,JSON_TYPE(manual_json) AS manual_type,JSON_LENGTH(manual_json) AS manual_length FROM local_tv_hotels WHERE id=? LIMIT 1');$q->execute([8208]);$row=$q->fetch(PDO::FETCH_ASSOC);
 need(is_array($row)&&(int)$row['id']===8208&&(int)$row['revision']===3&&$row['state']==='ready'&&$row['source_fetched_at']===PROFILE_SOURCE_FETCHED_AT,'original_local_profile_binding_changed');
 $out['source_binding']=['native_hotel_id'=>8208,'country_id'=>2,'revision'=>3,'state'=>$row['state'],'source_fetched_at'=>$row['source_fetched_at'],'stored_datetime_timezone'=>'UNVERIFIED','manual_type'=>$row['manual_type'],'manual_length'=>$row['manual_length']===null?null:(int)$row['manual_length']];
 need(manualEmptyProfile($row['manual_type'],$row['manual_length']),'effective_manual_profile_requires_canonical_projection');$out['last_completed_stage']='local_revision_manual_binding';
 $q=$catalog->prepare(profileSql(PROFILE_FIELDS));$q->execute([8208]);$data=$q->fetch(PDO::FETCH_ASSOC);need(is_array($data),'unread_normalized_profile_missing');$discovery=[];$content=[];
 foreach(PROFILE_FIELDS as $i=>$f){foreach(['d'=>'discovery','c'=>'content'] as $p=>$role){$v=$data[$p.$i];need($v===null||is_string($v)&&strlen($v)<=1048576,'selected_profile_field_bounds');$decoded=$v===null?null:json_decode($v,true,128,JSON_THROW_ON_ERROR);if($role==='discovery')$discovery[$f]=$decoded;else$content[$f]=$decoded;}}
 $profile=mergeProfile($discovery,$content);$out['partial_profile']=$profile;$out['last_completed_stage']='new_selected_normalized_fields';
 need(is_string($profile['name']??null)&&mb_strtolower(trim($profile['name']),'UTF-8')===mb_strtolower(PROFILE_EXPECTED_NAME,'UTF-8')&&is_array($profile['country']??null)&&(int)($profile['country']['id']??0)===2,'effective_profile_country_name_changed');
 need(is_array($profile['photoDetails']??null)&&galleryProfile($profile['photoDetails'],$input['gallery_sha256']),'original_declared_gallery_binding_changed');
 $out['profile']=$profile;unset($out['partial_profile']);$out['last_completed_stage']='complete_selected_profile';$out['installed_sha']=EXPECTED_INSTALLED_SHA;$out['source_capture_parent_run']=42166900411;$out['source_capture_parent_artifact']=13479576337;$out['copyright_reuse_proof']=null;$out['hero_quality_proof']=null;$out['server_epoch_utc']=time();$out['ok']=true;
 $pdo->rollBack();if(!$shared)$catalog->rollBack();
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if(isset($catalog)&&$catalog->inTransaction())$catalog->rollBack();$out['error_class']=get_class($e);$out['sqlstate']=$e instanceof PDOException?($e->errorInfo[0]??null):null;$out['error']=preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$e->getMessage())?$e->getMessage():'bounded_unread_profile_failed';$out['metadata_failure_is_not_absence']=true;}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;exit($out['ok']?0:2);
