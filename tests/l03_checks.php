<?php
declare(strict_types=1);
define('MANAGER_AI_BULK_LIBRARY_ONLY',true);
define('MANAGER_AI_DRAFT_MODEL','gpt-6-luna');
define('MANAGER_AI_DRAFT_REASONING',$argv[1]??'medium');
define('MANAGER_AI_DRAFT_SERVICE_TIER','default');
define('MANAGER_AI_L03_SOURCE',json_decode(file_get_contents(dirname(__DIR__).'/tools/l03_source.json'),true,32,JSON_THROW_ON_ERROR));
require dirname(__DIR__).'/services/ManagerAiDraftService.php';require dirname(__DIR__).'/tools/l03_eval.php';
$checks=0;
function callL03(string $method,...$args){return (new ReflectionMethod(ManagerAiBulkEval::class,$method))->invoke(null,...$args);}
function checkL03(bool $yes,string $what):void{global $checks;if(!$yes)throw new RuntimeException('l03_check_failed:'.$what);++$checks;}
function rejectL03(callable $f,string $what):void{$no=false;try{$f();}catch(Throwable $e){$no=true;}checkL03($no,$what);}
function resultL03():array{return ['ok'=>true,'should_draft'=>true,'draft'=>'На какие даты планируете поездку?','next_action'=>'clarify','reason'=>'Даты не указаны.','checks'=>[],'evidence_message_ids'=>[7],'history_incomplete'=>false];}
function neutralL03(int $index=1):array{return ['requested_model'=>'gpt-6-luna','requested_effort'=>MANAGER_AI_DRAFT_REASONING,'case_id'=>'L03-'.str_pad((string)$index,3,'0',STR_PAD_LEFT),'context'=>['conversation_id'=>2000000+$index,'as_of_message_id'=>7,'messages'=>[['id'=>7,'sender_type'=>'customer','text'=>'Нужен тур на двоих.','created_at'=>'2020-01-01T00:00:00Z','media_unread'=>false,'selections_link_unread'=>false]],'history_incomplete'=>false,'media_unread'=>false,'selections_link_unread'=>false,'verified_company_facts'=>[]],'provenance'=>['kind'=>'authored_synthetic_transport_fixture','control'=>'neutral_test']];}
checkL03(ManagerAiBulkEval::CASES===38&&ManagerAiBulkEval::OUTPUT_TOKEN_RESERVATION===311296&&ManagerAiBulkEval::MAX_PARALLELISM===1,'frozen38_budget');
$body=ManagerDraftClient::requestBody('',[]);checkL03($body['max_output_tokens']===8192&&$body['store']===false,'reasoning_headroom_no_store');
checkL03(callL03('requestedProfile',$body)===['model'=>'gpt-6-luna','reasoning_effort'=>MANAGER_AI_DRAFT_REASONING,'service_tier'=>'default'],'explicit_effort_and_standard');
foreach([['model'=>'gpt-6.1-sol'],['reasoning'=>['effort'=>'low']],['reasoning'=>['effort'=>'xhigh']],['service_tier'=>'fast'],['max_output_tokens'=>2200],['store'=>true]]as$delta)rejectL03(static fn()=>callL03('requestedProfile',array_replace($body,$delta)),'invalid_profile_rejected');
callL03('validateCase',neutralL03(),time(),1);checkL03(true,'five_field_case');
$bad=neutralL03();$bad['requested_effort']='low';rejectL03(static fn()=>callL03('validateCase',$bad,time(),1),'old_low_not_replayed');
$seen=null;$calls=0;$res=callL03('runCase',neutralL03(),1,'generate',static function($i,$p,$o)use(&$seen,&$calls){++$calls;$seen=[$i,$p];return resultL03();});
checkL03($calls===1&&$res['provider_calls']===1&&$res['outgoing_requested_profile']['reasoning_effort']===MANAGER_AI_DRAFT_REASONING,'one_attempt_actual_effort');
checkL03(hash('sha256',$seen[0])===MANAGER_AI_L03_SOURCE['candidate_instructions_sha256']&&!isset($seen[1]['requested_effort'])&&!isset($seen[1]['provenance']),'same_prompt_business_payload_only');
$pre=callL03('preflightCase',neutralL03(),1,time());checkL03($pre['provider_planned']&&$pre['canonical_request_sha256']===$res['outgoing_canonical_request_sha256']&&$pre['normalized_context']===$seen[1],'preflight_wire_equals_actual');
$c=neutralL03();$c['context']['messages'][0]['text']='Вопрос +7 999 111 22 33 a@example.test https://qui-quo.ru/AAAA-0001';$v=callL03('caseProjection',$c);checkL03($v['redactor_changed_count']===1&&!str_contains($v['context']['messages'][0]['text'],'example.test'),'same_redaction');
$office=neutralL03();$office['context']['messages'][0]['text']='Где у вас офис?';$n=0;$r=callL03('runCase',$office,1,'generate',static function()use(&$n){++$n;return resultL03();});checkL03($n===0&&$r['provider_calls']===0,'policy_has_no_fictitious_call');
callL03('validateBudget',MANAGER_AI_L03_SOURCE);checkL03(MANAGER_AI_L03_SOURCE['reservation_microusd']<1000000,'all38_static_reservations_below_dollar');
$bad=MANAGER_AI_L03_SOURCE;$bad['attempt_bindings'][0]['effort']='low';rejectL03(static fn()=>callL03('validateBudget',$bad),'effort_tamper_rejected');
$bad=MANAGER_AI_L03_SOURCE;$bad['attempt_bindings'][0]['reservation_microusd']-=1;rejectL03(static fn()=>callL03('validateBudget',$bad),'money_tamper_rejected');
checkL03(callL03('settledReservation',['provider_calls'=>null],'gpt-6-luna',10000)===10000,'unknown_retains_reservation');
checkL03(!callL03('canLaunch',time()+100,hrtime(true)+2400000000000)&&callL03('canLaunch',time()+3600,hrtime(true)+2400000000000),'ninety_second_launch_margin');
$dir=sys_get_temp_dir().'/l03-test-'.bin2hex(random_bytes(12));mkdir($dir,0700);
try{$cases=[neutralL03(1),neutralL03(2),neutralL03(3)];$r=callL03('parallel',$cases,1,'generate',$dir,static function(){},static fn()=>resultL03());checkL03(count($r)===3&&count(glob($dir.'/result-*.json'))===3,'durable_single_worker_results');foreach($r as$v)checkL03($v['provider_calls']===1&&$v['result']['ok']===true,'exact_one_no_retry');rejectL03(static fn()=>callL03('parallel',$cases,1,'generate',$dir,static function(){},static fn()=>resultL03()),'started_journal_blocks_replay');}finally{foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
$dir=sys_get_temp_dir().'/l03-test-'.bin2hex(random_bytes(12));mkdir($dir,0700);
try{$r=callL03('parallel',[neutralL03(1),neutralL03(2)],1,'generate',$dir,static function(){},static function(){exit(2);});checkL03(count($r)===1&&$r[0]['provider_calls']===null,'unknown_stops');}finally{foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
checkL03(!defined('OPENAI_API_KEY')&&!class_exists('ConversationDb',false),'no_secret_or_database_loaded');
echo 'L03 '.MANAGER_AI_DRAFT_REASONING.' isolated checks PASS: '.$checks.'; 0 real provider calls'.PHP_EOL;
