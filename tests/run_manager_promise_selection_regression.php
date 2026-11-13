<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/support/manager-promise-selection-fixture.php';
require_once dirname(__DIR__).'/services/ManagerPromiseSelectionService.php';

$passed=0;$failed=0;$cases=0;
function psCheck(string $name,bool $ok): void
{
    global $passed;if(!$ok)throw new RuntimeException($name);++$passed;echo "PASS {$name}\n";
}
function psCase(string $name,callable $run): void
{
    global $failed,$cases;$only=(string)getenv('PROMISE_SELECTION_CASE');if($only!==''&&!str_contains($name,$only))return;
    ++$cases;try{$run();}catch(Throwable $e){++$failed;echo "FAIL {$name}: ".$e->getMessage()."\n";}
}
function psIntent(PDO $pdo,string $id): array
{
    $q=$pdo->prepare('SELECT * FROM manager_promise_selection_intents WHERE intent_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
}
function psAttach(PDO $pdo): array
{
    $r=promiseSelectionAttach($pdo);if(empty($r['ok']))throw new RuntimeException('attach fixture: '.json_encode($r));return $r['intent'];
}
function psCommand(PDO $pdo): array
{
    $ids=promiseSelectionIds($pdo);$r=ManagerPromiseSelectionService::preview(7,1,$ids['promise_id'],$ids['publication_id']);
    if(empty($r['ok'])||empty($r['can_attach']))throw new RuntimeException('preview fixture: '.json_encode($r));
    return ['operation_id'=>bin2hex(random_bytes(16)),...$ids,'preview_sha256'=>$r['preview_sha256']];
}
function psManual(array $task): array
{
    return array_intersect_key($task,array_flip(['title','due_at_utc','is_pinned','assigned_manager_id','created_by_manager_id','created_at','reminder_attempted_at_utc','reminder_notified_at_utc']));
}
function psMirrorCounts(PDO $pdo): array
{
    return [(int)$pdo->query("SELECT COUNT(*) FROM messages WHERE json_extract(metadata_json,'$.tour_selection') IS NOT NULL")->fetchColumn(),
        (int)$pdo->query("SELECT COUNT(*) FROM conversation_events WHERE event_type='tour_offer_delivered'")->fetchColumn()];
}
function psPublication(PDO $pdo,bool $shared): string
{
    $current=ManagerTourSelectionService::get(7,1)['selection'];$draft=$current['draft'];$draft['title']='Другая явно выбранная подборка';
    if(!$shared)foreach($draft['offers'] as $i=>&$offer){$offer['hotel']='Другой отель '.($i+1);$offer['amount']=(string)(270000+1000*$i);}unset($offer);
    $saved=deliverySave($draft,$current['version']);if(empty($saved['ok']))throw new RuntimeException('new publication draft: '.json_encode($saved));
    $prepared=ManagerTourSelectionDeliveryService::prepare(7,1,$saved['saved_version']);
    if(empty($prepared['ok']))throw new RuntimeException('new publication prepare: '.json_encode($prepared));return $prepared['publication']['id'];
}
function psNewProfile(PDO $pdo,bool $newTrip): array
{
    $read=ManagerRequestProfileService::read(7,1);if(empty($read['ok']))throw new RuntimeException('profile refresh unavailable');
    // Keep the input frontier fixed so this case specifically exercises the
    // explicit new-trip/profile pins, independently of the inbound guard.
    return ManagerRequestProfileService::save(7,1,['version'=>$read['profile']['version'],'context_sha256'=>$read['context_sha256'],
        'operation_id'=>bin2hex(random_bytes(16)),'boundary_message_id'=>$read['profile']['boundary_message_id'],
        'new_trip'=>$newTrip,'label'=>$newTrip?'Сентябрьская поездка':'Уточнённые условия этой поездки','fields'=>[]]);
}

psCase('canonical task revisions',static function():void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$title=$before['title'];
    $same=LeadTaskService::update(1,101,'  '.$title.'  ','2027-06-01T15:00:00+03:00');
    psCheck('identical normalized task save does not change revision or timestamps',!empty($same['ok'])&&promiseSelectionTask($pdo)===$before);
    LeadTaskService::update(1,101,'selection','2027-06-01T12:00:00Z');$lower=promiseSelectionTask($pdo);
    LeadTaskService::update(1,101,'Selection','2027-06-01T12:00:00Z');$upper=promiseSelectionTask($pdo);
    psCheck('case-only title edit advances one business revision and retains reminder receipts',(int)$lower['business_revision']===2&&(int)$upper['business_revision']===3&&$upper['reminder_attempted_at_utc']===$before['reminder_attempted_at_utc']);
    LeadTaskService::update(1,101,'Selection',null);$clear=promiseSelectionTask($pdo);LeadTaskService::update(1,101,'Selection',null);
    psCheck('clearing deadline resets reminders once and repeated null deadline is a no-op',(int)$clear['business_revision']===4&&$clear['due_at_utc']===null&&$clear['reminder_attempted_at_utc']===null&&$clear['reminder_notified_at_utc']===null&&promiseSelectionTask($pdo)===$clear);
    LeadTaskService::setPinned(1,101,true);$pinned=promiseSelectionTask($pdo);LeadTaskService::setPinned(1,101,true);
    psCheck('pin change advances once and repeated pin keeps exact task row',(int)$pinned['business_revision']===5&&promiseSelectionTask($pdo)===$pinned);
    LeadTaskService::setCompleted(1,101,true);$done=promiseSelectionTask($pdo);LeadTaskService::setCompleted(1,101,true);
    psCheck('manual completion increments once and repeated completion preserves its timestamp',(int)$done['business_revision']===6&&$done['status']==='done'&&promiseSelectionTask($pdo)===$done);
    LeadTaskService::setCompleted(1,101,false);$open=promiseSelectionTask($pdo);LeadTaskService::setCompleted(1,101,false);
    psCheck('manual reopen increments once in the same stored second',(int)$open['business_revision']===7&&$open['status']==='open'&&$open['completed_at_utc']===null&&$done['updated_at']===$open['updated_at']&&promiseSelectionTask($pdo)===$open);
    psCheck('completed task is not edited or pinned',empty(LeadTaskService::update(1,103,'changed',null)['ok'])&&!LeadTaskService::setPinned(1,103,false)&&(int)promiseSelectionTask($pdo,103)['business_revision']===1);
});

