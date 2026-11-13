<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-promise-task-fixture.php';
require_once dirname(__DIR__).'/services/ManagerPromiseTaskService.php';
$passed=0;
function promiseCheck(string $name,bool $ok,mixed $detail=null): void
{
    global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name.($detail===null?'':' '.json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));++$passed;echo 'PASS '.$name."\n";
}
function promiseContext(int $task=101,int $source=12,string $kind='send_calculation',int $actor=1,int $cid=1): array
{return ManagerPromiseTaskService::context($actor,$cid,$task,$source,$kind);}
function promiseFailure(string $name,array $result): void{promiseCheck($name,empty($result['ok'])&&is_string($result['error']??null),$result);}

$p=promiseTaskFixture();$before=promiseTaskCanonicalSnapshot($p);$context=promiseContext();
promiseCheck('context identifies the authorized task, exact trip and genuine manager source',!empty($context['ok'])&&$context['can_bind']===true&&$context['viewer_id']===1&&$context['conversation_id']===1
    &&$context['task']['id']===101&&$context['profile']['version']===1&&$context['profile']['boundary_message_id']===10&&$context['source']['id']===12&&$context['source']['author_manager_id']===1,$context);
promiseCheck('context pins complete revisions and retains validated recording time',preg_match('/^[a-f0-9]{32}$/',$context['profile']['trip_id'])===1
    &&preg_match('/^[a-f0-9]{64}$/',$context['profile']['sha256'])===1&&preg_match('/^[a-f0-9]{64}$/',$context['profile']['context_sha256'])===1
    &&preg_match('/^[a-f0-9]{64}$/',$context['source']['revision'])===1&&$context['source']['recorded_at_utc']!==null&&$context['binding']===null);
promiseCheck('context is read-only for tasks, transcript, profile, events and read markers',promiseTaskCanonicalSnapshot($p)===$before&&promiseTaskCounts($p)===[0,0]);
$canonicalProfile=ManagerRequestProfileService::read(1,1)['profile'];
promiseCheck('explicit preview shows the same named trip and field values as the canonical pinned profile',
    $context['profile']['label']===$canonicalProfile['label']&&$context['profile']['fields']===$canonicalProfile['fields']
    &&$context['profile']['field_labels']===ManagerRequestProfile::FIELDS&&$context['profile']['version']===$canonicalProfile['version']);
$command=promiseTaskCommand($context);$bound=ManagerPromiseTaskService::bind(1,1,$command);
promiseCheck('explicit bind links one existing task without any permitted task write',!empty($bound['ok'])&&$bound['id']===101&&$bound['replayed']===false&&$bound['reused']===false
    &&preg_match('/^[a-f0-9]{64}$/',$bound['promise_id'])===1&&promiseTaskCounts($p)===[1,1],$bound);
promiseCheck('binding leaves all canonical business records byte-exact',promiseTaskCanonicalSnapshot($p)===$before);
$receipt=ManagerPromiseTaskService::operation(1,1,$command['operation_id']);
promiseCheck('operation lookup reconciles the exact committed task and promise',!empty($receipt['ok'])&&$receipt['found']===true&&$receipt['promise_id']===$bound['promise_id']&&$receipt['task_id']===101&&$receipt['task']['id']===101,$receipt);
$repeat=ManagerPromiseTaskService::bind(1,1,$command);
promiseCheck('identical request replays one receipt without a second binding',!empty($repeat['ok'])&&$repeat['replayed']===true&&$repeat['promise_id']===$bound['promise_id']&&promiseTaskCounts($p)===[1,1],$repeat);
$mismatch=ManagerPromiseTaskService::bind(1,1,array_replace($command,['deadline'=>['kind'=>'date','value'=>'2030-11-20','zone'=>'Europe/Moscow']]));
promiseCheck('operation id cannot be reused with another payload',($mismatch['error']??null)==='operation_mismatch'&&promiseTaskCounts($p)===[1,1],$mismatch);
$otherTask=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand(promiseContext(102)));
promiseCheck('one stable promise cannot be linked to two tasks',($otherTask['error']??null)==='binding_conflict'&&promiseTaskCounts($p)===[1,1],$otherTask);
$otherPromise=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand(promiseContext(101,13,'send_selection'),['kind'=>'send_selection']));
promiseCheck('one task cannot silently acquire a different promise',($otherPromise['error']??null)==='binding_conflict'&&promiseTaskCounts($p)===[1,1],$otherPromise);
$notFound=ManagerPromiseTaskService::operation(1,1,str_repeat('f',32));
promiseCheck('unknown operation is an honest read-only absence',!empty($notFound['ok'])&&$notFound['found']===false&&promiseTaskCounts($p)===[1,1],$notFound);
promiseFailure('operation cannot be read through another conversation',ManagerPromiseTaskService::operation(1,2,$command['operation_id']));
$otherActor=ManagerPromiseTaskService::operation(2,1,$command['operation_id']);
promiseCheck('operation identity is isolated by actor',!empty($otherActor['ok'])&&$otherActor['found']===false,$otherActor);

