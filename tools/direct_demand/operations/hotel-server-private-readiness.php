<?php
declare(strict_types=1);
/* One B07 metadata snapshot after authenticated APPLY run 41835798037.
 * Existing private channel; no server files, locks, provider, tick, or cron writes.
 * Admission expiry does not restrict observation of saved history.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='a45bb6c6d78576dba3854c683279d5126d67540e';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_CORRECTION_SHA='16783bcd14edc7d446ab40b6fdbf77d84857aa84a3a094b1153c043c808ae576';
const HOTEL_ADMISSION_SHA='ca01d41e5728645525ec0fe1ce9e06391658be9561b7faca7aa41660ca62749c';
const HOTEL_CAMPAIGN='HOTEL-SERVER-20261103-B07';
const HOTEL_BOOTSTRAP_SHA='4577961cf5ab96a62df821cfc4f1d9a80b373ae07da10e3f143d7b22adeac6ed';
const HOTEL_INPUTS_SHA='b0f093ff27ad6de311fb6eb351f194ba1b413483d4a7a336ae9aa13986f69448';
const HOTEL_BASELINE_SHA='d0f83be35829d178473ecb4b0d5f4397786256f4a7df5c1bf691085f7d096bec';
const HOTEL_PLAN_SHA='714315a5588f8eef7f4932ed06abf7d25107bd2d58b2799ac5a198c87d536134';
const HOTEL_CANDIDATE_SHA='44596fb4cc60d4ea40635b3b44ce8f2f3cd54d0152d20827d8d8bec7361c7034';
const HOTEL_CORRECTED_CANDIDATE_SHA='c8e35bf980524fcbcf0938a5a939321d412c3773b16923a3123851b55d66f269';
const HOTEL_CORRECTED_BASELINE_SHA='afbec54201fc1c608bb8454f3fa86dfa95bd3e294b9187e16e62263fdabcebb5';
const HOTEL_PROVIDER_REVISION='ac6a2624c93677a9d490bc6d7b72eaf878daf1b47694df27635218330aa41aa0';
const HOTEL_PROVIDER_SOURCE_SHA='555f21004d85d6a660633b643d0eed961fea382d60da120218e4572c1cf7f6dd';
const HOTEL_CONNECTION_SOURCE_SHA='8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1';
const HOTEL_CORE_CYCLE_SHA='c3461bb600ed6818aa569dbb5c18f82be0a47a0935c69bfc804c7c5f5787dd0b';
const HOTEL_CORE_CORRECTION_SHA='88364e544473cd3270056df9a0e305b86ff7110d6af4c7d8515699062e4c93de';
const HOTEL_PLAIN_LIMIT=524288;
const HOTEL_WIRE_LIMIT=786432;
const HOTEL_STATUS_KIND='hotel-server-private-incident-v1';
const HOTEL_OPERATION='282614c6987f01a19461597ae4a279b64b47c540826940d75a85857a2d4e15c4';
const HOTEL_RUNTIME_SHA='f3ac0a8cc459032a56903cee40ae116742d5ad4a8297ee0aaaecae6b75a28f65';
const HOTEL_CONTINUATION_PROVIDER_REVISION='cb3e9b7c9510a6f6c0803f7954e52d6a16d8e4fae27d145a62e3f0aceb835419';
const HOTEL_OBSERVATION_IDENTITY_JSON='{"campaign_id":"HOTEL-SERVER-20261103-B07","correction_sha256":"16783bcd14edc7d446ab40b6fdbf77d84857aa84a3a094b1153c043c808ae576","installed_sha":"a45bb6c6d78576dba3854c683279d5126d67540e","observation_kind":"b07_natural_progress_after_correction","original_apply_operation":"ad2df472c3a90392a9a1023561fe6584c73b25f06e191321d26213bffe0f6f5d","original_apply_plaintext_sha256":"26a87fa0c66716cc7e269f2919e68a9ce9cb2b847c8ef51db16c7a3d6d94657a","original_apply_run_id":"41835798037","schema":"hotel-server-private-incident-v1","source_ref":"https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-6607905718"}';
const HOTEL_EXPECTED_CORRECTION_JSON='{"receipt_sha256":"42214e4f7037a0b2e789a09c4e2ee887bfe554a5981c22b2a425114121b478d5","record_hashes":{"2fac0c8f93f32aa8143c480409c478eb03b455a96821f412c87a3d1a482ebeae":"b733a24c8718651e6155b321c7f908df1c9435ce7c4076c01290d1f4ff1ce94b","439821589c274dc02f15575956ab6b77d9b16fa14e205fd4aa3c8ac5c499c1d3":"52f8034ae28c84ee0479c383c28fb7943407bc4c3a4d37c9de251e4ac2caa7be","c98169c665d5d1f1186c3b06f3265b7c8daa12a1b1a92dab051e8fed41bd1f68":"22d9cfb4473bc385ae3505f311d88aecc66b2b3dfaf75fcfa88f1df468004106","e3378955f4edfa9b32a94a8f4948c46f1868399d20c25aebe839edec6fb13210":"0c928e4e700df9751248a905d02bd91865a6e78bfb19a028b995a848a6c6e87c"}}';

function hotelFail(string $reason): never { throw new DomainException($reason); }

function hotelKeys(array $value,array $expected): void {
    $keys=array_keys($value);sort($keys);sort($expected);
    if($keys!==$expected)hotelFail('private_shape_unconfirmed');
}

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

function preflightAttemptPins(): array {
    return [
        'producer'=>['attempt_id'=>'85806d12fc7b767229d042b96f378f61b25400fa29b99356ad9b68d7001cc66f',
            'request_sha256'=>'2c7c073baca776f8e93f4adab01dd9da1024c2792843637878c8ae96e208c2eb',
            'result_sha256'=>'4b830d18e0ce34d0bb09f7930ba8d5f9428162170c222f1fc2095109b12156be'],
        'reviewer'=>['attempt_id'=>'588e97daa5e1c7bd282a4b9408f83533a8569713348e934d830027f6fb250aa6',
            'request_sha256'=>'e1026048a22e10ad1954f01a30b6292aeb0101eb1c01a619a5a51e177f3c7f89',
            'result_sha256'=>'ea730f5f878d3968989a0830bcc08207f7d94aefd88cbb3e769beef96ac14b8f']
    ];
}

function preflightRows(PDO $pdo,string $sql,array $args,int $limit): array {
    if(!str_starts_with($sql,'SELECT ')||str_contains($sql,'FOR UPDATE')||($args[0]??null)!==HOTEL_CAMPAIGN)
        hotelFail('preflight_read_scope_unconfirmed');
    $s=$pdo->prepare($sql);$s->execute($args);$rows=[];
    try{while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){$rows[]=$row;if(count($rows)>$limit)hotelFail('preflight_row_bound');}}
    finally{$s->closeCursor();}return $rows;
}

function preflightOne(PDO $pdo,string $sql,array $args): array {
    $rows=preflightRows($pdo,$sql,$args,1);if(count($rows)!==1)hotelFail('preflight_exact_row_required');return $rows[0];
}

function applySources(string $app): void {
    hotelInstalled($app);
    foreach(['services/HotelServerCycle.php'=>HOTEL_CORE_CYCLE_SHA,'services/HotelServerCorrection.php'=>HOTEL_CORE_CORRECTION_SHA,
        'services/ConversationDb.php'=>HOTEL_CONNECTION_SOURCE_SHA,'services/HotelServerAiProvider.php'=>HOTEL_PROVIDER_SOURCE_SHA] as $path=>$sha){
        $file=$app.'/'.$path;clearstatcache(true,$file);
        if(is_link($file)||realpath($file)!==$file||!is_file($file)||filesize($file)>131072)hotelFail('canonical_source_unconfirmed');
        $raw=file_get_contents($file,false,null,0,131073);
        if(!is_string($raw)||strlen($raw)>131072||hash('sha256',$raw)!==$sha)hotelFail('canonical_source_unconfirmed');
    }
}

function applyRuntime(string $app,?string $expectedSha=null,?array $expectedPolicy=null): array {
    $root=dirname($app).'/max-search-bot-hotel-server';hotelDir($root);$raw=hotelRead($root.'/runtime.json',65536,null,$expectedSha);$v=hotelJson($raw);
    hotelKeys($v,['bootstrap_sha256','campaign_id','flags','policy','schema']);
    if($v['schema']!=='hotel-server-runtime-v1'||$v['campaign_id']!==HOTEL_CAMPAIGN||$v['bootstrap_sha256']!==HOTEL_BOOTSTRAP_SHA
        ||!is_array($v['flags'])||!is_array($v['policy']))hotelFail('runtime_pin_unconfirmed');
    hotelKeys($v['flags'],['dispatch_allowed','enabled','replacement_dispatch_allowed']);foreach($v['flags'] as $flag)if($flag!==true)hotelFail('runtime_flags_unconfirmed');
    hotelKeys($v['policy'],['budget_attestation_sha256','input_token_ceiling','max_scopes','max_source_age_seconds','model','principals',
        'profile_sha256','request_byte_ceiling','reserved_micros_minimum','source_mode','valid_until']);
    if($expectedPolicy!==null&&hotelCanonical($v['policy'])!==hotelCanonical($expectedPolicy))hotelFail('runtime_policy_cas');
    return ['sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw),'exact_bytes_base64'=>base64_encode($raw),'document'=>$v];
}

function applySnapshot(PDO $pdo): array {
    $out=['observed_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'scope'=>HOTEL_CAMPAIGN,'request_result_bytes_returned'=>false];
    $out['campaign']=preflightOne($pdo,'SELECT campaign_id,state,enabled,stage_cursor,new_accepted,historical_accepted,baseline_sha256,inputs_sha256,bootstrap_sha256,reserved_calls,reserved_tokens,reserved_micros,SHA2(plan_bytes,256) AS plan_sha256 FROM hotel_server_campaigns WHERE campaign_id=?',[HOTEL_CAMPAIGN]);
    $out['lane']=preflightOne($pdo,"SELECT lane_key,queue_id FROM hotel_work_lane WHERE lane_key='HOTEL' AND queue_id=?",[HOTEL_CAMPAIGN]);
    $out['attempts']=preflightRows($pdo,'SELECT attempt_id,stage_index,role,principal,state,baseline_sha256,request_sha256,SHA2(request_bytes,256) AS request_actual_sha256,result_sha256,SHA2(result_bytes,256) AS result_actual_sha256 FROM hotel_server_attempts WHERE campaign_id=? ORDER BY stage_index,role LIMIT 9',[HOTEL_CAMPAIGN],8);
    $out['outboxes']=preflightRows($pdo,'SELECT stage_index,role,state,attempt_id FROM hotel_server_outbox WHERE campaign_id=? ORDER BY stage_index,role LIMIT 9',[HOTEL_CAMPAIGN],8);
    $out['receipts']=preflightRows($pdo,'SELECT stage_index,receipt_sha256,SHA2(receipt_bytes,256) AS actual_sha256,OCTET_LENGTH(receipt_bytes) AS bytes FROM hotel_server_receipts WHERE campaign_id=? ORDER BY stage_index LIMIT 5',[HOTEL_CAMPAIGN],4);
    $out['corrections']=preflightRows($pdo,'SELECT stage_index,correction_sha256,SHA2(correction_bytes,256) AS actual_sha256,candidate_sha256,SHA2(candidate_bytes,256) AS candidate_actual_sha256 FROM hotel_server_corrections WHERE campaign_id=? ORDER BY stage_index LIMIT 5',[HOTEL_CAMPAIGN],4);
    $out['records']=preflightRows($pdo,'SELECT semantic_key,candidate_sha256,SHA2(record_bytes,256) AS record_sha256 FROM hotel_server_records WHERE campaign_id=? ORDER BY semantic_key LIMIT 17',[HOTEL_CAMPAIGN],16);
    $out['catalog_outboxes']=preflightRows($pdo,'SELECT receipt_sha256,state,SHA2(payload_bytes,256) AS payload_sha256 FROM hotel_server_catalog_outbox WHERE campaign_id=? ORDER BY receipt_sha256 LIMIT 5',[HOTEL_CAMPAIGN],4);
    $out['original_candidate']=preflightOne($pdo,'SELECT candidate_sha256,SHA2(candidate_bytes,256) AS actual_sha256,producer_attempt FROM hotel_server_candidates WHERE campaign_id=? AND stage_index=0',[HOTEL_CAMPAIGN]);
    $out['original_review']=preflightOne($pdo,'SELECT review_attempt,candidate_sha256,approved,SHA2(review_bytes,256) AS result_sha256 FROM hotel_server_reviews WHERE campaign_id=? AND stage_index=0',[HOTEL_CAMPAIGN]);
    return $out;
}

function applyOriginals(array $snapshot): void {
    $pins=preflightAttemptPins();$seen=[];$outs=[];
    foreach($snapshot['attempts'] as $row)if(hotelInt($row['stage_index'])===0){
        $role=$row['role'];if(!isset($pins[$role])||isset($seen[$role]))hotelFail('original_attempts_changed');$p=$pins[$role];
        foreach(['attempt_id','request_sha256','result_sha256'] as $key)if($row[$key]!==$p[$key])hotelFail('original_attempts_changed');
        if($row['state']!=='succeeded'||$row['baseline_sha256']!==HOTEL_BASELINE_SHA||$row['request_actual_sha256']!==$p['request_sha256']
            ||$row['result_actual_sha256']!==$p['result_sha256'])hotelFail('original_attempts_changed');$seen[$role]=true;
    }
    foreach($snapshot['outboxes'] as $row)if(hotelInt($row['stage_index'])===0){
        $role=$row['role'];if(!isset($pins[$role])||isset($outs[$role])||$row['state']!=='done'||$row['attempt_id']!==$pins[$role]['attempt_id'])hotelFail('original_outboxes_changed');$outs[$role]=true;
    }
    if(count($seen)!==2||count($outs)!==2||$snapshot['original_candidate']['candidate_sha256']!==HOTEL_CANDIDATE_SHA
        ||$snapshot['original_candidate']['actual_sha256']!==HOTEL_CANDIDATE_SHA||$snapshot['original_candidate']['producer_attempt']!==$pins['producer']['attempt_id']
        ||$snapshot['original_review']['review_attempt']!==$pins['reviewer']['attempt_id']||$snapshot['original_review']['candidate_sha256']!==HOTEL_CANDIDATE_SHA
        ||hotelInt($snapshot['original_review']['approved'],1)!==0||$snapshot['original_review']['result_sha256']!==$pins['reviewer']['result_sha256'])hotelFail('original_negative_evidence_changed');
    $c=$snapshot['campaign'];
    if($c['campaign_id']!==HOTEL_CAMPAIGN||$c['bootstrap_sha256']!==HOTEL_BOOTSTRAP_SHA||$c['inputs_sha256']!==HOTEL_INPUTS_SHA
        ||$c['plan_sha256']!==HOTEL_PLAN_SHA||hotelInt($c['historical_accepted'])!==19||hotelInt($c['enabled'],1)!==1
        ||$snapshot['lane']['queue_id']!==HOTEL_CAMPAIGN)hotelFail('original_campaign_controls_changed');
    $calls=hotelInt($c['reserved_calls'],8);
    if($calls<2||hotelInt($c['reserved_tokens'])!==$calls*1052200||hotelInt($c['reserved_micros'])!==$calls*11000000)hotelFail('original_reservations_changed');
}

function applyCommitted(array $snapshot,array $expected): void {
    applyOriginals($snapshot);$c=$snapshot['campaign'];$stage=hotelInt($c['stage_cursor'],4);
    if($stage<1||hotelInt($c['new_accepted'],4)!==$stage)hotelFail('correction_acceptance_unconfirmed');
    $ledger=array_values(array_filter($snapshot['corrections'],static fn(array $v):bool=>hotelInt($v['stage_index'])===0));
    $receipts=array_values(array_filter($snapshot['receipts'],static fn(array $v):bool=>hotelInt($v['stage_index'])===0));
    if(count($ledger)!==1||$ledger[0]['correction_sha256']!==HOTEL_CORRECTION_SHA||$ledger[0]['actual_sha256']!==HOTEL_CORRECTION_SHA
        ||$ledger[0]['candidate_sha256']!==HOTEL_CORRECTED_CANDIDATE_SHA||$ledger[0]['candidate_actual_sha256']!==HOTEL_CORRECTED_CANDIDATE_SHA
        ||count($receipts)!==1||$receipts[0]['receipt_sha256']!==$expected['receipt_sha256']||$receipts[0]['actual_sha256']!==$expected['receipt_sha256'])hotelFail('correction_receipt_unconfirmed');
    $records=[];foreach($snapshot['records'] as $r)if($r['candidate_sha256']===HOTEL_CORRECTED_CANDIDATE_SHA)$records[$r['semantic_key']]=$r['record_sha256'];ksort($records);
    if($records!==$expected['record_hashes'])hotelFail('corrected_records_unconfirmed');
    $next=array_values(array_filter($snapshot['outboxes'],static fn(array $v):bool=>hotelInt($v['stage_index'])===1&&$v['role']==='producer'));
    $catalog=array_values(array_filter($snapshot['catalog_outboxes'],static fn(array $v):bool=>$v['receipt_sha256']===$expected['receipt_sha256']));
    if(count($next)!==1||!in_array($next[0]['state'],['pending','unknown','done'],true)||count($catalog)!==1
        ||$catalog[0]['payload_sha256']!==HOTEL_CORRECTED_BASELINE_SHA||!in_array($catalog[0]['state'],['pending','done'],true))hotelFail('correction_outbox_unconfirmed');
}

ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$pdo=null;$target=false;$outputDone=false;$emergencyMemory=str_repeat('x',2097152);
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'campaign_id'=>HOTEL_CAMPAIGN,
    'state'=>'HOLD','phase'=>'target','operator_provider_calls'=>0,'database_writes'=>0,
    'runtime_config_changed'=>false,'cron_changed'=>false,'new_campaign_started'=>false,
    'automatic_retry_allowed'=>false,'read_only_snapshot_started'=>false,
    'read_only_snapshot_rolled_back'=>false,'snapshot_count'=>0,'section_errors'=>[]];

function observationReason(Throwable $e): string {
    $message=$e->getMessage();
    return $e instanceof DomainException&&preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'readback_unconfirmed';
}

function observationSummary(array $snapshot): array {
    $c=$snapshot['campaign'];$accepted=hotelInt($c['new_accepted'],4);$calls=hotelInt($c['reserved_calls'],8);
    if($calls!==count($snapshot['attempts'])||count($snapshot['receipts'])!==$accepted
        ||count($snapshot['records'])!==$accepted*4||count($snapshot['corrections'])!==1)
        hotelFail('observed_counts_unconfirmed');
    $succeeded=0;$unknown=0;$newSucceeded=0;$newUnknown=0;$seen=[];$byStage=[];
    foreach($snapshot['attempts'] as $row){
        $stage=hotelInt($row['stage_index'],3);$role=$row['role'];$id=$stage.':'.$role;
        if(!in_array($role,['producer','reviewer'],true)||isset($seen[$id]))hotelFail('observed_attempt_identity_unconfirmed');
        $revision=$stage===0?HOTEL_PROVIDER_REVISION:HOTEL_CONTINUATION_PROVIDER_REVISION;
        if($row['principal']!=='hotel-20261102-'.$role.':'.$revision
            ||hotelHash($row['request_sha256'])!==$row['request_actual_sha256'])hotelFail('observed_request_identity_unconfirmed');
        $seen[$id]=true;$byStage[$stage][$role]=$row;
        if($row['state']==='succeeded'){
            if(hotelHash($row['result_sha256'])!==$row['result_actual_sha256'])hotelFail('observed_saved_result_unconfirmed');
            $succeeded++;if($stage>=1)$newSucceeded++;
        }elseif($row['state']==='unknown'){
            $unknown++;if($stage>=1)$newUnknown++;
        }else hotelFail('observed_attempt_state_unconfirmed');
    }
    $receiptStages=[];
    foreach($snapshot['receipts'] as $r){
        $stage=hotelInt($r['stage_index'],3);
        if(isset($receiptStages[$stage])||$stage>=$accepted||hotelHash($r['receipt_sha256'])!==$r['actual_sha256'])
            hotelFail('observed_receipt_history_unconfirmed');
        foreach(['producer','reviewer'] as $role)if(($byStage[$stage][$role]['state']??null)!=='succeeded')
            hotelFail('observed_acceptance_results_unconfirmed');
        $receiptStages[$stage]=true;
    }
    for($stage=0;$stage<$accepted;$stage++)if(!isset($receiptStages[$stage]))hotelFail('observed_receipt_history_unconfirmed');
    $complete=$c['state']==='complete'&&$accepted===4&&hotelInt($c['stage_cursor'],4)===4
        &&$calls===8&&$succeeded===8&&$unknown===0;
    $status=$complete?'complete':($c['state']==='hold'?'hold':($newSucceeded>0?'saved_new_results':($calls>2?'new_reservations':'pending_without_new_reservations')));
    return ['business_status'=>$status,'campaign_state'=>$c['state'],'stage_index'=>hotelInt($c['stage_cursor'],4),
        'accepted_stages'=>$accepted,'accepted_records'=>count($snapshot['records']),
        'accepted_stage_receipts'=>count($snapshot['receipts']),'original_corrected_stage_accepted'=>true,
        'naturally_accepted_later_stages'=>$accepted-1,'reserved_calls'=>$calls,'original_reserved_calls'=>2,
        'newly_reserved_calls'=>$calls-2,'remaining_original_call_cap'=>8-$calls,'original_call_cap'=>8,
        'saved_succeeded_results'=>$succeeded,'new_saved_succeeded_results'=>$newSucceeded,
        'unknown_attempts'=>$unknown,'new_unknown_attempts'=>$newUnknown,
        'pending_outbox_is_not_result_evidence'=>true,'unknown_reservation_is_not_result_evidence'=>true,
        'original_attempts_and_negative_review_preserved'=>true,'correction_receipt_and_record_hashes_confirmed'=>true,
        'complete_confirmed'=>$complete,'all_requested_provider_calls_from_this_observer'=>0];
}

function observationFinish(): int {
    global $pdo,$target,$outputDone,$level,$report;
    if($outputDone)return 1;$outputDone=true;
    if($pdo instanceof PDO&&$pdo->inTransaction()){
        try{$pdo->rollBack();$report['read_only_snapshot_rolled_back']=true;}
        catch(Throwable $e){$report['section_errors']['rollback']=observationReason($e);$report['state']='HOLD';}
    }
    $report['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $clean=$report['state']==='observed'&&$report['read_only_snapshot_rolled_back']&&$report['section_errors']===[];
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
    $report['phase']='installed_source';applySources($app);$report['installed_sha']=HOTEL_EXPECTED_INSTALLED_SHA;
    hotelSeal(['state'=>'crypto_preflight','operator_provider_calls'=>0]);
    $report['phase']='runtime_metadata';$runtime=applyRuntime($app,HOTEL_RUNTIME_SHA);
    $report['runtime']=['sha256'=>$runtime['sha256'],'bytes'=>$runtime['bytes'],'document'=>$runtime['document']];unset($runtime);
    require_once $app.'/config.php';require_once $app.'/services/ConversationDb.php';
    $pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
        ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))hotelFail('application_database_unconfirmed');
    $report['phase']='single_bounded_snapshot';
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $report['read_only_snapshot_started']=true;$report['snapshot_count']=1;
    try{
        $report['snapshot']=applySnapshot($pdo);
        applyCommitted($report['snapshot'],hotelJson(HOTEL_EXPECTED_CORRECTION_JSON));
        $report['summary']=observationSummary($report['snapshot']);
    }finally{if($pdo->inTransaction()){$pdo->rollBack();$report['read_only_snapshot_rolled_back']=true;}}
    $report['phase']='installed_source_final';applySources($app);
    $report['state']='observed';$report['phase']='finished';
}catch(Throwable $e){$report['state']='HOLD';$report['reason']=observationReason($e);}
exit(observationFinish());
