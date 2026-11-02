<?php
declare(strict_types=1);
/* DRAFT ONLY. One B02 read-only first-acceptance/next-producer snapshot bound to the actual handoff journal.
 * No provider object/send/tick, schema/data/file mutation or scheduler operation.
 */
const HOTEL_EXPECTED_INSTALLED_SHA='ea70e1988b9fb0eb04c23dad12f1bc182acf5d68';
const HOTEL_OPERATION='c44e7842f84ad562518f9a3ed736aadff58a11616761801ff437fd901d732094';
const HOTEL_CAMPAIGN='HOTEL-SERVER-20261102-B02';
const HOTEL_PREDECESSOR='HOTEL-SERVER-20261102';
const HOTEL_HANDOFF_KIND='hotel-server-private-forward-handoff-v1';
const HOTEL_PUBLIC_PAYLOAD_SHA='da5a9e22cd1958bb5aef93df7239668f14cf3ad4912da09186bdcdecd527d542';
const HOTEL_PLAN_SHA='2f2ae3071765dd89eb986f04427bf968c368c00e845691168387976b4627e6e7';
const HOTEL_POLICY_SHA='0377f1ff805240426a9d4255abcb913b77a57b0dae374042731a2cd6d67ed72c';
const HOTEL_BUDGET_SHA='a7ca80535857cac0a6799eb6b3c6bea3a4eca450980c65506e15d02c24279a06';
const HOTEL_DELTA_SHA='e2902b56126eb22206c3cad0b9f9388a612c6ff5a5bab406146b2884a467cd17';
const HOTEL_OLD_BOOTSTRAP='3b37e06c6fd97f4571f282446fc41fb6e4751e8af82906ef08806d18c2173a59';
const HOTEL_OLD_BASELINE='dc44b5184351adb7469136821b84f0334c99ca6acabfa0e4e1360625fc861270';
const HOTEL_OLD_RUNTIME='83cfad5a569b5038984f34cb48ff5cfb33f14f078c1eb64455de5bbfa691303d';
const HOTEL_STATUS_KIND='hotel-server-private-forward-status-v1';
const HOTEL_RECEIPT_RECIPIENT_SHA='71f2b177d6baa924fa466765027c37f51a00f23f803a6d900060bf42944d237d';
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $ignored):string=>'');$pdo=null;
$report=['schema'=>HOTEL_STATUS_KIND,'operation_id'=>HOTEL_OPERATION,'campaign_id'=>HOTEL_CAMPAIGN,
    'state'=>'HOLD','operator_provider_calls'=>0,'database_writes'=>0,'server_file_writes'=>0,
    'first_new_acceptance_confirmed'=>false,'next_provider_call_confirmed'=>false];

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
    return ['operation'=>HOTEL_STATUS_KIND,'state'=>'sealed','operator_provider_calls'=>0,
        'algorithm'=>'RSA-OAEP-SHA1+A256GCM','recipient_sha256'=>HOTEL_RECEIPT_RECIPIENT_SHA,'aad'=>$aad,
        'key'=>base64_encode($wrapped),'nonce'=>base64_encode($nonce),'tag'=>base64_encode($tag),
        'ciphertext'=>base64_encode($cipher),'ciphertext_sha256'=>hash('sha256',$cipher)];
}

