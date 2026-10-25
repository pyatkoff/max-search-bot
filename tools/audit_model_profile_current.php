<?php
declare(strict_types=1);
// Isolated, fixed-scope, one-shot private evidence read. Never installed on main.
ini_set('display_errors','0');ini_set('log_errors','0');set_time_limit(45);
ob_start();$pdo=null;
try {
    require_once getcwd().'/config.php';
    require_once getcwd().'/services/ProjectConfig.php';
    require_once getcwd().'/services/ManagerConversationService.php';
    require_once getcwd().'/services/InternalOwnerDialogueScope.php';
    if (ProjectConfig::projectId()!=='anytour') throw new RuntimeException('scope');
    $cutoff=1792960647;
    $pdo=ConversationDb::connection();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    ProjectAccessService::initializeReadOnly();
    $clock=$pdo->query('SELECT UTC_TIMESTAMP() AS captured_at,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) AS utc_offset')->fetch();
    if ((int)$clock['utc_offset']!==0) throw new RuntimeException('clock');
    $captured=str_replace(' ','T',(string)$clock['captured_at']).'Z';
    if (time()<$cutoff || time()-$cutoff>6*3600) throw new RuntimeException('stale_scope');
    $type="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(mc.metadata_json) THEN mc.metadata_json ELSE '{}' END,'$.type')),'message')";
    $sql="SELECT c.id,c.manager_id,MAX(mc.id) AS qualifier FROM conversations c JOIN managers ma ON ma.id=c.manager_id AND ma.is_active=1 JOIN messages mc ON mc.conversation_id=c.id WHERE c.project_key='anytour' AND c.channel IN ('max','telegram') AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND mc.direction='inbound' AND mc.sender_type='customer' AND $type NOT IN ('callback','start','bot_started') AND CHAR_LENGTH(TRIM(mc.text))>=8 AND mc.created_at>=? AND mc.created_at<=? GROUP BY c.id,c.manager_id ORDER BY qualifier DESC,c.id DESC LIMIT 12";
    $q=$pdo->prepare($sql);$q->execute([gmdate('Y-m-d H:i:s',$cutoff-7*86400),gmdate('Y-m-d H:i:s',$cutoff)]);$candidates=$q->fetchAll();
    $cases=[];$skipped=['access_denied'=>0,'history_limited'=>0,'text_oversize'=>0,'payload_oversize'=>0];
    foreach ($candidates as $candidate) {
        if (count($cases)>=5) break;
        $detail=ManagerConversationService::detail((int)$candidate['id'],(int)$candidate['manager_id'],0,80,false,false);
        if (!$detail) {$skipped['access_denied']++;continue;}
        if (!empty($detail['history']['has_more'])) {$skipped['history_limited']++;continue;}
        $c=$detail['conversation'];$messages=[];$oversize=false;
        $mq=$pdo->prepare('SELECT id,metadata_json FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 80');$mq->execute([(int)$c['id']]);$metadata=[];
        foreach ($mq->fetchAll() as $row) {$decoded=json_decode((string)$row['metadata_json'],true);$metadata[(int)$row['id']]=['type'=>is_array($decoded)?(string)($decoded['type']??'message'):'message','revision'=>hash('sha256',(string)$row['metadata_json'])];}
        foreach ($detail['messages'] as $m) {
            if (mb_strlen((string)$m['text'],'UTF-8')>4000) {$oversize=true;break;}
            $messages[]=['id'=>(string)$m['id'],'direction'=>(string)$m['direction'],'sender_type'=>(string)$m['sender_type'],'text'=>(string)$m['text'],'recorded_at'=>str_replace(' ','T',(string)$m['created_at']).'Z','type'=>$metadata[(int)$m['id']]['type'],'metadata_revision'=>$metadata[(int)$m['id']]['revision']];
        }
        if ($oversize) {$skipped['text_oversize']++;continue;}
        $eq=$pdo->prepare('SELECT id,event_type,actor_type,payload_json,created_at FROM conversation_events WHERE conversation_id=? ORDER BY id DESC LIMIT 81');$eq->execute([(int)$c['id']]);$events=$eq->fetchAll();$eventsComplete=count($events)<=80;$events=array_reverse(array_slice($events,0,80));
        foreach ($events as &$e) {
            if (strlen((string)$e['payload_json'])>10000) {$oversize=true;break;}
            $e=['id'=>(string)$e['id'],'kind'=>(string)$e['event_type'],'actor_type'=>(string)$e['actor_type'],'payload_json'=>(string)$e['payload_json'],'recorded_at'=>str_replace(' ','T',(string)$e['created_at']).'Z'];
        }unset($e);
        if ($oversize) {$skipped['payload_oversize']++;continue;}
        $cases[]=['subject'=>['project_key'=>'anytour','platform'=>(string)$c['channel'],'customer_key'=>'customer:'.(string)$c['customer_id'],'conversation_id'=>(string)$c['id']],
            'started_at'=>str_replace(' ','T',(string)$c['started_at']).'Z','messages_complete'=>true,'events_complete'=>$eventsComplete,'messages'=>$messages,'events'=>$events,
            'tour_choices'=>$detail['tour_choices']??['available'=>false],
            'source_clock'=>'database_persistence_utc_not_provider_occurrence'];
    }
    $pdo->rollBack();
    $payload=['schema_version'=>1,'captured_at'=>$captured,'scope_cutoff_epoch'=>$cutoff,'lookback_days'=>7,'candidate_count'=>count($candidates),'candidate_cap'=>12,'case_cap'=>5,'message_cap'=>80,'skipped'=>$skipped,'database_writes'=>false,'conversion_uploads'=>0,'cases'=>$cases];
    $wire=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (strlen($wire)>2*1024*1024 || ob_get_length()!==0) throw new RuntimeException('bounds');
    ob_end_clean();echo $wire;
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR,'fixed_profile_read_failed'.PHP_EOL);exit(1);
}
