<?php
declare(strict_types=1);
/* One bounded B07 stage0 correction input preflight through the existing channel.
 * Reads only exact original candidate/two result bytes and their current immutable
 * bindings. No previous campaign, outcome replay, provider object, tick, correction,
 * admission, enable, lock, schema initialization or persistent write.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='17400f819817593b3b4428b43a8dbe24b109c428';
const HOTEL_OPERATION='90b00f80c62dccb911eb4a0082c634685f2bb55155d17a10a531783cd9fbf9f8';
const HOTEL_STATUS_KIND='hotel-server-private-incident-v1';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_CAMPAIGN='HOTEL-SERVER-20261103-B07';
const HOTEL_BOOTSTRAP_SHA='4577961cf5ab96a62df821cfc4f1d9a80b373ae07da10e3f143d7b22adeac6ed';
const HOTEL_INPUTS_SHA='b0f093ff27ad6de311fb6eb351f194ba1b413483d4a7a336ae9aa13986f69448';
const HOTEL_BASELINE_SHA='d0f83be35829d178473ecb4b0d5f4397786256f4a7df5c1bf691085f7d096bec';
const HOTEL_PLAN_SHA='714315a5588f8eef7f4932ed06abf7d25107bd2d58b2799ac5a198c87d536134';
const HOTEL_CRITERIA_SHA='bd386c46d35b2d97411c8cd5a5e8b7305ce4a3022b2b592c301bfcd2009630d7';
const HOTEL_CANDIDATE_SHA='44596fb4cc60d4ea40635b3b44ce8f2f3cd54d0152d20827d8d8bec7361c7034';
const HOTEL_RUNTIME_SHA='1e4abef0c4880bb1b28dfef1217938f630d5de974d44264679226df0662a2111';
const HOTEL_POLICY_SHA='c7cc48331a83249d700cac31e2804897d35bcdd0366bdb767dd150787ef8dcbb';
const HOTEL_PROFILE_SHA='92ae1de467574b17a77d188b2f9766d139e5ada622126a20d2fe2052f5506323';
const HOTEL_PROVIDER_REVISION='ac6a2624c93677a9d490bc6d7b72eaf878daf1b47694df27635218330aa41aa0';
const HOTEL_PROVIDER_SOURCE_SHA='555f21004d85d6a660633b643d0eed961fea382d60da120218e4572c1cf7f6dd';
const HOTEL_PRODUCER_INSTRUCTIONS_SHA='c116cda921261c22f82c9547cb6e3ba865bd3b7b1bc34b14bcf290291518800b';
const HOTEL_REVIEWER_INSTRUCTIONS_SHA='43d17a643e4f9da7adf06d0b312602683c014dc37aa2d701e2606b82d4265d5a';
const HOTEL_CONNECTION_SOURCE_SHA='8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1';
const HOTEL_TARGET_SCOPE_KEY='439821589c274dc02f15575956ab6b77d9b16fa14e205fd4aa3c8ac5c499c1d3';
const HOTEL_TARGET_CONTEXT_SHA='aeeaf8a6f12b5c483bda2f6dde509bd921cdb7c990827509870013df8282497a';
const HOTEL_PLAIN_LIMIT=524288;
const HOTEL_WIRE_LIMIT=786432;
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$pdo=null;$target=false;$outputDone=false;$emergencyMemory=str_repeat('x',2097152);
$report=['schema'=>HOTEL_STATUS_KIND,'observation_kind'=>'b07_stage0_correction_preflight',
    'operation_id'=>HOTEL_OPERATION,'campaign_id'=>HOTEL_CAMPAIGN,'stage_index'=>0,
    'state'=>'HOLD','phase'=>'target_check','operator_provider_calls'=>0,'database_writes'=>0,'server_file_writes'=>0,
    'correction_applied'=>false,'admission_created'=>false,'automatic_resume_authorized'=>false,
    'previous_campaign_read'=>false,'original_handoff_replayed'=>false,'section_errors'=>[]];

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

function hotelReason(Throwable $e): string {
    $message=$e->getMessage();
    return $e instanceof DomainException&&preg_match('/\A[a-z][a-z0-9_]{1,100}\z/D',$message)
        ?$message:'observation_read_unconfirmed';
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

function hotelFinish(): int {
    global $pdo,$target,$outputDone,$emergencyMemory,$report,$level;
    if($outputDone)return 1;
    $outputDone=true;$emergencyMemory=null;
    try{
        if($pdo instanceof PDO&&$pdo->inTransaction()){
            $pdo->rollBack();if(isset($report['database']))$report['database']['read_only_snapshot_rolled_back']=true;
        }
    }catch(Throwable $ignored){$report['state']='HOLD';$report['reason']='read_only_rollback_unconfirmed';}
    while(ob_get_level()>$level){if(!@ob_end_clean())break;}
    try{
        $envelope=!$target?['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0]:hotelSeal($report);
    }catch(Throwable $ignored){$envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'sealed_readback_result_unconfirmed','operator_provider_calls'=>0];}
    echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    return $report['state']==='observed'&&$envelope['state']==='sealed'?0:1;
}

register_shutdown_function(static function():void {
    global $outputDone,$emergencyMemory,$report;
    if($outputDone)return;
    $emergencyMemory=null;$last=error_get_last();
    $report['state']='HOLD';$report['reason']='observation_interrupted_before_completion';
    if(is_array($last)&&in_array($last['type']??null,[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR],true)){
        $report['reason']='observation_fatal_error';$report['observation_fatal_error_type']=$last['type'];
    }
    // Prior captured file bytes remain in $report. Never print PHP's message/path.
    hotelFinish();
});


try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)hotelFail('target_unconfirmed');
    $target=true;
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run)||in_array($run,['41666161566','41823005923'],true))hotelFail('new_correction_preflight_invocation_required');
    $identity=hotelJson('{"campaign_id":"HOTEL-SERVER-20261103-B07","candidate_sha256":"44596fb4cc60d4ea40635b3b44ce8f2f3cd54d0152d20827d8d8bec7361c7034","installed_sha":"17400f819817593b3b4428b43a8dbe24b109c428","observation_kind":"b07_stage0_correction_preflight","producer_result_sha256":"4b830d18e0ce34d0bb09f7930ba8d5f9428162170c222f1fc2095109b12156be","review_result_sha256":"ea730f5f878d3968989a0830bcc08207f7d94aefd88cbb3e769beef96ac14b8f","schema":"hotel-server-private-incident-v1","stage_index":0}');
    if(hash('sha256',hotelCanonical($identity))!==HOTEL_OPERATION)hotelFail('preflight_operation_identity');
    $report['github_run_id']=$run;$report['installed_sha']=hotelInstalled($app);hotelSeal(['state'=>'preflight','operator_provider_calls'=>0]);
    $report['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z');$report['phase']='original_policy';$runtime=preflightRuntime($app);$report['runtime']=$runtime;
    $report['phase']='database_connection';$connectionFile=$app.'/services/ConversationDb.php';
    if(is_link($connectionFile)||realpath($connectionFile)!==$connectionFile||hash_file('sha256',$connectionFile)!==HOTEL_CONNECTION_SOURCE_SHA)hotelFail('read_only_connection_source_unconfirmed');
    require_once $app.'/config.php';require_once $connectionFile;$pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))hotelFail('application_database_unconfirmed');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    if(!$pdo->inTransaction())hotelFail('read_only_snapshot_unconfirmed');
    $report['phase']='correction_inputs';$report['database']=[];preflightRead($pdo,$runtime,$report['database']);
    $pdo->rollBack();$report['database']['read_only_snapshot_rolled_back']=true;
    $report['phase']='final_pins';$again=preflightRuntime($app);if(hotelCanonical($again)!==hotelCanonical($runtime))hotelFail('policy_changed_during_preflight');hotelInstalled($app);
    $report['state']='observed';$report['snapshot_finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $report['correction_authorized_or_applied_by_this_read']=false;$report['next_action']='independently_review_exact_correction_and_immutable_admission';
}catch(Throwable $e){
    $report['state']='HOLD';$report['reason']=hotelReason($e);
    if($e instanceof PDOException){
        $report['reason']='correction_preflight_database_read_failed';$info=$e->errorInfo;
        if(is_array($info)&&is_string($info[0]??null)&&preg_match('/\A[A-Z0-9]{5}\z/D',$info[0]))$report['database_sqlstate']=$info[0];
        if(is_array($info)&&is_int($info[1]??null))$report['database_driver_code']=$info[1];
    }
}
exit(hotelFinish());
