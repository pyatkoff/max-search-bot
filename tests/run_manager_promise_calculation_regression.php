<?php
declare(strict_types=1);
require_once __DIR__.'/support/manager-promise-calculation-fixture.php';
$passed=0;$failed=0;$cases=0;$calls=0;
/** Force the initial observation to miss a separately committed unique winner. */
final class CalculationUniqueWinnerPdo extends PDO
{
    public bool $hideFirstAttempt=false;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if($this->hideFirstAttempt&&str_starts_with($query,'SELECT conversation_id,context_hash,request_hash,operation_kind,state,result_json FROM manager_send_attempts')){
            $this->hideFirstAttempt=false;$query.=' AND 1=0';
        }
        return parent::prepare($query,$options);
    }
}
function pcCheck(string $name,bool $ok): void {global $passed;if(!$ok)throw new RuntimeException($name);++$passed;echo "PASS {$name}\n";}
function pcCase(string $name,callable $run): void {global $cases,$failed;++$cases;try{$run();}catch(Throwable $e){++$failed;echo "FAIL {$name}: ".$e->getMessage()."\n";}}
function pcId(int $id): string {return str_pad(dechex($id),32,'0',STR_PAD_LEFT);}
function pcTask(PDO $pdo): array {return promiseSelectionTask($pdo,101);}
function pcIntent(PDO $pdo,array $composition): array
{$r=promiseCalculationArm($pdo,$composition);if(empty($r['ok']))throw new RuntimeException('arm: '.json_encode($r));return $r['intent'];}
function pcSnapshot(PDO $pdo): string
{
    $all=[];foreach(['manager_promise_calculation_intents','manager_promise_calculation_operations','manager_promise_calculation_deliveries','manager_send_attempts','messages','lead_tasks'] as $t)$all[$t]=$pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);
    return json_encode($all,JSON_THROW_ON_ERROR);
}
function pcText(PDO $pdo,array $intent,int $id,string $text='Расчёт 240000 ₽',?callable $during=null,string $mode='native',?string $mid=null): array
{
    return ManagerSendAttemptService::execute(7,1,pcId($id),$text,null,static function(string $context,?callable $accepted)use($pdo,$text,$during,$mode,$mid,$id):array{
        global $calls;++$calls;if($pdo->inTransaction())throw new RuntimeException('DB transaction crossed transport');
        $proof=promiseCalculationNative($pdo,$text,$mid??(string)(7000+$id));
        if($during)$during($pdo);
        if($mode==='unknown')return ['ok'=>false];
        if($mode==='rejected')return ['ok'=>false,'failure'=>['category'=>'blocked','http_code'=>403]];
        if($mode==='native')$accepted($proof);
        return ['ok'=>true];
    },['intent_id'=>$intent['intent_id'],'part_index'=>0]);
}
function pcMedia(PDO $pdo,array $intent,int $index,int $id,string $path,string $caption='',string $mode='native',?callable $during=null,?string $mid=null): array
{
    $name=basename($path);$mime='application/octet-stream';
    return ManagerSendAttemptService::executeMedia(7,1,pcId($id),$path,$name,$mime,$caption,static function(string $context,?callable $accepted)use($pdo,$name,$mime,$caption,$mode,$during,$mid,$id):array{
        global $calls;++$calls;if($pdo->inTransaction())throw new RuntimeException('DB transaction crossed transport');
        $proof=promiseCalculationNative($pdo,$caption,$mid??(string)(7000+$id),['type'=>'file','name'=>$name,'mime_type'=>$mime]);
        if($during)$during($pdo);
        if($mode==='unknown')return ['ok'=>false];
        if($mode==='rejected')return ['ok'=>false,'failure'=>['category'=>'blocked','http_code'=>403]];
        if($mode==='native')$accepted($proof);return ['ok'=>true];
    },['intent_id'=>$intent['intent_id'],'part_index'=>$index]);
}
function pcFiles(callable $run): void
{
    $a=tempnam(sys_get_temp_dir(),'calculation-a-');$b=tempnam(sys_get_temp_dir(),'calculation-b-');
    try{file_put_contents($a,"synthetic calculation attachment A\0");file_put_contents($b,"synthetic calculation attachment B\0");
        $files=array_map(static fn(string $p):array=>['sha256'=>hash_file('sha256',$p),'size'=>filesize($p),'name'=>basename($p)],[$a,$b]);$run($a,$b,$files);
    }finally{if(is_file($a))unlink($a);if(is_file($b))unlink($b);}
}
function pcPlanFacts(int $actor=7): array
{
    $result=ManagerPromiseSelectionService::forTasks($actor,1,[101]);
    if(empty($result['ok'])||!isset($result['by_task'][101]['calculation']))throw new RuntimeException('calculation_plan_projection_missing');
    return $result['by_task'][101];
}

