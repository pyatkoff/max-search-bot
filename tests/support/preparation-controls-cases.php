<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ManagerPersonalWorkspaceService.php';

/** Real canonical SQL/services; only synthetic preference and cache writes are allowed. */
function preparationControlsCases(): void
{
    $pdo=preparedReplyFixture();$pdo->preferenceWrites=true;
    $check=static fn(string $name,bool $ok)=>preparedReplyCheck('preparation controls: '.$name,$ok);
    $mutate=static function(int $actor,array $input,bool $queue=false,?string $op=null)use($pdo):array{
        static $sequence=0;
        $state=ManagerPersonalWorkspaceService::snapshot($actor);
        if($queue)$input['expected_queue_version']??=ManagerPersonalWorkspaceService::queuePolicy($actor)['queue_policy']['version']??0;
        return ManagerPersonalWorkspaceService::mutate($actor,$queue?'queue_preparation_policy':'preparation_policy',
            $input+['expected_version'=>$state['version']??0,'operation_id'=>$op??'preparation-fixture-'.str_pad((string)++$sequence,8,'0',STR_PAD_LEFT)]);
    };
    $business=preparedReplySourceState($pdo);
    $legacy=ManagerPreparationPolicy::effective($pdo,1,101,'anytour');
    $check('missing account and queue rules are read-only legacy', $legacy['allowed']&&$legacy['binding']===null
        &&ManagerPreparationPolicy::effective($pdo,0,101,'anytour')['binding']===null
        &&(int)$pdo->query('SELECT COUNT(*) FROM manager_personal_workspace_states')->fetchColumn()===0
        &&(int)$pdo->query('SELECT COUNT(*) FROM manager_preparation_queue_policies')->fetchColumn()===0);
    $on=$mutate(1,['scope'=>'all','enabled'=>true]);
    $check('existing CAS stores an explicit all-own rule',$on['ok']&&$on['state']['ai_preparation']['all']['enabled']);
    $foreignAdmin=$mutate(2,['scope'=>'conversation','conversation_id'=>101,'enabled'=>true]);
    $check('admin read authority does not configure another manager dialogue',!$foreignAdmin['ok']&&$foreignAdmin['error']==='forbidden');
    $snapshot=preparedReplySnapshot();$a=preparedReplyQueue($snapshot);
    $same=$mutate(1,['scope'=>'all','enabled'=>true]);
    $check('saving the same rule is a no-op, not another model admission',$same['ok']&&$same['version']===$on['version']&&preparedReplyQueue($snapshot)===$a);
    $packet=ManagerPreparedReplyService::context($a);$images=$packet['image_inputs'];unset($packet['image_inputs']);$authorize=static function():void{};$connection=str_repeat('b',32);
    $issued=ManagerPreparedReplyService::issueInput($packet,$images,2,$connection,$authorize);
    $check('active background work uses the existing immutable input receipt',!empty($issued['capture']['captured']));
    $off=$mutate(1,['scope'=>'conversation','conversation_id'=>101,'enabled'=>false]);
    $check('dialog exclusion fences pending inventory and completion',$off['ok']&&ManagerPreparedReplyService::pending()['jobs']===[]
        &&!ManagerPreparedReplyService::context($a)['ok']&&!ManagerPreparedReplyService::complete($a,$snapshot['revision'],preparedReplyProposal())['ok']);
    $lateInput=ManagerPreparedReplyService::issueInput($packet,$images,2,$connection,$authorize);
    $check('revocation after packet read rejects a late input issuance',empty($lateInput['ok'])&&(int)$pdo->query('SELECT COUNT(*) FROM manager_prepared_reply_inputs')->fetchColumn()===1);
    $clear=$mutate(1,['scope'=>'clear','conversation_id'=>101]);$b=preparedReplyQueue($snapshot);
    $check('off then clear inherits through a persistent generation, never A',$clear['ok']&&$clear['state']['ai_preparation']['conversations'][101]['inherit']&&$a!==$b
        &&!ManagerPreparedReplyService::complete($a,$snapshot['revision'],preparedReplyProposal())['ok']);
    $check('new B is exactly deduplicated while pending',preparedReplyQueue($snapshot)===$b);
    $check('current B completes and is passively available',ManagerPreparedReplyService::complete($b,$snapshot['revision'],preparedReplyProposal())['ok']
        &&ManagerPreparedReplyService::prepared(101,1,$snapshot,true)['prepared']);
    $oldBinding=ManagerPreparationPolicy::effective($pdo,1,101,'anytour')['binding'];
    $appearance=ManagerPersonalWorkspaceService::mutate(1,'settings',['settings'=>['font'=>'large'],'expected_version'=>ManagerPersonalWorkspaceService::snapshot(1)['version'],'operation_id'=>'preparation-appearance-op']);
    $check('unrelated appearance does not invalidate effective binding',$appearance['ok']&&ManagerPreparationPolicy::effective($pdo,1,101,'anytour')['binding']===$oldBinding);
    $pdo->mutate("INSERT INTO conversations SELECT 102,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,'SECOND SYNTHETIC',is_test FROM conversations WHERE id=101");
    $other=$mutate(1,['scope'=>'conversation','conversation_id'=>102,'enabled'=>false]);
    $check('another own dialogue rule does not revoke this result',$other['ok']&&ManagerPreparationPolicy::effective($pdo,1,101,'anytour')['binding']===$oldBinding);
    $mutate(1,['scope'=>'conversation','conversation_id'=>101,'enabled'=>false]);
    $check('off retains accepted ready result for explicit/manual read',ManagerPreparedReplyService::prepared(101,1,$snapshot)['prepared']
        &&!ManagerPreparedReplyService::prepared(101,1,$snapshot,true)['prepared']);
    $mutate(1,['scope'=>'clear','conversation_id'=>101]);$c=preparedReplyQueue($snapshot);
    $check('ready dedup is also policy-bound after off-clear',$c!==$b&&!ManagerPreparedReplyService::prepared(101,1,$snapshot,true)['prepared']);
    $mutate(1,['scope'=>'conversation','conversation_id'=>101,'enabled'=>true]);
    $mutate(1,['scope'=>'stop_all']);$stopped=ManagerPersonalWorkspaceService::snapshot(1)['state']['ai_preparation'];
    $check('stop-all removes positive overrides but preserves explicit exclusions',!ManagerPreparationPolicy::resolve($stopped,1,101,'anytour')['allowed']
        &&($stopped['conversations'][101]['inherit']??false)&&($stopped['conversations'][102]['enabled']??true)===false);
    $future=gmdate('Y-m-d H:i:s',time()+3600);
    $timed=$mutate(1,['scope'=>'all','enabled'=>true,'until_utc'=>$future]);$policy=$timed['state']['ai_preparation'];$deadline=strtotime($future.' UTC');
    $check('deadline is inclusive DENY and cannot fall back to legacy',ManagerPreparationPolicy::resolve($policy,1,101,'anytour',$deadline-1)['allowed']
        &&!ManagerPreparationPolicy::resolve($policy,1,101,'anytour',$deadline)['allowed']&&ManagerPreparationPolicy::resolve($policy,1,101,'anytour',$deadline)['mode']==='expired');
    $timedJob=preparedReplyQueue($snapshot);$query=$pdo->prepare('SELECT * FROM manager_prepared_replies WHERE job_id=?');$query->execute([$timedJob]);$timedRow=$query->fetch(PDO::FETCH_ASSOC);
    $check('the persisted pending binding expires without a settings change',ManagerPreparationPolicy::matchesJob($pdo,$timedRow,$deadline-1)&&!ManagerPreparationPolicy::matchesJob($pdo,$timedRow,$deadline));
    $check('permanent means no deadline',$mutate(1,['scope'=>'all','enabled'=>true])['state']['ai_preparation']['all']['until_utc']===null);
    $invalid=$mutate(1,['scope'=>'all','enabled'=>true,'until_utc'=>'2026-02-30 12:00:00']);
    $check('invalid or past input cannot be a saved rule',!$invalid['ok']&&$invalid['error']==='invalid_preparation_policy');
    $version=ManagerPersonalWorkspaceService::snapshot(1)['version'];$op='preparation-replay-exact-op';
    $request=['scope'=>'conversation','conversation_id'=>101,'enabled'=>false,'expected_version'=>$version,'operation_id'=>$op];
    $first=ManagerPersonalWorkspaceService::mutate(1,'preparation_policy',$request);
    $again=ManagerPersonalWorkspaceService::mutate(1,'preparation_policy',$request);
    $check('same nonce replays without changing rule generation',$first['ok']&&$again['version']===$first['version']);
    $conflict=ManagerPersonalWorkspaceService::mutate(1,'preparation_policy',['scope'=>'all','enabled'=>false,'expected_version'=>$version,'operation_id'=>'preparation-conflict-new']);
    $check('stale workspace version is conflict, not an implicit overwrite',!$conflict['ok']&&$conflict['error']==='conflict');
    $receipt=ManagerPersonalWorkspaceService::operation(1,$op);
    $check('unknown-write recovery uses the existing immutable receipt',$receipt['ok']&&$receipt['found']&&$receipt['result']['ok']);
    $pdo->mutate('UPDATE conversations SET manager_id=2 WHERE id=101');
    $foreign=$mutate(1,['scope'=>'conversation','conversation_id'=>101,'enabled'=>true]);
    $check('a formerly assigned manager cannot configure foreign scope',!$foreign['ok']&&$foreign['error']==='forbidden');
    $check('old owner job cannot complete after reassignment',!ManagerPreparedReplyService::complete($c,$snapshot['revision'],preparedReplyProposal())['ok']);
    $check('new assignment selects new owner legacy rule',ManagerPreparationPolicy::effective($pdo,2,101,'anytour')['binding']===null);
    $pdo->mutate('UPDATE conversations SET manager_id=1 WHERE id=101');
    $denied=$mutate(1,['scope'=>'all','enabled'=>true],true);
    $check('ordinary manager cannot change shared queue policy',!$denied['ok']&&$denied['error']==='forbidden');
    $outside=$mutate(3,['scope'=>'all','enabled'=>true],true);
    $check('admin outside the current project has no queue authority',!$outside['ok']&&$outside['error']==='forbidden');
    $queueOp='preparation-queue-replay-op';$queue=$mutate(2,['scope'=>'all','enabled'=>true],true,$queueOp);
    $check('fresh project admin uses one canonical queue rule',$queue['ok']&&$queue['queue_policy']['version']===1);
    $pdo->mutate("UPDATE conversations SET manager_id=NULL,status='waiting_manager' WHERE id=101");
    $queueBinding=ManagerPreparationPolicy::effective($pdo,0,101,'anytour')['binding'];
    $mutate(2,['scope'=>'all','enabled'=>false],true);
    $check('queue off-on gets a different admission identity',$mutate(2,['scope'=>'all','enabled'=>true],true)['ok']
        &&ManagerPreparationPolicy::effective($pdo,0,101,'anytour')['binding']!==$queueBinding);
    $invalidQueueOp='preparation-invalid-queue-op';$invalidQueue=$mutate(2,['scope'=>'conversation','conversation_id'=>101,'enabled'=>true],true,$invalidQueueOp);
    $check('queue rule cannot become a per-dialog foreign scope',!$invalidQueue['ok']&&$invalidQueue['error']==='invalid_preparation_policy');
    $pdo->mutate("UPDATE managers SET role='manager' WHERE id=2");
    $check('queue receipt readback rechecks current role, no snapshot leak',ManagerPersonalWorkspaceService::operation(2,$queueOp)['error']==='forbidden'
        &&ManagerPersonalWorkspaceService::queuePolicy(2)['error']==='forbidden'&&ManagerPersonalWorkspaceService::operation(2,$invalidQueueOp)['error']==='forbidden');
    $pdo->mutate("UPDATE managers SET role='admin' WHERE id=2; UPDATE conversations SET manager_id=1,status='manager' WHERE id=101; DELETE FROM conversations WHERE id=102");
    $check('preferences never write customer/business/source state',preparedReplySourceState($pdo)===$business&&$pdo->sourceWriteAttempts===0);

    [$pdo,$waiting,$legacyQueueJob]=preparedReplyWaitingFixture();$pdo->preferenceWrites=true;
    $adminState=ManagerPersonalWorkspaceService::snapshot(2);
    $saved=ManagerPersonalWorkspaceService::mutate(2,'queue_preparation_policy',['scope'=>'all','enabled'=>true,'expected_version'=>$adminState['version'],'expected_queue_version'=>0,'operation_id'=>'queue-assignment-fixture']);
    $queued=ManagerPreparedReplyService::enqueue([preparedReplyRow($waiting,['manager_id'=>0])]);$queueJob=$queued['job_ids'][0]??'';
    $check('queue settings create existing jobs with the fresh queue binding',$saved['ok']&&$queueJob!==''&&$queueJob!==$legacyQueueJob
        &&!ManagerPreparedReplyService::complete($legacyQueueJob,$waiting['revision'],preparedReplyProposal())['ok']);
    $pdo->mutate("UPDATE conversations SET manager_id=1,status='manager' WHERE id=101");
    $assigned=preparedReplySnapshot();$assignedJob=preparedReplyQueue($assigned);
    $check('assignment fences the old queue job and selects the assigned branch',$assignedJob!==$queueJob
        &&!ManagerPreparedReplyService::complete($queueJob,$waiting['revision'],preparedReplyProposal())['ok']);
    $pdo->guard=false;$date=gmdate('Y-m-d H:i:s');
    $q=$pdo->prepare("INSERT INTO messages(id,conversation_id,direction,sender_type,text,created_at,channel) VALUES (3,101,'outbound','manager','Synthetic human reply',?,'telegram'),(4,101,'inbound','customer','Synthetic new input',?,'telegram')");$q->execute([$date,$date]);$pdo->guard=true;
    $afterHuman=preparedReplySnapshot();$next=preparedReplyQueue($afterHuman);
    $check('new customer input after a human reply retains ordinary drafting',$next!==$assignedJob&&ManagerPreparedReplyService::complete($next,$afterHuman['revision'],preparedReplyProposal(['evidence_message_ids'=>[4]]))['ok']);

    // A first per-dialog exception must not invalidate untouched legacy work,
    // nor let old disabled candidates hide an eligible row behind the limit.
    $pdo=preparedReplyFixture();$pdo->preferenceWrites=true;
    $first=preparedReplySnapshot();$firstJob=preparedReplyQueue($first);
    $pdo->mutate("INSERT INTO conversations SELECT 102,customer_id,project_key,source_id,channel,entry_channel,attribution_region,attribution_campaign,status,lead_stage_key,manager_id,started_at,last_message_at,closed_at,'SECOND SYNTHETIC',is_test FROM conversations WHERE id=101");
    $pdo->guard=false;
    $pdo->exec("INSERT INTO messages SELECT 3,102,direction,sender_type,text,created_at,channel,metadata_json,sender_id,external_message_id FROM messages WHERE id=2");
    $pdo->guard=true;
    $second=ManagerAiDraftContextService::snapshot(102,1);
    $queued=ManagerPreparedReplyService::enqueue([preparedReplyRow($second,['conversation_id'=>102])]);$secondJob=$queued['job_ids'][0]??'';
    if($secondJob==='')throw new RuntimeException('FAIL second synthetic preparation fixture');
    $firstCreated=gmdate('Y-m-d H:i:s',time()-120);$secondCreated=gmdate('Y-m-d H:i:s',time()-60);
    $q=$pdo->prepare('UPDATE manager_prepared_replies SET created_at=? WHERE job_id=?');$q->execute([$firstCreated,$firstJob]);$q->execute([$secondCreated,$secondJob]);
    $state=ManagerPersonalWorkspaceService::snapshot(1);
    $excluded=ManagerPersonalWorkspaceService::mutate(1,'preparation_policy',['scope'=>'conversation','conversation_id'=>101,'enabled'=>false,'expected_version'=>$state['version'],'operation_id'=>'preparation-first-exception']);
    $pdo->executed=[];$inventory=ManagerPreparedReplyService::pending(1);$reads=count($pdo->executed);
    $check('first exception preserves another legacy binding and bounded one-query progress',
        $excluded['ok']&&$inventory['ok']&&count($inventory['jobs'])===1&&$inventory['jobs'][0]['job_id']===$secondJob
        &&$reads===1&&ManagerPreparationPolicy::effective($pdo,1,102,'anytour')['binding']===null
        &&!array_key_exists('policy_personal_json',$inventory['jobs'][0])
        &&!ManagerPreparedReplyService::complete($firstJob,$first['revision'],preparedReplyProposal())['ok']);

    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        // A second plain connection preserves this disposable fixture instead
        // of invoking PreparedReplyPdo's destructive constructor/reset.
        $dsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
        if(preg_match('/(?:\\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\\z)/D',$dsn,$match)!==1)throw new RuntimeException('unsafe_mysql_fixture_database');
        $reader=new PDO($dsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
        ]);
        if($reader->query('SELECT DATABASE()')->fetchColumn()!==$match[1]||$pdo->query('SELECT DATABASE()')->fetchColumn()!==$match[1])throw new RuntimeException('unsafe_mysql_fixture_database');
        $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        foreach(['missing','existing'] as $rowState){
            if($rowState==='missing')$pdo->mutate("DELETE FROM manager_preparation_queue_policies WHERE project_key='anytour'");
            $expected=ManagerPreparationPolicy::queueView($pdo,2,'anytour')['queue_policy']['version'];
            $reader->beginTransaction();
            try{
                $before=ManagerPreparationPolicy::queueView($reader,2,'anytour');
                $pdo->beginTransaction();
                try{
                    $write=ManagerPreparationPolicy::mutateQueue($pdo,2,'anytour',['scope'=>'all','enabled'=>false,'expected_queue_version'=>$expected]);
                    $pdo->commit();
                }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                $stale=ManagerPreparationPolicy::queueView($reader,2,'anytour');
                $conflict=ManagerPreparationPolicy::mutateQueue($reader,2,'anytour',['scope'=>'all','enabled'=>true,'expected_queue_version'=>$expected]);
                $reader->rollBack();
                $current=ManagerPreparationPolicy::queueView($pdo,2,'anytour')['queue_policy'];
                $check('native RR '.$rowState.' queue snapshot returns conflict without overwrite',
                    $write['ok']&&$before['queue_policy']['version']===$expected&&$stale['queue_policy']['version']===$expected
                    &&!$conflict['ok']&&$conflict['error']==='conflict'&&$current['version']===$expected+1&&$current['rule']['all']['enabled']===false);
            }finally{if($reader->inTransaction())$reader->rollBack();}
        }
    }
}
