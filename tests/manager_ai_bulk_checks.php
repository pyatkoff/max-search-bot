<?php
declare(strict_types=1);
define('MANAGER_AI_BULK_LIBRARY_ONLY',true);
require dirname(__DIR__).'/services/ManagerAiDraftService.php';
require dirname(__DIR__).'/tools/manager_ai_bulk.php';
$checksBulk=0;
function callBulk(string $method,...$args){return (new ReflectionMethod(ManagerAiBulkEval::class,$method))->invoke(null,...$args);}
function checkBulk(bool $yes,string $name):void{global $checksBulk;if(!$yes)throw new RuntimeException('k03_synthetic_failed:'.$name);++$checksBulk;}
function rejectBulk(callable $call,string $name):void{$rejected=false;try{$call();}catch(Throwable $ignored){$rejected=true;}checkBulk($rejected,$name);}
function resultBulk():array{return ['ok'=>true,'should_draft'=>true,'draft'=>'На какие даты планируете поездку?','next_action'=>'clarify','reason'=>'Не названы даты.','checks'=>[],'evidence_message_ids'=>[7],'history_incomplete'=>false];}
function contextBulk(int $index=1):array{return ['conversation_id'=>1000000+$index,'as_of_message_id'=>7,
    'messages'=>[['id'=>7,'sender_type'=>'customer','text'=>'Нужен тур на двоих.','event_type'=>'message',
        'created_at'=>'2020-01-01T00:00:00Z','media_unread'=>false,'selections_link_unread'=>false]],
    'history_incomplete'=>false,'media_unread'=>false,'selections_link_unread'=>false];}
function bindBulk(array $case):array{$case['provenance']['projected_prefix_sha256']=callBulk('projectedPrefixHash',$case);return $case;}
function historicalBulk(int $index=1):array{return bindBulk(['case_id'=>'H'.$index,'context'=>contextBulk($index),
    'provenance'=>['kind'=>'k02_development_prefix','cutoff_at'=>'2020-01-01T00:00:00Z',
        'source_sha256'=>str_repeat('a',64),'source_alias'=>'TG'.(9999000+$index),'cutoff_ordinal'=>1,
        'source_ordinals'=>[1],'source_message_ids'=>[7],'source_epoch_seconds'=>[1577836800],
        'source_prefix_sha256'=>str_repeat('b',64),'projected_prefix_sha256'=>str_repeat('0',64),
        'delivery_revision'=>'synthetic-r1','selection_freeze_sha256'=>str_repeat('c',64)]]);}
function syntheticBulk():array{
    $c=contextBulk();$c['messages'][0]['text']='Что входит в эти варианты? [ссылка на подборку не прочитана]';
    $c['messages'][0]['selections_link_unread']=true;$c['selections_link_unread']=true;
    return ['case_id'=>'S01','context'=>$c,'provenance'=>['kind'=>'authored_synthetic','author'=>'codex','authored_at'=>'2020-01-01T00:00:00Z'],
        'selection_fixture'=>['source_refs'=>[['message_id'=>7,'sender_type'=>'customer','url'=>'https://qui-quo.ru/AAAA-0001']],
            'responses'=>[['url'=>'https://qui-quo.ru/AAAA-0001','status'=>200,'type'=>'text/html',
                'html'=>'<div class="tour"><b class="name">Authored Synthetic Hotel</b><div class="details"><span class="dates">20.10.2026</span><span class="nights">7 ночей</span><span class="price">125000 RUB</span></div></div>']]]];
}
checkBulk(ManagerAiBulkEval::CASES===100&&ManagerAiBulkEval::MAX_PARALLELISM===1
    &&ManagerAiBulkEval::MAX_DURATION_MS===2400000&&ManagerAiBulkEval::TTL===3600
    &&ManagerAiBulkEval::OUTPUT_TOKEN_RESERVATION===220000&&ManagerDraftClient::MAX_REQUEST_BYTES===196608,'fixed100_budget_and_timing');
