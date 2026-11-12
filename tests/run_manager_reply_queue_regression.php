<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/manager_reply_queue_fixture.php';
final class ReplyQueueTracePdo extends PDO
{
    public array $preparedSql=[];
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        $this->preparedSql[]=$query;return parent::prepare($query,$options);
    }
}
$pdo=new ReplyQueueTracePdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);replyQueueFixture($pdo);
$passed=0;$now=time();
function rqCheck(string $name,bool $ok): void {global$passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function rqView(int $actor=7,int $cid=101): array {$r=ManagerReplyQueueService::snapshot($actor,$cid);if(!$r['ok'])throw new RuntimeException('Unexpected snapshot refusal');return$r['reply_queue'];}
function rqResolve(string $op,?array $input=null,int $actor=7,int $cid=101): array {return ManagerReplyQueueService::mutate($actor,$cid,'resolve',$input??replyQueueInput(rqView($actor,$cid),$op));}
function rqProtected(PDO $pdo): array {$out=[];foreach(['managers','manager_projects','conversations','manager_conversation_reads'] as $t)$out[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1,2')->fetchAll();return$out;}

replyQueueMessage($pdo,1,101,'inbound','customer',$now-600);
$request=replyQueueRequest($pdo,101,$now-300);$protected=rqProtected($pdo);$view=rqView();
rqCheck('initial wait starts at actual handoff, not earlier bot collection',$view['needed']&&$view['waiting_since_unix']===$now-300&&$view['customer_message_id']===1&&$view['request_event_id']===$request);
rqCheck('handoff exposes a current need for a human response',$view['activity_state']==='awaiting_manager');
rqCheck('same conversation queue context is bound to the current actor',$view['context']!==rqView(9)['context']);
$roleInput=replyQueueInput($view,'role-context-00000001');$roleEvents=(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn();
$pdo->exec("UPDATE managers SET role='admin' WHERE id=7");$roleView=rqView();
rqCheck('actor role change refreshes context without advancing the queue version or frontiers',$roleView['context']!==$view['context']&&$roleView['version']===$view['version']&&$roleView['customer_message_id']===$view['customer_message_id']&&$roleView['request_event_id']===$view['request_event_id']);
rqCheck('old role context cannot authorize a fresh decision even when current access remains',ManagerReplyQueueService::mutate(7,101,'resolve',$roleInput)['error']==='context_changed'&&(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===$roleEvents&&(int)$pdo->query('SELECT COUNT(*) FROM manager_reply_queue_operations')->fetchColumn()===0);
$pdo->exec("UPDATE managers SET role='manager' WHERE id=7");
$firstInput=replyQueueInput($view,'resolve-synthetic-0001');$first=rqResolve('resolve-synthetic-0001',$firstInput);
rqCheck('explicit decision clears only reply work and records actor plus two typed frontiers',!$first['reply_queue']['needed']&&$first['reply_queue']['resolution']['actor_id']===7&&$first['reply_queue']['resolution']['through_message_id']===1&&$first['reply_queue']['resolution']['through_request_event_id']===$request);
rqCheck('covering manual decision is the current no-reply reason',$first['reply_queue']['activity_state']==='no_reply_required');
rqCheck('read lifecycle assignment and shift remain unchanged',rqProtected($pdo)===$protected);
$events=(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn();$repeat=rqResolve('resolve-synthetic-0001',$firstInput);
rqCheck('same immutable command replays one receipt without another event',$repeat['saved_version']===$first['saved_version']&&(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===$events);
$changed=$firstInput;$changed['through_message_id']=0;
rqCheck('same operation cannot change its payload',rqResolve('resolve-synthetic-0001',$changed)['error']==='operation_mismatch');
$undo=['operation_id'=>'undo-synthetic-0001','context'=>$first['reply_queue']['context'],'expected_version'=>$first['reply_queue']['version'],'resolution_actor_id'=>7,'resolution_operation_id'=>'resolve-synthetic-0001'];
$undone=ManagerReplyQueueService::mutate(7,101,'undo',$undo);
rqCheck('undo restores the same wait and its original clock',$undone['reply_queue']['needed']&&$undone['reply_queue']['waiting_since_unix']===$now-300&&$undone['reply_queue']['resolution']===null);
$second=rqResolve('resolve-synthetic-0002');replyQueueMessage($pdo,2,101,'inbound','customer',$now-120);$view=rqView();
rqCheck('new question automatically begins a new waiting cycle',$view['needed']&&$view['waiting_since_unix']===$now-120);
rqCheck('old manual decision cannot label a new question as no reply required',$view['activity_state']==='awaiting_manager'&&$view['resolution']!==null);
$staleInput=replyQueueInput($view,'resolve-synthetic-0003');replyQueueMessage($pdo,3,101,'inbound','customer',$now-60);
rqCheck('another unanswered message never resets the elapsed wait',rqView()['waiting_since_unix']===$now-120);
$partial=rqResolve('resolve-synthetic-0003',$staleInput);
rqCheck('late incoming before decision stays pending above captured cutoff',$partial['reply_queue']['needed']&&$partial['reply_queue']['waiting_since_unix']===$now-60);
$staleUndo=$undo;$staleUndo['operation_id']='undo-synthetic-stale';
rqCheck('stale undo cannot replace a newer decision',ManagerReplyQueueService::mutate(7,101,'undo',$staleUndo)['error']==='conflict');

$pdo->preparedSql=[];$boundary=ManagerReplyQueueService::captureSendBoundary(101);$sendSql=$pdo->preparedSql;
rqCheck('send capture reads only its two required frontiers with one reference per table',count($sendSql)===1&&preg_match_all('/\bFROM\s+messages\b/i',$sendSql[0])===1&&preg_match_all('/\bFROM\s+conversation_events\b/i',$sendSql[0])===1&&$boundary===['version'=>1,'customer_message_id'=>3,'request_event_id'=>$request]);
replyQueueMessage($pdo,4,101,'inbound','customer',$now-30);
replyQueueMessage($pdo,5,101,'outbound','manager',$now-20,[ManagerReplyQueueService::SEND_BOUNDARY=>$boundary]);
rqCheck('question arriving during successful send remains unanswered despite its lower INSERT ID',rqView()['needed']&&rqView()['waiting_since_unix']===$now-30);
$boundary=ManagerReplyQueueService::captureSendBoundary(101);replyQueueMessage($pdo,6,101,'outbound','manager',$now-15,[ManagerReplyQueueService::SEND_BOUNDARY=>$boundary],'Уточняю стоимость, пришлю расчёт позже');
rqCheck('successful reply resolves its captured cycle',!rqView()['needed']);
rqCheck('successful checking reply ends only technical waiting and an older manual mark stays historical',rqView()['activity_state']==='no_pending_reply'&&rqView()['resolution']!==null);
replyQueueMessage($pdo,7,101,'inbound','customer',$now-10);
rqCheck('subsequent incoming starts a fresh timer',rqView()['waiting_since_unix']===$now-10);
replyQueueMessage($pdo,8,101,'outbound','ai',$now-9);replyQueueMessage($pdo,9,101,'outbound','system',$now-8);replyQueueMessage($pdo,10,101,'inbound','customer',$now-7,['type'=>'callback']);
rqCheck('bot output system output callbacks and absent failed-send rows do not resolve or restart wait',rqView()['waiting_since_unix']===$now-10);
rqCheck('bot or system output never changes the need for a manager reply',rqView()['activity_state']==='awaiting_manager');
$resolved=rqResolve('resolve-synthetic-0004');
rqCheck('ordinary read operation is genuinely read-only',ManagerReplyQueueService::operation(7,101,'resolve-synthetic-0004')['found']&&rqProtected($pdo)===$protected);
rqCheck('unknown operation is not an acknowledgement',ManagerReplyQueueService::operation(7,101,'not-arrived-000000000')['found']===false);

$pdo->exec("UPDATE conversations SET status='waiting_manager',manager_id=NULL WHERE id=102");replyQueueRequest($pdo,102,$now-45);
$empty=rqView(9,102);rqCheck('admin can settle a first handoff with zero genuine customer rows',$empty['customer_message_id']===0&&$empty['needed']&&$empty['can_resolve']&&$empty['waiting_since_unix']===$now-45);
rqCheck('unassigned ordinary viewer cannot mutate queue',!rqView(7,102)['can_resolve']&&ManagerReplyQueueService::mutate(7,102,'resolve',replyQueueInput(rqView(7,102),'unassigned-000000001'))['error']==='forbidden');
$zero=rqResolve('zero-inbound-00000001',replyQueueInput($empty,'zero-inbound-00000001'),9,102);
rqCheck('request-event cutoff settles empty handoff without technical close',!$zero['reply_queue']['needed']&&$pdo->query('SELECT status FROM conversations WHERE id=102')->fetchColumn()==='waiting_manager');
replyQueueRequest($pdo,102,$now-4);rqCheck('a distinct later handoff cannot be covered by an old decision',rqView(9,102)['needed']);

$pdo->exec("UPDATE conversations SET status='waiting_manager',manager_id=NULL WHERE id=103");replyQueueMessage($pdo,30,103,'outbound','manager',$now-600,[],'Historical answer before the uncaptured handoff');
$legacyWaiting=rqView(9,103);rqCheck('missing historical handoff event never drops waiting even with an old manager reply',$legacyWaiting['needed']&&$legacyWaiting['customer_message_id']===0&&$legacyWaiting['request_event_id']===0&&$legacyWaiting['waiting_since_unix']===null);
$legacyZero=rqResolve('legacy-zero-00000001',replyQueueInput($legacyWaiting,'legacy-zero-00000001'),9,103);
rqCheck('explicit zero frontier resolves only validated legacy waiting',!$legacyZero['reply_queue']['needed']);
$undoZero=['operation_id'=>'undo-legacy-zero-001','context'=>$legacyZero['reply_queue']['context'],'expected_version'=>$legacyZero['reply_queue']['version'],'resolution_actor_id'=>9,'resolution_operation_id'=>'legacy-zero-00000001'];
rqCheck('undo zero frontier restores its honest unknown wait',ManagerReplyQueueService::mutate(9,103,'undo',$undoZero)['reply_queue']['needed']&&rqView(9,103)['wait_age_seconds']===null);
$lateZero=replyQueueInput(rqView(9,103),'legacy-zero-00000002');replyQueueMessage($pdo,31,103,'inbound','customer',$now-3);$lateResolved=rqResolve('legacy-zero-00000002',$lateZero,9,103);
rqCheck('new genuine incoming before legacy zero decision remains pending above that snapshot',$lateResolved['reply_queue']['needed']&&$lateResolved['reply_queue']['waiting_since_unix']===$now-3);

foreach([[8,101],[7,104],[7,105],[10,101]] as [$actor,$cid])rqCheck('fresh access rejects foreign project source assignment or inactive actor',ManagerReplyQueueService::mutate($actor,$cid,'resolve',$firstInput)['error']==='forbidden');
$adminInput=replyQueueInput(rqView(9,101),'admin-change-00000001');$admin=rqResolve('admin-change-00000001',$adminInput,9);$eventCount=(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn();
$pdo->exec('UPDATE conversations SET manager_id=8 WHERE id=101');
rqCheck('fresh ordinary admin access can reconcile saved receipt after assignment changes',ManagerReplyQueueService::operation(9,101,'admin-change-00000001')['found']);
$replayed=rqResolve('admin-change-00000001',$adminInput,9);
rqCheck('explicit same-operation retry reads its receipt before stale context checks',$replayed['saved_version']===$admin['saved_version']&&(int)$pdo->query('SELECT COUNT(*) FROM conversation_events')->fetchColumn()===$eventCount);
$pdo->exec('UPDATE conversations SET manager_id=7 WHERE id=101');
replyQueueMessage($pdo,11,101,'inbound','customer',$now-2,null);
$legacy=rqView();rqCheck('historical bare time is not silently declared UTC',$legacy['needed']&&$legacy['waiting_since_unix']===null&&$legacy['wait_age_seconds']===null&&!$legacy['wait_time_known']);
rqCheck('unknown legacy time does not hide a known need for a human reply',$legacy['activity_state']==='awaiting_manager');
$invalid=replyQueueInput($legacy,'foreign-cutoff-000001');$invalid['through_message_id']=999;
rqCheck('foreign missing or synthetic frontier cannot resolve a question',rqResolve('foreign-cutoff-000001',$invalid)['error']==='invalid_request');
rqCheck('pure decoration never falls back to legacy raw waiting age',ManagerReplyQueueService::decorate([['reply_wait_started_unix'=>null,'wait_age_seconds'=>999]],$now)[0]['reply_wait_age_seconds']===null);
replyQueueMessage($pdo,12,101,'outbound','manager',$now-1,[ManagerReplyQueueService::SEND_BOUNDARY=>['version'=>0,'customer_message_id'=>0,'request_event_id'=>0]]);
rqCheck('new unbound mirror never inherits legacy ID-based acknowledgement',rqView()['needed']);
rqCheck('snapshot exposes actual outgoing and event frontiers for late-response fencing',rqView()['manager_message_id']===12&&rqView()['queue_event_id']>=$request);

// Execute the real bounded Waiting/count queries, not a duplicate client sort.
$pdo->exec("UPDATE conversations SET status='closed';INSERT INTO conversations(id,customer_id,project_key,source_id,channel,status,manager_id) VALUES(201,1,'anytour',1,'telegram','manager',7),(202,1,'anytour',1,'telegram','manager',7),(203,1,'anytour',1,'telegram','manager',7)");
$r201=replyQueueRequest($pdo,201,$now-9000);$r202=replyQueueRequest($pdo,202,$now-1000);
replyQueueMessage($pdo,20,201,'outbound','manager',$now-600,[ManagerReplyQueueService::SEND_BOUNDARY=>['version'=>1,'customer_message_id'=>0,'request_event_id'=>$r201]]);
replyQueueMessage($pdo,21,201,'inbound','customer',$now-10);
replyQueueMessage($pdo,22,202,'outbound','manager',$now-500,[ManagerReplyQueueService::SEND_BOUNDARY=>['version'=>1,'customer_message_id'=>0,'request_event_id'=>$r202]]);
replyQueueMessage($pdo,23,202,'inbound','customer',$now-100);
replyQueueMessage($pdo,24,203,'inbound','customer',$now-200,null);
$ordered=ManagerConversationService::queueCountRows(7,'waiting','anytour');
rqCheck('Waiting ranks recurring current cycles before LIMIT rather than older handoff dates',array_map('intval',array_column($ordered,'id'))===[202,201,203]);
rqCheck('unknown clock has its own deterministic position instead of a fake age',$ordered[2]['reply_wait_started_unix']===null);
require_once dirname(__DIR__).'/services/ManagerQueueProjectionService.php';
$counts=ManagerQueueProjectionService::counts(7,'anytour');rqResolve('ordered-resolve-00001',null,7,201);$after=ManagerQueueProjectionService::counts(7,'anytour');
rqCheck('operational Waiting shrinks after resolution while all-assigned Mine and unread stay unchanged',$counts['waiting']['count']===3&&$after['waiting']['count']===2&&$counts['mine']['count']===3&&$after['mine']['count']===3&&$counts['notification_unread']===$after['notification_unread']&&$counts['mine']['unread']===$after['mine']['unread']);

// An inactive queue has several distinct causes. None proves that the customer owes the next step.
$pdo->exec("UPDATE conversations SET status='ai' WHERE id=201");$bot=rqView(7,201);
rqCheck('bot mode is explicit even when an earlier manual mark exists',$bot['activity_state']==='bot'&&!$bot['needed']&&$bot['resolution']!==null);
$pdo->exec("UPDATE conversations SET status='manager' WHERE id=201");
$failure=$pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json,created_at) VALUES (201,'manager_message_failed','system',?,?)");$failure->execute(['{"category":"suspended"}',gmdate('Y-m-d H:i:s',$now-1)]);
$suspended=rqView(7,201);
rqCheck('canonical delivery suspension is distinct from a completed or manually resolved wait',$suspended['activity_state']==='delivery_unavailable'&&!$suspended['needed']);
$pdo->exec("UPDATE conversations SET status='closed' WHERE id=201");$closed=rqView(7,201);
rqCheck('closed dialogue is explicit without declaring a sales outcome',$closed['activity_state']==='closed'&&!$closed['needed']&&!$closed['can_resolve']);
$pdo->exec("UPDATE conversations SET status='future_state' WHERE id=201");
rqCheck('unrecognized technical state is not presented as customer waiting',rqView(9,201)['activity_state']==='unknown');
$pdo->exec("UPDATE conversations SET status='manager' WHERE id=201");replyQueueMessage($pdo,25,201,'inbound','customer',$now);
$resumed=rqView(7,201);
rqCheck('new inbound clears canonical suspension and restores real reply waiting above the old decision',$resumed['activity_state']==='awaiting_manager'&&$resumed['needed']&&$resumed['resolution']!==null);
echo 'TOTAL '.$passed.' | PASS '.$passed." | FAIL 0\n";