pcCase('read-only preview and explicit arm',static function():void{
    $pdo=promiseCalculationFixture();$ids=promiseCalculationIds($pdo);$composition=['text'=>'Расчёт 240000 ₽','files'=>[]];$before=pcSnapshot($pdo);
    $catalog=ManagerPromiseCalculationService::catalog(7,1);$p=ManagerPromiseCalculationService::preview(7,1,101,$ids['promise_id'],$composition);
    pcCheck('catalog and checked complete text preview never write',!empty($catalog['entries'][0]['can_arm'])&&!empty($p['can_arm'])&&$p['parts']===[['index'=>0,'kind'=>'text','text_sha256'=>hash('sha256',$composition['text'])]]&&$before===pcSnapshot($pdo));
    $interop=ManagerPromiseCalculationService::preview(7,1,101,$ids['promise_id'],['text'=>"Расчёт\u{2028}179000 ₽",'files'=>[]]);
    pcCheck('manifest Unicode line separator matches the hardcoded native JavaScript vector',
        ($interop['manifest_sha256']??null)==='7cfbcf5fecb42742192c99f4ab4caa66b896312ff62334562f6795bcc2195992'&&pcSnapshot($pdo)===$before);
    $input=$ids+['operation_id'=>pcId(1),'composition'=>$composition,'preview_sha256'=>$p['preview_sha256']];
    $arm=ManagerPromiseCalculationService::arm(7,1,$input);$snapshot=pcSnapshot($pdo);
    pcCheck('explicit arm records intent without task edit or send',!empty($arm['ok'])&&$arm['intent']['state']==='armed'&&$arm['intent']['confirmed']===0&&pcTask($pdo)['status']==='open'&&(int)$pdo->query('SELECT COUNT(*) FROM manager_send_attempts')->fetchColumn()===0);
    $replay=ManagerPromiseCalculationService::arm(7,1,$input);$receipt=ManagerPromiseCalculationService::operation(7,1,pcId(1));
    pcCheck('arm lost acknowledgement replays exact immutable operation',!empty($replay['replayed'])&&$receipt['found']&&$receipt['intent']['intent_id']===$arm['intent']['intent_id']&&pcSnapshot($pdo)===$snapshot);
    $reordered=array_reverse($input,true);
    pcCheck('JSON object member order cannot change arm operation identity',!empty(ManagerPromiseCalculationService::arm(7,1,$reordered)['replayed'])&&pcSnapshot($pdo)===$snapshot);
    $input['composition']['text']='Different';
    pcCheck('same arm operation cannot bind another composition',(ManagerPromiseCalculationService::arm(7,1,$input)['error']??null)==='operation_mismatch'&&pcSnapshot($pdo)===$snapshot);
    pcCheck('missing arm receipt is explicitly absent and read only',ManagerPromiseCalculationService::operation(7,1,pcId(900))['found']===false&&pcSnapshot($pdo)===$snapshot);
});

pcCase('reservation uniqueness fallback cannot borrow unrelated success',static function():void{
    global $calls;$path=tempnam(sys_get_temp_dir(),'calculation-unique-');unlink($path);
    try{
        $pdo=promiseCalculationFixture($path);$intent=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);$operation=pcId(990);
        // This is the durable winner from another request, with identical text
        // but NO calculation relation. The next observation misses it once.
        ManagerSendAttemptService::execute(7,1,$operation,'Расчёт 240000 ₽',null,static fn():array=>['ok'=>true]);
        $pdo=new CalculationUniqueWinnerPdo('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys=ON');promiseSelectionSqlFunctions($pdo);$pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
        (new ReflectionProperty(ConversationDb::class,'pdo'))->setValue(null,$pdo);$before=pcSnapshot($pdo);$n=$calls;
        $pdo->hideFirstAttempt=true;
        $result=pcText($pdo,$intent,990);
        pcCheck('real unique-key exception refuses generic confirmed receipt from another relation',
            ($result['error']??null)==='send_attempt_mismatch'&&!isset($result['attempt'])&&$calls===$n&&pcSnapshot($pdo)===$before);
    }finally{if(is_file($path))unlink($path);}
});

