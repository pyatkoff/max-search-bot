<?php
declare(strict_types=1);
/* One metadata-only prerequisite read through the existing private incident channel.
 * No provider, campaign tick, record/history replay, server file write or DDL.
 * Application and catalog snapshots are separate and explicitly READ ONLY.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='5f959c91ced2c19c74f3aaabee986963d79942a4';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_STATUS_KIND='hotel-server-private-incident-v1';
const HOTEL_OPERATION='a0c4c35e2ea61442583cb7b4049a53e0718d6461a077d9b31bb50db0e51bb0cc';
const HOTEL_CATALOG_TABLE='hotel_server_catalog_documents';
const HOTEL_SOURCE_PINS_JSON='{"migrations/hotel/084_hotel_server_projection.sql":"6f325bdd854fae375f39032c65d9c9eac89192c6bed008da6629b876ea035cec","services/ConversationDb.php":"8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1","services/HotelDatabase.php":"8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5"}';
const HOTEL_OBSERVATION_IDENTITY_JSON='{"database_writes":0,"expected_ops_parent":"e8053211449a6b44494b03f8c8f5cf8f7a61f862","installed_sha":"5f959c91ced2c19c74f3aaabee986963d79942a4","maximum_metadata_queries":12,"maximum_pending_receipts":100,"maximum_read_only_snapshots":2,"observation_kind":"accepted_catalog_prerequisites","original_installed_deploy_run_id":"41845980299","original_predecessor_run_id":"41839043713","provider_calls":0,"schema":"hotel-server-private-incident-v1","server_file_writes":0,"source_pins":{"migrations/hotel/084_hotel_server_projection.sql":"6f325bdd854fae375f39032c65d9c9eac89192c6bed008da6629b876ea035cec","services/ConversationDb.php":"8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1","services/HotelDatabase.php":"8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5"},"source_ref":"https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-6607905718"}';
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

function probeSources(string $app): void {
    hotelInstalled($app);
    foreach(hotelJson(HOTEL_SOURCE_PINS_JSON) as $path=>$sha){
        $file=$app.'/'.$path;clearstatcache(true,$file);
        if(is_link($file)||realpath($file)!==$file||!is_file($file)||filesize($file)>131072)hotelFail('canonical_source_unconfirmed');
        $raw=file_get_contents($file,false,null,0,131073);
        if(!is_string($raw)||strlen($raw)>131072||hash('sha256',$raw)!==$sha)hotelFail('canonical_source_unconfirmed');
    }
}

function probeRows(PDO $pdo,string $sql,array $args,int $cap): array {
    global $report;
    if((!str_starts_with($sql,'SELECT ')&&$sql!=='SHOW CREATE TABLE hotel_server_catalog_documents')
        ||str_contains($sql,'FOR UPDATE')||$cap<1||$cap>101)hotelFail('catalog_read_scope_unconfirmed');
    if(++$report['query_count']>12)hotelFail('catalog_query_bound');
    $s=$pdo->prepare($sql);$s->execute($args);$rows=[];
    try{while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){
        if(count($rows)>=$cap||strlen(json_encode($row,JSON_THROW_ON_ERROR))>32768)hotelFail('catalog_read_bound');
        $rows[]=$row;
    }}finally{$s->closeCursor();}return $rows;
}

function probeBegin(PDO $pdo,string $name): void {
    global $connections,$report;
    if(isset($connections[$name])||count($connections)>=2||$pdo->inTransaction()
        ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
        ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))hotelFail('catalog_database_context_unconfirmed');
    $connections[$name]=$pdo;
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $report['snapshots'][$name]=['started'=>true,'rolled_back'=>false,'started_at_utc'=>gmdate('Y-m-d\TH:i:s\Z')];
    $report['snapshot_count']++;
}

function probeEnd(PDO $pdo,string $name): void {
    global $report;
    if(!$pdo->inTransaction())hotelFail('catalog_read_only_snapshot_lost');
    $pdo->rollBack();$report['snapshots'][$name]['rolled_back']=true;
}

function probeDatabaseIdentity(PDO $pdo): string {
    $rows=probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ DATABASE() AS db,@@hostname AS host,@@port AS port',[],1);
    if(count($rows)!==1||!is_string($rows[0]['db'])||$rows[0]['db']===''
        ||!is_string($rows[0]['host'])||$rows[0]['host']==='')hotelFail('catalog_database_identity_unconfirmed');
    // A digest allows comparison without exposing database or host configuration.
    return hash('sha256',hotelCanonical(['database'=>$rows[0]['db'],'host'=>$rows[0]['host'],'port'=>hotelInt($rows[0]['port'],65535)]));
}

function probeApplication(PDO $pdo): array {
    $identity=probeDatabaseIdentity($pdo);
    $lanes=probeRows($pdo,"SELECT /*+ MAX_EXECUTION_TIME(2500) */ queue_id FROM hotel_work_lane WHERE lane_key='HOTEL' LIMIT 2",[],2);
    if(count($lanes)!==1)hotelFail('catalog_lane_unconfirmed');
    $owner=$lanes[0]['queue_id'];
    if($owner!==null&&(!is_string($owner)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D',$owner)))hotelFail('catalog_lane_unconfirmed');
    $counts=probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ campaign_id,state,COUNT(*) AS rows_count FROM hotel_server_catalog_outbox GROUP BY campaign_id,state ORDER BY campaign_id,state LIMIT 101',[],101);
    $countComplete=count($counts)<=100;$counts=array_slice($counts,0,100);$pending=0;$done=0;$other=0;
    foreach($counts as &$row){
        $row['rows_count']=hotelInt($row['rows_count'],1000000);
        if($row['state']==='pending')$pending+=$row['rows_count'];elseif($row['state']==='done')$done+=$row['rows_count'];else $other+=$row['rows_count'];
    }unset($row);
    $rows=probeRows($pdo,"SELECT /*+ MAX_EXECUTION_TIME(2500) */ o.campaign_id,o.receipt_sha256,o.state,
        OCTET_LENGTH(o.payload_bytes) AS payload_bytes,
        CASE WHEN OCTET_LENGTH(o.payload_bytes)<=1048576 THEN SHA2(o.payload_bytes,256) ELSE NULL END AS payload_sha256,
        r.stage_index,CASE WHEN OCTET_LENGTH(r.receipt_bytes)<=65536 THEN SHA2(r.receipt_bytes,256) ELSE NULL END AS receipt_actual_sha256,
        c.state AS campaign_state,c.enabled AS campaign_enabled
        FROM hotel_server_catalog_outbox o
        LEFT JOIN hotel_server_receipts r ON r.campaign_id=o.campaign_id AND r.receipt_sha256=o.receipt_sha256
        LEFT JOIN hotel_server_campaigns c ON c.campaign_id=o.campaign_id
        WHERE o.state='pending' ORDER BY o.campaign_id,r.stage_index,o.receipt_sha256 LIMIT 101",[],101);
    $complete=count($rows)<=100;$rows=array_slice($rows,0,100);$eligible=0;
    foreach($rows as &$row){
        if(!is_string($row['campaign_id'])||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D',$row['campaign_id']))hotelFail('catalog_campaign_identity_unconfirmed');
        hotelHash($row['receipt_sha256']);
        $row['payload_bytes']=hotelInt($row['payload_bytes']);
        if($row['stage_index']!==null)$row['stage_index']=hotelInt($row['stage_index'],9);
        if($row['campaign_enabled']!==null)$row['campaign_enabled']=hotelInt($row['campaign_enabled'],1);
        $row['receipt_hash_matches']=$row['receipt_sha256']===$row['receipt_actual_sha256'];
        $row['lane_admitted']=$owner===$row['campaign_id']||($row['campaign_state']==='complete'&&$row['campaign_enabled']===0);
        $row['eligible_by_metadata']=$row['lane_admitted']&&$row['receipt_hash_matches']&&$row['stage_index']!==null
            &&is_string($row['payload_sha256'])&&preg_match('/\A[a-f0-9]{64}\z/D',$row['payload_sha256'])===1;
        if($row['eligible_by_metadata'])$eligible++;
    }unset($row);
    return ['database_identity_sha256'=>$identity,'lane_owner'=>$owner,'counts_by_campaign_state'=>$counts,'counts_complete'=>$countComplete,
        'pending_total'=>$countComplete?$pending:null,'done_total'=>$countComplete?$done:null,'other_state_total'=>$countComplete?$other:null,
        'pending_receipts'=>$rows,'pending_receipts_complete'=>$complete,'pending_metadata_sha256'=>hash('sha256',hotelCanonical($rows)),
        'eligible_pending_in_snapshot'=>$eligible,'eligibility_is_metadata_only'=>true,'canonical_projection_validation_still_required'=>true,
        'raw_records_returned'=>false,'provider_attempts_queried'=>false];
}

function probeHotel(PDO $pdo,array $app): array {
    $identity=probeDatabaseIdentity($pdo);
    $out=['configured'=>true,'connection_opened'=>true,'separate_pdo'=>true,'database_identity_sha256'=>$identity,
        'observed_database_identity_differs'=>$identity!==$app['database_identity_sha256'],'table_exists'=>false,
        'schema_matches_expected'=>false,'documents_read'=>false,'documents'=>[]];
    if(!$out['observed_database_identity_differs'])return $out+['reason'=>'catalog_database_not_separate'];
    $tables=probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 2',[HOTEL_CATALOG_TABLE],2);
    if(count($tables)>1)hotelFail('catalog_table_identity_unconfirmed');
    if(!$tables)return $out+['reason'=>'catalog_table_absent'];
    $out['table_exists']=true;$out['table']=$tables[0];
    if($tables[0]['TABLE_TYPE']!=='BASE TABLE')return $out+['reason'=>'catalog_table_not_base_table'];
    $columns=probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION LIMIT 17',[HOTEL_CATALOG_TABLE],17);
    $indexes=probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX LIMIT 17',[HOTEL_CATALOG_TABLE],17);
    $triggers=probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME LIMIT 17',[HOTEL_CATALOG_TABLE],17);
    $constraints=probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY CONSTRAINT_NAME LIMIT 17',[HOTEL_CATALOG_TABLE],17);
    $create=probeRows($pdo,'SHOW CREATE TABLE hotel_server_catalog_documents',[],1);
    if(count($create)!==1||!is_string($create[0]['Create Table']??null)||strlen($create[0]['Create Table'])>16384)hotelFail('catalog_create_metadata_unconfirmed');
    $out['columns']=$columns;$out['indexes']=$indexes;$out['triggers']=$triggers;$out['constraints']=$constraints;
    $out['show_create_table']=$create[0]['Create Table'];$out['show_create_sha256']=hash('sha256',$out['show_create_table']);
    $expected=[['receipt_sha256','char(64)','PRI'],['payload_sha256','char(64)',''],['document_bytes','longblob','']];
    $match=count($columns)===3&&count($indexes)===1&&$triggers===[]&&strtolower((string)$tables[0]['ENGINE'])==='innodb'
        &&count($constraints)===1&&($constraints[0]['CONSTRAINT_NAME']??null)==='PRIMARY'&&($constraints[0]['CONSTRAINT_TYPE']??null)==='PRIMARY KEY';
    foreach($expected as $i=>[$name,$type,$key]){
        $c=$columns[$i]??[];
        $match=$match&&($c['COLUMN_NAME']??null)===$name&&($c['ORDINAL_POSITION']??null)==($i+1)
            &&strtolower((string)($c['COLUMN_TYPE']??''))===$type&&($c['IS_NULLABLE']??null)==='NO'
            &&array_key_exists('COLUMN_DEFAULT',$c)&&$c['COLUMN_DEFAULT']===null&&($c['COLUMN_KEY']??null)===$key&&($c['EXTRA']??null)==='';
    }
    $index=$indexes[0]??[];
    $match=$match&&($index['INDEX_NAME']??null)==='PRIMARY'&&($index['SEQ_IN_INDEX']??null)==1
        &&($index['COLUMN_NAME']??null)==='receipt_sha256'&&($index['NON_UNIQUE']??null)==0
        &&array_key_exists('SUB_PART',$index)&&$index['SUB_PART']===null&&($index['INDEX_TYPE']??null)==='BTREE';
    $out['schema_matches_expected']=$match;
    if(!$match)return $out+['reason'=>'catalog_schema_mismatch'];
    $receipts=array_column($app['pending_receipts'],'receipt_sha256');$expectedPayload=[];
    foreach($app['pending_receipts'] as $row)$expectedPayload[$row['receipt_sha256']]=$row['payload_sha256'];
    $docs=$receipts===[]?[]:probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ receipt_sha256,payload_sha256,OCTET_LENGTH(document_bytes) AS document_bytes,
        CASE WHEN OCTET_LENGTH(document_bytes)<=1048576 THEN SHA2(document_bytes,256) ELSE NULL END AS document_actual_sha256
        FROM hotel_server_catalog_documents WHERE receipt_sha256 IN ('.implode(',',array_fill(0,count($receipts),'?')).') ORDER BY receipt_sha256 LIMIT 101',$receipts,101);
    foreach($docs as &$d){
        hotelHash($d['receipt_sha256']);$d['document_bytes']=hotelInt($d['document_bytes']);
        $d['matches_pending_payload']=$d['payload_sha256']===($expectedPayload[$d['receipt_sha256']]??null)
            &&is_string($d['document_actual_sha256'])&&$d['document_actual_sha256']===$d['payload_sha256'];
    }unset($d);
    $out['documents']=$docs;$out['documents_read']=true;$out['requested_receipt_count']=count($receipts);
    $out['matching_document_count']=count(array_filter($docs,static fn(array $d):bool=>$d['matches_pending_payload']));
    $out['conflicting_document_count']=count($docs)-$out['matching_document_count'];
    $out['documents_metadata_sha256']=hash('sha256',hotelCanonical($docs));
    return $out;
}

ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$connections=[];$target=false;$outputDone=false;$emergencyMemory=str_repeat('x',2097152);
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'state'=>'HOLD','phase'=>'target',
    'operator_provider_calls'=>0,'database_writes'=>0,'server_file_writes'=>0,'runtime_config_changed'=>false,'cron_changed'=>false,
    'new_campaign_started'=>false,'automatic_retry_allowed'=>false,'snapshot_count'=>0,'query_count'=>0,'snapshots'=>[],
    'snapshots_atomic_across_databases'=>false,'section_errors'=>[]];

function observationReason(Throwable $e): string {
    $message=$e->getMessage();
    return $e instanceof DomainException&&preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'readback_unconfirmed';
}

function observationFinish(): int {
    global $connections,$target,$outputDone,$level,$report;
    if($outputDone)return 1;$outputDone=true;
    foreach($connections as $name=>$pdo)if($pdo->inTransaction()){
        try{$pdo->rollBack();$report['snapshots'][$name]['rolled_back']=true;}
        catch(Throwable $e){$report['section_errors'][$name.'_rollback']=observationReason($e);$report['state']='HOLD';}
    }
    $report['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $clean=$report['state']==='observed'&&$report['snapshot_count']>=1&&$report['snapshot_count']<=2&&$report['section_errors']===[];
    foreach($report['snapshots'] as $snapshot)$clean=$clean&&($snapshot['rolled_back']??false)===true;
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
    observationFinish();
});

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        hotelFail('target_unconfirmed');
    $target=true;
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $identity=hotelJson(HOTEL_OBSERVATION_IDENTITY_JSON);
    if(hash('sha256',hotelCanonical($identity))!==HOTEL_OPERATION)hotelFail('observation_identity_unconfirmed');
    $report['identity']=$identity;$report['github_run_id']=$run;$report['started_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $report['phase']='installed_source';probeSources($app);$report['installed_sha']=HOTEL_EXPECTED_INSTALLED_SHA;
    hotelSeal(['state'=>'crypto_preflight','operator_provider_calls'=>0]);
    require_once $app.'/config.php';require_once $app.'/services/ConversationDb.php';require_once $app.'/services/HotelDatabase.php';
    $appPdo=ConversationDb::connection();
    $report['phase']='application_metadata';probeBegin($appPdo,'application');
    try{$report['application']=probeApplication($appPdo);}finally{probeEnd($appPdo,'application');}
    $report['catalog']=['configured'=>HotelDatabase::configured(),'connection_opened'=>false,'table_exists'=>null,'schema_matches_expected'=>null];
    if($report['catalog']['configured']){
        $report['phase']='catalog_metadata';$hotelPdo=HotelDatabase::connection();
        if($hotelPdo===$appPdo)hotelFail('catalog_PDO_not_separate');
        probeBegin($hotelPdo,'catalog');
        try{$report['catalog']=probeHotel($hotelPdo,$report['application']);}finally{probeEnd($hotelPdo,'catalog');}
    }else $report['catalog']['reason']='catalog_not_configured';
    $report['phase']='installed_source_final';probeSources($app);
    $report['catalog_prerequisites_ready']=($report['catalog']['configured']??false)===true
        &&($report['catalog']['observed_database_identity_differs']??false)===true
        &&($report['catalog']['schema_matches_expected']??false)===true
        &&($report['catalog']['conflicting_document_count']??1)===0
        &&$report['application']['counts_complete']&&$report['application']['pending_receipts_complete']
        &&$report['application']['other_state_total']===0;
    $report['state']='observed';$report['phase']='finished';
}catch(Throwable $e){$report['state']='HOLD';$report['reason']=observationReason($e);}
exit(observationFinish());