$calls=0;$seen='';$payload=[];
$r=callBulk('runCase',historicalBulk(),1,'generate',static function($i,$p,$o)use(&$calls,&$seen,&$payload){++$calls;$seen=$i;$payload=$p;return resultBulk();});
checkBulk($calls===1&&$r['provider_calls']===1&&$seen===ManagerAiDraftService::instructions(),'canonical_prompt_no_case_id_routing');
checkBulk(!isset($payload['provenance'])&&!isset($payload['selection_fixture'])&&$payload['verified_company_facts']===[]&&!isset($payload['selection_snapshots']),'private_provenance_excluded');
checkBulk(is_int($r['provider_roundtrip_ms'])&&$r['provider_roundtrip_ms']>=0&&$r['elapsed_scope']==='service_total'
    &&is_int($r['context_projection_ms']),'separate_real_timing_scopes');
$preflight=callBulk('preflightCase',historicalBulk(),1,time());
$body=ManagerDraftClient::requestBody($seen,$payload);
checkBulk($preflight['provider_planned']&&$preflight['canonical_request_encoded_bytes']===strlen(json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))
    &&$preflight['canonical_request_sha256']===hash('sha256',json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))
    &&$preflight['instructions_sha256']===hash('sha256',$seen),'pure_preflight_canonical_request_capture');
$redacted=historicalBulk();$redacted['context']['messages'][0]['text']='Бюджет150000-250000₽; контакт user@example.test, +7 999 111 22 33; https://qui-quo.ru/AAAA-0001';$redacted=bindBulk($redacted);
$before=$redacted;$projection=callBulk('caseProjection',$redacted);
checkBulk($redacted===$before&&$projection['redactor_changed_count']===1
    &&str_contains($projection['context']['messages'][0]['text'],'150000-250000₽')
    &&!str_contains($projection['context']['messages'][0]['text'],'example.test')
    &&$projection['context']['selections_link_unread'],'canonical_redactor_preserves_money_and_detaches');