pcCase('catalog does not bury current open calculation under completed history',static function():void{
    $pdo=promiseCalculationFixture();$original=$pdo->query('SELECT * FROM manager_task_promises WHERE task_id=101')->fetch(PDO::FETCH_ASSOC);
    // Fifty older completed tasks used to consume the entire bounded catalog.
    for($id=1;$id<=50;$id++){
        $pdo->prepare("INSERT INTO lead_tasks(id,conversation_id,title,status,created_by_manager_id,created_at,updated_at) VALUES(?,1,'Исторический расчёт','done',7,'2026-01-01','2026-01-01')")->execute([$id]);
        $copy=$original;$copy['task_id']=$id;$copy['source_message_id']=1000+$id;
        $copy['promise_id']=hash('sha256',json_encode(['anytour',1,$copy['trip_id'],'send_calculation',$copy['source_message_id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $pdo->prepare('INSERT INTO manager_task_promises('.implode(',',array_keys($copy)).') VALUES('.implode(',',array_fill(0,count($copy),'?')).')')->execute(array_values($copy));
    }
    $catalog=ManagerPromiseCalculationService::catalog(7,1);
    pcCheck('bounded catalog prioritizes existing active/open work ahead of old completed rows',
        !empty($catalog['ok'])&&count($catalog['entries'])===50&&($catalog['entries'][0]['task_id']??null)===101&&!empty($catalog['entries'][0]['can_arm']));
});

pcCase('full text and reopened generations',static function():void{
    global $calls;$pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);$task=pcTask($pdo);$start=$calls;
    $sent=pcText($pdo,$i,2);$done=pcTask($pdo);$status=ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck('one exact native receipt commits canonical message and task once',($sent['attempt']['state']??null)==='confirmed'&&$status['intent']['state']==='completed'&&$status['intent']['task_status']==='done'&&$status['intent']['confirmed']===1&&$done['status']==='done'&&(int)$done['business_revision']===2&&$calls===$start+1);
    pcCheck('automatic completion preserves manual task fields',array_intersect_key($task,array_flip(['title','due_at_utc','is_pinned','assigned_manager_id']))===array_intersect_key($done,array_flip(['title','due_at_utc','is_pinned','assigned_manager_id'])));
    $snapshot=pcSnapshot($pdo);pcText($pdo,$i,2);ManagerPromiseCalculationService::status(7,1,$i['intent_id']);ManagerSendAttemptService::receipt(7,1,pcId(2));
    pcCheck('send replay and all result reads are write free and never dispatch',pcSnapshot($pdo)===$snapshot&&$calls===$start+1);
    LeadTaskService::setCompleted(1,101,false);$reopened=pcTask($pdo);$history=ManagerPromiseCalculationService::recover(7,1,$i['intent_id']);
    pcCheck('old completion recovery never re-closes a manually reopened task',pcTask($pdo)===$reopened&&$reopened['status']==='open'&&(int)$reopened['business_revision']===3&&$history['intent']['state']==='completed'&&$history['intent']['task_status']==='open');
    $new=pcIntent($pdo,['text'=>'Новый расчёт 245000 ₽','files'=>[]]);pcText($pdo,$new,3,'Новый расчёт 245000 ₽');
    pcCheck('new explicit reviewed calculation may complete the new task generation',$new['intent_id']!==$i['intent_id']&&pcTask($pdo)['status']==='done'&&(int)pcTask($pdo)['business_revision']===4);
});

pcCase('ok without native receipt is never fulfillment',static function():void{
    $pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);$before=pcTask($pdo);
    $r=pcText($pdo,$i,4,mode:'legacy_ok');$s=ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck('legacy success including suppressed duplicate leaves task open',($r['attempt']['state']??null)==='confirmed'&&pcTask($pdo)===$before&&$s['intent']['confirmed']===0&&$s['intent']['parts'][0]['state']==='unknown'&&!$s['intent']['can_recover']);
});

pcCase('full ordered media composition',static function():void{pcFiles(static function(string $a,string $b,array $files):void{
    global $calls;$pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт и условия','files'=>$files]);$before=pcTask($pdo);
    $first=pcMedia($pdo,$i,0,10,$a,'Расчёт и условия');$s=ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck('one confirmed attachment is partial and does not complete',($first['attempt']['state']??null)==='confirmed'&&$s['intent']['confirmed']===1&&$s['intent']['parts'][0]['state']==='confirmed'&&$s['intent']['parts'][1]['state']==='pending'&&pcTask($pdo)===$before);
    $n=$calls;$wrong=pcMedia($pdo,$i,1,11,$b,'Расчёт и условия');
    pcCheck('caption cannot be repeated on later manifest parts',($wrong['error']??null)==='composition_changed'&&$calls===$n);
    $last=pcMedia($pdo,$i,1,12,$b);$s=ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck('complete exact media manifest closes once without repeating caption',($last['attempt']['state']??null)==='confirmed'&&$s['intent']['state']==='completed'&&$s['intent']['confirmed']===2&&pcTask($pdo)['status']==='done');
});});

pcCase('unknown and rejected media',static function():void{pcFiles(static function(string $a,string $b,array $files):void{
    global $calls;$pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'','files'=>$files]);pcMedia($pdo,$i,0,20,$a);
    $r=pcMedia($pdo,$i,1,21,$b,mode:'unknown');$n=$calls;pcMedia($pdo,$i,1,21,$b);
    $new=pcMedia($pdo,$i,1,22,$b);$s=ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck('unknown file never repeats under old or new operation',($r['attempt']['state']??null)==='unknown'&&($new['error']??null)==='part_unresolved'&&$calls===$n&&$s['intent']['confirmed']===1&&pcTask($pdo)['status']==='open');
    $pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'','files'=>$files]);pcMedia($pdo,$i,0,23,$a);pcMedia($pdo,$i,1,24,$b,mode:'rejected');
    $n=$calls;pcMedia($pdo,$i,1,25,$b);
    pcCheck('definite rejected part permits one explicit new attempt only for itself',$calls===$n+1&&pcTask($pdo)['status']==='done');
});});

