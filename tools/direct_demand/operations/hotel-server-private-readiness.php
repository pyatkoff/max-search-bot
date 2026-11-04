<?php
declare(strict_types=1);
/* Existing private channel: one reviewed B07 stage-zero correction and bounded
 * continuation. No provider/tick/bootstrap/enable/cron installation is invoked.
 * Unpublished placeholders must be bound only after the canonical release and
 * fresh shared-publisher admission. A consumed operation is never replayed.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='31ea399bb7f2837bd05ab6a5f5e8529222ea71a4';
const HOTEL_OPERATION='ad2df472c3a90392a9a1023561fe6584c73b25f06e191321d26213bffe0f6f5d';
const HOTEL_PUBLICATION_ADMISSION_REF='https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-6608084807';
const HOTEL_STATUS_KIND='hotel-server-private-correction-v1';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_INPUT_RECIPIENT_SHA='137da1e625bcd005e4db69335781f0396fb44995b9668e1b35111f2307810388';
const HOTEL_CORRECTION_SHA='16783bcd14edc7d446ab40b6fdbf77d84857aa84a3a094b1153c043c808ae576';
const HOTEL_CAPSULE_SHA='aab16c5eb9559cb1205869305a4e5917dceececd7b0886bd1a446084288edb97';
const HOTEL_ADMISSION_SHA='ca01d41e5728645525ec0fe1ce9e06391658be9561b7faca7aa41660ca62749c';
const HOTEL_BUDGET_SHA='6fa17ce36c5ad8e558d0e6a167bd5e614494ea3019e0c38d3c67373dd879d435';
const HOTEL_VALID_FROM=1793794536;
const HOTEL_VALID_UNTIL=1793800800;
const HOTEL_CAMPAIGN='HOTEL-SERVER-20261103-B07';
const HOTEL_BOOTSTRAP_SHA='4577961cf5ab96a62df821cfc4f1d9a80b373ae07da10e3f143d7b22adeac6ed';
const HOTEL_INPUTS_SHA='b0f093ff27ad6de311fb6eb351f194ba1b413483d4a7a336ae9aa13986f69448';
const HOTEL_BASELINE_SHA='d0f83be35829d178473ecb4b0d5f4397786256f4a7df5c1bf691085f7d096bec';
const HOTEL_PLAN_SHA='714315a5588f8eef7f4932ed06abf7d25107bd2d58b2799ac5a198c87d536134';
const HOTEL_CRITERIA_SHA='bd386c46d35b2d97411c8cd5a5e8b7305ce4a3022b2b592c301bfcd2009630d7';
const HOTEL_CANDIDATE_SHA='44596fb4cc60d4ea40635b3b44ce8f2f3cd54d0152d20827d8d8bec7361c7034';
const HOTEL_CORRECTED_CANDIDATE_SHA='c8e35bf980524fcbcf0938a5a939321d412c3773b16923a3123851b55d66f269';
const HOTEL_CORRECTED_BASELINE_SHA='afbec54201fc1c608bb8454f3fa86dfa95bd3e294b9187e16e62263fdabcebb5';
const HOTEL_RUNTIME_SHA='1e4abef0c4880bb1b28dfef1217938f630d5de974d44264679226df0662a2111';
const HOTEL_POLICY_SHA='c7cc48331a83249d700cac31e2804897d35bcdd0366bdb767dd150787ef8dcbb';
const HOTEL_PROFILE_SHA='92ae1de467574b17a77d188b2f9766d139e5ada622126a20d2fe2052f5506323';
const HOTEL_PROVIDER_REVISION='ac6a2624c93677a9d490bc6d7b72eaf878daf1b47694df27635218330aa41aa0';
const HOTEL_PROVIDER_SOURCE_SHA='555f21004d85d6a660633b643d0eed961fea382d60da120218e4572c1cf7f6dd';
const HOTEL_PRODUCER_INSTRUCTIONS_SHA='c116cda921261c22f82c9547cb6e3ba865bd3b7b1bc34b14bcf290291518800b';
const HOTEL_REVIEWER_INSTRUCTIONS_SHA='43d17a643e4f9da7adf06d0b312602683c014dc37aa2d701e2606b82d4265d5a';
const HOTEL_CONNECTION_SOURCE_SHA='8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1';
const HOTEL_CORE_CYCLE_SHA='c3461bb600ed6818aa569dbb5c18f82be0a47a0935c69bfc804c7c5f5787dd0b';
const HOTEL_CORE_CORRECTION_SHA='88364e544473cd3270056df9a0e305b86ff7110d6af4c7d8515699062e4c93de';
const HOTEL_TARGET_SCOPE_KEY='439821589c274dc02f15575956ab6b77d9b16fa14e205fd4aa3c8ac5c499c1d3';
const HOTEL_TARGET_CONTEXT_SHA='aeeaf8a6f12b5c483bda2f6dde509bd921cdb7c990827509870013df8282497a';
const HOTEL_PLAIN_LIMIT=524288;
const HOTEL_WIRE_LIMIT=786432;
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$pdo=null;$lock=null;$target=false;$outputDone=false;$ownedWork=false;$work=null;$app=null;$mask=null;
$correction=null;$expected=null;$runtimeNext=null;$emergencyMemory=str_repeat('x',2097152);
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'campaign_id'=>HOTEL_CAMPAIGN,'stage_index'=>0,
    'publication_admission_ref'=>HOTEL_PUBLICATION_ADMISSION_REF,'correction_sha256'=>HOTEL_CORRECTION_SHA,
    'admission_sha256'=>HOTEL_ADMISSION_SHA,'state'=>'HOLD','phase'=>'target','operator_provider_calls'=>0,
    'runtime_switch_started'=>false,'runtime_file_installed'=>false,'database_mutation_started'=>false,
    'correction_committed'=>false,'mutation_outcome'=>'not_started','runtime_config_changed'=>false,
    'cron_changed'=>false,'new_campaign_started'=>false,'automatic_retry_allowed'=>false,'section_errors'=>[]];

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

function preflightProfile(): array {
    return ['max_output_tokens'=>2200,'model'=>'gpt-6.1-sol','reasoning'=>['effort'=>'low'],'service_tier'=>'default','store'=>false];
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

function preflightRuntime(string $app): array {
    $root=dirname($app).'/max-search-bot-hotel-server';hotelDir($root);
    $raw=hotelRead($root.'/runtime.json',65536,null,HOTEL_RUNTIME_SHA);$v=hotelJson($raw);
    hotelKeys($v,['schema','campaign_id','bootstrap_sha256','flags','policy']);
    if($v['schema']!=='hotel-server-runtime-v1'||$v['campaign_id']!==HOTEL_CAMPAIGN
        ||$v['bootstrap_sha256']!==HOTEL_BOOTSTRAP_SHA||!is_array($v['flags'])||!is_array($v['policy']))hotelFail('preflight_runtime_cas');
    hotelKeys($v['flags'],['enabled','dispatch_allowed','replacement_dispatch_allowed']);
    foreach($v['flags'] as $flag)if($flag!==true)hotelFail('preflight_runtime_cas');
    $p=$v['policy'];hotelKeys($p,['budget_attestation_sha256','input_token_ceiling','max_scopes','max_source_age_seconds',
        'model','principals','profile_sha256','request_byte_ceiling','reserved_micros_minimum','source_mode','valid_until']);
    hotelKeys($p['principals'],['producer','reviewer']);
    if(hash('sha256',hotelCanonical($p))!==HOTEL_POLICY_SHA)hotelFail('original_policy_changed');
    $profile=preflightProfile();if(hash('sha256',hotelCanonical($profile))!==HOTEL_PROFILE_SHA||$p['profile_sha256']!==HOTEL_PROFILE_SHA)hotelFail('original_profile_unconfirmed');
    $instructions=['producer'=>HOTEL_PRODUCER_INSTRUCTIONS_SHA,'reviewer'=>HOTEL_REVIEWER_INSTRUCTIONS_SHA];
    $revision=hash('sha256',hotelCanonical([$p,$profile,$instructions['producer'],$instructions['reviewer']]));
    if($revision!==HOTEL_PROVIDER_REVISION)hotelFail('original_provider_revision_unconfirmed');
    $provider=$app.'/services/HotelServerAiProvider.php';
    if(is_link($provider)||realpath($provider)!==$provider||hash_file('sha256',$provider)!==HOTEL_PROVIDER_SOURCE_SHA)
        hotelFail('instruction_source_unconfirmed');
    return ['runtime_sha256'=>hash('sha256',$raw),'policy'=>$p,'policy_sha256'=>HOTEL_POLICY_SHA,'profile'=>$profile,
        'profile_sha256'=>HOTEL_PROFILE_SHA,'instruction_sha256'=>$instructions,'provider_revision_sha256'=>$revision,
        'profile_origin'=>'original_public_budget_profile_checked_against_saved_results',
        'instruction_origin'=>'pinned_provider_source_public_static_strings_checked_against_saved_results',
        'current_policy_returned_without_substitution'=>true,'credentials_or_unknown_runtime_fields_returned'=>false];
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

function preflightDocument(mixed $raw,int $limit,string $hash): array {
    if(!is_string($raw)||$raw===''||strlen($raw)>$limit||hash('sha256',$raw)!==$hash)hotelFail('preflight_exact_bytes_required');
    $doc=hotelJson($raw);if(hotelCanonical($doc)!==$raw)hotelFail('preflight_canonical_bytes_required');return $doc;
}

function preflightByteEvidence(string $raw,string $hash): array {
    return ['bytes'=>strlen($raw),'sha256'=>$hash,'exact_bytes_base64'=>base64_encode($raw),'exact_bytes_preserved'=>true];
}

function preflightScopeKey(array $scope): string {
    hotelKeys($scope,['country','hotel','question','source']);
    foreach($scope as $v)if(!is_string($v)||trim($v)!==$v||$v===''||strlen($v)>256)hotelFail('preflight_scope_shape');
    if($scope['country']!=='EG'||$scope['hotel']!=='official_casa_cook_el_gouna')hotelFail('preflight_foreign_scope');
    return hash('sha256',hotelCanonical($scope));
}

function preflightRead(PDO $pdo,array $runtime,array &$out): void {
    $out=['state'=>'reading','consistent_read_only_snapshot'=>true,'read_only_snapshot_rolled_back'=>false,
        'request_bytes_returned'=>false,'accepted_history_returned'=>false];
    $c=preflightOne($pdo,'SELECT campaign_id,state,enabled,stage_cursor,new_accepted,historical_accepted,baseline_sha256,inputs_sha256,bootstrap_sha256,reserved_calls,reserved_tokens,reserved_micros,CASE WHEN OCTET_LENGTH(plan_bytes)<=65536 THEN plan_bytes ELSE NULL END AS plan_bytes FROM hotel_server_campaigns WHERE campaign_id=? LIMIT 2',[HOTEL_CAMPAIGN]);
    if($c['campaign_id']!==HOTEL_CAMPAIGN||$c['state']!=='hold'||hotelInt($c['enabled'],1)!==1
        ||hotelInt($c['stage_cursor'],4)!==0||hotelInt($c['new_accepted'],4)!==0||hotelInt($c['historical_accepted'],1000000)!==19
        ||$c['baseline_sha256']!==HOTEL_BASELINE_SHA||$c['inputs_sha256']!==HOTEL_INPUTS_SHA||$c['bootstrap_sha256']!==HOTEL_BOOTSTRAP_SHA
        ||hotelInt($c['reserved_calls'])!==2||hotelInt($c['reserved_tokens'])!==2104400||hotelInt($c['reserved_micros'])!==22000000)
        hotelFail('preflight_campaign_cas');
    $plan=preflightDocument($c['plan_bytes'],65536,HOTEL_PLAN_SHA);
    if(!is_array($plan['stages']??null)||count($plan['stages'])!==4||!is_array($plan['stages'][0])||count($plan['stages'][0])!==4)
        hotelFail('preflight_original_plan_shape');
    $scopes=$plan['stages'][0];$scopeKeys=array_map('preflightScopeKey',$scopes);
    if(count(array_unique($scopeKeys))!==4||!in_array(HOTEL_TARGET_SCOPE_KEY,$scopeKeys,true))hotelFail('preflight_target_scope_required');
    $input=preflightOne($pdo,'SELECT campaign_id,inputs_sha256 FROM hotel_server_inputs WHERE campaign_id=? LIMIT 2',[HOTEL_CAMPAIGN]);
    if($input['campaign_id']!==HOTEL_CAMPAIGN||$input['inputs_sha256']!==HOTEL_INPUTS_SHA)hotelFail('preflight_inputs_cas');
    $out['campaign']=['campaign_id'=>HOTEL_CAMPAIGN,'state'=>'hold','enabled'=>true,'stage_index'=>0,'new_accepted'=>0,
        'bootstrap_sha256'=>HOTEL_BOOTSTRAP_SHA,'baseline_sha256'=>HOTEL_BASELINE_SHA,'inputs_sha256'=>HOTEL_INPUTS_SHA,
        'reserved_calls'=>2,'reserved_tokens'=>2104400,'reserved_micros'=>22000000];
    $out['plan']=preflightByteEvidence($c['plan_bytes'],HOTEL_PLAN_SHA);$out['original_limits']=$plan['limits'];
    $out['remaining_original_reservation']=['calls'=>6,'tokens'=>6313200,'micros'=>66000000,'renewed_deadline_admitted'=>false];
    $candidate=preflightOne($pdo,'SELECT campaign_id,stage_index,candidate_sha256,producer_attempt,CASE WHEN OCTET_LENGTH(candidate_bytes)<=32768 THEN candidate_bytes ELSE NULL END AS candidate_bytes FROM hotel_server_candidates WHERE campaign_id=? AND stage_index=? LIMIT 2',[HOTEL_CAMPAIGN,0]);
    $pins=preflightAttemptPins();
    if($candidate['campaign_id']!==HOTEL_CAMPAIGN||hotelInt($candidate['stage_index'])!==0||$candidate['candidate_sha256']!==HOTEL_CANDIDATE_SHA||$candidate['producer_attempt']!==$pins['producer']['attempt_id'])hotelFail('preflight_candidate_cas');
    $doc=preflightDocument($candidate['candidate_bytes'],32768,HOTEL_CANDIDATE_SHA);hotelKeys($doc,['schema','records']);
    if($doc['schema']!=='hotel-server-candidate-v1'||!is_array($doc['records'])||!array_is_list($doc['records'])||count($doc['records'])!==4)hotelFail('preflight_candidate_shape');
    $seen=[];
    foreach($doc['records'] as $record){
        if(!is_array($record))hotelFail('preflight_candidate_shape');hotelKeys($record,['scope','answer','evidence']);
        $k=preflightScopeKey($record['scope']);
        if(isset($seen[$k])||!in_array($k,$scopeKeys,true)||!is_string($record['answer'])||$record['answer']===''||strlen($record['answer'])>32768||!is_array($record['evidence']))hotelFail('preflight_candidate_shape');
        $seen[$k]=true;
    }
    $out['candidate']=preflightByteEvidence($candidate['candidate_bytes'],HOTEL_CANDIDATE_SHA)+['producer_attempt'=>$candidate['producer_attempt'],'records'=>$doc['records']];
    $requests=[];$results=[];$out['attempts']=[];
    foreach($pins as $role=>$pin){
        $a=preflightOne($pdo,'SELECT campaign_id,stage_index,attempt_id,role,principal,state,baseline_sha256,request_sha256,result_sha256,CASE WHEN OCTET_LENGTH(request_bytes)<=131072 THEN request_bytes ELSE NULL END AS request_bytes,CASE WHEN OCTET_LENGTH(result_bytes)<=32768 THEN result_bytes ELSE NULL END AS result_bytes FROM hotel_server_attempts WHERE campaign_id=? AND stage_index=? AND role=? AND attempt_id=? LIMIT 2',[HOTEL_CAMPAIGN,0,$role,$pin['attempt_id']]);
        if($a['campaign_id']!==HOTEL_CAMPAIGN||hotelInt($a['stage_index'])!==0||$a['attempt_id']!==$pin['attempt_id']||$a['role']!==$role||$a['state']!=='succeeded'
            ||$a['baseline_sha256']!==HOTEL_BASELINE_SHA||$a['principal']!==$runtime['policy']['principals'][$role].':'.HOTEL_PROVIDER_REVISION
            ||$a['request_sha256']!==$pin['request_sha256']||$a['result_sha256']!==$pin['result_sha256'])hotelFail('preflight_attempt_cas');
        $request=preflightDocument($a['request_bytes'],131072,$pin['request_sha256']);$result=preflightDocument($a['result_bytes'],32768,$pin['result_sha256']);
        foreach(['attempt_id','principal','baseline_sha256'] as $key)if(($request[$key]??null)!==$a[$key]||($result[$key]??null)!==$a[$key])hotelFail('preflight_attempt_lineage');
        if(($request['campaign_id']??null)!==HOTEL_CAMPAIGN||($request['stage_index']??null)!==0||($request['role']??null)!==$role
            ||($request['inputs_sha256']??null)!==HOTEL_INPUTS_SHA||($result['inputs_sha256']??null)!==HOTEL_INPUTS_SHA
            ||($request['criteria_sha256']??null)!==HOTEL_CRITERIA_SHA||($request['research_mode']??null)!=='assigned_retained_excerpts'
            ||hotelCanonical($request['scopes']??null)!==hotelCanonical($scopes)||hotelCanonical($request['limits']??null)!==hotelCanonical($plan['limits'])
            ||($request['model']??null)!=='gpt-6.1-sol'||($result['provider_model']??null)!=='gpt-6.1-sol'
            ||($result['profile_sha256']??null)!==HOTEL_PROFILE_SHA||($result['provider_policy_sha256']??null)!==HOTEL_PROVIDER_REVISION
            ||($result['budget_attestation_sha256']??null)!==$runtime['policy']['budget_attestation_sha256']
            ||array_key_exists('provider_outcome',$result))hotelFail('preflight_saved_result_lineage');
        $requests[$role]=$request;$results[$role]=$result;
        $out['attempts'][$role]=['attempt_id'=>$pin['attempt_id'],'principal'=>$a['principal'],'state'=>'succeeded','request_sha256'=>$pin['request_sha256'],
            'request_bytes_read_only_for_hash_and_scoped_projection'=>true,'request_bytes_not_returned'=>true,
            'result'=>preflightByteEvidence($a['result_bytes'],$pin['result_sha256'])];
    }
    if(($requests['producer']['candidate']??null)!==null||($requests['producer']['candidate_sha256']??null)!==null
        ||hotelCanonical($results['producer']['candidate']??null)!==$candidate['candidate_bytes']
        ||($requests['reviewer']['candidate_sha256']??null)!==HOTEL_CANDIDATE_SHA||hotelCanonical($requests['reviewer']['candidate']??null)!==$candidate['candidate_bytes'])hotelFail('preflight_candidate_lineage');
    $review=preflightOne($pdo,'SELECT campaign_id,stage_index,review_attempt,candidate_sha256,approved,CASE WHEN OCTET_LENGTH(review_bytes)<=32768 THEN review_bytes ELSE NULL END AS review_bytes FROM hotel_server_reviews WHERE campaign_id=? AND stage_index=? LIMIT 2',[HOTEL_CAMPAIGN,0]);
    if($review['campaign_id']!==HOTEL_CAMPAIGN||hotelInt($review['stage_index'])!==0||$review['review_attempt']!==$pins['reviewer']['attempt_id']||$review['candidate_sha256']!==HOTEL_CANDIDATE_SHA
        ||hotelInt($review['approved'],1)!==0||!is_string($review['review_bytes'])||hash('sha256',$review['review_bytes'])!==$pins['reviewer']['result_sha256']
        ||hotelCanonical($results['reviewer'])!==$review['review_bytes']||($results['reviewer']['approved']??null)!==false
        ||($results['reviewer']['candidate_sha256']??null)!==HOTEL_CANDIDATE_SHA||($results['reviewer']['criteria_sha256']??null)!==HOTEL_CRITERIA_SHA)hotelFail('preflight_negative_review_lineage');
    $checks=$results['reviewer']['review_checks']??null;$seen=[];$negative=[];
    if(!is_array($checks)||!array_is_list($checks)||count($checks)!==4)hotelFail('preflight_scope_checks_required');
    foreach($checks as $check){
        if(!is_array($check))hotelFail('preflight_scope_checks_required');hotelKeys($check,['scope','approved','reason']);$k=preflightScopeKey($check['scope']);
        if(isset($seen[$k])||!in_array($k,$scopeKeys,true)||!is_bool($check['approved'])||!is_string($check['reason'])||$check['reason']===''||strlen($check['reason'])>2048)hotelFail('preflight_scope_checks_required');
        $seen[$k]=true;if(!$check['approved'])$negative[]=$k;
    }
    $out['review_checks']=$checks;$out['negative_scope_keys']=$negative;$out['single_target_scope_rejected']=$negative===[HOTEL_TARGET_SCOPE_KEY];
    $deps=$requests['producer']['dependencies']??null;
    if(!is_array($deps)||!array_is_list($deps)||count($deps)!==4||hotelCanonical($deps)!==hotelCanonical($requests['reviewer']['dependencies']??null))hotelFail('preflight_dependencies_lineage');
    $seen=[];$targetContext=null;
    foreach($deps as $context){
        if(!is_array($context))hotelFail('preflight_dependencies_shape');hotelKeys($context,['scope','dependencies']);$k=preflightScopeKey($context['scope']);
        if(isset($seen[$k])||!in_array($k,$scopeKeys,true)||!is_array($context['dependencies']))hotelFail('preflight_dependencies_shape');
        hotelKeys($context['dependencies'],['archives','corpus','criteria','instructions','map']);
        foreach($context['dependencies'] as $text)if(!is_string($text)||$text===''||strlen($text)>8192)hotelFail('preflight_dependencies_bound');
        $seen[$k]=true;if($k===HOTEL_TARGET_SCOPE_KEY)$targetContext=$context;
    }
    if($targetContext===null||hash('sha256',hotelCanonical($targetContext))!==HOTEL_TARGET_CONTEXT_SHA)hotelFail('preflight_target_context_cas');
    $out['stage_contexts']=$deps;$out['target_scope_key']=HOTEL_TARGET_SCOPE_KEY;$out['target_context_sha256']=HOTEL_TARGET_CONTEXT_SHA;$out['criteria_sha256']=HOTEL_CRITERIA_SHA;
    $outs=preflightRows($pdo,'SELECT campaign_id,stage_index,role,state,attempt_id FROM hotel_server_outbox WHERE campaign_id=? AND stage_index=? ORDER BY role LIMIT 3',[HOTEL_CAMPAIGN,0],2);
    if(count($outs)!==2)hotelFail('preflight_outbox_cas');$seen=[];
    foreach($outs as $row){$role=$row['role'];if(!isset($pins[$role])||isset($seen[$role])||$row['campaign_id']!==HOTEL_CAMPAIGN||hotelInt($row['stage_index'])!==0||$row['state']!=='done'||$row['attempt_id']!==$pins[$role]['attempt_id'])hotelFail('preflight_outbox_cas');$seen[$role]=true;}
    foreach(['hotel_server_receipts','hotel_server_corrections'] as $table){
        $row=preflightOne($pdo,'SELECT COUNT(*) AS saved_count FROM '.$table.' WHERE campaign_id=? AND stage_index=?',[HOTEL_CAMPAIGN,0]);
        if(hotelInt($row['saved_count'])!==0)hotelFail('preflight_stage_already_accepted_or_corrected');
    }
    $out['saved_outboxes_done']=true;$out['existing_stage_receipts']=0;$out['existing_stage_corrections']=0;
    $out['correction_content_prerequisite_met']=$out['single_target_scope_rejected'];
    $out['state']='observed';
}


// Same exclusive-create/fsync/readback primitive as the accepted B07 handoff.
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
function applyWindow(): void {
    if(time()<HOTEL_VALID_FROM||time()>=HOTEL_VALID_UNTIL)hotelFail('prepared_admission_expired_or_not_current');
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
function applyCorrection(string $root): array {
    $encoded='eyJhYWQiOiJob3RlbC1zZXJ2ZXItY29ycmVjdGlvbi1jYXBzdWxlLXYxfDE2NzgzYmNkMTRlZGM3ZDQ0NmFiNDBiNmZkYmY3N2Q4NDg1N2FhODRhM2EwOTRiMTE1M2MwNDNjODA4YWU1NzZ8MTM3ZGExZTYyNWJjZDAwNWU0ZGI2OTMzNTc4MWYwMzk2ZmI0NDk5NWI5NjY4ZTFiMzUxMTFmMjMwNzgxMDM4OCIsImFsZ29yaXRobSI6IlJTQS1PQUVQLVNIQTErQTI1NkdDTSIsImNpcGhlcnRleHQiOiJXYUNiODAwdFpTdDdwQ3FQN2lEM0VhbW9yU1h4T2hHZGdPQ0NEVUcxRnJjeGdTSEJvTk4wdWhnNllFcjZkb0Uvd2dHMjZLSGNLS0xKZHRjbzBGejlnSWNzbk1PVGIyeGdTbDFiSkpJblliZnBocmptMlN0OGVaWHJvSURBbzZCUTlnMHVmaFZhdjRLV1F3NlU1WmJjMDJYK2REaDNMOUNjbjVIeVNsMkQwU3EzOUlKODRpSE9objRKcXFPbHJNOE9jdDhpSzFsUFZsOTJyS2hKeXpMYVFJNm1HRFZLUkRhQzZkN1FQejlnZlNsRVpUbkYzMHlSQzl2RThldFlSWnozZlpUc2ltbmtTZmhJamtKR3JmQjEwWEZZb3FialorOEtHc1E3ZWRIeEdHeHdMNkRBQ2RKTUUwZi92VXdqZjdEemZJaUFucUJWWDFqVUdta05pdVpTVkpxaVFyeEs3QklkL2l5R2Ryb1FFdktEMHpoTno5bWYyRDVJanJJTmhmRFIzVHVLUDliYTV3N2FsVHJxTjl0WHlZZll2TVpwZDBJOHlCYUJLNWsvSmFtdHZDeXI1UzBvT3ZlOFo1c1o2Q3ZGN2hnK3o4eDl2MEI5cDVaN0VsZmVWenRNclFmVzdXRmpodlcvUkxDbzFyeVRScWF6SDJNeVlkMDZmYkxlTlpwbVRZcmRZTGVIeENJYWFMRE5ZSlRaZjdjWGc3Q0ZFWWpCb05hRUF2Ym9uWDQxa0RhcmZ2clZUNGQ1ZktnOWpTQWRGYmtRZVFFNjlIdW1ndklWenNrd1hNWjhhaVU4bjVQV0hXZS9qT05sQWxhWERlNU0vMjhPc3ZRQ2ZGbXN3MmhxblhQN1hLL0RyNjVUZjBEZ2FIRnhpdlBTYVFUWmVnbm1XbHFRZmxkaXpiaXE1czdScFJxTVA4cUxONnB6TDU5MnBVcUtxUU9TbGtMZ1YwRkZENEpOamh3S0htc1dVQ2NOWW02ZkdsYmwrTzZtcHRKblZndWZhdFJZVk5mVXp3NnlDSTI3MkpyaHJJaUlHNFdWOEx3RW1WUUJYbklEN3lWd3cxOGl6aU1Gd0VlbzRRREFBNkMwNmp5eW5CUlZ5MHVuclhyK2Uwbnp1ZHB4aE56ZkpDalhabW5FZitON0ZXUnZaMTllazFsblZ5ZG41Ry9NQXpId29GT1BjUXlOcEpocmRqNVNWVEoxUE54c1REblVkaEU4T09abVRPK2FVTUlQY0ZBdWtLclhVR2pWOVpzQkJXZmppSHdudHErQklMcldrQmFsWURWMXpDK2Z4eFQrRVNGalVDQlRBc3JtVWFKQVh0L0lhUmFwbmRMeHlBZTFMcDhzd0NUdlRva3k5a3RIem9zMkltTzI5cm43NzNITDNnaWtEZWZiWVNWZUVpQkhXSWpkeVM5Q3dUR3JaQS81VGJzdk00SGVDR2JlOS9CNFdlVVNDYVA1YXVlSW00d1E0d3IzR3FGRnE3YmZrMHdSNHl6WHRpL2dPbGt4enE3V1hrMjl4MTA1Y3l4dDBOTVFPVWl4WmNtbHZseHUrUDNGZnowc0wyek9jTTBYdTJtQmY0aXhGZnJQNW1oVThJSDJuZTFMSXBoMWdXNVdrV2llSUNqaTdlbkhJTHZuM0pRUVBHSEFJMHZaUC85VVpHYVV3STVncE1IUUdUeUFiTk5SSEhZemxNTW4wcVFCVStjTm1DVHlzMVcwS013cHlXTG82dmpzcGhZQnJoRHd5VllUK2Qxa1IySnplbTZvbjYzanE5dkhSQjRBWC9qa3NJM2RhSmdyU1JPQ3VGdHZwMjc1MGloaC9abjBIQ1dKdE5kcmh1eVRNV1Q1ZDR3QUd3N2haeUV5YnMyYlFsRStER3JRMTMvMmdCNmdXdWEyVHcrRjU2MDRhR1BobS9zeDg1MnZ4MFppUHdKbDJBNlVsNncyV2d4YzduenlKRFNWdlJIVlF5K0ZTNEZtMDJZQ09ydTNhaE5VU1NPM1VqQkRXdmkyME5adW9HenJ0cE9FQU1zeDFxcU40NTJER21SNE5hWXFualV2ZjBZVHBvTTcxWkk3cC93eHRPNUNTV3dSclpkY3BLUlRVYzl4N0hrc2NiZ1NSNmhtZlc5RUg0VXV4WWcxRjV1Qndva3FUMER3K2J2cmg3a0VtdEprMlE3VXB0TldLOElUUDJFSjM5NUJEOUZ0c0w4VVl5LzdrZTRJa2R0OWZVT0YvZGh1NTk0RTJ4bUszTjBRblFuUS9MRUo1NEVXdnlwZWdCUmRCUWFkSjlaMURydjRNeDJVQ1pYQ1ZYU3RIYzF6bFBPVVJGamJtMGZxZ21mb2dvdm1jVmZsWitWL3FSSjUxdHhKUWtYZG5zYm1wdUR5eFJIQ1Mxb203QkpLZ1pxYUhwZ0djMWpCci9VYXZqR0d0dWFhTmNTM1cwekZ6UW56TmFXZzdpR1E4Z0FEVyt6TFV2bTI2Wll6K3E1a1I1QXhQWndHalF1cEN3eUlQRlB6ano4N0hxOTloeXlpcVpOcG1CaVpHOHdNbkFsY1RkcmFGY2NPblNDS05jZGUxT2hnTDZJQ1REWk5hOHBIb3Y0MTlxczBEUVoxQzhCMTYvaVpBSUlzMzJla3AzejFSbVhsWmtTd1pEVGdTSDVISUlIODVjTnBoYmhOTlI5dVVKOGJGYWxQazdSNDB6OGUxUkJwVWZBQ2VPUmhVZDlvcHFPU1VaNlFoWmFZeFN6c01lTjRjblFXdjdsbDZvU1RBMDlGdVdmWWlqTHc5T0Jjd2dWbHo0YUU0bnJFV1MzdDNqNmViSW14d3pDV0RxVVk4Vm11U2xUSGJFVHJ3bmQwRVlQR3FiaE5zcGl6d3dMSXJJN1FBdGRSSWwvWVQxSlRnTkpoRjBlK1l0SEdBRFp2UzdSWE9rVE5xbDRRek1OTW1NTWNGYUx5eW9TUGtWVTBydlRTNVkwM2JXb3diV0p6N29ZK0s3dTR3UHk1Tlp0bkMvUmdCUjJTRDlIM2VDaWE5MzU5SWJhK3lOTzBVZStVZlRvd0lmQWl2NU5mSnJLZ1pEVENLUGFFOEdNZkVLZmcyZGh3dUtJdVYrQTYxNkd0Nkp5b3ZuVEs0aFRzYXdBZmZmaEtzNVJDdXlUeE9HMVE1NTFzaFoxeXNpT0tjRGt0K3lSOHRUVFQzUFBtbk1PaVMzNW4zU2xQcnVkT0JZdUIvN1N0SnlDZm1HTWRDT29MellacjdCazhPbEd0UWRYUkpBdGxRemQxcjhlMWJPQ3N2RTVxNVlLSTFvcTVnMHBpck5Xd1FEK0tPYXovV0svZkZWUytMbUJRUE95T091MzFrWlVoNTIybmt6ZlBoMzlDRGdKaVRYbENCa2tjS1ZvNDBpajk0VHdlRityMmczN3p5Zk1iZ21zdmtwU1BoU0tDOHd4MzBxUkR1SUdWTmM5YXB5RlplMW92Q0J0SVpVbG5vY25jZnJrNmQ2bmZENkhqZTBsbk5CaHhDblJ3TnRPd2phTCtDcnJoQS9zUnozSHBZQ1QrZll6cmZESXVlQmJ2UVBYZVZqNVM4VUhoQ2lZVkRUNlZReC9XZHQ5aUMyV3NPeHRVamplWkphLy9xRGNPYm9oQzFBQWNCcy9kV0pYWTErdk9JMnNTd2ExUWhRNTFmZlBQSmZlb3JQeHJlWnU3MDBBbUdZcWprbmdyQ0JTL1BqSlU5L0ttaHo0MEVxaWcwMnhsY3lTc0JRUTV6SkYrcGkxQU9jSkh1M0QrODRGSXFDaDR5S0ZJYmh4cTZzYnJKU2Z6NytVeWI2dU05UXhDYzNRKzhwL1RYQnNlNVFxYlhTQWNnNjlwanpBR0QwNG85Z1dYWFc0THVFTmYwdVc4aldydlZkMXRDNC83UFlHVWxJTXpCTGtDNWMrQk8zUEJmWTJsTHlSUW1JRkNxb003WkVRYWpSUW5jNmJtUnNZMWFBVGxpN29UOU5ZRzNMelUvZkxPdGNJVzA2US9IdGE0aSs4dXBmOXhxdGdaS25vQW5PQktUOFoyTE91V0ZtOG1raitFWVBHRFB6UE83L0hsbnNPSmp6WGN6LzlueDEvOWJxbSt6WDR1alJlcUNVTkRWYVhsSXYxUHI1bFhYTEoxbFVCMlAvNHFVSTBqaGswajROU1lwU3ZKVlprMC81TFJ0ak5YeWxIOFBPbmtIbkxuM0tsSFdwTGJweU9wWkkyTy9FMVAyS2Y2OUpYQjY4UXlMcHp4dWNES2VMc250VFV5WHFxKzJaVHozdzNSbjZXM0RJSmpZdmV1SklVbmIvRkZKL0JGcCtwUWo3ZTd6TnI3QWpENVFEc3BsV1pyblc3cWRPbTZreld1YWVNOVJzTk1PaXptdEFUSC9tQW9DdmZLVkVMb3pxK1FPTUZrUnBBaVEyZHdOcWtlMkFITGxSQ05rOWJvSjVGUHkvcWhRY1VHdkpKOW90OVRlOGgwWURieTgxZ1J1T1FNdEJ6U2s1ai9XRkpES3QveUIyaGUvMkZQYzF5djRkL0pPclFBUnBZWUIzQ3BtcVNGQmpYdWN4RWVXc09PK1dFc0R5eUl5WXBRUjl0UVJzWDFtR1c3OWpNUUgrZVh0MENuYTZJMXhnMmdZYUI3cXJxaEh4U1lSM3NtMmdjZU54Rys5ZjBRZmNvVUhFWmplOEVOZWREcytpWGw5cE5nYzFYaEQ3Z3F6WFptcTVDWnprcGdEVlhKSExyWEFiVlhUQi9hL3krbjROejFYdDI2OXRSVVRITGJIeHZCU3VNUEpRZ0NDTTY3TGZtV2VCWW5OeG5pU2hWSDk2bVlhTVR5RDc1NEdkWWlOWE4rTnJxWXpKNzdZU2o0a09qY3M5b0NHbEVpQVRlSzhRcDZWRkxnTnM0akFjYWN5dUlkMjVCc1dXYWtaQmxJVXBlMWVtenpTdXV6VkhMcytNRlpjSjZXRjErSlplVGFpY2VjYmdkbXRJK1dBdlU2cFpuYlNDZDJSN2ZsZ1hLRVlPU1VJV1Q5NEZmN1FhN2FqeFlqNXJOb2p0TlhGanpNQTdGNVcvNUthWDV2NnA4ZXRGRHg3a2VvZ0dxZXkrWXhsWVRlSUFCWE5CUXdhMDEza3ZFMFRnZlpuenh2Vm9PdDlxM2FTYmlDMEZUc29kK1RLUjZ2ZGZLaUxjZVVnWUJWRyt5R21kVmZ2d2M2QU1KL0RIOWJ4RWRkWXdxbTMxYmZLb2FsbkFVR3l1dlRxNGJpYmFPcjdSQytRYU5pd1Nud01UbFlXRnRVVGxKUk1oMnJQSjc4Z29XLzdpL09OMm9mVktGeHBqSUtFVXhqbnpqMkZtN0s5SFZtb2tHRzEwOFUrVW14ZWtLbjZDMjUwUW8vVXR0dTRoM09XRjNmSmk2UEdXeVhRVUd5K0hXVkpaa29OZFZ4cWlhMDQwUjJiSy9rQWtiT2huQi9ZMGhuQXByNDFncXhBRUpxRFhuSE51My9YTmZ5MFQ3cVhjR0VGM1B3Tllmb3NNRWVDTFZtM25vb0Z2RTJZNW94TmZmbTlmdExySEtZN2JxcXVBSTNyMExQYjZtMnVzaDRtOEFHSWp1Qyt3dmVtRmM3R3dOajZKaitTb0RBa2lDMVBycFQ1ZmxVNlkzMHlQeWlNUEtOQkw0ako1cDRXUkdxWjNnR093T2tEbHovQU40S1BqVUxLUE43S21pNENDOVFGYUoxNi9Pbm1qVHY2aTJaMzh6Y09oQW1hTEY0Q25GTHUxdFFnRHdBYVdQQXFUR2xibE8xYkI3YmkrcTUxNTkwSTJDSTNvc2lycjdrSndnYnZLNXZJeHJtQ1lyUUxQZEdUdkJZbzJVTWEvSUhDSVpsQlNJOUc0WDFZSEp5ckFMbnZUaTdXVzBPQmJyak14Yk02M3lLZWpKamh4dFdnSy8yOFRTUTJlZGhkU05FQzhtUFN2M1JBZk1SaVU2bWVQOTN1M2x4V1VkMFY1b2hMeCttOEFzc3J2c1RrSFloWFVLL1dDb3hYMXBBbmRlNXhNWlhsWG9GZkJNOUhJcFk2dk5ydnNJWTM0eTNXNE81VjRRZUJJTGUwbk1jOXRiRVgzTFJwQjZoS0txNkdlSDkxckQ4OHNKRE5TYXlZOG51aW05ekpiVEppZk91NXlEcjVraUlkdTJMS0QyZ0t3K3FjaWQ1QzFmZkNrYnFTbVlZbElRUkdRZTg4enFWK3RWRjRDdHNPMUJVR1Q3N1VsYzVEVlRsLytmNDdvbW9PUUdnOGh1SGh3ZWlrSmRHQmI5T3U2Z3hWY1VtakZhMEVBY2V2UmFjR1JhRGc4emNHdlpsdEVlamh6bWhYdlZ0OVFDREQrVko2SVZ2dUdBczFPVEhzRjBnNlNHY2poVXdYTXN0OThxRkI0M0tkY1BWNWRhajRLRmNKVWhabGdYTVhXMTZOL3JPY2ZscStnSFpTVis5S3dKYlMvQmROZGtFcXNEQ2VyVDg4cFV1TlRuakdOZDcvMUEzNi8zSGVYUWR0WlZ2SGJld1QySmY2cmcxck9kSUlqUTBsOGw4OWVtYUNBbjJpd3ViVGk3UkZkRHRuY2w1N2VDdDdmNzloYVdNWTJWaTcrQzE5eWdDbUhveno4akU0Szl1WDFWTUFxcE9Zc3M2UWFSeDJiVEkwOXBpbTVEaGw3MmVNZTI0SnZGd0daQTV3N2VEVCt2ckxhTFhESUZ5T2h5ZGM2RXh5c2hZb2pPMHpDUVRxN094c2hvM2MxckZHb2Z2dWNwV3NYWVZoa1ZPVnJta0xhMHVISXhPcDBFY0RyOHlpb2IzazQ2ZXdUY1AwUkZtYzZwWDNEYzZYRXlJclc2U1RPTTJTVmtjOXZvbW1KUmhxYXdVeU1ldTBucEcvNEM0RXNWYjhta2xBOENuSEwwZzRqQ25ySWlKMHYrc1d1Z2JaaE1HNzVZOHAyMnRjdW9EQ2FZeWNjNEVUVkFrTStSb0JRRHJOdlRFb0luYXUvNzVMSXVra1hWczN4Mld2ekpxYkFsVU5rdzYvS050elNTbjBGcExOVnFiZ1p2UE5sMGE5U2NJaU8rdGg3S0gxT256RmV1SGVwMHVaWENNYlVWZ2xyT0k3YWE3VS8xVWZ1UDdKcm9SVmJPdHZlL2pyOUJsU2ZvSGZJRkJaVW84ODZJS1Fpd1A5d0dXclVoRHBsb2lpQ0NWNHh5ajFpMTVVTVo3bStKd1I0RmlLenYxbXQwWWIxRlNxUDB4VDVoYkxSQkFCM0pWQmQzSUVhRnNKOHJyNUYya0JMcG03TitLa0pZeW9qazBTUTZ2ZGpIbzVZNjBhRUZVSkZvczJsMUVwOWRVZFl3cHBtaE1EUTRDVWtYSTBBWGFpRFJKRW1JVDRNL2ZEWnFqWUFacEV1N2ZHVCt2Rzd0Q2IraGxSaFQ2UFJUZ29kb01tOFE4T2lzbTBWdC9zREc1TlVuN0NhNXpUanpjSkdyMm91aGc1Tk8xYmt2cTdKM25HNTdqb1cycjhyQkZ5aGhvWVZkWGtGN0syaW80MFA1emRzNzQwTFI0aTNuQldFdTRScEZRSEthRnNJcVJtQWU1WU1PdUNsWmFVK2lmRDBzc0lMelVBWEczbnlJL3NUcHk3Vml6Nm8vcHY4OXMrc3VzTkIvUURDV21LcWNEb1d3T0FDZDRxa0JtMGpXWWZUN3V4cDUxeDQvaC9aazhtSHVtUDBhbHlrTzB4ZndyRmJOODlqOVRlaHZ5S2FSOE9ZMHBvRGRzdW1mNHE0SE5KMHQ2Sjd4NEk5SmxNdkhIaU93SDhXd2hYVStuZ2REc1pIOEFjQVpYUTZuNnNoNUlETHJmdVluelJyUG9ETHpDd0VwVHo1WWx4cUh0U1ZvY2FRQ0xEYVZNOTNRZ1dJYWF3bjI5cXVzcTBTTFEvQmV2cVUzd2U2MGl5b3ZGQ2FnMjd1UHltZ3d1VUtyUTJDRTdJOHhrZzBSY2ljSUJETEpLUlVVYmFnanhzTmhnRFdOeW5pQjlKbkJkQjZJWU9OZlZsZUgyK3d0OE1JSEtJdWwzNVdYd2tNU3dJLzVjUU5yb0hHb2w1aG00c25KcUxZV1pEcFlzTWdUT29yQ0ZocEdFTU12S2ExWk9QL0ZldjE4SE8rQkdnUDhtQmJJY0pTNTlYaTBrM3pYMmNPazQ4K1BxUkRKME1jQWJDejZ5aE50Zm1hYjVEUTg3SXd0ZEtjTkFvVTlYWG5iNGs4KzRDR1hJRjZlejJOVjhUeFRsWFQxeVhjZUVFWEhxMWtITDNKMkkzbk4xelhnb3JnQXl4SGs5cCtESVBWS1VzQnlJQnZxZ1RtQmh6TncvS0VINVluYjcweEdxeHdsZzdZYmRrSjVyRUs5ZTJidURWN0pNNUw3UXRmYjNNK3VDeDlFTHZMSTRzTitDZXVMTisvS1U0M0VmMzlrUVZFanRWRUFpcUluL1VxM1Fnd29hQ0FzOFJNRjhYVlY5U3pjWU1STVRmOEtLajhaRG5YMVRub2c2ektka0w1Ui9QU0NtRE9ERGw2bnhZWHB6Umd4WVRwWFRrUjQ5WTVUcDRwdUFKaGc3ZnZEbGhTbTVmMzhibzloaDR2czg4dHV3QjFHYWoxWlZzUXVyU0JnQ1orTnNyWWR3RnQwdU5EMHNZVEUydVQvSXpDTkNyRUJ1ckJDU3o4amFZaTFjQTcvWEpDWnh3Rit3aHNwKzhvTjF6YnhYUzRjeUQxakdVU2pxREVRWS9DMFk0dnIrLzVZRVRXTFlzSWJZcWpkdGJHZGljbU1XcjVxdkhpZEt2NkY5VUJ5SkV6NEhncWsrZUhwc3pWVDVrVGcvc1libVNZaVNQV1U1VnY1d1llRUdWeFRkdW1lM2YvdzU5eHg5SVk3ZGxKR1Fab2Y5d2R2NmZTOFpXWDljdXNXYjZtd1Bka0lmeG1LVDB3OUNjS2lDUDNEU2w3RXVCL1h2dHBPWUYwZFN3UUlNdFM2UkpMZUlFeVI2bTgyNUZUSElGbSszOTNSR3JzdHNHRlNYL2xSS3VwSkJCSXlObEhiR0R0NXozMW81ZFJYK1pncmt2OG5vWDFsRW5jRHZTekFGYyt2RGJvQWxFUSs4Tkljd0RNdngwZkN1KzBLRm8yZGd5V2F4UlNHT0F5Nk5yZWo1dnUzQTBuU3BsQ1NHWGFkK1lZc29ZckhpOSs5emdKa0k5ZFg5MzNWRHBvSDQ2NnA0QW4zRlFiS1VBSzlLcllYOCtiOEg4TWd1dHRXWkpwVURQUUhmWWJUSnpzVGhGTDhHVG1RcGZrL3RRNWFMS1FEbUZMSGl6Ynd0WkVhYktEZlBOVU1tbDlqTkZxSElGMVJscndSeThqMDQzM2JLNWNGNmcrY09USGxHQS9DOWZmRUlOTWU5TXdoVHI1S2pkTUQyVnZNN2pwcDNGWmRiRS9YUmpYRWNYWVpTRStOS1FFdUVwMGVDVFg4bmxkUFdTZGFGL0dxdGoyYVBIZ0Mzb2NoL21OZUNIZDNZUkFvVk5vU21ZUGtWL2xDUWlpV3FMVEpDN2QyK2tGNXlPczNsMHAwL0U2Rnp6allabkhrZUk0Zm5jMmVUQ0dsYndtaGRZMDdwM05JclRWaCtUNHd0elNHZ0tzdzhaNDNaRlJrb0dUMzMyR2pxWXowRTYxbmppY0VtZ2o0ZUtZU1hsWnJPMXVwRlpjKys2Q013WVNlblo4YWJ2ZnJMUWZGcnJXWlVTYmJzUmcrS0Y3T1c1Q09xOEpCeEIxbVBZUUxFb252Uk5HbkROK0h2cHhPOWdqdHN2L0V3OGtRVldIeG5Oelo2Y0J6dWtJQWdSTlo5TjBwMW5pU3d4NkpSa1M0U3o4ZWo3SFZ3QmMxZzEzemZWWmFWMWVVdlE4U3pGNHpNNExOVFpkQ0ViWmtXaE5BR29RQ2d5MHZ0b0dTd2FRNWNrS0FGVjRGc1ZGQXFHaWJkZkYyUGZ0RUVYTURlRk83ajg1cDUrSUxGa3pDcWxnd0hSWWJXLzNyc29aYThOOXI5b0VINHZTbEVRa0d4ZVVTVzlVV2hOTGM1TS9BSHU5aXh0aDhiVHVoYXVRamVSaWhKUS92ckVlR1h3Zi92Z1VFMHJmSmJOQm5sbUJzU05rRmZzWjJlazNNdDQ1bGMvZkFQcHVNZkNjelVVOEZiZHJjNVI2L2lZZVp0MzF6ZHU3WGtmaUM2MVZEdjRZYU9oTE8reU5hcUlEVVo2LytrRy9qTEhPWWVtc3p6UUhpWndMNzVHWmkwenozcVhMeGZCaDN3VDg5Nys0S2JjSEZ2ai9yNk8zMzBmZTNFMHlJdVdHRFE1NTFlalNIb2VBd2RaNE1kUGFrRFRZZDlvQVJUN0lSRklBc3lFdCtXSkpsN1dqYkd6TlpmdU94YzJUeWlpTHBic1ppem8yZ2RJVGY3R1VlUTFzdndFQ3oyZHp1Uy9BNGRGb0xrYWsvWkhVZmJ5czRuZXYrSmVQSTIxNndiNjRrd2xTZ3lFZzVycms5bncvdWJGWHlFV0haVXdKaXkyekkxUDZ5d1hWQkY2UU9Yd3Mybnk3VXk3cURLRUJnZVI0dlpzS2RweXkxQzlQWmgwclplVTl6ZkwvMGVKNXlyTzE5YVl3c0lyTTFRa1FFOUFtU0RoTjM2YjVjRGFyU01tUUZyM3gxdm8wNnNvcGNSZHdkZk1Ca09xckhVIiwiY2lwaGVydGV4dF9zaGEyNTYiOiJkOTc3OGM3ZWFmZDE0YTdiMDQyYWNjMjQ3ZGZjZmQ1YzM0MzgzMDIzZTg0OTZkZDVjNTQwNzRhMmE3MDZhOTc1Iiwia2V5IjoicTVpQnVQRG83aW04ZThNZWxpTG16U3ZGOXVDWmE0U2hOd3lZQk1SNFJMMUhDa3R2YjM3VS9YYVdZRko5SHQzM2lYNkREck5hbUEyWjFydHh2dzRzM21tRU9sNmlQb2JGOUdXSjE0SlRET3RYZXdFT1k2b09KOHJZVVlKaXpBVGhSZ29FNkQ1RnBMMk1PUTJnRy9adFpET2pzT3FzSTI0bStCYlBoayt5cm83cG8yL2xJRUpMTzhzRHhXYk5zT1p1RWhpMW5EcU5KMVJZUFpITFVNdHRXRC9Md1RqM1JFbHRZcGVxZHRqTVlvUkxhOWNTTHo2M1ZNbDh1SmRZcE5zVXJhalN0OXAzRFh3UFM3MHF1TmFhckxmMEVlOHFURHhCUVVEaTBrYjk2Y2VaZ2o3Z1MzOTJva2ZXa0dkWS94b3hwQTQwZkxLUXlhSlJZZVFQb2J0L2hFNWVlaW56RmJQd1pETk0wSXpzMXlkZ0krVTZjTW5EaXFBazBQSUtiQm0wUEdUd1dBM1lvcktJVjV3Ui8zeWVLOGMwZ0xpQjkzS2J4SkhDS1FLMWxiMlVQdkJPK2hQQjduYzQ4dFR0aW83MkJNR0RuaUhXWDMxbDZaQUZ3bmJUTlVUQk5VTzdKajN1RHA1SzJHRHJTOWJQL05hRXFZMEtGOWZIRG5IdEV1SEEiLCJub25jZSI6IkEwanBZN1VZSzNyei9FbTAiLCJwbGFpbnRleHRfYnl0ZXMiOjU5ODgsInBsYWludGV4dF9zaGEyNTYiOiIxNjc4M2JjZDE0ZWRjN2Q0NDZhYjQwYjZmZGJmNzdkODQ4NTdhYTg0YTNhMDk0YjExNTNjMDQzYzgwOGFlNTc2IiwicmVjaXBpZW50X3NoYTI1NiI6IjEzN2RhMWU2MjViY2QwMDVlNGRiNjkzMzU3ODFmMDM5NmZiNDQ5OTViOTY2OGUxYjM1MTExZjIzMDc4MTAzODgiLCJzY2hlbWEiOiJob3RlbC1zZXJ2ZXItY29ycmVjdGlvbi1jYXBzdWxlLXYxIiwidGFnIjoiRmozMHg2T2VNZHlvOUZNL2FHTmVGZz09In0=';$raw=base64_decode($encoded,true);
    if(!is_string($raw)||strlen($raw)>16384||hash('sha256',$raw)!==HOTEL_CAPSULE_SHA)hotelFail('correction_capsule_unconfirmed');
    $v=hotelJson($raw);hotelKeys($v,['schema','algorithm','recipient_sha256','aad','plaintext_bytes','plaintext_sha256','key','nonce','tag','ciphertext','ciphertext_sha256']);
    $aad='hotel-server-correction-capsule-v1|'.HOTEL_CORRECTION_SHA.'|'.HOTEL_INPUT_RECIPIENT_SHA;
    if($v['schema']!=='hotel-server-correction-capsule-v1'||$v['algorithm']!=='RSA-OAEP-SHA1+A256GCM'
        ||$v['recipient_sha256']!==HOTEL_INPUT_RECIPIENT_SHA||$v['aad']!==$aad||$v['plaintext_sha256']!==HOTEL_CORRECTION_SHA||$v['plaintext_bytes']!==5988)
        hotelFail('correction_capsule_unconfirmed');
    $d=[];foreach(['key','nonce','tag','ciphertext'] as $k){$d[$k]=is_string($v[$k])?base64_decode($v[$k],true):false;if(!is_string($d[$k]))hotelFail('correction_capsule_unconfirmed');}
    if(strlen($d['key'])!==384||strlen($d['nonce'])!==12||strlen($d['tag'])!==16||strlen($d['ciphertext'])!==5988
        ||hash('sha256',$d['ciphertext'])!==$v['ciphertext_sha256'])hotelFail('correction_capsule_unconfirmed');
    $ingress=$root.'/ingress-v1';hotelDir($ingress);hotelRead($ingress.'/recipient.public.pem',4096,null,HOTEL_INPUT_RECIPIENT_SHA);
    // Existing server ingress key is used in memory only; never returned or logged.
    $private=hotelRead($ingress.'/recipient.private.pem',8192);$handle=openssl_pkey_get_private($private);
    $details=$handle===false?false:openssl_pkey_get_details($handle);
    if(!is_array($details)||hash('sha256',$details['key']??'')!==HOTEL_INPUT_RECIPIENT_SHA)hotelFail('existing_ingress_identity_unconfirmed');
    $key='';if(!openssl_private_decrypt($d['key'],$key,$handle,OPENSSL_PKCS1_OAEP_PADDING)||strlen($key)!==32)hotelFail('correction_decrypt_unconfirmed');
    $plain=openssl_decrypt($d['ciphertext'],'aes-256-gcm',$key,OPENSSL_RAW_DATA,$d['nonce'],$d['tag'],$aad);unset($private,$handle,$details,$key,$d,$v);
    if(!is_string($plain)||strlen($plain)!==5988||hash('sha256',$plain)!==HOTEL_CORRECTION_SHA)hotelFail('correction_decrypt_unconfirmed');
    $correction=hotelJson($plain);if(hotelCanonical($correction)!==$plain)hotelFail('correction_canonical_bytes_unconfirmed');unset($plain);
    hotelKeys($correction,['schema','proposal','review','continuation_admission']);
    $a=$correction['continuation_admission']??null;$b=$a['budget_attestation']??null;
    if($correction['schema']!=='hotel-server-correction-v2'||!is_array($a)||!is_array($b)
        ||hash('sha256',hotelCanonical($a))!==HOTEL_ADMISSION_SHA||hash('sha256',hotelCanonical($b))!==HOTEL_BUDGET_SHA
        ||($b['valid_from']??null)!==HOTEL_VALID_FROM||($b['valid_until']??null)!==HOTEL_VALID_UNTIL
        ||($correction['proposal']['campaign_id']??null)!==HOTEL_CAMPAIGN||($correction['proposal']['stage_index']??null)!==0)
        hotelFail('prepared_correction_identity');
    return $correction;
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
function applyHeld(array $snapshot): void {
    applyOriginals($snapshot);$c=$snapshot['campaign'];
    if($c['state']!=='hold'||hotelInt($c['stage_cursor'])!==0||hotelInt($c['new_accepted'])!==0
        ||$c['baseline_sha256']!==HOTEL_BASELINE_SHA||hotelInt($c['reserved_calls'])!==2||count($snapshot['attempts'])!==2||count($snapshot['outboxes'])!==2
        ||$snapshot['receipts']!==[]||$snapshot['corrections']!==[]||$snapshot['records']!==[]||$snapshot['catalog_outboxes']!==[])hotelFail('original_hold_boundary_required');
}
function applyExpected(PDO $pdo,array $before,array $correction): array {
    $candidate=['schema'=>'hotel-server-candidate-v1','records'=>$before['candidate']['records']];$changed=0;
    foreach($candidate['records'] as &$record)if(preflightScopeKey($record['scope'])===HOTEL_TARGET_SCOPE_KEY){$record['answer']=$correction['proposal']['answer'];$changed++;}unset($record);
    if($changed!==1||hash('sha256',hotelCanonical($candidate))!==HOTEL_CORRECTED_CANDIDATE_SHA)hotelFail('reviewed_corrected_candidate_required');
    $delta=['schema'=>'hotel-server-baseline-v1','parent_sha256'=>HOTEL_BASELINE_SHA,'candidate_sha256'=>HOTEL_CORRECTED_CANDIDATE_SHA,'records'=>$candidate['records']];
    if(hash('sha256',hotelCanonical($delta))!==HOTEL_CORRECTED_BASELINE_SHA)hotelFail('reviewed_corrected_baseline_required');
    $row=preflightOne($pdo,"SELECT CASE WHEN OCTET_LENGTH(JSON_EXTRACT(CONVERT(package_bytes USING utf8mb4),'$.retained_admission'))<=65536 THEN JSON_EXTRACT(CONVERT(package_bytes USING utf8mb4),'$.retained_admission') ELSE NULL END AS retained_admission,JSON_CONTAINS_PATH(CONVERT(package_bytes USING utf8mb4),'one','$.retained_admission') AS has_admission FROM hotel_server_inputs WHERE campaign_id=? AND inputs_sha256=?",[HOTEL_CAMPAIGN,HOTEL_INPUTS_SHA]);
    $retained=null;if(hotelInt($row['has_admission'],1)===1){if(!is_string($row['retained_admission']))hotelFail('retained_admission_bound');if($row['retained_admission']!=='null')$retained=hotelJson($row['retained_admission']);}
    $pins=preflightAttemptPins();$prepared=['candidate_sha256'=>HOTEL_CORRECTED_CANDIDATE_SHA,'correction_sha256'=>HOTEL_CORRECTION_SHA,
        'correction_review_sha256'=>hash('sha256',hotelCanonical($correction['review']))];
    $receipt=HotelServerCorrection::receipt(['campaign_id'=>HOTEL_CAMPAIGN,'baseline_sha256'=>HOTEL_BASELINE_SHA,'inputs_sha256'=>HOTEL_INPUTS_SHA],0,
        $prepared,['candidate_sha256'=>HOTEL_CANDIDATE_SHA],$pins['producer'],$pins['reviewer'],HOTEL_CORRECTED_BASELINE_SHA,$retained);
    $hashes=[];foreach($candidate['records'] as $r)$hashes[preflightScopeKey($r['scope'])]=hash('sha256',hotelCanonical($r));ksort($hashes);
    return ['receipt_sha256'=>hash('sha256',hotelCanonical($receipt)),'receipt'=>$receipt,'record_hashes'=>$hashes,
        'candidate_sha256'=>HOTEL_CORRECTED_CANDIDATE_SHA,'baseline_sha256'=>HOTEL_CORRECTED_BASELINE_SHA];
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
function applyObserved(PDO $pdo): array {
    if($pdo->inTransaction())hotelFail('foreign_transaction_unconfirmed');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try{return applySnapshot($pdo);}finally{if($pdo->inTransaction())$pdo->rollBack();}
}
function applyCheckpoint(string $name,array $payload): string {
    global $work;
    $raw=json_encode(hotelSeal($payload),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);hotelWriteNew($work.'/'.$name,$raw);return hash('sha256',$raw);
}

function applyFinish(): int {
    global $pdo,$lock,$target,$outputDone,$ownedWork,$work,$app,$mask,$level,$report,$expected,$runtimeNext;
    if($outputDone)return 1;$outputDone=true;
    if($pdo instanceof PDO&&$pdo->inTransaction())try{$pdo->rollBack();}catch(Throwable $e){$report['section_errors']['rollback']=applyReason($e);}
    if($target&&$pdo instanceof PDO){
        try{
            $after=applyObserved($pdo);$report['after']['database']=$after;
            if($expected!==null&&$report['database_mutation_started']){
                try{
                    applyCommitted($after,$expected);$report['correction_committed']=true;$report['mutation_outcome']='committed_readback_confirmed';
                    $report['continuation']=['operator_provider_calls'=>0,'driver'=>'existing_cron_only','state'=>$after['campaign']['state'],
                        'stage_index'=>hotelInt($after['campaign']['stage_cursor']),'new_accepted'=>hotelInt($after['campaign']['new_accepted']),
                        'reserved_calls'=>hotelInt($after['campaign']['reserved_calls']),
                        'unknown_attempts'=>count(array_filter($after['attempts'],static fn(array $r):bool=>$r['state']==='unknown')),
                        'automatic_retry_allowed'=>false];
                }catch(Throwable $e){
                    $report['section_errors']['commit_readback']=applyReason($e);
                    if(!$report['correction_committed']){
                        try{applyHeld($after);$report['mutation_outcome']='correction_not_applied_original_hold_confirmed';}
                        catch(Throwable $ignored){$report['mutation_outcome']='unconfirmed_no_retry';}
                    }
                }
            }
        }catch(Throwable $e){$report['section_errors']['after_database']=applyReason($e);if($report['database_mutation_started']&&!$report['correction_committed'])$report['mutation_outcome']='unconfirmed_no_retry';}
    }
    if($target&&is_string($app)){
        try{
            $runtime=applyRuntime($app);$report['after']['runtime']=$runtime;$report['runtime_config_changed']=$runtime['sha256']!==HOTEL_RUNTIME_SHA;
            $report['runtime_after_matches_prepared']=$runtimeNext!==null&&$runtime['sha256']===$runtimeNext['sha256'];
        }catch(Throwable $e){
            $report['section_errors']['after_runtime']=applyReason($e);
            try{$raw=hotelRead(dirname($app).'/max-search-bot-hotel-server/runtime.json',65536);$report['after']['runtime_opaque']=['sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw)];}
            catch(Throwable $ignored){}
        }
    }
    $clean=$report['correction_committed']&&$report['mutation_outcome']==='committed_readback_confirmed'
        &&($report['runtime_after_matches_prepared']??false)&&!isset($report['reason'])&&$report['section_errors']===[];
    if($clean)$report['state']='correction_committed';
    elseif($report['correction_committed'])$report['state']='CORRECTION_COMMITTED_HOLD';
    elseif($report['database_mutation_started']&&$report['mutation_outcome']==='unconfirmed_no_retry')$report['state']='MUTATION_OUTCOME_UNCONFIRMED';
    elseif($report['runtime_switch_started'])$report['state']='RUNTIME_SWITCHED_CORRECTION_HOLD';
    else $report['state']='HOLD';
    $report['finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $report['resume_or_replay_authorized']=false;
    try{
        $envelope=!$target?['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0]:hotelSeal($report);
        if($target&&$ownedWork){
            try{hotelWriteNew($work.'/result.sealed.json',json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
            catch(Throwable $e){$clean=false;$report['section_errors']['result_persistence']=applyReason($e);$report['state']=$report['correction_committed']?'CORRECTION_COMMITTED_HOLD':'HOLD';$envelope=hotelSeal($report);}
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
    applyFinish();
});

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)hotelFail('target_unconfirmed');
    $target=true;
    if(!preg_match('/\A[a-f0-9]{40}\z/D',HOTEL_EXPECTED_INSTALLED_SHA)||!preg_match('/\A[a-f0-9]{64}\z/D',HOTEL_OPERATION)
        ||!preg_match('/\Ahttps:\/\/github\.com\/pyatkoff\/max-search-bot\/issues\/1122#issuecomment-[1-9][0-9]*\z/D',HOTEL_PUBLICATION_ADMISSION_REF))hotelFail('unpublished_operator');
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $identity=['schema'=>HOTEL_STATUS_KIND,'campaign_id'=>HOTEL_CAMPAIGN,'correction_sha256'=>HOTEL_CORRECTION_SHA,
        'admission_sha256'=>HOTEL_ADMISSION_SHA,'installed_sha'=>HOTEL_EXPECTED_INSTALLED_SHA,'runtime_before_sha256'=>HOTEL_RUNTIME_SHA,
        'publication_admission_ref'=>HOTEL_PUBLICATION_ADMISSION_REF];
    if(hash('sha256',hotelCanonical($identity))!==HOTEL_OPERATION)hotelFail('correction_operation_identity');
    $report['github_run_id']=$run;$report['started_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    applySources($app);applyWindow();$report['installed_sha']=HOTEL_EXPECTED_INSTALLED_SHA;hotelSeal(['state'=>'preflight','operator_provider_calls'=>0]);
    $root=dirname($app).'/max-search-bot-hotel-server';hotelDir($root);hotelDir($root.'/ingress-v1');$mask=umask(0077);
    $work=$root.'/correction-v2-'.HOTEL_CAMPAIGN.'-stage0';
    $lockPath=$root.'/ingress-v1/channel.lock';hotelRead($lockPath,1,0);$lock=@fopen($lockPath,'rb');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))hotelFail('active_private_operation');
    $opened=fstat($lock);clearstatcache(true,$lockPath);$actual=lstat($lockPath);
    if(!is_array($actual)||$opened['dev']!==$actual['dev']||$opened['ino']!==$actual['ino'])hotelFail('private_admin_lock_changed');
    if(file_exists($work)||is_link($work))hotelFail('prior_operation_requires_readback');
    $report['phase']='original_runtime';$runtime=preflightRuntime($app);$correction=applyCorrection($root);
    $oldPolicy=$correction['continuation_admission']['previous_policy'];$newPolicy=$correction['continuation_admission']['policy'];
    if(hotelCanonical($runtime['policy'])!==hotelCanonical($oldPolicy))hotelFail('previous_policy_unconfirmed');
    $expectedPolicy=$oldPolicy;$expectedPolicy['valid_until']=HOTEL_VALID_UNTIL;$expectedPolicy['budget_attestation_sha256']=HOTEL_BUDGET_SHA;
    if(hotelCanonical($newPolicy)!==hotelCanonical($expectedPolicy))hotelFail('only_two_policy_fields_admitted');
    $oldRuntime=applyRuntime($app,HOTEL_RUNTIME_SHA,$oldPolicy);$oldBytes=base64_decode($oldRuntime['exact_bytes_base64'],true);
    if(!is_string($oldBytes)||hotelCanonical($oldRuntime['document'])!==$oldBytes)hotelFail('original_runtime_canonical_bytes_required');
    $nextDocument=$oldRuntime['document'];$nextDocument['policy']=$newPolicy;$nextBytes=hotelCanonical($nextDocument);
    $runtimeNext=['sha256'=>hash('sha256',$nextBytes),'bytes'=>strlen($nextBytes),'document'=>$nextDocument];$report['runtime_before']=$oldRuntime;
    $report['runtime_prepared']=$runtimeNext;
    require_once $app.'/config.php';require_once $app.'/services/ConversationDb.php';require_once $app.'/services/HotelServerCycle.php';
    if(!method_exists(HotelServerCycle::class,'correctRejectedStage')||!method_exists(HotelServerCorrection::class,'receipt'))hotelFail('canonical_correction_api_unavailable');
    $pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
        ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))hotelFail('application_database_unconfirmed');
    $lane=new HotelServerLane($pdo);$cycle=new HotelServerCycle($pdo,$lane,null);
    $report['phase']='bounded_original_preflight';$report['before']=[];
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try{
        preflightRead($pdo,$runtime,$report['before']);
        if(($report['before']['single_target_scope_rejected']??false)!==true)hotelFail('single_original_rejected_scope_required');
        $report['before_snapshot']=applySnapshot($pdo);applyHeld($report['before_snapshot']);$expected=applyExpected($pdo,$report['before'],$correction);
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
    $report['before']['read_only_snapshot_rolled_back']=true;$report['expected_correction']=$expected;
    applySources($app);applyWindow();applyRuntime($app,HOTEL_RUNTIME_SHA,$oldPolicy);
    // A new private operation directory is the existing once-only intent guard,
    // not a second queue/worker. Its existence never proves that effects did not happen.
    if(file_exists($work)||is_link($work)||!mkdir($work,0700))hotelFail('prior_operation_requires_readback');$ownedWork=true;hotelDir($work);
    $intent=hotelCanonical($identity+['operation_id'=>HOTEL_OPERATION,'github_run_id'=>$run,'started_at_utc'=>$report['started_at_utc'],
        'expected_runtime_after_sha256'=>$runtimeNext['sha256'],'expected_receipt_sha256'=>$expected['receipt_sha256'],
        'original_reservations'=>['calls'=>2,'tokens'=>2104400,'micros'=>22000000],'valid_until'=>HOTEL_VALID_UNTIL,'automatic_retry_allowed'=>false]);
    hotelWriteNew($work.'/intent.json',$intent);$intentSha=hash('sha256',$intent);$report['intent_sha256']=$intentSha;
    hotelWriteNew($work.'/runtime.before.json',$oldBytes);$report['phase']='preflight_verified';
    $report['preflight_sealed_sha256']=applyCheckpoint('preflight.sealed.json',$report);
    $staged=$work.'/runtime.next.part';hotelWriteNew($staged,$nextBytes);
    hotelWriteNew($work.'/runtime-switch.intent.json',hotelCanonical(['operation_id'=>HOTEL_OPERATION,'expected_old_sha256'=>HOTEL_RUNTIME_SHA,
        'new_sha256'=>$runtimeNext['sha256'],'expected_campaign_state'=>'hold','expected_stage_index'=>0,'expected_reserved_calls'=>2]));
    $report['phase']='runtime_switch';
    $lane->own(HOTEL_CAMPAIGN,static function()use($pdo,$app,$root,$work,$staged,$intentSha,$oldPolicy,$newPolicy,$runtimeNext,&$report):array{
        // The same HOTEL lock used by cron/correction proves HOLD throughout the file CAS.
        $locked=applySnapshot($pdo);applyHeld($locked);applySources($app);applyWindow();
        hotelRead($work.'/intent.json',65536,null,$intentSha);hotelRead($work.'/runtime.before.json',65536,null,HOTEL_RUNTIME_SHA);
        applyRuntime($app,HOTEL_RUNTIME_SHA,$oldPolicy);hotelRead($staged,65536,null,$runtimeNext['sha256']);
        $report['runtime_cas_boundary_sha256']=hash('sha256',hotelCanonical($locked));$report['runtime_switch_started']=true;
        if(!rename($staged,$root.'/runtime.json'))hotelFail('runtime_switch_unconfirmed');
        $report['runtime_after_switch']=applyRuntime($app,$runtimeNext['sha256'],$newPolicy);
        $report['runtime_file_installed']=true;$report['runtime_config_changed']=true;return [];
    });
    hotelWriteNew($work.'/runtime-switch.readback.json',hotelCanonical(['operation_id'=>HOTEL_OPERATION,'runtime_sha256'=>$runtimeNext['sha256'],
        'campaign_state_at_switch'=>'hold','stage_index_at_switch'=>0,'reserved_calls_at_switch'=>2]));
    $report['phase']='correction_invocation';
    hotelWriteNew($work.'/correction.intent.json',hotelCanonical(['operation_id'=>HOTEL_OPERATION,'github_run_id'=>$run,'correction_sha256'=>HOTEL_CORRECTION_SHA,
        'runtime_sha256'=>$runtimeNext['sha256'],'state'=>'single_invocation_starting','automatic_retry_allowed'=>false]));
    $authorized=static function()use($app,$work,$intentSha,$newPolicy,$runtimeNext,$correction):bool{
        applySources($app);applyWindow();hotelRead($work.'/intent.json',65536,null,$intentSha);applyRuntime($app,$runtimeNext['sha256'],$newPolicy);
        return hash('sha256',HotelServerInputs::json($correction))===HOTEL_CORRECTION_SHA;
    };
    $report['database_mutation_started']=true;$report['mutation_outcome']='unconfirmed_no_retry';
    $result=$cycle->correctRejectedStage(HOTEL_CAMPAIGN,$correction,$authorized);
    $report['correction_result']=$result;
    if(($result['state']??null)!=='ready'||($result['stage_index']??null)!==1||($result['new_accepted']??null)!==1
        ||($result['bootstrap_sha256']??null)!==HOTEL_BOOTSTRAP_SHA||($result['baseline_sha256']??null)!==HOTEL_CORRECTED_BASELINE_SHA
        ||($result['correction_sha256']??null)!==HOTEL_CORRECTION_SHA||($result['receipt_sha256']??null)!==$expected['receipt_sha256']
        ||($result['already_applied']??null)!==false)hotelFail('correction_original_outcome_required');
    $report['correction_committed']=true;$report['mutation_outcome']='committed_api_result';$report['committed_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    // Preserve the definitive commit before any later read/cron observation can fail.
    $report['phase']='correction_committed';$report['commit_sealed_sha256']=applyCheckpoint('correction-result.sealed.json',$report);
    applySources($app);applyRuntime($app,$runtimeNext['sha256'],$newPolicy);$report['phase']='final_readback';
}catch(Throwable $e){
    $report['reason']=applyReason($e);$report['error_class']=get_class($e);$report['error_message_sha256']=hash('sha256',$e->getMessage());
    $report['exception_phase']=$report['phase'];
}
exit(applyFinish());
