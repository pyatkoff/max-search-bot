<?php
declare(strict_types=1);
/* One incident-mode revision of the existing private stdin reader. Saved state only.
 * No provider/tick/replay, history audit, application writes, file locks or schema initialization.
 * A fresh observation is not the original failed cron invocation: its exception may not be persisted.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='e77909a76b9df3fd5694c7a5f88993e83b3f0ed4';
const HOTEL_OPERATION='c4e57cee31f7384714b0211b56b79f5c9f7a1c64ff88d47afcc5e8cd0d8801cb';
const HOTEL_CAMPAIGN_OPERATION='fbbaa1ea243a9684958a5a1d4eccff2b4381e5e37d6a9594b299400722589858';
const HOTEL_CAMPAIGN='HOTEL-SERVER-20261103-B03';
const HOTEL_ORIGINAL_DEPLOY_RUN='41543909203';
const HOTEL_ORIGINAL_DEPLOY_JOB='125616651980';
const HOTEL_EXPECTED_RUNTIME_SHA='458f612752afba2debf9e7b7e66dfe695977c1a9726a6b9a0bc36896bbc1b017';
const HOTEL_EXPECTED_BOOTSTRAP_SHA='bfe4ac73ad393cfd990654a5dccf9cb37fb5f040d327e3aa6732c0e7756b5aad';
const HOTEL_EXPECTED_INPUTS_SHA='8bccd3235cbd0d5be625e12152c56276e2b1e2f0dd7c5e85b7cd6ec73ce089af';
const HOTEL_EXPECTED_PLAN_SHA='7803cb45057fd2d819147a70cbd60419f2220d4aa530c0534cc0de4675431704';
const HOTEL_STATUS_KIND='hotel-server-private-incident-v1';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');$pdo=null;
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'campaign_operation'=>HOTEL_CAMPAIGN_OPERATION,
    'campaign_id'=>HOTEL_CAMPAIGN,'original_deploy_run_id'=>HOTEL_ORIGINAL_DEPLOY_RUN,'original_deploy_job_id'=>HOTEL_ORIGINAL_DEPLOY_JOB,
    'state'=>'HOLD','operator_provider_calls'=>0,'database_writes'=>0,'server_file_writes'=>0,
    'automatic_resume_authorized'=>false,'snapshot_is_original_failed_invocation'=>false];

function hotelFail(string $reason): never { throw new DomainException($reason); }

function hotelKeys(array $value,array $expected): void {
    $keys=array_keys($value);sort($keys);sort($expected);if($keys!==$expected)hotelFail('private_shape_unconfirmed');
}

function hotelDir(string $path): void {
    clearstatcache(true,$path);
    if(is_link($path)||realpath($path)!==$path||!is_dir($path)||(fileperms($path)&0777)!==0700
        ||fileowner($path)!==posix_geteuid())hotelFail('private_directory_unconfirmed');
}

function hotelRead(string $path,int $limit,?int $size=null,?string $sha=null): string {
    clearstatcache(true,$path);$before=lstat($path);
    if(!is_array($before)||is_link($path)||!is_file($path)||($before['mode']&0777)!==0600
        ||$before['uid']!==posix_geteuid()||$before['size']<0||($before['size']===0&&$size!==0)||$before['size']>$limit
        ||($size!==null&&$before['size']!==$size))hotelFail('private_file_unconfirmed');
    $h=fopen($path,'rb');if(!$h)hotelFail('private_file_unconfirmed');
    try{$opened=fstat($h);if($opened['dev']!==$before['dev']||$opened['ino']!==$before['ino'])hotelFail('private_file_changed');
        $raw=stream_get_contents($h,$limit+1);}finally{fclose($h);}
    clearstatcache(true,$path);$after=lstat($path);
    if(!is_string($raw)||strlen($raw)!==$before['size']||$before['dev']!==$after['dev']
        ||$before['ino']!==$after['ino']||$before['mtime']!==$after['mtime']||$before['size']!==$after['size']
        ||($sha!==null&&!hash_equals($sha,hash('sha256',$raw))))hotelFail('private_file_changed');
    return $raw;
}

function hotelJson(string $raw): array {
    $v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($v))hotelFail('private_shape_unconfirmed');return $v;
}

function hotelInstalled(string $app): string {
    $out=[];$code=1;exec('git -C '.escapeshellarg($app).' rev-parse HEAD 2>/dev/null',$out,$code);
    $sha=trim(implode('', $out));
    if($code!==0||!preg_match('/\A[a-f0-9]{40}\z/D',HOTEL_EXPECTED_INSTALLED_SHA)||$sha!==HOTEL_EXPECTED_INSTALLED_SHA)
        hotelFail('installed_release_unconfirmed');return $sha;
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
    if(strlen($plain)>65536)hotelFail('receipt_bounds_unconfirmed');
    $key=random_bytes(32);$nonce=random_bytes(12);$tag='';$wrapped='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
    if(!is_string($cipher)||strlen($tag)!==16||!openssl_public_encrypt($key,$wrapped,$recipient,OPENSSL_PKCS1_OAEP_PADDING))hotelFail('sealing_unavailable');
    unset($key,$plain);
    $envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'sealed','operator_provider_calls'=>0,
        'algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>HOTEL_RECEIPT_RECIPIENT_SHA,'aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
        'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
    if(strlen(json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))+1>98304)hotelFail('wire_receipt_bounds_unconfirmed');
    return $envelope;
}

function hotelOne(PDO $pdo,string $sql,array $args=[]): ?array {
    if(!str_starts_with($sql,'SELECT '))hotelFail('read_only_query_required');
    $s=$pdo->prepare($sql);$s->execute($args);$row=$s->fetch(PDO::FETCH_ASSOC);
    if($s->fetch(PDO::FETCH_ASSOC)!==false)hotelFail('ambiguous_readback');$s->closeCursor();return $row===false?null:$row;
}
function hotelHash(mixed $value): string {
    if(!is_string($value)||!preg_match('/\A[a-f0-9]{64}\z/D',$value))hotelFail('saved_hash_unconfirmed');return $value;
}
function hotelInt(mixed $value,int $maximum=PHP_INT_MAX): int {
    if(is_int($value))$text=(string)$value;elseif(is_string($value))$text=$value;else hotelFail('saved_integer_unconfirmed');
    if(!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$text)||strlen($text)>strlen((string)$maximum)
        ||(strlen($text)===strlen((string)$maximum)&&strcmp($text,(string)$maximum)>0))hotelFail('saved_integer_unconfirmed');return (int)$text;
}
function hotelRows(PDO $pdo,string $sql,array $args,int $maximum): array {
    if(!str_starts_with($sql,'SELECT '))hotelFail('read_only_query_required');
    $s=$pdo->prepare($sql);$s->execute($args);$rows=[];
    try{while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){$rows[]=$row;if(count($rows)>$maximum)hotelFail('readback_bounds_unconfirmed');}}
    finally{$s->closeCursor();}return $rows;
}
function hotelSavedHealth(string $app): array {
    // One bounded raw read. No DiagnosticLogger, mkdir/chmod, flock or second observation.
    $dir=dirname($app).'/max-search-bot-private-logs';
    try{
        hotelDir($dir);$bytes=hotelRead($dir.'/hotel_work_health.json',8192);$v=hotelJson($bytes);
        $states=['running','ok','awaiting_connection','failed'];$stages=['bootstrap','subscription','delivery','server_cycle','complete'];
        if(!in_array($v['state']??null,$states,true)||!in_array($v['stage']??null,$stages,true))hotelFail('health_shape_unconfirmed');
        $out=['available'=>true,'saved_file_sha256'=>hash('sha256',$bytes),'saved_file_bytes'=>strlen($bytes),'state'=>$v['state'],'stage'=>$v['stage'],
            'trigger'=>in_array($v['trigger']??null,['cron','manual'],true)?$v['trigger']:'unknown'];
        foreach(['last_started_at','last_finished_at','last_success_at','last_error_at'] as $key){
            $value=$v[$key]??null;
            $out[$key]=is_string($value)&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D',$value)?$value:null;
        }
        $out['last_error_stage']=in_array($v['last_error_stage']??null,$stages,true)?$v['last_error_stage']:null;
        if($out['last_started_at']===null||($out['state']!=='running'&&$out['last_finished_at']===null))hotelFail('health_timestamp_unconfirmed');
        $at=strtotime((string)($out['state']==='running'?$out['last_started_at']:$out['last_finished_at']));$age=time()-($at===false?0:$at);
        $out['stale']=$at===false||$age<0||$age>180;$out['age_seconds']=max(0,$age);
        return $out;
    }catch(Throwable $ignored){return ['available'=>false,'state'=>'unknown','reason'=>'saved_health_unconfirmed'];}
}
function hotelRuntime(string $app): array {
    $dir=dirname($app).'/max-search-bot-hotel-server';hotelDir($dir);
    $bytes=hotelRead($dir.'/runtime.json',65536,null,HOTEL_EXPECTED_RUNTIME_SHA);$runtime=hotelJson($bytes);
    hotelKeys($runtime,['bootstrap_sha256','campaign_id','flags','policy','schema']);
    if(($runtime['schema']??null)!=='hotel-server-runtime-v1'||($runtime['campaign_id']??null)!==HOTEL_CAMPAIGN
        ||($runtime['bootstrap_sha256']??null)!==HOTEL_EXPECTED_BOOTSTRAP_SHA||!is_array($runtime['flags']??null)
        ||!is_array($runtime['policy']??null))hotelFail('runtime_pin_unconfirmed');
    hotelKeys($runtime['flags'],['dispatch_allowed','enabled','replacement_dispatch_allowed']);
    foreach($runtime['flags'] as $flag)if($flag!==true)hotelFail('runtime_flags_unconfirmed');
    return ['configuration_sha256'=>hash('sha256',$bytes),'campaign_id'=>$runtime['campaign_id'],
        'bootstrap_sha256'=>$runtime['bootstrap_sha256'],'flags'=>$runtime['flags']];
}
function hotelEvidence(string $value): array {
    // Private receipt only; never emit the full provider result or request.
    $length=strlen($value);$out=substr($value,0,2048);
    for($i=0;$i<4&&!preg_match('//u',$out);$i++)$out=substr($out,0,-1);
    if(!preg_match('//u',$out))hotelFail('review_evidence_unconfirmed');
    $out=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',' ',$out);
    if(!is_string($out))hotelFail('review_evidence_unconfirmed');
    return ['text'=>$out,'original_bytes'=>$length,'sha256'=>hash('sha256',$value),'truncated'=>$length>2048];
}
function hotelSavedAttempt(array $row): array {
    $id=hotelHash($row['attempt_id']??null);$stage=hotelInt($row['stage_index']??null,3);$role=$row['role']??null;$state=$row['state']??null;
    if(($row['campaign_id']??null)!==HOTEL_CAMPAIGN||!in_array($role,['producer','reviewer'],true)
        ||!in_array($state,['unknown','succeeded'],true))hotelFail('saved_attempt_unconfirmed');
    $out=['attempt_id'=>$id,'stage_index'=>$stage,'role'=>$role,'state'=>$state,'baseline_sha256'=>hotelHash($row['baseline_sha256']??null),
        'request_sha256'=>hotelHash($row['request_sha256']??null),'result_sha256'=>null,'result_bytes'=>0,'stored_cause'=>null];
    if($state==='unknown'){
        if(($row['result_sha256']??null)!==null||($row['result_size']??null)!==null||($row['bounded_result_bytes']??null)!==null)hotelFail('unknown_result_requires_reconciliation');
        $out['stored_cause']='provider_outcome_unknown';return $out;
    }
    $size=hotelInt($row['result_size']??null,2097152);$bytes=$row['bounded_result_bytes']??null;$sha=hotelHash($row['result_sha256']??null);
    if($size<1||!is_string($bytes)||strlen($bytes)!==$size||!hash_equals($sha,hash('sha256',$bytes)))hotelFail('saved_result_hash_unconfirmed');
    $result=hotelJson($bytes);
    if(($result['attempt_id']??null)!==$id||($result['principal']??null)!==($row['principal']??null)
        ||($result['baseline_sha256']??null)!==$out['baseline_sha256']||($result['inputs_sha256']??null)!==HOTEL_EXPECTED_INPUTS_SHA)hotelFail('saved_result_lineage_unconfirmed');
    $out['result_sha256']=$sha;$out['result_bytes']=$size;$out['result_hash_verified']=true;
    $outcome=$result['provider_outcome']??null;
    if($outcome!==null&&$outcome!=='rejected')hotelFail('provider_outcome_unconfirmed');
    $out['provider_outcome']=$outcome;
    if($outcome==='rejected'){
        $code=$result['rejection_code']??null;
        if(!in_array($code,['insufficient_evidence','invalid_candidate','invalid_review','invalid_response','provider_refused','provider_incomplete'],true))hotelFail('saved_rejection_unconfirmed');
        $out['rejection_code']=$code;$out['stored_cause']='provider_rejected';
    }
    if(array_key_exists('approved',$result)){
        if(!is_bool($result['approved'])||$role!=='reviewer')hotelFail('saved_review_unconfirmed');
        $out['approved']=$result['approved'];
        if($outcome===null&&$result['approved']===false)$out['stored_cause']='independent_review_rejected';
    }
    if($role==='reviewer'&&array_key_exists('evidence',$result)){
        if(!is_string($result['evidence']))hotelFail('review_evidence_unconfirmed');$out['review_evidence']=hotelEvidence($result['evidence']);
    }
    return $out;
}
function hotelIncident(PDO $pdo): array {
    // Fixed B03 only. No predecessors, receipts, baseline documents, accepted records or schema APIs.
    $c=hotelOne($pdo,'SELECT campaign_id,state,enabled,stage_cursor,new_accepted,baseline_sha256,inputs_sha256,bootstrap_sha256,reserved_calls,reserved_tokens,reserved_micros,OCTET_LENGTH(plan_bytes) AS plan_size,CASE WHEN OCTET_LENGTH(plan_bytes)<=65536 THEN plan_bytes ELSE NULL END AS bounded_plan_bytes FROM hotel_server_campaigns WHERE campaign_id=?',[HOTEL_CAMPAIGN]);
    if($c===null||$c['campaign_id']!==HOTEL_CAMPAIGN||$c['inputs_sha256']!==HOTEL_EXPECTED_INPUTS_SHA||$c['bootstrap_sha256']!==HOTEL_EXPECTED_BOOTSTRAP_SHA)hotelFail('campaign_pin_unconfirmed');
    if(!in_array($c['state'],['disabled','ready','candidate','reviewed','hold','unknown','complete'],true))hotelFail('campaign_state_unconfirmed');
    $stage=hotelInt($c['stage_cursor'],4);$new=hotelInt($c['new_accepted'],4);$enabled=hotelInt($c['enabled'],1)===1;
    $planBytes=$c['bounded_plan_bytes'];$planSize=hotelInt($c['plan_size'],65536);
    if(!is_string($planBytes)||strlen($planBytes)!==$planSize||!hash_equals(HOTEL_EXPECTED_PLAN_SHA,hash('sha256',$planBytes)))hotelFail('campaign_plan_unconfirmed');
    $plan=hotelJson($planBytes);$limits=$plan['limits']??null;
    if(!is_array($limits))hotelFail('campaign_limits_unconfirmed');
    $budget=[];foreach(['max_calls','max_tokens','max_micros','tokens_per_call','micros_per_call'] as $name)$budget[$name]=hotelInt($limits[$name]??null);
    $deadline=hotelInt($limits['deadline']??null,2000000000);
    $budget['deadline']=$deadline;$budget['deadline_utc']=gmdate('Y-m-d\TH:i:s\Z',$deadline);
    $budget['reserved_calls']=hotelInt($c['reserved_calls']);$budget['reserved_tokens']=hotelInt($c['reserved_tokens']);$budget['reserved_micros']=hotelInt($c['reserved_micros']);
    $budget['deadline_reached_at_observation']=time()>=$deadline;
    $budget['next_call_exceeds_calls']=$budget['reserved_calls']>=$budget['max_calls'];
    $budget['next_call_exceeds_tokens']=$budget['reserved_tokens']>$budget['max_tokens']-$budget['tokens_per_call'];
    $budget['next_call_exceeds_micros']=$budget['reserved_micros']>$budget['max_micros']-$budget['micros_per_call'];
    $columns='attempt_id,campaign_id,stage_index,role,principal,state,baseline_sha256,request_sha256,result_sha256,OCTET_LENGTH(result_bytes) AS result_size,CASE WHEN OCTET_LENGTH(result_bytes)<=2097152 THEN result_bytes ELSE NULL END AS bounded_result_bytes';
    $rows=hotelRows($pdo,'SELECT '.$columns." FROM hotel_server_attempts WHERE campaign_id=? AND stage_index=? ORDER BY CASE role WHEN 'producer' THEN 0 WHEN 'reviewer' THEN 1 ELSE 2 END LIMIT 3",[HOTEL_CAMPAIGN,$stage],2);
    $attempts=[];$roles=[];
    foreach($rows as $row){$a=hotelSavedAttempt($row);if($a['stage_index']!==$stage||isset($roles[$a['role']]))hotelFail('current_attempt_set_unconfirmed');$roles[$a['role']]=true;$attempts[]=$a;}
    $latest=$attempts?end($attempts):null;
    // The existing schema has no attempt timestamp. Sequential stage/role is the saved ordering, not a fabricated time.
    if(!$attempts){
        $row=hotelOne($pdo,'SELECT '.$columns." FROM hotel_server_attempts WHERE campaign_id=? ORDER BY stage_index DESC,CASE role WHEN 'reviewer' THEN 1 WHEN 'producer' THEN 0 ELSE 2 END DESC LIMIT 1",[HOTEL_CAMPAIGN]);
        if($row!==null){$latest=hotelSavedAttempt($row);if($latest['stage_index']>$stage)hotelFail('latest_attempt_ahead_of_campaign');}
    }
    $outboxRows=hotelRows($pdo,"SELECT stage_index,role,state,attempt_id FROM hotel_server_outbox WHERE campaign_id=? AND stage_index=? ORDER BY role LIMIT 3",[HOTEL_CAMPAIGN,$stage],2);
    $outboxes=[];$outRoles=[];
    foreach($outboxRows as $row){
        if(hotelInt($row['stage_index'],3)!==$stage||!in_array($row['role'],['producer','reviewer'],true)||isset($outRoles[$row['role']])
            ||!in_array($row['state'],['pending','unknown','done'],true))hotelFail('current_outbox_unconfirmed');
        $outRoles[$row['role']]=true;$outboxes[]=['stage_index'=>$stage,'role'=>$row['role'],'state'=>$row['state'],'attempt_id'=>$row['attempt_id']===null?null:hotelHash($row['attempt_id'])];
    }
    $causes=[];
    foreach($attempts?:($latest===null?[]:[$latest]) as $a)if($a['stored_cause']!==null)$causes[]=['stage_index'=>$a['stage_index'],'role'=>$a['role'],'attempt_id'=>$a['attempt_id'],'cause'=>$a['stored_cause'],'rejection_code'=>$a['rejection_code']??null];
    $attention=$causes?$causes[count($causes)-1]['cause']:(in_array($c['state'],['hold','unknown'],true)?'original_error_not_persisted':null);
    return ['campaign'=>['campaign_id'=>HOTEL_CAMPAIGN,'state'=>$c['state'],'enabled'=>$enabled,'stage_index'=>$stage,'new_accepted'=>$new,
        'baseline_sha256'=>hotelHash($c['baseline_sha256']),'inputs_sha256'=>$c['inputs_sha256'],'bootstrap_sha256'=>$c['bootstrap_sha256'],'plan_sha256'=>HOTEL_EXPECTED_PLAN_SHA],
        'budget_at_observation'=>$budget,'current_stage_attempts'=>$attempts,'latest_saved_attempt'=>$latest,
        'latest_ordering'=>'stage_index_then_reviewer_after_producer_no_timestamp','current_stage_outbox'=>$outboxes,
        'stored_causes'=>$causes,'current_attention_reason'=>$attention,'original_error_detail'=>$causes?'stored_attempt_cause_available':'original_error_not_persisted',
        'original_error_time_correlation'=>'not_persisted_in_attempt_schema','budget_flags_are_original_error_proof'=>false];
}

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)hotelFail('target_unconfirmed');
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $identity=['campaign_id'=>HOTEL_CAMPAIGN,'campaign_operation'=>HOTEL_CAMPAIGN_OPERATION,'installed_sha'=>HOTEL_EXPECTED_INSTALLED_SHA,
        'original_deploy_job_id'=>HOTEL_ORIGINAL_DEPLOY_JOB,'original_deploy_run_id'=>HOTEL_ORIGINAL_DEPLOY_RUN,'schema'=>HOTEL_STATUS_KIND];
    if(!hash_equals(HOTEL_OPERATION,hash('sha256',json_encode($identity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))))hotelFail('incident_identity_unconfirmed');
    $report['github_run_id']=$run;$report['installed_sha']=hotelInstalled($app);hotelSeal(['state'=>'preflight','operator_provider_calls'=>0]);
    $report['observed_at_utc']=gmdate('Y-m-d\TH:i:s\Z');$report['health']=hotelSavedHealth($app);
    $report['runtime']=hotelRuntime($app);
    // Direct existing PDO connection only: fresh installed ConversationDb::connection() has no schema/project initialization.
    require_once $app.'/config.php';require_once $app.'/services/ConversationDb.php';$pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)hotelFail('application_database_unconfirmed');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    if(!$pdo->inTransaction())hotelFail('read_only_snapshot_unconfirmed');
    $snapshot=hotelIncident($pdo);$pdo->rollBack();
    hotelInstalled($app);hotelRuntime($app);
    $report=array_replace($report,$snapshot);$report['state']='observed';$report['snapshot_finished_at_utc']=gmdate('Y-m-d\TH:i:s\Z');
    if(($report['health']['available']??false)!==true){$report['state']='HOLD';$report['reason']='saved_health_unconfirmed';}
}catch(Throwable $e){
    $message=$e->getMessage();$report['state']='HOLD';$report['reason']=preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'incident_readback_unconfirmed';
}finally{
    if($pdo instanceof PDO&&$pdo->inTransaction())try{$pdo->rollBack();}catch(Throwable $ignored){}
    while(ob_get_level()>$level)ob_end_clean();
}
try{
    $envelope=($report['reason']??null)==='target_unconfirmed'
        ?['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0]:hotelSeal($report);
}catch(Throwable $ignored){$envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'sealed_readback_result_unconfirmed','operator_provider_calls'=>0];}
echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($report['state']==='observed'&&$envelope['state']==='sealed'?0:1);