pcCase('actual file bytes and exact operation identity',static function():void{pcFiles(static function(string $a,string $b,array $files):void{
    global $calls;$pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'','files'=>$files]);$n=$calls;
    file_put_contents($a,"different bytes same name");
    $r=pcMedia($pdo,$i,0,30,$a);
    pcCheck('changed uploaded bytes fail before reservation or adapter',($r['error']??null)==='composition_changed'&&$calls===$n&&(int)$pdo->query('SELECT COUNT(*) FROM manager_send_attempts')->fetchColumn()===0);
});});

pcCase('same MID cannot fulfill two parts',static function():void{pcFiles(static function(string $a,string $b,array $files):void{
    $pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'','files'=>$files]);pcMedia($pdo,$i,0,40,$a,mid:'8800');$r=pcMedia($pdo,$i,1,41,$b,mid:'8800');
    $s=ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck('native message identity is unique across the complete result',($r['attempt']['state']??null)==='unknown'&&$s['intent']['confirmed']===1&&pcTask($pdo)['status']==='open'&&(int)$pdo->query('SELECT COUNT(*) FROM manager_promise_calculation_deliveries WHERE native_json IS NOT NULL')->fetchColumn()===1);
});});

foreach(['task_edit','task_aba','done_reopen','inbound','request','profile','source','recipient','account','assignment'] as $change)pcCase('changed '.$change,static function()use($change):void{
    $pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);$manual=null;
    $r=pcText($pdo,$i,50,during:static function(PDO $pdo)use($change,&$manual):void{
        $old=pcTask($pdo);
        if($change==='task_edit'||$change==='task_aba'){LeadTaskService::update(1,101,'Новый заголовок',$old['due_at_utc']);if($change==='task_aba')LeadTaskService::update(1,101,$old['title'],$old['due_at_utc']);}
        elseif($change==='done_reopen'){LeadTaskService::setCompleted(1,101,true);LeadTaskService::setCompleted(1,101,false);}
        elseif($change==='inbound')promiseSelectionMessage($pdo,20,'customer','Другие условия');
        elseif($change==='request')$pdo->exec("INSERT INTO conversation_events(conversation_id,event_type,actor_type,payload_json) VALUES(1,'waiting_manager','customer','{}')");
        elseif($change==='profile'){$v=ManagerRequestProfileService::read(7,1);$saved=ManagerRequestProfileService::save(7,1,['version'=>$v['profile']['version'],'context_sha256'=>$v['context_sha256'],'operation_id'=>pcId(510),'boundary_message_id'=>12,'new_trip'=>true,'label'=>'Другая поездка','fields'=>[]]);if(empty($saved['ok']))throw new RuntimeException('profile change fixture');}
        elseif($change==='source')$pdo->exec("UPDATE messages SET text='Отменённое обещание' WHERE id=12");
        elseif($change==='recipient')$pdo->exec("UPDATE customer_channels SET external_user_id='999' WHERE id=1");
        elseif($change==='account')$pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');
        elseif($change==='assignment')$pdo->exec('UPDATE conversations SET manager_id=8 WHERE id=1');
        $manual=pcTask($pdo);
    });
    $stored=$pdo->query('SELECT state,reason FROM manager_promise_calculation_intents')->fetch(PDO::FETCH_ASSOC);
    pcCheck($change.' preserves confirmed send but blocks stale completion',($r['attempt']['state']??null)==='confirmed'&&$stored['state']==='blocked'&&$stored['reason']!==null&&pcTask($pdo)===$manual);
});

