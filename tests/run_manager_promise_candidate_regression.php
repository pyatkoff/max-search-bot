<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-promise-candidate-fixture.php';
require_once dirname(__DIR__).'/services/ManagerPromiseCandidates.php';

$passed=0;
function candidateCheck(string $name,bool $ok,mixed $detail=null): void
{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL '.$name.($detail===null?'':' '.json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    ++$passed;echo 'PASS '.$name."\n";
}
function candidateJson(array $value): string
{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}

// These are deterministic boundary/provenance checks. No model or recognition-quality claim.
$pdo=promiseCandidateFixture();$before=promiseTaskCanonicalSnapshot($pdo);$snapshot=promiseCandidateSnapshot($pdo);
candidateCheck('authorized candidate capture uses real read-only SQL and ends its transaction',
    ($snapshot['ok']??null)===true&&!$pdo->inTransaction()&&$pdo->writeAttempts===0,$snapshot['error']??null);
$group=promiseCandidateGroup($snapshot);$source=promiseCandidateSource($snapshot);
candidateCheck('capture projects a bounded typed current-trip source set',is_array($group)&&$group['schema_version']===1
    &&$group['conversation_id']===1&&$group['as_of_message_id']===13&&$group['profile_version']===1
    &&$group['profile_status']==='fresh'&&$group['boundary_message_id']===10&&$group['sources_truncated']===false
    &&preg_match('/\A[a-f0-9]{32}\z/',$group['trip_id'])===1&&preg_match('/\A[a-f0-9]{64}\z/',$group['profile_sha256'])===1,$group);
candidateCheck('source pins contain no repeated transcript author or native identifiers',is_array($source)
    &&array_keys($source)===['source_message_id','source_message_revision','recorded_at_utc']
    &&preg_match('/\A[a-f0-9]{64}\z/',$source['source_message_revision'])===1&&$source['recorded_at_utc']!==null,$source);
candidateCheck('capture leaves business records and promise operations untouched',promiseTaskCanonicalSnapshot($pdo)===$before&&promiseTaskCounts($pdo)===[0,0]);
$manual=ManagerPromiseTaskService::context(1,1,101,12,'send_calculation');
candidateCheck('candidate source revision agrees with the existing manual binding owner',($manual['ok']??null)===true
    &&$manual['source']['revision']===$source['source_message_revision']&&$manual['profile']['sha256']===$group['profile_sha256'],$manual);

$new=promiseCandidateProposal([promiseCandidateItem()]);$observed=null;$input=null;
$result=promiseCandidateGenerate($snapshot,$new,$observed,$input);
candidateCheck('no_contact and a pending promise coexist without a customer draft',$result===$new&&$result['draft']===null,$result);
candidateCheck('the actual normalized provider boundary carries the captured source group',($observed['context']['promise_sources']??null)===$group,$observed['context']['promise_sources']??null);
candidateCheck('the input archive preserves the exact source group and its hash', $input instanceof ManagerAiDraftInputEvidence
    &&($input->context()['promise_sources']??null)===$group
    &&ManagerAiDraftInputEvidence::fromJson($input->json())?->hash()===$input->hash());
candidateCheck('the existing shared prompt includes candidate instructions exactly once',
    substr_count($observed['instructions'],ManagerPromiseCandidates::instructions())===1);
$forged=ManagerAiDraftService::generate($observed['context'],static fn():array=>$new);
candidateCheck('caller-supplied promise_sources cannot replace server-owned captured reference evidence',empty($forged['ok']));
$projected=ManagerPromiseCandidates::project($new,$snapshot);
$expectedKeys=['conversation_id','as_of_message_id','kind','source_message_id','source_message_revision','trip_id','profile_version','profile_sha256','profile_status','quote','deadline_text','deadline','deadline_state'];
candidateCheck('UI projection uses only the captured pins and requires explicit manual deadline choice',count($projected)===1
    &&array_keys($projected[0])===$expectedKeys&&$projected[0]['source_message_revision']===$source['source_message_revision']
    &&$projected[0]['trip_id']===$group['trip_id']&&$projected[0]['profile_sha256']===$group['profile_sha256']
    &&$projected[0]['deadline']===null&&$projected[0]['deadline_state']==='manual_required',$projected);