psCase('task completion CAS',static function():void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$threw=false;
    try{LeadTaskService::completeForPromise($pdo,1,101,1,7);}catch(LogicException $e){$threw=$e->getMessage()==='promise_task_transaction_required';}
    psCheck('promise task completion requires the canonical owning transaction',$threw&&promiseSelectionTask($pdo)===$before);
    $pdo->beginTransaction();$stale=LeadTaskService::completeForPromise($pdo,1,101,2,7);$missing=LeadTaskService::completeForPromise($pdo,2,101,1,7);$done=LeadTaskService::completeForPromise($pdo,1,103,1,7);
    psCheck('task CAS distinguishes changed missing and already-done without writes',$stale===['outcome'=>'changed','business_revision'=>1]&&$missing===['outcome'=>'missing','business_revision'=>null]&&$done===['outcome'=>'already_done','business_revision'=>1]&&promiseSelectionTask($pdo)===$before);
    $applied=LeadTaskService::completeForPromise($pdo,1,101,1,7);$after=promiseSelectionTask($pdo);
    psCheck('exact task CAS completes once inside caller transaction preserving manual business fields',$applied===['outcome'=>'applied','business_revision'=>2]&&$pdo->inTransaction()&&$after['status']==='done'&&$after['completed_at_utc']!==null&&psManual($after)===psManual($before));
    $pdo->rollBack();psCheck('caller rollback restores task completion and revision atomically',promiseSelectionTask($pdo)===$before);
});

psCase('canonical reassignment revisions',static function():void{
    $path=sys_get_temp_dir().'/promise-selection-reassign-'.bin2hex(random_bytes(8)).'.sqlite';$pdo=null;
    try{
        promiseSelectionFixture($path);
        $pdo=new PromiseSelectionSqliteLockPdo('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');promiseSelectionSqlFunctions($pdo);$pdo->sqliteCreateFunction('NOW',static fn():string=>'2026-11-13 12:00:00');
        (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);
        $already=promiseSelectionTask($pdo,102);$done=promiseSelectionTask($pdo,103);
        $ok=ManagerConversationService::reassign(1,90,8);$changed=promiseSelectionTask($pdo);
        psCheck('real reassignment advances only the task whose assignee changed',$ok&&(int)$changed['assigned_manager_id']===8&&(int)$changed['business_revision']===2&&promiseSelectionTask($pdo,102)===$already&&promiseSelectionTask($pdo,103)===$done);
        psCheck('same-manager reassignment leaves all task business revisions unchanged',ManagerConversationService::reassign(1,90,8)&&promiseSelectionTask($pdo)===$changed&&promiseSelectionTask($pdo,102)===$already&&promiseSelectionTask($pdo,103)===$done);
    }finally{
        if($pdo&&$pdo->inTransaction())$pdo->rollBack();(new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,null);$pdo=null;if(is_file($path))unlink($path);
    }
});