pcCase('modified first canonical receipt cannot finish later',static function():void{pcFiles(static function(string $a,string $b,array $files):void{
    $pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'','files'=>$files]);pcMedia($pdo,$i,0,60,$a);
    $pdo->exec("UPDATE messages SET text='Edited after first delivery' WHERE id=(SELECT message_id FROM manager_promise_calculation_deliveries WHERE part_index=0)");
    pcMedia($pdo,$i,1,61,$b);$s=ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck('complete count never substitutes exact canonical message proof',$s['intent']['state']==='blocked'&&$s['intent']['reason']==='delivery_evidence_changed'&&pcTask($pdo)['status']==='open');
});});

foreach(['mirror','message_proof','attempt','task','completion'] as $stage)pcCase('atomic failure '.$stage,static function()use($stage):void{
    global $calls;$pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);$task=pcTask($pdo);$count=(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();$n=$calls;
    $trigger=match($stage){
        'mirror'=>"CREATE TRIGGER calculation_fail BEFORE INSERT ON messages WHEN NEW.direction='outbound' BEGIN SELECT RAISE(FAIL,'synthetic mirror failure'); END",
        'message_proof'=>"CREATE TRIGGER calculation_fail BEFORE UPDATE ON manager_promise_calculation_deliveries WHEN NEW.message_id IS NOT NULL BEGIN SELECT RAISE(FAIL,'synthetic proof failure'); END",
        'attempt'=>"CREATE TRIGGER calculation_fail BEFORE UPDATE ON manager_send_attempts WHEN NEW.state='confirmed' BEGIN SELECT RAISE(FAIL,'synthetic receipt failure'); END",
        'task'=>"CREATE TRIGGER calculation_fail BEFORE UPDATE ON lead_tasks WHEN NEW.status='done' BEGIN SELECT RAISE(FAIL,'synthetic task failure'); END",
        'completion'=>"CREATE TRIGGER calculation_fail BEFORE UPDATE ON manager_promise_calculation_intents WHEN NEW.state='completed' BEGIN SELECT RAISE(FAIL,'synthetic completion failure'); END",
    };$pdo->exec($trigger);$r=pcText($pdo,$i,70);$s=ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck($stage.' rollback preserves durable native proof and no partial task or mirror',($r['attempt']['state']??null)==='unknown'&&$s['intent']['can_recover']&&$s['intent']['confirmed']===0&&pcTask($pdo)===$task&&(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$count);
    $snapshot=pcSnapshot($pdo);ManagerSendAttemptService::receipt(7,1,pcId(70));ManagerPromiseCalculationService::status(7,1,$i['intent_id']);
    pcCheck($stage.' result reads do not finalize or replay delivery',pcSnapshot($pdo)===$snapshot&&$calls===$n+1);
    $pdo->exec('DROP TRIGGER calculation_fail');$recovered=ManagerPromiseCalculationService::recover(7,1,$i['intent_id']);
    pcCheck($stage.' explicit recovery reconciles native message receipt and task with zero sends',!empty($recovered['ok'])&&$recovered['intent']['state']==='completed'&&ManagerSendAttemptService::receipt(7,1,pcId(70))['attempt']['state']==='confirmed'&&pcTask($pdo)['status']==='done'&&$calls===$n+1&&(int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$count+1);
});

pcCase('cancellation and lost identity',static function():void{
    global $calls;$pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);$n=$calls;
    $cancel=ManagerPromiseCalculationService::cancel(7,1,['operation_id'=>pcId(80),'intent_id'=>$i['intent_id']]);$r=pcText($pdo,$i,81);
    pcCheck('cancelled association cannot authorize a delayed first send',!empty($cancel['ok'])&&$cancel['intent']['state']==='cancelled'&&($r['error']??null)==='intent_conflict'&&$calls===$n&&pcTask($pdo)['status']==='open');
    $fresh=pcIntent($pdo,['text'=>'Другой расчёт','files'=>[]]);
    pcCheck('fresh reviewed composition after unstarted cancel has a new intent',$fresh['intent_id']!==$i['intent_id']);
    $pdo->exec('UPDATE managers SET is_active=0 WHERE id=7');
    pcCheck('current account ACL gates status and operations',(ManagerPromiseCalculationService::status(7,1,$fresh['intent_id'])['error']??null)==='forbidden');
});

pcCase('plan no intent and finite disclosure',static function():void{
    $pdo=promiseCalculationFixture();$before=pcSnapshot($pdo);$none=pcPlanFacts();
    pcCheck('a bound calculation without a reviewed composition is explicitly none and read only',
        $none['calculation']===['availability'=>'none','reason'=>null,'intent'=>null,'stale_reasons'=>[],'progress'=>null]
        &&$none['selection']['availability']==='not_applicable'&&pcSnapshot($pdo)===$before);
    $intent=pcIntent($pdo,['text'=>'PRIVATE EXACT CALCULATION BODY','files'=>[]]);$before=pcSnapshot($pdo);$p=pcPlanFacts()['calculation'];
    pcCheck('armed calculation summary discloses only finite identity state and exact part counts',
        $p['availability']==='available'&&$p['intent']['intent_id']===$intent['intent_id']&&$p['intent']['state']==='armed'
        &&array_keys($p['intent'])===['intent_id','state','reason','created_at','completed_at','cancelled_at']
        &&$p['progress']===['availability'=>'available','reason'=>null,'confirmed'=>0,'total'=>1]
        &&!str_contains(json_encode($p),'PRIVATE EXACT')&&!str_contains(json_encode($p),'operation_id')&&pcSnapshot($pdo)===$before);
});

pcCase('plan partial and unknown native parts',static function():void{
    pcFiles(static function($a,$b,$files):void{
        global $calls;$pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'PRIVATE TWO FILES','files'=>$files]);
        pcMedia($pdo,$i,0,201,$a,'PRIVATE TWO FILES');pcMedia($pdo,$i,1,202,$b,'','unknown');
        $before=pcSnapshot($pdo);$n=$calls;$p=pcPlanFacts()['calculation'];
        pcCheck('plan reports one verified part and an unknown final result without exposing file names',
            $p['availability']==='available'&&$p['intent']['state']==='armed'
            &&$p['progress']===['availability'=>'available','reason'=>'delivery_unknown','confirmed'=>1,'total'=>2]
            &&!str_contains(json_encode($p),basename($a))&&!str_contains(json_encode($p),basename($b)));
        pcCheck('partial plan reads never recover retry send or complete an open task',pcSnapshot($pdo)===$before&&$calls===$n&&pcTask($pdo)['status']==='open');
    });
});