// Structural validity cannot establish the meaning of a conditional/negated promise.
$schema=ManagerPromiseCandidates::schema();
candidateCheck('provider schema bounds the exact four-field candidate shape',$schema['type']==='array'&&$schema['maxItems']===3
    &&$schema['items']['additionalProperties']===false&&$schema['items']['required']===array_keys($schema['items']['properties'])
    &&count($schema['items']['required'])===4,$schema);
candidateCheck('empty candidate list and both supported kinds are structurally valid',ManagerPromiseCandidates::validShape([])
    &&ManagerPromiseCandidates::validShape([promiseCandidateItem(),promiseCandidateItem(['kind'=>'send_selection','source_message_id'=>13])]));
$invalidShapes=[
    'null'=>null,'map'=>['candidate'=>promiseCandidateItem()],
    'unknown key'=>[promiseCandidateItem(['task_id'=>101])],
    'string source'=>[promiseCandidateItem(['source_message_id'=>'12'])],
    'zero source'=>[promiseCandidateItem(['source_message_id'=>0])],
    'unsupported kind'=>[promiseCandidateItem(['kind'=>'check_price'])],
    'empty quote'=>[promiseCandidateItem(['quote'=>''])],
    'oversized quote'=>[promiseCandidateItem(['quote'=>str_repeat('я',601)])],
    'empty deadline'=>[promiseCandidateItem(['deadline_text'=>''])],
    'oversized deadline'=>[promiseCandidateItem(['deadline_text'=>str_repeat('я',161)])],
    'duplicate identity'=>[promiseCandidateItem(),promiseCandidateItem()],
    'four candidates'=>array_map(static fn(int $id):array=>promiseCandidateItem(['source_message_id'=>$id]),[12,13,14,15]),
];
foreach($invalidShapes as $name=>$bad)candidateCheck('strict candidate shape rejects '.$name,!ManagerPromiseCandidates::validShape($bad));
foreach([
    'inbound source'=>promiseCandidateItem(['source_message_id'=>11,'quote'=>'Даты подходят.']),
    'foreign conversation'=>promiseCandidateItem(['source_message_id'=>20,'quote'=>'Обещание из другого диалога.']),
    'invented quote'=>promiseCandidateItem(['quote'=>'Пришлю расчёт бесплатно завтра.']),
    'invented deadline'=>promiseCandidateItem(['deadline_text'=>'завтра']),
]as $name=>$bad)candidateCheck('same-snapshot validation rejects '.$name,!ManagerPromiseCandidates::valid([$bad],$observed['context']));
$withoutProfile=$observed['context'];unset($withoutProfile['request_profile']);
candidateCheck('candidate validation requires the current trip actually admitted to model input',!ManagerPromiseCandidates::valid([promiseCandidateItem()],$withoutProfile));
$badMessages=$observed['context'];$badMessages['messages']='not-a-message-list';
candidateCheck('malformed message container fails closed without an array_column exception',!ManagerPromiseCandidates::valid([promiseCandidateItem()],$badMessages));
foreach(['trip_id'=>str_repeat('f',32),'version'=>99,'profile_sha256'=>str_repeat('0',64),
    'status'=>'needs_review','boundary_message_id'=>13]as $field=>$value){
    $mismatched=$observed['context'];$mismatched['request_profile'][$field]=$value;
    candidateCheck('candidate source pins cannot attach to another admitted profile '.$field,!ManagerPromiseCandidates::valid([promiseCandidateItem()],$mismatched));
}
$budgetBaseline=$withoutProfile;unset($budgetBaseline['promise_sources']);
$admitted=(new ReflectionMethod(ManagerAiDraftService::class,'admitPromiseSources'))->invoke(null,$budgetBaseline,$group);
candidateCheck('optional evidence cannot outlive a profile removed by the request budget',
    $admitted[0]===$budgetBaseline&&($admitted[1]['status']??null)==='omitted'&&($admitted[1]['reason']??null)==='profile_unavailable');
candidateCheck('unknown model fields are not projected into actionable advice',ManagerPromiseCandidates::project(promiseCandidateProposal([promiseCandidateItem(['task_id'=>101])]),$snapshot)===[]);