$seenPayload=null;
callBulk('runCase',$redacted,1,'generate',static function($i,$p)use(&$seenPayload){$seenPayload=$p;return resultBulk();});
$redactPreflight=callBulk('preflightCase',$redacted,1,time());
checkBulk($redactPreflight['payload_sha256']===hash('sha256',json_encode($seenPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'preflight_and_execution_share_projection');
checkBulk($redactPreflight['normalized_context']===$seenPayload
    &&$redactPreflight['normalized_context_scope']==='canonical_redacted_input','exact_context_retained_only_in_private_capture');
$referencedText='user@example.test';$aliased=historicalBulk();$aliased['context']['messages'][0]['text']=&$referencedText;
$aliasedProjection=callBulk('caseProjection',$aliased);
checkBulk($referencedText==='user@example.test'&&$aliased['context']['messages'][0]['text']==='user@example.test'
    &&$aliasedProjection['context']['messages'][0]['text']!=='user@example.test','projection_breaks_php_reference_alias');
$office=historicalBulk();$office['context']['messages'][0]['text']='Где у вас офис?';$office=bindBulk($office);$n=0;
$r=callBulk('runCase',$office,1,'generate',static function()use(&$n){++$n;return resultBulk();});
checkBulk($n===0&&$r['provider_calls']===0&&$r['result_origin']==='policy'&&$r['provider_roundtrip_ms']===null,'zero_call_policy_not_fake_latency');
$policyCapture=callBulk('preflightCase',$office,1,time());
checkBulk(!$policyCapture['provider_planned']&&$policyCapture['canonical_request_encoded_bytes']>0,'policy_request_explicit_counterfactual');
$r=callBulk('runCase',historicalBulk(),1,'generate',static function(){usleep(2000);throw new RuntimeException('synthetic_private_error');});
checkBulk($r['provider_calls']===1&&is_int($r['provider_roundtrip_ms'])&&$r['result']['error']==='provider_unavailable'
    &&!str_contains(json_encode($r),'synthetic_private_error'),'failure_duration_without_error_leak');
$synthetic=syntheticBulk();
$r=callBulk('runCase',$synthetic,1,'generate',static function($i,$p)use(&$payload){$payload=$p;return resultBulk();});
checkBulk($r['selection_reads']===1&&$r['live_selection_fetches']===0&&$r['provider_calls']===1,'mandatory_authored_transport_unit_only');
checkBulk($payload['selection_snapshots'][0]['offers'][0]['hotel']==='Authored Synthetic Hotel'
    &&!$payload['selection_snapshots'][0]['historical_send_snapshot']&&!$payload['selection_snapshots'][0]['current_price_verified'],'no_live_price_or_sendtime_claim');
checkBulk(!str_contains(json_encode($payload),'qui-quo.ru')&&!str_contains(json_encode($payload),'<div'),'fixture_url_html_stays_off_provider');
foreach ([[],[['url'=>'https://qui-quo.ru/AAAA-0001','status'=>404,'type'=>'text/html','html'=>'']]] as $responses){
    $bad=syntheticBulk();$bad['selection_fixture']['responses']=$responses;
    $r=callBulk('runCase',$bad,1,'generate',static function($i,$p)use(&$payload){$payload=$p;return resultBulk();});
    checkBulk($r['selection_reads']===1&&$r['live_selection_fetches']===0&&$payload['selections_link_unread'],'empty_fixture_never_fetches_live');
}
foreach ([
    static function($c){$c['provenance']['kind']='historical_prefix';return $c;},
    static function($c){$c['provenance']['source_ordinals']=[];return bindBulk($c);},
    static function($c){$c['provenance']['source_ordinals']=[1,1];return bindBulk($c);},
    static function($c){$c['provenance']['source_ordinals']=[2];return bindBulk($c);},
    static function($c){$c['provenance']['source_ordinals']=[true];return bindBulk($c);},
    static function($c){$c['provenance']['source_message_ids']=[8];return bindBulk($c);},
    static function($c){$c['provenance']['source_epoch_seconds']=[1577836801];return bindBulk($c);},
    static function($c){$c['provenance']['source_epoch_seconds']=[true];return bindBulk($c);},
    static function($c){$c['context']['messages'][0]['created_at']='2020-01-02T00:00:00Z';return bindBulk($c);},
    static function($c){$c['context']['conversation_id']=9999001;return $c;},
    static function($c){unset($c['context']['messages'][0]['event_type']);return $c;},
    static function($c){$c['provenance']['projected_prefix_sha256']=str_repeat('0',64);return $c;},
    static function($c){$c['context']['verified_company_facts']=[['text'=>'invented']];return $c;},
    static function($c){$c['context']['messages'][0]['future_outcome']='paid';return $c;},
] as $change)rejectBulk(static fn()=>callBulk('validateCase',$change(historicalBulk()),time(),1),'strict_full_prefix_no_future_or_legacy');
$tuple=historicalBulk();$tuple['context']['messages'][0]['text']="Synthetic \u{2028} 🌍";$tuple=bindBulk($tuple);
checkBulk(callBulk('projectedPrefixHash',$tuple)===hash('sha256',json_encode([[1],[7],[1577836800],[[7,'customer',"Synthetic \u{2028} 🌍",'message','2020-01-01T00:00:00Z',false,false,false]]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'ascii_positional_hash_no_map_key_order');
rejectBulk(static fn()=>callBulk('runCase',historicalBulk(),1,'grade',static fn()=>[]),'grader_disabled');
$now=time();$options=['task-token'=>str_repeat('d',48),'production-sha'=>str_repeat('e',40),'bundle-sha256'=>str_repeat('f',64),'recipient-sha256'=>str_repeat('0',64),'case-count'=>'100','parallelism'=>'1','stage'=>'generate'];
$lease=['lease_id'=>str_repeat('1',32),'created_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-5),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$now+3595)];
$cases=[];for($i=1;$i<=100;++$i)$cases[]=historicalBulk($i);
$input=['schema'=>ManagerAiBulkEval::INPUT_SCHEMA,'repository'=>'pyatkoff/max-search-bot','task_token'=>$options['task-token'],'production_sha'=>$options['production-sha'],'source_bundle_sha256'=>$options['bundle-sha256'],'output_recipient_sha256'=>$options['recipient-sha256'],'lease_id'=>$lease['lease_id'],'stage'=>'generate','generated_at'=>gmdate('Y-m-d\TH:i:s\Z',$now),'expires_at'=>$lease['expires_at'],'cases'=>$cases];
checkBulk(count(callBulk('validateInput',$input,$options,$lease))===100,'exact100_distinct_frozen_histories');
foreach ([
    static function($v){array_pop($v['cases']);return $v;},
    static function($v){$v['cases'][]=historicalBulk(100);return $v;},
    static function($v){$v['cases'][1]=$v['cases'][0];return $v;},
    static function($v){$v['cases'][1]['provenance']['source_alias']=$v['cases'][0]['provenance']['source_alias'];return $v;},
    static function($v){$v['cases'][99]['provenance']['source_ordinals']=[2];return $v;},
    static function($v){$v['cases'][99]['provenance']['selection_freeze_sha256']=str_repeat('9',64);return $v;},
    static function($v){$v['cases'][1]=syntheticBulk();return $v;},
    static function($v){$v['task_token']=str_repeat('2',48);return $v;},
    static function($v){$v['generated_at']='2099-01-01T00:00:00Z';return $v;},
    static function($v){$v['future_outcomes']=[];return $v;},
] as $change)rejectBulk(static fn()=>callBulk('validateInput',$change($input),$options,$lease),'all100_integrity_gate_before_spend');
$badOptions=$options;$badOptions['parallelism']='2';
rejectBulk(static fn()=>callBulk('validateInput',$input,$badOptions,$lease),'parallelism2_refused');
checkBulk(callBulk('requestedProfile',ManagerDraftClient::requestBody('',[]))===['model'=>'gpt-6.1-sol','reasoning_effort'=>'low','service_tier'=>'fast'],'fixed_profile');
foreach ([
    array_replace(ManagerDraftClient::requestBody('',[]),['model'=>'other']),
    array_replace(ManagerDraftClient::requestBody('',[]),['max_output_tokens'=>2201]),
] as $p)rejectBulk(static fn()=>callBulk('requestedProfile',$p),'profile_and_token_override_refused');
checkBulk(!callBulk('canLaunch',time()+30,hrtime(true)+2400000000000)
    &&!callBulk('canLaunch',time()+3600,hrtime(true)+29000000000)
    &&callBulk('canLaunch',time()+3600,hrtime(true)+2400000000000),'last_worker_launch_deadline');
$d=sys_get_temp_dir().'/k03-bulk-synthetic-'.bin2hex(random_bytes(10));mkdir($d,0700);
try{
    $small=array_slice($cases,0,12);file_put_contents($d.'/counter','0,0');$output=[];
    $records=callBulk('parallel',$small,1,'generate',$d,static function($r)use(&$output){$output[]=$r;},static function()use($d){
        $h=fopen($d.'/counter','r+');flock($h,LOCK_EX);[$n,$peak]=array_map('intval',explode(',',stream_get_contents($h)));++$n;$peak=max($peak,$n);rewind($h);ftruncate($h,0);fwrite($h,$n.','.$peak);fflush($h);flock($h,LOCK_UN);fclose($h);
        usleep(10000);
        $h=fopen($d.'/counter','r+');flock($h,LOCK_EX);[$n,$peak]=array_map('intval',explode(',',stream_get_contents($h)));--$n;rewind($h);ftruncate($h,0);fwrite($h,$n.','.$peak);fflush($h);flock($h,LOCK_UN);fclose($h);
        return resultBulk();
    });
    checkBulk(count($records)===12&&count($output)===12&&count(glob($d.'/result-*.json'))===12,'durable_case_records');
    [$n,$peak]=array_map('intval',explode(',',file_get_contents($d.'/counter')));checkBulk($n===0&&$peak===1,'exactly_one_active_worker');
    foreach($records as $r)checkBulk($r['provider_calls']===1&&$r['result']['ok']===true,'one_call_no_retry');
    rejectBulk(static fn()=>callBulk('parallel',$small,1,'generate',$d,static function(){},static function(){throw new RuntimeException('must_not_replay');}),'started_ledger_blocks_replay');
    rejectBulk(static fn()=>callBulk('parallel',array_fill(0,101,historicalBulk()),1,'generate',$d,static function(){}),'case101_refused');
    rejectBulk(static fn()=>callBulk('parallel',$small,2,'generate',$d,static function(){}),'two_workers_refused');
}finally{foreach(glob($d.'/*')?:[] as $p)unlink($p);rmdir($d);}
checkBulk(!class_exists('ConversationDb',false)&&!defined('OPENAI_API_KEY'),'no_production_database_or_credentials');
echo 'K03 bulk synthetic checks PASS: '.$checksBulk.' checks; exact100/1, pure preflight/redactor, strict epoch-prefix binding, generation-only and no replay'.PHP_EOL;
