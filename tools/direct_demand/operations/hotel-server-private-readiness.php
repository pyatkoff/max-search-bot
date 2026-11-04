<?php
declare(strict_types=1);
/* One explicit existing HotelDatabase projection migration, under the existing private channel.
 * Canonical 285-byte CREATE TABLE IF NOT EXISTS only. No provider, app-data write,
 * outbox ACK, runtime/cron change, ALTER, DROP, grant or automatic retry.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='5f959c91ced2c19c74f3aaabee986963d79942a4';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_STATUS_KIND='hotel-server-private-correction-v1';
const HOTEL_OPERATION='e18b91adecaf204373ee8604b8e201923bcc6ded9ca64a5a421c7e1571591dbb';
const HOTEL_CATALOG_TABLE='hotel_server_catalog_documents';
const HOTEL_CATALOG_IDENTITY_SHA='a0eeb9b531321e9854c472e91a4ff4b7191533b8037c707fadc5251d964b1491';
const HOTEL_PROBE_APPLICATION_IDENTITY_SHA='ea32f9490874a6b4429a7bec1e3c562717b97f009e84df2c7f8ee8f14d0b3653';
const HOTEL_MIGRATION_SHA='6f325bdd854fae375f39032c65d9c9eac89192c6bed008da6629b876ea035cec';
const HOTEL_SOURCE_PINS_JSON='{"migrations/hotel/084_hotel_server_projection.sql":"6f325bdd854fae375f39032c65d9c9eac89192c6bed008da6629b876ea035cec","services/ConversationDb.php":"8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1","services/HotelDatabase.php":"8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5"}';
const HOTEL_SETUP_IDENTITY_JSON='{"application_database_writes":0,"automatic_retry_allowed":false,"catalog_database_identity_sha256":"a0eeb9b531321e9854c472e91a4ff4b7191533b8037c707fadc5251d964b1491","catalog_document_writes":0,"catalog_outbox_acks":0,"expected_ops_parent":"93b47285c645e1179f49be74408733c2125f8790","installed_sha":"5f959c91ced2c19c74f3aaabee986963d79942a4","maximum_ddl_statements":1,"migration_bytes":285,"migration_path":"migrations/hotel/084_hotel_server_projection.sql","migration_sha256":"6f325bdd854fae375f39032c65d9c9eac89192c6bed008da6629b876ea035cec","mutation_kind":"catalog_projection_schema_setup","original_probe_operation_id":"a0c4c35e2ea61442583cb7b4049a53e0718d6461a077d9b31bb50db0e51bb0cc","original_probe_plaintext_sha256":"8423600d62dbadfcfd3636cac2e6332cb39ecc55a0b23a5cca84f196983e3a65","original_probe_run_id":"41847768588","provider_calls":0,"publication_admission_ref":"https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-6608084807","schema":"hotel-server-private-correction-v1","schema_change":"canonical CREATE TABLE IF NOT EXISTS only","source_pins":{"migrations/hotel/084_hotel_server_projection.sql":"6f325bdd854fae375f39032c65d9c9eac89192c6bed008da6629b876ea035cec","services/ConversationDb.php":"8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1","services/HotelDatabase.php":"8bafffffb952007d19e075cd7bbc976b70eba296d6334446bdd4840fdb787ac5"},"source_pr":1329,"source_ref":"https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-6607905718"}';
const HOTEL_PLAIN_LIMIT=524288;
const HOTEL_WIRE_LIMIT=786432;

function hotelFail(string $reason): never { throw new DomainException($reason); }

function hotelDir(string $path): void {
    clearstatcache(true,$path);
    if(is_link($path)||realpath($path)!==$path||!is_dir($path)||(fileperms($path)&0777)!==0700
        ||fileowner($path)!==posix_geteuid())hotelFail('private_directory_unconfirmed');
}

function hotelRead(string $path,int $limit,?int $size=null,?string $sha=null): string {
    clearstatcache(true,$path);$before=@lstat($path);
    if(!is_array($before)||is_link($path)||!is_file($path)||($before['mode']&0777)!==0600
        ||$before['uid']!==posix_geteuid()||$before['nlink']!==1||$before['size']<0
        ||($before['size']===0&&$size!==0)||$before['size']>$limit
        ||($size!==null&&$before['size']!==$size))hotelFail('private_file_unconfirmed');
    $h=@fopen($path,'rb');if(!$h)hotelFail('private_file_unconfirmed');
    try{
        $opened=fstat($h);
        if(!is_array($opened)||$opened['dev']!==$before['dev']||$opened['ino']!==$before['ino'])
            hotelFail('private_file_changed');
        $raw=stream_get_contents($h,$limit+1);$lastOpen=fstat($h);
    }finally{fclose($h);}
    clearstatcache(true,$path);$after=@lstat($path);
    if(!is_string($raw)||strlen($raw)!==$before['size']||!is_array($after)||!is_array($lastOpen))
        hotelFail('private_file_changed');
    foreach(['dev','ino','mode','uid','nlink','size','mtime','ctime'] as $key)
        if($before[$key]!==$after[$key]||$before[$key]!==$lastOpen[$key])hotelFail('private_file_changed');
    if(($sha!==null&&!hash_equals($sha,hash('sha256',$raw))))hotelFail('private_file_changed');
    return $raw;
}

function hotelJson(string $raw): array {
    $v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($v))hotelFail('private_shape_unconfirmed');return $v;
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

function hotelWriteNew(string $path,string $raw): void {
    if(file_exists($path)||is_link($path))hotelFail('prior_operation_requires_readback');
    $h=@fopen($path,'x');if(!$h)hotelFail('private_write_unconfirmed');
    try{
        if(!chmod($path,0600))hotelFail('private_write_unconfirmed');$offset=0;$length=strlen($raw);
        while($offset<$length){$n=fwrite($h,substr($raw,$offset,65536));if(!is_int($n)||$n<1)hotelFail('private_write_unconfirmed');$offset+=$n;}
        if(!fflush($h)||(function_exists('fsync')&&!fsync($h)))hotelFail('private_write_unconfirmed');
    }finally{fclose($h);}
    hotelRead($path,max(1,strlen($raw)),strlen($raw),hash('sha256',$raw));
}

function applyReason(Throwable $e): string {
    $message=$e->getMessage();
    return $e instanceof DomainException&&preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'apply_outcome_unconfirmed';
}

function applyCheckpoint(string $name,array $payload): string {
    global $work;
    $raw=json_encode(hotelSeal($payload),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);hotelWriteNew($work.'/'.$name,$raw);return hash('sha256',$raw);
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

function setupMetadata(PDO $pdo): array {
    $identity=probeDatabaseIdentity($pdo);
    if($identity!==HOTEL_CATALOG_IDENTITY_SHA)hotelFail('catalog_database_identity_changed');
    $out=['configured'=>true,'connection_opened'=>true,'application_pdo_opened'=>false,'same_catalog_identity_as_accepted_probe'=>true,'database_identity_sha256'=>$identity,
        'differs_from_probe_application_identity'=>$identity!==HOTEL_PROBE_APPLICATION_IDENTITY_SHA,'table_exists'=>false,
        'schema_matches_expected'=>false,'row_count'=>null];
    if(!$out['differs_from_probe_application_identity'])return $out+['reason'=>'catalog_database_not_separate'];
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
    $rows=probeRows($pdo,'SELECT /*+ MAX_EXECUTION_TIME(2500) */ COUNT(*) AS rows_count FROM hotel_server_catalog_documents',[],1);
    if(count($rows)!==1)hotelFail('catalog_row_count_unconfirmed');
    $out['row_count']=hotelInt($rows[0]['rows_count']);
    return $out;
}