pcCase('plan native proof awaiting local verification and reopened history',static function():void{
    global $calls;$pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);
    $pdo->exec("CREATE TRIGGER calculation_plan_fail BEFORE INSERT ON messages WHEN NEW.direction='outbound' BEGIN SELECT RAISE(FAIL,'synthetic mirror failure'); END");
    pcText($pdo,$i,203);$before=pcSnapshot($pdo);$n=$calls;$p=pcPlanFacts()['calculation'];
    pcCheck('durable native proof without a canonical message remains unverified and does not repair on read',
        $p['progress']===['availability'=>'available','reason'=>'delivery_unverified','confirmed'=>0,'total'=>1]
        &&pcSnapshot($pdo)===$before&&$calls===$n&&pcTask($pdo)['status']==='open');
    $pdo->exec('DROP TRIGGER calculation_plan_fail');ManagerPromiseCalculationService::recover(7,1,$i['intent_id']);
    $done=pcPlanFacts()['calculation'];
    pcCheck('completed plan proof uses the canonical completion revision',
        $done['intent']['state']==='completed'&&$done['progress']['confirmed']===1&&$done['progress']['total']===1
        &&!in_array('task_changed',$done['stale_reasons'],true)&&pcTask($pdo)['business_revision']===2);
    LeadTaskService::setCompleted(1,101,false);$before=pcSnapshot($pdo);$history=pcPlanFacts()['calculation'];
    pcCheck('historical completion cannot satisfy or reclose the manually reopened task generation',
        $history['intent']['state']==='completed'&&$history['progress']['confirmed']===1&&in_array('task_changed',$history['stale_reasons'],true)
        &&pcTask($pdo)['status']==='open'&&pcSnapshot($pdo)===$before&&$calls===$n);
    $new=pcIntent($pdo,['text'=>'New explicit composition','files'=>[]]);$current=pcPlanFacts()['calculation'];
    pcCheck('a new explicitly armed generation takes priority over the old completed history',
        $current['intent']['intent_id']===$new['intent_id']&&$current['intent']['state']==='armed'&&$current['progress']['confirmed']===0);
});