promiseTaskManualEdit($p,"UPDATE lead_tasks SET title='Ручная правка менеджера',due_at_utc='2031-03-03 10:00:00',is_pinned=0,reminder_attempted_at_utc='2031-03-02 10:00:00',reminder_notified_at_utc='2031-03-02 10:00:01',status='done',completed_at_utc='2031-03-03 09:00:00' WHERE id=101");
$manual=$p->query('SELECT * FROM lead_tasks WHERE id=101')->fetch(PDO::FETCH_ASSOC);requestProfileMessage($p,21,'customer','Спасибо');
$replayed=ManagerPromiseTaskService::bind(1,1,$command);
promiseCheck('recorded operation reconciles after completion and newer context without reopening or rewriting the task',!empty($replayed['ok'])&&$replayed['replayed']===true&&$replayed['task']['status']==='done'
    &&$replayed['task']['title']==='Ручная правка менеджера'&&$p->query('SELECT * FROM lead_tasks WHERE id=101')->fetch(PDO::FETCH_ASSOC)===$manual,$replayed);
$fresh=promiseContext();$reused=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand($fresh));
promiseCheck('new explicit operation with fresh pins reuses an existing completed binding',!empty($reused['ok'])&&$reused['reused']===true&&$reused['replayed']===false&&$reused['promise_id']===$bound['promise_id']&&promiseTaskCounts($p)===[1,2],$reused);
promiseCheck('manual task title, deadline, pin, completion and reminder receipts remain authoritative',$p->query('SELECT * FROM lead_tasks WHERE id=101')->fetch(PDO::FETCH_ASSOC)===$manual);
$lookup=ManagerPromiseTaskService::operation(1,1,$command['operation_id']);
promiseCheck('operation read returns current task state rather than a stale task snapshot',$lookup['task']['status']==='done'&&$lookup['task']['title']==='Ручная правка менеджера',$lookup);
$old=$command;$view=ManagerRequestProfileService::read(1,1);$newTrip=requestProfileCommand($view,['new_trip'=>true,'boundary_message_id'=>21,'label'=>'Отдельная поездка','fields'=>[]]);
promiseCheck('fixture explicitly changes the current trip',!empty(ManagerRequestProfileService::save(1,1,$newTrip)['ok']));
promiseCheck('completed receipt remains reconcilable after a new trip',!empty(ManagerPromiseTaskService::bind(1,1,$old)['replayed']));
promiseFailure('new operation cannot apply old-trip pins to the current trip',ManagerPromiseTaskService::bind(1,1,array_replace($old,['operation_id'=>bin2hex(random_bytes(16))])));

foreach([0,3,4,5] as $actor){$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());promiseFailure('unauthorized actor '.$actor.' cannot bind',ManagerPromiseTaskService::bind($actor,1,$cmd));promiseCheck('denied actor '.$actor.' leaves no record',promiseTaskCounts($p)===[0,0]);}
$p=promiseTaskFixture();$admin=promiseContext(actor:2);$adminResult=ManagerPromiseTaskService::bind(2,1,promiseTaskCommand($admin));
promiseCheck('authorized current-project administrator uses existing edit policy',!empty($adminResult['ok']),$adminResult);
$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());ManagerPromiseTaskService::bind(1,1,$cmd);$p->exec('DELETE FROM manager_projects WHERE manager_id=1');
promiseFailure('revoked project blocks even an identical successful-operation replay',ManagerPromiseTaskService::bind(1,1,$cmd));
promiseFailure('revoked project blocks receipt lookup',ManagerPromiseTaskService::operation(1,1,$cmd['operation_id']));
$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());$p->exec('UPDATE conversations SET manager_id=5 WHERE id=1');
promiseFailure('ownership change after preview blocks binding',ManagerPromiseTaskService::bind(1,1,$cmd));
promiseFailure('task from another conversation is not a valid target',promiseContext(201));
promiseFailure('source from another conversation is not accepted',promiseContext(102,20));

