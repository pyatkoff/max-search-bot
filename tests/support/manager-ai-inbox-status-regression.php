<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/ManagerAiInboxStatusService.php';

function managerAiInboxRow(PreparedReplyPdo $pdo,int $id=101): array
{
    $q=$pdo->prepare('SELECT id,project_key,source_id,channel,status,manager_id,started_at,last_message_at FROM conversations WHERE id=?');$q->execute([$id]);
    $row=$q->fetch();if(!$row)throw new RuntimeException('FAIL Inbox status missing synthetic conversation');return $row;
}

/** A saved candidate is intentionally weaker than canonical selected-chat validation. */
function managerAiInboxStatusRegression(): void
{
    $check=static fn(string $name,bool $ok)=>preparedReplyCheck('Inbox AI metadata '.$name,$ok);
    $read=static fn(PreparedReplyPdo $pdo,int $viewer=1,?array $row=null):array=>ManagerAiInboxStatusService::decorate([$row??managerAiInboxRow($pdo)],$viewer)[0];
    $status=static fn(array $row)=>$row['ai_preparation_status']??null;
    $seed=static function(bool $ready=false,?array $proposal=null):array {
        $pdo=managerSupervisorFixture();$snap=preparedReplySnapshot();$job=preparedReplyQueue($snap);
        if($ready&&empty(ManagerPreparedReplyService::complete($job,$snap['revision'],$proposal??preparedReplyProposal())['ok']))throw new RuntimeException('FAIL Inbox metadata ready seed');
        return [$pdo,$snap,$job];
    };
    $open=static function(PreparedReplyPdo $pdo,int $viewer=2,bool $ready=true):array {
        $snap=ManagerAiDraftContextService::viewerSnapshot(101,$viewer);
        if(empty(ManagerPreparedReplyService::requestOnOpen(101,$viewer,$snap)['ok']))throw new RuntimeException('FAIL Inbox metadata open seed');
        $q=$pdo->prepare("SELECT job_id FROM manager_prepared_replies WHERE purpose='open_chat' AND requested_by_manager_id=? ORDER BY created_at DESC,job_id DESC LIMIT 1");$q->execute([$viewer]);$job=$q->fetchColumn();
        if(!is_string($job)||($ready&&empty(ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal())['ok'])))throw new RuntimeException('FAIL Inbox metadata open completion');
        return [$snap,$job];
    };

    [$pdo]=$seed();$row=managerAiInboxRow($pdo);$before=preparedReplySourceState($pdo);$cache=$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll();$writes=$pdo->cacheWrites;$pdo->executed=[];
    $pending=$read($pdo,1,$row);$queries=$pdo->executed;
    $metadata=array_filter($queries,static fn(string $sql):bool=>str_contains($sql,'FROM manager_prepared_replies j JOIN conversations c'));
    $histories=array_filter($queries,static fn(string $sql):bool=>str_starts_with($sql,'SELECT id,direction,sender_type,text,created_at FROM messages')||str_starts_with($sql,'SELECT id,metadata_json FROM messages'));
    $check('own pending state is two inert fields',$status($pending)==='pending'&&array_keys(array_diff_key($pending,$row))===['ai_preparation_status','ai_preparation_expires_at']&&array_diff_key($pending,array_flip(['ai_preparation_status','ai_preparation_expires_at']))===$row);
    $check('uses one bounded job metadata read without histories',count($metadata)===1&&$histories===[]&&!str_contains(json_encode(array_diff_key($pending,$row),JSON_THROW_ON_ERROR),'PRIVATE'));
    $check('never writes source/cache or read markers',preparedReplySourceState($pdo)===$before&&$pdo->query('SELECT * FROM manager_prepared_replies')->fetchAll()===$cache&&$pdo->cacheWrites===$writes&&$pdo->sourceWriteAttempts===0);
    [$pdo,$snap,$job]=$seed(true);$check('valid worker result is a saved ready candidate',$status($read($pdo))==='ready');
    $proposal=preparedReplyProposal(['should_draft'=>false,'draft'=>null,'next_action'=>'no_contact','reason'=>'Ответ не требуется.']);
    [$pdo]=$seed(true,$proposal);$check('valid no-contact analysis is recommendations',$status($read($pdo))==='recommendation');
    $check('owner can see a foreign background candidate',$status($read($pdo,2))==='recommendation');
    $check('another project and ordinary colleague see no AI metadata',$status($read($pdo,3))===null&&$status($read($pdo,5))===null);
    ManagerSupervisorAccessService::set(2,4,1,[11],false,true,0);
    $check('employee supervision does not grant automatic foreign AI',$status($read($pdo,4))===null);

    foreach(['active','closed','inactive_owner','unassigned','internal','test'] as $case){
        $pdo=managerSupervisorFixture();
        if($case==='closed')$pdo->mutate("UPDATE conversations SET status='closed'");
        if($case==='inactive_owner')$pdo->mutate('UPDATE managers SET is_active=0 WHERE id=1');
        if($case==='unassigned')$pdo->mutate("UPDATE conversations SET status='waiting_manager',manager_id=NULL");
        if($case==='internal')$pdo->mutate('UPDATE conversations SET id=1;UPDATE messages SET conversation_id=1;UPDATE manager_conversation_reads SET conversation_id=1');
        if($case==='test')$pdo->mutate('UPDATE conversations SET is_test=1');
        if($case==='internal'){
            $snap=ManagerAiDraftContextService::viewerSnapshot(1,2);$request=ManagerPreparedReplyService::requestOnOpen(1,2,$snap);$job=$pdo->query("SELECT job_id FROM manager_prepared_replies WHERE purpose='open_chat'")->fetchColumn();
            $saved=ManagerPreparedReplyService::complete($job,$snap['revision'],preparedReplyProposal());
            $check('internal private opening remains eligible',!empty($request['ok'])&&!empty($saved['ok'])&&$status($read($pdo,2,managerAiInboxRow($pdo,1)))==='ready');
        }else{
            $open($pdo);$check($case.' foreign opening remains requester-bound',$status($read($pdo,2))==='ready'&&$status($read($pdo,1))===null);
        }
    }
    $pdo=managerSupervisorFixture();$open($pdo,2,false);$check('private opening pending is visible only to its requester',$status($read($pdo,2))==='pending'&&$status($read($pdo,1))===null);
    $pdo->mutate("INSERT INTO managers VALUES(7,'other-admin','Another owner','admin','',1,1);INSERT INTO manager_projects VALUES(7,1)");
    $check('same-project second admin cannot read another opener state',$status($read($pdo,7))===null);
    $pdo->mutate("INSERT INTO conversation_sources VALUES(31,'New source',1,99,'none',NULL,0,1,'telegram')");
    $check('changed private viewer capability hash withdraws candidate',$status($read($pdo,2))===null);

    foreach([
        'new latest message'=>"INSERT INTO messages VALUES(3,101,'inbound','customer','Другие даты.','2026-10-27 08:00:00','telegram',NULL,'PRIVATE','PRIVATE')",
        'instruction changed'=>"UPDATE manager_prepared_replies SET instruction_revision='".str_repeat('d',64)."'",
        'cache expired'=>"UPDATE manager_prepared_replies SET expires_at='2020-01-01 00:00:00'",
        'viewer inactive'=>'UPDATE managers SET is_active=0 WHERE id=1',
        'project revoked'=>'DELETE FROM manager_projects WHERE manager_id=1',
        'project inactive'=>'UPDATE projects SET is_active=0 WHERE id=1',
        'source route revoked'=>'DELETE FROM manager_group_members WHERE manager_id=1',
        'background marked test'=>'UPDATE conversations SET is_test=1',
        'background owner inactive'=>'UPDATE managers SET is_active=0 WHERE id=1',
        'background closed'=>"UPDATE conversations SET status='closed'",
    ] as $case=>$sql){
        [$pdo]=$seed(true);$pdo->mutate($sql);$check($case.' suppresses saved metadata',$status($read($pdo))===null);
    }
    [$pdo]=$seed(true);$row=managerAiInboxRow($pdo);$pdo->mutate('UPDATE conversations SET source_id=12');
    $check('stale Inbox source tuple cannot obtain a badge',$status($read($pdo,2,$row))===null);
    [$pdo]=$seed(true);$row=managerAiInboxRow($pdo);$pdo->mutate('UPDATE conversations SET manager_id=6');
    $check('stale Inbox owner tuple cannot obtain a badge',$status($read($pdo,2,$row))===null);
    foreach(['transfer'=>'UPDATE conversations SET manager_id=6','source move'=>'UPDATE conversations SET source_id=12','role downgrade'=>"UPDATE managers SET role='manager' WHERE id=2",'project revoke'=>'DELETE FROM manager_projects WHERE manager_id=2','test toggle'=>'UPDATE conversations SET is_test=1'] as $case=>$sql){
        [$pdo]=$seed(true);$row=managerAiInboxRow($pdo);$pdo->beforeAiInboxConfirmation=static fn(PreparedReplyPdo $p)=>$p->mutate($sql);
        $check($case.' racing metadata is denied by final tuple fence',$status($read($pdo,2,$row))===null);
    }

    $now=time();
    foreach([
        'future creation'=>"created_at='".gmdate('Y-m-d H:i:s',$now+300)."'",
        'future completion'=>"prepared_at='".gmdate('Y-m-d H:i:s',$now+300)."',expires_at='".gmdate('Y-m-d H:i:s',$now+3900)."'",
        'invalid timestamp'=>PreparedReplyPdo::$mysqlFixture?'prepared_at=NULL':"prepared_at='2026-02-30 10:00:00'",
        'invalid TTL'=>"expires_at='".gmdate('Y-m-d H:i:s',$now+7200)."'",
        'malformed JSON'=>"proposal_json='invalid'",
        'nonboolean decision'=>"proposal_json='{\"ok\":true,\"should_draft\":\"true\",\"draft\":\"Private\"}'",
        'nonboolean ok'=>"proposal_json='{\"ok\":\"true\",\"should_draft\":true,\"draft\":\"Private\"}'",
        'invalid proposal fingerprint'=>"proposal_sha256='bad'",
    ] as $case=>$change){
        [$pdo]=$seed(true);$pdo->mutate('UPDATE manager_prepared_replies SET '.$change);$check($case.' never advertises readiness',$status($read($pdo))===null);
    }
    [$pdo]=$seed();$pdo->mutate("UPDATE manager_prepared_replies SET expires_at='".gmdate('Y-m-d H:i:s',time()+7200)."'");
    $check('pending also requires the canonical TTL',$status($read($pdo))===null);

    [$pdo,$snap,$job]=$seed(true);$now=time();$created=gmdate('Y-m-d H:i:s',$now-30);$prepared=gmdate('Y-m-d H:i:s',$now-5);$expiry=gmdate('Y-m-d H:i:s',$now+3595);$newer=gmdate('Y-m-d H:i:s',$now-10);$newerExpiry=gmdate('Y-m-d H:i:s',$now+3590);$pendingId=str_repeat('b',64);
    $pdo->mutate("UPDATE manager_prepared_replies SET created_at='$created',prepared_at='$prepared',expires_at='$expiry';INSERT INTO manager_prepared_replies (job_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,status,created_at,expires_at,purpose) SELECT '$pendingId',conversation_id,manager_id,project_key,'".str_repeat('d',64)."',instruction_revision,latest_message_id,'pending','$newer','$newerExpiry','background' FROM manager_prepared_replies WHERE job_id='$job'");
    $check('newer request pending precedes an older request completed later',$status($read($pdo))==='pending');
    // Coarse saved metadata deliberately does not pretend to validate same-ID
    // edits. Canonical opening/Tab still rejects the obsolete full revision.
    [$pdo,$snap]=$seed(true);$pdo->mutate("UPDATE messages SET text='Изменён тот же вопрос' WHERE id=2");$fresh=preparedReplySnapshot();
    $check('saved candidate qualification preserves canonical same-ID validation',$status($read($pdo))==='ready'&&empty(ManagerPreparedReplyService::prepared(101,1,$fresh)['prepared']));

    [$pdo,$snap,$job]=$seed(true);$pdo->mutate('UPDATE conversations SET id=1;UPDATE messages SET conversation_id=1;UPDATE manager_conversation_reads SET conversation_id=1;UPDATE manager_prepared_replies SET conversation_id=1');
    $check('background internal demo is suppressed',$status($read($pdo,2,managerAiInboxRow($pdo,1)))===null);
    [$pdo]=$seed(true);$row=managerAiInboxRow($pdo)+['delivery_failure_category'=>'suspended'];$pdo->executed=[];
    $check('known suspended client is skipped without a metadata query',$status($read($pdo,1,$row))===null&&$pdo->executed===[]);
    $stale=managerAiInboxRow($pdo)+['ai_preparation_status'=>'ready','ai_preparation_expires_at'=>'2099-01-01 00:00:00'];$pdo->executed=[];
    $check('missing actor strips stale fields without reading metadata',!isset(ManagerAiInboxStatusService::decorate([$stale],0)[0]['ai_preparation_status'])&&$pdo->executed===[]);
    $pdo->guard=false;
    // Simulate missing cache in the disposable fixture while respecting082 FK ownership.
    $pdo->exec('DROP TABLE manager_prepared_reply_input_links');
    $pdo->exec('DROP TABLE manager_prepared_reply_inputs');
    $pdo->exec('DROP TABLE manager_prepared_replies');$pdo->guard=true;
    $check('missing cache table never breaks or retains an old badge',$status($read($pdo,1,$stale))===null);

    [$pdo]=$seed();$row=managerAiInboxRow($pdo);$rows=[];for($i=0;$i<250;$i++)$rows[]=array_replace($row,['id'=>101+$i]);$pdo->executed=[];$out=ManagerAiInboxStatusService::decorate($rows,1);
    $metadata=array_values(array_filter($pdo->executed,static fn(string $sql):bool=>str_contains($sql,'FROM manager_prepared_replies j JOIN conversations c')));
    $check('large caller input keeps the candidate query capped at200 IDs',count($out)===250&&count($metadata)===1&&substr_count($metadata[0],'?')===204&&$status($out[0])==='pending'&&$status($out[249])===null);
}