function setupSources(string $app): string {
    hotelInstalled($app);$migration=null;
    foreach(hotelJson(HOTEL_SOURCE_PINS_JSON) as $path=>$sha){
        $file=$app.'/'.$path;clearstatcache(true,$file);
        if(is_link($file)||realpath($file)!==$file||!is_file($file)||filesize($file)>131072)hotelFail('canonical_source_unconfirmed');
        $raw=file_get_contents($file,false,null,0,131073);
        if(!is_string($raw)||strlen($raw)>131072||hash('sha256',$raw)!==$sha)hotelFail('canonical_source_unconfirmed');
        if($path==='migrations/hotel/084_hotel_server_projection.sql')$migration=$raw;
    }
    if(!is_string($migration)||strlen($migration)!==285||hash('sha256',$migration)!==HOTEL_MIGRATION_SHA)hotelFail('canonical_migration_unconfirmed');
    return $migration;
}

function setupSnapshot(PDO $pdo,string $name): array {
    probeBegin($pdo,$name);
    try{return setupMetadata($pdo);}finally{probeEnd($pdo,$name);}
}

ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$pdo=null;$connections=[];$lock=null;$target=false;$outputDone=false;$ownedWork=false;$work=null;$mask=null;
$emergencyMemory=str_repeat('x',2097152);
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'mutation_kind'=>'catalog_projection_schema_setup',
    'state'=>'HOLD','phase'=>'target','operator_provider_calls'=>0,'application_database_opened'=>false,
    'application_data_writes'=>0,'catalog_document_writes'=>0,'catalog_outbox_acks'=>0,
    'runtime_config_changed'=>false,'cron_changed'=>false,'new_campaign_started'=>false,
    'ddl_attempted'=>false,'ddl_statements_attempted'=>0,'ddl_statement_returned'=>false,'schema_ensured'=>false,
    'exclusive_table_creator_claimed'=>false,'private_guard_created'=>false,'automatic_retry_allowed'=>false,
    'mutation_outcome'=>'not_started','snapshot_count'=>0,'query_count'=>0,'snapshots'=>[],'section_errors'=>[]];

