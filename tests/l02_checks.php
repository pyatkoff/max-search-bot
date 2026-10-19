<?php
declare(strict_types=1);
define('MANAGER_AI_BULK_LIBRARY_ONLY',true);
define('MANAGER_AI_DRAFT_MODEL',$argv[1]??'gpt-6-luna');
define('MANAGER_AI_DRAFT_REASONING','low');
define('MANAGER_AI_DRAFT_SERVICE_TIER','default');
define('MANAGER_AI_L02_SOURCE',json_decode(file_get_contents(dirname(__DIR__).'/tools/l02_source.json'),true,16,JSON_THROW_ON_ERROR));
require dirname(__DIR__).'/services/ManagerAiDraftService.php';
require dirname(__DIR__).'/tools/l02_eval.php';
$checksBulk=0;
function callBulk(string $method,...$args){return (new ReflectionMethod(ManagerAiBulkEval::class,$method))->invoke(null,...$args);}
function checkBulk(bool $yes,string $name):void{global $checksBulk;if(!$yes)throw new RuntimeException('l02_synthetic_failed:'.$name);++$checksBulk;}
function rejectBulk(callable $call,string $name):void{$rejected=false;try{$call();}catch(Throwable $ignored){$rejected=true;}checkBulk($rejected,$name);}
function resultBulk():array{return ['ok'=>true,'should_draft'=>true,'draft'=>'На какие даты планируете поездку?','next_action'=>'clarify','reason'=>'Не названы даты.','checks'=>[],'evidence_message_ids'=>[7],'history_incomplete'=>false];}
function neutralBulk(int $index=1):array{return ['requested_model'=>MANAGER_AI_DRAFT_MODEL,'case_id'=>'L02-'.str_pad((string)$index,3,'0',STR_PAD_LEFT),
    'context'=>['conversation_id'=>2000000+$index,'as_of_message_id'=>7,
        'messages'=>[['id'=>7,'sender_type'=>'customer','text'=>'Нужен тур на двоих.','event_type'=>'message',
            'created_at'=>'2020-01-01T00:00:00Z','media_unread'=>false,'selections_link_unread'=>false]],
        'history_incomplete'=>false,'media_unread'=>false,'selections_link_unread'=>false,'verified_company_facts'=>[]],
    'provenance'=>['kind'=>'authored_synthetic_transport_fixture','control'=>'neutral_unit_control']];}
checkBulk(ManagerAiBulkEval::CASES===24&&ManagerAiBulkEval::MAX_PARALLELISM===1
    &&ManagerAiBulkEval::MAX_DURATION_MS===2400000&&ManagerAiBulkEval::TTL===3600
    &&ManagerAiBulkEval::OUTPUT_TOKEN_RESERVATION===52800&&ManagerDraftClient::MAX_REQUEST_BYTES===196608,'fixed11_budget_and_timing');
$body=ManagerDraftClient::requestBody('',[]);
checkBulk(callBulk('requestedProfile',$body)===['model'=>MANAGER_AI_DRAFT_MODEL,'reasoning_effort'=>'low','service_tier'=>'default'],'standard_actual_client_projection');
foreach ([['model'=>'other'],['service_tier'=>'fast'],['service_tier'=>'priority'],['reasoning'=>['effort'=>'high']],['max_output_tokens'=>2201],['store'=>true]] as $change)
    rejectBulk(static fn()=>callBulk('requestedProfile',array_replace($body,$change)),'all_profile_deviations_refused');
