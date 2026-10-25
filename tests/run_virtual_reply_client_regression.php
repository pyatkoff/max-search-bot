<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/ai/VirtualReplyClient.php';
require_once dirname(__DIR__).'/services/ManagerAiDraftService.php';

$passed=0;$failed=0;
function virtualCheck(string $name,bool $ok): void
{
    global $passed,$failed;
    echo ($ok?'PASS  ':'FAIL  ').$name.PHP_EOL;
    $ok?$passed++:$failed++;
}
function virtualReject(callable $call): bool
{
    try {$call();return false;} catch (InvalidArgumentException $ignored) {return true;}
}
function virtualAuthor(): array
{
    return ['ok'=>true,'should_draft'=>true,'draft'=>'На какой период рассматриваете поездку?',
        'next_action'=>'clarify','reason'=>'Состав и вылет уже известны, периода нет.',
        'checks'=>[],'evidence_message_ids'=>[2],'history_incomplete'=>false];
}
function virtualReview(): array
{
    return ['ok'=>true,'verdict'=>'revise','issues'=>[['category'=>'question','severity'=>'major',
        'explanation'=>'Повторно спрашивается уже названный город.','evidence_message_ids'=>[2]]],
        'corrected_draft'=>'На какой период рассматриваете поездку?',
        'lesson_candidate'=>'Сначала использовать названные условия; уточнять только существенные неизвестные.'];
}
function virtualEnvelope(array $value,array $extra=[]): string
{
    return json_encode($extra+['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','status'=>'completed',
        'content'=>[['type'=>'output_text','text'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

// Deliberately poisoned production settings must never change either private role.
define('MANAGER_AI_DRAFT_MODEL','production-profile-canary');
define('MANAGER_AI_DRAFT_REASONING','max');
define('MANAGER_AI_DRAFT_SERVICE_TIER','fast');
$payload=['messages'=>[['id'=>1,'text'=>'Нас трое взрослых.'],['id'=>2,'text'=>'Из Казани.']]];
$author=VirtualReplyClient::requestBody('author',VirtualReplyProtocol::instructions('author'),$payload);
$reviewer=VirtualReplyClient::requestBody('reviewer',VirtualReplyProtocol::instructions('reviewer'),['context'=>$payload,'answer'=>virtualAuthor()]);
virtualCheck('author stays Sol low Standard despite production constants',$author['model']==='gpt-6.1-sol'
    && $author['reasoning']===['effort'=>'low'] && $author['service_tier']==='default');
virtualCheck('reviewer independently uses Astra high Standard',$reviewer['model']==='gpt-6-astra'
    && $reviewer['reasoning']===['effort'=>'high'] && $reviewer['service_tier']==='default');
virtualCheck('both requests disable provider storage and have bounded output',!$author['store'] && !$reviewer['store']
    && $author['max_output_tokens']===4096 && $reviewer['max_output_tokens']===8192);
virtualCheck('roles have distinct strict response schemas',$author['text']['format']['strict']===true
    && $reviewer['text']['format']['strict']===true && $author['text']['format']['schema']!==$reviewer['text']['format']['schema']
    && $reviewer['text']['format']['schema']['additionalProperties']===false);
virtualCheck('untrusted payload stays in user message',$author['input'][1]['role']==='user'
    && json_decode($author['input'][1]['content'][0]['text'],true)===$payload
    && !str_contains($author['input'][0]['content'][0]['text'],'Нас трое взрослых.'));
virtualCheck('frozen supplied instructions are not silently replaced',VirtualReplyClient::requestBody('reviewer','FROZEN_REVIEW_INSTRUCTION',[])['input'][0]['content'][0]['text']==='FROZEN_REVIEW_INSTRUCTION');
virtualCheck('revision is stable and separates both roles',VirtualReplyProtocol::revision('author')===VirtualReplyProtocol::revision('author')
    && strlen(VirtualReplyProtocol::revision('author'))===64 && VirtualReplyProtocol::revision('author')!==VirtualReplyProtocol::revision('reviewer'));
$policy=TourConsultationPolicy::instructions();
$ordinary=ManagerDraftClient::requestBody(ManagerAiDraftService::instructions(),$payload);
foreach (['ordinary'=>$ordinary,'author'=>$author,'reviewer'=>$reviewer] as $role=>$request) {
    $system=$request['input'][0]['content'][0]['text'];
    virtualCheck($role.' provider envelope consumes the exact shared policy once',substr_count($system,$policy)===1);
    virtualCheck($role.' policy is not injected into source data',json_decode($request['input'][1]['content'][0]['text'],true)===($role==='reviewer'?['context'=>$payload,'answer'=>virtualAuthor()]:$payload));
}
foreach (['author','reviewer'] as $role) {
    $contract=['role'=>$role,'instructions'=>VirtualReplyProtocol::instructions($role),'profile'=>VirtualReplyProtocol::profile($role),'schema'=>VirtualReplyProtocol::schema($role)];
    $encode=static fn(array $value):string=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    virtualCheck($role.' revision covers the complete shared instruction payload',VirtualReplyProtocol::revision($role)===hash('sha256',$encode($contract)));
    $contract['instructions']=str_replace($policy,'',$contract['instructions']);
    virtualCheck($role.' contract without the shared policy has a different revision',VirtualReplyProtocol::revision($role)!==hash('sha256',$encode($contract)));
}
virtualCheck('ordinary instruction revision also changes with shared policy',hash('sha256',ManagerAiDraftService::instructions())!==hash('sha256',str_replace($policy,'',ManagerAiDraftService::instructions())));
virtualCheck('frozen caller contract stays exact after current shared policy is loaded',VirtualReplyClient::requestBody('author','FROZEN_AUTHOR_CONTRACT_BEFORE_SHARED_POLICY',$payload)['input'][0]['content'][0]['text']==='FROZEN_AUTHOR_CONTRACT_BEFORE_SHARED_POLICY');

// These checks bind current rule bytes to real envelopes, not model responses.
$pairRule='«Двое/на двоих» — исходно двое взрослых без уточнения гипотетических детей; явный ребёнок/иной состав важнее.';
$directQuestionRule='При уточнении сразу задай существенные вопросы без пересказа и «подберу/посмотрю».';
$usefulAnswerRule='Сохраняй полезный подтверждённый ответ; не обещай необеспеченные действия/сроки.';
foreach (['ordinary'=>$ordinary,'author'=>$author,'reviewer'=>$reviewer] as $role=>$request) {
    $instruction=$request['input'][0]['content'][0]['text'];
    virtualCheck($role.' current envelope carries pair default and explicit-child precedence once',substr_count($instruction,$pairRule)===1);
    virtualCheck($role.' current envelope carries direct necessary-question rule once',substr_count($instruction,$directQuestionRule)===1);
    virtualCheck($role.' current envelope preserves useful factual answers rather than enforcing questions only',substr_count($instruction,$usefulAnswerRule)===1);
}

// Synthetic window shapes remain source data; this instruction edit never parses or narrows them.
$windowRule='Каникулы/отпуск — исходное окно поездки без повторного подтверждения. Диапазон вылета не задаёт срок возвращения; «начиная с» не задаёт конец. Уточняй неясный смысл периода или конфликт с длительностью/дорогой; выход за окно согласуй.';
$windowInputs=[
    'closed_holiday'=>['text'=>'Отпуск с 12 по 20 ноября, нужна поездка.'],
    'departure_range'=>['text'=>'Вылет 12–14 ноября, на 8 ночей.'],
    'open_departure'=>['text'=>'Вылет начиная с 12 ноября.'],
    'conflict'=>['text'=>'Свободны с 12 по 16 ноября, хотим 9 ночей.'],
    'ambiguous'=>['text'=>'С 12 по 20 ноября, на 5 ночей.'],
];
foreach ($windowInputs as $name=>$message) {
    $source=['messages'=>[['id'=>91,'sender_type'=>'tourist']+$message],'history_incomplete'=>false];
    $request=VirtualReplyClient::requestBody('author',VirtualReplyProtocol::instructions('author'),$source);
    virtualCheck($name.' source window survives the actual author envelope unchanged',json_decode($request['input'][1]['content'][0]['text'],true)===$source);
    virtualCheck($name.' source text is not promoted to system instructions',!str_contains($request['input'][0]['content'][0]['text'],$message['text']));
}
foreach (['ordinary'=>ManagerAiDraftService::instructions(),'author'=>VirtualReplyProtocol::instructions('author'),'reviewer'=>VirtualReplyProtocol::instructions('reviewer')] as $name=>$instruction) {
    virtualCheck($name.' consumes exact bounded-window rule with departure/open/conflict exceptions once',substr_count($instruction,$windowRule)===1);
}

foreach (['AUTHOR','judge','','author/../reviewer'] as $role) {
    virtualCheck('reject unknown role '.json_encode($role),virtualReject(static fn()=>VirtualReplyClient::requestBody($role,'test',[])));
}
virtualCheck('reject blank instructions',virtualReject(static fn()=>VirtualReplyClient::requestBody('author',' ',[])));
virtualCheck('bound encoded request before any transport',virtualReject(static fn()=>VirtualReplyClient::requestBody('author','test',['text'=>str_repeat('x',VirtualReplyClient::MAX_REQUEST_BYTES)])));

$valid=virtualAuthor();
virtualCheck('canonical author proposal accepted',VirtualReplyProtocol::validate('author',$valid,[1,2])===$valid);
$noContact=$valid;$noContact['should_draft']=false;$noContact['draft']=null;$noContact['next_action']='no_contact';$noContact['evidence_message_ids']=[];
virtualCheck('justified absence of outgoing text accepted',VirtualReplyProtocol::validate('author',$noContact,[1,2])===$noContact);
$bad=$valid;$bad['extra']='source injection';virtualCheck('unknown proposal field rejected',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;unset($bad['checks']);virtualCheck('missing proposal field rejected',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['evidence_message_ids']=[3];virtualCheck('future or foreign author evidence rejected',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['evidence_message_ids']=[2,2];virtualCheck('duplicate author evidence rejected',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['evidence_message_ids']=['2'];virtualCheck('string evidence is not coerced into identity',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['next_action']='no_contact';virtualCheck('no-contact cannot contain an outgoing draft',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['should_draft']=false;virtualCheck('false draft flag requires null text',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['draft']=str_repeat('я',3001);virtualCheck('bound author text by Unicode characters',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['draft']="bad\x00text";virtualCheck('control bytes rejected',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['draft']="\xff";virtualCheck('malformed UTF-8 rejected',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['history_incomplete']=0;virtualCheck('history flag is strictly boolean',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
$bad=$valid;$bad['checks']=array_fill(0,11,'check');virtualCheck('bound author checks',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$bad,[1,2])));
virtualCheck('frozen evidence inventory must itself be valid',virtualReject(static fn()=>VirtualReplyProtocol::validate('author',$valid,[1,2,2])));

$review=virtualReview();
virtualCheck('grounded review and candidate lesson accepted',VirtualReplyProtocol::validate('reviewer',$review,[1,2])===$review);
$pass=['ok'=>true,'verdict'=>'pass','issues'=>[],'corrected_draft'=>null,'lesson_candidate'=>null];
virtualCheck('clean review accepted',VirtualReplyProtocol::validate('reviewer',$pass,[1,2])===$pass);
$incomplete=$review;$incomplete['verdict']='insufficient_context';$incomplete['corrected_draft']=null;
virtualCheck('explicit context limitation accepted',VirtualReplyProtocol::validate('reviewer',$incomplete,[1,2])===$incomplete);
$bad=$review;$bad['issues'][0]['evidence_message_ids']=[999];virtualCheck('review cannot cite unseen evidence',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$bad,[1,2])));
$bad=$review;$bad['issues'][0]['category']='invented';virtualCheck('unknown review category rejected',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$bad,[1,2])));
$bad=$review;$bad['issues'][0]['severity']='approved';virtualCheck('unknown severity rejected',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$bad,[1,2])));
$bad=$review;$bad['issues'][0]['explanation']=str_repeat('я',501);virtualCheck('bound review explanation',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$bad,[1,2])));
$bad=$review;$bad['verdict']='pass';virtualCheck('pass cannot hide correction or findings',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$bad,[1,2])));
$bad=$review;$bad['issues']=[];virtualCheck('revision verdict needs a concrete finding',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$bad,[1,2])));
$bad=$review;$bad['verdict']='insufficient_context';virtualCheck('insufficient context cannot invent corrected reply',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$bad,[1,2])));
$bad=$review;$bad['apply_lesson']=true;virtualCheck('review has no automatic instruction-write field',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$bad,[1,2])));
virtualCheck('author envelope cannot pass as a review',virtualReject(static fn()=>VirtualReplyProtocol::validate('reviewer',$valid,[1,2])));