// Changes come from the fixture; capture is forbidden from writing or repairing them.
$original=$pdo->query('SELECT * FROM messages WHERE id=12')->fetch();
$mutations=[
    'AI sender'=>['sender_type'=>'ai'],
    'inbound manager'=>['direction'=>'inbound'],
    'missing native id'=>['external_message_id'=>''],
    'unknown author'=>['sender_id'=>'999'],
    'nonnumeric author'=>['sender_id'=>'manager'],
    'wrong channel'=>['channel'=>'max'],
    'quoted container'=>['metadata_json'=>candidateJson(['linked_message'=>['text'=>'Пришлю расчёт']])],
    'forward container'=>['metadata_json'=>candidateJson(['forwarded'=>true])],
    'forward origin'=>['metadata_json'=>candidateJson(['forward_origin'=>['id'=>'synthetic']])],
    'callback event'=>['metadata_json'=>candidateJson(['type'=>'callback'])],
    'unreadable metadata'=>['metadata_json'=>'{'],
    'oversized source'=>['text'=>str_repeat('я',6001)],
];
foreach($mutations as $name=>$changes){
    $set=[];$args=[];foreach($changes as $key=>$value){$set[]=$key.'=?';$args[]=$value;}
    $pdo->prepare('UPDATE messages SET '.implode(',',$set).' WHERE id=12')->execute($args);
    $changed=promiseCandidateSnapshot($pdo);
    candidateCheck('source admission rejects '.$name,promiseCandidateSource($changed)===null&&$pdo->writeAttempts===0);
    foreach($changes as $key=>$value)$pdo->prepare('UPDATE messages SET '.$key.'=? WHERE id=12')->execute([$original[$key]]);
}
foreach(['sender_id'=>'2','external_message_id'=>'edited-native-id','metadata_json'=>candidateJson(['edited'=>true])]as $key=>$value){
    $pdo->prepare('UPDATE messages SET '.$key.'=? WHERE id=12')->execute([$value]);$changed=promiseCandidateSnapshot($pdo);
    candidateCheck('eligible '.$key.' edit changes both source and prepared-context revisions',promiseCandidateSource($changed)!==null
        &&promiseCandidateSource($changed)['source_message_revision']!==$source['source_message_revision']&&$changed['revision']!==$snapshot['revision']);
    $pdo->prepare('UPDATE messages SET '.$key.'=? WHERE id=12')->execute([$original[$key]]);
}
candidateCheck('read-only capture restores no edited business data and retains no transaction',!$pdo->inTransaction()&&$pdo->writeAttempts===0
    &&promiseTaskCanonicalSnapshot($pdo)===$before);

$pdo=promiseCandidateFixture();$fresh=promiseCandidateSnapshot($pdo);requestProfileMessage($pdo,14,'customer','Спасибо.');$review=promiseCandidateSnapshot($pdo);
candidateCheck('a subsequent thank-you marks profile review without inventing a new promise source',promiseCandidateGroup($review)['profile_status']==='needs_review'
    &&promiseCandidateSource($review,14)===null&&promiseCandidateSource($review,12)!==null);
$reviewed=ManagerPromiseCandidates::project($new,$review);
candidateCheck('needs_review remains visible as a proposal status rather than automatic authorization',count($reviewed)===1
    &&$reviewed[0]['profile_status']==='needs_review'&&!array_key_exists('can_bind',$reviewed[0])&&promiseTaskCounts($pdo)===[0,0]);
$pdo->exec("UPDATE messages SET text='Изменённые условия исходной поездки' WHERE id=10");$changed=promiseCandidateSnapshot($pdo);
candidateCheck('changed profile citations withdraw candidate evidence',promiseCandidateGroup($changed)===null&&ManagerPromiseCandidates::project($new,$changed)===[]);
$pdo=promiseCandidateFixture();$fresh=promiseCandidateSnapshot($pdo);$view=ManagerRequestProfileService::read(1,1);
$saved=ManagerRequestProfileService::save(1,1,requestProfileCommand($view,['label'=>'Уточнённый отпуск']));$version=promiseCandidateSnapshot($pdo);
candidateCheck('same-trip profile version invalidates the earlier prepared revision',($saved['ok']??null)===true
    &&promiseCandidateGroup($version)['profile_version']===2&&promiseCandidateGroup($version)['trip_id']===promiseCandidateGroup($fresh)['trip_id']
    &&$version['revision']!==$fresh['revision']);
$view=ManagerRequestProfileService::read(1,1);$saved=ManagerRequestProfileService::save(1,1,requestProfileCommand($view,
    ['new_trip'=>true,'boundary_message_id'=>13,'label'=>'Новая поездка','fields'=>[]]));$nextTrip=promiseCandidateSnapshot($pdo);