psCase('explicit intent and operation receipts',static function():void{
    $pdo=promiseSelectionFixture();$task=promiseSelectionTask($pdo);$counts=psMirrorCounts($pdo);$catalog=ManagerPromiseSelectionService::catalog(7,1);$command=psCommand($pdo);
    psCheck('catalog and preview expose current task revision without creating tasks or delivery',!empty($catalog['ok'])&&count($catalog['promises'])===1&&$catalog['promises'][0]['task']['business_revision']===1&&promiseSelectionTask($pdo)===$task&&psMirrorCounts($pdo)===$counts&&(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_intents')->fetchColumn()===0);
    $unknown=ManagerPromiseSelectionService::operation(7,1,str_repeat('f',32));psCheck('unknown operation is read-only and explicitly absent',!empty($unknown['ok'])&&$unknown['found']===false&&$unknown['intent']===null);
    $first=ManagerPromiseSelectionService::attach(7,1,$command);$replay=ManagerPromiseSelectionService::attach(7,1,$command);$op=ManagerPromiseSelectionService::operation(7,1,$command['operation_id']);
    psCheck('explicit association arms one immutable intent and exact retry replays its receipt',!empty($first['ok'])&&$first['intent']['state']==='armed'&&$first['replayed']===false&&!empty($replay['replayed'])&&$replay['intent']['intent_id']===$first['intent']['intent_id']&&(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_intents')->fetchColumn()===1);
    psCheck('operation lookup reconciles the saved association without task or transcript writes',!empty($op['found'])&&$op['action']==='attach'&&$op['intent']===$replay['intent']&&strlen($op['request_sha256'])===64&&promiseSelectionTask($pdo)===$task&&psMirrorCounts($pdo)===$counts);
    $changed=$command;$changed['preview_sha256']=str_repeat('0',64);$mismatch=ManagerPromiseSelectionService::attach(7,1,$changed);
    psCheck('same operation with changed payload is refused',($mismatch['error']??null)==='operation_mismatch'&&(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_operations')->fetchColumn()===1);
    $fresh=$command;$fresh['operation_id']=bin2hex(random_bytes(16));$again=ManagerPromiseSelectionService::attach(7,1,$fresh);
    psCheck('a second operation cannot silently create another active intent',empty($again['ok'])&&(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_intents')->fetchColumn()===1);
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');$denied=ManagerPromiseSelectionService::operation(7,1,$command['operation_id']);
    psCheck('receipt reconciliation rechecks the current actor access',($denied['error']??null)==='forbidden');
});

psCase('stale preview and unsupported task',static function():void{
    $pdo=promiseSelectionFixture();$command=psCommand($pdo);LeadTaskService::setPinned(1,101,true);$reply=ManagerPromiseSelectionService::attach(7,1,$command);
    psCheck('manual task edit invalidates the exact preview before association',($reply['error']??null)==='preview_changed'&&(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_selection_intents')->fetchColumn()===0);
    LeadTaskService::setCompleted(1,101,true);$ids=promiseSelectionIds($pdo);$preview=ManagerPromiseSelectionService::preview(7,1,$ids['promise_id'],$ids['publication_id']);
    psCheck('completed task remains readable but cannot be armed',!empty($preview['ok'])&&$preview['can_attach']===false&&$preview['reason']==='task_closed');
});

psCase('full two-card delivery',static function():void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$intent=psAttach($pdo);$state=['calls'=>[],'creates'=>0];
    $first=promiseSelectionSend($pdo,0,$state);$partial=ManagerPromiseSelectionService::status(7,1,$intent['intent_id']);
    psCheck('one confirmed card is partial and leaves linked task open',!empty($first['ok'])&&$state['creates']===1&&$partial['intent']['state']==='armed'&&$partial['intent']['delivered_count']===1&&promiseSelectionTask($pdo)===$before);
    $last=promiseSelectionSend($pdo,1,$state);$raw=psIntent($pdo,$intent['intent_id']);$task=promiseSelectionTask($pdo);$proof=json_decode($raw['evidence_json'],true,32,JSON_THROW_ON_ERROR);
    psCheck('all exact native cards commit task completion and one complete proof',!empty($last['ok'])&&$raw['state']==='completed'&&$task['status']==='done'&&(int)$task['business_revision']===2&&$proof['task_outcome']==='applied'&&count($proof['cards'])===2&&psMirrorCounts($pdo)===[2,2]);
    psCheck('automatic completion preserves manual task data and technical conversation state',psManual($task)===psManual($before)&&$pdo->query('SELECT status FROM conversations WHERE id=1')->fetchColumn()==='manager'&&$raw['active_promise_id']===null&&$raw['active_publication_id']===null);
    promiseSelectionComplete($pdo,$state);$status=ManagerPromiseSelectionService::status(7,1,$intent['intent_id']);
    psCheck('repeated delivered cards and status reads do not create or close twice',$state['creates']===2&&promiseSelectionTask($pdo)===$task&&psIntent($pdo,$intent['intent_id'])===$raw&&$status['intent']['delivered_count']===2&&psMirrorCounts($pdo)===[2,2]);
    LeadTaskService::setCompleted(1,101,false);$reopened=promiseSelectionTask($pdo);promiseSelectionComplete($pdo,$state);
    psCheck('manual reopen after completed proof is not undone by old delivery replay',$reopened['status']==='open'&&(int)$reopened['business_revision']===3&&promiseSelectionTask($pdo)===$reopened&&psIntent($pdo,$intent['intent_id'])===$raw&&$state['creates']===2);
});

foreach(['unknown','rejected'] as $mode)psCase('unconfirmed delivery '.$mode,static function()use($mode):void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$intent=psAttach($pdo);$state=['calls'=>[],'creates'=>0,'mode'=>$mode];$r=promiseSelectionSend($pdo,0,$state);
    psCheck($mode.' native response cannot close task or create fulfillment proof',empty($r['ok'])&&($r['error']??'')==='delivery_'.$mode&&promiseSelectionTask($pdo)===$before&&psIntent($pdo,$intent['intent_id'])['evidence_json']===null&&psMirrorCounts($pdo)===[0,0]);
    if($mode==='unknown'){promiseSelectionSend($pdo,0,$state);psCheck('unknown native creation is never automatically repeated',$state['creates']===1);}
});

psCase('known receipt verification recovery',static function():void{
    $pdo=promiseSelectionFixture(null,'max');$before=promiseSelectionTask($pdo);$intent=psAttach($pdo);$state=['calls'=>[],'creates'=>0,'readback'=>false];$r=promiseSelectionSend($pdo,0,$state);
    psCheck('known native ID without verified content remains incomplete',($r['error']??null)==='delivery_unverified'&&$state['creates']===1&&promiseSelectionTask($pdo)===$before&&psMirrorCounts($pdo)===[0,0]);
    $state['readback']=true;$first=promiseSelectionSend($pdo,0,$state);
    psCheck('known card verification recovers without another native creation',!empty($first['ok'])&&$state['creates']===1&&promiseSelectionTask($pdo)===$before&&psMirrorCounts($pdo)===[1,1]);
    $last=promiseSelectionSend($pdo,1,$state);
    psCheck('second verified MAX card completes only the exact linked task',!empty($last['ok'])&&$state['creates']===2&&promiseSelectionTask($pdo)['status']==='done'&&psIntent($pdo,$intent['intent_id'])['state']==='completed');
});

psCase('wrong publication',static function():void{
    $pdo=promiseSelectionFixture();$task=promiseSelectionTask($pdo);$intent=psAttach($pdo);$other=psPublication($pdo,false);$state=['calls'=>[],'creates'=>0];$r=promiseSelectionComplete($pdo,$state,$other);
    psCheck('complete delivery of a different publication does not satisfy the armed promise',!empty($r['ok'])&&$other!==$intent['publication_id']&&$state['creates']===2&&promiseSelectionTask($pdo)===$task&&psIntent($pdo,$intent['intent_id'])['state']==='armed');
});

psCase('shared earlier delivery',static function():void{
    $pdo=promiseSelectionFixture();$state=['calls'=>[],'creates'=>0];$sent=promiseSelectionComplete($pdo,$state);$task=promiseSelectionTask($pdo);$other=psPublication($pdo,true);$ids=promiseSelectionIds($pdo);$r=ManagerPromiseSelectionService::preview(7,1,$ids['promise_id'],$other);
    psCheck('already delivered shared offers cannot be reclassified as fresh promised work',!empty($sent['ok'])&&$other!==$ids['publication_id']&&!empty($r['ok'])&&$r['can_attach']===false&&$r['reason']==='delivery_started'&&promiseSelectionTask($pdo)===$task&&$state['creates']===2);
});

psCase('new customer message between cards',static function():void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$intent=psAttach($pdo);$state=['calls'=>[],'creates'=>0];promiseSelectionSend($pdo,0,$state);
    promiseSelectionMessage($pdo,30,'customer','Эти даты больше не подходят, подборку пока не отправляйте.');
    $r=promiseSelectionSend($pdo,1,$state);$raw=psIntent($pdo,$intent['intent_id']);
    psCheck('new inbound between verified cards preserves delivery but blocks stale completion',!empty($r['ok'])&&$state['creates']===2&&psMirrorCounts($pdo)===[2,2]&&promiseSelectionTask($pdo)===$before&&$raw['state']==='blocked'&&$raw['reason']==='context_changed'&&$raw['completed_at']===null&&$raw['evidence_json']===null);
});

psCase('armed cancellation across trip',static function():void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$intent=psAttach($pdo);$old=psIntent($pdo,$intent['intent_id']);
    $saved=psNewProfile($pdo,true);if(empty($saved['ok']))throw new RuntimeException('new trip save: '.json_encode($saved));
    $catalog=ManagerPromiseSelectionService::catalog(7,1);
    psCheck('new-trip catalog keeps the previously armed association visible for cancellation',!empty($catalog['ok'])&&$catalog['profile']['trip_id']!==$old['trip_id']&&count($catalog['intents'])===1&&$catalog['intents'][0]['intent_id']===$intent['intent_id']&&$catalog['intents'][0]['state']==='armed');
    $cancel=ManagerPromiseSelectionService::cancel(7,1,['operation_id'=>bin2hex(random_bytes(16)),'intent_id'=>$intent['intent_id']]);$raw=psIntent($pdo,$intent['intent_id']);
    psCheck('explicit older-trip cancellation releases active keys without task or delivery writes',!empty($cancel['ok'])&&$raw['state']==='cancelled'&&$raw['active_promise_id']===null&&$raw['active_publication_id']===null&&$raw['evidence_json']===null&&promiseSelectionTask($pdo)===$before&&psMirrorCounts($pdo)===[0,0]);
});

foreach(['source','profile','trip'] as $change)psCase('freshness '.$change,static function()use($change):void{
    $pdo=promiseSelectionFixture();$task=promiseSelectionTask($pdo);$intent=psAttach($pdo);
    if($change==='source')$pdo->exec("UPDATE messages SET text='Срок и состав обещанной подборки изменились.' WHERE id=12");
    else{$saved=psNewProfile($pdo,$change==='trip');if(empty($saved['ok']))throw new RuntimeException('profile mutation: '.json_encode($saved));}
    $state=['calls'=>[],'creates'=>0];$r=promiseSelectionComplete($pdo,$state);$raw=psIntent($pdo,$intent['intent_id']);
    psCheck($change.' change allows native delivery but blocks stale task completion',!empty($r['ok'])&&$state['creates']===2&&$raw['state']==='blocked'&&promiseSelectionTask($pdo)===$task&&psMirrorCounts($pdo)===[2,2]);
    psCheck($change.' refusal records its reason without a false fulfillment timestamp',in_array($raw['reason'],['source_changed','source_unavailable','profile_changed'],true)&&$raw['completed_at']===null&&$raw['evidence_json']===null);
});

foreach(['title_aba','reopen_aba','manual_done','reminder_only'] as $change)psCase('manual precedence '.$change,static function()use($change):void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$intent=psAttach($pdo);
    if($change==='title_aba'){LeadTaskService::update(1,101,'Временно другое действие','2027-06-01T12:00:00Z');LeadTaskService::update(1,101,$before['title'],'2027-06-01T12:00:00Z');}
    elseif($change==='reopen_aba'){LeadTaskService::setCompleted(1,101,true);LeadTaskService::setCompleted(1,101,false);}
    elseif($change==='manual_done')LeadTaskService::setCompleted(1,101,true);
    else $pdo->exec("UPDATE lead_tasks SET reminder_attempted_at_utc='2026-11-13 11:30:00',reminder_notified_at_utc='2026-11-13 11:30:01',updated_at=UTC_TIMESTAMP() WHERE id=101");
    $manual=promiseSelectionTask($pdo);$state=['calls'=>[],'creates'=>0];$r=promiseSelectionComplete($pdo,$state);$after=promiseSelectionTask($pdo);$raw=psIntent($pdo,$intent['intent_id']);
    if($change==='reminder_only')psCheck('reminder receipts do not consume business revision or block real fulfillment',!empty($r['ok'])&&(int)$manual['business_revision']===1&&(int)$after['business_revision']===2&&$raw['state']==='completed'&&psManual($after)===psManual($manual));
    else{
        psCheck($change.' preserves the exact manual task state after full native delivery',!empty($r['ok'])&&$after===$manual&&$raw['state']==='blocked'&&$raw['reason']===($change==='manual_done'?'task_done':'task_changed'));
        if($change!=='manual_done')psCheck($change.' detects same-second ABA by monotonic revision',$manual['updated_at']==='2026-11-13 12:00:00'&&(int)$manual['business_revision']===3&&$manual['status']==='open');
    }
});

psCase('explicit cancellation',static function():void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$intent=psAttach($pdo);$input=['operation_id'=>bin2hex(random_bytes(16)),'intent_id'=>$intent['intent_id']];
    $cancel=ManagerPromiseSelectionService::cancel(7,1,$input);$repeat=ManagerPromiseSelectionService::cancel(7,1,$input);$raw=psIntent($pdo,$intent['intent_id']);
    psCheck('explicit cancellation is idempotent and keeps task unchanged',!empty($cancel['ok'])&&!empty($repeat['replayed'])&&$cancel['intent']['state']==='cancelled'&&$raw['cancelled_at']!==null&&promiseSelectionTask($pdo)===$before);
    $state=['calls'=>[],'creates'=>0];$r=promiseSelectionComplete($pdo,$state);
    psCheck('later full delivery never reactivates a cancelled association',!empty($r['ok'])&&$state['creates']===2&&promiseSelectionTask($pdo)===$before&&psIntent($pdo,$intent['intent_id'])===$raw);
});

foreach(['task','proof'] as $target)psCase('atomic rollback '.$target,static function()use($target):void{
    $pdo=promiseSelectionFixture();$before=promiseSelectionTask($pdo);$intent=psAttach($pdo);$state=['calls'=>[],'creates'=>0];promiseSelectionSend($pdo,0,$state);
    $trigger=$target==='task'?"CREATE TRIGGER fail_completion BEFORE UPDATE OF status ON lead_tasks WHEN NEW.id=101 AND NEW.status='done' BEGIN SELECT RAISE(ABORT,'synthetic task failure'); END":"CREATE TRIGGER fail_completion BEFORE UPDATE OF state ON manager_promise_selection_intents WHEN NEW.state='completed' BEGIN SELECT RAISE(ABORT,'synthetic proof failure'); END";
    $pdo->exec($trigger);$failed=false;try{promiseSelectionSend($pdo,1,$state);}catch(PDOException $e){$failed=str_contains($e->getMessage(),'synthetic '.$target.' failure');}
    $raw=psIntent($pdo,$intent['intent_id']);$known=(int)$pdo->query("SELECT COUNT(*) FROM manager_tour_selection_deliveries WHERE state='known'")->fetchColumn();
    psCheck($target.' DB failure rolls back task proof transcript and final delivered mirror',$failed&&!$pdo->inTransaction()&&$state['creates']===2&&$known===1&&promiseSelectionTask($pdo)===$before&&$raw['state']==='armed'&&$raw['evidence_json']===null&&psMirrorCounts($pdo)===[1,1]);
    $pdo->exec('DROP TRIGGER fail_completion');$retry=promiseSelectionSend($pdo,1,$state);$after=psIntent($pdo,$intent['intent_id']);
    psCheck($target.' failure recovers from known receipt with zero further native creation',!empty($retry['ok'])&&$state['creates']===2&&promiseSelectionTask($pdo)['status']==='done'&&(int)promiseSelectionTask($pdo)['business_revision']===2&&$after['state']==='completed'&&psMirrorCounts($pdo)===[2,2]);
});

echo 'TOTAL '.($passed+$failed)." | PASS {$passed} | FAIL {$failed} | CASES {$cases}\n";
exit($failed?1:0);