$metadata=[];$body=virtualEnvelope($review,['model'=>'gpt-6-astra','service_tier'=>'default','usage'=>['input_tokens'=>10,'output_tokens'=>30,'total_tokens'=>40,'output_tokens_details'=>['reasoning_tokens'=>20]],'id'=>'PRIVATE_ID_CANARY']);
$decoded=VirtualReplyClient::parseResponse($body,200,static function(array $value) use (&$metadata):void {$metadata=$value;});
virtualCheck('completed review decodes without a provider call',$decoded===$review);
virtualCheck('only allowlisted technical metadata retained',$metadata===['model'=>'gpt-6-astra','service_tier'=>'default','usage'=>['input_tokens'=>10,'output_tokens'=>30,'total_tokens'=>40,'output_tokens_details'=>['reasoning_tokens'=>20]]]
    && !str_contains(json_encode($metadata),'PRIVATE_ID_CANARY'));
virtualCheck('HTTP failure never exposes provider body',VirtualReplyClient::parseResponse('SECRET_BODY_CANARY',429)===['_error'=>'provider_unavailable']);
virtualCheck('incomplete output cannot become a saved review',VirtualReplyClient::parseResponse(virtualEnvelope($review,['status'=>'incomplete','incomplete_details'=>['reason'=>'max_output_tokens']]),200)===['_error'=>'provider_incomplete']);
virtualCheck('oversized response rejected',VirtualReplyClient::parseResponse(str_repeat('x',VirtualReplyClient::MAX_RESPONSE_BYTES+1),200)===['_error'=>'provider_response_too_large']);
$refusal=json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'refusal','refusal'=>'no']]]]]);
virtualCheck('provider refusal is explicit',VirtualReplyClient::parseResponse($refusal,200)===['_error'=>'provider_refused']);
if (!defined('OPENAI_API_KEY')) virtualCheck('unconfigured client stops before any network call',VirtualReplyClient::request('author','test',[])===['_error'=>'provider_not_configured']);

echo "\nVirtual reply client: $passed passed, $failed failed\n";
exit($failed===0?0:1);