candidateCheck('new trip boundary excludes the old promise source',($saved['ok']??null)===true&&promiseCandidateSource($nextTrip,12)===null
    &&promiseCandidateGroup($nextTrip)['trip_id']!==promiseCandidateGroup($fresh)['trip_id']&&ManagerPromiseCandidates::project($new,$nextTrip)===[],$saved);
$pdo=promiseCandidateFixture();$pdo->exec('DELETE FROM manager_request_profiles');$missing=promiseCandidateSnapshot($pdo);
candidateCheck('missing saved trip has no actionable candidate',promiseCandidateGroup($missing)===null&&ManagerPromiseCandidates::project($new,$missing)===[]);
foreach([3,4,5]as $actor)candidateCheck('unauthorized actor cannot capture candidate source '.$actor,empty(promiseCandidateSnapshot($pdo,$actor)['ok']));
$pdo=promiseCandidateFixture();
for($id=30;$id<55;++$id)requestProfileMessage($pdo,$id,'manager','Пришлю расчёт по согласованным условиям.');
$bounded=promiseCandidateSnapshot($pdo);$boundedGroup=promiseCandidateGroup($bounded);
candidateCheck('capture bounds eligible sources to the latest twenty visible messages',is_array($boundedGroup)
    &&count($boundedGroup['sources'])===20&&$boundedGroup['sources_truncated']===true
    &&promiseCandidateSource($bounded,12)===null&&promiseCandidateSource($bounded,35)!==null&&promiseCandidateSource($bounded,54)!==null,$boundedGroup);

// No model call: inject exact outputs to verify deterministic deadline precision only.
$dateCases=[
    ['завтра','2026-11-13T20:59:00Z',['kind'=>'date','value'=>'2026-11-14','zone'=>'Europe/Moscow']],
    ['завтра','2026-11-13T21:01:00Z',['kind'=>'date','value'=>'2026-11-15','zone'=>'Europe/Moscow']],
    ['сегодня','2026-11-13T21:01:00Z',['kind'=>'date','value'=>'2026-11-14','zone'=>'Europe/Moscow']],
    ['послезавтра','2026-11-13T20:59:00Z',['kind'=>'date','value'=>'2026-11-15','zone'=>'Europe/Moscow']],
    ['завтра к 14:30','2026-11-13T20:59:00Z',['kind'=>'instant','value'=>'2026-11-14T14:30:00+03:00']],
    ['2026-11-20',null,['kind'=>'date','value'=>'2026-11-20','zone'=>'Europe/Moscow']],
    ['20.11.2026',null,['kind'=>'date','value'=>'2026-11-20','zone'=>'Europe/Moscow']],
    ['20 ноября 2026',null,['kind'=>'date','value'=>'2026-11-20','zone'=>'Europe/Moscow']],
    ['2026-11-20T14:30:00+03:00',null,['kind'=>'instant','value'=>'2026-11-20T14:30:00+03:00']],
    ['завтра',null,null],['вечером','2026-11-13T20:59:00Z',null],['31.02.2026',null,null],
];
$pdo=promiseCandidateFixture();
foreach($dateCases as [$text,$clock,$deadline]){
    $message='Пришлю расчёт '.$text.'.';promiseCandidateMessage($pdo,12,$message,$clock);$s=promiseCandidateSnapshot($pdo);
    $p=promiseCandidateProposal([promiseCandidateItem(['quote'=>$message,'deadline_text'=>$text])]);$v=ManagerPromiseCandidates::project($p,$s);
    candidateCheck('deadline preserves Moscow source time and precision: '.$text.' / '.($clock??'unknown'),count($v)===1
        &&$v[0]['deadline']===$deadline&&$v[0]['deadline_state']===($deadline===null?'manual_required':'suggested'),$v);
}

// Real persistence/readback paths retain legacy bytes and the complete new proposal.
$pdo=promiseCandidateFixture();$snapshot=promiseCandidateSnapshot($pdo);$job=promiseCandidateJob($pdo,$snapshot);
$withoutReceipt=ManagerPreparedReplyService::complete($job,$snapshot['revision'],$new);
candidateCheck('prepared promise cannot be accepted without the exact issued input receipt',($withoutReceipt['error']??null)==='invalid_input_receipt'
    &&$pdo->query('SELECT status FROM manager_prepared_replies')->fetchColumn()==='pending'
    &&ManagerPreparedInputService::linked($pdo,$job)===null,$withoutReceipt);
