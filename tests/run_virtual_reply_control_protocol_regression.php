<?php

declare(strict_types=1);

require_once __DIR__.'/../services/VirtualReplyControlProtocol.php';

$passed=0; $failed=0;
function controlCheck(string $name,bool $ok): void {
    global $passed,$failed; echo ($ok?'PASS ':'FAIL ').$name."\n"; $ok?$passed++:$failed++;
}
function controlReject(callable $fn): bool {
    try {$fn();return false;} catch (InvalidArgumentException $ignored) {return true;}
}
function controlAuthor(): array {
    return ['ok'=>true,'should_draft'=>true,'draft'=>'Какой общий бюджет рассматриваете?', 'next_action'=>'clarify',
        'reason'=>'Направление и состав уже известны, бюджета нет.', 'checks'=>[], 'evidence_message_ids'=>[12], 'history_incomplete'=>false];
}
function controlContext(): array {
    return ['messages'=>[['id'=>11,'sender_type'=>'tourist','text'=>'Из Казани, двое взрослых.'],
        ['id'=>12,'sender_type'=>'tourist','text'=>'Нужен Египет на неделю.']], 'history_incomplete'=>false,
        'cutoff'=>['source_id'=>12,'reference_time'=>'2026-10-21T10:00:00Z'],
        'historical_context_reconstructed'=>false,'revised_question'=>false];
}
function controlMetrics(?int $questions=0,?int $constraints=0,?int $claims=0,?bool $useful=true): array {
    return ['unnecessary_questions'=>$questions,'lost_constraints'=>$constraints,'unsupported_claims'=>$claims,'useful_next_action'=>$useful];
}
function controlReview(?array $a=null,?array $b=null,string $preference='tie'): array {
    $a=$a??controlMetrics(); $b=$b??controlMetrics(); $paired=false; $null=false; $issues=[];
    foreach (['A'=>$a,'B'=>$b] as $label=>$metrics) foreach ($metrics as $metric=>$value) {
        $null=$null||$value===null;
        if ($value!==null && ($metric==='useful_next_action'?$value===false:$value>0)) $issues[]=[
            'answer'=>$label,'category'=>$metric,'explanation'=>'Конкретный недостаток ответа по указанному контексту.', 'evidence_message_ids'=>[12]];
    }
    foreach (array_keys($a) as $metric) $paired=$paired||($a[$metric]!==null&&$b[$metric]!==null);
    return ['ok'=>true,'assessable'=>$paired,'answers'=>['A'=>$a,'B'=>$b], 'issues'=>$issues,
        'limitations'=>$null?['Не все сведения доступны для оценки этой метрики.']:[],
        'preference'=>$paired?$preference:'insufficient_context'];
}
function controlRow(int $id,array $review,?array $order=null): array {
    return ['case_id'=>hash('sha256','case-'.$id),'group_key'=>hash('sha256','group-'.$id),
        'author_statuses'=>['A'=>'completed','B'=>'completed'],'reviewer_status'=>'completed', 'review'=>$review,
        'evidence_message_ids'=>[11,12], 'order'=>$order??['A'=>'baseline','B'=>'candidate']];
}

$contract=VirtualReplyControlProtocol::authorContract('FROZEN HISTORICAL AUTHOR INSTRUCTIONS');
controlCheck('historical author contract remains exact',VirtualReplyControlProtocol::validateAuthorContract($contract)===$contract
    &&$contract['instructions']==='FROZEN HISTORICAL AUTHOR INSTRUCTIONS');
controlCheck('author profile and schema reuse the ordinary private role',$contract['profile']===VirtualReplyProtocol::profile('author')
    &&$contract['schema']===VirtualReplyProtocol::schema('author'));
$current=VirtualReplyControlProtocol::authorContract(VirtualReplyProtocol::instructions('author'));
controlCheck('current author revision agrees with existing frozen protocol',$current['instruction_revision']===VirtualReplyProtocol::revision('author'));
$bad=$contract;$bad['instructions']='Silently modified';
controlCheck('instruction mutation cannot retain old revision',controlReject(fn()=>VirtualReplyControlProtocol::validateAuthorContract($bad)));
$bad=$contract;$bad['profile']['model']='weaker';$bad['instruction_revision']=VirtualReplyControlProtocol::contractRevision('author',$bad['instructions'],$bad['schema'],$bad['profile']);
controlCheck('a self-consistent hash does not authorize a different author model',controlReject(fn()=>VirtualReplyControlProtocol::validateAuthorContract($bad)));
$bad=$contract;$bad['schema']['additionalProperties']=true;$bad['instruction_revision']=VirtualReplyControlProtocol::contractRevision('author',$bad['instructions'],$bad['schema'],$bad['profile']);
controlCheck('unsupported author output contract is rejected',controlReject(fn()=>VirtualReplyControlProtocol::validateAuthorContract($bad)));
$reviewer=VirtualReplyControlProtocol::reviewerContract();
controlCheck('blind reviewer remains Astra high with distinct response contract',$reviewer['profile']===VirtualReplyProtocol::profile('reviewer')
    &&$reviewer['schema']!==VirtualReplyProtocol::schema('reviewer')&&strlen($reviewer['instruction_revision'])===64
    &&$reviewer['instruction_revision']===VirtualReplyControlProtocol::revision());