foreach([
    'customer'=>"UPDATE messages SET direction='inbound',sender_type='customer' WHERE id=12",
    'AI'=>"UPDATE messages SET sender_type='ai' WHERE id=12",
    'system'=>"UPDATE messages SET sender_type='system' WHERE id=12",
    'missing author'=>"UPDATE messages SET sender_id=NULL WHERE id=12",
    'non-numeric author'=>"UPDATE messages SET sender_id='1junk' WHERE id=12",
    'unknown author'=>"UPDATE messages SET sender_id='999' WHERE id=12",
    'missing native id'=>"UPDATE messages SET external_message_id='' WHERE id=12",
    'empty text'=>"UPDATE messages SET text='' WHERE id=12",
    'malformed metadata'=>"UPDATE messages SET metadata_json='{' WHERE id=12"
] as $label=>$sql){$p=promiseTaskFixture();$p->exec($sql);promiseFailure('source rejects '.$label,promiseContext());promiseCheck('rejected '.$label.' causes no writes',promiseTaskCounts($p)===[0,0]);}
foreach([['linked_message'=>['type'=>'reply']],['forward_origin'=>['type'=>'user']],['type'=>'callback']] as $metadata){$p=promiseTaskFixture();$p->prepare('UPDATE messages SET metadata_json=? WHERE id=12')->execute([json_encode($metadata)]);promiseFailure('linked, forwarded or non-message source is not a standalone promise',promiseContext());}
$p=promiseTaskFixture();$p->prepare('UPDATE messages SET text=? WHERE id=12')->execute([str_repeat('я',6001)]);promiseFailure('oversized promise text is not silently truncated',promiseContext());
$p=promiseTaskFixture();$p->exec("UPDATE messages SET sender_id='5' WHERE id=12");$historical=promiseContext();
promiseCheck('source author and current responsible actor remain distinct',!empty($historical['ok'])&&$historical['source']['author_manager_id']===5&&!empty(ManagerPromiseTaskService::bind(1,1,promiseTaskCommand($historical))['ok']),$historical);
$p=promiseTaskFixture();$p->exec("UPDATE messages SET metadata_json='{}' WHERE id=12");$unknown=promiseContext();
promiseCheck('bare legacy DATETIME is not fabricated into an exact recording instant',!empty($unknown['ok'])&&$unknown['source']['recorded_at_utc']===null,$unknown);

foreach(['text'=>"text='Содержание обещания изменилось'",'channel'=>"channel='max'",'author'=>"sender_id='5'",'native id'=>"external_message_id='changed-native-id'"] as $label=>$set){
    $p=promiseTaskFixture();$old=promiseContext();$cmd=promiseTaskCommand($old);$p->exec('UPDATE messages SET '.$set.' WHERE id=12');
    promiseFailure('same-id source change fences stale '.$label,ManagerPromiseTaskService::bind(1,1,$cmd));promiseCheck('stale '.$label.' does not create a binding',promiseTaskCounts($p)===[0,0]);
    $new=promiseContext();promiseCheck('source revision includes '.$label,empty($new['ok'])||$new['source']['revision']!==$old['source']['revision']);
}
$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());requestProfileMessage($p,21,'customer','Спасибо');
promiseFailure('new short message invalidates old context pins without interpreting thanks as a task',ManagerPromiseTaskService::bind(1,1,$cmd));
$review=promiseContext();promiseCheck('needs-review profile is explicit and does not auto-create work',!empty($review['ok'])&&$review['profile']['status']==='needs_review'&&promiseTaskCounts($p)===[0,0],$review);
promiseCheck('explicit manager confirmation can bind current needs-review context',!empty(ManagerPromiseTaskService::bind(1,1,promiseTaskCommand($review))['ok']));
$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());$p->exec("UPDATE messages SET text='Условия поездки исправлены' WHERE id=10");
promiseFailure('changed cited profile source is never accepted as merely needs-review',ManagerPromiseTaskService::bind(1,1,$cmd));
$p=promiseTaskFixture();$initial=promiseContext();$old=promiseTaskCommand($initial);$first=ManagerPromiseTaskService::bind(1,1,$old);
foreach(['Промежуточное название','Декабрьский отдых'] as $label){$v=ManagerRequestProfileService::read(1,1);$r=ManagerRequestProfileService::save(1,1,requestProfileCommand($v,['label'=>$label]));promiseCheck('profile version advances for explicit edit '.$label,!empty($r['ok']),$r);}
promiseFailure('profile A-B-A does not revive an old version pin',ManagerPromiseTaskService::bind(1,1,array_replace($old,['operation_id'=>bin2hex(random_bytes(16))])));
$new=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand(promiseContext()));
promiseCheck('stable promise identity excludes profile generation',!empty($new['ok'])&&$new['reused']===true&&$new['promise_id']===$first['promise_id']&&promiseTaskCounts($p)===[1,2],$new);
$p=promiseTaskFixture();promiseTaskManualEdit($p,"UPDATE lead_tasks SET status='done',completed_at_utc='2030-01-01 12:00:00' WHERE id=101");$done=promiseContext();$r=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand($done));
promiseCheck('new binding to a completed task is refused without reopening',($r['error']??null)==='task_closed'&&promiseTaskCounts($p)===[0,0],$r);

