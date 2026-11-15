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

echo 'TOTAL '.($passed+$failed)." | PASS {$passed} | FAIL {$failed} | CASES {$cases}\n";
exit($failed?1:0);