function hotelOne(PDO $pdo,string $sql,array $args=[]): ?array {
    if(!str_starts_with($sql,'SELECT '))hotelFail('read_only_query_required');
    $s=$pdo->prepare($sql);$s->execute($args);$row=$s->fetch(PDO::FETCH_ASSOC);
    if($s->fetch(PDO::FETCH_ASSOC)!==false)hotelFail('ambiguous_readback');$s->closeCursor();return $row===false?null:$row;
}
function hotelHash(mixed $v): string {
    if(!is_string($v)||!preg_match('/\A[a-f0-9]{64}\z/D',$v))hotelFail('evidence_hash_unconfirmed');return $v;
}
function hotelRowBytes(array $row,string $bytes,string $sha,int $limit): string {
    if(!is_string($row[$bytes]??null)||strlen($row[$bytes])<1||strlen($row[$bytes])>$limit
        ||!hash_equals(hotelHash($row[$sha]??null),hash('sha256',$row[$bytes])))hotelFail('stored_evidence_hash_unconfirmed');return $row[$bytes];
}
function hotelAdmission(mixed $value): array {
    $expected=['mode'=>'available_confirmed','historical_accepted'=>19,'available_accepted'=>13,'unavailable_accepted'=>6,
        'quarantine_total'=>1,'mapped_quarantine'=>0,'unavailable_quarantine'=>1,'duplicate_prevention'=>'available_confirmed_scopes_only',
        'unknown_legacy_overlap'=>true,'new_acceptance_credit'=>0];
    if(!is_array($value)||HotelServerInputs::json($value)!==HotelServerInputs::json($expected))hotelFail('retained_admission_unconfirmed');return $expected;
}
function hotelAttempt(PDO $pdo,array $a,int $stage,string $role,string $baseline,string $inputs,array $plan,array $policy,string $revision,string $criteria): array {
    $id=hotelHash($a['attempt_id']??null);$request=hotelJson(hotelRowBytes($a,'request_bytes','request_sha256',262144));
    if(($a['campaign_id']??null)!==HOTEL_CAMPAIGN||(int)($a['stage_index']??-1)!==$stage||($a['role']??null)!==$role
        ||($a['baseline_sha256']??null)!==$baseline||($a['principal']??null)!==$policy['principals'][$role].':'.$revision
        ||($request['schema']??null)!=='hotel-server-attempt-v1'||($request['attempt_id']??null)!==$id
        ||($request['campaign_id']??null)!==HOTEL_CAMPAIGN||($request['stage_index']??null)!==$stage||($request['role']??null)!==$role
        ||($request['principal']??null)!==$a['principal']||($request['baseline_sha256']??null)!==$baseline
        ||($request['inputs_sha256']??null)!==$inputs||($request['model']??null)!==$policy['model']
        ||($request['research_mode']??null)!=='assigned_retained_excerpts'||($request['criteria_sha256']??null)!==$criteria
        ||HotelServerInputs::json($request['scopes']??null)!==HotelServerInputs::json($plan['stages'][$stage])
        ||HotelServerInputs::json($request['limits']??null)!==HotelServerInputs::json($plan['limits']))hotelFail('attempt_lineage_unconfirmed');
    $view=$request['accepted_context']??null;if(!is_array($view))hotelFail('attempt_baseline_read_unconfirmed');
    $viewSha=hotelHash($view['view_sha256']??null);unset($view['view_sha256']);
    if(($view['schema']??null)!=='hotel-server-accepted-view-v1'||($view['baseline_sha256']??null)!==$baseline
        ||!hash_equals($viewSha,hash('sha256',HotelServerInputs::json($view))))hotelFail('attempt_baseline_read_unconfirmed');
    if($role==='producer'&&(($request['candidate']??null)!==null||($request['candidate_sha256']??null)!==null))hotelFail('producer_request_candidate_unconfirmed');
    $out=['attempt_id'=>$id,'stage_index'=>$stage,'role'=>$role,'state'=>$a['state'],'principal'=>$a['principal'],
        'request_sha256'=>$a['request_sha256'],'baseline_sha256'=>$baseline,'inputs_sha256'=>$inputs,'provider_call_confirmed'=>false];
    if($a['state']==='unknown'){
        if(($a['result_sha256']??null)!==null||($a['result_bytes']??null)!==null)hotelFail('unknown_result_requires_reconciliation');
        return ['row'=>$a,'request'=>$request,'result'=>null,'proof'=>$out];
    }
    if($a['state']!=='succeeded')hotelFail('attempt_state_unconfirmed');
    $result=hotelJson(hotelRowBytes($a,'result_bytes','result_sha256',2097152));$usage=$result['provider_usage']??null;
    if(($result['attempt_id']??null)!==$id||($result['principal']??null)!==$a['principal']
        ||($result['baseline_sha256']??null)!==$baseline||($result['inputs_sha256']??null)!==$inputs
        ||($result['provider_model']??null)!==$policy['model']||($result['profile_sha256']??null)!==$policy['profile_sha256']
        ||($result['provider_policy_sha256']??null)!==$revision||($result['budget_attestation_sha256']??null)!==$policy['budget_attestation_sha256']
        ||!is_string($result['provider_response_id']??null)||strlen($result['provider_response_id'])>256
        ||!preg_match('/\Aresp_[A-Za-z0-9_-]+\z/D',$result['provider_response_id'])||!is_array($usage))hotelFail('actual_provider_result_unconfirmed');
    foreach(['input_tokens','output_tokens','total_tokens'] as $k)if(!is_int($usage[$k]??null)||$usage[$k]<0)hotelFail('provider_usage_unconfirmed');
    if($usage['total_tokens']<1||$usage['total_tokens']!==$usage['input_tokens']+$usage['output_tokens']
        ||$usage['input_tokens']>$policy['input_token_ceiling']||$usage['output_tokens']>2200
        ||$usage['total_tokens']>$plan['limits']['tokens_per_call']||($result['usage']['tokens']??null)!==$usage['total_tokens']
        ||($result['usage']['micros']??null)!==$plan['limits']['micros_per_call']
        ||($result['usage']['micros_kind']??null)!=='full_reserved_ceiling_not_provider_invoice')hotelFail('provider_usage_unconfirmed');
    if(isset($result['provider_outcome'])&&($result['provider_outcome']!=='rejected'||!in_array($result['rejection_code']??null,
        ['insufficient_evidence','invalid_candidate','invalid_review','invalid_response','provider_refused','provider_incomplete'],true)))hotelFail('provider_outcome_unconfirmed');
    $out['provider_call_confirmed']=true;$out+=['result_sha256'=>$a['result_sha256'],'provider_response_id'=>$result['provider_response_id'],
        'provider_model'=>$result['provider_model'],'provider_usage'=>$usage,'provider_outcome'=>$result['provider_outcome']??'answered',
        'rejection_code'=>$result['rejection_code']??null];
    if($role==='reviewer')$out+=['review_approved'=>$result['approved']??null,
        'review_evidence'=>is_string($result['evidence']??null)?substr($result['evidence'],0,4096):null];
    return ['row'=>$a,'request'=>$request,'result'=>$result,'proof'=>$out];
}
function hotelOutbox(PDO $pdo,int $stage,string $role): ?array {
    $row=hotelOne($pdo,'SELECT campaign_id,stage_index,role,state,attempt_id FROM hotel_server_outbox WHERE campaign_id=? AND stage_index=? AND role=?',[HOTEL_CAMPAIGN,$stage,$role]);
    if($row!==null&&(!in_array($row['state'],['pending','unknown','done'],true)||($row['attempt_id']!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$row['attempt_id']))))hotelFail('outbox_shape_unconfirmed');return $row;
}
function hotelCandidate(PDO $pdo,int $stage,string $producer,string $candidateSha,array $expected): array {
    $row=hotelOne($pdo,'SELECT campaign_id,stage_index,candidate_sha256,producer_attempt,candidate_bytes FROM hotel_server_candidates WHERE campaign_id=? AND stage_index=?',[HOTEL_CAMPAIGN,$stage]);
    if($row===null||$row['producer_attempt']!==$producer||$row['candidate_sha256']!==$candidateSha)hotelFail('candidate_lineage_unconfirmed');
    $candidate=hotelJson(hotelRowBytes($row,'candidate_bytes','candidate_sha256',1048576));
    hotelKeys($candidate,['schema','records']);if($candidate['schema']!=='hotel-server-candidate-v1'||!is_array($candidate['records'])||!array_is_list($candidate['records'])||!$candidate['records'])hotelFail('candidate_shape_unconfirmed');
    $actual=[];foreach($candidate['records'] as $record){hotelKeys($record,['scope','answer','evidence']);$actual[]=HotelServerInputs::key($record['scope']);
        if(!is_string($record['answer'])||trim($record['answer'])===''||strlen($record['answer'])>32768||!is_array($record['evidence'])||!array_is_list($record['evidence'])||!$record['evidence'])hotelFail('candidate_evidence_unconfirmed');}
    $keys=array_map([HotelServerInputs::class,'key'],$expected);sort($actual);sort($keys);
    if($actual!==$keys||count(array_unique($actual))!==count($actual))hotelFail('candidate_scope_unconfirmed');
    return ['row'=>$row,'document'=>$candidate,'semantic_keys'=>$actual];
}
function hotelReadback(PDO $pdo,array $runtime,array $intent,array $preflight,array $bootstrap,array $plan,array $policy,array $budget,array $delta): array {
    $inputPin=hotelHash($intent['new_inputs_sha256']??null);$bootstrapPin=hotelHash($intent['expected_bootstrap_sha256']??null);
    $seedPin=hotelHash($intent['expected_seed_sha256']??null);
    if(hash('sha256',HotelServerInputs::json([$inputPin,$plan,['continuation_seed_sha256'=>$seedPin]]))!==$bootstrapPin
        ||$runtime['bootstrap_sha256']!==$bootstrapPin||($bootstrap['bootstrap_sha256']??null)!==$bootstrapPin
        ||($bootstrap['baseline_sha256']??null)!==$seedPin||($bootstrap['campaign_id']??null)!==HOTEL_CAMPAIGN
        ||($bootstrap['state']??null)!=='disabled'||($bootstrap['enabled']??null)!==false||($bootstrap['stage_index']??null)!==0
        ||($bootstrap['new_accepted']??null)!==0||($intent['operation_id']??null)!==HOTEL_OPERATION)hotelFail('handoff_lineage_unconfirmed');
    $retained=hotelAdmission($preflight['retained_admission']??null);hotelAdmission($bootstrap['retained_admission']??null);
    // The B02 initial baseline is its continuation seed, not the retired legacy root or B01 final delta.
    $originalBaseline=$seedPin;$criteria=hash('sha256',$delta['criteria']);
    $seedRow=hotelOne($pdo,'SELECT campaign_id,baseline_sha256,parent_sha256,document_bytes FROM hotel_server_baselines WHERE campaign_id=? AND baseline_sha256=?',[HOTEL_CAMPAIGN,$seedPin]);
    $seed=$preflight['predecessor']['seed']??null;
    if(!is_array($seed)||$seedRow===null||$seedRow['parent_sha256']!==null
        ||hotelRowBytes($seedRow,'document_bytes','baseline_sha256',1048576)!==HotelServerInputs::json($seed))hotelFail('saved_continuation_seed_unconfirmed');
    hotelKeys($seed,['schema','retained_baseline_sha256','predecessor','records']);
    if(($seed['schema']??null)!=='hotel-server-continuation-seed-v1'||($seed['predecessor']['campaign_id']??null)!==HOTEL_PREDECESSOR
        ||($seed['predecessor']['bootstrap_sha256']??null)!==HOTEL_OLD_BOOTSTRAP||($seed['predecessor']['baseline_sha256']??null)!==HOTEL_OLD_BASELINE
        ||!is_array($seed['records'])||!array_is_list($seed['records'])||count($seed['records'])!==4
        ||!is_array($seed['predecessor']['receipts']??null)||count($seed['predecessor']['receipts'])!==4)hotelFail('carried_server_history_unconfirmed');
    if(($budget['campaign_id']??null)!==HOTEL_CAMPAIGN||($budget['previous_campaign_budget_not_reused']??null)!==true
        ||($budget['maximum_calls']??null)!==8||($budget['planned_record_count']??null)!==16
        ||!is_array($plan['stages']??null)||count($plan['stages'])!==4)hotelFail('b02_plan_budget_unconfirmed');
    foreach($plan['stages'] as $scopes)if(!is_array($scopes)||count($scopes)!==4)hotelFail('b02_scope_count_unconfirmed');
    if(($budget['profile_sha256']??null)!==$policy['profile_sha256']||hash('sha256',HotelServerInputs::json($budget['profile']??null))!==$policy['profile_sha256'])hotelFail('profile_pin_unconfirmed');
    $revision=hash('sha256',HotelServerInputs::json([$policy,$budget['profile'],hash('sha256',HotelServerAiProvider::instructions('producer')),hash('sha256',HotelServerAiProvider::instructions('reviewer'))]));
    // Only SELECTs below, inside an engine-enforced, consistent read-only transaction.
    $cycle=new HotelServerCycle($pdo,new HotelServerLane($pdo));$status=$cycle->status(HOTEL_CAMPAIGN);hotelAdmission($status['retained_admission']??null);
    $lane=hotelOne($pdo,"SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'");
    $enabledCampaign=hotelOne($pdo,'SELECT campaign_id FROM hotel_server_campaigns WHERE enabled=1');
    if($lane===null||$lane['queue_id']!==HOTEL_CAMPAIGN||$enabledCampaign===null||$enabledCampaign['campaign_id']!==HOTEL_CAMPAIGN)
        hotelFail('current_handoff_owner_unconfirmed');
    $campaign=hotelOne($pdo,'SELECT campaign_id,state,enabled,stage_cursor,new_accepted,historical_accepted,baseline_sha256,inputs_sha256,bootstrap_sha256,plan_bytes,reserved_calls,reserved_tokens,reserved_micros FROM hotel_server_campaigns WHERE campaign_id=?',[HOTEL_CAMPAIGN]);
    if($campaign===null||$campaign['inputs_sha256']!==$inputPin||$campaign['bootstrap_sha256']!==$bootstrapPin||$status['bootstrap_sha256']!==$bootstrapPin
        ||HotelServerInputs::json(hotelJson($campaign['plan_bytes']))!==HotelServerInputs::json($plan)
        ||(int)$campaign['historical_accepted']!==19||$status['new_accepted']!==(int)$campaign['new_accepted']
        ||(int)$campaign['new_accepted']<0||(int)$campaign['new_accepted']>4||(int)$campaign['stage_cursor']<0||(int)$campaign['stage_cursor']>4
        ||(int)$campaign['reserved_calls']<0||(int)$campaign['reserved_calls']>8||(int)$campaign['reserved_tokens']>$plan['limits']['max_tokens']
        ||(int)$campaign['reserved_micros']>$plan['limits']['max_micros'])hotelFail('campaign_pin_unconfirmed');
    $report=['state'=>'partial','reason'=>'first_acceptance_pending','observed_at_utc'=>gmdate('c'),'campaign_status'=>$status,
        'inputs_sha256'=>$inputPin,'bootstrap_sha256'=>$bootstrapPin,'runtime_sha256'=>$runtime['configuration_sha256'],
        'retained_admission'=>$retained,'central41'=>'UNKNOWN','first_new_acceptance_confirmed'=>false,
        'next_producer_attempt_exists'=>false,'next_provider_call_confirmed'=>false,'next_candidate_saved'=>false,
        'cron_trigger_corroborated'=>false,'operator_provider_calls'=>0,'database_writes'=>0,'server_file_writes'=>0];
    $report['inherited_server_history']=['campaign_id'=>HOTEL_PREDECESSOR,'new_acceptances'=>4,'canonical_records'=>4,
        'terminal_baseline_sha256'=>HOTEL_OLD_BASELINE,'continuation_seed_sha256'=>$seedPin,'new_acceptance_credit_for_B02'=>0];
    $report['expected_records_per_new_acceptance']=4;
    $report['current_lane_owner']=$lane['queue_id'];$report['sole_enabled_campaign']=$enabledCampaign['campaign_id'];
    $counts=[];foreach(['acceptance_receipts'=>'hotel_server_receipts','canonical_records'=>'hotel_server_records',
        'attempts'=>'hotel_server_attempts','work_outbox'=>'hotel_server_outbox'] as $name=>$table){
        $row=hotelOne($pdo,'SELECT COUNT(*) AS n FROM '.$table.' WHERE campaign_id=?',[HOTEL_CAMPAIGN]);$counts[$name]=(int)$row['n'];
    }
    $attemptCounts=hotelOne($pdo,"SELECT SUM(state='succeeded') AS succeeded,SUM(state='unknown') AS unknown_count FROM hotel_server_attempts WHERE campaign_id=?",[HOTEL_CAMPAIGN]);
    $counts['succeeded_attempts']=(int)$attemptCounts['succeeded'];$counts['unknown_attempts']=(int)$attemptCounts['unknown_count'];
    if($counts['acceptance_receipts']!==$status['new_accepted']||$counts['canonical_records']!==4*$status['new_accepted']
        ||$counts['attempts']>8||$counts['work_outbox']>8||$counts['succeeded_attempts']+$counts['unknown_attempts']!==$counts['attempts'])hotelFail('b02_counts_unconfirmed');
    $report['exact_counts']=$counts;
    $report['cron_health']=HotelWorkHealth::snapshot();
    $report['budget_observation']=['reserved_calls'=>(int)$campaign['reserved_calls'],'reserved_tokens'=>(int)$campaign['reserved_tokens'],
        'reserved_micros'=>(int)$campaign['reserved_micros'],'deadline_utc'=>gmdate('c',$plan['limits']['deadline']),
        'deadline_has_passed'=>time()>=$plan['limits']['deadline'],'accounting'=>'reserved_ceiling_not_provider_invoice'];
    $report['current_stage_attempts']=[];$report['current_attention_reason']=null;
    $current=(int)$campaign['stage_cursor'];
    if($current<4)foreach(['producer','reviewer'] as $role){
        $row=hotelOne($pdo,'SELECT attempt_id,campaign_id,stage_index,role,principal,state,baseline_sha256,request_sha256,request_bytes,result_sha256,result_bytes FROM hotel_server_attempts WHERE campaign_id=? AND stage_index=? AND role=?',[HOTEL_CAMPAIGN,$current,$role]);
        if($row===null)continue;
        $observed=hotelAttempt($pdo,$row,$current,$role,$campaign['baseline_sha256'],$inputPin,$plan,$policy,$revision,$criteria);
        $report['current_stage_attempts'][$role]=$observed['proof'];
        if($row['state']==='unknown')$report['current_attention_reason']='stage'.$current.'_'.$role.'_provider_outcome_unknown';
        if(($observed['result']['provider_outcome']??null)==='rejected')$report['current_attention_reason']='stage'.$current.'_'.$role.'_provider_rejected';
        if($role==='reviewer'&&($observed['result']['approved']??null)===false)$report['current_attention_reason']='stage'.$current.'_independent_review_rejected';
    }
    $report['stage0_attempts']=[];$firstAttempts=[];
    foreach(['producer','reviewer'] as $role){
        $a=hotelOne($pdo,'SELECT attempt_id,campaign_id,stage_index,role,principal,state,baseline_sha256,request_sha256,request_bytes,result_sha256,result_bytes FROM hotel_server_attempts WHERE campaign_id=? AND stage_index=0 AND role=?',[HOTEL_CAMPAIGN,$role]);
        if($a!==null){$firstAttempts[$role]=hotelAttempt($pdo,$a,0,$role,$originalBaseline,$inputPin,$plan,$policy,$revision,$criteria);$report['stage0_attempts'][$role]=$firstAttempts[$role]['proof'];}
    }
    $receiptRow=hotelOne($pdo,'SELECT campaign_id,stage_index,receipt_sha256,receipt_bytes FROM hotel_server_receipts WHERE campaign_id=? AND stage_index=0',[HOTEL_CAMPAIGN]);
    if($receiptRow===null){
        if($status['new_accepted']!==0)hotelFail('acceptance_receipt_missing');
        foreach(['producer','reviewer'] as $role)if(isset($firstAttempts[$role])){
            $a=$firstAttempts[$role];
            if($a['row']['state']==='unknown')$report['reason']='stage0_'.$role.'_provider_outcome_unknown';
            if(($a['result']['provider_outcome']??null)==='rejected')$report['reason']='stage0_'.$role.'_provider_rejected';
            if($role==='reviewer'&&($a['result']['approved']??null)===false)$report['reason']='stage0_independent_review_rejected';
        }
        if($status['state']==='hold'&&$report['reason']==='first_acceptance_pending')$report['reason']='stage0_campaign_hold_without_acceptance';
        $report['safe_continuation']='read_same_original_attempt_only_no_retry';return $report;
    }
    $receipt=hotelJson(hotelRowBytes($receiptRow,'receipt_bytes','receipt_sha256',65536));
    if(($receipt['schema']??null)!=='hotel-server-acceptance-v1'||($receipt['campaign_id']??null)!==HOTEL_CAMPAIGN||($receipt['stage_index']??null)!==0
        ||($receipt['new_acceptance_credit']??null)!==1||($receipt['historical_accepted']??null)!==19||($receipt['inputs_sha256']??null)!==$inputPin
        ||($receipt['previous_baseline_sha256']??null)!==$originalBaseline||$status['new_accepted']<1||$status['stage_index']<1)hotelFail('first_acceptance_unconfirmed');
    hotelAdmission($receipt['retained_admission']??null);$newBaseline=hotelHash($receipt['baseline_sha256']??null);$candidateSha=hotelHash($receipt['candidate_sha256']??null);
    if($newBaseline===$originalBaseline||!isset($firstAttempts['producer'],$firstAttempts['reviewer']))hotelFail('first_acceptance_lineage_missing');
    $producer=$firstAttempts['producer'];$reviewer=$firstAttempts['reviewer'];
    if($producer['result']===null||$reviewer['result']===null||isset($producer['result']['provider_outcome'])||isset($reviewer['result']['provider_outcome'])
        ||$receipt['producer_attempt']!==$producer['row']['attempt_id']||$receipt['review_attempt']!==$reviewer['row']['attempt_id']
        ||$producer['row']['attempt_id']===$reviewer['row']['attempt_id']||$producer['row']['principal']===$reviewer['row']['principal']
        ||$producer['result']['provider_response_id']===$reviewer['result']['provider_response_id'])hotelFail('independent_provider_lineage_unconfirmed');
    $candidate=hotelCandidate($pdo,0,$receipt['producer_attempt'],$candidateSha,$plan['stages'][0]);
    if(HotelServerInputs::json($producer['result']['candidate']??null)!==$candidate['row']['candidate_bytes']
        ||($reviewer['request']['candidate_sha256']??null)!==$candidateSha||HotelServerInputs::json($reviewer['request']['candidate']??null)!==$candidate['row']['candidate_bytes'])hotelFail('candidate_provider_link_unconfirmed');
    $review=hotelOne($pdo,'SELECT campaign_id,stage_index,review_attempt,candidate_sha256,approved,review_bytes FROM hotel_server_reviews WHERE campaign_id=? AND stage_index=0',[HOTEL_CAMPAIGN]);
    if($review===null||(int)$review['approved']!==1||$review['review_attempt']!==$receipt['review_attempt']||$review['candidate_sha256']!==$candidateSha
        ||$review['review_bytes']!==$reviewer['row']['result_bytes']||($reviewer['result']['approved']??null)!==true
        ||($reviewer['result']['candidate_sha256']??null)!==$candidateSha||($reviewer['result']['criteria_sha256']??null)!==$criteria
        ||!is_string($reviewer['result']['evidence']??null)||trim($reviewer['result']['evidence'])==='')hotelFail('saved_approved_review_unconfirmed');
    $checks=$reviewer['result']['review_checks']??null;if(!is_array($checks)||!array_is_list($checks))hotelFail('independent_scope_review_unconfirmed');$checked=[];
    foreach($checks as $check){if(!is_array($check)||($check['approved']??null)!==true||!is_string($check['reason']??null)||trim($check['reason'])==='')hotelFail('independent_scope_review_unconfirmed');$checked[]=HotelServerInputs::key($check['scope']??[]);}
    sort($checked);if($checked!==$candidate['semantic_keys'])hotelFail('independent_scope_review_unconfirmed');
    $storedKeys=[];foreach($candidate['document']['records'] as $record){$key=HotelServerInputs::key($record['scope']);
        $saved=hotelOne($pdo,'SELECT semantic_key,campaign_id,candidate_sha256,record_bytes FROM hotel_server_records WHERE semantic_key=?',[$key]);
        if($saved===null||$saved['campaign_id']!==HOTEL_CAMPAIGN||$saved['candidate_sha256']!==$candidateSha||$saved['record_bytes']!==HotelServerInputs::json($record))hotelFail('new_accepted_record_not_saved');$storedKeys[]=$key;}
    $delta=hotelOne($pdo,'SELECT campaign_id,baseline_sha256,parent_sha256,document_bytes FROM hotel_server_baselines WHERE campaign_id=? AND baseline_sha256=?',[HOTEL_CAMPAIGN,$newBaseline]);
    if($delta===null||$delta['parent_sha256']!==$originalBaseline||hotelRowBytes($delta,'document_bytes','baseline_sha256',1048576)!==HotelServerInputs::json([
        'schema'=>'hotel-server-baseline-v1','parent_sha256'=>$originalBaseline,'candidate_sha256'=>$candidateSha,'records'=>$candidate['document']['records']]))hotelFail('accepted_baseline_not_saved');
    foreach(['producer','reviewer'] as $role){$out=hotelOutbox($pdo,0,$role);if($out===null||$out['state']!=='done'||$out['attempt_id']!==$firstAttempts[$role]['row']['attempt_id'])hotelFail('first_outbox_lineage_unconfirmed');}
    $report['first_new_acceptance_confirmed']=true;$report['first_acceptance']=['receipt_sha256'=>$receiptRow['receipt_sha256'],'receipt'=>$receipt,
        'candidate_sha256'=>$candidateSha,'saved_record_count'=>count($storedKeys),'saved_record_semantic_keys'=>$storedKeys,'baseline_sha256'=>$newBaseline,
        'producer'=>$producer['proof'],'reviewer'=>$reviewer['proof'],'new_acceptance_credit'=>1];
    $nextOut=hotelOutbox($pdo,1,'producer');$report['next_outbox']=$nextOut;$report['reason']='next_producer_not_created';
    $nextRow=hotelOne($pdo,'SELECT attempt_id,campaign_id,stage_index,role,principal,state,baseline_sha256,request_sha256,request_bytes,result_sha256,result_bytes FROM hotel_server_attempts WHERE campaign_id=? AND stage_index=1 AND role=?',[HOTEL_CAMPAIGN,'producer']);
    if($nextRow===null)return $report;
    $report['next_producer_attempt_exists']=true;$next=hotelAttempt($pdo,$nextRow,1,'producer',$newBaseline,$inputPin,$plan,$policy,$revision,$criteria);
    $report['next_producer']=$next['proof'];$report['reason']='next_provider_outcome_unknown';
    if($nextOut===null||$nextOut['attempt_id']!==$nextRow['attempt_id'])hotelFail('next_outbox_attempt_unconfirmed');
    if($next['result']===null)return $report;
    if($nextOut['state']!=='done'||in_array($next['result']['provider_response_id'],[$producer['result']['provider_response_id'],$reviewer['result']['provider_response_id']],true))hotelFail('next_actual_provider_lineage_unconfirmed');
    $report['next_provider_call_confirmed']=true;$report['reason']='next_provider_rejected';
    if(isset($next['result']['provider_outcome']))return $report;
    $nextCandidate=$next['result']['candidate']??null;if(!is_array($nextCandidate))hotelFail('next_candidate_unconfirmed');
    $nextCandidateSha=hash('sha256',HotelServerInputs::json($nextCandidate));
    $savedNext=hotelCandidate($pdo,1,$nextRow['attempt_id'],$nextCandidateSha,$plan['stages'][1]);
    if($savedNext['row']['candidate_bytes']!==HotelServerInputs::json($nextCandidate))hotelFail('next_candidate_not_saved');
    $report['next_candidate_saved']=true;$report['next_candidate_sha256']=$nextCandidateSha;
    $report['next_candidate_saved_record_count']=count($savedNext['document']['records']);$report['reason']='cron_trigger_not_corroborated';
    $health=HotelWorkHealth::snapshot();$report['cron_health']=$health;$handoffAt=strtotime((string)($intent['started_at']??''));$lastSuccess=strtotime((string)($health['last_success_at']??''));
    $cron=($health['available']??null)===true&&($health['stale']??null)===false&&($health['trigger']??null)==='cron'
        &&in_array($health['state']??null,['running','ok'],true)&&$handoffAt!==false&&$lastSuccess!==false&&$lastSuccess>=$handoffAt;
    $report['cron_trigger_corroborated']=$cron;
    if(!in_array($status['state'],['ready','candidate','reviewed','unknown','complete'],true)||$status['enabled']!==true){$report['reason']=$report['current_attention_reason']??'current_campaign_requires_review';return $report;}
    if(!$cron)return $report;
    $report['state']='confirmed';$report['reason']='first_acceptance_and_actual_next_producer_confirmed';return $report;
}

