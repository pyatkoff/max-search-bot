<?php
declare(strict_types=1);
/* One canonical TOP500 ID identity read; no acceptance or runtime mutation. */
const HOTEL_EXPECTED_INSTALLED_SHA='0e57de3a6a06cc46b97ff10777c9821818b03af1';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_STATUS_KIND='hotel-server-private-incident-v1';
const HOTEL_OPERATION='081834ab2e0acb0f75c0f742ee813f52b3e3a07c65930dbfe0d3d729b15bdd89';
const HOTEL_SOURCE_PINS_JSON='{"services/HotelDatabase.php":"8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5"}';
const HOTEL_OBSERVATION_IDENTITY_JSON='{"binding_admission":false,"candidate_filter":{"id":39873,"namespace":"legacy_catalog/catalog_hotels.id"},"canonical_top500":{"blob_sha":"abc30114e8d9f780db4b2ef6b56164a1fa47fb9d","branch":"release/search3-production-ready-v1","commit":"863ae0ff8ad1424b2a4645877d89cb4849ab7a43","count":492,"ordered_ids_sha256":"0ec0e7ed0852b88e2264249c1e70da54194e5138fdbb19026a3b0a3de88f2b61","original_owner_source":"500 отелей .csv","path":"v2/data/hotel-popularity-v1.php","position":11,"position_meaning":"original canonical cohort order; not a quality rating","repository":"pyatkoff/poisk-turov-test","sha256":"d4a49f07e6a4dbe1f8256fc350e991cfd54cdc1b5afc84b63d14ea595344af53","url":"https://github.com/pyatkoff/poisk-turov-test/blob/863ae0ff8ad1424b2a4645877d89cb4849ab7a43/v2/data/hotel-popularity-v1.php"},"country_policy":"Read country_id from the exact legacy row; require the returned local-tv item to match it. No country inferred from hotel name.","database_writes":0,"expected_catalog_database_identity_sha256":"a0eeb9b531321e9854c472e91a4ff4b7191533b8037c707fadc5251d964b1491","expected_ops_parent":"19ba436daefc08f5f097655cf1d6317e7d2a1e0e","installed_sha":"0e57de3a6a06cc46b97ff10777c9821818b03af1","mapping_connect_timeout_seconds":10,"mapping_host":"anytoour.ru","mapping_paths":["/_preview/search3-local-candidate/data/hotel-details-read-v1.php","/_preview/search3-next-candidate/data/local-tv-catalog-read-v1.php"],"mapping_total_timeout_seconds":20,"maximum_mapping_body_bytes":65536,"maximum_mapping_gets":2,"maximum_query_rows":2,"maximum_read_only_snapshots":1,"maximum_retained_candidates":1,"maximum_select_queries":2,"observation_kind":"top500_catalog_identity","original_installed_deploy_run_id":"42168413861","original_predecessor_run_id":"41862474777","provider_calls":0,"schema":"hotel-server-private-incident-v1","server_file_writes":0,"source_pins":{"services/HotelDatabase.php":"8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5"},"source_ref":"https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-6607905718"}';
const HOTEL_EXPECTED_CATALOG_DB='a0eeb9b531321e9854c472e91a4ff4b7191533b8037c707fadc5251d964b1491';
const HOTEL_TARGET_LEGACY_ID='39873';
const HOTEL_PLAIN_LIMIT=524288;
const HOTEL_WIRE_LIMIT=786432;

function hotelFail(string $reason): never { throw new DomainException($reason); }

function hotelJson(string $raw): array {
    $v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($v))hotelFail('private_shape_unconfirmed');return $v;
}

function hotelHash(mixed $value): string {
    if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value))hotelFail('saved_hash_unconfirmed');
    return $value;
}

function hotelInt(mixed $value,int $maximum=PHP_INT_MAX): int {
    if(is_int($value))$text=(string)$value;elseif(is_string($value))$text=$value;else hotelFail('saved_integer_unconfirmed');
    if(!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$text)||strlen($text)>strlen((string)$maximum)
        ||(strlen($text)===strlen((string)$maximum)&&strcmp($text,(string)$maximum)>0))hotelFail('saved_integer_unconfirmed');
    return (int)$text;
}

