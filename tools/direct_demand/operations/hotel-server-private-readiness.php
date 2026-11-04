<?php
declare(strict_types=1);
/*
 * One bounded observation revision of the existing private stdin incident reader.
 * Preserve the original B07 sealed bytes or named immutable phases, plus current
 * B06/B07 metadata in one READ ONLY snapshot. Never execute the original operator.
 * No provider/tick/replay, locks, file/database writes, schema initialization or
 * automatic reconciliation. Envelope structure is not decrypted outcome proof.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='17400f819817593b3b4428b43a8dbe24b109c428';
const HOTEL_OPERATION='f015a45bc062c4fabbfbea5948b8984449f64aeeac90c11f61a520bf6bafbf3f';
const HOTEL_STATUS_KIND='hotel-server-private-incident-v1';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
const HOTEL_ORIGINAL_OPERATION='5a8ea8b6732af3d32a5e9c126c65a8d58a595af587306e49590669f2e4d99022';
const HOTEL_ORIGINAL_INSTALLED_SHA='0c3d826bc91e534c2232136df9ab9cd318d089f6';
const HOTEL_ORIGINAL_RUN_ID='41666161566';
const HOTEL_ORIGINAL_JOB_ID='126021928968';
const HOTEL_ORIGINAL_KIND='hotel-server-private-forward-handoff-v2';
const HOTEL_CAMPAIGN='HOTEL-SERVER-20261103-B07';
const HOTEL_PREDECESSOR='HOTEL-SERVER-20261103-B06';
const HOTEL_CONNECTION_SOURCE_SHA='8a3c8ef5625aaa24294ba5b9799613920a51a0355c431d0d629662b887652fe1';
const HOTEL_PLAIN_LIMIT=524288;
const HOTEL_WIRE_LIMIT=786432;
const HOTEL_CAPTURE_TOTAL_LIMIT=262144;
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');
$pdo=null;$target=false;$outputDone=false;$emergencyMemory=str_repeat('x',2097152);
$report=['schema'=>HOTEL_STATUS_KIND,'observation_kind'=>'original_b07_files_and_metadata',
    'operation_id'=>HOTEL_OPERATION,'campaign_operation'=>HOTEL_ORIGINAL_OPERATION,
    'campaign_id'=>HOTEL_CAMPAIGN,'predecessor_id'=>HOTEL_PREDECESSOR,
    'original_installed_sha'=>HOTEL_ORIGINAL_INSTALLED_SHA,
    'original_run_id'=>HOTEL_ORIGINAL_RUN_ID,'original_job_id'=>HOTEL_ORIGINAL_JOB_ID,'original_run_attempt'=>1,
    'state'=>'HOLD','phase'=>'target_check','operator_provider_calls'=>0,'database_writes'=>0,'server_file_writes'=>0,
    'automatic_resume_authorized'=>false,'original_outcome_confirmed'=>false,
    'snapshot_is_original_failed_invocation'=>false,'intent_is_no_effects_proof'=>false,
    'queries_return_facts_requests_or_transcripts'=>false,'section_errors'=>[]];

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
function hotelCampaign(mixed $id): string {
    if(!is_string($id)||!in_array($id,[HOTEL_PREDECESSOR,HOTEL_CAMPAIGN],true))hotelFail('foreign_campaign_metadata');
    return $id;
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


function hotelOriginalEnvelope(string $raw): array {
    $v=hotelJson($raw);
    hotelKeys($v,['operation','state','operator_provider_calls','algorithm','recipient_sha256','aad','key','nonce','tag','ciphertext','ciphertext_sha256']);
    // Original writer emitted precisely this JSON representation, without a newline.
    // Exact round-trip rejects duplicate keys and altered JSON representations.
    if(json_encode($v,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)!==$raw
        ||$v['operation']!==HOTEL_ORIGINAL_KIND||$v['state']!=='sealed'||$v['operator_provider_calls']!==0
        ||$v['algorithm']!=='RSA-OAEP-SHA1+A256GCM'||$v['recipient_sha256']!==HOTEL_RECEIPT_RECIPIENT_SHA
        ||$v['aad']!==HOTEL_ORIGINAL_KIND.'|'.HOTEL_ORIGINAL_INSTALLED_SHA.'|'.HOTEL_ORIGINAL_OPERATION)
        hotelFail('original_envelope_binding_unconfirmed');
    $parts=[];
    foreach(['key','nonce','tag','ciphertext'] as $key){
        if(!is_string($v[$key]))hotelFail('original_envelope_encoding_unconfirmed');
        $decoded=base64_decode($v[$key],true);
        if(!is_string($decoded)||base64_encode($decoded)!==$v[$key])hotelFail('original_envelope_encoding_unconfirmed');
        $parts[$key]=$decoded;
    }
    if(strlen($parts['key'])!==384||strlen($parts['nonce'])!==12||strlen($parts['tag'])!==16
        ||strlen($parts['ciphertext'])<1||strlen($parts['ciphertext'])>65536
        ||!hash_equals(hotelHash($v['ciphertext_sha256']),hash('sha256',$parts['ciphertext'])))
        hotelFail('original_envelope_bounds_or_hash_unconfirmed');
    return ['shape_and_public_binding_confirmed'=>true,'cryptographically_authenticated'=>false,
        'plaintext_not_read'=>true,'original_run_binding_requires_decryption'=>true,
        'ciphertext_sha256'=>$v['ciphertext_sha256'],'ciphertext_bytes'=>strlen($parts['ciphertext'])];
}
function hotelCapture(string $directory,string $name,int $limit,int &$total): array {
    $path=$directory.'/'.$name;clearstatcache(true,$path);$st=@lstat($path);
    if($st===false)return ['capture'=>'not_present_or_stat_unavailable','absence_is_no_effects_proof'=>false];
    $size=is_array($st)&&isset($st['size'])&&is_int($st['size'])&&$st['size']>=0?$st['size']:null;
    if($size===null)return ['capture'=>'unconfirmed','reason'=>'file_stat_unconfirmed'];
    if($size>$limit||$size>HOTEL_CAPTURE_TOTAL_LIMIT-$total)
        return ['capture'=>'omitted_by_bound','bytes_at_stat'=>$size,'per_file_limit'=>$limit,'exact_bytes_preserved'=>false];
    try{
        $raw=hotelRead($path,$limit,$size);$total+=strlen($raw);
        return ['capture'=>'captured','bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw),
            'exact_bytes_base64'=>base64_encode($raw),'exact_bytes_preserved'=>true];
    }catch(Throwable $e){
        return ['capture'=>'unconfirmed','bytes_at_stat'=>$size,'reason'=>hotelReason($e)];
    }
}
function hotelOriginalFiles(string $root): array {
    $out=['scope'=>'original_b07_directory_only','original_result_authenticated'=>false,
        'phase_presence_is_mutation_proof'=>false,'intent_is_no_effects_proof'=>false,
        'total_capture_limit'=>HOTEL_CAPTURE_TOTAL_LIMIT,'captured_bytes'=>0,'files'=>[]];
    hotelDir($root);$dir=$root.'/handoff-v2-'.HOTEL_CAMPAIGN;
    clearstatcache(true,$dir);
    if(@lstat($dir)===false){$out['directory']='not_present_or_stat_unavailable';return $out;}
    hotelDir($dir);$out['directory']='confirmed_private_directory';
    $total=0;$file=hotelCapture($dir,'result.sealed.json',98304,$total);
    $out['files']['result.sealed.json']=$file;$valid=false;
    if(($file['capture']??null)==='captured'){
        try{
            $out['result_envelope']=hotelOriginalEnvelope(base64_decode($file['exact_bytes_base64'],true));$valid=true;
        }catch(Throwable $e){$out['result_envelope']=['shape_and_public_binding_confirmed'=>false,'reason'=>hotelReason($e)];}
    }
    if(!$valid){
        // Existing immutable files only. No directory enumeration, runtime.next.part,
        // provider payload, request, public-payload, accepted baseline or input corpus.
        $files=['preflight.sealed.json'=>98304,'launch-manifest.json'=>16384,'resolved-boundary.json'=>65536,
            'intent.json'=>16384,'bootstrap-next.intent.json'=>16384,'bootstrap-next.readback.json'=>32768,
            'runtime-switch.intent.json'=>8192,'runtime-switch.readback.json'=>8192,
            'enable.intent.json'=>8192,'enable.readback.json'=>32768,'startup-observation.json'=>98304];
        foreach($files as $name=>$limit){
            $item=hotelCapture($dir,$name,$limit,$total);$out['files'][$name]=$item;
            if(($item['capture']??null)!=='captured'||str_ends_with($name,'.sealed.json'))continue;
            try{
                $v=hotelJson(base64_decode($item['exact_bytes_base64'],true));$codes=[];
                foreach(['state','phase','reason','observation_error'] as $key){
                    $value=$v[$key]??null;
                    if(is_string($value)&&preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$value))$codes[$key]=$value;
                }
                if($codes)$out['files'][$name]['stored_code_fields']=$codes;
            }catch(Throwable $ignored){}
        }
    }
    $out['phase_files_requested']=!$valid;$out['captured_bytes']=$total;
    $out['original_outcome_requires_independent_review']=true;
    return $out;
}
function hotelRuntime(string $root): array {
    hotelDir($root);$bytes=hotelRead($root.'/runtime.json',65536);$runtime=hotelJson($bytes);
    hotelKeys($runtime,['bootstrap_sha256','campaign_id','flags','policy','schema']);
    if(($runtime['schema']??null)!=='hotel-server-runtime-v1'||!is_array($runtime['flags']??null)
        ||!is_array($runtime['policy']??null))hotelFail('runtime_shape_unconfirmed');
    hotelKeys($runtime['flags'],['dispatch_allowed','enabled','replacement_dispatch_allowed']);
    foreach($runtime['flags'] as $flag)if(!is_bool($flag))hotelFail('runtime_flags_unconfirmed');
    $campaign=hotelCampaign($runtime['campaign_id']??null);
    return ['available'=>true,'configuration_sha256'=>hash('sha256',$bytes),'configuration_bytes'=>strlen($bytes),
        'campaign_id'=>$campaign,'bootstrap_sha256'=>hotelHash($runtime['bootstrap_sha256']??null),'flags'=>$runtime['flags'],
        'configuration_is_database_enable_proof'=>false];
}
function hotelSavedHealth(string $app): array {
    $dir=dirname($app).'/max-search-bot-private-logs';hotelDir($dir);
    $bytes=hotelRead($dir.'/hotel_work_health.json',8192);$v=hotelJson($bytes);
    $states=['running','ok','awaiting_connection','failed'];
    $stages=['bootstrap','subscription','delivery','server_cycle','server_cycle_hold','complete'];
    if(!in_array($v['state']??null,$states,true)||!in_array($v['stage']??null,$stages,true))hotelFail('health_shape_unconfirmed');
    $out=['available'=>true,'saved_file_sha256'=>hash('sha256',$bytes),'saved_file_bytes'=>strlen($bytes),
        'state'=>$v['state'],'stage'=>$v['stage'],'trigger'=>in_array($v['trigger']??null,['cron','manual'],true)?$v['trigger']:'unknown'];
    foreach(['last_started_at','last_finished_at','last_success_at','last_error_at'] as $key){
        $value=$v[$key]??null;
        $out[$key]=is_string($value)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value)?$value:null;
    }
    $out['last_error_stage']=in_array($v['last_error_stage']??null,$stages,true)?$v['last_error_stage']:null;
    $at=strtotime((string)($out['state']==='running'?$out['last_started_at']:$out['last_finished_at']));
    $age=$at===false?null:time()-$at;
    $out['age_seconds']=$age;$out['stale']=$age===null||$age<0||$age>180;
    $out['snapshot_is_original_failure_proof']=false;
    return $out;
}
function hotelRows(PDO $pdo,string $sql,array $args,int $maximum): array {
    if(!str_starts_with($sql,'SELECT ')||$args!==[HOTEL_PREDECESSOR,HOTEL_CAMPAIGN])hotelFail('scoped_read_only_query_required');
    $s=$pdo->prepare($sql);$s->execute($args);$rows=[];
    try{while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){$rows[]=$row;if(count($rows)>$maximum)hotelFail('metadata_bounds_unconfirmed');}}
    finally{$s->closeCursor();}return $rows;
}
function hotelSnapshot(PDO $pdo,array &$out): void {
    $args=[HOTEL_PREDECESSOR,HOTEL_CAMPAIGN];
    $out=['state'=>'reading','scope'=>$args,'metadata_only'=>true,'consistent_read_only_snapshot'=>true,
        'metadata_hashes_are_independent_acceptance_proof'=>false,'read_only_snapshot_rolled_back'=>false];
    $out['campaigns']=[];
    $plan="CASE WHEN OCTET_LENGTH(plan_bytes)<=65536 AND JSON_VALID(CONVERT(plan_bytes USING utf8mb4)) THEN CONVERT(plan_bytes USING utf8mb4) ELSE NULL END";
    $rows=hotelRows($pdo,"SELECT campaign_id,state,enabled,stage_cursor,new_accepted,historical_accepted,baseline_sha256,inputs_sha256,bootstrap_sha256,reserved_calls,reserved_tokens,reserved_micros,JSON_UNQUOTE(JSON_EXTRACT(($plan),'$.limits.deadline')) AS plan_deadline FROM hotel_server_campaigns WHERE campaign_id IN (?,?) ORDER BY campaign_id LIMIT 3",$args,2);
    foreach($rows as $row){
        if(!in_array($row['state']??null,['disabled','ready','candidate','reviewed','hold','unknown','complete'],true))hotelFail('campaign_state_unconfirmed');
        $c=['campaign_id'=>hotelCampaign($row['campaign_id']),'state'=>$row['state'],'enabled'=>hotelInt($row['enabled'],1)===1,
            'stage_cursor'=>hotelInt($row['stage_cursor'],4),'new_accepted'=>hotelInt($row['new_accepted'],4),
            'historical_accepted'=>hotelInt($row['historical_accepted'],1000000),
            'baseline_sha256'=>hotelHash($row['baseline_sha256']),'inputs_sha256'=>hotelHash($row['inputs_sha256']),
            'bootstrap_sha256'=>hotelHash($row['bootstrap_sha256']),
            'reserved_calls'=>hotelInt($row['reserved_calls'],1000000),'reserved_tokens'=>hotelInt($row['reserved_tokens']),
            'reserved_micros'=>hotelInt($row['reserved_micros']),'plan_deadline'=>null];
        if($row['plan_deadline']!==null){$c['plan_deadline']=hotelInt($row['plan_deadline'],2147483647);
            $c['deadline_reached_at_observation']=time()>=$c['plan_deadline'];}
        $c['deadline_flag_is_original_error_proof']=false;$out['campaigns'][]=$c;
    }
    $out['receipt_refs']=[];
    $rows=hotelRows($pdo,'SELECT campaign_id,stage_index,receipt_sha256,OCTET_LENGTH(receipt_bytes) AS receipt_size FROM hotel_server_receipts WHERE campaign_id IN (?,?) ORDER BY campaign_id,stage_index LIMIT 9',$args,8);
    foreach($rows as $row)$out['receipt_refs'][]=['campaign_id'=>hotelCampaign($row['campaign_id']),
        'stage_index'=>hotelInt($row['stage_index'],3),'receipt_sha256'=>hotelHash($row['receipt_sha256']),
        'receipt_bytes'=>hotelInt($row['receipt_size'],2097152),'content_not_read'=>true];
    $out['attempts']=[];
    // Project only enumerated status/cause fields inside the server; never return result/request bytes or evidence text.
    $result="CASE WHEN OCTET_LENGTH(result_bytes)<=2097152 AND JSON_VALID(CONVERT(result_bytes USING utf8mb4)) THEN CONVERT(result_bytes USING utf8mb4) ELSE NULL END";
    $rows=hotelRows($pdo,"SELECT campaign_id,attempt_id,stage_index,role,state,baseline_sha256,request_sha256,result_sha256,OCTET_LENGTH(result_bytes) AS result_size,JSON_UNQUOTE(JSON_EXTRACT(($result),'$.provider_outcome')) AS saved_provider_outcome,JSON_UNQUOTE(JSON_EXTRACT(($result),'$.rejection_code')) AS saved_rejection_code,JSON_UNQUOTE(JSON_EXTRACT(($result),'$.approved')) AS saved_approved FROM hotel_server_attempts WHERE campaign_id IN (?,?) ORDER BY campaign_id,stage_index,role LIMIT 17",$args,16);
    foreach($rows as $row){
        if(!in_array($row['role']??null,['producer','reviewer'],true)||!in_array($row['state']??null,['unknown','succeeded'],true))
            hotelFail('attempt_state_unconfirmed');
        $a=['campaign_id'=>hotelCampaign($row['campaign_id']),'attempt_id'=>hotelHash($row['attempt_id']),
            'stage_index'=>hotelInt($row['stage_index'],3),'role'=>$row['role'],'state'=>$row['state'],
            'baseline_sha256'=>hotelHash($row['baseline_sha256']),'request_sha256'=>hotelHash($row['request_sha256']),
            'result_sha256'=>$row['result_sha256']===null?null:hotelHash($row['result_sha256']),
            'result_bytes'=>$row['result_size']===null?null:hotelInt($row['result_size']),
            'result_hash_independently_verified'=>false,'result_and_request_bytes_not_returned'=>true,'stored_cause'=>null];
        $a['saved_provider_outcome']=in_array($row['saved_provider_outcome'],['rejected'],true)?$row['saved_provider_outcome']:null;
        $a['saved_rejection_code']=in_array($row['saved_rejection_code'],['insufficient_evidence','invalid_candidate','invalid_review','invalid_response','provider_refused','provider_incomplete'],true)?$row['saved_rejection_code']:null;
        $a['saved_approved']=$row['saved_approved']==='true'?true:($row['saved_approved']==='false'?false:null);
        if($a['state']==='unknown')$a['stored_cause']='provider_outcome_unknown';
        elseif($a['saved_provider_outcome']==='rejected')$a['stored_cause']='provider_rejected';
        elseif($a['role']==='reviewer'&&$a['saved_approved']===false)$a['stored_cause']='independent_review_rejected';
        $a['stored_cause_is_original_handoff_failure_proof']=false;$out['attempts'][]=$a;
    }
    $out['work_outboxes']=[];
    $rows=hotelRows($pdo,'SELECT campaign_id,stage_index,role,state,attempt_id FROM hotel_server_outbox WHERE campaign_id IN (?,?) ORDER BY campaign_id,stage_index,role LIMIT 17',$args,16);
    foreach($rows as $row){
        if(!in_array($row['role']??null,['producer','reviewer'],true)||!in_array($row['state']??null,['pending','unknown','done'],true))
            hotelFail('outbox_state_unconfirmed');
        $out['work_outboxes'][]=['campaign_id'=>hotelCampaign($row['campaign_id']),'stage_index'=>hotelInt($row['stage_index'],3),
            'role'=>$row['role'],'state'=>$row['state'],'attempt_id'=>$row['attempt_id']===null?null:hotelHash($row['attempt_id'])];
    }
    $out['catalog_outboxes']=[];
    $rows=hotelRows($pdo,'SELECT campaign_id,receipt_sha256,state FROM hotel_server_catalog_outbox WHERE campaign_id IN (?,?) ORDER BY campaign_id,receipt_sha256 LIMIT 9',$args,8);
    foreach($rows as $row){
        if(!in_array($row['state']??null,['pending','done'],true))hotelFail('catalog_outbox_state_unconfirmed');
        $out['catalog_outboxes'][]=['campaign_id'=>hotelCampaign($row['campaign_id']),
            'receipt_sha256'=>hotelHash($row['receipt_sha256']),'state'=>$row['state']];
    }
    $out['record_counts']=[];
    $rows=hotelRows($pdo,'SELECT campaign_id,COUNT(*) AS record_count FROM hotel_server_records WHERE campaign_id IN (?,?) GROUP BY campaign_id ORDER BY campaign_id LIMIT 3',$args,2);
    foreach($rows as $row)$out['record_counts'][]=['campaign_id'=>hotelCampaign($row['campaign_id']),'record_count'=>hotelInt($row['record_count'],1000000),'record_contents_not_read'=>true];
    $out['review_statuses']=[];
    $rows=hotelRows($pdo,'SELECT campaign_id,stage_index,review_attempt,candidate_sha256,approved FROM hotel_server_reviews WHERE campaign_id IN (?,?) ORDER BY campaign_id,stage_index LIMIT 9',$args,8);
    foreach($rows as $row)$out['review_statuses'][]=['campaign_id'=>hotelCampaign($row['campaign_id']),
        'stage_index'=>hotelInt($row['stage_index'],3),'review_attempt'=>hotelHash($row['review_attempt']),
        'candidate_sha256'=>hotelHash($row['candidate_sha256']),'approved'=>hotelInt($row['approved'],1)===1];
    $out['state']='observed';
}
function hotelSection(string $name,callable $read): void {
    global $report;
    $report['phase']=$name;
    try{$report[$name]=$read();}
    catch(Throwable $e){$reason=hotelReason($e);$report[$name]=['available'=>false,'reason'=>$reason];
        $report['section_errors'][]=['section'=>$name,'reason'=>$reason];}
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
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app
        ||!function_exists('posix_geteuid')||posix_geteuid()!==1012)hotelFail('target_unconfirmed');
    $target=true;
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run)||$run===HOTEL_ORIGINAL_RUN_ID)hotelFail('new_observation_invocation_unconfirmed');
    $identity=['campaign_id'=>HOTEL_CAMPAIGN,'campaign_operation'=>HOTEL_ORIGINAL_OPERATION,
        'installed_sha'=>HOTEL_EXPECTED_INSTALLED_SHA,'original_installed_sha'=>HOTEL_ORIGINAL_INSTALLED_SHA,
        'original_job_id'=>HOTEL_ORIGINAL_JOB_ID,'original_run_id'=>HOTEL_ORIGINAL_RUN_ID,
        'predecessor_id'=>HOTEL_PREDECESSOR,'schema'=>HOTEL_STATUS_KIND];
    if(!hash_equals(HOTEL_OPERATION,hash('sha256',json_encode($identity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))))
        hotelFail('observation_identity_unconfirmed');
    $report['github_run_id']=$run;$report['installed_sha']=hotelInstalled($app);
    hotelSeal(['state'=>'preflight','operator_provider_calls'=>0]);
    $report['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z');$root=dirname($app).'/max-search-bot-hotel-server';
    // Capture immutable original files before config/DB work so a later failure preserves them.
    hotelSection('original_files',static fn():array=>hotelOriginalFiles($root));
    hotelSection('runtime',static fn():array=>hotelRuntime($root));
    hotelSection('health',static fn():array=>hotelSavedHealth($app));
    $report['phase']='database_connection';
    // Current exact-source connection does only new PDO; do not initialize ProjectAccess/Hotel classes.
    $connectionFile=$app.'/services/ConversationDb.php';
    if(is_link($connectionFile)||realpath($connectionFile)!==$connectionFile
        ||!hash_equals(HOTEL_CONNECTION_SOURCE_SHA,(string)hash_file('sha256',$connectionFile)))
        hotelFail('read_only_connection_source_unconfirmed');
    require_once $app.'/config.php';require_once $connectionFile;
    $pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
        ||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
        ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))hotelFail('application_database_unconfirmed');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    if(!$pdo->inTransaction())hotelFail('read_only_snapshot_unconfirmed');
    $report['phase']='database_metadata';$report['database']=[];
    hotelSnapshot($pdo,$report['database']);$pdo->rollBack();$report['database']['read_only_snapshot_rolled_back']=true;
    $report['phase']='final_release_pin';hotelInstalled($app);
    $report['state']=$report['section_errors']===[]?'observed':'HOLD';
    if($report['section_errors']!==[])$report['reason']='observation_sections_incomplete';
    $report['snapshot_finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    $report['files_and_database_are_separate_observation_instants']=true;
    $report['original_failure_detail']='requires_original_receipt_decryption_or_saved_phase_review';
}catch(Throwable $e){
    $report['state']='HOLD';$report['reason']=hotelReason($e);
    if($e instanceof PDOException){
        $report['reason']='database_metadata_observation_failed';
        $info=$e->errorInfo;
        if(is_array($info)&&is_string($info[0]??null)&&preg_match('/\A[A-Z0-9]{5}\z/D',$info[0]))$report['database_sqlstate']=$info[0];
        if(is_array($info)&&is_int($info[1]??null))$report['database_driver_code']=$info[1];
    }
}
exit(hotelFinish());