function setupFinish(): int {
    global $pdo,$lock,$target,$outputDone,$ownedWork,$work,$mask,$level,$report;
    if($outputDone)return 1;$outputDone=true;
    if($pdo instanceof PDO&&$pdo->inTransaction()){
        try{$pdo->rollBack();}catch(Throwable $e){$report['section_errors']['rollback']=applyReason($e);}
    }
    $clean=$report['schema_ensured']&&!isset($report['reason'])&&$report['section_errors']===[];
    if($clean)$report['state']=$report['ddl_attempted']?'canonical_schema_ensured':'existing_compatible_schema_noop';
    elseif($report['schema_ensured'])$report['state']='SCHEMA_ENSURED_HOLD';
    elseif($report['ddl_attempted'])$report['state']='DDL_OUTCOME_UNCONFIRMED';
    else $report['state']='HOLD';
    $report['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');$report['resume_or_replay_authorized']=false;
    try{
        $envelope=!$target?['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0]:hotelSeal($report);
        if($target&&$ownedWork){
            try{hotelWriteNew($work.'/result.sealed.json',json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
            catch(Throwable $e){$clean=false;$report['section_errors']['result_persistence']=applyReason($e);$report['state']=$report['schema_ensured']?'SCHEMA_ENSURED_HOLD':($report['ddl_attempted']?'DDL_OUTCOME_UNCONFIRMED':'HOLD');$envelope=hotelSeal($report);}
        }
    }catch(Throwable $ignored){$clean=false;$envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'sealed_apply_result_unconfirmed','operator_provider_calls'=>0];}
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);$lock=null;}
    if(is_int($mask))umask($mask);while(ob_get_level()>$level)ob_end_clean();
    echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    return $clean&&$envelope['state']==='sealed'?0:1;
}