foreach([null,['kind'=>'date','value'=>'2028-02-29','zone'=>'Europe/Moscow'],['kind'=>'instant','value'=>'2030-11-20T16:45:00+03:00']] as $deadline){
    $p=promiseTaskFixture();$before=promiseTaskCanonicalSnapshot($p);$r=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand(promiseContext(),['deadline'=>$deadline]));$stored=$p->query('SELECT deadline_json FROM manager_task_promises')->fetchColumn();
    promiseCheck('typed deadline preserves its precision without changing canonical task time',!empty($r['ok'])&&json_decode((string)$stored,true,8,JSON_THROW_ON_ERROR)===$deadline&&promiseTaskCanonicalSnapshot($p)===$before,$r);
}
foreach([
    ['kind'=>'date','value'=>'2026-02-29','zone'=>'Europe/Moscow'],['kind'=>'date','value'=>'2030-11-31','zone'=>'Europe/Moscow'],['kind'=>'date','value'=>'2030-11-20','zone'=>'UTC'],
    ['kind'=>'instant','value'=>'2030-11-20T16:45:00'],['kind'=>'instant','value'=>'tomorrow'],['kind'=>'instant','value'=>'2030-11-20T25:00:00+03:00'],
    ['kind'=>'date','value'=>'2030-11-20','zone'=>'Europe/Moscow','time'=>'00:00'],['kind'=>'unknown','value'=>'2030-11-20']
] as $deadline){$p=promiseTaskFixture();$r=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand(promiseContext(),['deadline'=>$deadline]));promiseFailure('invalid or ambiguous deadline fails closed '.json_encode($deadline),$r);promiseCheck('invalid deadline creates no records',promiseTaskCounts($p)===[0,0]);}
foreach([['task_id'=>'101'],['profile_version'=>'1'],['operation_id'=>'bad'],['kind'=>'no_contact'],['title'=>'Unsupported task writer'],['completed'=>true]] as $changes){$p=promiseTaskFixture();promiseFailure('bind accepts only its strict command contract '.json_encode($changes),ManagerPromiseTaskService::bind(1,1,promiseTaskCommand(promiseContext(),$changes)));}