function hotelInstalled(string $app): string {
    $out=[];$code=1;exec('git -C '.escapeshellarg($app).' rev-parse HEAD 2>/dev/null',$out,$code);
    $sha=trim(implode('',$out));
    if($code!==0||!preg_match('/\A[a-f0-9]{40}\z/D',$sha)||!hash_equals(HOTEL_EXPECTED_INSTALLED_SHA,$sha))
        hotelFail('installed_release_unconfirmed');
    return $sha;
}

function hotelSeal(array $payload): array {
    $recipient=<<<'PUBLIC_RECEIPT_RECIPIENT'
-----BEGIN PUBLIC KEY-----
MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAuN8W3YQpfXZak/D2Whmc
58DfcWVrLJRumXPFLQjaHhcueBq9zWnFf6jp8/x9IIZ/CZDz/vxvdh7PmSAW/Faa
ciVa2HDRJmxO3ZteBVcnVWatO9j1GrbvAZc/OCYxflqBwueetSikhlqFdeqKDok4
gKE81+uPhl28Um8leV4+ouLmM9Pic4gDSqL87uMyKSe1O5lDmUbvJm3Qyfs+ZfOn
PBuEsUuvMUkVZ7c6FrhY74pEzyT6Mx6XdnnVdyZ+ksGcmCAroAQoPtia7ebv17Xi
pJjgwEokntbAAlvqwrbH8X7X+MpeI84EXAh6kJOPxCj8l/Oxb1xdZR/wC0DorQkj
R7yipD6y0nyjWWHdwucd4Lty0H332crtnxktGYIfymRXprZb/creHaGOw39gIvGV
T8YNFRu1JmL3tbjxnO4ekvJfvOzwErVxLahHT0RJCcZ43a7zga+9oMaFLII9RqGd
LPAX0T14+63yqvazaMLpk7UOTjSedaIGCxEfuviVR4pzAgMBAAE=
-----END PUBLIC KEY-----
PUBLIC_RECEIPT_RECIPIENT;
    $recipient.="\n";
    if(!hash_equals(HOTEL_RECEIPT_RECIPIENT_SHA,hash('sha256',$recipient)))hotelFail('receipt_recipient_unconfirmed');
    $aad=HOTEL_STATUS_KIND.'|'.HOTEL_EXPECTED_INSTALLED_SHA.'|'.HOTEL_OPERATION;
    $plain=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($plain)>HOTEL_PLAIN_LIMIT)hotelFail('receipt_bounds_unconfirmed');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||strlen($tag)!==16||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING))hotelFail('sealing_unavailable');
    unset($key,$plain);
    $envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'sealed','operator_provider_calls'=>0,
        'algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>HOTEL_RECEIPT_RECIPIENT_SHA,'aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
        'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
    if(strlen(json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))+1>HOTEL_WIRE_LIMIT)hotelFail('wire_receipt_bounds_unconfirmed');
    return $envelope;
}

function hotelCanonical(mixed $value): string {
    $sort=static function(mixed $v)use(&$sort):mixed{
        if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as $k=>$item)$v[$k]=$sort($item);return $v;
    };
    return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

function identitySources(string $app): void {
    hotelInstalled($app);
    $pins=hotelJson(HOTEL_SOURCE_PINS_JSON);
    if(array_keys($pins)!==['services/HotelDatabase.php']
        ||($pins['services/HotelDatabase.php']??null)!=='8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5')
        hotelFail('catalog_identity_source_pin_unconfirmed');
    $file=$app.'/services/HotelDatabase.php';clearstatcache(true,$file);
    if(is_link($file)||realpath($file)!==$file||!is_file($file)||filesize($file)>131072)
        hotelFail('catalog_identity_source_unconfirmed');
    $raw=file_get_contents($file,false,null,0,131073);
    if(!is_string($raw)||strlen($raw)>131072||hash('sha256',$raw)!==$pins['services/HotelDatabase.php'])
        hotelFail('catalog_identity_source_unconfirmed');
}

function identityPositive(mixed $value,bool $jsonInteger=false): int {
    if($jsonInteger&&!is_int($value))hotelFail('catalog_identity_integer_unconfirmed');
    $id=hotelInt($value);
    if($id<1)hotelFail('catalog_identity_integer_unconfirmed');
    return $id;
}

