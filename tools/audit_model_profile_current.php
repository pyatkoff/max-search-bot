<?php
declare(strict_types=1);
// Owner-approved one-shot read, max100 metadata candidates/5episodes/250messages.
ini_set('display_errors','0');ini_set('log_errors','0');set_time_limit(45);
function profileUtc(string $v,DateTimeZone $z): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,$z);
    if (!$d || $d->format('Y-m-d H:i:s')!==$v) throw new RuntimeException('source_time');
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
ob_start();$pdo=null;$phase='bootstrap';
try {
    require_once getcwd().'/config.php';
    require_once getcwd().'/services/ProjectConfig.php';
    require_once getcwd().'/services/ManagerConversationService.php';
    require_once getcwd().'/services/InternalOwnerDialogueScope.php';
    if (ProjectConfig::projectId()!=='anytour') throw new RuntimeException('scope');
    $cutoff=1792991732;$oldFence=1792960647;
    if (time()<$cutoff || time()-$cutoff>3600) throw new RuntimeException('stale_scope');
    $pdo=ConversationDb::connection();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    ProjectAccessService::initializeReadOnly();
    $phase='clock';$clock=$pdo->query('SELECT UTC_TIMESTAMP() AS captured_at,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) AS utc_offset,@@session.time_zone AS session_zone,@@system_time_zone AS system_zone')->fetch();
    $zn=(string)($clock['session_zone']==='SYSTEM'?$clock['system_zone']:$clock['session_zone']);
    $zn=['MSK'=>'Europe/Moscow','UTC'=>'UTC','GMT'=>'UTC'][$zn]??$zn;
    if ($zn!=='UTC' && !str_contains($zn,'/') && !preg_match('/\A[+-][0-9]{2}:[0-9]{2}\z/D',$zn)) throw new RuntimeException('unknown_zone');
    $zone=new DateTimeZone($zn);
    if ($zone->getOffset(new DateTimeImmutable('now',new DateTimeZone('UTC')))!==(int)$clock['utc_offset']) throw new RuntimeException('zone_offset');
    $local=fn(int $t)=>(new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d H:i:s');
    $phase='candidates';
    // Metadata only. New-person restriction conservatively excludes every old
    // five-case person, without reopening their texts or exporting identity lists.
    $sql="SELECT c.id,c.customer_id,c.manager_id FROM conversations c JOIN customers cu ON cu.id=c.customer_id JOIN managers ma ON ma.id=c.manager_id AND ma.is_active=1 WHERE c.project_key='anytour' AND c.channel='max' AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c')." AND c.started_at>? AND cu.created_at>? AND c.started_at>=? AND c.started_at<=? ORDER BY c.id DESC LIMIT 100";
    $q=$pdo->prepare($sql);$q->execute([$local($oldFence),$local($oldFence),$local($cutoff-7*86400),$local($cutoff)]);$candidates=$q->fetchAll();
    $cases=[];$seen=[];$total=0;$scanned=0;$readEpisodes=0;
    $skipped=['seen_group'=>0,'not_unique_trip'=>0,'message_limit'=>0,'after_fence'=>0,'access_denied'=>0,'text_oversize'=>0,'no_customer_text'=>0];
    foreach ($candidates as $x) {
        if (count($cases)>=5 || $readEpisodes>=5) break;$scanned++;
        $phase='exclusion';$cid=(int)$x['id'];$customer=(int)$x['customer_id'];
        $q=$pdo->prepare('SELECT COUNT(*) FROM conversations WHERE customer_id=?');$q->execute([$customer]);
        if ((int)$q->fetchColumn()!==1 || isset($seen[$customer])) {$skipped['not_unique_trip']++;continue;}
        $keys=[hash('sha256','virtual_reply_dialogue_v1|anytour|customer|'.$customer),hash('sha256','virtual_reply_dialogue_v1|anytour|conversation|'.$cid)];
        $q=$pdo->prepare("SELECT 1 FROM virtual_reply_sampling_aliases WHERE project_key='anytour' AND identity_key IN (?,?) LIMIT 1");$q->execute($keys);$exposed=(bool)$q->fetchColumn();
        foreach (['virtual_reply_cases','virtual_reply_control_cases'] as $table) {
            $q=$pdo->prepare("SELECT 1 FROM $table v JOIN messages m ON v.source_kind='conversation' AND v.source_id=m.id JOIN conversations oc ON oc.id=m.conversation_id WHERE oc.customer_id=? LIMIT 1");$q->execute([$customer]);$exposed=$exposed||(bool)$q->fetchColumn();
        }
        if ($exposed) {$skipped['seen_group']++;continue;}
        $phase='bounds';$q=$pdo->prepare('SELECT COUNT(*) AS n,MAX(created_at) AS last_at FROM messages WHERE conversation_id=?');$q->execute([$cid]);$bound=$q->fetch();
        if ((int)$bound['n']>50 || $total+(int)$bound['n']>250) {$skipped['message_limit']++;continue;}
        if ((string)$bound['last_at']>$local($cutoff)) {$skipped['after_fence']++;continue;}
        $phase='detail';$readEpisodes++;$detail=ManagerConversationService::detail($cid,(int)$x['manager_id'],0,50,false,false);
        if (!$detail) {$skipped['access_denied']++;continue;}
        if (!empty($detail['history']['has_more']) || count($detail['messages'])!==(int)$bound['n']) throw new RuntimeException('history_bound');
        $q=$pdo->prepare('SELECT id,metadata_json FROM messages WHERE conversation_id=? ORDER BY id LIMIT 50');$q->execute([$cid]);$meta=[];
        foreach ($q->fetchAll() as $m) {$j=json_decode((string)$m['metadata_json'],true);$meta[(string)$m['id']]=['type'=>is_array($j)?(string)($j['type']??'message'):'message','revision'=>hash('sha256',(string)$m['metadata_json'])];}
        $messages=[];$large=false;$anchor=null;
        foreach ($detail['messages'] as $m) {
            if (mb_strlen((string)$m['text'],'UTF-8')>4000) {$large=true;break;}
            $id=(string)$m['id'];$messages[]=['id'=>$id,'direction'=>(string)$m['direction'],'sender_type'=>(string)$m['sender_type'],'text'=>(string)$m['text'],'recorded_at'=>profileUtc((string)$m['created_at'],$zone),'type'=>$meta[$id]['type'],'metadata_revision'=>$meta[$id]['revision']];
            if ($anchor===null && $m['direction']==='inbound' && $m['sender_type']==='customer' && $meta[$id]['type']==='message' && mb_strlen(trim((string)$m['text']),'UTF-8')>=8) $anchor=$id;
        }
        // Read budget counts every fetched text, including skipped cases.
        $total+=(int)$bound['n'];
        if ($large) {$skipped['text_oversize']++;continue;}
        if ($anchor===null) {$skipped['no_customer_text']++;continue;}
        $c=$detail['conversation'];$seen[$customer]=true;
        $cases[]=['subject'=>['project_key'=>'anytour','platform'=>'max','customer_key'=>'customer:'.$customer,'conversation_id'=>(string)$cid],
            'started_at'=>profileUtc((string)$c['started_at'],$zone),'messages_complete'=>true,'events_complete'=>false,'messages'=>$messages,
            'tour_choices'=>$detail['tour_choices']??['available'=>false],
            'source_clock'=>'database_persistence_normalized_to_utc_not_provider_occurrence'];
    }
    $phase='serialize';$pdo->rollBack();
    $data=['schema_version'=>1,'captured_at'=>str_replace(' ','T',(string)$clock['captured_at']).'Z','database_zone'=>$zn,'database_offset_seconds'=>(int)$clock['utc_offset'],
        'scope_cutoff_epoch'=>$cutoff,'lookback_days'=>7,'candidate_count'=>count($candidates),'candidate_cap'=>100,'case_cap'=>5,'message_cap'=>50,'skipped'=>$skipped,'database_writes'=>false,'conversion_uploads'=>0,'cases'=>$cases];
    $wire=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (strlen($wire)>2*1024*1024 || ob_get_length()!==0 || $total>250) throw new RuntimeException('bounds');
    ob_end_clean();echo $wire;
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();if (ob_get_level()) ob_end_clean();
    $state=$e instanceof PDOException?(string)($e->errorInfo[0]??'none'):'none';$number=$e instanceof PDOException?(int)($e->errorInfo[1]??0):0;
    if (!preg_match('/\A[A-Z0-9]{5}\z/D',$state)) $state='none';
    fwrite(STDERR,'fixed_profile_read_failed:'.$phase.':'.$state.':'.$number.PHP_EOL);exit(1);
}
