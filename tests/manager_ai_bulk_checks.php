<?php
declare(strict_types=1);
define('MANAGER_AI_BULK_LIBRARY_ONLY',true);
require dirname(__DIR__).'/services/ManagerAiDraftService.php';
require dirname(__DIR__).'/tools/manager_ai_bulk.php';
$checksBulk=0;
function callBulk(string $method,...$args){return (new ReflectionMethod(ManagerAiBulkEval::class,$method))->invoke(null,...$args);}
function checkBulk(bool $yes,string $name):void{global $checksBulk;if(!$yes)throw new RuntimeException('bulk_synthetic_failed:'.$name);++$checksBulk;}
function rejectBulk(callable $call,string $name):void{$rejected=false;try{$call();}catch(Throwable $ignored){$rejected=true;}checkBulk($rejected,$name);}
function rejectCaseBulk(array $case,string $name):void{$rejected=false;$calls=0;try{callBulk('runCase',$case,1,'generate',static function()use(&$calls){++$calls;return resultBulk();});}catch(Throwable $ignored){$rejected=true;}checkBulk($rejected&&$calls===0,$name);}
function contextBulk(int $id=1):array{return ['conversation_id'=>$id,'as_of_message_id'=>7,'messages'=>[['id'=>7,'sender_type'=>'customer','text'=>'Нужен тур в Турцию на двоих из Казани.','created_at'=>'2020-01-01T00:00:00Z']],'history_incomplete'=>false,'selections_link_unread'=>false];}
function historicalBulk(int $id=1):array{return ['case_id'=>'H'.$id,'context'=>contextBulk($id),'provenance'=>['kind'=>'historical_prefix','cutoff_at'=>'2020-01-01T00:00:00Z','source_sha256'=>str_repeat('a',64),'source_alias'=>'TG'.str_pad((string)$id,4,'0',STR_PAD_LEFT)]];}
function resultBulk():array{return ['ok'=>true,'should_draft'=>true,'draft'=>'На какие даты планируете поездку?','next_action'=>'clarify','reason'=>'Не названы даты.','checks'=>[],'evidence_message_ids'=>[7],'history_incomplete'=>false];}
function syntheticBulk():array{
    $c=contextBulk();$c['messages'][0]['text']='Что входит в эти варианты? [ссылка на подборку не прочитана]';
    $c['messages'][0]['selections_link_unread']=true;$c['selections_link_unread']=true;
    return ['case_id'=>'S01','context'=>$c,'provenance'=>['kind'=>'authored_synthetic','author'=>'codex','authored_at'=>'2020-01-01T00:00:00Z'],
        'selection_fixture'=>['source_refs'=>[['message_id'=>7,'sender_type'=>'customer','url'=>'https://qui-quo.ru/AAAA-0001']],
            'responses'=>[['url'=>'https://qui-quo.ru/AAAA-0001','status'=>200,'type'=>'text/html',
                'html'=>'<div class="tour"><b class="name">Authored Synthetic Hotel</b><div class="details"><span class="dates">20.10.2026</span><span class="nights">7 ночей</span><span class="price">125000 RUB</span></div></div>']]]];
}
$calls=0;$seen='';$payload=[];
$r=callBulk('runCase',historicalBulk(),1,'generate',static function($i,$p,$o)use(&$calls,&$seen,&$payload){++$calls;$seen=$i;$payload=$p;return resultBulk();});
checkBulk($calls===1&&$r['provider_calls']===1&&$seen===ManagerAiDraftService::instructions(),'canonical_prompt_no_id_routing');
checkBulk(!isset($payload['provenance'])&&!isset($payload['selection_fixture'])&&$payload['verified_company_facts']===[]&&!isset($payload['selection_snapshots']),'historical_metadata_and_knowledge_excluded');
$history=historicalBulk();$history['context']['selections_link_unread']=true;$history['context']['messages'][0]['selections_link_unread']=true;
$history['context']['messages'][0]['text']='Посмотрите [ссылка на подборку не прочитана]';
callBulk('runCase',$history,1,'generate',static function($i,$p)use(&$payload){$payload=$p;return resultBulk();});
checkBulk($payload['selections_link_unread']&&!isset($payload['selection_snapshots']),'historical_link_remains_unread');
$office=historicalBulk();$office['context']['messages'][0]['text']='Где у вас офис?';$n=0;
$r=callBulk('runCase',$office,1,'generate',static function()use(&$n){++$n;return resultBulk();});
checkBulk($n===0&&$r['provider_calls']===0&&$r['result_origin']==='policy','zero_call_policy');
$synthetic=syntheticBulk();
$r=callBulk('runCase',$synthetic,1,'generate',static function($i,$p)use(&$payload){$payload=$p;return resultBulk();});
checkBulk($r['selection_reads']===1&&$r['live_selection_fetches']===0&&$r['provider_calls']===1,'mandatory_authored_transport');
checkBulk($payload['selection_snapshots'][0]['offers'][0]['hotel']==='Authored Synthetic Hotel'&&!$payload['selections_link_unread'],'genuine_bound_snapshot_enters_model');
checkBulk(!$payload['selection_snapshots'][0]['historical_send_snapshot']&&!$payload['selection_snapshots'][0]['current_price_verified']&&$payload['selection_snapshots'][0]['offers'][0]['price_basis']===null,'no_price_or_sendtime_claim');
checkBulk(!str_contains(json_encode($payload),'qui-quo.ru')&&!str_contains(json_encode($payload),'<div')&&!isset($payload['selection_fixture']),'fixture_html_url_and_provenance_not_model_input');
foreach ([[],[['url'=>'https://qui-quo.ru/AAAA-0001','status'=>404,'type'=>'text/html','html'=>'']]] as $responses){
    $bad=syntheticBulk();$bad['selection_fixture']['responses']=$responses;
    $r=callBulk('runCase',$bad,1,'generate',static function($i,$p)use(&$payload){$payload=$p;return resultBulk();});
    checkBulk($r['selection_reads']===1&&$r['live_selection_fetches']===0&&$payload['selections_link_unread'],'empty_or_failed_fixture_stays_offline_unread');
}
$office=syntheticBulk();$office['context']['messages'][0]['text']='Где у вас офис?';
$r=callBulk('runCase',$office,1,'generate',static function(){throw new RuntimeException('must_not_call');});
checkBulk($r['provider_calls']===0&&$r['selection_reads']===0,'office_policy_before_synthetic_reader');
foreach ([
    static function($c){$c['provenance']['kind']='observed_page';return $c;},
    static function($c){unset($c['selection_fixture']);return $c;},
    static function($c){$c['selection_fixture']['source_refs'][0]['url']='https://evil.test/AAAA-0001';return $c;},
    static function($c){$c['selection_fixture']['source_refs'][0]['message_id']=999;return $c;},
    static function($c){$c['selection_fixture']['source_refs'][0]['sender_type']='manager';return $c;},
    static function($c){$c['selection_fixture']['responses'][0]['url']='https://qui-quo.ru/BBBB-0002';return $c;},
    static function($c){$c['selection_fixture']['responses'][0]['html']="bad\xFF";return $c;},
    static function($c){$c['selection_fixture']['responses'][0]['html']=str_repeat('x',1048577);return $c;},
    static function($c){$c['context']['selection_snapshots']=[];return $c;},
    static function($c){$c['context']['verified_company_facts']=[['text'=>'invented']];return $c;},
    static function($c){$c['context']['messages'][0]['future_outcome']='paid';return $c;},
    static function($c){$c['context']['messages'][0]['text_truncated']=true;$c['context']['history_incomplete']=true;return $c;},
    static function($c){$c['provenance']['authored_at']='2099-01-01T00:00:00Z';return $c;}
] as $change)rejectCaseBulk($change(syntheticBulk()),'hostile_or_incomplete_case_rejected_before_provider');
$bad=historicalBulk();$bad['context']['messages'][0]['created_at']='2020-01-02T00:00:00Z';
rejectBulk(static fn()=>callBulk('validateCase',$bad,time()),'future_message_rejected');
$bad=historicalBulk();$bad['context']['messages'][0]['text']='https://qui-quo.ru/AAAA-0001';
rejectBulk(static fn()=>callBulk('validateCase',$bad,time()),'historical_raw_link_rejected');
$bad=historicalBulk();$bad['selection_fixture']=syntheticBulk()['selection_fixture'];
rejectBulk(static fn()=>callBulk('validateCase',$bad,time()),'historical_observation_forbidden');
rejectBulk(static fn()=>callBulk('runCase',historicalBulk(),1,'grade',static fn()=>[]),'grader_disabled');
$now=time();$options=['task-token'=>str_repeat('b',48),'production-sha'=>str_repeat('c',40),'bundle-sha256'=>str_repeat('d',64),'recipient-sha256'=>str_repeat('e',64),'case-count'=>'2','parallelism'=>'5','stage'=>'generate'];
$lease=['lease_id'=>str_repeat('f',32),'created_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now-5),'expires_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now+3595)];
$input=['schema'=>ManagerAiBulkEval::INPUT_SCHEMA,'repository'=>'pyatkoff/max-search-bot','task_token'=>$options['task-token'],'production_sha'=>$options['production-sha'],'source_bundle_sha256'=>$options['bundle-sha256'],'output_recipient_sha256'=>$options['recipient-sha256'],'lease_id'=>$lease['lease_id'],'stage'=>'generate','generated_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now),'expires_at'=>$lease['expires_at'],'cases'=>[historicalBulk(),syntheticBulk()]];
checkBulk(count(callBulk('validateInput',$input,$options,$lease))===2,'frozen_mixed_membership_accepted');
foreach ([
    static function($v){$v['task_token']=str_repeat('a',48);return $v;},
    static function($v){$v['generated_at']='2020-01-01T00:00:00Z';return $v;},
    static function($v){$v['generated_at']='2099-01-01T00:00:00Z';return $v;},
    static function($v){$v['expires_at']='2020-01-01T00:00:00Z';return $v;},
    static function($v){$v['lease_id']=str_repeat('0',32);return $v;},
    static function($v){$v['cases'][1]=$v['cases'][0];return $v;},
    static function($v){$v['cases'][1]=dictBulk($v['cases'][0],['case_id'=>'OTHER']);return $v;},
    static function($v){$v['future_outcomes']=[];return $v;}
] as $change)rejectBulk(static fn()=>callBulk('validateInput',$change($input),$options,$lease),'freshness_binding_or_membership_rejected');
function dictBulk(array $a,array $b):array{return array_replace($a,$b);}
checkBulk(callBulk('requestedProfile',ManagerDraftClient::requestBody('',[]))===['model'=>'gpt-6.1-sol','reasoning_effort'=>'low','service_tier'=>'fast'],'fixed_profile');
foreach ([['model'=>'other','reasoning'=>['effort'=>'low'],'service_tier'=>'fast'],['model'=>'gpt-6.1-sol','reasoning'=>['effort'=>'medium'],'service_tier'=>'fast']] as $p)rejectBulk(static fn()=>callBulk('requestedProfile',$p),'profile_override_refused');
$d=sys_get_temp_dir().'/selection-bulk-synthetic-'.bin2hex(random_bytes(10));mkdir($d,0700);
try{
    $cases=[];for($i=0;$i<12;++$i)$cases[]=historicalBulk($i+1);
    file_put_contents($d.'/counter','0,0');$output=[];
    $records=callBulk('parallel',$cases,3,'generate',$d,static function($r)use(&$output){$output[]=$r;},static function()use($d){
        $h=fopen($d.'/counter','r+');flock($h,LOCK_EX);[$n,$peak]=array_map('intval',explode(',',stream_get_contents($h)));++$n;$peak=max($peak,$n);rewind($h);ftruncate($h,0);fwrite($h,$n.','.$peak);fflush($h);flock($h,LOCK_UN);fclose($h);
        usleep(100000);
        $h=fopen($d.'/counter','r+');flock($h,LOCK_EX);[$n,$peak]=array_map('intval',explode(',',stream_get_contents($h)));--$n;rewind($h);ftruncate($h,0);fwrite($h,$n.','.$peak);fflush($h);flock($h,LOCK_UN);fclose($h);
        return resultBulk();
    });
    checkBulk(count($records)===12&&count($output)===12&&count(glob($d.'/result-*.json'))===12,'durable_parallel_records');
    [$n,$peak]=array_map('intval',explode(',',file_get_contents($d.'/counter')));checkBulk($n===0&&$peak===3,'actual_parallel_cap');
    foreach($records as $r)checkBulk($r['provider_calls']===1&&$r['result']['ok']===true,'one_call_per_worker');
    rejectBulk(static fn()=>callBulk('parallel',$cases,3,'generate',$d,static function(){},static function(){throw new RuntimeException('must_not_replay');}),'started_ledger_prevents_replay');
    rejectBulk(static fn()=>callBulk('parallel',array_fill(0,37,historicalBulk()),3,'generate',$d,static function(){}),'thirty_seven_cases_forbidden');
}finally{foreach(glob($d.'/*')?:[] as $p)unlink($p);rmdir($d);}
checkBulk(!class_exists('ConversationDb',false)&&!defined('OPENAI_API_KEY'),'no_production_or_database_bootstrap');
echo 'selection bulk synthetic checks PASS: '.$checksBulk.' checks; canonical service, provenance, no-network selection seam, generation-only, fork and no replay'.PHP_EOL;
