<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-assistant-promise-facts-fixture.php';
$passed=0;$failed=0;$cases=0;
function apfCheck(string $name,bool $ok,mixed $detail=null): void
{
    global $passed;if(!$ok)throw new RuntimeException($name.($detail===null?'':' '.json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));++$passed;echo 'PASS '.$name."\n";
}
function apfCase(string $name,callable $work): void
{
    global $failed,$cases;$only=(string)getenv('ASSISTANT_PROMISE_CASE');if($only!==''&&!str_contains($name,$only))return;
    ++$cases;try{$work();}catch(Throwable $e){++$failed;echo 'FAIL '.$name.': '.$e->getMessage()."\n";}
}
function apfFact(array $item,int $id=101): array
{
    $task=assistantPromiseTaskRow($item,$id);
    if(!is_array($task['promise']??null))throw new RuntimeException('missing explicit promise fact for task '.$id);return $task['promise'];
}
function apfKeys(array $row,array $keys): bool{return count($row)===count($keys)&&array_diff(array_keys($row),$keys)===[];}
function apfStale(array $fact,string $reason): bool
{return in_array($reason,$fact['stale_reasons']??[],true)||in_array($reason,$fact['selection']['stale_reasons']??[],true);}
function apfRawIntent(PDO $pdo,string $id): array
{$q=$pdo->prepare('SELECT * FROM manager_promise_selection_intents WHERE intent_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:[];}
function apfExactView(array $intent,int $actor=7,int $taskId=101): array
{return ManagerPromiseSelectionService::viewForTask($actor,1,$taskId,$intent['promise_id'],$intent['intent_id'],$intent['publication_id']);}

try{
apfCase('detail-only read projection',static function():void{
    $pdo=assistantPromiseFixture();$before=assistantPromiseSnapshot($pdo);$pdo->reads=[];
    $list=ManagerAssistantService::listing(7);$factReads=array_filter($pdo->reads,static fn(array $r):bool=>str_contains($r['sql'],'manager_task_promises')||str_contains($r['sql'],'manager_promise_selection_intents'));
    apfCheck('list declares not-loaded coverage and performs no promise or intent hydration',!empty($list['ok'])&&count($list['items'])===2&&array_unique(array_column($list['items'],'promise_coverage'))===['not_loaded']&&$factReads===[],$list);
    $item=assistantPromiseDetail();$row=assistantPromiseTaskRow($item);$fact=apfFact($item);$task=promiseSelectionTask($pdo);
    apfCheck('detail checks only current visible tasks independently of absent AI',$item['promise_coverage']==='checked'&&$item['recommendation']===null&&$item['analysis_state']==='unavailable'&&array_column($item['tasks'],'id')===[101]&&$fact['availability']==='available');
    apfCheck('task title deadline status and pin remain canonical with separate promise deadline',$row['title']===$task['title']&&$row['due_at_utc']===$task['due_at_utc']&&$row['status']===$task['status']&&$row['is_pinned']===(bool)$task['is_pinned']&&$fact['binding']['deadline']===null);
    apfCheck('promise DTO contains only finite binding and selection facts',apfKeys($fact,['availability','reason','binding','stale_reasons','selection'])&&apfKeys($fact['binding'],['promise_id','kind','deadline','source_message_id','source_state','trip_id','profile_version'])&&$fact['binding']['source_message_id']===12&&$fact['binding']['source_state']==='current'&&!str_contains(json_encode($fact),'synthetic-promise-12'));
    $direct=ManagerPromiseSelectionService::forTasks(7,1,[101]);
    apfCheck('reader returns canonical internal task guards without changing the task',!empty($direct['ok'])&&$direct['viewer_id']===7&&$direct['conversation_id']===1&&isset($direct['by_task'][101])&&($direct['task_guards'][101]['business_revision']??null)===1&&($direct['task_guards'][101]['title']??null)===$task['title'],$direct);
    apfCheck('list detail and direct facts are business-read-only',assistantPromiseSnapshot($pdo)===$before&&$pdo->writeAttempts===0);
});

apfCase('task and actor visibility',static function():void{
    $pdo=assistantPromiseFixture();$foreignBinding=assistantPromiseBind($pdo,null,'send_selection',102);
    $foreignIntent=$pdo->mutate(static function(PDO $db)use($foreignBinding):array{
        $publication=promiseSelectionIds($db)['publication_id'];$preview=ManagerPromiseSelectionService::preview(7,1,$foreignBinding['promise_id'],$publication);
        if(empty($preview['ok']))throw new RuntimeException('foreign_intent_preview: '.json_encode($preview));
        $r=ManagerPromiseSelectionService::attach(7,1,['operation_id'=>bin2hex(random_bytes(16)),'promise_id'=>$foreignBinding['promise_id'],'publication_id'=>$publication,'preview_sha256'=>$preview['preview_sha256']]);
        if(empty($r['ok']))throw new RuntimeException('foreign_intent_attach: '.json_encode($r));return $r['intent'];
    });$before=assistantPromiseSnapshot($pdo);
    $own=assistantPromiseDetail();$team=assistantPromiseDetail(90);
    apfCheck('employee sees only own task facts while authorized admin sees foreign-assignee facts',array_column($own['tasks'],'id')===[101]&&in_array(102,array_column($team['tasks'],'id'),true)&&apfFact($team,102)['binding']['promise_id']===$foreignBinding['promise_id']&&$team['can_decide']===false);
    $foreignTask=ManagerPromiseSelectionService::forTasks(7,1,[102]);
    apfCheck('explicit task IDs cannot bypass own-task visibility',empty($foreignTask['ok'])||!isset($foreignTask['by_task'][102]['binding']['promise_id']),$foreignTask);
    $hiddenView=apfExactView($foreignIntent,7,102);$adminView=apfExactView($foreignIntent,90,102);
    apfCheck('an exact foreign task tuple still refuses the ordinary personal-plan reader',($hiddenView['error']??null)==='not_found'&&!isset($hiddenView['projection'])&&!isset($hiddenView['publication']),$hiddenView);
    apfCheck('authorized admin can read that same exact foreign task association',!empty($adminView['ok'])&&$adminView['task_id']===102&&$adminView['promise_id']===$foreignBinding['promise_id']&&$adminView['publication']['publication_id']===$foreignIntent['publication_id'],$adminView);
    foreach([8,91,92] as $actor){$r=ManagerAssistantService::detail($actor,1);apfCheck('actor '.$actor.' cannot disclose another managers private task promises',empty($r['ok'])&&!isset($r['item']),$r);}
    $r=ManagerAssistantService::listing(7,null,20,'team');apfCheck('employee cannot request the administrator team feed',($r['error']??null)==='forbidden');
    apfCheck('visibility checks do not write bindings intents tasks or read markers',assistantPromiseSnapshot($pdo)===$before&&$pdo->writeAttempts===0);
});

foreach([null,['kind'=>'date','value'=>'2027-06-05','zone'=>'Europe/Moscow'],['kind'=>'instant','value'=>'2027-06-04T21:30:00Z']] as $deadline)apfCase('deadline '.($deadline['kind']??'none'),static function()use($deadline):void{
    $pdo=assistantPromiseFixture();assistantPromiseBind($pdo,$deadline);$before=promiseSelectionTask($pdo,104);$item=assistantPromiseDetail();$row=assistantPromiseTaskRow($item,104);$fact=apfFact($item,104);
    apfCheck('typed '.($deadline['kind']??'absent').' promise deadline round-trips with original precision',$fact['binding']['deadline']===$deadline&&$fact['binding']['kind']==='send_calculation'&&$fact['selection']['availability']==='not_applicable');
    apfCheck('promised date never replaces independently scheduled task deadline',$row['due_at_utc']==='2027-07-15 09:30:00'&&$row['title']==='Ручное название расчёта'&&promiseSelectionTask($pdo,104)===$before&&$pdo->writeAttempts===0);
});

apfCase('confirmed delivery and reopened history',static function():void{
    $pdo=assistantPromiseFixture();$intent=assistantPromiseAttach($pdo);$state=['calls'=>[],'creates'=>0];$start=apfFact(assistantPromiseDetail());
    apfCheck('armed association is distinct from delivered work',$start['selection']['intent']['state']==='armed'&&$start['selection']['progress']['confirmed']===0&&$start['selection']['progress']['total']===2);
    assistantPromiseSend($pdo,0,$state);$before=assistantPromiseSnapshot($pdo);$partial=apfFact(assistantPromiseDetail());
    apfCheck('one exact publication card produces one confirmed item without outbound self-invalidation',$partial['selection']['progress']['availability']==='available'&&$partial['selection']['progress']['confirmed']===1&&$partial['selection']['progress']['total']===2&&!apfStale($partial,'context_changed'));
    apfCheck('partial projection itself changes no task or delivery evidence',assistantPromiseSnapshot($pdo)===$before&&$pdo->writeAttempts===0);
    assistantPromiseSend($pdo,1,$state);$done=assistantPromiseDetail();apfCheck('canonical done task disappears from open My Plan rows',assistantPromiseTaskRow($done)===null&&promiseSelectionTask($pdo)['status']==='done');
    $pdo->mutate(static fn()=>LeadTaskService::setCompleted(1,101,false));$reopened=promiseSelectionTask($pdo);$raw=apfRawIntent($pdo,$intent['intent_id']);$item=assistantPromiseDetail();$fact=apfFact($item);$view=apfExactView($intent);
    apfCheck('reopened task remains open while its completed intent stays historical',assistantPromiseTaskRow($item)['status']==='open'&&$fact['selection']['intent']['state']==='completed'&&$fact['selection']['intent']['intent_id']===$intent['intent_id']&&!empty($view['ok'])&&$view['intent_id']===$intent['intent_id']);
    apfCheck('history read does not reclose task or repeat native creation',promiseSelectionTask($pdo)===$reopened&&apfRawIntent($pdo,$intent['intent_id'])===$raw&&$state['creates']===2&&$pdo->writeAttempts===0);
});

foreach(['unknown','rejected','known'] as $mode)apfCase('unconfirmed '.$mode,static function()use($mode):void{
    $pdo=assistantPromiseFixture(null,$mode==='known'?'max':'telegram');assistantPromiseAttach($pdo);$state=['calls'=>[],'creates'=>0,'mode'=>$mode,'readback'=>false];assistantPromiseSend($pdo,0,$state);
    $before=assistantPromiseSnapshot($pdo);$fact=apfFact(assistantPromiseDetail());$progress=$fact['selection']['progress'];$reason=['unknown'=>'delivery_unknown','rejected'=>'delivery_rejected','known'=>'delivery_unverified'][$mode];
    apfCheck($mode.' receipt remains explicitly unconfirmed in task progress',$progress['reason']===$reason&&$progress['confirmed']===0&&$progress['total']===2&&$fact['selection']['intent']['state']==='armed',$fact);
    apfCheck($mode.' status read never retries or changes the task',assistantPromiseSnapshot($pdo)===$before&&$state['creates']===1&&$pdo->writeAttempts===0);
});

apfCase('shared wrong-publication evidence',static function():void{
    $pdo=assistantPromiseFixture();$intent=assistantPromiseAttach($pdo);$other=assistantPromisePublication($pdo,true);$state=['calls'=>[],'creates'=>0];assistantPromiseSend($pdo,0,$state,$other);assistantPromiseSend($pdo,1,$state,$other);
    $before=assistantPromiseSnapshot($pdo);$fact=apfFact(assistantPromiseDetail());$progress=$fact['selection']['progress'];
    apfCheck('shared delivered fences from another publication do not claim promised fulfillment',$other!==$intent['publication_id']&&$progress['availability']==='unavailable'&&$progress['reason']==='delivery_evidence_changed'&&$progress['confirmed']===null&&$progress['total']===2&&$fact['selection']['intent']['state']==='armed',$fact);
    apfCheck('wrong-publication read retains the open task and creates no new evidence',promiseSelectionTask($pdo)['status']==='open'&&assistantPromiseSnapshot($pdo)===$before&&$state['creates']===2&&$pdo->writeAttempts===0);
});

foreach(['source','profile','trip','task','inbound'] as $change)apfCase('stale '.$change,static function()use($change):void{
    $pdo=assistantPromiseFixture();$intent=assistantPromiseAttach($pdo);
    if($change==='source')$pdo->mutate(static fn(PDO $db)=>$db->exec("UPDATE messages SET text='Обещание менеджера отредактировано.' WHERE id=12"));
    elseif($change==='profile'||$change==='trip')assistantPromiseProfile($pdo,$change==='trip');
    elseif($change==='inbound')$pdo->mutate(static fn(PDO $db)=>promiseSelectionMessage($db,30,'customer','Даты поменялись, прежняя подборка пока не нужна.'));
    else $pdo->mutate(static function():void{LeadTaskService::update(1,101,'Другая ручная задача','2027-06-01T12:00:00Z');LeadTaskService::update(1,101,'Отправить согласованную подборку','2027-06-01T12:00:00Z');});
    $before=assistantPromiseSnapshot($pdo);$fact=apfFact(assistantPromiseDetail());$reason=['source'=>'source_changed','profile'=>'profile_changed','trip'=>'trip_changed','task'=>'task_changed','inbound'=>'context_changed'][$change];
    apfCheck($change.' change is an explicit stale reason rather than apparent completion',apfStale($fact,$reason)&&$fact['selection']['intent']['state']==='armed'&&promiseSelectionTask($pdo)['status']==='open',$fact);
    if($change==='source')apfCheck('edited source has an explicit changed source state',$fact['binding']['source_state']==='changed');
    apfCheck($change.' freshness projection never terminalizes or repairs the stored intent',assistantPromiseSnapshot($pdo)===$before&&apfRawIntent($pdo,$intent['intent_id'])['state']==='armed'&&$pdo->writeAttempts===0);
});

apfCase('reminder-only freshness',static function():void{
    $pdo=assistantPromiseFixture();assistantPromiseAttach($pdo);$pdo->mutate(static fn(PDO $db)=>$db->exec("UPDATE lead_tasks SET reminder_attempted_at_utc='2026-11-13 11:55:00',reminder_notified_at_utc='2026-11-13 11:55:01',updated_at=UTC_TIMESTAMP() WHERE id=101"));
    $fact=apfFact(assistantPromiseDetail());apfCheck('reminder bookkeeping is not a changed business task',!apfStale($fact,'task_changed')&&promiseSelectionTask($pdo)['business_revision']===1&&$fact['selection']['intent']['state']==='armed');
});

apfCase('exact historical tuple and recipient',static function():void{
    $pdo=assistantPromiseFixture();$first=assistantPromiseAttach($pdo);$pdo->mutate(static fn()=>ManagerPromiseSelectionService::cancel(7,1,['operation_id'=>bin2hex(random_bytes(16)),'intent_id'=>$first['intent_id']]));
    $other=assistantPromisePublication($pdo,true);$ids=promiseSelectionIds($pdo);$second=$pdo->mutate(static function()use($ids,$other):array{
        $preview=ManagerPromiseSelectionService::preview(7,1,$ids['promise_id'],$other);$r=ManagerPromiseSelectionService::attach(7,1,['operation_id'=>bin2hex(random_bytes(16)),'promise_id'=>$ids['promise_id'],'publication_id'=>$other,'preview_sha256'=>$preview['preview_sha256']]);
        if(empty($r['ok']))throw new RuntimeException('second_intent: '.json_encode($r));return $r['intent'];
    });
    $fact=apfFact(assistantPromiseDetail());$history=apfExactView($first);
    apfCheck('task summary prefers current armed intent but exact view preserves requested old history',$fact['selection']['intent']['intent_id']===$second['intent_id']&&!empty($history['ok'])&&$history['intent_id']===$first['intent_id']&&$history['projection']['selection']['intent']['state']==='cancelled'&&$history['publication']['publication_id']===$first['publication_id']);
    $tuples=[[102,$first['promise_id'],$first['intent_id'],$first['publication_id']],[101,str_repeat('0',64),$first['intent_id'],$first['publication_id']],[101,$first['promise_id'],str_repeat('0',32),$first['publication_id']],[101,$first['promise_id'],$first['intent_id'],$second['publication_id']]];
    foreach($tuples as $index=>$tuple){$r=ManagerPromiseSelectionService::viewForTask(7,1,...$tuple);apfCheck('valid mismatched tuple '.$index.' never substitutes another association',($r['error']??null)==='not_found'&&!isset($r['projection'])&&!isset($r['publication']),$r);}
    $pdo->mutate(static fn(PDO $db)=>$db->exec("UPDATE conversations SET external_chat_id='909' WHERE id=1; UPDATE customer_channels SET external_chat_id='909',external_user_id='909' WHERE id=1"));
    $before=assistantPromiseSnapshot($pdo);$changed=apfExactView($first);
    apfCheck('changed recipient keeps only history metadata and never reveals frozen card contents',!empty($changed['ok'])&&$changed['publication']===null&&in_array('scope_changed',$changed['projection']['selection']['stale_reasons'],true)&&($changed['projection']['selection']['progress']['confirmed']??null)===null,$changed);
    apfCheck('historical view and recipient refusal do not mutate business state',assistantPromiseSnapshot($pdo)===$before&&$pdo->writeAttempts===0);
});

apfCase('history after owner and status changes',static function():void{
    $pdo=assistantPromiseFixture();$intent=assistantPromiseAttach($pdo);$original=apfExactView($intent)['publication'];
    $moved=$pdo->mutate(static fn()=>ManagerConversationService::reassign(1,90,8));
    if(!$moved)throw new RuntimeException('canonical_reassign_failed');
    $afterMove=assistantPromiseSnapshot($pdo);$owner=apfExactView($intent,8);$admin=apfExactView($intent,90);
    $moveReadOnly=assistantPromiseSnapshot($pdo)===$afterMove;
    $closed=$pdo->mutate(static fn()=>ManagerConversationService::closeResult(1,8));
    if(empty($closed['ok']))throw new RuntimeException('canonical_close_failed: '.json_encode($closed));
    $afterClose=assistantPromiseSnapshot($pdo);$closedView=apfExactView($intent,90);
    apfCheck('same recipient history stays exact and read-only after canonical owner and status changes',
        !empty($owner['ok'])&&!empty($admin['ok'])&&!empty($closedView['ok'])&&$owner['publication']===$original&&$admin['publication']===$original&&$closedView['publication']===$original
        &&apfStale($owner['projection'],'scope_changed')&&apfStale($owner['projection'],'task_changed')&&apfStale($closedView['projection'],'scope_changed')
        &&$moveReadOnly&&assistantPromiseSnapshot($pdo)===$afterClose&&$pdo->writeAttempts===0,
        ['owner'=>$owner,'admin'=>$admin,'closed'=>$closedView]);
    $pdo->mutate(static fn(PDO $db)=>$db->exec("UPDATE conversations SET external_chat_id='909' WHERE id=1; UPDATE customer_channels SET external_chat_id='909',external_user_id='909' WHERE id=1"));
    $before=assistantPromiseSnapshot($pdo);$private=apfExactView($intent,90);
    apfCheck('recipient change still conceals frozen private composition after an authorized owner transfer',
        !empty($private['ok'])&&$private['publication']===null&&apfStale($private['projection'],'scope_changed')
        &&($private['projection']['selection']['progress']['confirmed']??null)===null&&!str_contains(json_encode($private,JSON_UNESCAPED_UNICODE),'Синтетический отель')
        &&assistantPromiseSnapshot($pdo)===$before&&$pdo->writeAttempts===0,$private);
});

apfCase('missing and corrupt optional facts',static function():void{
    $pdo=assistantPromiseFixture();$pdo->mutate(static fn(PDO $db)=>$db->exec('DROP TABLE manager_task_promises'));$item=assistantPromiseDetail();$fact=apfFact($item);
    apfCheck('missing optional promise storage retains the normal task with unavailable coverage',assistantPromiseTaskRow($item)['title']==='Отправить согласованную подборку'&&$fact['availability']==='unavailable'&&$fact['reason']==='storage_unavailable'&&$fact['binding']===null,$fact);
    $pdo=assistantPromiseFixture();$pdo->mutate(static fn(PDO $db)=>$db->exec("UPDATE manager_task_promises SET deadline_json='{\"kind\":\"date\",\"value\":\"2027-02-30\",\"zone\":\"Europe/Moscow\"}'"));$item=assistantPromiseDetail();$fact=apfFact($item);
    apfCheck('corrupt stored promise never becomes none or a fabricated deadline',$fact['availability']==='unavailable'&&$fact['reason']==='integrity_unavailable'&&$fact['binding']===null&&assistantPromiseTaskRow($item)['status']==='open',$fact);
    $pdo=assistantPromiseFixture();$intent=assistantPromiseAttach($pdo);$pdo->mutate(static fn(PDO $db)=>$db->exec("UPDATE manager_promise_selection_intents SET manifest_sha256='bad'"));$fact=apfFact(assistantPromiseDetail());
    apfCheck('corrupt intent does not erase the valid promise or claim delivery',$fact['availability']==='available'&&$fact['selection']['availability']==='unavailable'&&$fact['selection']['reason']==='integrity_unavailable'&&($fact['selection']['progress']['confirmed']??null)===null,$fact);
});

apfCase('task and ACL read races',static function():void{
    $pdo=assistantPromiseFixture();assistantPromiseAttach($pdo);$fired=false;
    $pdo->readHook=static function(AssistantPromiseFixturePdo $db,string $sql)use(&$fired):void{
        if(!str_contains($sql,'FROM lead_tasks t LEFT JOIN managers'))return;$db->readHook=null;$fired=true;
        $db->mutate(static fn()=>LeadTaskService::update(1,101,'Свежая ручная правка','2027-06-01T12:00:00Z'));
    };
    $r=ManagerAssistantService::detail(7,1);$row=isset($r['item'])?assistantPromiseTaskRow($r['item']):null;$fact=$row['promise']??null;
    apfCheck('task change during hydration cannot attach current proof to stale manual fields',$fired&&(empty($r['ok'])||($row!==null&&($row['title']==='Свежая ручная правка'||!is_array($fact)||$fact['availability']!=='available'||apfStale($fact,'task_changed')))),$r);
    $pdo=assistantPromiseFixture();assistantPromiseAttach($pdo);$fired=false;
    $pdo->readHook=static function(AssistantPromiseFixturePdo $db,string $sql)use(&$fired):void{
        if(!str_contains($sql,'FROM manager_promise_selection_intents'))return;$db->readHook=null;$fired=true;
        $db->mutate(static fn(PDO $p)=>$p->exec('DELETE FROM manager_projects WHERE manager_id=7'));
    };
    $r=ManagerAssistantService::detail(7,1);apfCheck('project revoke during fact hydration discards the private response',$fired&&empty($r['ok'])&&!isset($r['item']),$r);
});

apfCase('same-owner admin role race',static function():void{
    $pdo=assistantPromiseFixture();$pdo->mutate(static fn(PDO $db)=>$db->exec("UPDATE managers SET role='admin' WHERE id=7; UPDATE lead_tasks SET title='PRIVATE FOREIGN TASK BODY' WHERE id=102"));$fired=false;
    $pdo->readHook=static function(AssistantPromiseFixturePdo $db,string $sql)use(&$fired):void{
        if(!str_contains($sql,'FROM manager_task_promises'))return;$db->readHook=null;$fired=true;
        $db->mutate(static fn(PDO $p)=>$p->exec("UPDATE managers SET role='manager' WHERE id=7"));
    };
    $r=ManagerAssistantService::detail(7,1);$tasks=$r['item']['tasks']??[];
    apfCheck('role downgrade retains no foreign-assignee task body even when the conversation stays owned',$fired&&!in_array(102,array_column($tasks,'id'),true)&&!str_contains(json_encode($r),'PRIVATE FOREIGN TASK BODY'),$r);
});

apfCase('bounded task subset and no binding',static function():void{
    $pdo=assistantPromiseFixture();$pdo->mutate(static function(PDO $db):void{
        $q=$db->prepare("INSERT INTO lead_tasks(id,conversation_id,title,status,is_pinned,assigned_manager_id,created_by_manager_id,created_at,updated_at) VALUES(?,1,?,'open',0,7,7,'2026-11-13 10:00:00','2026-11-13 10:00:00')");
        for($i=200;$i<260;++$i)$q->execute([$i,'Обычная ручная задача '.$i]);
    });
    $item=assistantPromiseDetail();$none=0;foreach($item['tasks'] as $task)if(($task['promise']['availability']??null)==='none')++$none;
    apfCheck('facts stay within the existing fifty-task visible subset',count($item['tasks'])===50&&$item['tasks_truncated']===true&&$none===49&&apfFact($item)['availability']==='available');
    $r=ManagerPromiseSelectionService::forTasks(7,1,range(200,250));apfCheck('direct reader refuses an unbounded task-ID request',($r['error']??null)==='invalid_input',$r);
    apfCheck('bounded hydration never creates new tasks or promises',(int)$pdo->query('SELECT COUNT(*) FROM lead_tasks')->fetchColumn()===63&&(int)$pdo->query('SELECT COUNT(*) FROM manager_task_promises')->fetchColumn()===1&&$pdo->writeAttempts===0);
});
}finally{assistantPromiseCleanup();}
echo 'TOTAL '.($passed+$failed)." | PASS {$passed} | FAIL {$failed} | CASES {$cases}\n";
exit($failed?1:0);