try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';$run=getenv('HOTEL_OPERATION_RUN_ID');
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$app||!function_exists('posix_geteuid')||posix_geteuid()!==1012)hotelFail('target_unconfirmed');
    if(!is_string($run)||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$run))hotelFail('invocation_unconfirmed');
    $report['github_run_id']=$run;$report['installed_sha']=hotelInstalled($app);hotelSeal(['state'=>'preflight','operator_provider_calls'=>0]);
    $root=dirname($app).'/max-search-bot-hotel-server';$work=$root.'/handoff-20261102-b02';hotelDir($root);hotelDir($work);
    $intent=hotelJson(hotelRead($work.'/intent.json',65536));$preflight=hotelJson(hotelRead($work.'/preflight.json',65536));
    $bootstrapRead=hotelJson(hotelRead($work.'/bootstrap-next.readback.json',65536));
    $switch=hotelJson(hotelRead($work.'/runtime-switch.readback.json',65536));$enabled=hotelJson(hotelRead($work.'/enable.readback.json',65536));
    $bootstrap=$bootstrapRead['disabled']??null;
    if(($intent['schema']??null)!==HOTEL_HANDOFF_KIND||($intent['operation_id']??null)!==HOTEL_OPERATION
        ||($intent['predecessor_id']??null)!==HOTEL_PREDECESSOR||($intent['successor_id']??null)!==HOTEL_CAMPAIGN
        ||($intent['installed_sha']??null)!==HOTEL_EXPECTED_INSTALLED_SHA||!is_string($intent['github_run_id']??null)
        ||!preg_match('/\A[1-9][0-9]{6,20}\z/D',$intent['github_run_id'])||!is_array($bootstrap))hotelFail('handoff_identity_unconfirmed');
    $report['handoff_github_run_id']=$intent['github_run_id'];
    $publicRaw=hotelRead($work.'/public-payload.json',131072,101645,HOTEL_PUBLIC_PAYLOAD_SHA);$public=hotelJson($publicRaw);
    hotelKeys($public,['schema','predecessor_id','successor_id','delta','plan','policy','budget_attestation']);
    if(($public['schema']??null)!=='hotel-server-public-handoff-v1'||$public['predecessor_id']!==HOTEL_PREDECESSOR||$public['successor_id']!==HOTEL_CAMPAIGN)hotelFail('public_handoff_identity_unconfirmed');
    $plan=$public['plan'];$policy=$public['policy'];$budget=$public['budget_attestation'];$delta=$public['delta'];
    hotelKeys($delta,['corpus','criteria','map','archives','instructions','stage_contexts']);
    $expectedRuntime=hotelHash($switch['runtime_sha256']??null);$expectedBootstrap=hotelHash($intent['expected_bootstrap_sha256']??null);
    if(($switch['campaign_id']??null)!==HOTEL_CAMPAIGN||($switch['bootstrap_sha256']??null)!==$expectedBootstrap||($switch['enabled_campaigns']??null)!==0
        ||($bootstrapRead['seed_sha256']??null)!==($intent['expected_seed_sha256']??null)||($bootstrapRead['enabled_campaigns']??null)!==0
        ||($bootstrapRead['operator_provider_calls']??null)!==0||($preflight['operator_provider_calls']??null)!==0||($preflight['producer_preflights']??null)!==4
        ||($bootstrapRead['accepted_facts_sha256']??null)!==($preflight['accepted_facts_sha256']??null)
        ||($bootstrapRead['accepted_facts_count']??null)!==($preflight['accepted_facts_count']??null)
        ||($enabled['campaign_id']??null)!==HOTEL_CAMPAIGN||($enabled['state']??null)!=='ready'||($enabled['enabled']??null)!==true
        ||($enabled['stage_index']??null)!==0||($enabled['new_accepted']??null)!==0||($enabled['bootstrap_sha256']??null)!==$expectedBootstrap
        ||($enabled['baseline_sha256']??null)!==($intent['expected_seed_sha256']??null))hotelFail('handoff_readback_unconfirmed');
    require_once $app.'/config.php';require_once $app.'/services/ProjectAccessService.php';ProjectAccessService::initializeReadOnly();$pdo=ConversationDb::connection();
    if($pdo->inTransaction()||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')hotelFail('application_database_unconfirmed');
    require_once $app.'/services/HotelServerCycle.php';require_once $app.'/services/HotelServerRuntimeConfig.php';
    require_once $app.'/services/HotelServerAiProvider.php';require_once $app.'/services/HotelWorkHealth.php';
    foreach(['plan'=>HOTEL_PLAN_SHA,'policy'=>HOTEL_POLICY_SHA,'budget_attestation'=>HOTEL_BUDGET_SHA,'delta'=>HOTEL_DELTA_SHA] as $name=>$pin)
        if(!is_array($public[$name])||hash('sha256',HotelServerInputs::json($public[$name]))!==$pin)hotelFail('public_handoff_pin_unconfirmed');
    if(HotelServerInputs::json($public)!==$publicRaw||($intent['public_payload_sha256']??null)!==HOTEL_PUBLIC_PAYLOAD_SHA
        ||($intent['plan_sha256']??null)!==HOTEL_PLAN_SHA||($intent['policy_sha256']??null)!==HOTEL_POLICY_SHA
        ||($intent['budget_sha256']??null)!==HOTEL_BUDGET_SHA||($intent['delta_sha256']??null)!==HOTEL_DELTA_SHA
        ||($bootstrapRead['predecessor_evidence_sha256']??null)!==hash('sha256',HotelServerInputs::json($preflight['predecessor']??null)))hotelFail('handoff_journal_pin_unconfirmed');
    $operationIdentity=['schema'=>HOTEL_HANDOFF_KIND,'predecessor_id'=>HOTEL_PREDECESSOR,'predecessor_bootstrap_sha256'=>HOTEL_OLD_BOOTSTRAP,
        'predecessor_baseline_sha256'=>HOTEL_OLD_BASELINE,'predecessor_runtime_sha256'=>HOTEL_OLD_RUNTIME,
        'successor_id'=>HOTEL_CAMPAIGN,'public_payload_sha256'=>HOTEL_PUBLIC_PAYLOAD_SHA];
    if(hash('sha256',HotelServerInputs::json($operationIdentity))!==HOTEL_OPERATION)hotelFail('handoff_operation_pin_unconfirmed');
    foreach($operationIdentity as $name=>$value)if(($intent[$name]??null)!==$value)hotelFail('handoff_operation_pin_unconfirmed');
    // Bind the exact existing canonical log path in this process. The default
    // DiagnosticLogger::filePath() branch mkdir/chmods its parent even on reads.
    // This in-memory override avoids both writes; it changes no application file.
    $logDir=dirname($app).'/max-search-bot-private-logs';
    DiagnosticLogger::setFile($logDir.'/structured_events.log');
    // Loading the provider class supplies only its static immutable instructions. No provider object/send/ready is used.
    $runtime=HotelServerRuntimeConfig::load($app);if($runtime===null||$runtime['campaign_id']!==HOTEL_CAMPAIGN
        ||HotelServerInputs::json($runtime['policy'])!==HotelServerInputs::json($policy)
        ||$runtime['flags']!==['dispatch_allowed'=>true,'enabled'=>true,'replacement_dispatch_allowed'=>true])hotelFail('runtime_pin_unconfirmed');
    $runtimePin=$runtime['configuration_sha256'];
    if($runtimePin!==$expectedRuntime||$runtime['bootstrap_sha256']!==$expectedBootstrap)hotelFail('actual_handoff_runtime_unconfirmed');
    // Next-transaction characteristics only. This changes no persistent or session configuration.
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    if(!$pdo->inTransaction())hotelFail('read_only_snapshot_unconfirmed');
    $readback=hotelReadback($pdo,$runtime,$intent,$preflight,$bootstrap,$plan,$policy,$budget,$delta);
    $pdo->rollBack();
    hotelInstalled($app);$after=HotelServerRuntimeConfig::load($app);if(($after['configuration_sha256']??null)!==$runtimePin)hotelFail('runtime_changed_during_readback');
    $report=array_replace($report,$readback);
}catch(Throwable $e){
    $message=$e->getMessage();$report['state']='HOLD';$report['reason']=preg_match('/\A[a-z][a-zA-Z0-9_]{1,100}\z/D',$message)?$message:'readback_unconfirmed';
}finally{
    if($pdo instanceof PDO&&$pdo->inTransaction())try{$pdo->rollBack();}catch(Throwable $ignored){}
    while(ob_get_level()>$level)ob_end_clean();
}
try{
    $envelope=($report['reason']??null)==='target_unconfirmed'
        ?['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'target_unconfirmed','operator_provider_calls'=>0]:hotelSeal($report);
}catch(Throwable $ignored){$envelope=['operation'=>HOTEL_STATUS_KIND,'state'=>'HOLD','reason'=>'sealed_readback_result_unconfirmed','operator_provider_calls'=>0];}
echo json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($report['state']==='confirmed'&&$envelope['state']==='sealed'?0:1);