$calls=0;$seen='';$payload=[];
$r=callBulk('runCase',neutralBulk(),1,'generate',static function($i,$p,$o)use(&$calls,&$seen,&$payload){++$calls;$seen=$i;$payload=$p;return resultBulk();});
checkBulk($calls===1&&$r['provider_calls']===1&&$seen===ManagerAiDraftService::instructions(),'canonical_candidate_prompt');
checkBulk(!isset($payload['provenance'])&&!isset($payload['case_id'])&&$payload['verified_company_facts']===[]&&!isset($payload['selection_snapshots']),'private_metadata_excluded');
$wire=json_encode(ManagerDraftClient::requestBody($seen,$payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
checkBulk($r['outgoing_requested_profile']['service_tier']==='default'&&$r['outgoing_canonical_request_sha256']===hash('sha256',$wire),'guarded_actual_request_hash_and_standard');
checkBulk(is_int($r['provider_roundtrip_ms'])&&$r['provider_roundtrip_ms']>=0&&$r['elapsed_scope']==='service_total'&&is_int($r['context_projection_ms']),'separate_real_timing_scopes');
$preflight=callBulk('preflightCase',neutralBulk(),1,time());
checkBulk($preflight['provider_planned']&&$preflight['canonical_request_encoded_bytes']===strlen($wire)
    &&$preflight['canonical_request_sha256']===$r['outgoing_canonical_request_sha256']
    &&$preflight['instructions_sha256']===MANAGER_AI_L02_SOURCE['candidate_instructions_sha256'],'preflight_and_actual_wire_identical');
$redacted=neutralBulk();$redacted['context']['messages'][0]['text']='Бюджет150000-250000₽; user@example.test, +7 999 111 22 33; https://qui-quo.ru/AAAA-0001';
$before=$redacted;$projection=callBulk('caseProjection',$redacted);
checkBulk($redacted===$before&&$projection['redactor_changed_count']===1&&str_contains($projection['context']['messages'][0]['text'],'150000-250000₽')
    &&!str_contains($projection['context']['messages'][0]['text'],'example.test')&&$projection['context']['selections_link_unread'],'redactor_preserves_budget_and_detaches');
$seenPayload=null;
callBulk('runCase',$redacted,1,'generate',static function($i,$p)use(&$seenPayload){$seenPayload=$p;return resultBulk();});
$redactPreflight=callBulk('preflightCase',$redacted,1,time());
checkBulk($redactPreflight['normalized_context']===$seenPayload&&$redactPreflight['payload_sha256']===hash('sha256',json_encode($seenPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'same_pure_projection_before_and_during_execution');
$office=neutralBulk();$office['context']['messages'][0]['text']='Где у вас офис?';$n=0;
$r=callBulk('runCase',$office,1,'generate',static function()use(&$n){++$n;return resultBulk();});
checkBulk($n===0&&$r['provider_calls']===0&&$r['result_origin']==='policy'&&$r['provider_roundtrip_ms']===null
    &&$r['outgoing_requested_profile']===null&&$r['outgoing_canonical_request_sha256']===null,'policy_has_no_fictitious_actual_request');
checkBulk(!callBulk('preflightCase',$office,1,time())['provider_planned'],'policy_capture_explicit_counterfactual');
$r=callBulk('runCase',neutralBulk(),1,'generate',static function(){throw new RuntimeException('synthetic_private_error');});
checkBulk($r['provider_calls']===1&&is_int($r['provider_roundtrip_ms'])&&$r['result']['error']==='provider_unavailable'&&!str_contains(json_encode($r),'synthetic_private_error'),'failure_no_private_exception_leak_or_retry');
$historical=neutralBulk();$historical['context']['conversation_id']=330001;
$historical['provenance']=['kind'=>'prepared_anex_l02_development','exact_context_reused'=>true,
    'future_messages_added'=>0,'source_input_id'=>'PX43-01','source_input_sha256'=>str_repeat('a',64),'context_sha256'=>callBulk('stableHash',$historical['context'])];
callBulk('validateCase',$historical,time(),1);checkBulk(true,'historical_context_hash_valid');
$historical['context']['messages'][0]['text']='Changed';
rejectBulk(static fn()=>callBulk('validateCase',$historical,time(),1),'historical_context_change_rejected');
foreach ([
    static function($c){$c['case_id']='L02-002';return $c;},
    static function($c){$c['provenance']['kind']='k02_development_prefix';return $c;},
    static function($c){$c['context']['conversation_id']=2000002;return $c;},
    static function($c){$c['selection_fixture']=[];return $c;},
    static function($c){$c['context']['verified_company_facts']=null;return $c;},
    static function($c){$c['context']['verified_company_facts']=[['text'=>'invented']];return $c;},
    static function($c){$c['context']['messages'][0]['future_outcome']='paid';return $c;},
    static function($c){$c['context']['messages'][0]['created_at']='2099-01-01T00:00:00Z';return $c;},
] as $change)rejectBulk(static fn()=>callBulk('validateCase',$change(neutralBulk()),time(),1),'strict_context_and_provenance');
rejectBulk(static fn()=>callBulk('runCase',neutralBulk(),1,'grade',static fn()=>[]),'grader_disabled');
$cases=[];for($i=1;$i<=24;++$i){$cases[]=neutralBulk($i);callBulk('validateCase',end($cases),time(),$i);}
checkBulk(callBulk('proposalHash',$cases)!==MANAGER_AI_L02_SOURCE['proposal_inputs_sha256'],'neutral_tests_are_not_actual_frozen_membership');
$now=time();$options=['task-token'=>str_repeat('d',48),'production-sha'=>MANAGER_AI_L02_SOURCE['production_sha'],
    'candidate-sha'=>MANAGER_AI_L02_SOURCE['candidate_sha'],'bundle-sha256'=>str_repeat('f',64),'recipient-sha256'=>str_repeat('0',64),'case-count'=>'24','parallelism'=>'1','stage'=>'generate'];
$lease=['lease_id'=>str_repeat('1',32),'created_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-5),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$now+3595)];
$input=['schema'=>ManagerAiBulkEval::INPUT_SCHEMA,'repository'=>'pyatkoff/max-search-bot','task_token'=>$options['task-token'],
    'production_sha'=>$options['production-sha'],'candidate_sha'=>$options['candidate-sha'],'source_bundle_sha256'=>$options['bundle-sha256'],
    'output_recipient_sha256'=>$options['recipient-sha256'],'lease_id'=>$lease['lease_id'],'stage'=>'generate',
    'generated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'expires_at'=>$lease['expires_at'],'cases'=>$cases,
    'proposal_inputs_sha256'=>MANAGER_AI_L02_SOURCE['proposal_inputs_sha256'],'criteria_sha256'=>MANAGER_AI_L02_SOURCE['criteria_sha256'],
    'selection_freeze_sha256'=>MANAGER_AI_L02_SOURCE['selection_freeze_sha256']];
rejectBulk(static fn()=>callBulk('validateInput',$input,$options,$lease),'unfrozen_well_formed_replacement_proposal_rejected');
checkBulk(!callBulk('canLaunch',time()+30,hrtime(true)+2400000000000)&&!callBulk('canLaunch',time()+3600,hrtime(true)+29000000000)
    &&callBulk('canLaunch',time()+3600,hrtime(true)+2400000000000),'launch_margin');
function temporaryBulk(callable $f):void{$d=sys_get_temp_dir().'/manager-l02-synthetic-'.bin2hex(random_bytes(10));mkdir($d,0700);try{$f($d);}finally{foreach(glob($d.'/*')?:[] as $p)unlink($p);rmdir($d);}}
temporaryBulk(static function($d)use($cases){
    file_put_contents($d.'/counter','0,0');$output=[];
    $records=callBulk('parallel',array_slice($cases,0,3),1,'generate',$d,static function($r)use(&$output){$output[]=$r;},static function()use($d){
        $h=fopen($d.'/counter','r+');flock($h,LOCK_EX);[$n,$peak]=array_map('intval',explode(',',stream_get_contents($h)));++$n;$peak=max($peak,$n);rewind($h);ftruncate($h,0);fwrite($h,$n.','.$peak);fflush($h);flock($h,LOCK_UN);fclose($h);usleep(10000);
        $h=fopen($d.'/counter','r+');flock($h,LOCK_EX);[$n,$peak]=array_map('intval',explode(',',stream_get_contents($h)));--$n;rewind($h);ftruncate($h,0);fwrite($h,$n.','.$peak);fflush($h);flock($h,LOCK_UN);fclose($h);return resultBulk();});
    checkBulk(count($records)===3&&count($output)===3&&count(glob($d.'/result-*.json'))===3,'durable_one_worker_records');
    [$n,$peak]=array_map('intval',explode(',',file_get_contents($d.'/counter')));checkBulk($n===0&&$peak===1,'one_active_worker');
    foreach($records as $r)checkBulk($r['provider_calls']===1&&$r['result']['ok']===true,'one_call_no_retry');
    rejectBulk(static fn()=>callBulk('parallel',array_slice($cases,0,3),1,'generate',$d,static function(){},static fn()=>resultBulk()),'started_ledger_blocks_replay');
    rejectBulk(static fn()=>callBulk('parallel',array_fill(0,15,neutralBulk()),1,'generate',$d,static function(){}),'case15_refused');
    rejectBulk(static fn()=>callBulk('parallel',array_slice($cases,0,3),2,'generate',$d,static function(){}),'two_workers_refused');
});
temporaryBulk(static function($d)use($cases){
    $records=callBulk('parallel',$cases,1,'generate',$d,static function(){},static function(){exit(2);});
    checkBulk(count($records)===1&&$records[0]['provider_calls']===null&&$records[0]['result_origin']==='unknown'
        &&count(glob($d.'/started-*.json'))===1,'unknown_halts_without_replay');
});
$budget=MANAGER_AI_L02_SOURCE;callBulk('validateBudget',$budget);checkBulk(true,'exact_dollar_bound_verified');
foreach ([static function($v){$v['budget_cap_microusd']=1000001;return $v;},static function($v){$v['reservation_microusd']-=1;return $v;},static function($v){$v['attempt_bindings'][0]['reservation_microusd']-=1;return $v;},static function($v){$v['attempt_bindings'][0]['request_bytes']=50000;return $v;},static function($v){$v['attempt_bindings'][0]['model']='other';return $v;}] as $mutate)rejectBulk(static fn()=>callBulk('validateBudget',$mutate($budget)),'money_or_model_bound_tamper_refused');
checkBulk(!class_exists('ConversationDb',false)&&!defined('OPENAI_API_KEY'),'no_database_or_credentials');
$budgetRecord=['provider_calls'=>1,'provider_metadata'=>['model'=>MANAGER_AI_DRAFT_MODEL,'service_tier'=>'default','usage'=>['input_tokens'=>100,'output_tokens'=>200,'input_tokens_details'=>['cached_tokens'=>50]]]];
checkBulk(callBulk('settledReservation',$budgetRecord,MANAGER_AI_DRAFT_MODEL,20000)<20000,'known_usage_releases_only_proven_reservation');
$budgetRecord['provider_metadata']['service_tier']='fast';
checkBulk(callBulk('settledReservation',$budgetRecord,MANAGER_AI_DRAFT_MODEL,20000)===20000,'other_tier_retains_reservation');
checkBulk(callBulk('settledReservation',['provider_calls'=>null],MANAGER_AI_DRAFT_MODEL,20000)===20000,'unknown_retains_full_reservation');
temporaryBulk(static function($d)use($cases){
    $records=callBulk('parallel',$cases,1,'generate',$d,static function(){},static fn()=>resultBulk(),time()+3600,hrtime(true)+2400000000000,true);
    $held=0;foreach($records as $i=>$record){$held+=MANAGER_AI_L02_SOURCE['attempt_bindings'][$i]['reservation_microusd'];}
    checkBulk(count($records)>0&&count($records)<24&&$held<=1000000,'rolling_budget_halts_before_cap_with_unknown_usage');
    checkBulk($held+MANAGER_AI_L02_SOURCE['attempt_bindings'][count($records)]['reservation_microusd']>1000000,'next_reservation_would_exceed_cap');
});
echo 'L02 Standard synthetic checks PASS: '.$checksBulk.' checks; exact24/1/default, rolling dollar cap, frozen membership, no retry'.PHP_EOL;