pcCase('plan generic success cannot prove a calculation',static function():void{
    $pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);pcText($pdo,$i,204,'Расчёт 240000 ₽',null,'legacy');
    $before=pcSnapshot($pdo);$p=pcPlanFacts()['calculation'];
    pcCheck('generic successful receipt without native proof remains unknown in plan',
        $p['progress']===['availability'=>'available','reason'=>'delivery_unknown','confirmed'=>0,'total'=>1]
        &&pcTask($pdo)['status']==='open'&&pcSnapshot($pdo)===$before);
});

foreach(['native_hash','native_content','content','attempt','message','completion'] as $corruption)pcCase('plan corrupt '.$corruption,static function()use($corruption):void{
    $pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);pcText($pdo,$i,205);
    if($corruption==='native_hash')$pdo->exec("UPDATE manager_promise_calculation_deliveries SET native_sha256='".str_repeat('0',64)."'");
    elseif($corruption==='native_content'){
        $raw=json_decode((string)$pdo->query('SELECT native_json FROM manager_promise_calculation_deliveries')->fetchColumn(),true);$raw['text']='Different native content';$raw=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare('UPDATE manager_promise_calculation_deliveries SET native_json=?,native_sha256=?')->execute([$raw,hash('sha256',$raw)]);
    }elseif($corruption==='content')$pdo->exec("UPDATE manager_promise_calculation_deliveries SET content_json='{}'");
    elseif($corruption==='attempt')$pdo->exec("UPDATE manager_send_attempts SET request_hash='".str_repeat('0',64)."'");
    elseif($corruption==='message')$pdo->exec("UPDATE messages SET text='Changed stored message' WHERE id=(SELECT message_id FROM manager_promise_calculation_deliveries)");
    else $pdo->exec("UPDATE manager_promise_calculation_intents SET evidence_json='{}'");
    $before=pcSnapshot($pdo);$p=pcPlanFacts()['calculation'];
    pcCheck($corruption.' cannot turn frozen completed state into verified progress',
        ($corruption==='completion'?$p['availability']==='unavailable'&&$p['intent']===null&&$p['progress']===null
            :$p['progress']['availability']==='unavailable'&&$p['progress']['reason']==='delivery_evidence_changed'&&$p['progress']['confirmed']===null)
        &&pcSnapshot($pdo)===$before);
});