controlCheck('frozen reviewer contract is validated without substitution',VirtualReplyControlProtocol::validateReviewerContract($reviewer)===$reviewer);
$historical=$reviewer;$historical['instructions']='FROZEN PREVIOUS BLIND RUBRIC';
$historical['instruction_revision']=VirtualReplyControlProtocol::contractRevision('reviewer',$historical['instructions'],$historical['schema'],$historical['profile']);
controlCheck('supported historical reviewer rubric can stay frozen',VirtualReplyControlProtocol::validateReviewerContract($historical)===$historical);
$bad=$reviewer;$bad['schema']=VirtualReplyProtocol::schema('reviewer');
$bad['instruction_revision']=VirtualReplyControlProtocol::contractRevision('reviewer',$bad['instructions'],$bad['schema'],$bad['profile']);
controlCheck('single-answer reviewer schema cannot masquerade as blind control',controlReject(fn()=>VirtualReplyControlProtocol::validateReviewerContract($bad)));
$metadata=VirtualReplyControlProtocol::metadata('reviewer',['model'=>'gpt-6-astra','reasoning_effort'=>'high','service_tier'=>'unknown']);
controlCheck('unknown declared tier does not become requested default or provider proof',$metadata['declared_profile']['service_tier']==='unknown'
    &&$metadata['requested_profile']['service_tier']==='default'&&!$metadata['provider_verified']&&$metadata['profile_source']==='worker_declared');
controlCheck('metadata cannot claim a weaker reviewer',controlReject(fn()=>VirtualReplyControlProtocol::metadata('reviewer',[
    'model'=>'gpt-6.1-sol','reasoning_effort'=>'high','service_tier'=>'unknown'])));
controlCheck('metadata cannot supply provider verification',controlReject(fn()=>VirtualReplyControlProtocol::metadata('reviewer',[
    'model'=>'gpt-6-astra','reasoning_effort'=>'high','service_tier'=>'unknown','provider_verified'=>true])));

$context=controlContext();$a=controlAuthor();$b=controlAuthor();$b['should_draft']=false;$b['draft']=null;$b['next_action']='prepare_selection';
$payload=VirtualReplyControlProtocol::blindPayload($context,[11,12],$a,$b);
controlCheck('blind payload contains only exact source and exact answer objects',$payload===['context'=>$context,'answers'=>['A'=>$a,'B'=>$b]]);
$a=array_reverse($a,true);$exact=VirtualReplyControlProtocol::blindPayload($context,[11,12],$a,$b);
controlCheck('answer field order and values are not normalized',$exact['answers']['A']===$a);
controlCheck('source reference time remains available without completion timestamps',$payload['context']['cutoff']['reference_time']==='2026-10-21T10:00:00Z'
    &&array_keys($payload)===['context','answers']);
$badContext=$context;$badContext['baseline_revision']=str_repeat('a',64);
controlCheck('orchestrator version labels cannot enter blind source envelope',controlReject(fn()=>VirtualReplyControlProtocol::blindPayload($badContext,[11,12],$a,$b)));
$bad=$b;$bad['evidence_message_ids']=[13];
controlCheck('blind payload rejects future author evidence',controlReject(fn()=>VirtualReplyControlProtocol::blindPayload($context,[11,12],$a,$bad)));
$bad=$b;$bad['history_incomplete']=true;
controlCheck('both authors must preserve the same frozen history boundary',controlReject(fn()=>VirtualReplyControlProtocol::blindPayload($context,[11,12],$a,$bad)));
controlCheck('invented evidence inventory is rejected',controlReject(fn()=>VirtualReplyControlProtocol::blindPayload($context,[11,12,13],$a,$b)));
$large=$context;$large['messages'][0]['text']=str_repeat('я',100000);
controlCheck('oversized source is rejected rather than truncated for comparison',controlReject(fn()=>VirtualReplyControlProtocol::blindPayload($large,[11,12],$a,$b)));