register_shutdown_function(static function():void{
    global $outputDone,$emergencyMemory,$report;
    if($outputDone)return;$emergencyMemory='';$e=error_get_last();
    if($e!==null){$report['reason']='fatal_apply_unconfirmed';$report['fatal']=['type'=>$e['type'],'line'=>$e['line'],
        'message_sha256'=>hash('sha256',(string)$e['message'])];}
    else $report['reason']='interrupted_apply_unconfirmed';
    setupFinish();
});

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)hotelFail('target_unconfirmed');
    $target=true;
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $identity=hotelJson(HOTEL_SETUP_IDENTITY_JSON);
    if(hash('sha256',hotelCanonical($identity))!==HOTEL_OPERATION)hotelFail('setup_operation_identity_unconfirmed');
    $report['identity']=$identity;$report['github_run_id']=$run;$report['started_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $migration=setupSources($app);$report['installed_sha']=HOTEL_EXPECTED_INSTALLED_SHA;
    hotelSeal(['state'=>'crypto_preflight','operator_provider_calls'=>0]);
    $root=dirname($app).'/max-search-bot-hotel-server';hotelDir($root);hotelDir($root.'/ingress-v1');$mask=umask(0077);
    // Fixed logical migration guard: changing operation/release IDs cannot bypass an unknown prior DDL.
    $work=$root.'/catalog-projection-schema-084-v1';
    $lockPath=$root.'/ingress-v1/channel.lock';hotelRead($lockPath,1,0);$lock=@fopen($lockPath,'rb');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))hotelFail('active_private_operation');
    $opened=fstat($lock);clearstatcache(true,$lockPath);$actual=lstat($lockPath);
    if(!is_array($actual)||$opened['dev']!==$actual['dev']||$opened['ino']!==$actual['ino'])hotelFail('private_admin_lock_changed');
    if(file_exists($work)||is_link($work))hotelFail('prior_operation_requires_readback');
    require_once $app.'/config.php';require_once $app.'/services/HotelDatabase.php';
    if(!HotelDatabase::configured())hotelFail('catalog_not_configured');$pdo=HotelDatabase::connection();
    $report['phase']='catalog_before';$report['before']=setupSnapshot($pdo,'before');
    if($report['before']['table_exists']&&!$report['before']['schema_matches_expected'])hotelFail('existing_catalog_schema_incompatible');
    if(file_exists($work)||is_link($work)||!mkdir($work,0700))hotelFail('prior_operation_requires_readback');
    $ownedWork=true;hotelDir($work);$report['private_guard_created']=true;
    $intent=hotelCanonical($identity+['operation_id'=>HOTEL_OPERATION,'github_run_id'=>$run,'started_at_utc'=>$report['started_at_utc'],
        'before_sha256'=>hash('sha256',hotelCanonical($report['before'])),'automatic_retry_allowed'=>false]);
    hotelWriteNew($work.'/intent.json',$intent);$report['intent_sha256']=hash('sha256',$intent);
    $report['phase']='preflight_verified';$report['preflight_sealed_sha256']=applyCheckpoint('preflight.sealed.json',$report);
    if($report['before']['table_exists']){
        // Observation-only success; preserve every existing row and do not execute DDL.
        $report['after']=$report['before'];$report['after_reuses_noop_observation']=true;
        $report['schema_ensured']=true;$report['mutation_outcome']='existing_compatible_schema_observed_noop';
    }else{
        $migration=setupSources($app);hotelRead($work.'/intent.json',65536,null,$report['intent_sha256']);
        if($pdo->inTransaction())hotelFail('foreign_catalog_transaction');
        $ddlIntent=hotelCanonical(['operation_id'=>HOTEL_OPERATION,'github_run_id'=>$run,'phase'=>'one_canonical_statement_starting',
            'catalog_identity_sha256'=>HOTEL_CATALOG_IDENTITY_SHA,'migration_sha256'=>HOTEL_MIGRATION_SHA,'migration_bytes'=>285,
            'maximum_statements'=>1,'before_table_exists'=>false,'automatic_retry_allowed'=>false]);
        hotelWriteNew($work.'/ddl.intent.json',$ddlIntent);
        $report['phase']='canonical_ddl';$report['ddl_attempted']=true;$report['ddl_statements_attempted']=1;$report['mutation_outcome']='unconfirmed_no_retry';
        $result=$pdo->exec($migration);
        if(!is_int($result))hotelFail('canonical_ddl_result_unconfirmed');
        $report['ddl_statement_returned']=true;$report['ddl_return_value']=$result;$report['ddl_returned_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
        $report['mutation_outcome']='canonical_statement_returned';$report['phase']='canonical_ddl_returned';
        // Persist the returned DDL outcome before any subsequent metadata read can fail.
        $report['ddl_result_sealed_sha256']=applyCheckpoint('ddl-result.sealed.json',$report);
        $report['phase']='catalog_readback';$report['after']=setupSnapshot($pdo,'after');
        if(!$report['after']['table_exists']||!$report['after']['schema_matches_expected']||$report['after']['row_count']!==0)
            hotelFail('empty_canonical_catalog_readback_unconfirmed');
        $report['schema_ensured']=true;$report['mutation_outcome']='canonical_empty_schema_readback_confirmed';
    }
    $report['phase']='schema_ensured';$report['schema_result_sealed_sha256']=applyCheckpoint('schema-result.sealed.json',$report);
    $report['phase']='final_source_readback';setupSources($app);$report['phase']='finished';
}catch(Throwable $e){
    $report['reason']=applyReason($e);$report['error_class']=get_class($e);$report['error_message_sha256']=hash('sha256',$e->getMessage());
    $report['exception_phase']=$report['phase'];
    if($e instanceof PDOException&&is_array($e->errorInfo)){
        if(is_string($e->errorInfo[0]??null)&&preg_match('/\A[A-Z0-9]{5}\z/D',$e->errorInfo[0]))$report['sqlstate']=$e->errorInfo[0];
        if(is_int($e->errorInfo[1]??null))$report['database_error_code']=$e->errorInfo[1];
    }
}
exit(setupFinish());