foreach(['source','profile','trip','task','inbound'] as $change)pcCase('plan stale '.$change,static function()use($change):void{
    $pdo=promiseCalculationFixture();pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);
    if($change==='source')$pdo->exec("UPDATE messages SET text='The promised result changed' WHERE id=12");
    elseif($change==='profile'||$change==='trip'){
        $read=ManagerRequestProfileService::read(7,1);$input=['version'=>$read['profile']['version'],'context_sha256'=>$read['context_sha256'],
            'operation_id'=>bin2hex(random_bytes(16)),'boundary_message_id'=>$read['profile']['boundary_message_id'],
            'new_trip'=>$change==='trip','label'=>'Changed current profile','fields'=>[]];
        $saved=ManagerRequestProfileService::save(7,1,$input);if(empty($saved['ok']))throw new RuntimeException('stale_profile_setup');
    }elseif($change==='task')LeadTaskService::update(1,101,'Changed manual task','2027-06-01T12:00:00Z');
    else promiseSelectionMessage($pdo,30,'customer','Different conditions after the reviewed calculation');
    $expected=['source'=>'source_changed','profile'=>'profile_changed','trip'=>'trip_changed','task'=>'task_changed','inbound'=>'context_changed'][$change];
    $before=pcSnapshot($pdo);$p=pcPlanFacts()['calculation'];
    pcCheck($change.' remains an explicit stale read fact without mutating the armed intent',
        in_array($expected,$p['stale_reasons'],true)&&$p['intent']['state']==='armed'&&pcTask($pdo)['status']==='open'&&pcSnapshot($pdo)===$before);
});

pcCase('plan recipient and owner authorization',static function():void{
    $pdo=promiseCalculationFixture();pcIntent($pdo,['text'=>'PRIVATE RECIPIENT CONTENT','files'=>[]]);
    $foreign=ManagerPromiseSelectionService::forTasks(8,1,[101]);$admin=pcPlanFacts(90)['calculation'];
    pcCheck('plan keeps existing actor visibility while allowing authorized administrator history',
        (empty($foreign['ok'])||(($foreign['by_task'][101]['availability']??null)==='unavailable'
            &&($foreign['by_task'][101]['binding']??null)===null&&($foreign['by_task'][101]['calculation']['intent']??null)===null))&&$admin['availability']==='available');
    $pdo->exec("UPDATE conversations SET external_chat_id='909' WHERE id=1; UPDATE customer_channels SET external_chat_id='909',external_user_id='909' WHERE id=1");
    $before=pcSnapshot($pdo);$p=pcPlanFacts()['calculation'];
    pcCheck('recipient rebinding withholds proof counts and all private composition',
        $p===['availability'=>'unavailable','reason'=>'scope_changed','intent'=>null,'stale_reasons'=>[],'progress'=>null]
        &&!str_contains(json_encode($p),'PRIVATE RECIPIENT')&&pcSnapshot($pdo)===$before);
});

pcCase('plan optional storage and bounded proof reads',static function():void{
    $pdo=promiseCalculationFixture();$i=pcIntent($pdo,['text'=>'Расчёт 240000 ₽','files'=>[]]);
    $pdo->prepare('UPDATE manager_promise_calculation_intents SET evidence_json=?')->execute([str_repeat(' ',8388609)]);
    $before=pcSnapshot($pdo);$p=pcPlanFacts()['calculation'];
    pcCheck('oversized calculation evidence stops at metadata budget before full hydration',
        $p['availability']==='unavailable'&&$p['reason']==='coverage_limit'&&$p['intent']===null&&pcSnapshot($pdo)===$before);
    $pdo=promiseCalculationFixture();$pdo->exec('DROP TABLE manager_promise_calculation_intents');$facts=pcPlanFacts();
    pcCheck('old missing optional calculation storage preserves the ordinary promise and task',
        $facts['availability']==='available'&&$facts['calculation']['availability']==='unavailable'
        &&$facts['calculation']['reason']==='storage_unavailable'&&pcTask($pdo)['status']==='open');
});

echo 'TOTAL '.($passed+$failed)." | PASS {$passed} | FAIL {$failed} | CASES {$cases}\n";
exit($failed?1:0);