$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());$p->exec("CREATE TRIGGER promise_fixture_fail_receipt BEFORE INSERT ON manager_task_promise_operations BEGIN SELECT RAISE(ABORT,'receipt storage failure'); END");
promiseFailure('receipt failure rolls back the binding in the same transaction',ManagerPromiseTaskService::bind(1,1,$cmd));promiseCheck('failed operation leaves neither half-record nor task side effect',promiseTaskCounts($p)===[0,0]);
$p->exec('DROP TRIGGER promise_fixture_fail_receipt');$recovered=ManagerPromiseTaskService::bind(1,1,$cmd);
promiseCheck('a non-committed operation can later succeed exactly once',!empty($recovered['ok'])&&$recovered['replayed']===false&&promiseTaskCounts($p)===[1,1],$recovered);
$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());ManagerPromiseTaskService::bind(1,1,$cmd);promiseTaskManualEdit($p,'DELETE FROM lead_tasks WHERE id=101');
promiseFailure('orphaned receipt does not recreate its deleted task',ManagerPromiseTaskService::operation(1,1,$cmd['operation_id']));
promiseFailure('orphaned replay fails closed',ManagerPromiseTaskService::bind(1,1,$cmd));
$orphanNew=ManagerPromiseTaskService::bind(1,1,array_replace($cmd,['operation_id'=>bin2hex(random_bytes(16))]));
promiseCheck('new operation on an orphaned binding is an integrity failure, not a fresh missing target',($orphanNew['error']??null)==='storage_unavailable',$orphanNew);
promiseCheck('orphan binding remains recorded without a new task',promiseTaskCounts($p)===[1,1]&&(int)$p->query('SELECT COUNT(*) FROM lead_tasks WHERE id=101')->fetchColumn()===0);
foreach(['manager_task_promises','manager_task_promise_operations'] as $table){$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());$p->exec('DROP TABLE '.$table);promiseFailure('missing '.$table.' has no unsupported fallback writer',ManagerPromiseTaskService::bind(1,1,$cmd));}
foreach(['deadline_json','scope_json','profile_sha256'] as $column){$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());ManagerPromiseTaskService::bind(1,1,$cmd);$p->exec("UPDATE manager_task_promises SET $column='{' ");promiseFailure('corrupt stored '.$column.' is not reused',ManagerPromiseTaskService::operation(1,1,$cmd['operation_id']));}
$p=promiseTaskFixture();$cmd=promiseTaskCommand(promiseContext());ManagerPromiseTaskService::bind(1,1,$cmd);
$p->exec("UPDATE manager_task_promise_operations SET request_sha256='{' ");
$corruptLookup=ManagerPromiseTaskService::operation(1,1,$cmd['operation_id']);$corruptReplay=ManagerPromiseTaskService::bind(1,1,$cmd);
promiseCheck('corrupt receipt digest fails closed for both lookup and replay',
    ($corruptLookup['error']??null)==='storage_unavailable'&&($corruptReplay['error']??null)==='storage_unavailable'&&promiseTaskCounts($p)===[1,1],[$corruptLookup,$corruptReplay]);
$p=promiseTaskFixture();$fields=[];$sourceId=30;
foreach(array_keys(ManagerRequestProfile::FIELDS) as $field){
    $quote='Проверенное условие '.$field.'.';requestProfileMessage($p,$sourceId,'manager',$quote);
    $fields[$field]=['value'=>$field==='party'?['adults'=>2,'children'=>0,'child_ages'=>[]]:$quote,
        'kind'=>'manager_interpretation','importance'=>'hard','note'=>'','source_id'=>$sourceId,'quote'=>$quote];++$sourceId;
}
$view=ManagerRequestProfileService::read(1,1);
$saved=ManagerRequestProfileService::save(1,1,requestProfileCommand($view,['fields'=>$fields]));
promiseCheck('bounded fixture confirms twelve independently cited profile fields',!empty($saved['ok'])&&$saved['saved_version']===2,$saved);
for($id=100;$id<=140;++$id)requestProfileMessage($p,$id,'customer','Дополнение '.$id.'.');
$profile=ManagerRequestProfileService::read(1,1);$context=promiseContext();$before=promiseTaskCanonicalSnapshot($p);
promiseCheck('binding reads twelve old citations plus distinct boundary and old promise outside the latest forty messages',
    !empty($profile['ok'])&&count($profile['profile']['fields'])===12&&!empty($context['ok'])&&$context['can_bind']===true
    &&$context['profile']['version']===2&&$context['source']['id']===12,$context);
$bound=ManagerPromiseTaskService::bind(1,1,promiseTaskCommand($context));
promiseCheck('bounded profile binds explicitly without changing the canonical profile or any task',
    !empty($bound['ok'])&&promiseTaskCounts($p)===[1,1]&&promiseTaskCanonicalSnapshot($p)===$before,$bound);

echo 'TOTAL '.$passed.' | PASS '.$passed.' | FAIL 0'."\n";