function identityRows(PDO $pdo,string $sql,int $cap): array {
    global $report;
    if(!str_starts_with($sql,'SELECT ')||str_contains($sql,'FOR UPDATE')||$cap<1||$cap>2)
        hotelFail('catalog_identity_read_scope');
    if(++$report['query_count']>2)hotelFail('catalog_identity_query_bound');
    $s=$pdo->prepare($sql);$s->execute();$rows=[];
    try{while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){
        if(count($rows)>=$cap||strlen(json_encode($row,JSON_THROW_ON_ERROR))>4096)
            hotelFail('catalog_identity_read_bound');
        $rows[]=$row;
    }}finally{$s->closeCursor();}
    return $rows;
}

function identitySnapshot(PDO $pdo): void {
    global $catalogPdo,$snapshotOwned,$report;
    if($catalogPdo!==null||$pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
        ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
        ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))
        hotelFail('catalog_identity_database_context');
    $catalogPdo=$pdo;$snapshotOwned=true;
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $report['snapshot_count']=1;
    $report['snapshot']=['started'=>true,'rolled_back'=>false,'started_at_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
    try{
        $identity=identityRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ DATABASE() AS db,@@hostname AS host,@@port AS port',1);
        if(count($identity)!==1||!is_string($identity[0]['db']??null)||$identity[0]['db']===''
            ||!is_string($identity[0]['host']??null)||$identity[0]['host']==='')
            hotelFail('catalog_identity_database_unconfirmed');
        $digest=hash('sha256',hotelCanonical(['database'=>$identity[0]['db'],'host'=>$identity[0]['host'],
            'port'=>hotelInt($identity[0]['port'],65535)]));
        $report['catalogue_database_identity_sha256']=$digest;
        if(!hash_equals(HOTEL_EXPECTED_CATALOG_DB,$digest))hotelFail('catalog_identity_database_changed');
        $rows=identityRows($pdo,"SELECT /*+ MAX_EXECUTION_TIME(2500) */ id,country_id,region_id,name FROM catalog_hotels"
            ." WHERE id=39873 LIMIT 2",2);
        $report['candidate']['returned_rows']=count($rows);$report['candidate']['overflow']=count($rows)>1;
        foreach(array_slice($rows,0,1) as $row){
            $row['id']=identityPositive($row['id']??null);$row['country_id']=identityPositive($row['country_id']??null);
            $row['region_id']=($row['region_id']??null)===null?null:hotelInt($row['region_id']);
            if($row['id']!==(int)HOTEL_TARGET_LEGACY_ID||!is_string($row['name']??null)||trim($row['name'])===''||strlen($row['name'])>1024)
                hotelFail('catalog_identity_candidate_shape');
            $report['candidate']['rows'][]=$row;
        }
    }finally{
        if(!$pdo->inTransaction())hotelFail('catalog_identity_snapshot_lost');
        $pdo->rollBack();$snapshotOwned=false;$report['snapshot']['rolled_back']=true;
        $report['snapshot']['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    }
}

function identityAttachBodies(): void {
    global $report,$httpBodies;
    foreach($httpBodies as $kind=>$body){
        $report['http'][$kind]['body_encoding']='base64';
        $report['http'][$kind]['body_base64']=base64_encode($body);
        $report['http'][$kind]['body_bytes']=strlen($body);
        $report['http'][$kind]['body_sha256']=hash('sha256',$body);
        $report['http'][$kind]['body_representation']='bytes_delivered_to_curl_write_callback';
        $report['http'][$kind]['body_hash_scope']=($report['http'][$kind]['body_complete']??false)
            ?'complete_received_body':'captured_prefix_or_empty';
    }
}

function identityGet(string $kind,int $id): array {
    global $report,$httpBodies;
    identityPositive($id,true);
    $paths=['anytour'=>'/_preview/search3-local-candidate/data/hotel-details-read-v1.php',
        'local_tv'=>'/_preview/search3-next-candidate/data/local-tv-catalog-read-v1.php'];
    if(!isset($paths[$kind])||isset($report['http'][$kind])||$report['http_attempts']>=2)
        hotelFail('catalog_identity_http_scope');
    if(!function_exists('curl_init'))hotelFail('catalog_identity_http_unavailable');
    $query=$kind==='anytour'?'catalog=anytour&legacyHotelIds%5B%5D='.$id:'oldLocalHotelIds%5B%5D='.$id;
    $url='https://anytoour.ru'.$paths[$kind].'?'.$query;$parts=parse_url($url);
    if(!is_array($parts)||($parts['scheme']??null)!=='https'||($parts['host']??null)!=='anytoour.ru'
        ||($parts['path']??null)!==$paths[$kind]||($parts['query']??null)!==$query
        ||isset($parts['port'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))
        hotelFail('catalog_identity_http_scope');
    $httpBodies[$kind]='';$overflow=false;$seen=0;$ch=curl_init($url);
    if($ch===false)hotelFail('catalog_identity_http_unavailable');
    $report['http'][$kind]=['method'=>'GET','url'=>$url,'request_attempted'=>false,'body_complete'=>false,
        'body_limit'=>65536,'status'=>null,'content_type'=>null,'curl_errno'=>null,'shape_verified'=>false];
    try{
        $options=[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,
            CURLOPT_NOSIGNAL=>true,CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_HTTPAUTH=>0,
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Encoding: identity'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use($kind,&$overflow,&$seen):int{
                global $httpBodies;
                $length=strlen($chunk);$seen+=$length;$remaining=65536-strlen($httpBodies[$kind]);
                if($length>$remaining){$httpBodies[$kind].=substr($chunk,0,max(0,$remaining));$overflow=true;return 0;}
                $httpBodies[$kind].=$chunk;return $length;
            }];
        if(!curl_setopt_array($ch,$options))hotelFail('catalog_identity_http_options');
        $report['http_attempts']++;$report['http'][$kind]['request_attempted']=true;
        $report['http'][$kind]['started_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
        $ok=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $type=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$effective=curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
        $report['http'][$kind]['status']=$status;$report['http'][$kind]['curl_errno']=$errno;
        if(is_string($type)&&strlen($type)<=256&&preg_match('/\A[\x20-\x7E]+\z/D',$type))
            $report['http'][$kind]['content_type']=$type;
        elseif(is_string($type))$report['http'][$kind]['unaccepted_content_type_sha256']=hash('sha256',$type);
        $report['http'][$kind]['body_complete']=$ok===true&&$errno===0&&!$overflow;
        $report['http'][$kind]['body_limit_exceeded']=$overflow;
        $report['http'][$kind]['body_bytes_seen']=$seen;
        $report['http'][$kind]['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
        identityAttachBodies();
        if($overflow)hotelFail('catalog_identity_http_body_bound');
        if($ok!==true||$errno!==0||$effective!==$url)hotelFail('catalog_identity_http_unconfirmed');
        if($status!==200)hotelFail('catalog_identity_http_status');
        if(!is_string($report['http'][$kind]['content_type'])
            ||!preg_match('~\Aapplication/json(?:\s*;.*)?\z~iD',$report['http'][$kind]['content_type']))
            hotelFail('catalog_identity_http_content_type');
        try{$data=hotelJson($httpBodies[$kind]);}catch(Throwable $ignored){hotelFail('catalog_identity_http_json');}
        return $data;
    }finally{curl_close($ch);identityAttachBodies();}
}

function identityItem(array $item,string $catalog,int $id,?int $countryId=null): array {
    if(($item['catalog']??null)!==$catalog||identityPositive($item['id']??null,true)!==$id)
        hotelFail('catalog_identity_item_unconfirmed');
    $revision=identityPositive($item['revision']??null,true);
    if(!is_string($item['name']??null)||trim($item['name'])===''||strlen($item['name'])>1024
        ||!is_array($item['country']??null)||!is_string($item['country']['name']??null)
        ||trim($item['country']['name'])===''||strlen($item['country']['name'])>256
        ||!is_string($item['address']??null)||trim($item['address'])===''||strlen($item['address'])>2048)
        hotelFail('catalog_identity_item_unconfirmed');
    if($catalog==='local-tv'&&($countryId===null||identityPositive($item['country']['id']??null,true)!==$countryId))
        hotelFail('catalog_identity_country_unconfirmed');
    $out=['id'=>$id,'catalog'=>$catalog,'revision'=>$revision,'name'=>$item['name'],
        'country'=>$item['country'],'address'=>$item['address']];
    if(array_key_exists('coordinates',$item))$out['coordinates']=$item['coordinates'];
    if(strlen(json_encode($out,JSON_THROW_ON_ERROR))>4096)hotelFail('catalog_identity_item_bound');
    return $out;
}

function identityAnyTour(array $data,int $legacy): array {
    if(($data['ok']??null)!==true||($data['source']??null)!=='anytour-canonical-catalog'||($data['catalog']??null)!=='anytour'
        ||($data['requestedLegacyIds']??null)!==[$legacy]||($data['missingLegacyIds']??null)!==[]
        ||!is_array($data['links']??null)||!array_is_list($data['links'])||count($data['links'])!==1||!is_array($data['links'][0])
        ||!is_array($data['items']??null)||!array_is_list($data['items'])||count($data['items'])!==1||!is_array($data['items'][0]))
        hotelFail('catalog_identity_anytour_shape');
    $link=$data['links'][0];
    if(identityPositive($link['legacyHotelId']??null,true)!==$legacy)hotelFail('catalog_identity_anytour_link');
    $native=identityPositive($link['anytourHotelId']??null,true);
    return ['link'=>['legacyHotelId'=>$legacy,'anytourHotelId'=>$native],
        'item'=>identityItem($data['items'][0],'anytour',$native)];
}

function identityLocalTv(array $data,int $native,int $countryId): array {
    if(($data['ok']??null)!==true||($data['source']??null)!=='anytour-local-tv-catalog-v1'||($data['catalog']??null)!=='local-tv'
        ||($data['requestedIds']??null)!==[$native]||($data['missingIds']??null)!==[]
        ||!is_array($data['links']??null)||!array_is_list($data['links'])||count($data['links'])!==1||!is_array($data['links'][0])
        ||!is_array($data['items']??null)||!array_is_list($data['items'])||count($data['items'])!==1||!is_array($data['items'][0]))
        hotelFail('catalog_identity_local_tv_shape');
    $link=$data['links'][0];
    if(identityPositive($link['oldLocalId']??null,true)!==$native)hotelFail('catalog_identity_local_tv_link');
    $tv=identityPositive($link['tourvisorHotelId']??null,true);
    return ['link'=>['oldLocalId'=>$native,'tourvisorHotelId'=>$tv],
        'item'=>identityItem($data['items'][0],'local-tv',$tv,$countryId)];
}

ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$catalogPdo=null;$snapshotOwned=false;$httpBodies=[];$target=false;$outputDone=false;$emergencyMemory=str_repeat('x',2097152);
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'observation_kind'=>'top500_catalog_identity',
    'state'=>'HOLD','phase'=>'target','operator_provider_calls'=>0,'database_writes'=>0,'server_file_writes'=>0,
    'application_database_opened'=>false,'runtime_config_changed'=>false,'cron_changed'=>false,'new_campaign_started'=>false,
    'automatic_retry_allowed'=>false,'native_binding_accepted'=>false,'identity_admission_required'=>true,
    'snapshot_count'=>0,'query_count'=>0,'snapshot'=>['started'=>false,'rolled_back'=>false],
    'candidate'=>['requested_legacy_id'=>(int)HOTEL_TARGET_LEGACY_ID,'maximum_rows'=>1,'returned_rows'=>null,'overflow'=>false,'rows'=>[]],
    'http_attempts'=>0,'http'=>[],'catalogue_response_shapes_verified'=>false,'section_errors'=>[]];

function identityReason(Throwable $e): string {
    $message=$e->getMessage();
    return $e instanceof DomainException&&preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'readback_unconfirmed';
}

function identityFinish(): int {
    global $catalogPdo,$snapshotOwned,$target,$outputDone,$level,$report;
    if($outputDone)return 1;$outputDone=true;
    if($snapshotOwned&&$catalogPdo instanceof PDO&&$catalogPdo->inTransaction()){
        try{$catalogPdo->rollBack();$snapshotOwned=false;$report['snapshot']['rolled_back']=true;}
        catch(Throwable $e){$report['section_errors']['rollback']=identityReason($e);$report['state']='HOLD';}
    }
    identityAttachBodies();$report['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $clean=$report['state']==='observed'&&$report['snapshot_count']===1&&$report['query_count']===2
        &&$report['snapshot']['rolled_back']===true&&$report['http_attempts']===2
        &&$report['catalogue_response_shapes_verified']===true&&$report['section_errors']===[];
    try{$envelope=$target?hotelSeal($report):['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0];}
    catch(Throwable $ignored){$clean=false;$envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'sealed_readback_result_unconfirmed','operator_provider_calls'=>0];}
    while(ob_get_level()>$level)ob_end_clean();
    echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    return $clean&&$envelope['state']==='sealed'?0:1;
}

register_shutdown_function(static function():void{
    global $outputDone,$emergencyMemory,$report;
    if($outputDone)return;$emergencyMemory='';$e=error_get_last();$report['state']='HOLD';
    if($e!==null){$report['reason']='fatal_readback_unconfirmed';$report['fatal']=['type'=>$e['type'],'line'=>$e['line'],
        'message_sha256'=>hash('sha256',(string)$e['message'])];}
    else $report['reason']='interrupted_readback_unconfirmed';
    identityFinish();
});

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        hotelFail('target_unconfirmed');
    $target=true;
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $identity=hotelJson(HOTEL_OBSERVATION_IDENTITY_JSON);
    if(hash('sha256',hotelCanonical($identity))!==HOTEL_OPERATION)hotelFail('observation_identity_unconfirmed');
    hotelHash(HOTEL_EXPECTED_CATALOG_DB);
    $report['identity']=$identity;$report['github_run_id']=$run;$report['started_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $report['phase']='installed_source';identitySources($app);$report['installed_sha']=HOTEL_EXPECTED_INSTALLED_SHA;
    hotelSeal(['state'=>'crypto_preflight','operator_provider_calls'=>0]);
    require_once $app.'/config.php';require_once $app.'/services/HotelDatabase.php';
    if(!HotelDatabase::configured())hotelFail('catalog_not_configured');
    $report['phase']='candidate_metadata';identitySnapshot(HotelDatabase::connection());
    if($report['candidate']['overflow'])hotelFail('catalog_identity_candidate_bound');
    if($report['candidate']['returned_rows']===0)hotelFail('catalog_identity_candidate_missing');
    if($report['candidate']['returned_rows']!==1)hotelFail('catalog_identity_candidate_ambiguous');
    $legacy=$report['candidate']['rows'][0]['id'];$country=$report['candidate']['rows'][0]['country_id'];
    $report['phase']='anytour_read';$anytour=identityAnyTour(identityGet('anytour',$legacy),$legacy);
    $report['http']['anytour']['validated']=$anytour;$report['http']['anytour']['shape_verified']=true;
    $native=$anytour['link']['anytourHotelId'];
    $report['phase']='local_tv_read';$local=identityLocalTv(identityGet('local_tv',$native),$native,$country);
    $report['http']['local_tv']['validated']=$local;$report['http']['local_tv']['shape_verified']=true;
    $tv=$local['link']['tourvisorHotelId'];
    $report['typed_chain']=[['namespace'=>'legacy_catalog/catalog_hotels.id','id'=>$legacy],
        ['namespace'=>'anytour','id'=>$native,'catalog_revision'=>$anytour['item']['revision']],
        ['namespace'=>'local-tv','id'=>$tv,'catalog_revision'=>$local['item']['revision']]];
    $report['local_tv_id_matches_observed_legacy_candidate']=$tv===$legacy;
    if($tv!==$legacy)hotelFail('catalog_identity_tv_source_mismatch');
    $report['catalogue_response_shapes_verified']=true;
    $report['phase']='installed_source_final';identitySources($app);
    $report['state']='observed';$report['phase']='finished';
}catch(Throwable $e){$report['state']='HOLD';$report['reason']=identityReason($e);$report['exception_phase']=$report['phase'];}
exit(identityFinish());