$clean=controlReview();
controlCheck('zero counts and a tie are assessed results',VirtualReplyControlProtocol::validate($clean,[11,12])===$clean);
$reordered=array_reverse($clean,true);
controlCheck('review validation returns exact decoded JSON object',VirtualReplyControlProtocol::validate($reordered,[11,12])===$reordered);
$none=controlReview(controlMetrics(null,null,null,null),controlMetrics(null,null,null,null));
controlCheck('fully unavailable context is explicit and cannot prefer an arm',!$none['assessable']&&$none['preference']==='insufficient_context'
    &&VirtualReplyControlProtocol::validate($none,[11,12])===$none);
$partial=controlReview(controlMetrics(1,null,0,true),controlMetrics(0,0,0,true),'B');
controlCheck('a null metric does not erase other assessed paired metrics',VirtualReplyControlProtocol::validate($partial,[11,12])===$partial);
$unpaired=controlReview(controlMetrics(0,null,null,null),controlMetrics(null,0,null,null));
controlCheck('two unilateral assessments are not an assessable pair',VirtualReplyControlProtocol::validate($unpaired,[11,12])===$unpaired&&!$unpaired['assessable']);
foreach ([['unnecessary_questions',-1],['unnecessary_questions',11],['lost_constraints','0'],['unsupported_claims',1.0],['useful_next_action',1]] as [$metric,$value]) {
    $bad=$clean;$bad['answers']['A'][$metric]=$value;
    controlCheck('reject wrong metric type or range '.json_encode([$metric,$value]),controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
}
$bad=$none;$bad['assessable']=true;$bad['preference']='tie';
controlCheck('all-null scores cannot be called assessable',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$clean;$bad['assessable']=false;$bad['preference']='insufficient_context';
controlCheck('a fully scored pair cannot be called unavailable',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$partial;$bad['preference']='insufficient_context';
controlCheck('assessable preference cannot silently discard scored pair',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$none;$bad['limitations']=[];
controlCheck('missing metric has an explicit limitation',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$partial;$bad['issues'][0]['evidence_message_ids']=[13];
controlCheck('review issue cannot cite future or foreign evidence',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$partial;$bad['issues'][0]['evidence_message_ids']=[12,12];
controlCheck('review issue evidence must be unique',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$partial;$bad['issues']=[];
controlCheck('a numerical fault requires a concrete corresponding issue',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$clean;$bad['issues']=$partial['issues'];
controlCheck('an error issue cannot coexist with a zero count',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$pure=$partial;$pure['issues'][0]['evidence_message_ids']=[];
controlCheck('a text-only answer flaw need not invent a source ID',VirtualReplyControlProtocol::validate($pure,[11,12])===$pure);
$bad=$partial;$bad['issues']=array_fill(0,11,$partial['issues'][0]);
controlCheck('issue count is bounded',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$none;$bad['limitations']=array_fill(0,11,'Недостаточно данных.');
controlCheck('limitation count is bounded',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$partial;$bad['issues'][0]['explanation']=str_repeat('я',501);
controlCheck('explanations are bounded in Unicode characters',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$none;$bad['limitations']=["invalid\x00text"];
controlCheck('control bytes do not enter explanations',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$none;$bad['limitations']=["\xff"];
controlCheck('malformed Unicode is rejected before serialization',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$partial;$bad['issues']=array_fill(0,10,$partial['issues'][0]);
foreach ($bad['issues'] as &$issue) $issue['explanation']=str_repeat('я',500);unset($issue);
$bad['limitations']=array_fill(0,10,str_repeat('я',400));
controlCheck('combined Unicode content respects existing MCP encoded-byte cap',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));
$bad=$clean;$bad['version']='candidate';
controlCheck('unknown review fields are rejected',controlReject(fn()=>VirtualReplyControlProtocol::validate($bad,[11,12])));

$first=controlRow(1,controlReview(controlMetrics(2,1,0,false),controlMetrics(0,null,0,true),'B'));
$second=controlRow(2,controlReview(controlMetrics(0,0,1,true),controlMetrics(0,0,null,false),'A'),['A'=>'candidate','B'=>'baseline']);
$third=controlRow(3,$none);
$unknown=controlRow(4,$clean);$unknown['author_statuses']['A']='unknown';$unknown['reviewer_status']='pending';$unknown['review']=null;$unknown['order']=null;
$invalidated=controlRow(5,$clean);$invalidated['author_statuses']['B']='invalidated';
$incomplete=controlRow(6,$clean);$incomplete['author_statuses']['B']='pending';$incomplete['reviewer_status']='pending';$incomplete['review']=null;$incomplete['order']=null;
$awaiting=controlRow(7,$clean);$awaiting['reviewer_status']='running';$awaiting['review']=null;
$summary=VirtualReplyControlProtocol::aggregate([$first,$second,$third,$unknown,$invalidated,$incomplete,$awaiting]);
controlCheck('incomplete, unknown and invalidated pairs do not become quality observations',$summary['cases_total']===7&&$summary['unique_groups']===7
    &&$summary['reviews_completed']===3&&$summary['assessable_reviews']===2&&$summary['unassessable_reviews']===1
    &&$summary['unique_reviewed_groups']===3&&$summary['unique_assessable_groups']===2
    &&$summary['excluded']===['invalidated'=>1,'unknown'=>1,'incomplete_authors'=>1,'awaiting_review'=>1]);
controlCheck('private A/B mapping unblinds preference without favoring position',$summary['preference']['candidate']===2
    &&$summary['preference']['baseline']===0&&$summary['preference']['candidate_rate']===1&&$summary['preference']['insufficient_context']===1);
controlCheck('count rates use cases and preserve separate event counts',$summary['metrics']['unnecessary_questions']['paired_denominator']===2
    &&$summary['metrics']['unnecessary_questions']['baseline']['events']===2
    &&$summary['metrics']['unnecessary_questions']['baseline']['affected_cases']===1
    &&$summary['metrics']['unnecessary_questions']['baseline']['affected_case_rate']===0.5
    &&$summary['metrics']['unnecessary_questions']['baseline']['mean_events']===1);
controlCheck('one-arm null excludes both arms from only that metric denominator',$summary['metrics']['lost_constraints']['paired_denominator']===1
    &&$summary['metrics']['lost_constraints']['baseline']['events']===0
    &&$summary['metrics']['unsupported_claims']['paired_denominator']===1
    &&$summary['metrics']['unsupported_claims']['candidate']['events']===0);
controlCheck('useful action compares paired booleans, not filled draft counts',$summary['metrics']['useful_next_action']['paired_denominator']===2
    &&$summary['metrics']['useful_next_action']['baseline']['useful_cases']===0
    &&$summary['metrics']['useful_next_action']['candidate']['useful_cases']===2);
$empty=VirtualReplyControlProtocol::aggregate([]);
controlCheck('empty report has zero observations and no fabricated rates',$empty['unique_groups']===0
    &&$empty['metrics']['lost_constraints']['paired_denominator']===0&&$empty['metrics']['lost_constraints']['baseline']['affected_case_rate']===null
    &&$empty['preference']['candidate_rate']===null);
$duplicate=$second;$duplicate['group_key']=$first['group_key'];
controlCheck('repeated group cannot inflate independent denominator',controlReject(fn()=>VirtualReplyControlProtocol::aggregate([$first,$duplicate])));
$duplicate=$second;$duplicate['case_id']=$first['case_id'];
controlCheck('repeated case cannot inflate denominator',controlReject(fn()=>VirtualReplyControlProtocol::aggregate([$first,$duplicate])));
$bad=$first;$bad['order']=['A'=>'candidate','B'=>'candidate'];
controlCheck('unblinding must map one arm to each version',controlReject(fn()=>VirtualReplyControlProtocol::aggregate([$bad])));
$bad=$first;$bad['order']=null;
controlCheck('completed comparison cannot lose its private mapping',controlReject(fn()=>VirtualReplyControlProtocol::aggregate([$bad])));
$bad=$first;$bad['group_key']='raw-customer-identity';
controlCheck('group key is opaque rather than a raw identity',controlReject(fn()=>VirtualReplyControlProtocol::aggregate([$bad])));
$bad=$first;$bad['review']['issues'][0]['evidence_message_ids']=[999];
controlCheck('aggregation revalidates completed real reviews',controlReject(fn()=>VirtualReplyControlProtocol::aggregate([$bad])));
$reverse=VirtualReplyControlProtocol::aggregate(array_reverse([$first,$second,$third,$unknown,$invalidated,$incomplete,$awaiting]));
controlCheck('aggregate statistics do not depend on row order',$reverse===$summary);
controlCheck('summary contains only aggregate data',!str_contains(json_encode($summary),'case_id')&&!str_contains(json_encode($summary),'group_key')
    &&!str_contains(json_encode($summary),'explanation')&&!str_contains(json_encode($summary),'evidence_message_ids'));

echo "Control protocol: $passed passed, $failed failed\n";
exit($failed?1:0);