$receipt=promiseCandidateIssueInput($job,true);
$omitted=ManagerPreparedReplyService::complete($job,$snapshot['revision'],$new,$receipt,2,str_repeat('c',32));
candidateCheck('fresh recapture cannot restore promise sources omitted from the issued worker input',($omitted['error']??null)==='invalid_proposal'
    &&$pdo->query('SELECT status FROM manager_prepared_replies')->fetchColumn()==='pending'
    &&ManagerPreparedInputService::linked($pdo,$job)===null,$omitted);
$pdo=promiseCandidateFixture();$snapshot=promiseCandidateSnapshot($pdo);$legacy=promiseCandidateProposal();$legacyJson=candidateJson($legacy);
$legacyResult=promiseCandidateGenerate($snapshot,$legacy);
candidateCheck('legacy eight-field output remains byte-exact without an appended empty list',$legacyResult===$legacy
    &&!array_key_exists('manager_promises',$legacyResult)&&ManagerAiReplyAdmission::proposalHash($legacy)===hash('sha256',$legacyJson));
candidateCheck('new proposal hash includes the exact candidate evidence',ManagerAiReplyAdmission::proposalHash($new)===hash('sha256',candidateJson($new))
    &&ManagerAiReplyAdmission::proposalHash($new)!==ManagerAiReplyAdmission::proposalHash(promiseCandidateProposal([])));
$reportMethod=new ReflectionMethod(ManagerAiDraftFeedbackReport::class,'proposal');
foreach(['legacy'=>$legacy,'new'=>$new]as $label=>$proposal){
    $captured=null;$evidence=null;$generated=promiseCandidateGenerate($snapshot,$proposal,$captured,$evidence);
    $draft=ManagerAiDraftFeedbackService::record(1,1,$snapshot,$generated,null,$evidence);
    $q=$pdo->prepare('SELECT proposal_json FROM manager_ai_drafts WHERE draft_id=?');$q->execute([$draft]);$json=$q->fetchColumn();
    candidateCheck($label.' feedback persistence and report preserve the exact proposal',is_string($draft)&&$json===candidateJson($proposal)
        &&$reportMethod->invoke(null,$json)===$proposal,['draft'=>$draft,'json'=>$json]);
}
$legacyPrepared=promiseCandidatePrepared($pdo,$snapshot,$legacy);
candidateCheck('legacy prepared readback neither appends candidates nor rewrites archived JSON',($legacyPrepared['prepared']??null)===true
    &&$legacyPrepared['proposal']===$legacy&&$pdo->query("SELECT proposal_json FROM manager_prepared_replies WHERE status='ready'")->fetchColumn()===$legacyJson,$legacyPrepared);
$pdo=promiseCandidateFixture();$snapshot=promiseCandidateSnapshot($pdo);
$before=promiseTaskCanonicalSnapshot($pdo);$prepared=promiseCandidatePrepared($pdo,$snapshot,$new);
candidateCheck('canonical prepared complete and readback preserve the nine-field proposal',($prepared['prepared']??null)===true&&$prepared['proposal']===$new,$prepared);
candidateCheck('generation feedback preparation and projection never mutate tasks or promise bindings',promiseTaskCanonicalSnapshot($pdo)===$before&&promiseTaskCounts($pdo)===[0,0]);
$pdo->exec("UPDATE messages SET external_message_id='source-changed-after-ready' WHERE id=12");
candidateCheck('prepared lookup rejects old candidate pins after an otherwise invisible native-id edit',
    empty(ManagerPreparedReplyService::prepared(1,1,$snapshot)['prepared']));

$pdo=promiseCandidateFixture();$snapshot=promiseCandidateSnapshot($pdo);$manual=ManagerPromiseTaskService::context(1,1,101,12,'send_calculation');
$bound=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand($manual));
promiseTaskManualEdit($pdo,"UPDATE lead_tasks SET status='done',title='Завершено вручную' WHERE id=101");
$before=promiseTaskCanonicalSnapshot($pdo);$projection=ManagerPromiseCandidates::project($new,$snapshot);
$repeatProjection=ManagerPromiseCandidates::project($new,$snapshot);$current=ManagerPromiseTaskService::context(1,1,101,12,'send_calculation');
candidateCheck('repeated proposals leave an existing binding and manually completed task untouched',($bound['ok']??null)===true
    &&$projection===$repeatProjection&&promiseTaskCounts($pdo)===[1,1]&&promiseTaskCanonicalSnapshot($pdo)===$before
    &&($current['binding']['promise_id']??null)===$bound['promise_id']&&$current['task']['status']==='done',$current);

echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
